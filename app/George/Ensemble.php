<?php

namespace App\George;

use App\George\Engines\TransformersEngine;
use App\George\Reasoners\TransformersReasoner;
use App\George\Support\TemperatureScaler;
use App\Jobs\EvaluateSlot;
use App\Models\Evaluation;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * Up to three slots, each in its own process:
 *
 *   a  zero-shot NLI        lexical evidence
 *   b  second NLI head      lexical evidence, different training
 *   c  instruct LLM readout decision evidence
 *
 * Readouts are pooled by weight, then sharpened when the slots agree and
 * flattened when they do not.
 */
final class Ensemble
{
    public const SLOTS = ['a', 'b', 'c'];

    public static function configured(): bool
    {
        return (bool) config('george.ensemble')
            && filled(config('george.model_b'))
            && config('george.model_b') !== config('george.model');
    }

    public static function reasonerConfigured(): bool
    {
        return (bool) config('george.reasoner')
            && filled(config('george.reasoner_model'));
    }

    /**
     * Slots enabled by configuration.
     *
     * @return list<string>
     */
    public static function slots(): array
    {
        $slots = ['a'];

        if (self::configured()) {
            $slots[] = 'b';
        }

        if (self::reasonerConfigured()) {
            $slots[] = 'c';
        }

        return $slots;
    }

    /**
     * Slots enabled and with weights on disk. Fake engine can always run.
     *
     * @return list<string>
     */
    public static function readySlots(): array
    {
        if (config('george.engine') === 'fake') {
            return self::slots();
        }

        return array_values(array_filter(
            self::slots(),
            fn (string $slot): bool => self::slotIsCached($slot),
        ));
    }

    public static function ready(): bool
    {
        return count(self::readySlots()) >= 2;
    }

    public static function slotIsCached(string $slot): bool
    {
        return $slot === 'c'
            ? TransformersReasoner::modelIsCached(self::model('c'))
            : TransformersEngine::modelIsCached(self::model($slot));
    }

    public static function model(string $slot): string
    {
        return match ($slot) {
            'b' => (string) config('george.model_b'),
            'c' => (string) config('george.reasoner_model'),
            default => (string) config('george.model'),
        };
    }

    /**
     * @return list<string>
     */
    public static function models(): array
    {
        return array_map(fn (string $slot): string => self::model($slot), self::readySlots());
    }

    public static function queue(string $slot): string
    {
        return match ($slot) {
            'b' => (string) config('george.queue_b'),
            'c' => (string) config('george.queue_c'),
            default => (string) config('george.queue_a'),
        };
    }

    public static function applySlot(string $slot): void
    {
        config(['george.queue' => self::queue($slot)]);

        if ($slot !== 'c') {
            config(['george.model' => self::model($slot)]);
        }
    }

    public static function dispatch(Evaluation $evaluation): void
    {
        foreach (self::readySlots() as $slot) {
            EvaluateSlot::dispatch($evaluation, $slot);
        }
    }

    /**
     * Fake (and tests) run every slot in-process. Real models fork one PHP
     * process per slot so each ONNX graph stays in its own address space.
     */
    public static function runSync(Evaluation $evaluation): void
    {
        $slots = self::readySlots();

        if (config('george.engine') === 'fake') {
            $factory = app(EngineFactory::class);

            foreach ($slots as $slot) {
                (new EvaluateSlot($evaluation, $slot))->handle($factory);
            }

            return;
        }

        self::runInProcesses($evaluation, $slots);
        $evaluation->refresh();

        if ($evaluation->status !== 'done') {
            throw new RuntimeException($evaluation->error ?: 'The ensemble did not finish every slot.');
        }
    }

    /**
     * @param  list<string>  $slots
     */
    private static function runInProcesses(Evaluation $evaluation, array $slots): void
    {
        $php = PHP_BINARY;
        $artisan = base_path('artisan');
        $id = $evaluation->id;
        $timeout = (int) config('george.job_timeout', 180);

        $processes = [];

        foreach ($slots as $slot) {
            $process = new Process(
                [$php, '-d', 'memory_limit=4096M', $artisan, 'george:score', $id, '--slot='.$slot],
                base_path(),
            );
            $process->setTimeout($timeout);
            $process->setIdleTimeout($timeout);
            $process->start();
            $processes[$slot] = $process;
        }

        $errors = [];

        foreach ($processes as $slot => $process) {
            $process->wait();

            if (! $process->isSuccessful()) {
                $errors[] = '['.$slot.'] '.trim($process->getErrorOutput());
            }
        }

        if ($errors !== []) {
            throw new RuntimeException(trim(implode("\n", $errors)) ?: 'Ensemble worker processes failed.');
        }
    }

    /**
     * @param  array{answers: list<array<string, mixed>>, meta: array<string, mixed>}  $result
     */
    public static function recordSlot(Evaluation $evaluation, string $slot, array $result): Evaluation
    {
        $expected = self::readySlots();

        return retry(8, function () use ($evaluation, $slot, $result, $expected): Evaluation {
            return DB::transaction(function () use ($evaluation, $slot, $result, $expected): Evaluation {
                $evaluation = Evaluation::query()->lockForUpdate()->findOrFail($evaluation->id);
                $slots = $evaluation->slots ?? [];
                $slots[$slot] = $result;
                $evaluation->slots = $slots;

                $ordered = [];

                foreach ($expected as $name) {
                    if (isset($slots[$name])) {
                        $ordered[$name] = $slots[$name];
                    }
                }

                if (count($ordered) === count($expected)) {
                    $merged = self::mergeSlots($ordered);
                    $evaluation->status = 'done';
                    $evaluation->result = $merged;
                    $evaluation->error = null;
                    $evaluation->model = $merged['meta']['model'] ?? null;
                    $evaluation->engine = $merged['meta']['engine'] ?? 'ensemble';
                    $evaluation->elapsed_ms = (int) max(
                        0,
                        (int) round($evaluation->created_at?->diffInMilliseconds(now()) ?? 0),
                    );
                } else {
                    $evaluation->status = 'running';
                }

                $evaluation->save();

                return $evaluation;
            });
        }, 40);
    }

    /**
     * Two-slot convenience wrapper around mergeSlots().
     *
     * @param  array{answers: list<array<string, mixed>>, meta: array<string, mixed>}  $left
     * @param  array{answers: list<array<string, mixed>>, meta: array<string, mixed>}  $right
     * @return array{answers: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function merge(array $left, array $right): array
    {
        return self::mergeSlots(['a' => $left, 'b' => $right]);
    }

    /**
     * Weighted pooling of every slot's readout, sharpened by agreement.
     *
     * @param  array<string, array{answers: list<array<string, mixed>>, meta: array<string, mixed>}>  $bySlot
     * @return array{answers: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public static function mergeSlots(array $bySlot): array
    {
        if ($bySlot === []) {
            throw new RuntimeException('Nothing to merge.');
        }

        $weights = self::normalizedWeights(array_keys($bySlot));
        $first = reset($bySlot);
        $answers = [];

        foreach ($first['answers'] as $i => $reference) {
            $perSlot = [];

            foreach ($bySlot as $slot => $result) {
                $perSlot[$slot] = $result['answers'][$i] ?? $reference;
            }

            $answers[] = match ($reference['type'] ?? '') {
                'noul' => self::mergeNoul($reference, $perSlot, $weights),
                'choice' => self::mergeChoice($reference, $perSlot, $weights),
                'score' => self::mergeScore($reference, $perSlot, $weights),
                default => $reference,
            };
        }

        $models = [];
        $passes = 0;
        $englishWarning = false;
        $tokenWarning = false;

        foreach ($bySlot as $slot => $result) {
            $models[] = (string) ($result['meta']['model'] ?? self::model($slot));
            $passes += (int) ($result['meta']['forward_passes'] ?? 0);
            $englishWarning = $englishWarning || (bool) ($result['meta']['english_warning'] ?? false);
            $tokenWarning = $tokenWarning || (bool) ($result['meta']['token_warning'] ?? false);
        }

        return [
            'answers' => $answers,
            'meta' => [
                'model' => implode(' + ', $models),
                'engine' => 'ensemble',
                'ensemble' => $models,
                'slots' => array_keys($bySlot),
                'weights' => array_map(fn (float $w): float => round($w, 3), $weights),
                'reasoner' => isset($bySlot['c']),
                'calibrated' => false,
                'temperature' => $first['meta']['temperature'] ?? 1.0,
                'locale' => $first['meta']['locale'] ?? 'en',
                'english_warning' => $englishWarning,
                'forward_passes' => $passes,
                'token_warning' => $tokenWarning,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, array<string, mixed>>  $perSlot
     * @param  array<string, float>  $weights
     * @return array<string, mixed>
     */
    private static function mergeNoul(array $reference, array $perSlot, array $weights): array
    {
        $vectors = [];

        foreach ($perSlot as $slot => $answer) {
            $p = min(max((float) ($answer['p_yes'] ?? 0.5), 0.0), 1.0);
            $vectors[$slot] = [$p, 1 - $p];
        }

        $pooled = self::pool($vectors, $weights);
        $p = min(max($pooled[0], 0.0), 1.0);

        $merged = $reference;
        $merged['p_yes'] = round($p, 4);
        $merged['p_no'] = round(1 - $p, 4);

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, array<string, mixed>>  $perSlot
     * @param  array<string, float>  $weights
     * @return array<string, mixed>
     */
    private static function mergeChoice(array $reference, array $perSlot, array $weights): array
    {
        $labels = [];
        $applies = [];

        foreach ($perSlot as $answer) {
            foreach ($answer['distribution'] ?? [] as $row) {
                $label = (string) ($row['label'] ?? '');

                if (! in_array($label, $labels, true)) {
                    $labels[] = $label;
                    $applies[$label] = $row['applies'] ?? $label;
                }
            }
        }

        $vectors = [];

        foreach ($perSlot as $slot => $answer) {
            $byLabel = [];

            foreach ($answer['distribution'] ?? [] as $row) {
                $byLabel[(string) ($row['label'] ?? '')] = (float) ($row['score'] ?? 0);
            }

            $vectors[$slot] = array_map(fn (string $label): float => $byLabel[$label] ?? 0.0, $labels);
        }

        $pooled = self::pool($vectors, $weights);
        $distribution = [];

        foreach ($labels as $i => $label) {
            $distribution[] = [
                'label' => $label,
                'applies' => $applies[$label],
                'score' => round($pooled[$i], 4),
            ];
        }

        usort($distribution, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        $merged = $reference;
        $merged['distribution'] = $distribution;
        $merged['top'] = $distribution[0]['label'] ?? null;

        return $merged;
    }

    /**
     * @param  array<string, mixed>  $reference
     * @param  array<string, array<string, mixed>>  $perSlot
     * @param  array<string, float>  $weights
     * @return array<string, mixed>
     */
    private static function mergeScore(array $reference, array $perSlot, array $weights): array
    {
        $levels = $reference['distribution'] ?? [];
        $n = count($levels);
        $vectors = [];

        foreach ($perSlot as $slot => $answer) {
            $byIndex = [];

            foreach ($answer['distribution'] ?? [] as $row) {
                $byIndex[(int) ($row['index'] ?? 0)] = (float) ($row['score'] ?? 0);
            }

            $vector = [];

            for ($i = 1; $i <= $n; $i++) {
                $vector[] = $byIndex[$i] ?? 0.0;
            }

            $vectors[$slot] = $vector;
        }

        $pooled = self::pool($vectors, $weights);
        $distribution = [];

        foreach ($levels as $i => $row) {
            $distribution[] = [
                'label' => $row['label'],
                'index' => $row['index'] ?? ($i + 1),
                'score' => round($pooled[$i], 4),
            ];
        }

        $merged = $reference;
        $merged['distribution'] = $distribution;
        $merged['position'] = round(self::weightedPosition($pooled), 3);

        return $merged;
    }

    /**
     * Weighted mean of the slot distributions, then temperature by agreement.
     *
     * @param  array<string, list<float>>  $vectors
     * @param  array<string, float>  $weights
     * @return list<float>
     */
    private static function pool(array $vectors, array $weights): array
    {
        $slots = array_keys($vectors);
        $n = count(reset($vectors) ?: []);

        if ($n === 0) {
            return [];
        }

        $mean = array_fill(0, $n, 0.0);

        foreach ($vectors as $slot => $vector) {
            $w = $weights[$slot] ?? 0.0;

            for ($i = 0; $i < $n; $i++) {
                $mean[$i] += $w * ($vector[$i] ?? 0.0);
            }
        }

        $pairs = 0;
        $distance = 0.0;

        for ($x = 0; $x < count($slots); $x++) {
            for ($y = $x + 1; $y < count($slots); $y++) {
                $l1 = 0.0;

                for ($i = 0; $i < $n; $i++) {
                    $l1 += abs(($vectors[$slots[$x]][$i] ?? 0.0) - ($vectors[$slots[$y]][$i] ?? 0.0));
                }

                $distance += min($l1 / 2, 1.0);
                $pairs++;
            }
        }

        $agreement = $pairs > 0 ? 1 - ($distance / $pairs) : 1.0;

        return (new TemperatureScaler(self::temperature($agreement)))->scale($mean);
    }

    /**
     * @param  list<string>  $slots
     * @return array<string, float>
     */
    public static function normalizedWeights(array $slots): array
    {
        $raw = [];

        foreach ($slots as $slot) {
            $raw[$slot] = max(self::weight($slot), 0.0);
        }

        $sum = array_sum($raw);

        if ($sum <= 0.0) {
            $n = max(count($slots), 1);

            return array_fill_keys($slots, 1 / $n);
        }

        return array_map(fn (float $w): float => $w / $sum, $raw);
    }

    public static function weight(string $slot): float
    {
        $defaults = ['a' => 0.25, 'b' => 0.15, 'c' => 0.60];
        $key = 'george.weight_'.$slot;

        if (! function_exists('app') || ! app()->bound('config')) {
            return $defaults[$slot] ?? 0.0;
        }

        return (float) config($key, $defaults[$slot] ?? 0.0);
    }

    /**
     * T < 1 sharpens. Full agreement → 0.7; disagreement → 1.25.
     */
    private static function temperature(float $agreement): float
    {
        $agreement = min(max($agreement, 0.0), 1.0);

        return 1.25 - (0.55 * $agreement);
    }

    /**
     * @param  list<float>  $probabilities
     */
    private static function weightedPosition(array $probabilities): float
    {
        $position = 0.0;

        foreach ($probabilities as $i => $probability) {
            $position += ($i + 1) * $probability;
        }

        return $position;
    }
}
