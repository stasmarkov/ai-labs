<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$workerPing = 'restart/2026-11-17/frankenphp-worker-api-ping.json';
$workerSearch = 'restart/2026-11-17/frankenphp-worker-listings-make-BMW.json';
$octanePing = 'restart/2026-11-17/frankenphp-octane-api-ping.json';
$overhead = 'restart/2026-11-17/log-overhead.json';

$lostNative = Source::figure($workerPing, 'failures.inside');
$lostReload = Source::figure($octanePing, 'failures.inside');
$tailIn = Source::figure($workerSearch, 'median_p99_ms.inside', 1);
$tailOut = Source::figure($workerSearch, 'median_p99_ms.outside', 1);
$cost = Source::figure($overhead, 'difference_percent');

return [
    'title' => 'Logs and a safe worker restart',
    'summary' => 'How one request id follows a request into every log line, what the JSON channel costs, and how replacing the PHP workers loses requests on one server mode and none on the other.',
    'minutes' => 11,
    'level' => 'intermediate',
    'guide' => 'logs-restart',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain how a request gets its id and where the id shows up.'],
        ['id' => 'lo-2', 'text' => 'Name what every log entry carries and where it is written.'],
        ['id' => 'lo-3', 'text' => 'Say what the redaction covers and what it cannot see.'],
        ['id' => 'lo-4', 'text' => 'Tell what the JSON channel costs, and why it is opt-in.'],
        ['id' => 'lo-5', 'text' => 'Compare what the two ways of replacing workers lose under load.'],
        ['id' => 'lo-6', 'text' => 'Say what the native restart costs instead, and why answered-only latency misleads.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 200],
            ['id' => 'workers', 'kind' => 'workers', 'label' => 'PHP workers', 'x' => 500, 'y' => 300],
            ['id' => 'log', 'kind' => 'app', 'label' => 'Log stream', 'x' => 850, 'y' => 150],
            ['id' => 'person', 'kind' => 'person', 'label' => 'Developer', 'x' => 150, 'y' => 480],
            ['id' => 'load', 'kind' => 'users', 'label' => 'Steady load', 'x' => 850, 'y' => 480],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'workers'],
            ['id' => 'reply', 'from' => 'workers', 'to' => 'browser'],
            ['id' => 'entry', 'from' => 'workers', 'to' => 'log'],
            ['id' => 'restart', 'from' => 'person', 'to' => 'workers'],
            ['id' => 'traffic', 'from' => 'load', 'to' => 'workers'],
        ],
    ],
    'kpis' => [
        ['id' => 'cost', 'label' => 'Cost of the JSON channel', 'format' => 'percent', 'better' => 'lower'],
        ['id' => 'lostReload', 'label' => 'Probe requests lost, Octane reload', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'lostNative', 'label' => 'Probe requests lost, native restart', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'tailIn', 'label' => 'Slow tail of search during a restart', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'tailOut', 'label' => 'Slow tail of search otherwise', 'format' => 'ms', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'Log lines with no request id',
            'body' => [
                'Many requests run at once, and their log lines interleave in one file. A line says something went wrong, but not for which request, and not whether two lines belong together.',
                'In worker mode it gets worse: one PHP process serves many requests, so anything remembered between them can leak from one request into the next.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A log line you cannot tie to a request is only a rumour.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'many at once'],
                    'workers' => ['load' => 60],
                    'log' => ['badge' => 'lines interleave'],
                    'person' => ['status' => 'hidden'],
                    'load' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 3]],
                    'entry' => ['packets' => ['request' => 3]],
                ],
            ],
            'kpis' => [
                'cost' => '—',
                'lostReload' => '—',
                'lostNative' => '—',
                'tailIn' => '—',
                'tailOut' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'no line says which request it belongs to'],
            ],
            'learn' => [],
            'deeper' => 'logs-restart#works',
        ],
        [
            'role' => 'solution',
            'title' => 'An id for every request',
            'body' => [
                'A middleware gives each request an id. An incoming request id header is reused when it is a string of letters, digits and dashes that is neither too short nor too long. Anything else is replaced by a new ULID, without a warning.',
                'The id goes into hidden context, which the framework clears for every request, and onto the response as a header. Because the context is cleared for every request, the id of one request does not leak into the next on the same worker.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Check an incoming id before you reuse it. A caller must not choose what lands in your log.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'sees the id in a header'],
                    'workers' => ['badge' => 'id cleared per request'],
                    'log' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'reply' => ['packets' => ['request' => 1]],
                    'entry' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'every response carries its request id'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'logs-restart#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The same fields on every entry',
            'body' => [
                'A processor adds the id, the method, the path without its query string, the route name, the server mode, a worker flag and the milliseconds since the request started.',
                'A console command has no request, so those fields are empty and the entry says cli. A queue job says queue, and so does the error reported when a job fails.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Leave the query string out of the path. It often holds what a visitor typed.'],
            'state' => [
                'nodes' => [
                    'log' => ['badge' => 'id, route, mode, time'],
                ],
                'edges' => [
                    'entry' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'one entry shows id, method, path and elapsed time'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'logs-restart#works',
        ],
        [
            'role' => 'solution',
            'title' => 'One JSON entry, written by the server',
            'body' => [
                'Inside a request, the entry goes through the server\'s own log function, so it lands in the server log stream next to the access log. Outside a request, in the console or a queue worker, it is one JSON line on stderr.',
                'The server prints a nested array as a pair of map objects, so the handler passes flat keys. An error arrives as its class, message, code and file with the line.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Test logging inside a real served request. A unit test cannot see how the server prints a field.'],
            'state' => [
                'nodes' => [
                    'log' => ['badge' => 'next to the access log'],
                ],
                'edges' => [
                    'entry' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'entries are plain JSON that any collector can read'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'logs-restart#implementation',
        ],
        [
            'role' => 'careful',
            'title' => 'Secrets are taken out by key',
            'body' => [
                'A value under a key such as password, secret, token, authorization or cookie is replaced by redacted text before the entry is written, at any depth and in any letter case. A secret under any other key, such as dsn or credentials, is written as it is.',
                'Counters such as tokens in stay readable. Only keys are checked: a secret written into the message itself is not found, and neither is one in a list item. Put a secret only under a key with one of those names, never in the message.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Redaction reads keys, not sentences. A secret in the message text goes straight to the log.'],
            'state' => [
                'nodes' => [
                    'log' => ['badge' => 'values under secret keys hidden'],
                ],
                'edges' => [
                    'entry' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'values under secret keys are redacted'],
                ['sign' => '~', 'text' => 'a secret in the message text is not'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'logs-restart#support',
        ],
        [
            'role' => 'careful',
            'title' => 'The channel is not free',
            'body' => [
                'Writing each entry through the server costs time. On a request that does almost nothing else, the channel made the slower requests {kpi:cost} slower, against a budget of {num:'.$overhead.'#threshold_percent|percent|0}.',
                'That is over the budget, so the channel is opt-in. The default stack stays a plain file, and you switch the channel on in the env file when you want the entries.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Measure what a logger costs on the cheapest request. A busy request hides it.'],
            'state' => [
                'nodes' => [
                    'log' => ['badge' => 'opt-in'],
                    'workers' => ['badge' => null],
                ],
                'edges' => [
                    'entry' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'cost' => $cost,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'the slower requests take {kpi:cost} longer with the channel on'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'logs-restart#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'Switch it on and follow one request',
            'body' => [
                'Put the channel next to the file in the log stack setting, restart the project, call any endpoint and read the id from the response header. Then read the server log and find the same id on the entry.',
                'Send your own safe id to check the rule: it comes back unchanged. A short or odd one is replaced.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Search every log for the id a customer gives you. One id finds the whole request.'],
            'state' => [
                'nodes' => [
                    'person' => ['status' => 'ok', 'badge' => 'reads the id'],
                    'log' => ['badge' => 'same id'],
                ],
                'edges' => [
                    'reply' => ['packets' => ['request' => 1]],
                    'entry' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the id from a response finds its log lines'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'logs-restart#modify',
        ],
        [
            'role' => 'problem',
            'title' => 'Replacing workers under load',
            'body' => [
                'A worker keeps the app in memory, so new code needs new workers. If replacing them cuts requests off, every deploy costs some. On Octane, a reload under steady load lost {kpi:lostReload} of {num:'.$octanePing.'#probe_requests.inside|count|0} probe requests in its windows.',
                'The same load also saw {num:'.$octanePing.'#load_non_2xx.inside|count|0} answers that were not a success, during the windows of the reload.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A reload that works on a quiet machine can still drop requests when traffic is steady.'],
            'state' => [
                'nodes' => [
                    'person' => ['badge' => 'reloads Octane'],
                    'load' => ['status' => 'ok', 'load' => 80, 'badge' => 'steady traffic'],
                    'workers' => ['status' => 'busy', 'load' => 90],
                    'log' => ['badge' => null],
                ],
                'edges' => [
                    'restart' => ['packets' => ['request' => 1]],
                    'traffic' => ['packets' => ['request' => 3, 'failure' => 1]],
                    'ask' => ['packets' => ['request' => 0]],
                    'reply' => ['packets' => ['request' => 0]],
                    'entry' => ['packets' => ['request' => 0]],
                ],
            ],
            'kpis' => [
                'lostReload' => $lostReload,
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'requests lost during a reload: {kpi:lostReload}'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'logs-restart#numbers',
        ],
        [
            'role' => 'solution',
            'title' => 'The native restart loses none',
            'body' => [
                'On the server\'s own worker mode, one command sends one request to its admin API on localhost and prints how long the swap took. The port stays open, and the requests that arrive meanwhile are answered by the new workers. This was observed, not traced.',
                'Under the same load, the native restart lost {kpi:lostNative} of {num:'.$workerPing.'#probe_requests.inside|count|0} probe requests on the ping and {num:'.$workerSearch.'#failures.inside|count|0} of {num:'.$workerSearch.'#probe_requests.inside|count|0} on the database search.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Use the server\'s own restart for deploys. Octane\'s reload only behind something that drains the instance first.'],
            'state' => [
                'nodes' => [
                    'person' => ['badge' => 'native restart'],
                    'workers' => ['status' => 'ok', 'load' => 50, 'badge' => 'swapped, port open'],
                ],
                'edges' => [
                    'traffic' => ['packets' => ['request' => 3, 'failure' => 0]],
                    'reply' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'lostNative' => $lostNative,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'requests lost during a native restart: {kpi:lostNative}'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'logs-restart#works',
        ],
        [
            'role' => 'careful',
            'title' => 'The price is latency, not errors',
            'body' => [
                'The fresh workers boot the app again and start with cold caches. During a native restart, the slow tail of the database search was {kpi:tailIn}, against {kpi:tailOut} the rest of the time. On the cheap ping, the tail hardly moved.',
                'A request that never gets an answer is missing from the load tool\'s latency figures. Octane\'s tail on the ping looked harmless while the load tool counted {num:'.$octanePing.'#oha_errors_whole_run|count|0} errors over the run. Read the failed requests, not only the latency.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Latency of answered requests hides the ones that never answered. Count the failures apart.'],
            'state' => [
                'nodes' => [
                    'workers' => ['load' => 70, 'badge' => 'cold caches after the swap'],
                    'person' => ['badge' => null],
                ],
                'edges' => [
                    'traffic' => ['packets' => ['request' => 2, 'failure' => 0]],
                ],
            ],
            'kpis' => [
                'tailIn' => $tailIn,
                'tailOut' => $tailOut,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'the slow tail of search: {kpi:tailIn} during a restart, {kpi:tailOut} otherwise'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'logs-restart#numbers',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Give every request an id and put it on every entry, so an id finds the entries of one request, or of a caller that repeats its own id. Take secrets out by key, and keep the JSON channel opt-in, because it costs {kpi:cost} on the cheapest request.',
                'Replace workers with the server\'s own restart: {kpi:lostNative} requests lost, against {kpi:lostReload} on the Octane reload, and a slower search for a moment.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'One id that follows the work, and workers replaced without closing the door.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'workers' => ['status' => 'ok', 'load' => 40, 'badge' => null],
                    'log' => ['badge' => null],
                    'load' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'reply' => ['packets' => ['request' => 1]],
                    'entry' => ['packets' => ['request' => 1]],
                    'restart' => ['packets' => ['request' => 1]],
                    'traffic' => ['packets' => ['request' => 2, 'failure' => 0]],
                ],
            ],
            'kpis' => [
                'cost' => Source::uncompared($cost),
                'lostReload' => Source::uncompared($lostReload),
                'lostNative' => Source::uncompared($lostNative),
                'tailIn' => Source::uncompared($tailIn),
                'tailOut' => Source::uncompared($tailOut),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'native restart: {kpi:lostNative} requests lost'],
                ['sign' => '~', 'text' => 'Octane reload: {kpi:lostReload} requests lost'],
            ],
            'learn' => [],
            'deeper' => 'logs-restart#modify',
        ],
    ],
];
