<?php

namespace App\George\Support;

final class TemperatureScaler
{
    public function __construct(private readonly float $temperature) {}

    public function temperature(): float
    {
        return $this->temperature;
    }

    /**
     * Flatten or sharpen a discrete distribution. T=1 is a no-op.
     *
     * @param  list<float>  $probabilities
     * @return list<float>
     */
    public function scale(array $probabilities): array
    {
        $t = max($this->temperature, 1e-6);

        if (abs($t - 1.0) < 1e-9 || count($probabilities) < 2) {
            return $this->renormalize($probabilities);
        }

        $logits = array_map(
            fn (float $p): float => log(max($p, 1e-12)) / $t,
            $probabilities,
        );

        return Softmax::normalize($logits);
    }

    /**
     * Scale a single Bernoulli probability via logit / T.
     */
    public function scaleBernoulli(float $probability): float
    {
        $t = max($this->temperature, 1e-6);
        $p = min(max($probability, 1e-12), 1 - 1e-12);

        if (abs($t - 1.0) < 1e-9) {
            return $probability;
        }

        $logit = log($p / (1 - $p)) / $t;

        return 1 / (1 + exp(-$logit));
    }

    /**
     * @param  list<float>  $probabilities
     * @return list<float>
     */
    private function renormalize(array $probabilities): array
    {
        $sum = array_sum($probabilities);

        if ($sum <= 0.0) {
            $n = count($probabilities);

            return $n === 0 ? [] : array_fill(0, $n, 1 / $n);
        }

        return array_map(fn (float $p): float => $p / $sum, $probabilities);
    }
}
