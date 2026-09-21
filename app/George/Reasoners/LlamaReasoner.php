<?php

namespace App\George\Reasoners;

use App\George\Support\Primer;
use App\George\Support\Prompt;
use App\George\Support\Softmax;
use Illuminate\Support\Facades\Http;
use RuntimeException;
use Throwable;

/**
 * Slot C over llama.cpp: the weights live in a llama-server process and
 * George asks it for one token.
 *
 * Same prompt and same readout as TransformersReasoner — `/completion`
 * with n_predict 1 and n_probs returns the next-token distribution, and
 * the option letters are picked out of it — but the model is a GGUF, so
 * 8B and 14B are on the table and the float16 KV cache limit of the PHP
 * ONNX binding does not apply.
 *
 * The prompt is built prefix-stable on purpose: the primer is identical
 * on every call and the situation is identical across the conditions of
 * one Run, so with cache_prompt llama-server re-reads only the question
 * and the options.
 */
final class LlamaReasoner extends LetterReasoner
{
    private ?bool $healthy = null;

    private float $checkedAt = 0.0;

    private ?string $reportedModel = null;

    public function __construct(
        private readonly ?string $url = null,
        private readonly ?string $format = null,
    ) {}

    public function modelName(): string
    {
        return $this->reportedModel ??= $this->askModelName();
    }

    public function driver(): string
    {
        return 'llama';
    }

    public function isReady(): bool
    {
        // Slot readiness is read on every status request; one socket per
        // page load is fine, one per condition is not.
        if ($this->healthy !== null && (microtime(true) - $this->checkedAt) < 10.0) {
            return $this->healthy;
        }

        $this->checkedAt = microtime(true);

        try {
            $this->healthy = Http::timeout(2)->get($this->url().'/health')->successful();
        } catch (Throwable) {
            $this->healthy = false;
        }

        return $this->healthy;
    }

    /**
     * @param  list<string>  $options
     * @return list<float>
     */
    protected function readout(string $situation, string $question, array $options): array
    {
        $system = Primer::enabled() ? Primer::system() : Prompt::SYSTEM;
        $shots = Primer::enabled() ? Primer::shots() : [];
        $text = Prompt::chat($system, Prompt::user($situation, $question, $options), $this->format(), $shots);

        $response = $this->post($this->payload($text));

        $this->countPass();

        return $this->letters($this->candidates($response), count($options));
    }

    protected function prepare(): void
    {
        if (! $this->isReady()) {
            throw new RuntimeException(
                'llama-server is not answering at '.$this->url().'. '
                .'Start it, or set GEORGE_REASONER_BACKEND=onnx.'
            );
        }
    }

    private function url(): string
    {
        return rtrim($this->url ?? (string) config('george.llama.url', 'http://127.0.0.1:8080'), '/');
    }

    private function format(): string
    {
        return $this->chatFormat($this->format ?? (string) config('george.llama.format', 'chatml'));
    }

    /**
     * @return array<string, mixed>
     */
    private function payload(string $text): array
    {
        return [
            // llama-server adds the BOS itself; the llama3 layout writes
            // one into the text, and two make the first turn look wrong.
            'prompt' => $this->format() === 'llama3'
                ? (string) preg_replace('/^<\|begin_of_text\|>/', '', $text)
                : $text,
            'n_predict' => 1,
            'n_probs' => max((int) config('george.llama.n_probs', 40), count(Prompt::LETTERS) * 2),
            // Pre-sampling probabilities: the model's own distribution over
            // the vocabulary, untouched by the sampler settings. That is
            // the same quantity the ONNX backend softmaxes.
            'post_sampling_probs' => false,
            'cache_prompt' => (bool) config('george.llama.cache_prompt', true),
            'temperature' => 0,
            'stream' => false,
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    private function post(array $payload): array
    {
        try {
            $response = Http::timeout((int) config('george.llama.timeout', 120))
                ->acceptJson()
                ->post($this->url().'/completion', $payload);
        } catch (Throwable $e) {
            $this->healthy = null;

            throw new RuntimeException('llama-server did not answer: '.$e->getMessage(), previous: $e);
        }

        if (! $response->successful()) {
            $this->healthy = null;

            throw new RuntimeException('llama-server returned HTTP '.$response->status().': '.$response->body());
        }

        return (array) $response->json();
    }

    /**
     * The next-token candidates, as token text => score. The server has
     * changed the shape of this block between releases, so take whichever
     * of the known layouts came back.
     *
     * @param  array<string, mixed>  $response
     * @return array{tokens: array<string, float>, log: bool}
     */
    private function candidates(array $response): array
    {
        $block = $response['completion_probabilities'] ?? $response['probs'] ?? null;

        if (! is_array($block) || $block === []) {
            throw new RuntimeException(
                'llama-server returned no token probabilities. It has to run with n_probs support (a current build).'
            );
        }

        $first = $block[0] ?? [];
        $rows = $first['top_logprobs'] ?? $first['probs'] ?? (isset($first['token']) || isset($first['tok_str']) ? $block : []);

        $tokens = [];
        $log = false;

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $token = (string) ($row['token'] ?? $row['tok_str'] ?? '');

            if ($token === '') {
                continue;
            }

            if (array_key_exists('prob', $row)) {
                $value = (float) $row['prob'];
            } elseif (array_key_exists('logprob', $row)) {
                $value = (float) $row['logprob'];
                $log = true;
            } else {
                continue;
            }

            // A letter reaches the sampler both bare and with a leading
            // space. Keep the better of the two, as the ONNX slot does.
            $key = $this->fold($token);

            $tokens[$key] = isset($tokens[$key]) ? max($tokens[$key], $value) : $value;
        }

        return ['tokens' => $tokens, 'log' => $log];
    }

    /**
     * @param  array{tokens: array<string, float>, log: bool}  $candidates
     * @return list<float>
     */
    private function letters(array $candidates, int $n): array
    {
        $tokens = $candidates['tokens'];
        $found = [];

        foreach (range(0, $n - 1) as $i) {
            $letter = Prompt::LETTERS[$i];
            $found[$i] = $tokens[$letter] ?? null;
        }

        $present = array_filter($found, fn (?float $value): bool => $value !== null);

        if ($present === []) {
            throw new RuntimeException(
                'None of the option letters came back in the top '.count($tokens).' tokens. '
                .'Raise GEORGE_LLAMA_N_PROBS, or check that the chat format matches the model.'
            );
        }

        // A letter the sampler never considered is not a tie, it is a
        // rejection: floor it well below the ones that did come back.
        $floor = min($present);
        $values = array_map(
            fn (?float $value): float => $value ?? ($candidates['log'] ? $floor - 10.0 : 0.0),
            $found,
        );

        if (! $candidates['log']) {
            $sum = array_sum($values);

            return $sum > 0
                ? array_values(array_map(fn (float $v): float => $v / $sum, $values))
                : array_fill(0, $n, 1 / $n);
        }

        return Softmax::normalize(array_values($values));
    }

    /**
     * Strip the space markers the tokenizers use so " A", "▁A" and "A"
     * all land on the same letter.
     */
    private function fold(string $token): string
    {
        return trim(str_replace(["\u{2581}", "\u{0120}"], ' ', $token));
    }

    private function askModelName(): string
    {
        $configured = trim((string) config('george.llama.repo', ''));
        $quant = trim((string) config('george.llama.quant', ''));
        $fallback = $configured !== ''
            ? $configured.($quant !== '' ? ':'.$quant : '')
            : 'llama-server';

        try {
            $props = Http::timeout(2)->get($this->url().'/props');

            if (! $props->successful()) {
                return $fallback;
            }

            $path = (string) ($props->json('model_path')
                ?? $props->json('default_generation_settings.model')
                ?? '');

            return $path !== '' ? basename($path) : $fallback;
        } catch (Throwable) {
            return $fallback;
        }
    }
}
