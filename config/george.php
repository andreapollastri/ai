<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Inference engine
    |--------------------------------------------------------------------------
    |
    | auto          → TransformersPHP if the ONNX model is cached, else heuristic
    | transformers  → always TransformersPHP (downloads on first use)
    | fake          → keyword heuristic, for tests and UI work without a model
    |
    */

    'engine' => env('GEORGE_ENGINE', 'auto'),

    /*
    |--------------------------------------------------------------------------
    | How HTTP requests wait for George
    |--------------------------------------------------------------------------
    |
    | sync   → run inference in the request (fine for `php artisan serve`)
    | queue  → dispatch a job; keep the models warm with `php artisan george:work`
    |
    */

    'driver' => env('GEORGE_DRIVER', 'sync'),

    /*
    |--------------------------------------------------------------------------
    | Slot A: zero-shot NLI (lexical evidence)
    |--------------------------------------------------------------------------
    */

    'model' => env(
        'GEORGE_MODEL',
        'onnx-community/deberta-v3-large-zeroshot-v2.0-ONNX',
    ),

    /*
    |--------------------------------------------------------------------------
    | Slot B: a second English NLI head, run in its own process
    |--------------------------------------------------------------------------
    |
    | Set GEORGE_ENSEMBLE=false to skip it.
    |
    */

    'model_b' => env('GEORGE_MODEL_B', 'Xenova/nli-deberta-v3-large'),

    'ensemble' => filter_var(env('GEORGE_ENSEMBLE', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Slot C: the reasoner (decision evidence)
    |--------------------------------------------------------------------------
    |
    | A small instruct LLM in ONNX, read through next-token logits over the
    | option letters. One forward pass per condition, no text generation.
    | NLI heads match words; this slot is the one that infers what to do.
    | Any causal LM TransformersPHP can load with float32 KV cache (q4 /
    | int8 variants; q4f16 is not supported). Set the chat format to match
    | the family. Qwen3 runs with thinking disabled so the readout stays a
    | single forward pass.
    |
    */

    'reasoner' => filter_var(env('GEORGE_REASONER', true), FILTER_VALIDATE_BOOLEAN),

    'reasoner_model' => env('GEORGE_REASONER_MODEL', 'onnx-community/Qwen3-1.7B-ONNX'),

    // ONNX file inside the repo's onnx/ folder, without extension.
    'reasoner_file' => env('GEORGE_REASONER_FILE', 'model_q4'),

    // Chat turn layout: chatml (Qwen), phi3, llama3.
    'reasoner_format' => env('GEORGE_REASONER_FORMAT', 'chatml'),

    // Score each condition twice with the options reversed and average.
    // Removes the letter-position bias small LLMs have. Doubles slot C time.
    'reasoner_debias' => filter_var(env('GEORGE_REASONER_DEBIAS', true), FILTER_VALIDATE_BOOLEAN),

    /*
    |--------------------------------------------------------------------------
    | Slot weights
    |--------------------------------------------------------------------------
    |
    | Normalised over the slots that are actually running. With all three,
    | the reasoner carries the decision and the NLI pair keeps it honest on
    | wording. Without the reasoner, A and B split 0.625 / 0.375.
    |
    */

    'weight_a' => (float) env('GEORGE_WEIGHT_A', 0.25),

    'weight_b' => (float) env('GEORGE_WEIGHT_B', 0.15),

    'weight_c' => (float) env('GEORGE_WEIGHT_C', 0.60),

    'quantized' => env('GEORGE_QUANTIZED', true),

    'cache_dir' => env('GEORGE_CACHE_DIR', storage_path('app/george-models')),

    /*
    |--------------------------------------------------------------------------
    | Temperature scaling
    |--------------------------------------------------------------------------
    |
    | NLI zero-shot scores are not calibrated probabilities. T > 1 flattens
    | over-confident distributions. This is not a substitute for labelled
    | temperature scaling / isotonic regression on your domain.
    |
    */

    'temperature' => (float) env('GEORGE_TEMPERATURE', 1.0),

    'max_situation_chars' => 2000,

    'token_warning_chars' => 1600,

    'max_conditions' => 5,

    'max_options' => 8,

    'max_levels' => 7,

    'queue' => env('GEORGE_QUEUE', 'default'),

    'queue_a' => env('GEORGE_QUEUE_A', 'george-a'),

    'queue_b' => env('GEORGE_QUEUE_B', 'george-b'),

    'queue_c' => env('GEORGE_QUEUE_C', 'george-c'),

    'job_timeout' => (int) env('GEORGE_JOB_TIMEOUT', 180),

];
