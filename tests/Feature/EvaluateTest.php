<?php

use App\George\Contracts\Engine;
use App\George\Engines\FakeEngine;
use App\Models\Evaluation;
use Illuminate\Foundation\Testing\RefreshDatabase;

uses(RefreshDatabase::class);

beforeEach(function () {
    $this->withoutVite();
    $this->app->instance(Engine::class, new FakeEngine);
    config([
        'george.engine' => 'fake',
        'george.driver' => 'sync',
        'george.ensemble' => false,
        'george.reasoner' => false,
    ]);
});

it('renders the home page', function () {
    $this->get('/')
        ->assertOk()
        ->assertSee('TryGeorge', false)
        ->assertSee('Write in English.', false)
        ->assertSee('window.__GEORGE__', false);
});

it('renders the privacy page', function () {
    $this->get('/privacy')->assertOk()->assertSee('Privacy');
});

it('exposes engine status', function () {
    $this->get('/status')
        ->assertOk()
        ->assertJsonPath('engine', 'fake')
        ->assertJsonPath('calibrated', false);
});

it('evaluates a typed situation', function () {
    $response = $this->postJson('/evaluations', [
        'situation' => 'A customer emailed at 2am about a damaged order and wants a refund.',
        'locale' => 'en',
        'conditions' => [
            [
                'type' => 'noul',
                'name' => 'Urgent',
                'prompt' => 'Is this urgent?',
                'yes' => 'Time-sensitive or escalating',
                'no' => 'No time pressure',
            ],
            [
                'type' => 'choice',
                'name' => 'Team',
                'prompt' => 'Which team should handle this?',
                'options' => [
                    ['label' => 'billing', 'applies' => 'Refunds and payments'],
                    ['label' => 'logistics', 'applies' => 'Damage in transit'],
                ],
            ],
            [
                'type' => 'score',
                'name' => 'Goodwill',
                'prompt' => 'How much goodwill?',
                'levels' => ['None', 'Small', 'Refund'],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('result.answers.0.type', 'noul')
        ->assertJsonPath('result.answers.1.type', 'choice')
        ->assertJsonPath('result.answers.2.type', 'score');

    expect($response->json('result.answers.0.p_yes'))->toBeBetween(0, 1)
        ->and($response->json('result.meta.calibrated'))->toBeFalse()
        ->and(Evaluation::query()->count())->toBe(1);
});

it('rejects an empty situation', function () {
    $this->postJson('/evaluations', [
        'situation' => 'short',
        'conditions' => [
            [
                'type' => 'noul',
                'name' => 'Urgent',
                'prompt' => 'Is this urgent?',
                'yes' => 'Yes',
            ],
        ],
    ])->assertUnprocessable();
});

it('rejects a choice with one option', function () {
    $this->postJson('/evaluations', [
        'situation' => 'A customer emailed about a damaged parcel and wants help.',
        'conditions' => [
            [
                'type' => 'choice',
                'name' => 'Team',
                'prompt' => 'Which team?',
                'options' => [
                    ['label' => 'billing', 'applies' => 'Refunds'],
                ],
            ],
        ],
    ])->assertUnprocessable();
});

it('merges two fake slots when the ensemble is on', function () {
    config(['george.ensemble' => true, 'george.model_b' => 'fake-b']);

    $response = $this->postJson('/evaluations', [
        'situation' => 'A customer emailed at 2am about a damaged order and wants a refund.',
        'locale' => 'en',
        'conditions' => [
            [
                'type' => 'noul',
                'name' => 'Urgent',
                'prompt' => 'Is this urgent?',
                'yes' => 'Time-sensitive or escalating',
                'no' => 'No time pressure',
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('result.meta.engine', 'ensemble');

    expect($response->json('result.answers.0.p_yes'))->toBeBetween(0, 1)
        ->and($response->json('result.meta.ensemble'))->toHaveCount(2);
});

it('runs the reasoner as a third slot when it is on', function () {
    config(['george.ensemble' => true, 'george.model_b' => 'fake-b', 'george.reasoner' => true]);

    $this->get('/status')
        ->assertOk()
        ->assertJsonPath('engine', 'ensemble')
        ->assertJsonPath('reasoner', true)
        ->assertJsonPath('slots', ['a', 'b', 'c']);

    $response = $this->postJson('/evaluations', [
        'situation' => 'Production is down and revenue is zero. Customers are tweeting about failed payments.',
        'locale' => 'en',
        'conditions' => [
            [
                'type' => 'noul',
                'name' => 'Page someone',
                'prompt' => 'Should this wake an on call engineer right now?',
                'yes' => 'Revenue affecting and still getting worse',
                'no' => 'Contained, or already recovering on its own',
            ],
            [
                'type' => 'choice',
                'name' => 'Team',
                'prompt' => 'Which team should handle this?',
                'options' => [
                    ['label' => 'billing', 'applies' => 'Refunds and payments'],
                    ['label' => 'support', 'applies' => 'General questions'],
                ],
            ],
        ],
    ]);

    $response->assertOk()
        ->assertJsonPath('status', 'done')
        ->assertJsonPath('result.meta.engine', 'ensemble')
        ->assertJsonPath('result.meta.reasoner', true)
        ->assertJsonPath('result.meta.slots', ['a', 'b', 'c']);

    expect($response->json('result.meta.ensemble'))->toHaveCount(3)
        ->and($response->json('result.answers.0.p_yes'))->toBeBetween(0, 1)
        ->and($response->json('result.answers.1.distribution'))->toHaveCount(2)
        ->and(Evaluation::query()->first()->slots)->toHaveKeys(['a', 'b', 'c']);
});

it('runs the reasoner with a single nli head when model b is off', function () {
    config(['george.ensemble' => false, 'george.reasoner' => true]);

    $this->get('/status')
        ->assertOk()
        ->assertJsonPath('engine', 'ensemble')
        ->assertJsonPath('slots', ['a', 'c']);
});

it('scores the blind set with the fake reasoner', function () {
    config(['george.engine' => 'fake']);

    $this->artisan('george:bench', ['--quiet-rows' => true])
        ->expectsOutputToContain('fake-reasoner')
        ->expectsOutputToContain('Agrees with the reference')
        ->assertExitCode(0);
});

it('keeps every blind set situation gradeable', function () {
    $cases = require base_path('bench/blind-set.php');

    expect($cases)->not->toBeEmpty();

    foreach ($cases as $case) {
        expect($case)->toHaveKeys(['id', 'situation', 'conditions', 'human']);

        foreach ($case['conditions'] as $condition) {
            expect($case['human'])->toHaveKey($condition['name']);
            expect($case['human'][$condition['name']])->toHaveKey('answer');

            if ($condition['type'] === 'choice') {
                expect(array_column($condition['options'], 'label'))
                    ->toContain($case['human'][$condition['name']]['answer']);
            }

            if ($condition['type'] === 'noul') {
                expect($case['human'][$condition['name']]['answer'])->toBeIn(['yes', 'no']);
            }
        }
    }
});
