<?php

use App\George\Classifier;
use App\George\Engines\FakeEngine;
use App\George\Ensemble;
use App\George\Judge;
use App\George\Reasoners\FakeReasoner;
use App\George\Support\Distributions;
use App\George\Support\Hypotheses;
use App\George\Support\Language;
use App\George\Support\Prompt;
use App\George\Support\Softmax;
use App\George\Support\TemperatureScaler;

it('flags italian input so the UI can warn', function () {
    expect(Language::looksItalian('Il cliente chiede un rimborso per questo ordine'))->toBeTrue()
        ->and(Language::looksItalian('The customer is asking for a refund on this order'))->toBeFalse();
});

it('applies temperature scaling to flatten peaks', function () {
    $scaler = new TemperatureScaler(2.0);
    $scaled = $scaler->scale([0.9, 0.08, 0.02]);

    expect($scaled[0])->toBeLessThan(0.9)
        ->and(array_sum($scaled))->toBeBetween(0.999, 1.001);
});

it('leaves temperature 1 as a no-op after renormalize', function () {
    $scaler = new TemperatureScaler(1.0);
    $scaled = $scaler->scale([0.5, 0.5]);

    expect($scaled[0])->toBeBetween(0.49, 0.51);
});

it('computes a weighted rating position', function () {
    expect(Distributions::weightedPosition([0.1, 0.2, 0.7]))->toEqualWithDelta(2.6, 0.0001);
});

it('maps pipeline output back to original label order', function () {
    $ordered = Distributions::scoresInOrder(
        ['billing', 'logistics'],
        ['labels' => ['logistics', 'billing'], 'scores' => [0.7, 0.3]],
    );

    expect($ordered)->toBe([0.3, 0.7]);
});

it('softmaxes positive logits', function () {
    $out = Softmax::normalize([1.0, 1.0]);

    expect($out[0])->toBeBetween(0.49, 0.51)
        ->and(array_sum($out))->toBeBetween(0.999, 1.001);
});

it('turns criteria into nli fragments', function () {
    expect(Hypotheses::phrase('Does this message convey urgency?'))
        ->toBe('message convey urgency')
        ->and(Hypotheses::phrase('Explicitly time-sensitive or escalating'))
        ->toBe('explicitly time-sensitive or escalating')
        ->and(Hypotheses::choice('billing', 'Refunds, payments and policy exceptions'))
        ->toBe('Refunds, payments and policy exceptions')
        ->and(Hypotheses::premise('A damaged order.', 'Does this convey urgency?'))
        ->toBe("A damaged order.\n\nQuestion: Does this convey urgency?");
});

it('returns noul, choice and score answers from the fake engine', function () {
    $classifier = new Classifier(new FakeEngine, new TemperatureScaler(1.0));

    $result = $classifier->evaluate(
        'A customer emailed at 2am about a damaged order and wants a refund. Payments are involved.',
        [
            [
                'type' => 'noul',
                'name' => 'Urgent',
                'prompt' => 'Is this urgent?',
                'yes' => 'Damaged order and waiting at 2am',
                'no' => 'A routine billing question',
            ],
            [
                'type' => 'choice',
                'name' => 'Team',
                'prompt' => 'Which team?',
                'options' => [
                    ['label' => 'billing', 'applies' => 'Refunds and payments'],
                    ['label' => 'logistics', 'applies' => 'Damaged order in transit'],
                    ['label' => 'support', 'applies' => 'General follow up questions'],
                ],
            ],
            [
                'type' => 'score',
                'name' => 'Goodwill',
                'prompt' => 'How much goodwill?',
                'levels' => ['None', 'Small', 'Refund', 'Apology'],
            ],
        ],
        'en',
    );

    $noul = $result['answers'][0];
    $choice = $result['answers'][1];
    $score = $result['answers'][2];

    expect($noul['type'])->toBe('noul')
        ->and($noul['p_yes'] + $noul['p_no'])->toBeBetween(0.999, 1.001)
        ->and($choice['distribution'])->toHaveCount(3)
        ->and(array_sum(array_column($choice['distribution'], 'score')))->toBeBetween(0.999, 1.001)
        ->and($score['position'])->toBeBetween(1, 4)
        ->and($result['meta']['calibrated'])->toBeFalse()
        ->and($result['meta']['forward_passes'])->toBe(9)
        ->and($result['meta']['engine'])->toBe('fake')
        ->and($result['meta']['english_warning'])->toBeFalse();
});

it('warns when the situation looks italian', function () {
    $classifier = new Classifier(new FakeEngine, new TemperatureScaler(1.0));

    $result = $classifier->evaluate(
        'Il cliente chiede un rimborso per questo ordine danneggiato.',
        [
            [
                'type' => 'noul',
                'name' => 'Urgent',
                'prompt' => 'Is this urgent?',
                'yes' => 'Time sensitive',
                'no' => 'No pressure',
            ],
        ],
    );

    expect($result['meta']['english_warning'])->toBeTrue()
        ->and($result['meta']['locale'])->toBe('en');
});

it('sharpens noul when two models agree', function () {
    $merged = Ensemble::merge(
        [
            'answers' => [[
                'type' => 'noul',
                'name' => 'Urgent',
                'p_yes' => 0.90,
                'p_no' => 0.10,
            ]],
            'meta' => ['model' => 'a', 'forward_passes' => 2, 'temperature' => 1.0],
        ],
        [
            'answers' => [[
                'type' => 'noul',
                'name' => 'Urgent',
                'p_yes' => 0.88,
                'p_no' => 0.12,
            ]],
            'meta' => ['model' => 'b', 'forward_passes' => 2],
        ],
    );

    expect($merged['answers'][0]['p_yes'])->toBeGreaterThan(0.891)
        ->and($merged['meta']['engine'])->toBe('ensemble')
        ->and($merged['meta']['forward_passes'])->toBe(4);
});

it('flattens a choice when two models disagree', function () {
    $merged = Ensemble::merge(
        [
            'answers' => [[
                'type' => 'choice',
                'name' => 'Team',
                'top' => 'billing',
                'distribution' => [
                    ['label' => 'billing', 'applies' => 'refunds', 'score' => 0.80],
                    ['label' => 'logistics', 'applies' => 'damage', 'score' => 0.20],
                ],
            ]],
            'meta' => ['model' => 'a', 'forward_passes' => 2],
        ],
        [
            'answers' => [[
                'type' => 'choice',
                'name' => 'Team',
                'top' => 'logistics',
                'distribution' => [
                    ['label' => 'logistics', 'applies' => 'damage', 'score' => 0.80],
                    ['label' => 'billing', 'applies' => 'refunds', 'score' => 0.20],
                ],
            ]],
            'meta' => ['model' => 'b', 'forward_passes' => 2],
        ],
    );

    $byLabel = [];

    foreach ($merged['answers'][0]['distribution'] as $row) {
        $byLabel[$row['label']] = $row['score'];
    }

    expect($byLabel['billing'])->toBeBetween(0.35, 0.65)
        ->and($byLabel['logistics'])->toBeBetween(0.35, 0.65);
});

it('builds reasoner options from every condition type', function () {
    expect(Prompt::options([
        'type' => 'noul',
        'yes' => 'Revenue affecting and still getting worse',
        'no' => 'Contained, or already recovering on its own',
    ]))->toBe([
        'Yes. Revenue affecting and still getting worse',
        'No. Contained, or already recovering on its own',
    ])
        ->and(Prompt::options(['type' => 'noul', 'prompt' => 'Is it urgent?', 'yes' => 'Time sensitive']))
        ->toBe(['Yes. Time sensitive', 'No. It does not apply'])
        ->and(Prompt::options([
            'type' => 'choice',
            'options' => [
                ['label' => 'billing', 'applies' => 'Refunds and payments'],
                ['label' => 'support', 'applies' => 'General questions'],
            ],
        ]))->toBe(['billing: Refunds and payments', 'support: General questions'])
        ->and(Prompt::options(['type' => 'score', 'levels' => ['Minor', ' Major ']]))
        ->toBe(['Minor', 'Major']);
});

it('renders a chatml prompt with lettered options and no thinking', function () {
    $user = Prompt::user('Production is down.', 'Page someone?', ['Yes. Down', 'No. Fine']);
    $chat = Prompt::chat(Prompt::SYSTEM, $user);

    expect($user)->toContain("Situation:\nProduction is down.")
        ->toContain('Question: Page someone?')
        ->toContain("A. Yes. Down\nB. No. Fine")
        ->toContain('Answer with the letter only.')
        ->and($chat)->toStartWith("<|im_start|>system\n")
        ->toEndWith("<|im_start|>assistant\n<think>\n\n</think>\n\n");
});

it('judges every condition type with the fake reasoner', function () {
    $judge = new Judge(new FakeReasoner);

    $result = $judge->evaluate(
        'Production is down and revenue is zero. Customers are tweeting about failed payments.',
        [
            [
                'type' => 'noul',
                'name' => 'Page',
                'prompt' => 'Should this wake an on call engineer?',
                'yes' => 'Revenue affecting and still getting worse',
                'no' => 'Contained or recovering',
            ],
            [
                'type' => 'choice',
                'name' => 'Team',
                'prompt' => 'Which team?',
                'options' => [
                    ['label' => 'billing', 'applies' => 'Payments and refunds'],
                    ['label' => 'support', 'applies' => 'General questions'],
                ],
            ],
            [
                'type' => 'score',
                'name' => 'Severity',
                'prompt' => 'How severe?',
                'levels' => ['Negligible', 'Minor', 'Major', 'Critical'],
            ],
        ],
    );

    [$noul, $choice, $score] = $result['answers'];

    expect($noul['type'])->toBe('noul')
        ->and($noul['p_yes'])->toBeGreaterThan(0.5)
        ->and($noul['p_yes'] + $noul['p_no'])->toBeBetween(0.999, 1.001)
        ->and($choice['top'])->toBe('billing')
        ->and(array_sum(array_column($choice['distribution'], 'score')))->toBeBetween(0.999, 1.001)
        ->and($score['position'])->toBeBetween(1, 4)
        ->and($score['distribution'])->toHaveCount(4)
        ->and($result['meta']['engine'])->toBe('fake')
        ->and($result['meta']['forward_passes'])->toBe(3);
});

it('pools three slots with the reasoner carrying the decision', function () {
    $slot = fn (string $model, float $pYes): array => [
        'answers' => [['type' => 'noul', 'name' => 'Page', 'p_yes' => $pYes, 'p_no' => 1 - $pYes]],
        'meta' => ['model' => $model, 'forward_passes' => 2],
    ];

    $merged = Ensemble::mergeSlots([
        'a' => $slot('a', 0.20),
        'b' => $slot('b', 0.30),
        'c' => $slot('c', 0.95),
    ]);

    expect($merged['answers'][0]['p_yes'])->toBeGreaterThan(0.5)
        ->and($merged['meta']['slots'])->toBe(['a', 'b', 'c'])
        ->and($merged['meta']['reasoner'])->toBeTrue()
        ->and($merged['meta']['weights']['c'])->toEqualWithDelta(0.6, 0.001)
        ->and($merged['meta']['forward_passes'])->toBe(6)
        ->and($merged['meta']['model'])->toBe('a + b + c');
});

it('aligns choice labels across slots regardless of order', function () {
    $merged = Ensemble::mergeSlots([
        'a' => [
            'answers' => [[
                'type' => 'choice',
                'name' => 'Team',
                'top' => 'logistics',
                'distribution' => [
                    ['label' => 'logistics', 'applies' => 'damage', 'score' => 0.45],
                    ['label' => 'billing', 'applies' => 'refunds', 'score' => 0.30],
                    ['label' => 'support', 'applies' => 'questions', 'score' => 0.25],
                ],
            ]],
            'meta' => ['model' => 'a'],
        ],
        'c' => [
            'answers' => [[
                'type' => 'choice',
                'name' => 'Team',
                'top' => 'billing',
                'distribution' => [
                    ['label' => 'billing', 'applies' => 'refunds', 'score' => 0.98],
                    ['label' => 'support', 'applies' => 'questions', 'score' => 0.01],
                    ['label' => 'logistics', 'applies' => 'damage', 'score' => 0.01],
                ],
            ]],
            'meta' => ['model' => 'c'],
        ],
    ]);

    $answer = $merged['answers'][0];
    $byLabel = [];

    foreach ($answer['distribution'] as $row) {
        $byLabel[$row['label']] = $row['score'];
    }

    expect($answer['top'])->toBe('billing')
        ->and($byLabel['billing'])->toBeGreaterThan($byLabel['logistics'])
        ->and(array_sum($byLabel))->toBeBetween(0.999, 1.001);
});

it('normalises slot weights over the slots that run', function () {
    $two = Ensemble::normalizedWeights(['a', 'b']);
    $three = Ensemble::normalizedWeights(['a', 'b', 'c']);

    expect($two['a'])->toEqualWithDelta(0.625, 0.001)
        ->and($two['b'])->toEqualWithDelta(0.375, 0.001)
        ->and(array_sum($three))->toEqualWithDelta(1.0, 0.001)
        ->and($three['c'])->toEqualWithDelta(0.6, 0.001);
});
