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
        'chunk' => [
            // Largest chunk in tokens (~4 characters each) and the size below which a section is merged into the next one.
            'max_tokens' => max(1, (int) (env('AI_DOCS_CHUNK_MAX_TOKENS') ?: 500)),
            'merge_below_tokens' => max(1, (int) (env('AI_DOCS_CHUNK_MERGE_BELOW_TOKENS') ?: 300)),
        ],
        // rag:eval writes its result directories only inside this directory.
        'eval_results_root' => base_path('bench/results'),
        'embed_batch' => max(1, min(256, (int) (env('AI_DOCS_EMBED_BATCH') ?: 32))),
        'k_default' => max(1, (int) (env('AI_DOCS_K_DEFAULT') ?: 6)),
        'k_max' => max(1, (int) (env('AI_DOCS_K_MAX') ?: 20)),
        'rrf_depth' => max(1, (int) (env('AI_DOCS_RRF_DEPTH') ?: 20)),
        'search_per_minute' => filled(env('AI_DOCS_SEARCH_PER_MINUTE')) ? (int) env('AI_DOCS_SEARCH_PER_MINUTE') : 60,
        'answer' => [
            // Chunks retrieved per question, the cosine similarity a vector-only chunk needs, the context size in tokens (~4 characters each).
            // 0.5 is kept after rag:eval (500/300, 30 questions): the best cut, 0.58, has TPR 1 and FPR 0.17, the same as 0.5, so it is no improvement.
            'k' => max(1, (int) (env('AI_DOCS_ANSWER_K') ?: 6)),
            'min_similarity' => filled(env('AI_DOCS_ANSWER_MIN_SIMILARITY')) ? (float) env('AI_DOCS_ANSWER_MIN_SIMILARITY') : 0.5,
            'max_context_tokens' => max(1, (int) (env('AI_DOCS_ANSWER_MAX_CONTEXT_TOKENS') ?: 4000)),
            'per_minute' => filled(env('AI_DOCS_ANSWER_PER_MINUTE')) ? (int) env('AI_DOCS_ANSWER_PER_MINUTE') : 10,
            'guard' => env('AI_DOCS_ANSWER_GUARD') ?: resource_path('guards/prompt-injection.v1.php'),
        ],
        'cache' => [
            // Whole-answer cache for POST /api/docs/ask: exact keys in "store", nearest question by cosine in docs_answer_cache.
            // min_similarity 0.94: the highest near-miss of the committed pair set (0.930409) rounded up to 2 decimals. The ranges overlap, so 0 of 24 paraphrases reach it (TPR 0, FPR 0); source: bench/results/docs-cache/2026-11-03/run/calibration.json.
            'enabled' => filter_var(env('AI_DOCS_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
            'store' => env('AI_DOCS_CACHE_STORE') ?: 'redis',
            'ttl_hours' => max(1, (int) (env('AI_DOCS_CACHE_TTL_HOURS') ?: 168)),
            'min_similarity' => filled(env('AI_DOCS_CACHE_MIN_SIMILARITY')) ? (float) env('AI_DOCS_CACHE_MIN_SIMILARITY') : 0.94,
        ],
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
        'docs_answer' => env('AI_PROMPT_DOCS_ANSWER') ?: 'docs-answer.v1',
        'rag_grader' => env('AI_PROMPT_RAG_GRADER') ?: 'rag-grader.v2',
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
            'text' => filled(env('AI_FAKE_TEXT')) ? (string) env('AI_FAKE_TEXT') : null,
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
