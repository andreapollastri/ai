<?php

namespace App\George;

use App\George\Contracts\Engine;
use App\George\Contracts\Reasoner;
use App\George\Engines\FakeEngine;
use App\George\Engines\TransformersEngine;
use App\George\Reasoners\FakeReasoner;
use App\George\Reasoners\LlamaReasoner;
use App\George\Reasoners\TransformersReasoner;

/**
 * One instance per model, kept for the life of the process so a warmed
 * worker does not reload weights on every job.
 */
final class EngineFactory
{
    /** @var array<string, Engine> */
    private array $engines = [];

    private ?Reasoner $reasoner = null;

    public function make(?string $model = null): Engine
    {
        $model ??= (string) config('george.model');

        return $this->engines[$model] ??= match (config('george.engine')) {
            'fake' => new FakeEngine,
            'transformers' => new TransformersEngine($model),
            default => TransformersEngine::modelIsCached($model)
                ? new TransformersEngine($model)
                : new FakeEngine,
        };
    }

    public function makeReasoner(): Reasoner
    {
        return $this->reasoner ??= $this->buildReasoner();
    }

    /**
     * Slot C runs either in this process (ONNX) or against llama-server,
     * and falls back to the heuristic when its backend is not there.
     */
    private function buildReasoner(): Reasoner
    {
        if (config('george.engine') === 'fake') {
            return new FakeReasoner;
        }

        $reasoner = Ensemble::reasonerBackend() === 'llama'
            ? new LlamaReasoner
            : new TransformersReasoner;

        if (config('george.engine') === 'transformers') {
            return $reasoner;
        }

        return $reasoner->isReady() ? $reasoner : new FakeReasoner;
    }

    public function forget(): void
    {
        $this->engines = [];
        $this->reasoner = null;
    }
}
