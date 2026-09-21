<?php

namespace App\Console\Commands;

use App\George\EngineFactory;
use App\George\Ensemble;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Process\Process;

#[AsCommand(name: 'george:work', description: 'Load George into memory and process evaluation jobs.')]
class GeorgeWorkCommand extends Command
{
    protected $signature = 'george:work
        {--slot=all : a, b, c, or all (one process per ready slot)}
        {--tries=1 : Number of times to attempt a job}
        {--timeout=180 : Seconds a child process can run}
        {--memory=4096 : Memory limit in megabytes}
        {--sleep=1 : Seconds to wait when no job is available}';

    public function handle(EngineFactory $factory): int
    {
        $slot = (string) $this->option('slot');

        if (in_array($slot, ['all', 'both'], true)) {
            $slots = Ensemble::readySlots();

            if (count($slots) > 1) {
                return $this->supervise($slots);
            }

            return $this->workOne($factory, null);
        }

        if (! in_array($slot, Ensemble::SLOTS, true)) {
            $this->components->error('Slot must be a, b, c or all.');

            return self::FAILURE;
        }

        // Slot A only moves to its own queue when it has company: alone,
        // evaluations go through the default queue as a single engine.
        if ($slot !== 'a' || Ensemble::ready()) {
            Ensemble::applySlot($slot);
        }

        return $this->workOne($factory, $slot);
    }

    private function workOne(EngineFactory $factory, ?string $slot): int
    {
        ini_set('memory_limit', ((int) $this->option('memory')).'M');

        $runner = $slot === 'c' ? $factory->makeReasoner() : $factory->make();

        $this->components->info('Loading '.$runner->modelName().' ('.$runner->driver().') on '.config('george.queue').'…');

        $started = microtime(true);
        $runner->warmup();
        $elapsed = microtime(true) - $started;

        $this->components->info(sprintf('George is ready in %.1fs. Waiting for jobs.', $elapsed));

        return $this->call('queue:work', [
            '--queue' => config('george.queue'),
            '--tries' => $this->option('tries'),
            '--timeout' => $this->option('timeout'),
            '--memory' => $this->option('memory'),
            '--sleep' => $this->option('sleep'),
        ]);
    }

    /**
     * @param  list<string>  $slots
     */
    private function supervise(array $slots): int
    {
        $this->components->info('Starting '.count($slots).' George processes (slots '.implode(', ', $slots).')…');

        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $memory = (string) $this->option('memory');
        $tries = (string) $this->option('tries');
        $timeout = (string) $this->option('timeout');
        $sleep = (string) $this->option('sleep');

        $command = fn (string $slot): array => [
            $php,
            '-d',
            'memory_limit='.$memory.'M',
            $artisan,
            'george:work',
            '--slot='.$slot,
            '--tries='.$tries,
            '--timeout='.$timeout,
            '--memory='.$memory,
            '--sleep='.$sleep,
        ];

        $processes = [];

        foreach ($slots as $slot) {
            $process = new Process($command($slot), base_path());
            $process->setTimeout(null);
            $tag = '['.strtoupper($slot).'] ';
            $process->start(fn (string $type, string $buffer): mixed => $this->output->write($tag.$buffer));
            $processes[$slot] = $process;
        }

        if (function_exists('pcntl_async_signals')) {
            pcntl_async_signals(true);
            $stop = function () use ($processes): void {
                foreach ($processes as $process) {
                    $process->stop(3);
                }
            };
            pcntl_signal(SIGINT, $stop);
            pcntl_signal(SIGTERM, $stop);
        }

        $running = true;

        while ($running) {
            $running = false;

            foreach ($processes as $process) {
                if ($process->isRunning()) {
                    $running = true;
                }
            }

            usleep(200_000);
        }

        foreach ($processes as $process) {
            if (! $process->isSuccessful()) {
                return self::FAILURE;
            }
        }

        return self::SUCCESS;
    }
}
