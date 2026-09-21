<?php

namespace App\George\Reasoners;

use App\George\Contracts\Reasoner;
use App\George\Support\Softmax;

/**
 * Keyword overlap between the situation and each option, for tests and
 * UI work without a model.
 */
final class FakeReasoner implements Reasoner
{
    private int $lastPasses = 0;

    public function judge(string $situation, string $question, array $options): array
    {
        $text = mb_strtolower($situation.' '.$question);
        $raw = [];

        foreach (array_values($options) as $option) {
            $score = 0.0;
            $words = preg_split('/[^\p{L}\p{N}]+/u', mb_strtolower($option), -1, PREG_SPLIT_NO_EMPTY) ?: [];

            foreach ($words as $word) {
                if (mb_strlen($word) >= 4 && str_contains($text, $word)) {
                    $score += 1.0;
                }
            }

            $raw[] = $score;
        }

        $this->lastPasses = 1;

        return Softmax::normalize($raw);
    }

    public function warmup(): void
    {
        //
    }

    public function modelName(): string
    {
        return 'fake-reasoner';
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function isReady(): bool
    {
        return true;
    }

    public function lastPasses(): int
    {
        return $this->lastPasses;
    }

    public function lastSpread(): float
    {
        return 0.0;
    }
}
