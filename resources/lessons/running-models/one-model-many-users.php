<?php

declare(strict_types=1);

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$run = 'llm/2026-11-13/run.json';
$load = 'llm-load/2026-11-13/concurrency.json';
$search = 'llm-load/2026-11-13/search-under-load.json';
$threads = 'metrics/2026-11-16/sse-frankenphp-auto.json';
$workers = 'metrics/2026-11-16/sse-frankenphp-worker-4.json';
$model = 'models."qwen3.5:9b"';
$other = 'models."gemma4:12b"';

$seconds = static fn (string $file, string $path): array => ['file' => $file, 'path' => $path, 'scale' => 0.001, 'round' => 1];
$figure = static fn (string $file, string $path, int $round = 0): array => ['file' => $file, 'path' => $path, 'round' => $round];
$uncompared = static fn (array $value): array => [...$value, 'trend' => false];

$ttft = $seconds($run, "{$model}.speed.ttft_ms.median");
$ttftFour = $seconds($load, 'models.0.levels.1.ttft_p50_ms');
$ttftSixteen = $seconds($load, 'models.0.levels.2.ttft_p50_ms');
$speed = $figure($run, "{$model}.speed.generation_tokens_per_s.median", 1);
$idle = $figure($search, 'servers.1.without_load.p95_ms');

return [
    'title' => 'One model, many users',
    'summary' => 'One local model serves one visitor at a time. Follow a description from one user to sixteen, and see what the queue does to first-token time and to search.',
    'minutes' => 8,
    'level' => 'beginner',
    'guide' => 'local-models',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Tell the time to first token apart from the writing speed.'],
        ['id' => 'lo-2', 'text' => 'Explain why more users make a single model server slower to start but not slower to write.'],
        ['id' => 'lo-3', 'text' => 'Explain how a few open answer streams can slow search for everyone.'],
        ['id' => 'lo-4', 'text' => 'Name two ways to keep streams from using up the request workers.'],
        ['id' => 'lo-5', 'text' => 'Describe how fast a request fails when the model server is gone, and why a timeout is slower.'],
        ['id' => 'lo-6', 'text' => 'Say what changing the model changes and what it leaves alone.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browsers', 'x' => 150, 'y' => 300],
            ['id' => 'workers', 'kind' => 'workers', 'label' => 'PHP workers', 'x' => 450, 'y' => 300],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model server', 'x' => 850, 'y' => 300],
            ['id' => 'search', 'kind' => 'vector-db', 'label' => 'Search database', 'x' => 450, 'y' => 520],
            ['id' => 'queue', 'kind' => 'queue', 'label' => 'Queue and live updates', 'x' => 450, 'y' => 90],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'workers'],
            ['id' => 'call', 'from' => 'workers', 'to' => 'model'],
            ['id' => 'answer', 'from' => 'model', 'to' => 'workers'],
            ['id' => 'stream', 'from' => 'workers', 'to' => 'browser'],
            ['id' => 'lookup', 'from' => 'workers', 'to' => 'search'],
            ['id' => 'enqueue', 'from' => 'workers', 'to' => 'queue'],
            ['id' => 'queue-call', 'from' => 'queue', 'to' => 'model'],
            ['id' => 'queue-answer', 'from' => 'model', 'to' => 'queue'],
            ['id' => 'live', 'from' => 'queue', 'to' => 'browser'],
        ],
    ],
    'kpis' => [
        ['id' => 'users', 'label' => 'Users at once', 'format' => 'count', 'better' => 'none'],
        ['id' => 'ttft', 'label' => 'First token (median)', 'format' => 's', 'better' => 'lower'],
        ['id' => 'speed', 'label' => 'Writing speed', 'format' => 'tokens-per-s', 'better' => 'higher'],
        ['id' => 'search', 'label' => 'Search p95', 'format' => 'ms', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'One user',
            'body' => [
                'A visitor asks for a description of a car. The browser calls a PHP worker, the worker calls the model server, and the answer comes back as a stream.',
                'The first token takes about {kpi:ttft}. After that the model writes at about {kpi:speed}, faster than anyone reads.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Two numbers describe a model for one user: how long until the first token, and how fast it writes after that.'],
            'state' => [
                'nodes' => [
                    'browser' => ['load' => 5],
                    'workers' => ['load' => 10],
                    'model' => ['load' => 40],
                    'search' => ['load' => 0],
                    'queue' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1]],
                    'answer' => ['packets' => ['token' => 5]],
                    'stream' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [
                'users' => $figure($load, 'models.0.levels.0.level'),
                'ttft' => $ttft,
                'speed' => $speed,
                'search' => $idle,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'first token after {kpi:ttft}'],
                ['sign' => '+', 'text' => 'writing at {kpi:speed}'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'local-models#works',
        ],
        [
            'role' => 'useful',
            'title' => 'What a token stream is',
            'body' => [
                'The model does not send a finished answer. It sends **tokens**, small pieces of text, as soon as each one is ready, and the page shows them as they arrive.',
                'That is why the two numbers matter separately: the first token ends the waiting, and the writing speed sets how long the answer takes to finish.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A slow first token feels like a hang. A slow writing speed feels like a typist. They have different causes.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'tokens appear one by one'],
                    'model' => ['badge' => 'next token'],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'the answer is a stream of tokens, not one message'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'streaming#works',
        ],
        [
            'role' => 'problem',
            'title' => 'Four users',
            'body' => [
                'Four visitors ask at once. The model server answers one request at a time, so three of them wait in its queue.',
                'The median first token now takes {kpi:ttft}. A stream that has started still writes at about {num:'.$load.'#models.0.levels.1.events_per_s_per_stream_median|count|0} chunks a second, so the wait is all at the start.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'The queue costs time before the first token, not speed after it.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'workers' => ['load' => 70],
                    'model' => ['load' => 100, 'queue' => 3, 'status' => 'busy', 'badge' => 'one at a time'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 4]],
                ],
            ],
            'kpis' => [
                'users' => $figure($load, 'models.0.levels.1.level'),
                'ttft' => $ttftFour,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'first token at {kpi:ttft}, up from {num:'.$run.'#'.$model.'.speed.ttft_ms.median|ms|0}'],
                ['sign' => '+', 'text' => 'requests waiting in the model queue'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'local-models#support',
        ],
        [
            'role' => 'problem',
            'title' => 'Sixteen users',
            'body' => [
                'With sixteen visitors the queue is deep. The median first token takes {kpi:ttft}, and nothing failed: every stream finished, they just started late.',
                'A faster model would shorten each turn, but the server still takes them one after another.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Waiting time grows with the length of the queue, so it is a capacity problem, not a speed problem.'],
            'state' => [
                'nodes' => [
                    'workers' => ['load' => 100],
                    'model' => ['queue' => 15, 'badge' => 'one at a time'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 5]],
                ],
            ],
            'kpis' => [
                'users' => $figure($load, 'models.0.levels.2.level'),
                'ttft' => $ttftSixteen,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'first token at {kpi:ttft}, up from {num:'.$load.'#models.0.levels.1.ttft_p50_ms|ms|0}'],
                ['sign' => '+', 'text' => 'a deeper queue at the model server'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'local-models#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'The rest of the site',
            'body' => [
                'Now look at search. On a worker server there are only four PHP workers, and four open streams hold all of them for the whole answer.',
                'A search request has to wait for a worker to come free. Its p95 goes from {num:'.$search.'#servers.1.without_load.p95_ms|ms|0} to {kpi:search}, even though search itself did nothing different.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A slow feature can take the whole site down with it when both share the same workers.'],
            'state' => [
                'nodes' => [
                    'workers' => ['status' => 'busy', 'load' => 100, 'queue' => 6, 'badge' => 'every worker held by a stream'],
                    'search' => ['load' => 10],
                    'model' => ['queue' => 3],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 5]],
                    'lookup' => ['packets' => ['request' => 3]],
                ],
            ],
            'kpis' => [
                'users' => $figure($load, 'models.0.levels.1.level'),
                'ttft' => $uncompared($ttftFour),
                'search' => $figure($search, 'servers.1.with_load.p95_ms'),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'slowest searches from {num:'.$search.'#servers.1.without_load.p95_ms|ms|0} to {kpi:search}'],
                ['sign' => '+', 'text' => 'search requests queued behind the streams'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'local-models#works',
        ],
        [
            'role' => 'solution',
            'title' => 'More threads than streams',
            'body' => [
                'A classic server starts more PHP threads as load arrives. In the same test, with the same open streams, search p95 only went from {num:'.$search.'#servers.0.without_load.p95_ms|ms|0} to {kpi:search}.',
                'A separate metrics run, with answer streams open on each server, shows why. On autoscale up to {num:'.$threads.'#metrics.max_busy_threads} requests were inside PHP at once, more than the {num:'.$threads.'#metrics.max_total_threads} threads the server reports (that gauge does not count the threads it adds). On the worker server {num:'.$workers.'#metrics.max_busy_workers} requests shared {num:'.$workers.'#metrics.total_workers} workers.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Give the site more threads than there will be open streams, and a stream cannot starve a search.'],
            'state' => [
                'nodes' => [
                    'workers' => ['status' => 'ok', 'load' => 60, 'queue' => 0, 'badge' => 'threads to spare'],
                    'search' => ['load' => 20],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 4]],
                    'lookup' => ['packets' => ['request' => 3]],
                ],
            ],
            'kpis' => [
                'search' => $figure($search, 'servers.0.with_load.p95_ms'),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'slowest searches stay near {kpi:search}'],
                ['sign' => '-', 'text' => 'search no longer waits for a worker'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'metrics#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Take streams off the request workers',
            'body' => [
                'The other fix is to stop holding a worker for the whole answer. The request puts a job on a queue and returns at once; a queue worker reads the model and the page gets the tokens as live updates.',
                'The request workers are free again, and how many streams can run at once becomes a setting of the queue instead of a side effect of the web server.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'This lesson did not measure the queued version, so its figures show as not measured.'],
            'state' => [
                'nodes' => [
                    'queue' => ['status' => 'ok', 'queue' => 3, 'badge' => 'jobs wait here'],
                    'workers' => ['load' => 15, 'badge' => 'free for search'],
                    'search' => ['load' => 10],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 4]],
                    'call' => ['visible' => false, 'packets' => ['request' => 0]],
                    'answer' => ['visible' => false, 'packets' => ['token' => 0]],
                    'stream' => ['visible' => false, 'packets' => ['token' => 0]],
                    'enqueue' => ['packets' => ['request' => 2]],
                    'queue-call' => ['packets' => ['request' => 1]],
                    'queue-answer' => ['packets' => ['token' => 5]],
                    'live' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [
                'ttft' => 'not measured',
                'speed' => 'not measured',
                'search' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a queue between the page and the model'],
                ['sign' => '-', 'text' => 'streams holding request workers'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'streaming#modify',
        ],
        [
            'role' => 'careful',
            'title' => 'When the model is gone',
            'body' => [
                'If the model server is not reachable at all, the request fails in about {num:'.$run.'#'.$model.'.failover.unreachable.describe.time_to_fail_ms|ms|0} and the page shows an error.',
                'If the server accepts the connection but never answers, the request waits until its timeout: {num:'.$run.'#'.$model.'.failover.timeout.describe.time_to_fail_ms|ms|0} in the same test.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A refused connection is fast. A silent server is slow, so every call needs a timeout.'],
            'state' => [
                'nodes' => [
                    'queue' => ['status' => 'hidden', 'queue' => 0, 'badge' => null],
                    'workers' => ['load' => 20, 'badge' => null],
                    'search' => ['load' => 0],
                    'model' => ['status' => 'down', 'load' => 0, 'queue' => 0, 'badge' => 'unreachable'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'lookup' => ['packets' => ['request' => 0]],
                    'call' => ['visible' => true, 'packets' => ['request' => 0, 'failure' => 2]],
                    'answer' => ['visible' => true],
                    'stream' => ['visible' => true],
                    'enqueue' => ['packets' => ['request' => 0]],
                    'queue-call' => ['packets' => ['request' => 0]],
                    'queue-answer' => ['packets' => ['token' => 0]],
                    'live' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [
                'users' => $figure($load, 'models.0.levels.0.level'),
                'ttft' => 'no answer',
                'speed' => '—',
                'search' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the model server stops answering'],
                ['sign' => '~', 'text' => 'an unreachable server fails in {num:'.$run.'#'.$model.'.failover.unreachable.describe.time_to_fail_ms|ms|0}'],
                ['sign' => '~', 'text' => 'a silent one holds the request for {num:'.$run.'#'.$model.'.failover.timeout.describe.time_to_fail_ms|ms|0}'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'local-models#support',
        ],
        [
            'role' => 'useful',
            'title' => 'Another model, same queue',
            'body' => [
                'Swapping to a second local model changes the start: its first token takes {kpi:ttft} against {num:'.$run.'#'.$model.'.speed.ttft_ms.median|ms|0}, because it reads the prompt more slowly.',
                'It writes at {kpi:speed}, about the same as before, and needs about the same memory: {num:'.$run.'#'.$other.'.speed.size|bytes|1} against {num:'.$run.'#'.$model.'.speed.size|bytes|1}. The queue is untouched.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Pick a model for quality and first-token time. Plan capacity for the queue, because no model removes it.'],
            'state' => [
                'nodes' => [
                    'model' => ['status' => 'ok', 'load' => 40, 'badge' => 'another model'],
                    'workers' => ['load' => 10],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1, 'failure' => 0]],
                    'answer' => ['packets' => ['token' => 5]],
                    'stream' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [
                'ttft' => $uncompared($seconds($run, "{$other}.speed.ttft_ms.median")),
                'speed' => $uncompared($figure($run, "{$other}.speed.generation_tokens_per_s.median", 1)),
                'search' => $uncompared($idle),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'first token after {kpi:ttft}'],
                ['sign' => '~', 'text' => 'writing speed at {kpi:speed}, close to the first model'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'local-models#numbers',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'First token and writing speed are two numbers. Users queue at the model server, and that costs first-token time. A stream holds a worker, so a few of them can slow search.',
                'Keep more threads than streams, or move streams to a queue. Time out every call to the model, and plan for the queue, whichever model you pick. The numbers above are the first model\'s again, with sixteen users and threads to spare.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'The model is rarely the bottleneck you feel. The queue in front of it, and the workers behind the page, usually are.'],
            'state' => [
                'nodes' => [
                    'model' => ['load' => 100, 'queue' => 15, 'status' => 'busy', 'badge' => null],
                    'workers' => ['load' => 60],
                    'search' => ['load' => 20],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 5]],
                    'lookup' => ['packets' => ['request' => 3]],
                ],
            ],
            'kpis' => [
                'users' => $uncompared($figure($load, 'models.0.levels.2.level')),
                'ttft' => $uncompared($ttftSixteen),
                'speed' => $uncompared($speed),
                'search' => $uncompared($figure($search, 'servers.0.with_load.p95_ms')),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'sixteen users wait {kpi:ttft} for a first token'],
                ['sign' => '~', 'text' => 'search holds at {kpi:search} with threads to spare'],
            ],
            'learn' => [],
            'deeper' => 'local-models#modify',
        ],
    ],
];
