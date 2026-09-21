<?php

namespace App\George\Engines;

use App\George\Contracts\Engine;
use App\George\Support\Softmax;

final class FakeEngine implements Engine
{
    public function classify(
        string $sequence,
        array $labels,
        bool $multiLabel = false,
        string $hypothesisTemplate = '{}',
    ): array {
        $text = mb_strtolower($sequence);
        $raw = [];

        foreach ($labels as $label) {
            $hypothesis = mb_strtolower(str_replace('{}', $label, $hypothesisTemplate));
            $raw[] = $this->overlapScore($text, $hypothesis);
        }

        if (! $multiLabel && count($raw) > 1) {
            $scores = Softmax::normalize($raw);
        } else {
            $scores = array_map(
                fn (float $score): float => 1 / (1 + exp(-($score - 0.35) * 6)),
                $raw,
            );
        }

        return $this->sorted($sequence, $labels, $scores);
    }

    public function warmup(): void
    {
        //
    }

    public function modelName(): string
    {
        return 'fake-heuristic';
    }

    public function driver(): string
    {
        return 'fake';
    }

    public function isReady(): bool
    {
        return true;
    }

    private function overlapScore(string $text, string $hypothesis): float
    {
        $score = 0.12;
        $words = preg_split('/[^\p{L}\p{N}]+/u', $hypothesis, -1, PREG_SPLIT_NO_EMPTY) ?: [];

        foreach ($words as $word) {
            if (mb_strlen($word) < 4) {
                continue;
            }

            if (str_contains($text, $word)) {
                $score += 0.22;
            }
        }

        return min($score, 2.4);
    }

    /**
     * @param  list<string>  $labels
     * @param  list<float>  $scores
     * @return array{sequence: string, labels: list<string>, scores: list<float>}
     */
    private function sorted(string $sequence, array $labels, array $scores): array
    {
        $pairs = [];

        foreach ($labels as $i => $label) {
            $pairs[] = [$label, $scores[$i]];
        }

        usort($pairs, fn (array $a, array $b): int => $b[1] <=> $a[1]);

        return [
            'sequence' => $sequence,
            'labels' => array_column($pairs, 0),
            'scores' => array_column($pairs, 1),
        ];
    }
}
