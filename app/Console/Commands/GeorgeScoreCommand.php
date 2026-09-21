<?php

namespace App\Console\Commands;

use App\George\EngineFactory;
use App\George\Ensemble;
use App\Jobs\EvaluateSlot;
use App\Models\Evaluation;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;

#[AsCommand(name: 'george:score', description: 'Run one ensemble slot for an evaluation (used by the worker processes).')]
class GeorgeScoreCommand extends Command
{
    protected $signature = 'george:score
        {evaluation : Evaluation UUID}
        {--slot=a : Ensemble slot a, b or c}';

    public function handle(EngineFactory $factory): int
    {
        $slot = (string) $this->option('slot');

        if (! in_array($slot, Ensemble::SLOTS, true)) {
            $this->components->error('Slot must be a, b or c.');

            return self::FAILURE;
        }

        ini_set('memory_limit', $slot === 'c' ? '4096M' : '2048M');

        $evaluation = Evaluation::query()->find($this->argument('evaluation'));

        if ($evaluation === null) {
            $this->components->error('Evaluation not found.');

            return self::FAILURE;
        }

        (new EvaluateSlot($evaluation, $slot))->handle($factory);

        return self::SUCCESS;
    }
}
