<?php

return [

    /*
    |--------------------------------------------------------------------------
    | Default AI Provider
    |--------------------------------------------------------------------------
    |
    | The provider used when an agent names none. Any key of "providers"
    | below works; "fake-stream" is the default so no API key is needed.
    |
    */

    'default' => env('AI_PROVIDER') ?: 'fake-stream',

    /*
    |--------------------------------------------------------------------------
    | Default Embeddings Provider
    |--------------------------------------------------------------------------
    |
    | The provider used for embeddings; its "models.embeddings" entry names
    | the model and the vector size.
    |
    */

    'default_for_embeddings' => env('AI_EMBEDDINGS_PROVIDER') ?: 'ollama',

    /*
    |--------------------------------------------------------------------------
    | Embedding Batches
    |--------------------------------------------------------------------------
    |
    | Descriptions sent per model call by listings:embed, and the queue its
    | jobs run on.
    |
    */

    'embeddings' => [
        'batch' => max(1, min(1024, (int) (env('AI_EMBED_BATCH') ?: 256))),
        'queue' => 'embeddings',
        // HTTP timeout of one embedding call; below the batch job's 300 s.
        'timeout' => max(1, (int) (env('AI_EMBED_TIMEOUT') ?: 120)),
        // HNSW candidates searched per query: more = better recall, slower (see BENCHMARKS.md).
        'ef_search' => max(1, min(1000, (int) (env('AI_EF_SEARCH') ?: 400))),
    ],

    /*
    |--------------------------------------------------------------------------
    | Docs Index
    |--------------------------------------------------------------------------
    |
    | The two corpora docs:index reads: this repository's markdown (its root) and
    | the Laravel docs fetched by docs:fetch-laravel (git remote, branch, the
    | folder it is cloned into and the file recording its commit). "embed_batch"
    | is the chunks sent per embedding call.
    |
    */

    'docs' => [
        'repo' => [
            'path' => base_path(),
        ],
        'laravel' => [
            'repository' => env('AI_DOCS_LARAVEL_REPOSITORY') ?: 'https://github.com/laravel/docs.git',
            'branch' => env('AI_DOCS_LARAVEL_BRANCH') ?: '13.x',
            'path' => storage_path('app/docs/laravel'),
            'meta_path' => storage_path('app/docs/laravel.json'),
        ],
        'embed_batch' => max(1, min(256, (int) (env('AI_DOCS_EMBED_BATCH') ?: 32))),
        'k_default' => max(1, (int) (env('AI_DOCS_K_DEFAULT') ?: 6)),
        'k_max' => max(1, (int) (env('AI_DOCS_K_MAX') ?: 20)),
        'rrf_depth' => max(1, (int) (env('AI_DOCS_RRF_DEPTH') ?: 20)),
        'search_per_minute' => filled(env('AI_DOCS_SEARCH_PER_MINUTE')) ? (int) env('AI_DOCS_SEARCH_PER_MINUTE') : 60,
    ],

    'semantic_per_minute' => filled(env('AI_SEMANTIC_PER_MINUTE')) ? (int) env('AI_SEMANTIC_PER_MINUTE') : 60,

    /*
    |--------------------------------------------------------------------------
    | Prompts
    |--------------------------------------------------------------------------
    |
    | The file under resources/prompts (without ".md") each agent reads. The
    | last "." becomes "@" in the prompt version logged with every run.
    |
    */

    /*
    | Describe requests allowed per minute per IP (each one runs the model).
    */

    'describe_per_minute' => filled(env('AI_DESCRIBE_PER_MINUTE')) ? (int) env('AI_DESCRIBE_PER_MINUTE') : 20,

    'prompts' => [
        'car_description' => env('AI_PROMPT_CAR_DESCRIPTION') ?: 'car-description.v1',
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | The "fake-stream" driver replays a canned description word by word:
    | "tokens" deltas, "delay_ms" apart, optionally throwing after
    | "fail_after" of them. "fake-instant" is the same driver without delay.
    | "ollama" talks to a local Ollama; its "request_options" go into every
    | /api/chat call, and "max_tokens" and "timeout" apply to the agent.
    |
    */

    'providers' => [
        'fake-stream' => [
            'driver' => 'fake-stream',
            'tokens' => filled(env('AI_FAKE_TOKENS')) ? (int) env('AI_FAKE_TOKENS') : 200,
            'delay_ms' => filled(env('AI_FAKE_TOKEN_DELAY_MS')) ? (int) env('AI_FAKE_TOKEN_DELAY_MS') : 50,
            'fail_after' => filled(env('AI_FAKE_FAIL_AFTER')) ? (int) env('AI_FAKE_FAIL_AFTER') : null,
            'models' => [
                'text' => ['default' => env('AI_MODEL') ?: 'fake-slow-1'],
            ],
        ],

        'fake-instant' => [
            'driver' => 'fake-stream',
            'tokens' => 20,
            'delay_ms' => 0,
            'fail_after' => null,
            'models' => [
                'text' => ['default' => env('AI_MODEL') ?: 'fake-instant-1'],
            ],
        ],

        'ollama' => [
            'driver' => 'ollama',
            'key' => env('OLLAMA_API_KEY', ''),
            'url' => env('OLLAMA_URL') ?: 'http://host.docker.internal:11434',
            'max_tokens' => filled(env('AI_MAX_TOKENS')) ? (int) env('AI_MAX_TOKENS') : 400,
            'timeout' => filled(env('AI_TIMEOUT')) ? (int) env('AI_TIMEOUT') : 120,
            'num_parallel' => filled(env('OLLAMA_NUM_PARALLEL')) ? (int) env('OLLAMA_NUM_PARALLEL') : null,
            'request_options' => [
                'think' => filter_var(env('AI_THINK', false), FILTER_VALIDATE_BOOL),
                // Ollama reads a string as a Go duration ("10m"); unitless values such as -1 or 3600 must be numbers.
                'keep_alive' => is_numeric(env('OLLAMA_KEEP_ALIVE')) ? (int) env('OLLAMA_KEEP_ALIVE') : (env('OLLAMA_KEEP_ALIVE') ?: '10m'),
                'num_ctx' => filled(env('OLLAMA_NUM_CTX')) ? (int) env('OLLAMA_NUM_CTX') : 8192,
            ],
            'models' => [
                'text' => ['default' => env('OLLAMA_MODEL') ?: 'qwen3.5:9b'],
                'embeddings' => ['default' => env('OLLAMA_EMBED_MODEL') ?: 'bge-m3', 'dimensions' => 1024],
            ],
        ],

    ],

];
