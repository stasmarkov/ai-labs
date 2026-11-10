<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'The run log',
    'summary' => 'Every AI call leaves one row that says what ran, how long it took and how it ended.',
    'works' => [
        [
            'title' => 'The run starts',
            'body' => 'When a call begins, the recorder notes the agent, provider, model and prompt version, and starts a clock.',
            'snippet' => 'App\Ai\AiRunRecorder::started',
        ],
        [
            'title' => 'The first token is timed',
            'body' => 'The first piece of text stops the first clock. This is the time a reader waits before anything appears.',
            'event' => 'text_delta',
            'snippet' => 'App\Ai\AiRunRecorder::firstToken',
        ],
        [
            'title' => 'The run ends one of three ways',
            'body' => 'It completes with token counts, it fails with the error class and message, or the reader stops it. Each writes exactly one row.',
            'event' => 'stream_end',
            'snippet' => 'App\Ai\AiRunRecorder::completed',
        ],
        [
            'title' => 'A failure keeps its reason',
            'body' => 'A failed row stores the error class and a trimmed message, so the cause can be read without the server log.',
            'event' => 'error',
            'snippet' => 'App\Ai\AiRunRecorder::failed',
        ],
    ],
    'failures' => [
        [
            'title' => 'A run has no row',
            'body' => "**Cause:** the request never reached the model, for example a rejected input or the rate limit.\n\n**Fix:** read the response status first; only calls that reach the model are logged.",
        ],
        [
            'title' => 'A stopped run shows no tokens',
            'body' => "**Cause:** the reader left before the model reported its usage.\n\n**Fix:** none needed; a stopped row records the pieces sent so far.",
            'snippet' => 'App\Ai\AiRunRecorder::stopped',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Find the slowest runs',
            'body' => 'Open the run list and sort by total time. Compare their model and prompt label before you blame the code.',
        ],
        [
            'title' => 'Compare two models',
            'body' => 'Run the same input on each model and read the model, first token and total time columns of the two rows.',
            'snippet' => 'config/ai.php#provider-ollama',
        ],
    ],
    'support' => [
        [
            'name' => 'Run row',
            'status' => 'logged',
            'note' => 'The row is the log: agent, provider, model, prompt version, tokens, timings and status.',
        ],
        [
            'name' => 'Recorder tests',
            'status' => 'guarded',
            'note' => 'Tests cover the completed, failed and stopped rows and the masked error text.',
        ],
        [
            'name' => 'The list of runs is empty',
            'status' => 'runbook',
            'note' => "1. Check that the database is the one the app writes to.\n2. Make one call and reload.\n3. If still empty, read the application log for a recorder error.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Rows written per call',
            'pending' => true,
        ],
    ],
    'experiments' => [
        [
            'title' => 'Make a run and open its row',
            'predictions' => [
                ['id' => 'a', 'text' => 'No row appears until the run is read back.'],
                ['id' => 'b', 'text' => 'A row appears with status started and no timings.'],
                ['id' => 'c', 'text' => 'One new row appears with status completed, a first-token time and token counts.'],
            ],
            'answer' => ['id' => 'c', 'body' => 'One new row appears with the status completed, a first-token time and token counts.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['providers', 'streaming'],
];
