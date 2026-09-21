<?php

namespace App\Http\Controllers;

use App\George\Contracts\Engine;
use Illuminate\View\View;

class HomeController extends Controller
{
    public function __invoke(Engine $engine): View
    {
        return view('app', [
            'george' => [
                ...EvaluateController::statusPayload($engine),
                'maxSituation' => (int) config('george.max_situation_chars'),
                'maxConditions' => (int) config('george.max_conditions'),
                'maxOptions' => (int) config('george.max_options'),
                'maxLevels' => (int) config('george.max_levels'),
            ],
        ]);
    }
}
