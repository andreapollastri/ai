<?php

namespace App\Console\Commands;

use App\George\EngineFactory;
use App\George\Support\Prompt;
use Illuminate\Console\Command;
use Symfony\Component\Console\Attribute\AsCommand;
use Throwable;

/**
 * Scores the configured reasoner against the reference answers in
 * bench/blind-set.php, on the machine that will actually run it.
 */
#[AsCommand(name: 'george:bench', description: 'Score the reasoner against the blind set of reference answers.')]
class GeorgeBenchCommand extends Command
{
    protected $signature = 'george:bench
        {--json= : Also write the full readouts to this file}
        {--quiet-rows : Only print the summary}';

    public function handle(EngineFactory $factory): int
    {
        ini_set('memory_limit', '8192M');

        $path = base_path('bench/blind-set.php');

        if (! is_file($path)) {
            $this->components->error('bench/blind-set.php is missing.');

            return self::FAILURE;
        }

        $cases = require $path;
        $reasoner = $factory->makeReasoner();

        $this->components->info('Loading '.$reasoner->modelName().' ('.$reasoner->driver().')…');

        $started = microtime(true);

        try {
            $reasoner->warmup();
        } catch (Throwable $e) {
            $this->components->error('The reasoner could not load: '.$e->getMessage());

            return self::FAILURE;
        }

        $this->components->info(sprintf('Ready in %.1fs. Scoring %d situations.', microtime(true) - $started, count($cases)));
        $this->newLine();

        $rows = [];
        $agree = 0;
        $total = 0;
        $cancelled = 0;
        $elapsed = 0.0;

        foreach ($cases as $case) {
            foreach ($case['conditions'] as $condition) {
                $human = $case['human'][$condition['name']] ?? null;

                if ($human === null) {
                    continue;
                }

                $options = Prompt::options($condition);

                $at = microtime(true);
                $scores = $reasoner->judge($case['situation'], (string) $condition['prompt'], $options);
                $ms = (int) round((microtime(true) - $at) * 1000);
                $elapsed += $ms;

                $spread = $reasoner->lastSpread();
                [$shown, $ok] = $this->grade($condition, $human, $scores);

                $total++;
                $agree += $ok ? 1 : 0;
                // Above 0.9 the two readouts were opposite: the answer came
                // from where the options sat, not from what they said.
                $cancelled += $spread > 0.9 ? 1 : 0;

                $rows[] = [
                    'case' => $case['id'],
                    'condition' => $condition['name'],
                    'type' => $condition['type'],
                    'george' => $shown,
                    'human' => is_float($human['answer']) ? number_format($human['answer'], 2) : $human['answer'],
                    'agrees' => $ok ? 'yes' : 'NO',
                    'flip' => number_format($spread, 2),
                    'ms' => $ms,
                    'hard' => ! empty($human['hard']),
                    'scores' => array_map(fn (float $s): float => round($s, 4), $scores),
                ];

                if (! $this->option('quiet-rows')) {
                    $this->line(sprintf(
                        '  %-20s %-16s %-22s me=%-10s %s  flip %.2f  %5dms',
                        $case['id'],
                        $condition['name'],
                        $shown,
                        (string) $rows[count($rows) - 1]['human'],
                        $ok ? ' ' : '<',
                        $spread,
                        $ms,
                    ));
                }
            }
        }

        $this->newLine();
        $this->components->twoColumnDetail('<fg=gray>Model</>', $reasoner->modelName());
        $this->components->twoColumnDetail('<fg=gray>Agrees with the reference</>', $agree.' / '.$total);
        $this->components->twoColumnDetail(
            '<fg=gray>Readouts decided by option order</>',
            $cancelled.' / '.$total.($cancelled > 0 ? '  <fg=yellow>(lower is better)</>' : ''),
        );
        $this->components->twoColumnDetail(
            '<fg=gray>Seconds per condition</>',
            $total > 0 ? number_format($elapsed / $total / 1000, 2) : 'n/a',
        );

        if ($file = $this->option('json')) {
            file_put_contents($file, json_encode([
                'model' => $reasoner->modelName(),
                'agree' => $agree,
                'total' => $total,
                'cancelled' => $cancelled,
                'ms_per_condition' => $total > 0 ? (int) round($elapsed / $total) : null,
                'rows' => $rows,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
            $this->components->info('Wrote '.$file);
        }

        return self::SUCCESS;
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  array<string, mixed>  $human
     * @param  list<float>  $scores
     * @return array{0: string, 1: bool}
     */
    private function grade(array $condition, array $human, array $scores): array
    {
        if ($condition['type'] === 'noul') {
            $pYes = (float) ($scores[0] ?? 0);
            $got = $pYes >= 0.5 ? 'yes' : 'no';

            return [sprintf('%s (%.2f)', $got, $pYes), $got === $human['answer']];
        }

        if ($condition['type'] === 'choice') {
            $labels = array_column($condition['options'], 'label');
            $best = 0;

            foreach ($scores as $i => $score) {
                if ($score > $scores[$best]) {
                    $best = $i;
                }
            }

            $got = $labels[$best] ?? '?';

            return [sprintf('%s (%.2f)', $got, $scores[$best]), $got === $human['answer']];
        }

        $position = 0.0;

        foreach ($scores as $i => $score) {
            $position += $i * $score;
        }

        $levels = max(count($condition['levels']) - 1, 1);
        $error = abs($position - (float) $human['answer']) / $levels;

        return [number_format($position, 2), $error < 0.33];
    }
}
