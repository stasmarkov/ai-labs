<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$run = 'llm/2026-11-13/run.json';
$stop = 'chat-stop/2026-11-03/frankenphp.json';
$model = 'models."qwen3.5:9b"';

$ttft = Source::seconds($run, "{$model}.speed.ttft_ms.median");
$speed = Source::figure($run, "{$model}.speed.generation_tokens_per_s.median", 1);
$length = Source::figure($run, "{$model}.speed.output_tokens.median");
$after = Source::figure($stop, 'median_tokens_after_stop');
$free = Source::figure($stop, 'median_probe_ms_after_stop');

return [
    'title' => 'Describing a car',
    'summary' => 'How one listing becomes a short description that streams word by word, what Stop does to the model call behind it, and what the run row keeps.',
    'minutes' => 10,
    'level' => 'beginner',
    'guide' => 'describe',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Say what the service checks before it opens a stream, and why that comes first.'],
        ['id' => 'lo-2', 'text' => 'Explain how a listing becomes a prompt, and where the wording of the answer is kept.'],
        ['id' => 'lo-3', 'text' => 'Describe what the page receives while the model writes, and what it gets when the provider fails.'],
        ['id' => 'lo-4', 'text' => 'Tell what Stop does to the model call, and how that was measured.'],
        ['id' => 'lo-5', 'text' => 'Read from a run row what one description cost and how it ended.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 300],
            ['id' => 'service', 'kind' => 'app', 'label' => 'Describe service', 'x' => 500, 'y' => 300],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model server', 'x' => 850, 'y' => 300],
            ['id' => 'prompt', 'kind' => 'app', 'label' => 'Prompt file', 'x' => 500, 'y' => 80],
            ['id' => 'log', 'kind' => 'app', 'label' => 'Run row', 'x' => 500, 'y' => 520],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'service'],
            ['id' => 'stream', 'from' => 'service', 'to' => 'browser'],
            ['id' => 'call', 'from' => 'service', 'to' => 'model'],
            ['id' => 'answer', 'from' => 'model', 'to' => 'service'],
            ['id' => 'read', 'from' => 'prompt', 'to' => 'service'],
            ['id' => 'record', 'from' => 'service', 'to' => 'log'],
        ],
    ],
    'kpis' => [
        ['id' => 'ttft', 'label' => 'First token (median)', 'format' => 's', 'better' => 'lower'],
        ['id' => 'speed', 'label' => 'Writing speed', 'format' => 'tokens-per-s', 'better' => 'higher'],
        ['id' => 'length', 'label' => 'Description length (median)', 'format' => 'count', 'unit' => 'tokens', 'better' => 'none'],
        ['id' => 'after', 'label' => 'Pieces sent after Stop (median)', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'free', 'label' => 'Model free again after Stop', 'format' => 'ms', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A blank box',
            'body' => [
                'A visitor opens a listing and asks for a description. The model writes it piece by piece: in the speed test a description came to {kpi:length}, written at about {kpi:speed}.',
                'If the page waits for the whole text, the box stays empty until the end, although the first piece was ready after {kpi:ttft}.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'The wait that hurts is the one with an empty screen.'],
            'state' => [
                'nodes' => [
                    'browser' => ['load' => 5, 'badge' => 'empty until the end'],
                    'service' => ['load' => 10],
                    'model' => ['load' => 40],
                    'prompt' => ['status' => 'hidden'],
                    'log' => ['status' => 'hidden'],
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
                'length' => $length,
                'after' => '—',
                'free' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'nothing is shown until the whole description exists'],
            ],
            'learn' => [],
            'deeper' => 'describe#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Look the car up first',
            'body' => [
                'The request names one listing. The service loads it before it opens any stream, so an unknown id gets a plain JSON error and never a half-open stream.',
                'The provider is resolved before the stream opens too, so a cloud provider without a key fails the same way, as an ordinary error.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Fail before the stream opens. After the first line the status code is gone.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => 'finds the listing first'],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'an unknown id fails as a plain error'],
                ['sign' => '+', 'text' => 'a missing provider key fails the same way'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'describe#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The car becomes five lines',
            'body' => [
                'The agent turns the listing into five short lines: the car, the fuel, the mileage, the price and the city. The model sees facts, not a database row.',
                'The instructions ask for one warm paragraph that uses only those facts and invents no features.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Give the model only stored facts, and say so. Otherwise it may add a feature the car does not have.'],
            'state' => [
                'nodes' => [
                    'prompt' => ['status' => 'ok', 'badge' => 'facts only'],
                    'service' => ['badge' => 'five lines of facts'],
                ],
                'edges' => [
                    'read' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the model gets five short lines of facts'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'describe#works',
        ],
        [
            'role' => 'useful',
            'title' => 'The wording is a file',
            'body' => [
                'The instructions are not in the code. They come from a versioned prompt file, and one setting names the version in use.',
                'To change the wording, copy the file to a new version, edit the copy and point the setting at it. The old file stays, and every run row keeps the label of the version that wrote it.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Never edit a version in place. Old rows would name wording that no longer exists.'],
            'state' => [
                'nodes' => [
                    'prompt' => ['badge' => 'one file per version'],
                    'service' => ['badge' => null],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'wording is data: a new version is a new file'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'describe#modify',
        ],
        [
            'role' => 'solution',
            'title' => 'Words arrive as they are written',
            'body' => [
                'The reply is a stream: one line for every piece of text the model writes, flushed at once. The first words show after {kpi:ttft}, and the text grows at about {kpi:speed}.',
                'The model needs the same time as before. The visitor just stops staring at an empty box.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Streaming moves the first word earlier. It does not make the last word come sooner.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'text grows word by word'],
                    'model' => ['load' => 60],
                ],
                'edges' => [
                    'answer' => ['packets' => ['token' => 5]],
                    'stream' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the first words show after {kpi:ttft}'],
                ['sign' => '~', 'text' => 'the last word arrives when it did before'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'describe#works',
        ],
        [
            'role' => 'careful',
            'title' => 'When the provider fails midway',
            'body' => [
                'A provider can fail halfway through a description. By then the status code has gone, so the page gets one short error event and nothing of the provider\'s own message.',
                'The cause stays in the run row, with the error class and message, so you can open the run and read why.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Tell the stream that it failed, not why. The run page does show the stored message, so keep secrets out of it.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'sees one safe error'],
                    'model' => ['status' => 'down', 'load' => 0],
                    'log' => ['status' => 'ok', 'badge' => 'real cause kept here'],
                ],
                'edges' => [
                    'answer' => ['packets' => ['token' => 0, 'failure' => 2]],
                    'stream' => ['packets' => ['token' => 0, 'failure' => 1]],
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the page gets one safe error event'],
                ['sign' => '~', 'text' => 'the provider\'s message stays in the run row'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'describe#support',
        ],
        [
            'role' => 'problem',
            'title' => 'The visitor presses Stop',
            'body' => [
                'Halfway through the text the visitor presses Stop. The page stops drawing, but that says nothing about the model.',
                'If nothing tells the model, it keeps writing for nobody, and the next visitor waits behind it.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A Stop button that only hides the text leaves the model busy.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'Stop pressed'],
                    'model' => ['status' => 'busy', 'load' => 100, 'badge' => 'still writing'],
                    'log' => ['badge' => null],
                ],
                'edges' => [
                    'answer' => ['packets' => ['token' => 5, 'failure' => 0]],
                    'stream' => ['packets' => ['token' => 0, 'failure' => 0]],
                    'record' => ['packets' => ['request' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'the text stops, the model does not'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'describe#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Stop reaches the model',
            'body' => [
                'After every flush the server checks that the connection is still open. When the visitor has gone, it lets go of the model stream, which closes the call to the model server, and writes a run row marked stopped.',
                'These figures were measured on the docs answer stream, which shares the stop code with the description. On classic FrankenPHP the median number of pieces sent after Stop was {kpi:after}. A one-token request sent right after took {kpi:free}, against {num:'.$stop.'#median_idle_probe_ms|ms|0} when idle.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A closed connection shows itself only on the next write, so check after every one.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => 'sees the close, stops'],
                    'model' => ['status' => 'ok', 'load' => 0, 'badge' => 'call cancelled'],
                    'log' => ['badge' => 'row marked stopped'],
                ],
                'edges' => [
                    'call' => ['packets' => ['request' => 0, 'failure' => 2]],
                    'answer' => ['packets' => ['token' => 0]],
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'after' => $after,
                'free' => $free,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the model is released when the visitor leaves'],
                ['sign' => '~', 'text' => 'pieces sent after Stop, median: {kpi:after}'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'describe#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'What one description costs',
            'body' => [
                'Every call writes one run row: provider, model, prompt version, tokens in and out, time to first token, total time and a status of completed, stopped or failed.',
                'A longer description costs more output tokens and more waiting. The speed test median was {kpi:length}, so cap the length in the prompt and the options, and keep a limit on the route, because each call runs the model.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A row for every ending, even a cut stream, is what makes a slow or failed description explainable.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => null],
                    'model' => ['badge' => null],
                    'log' => ['badge' => 'one row per call'],
                ],
                'edges' => [
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'every call leaves a row with tokens, times and status'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'describe#support',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Look the listing up before the stream opens, build the prompt from stored facts, and keep the wording in a versioned file. Stream the text, and keep provider errors off the page.',
                'Make Stop reach the model, and log every ending in a run row. Together that gives a first word after {kpi:ttft} and a model that is free again after {kpi:free}, as measured on the docs answer stream.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'One short answer touches the service, the prompt, the stream, the model and the log. Check each one on its own.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'model' => ['status' => 'ok', 'load' => 40],
                    'log' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'call' => ['packets' => ['request' => 1]],
                    'answer' => ['packets' => ['token' => 5]],
                    'stream' => ['packets' => ['token' => 5]],
                    'read' => ['packets' => ['request' => 1]],
                    'record' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared($ttft),
                'speed' => Source::uncompared($speed),
                'length' => Source::uncompared($length),
                'after' => Source::uncompared($after),
                'free' => Source::uncompared($free),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'first words after {kpi:ttft}'],
                ['sign' => '+', 'text' => 'the model free again after {kpi:free} on Stop'],
            ],
            'learn' => [],
            'deeper' => 'describe#modify',
        ],
    ],
];
