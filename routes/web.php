<?php

use App\Http\Controllers\EvaluateController;
use App\Http\Controllers\HomeController;
use Illuminate\Support\Facades\Route;

Route::get('/', HomeController::class);

Route::view('/privacy', 'privacy');

Route::get('/status', [EvaluateController::class, 'status']);
Route::post('/evaluations', [EvaluateController::class, 'store'])->middleware('throttle:20,1');
Route::get('/evaluations/{evaluation}', [EvaluateController::class, 'show']);
