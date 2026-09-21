<?php

namespace App\George\Contracts;

interface Engine
{
    /**
     * @param  list<string>  $labels
     * @return array{sequence: string, labels: list<string>, scores: list<float>}
     */
    public function classify(
        string $sequence,
        array $labels,
        bool $multiLabel = false,
        string $hypothesisTemplate = '{}',
    ): array;

    public function warmup(): void;

    public function modelName(): string;

    public function driver(): string;

    public function isReady(): bool;
}
