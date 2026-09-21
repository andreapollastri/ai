<?php

namespace App\George\Reasoners;

use App\George\Contracts\Reasoner;
use App\George\Support\Prompt;
use RuntimeException;

/**
 * Shared shape of slot C: whatever runs the model, the answer is the
 * next-token distribution over the option letters, read twice with the
 * options reversed so the letter positions cancel out.
 *
 * Subclasses only have to turn one prompt into one distribution.
 */
abstract class LetterReasoner implements Reasoner
{
    private int $lastPasses = 0;

    private float $lastSpread = 0.0;

    /**
     * One forward pass: the probability of each option letter being the
     * next token, in the order the options are given.
     *
     * @param  list<string>  $options
     * @return list<float>
     */
    abstract protected function readout(string $situation, string $question, array $options): array;

    public function judge(string $situation, string $question, array $options): array
    {
        $options = array_values($options);
        $n = count($options);

        if ($n < 1 || $n > count(Prompt::LETTERS)) {
            throw new RuntimeException('The reasoner takes between 1 and '.count(Prompt::LETTERS).' options.');
        }

        $this->prepare();
        $this->lastPasses = 0;
        $this->lastSpread = 0.0;

        $forward = $this->readout($situation, $question, $options);

        if (! (bool) config('george.reasoner_debias', true) || $n < 2) {
            return $forward;
        }

        $reversed = array_reverse($this->readout($situation, $question, array_reverse($options)));

        $averaged = [];
        $l1 = 0.0;

        foreach ($forward as $i => $p) {
            $averaged[] = ($p + $reversed[$i]) / 2;
            $l1 += abs($p - $reversed[$i]);
        }

        $this->lastSpread = min($l1 / 2, 1.0);

        $sum = array_sum($averaged);

        return $sum > 0
            ? array_map(fn (float $p): float => $p / $sum, $averaged)
            : array_fill(0, $n, 1 / $n);
    }

    public function warmup(): void
    {
        $this->prepare();
        $this->judge('George is warming up.', 'Is George awake?', ['Yes. Awake', 'No. Asleep']);
    }

    public function lastPasses(): int
    {
        return $this->lastPasses;
    }

    public function lastSpread(): float
    {
        return $this->lastSpread;
    }

    /**
     * Whatever has to happen once before the first readout: loading a
     * graph, checking a socket. Called on every judge(), so it must be
     * cheap after the first time.
     */
    protected function prepare(): void
    {
        //
    }

    protected function countPass(): void
    {
        $this->lastPasses++;
    }

    /**
     * The chat format the configured model was tuned on.
     */
    protected function chatFormat(string $configured): string
    {
        return in_array($configured, Prompt::FORMATS, true) ? $configured : 'chatml';
    }
}
