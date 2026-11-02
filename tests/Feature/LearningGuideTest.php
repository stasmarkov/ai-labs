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

    expect($guide)->toContain('sections 1–12');
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

    expect($guide)->toContain('sections 1–12');
    expect($guide)->not->toContain('running models locally (SL-22)');
});

test('AC-9 learning guide explains embeddings and vector search', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 11\. Embeddings and vector search\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['embedding', 'bge-m3', 'cosine', 'normalised', 'HNSW', 'ef_search', 'seed', '48.98', '391 MB', '2.9 ms', 'recall@10', '0.795', 'enable_seqscan', 'ordering'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../docs/adr/0001-local-model-server-models-php-client.md',
        '../app/Services/',
        '../.ai/specs/spec-20261102-2115-listing-embeddings/',
        '../bench/results/2026-11-02-embeddings/',
        'https://github.com/pgvector/pgvector',
        'https://huggingface.co/BAAI/bge-m3',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../docs/adr/0001-local-model-server-models-php-client.md', '../app/Services/', '../.ai/specs/spec-20261102-2115-listing-embeddings/', '../bench/results/2026-11-02-embeddings/'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->toContain('sections 1–12');
    expect($guide)->not->toContain('embeddings and vector search (SL-24)');
});

test('AC-9 learning guide explains chunking and hybrid retrieval', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 12\. Chunking and hybrid retrieval\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['overlap', 'RRF', 'oversize', 'websearch_to_tsquery', 'ts_rank_cd', 'flushpackets', '2 706', '0.824', '146.8', '10 of 10', '8 of 10', '› Section:'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../.ai/research/ai-chatbot-reference.md',
        '../app/Services/MarkdownChunker.php',
        '../app/Services/DocsSearchService.php',
        '../.ai/specs/spec-20261102-2352-docs-index-retrieval/',
        '../bench/results/2026-11-02-docs-index/',
        'https://www.postgresql.org/docs/16/textsearch.html',
        'https://github.com/pgvector/pgvector',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../.ai/research/ai-chatbot-reference.md', '../app/Services/MarkdownChunker.php', '../app/Services/DocsSearchService.php', '../.ai/specs/spec-20261102-2352-docs-index-retrieval/', '../bench/results/2026-11-02-docs-index/'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->toContain('sections 1–12');
    expect($guide)->not->toContain('RAG with citations (SL-32)');
});
