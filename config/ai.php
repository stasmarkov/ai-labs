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
            'request_options' => [
                'think' => filter_var(env('AI_THINK', false), FILTER_VALIDATE_BOOL),
                // Ollama reads a string as a Go duration ("10m"); unitless values such as -1 or 3600 must be numbers.
                'keep_alive' => is_numeric(env('OLLAMA_KEEP_ALIVE')) ? (int) env('OLLAMA_KEEP_ALIVE') : (env('OLLAMA_KEEP_ALIVE') ?: '10m'),
                'num_ctx' => filled(env('OLLAMA_NUM_CTX')) ? (int) env('OLLAMA_NUM_CTX') : 8192,
            ],
            'models' => [
                'text' => ['default' => env('OLLAMA_MODEL') ?: 'qwen3.5:9b'],
            ],
        ],

    ],

];
