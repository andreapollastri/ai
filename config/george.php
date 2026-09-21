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
    | An instruct LLM read through next-token logits over the option letters.
    | One forward pass per condition, no text generation. NLI heads match
    | words; this slot is the one that infers what to do.
    |
    | Two backends answer the same question with the same prompt:
    |
    |   onnx   TransformersPHP in-process. No extra service, but ONNX
    |          Runtime through PHP FFI caps out around 4B: larger repos
    |          ship either float16 KV cache (which the binding cannot read)
    |          or an ONNX Runtime GenAI layout the library cannot load.
    |   llama  llama-server over HTTP. Needs a service, and in exchange
    |          takes 8B and 14B GGUF weights at several times the CPU
    |          throughput, and reuses the cached prompt prefix between
    |          conditions of the same Run.
    |
    */

    'reasoner' => filter_var(env('GEORGE_REASONER', true), FILTER_VALIDATE_BOOLEAN),

    'reasoner_backend' => env('GEORGE_REASONER_BACKEND', 'onnx'),

    // The largest causal LM TransformersPHP can actually load: 4B in q4,
    // with float32 KV cache and weights in sibling .onnx_data files.
    'reasoner_model' => env('GEORGE_REASONER_MODEL', 'onnx-community/Qwen3-4B-Instruct-2507-ONNX'),

    // ONNX file inside the repo's onnx/ folder, without extension.
    'reasoner_file' => env('GEORGE_REASONER_FILE', 'model_q4'),

    // Chat turn layout: chatml (Qwen3 thinking), chatml_plain, phi3, llama3.
    // The 2507 Instruct release has no thinking mode, so no think block.
    'reasoner_format' => env('GEORGE_REASONER_FORMAT', 'chatml_plain'),

    // Score each condition twice with the options reversed and average.
    // Removes the letter-position bias small LLMs have. Doubles slot C time.
    'reasoner_debias' => filter_var(env('GEORGE_REASONER_DEBIAS', true), FILTER_VALIDATE_BOOLEAN),

    // Forced teaching for slot C. Hardwired on: no env switch.
    'reasoner_primer' => true,

    /*
    |--------------------------------------------------------------------------
    | Slot C over llama.cpp
    |--------------------------------------------------------------------------
    |
    | Used when reasoner_backend is llama. George never generates: it posts
    | the prompt to /completion with n_predict 1 and reads the probability
    | of each option letter out of the next-token distribution.
    |
    | The weights belong to llama-server, not to George. Start it with
    | `llama-server -hf <repo>:<quant>` and it fetches its own GGUF; the
    | repo and quant here only name what the systemd unit should serve and
    | what the UI reports.
    |
    */

    'llama' => [

        'url' => rtrim((string) env('GEORGE_LLAMA_URL', 'http://127.0.0.1:8080'), '/'),

        // What the unit serves. `george:download` does not fetch these.
        'repo' => env('GEORGE_LLAMA_REPO', 'Qwen/Qwen3-8B-GGUF'),

        'quant' => env('GEORGE_LLAMA_QUANT', 'Q4_K_M'),

        // Chat turn layout, same vocabulary as reasoner_format. Qwen3 is a
        // hybrid thinking model, so chatml closes an empty think block.
        'format' => env('GEORGE_LLAMA_FORMAT', 'chatml'),

        // Candidates asked of the sampler. The letters have to be in there,
        // so keep it well above the number of options.
        'n_probs' => (int) env('GEORGE_LLAMA_N_PROBS', 40),

        // Reuse the KV of the shared prefix. The primer is identical on
        // every call and the situation is identical across the conditions
        // of one Run, so this is most of the prompt.
        'cache_prompt' => filter_var(env('GEORGE_LLAMA_CACHE_PROMPT', true), FILTER_VALIDATE_BOOLEAN),

        'timeout' => (int) env('GEORGE_LLAMA_TIMEOUT', 120),

    ],

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
