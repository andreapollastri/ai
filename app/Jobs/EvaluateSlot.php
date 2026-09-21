<?php

namespace App\Jobs;

use App\George\Classifier;
use App\George\EngineFactory;
use App\George\Ensemble;
use App\George\Judge;
use App\George\Support\TemperatureScaler;
use App\Models\Evaluation;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Throwable;

class EvaluateSlot implements ShouldQueue
{
    use Queueable;

    public int $tries = 1;

    public int $timeout = 180;

    public function __construct(
        public Evaluation $evaluation,
        public string $slot,
    ) {
        $this->onQueue(Ensemble::queue($slot));
        $this->timeout = (int) config('george.job_timeout', 180);
    }

    public function handle(EngineFactory $factory): void
    {
        $situation = $this->evaluation->situation;
        $conditions = $this->evaluation->conditions ?? [];
        $locale = $this->evaluation->locale;

        if ($this->slot === 'c') {
            $result = (new Judge($factory->makeReasoner()))->evaluate($situation, $conditions, $locale);
        } else {
            $classifier = new Classifier(
                $factory->make(Ensemble::model($this->slot)),
                new TemperatureScaler((float) config('george.temperature')),
            );
            $result = $classifier->evaluate($situation, $conditions, $locale);
        }

        Ensemble::recordSlot($this->evaluation, $this->slot, $result);
    }

    public function failed(?Throwable $exception): void
    {
        $this->evaluation->update([
            'status' => 'failed',
            'error' => $exception?->getMessage() ?: 'George could not evaluate this situation.',
        ]);
    }
}
