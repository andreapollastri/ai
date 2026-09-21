<?php

namespace App\Http\Controllers;

use App\George\Contracts\Engine;
use App\George\Ensemble;
use App\George\Support\Primer;
use App\Http\Requests\EvaluateRequest;
use App\Jobs\EvaluateSituation;
use App\Models\Evaluation;
use Illuminate\Http\JsonResponse;

class EvaluateController extends Controller
{
    public function store(EvaluateRequest $request, Engine $engine): JsonResponse
    {
        $ensemble = Ensemble::ready();

        $evaluation = Evaluation::query()->create([
            'situation' => $request->validated('situation'),
            'locale' => $request->validated('locale') ?: 'en',
            'conditions' => $request->validated('conditions'),
            'status' => 'pending',
            'model' => $ensemble
                ? implode(' + ', Ensemble::models())
                : $engine->modelName(),
            'engine' => $ensemble ? 'ensemble' : $engine->driver(),
        ]);

        if ($ensemble) {
            if (config('george.driver') === 'sync') {
                Ensemble::runSync($evaluation);
                $evaluation->refresh();

                return response()->json($evaluation->toApi());
            }

            Ensemble::dispatch($evaluation);

            return response()->json($evaluation->toApi(), 202);
        }

        if (config('george.driver') === 'sync') {
            EvaluateSituation::dispatchSync($evaluation);
            $evaluation->refresh();

            return response()->json($evaluation->toApi());
        }

        EvaluateSituation::dispatch($evaluation);

        return response()->json($evaluation->toApi(), 202);
    }

    public function show(Evaluation $evaluation): JsonResponse
    {
        return response()->json($evaluation->toApi());
    }

    public function status(Engine $engine): JsonResponse
    {
        return response()->json($this->statusPayload($engine));
    }

    /**
     * @return array<string, mixed>
     */
    public static function statusPayload(Engine $engine): array
    {
        $ensemble = Ensemble::ready();
        $slots = Ensemble::readySlots();
        $models = $ensemble ? Ensemble::models() : [$engine->modelName()];

        return [
            'engine' => $ensemble ? 'ensemble' : $engine->driver(),
            'model' => implode(' + ', $models),
            'models' => $models,
            'ensemble' => $ensemble,
            'slots' => $ensemble ? $slots : ['a'],
            'reasoner' => $ensemble && in_array('c', $slots, true),
            'primer' => Primer::enabled(),
            'ready' => $ensemble || $engine->isReady(),
            'driver' => config('george.driver'),
            'calibrated' => false,
        ];
    }
}
