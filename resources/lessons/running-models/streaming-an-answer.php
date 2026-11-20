<?php

declare(strict_types=1);

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$run = 'llm/2026-11-13/run.json';
$classic = 'chat-stop/2026-11-03/frankenphp.json';
$worker = 'chat-stop/2026-11-03/frankenphp-worker.json';
$octane = 'chat-stop/2026-11-03/frankenphp-octane.json';
$fixed = 'slow-load/2026-11-16/sse-frankenphp-14.json';
$growing = 'slow-load/2026-11-16/sse-frankenphp-auto.json';
$few = 'slow-load/2026-11-16/sse-frankenphp-worker-4.json';
$model = 'models."qwen3.5:9b"';

$figure = static fn (string $file, string $path, int $round = 0): array => ['file' => $file, 'path' => $path, 'round' => $round];
$seconds = static fn (string $file, string $path): array => ['file' => $file, 'path' => $path, 'scale' => 0.001, 'round' => 1];
$uncompared = static fn (array $value): array => [...$value, 'trend' => false];

$ttft = $seconds($run, "{$model}.speed.ttft_ms.median");
$speed = $figure($run, "{$model}.speed.generation_tokens_per_s.median", 1);

return [
    'title' => 'Streaming an answer',
    'summary' => 'How text reaches the page piece by piece, what Stop does to the work behind it, and what an open stream costs the server.',
    'minutes' => 10,
    'level' => 'beginner',
    'guide' => 'streaming',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why a streamed answer feels faster than the same answer sent at the end.'],
        ['id' => 'lo-2', 'text' => 'Name what the page receives on the line, and what ends it or breaks it.'],
        ['id' => 'lo-3', 'text' => 'Say why Stop has to reach the model, and how the server finds out the visitor left.'],
        ['id' => 'lo-4', 'text' => 'Tell how much the model still writes after Stop on each of the three server modes.'],
        ['id' => 'lo-5', 'text' => 'Explain why an open stream holds a PHP worker, and what that does to a cheap request.'],
        ['id' => 'lo-6', 'text' => 'Name what keeps a health check answering under open streams, and what does not.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 300],
            ['id' => 'workers', 'kind' => 'workers', 'label' => 'PHP workers', 'x' => 500, 'y' => 300],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model server', 'x' => 850, 'y' => 300],
            ['id' => 'health', 'kind' => 'app', 'label' => 'Health check', 'x' => 150, 'y' => 520],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'workers'],
            ['id' => 'call', 'from' => 'workers', 'to' => 'model'],
            ['id' => 'answer', 'from' => 'model', 'to' => 'workers'],
            ['id' => 'stream', 'from' => 'workers', 'to' => 'browser'],
            ['id' => 'probe', 'from' => 'health', 'to' => 'workers'],
        ],
    ],
    'kpis' => [
        ['id' => 'ttft', 'label' => 'First token (median)', 'format' => 's', 'better' => 'lower'],
        ['id' => 'speed', 'label' => 'Writing speed', 'format' => 'tokens-per-s', 'better' => 'higher'],
        ['id' => 'after', 'label' => 'Pieces sent after Stop (median)', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'free', 'label' => 'Model free again after Stop', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'health', 'label' => 'Health check, slowest answers', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'missed', 'label' => 'Health checks unanswered', 'format' => 'percent', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A blank wait',
            'body' => [
                'A visitor asks for a description of a car. If the server sends the answer only when the model has finished, the page stays empty for the whole writing time. A description in the speed test was {num:'.$run.'#'.$model.'.speed.output_tokens.median|count|0} tokens long, written at about {kpi:speed}.',
                'The model had its first token ready after {kpi:ttft}, but nothing could show it.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'The wait that hurts is the one with nothing on the screen.'],
            'state' => [
                'nodes' => [
                    'browser' => ['load' => 5, 'badge' => 'blank until the end'],
                    'workers' => ['load' => 10],
                    'model' => ['load' => 40],
                    'health' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1]],
                    'answer' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [
                'ttft' => $ttft,
                'speed' => $speed,
                'after' => '—',
                'free' => '—',
                'health' => '—',
                'missed' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'nothing is shown until the whole answer exists'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'streaming#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Send each piece as it is ready',
            'body' => [
                'The server answers with a stream. It writes one line for every piece of text the model produces and flushes it at once, so the page shows the words as they arrive.',
                'The first words now appear after {kpi:ttft}, and the answer finishes at the same moment as before. Only the waiting changed.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Streaming does not make the model faster. It makes the first word reach the visitor sooner.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'text grows word by word'],
                ],
                'edges' => [
                    'stream' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the first words show after {kpi:ttft}'],
                ['sign' => '~', 'text' => 'the answer ends when it did before'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'streaming#works',
        ],
        [
            'role' => 'useful',
            'title' => 'What travels on the line',
            'body' => [
                'The response is a plain text stream. Each line starts with `data:` and holds one event, and the server flushes after every line, with proxy buffering switched off.',
                'The last line is a done marker, so the page can tell a finished answer from a dropped connection. A provider error never reaches the page as it is: once the first line is sent the status code is gone, so the stream sends one generic error event and keeps the real cause in the run log.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A line that never arrives and a line that says "error" mean different things. The done marker is what tells them apart.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'reads one line at a time'],
                    'workers' => ['badge' => 'flush after every line'],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'one event per line, ended by a done marker'],
                ['sign' => '+', 'text' => 'errors sent as one safe event'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'streaming#works',
        ],
        [
            'role' => 'problem',
            'title' => 'The visitor leaves',
            'body' => [
                'Halfway through the answer the visitor presses Stop, or closes the tab. The page stops drawing text, but the work behind it is a separate question.',
                'PHP only learns that the connection closed when it next tries to write to it. Until then the server keeps reading from the model, and the model keeps writing for nobody.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A Stop button that only hides the text leaves the model busy, and the next visitor waits behind it.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'Stop pressed'],
                    'workers' => ['badge' => 'has not noticed yet'],
                    'model' => ['status' => 'busy', 'load' => 100, 'badge' => 'still writing'],
                ],
                'edges' => [
                    'stream' => ['packets' => ['token' => 0]],
                    'answer' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'the text stops, the model does not'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'streaming#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Stop that reaches the model',
            'body' => [
                'The server keeps running after the client has gone, and after every flush it asks whether the connection is still open. When it is not, it stops reading, lets go of the model stream, which closes the connection to the model server, and writes a run row marked stopped.',
                'In the test the median number of pieces sent after the visitor left was {kpi:after}, and a one-token request to the model server, sent right after the stop, took {kpi:free}, against {num:'.$classic.'#median_idle_probe_ms|ms|0} when idle. Its own log shows every stopped request as cancelled.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Check after every write. A closed connection shows itself only on the next one.'],
            'state' => [
                'nodes' => [
                    'workers' => ['badge' => 'sees the close, stops'],
                    'model' => ['status' => 'ok', 'load' => 0, 'badge' => 'request cancelled'],
                ],
                'edges' => [
                    'call' => ['packets' => ['request' => 0, 'failure' => 2]],
                    'answer' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [
                'after' => $figure($classic, 'median_tokens_after_stop'),
                'free' => $figure($classic, 'median_probe_ms_after_stop'),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the model is released when the visitor leaves'],
                ['sign' => '~', 'text' => 'pieces sent after Stop, median: {kpi:after}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'streaming#support',
        ],
        [
            'role' => 'careful',
            'title' => 'The same on every server mode',
            'body' => [
                'The lab runs on classic FrankenPHP, on worker mode and on Octane, and the stop was measured on each. After Stop the median was {num:'.$classic.'#median_tokens_after_stop|count|0}, {num:'.$worker.'#median_tokens_after_stop|count|0} and {num:'.$octane.'#median_tokens_after_stop|count|0} pieces, and the model was free again after {num:'.$classic.'#median_probe_ms_after_stop|ms|0}, {num:'.$worker.'#median_probe_ms_after_stop|ms|0} and {kpi:free}.',
                'All three look alike. That is a result, not a given: whether a server notices a closed connection depends on the path in front of PHP.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Measure Stop on the server mode you run. The page cannot tell you whether the model really stopped.'],
            'state' => [
                'nodes' => [
                    'workers' => ['badge' => 'classic, worker or Octane'],
                ],
            ],
            'kpis' => [
                'after' => $uncompared($figure($octane, 'median_tokens_after_stop')),
                'free' => $uncompared($figure($octane, 'median_probe_ms_after_stop')),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'worker mode and Octane stop as classic does'],
                ['sign' => '~', 'text' => 'the model is free again after {kpi:free} on Octane'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'streaming#modify',
        ],
        [
            'role' => 'careful',
            'title' => 'A stream holds a worker',
            'body' => [
                'While an answer is written, the request that carries it stays inside a PHP worker, for the whole length of the answer. That is the price of answering over a direct stream.',
                'In a test with {num:'.$fixed.'#users|count|0} visitors streaming at once, on a fixed set of threads, a health check that does no work at all waited in the same queue. Its slowest answers took {kpi:health}, and {kpi:missed} of the checks got no answer in time.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A load balancer that loses its health check marks a healthy server dead.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'workers' => ['status' => 'busy', 'load' => 100, 'queue' => 6, 'badge' => 'a worker per open answer'],
                    'model' => ['status' => 'ok', 'load' => 60, 'badge' => null],
                    'health' => ['status' => 'ok', 'load' => 0],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 5]],
                    'answer' => ['packets' => ['token' => 5]],
                    'stream' => ['packets' => ['token' => 5]],
                    'probe' => ['packets' => ['health' => 3]],
                ],
            ],
            'kpis' => [
                'health' => $figure($fixed, 'median.health_p95_ms'),
                'missed' => $figure($fixed, 'median.health_error_percent'),
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'health checks wait behind the open streams'],
                ['sign' => '~', 'text' => '{kpi:missed} of the checks go unanswered'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'streaming#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Let the threads grow',
            'body' => [
                'With the same visitors and the same streams, but with threads allowed to grow under load, the health check answered even in its slowest cases within {kpi:health}, and {kpi:missed} of the checks went unanswered.',
                'The streams still hold their workers. There are just more workers than streams, so a cheap request always finds a free one.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Plan more threads than there will be open answers. The pool must be larger than the longest answers you allow.'],
            'state' => [
                'nodes' => [
                    'workers' => ['status' => 'ok', 'load' => 60, 'queue' => 0, 'badge' => 'threads to spare'],
                ],
                'edges' => [
                    'probe' => ['packets' => ['health' => 3]],
                ],
            ],
            'kpis' => [
                'health' => $figure($growing, 'median.health_p95_ms'),
                'missed' => $figure($growing, 'median.health_error_percent'),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the health check answers at {kpi:health}'],
                ['sign' => '-', 'text' => 'no check goes unanswered'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'streaming#works',
        ],
        [
            'role' => 'careful',
            'title' => 'A few workers do not carry it',
            'body' => [
                'Worker mode and Octane were tested with a small fixed set of workers. With the same streams, {num:'.$few.'#median.health_error_percent|percent|0} of the health checks went unanswered there.',
                'Stop frees a worker only for the visitors who press it. The size of the pool is what carries everyone else.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Stop returns a worker; it does not change how many workers there are.'],
            'state' => [
                'nodes' => [
                    'workers' => ['status' => 'busy', 'load' => 100, 'queue' => 6, 'badge' => 'a small fixed pool'],
                ],
                'edges' => [
                    'probe' => ['packets' => ['health' => 3]],
                ],
            ],
            'kpis' => [
                'health' => $uncompared($figure($few, 'median.health_p95_ms')),
                'missed' => $uncompared($figure($few, 'median.health_error_percent')),
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'every health check goes unanswered while the streams run'],
                ['sign' => '~', 'text' => 'the slowest health checks hit {kpi:health}, the limit the test allowed'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'streaming#support',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Stream the answer, so the first words show after the first token and not after the last. Send one event per line, end with a marker, and keep errors generic on the line.',
                'Make Stop reach the model: check the connection after every write, and measure it on the server mode you run. An open stream holds a worker, so keep more threads than open answers, because Stop alone will not protect the rest of the site.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A stream is cheap to read and expensive to hold. Count the open streams, not the requests.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'workers' => ['status' => 'ok', 'load' => 60, 'queue' => 0, 'badge' => 'threads to spare'],
                    'model' => ['status' => 'ok', 'load' => 40],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1]],
                    'answer' => ['packets' => ['token' => 5]],
                    'stream' => ['packets' => ['token' => 5]],
                    'probe' => ['packets' => ['health' => 3]],
                ],
            ],
            'kpis' => [
                'ttft' => $uncompared($ttft),
                'speed' => $uncompared($speed),
                'after' => $uncompared($figure($classic, 'median_tokens_after_stop')),
                'free' => $uncompared($figure($classic, 'median_probe_ms_after_stop')),
                'health' => $uncompared($figure($growing, 'median.health_p95_ms')),
                'missed' => $uncompared($figure($growing, 'median.health_error_percent')),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'first words after {kpi:ttft}'],
                ['sign' => '+', 'text' => 'the model free again after {kpi:free} on Stop'],
                ['sign' => '+', 'text' => 'the health check at {kpi:health} with threads to spare'],
            ],
            'learn' => [],
            'deeper' => 'streaming#modify',
        ],
    ],
];
