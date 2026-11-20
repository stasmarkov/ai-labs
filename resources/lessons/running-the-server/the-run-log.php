<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$run = 'llm/2026-11-13/run.json';
$stop = 'chat-stop/2026-11-03/frankenphp.json';
$model = 'models."qwen3.5:9b"';

$ttft = Source::figure($run, "{$model}.speed.ttft_ms.median");
$tokens = Source::figure($run, "{$model}.speed.output_tokens.median");
$fail = Source::figure($run, "{$model}.failover.unreachable.describe.time_to_fail_ms");
$rows = Source::figure($stop, 'stopped_rows');
$stops = Source::figure($stop, 'metadata.runs');

return [
    'title' => 'The run log',
    'summary' => 'How every model call leaves one row that says what ran, how long it took and how it ended, and what that row can and cannot tell you.',
    'minutes' => 9,
    'level' => 'beginner',
    'guide' => 'run-log',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Say what one run row holds and when it is written.'],
        ['id' => 'lo-2', 'text' => 'Name the ways a run can end and what each kind of row keeps.'],
        ['id' => 'lo-3', 'text' => 'Explain why the page shows a short error while the row keeps the cause.'],
        ['id' => 'lo-4', 'text' => 'Find a run in the lab and read its times and status.'],
        ['id' => 'lo-5', 'text' => 'Name what the log cannot tell you, and what to watch as it grows.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 200],
            ['id' => 'service', 'kind' => 'app', 'label' => 'A model call', 'x' => 500, 'y' => 200],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model server', 'x' => 850, 'y' => 200],
            ['id' => 'log', 'kind' => 'app', 'label' => 'Run row', 'x' => 500, 'y' => 450],
            ['id' => 'reader', 'kind' => 'person', 'label' => 'Developer', 'x' => 850, 'y' => 450],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'service'],
            ['id' => 'call', 'from' => 'service', 'to' => 'model'],
            ['id' => 'answer', 'from' => 'model', 'to' => 'service'],
            ['id' => 'record', 'from' => 'service', 'to' => 'log'],
            ['id' => 'read', 'from' => 'reader', 'to' => 'log'],
        ],
    ],
    'kpis' => [
        ['id' => 'ttft', 'label' => 'First token (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'tokens', 'label' => 'Description length (median)', 'format' => 'count', 'unit' => 'tokens', 'better' => 'none'],
        ['id' => 'fail', 'label' => 'Time to fail, server unreachable', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'rows', 'label' => 'Stopped runs that left a row', 'format' => 'count', 'better' => 'higher'],
        ['id' => 'stops', 'label' => 'Stops pressed in the test', 'format' => 'count', 'better' => 'none'],
        ['id' => 'growth', 'label' => 'Rows added per day', 'format' => 'text', 'better' => 'none'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A call fails and nobody knows why',
            'body' => [
                'A visitor asks for a description and gets a short error. Was it the model, a missing key, a network problem or a slow server? The page cannot say, and by the time you look, the moment has passed.',
                'Without a record you can only guess, and a guess about a model is easy to get wrong.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A failure that leaves no trace cannot be explained, only repeated.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'sees a short error'],
                    'service' => ['load' => 20],
                    'model' => ['status' => 'down', 'load' => 0],
                    'log' => ['status' => 'hidden'],
                    'reader' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1]],
                    'answer' => ['packets' => ['failure' => 1]],
                ],
            ],
            'kpis' => [
                'ttft' => '—',
                'tokens' => '—',
                'fail' => '—',
                'rows' => '—',
                'stops' => '—',
                'growth' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the cause of a failure is nowhere to be read'],
            ],
            'learn' => [],
            'deeper' => 'run-log#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The run starts a clock',
            'body' => [
                'When a call begins, the recorder notes the agent, the provider, the model and the prompt version, and starts a clock. The run gets an invocation id, which is how you find it again.',
                'Nothing is written yet. The row is saved once, when the run ends, so a row is never left half filled.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Note who ran what at the start. At the end you may no longer know.'],
            'state' => [
                'nodes' => [
                    'model' => ['status' => 'ok', 'load' => 40],
                    'service' => ['badge' => 'notes agent, model, prompt'],
                    'log' => ['status' => 'ok', 'badge' => 'written at the end'],
                ],
                'edges' => [
                    'answer' => ['packets' => ['failure' => 0, 'token' => 2]],
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'every call starts with a note of what is running'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'run-log#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Two clocks, one row',
            'body' => [
                'The first piece of text stops the first clock: that is what a reader waits before anything appears. The end of the run stops the second one: the whole time.',
                'In the speed test the first token came after {kpi:ttft}, and a description came to {kpi:tokens}. They answer different questions, so the row keeps both.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Time to first token is how it feels. Total time is what it costs.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => 'two clocks'],
                    'log' => ['badge' => 'first token, total time, tokens'],
                ],
                'edges' => [
                    'answer' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [
                'ttft' => $ttft,
                'tokens' => $tokens,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'first token after {kpi:ttft}, kept as its own column'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'run-log#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Every run ends in a row',
            'body' => [
                'A run ends one of three ways. It completes, and the row holds the tokens in and out. It fails, and the row holds the error class and message. Or the reader stops it, and the row says stopped.',
                'Calls that never reach the model are logged too: a refused question, a question with no matching source, and a cached answer each get a row with their own status.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A status for every ending means no run is left open and none is missing.'],
            'state' => [
                'nodes' => [
                    'log' => ['badge' => 'completed, failed, stopped'],
                ],
                'edges' => [
                    'record' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the status says how the run ended'],
                ['sign' => '+', 'text' => 'refused, no-context and cached calls get a row too'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'run-log#works',
        ],
        [
            'role' => 'careful',
            'title' => 'The stream says little, the row keeps the cause',
            'body' => [
                'When the model server is down, the stream sends one fixed error, none of the provider\'s words. In the failover check the call failed after {kpi:fail} and a failed row was written.',
                'The row keeps the error class and the trimmed message. Only configured provider keys of eight or more characters and keys starting sk-, AIza or org- are masked; a bearer token, another vendor\'s key or a password in an address stays.',
                'The run page serves the whole row as JSON to anyone with its invocation id, so the reason does reach a browser there.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'The stream says only that it failed. The run page shows why, so keep secrets out of error messages instead of trusting the masking.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'one safe error'],
                    'model' => ['status' => 'down', 'load' => 0],
                    'log' => ['badge' => 'class and message kept'],
                ],
                'edges' => [
                    'answer' => ['packets' => ['token' => 0, 'failure' => 2]],
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'fail' => $fail,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a failed run is written after {kpi:fail}'],
                ['sign' => '~', 'text' => 'the cause is in the row and on the run page, not in the stream'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'run-log#support',
        ],
        [
            'role' => 'solution',
            'title' => 'A stopped run leaves a row too',
            'body' => [
                'When the reader presses Stop, the stream is abandoned and the model never reports its usage. The service still writes the row, marked stopped, with the pieces sent so far.',
                'In the Stop test the reader stopped {kpi:stops} runs, and {kpi:rows} rows were marked stopped. A stopped row has no input tokens, because the model reports them only at the end.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Missing tokens on a stopped row are expected. They are not a bug in the log.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'Stop pressed'],
                    'model' => ['status' => 'ok', 'load' => 0, 'badge' => 'call cancelled'],
                    'log' => ['badge' => 'status stopped'],
                ],
                'edges' => [
                    'answer' => ['packets' => ['token' => 0, 'failure' => 0]],
                    'call' => ['packets' => ['request' => 0, 'failure' => 2]],
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'rows' => $rows,
                'stops' => $stops,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'every Stop left a row: {kpi:rows} of {kpi:stops}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'run-log#support',
        ],
        [
            'role' => 'useful',
            'title' => 'Open the run in the lab',
            'body' => [
                'The lab overview lists the newest runs with agent, provider, model, tokens in and out, first token, total time and status. A failed one shows in red.',
                'To find the slow ones, read down the total time column, then compare their model before you blame the code. One run is also served by its invocation id as the whole row in JSON, with the error message of a failed one.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Compare the model first. A slow run is often a different model, not a bug.'],
            'state' => [
                'nodes' => [
                    'reader' => ['status' => 'ok', 'badge' => 'reads the list'],
                    'log' => ['badge' => 'newest runs first'],
                    'browser' => ['badge' => null],
                    'model' => ['badge' => null],
                ],
                'edges' => [
                    'read' => ['packets' => ['request' => 2]],
                    'record' => ['packets' => ['request' => 0]],
                    'call' => ['packets' => ['failure' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'one run can be opened and read in full'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'run-log#modify',
        ],
        [
            'role' => 'careful',
            'title' => 'The table only grows',
            'body' => [
                'Each call adds a row, and no job or command in the app removes old ones. How many rows a day the lab adds has not been measured, so there is no figure to quote: it is not measured.',
                'A run without a row also means something. A request stopped before the call, for example by a failed validation or the rate limit, writes no row, so read the response status first.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Plan to archive or prune old rows and keep an index on what you filter by, before the table is large.'],
            'state' => [
                'nodes' => [
                    'log' => ['badge' => 'grows with every call'],
                    'reader' => ['badge' => 'checks the response first'],
                ],
                'edges' => [
                    'read' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'growth' => 'not measured',
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'rows added per day: not measured'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'run-log#support',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Note who ran what at the start, time the first token and the whole run, and end every run in a row with a status. Keep the cause of a failure in the row and a short message on the page.',
                'Read the list before you blame the code, and remember what the log leaves out: requests stopped before any call, and how fast the table grows.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A log is useful when every ending writes a row, and honest when you know what it leaves out.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'model' => ['status' => 'ok', 'load' => 40],
                    'log' => ['badge' => null],
                    'reader' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1, 'failure' => 0]],
                    'answer' => ['packets' => ['token' => 5]],
                    'record' => ['packets' => ['request' => 1]],
                    'read' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared($ttft),
                'tokens' => Source::uncompared($tokens),
                'fail' => Source::uncompared($fail),
                'rows' => Source::uncompared($rows),
                'stops' => Source::uncompared($stops),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a failed run is written after {kpi:fail}'],
                ['sign' => '+', 'text' => 'every Stop left a row: {kpi:rows} of {kpi:stops}'],
            ],
            'learn' => [],
            'deeper' => 'run-log#works',
        ],
    ],
];
