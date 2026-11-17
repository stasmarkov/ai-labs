<?php

declare(strict_types=1);

return [
    'scenarios' => [
        [
            'id' => 'bad-colour',
            'title' => 'An answer keeps failing',
            'outcomes' => ['LO-2', 'LO-3'],
            'symptom' => "status: failed\nattempts: 3\nerrors.dominant_colours.0: The selected dominant_colours.0 is invalid.",
            'clues' => [
                'Each of the three run rows is completed but marked validation failed, so the model did answer every time.',
                'The schema and the PHP rule share one fixed list of colours.',
                'The model sees the list in the schema, but nothing forces it to stay inside it.',
            ],
            'causes' => [
                ['text' => 'The model server is slow', 'why' => 'A slow or stopped server is a transport error: one failed attempt, not three answers that fail a rule.'],
                ['text' => 'The model answers with a colour outside the fixed list', 'why' => 'Right: the PHP rule rejects it, the answer is asked for again, and after the third invalid answer the photo is marked failed with the last errors.'],
                ['text' => 'The queue worker is not running', 'why' => 'Without a worker the row would still be queued, with no attempts at all.'],
            ],
            'correct' => 1,
            'fix' => 'Try a larger vision model, or make the prompt name the allowed colours and save it as a new prompt version.',
            'test' => '"AC-3 rejects output that breaks a rule in PHP" checks that a colour outside the list, among other cases, fails the PHP rules',
            'snippet' => 'App\Rules\PhotoExtractionRules::rules',
        ],
        [
            'id' => 'server-down',
            'title' => 'Photos fail at once',
            'outcomes' => ['LO-4'],
            'symptom' => "status: failed\nattempts: 1\nerrors.transport.0: Illuminate\\Http\\Client\\ConnectionException\nerrors.transport.1: cURL error 7: Failed to connect to host.docker.internal port 11434",
            'clues' => [
                'Every photo fails after exactly one attempt.',
                'The error is stored under "transport", not under a field name.',
            ],
            'causes' => [
                ['text' => 'The answers fail the PHP rules', 'why' => 'Invalid answers are asked for again, so they use up three attempts and fail under the field names, not after one attempt under "transport".'],
                ['text' => 'The model server is not running', 'why' => 'Right: a connection error is a transport error, which ends the photo after one attempt with no retry.'],
            ],
            'correct' => 1,
            'fix' => 'Start the model server, then run the command again with the fresh option. A failed row counts as done for its model and prompt version, so a plain run skips it; fresh deletes the rows of that model and prompt version, completed ones too, and queues every photo again.',
            'test' => '"AC-4 records a transport error as one failed attempt with no retry" checks that a connection error ends the photo after one attempt',
            'snippet' => 'App\Services\PhotoExtractionService::extract',
        ],
        [
            'id' => 'nothing-runs',
            'title' => 'Photos stay queued',
            'outcomes' => ['LO-1', 'LO-4'],
            'symptom' => "Queued 3 photos on ai-vision for gemma4:12b (photo-vision@v1); 47 skipped.\nCompleted 0, failed 0, still queued 3, running 0.\nRun a worker: ddev artisan queue:work --queue=ai-vision",
            'clues' => [
                'No run rows appear, not even failed ones.',
                'A worker is running, and the other AI features still work.',
                'That worker listens to the default queue only.',
            ],
            'causes' => [
                ['text' => 'No worker listens to the vision queue', 'why' => 'Right: the jobs wait on the ai-vision queue, which a worker on the default queue never reads.'],
                ['text' => 'The schema has a typo', 'why' => 'A schema problem shows up when the model is called, as failed attempts with run rows, not as jobs that never start.'],
            ],
            'correct' => 0,
            'fix' => 'Start a worker for the vision queue, as the command prints: ddev artisan queue:work --queue=ai-vision.',
            'test' => '"NFR-2 queues each job on ai-vision and does not retry in the queue" checks that the job goes to the vision queue',
            'snippet' => 'App\Jobs\ExtractPhotoJob',
        ],
        [
            'id' => 'old-model',
            'title' => 'Nothing is queued for the model you picked',
            'outcomes' => ['LO-5'],
            'symptom' => 'No photos to extract; 50 already have an extraction for qwen3.5:9b (photo-vision@v1).',
            'clues' => [
                'You ran the same command with this model last week, and some of those photos failed.',
                'The command picks only the photos with no extraction row for the same provider, model and prompt version, whatever the status of that row.',
            ],
            'causes' => [
                ['text' => 'Every photo already has an extraction row, completed or failed, for that provider, model and prompt version', 'why' => 'Right: the command skips a photo when any row exists for the same provider, model and prompt version, so a failed row counts as done too.'],
                ['text' => 'The model you passed is ignored and the default model is used', 'why' => 'The output names the model you passed; the provider, model and prompt version of that request are what the rows are matched on.'],
                ['text' => 'The run rows are cached', 'why' => 'Run rows are written on every model call; the command does not read them to decide what to skip.'],
            ],
            'correct' => 0,
            'fix' => 'Run the command again with the fresh option: it deletes the rows of that model and prompt version first, then queues every photo. A new prompt version also starts from zero.',
            'test' => '"AC-3 --fresh redoes only the rows of the model and prompt version" checks that a plain run skips a photo that has a row and that fresh redoes only the rows of that model and prompt version',
            'snippet' => 'App\Repositories\EloquentPhotoExtractionRepository::pendingPhotoIds',
        ],
    ],
];
