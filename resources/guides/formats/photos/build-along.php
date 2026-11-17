<?php

declare(strict_types=1);

return [
    'steps' => [
        [
            'id' => 'command',
            'title' => 'Start from one command',
            'outcomes' => ['LO-1'],
            'goal' => 'Queue one extraction job for each photo that has no answer yet.',
            'snippet' => 'App\Console\Commands\ExtractPhotosCommand::handle',
            'command' => 'ddev artisan photos:extract --limit=3',
            'expected' => 'The command prints "Queued 3 photos on ai-vision for gemma4:12b (photo-vision@v1)", how many it skipped, the status counts and the worker command to run.',
        ],
        [
            'id' => 'queue',
            'title' => 'Give the work its own queue',
            'outcomes' => ['LO-4'],
            'goal' => 'Run every photo on a queue of its own, so slow image calls never hold up the other features.',
            'snippet' => 'App\Jobs\ExtractPhotoJob',
            'command' => 'ddev artisan queue:work --queue=ai-vision',
            'expected' => 'The worker picks up the three jobs one by one. A stopped model server fails a photo after one attempt.',
        ],
        [
            'id' => 'schema',
            'title' => 'Ask for a schema, not prose',
            'outcomes' => ['LO-2'],
            'goal' => 'Describe the fields you want: caption, tags, colours, body type, damage and people count.',
            'snippet' => 'App\Ai\PhotoVisionAgent::schema',
            'command' => 'ddev artisan test --compact --filter="declares every key of the extraction"',
            'expected' => 'One test passes: every field is required, with its type, its allowed values and its limits.',
        ],
        [
            'id' => 'php-rules',
            'title' => 'Check the answer again in PHP',
            'outcomes' => ['LO-2', 'LO-3'],
            'goal' => 'Enforce the same limits in PHP, because the schema is a request and not a promise.',
            'snippet' => 'App\Rules\PhotoExtractionRules::rules',
            'command' => 'ddev artisan test --compact --filter="rejects output that breaks a rule in PHP"',
            'expected' => 'Every case passes: an answer with a colour, a body type or a count outside the limits is rejected by the PHP rules.',
        ],
        [
            'id' => 'attempts',
            'title' => 'Retry up to three times and log each try',
            'outcomes' => ['LO-1', 'LO-3'],
            'goal' => 'Ask again when the answer fails the rules, with one run row per attempt, and mark the photo failed after the third.',
            'snippet' => 'App\Services\PhotoExtractionService::extract',
            'command' => 'ddev artisan test --compact --filter="after three invalid answers"',
            'expected' => 'One test passes: after three invalid answers the photo is stored as failed with the last errors and three run rows.',
        ],
        [
            'id' => 'model',
            'title' => 'Change the model and confirm it',
            'outcomes' => ['LO-5'],
            'goal' => 'Pick another vision model installed on your model server for one run, then read the model name back from the run rows.',
            'snippet' => 'App\Ai\PhotoVisionAgent::modelName',
            'command' => 'ddev artisan photos:extract --limit=3 --fresh --model=qwen3.5:9b',
            'expected' => 'The command queues the photos for qwen3.5:9b, and once a worker runs them their run rows name that model. The answers of the default model stay as they were.',
        ],
    ],
];
