<?php

declare(strict_types=1);

use App\Services\RagEvalSet;

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

    expect($guide)->toContain('sections 1–15');
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

    expect($guide)->toContain('sections 1–15');
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

    expect($guide)->toContain('sections 1–15');
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

    expect($guide)->toContain('sections 1–15');
    expect($guide)->not->toContain('RAG with citations (SL-32)');
});

test('AC-8 learning guide explains the AI lab UI', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 13\. The AI lab UI: Inertia, Vue and streams in the browser\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['X-Inertia', 'props', 'ai.app', 'parity', 'ReadableStream', 'AbortController', 'data: [DONE]', 'Server-Timing', 'embed', 'db'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../resources/js/ai/',
        '../app/Http/Controllers/ShowLabOverviewController.php',
        '../.ai/specs/spec-20261103-0012-ai-lab-ui/',
        'https://inertiajs.com',
        'https://vuejs.org',
        'https://developer.mozilla.org/en-US/docs/Web/API/ReadableStream',
        'https://developer.mozilla.org/en-US/docs/Web/API/AbortController',
        'https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Server-Timing',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../resources/js/ai/', '../app/Http/Controllers/ShowLabOverviewController.php', '../.ai/specs/spec-20261103-0012-ai-lab-ui/'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->toContain('sections 1–15');
});

test('AC-11 learning guide documents docs answering', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 14\. Answering with citations: RAG, guards and the trace row\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['0.635', '0.674', '0.469', '0.446', 'relevance gate', 'sources', 'TTFT', '4783', 'disregard everything you were told before', 'prompt-injection.v1', '<doc', 'canary', '12-word', 'replace', 'cannot un-send', 'retrieved_chunk_ids', 'cited_chunk_ids', 'refusal_reason', 'Server-Timing'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../app/Services/DocsAnswerService.php',
        '../app/Ai/DocsAnswerProtocol.php',
        '../app/Ai/OutputGuard.php',
        '../app/Services/PromptInjectionGuard.php',
        '../.ai/specs/spec-20261103-1028-docs-chat-answer/',
        'https://platform.openai.com/docs/guides/prompt-engineering',
        'https://owasp.org/www-project-top-10-for-large-language-model-applications/',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../app/Services/DocsAnswerService.php', '../app/Ai/DocsAnswerProtocol.php', '../app/Ai/OutputGuard.php', '../app/Services/PromptInjectionGuard.php', '../.ai/specs/spec-20261103-1028-docs-chat-answer/'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    expect($guide)->toContain('sections 1–15');
    expect($guide)->not->toContain('RAG with citations (SL-45, on top of the index from SL-32)');
});

test('AC-1 NFR-1 learning guide records the live docs answering run', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 14\. Answering with citations: RAG, guards and the trace row\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    foreach (['Live run: five questions', 'tokens/s', 'no_context', '1695', '2290', '2595', '2587', '1758', '1766', '4072', '5270', '4600', '500 ms'] as $needle) {
        expect($section)->toContain($needle);
    }
});

test('AC-10 learning guide section 15 explains measuring RAG', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 15\. Measuring RAG: recall@k, calibration and LLM grading\n(.*?)(?=^---$|^## \d+\. |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['rag:eval', 'recall@k', 'MRR', 'Youden', 'overfit', 'self-grading', 'contamination', 'RagEvalContaminationTest', 'chunk size', '0.581951', '0.875', '300/180', 'false don\'t-know', 'off-python-sort', 'rag-grader@v1', 'ai_runs'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../app/Console/Commands/RagEvalCommand.php',
        '../app/Services/ThresholdCalibrator.php',
        '../app/Services/RagRetrievalEvaluator.php',
        '../app/Ai/RagGraderAgent.php',
        '../.ai/specs/spec-20261103-1204-rag-eval-harness/',
        '../bench/results/rag-eval/2026-11-03/',
        '../BENCHMARKS.md',
        'https://en.wikipedia.org/wiki/Youden%27s_J_statistic',
        'https://en.wikipedia.org/wiki/Mean_reciprocal_rank',
        'https://arxiv.org/abs/2306.05685',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    foreach (['../app/Console/Commands/RagEvalCommand.php', '../app/Services/ThresholdCalibrator.php', '../app/Services/RagRetrievalEvaluator.php', '../app/Ai/RagGraderAgent.php', '../.ai/specs/spec-20261103-1204-rag-eval-harness/', '../bench/results/rag-eval/2026-11-03/'] as $path) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$path))->toBeTrue();
    }

    foreach ((new RagEvalSet)->load() as $question) {
        expect(RagEvalSet::normalizeText($section))->not->toContain(RagEvalSet::normalizeText($question->question));
    }

    foreach (['answers-qwen-500-300', 'answers-gemma-500-300', 'answers-qwen-300-180'] as $label) {
        $report = json_decode(file_get_contents(base_path("bench/results/rag-eval/2026-11-03/{$label}/answers.json")), true, flags: JSON_THROW_ON_ERROR)['models'][0];

        expect($section)->toContain((string) $report['median_ttft_ms']);
    }

    expect($section)->not->toContain('is empty in the result files');

    expect($guide)->toContain('sections 1–15');
});

test('the learning guide maps every AI file, request flow and feature recipe, and every linked file exists', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = strpos($guide, '## 16. How the AI features are built: a map of the code');
    expect($start)->not->toBeFalse();
    $section = substr($guide, (int) $start, strpos($guide, '## Planned sections') - (int) $start);

    foreach (['### The layers', '### Every file and its purpose', '### Four requests, end to end', '### Common feature requests and where they land', '### Testing patterns used everywhere'] as $heading) {
        expect($section)->toContain($heading);
    }

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    expect(count($links[1]))->toBeGreaterThan(60);

    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    foreach (glob(base_path('app/Ai/*.php')) ?: [] as $file) {
        expect($section)->toContain('../app/Ai/'.basename($file));
    }
});

test('AC-11 learning guide section 17 explains caching answers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 17\. Caching answers: exact keys, semantic neighbours and invalidation\n(.*?)(?=^---$|^## \d+\. |^## Planned sections|\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    expect($section)->not->toBe('');

    foreach (['docs:cache-calibrate', 'docs:cache', 'near-miss', 'paraphrase', 'wrong hit', '0.930', '0.906', '0.94', 'zero-false-hit', 'index version', 'prompt version', 'filter', 'prune', 'TTL', 'cache_hit', 'cached', 'saved_latency_ms', 'ai_runs', 'input guard', 'before the cache', 'one embedding', 'cache:false', 'rag:eval', '46.5 ms', '126 ms', '6071 ms', '10 of 30', 'AI_DOCS_CACHE_MIN_SIMILARITY'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([
        '../app/Services/DocsAnswerCache.php',
        '../app/Services/DocsIndexVersion.php',
        '../app/Services/DocsCacheCalibrator.php',
        '../app/Console/Commands/DocsCacheCommand.php',
        '../app/Console/Commands/DocsCacheCalibrateCommand.php',
        '../bench/results/docs-cache/2026-11-03/',
        '../bench/rag-eval/cache-pairs.v1.jsonl',
        '../BENCHMARKS.md',
        '../.ai/specs/spec-20261103-1616-docs-semantic-cache/',
        'https://redis.io/docs/latest/develop/get-started/vector-database/',
        'https://github.com/pgvector/pgvector',
    ] as $link) {
        expect($section)->toContain("]($link");
    }

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    foreach ((new RagEvalSet)->load() as $question) {
        expect(RagEvalSet::normalizeText($section))->not->toContain(RagEvalSet::normalizeText($question->question));
    }
    foreach (file(base_path('bench/rag-eval/cache-pairs.v1.jsonl'), FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        $pair = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        expect(RagEvalSet::normalizeText($section))->not->toContain(RagEvalSet::normalizeText($pair['a']))
            ->not->toContain(RagEvalSet::normalizeText($pair['b']));
    }

    expect($guide)->toContain('sections 1–15')->toContain('Section 17');
});

test('AC-11 learning guide section 16 maps every answer cache file and the cache flow', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = (int) strpos($guide, '## 16. How the AI features are built: a map of the code');
    $section = substr($guide, $start, (int) strpos($guide, '## 17. ') - $start);

    $files = [
        'app/Services/DocsAnswerCache.php', 'app/Services/DocsIndexVersion.php', 'app/Services/DocsCacheCalibrator.php',
        'app/Services/DocsCacheMaintenance.php', 'app/Services/DocsCachePairScorer.php', 'app/Services/DocsCachePairSet.php',
        'app/Contracts/DocsAnswerCacheRepositoryInterface.php', 'app/Repositories/EloquentDocsAnswerCacheRepository.php',
        'app/Console/Commands/DocsCacheCommand.php', 'app/Console/Commands/DocsCacheCalibrateCommand.php',
        'app/Enums/DocsCacheKind.php', 'app/Enums/DocsCacheMode.php', 'app/Exceptions/DocsCachePairException.php',
        'bench/docs-cache.sh', 'bench/rag-eval/cache-pairs.v1.jsonl', 'bench/rag-eval/cache-hitmiss-paraphrases.v1.jsonl', 'bench/rag-eval/cache-latency-paraphrases.v1.jsonl', 'app/Services/ThresholdMath.php',
    ];
    foreach (glob(base_path('app/DTOs/DocsCache*.php')) ?: [] as $dto) {
        $files[] = 'app/DTOs/'.basename($dto);
    }
    foreach (glob(base_path('database/migrations/*docs_answer_cache*.php')) ?: [] as $migration) {
        $files[] = 'database/migrations/'.basename($migration);
    }
    foreach (glob(base_path('database/migrations/*cache_columns_to_ai_runs*.php')) ?: [] as $migration) {
        $files[] = 'database/migrations/'.basename($migration);
    }

    foreach ($files as $file) {
        expect($section)->toContain('../'.$file);
    }

    expect($section)->toContain('Ask the docs')->toContain('lookupExact')->toContain('lookupSemantic')->toContain('cache_hit')
        ->toContain('tune the cache');
});

test('AC-8 learning guide section 18 explains Stop that really stops and conversations', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## 18\. Stop that really stops, and conversations\n(.*?)(?=^---$|^## \d+\. |^## Planned sections|\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';
    expect($section)->not->toBe('');

    foreach (['ignore_user_abort', 'connection_aborted()', 'AbortWatcher', 'stopped', 'stopped_after_ms', 'tokens_out', '320 tokens', '22 224 ms', 'no `ai_runs` row', 'model free', 'delta lag', 'nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp-octane', 'frankenphp-worker', 'docs-answer@v2', 'history_turns', '<history>', 'forged', 'output:prompt_overlap', 'refused', 'turn', 'retrieval', 'query rewriting', '1 of 3', 'undo it', 'never cached', 'bench/chat-stop.sh', 'NFR-1', 'aria-live'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach (['../bench/chat-stop.sh', '../app/Ai/AbortWatcher.php', '../app/Ai/SseProtocol.php', '../bench/results/chat-stop/2026-11-03/', '../bench/results/chat-stop/2026-11-03/followups.md', '../.ai/specs/spec-20261103-2118-chat-stop-multiturn/', '../BENCHMARKS.md'] as $link) {
        expect($section)->toContain($link);
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    foreach (['nginx-fpm', 'apache-fpm', 'openlitespeed', 'frankenphp', 'frankenphp-octane', 'frankenphp-worker'] as $mode) {
        $result = json_decode((string) file_get_contents(base_path("bench/results/chat-stop/2026-11-03/{$mode}.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($section)->toContain('| '.$mode.' | '.$result['median_tokens_after_stop'].' | '.$result['median_idle_probe_ms'].' ms | '.$result['median_probe_ms_after_stop'].' ms |');
    }

    expect($guide)->toContain('Section 18');
});

test('AC-8 learning guide section 16 maps the Stop and conversation files, flow and feature request', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = (int) strpos($guide, '## 16. How the AI features are built: a map of the code');
    $section = substr($guide, $start, (int) strpos($guide, '## 17. ') - $start);

    foreach ([
        'app/Ai/AbortWatcher.php', 'app/Ai/ConnectionAbortWatcher.php', 'app/DTOs/DocsChatTurn.php',
        'resources/prompts/docs-answer.v2.md', 'bench/chat-stop.sh', 'bench/results/chat-stop/2026-11-03/',
        'database/migrations/2026_11_03_210000_add_stop_columns_to_ai_runs_table.php',
        'tests/Feature/StreamCancelTest.php', 'tests/Feature/DocsChatHistoryTest.php',
    ] as $file) {
        expect($section)->toContain('../'.$file);
        expect(file_exists(base_path($file)))->toBeTrue("missing: {$file}");
    }

    expect($section)->toContain('history')->toContain('docs-answer@v2')->toContain('connection_aborted()')
        ->toContain('Stop doesn\'t stop')->toContain('section 18');
});

test('AC-8 learning guide section 19 explains slow I/O, worker pools and the health check', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = strpos($guide, '## 19. Slow I/O, worker pools and a health check that stays fast');
    expect($start)->not->toBeFalse();
    $section = substr($guide, (int) $start, strpos($guide, '## Planned sections') - (int) $start);

    foreach (['pm.max_children', 'max_threads', 'max_wait_time', 'Little', '/healthz', 'no middleware group', 'SSE', 'queued', '4003 ms', '276 ms', '299 of 300', '16 ms'] as $needle) {
        expect($section)->toContain($needle);
    }

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    expect(substr($guide, (int) strpos($guide, '## 16.'), (int) strpos($guide, '## 17.') - (int) strpos($guide, '## 16.')))->toContain('bench/slow-load.sh --workload sse');
});

test('AC-7 learning guide section 20 explains offloaded downloads with the measured numbers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = strpos($guide, '## 20. Letting the web server send the file');
    expect($start)->not->toBeFalse();
    $section = substr($guide, (int) $start, strpos($guide, '## Planned sections') - (int) $start);

    foreach (['X-Accel-Redirect', 'X-Sendfile', 'X-LiteSpeed-Location', 'mod_xsendfile', 'intercept', '/_protected_exports/', 'internal', 'php_held_ms', 'bytes_sent_by_php', 'stream-fallback', 'nginx-fpm', 'apache-fpm', 'frankenphp', 'openlitespeed', 'section 19'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach (['apache-fpm', 'frankenphp', 'nginx-fpm', 'openlitespeed'] as $server) {
        foreach (['offload', 'stream'] as $mode) {
            $result = json_decode((string) file_get_contents(base_path("bench/results/export-load/2026-11-04/{$mode}-{$server}.json")), true, flags: JSON_THROW_ON_ERROR);
            $p95 = (int) round($result['median']['health_p95_ms']);
            $row = sprintf('| %s | %s | %s | %s |', $server, $mode, $p95 >= 10000 ? '10 000 ms' : $p95.' ms', number_format((int) round($result['median']['php_held_ms_p95']), 0, '', ' ').' ms');
            expect($section)->toContain($row);
        }
    }

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    expect($links[1])->not->toBeEmpty();
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }
});

test('AC-7 learning guide section 20 walks each server through one offload and notes the out-of-scope modes', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = (int) strpos($guide, '## 20. Letting the web server send the file');
    $section = substr($guide, $start, strpos($guide, '## Planned sections') - $start);

    foreach (['**One offload per server.**', 'who reads the file', 'is free', 'Octane', 'worker mode', 'shutdown functions', '.ddev/web-build/Dockerfile', 'rebuild', '/exports/listings?mode=offload'] as $needle) {
        expect($section)->toContain($needle);
    }

    expect($section)->not->toContain('/exports/1?mode=offload');

    $walkthrough = substr($section, (int) strpos($section, '**One offload per server.**'));
    $walkthrough = substr($walkthrough, 0, (int) strpos($walkthrough, '**Why the path must be restricted.**'));
    foreach (['nginx-fpm', 'apache-fpm', 'frankenphp', 'openlitespeed'] as $server) {
        expect($walkthrough)->toContain("- **{$server}**");
    }
});

test('AC-7 learning guide section 21 explains Early Hints and compression with the measured numbers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = strpos($guide, '## 21. Early Hints and compression');
    expect($start)->not->toBeFalse();
    $section = substr($guide, (int) $start, strpos($guide, '## Planned sections') - (int) $start);

    foreach (['103', 'HTTP/2', 'before the slow work', 'proxies', 'preload', 'modulepreload', 'Accept-Encoding', 'Content-Encoding', 'Vary', 'zstd', 'br', 'gzip', 'CPU', 'section 20', 'SendEarlyHints', 'headers_send'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach (['frankenphp-hints', 'frankenphp-no-hints', 'nginx-fpm'] as $variant) {
        $result = json_decode((string) file_get_contents(base_path("bench/results/lcp/2026-11-05/{$variant}.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($section)->toContain(sprintf('| %s | %d ms | %d ms |', $variant, $result['lcp_ms']['median'], $result['fcp_ms']['median']));
    }

    $export = json_decode((string) file_get_contents(base_path('bench/results/compression/2026-11-05/frankenphp.json')), true, flags: JSON_THROW_ON_ERROR)['export']['rows'];
    foreach ($export as $row) {
        if ($row['content_encoding'] !== '' && $row['accept_encoding'] !== 'zstd, br, gzip') {
            expect($section)->toContain(sprintf('| %s | %s | %.3f s |', $row['content_encoding'], number_format($row['wire_bytes'], 0, '', ' '), $row['cpu_seconds']));
        }
    }

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    expect($links[1])->not->toBeEmpty();
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }
});

test('AC-7 guide section 21 links the server compression configs and names the substitute script bundle', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = (int) strpos($guide, '## 21. Early Hints and compression');
    $section = substr($guide, $start, strpos($guide, '## Planned sections') - $start);

    foreach (['../.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp', '../.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp', '../.ddev/nginx_full/nginx-site.conf'] as $link) {
        expect($section)->toContain("]({$link})")
            ->and(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue();
    }

    expect($section)->toContain('lab-bundle')->toContain('0 bytes');
});

test('AC-5 guide section 22 explains HTTP/3 and quotes the measured numbers with working links', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $start = strpos($guide, '## 22. HTTP/3 and QUIC');
    expect($start)->not->toBeFalse();
    $section = substr($guide, (int) $start, strpos($guide, '## Planned sections') - (int) $start);

    foreach (['QUIC', 'UDP', 'Alt-Svc', 'head-of-line', 'Traefik', 'router', 'netem', 'network layer', 'handshake', 'origin-to-force-quic-on', 'nextHopProtocol', 'section 21'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach (['h2-loss0', 'h3-loss0', 'h2-loss2', 'h3-loss2'] as $variant) {
        $result = json_decode((string) file_get_contents(base_path("bench/results/http3/2026-11-05/lcp-{$variant}.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($section)->toContain(sprintf('| %s | %d ms | %d ms | %d ms |', $variant, $result['load_ms']['median'], $result['lcp_ms']['median'], $result['ttfb_ms']['median']));
    }

    foreach (['../bench/http3.sh', '../bench/lib/netem.sh', '../bench/lcp.mjs', '../bench/results/http3/', '../BENCHMARKS.md', '../tests/Feature/BenchHttp3Test.php', '../tests/Feature/BenchNetemTest.php'] as $link) {
        expect($section)->toContain("]({$link}");
    }
    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }
    expect($section)->toContain('https://www.rfc-editor.org/rfc/rfc9000')->toContain('https://www.rfc-editor.org/rfc/rfc9114');
});
