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
    | Settings UI
    |--------------------------------------------------------------------------
    |
    | Whether saved model settings (the "ai_settings" table) apply. They are
    | read only in the local environment, whatever this switch says.
    |
    */

    'settings_ui' => filter_var(env('AI_SETTINGS_UI', true), FILTER_VALIDATE_BOOLEAN),

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
    | Features
    |--------------------------------------------------------------------------
    |
    | The provider and model each feature runs on, read by App\Ai\AiProviders.
    | An empty provider falls back to "default" (text features) or
    | "default_for_embeddings"; an empty model to that provider's default
    | model. The model applies only while the feature runs on its configured
    | provider.
    |
    */

    'features' => [
        'describe' => [
            'provider' => env('AI_DESCRIBE_PROVIDER') ?: null,
            'model' => env('AI_DESCRIBE_MODEL') ?: null,
        ],
        'nl-search' => [
            'provider' => env('AI_NL_SEARCH_PROVIDER') ?: null,
            'model' => env('AI_NL_SEARCH_MODEL') ?: null,
        ],
        'docs-answer' => [
            'provider' => env('AI_DOCS_ANSWER_PROVIDER') ?: null,
            'model' => env('AI_DOCS_ANSWER_MODEL') ?: null,
        ],
        'rag-grader' => [
            'provider' => env('AI_RAG_GRADER_PROVIDER') ?: null,
            'model' => env('AI_RAG_GRADER_MODEL') ?: null,
        ],
        'photo-vision' => [
            'provider' => env('AI_PHOTO_VISION_PROVIDER') ?: 'ollama',
            // Empty means the provider's vision model (Ollama: gemma4:12b), or its text model when it names none.
            'model' => env('AI_PHOTO_VISION_MODEL') ?: null,
        ],
        'embeddings-listings' => [
            'provider' => env('AI_EMBEDDINGS_LISTINGS_PROVIDER') ?: null,
            'model' => env('AI_EMBEDDINGS_LISTINGS_MODEL') ?: null,
        ],
        'embeddings-docs' => [
            'provider' => env('AI_EMBEDDINGS_DOCS_PROVIDER') ?: null,
            'model' => env('AI_EMBEDDINGS_DOCS_MODEL') ?: null,
        ],
        // Image vectors of the listing photos; runs on its own provider, never on "default_for_embeddings".
        'embeddings-photos' => [
            'provider' => env('AI_EMBEDDINGS_PHOTOS_PROVIDER') ?: 'photo-encoder',
            'model' => env('AI_EMBEDDINGS_PHOTOS_MODEL') ?: null,
        ],
    ],

    /*
    |--------------------------------------------------------------------------
    | Similar Photos
    |--------------------------------------------------------------------------
    |
    | Photos of other listings shown as similar: at most "limit", with a cosine
    | similarity of at least "min_score" and below "near_duplicate_cutoff". The
    | search reads "candidates" nearest vectors (hnsw.ef_search "ef_search") and
    | filters them in PHP; results are cached for "ttl_seconds".
    |
    */

    'similar_photos' => [
        'min_score' => filled(env('AI_SIMILAR_MIN_SCORE')) ? (float) env('AI_SIMILAR_MIN_SCORE') : 0.75,
        'near_duplicate_cutoff' => 0.98,
        'limit' => 8,
        'candidates' => 40,
        'ef_search' => 100,
        'ttl_seconds' => 3600,
    ],

    /*
    |--------------------------------------------------------------------------
    | Embedding Batches
    |--------------------------------------------------------------------------
    |
    | Descriptions sent per model call by listings:embed, and the queue its
    | jobs run on.
    |
    */

    // guide:start embeddings
    'embeddings' => [
        'batch' => max(1, min(1024, (int) (env('AI_EMBED_BATCH') ?: 256))),
        'queue' => 'embeddings',
        // HTTP timeout of one embedding call; below the batch job's 300 s.
        'timeout' => max(1, (int) (env('AI_EMBED_TIMEOUT') ?: 120)),
        // HNSW candidates searched per query: more = better recall, slower.
        'ef_search' => max(1, min(1000, (int) (env('AI_EF_SEARCH') ?: 400))),
    ],
    // guide:end embeddings

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
        // guide:start docs-chunk
        'chunk' => [
            // Largest chunk in tokens (~4 characters each) and the size below which a section is merged into the next one.
            'max_tokens' => max(1, (int) (env('AI_DOCS_CHUNK_MAX_TOKENS') ?: 500)),
            'merge_below_tokens' => max(1, (int) (env('AI_DOCS_CHUNK_MERGE_BELOW_TOKENS') ?: 300)),
        ],
        // guide:end docs-chunk
        // rag:eval writes its result directories only inside this directory.
        'eval_results_root' => base_path('bench/results'),
        'embed_batch' => max(1, min(256, (int) (env('AI_DOCS_EMBED_BATCH') ?: 32))),
        'k_default' => max(1, (int) (env('AI_DOCS_K_DEFAULT') ?: 6)),
        'k_max' => max(1, (int) (env('AI_DOCS_K_MAX') ?: 20)),
        'rrf_depth' => max(1, (int) (env('AI_DOCS_RRF_DEPTH') ?: 20)),
        'search_per_minute' => filled(env('AI_DOCS_SEARCH_PER_MINUTE')) ? (int) env('AI_DOCS_SEARCH_PER_MINUTE') : 60,
        // guide:start docs-answer
        'answer' => [
            // Chunks retrieved per question, the cosine similarity a vector-only chunk needs, the context size in tokens (~4 characters each).
            // 0.5 is kept after rag:eval (500/300, 30 questions): the best cut, 0.58, has TPR 1 and FPR 0.17, the same as 0.5, so it is no improvement.
            'k' => max(1, (int) (env('AI_DOCS_ANSWER_K') ?: 6)),
            'min_similarity' => filled(env('AI_DOCS_ANSWER_MIN_SIMILARITY')) ? (float) env('AI_DOCS_ANSWER_MIN_SIMILARITY') : 0.5,
            'max_context_tokens' => max(1, (int) (env('AI_DOCS_ANSWER_MAX_CONTEXT_TOKENS') ?: 4000)),
            'per_minute' => filled(env('AI_DOCS_ANSWER_PER_MINUTE')) ? (int) env('AI_DOCS_ANSWER_PER_MINUTE') : 10,
            'guard' => env('AI_DOCS_ANSWER_GUARD') ?: resource_path('guards/prompt-injection.v1.php'),
        ],
        // guide:end docs-answer
        'chat' => [
            // Most earlier turns (user and assistant messages) a follow-up question may carry; 6 is three exchanges.
            'max_history_turns' => max(2, (int) (env('AI_DOCS_CHAT_MAX_HISTORY_TURNS') ?: 6)),
        ],
        // guide:start docs-cache
        'cache' => [
            // Whole-answer cache for POST /api/docs/ask: exact keys in "store", nearest question by cosine in docs_answer_cache.
            // min_similarity 0.94: the highest near-miss of the committed pair set (0.930409) rounded up to 2 decimals. The ranges overlap, so 0 of 24 paraphrases reach it (TPR 0, FPR 0); source: bench/results/docs-cache/2026-11-03/run/calibration.json.
            'enabled' => filter_var(env('AI_DOCS_CACHE_ENABLED', true), FILTER_VALIDATE_BOOL),
            'store' => env('AI_DOCS_CACHE_STORE') ?: 'redis',
            'ttl_hours' => max(1, (int) (env('AI_DOCS_CACHE_TTL_HOURS') ?: 168)),
            'min_similarity' => filled(env('AI_DOCS_CACHE_MIN_SIMILARITY')) ? (float) env('AI_DOCS_CACHE_MIN_SIMILARITY') : 0.94,
        ],
        // guide:end docs-cache
    ],

    // guide:start nl-search
    'nl_search' => [
        // Requests allowed per minute per IP (each one runs the model), and the models a caller may name; null means
        // the active provider's text model plus gemma4:12b.
        'per_minute' => filled(env('AI_NL_SEARCH_PER_MINUTE')) ? (int) env('AI_NL_SEARCH_PER_MINUTE') : 20,
        'models' => null,
        // HTTP timeout in seconds of the model call; shorter than the provider's 120 s, which is sized for streamed chat.
        'timeout' => filled(env('AI_NL_SEARCH_TIMEOUT')) ? max(1, (int) env('AI_NL_SEARCH_TIMEOUT')) : 20,
    ],
    // guide:end nl-search

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

    /*
    | Cloud-provider requests allowed per minute per IP across describe, NL search and docs ask.
    */

    'cloud_per_minute' => filled(env('AI_CLOUD_PER_MINUTE')) ? (int) env('AI_CLOUD_PER_MINUTE') : 20,
    'cloud_global_per_minute' => filled(env('AI_CLOUD_GLOBAL_PER_MINUTE')) ? (int) env('AI_CLOUD_GLOBAL_PER_MINUTE') : 60,

    // guide:start prompts
    'prompts' => [
        'car_description' => env('AI_PROMPT_CAR_DESCRIPTION') ?: 'car-description.v1',
        'docs_answer' => env('AI_PROMPT_DOCS_ANSWER') ?: 'docs-answer.v1',
        'docs_answer_history' => env('AI_PROMPT_DOCS_ANSWER_HISTORY') ?: 'docs-answer.v2',
        'listing_filters' => env('AI_PROMPT_LISTING_FILTERS') ?: 'listing-filters.v1',
        'rag_grader' => env('AI_PROMPT_RAG_GRADER') ?: 'rag-grader.v2',
        'photo_vision' => env('AI_PROMPT_PHOTO_VISION') ?: 'photo-vision.v1',
    ],
    // guide:end prompts

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

        // guide:start provider-ollama
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
                'vision' => ['default' => env('OLLAMA_VISION_MODEL') ?: 'gemma4:12b'],
                'embeddings' => ['default' => env('OLLAMA_EMBED_MODEL') ?: 'bge-m3', 'dimensions' => 1024],
            ],
        ],
        // guide:end provider-ollama

        // guide:start provider-cloud
        // Cloud providers are enabled by their key alone. Model ids are the SDK's current defaults; see the slice's decisions.md.
        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY') ?: null,
            'url' => env('ANTHROPIC_URL') ?: 'https://api.anthropic.com/v1',
            'max_tokens' => filled(env('AI_CLOUD_MAX_TOKENS')) ? (int) env('AI_CLOUD_MAX_TOKENS') : 400,
            'timeout' => filled(env('AI_CLOUD_TIMEOUT')) ? (int) env('AI_CLOUD_TIMEOUT') : 60,
            'models' => [
                'text' => ['default' => env('ANTHROPIC_MODEL') ?: 'claude-haiku-5-5', 'available' => filled(env('ANTHROPIC_MODELS_AVAILABLE')) ? array_map('trim', explode(',', (string) env('ANTHROPIC_MODELS_AVAILABLE'))) : ['claude-haiku-5-5', 'claude-sonnet-5-5']],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY') ?: null,
            'url' => env('OPENAI_URL') ?: 'https://api.openai.com/v1',
            'max_tokens' => filled(env('AI_CLOUD_MAX_TOKENS')) ? (int) env('AI_CLOUD_MAX_TOKENS') : 400,
            'timeout' => filled(env('AI_CLOUD_TIMEOUT')) ? (int) env('AI_CLOUD_TIMEOUT') : 60,
            'models' => [
                'text' => ['default' => env('OPENAI_MODEL') ?: 'gpt-6-luna', 'available' => filled(env('OPENAI_MODELS_AVAILABLE')) ? array_map('trim', explode(',', (string) env('OPENAI_MODELS_AVAILABLE'))) : ['gpt-6-luna']],
                'embeddings' => ['default' => env('OPENAI_EMBED_MODEL') ?: 'text-embedding-3-small', 'dimensions' => 1024, 'available' => filled(env('OPENAI_EMBED_MODELS_AVAILABLE')) ? array_map('trim', explode(',', (string) env('OPENAI_EMBED_MODELS_AVAILABLE'))) : ['text-embedding-3-small']],
            ],
        ],

        'gemini' => [
            'driver' => 'gemini',
            'key' => env('GEMINI_API_KEY') ?: null,
            'url' => env('GEMINI_URL') ?: 'https://generativelanguage.googleapis.com/v1beta/',
            'max_tokens' => filled(env('AI_CLOUD_MAX_TOKENS')) ? (int) env('AI_CLOUD_MAX_TOKENS') : 400,
            'timeout' => filled(env('AI_CLOUD_TIMEOUT')) ? (int) env('AI_CLOUD_TIMEOUT') : 60,
            'models' => [
                'text' => ['default' => env('GEMINI_MODEL') ?: 'gemini-3.1-flash-lite', 'available' => filled(env('GEMINI_MODELS_AVAILABLE')) ? array_map('trim', explode(',', (string) env('GEMINI_MODELS_AVAILABLE'))) : ['gemini-3.1-flash-lite']],
                'embeddings' => ['default' => env('GEMINI_EMBED_MODEL') ?: 'gemini-embedding-2', 'dimensions' => 1024, 'available' => filled(env('GEMINI_EMBED_MODELS_AVAILABLE')) ? array_map('trim', explode(',', (string) env('GEMINI_EMBED_MODELS_AVAILABLE'))) : ['gemini-embedding-2']],
            ],
        ],
        // guide:end provider-cloud

        // The local image-encoder sidecar (.ddev/docker-compose.photo-encoder.yaml), an app-level driver, not an SDK one.
        'photo-encoder' => [
            'driver' => 'image-encoder',
            'url' => env('PHOTO_ENCODER_URL') ?: 'http://photo-encoder:7997',
            'timeout' => filled(env('PHOTO_ENCODER_TIMEOUT')) ? max(1, (int) env('PHOTO_ENCODER_TIMEOUT')) : 120,
            'batch' => 16,
            'models' => [
                'image_embeddings' => [
                    'default' => env('PHOTO_ENCODER_MODEL') ?: 'openai/clip-vit-base-patch32',
                    'dimensions' => 512,
                    'available' => ['openai/clip-vit-base-patch32', 'laion/CLIP-ViT-B-32-laion2B-s34B-b79K'],
                ],
            ],
        ],
    ],

];
