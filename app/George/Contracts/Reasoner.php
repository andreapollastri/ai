<?php

namespace App\George\Contracts;

interface Reasoner
{
    /**
     * Probability over the options, in the order given, that each one is the
     * right answer to the question for this situation.
     *
     * @param  list<string>  $options
     * @return list<float>
     */
    public function judge(string $situation, string $question, array $options): array;

    public function warmup(): void;

    public function modelName(): string;

    public function driver(): string;

    public function isReady(): bool;

    /**
     * Forward passes spent on the last judge() call.
     */
    public function lastPasses(): int;

    /**
     * How far the two debiased readouts of the last judge() call disagreed:
     * 0 when reversing the options changed nothing, 1 when they were opposite.
     * A high value means the answer came from option position, not content.
     */
    public function lastSpread(): float;
}
