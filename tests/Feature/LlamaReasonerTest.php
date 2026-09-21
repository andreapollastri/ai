<?php

use App\George\EngineFactory;
use App\George\Ensemble;
use App\George\Reasoners\FakeReasoner;
use App\George\Reasoners\LlamaReasoner;
use App\George\Reasoners\TransformersReasoner;
use Illuminate\Support\Facades\Http;

/**
 * Slot C over llama-server. The readout is the same quantity the ONNX
 * backend computes — the next-token distribution restricted to the option
 * letters — so these fix the shape of the server's answer, not the model's.
 */
beforeEach(function () {
    config([
        'george.engine' => 'auto',
        'george.llama.url' => 'http://llama.test:8080',
        'george.reasoner_debias' => false,
        'george.reasoner_backend' => 'llama',
    ]);
});

function llamaFake(array $topLogprobs, string $sampled = 'A'): void
{
    Http::fake([
        '*/health' => Http::response(['status' => 'ok']),
        '*/props' => Http::response(['model_path' => '/models/Qwen3-8B-Q4_K_M.gguf']),
        '*/completion' => Http::response([
            'content' => $sampled,
            'completion_probabilities' => [[
                'token' => $sampled,
                'logprob' => $topLogprobs[0]['logprob'] ?? -0.1,
                'top_logprobs' => $topLogprobs,
            ]],
        ]),
    ]);
}

it('reads the option letters out of a completion', function () {
    llamaFake([
        ['token' => 'B', 'logprob' => -0.1],
        ['token' => ' A', 'logprob' => -2.4],
        ['token' => 'the', 'logprob' => -8.0],
    ], 'B');

    $scores = (new LlamaReasoner)->judge('A contractor changed their bank details.', 'Which team?', [
        'billing: Payments and invoices',
        'security: Fraud and impersonation',
    ]);

    expect(array_sum($scores))->toBeBetween(0.999, 1.001)
        ->and($scores[1])->toBeGreaterThan($scores[0]);
});

it('asks for one token and no generation', function () {
    llamaFake([['token' => 'A', 'logprob' => -0.1], ['token' => 'B', 'logprob' => -3.0]]);

    (new LlamaReasoner)->judge('Routine question.', 'Which team?', ['Yes', 'No']);

    Http::assertSent(function ($request) {
        if (! str_contains($request->url(), '/completion')) {
            return true;
        }

        return $request['n_predict'] === 1
            && $request['cache_prompt'] === true
            && $request['n_probs'] >= 16;
    });
});

it('floors an option letter the sampler never returned', function () {
    llamaFake([['token' => 'A', 'logprob' => -0.05]]);

    $scores = (new LlamaReasoner)->judge('Routine question.', 'Which team?', ['Yes', 'No']);

    expect($scores[0])->toBeGreaterThan(0.99)
        ->and($scores[1])->toBeLessThan(0.01);
});

it('averages the reversed readout so letter position cancels out', function () {
    config(['george.reasoner_debias' => true]);

    // The model answers A wherever A sits: a pure position bias, which the
    // two readouts have to cancel into a tie rather than a decision.
    llamaFake([['token' => 'A', 'logprob' => -0.02], ['token' => 'B', 'logprob' => -4.0]]);

    $reasoner = new LlamaReasoner;
    $scores = $reasoner->judge('Ambiguous.', 'Which team?', ['Yes', 'No']);

    expect($scores[0])->toBeBetween(0.49, 0.51)
        ->and($reasoner->lastPasses())->toBe(2)
        ->and($reasoner->lastSpread())->toBeGreaterThan(0.9);
});

it('reads linear probabilities when the server posts sampled ones', function () {
    Http::fake([
        '*/health' => Http::response(['status' => 'ok']),
        '*/completion' => Http::response([
            'completion_probabilities' => [[
                'token' => 'B',
                'prob' => 0.75,
                'top_logprobs' => [
                    ['token' => 'B', 'prob' => 0.75],
                    ['token' => 'A', 'prob' => 0.25],
                ],
            ]],
        ]),
    ]);

    $scores = (new LlamaReasoner)->judge('Anything.', 'Which team?', ['Yes', 'No']);

    expect($scores[0])->toEqualWithDelta(0.25, 0.001)
        ->and($scores[1])->toEqualWithDelta(0.75, 0.001);
});

it('refuses to guess when no option letter came back', function () {
    llamaFake([['token' => 'Sure', 'logprob' => -0.1], ['token' => '!', 'logprob' => -1.0]], 'Sure');

    expect(fn () => (new LlamaReasoner)->judge('Anything.', 'Which team?', ['Yes', 'No']))
        ->toThrow(RuntimeException::class);
});

it('says so when the server is not there', function () {
    Http::fake(['*' => Http::response('', 503)]);

    expect(fn () => (new LlamaReasoner)->judge('Anything.', 'Which team?', ['Yes', 'No']))
        ->toThrow(RuntimeException::class, 'llama-server is not answering');
});

it('names the model the server actually loaded', function () {
    llamaFake([['token' => 'A', 'logprob' => -0.1]]);

    expect((new LlamaReasoner)->modelName())->toBe('Qwen3-8B-Q4_K_M.gguf')
        ->and((new LlamaReasoner)->driver())->toBe('llama');
});

it('falls back to the configured repo when the server has no opinion', function () {
    Http::fake(['*' => Http::response('', 404)]);

    config(['george.llama.repo' => 'Qwen/Qwen3-8B-GGUF', 'george.llama.quant' => 'Q4_K_M']);

    expect((new LlamaReasoner)->modelName())->toBe('Qwen/Qwen3-8B-GGUF:Q4_K_M');
});

it('builds the reasoner the configured backend asks for', function () {
    llamaFake([['token' => 'A', 'logprob' => -0.1]]);

    expect(app(EngineFactory::class)->makeReasoner())->toBeInstanceOf(LlamaReasoner::class);

    config(['george.reasoner_backend' => 'onnx', 'george.engine' => 'transformers']);

    expect((new EngineFactory)->makeReasoner())->toBeInstanceOf(TransformersReasoner::class);
});

it('falls back to the heuristic when llama-server is down', function () {
    Http::fake(['*' => Http::response('', 503)]);

    expect((new EngineFactory)->makeReasoner())->toBeInstanceOf(FakeReasoner::class);
});

it('reports the served weights as the slot C model', function () {
    config(['george.llama.repo' => 'Qwen/Qwen3-14B-GGUF', 'george.llama.quant' => 'Q4_K_M']);

    expect(Ensemble::model('c'))->toBe('Qwen/Qwen3-14B-GGUF:Q4_K_M');

    config(['george.reasoner_backend' => 'onnx']);

    expect(Ensemble::model('c'))->toBe(config('george.reasoner_model'));
});
