<?php

namespace App\George\Reasoners;

use App\George\Support\Primer;
use App\George\Support\Prompt;
use App\George\Support\Softmax;
use Codewithkyrian\Transformers\Models\Auto\AutoModelForCausalLM;
use Codewithkyrian\Transformers\PreTrainedTokenizers\AutoTokenizer;
use Codewithkyrian\Transformers\Transformers;
use Codewithkyrian\Transformers\Utils\ImageDriver;
use RuntimeException;

/**
 * Slot C in-process: TransformersPHP loads the ONNX graph into this PHP
 * process and the readout is one forward pass, no generation.
 *
 * The ceiling is the runtime, not the machine. The binding has no float16
 * tensor mapping, so the build needs a float32 KV cache (q4 / int8 /
 * quantized), and the larger repos ship either q4f16 only or an ONNX
 * Runtime GenAI layout this library cannot open. In practice that stops
 * at 4B; above it, use LlamaReasoner.
 */
final class TransformersReasoner extends LetterReasoner
{
    private mixed $model = null;

    private mixed $tokenizer = null;

    /** @var array<string, list<int>> */
    private array $letterIds = [];

    private bool $booted = false;

    public function __construct(
        private readonly ?string $modelName = null,
        private readonly ?string $file = null,
        private readonly ?string $format = null,
    ) {}

    public function modelName(): string
    {
        return $this->modelName ?? (string) config('george.reasoner_model');
    }

    public function driver(): string
    {
        return 'reasoner';
    }

    public function isReady(): bool
    {
        return self::modelIsCached($this->modelName(), $this->fileName());
    }

    public static function modelIsCached(?string $model = null, ?string $file = null, ?string $cacheDir = null): bool
    {
        $model ??= (string) config('george.reasoner_model');
        $file ??= (string) config('george.reasoner_file', 'model_q4');
        $cacheDir ??= (string) config('george.cache_dir');

        $base = rtrim($cacheDir, DIRECTORY_SEPARATOR).DIRECTORY_SEPARATOR
            .str_replace('/', DIRECTORY_SEPARATOR, $model);

        $graph = $base.DIRECTORY_SEPARATOR.'onnx'.DIRECTORY_SEPARATOR.$file.'.onnx';

        if (! is_file($base.DIRECTORY_SEPARATOR.'config.json')
            || ! is_file($base.DIRECTORY_SEPARATOR.'tokenizer.json')
            || ! is_file($graph)) {
            return false;
        }

        // A large model stores its weights beside the graph, which is then only
        // a few MB. Counting that as ready would start a worker that cannot
        // load, so require the weights when the graph is clearly a stub.
        return filesize($graph) > 50 * 1024 * 1024 || is_file($graph.'_data');
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

        $encoded = ($this->tokenizer)($text, addSpecialTokens: false, returnTensor: true);

        $output = $this->model->forward([
            'input_ids' => $encoded['input_ids'],
            'attention_mask' => $encoded['attention_mask'],
        ]);

        $this->countPass();

        $logits = $output['logits'];
        $shape = $logits->shape();
        $last = $logits[0][$shape[1] - 1];

        $values = [];

        foreach (array_keys($options) as $i) {
            // A letter may exist with and without a leading-space marker
            // (sentencepiece "▁A", byte-level "ĠA"); take whichever the
            // model prefers so the readout does not depend on the tokenizer.
            $best = -INF;

            foreach ($this->letterIds[Prompt::LETTERS[$i]] as $id) {
                $best = max($best, (float) $last[$id]);
            }

            $values[] = $best;
        }

        return Softmax::normalize($values);
    }

    private function fileName(): string
    {
        return $this->file ?? (string) config('george.reasoner_file', 'model_q4');
    }

    private function format(): string
    {
        return $this->chatFormat($this->format ?? (string) config('george.reasoner_format', 'chatml'));
    }

    protected function prepare(): void
    {
        if ($this->model !== null) {
            return;
        }

        $this->boot();

        $cacheDir = (string) config('george.cache_dir');

        if (! is_dir($cacheDir)) {
            mkdir($cacheDir, 0755, true);
        }

        $this->tokenizer = AutoTokenizer::fromPretrained($this->modelName(), $cacheDir);

        if ($this->tokenizer === null) {
            throw new RuntimeException('The reasoner tokenizer could not be loaded for '.$this->modelName().'.');
        }

        $this->model = AutoModelForCausalLM::fromPretrained(
            $this->modelName(),
            true,
            null,
            $cacheDir,
            'main',
            $this->fileName(),
        );

        foreach (Prompt::LETTERS as $letter) {
            $candidates = [];

            foreach ([$letter, ' '.$letter] as $form) {
                $ids = $this->tokenizer->encode($form, addSpecialTokens: false);

                if (count($ids) === 1) {
                    $candidates[] = (int) $ids[0];
                }
            }

            $candidates = array_values(array_unique($candidates));

            if ($candidates === []) {
                throw new RuntimeException("Option letter {$letter} is not a single token for ".$this->modelName().'.');
            }

            $this->letterIds[$letter] = $candidates;
        }
    }

    private function boot(): void
    {
        if ($this->booted) {
            return;
        }

        if (! extension_loaded('ffi')) {
            throw new RuntimeException('PHP FFI is required to run George. Enable the ffi extension and set ffi.enable=true.');
        }

        $memory = ini_get('memory_limit');
        if ($memory !== '-1' && $this->memoryToBytes((string) $memory) < 3072 * 1024 * 1024) {
            ini_set('memory_limit', '4096M');
        }

        Transformers::setup()
            ->setCacheDir((string) config('george.cache_dir'))
            ->setImageDriver(ImageDriver::GD)
            ->apply();

        $this->booted = true;
    }

    private function memoryToBytes(string $value): int
    {
        $value = trim($value);
        $unit = strtolower(substr($value, -1));
        $number = (int) $value;

        return match ($unit) {
            'g' => $number * 1024 * 1024 * 1024,
            'm' => $number * 1024 * 1024,
            'k' => $number * 1024,
            default => (int) $value,
        };
    }
}
