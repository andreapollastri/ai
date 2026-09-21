<?php

namespace App\George;

use App\George\Contracts\Engine;
use App\George\Support\Distributions;
use App\George\Support\Hypotheses;
use App\George\Support\Language;
use App\George\Support\TemperatureScaler;

final class Classifier
{
    public function __construct(
        private readonly Engine $engine,
        private readonly TemperatureScaler $scaler,
    ) {}

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return array{answers: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function evaluate(string $situation, array $conditions, ?string $locale = 'auto'): array
    {
        $answers = [];
        $passes = 0;

        foreach ($conditions as $condition) {
            $answers[] = match ($condition['type']) {
                'noul' => $this->noul($situation, $condition, $passes),
                'choice' => $this->choice($situation, $condition, $passes),
                'score' => $this->score($situation, $condition, $passes),
                default => throw new \InvalidArgumentException('Unknown condition type.'),
            };
        }

        return [
            'answers' => $answers,
            'meta' => [
                'model' => $this->engine->modelName(),
                'engine' => $this->engine->driver(),
                'calibrated' => false,
                'temperature' => $this->scaler->temperature(),
                'locale' => 'en',
                'english_warning' => Language::looksItalian($situation),
                'forward_passes' => $passes,
                'token_warning' => mb_strlen($situation) > 1600,
            ],
        ];
    }

    /**
     * Competing entailment of the yes vs no criterion, given the situation
     * and the question Jev would receive as `instructions`.
     *
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function noul(string $situation, array $condition, int &$passes): array
    {
        $yes = trim((string) ($condition['yes'] ?? $condition['prompt'] ?? ''));
        $no = trim((string) ($condition['no'] ?? ''));
        $prompt = trim((string) ($condition['prompt'] ?? ''));
        $sequence = Hypotheses::premise($situation, $prompt);
        $yesPhrase = Hypotheses::phrase($yes);

        if ($no !== '') {
            $noPhrase = Hypotheses::phrase($no);
            $passes += 2;
            $output = $this->engine->classify(
                $sequence,
                [$yesPhrase, $noPhrase],
                false,
                Hypotheses::NOUL,
            );
            $ordered = Distributions::scoresInOrder([$yesPhrase, $noPhrase], $output);
            $pYes = $ordered[0];
        } else {
            $passes += 1;
            $output = $this->engine->classify(
                $sequence,
                [$yesPhrase],
                true,
                Hypotheses::NOUL,
            );
            $pYes = (float) ($output['scores'][0] ?? 0);
        }

        $pYes = $this->scaler->scaleBernoulli(min(max($pYes, 0.0), 1.0));

        return [
            'type' => 'noul',
            'name' => $condition['name'],
            'prompt' => $condition['prompt'] ?? '',
            'yes' => $yes,
            'no' => $no !== '' ? $no : null,
            'p_yes' => round($pYes, 4),
            'p_no' => round(1 - $pYes, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function choice(string $situation, array $condition, int &$passes): array
    {
        // The category claim stands on its own; the question only dilutes it.
        $sequence = Hypotheses::premise($situation);
        $options = array_values($condition['options'] ?? []);
        $hypotheses = [];
        $labels = [];

        foreach ($options as $option) {
            $label = trim((string) ($option['label'] ?? ''));
            $applies = trim((string) ($option['applies'] ?? ''));
            $labels[] = $label;
            $hypotheses[] = Hypotheses::choice($label, $applies);
        }

        $passes += count($hypotheses);
        $output = $this->engine->classify(
            $sequence,
            $hypotheses,
            false,
            Hypotheses::CHOICE,
        );
        $scores = $this->scaler->scale(Distributions::scoresInOrder($hypotheses, $output));

        $distribution = [];

        foreach ($labels as $i => $label) {
            $distribution[] = [
                'label' => $label,
                'applies' => $hypotheses[$i],
                'score' => round($scores[$i], 4),
            ];
        }

        usort($distribution, fn (array $a, array $b): int => $b['score'] <=> $a['score']);

        return [
            'type' => 'choice',
            'name' => $condition['name'],
            'prompt' => $condition['prompt'] ?? '',
            'top' => $distribution[0]['label'] ?? null,
            'distribution' => $distribution,
        ];
    }

    /**
     * Exclusive softmax over scale points, then a weighted position.
     *
     * @param  array<string, mixed>  $condition
     * @return array<string, mixed>
     */
    private function score(string $situation, array $condition, int &$passes): array
    {
        $prompt = trim((string) ($condition['prompt'] ?? ''));
        $sequence = Hypotheses::premise($situation, $prompt);
        $levels = array_values(array_map(
            fn (mixed $level): string => trim((string) $level),
            $condition['levels'] ?? [],
        ));
        $phrases = array_map(fn (string $level): string => Hypotheses::phrase($level), $levels);

        $passes += count($phrases);
        $output = $this->engine->classify(
            $sequence,
            $phrases,
            false,
            Hypotheses::SCORE,
        );
        $raw = Distributions::scoresInOrder($phrases, $output);
        $scores = $this->scaler->scale($raw);
        $position = Distributions::weightedPosition($scores);

        $distribution = [];

        foreach ($levels as $i => $level) {
            $distribution[] = [
                'label' => $level,
                'index' => $i + 1,
                'score' => round($scores[$i], 4),
            ];
        }

        return [
            'type' => 'score',
            'name' => $condition['name'],
            'prompt' => $condition['prompt'] ?? '',
            'position' => round($position, 3),
            'min' => 1,
            'max' => count($levels),
            'distribution' => $distribution,
        ];
    }
}
