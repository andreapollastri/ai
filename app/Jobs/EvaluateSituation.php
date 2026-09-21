<?php

namespace App\Jobs;

use App\George\Classifier;
use App\Models\Evaluation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EvaluateSituation implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(public Evaluation $evaluation)
    {
        $this->onQueue((string) config('george.queue'));
        $this->timeout = (int) config('george.job_timeout', 180);
    }

    public function handle(Classifier $classifier): void
    {
        $started = microtime(true);

        $this->evaluation->update([
            'status' => 'running',
        ]);

        $result = $classifier->evaluate(
            $this->evaluation->situation,
            $this->evaluation->conditions ?? [],
            $this->evaluation->locale,
        );

        $elapsed = (int) round((microtime(true) - $started) * 1000);

        $this->evaluation->update([
            'status' => 'done',
            'result' => $result,
            'error' => null,
            'elapsed_ms' => $elapsed,
            'model' => $result['meta']['model'] ?? null,
            'engine' => $result['meta']['engine'] ?? null,
        ]);
    }

    public function failed(?Throwable $exception): void
    {
        $this->evaluation->update([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?: 'George could not evaluate this situation.',
        ]);
    }
}
