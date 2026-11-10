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
            'note' => 'Feature tests read the stream line by line, and a bench checks flushing on every web server.',
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
];
