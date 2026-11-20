<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'Streaming',
    'summary' => 'How text reaches the page piece by piece while the model is still writing it, and how Stop works.',
    'works' => [
        [
            'title' => 'The response is a stream of lines',
            'body' => 'The server answers with `text/event-stream` and writes one `data:` line per event. Headers turn off proxy buffering.',
            'snippet' => 'App\Ai\SseProtocol::headers',
        ],
        [
            'title' => 'Every event is flushed',
            'body' => 'After each line the server flushes its output, so the page sees the text grow instead of arriving at the end.',
            'event' => 'text_delta',
            'snippet' => 'App\Ai\SseProtocol::response',
        ],
        [
            'title' => 'Errors are masked',
            'body' => 'A provider error never reaches the page as is. The stream sends one generic `error` event and the real cause goes to the run row.',
            'event' => 'error',
            'snippet' => 'App\Ai\SseProtocol::maskedErrorParts',
        ],
        [
            'title' => 'The stream ends with a marker',
            'body' => 'The last line is `[DONE]`, so the page knows the stream ended on purpose and not by a dropped connection.',
            'event' => 'stream_end',
            'snippet' => 'App\Ai\SseProtocol::terminator',
        ],
    ],
    'failures' => [
        [
            'title' => 'The text arrives all at once',
            'body' => "**Cause:** a proxy or server holds bytes back until a buffer fills.\n\n**Fix:** check the no-buffering header is present and test with `curl --no-buffer`.",
            'snippet' => 'App\Ai\SseProtocol::headers',
        ],
        [
            'title' => 'Stop hides the text but the model keeps working',
            'body' => "**Cause:** a closed connection is only noticed on the next write.\n\n**Fix:** the stream checks the connection after every flush and records a `stopped` row.",
            'snippet' => 'App\Ai\SseProtocol::recordAbort',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Check that streaming works on your server',
            'body' => 'Request the stream with `curl --no-buffer` and watch the lines arrive one by one. If they come in a burst, look at the buffering of the proxy in front.',
        ],
        [
            'title' => 'Stream on each of the three FrankenPHP modes',
            'body' => 'The lab runs classic FrankenPHP (the default, with Mercure), worker mode and Octane. Switch with `ddev server frankenphp-worker`, `ddev server frankenphp-octane` or `ddev server default`, then repeat the curl check. Streaming was measured earlier on PHP-FPM; the three modes are what runs now.',
        ],
        [
            'title' => 'Test with a slow fake provider',
            'body' => 'Set the provider to the slow fake entry and the delay per piece to a visible value. No real model is needed.',
            'snippet' => 'App\Ai\FakeStreamProvider',
        ],
    ],
    'support' => [
        [
            'name' => 'Stream outcome',
            'status' => 'logged',
            'note' => 'Completed, failed and stopped streams each write one run row.',
        ],
        [
            'name' => 'Streaming tests',
            'status' => 'guarded',
            'note' => 'Feature tests read the stream line by line, and a bench checks flushing on each of the three FrankenPHP modes.',
        ],
        [
            'name' => 'A stream hangs',
            'status' => 'runbook',
            'note' => "1. Open the latest run and read its status.\n2. No row: the call never reached the model; check the rate limit and the web server.\n3. Run the stream bench against the server to see whether buffering is back.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Deltas still sent after Stop (median of 5)',
            'unit' => 'deltas',
            'file' => 'chat-stop/2026-11-03/frankenphp.json',
            'path' => 'median_tokens_after_stop',
        ],
    ],
    'experiments' => [
        [
            'title' => 'Watch the stream arrive',
            'predictions' => [
                ['id' => 'a', 'text' => 'The whole text appears at once when the model finishes.'],
                ['id' => 'b', 'text' => 'The first words show up long before the last ones, one text event per piece.'],
                ['id' => 'c', 'text' => 'The text arrives in exactly three equal pieces.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'The first words show up long before the last ones, and the log lists one text event per piece of text.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['run-log', 'chat'],
    'lessons' => ['running-models/streaming-an-answer'],
    'build' => [
        ['step' => 'Stream a line per event', 'detail' => 'Send newline-delimited JSON events, each with a type and a payload, and flush after each one.', 'tab' => 'recipes', 'doc' => 'https://laravel.com/docs/responses#streamed-responses'],
        ['step' => 'Disable buffering on the path', 'detail' => 'Turn off output buffering and proxy buffering, and test on the server you deploy to.', 'tab' => 'recipes', 'doc' => 'https://frankenphp.dev/docs/'],
        ['step' => 'Read the stream in the browser', 'detail' => 'Use the Fetch API reader, split by lines and append text as events arrive.', 'tab' => 'recipes'],
        ['step' => 'Mask errors', 'detail' => 'Send a safe error event and keep details in the log.', 'tab' => 'support'],
        ['step' => 'End with a marker', 'detail' => 'Finish every stream with a done event, so the client can tell a clean end from a cut.', 'tab' => 'support'],
    ],
    'watch' => [
        ['point' => 'Buffering', 'detail' => 'Any layer that buffers the response turns a stream into a block at the end.', 'tab' => 'failures'],
        ['point' => 'Stop must reach the model', 'detail' => 'Closing the page does not always cancel the upstream call. Check that it does.', 'tab' => 'failures'],
        ['point' => 'Workers are held', 'detail' => 'A streamed answer occupies a thread for its whole length.', 'tab' => 'support'],
        ['point' => 'Errors mid-stream', 'detail' => 'Headers are already sent, so an error needs an event, not a status code.', 'tab' => 'failures'],
    ],
    'elsewhere' => [
        ['domain' => 'Chat products', 'problem' => 'Users wait for a long answer.', 'how' => 'Stream tokens as they arrive so the first words show in under a second.'],
        ['domain' => 'Reports', 'problem' => 'A long export should show progress.', 'how' => 'Stream progress events and a final link.'],
        ['domain' => 'Live logs', 'problem' => 'Operators watch a job run.', 'how' => 'Stream log lines from the job as events.'],
    ],
];
