<?php

namespace App\George\Support;

final class Distributions
{
    /**
     * Map pipeline output (sorted by score desc) back onto the original label order.
     *
     * @param  list<string>  $labels
     * @param  array{labels: list<string>, scores: list<float>}  $output
     * @return list<float>
     */
    public static function scoresInOrder(array $labels, array $output): array
    {
        $byLabel = [];

        foreach ($output['labels'] as $i => $label) {
            $byLabel[$label] = (float) $output['scores'][$i];
        }

        return array_map(fn (string $label): float => $byLabel[$label] ?? 0.0, $labels);
    }

    /**
     * Expected value on a 1..n scale: Σ pᵢ · i
     *
     * @param  list<float>  $probabilities  already in scale order
     */
    public static function weightedPosition(array $probabilities): float
    {
        $position = 0.0;

        foreach ($probabilities as $i => $probability) {
            $position += ($i + 1) * $probability;
        }

        return $position;
    }
}
