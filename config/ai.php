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

    'default' => env('AI_PROVIDER', 'fake-stream'),

    /*
    |--------------------------------------------------------------------------
    | Prompts
    |--------------------------------------------------------------------------
    |
    | The file under resources/prompts (without ".md") each agent reads. The
    | last "." becomes "@" in the prompt version logged with every run.
    |
    */

    'prompts' => [
        'car_description' => env('AI_PROMPT_CAR_DESCRIPTION', 'car-description.v1'),
    ],

    /*
    |--------------------------------------------------------------------------
    | AI Providers
    |--------------------------------------------------------------------------
    |
    | The "fake-stream" driver replays a canned description word by word:
    | "tokens" deltas, "delay_ms" apart, optionally throwing after
    | "fail_after" of them. "fake-instant" is the same driver without delay.
    |
    */

    'providers' => [
        'fake-stream' => [
            'driver' => 'fake-stream',
            'tokens' => filled(env('AI_FAKE_TOKENS')) ? (int) env('AI_FAKE_TOKENS') : 200,
            'delay_ms' => filled(env('AI_FAKE_TOKEN_DELAY_MS')) ? (int) env('AI_FAKE_TOKEN_DELAY_MS') : 50,
            'fail_after' => filled(env('AI_FAKE_FAIL_AFTER')) ? (int) env('AI_FAKE_FAIL_AFTER') : null,
            'models' => [
                'text' => ['default' => env('AI_MODEL', 'fake-slow-1')],
            ],
        ],

        'fake-instant' => [
            'driver' => 'fake-stream',
            'tokens' => 20,
            'delay_ms' => 0,
            'fail_after' => null,
            'models' => [
                'text' => ['default' => env('AI_MODEL', 'fake-instant-1')],
            ],
        ],

        'anthropic' => [
            'driver' => 'anthropic',
            'key' => env('ANTHROPIC_API_KEY'),
            'url' => env('ANTHROPIC_URL', 'https://api.anthropic.com/v1'),
            'models' => [
                'text' => ['default' => env('AI_MODEL', 'claude-haiku-4-5')],
            ],
        ],

        'openai' => [
            'driver' => 'openai',
            'key' => env('OPENAI_API_KEY'),
            'url' => env('OPENAI_URL', 'https://api.openai.com/v1'),
            'store' => env('OPENAI_STORE', true),
            'models' => [
                'text' => ['default' => env('AI_MODEL', 'gpt-5-mini')],
            ],
        ],
    ],

];
