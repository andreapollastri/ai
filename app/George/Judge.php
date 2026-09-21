<?php

namespace App\George;

use App\George\Contracts\Reasoner;
use App\George\Support\Distributions;
use App\George\Support\Language;
use App\George\Support\Prompt;

/**
 * Same answer schema as Classifier, produced by a Reasoner instead of an
 * NLI head. Slot C of the ensemble.
 */
final class Judge
{
    public function __construct(private readonly Reasoner $reasoner) {}

    /**
     * @param  list<array<string, mixed>>  $conditions
     * @return array{answers: list<array<string, mixed>>, meta: array<string, mixed>}
     */
    public function evaluate(string $situation, array $conditions, ?string $locale = 'auto'): array
    {
        $answers = [];
        $passes = 0;

        foreach ($conditions as $condition) {
            $question = trim((string) ($condition['prompt'] ?? ''));
            $options = Prompt::options($condition);
            $scores = $this->reasoner->judge($situation, $question, $options);
            $passes += $this->reasoner->lastPasses();

            $answers[] = match ($condition['type']) {
                'noul' => $this->noul($condition, $scores),
                'choice' => $this->choice($condition, $options, $scores),
                'score' => $this->score($condition, $scores),
            };
        }

        return [
            'answers' => $answers,
            'meta' => [
                'model' => $this->reasoner->modelName(),
                'engine' => $this->reasoner->driver(),
                'calibrated' => false,
                'temperature' => 1.0,
                'locale' => 'en',
                'english_warning' => Language::looksItalian($situation),
                'forward_passes' => $passes,
                'token_warning' => mb_strlen($situation) > 1600,
            ],
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<float>  $scores
     * @return array<string, mixed>
     */
    private function noul(array $condition, array $scores): array
    {
        $pYes = min(max((float) ($scores[0] ?? 0.5), 0.0), 1.0);
        $no = trim((string) ($condition['no'] ?? ''));

        return [
            'type' => 'noul',
            'name' => $condition['name'],
            'prompt' => $condition['prompt'] ?? '',
            'yes' => trim((string) ($condition['yes'] ?? $condition['prompt'] ?? '')),
            'no' => $no !== '' ? $no : null,
            'p_yes' => round($pYes, 4),
            'p_no' => round(1 - $pYes, 4),
        ];
    }

    /**
     * @param  array<string, mixed>  $condition
     * @param  list<string>  $options
     * @param  list<float>  $scores
     * @return array<string, mixed>
     */
    private function choice(array $condition, array $options, array $scores): array
    {
        $distribution = [];

        foreach (array_values($condition['options'] ?? []) as $i => $option) {
            $distribution[] = [
                'label' => trim((string) ($option['label'] ?? '')),
                'applies' => $options[$i] ?? '',
                'score' => round((float) ($scores[$i] ?? 0), 4),
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
     * @param  array<string, mixed>  $condition
     * @param  list<float>  $scores
     * @return array<string, mixed>
     */
    private function score(array $condition, array $scores): array
    {
        $levels = array_values($condition['levels'] ?? []);
        $distribution = [];

        foreach ($levels as $i => $level) {
            $distribution[] = [
                'label' => trim((string) $level),
                'index' => $i + 1,
                'score' => round((float) ($scores[$i] ?? 0), 4),
            ];
        }

        return [
            'type' => 'score',
            'name' => $condition['name'],
            'prompt' => $condition['prompt'] ?? '',
            'position' => round(Distributions::weightedPosition($scores), 3),
            'min' => 1,
            'max' => count($levels),
            'distribution' => $distribution,
        ];
    }
}
