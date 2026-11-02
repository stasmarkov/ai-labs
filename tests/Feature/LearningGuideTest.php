<?php

declare(strict_types=1);

test('AC-10 learning guide explains state leaks with links', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## \d+\. State leaks in worker mode\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['CreateConfigurationSandbox', 'FlushLocaleState', 'GiveNewRequestInstanceToApplication', 'dealer-scope', 'price-cache', 'number-locale', 'request-at-boot', '--max-requests', '/debug/worker', 'worker_hits', 'Number::useLocale'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach (['../app/Debug/Leaks/', '../tests/Feature/LeakLabTest.php', '../BENCHMARKS.md#state-leaks', 'https://laravel.com/docs/13.x/octane'] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../app/Debug/Leaks/', '../tests/Feature/LeakLabTest.php', '../bench/leaks.sh'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->not->toContain('state leaks in worker mode (SL-07)');
});

test('AC-9 learning guide explains streaming an AI answer with links', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 9\. Streaming an AI answer\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['text/event-stream', 'X-Accel-Buffering', 'data: [DONE]', 'flushpackets', 'ai_runs', 'first_text_ms', 'tokenizer', 'frankenphp-octane', 'apache-fpm', 'openlitespeed', 'AI_FAKE_TOKENS'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../app/Ai/',
        '../app/Http/Controllers/DescribeListingController.php',
        '../.ai/specs/spec-20261102-1552-streamed-car-description/',
        '../bench/sse.sh',
        'https://laravel.com/docs/13.x/ai-sdk',
        'https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../app/Ai/', '../app/Http/Controllers/DescribeListingController.php', '../bench/sse.sh', '../.ai/specs/spec-20261102-1552-streamed-car-description/'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->toContain('sections 1–10');
});

test('AC-8 learning guide explains running the model locally', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 10\. Running the model locally\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['host.docker.internal', 'OLLAMA_URL', 'AI_PROVIDER', 'AI_THINK', 'num_predict', 'keep_alive', 'load_duration', 'Expose Ollama to the network', 'qwen3.5:9b', 'nginx-fpm', 'frankenphp-worker', 'openlitespeed', 'MLX', 'nvfp4', 'KV cache', 'num_ctx', 'tokens/s', 'CarDescriptionAgent.php', 'AI_MAX_TOKENS=1200'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../docs/adr/0001-local-model-server-models-php-client.md',
        '../app/Ai/CarDescriptionAgent.php',
        '../config/ai.php',
        '../.ai/specs/spec-20261102-1812-local-model-wiring/',
        '../bench/sse.sh',
        'https://docs.ollama.com/',
        'https://laravel.com/docs/13.x/ai-sdk',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../docs/adr/0001-local-model-server-models-php-client.md', '../app/Ai/CarDescriptionAgent.php', '../config/ai.php', '../.ai/specs/spec-20261102-1812-local-model-wiring/', '../bench/sse.sh'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    preg_match_all('/^\| (nginx-fpm|apache-fpm|openlitespeed|frankenphp|frankenphp-octane|frankenphp-worker) \|(.*)\|$/m', $section, $rows, PREG_SET_ORDER);
    expect($rows)->toHaveCount(6);

    foreach ($rows as $row) {
        expect($row[2])->not->toContain('null');
    }

    expect($section)->toContain('| Server | Tokens in |');

    expect($guide)->toContain('sections 1–10');
    expect($guide)->not->toContain('running models locally (SL-22)');
});
