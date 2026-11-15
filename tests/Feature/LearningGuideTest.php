<?php

declare(strict_types=1);

use App\Services\RagEvalSet;

/**
 * One `##` section of the learning guide: from its heading to the next `#` or `##` heading.
 */
function learningGuideSection(string $guide, string $heading): string
{
    $start = strpos($guide, "\n## ".$heading);
    expect($start)->not->toBeFalse();

    $rest = substr($guide, (int) $start + 1);
    $end = preg_match('/^#{1,2} /m', $rest, $next, PREG_OFFSET_CAPTURE, 3) === 1 ? $next[0][1] : strlen($rest);

    return substr($rest, 0, $end);
}

test('AC-10 learning guide explains state leaks with links', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^### State leaks in worker mode\n(.*?)(?=^#{1,3} |\z)/ms', $guide, $matches);
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

    preg_match('/^## II-2\. Streaming an AI answer\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
});

test('AC-8 learning guide explains running the model locally', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-3\. Running the model locally\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
    expect($guide)->not->toContain('running models locally (SL-22)');
});

test('AC-9 learning guide explains embeddings and vector search', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-9\. Embeddings and vector search\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
    expect($guide)->not->toContain('embeddings and vector search (SL-24)');
});

test('AC-9 learning guide explains chunking and hybrid retrieval', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-10\. Chunking and hybrid retrieval\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
    expect($guide)->not->toContain('RAG with citations (SL-32)');
});

test('AC-8 learning guide explains the AI lab UI', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-22\. The AI lab UI: Inertia, Vue and streams in the browser\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
});

test('AC-11 learning guide documents docs answering', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-11\. Answering with citations: RAG, guards and the trace row\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
    expect($guide)->not->toContain('RAG with citations (SL-45, on top of the index from SL-32)');
});

test('AC-1 NFR-1 learning guide records the live docs answering run', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-11\. Answering with citations: RAG, guards and the trace row\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';

    foreach (['Live run: five questions', 'tokens/s', 'no_context', '1695', '2290', '2595', '2587', '1758', '1766', '4072', '5270', '4600', '500 ms'] as $needle) {
        expect($section)->toContain($needle);
    }
});

test('AC-10 learning guide II-12 explains measuring RAG', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-12\. Measuring RAG: recall@k, calibration and LLM grading\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)');
});

test('the learning guide maps every AI file, request flow and feature recipe, and every linked file exists', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'II-24. How the AI features are built: a map of the code');

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

test('AC-11 learning guide II-13 explains caching answers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-13\. Caching answers: exact keys, semantic neighbours and invalidation\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('Part II (II-1 to II-24)')->toContain('## II-13. Caching answers');
});

test('AC-11 learning guide II-24 maps every answer cache file and the cache flow', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'II-24. How the AI features are built: a map of the code');

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

test('AC-8 learning guide II-14 explains Stop that really stops and conversations', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-14\. Stop that really stops, and conversations\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
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

    expect($guide)->toContain('## II-14. Stop that really stops');
});

test('AC-8 learning guide II-24 maps the Stop and conversation files, flow and feature request', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'II-24. How the AI features are built: a map of the code');

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
        ->toContain('Stop doesn\'t stop')->toContain('section [II-14]');
});

test('AC-8 learning guide I-6 explains slow I/O, worker pools and the health check', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-6. Slow I/O, worker pools and a health check that stays fast');

    foreach (['pm.max_children', 'max_threads', 'max_wait_time', 'Little', '/healthz', 'no middleware group', 'SSE', 'queued', '4003 ms', '276 ms', '299 of 300', '16 ms'] as $needle) {
        expect($section)->toContain($needle);
    }

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    expect(learningGuideSection($guide, 'II-24. '))->toContain('bench/slow-load.sh --workload sse');
});

test('AC-7 learning guide I-7 explains offloaded downloads with the measured numbers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-7. Letting the web server send the file');

    foreach (['X-Accel-Redirect', 'X-Sendfile', 'X-LiteSpeed-Location', 'mod_xsendfile', 'intercept', '/_protected_exports/', 'internal', 'php_held_ms', 'bytes_sent_by_php', 'stream-fallback', 'nginx-fpm', 'apache-fpm', 'frankenphp', 'openlitespeed', 'section [I-6]'] as $needle) {
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

test('AC-7 learning guide I-7 walks each server through one offload and notes the out-of-scope modes', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-7. Letting the web server send the file');

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

test('AC-7 learning guide I-8 explains Early Hints and compression with the measured numbers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-8. Early Hints and compression');

    foreach (['103', 'HTTP/2', 'before the slow work', 'proxies', 'preload', 'modulepreload', 'Accept-Encoding', 'Content-Encoding', 'Vary', 'zstd', 'br', 'gzip', 'CPU', 'section [I-7]', 'SendEarlyHints', 'headers_send'] as $needle) {
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

test('AC-7 guide I-8 links the server compression configs and names the substitute script bundle', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-8. Early Hints and compression');

    foreach (['../.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp', '../.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp', '../.ddev/nginx_full/nginx-site.conf'] as $link) {
        expect($section)->toContain("]({$link})")
            ->and(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue();
    }

    expect($section)->toContain('lab-bundle')->toContain('0 bytes');
});

test('AC-5 guide I-9 explains HTTP/3 and quotes the measured numbers with working links', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-9. HTTP/3 and QUIC');

    foreach (['QUIC', 'UDP', 'Alt-Svc', 'head-of-line', 'Traefik', 'router', 'netem', 'network layer', 'handshake', 'origin-to-force-quic-on', 'nextHopProtocol', 'section [I-8]'] as $needle) {
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

test('AC-7 guide I-10 explains Mercure, quotes the measured numbers and II-24 points to it', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'I-10. Live updates with Mercure');

    foreach (['EventSource', 'text/event-stream', 'topic', 'JWT', 'mercure.subscribe', 'HttpOnly', 'SameSite=Strict', 'aria-live', 'private', 'Give up', 'section [I-6]', 'section [II-2]'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ([100, 1000] as $subscribers) {
        $result = json_decode((string) file_get_contents(base_path("bench/results/mercure-load/2026-11-05/n{$subscribers}-frankenphp.json")), true, flags: JSON_THROW_ON_ERROR);
        expect($section)->toContain(sprintf('| %d | %d ms | %d ms | %d ms | %d |', $subscribers, $result['latency_ms']['p50'], $result['latency_ms']['p95'], round($result['health']['p95_ms']), $result['busy_threads']['max']));
    }

    foreach (['../bench/mercure-load.sh', '../BENCHMARKS.md', '../app/Support/MercurePublisher.php', '../app/Support/MercureTokens.php', '../tests/Feature/MercureTokensTest.php', '../tests/Feature/BenchMercureLoadTest.php', '../.ai/specs/spec-20261105-1709-mercure-live-price/'] as $link) {
        expect($section)->toContain("]({$link}");
    }
    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    expect($section)->toContain('](../bench/results/mercure-load/')->toContain('.ddev/.env.web');

    foreach (['same-origin', 'whatever `withCredentials` says', 'Why a plain page clears the cookie', 'lastEventID=earliest', 'data-price-rendered-ms', 'transport bolt', 'queue the publish', 'one extra request per page view', 'Caddy metrics'] as $needle) {
        expect($section)->toContain($needle);
    }
    expect($section)->not->toContain('PublishListingPrice')->not->toContain('because the script sets `withCredentials`');

    $map = [learningGuideSection($guide, 'II-24. ')];
    expect($map[0] ?? '')->toContain('section [I-10]')->toContain('Mercure');
    expect($map[0] ?? '')->toMatch('/Notify the browser when something changes[^\n]*section \[I-10\]/');
});

test('AC-9 guide II-6 explains structured output with a validation boundary and quotes the eval numbers', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $result = json_decode((string) file_get_contents(base_path('bench/results/nl-search/2026-11-09/run.json')), true, flags: JSON_THROW_ON_ERROR);

    preg_match('/^## II-6\. From words to filters: structured output with a validation boundary\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
    $section = $matches[1] ?? '';
    expect($section)->not->toBe('');

    foreach (['structured output', 'ListingFilterRules', 'unsupported', 'extracted', 'bound', 'refused', 'validation_failed', 'nl:eval', 'exact match', 'unsupported recall', 'median', 'p95', 'alias', 'ListingSearchService', 'allowedModels'] as $needle) {
        expect($section)->toContain($needle);
    }

    foreach ($result['models'] as $model) {
        expect($section)->toContain(sprintf('| %s | %.3f | %d/%d | %d ms | %d ms |', $model['model'], $model['exact_match']['rate'], $model['unsupported_recall']['caught'], $model['unsupported_recall']['expected'], $model['latency_ms']['median'], $model['latency_ms']['p95']));
    }

    foreach ([
        '../app/Ai/ListingFilterAgent.php',
        '../app/Services/NlSearchService.php',
        '../app/Rules/ListingFilterRules.php',
        '../app/Console/Commands/NlEvalCommand.php',
        '../app/Services/NlEvalScorer.php',
        '../resources/prompts/listing-filters.v1.md',
        '../bench/nl-search/queries.v1.jsonl',
        '../bench/results/nl-search/2026-11-09/',
        '../tests/Feature/NlSearchTest.php',
        '../BENCHMARKS.md',
        '../.ai/specs/spec-20261109-1213-nl-search/',
    ] as $link) {
        expect($section)->toContain("]({$link}");
    }
    expect($section)->toMatch('/\]\(https:\/\/[^)]*laravel\.com\/docs\/13\.x\/ai-sdk/');

    preg_match_all('/\]\((\.\.\/[^)#]+)/', $section, $links);
    foreach ($links[1] as $link) {
        expect(file_exists(dirname(base_path('docs/learning-guide.md')).'/'.$link))->toBeTrue("missing: {$link}");
    }

    $map = [learningGuideSection($guide, 'II-24. ')];
    expect($map[0] ?? '')->toContain('section [II-6]')->toContain('NlSearchService')->toContain('NlSearchController')->toContain('NlEvalCommand');
    expect($guide)->toContain('## II-6. From words to filters');
});

test('AC-9 guide II-6 describes recall per term and II-24 has the free-text row', function () {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));

    preg_match('/^## II-6\. .*?(?=^#{1,2} |\z)/ms', $guide, $matches);
    $section = $matches[0] ?? '';
    expect($section)
        ->toContain('per term')
        ->toContain('12 expected terms over 10 queries')
        ->toContain('case-insensitive substring')
        ->not->toContain('got a non-empty `unsupported` list');

    preg_match('/^### Common feature requests and where they land\n(.*?)(?=^\*\*|^## )/ms', $guide, $rows);
    $row = collect(explode("\n", $rows[1] ?? ''))->first(fn (string $line): bool => str_contains($line, 'free text into structured'));
    expect($row)->not->toBeNull()
        ->toContain('ListingFilterAgent')
        ->toContain('ListingFilterRules')
        ->toContain('NlSearchService')
        ->toContain('section [II-6]');
});

test('AC-8 learning guide II-24 lists every in-app guide file', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'II-24. ');

    expect($section)->toContain('### In-app guides');

    $files = glob(base_path('resources/guides/*.php')) ?: [];
    expect($files)->not->toBeEmpty();

    foreach ($files as $file) {
        expect($section)->toContain('](../resources/guides/'.basename($file).')');
    }
});

test('AC-4 learning guide sections I-1 to I-5 that name a removed server start with the history line', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));
    $history = '*History: measured on nginx-fpm, apache-fpm and OpenLiteSpeed, which the lab dropped on 2026-11-10; the FrankenPHP rows still apply.*';

    foreach (['I-1', 'I-2', 'I-3', 'I-4', 'I-5'] as $number) {
        preg_match('/^## '.$number.'\. [^\n]*\n(.*?)(?=^#{1,2} |\z)/ms', $guide, $matches);
        $section = $matches[1] ?? '';

        expect($section)->not->toBe('');

        if (preg_match('/nginx-fpm|apache-fpm|openlitespeed/i', $section) === 1) {
            expect(ltrim($section))->toStartWith($history);
        }

        if ($number === 'I-3') {
            expect($section)->toContain('`frankenphp`')
                ->toContain('`frankenphp-worker`')
                ->toContain('`frankenphp-octane`');
        }
    }
});

test('AC-8 learning guide II-24 lists the cloud provider files and the add-a-provider recipe', function (): void {
    $guide = (string) file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, 'II-24. ');

    foreach ([
        'app/Http/Middleware/LimitCloudAi.php',
        'app/Exceptions/ProviderNotConfiguredException.php',
        'app/Exceptions/ProviderNotEnabledException.php',
        'app/Exceptions/IndexNotBuiltException.php',
        'app/Exceptions/EmbeddingDimensionsException.php',
        'app/Contracts/EmbeddingIndexRepositoryInterface.php',
        'app/Repositories/EloquentEmbeddingIndexRepository.php',
        'app/Services/LabProviderService.php',
        'app/DTOs/LabProviderOption.php',
        'app/Http/Requests/DescribeListingRequest.php',
        'app/Http/Requests/Concerns/NamesProvider.php',
        'resources/js/ai/components/LabProviderSelect.vue',
        'resources/js/ai/composables/useProviderChoice.ts',
    ] as $file) {
        expect($section)->toContain('](../'.$file.')');
        expect(file_exists(base_path($file)))->toBeTrue();
    }

    expect($section)->toContain('"Add a provider"')->toContain('ANTHROPIC_API_KEY');
});

dataset('AC-2 full sections', [
    'I-2 server history' => ['I-2. '],
    'II-4 providers and settings' => ['II-4. '],
    'II-5 local model limits' => ['II-5. '],
    'II-8 vision extraction' => ['II-8. '],
    'II-15 similar photos and text search' => ['II-15. '],
    'II-16 hybrid and duplicates' => ['II-16. '],
    'II-18 hand-written tool loop' => ['II-18. '],
    'II-19 MCP' => ['II-19. '],
    'II-20 research agent' => ['II-20. '],
    'II-21 prompt playground' => ['II-21. '],
    'II-23 in-app guides and homescreen' => ['II-23. '],
]);

test('AC-2 guide section has code and spec links, a numbers or not-measured line and a read-next line', function (string $heading): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));
    $section = learningGuideSection($guide, $heading);

    expect($section)->toMatch('/\]\(\.\.\/(app|resources|routes|config|\.ddev)\//');
    expect($section)->toContain('](../.ai/specs/');
    expect($section)->toContain('**Read next.**');
    expect(preg_match('/^\*\*Numbers\.\*\*|^Not measured:|^\*\*Numbers\b|\bNot measured:/m', $section))->toBe(1);

    if (! str_starts_with($heading, 'I-2.')) {
        expect(preg_match('/^\*\*[^\n]*step by step[^\n]*\n\n?1\. /mi', $section))->toBe(1);
    }
})->with('AC-2 full sections');

test('AC-5 guide numbers in the new and promoted sections match BENCHMARKS and the result files', function (): void {
    $guide = file_get_contents(base_path('docs/learning-guide.md'));
    $benchmarks = file_get_contents(base_path('BENCHMARKS.md'));

    $fromBenchmarks = [
        'II-8. ' => ['3697 ms', '5879 ms', '37 of 50', '2572 ms', '6523 ms', '41 of 50', '0.860'],
        'II-15. ' => ['0.785', '0.677', '0.987', '0.82 ms', '1.26 ms', '0.895', '0.603', '11.4 s', '0.506', '0.569', '60.4 ms', '136.1 ms'],
        'II-16. ' => ['0.693', '0.417', '2.42 ms', '4.32 ms', '0.184', '0.902', '0.886', '0.894', '185 ms', '0.263', '0.421'],
    ];

    foreach ($fromBenchmarks as $heading => $numbers) {
        $section = learningGuideSection($guide, $heading);
        $quoted = substr($section, (int) strpos($section, '**Numbers.**'));

        foreach ($numbers as $number) {
            expect($quoted)->toContain($number);
            expect($benchmarks)->toContain((string) preg_replace('/^(\d+) of 50$/', '$1/50', $number));
        }
    }

    $research = json_decode((string) file_get_contents(base_path('bench/results/research/2026-11-14/stats.json')), true)['summary'];
    $researchSection = learningGuideSection($guide, 'II-20. ');
    expect($research['runs'])->toBe(15);
    expect($research['done'])->toBe(11);
    expect($research['refused'])->toBe(4);
    expect($research['average_model_calls'])->toBe(3.9);
    expect($research['average_steps'])->toBe(7.8);
    expect($research['average_duration_s'])->toBe(20);
    expect($researchSection)->toContain('15 runs')->toContain('3.9 model calls')->toContain('7.8 steps')->toContain('took 20 s');

    $playground = json_decode((string) file_get_contents(base_path('bench/results/playground/2026-11-14/stats.json')), true)['summary'];
    $playgroundSection = learningGuideSection($guide, 'II-21. ');
    expect($playground['runs'])->toBe(9);
    expect($playground['two_model_local_runs'])->toBe(7);
    expect($playground['average_two_model_local_duration_s'])->toBe(10.3);
    expect($playgroundSection)->toContain('9 stored runs')->toContain('7 runs')->toContain('10.3 s');
});
