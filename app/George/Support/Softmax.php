<?php

namespace App\George\Support;

final class Softmax
{
    /**
     * @param  list<float>  $values
     * @return list<float>
     */
    public static function normalize(array $values): array
    {
        if ($values === []) {
            return [];
        }

        $max = max($values);
        $exps = array_map(fn (float $value): float => exp($value - $max), $values);
        $sum = array_sum($exps);

        if ($sum <= 0.0) {
            $n = count($values);

            return array_fill(0, $n, 1 / $n);
        }

        return array_map(fn (float $exp): float => $exp / $sum, $exps);
    }
}
