<?php

declare(strict_types=1);

return [
    'stages' => [
        [
            'id' => 'command',
            'title' => 'The command',
            'outcomes' => ['LO-1'],
            'what' => 'The command finds the photos that have no extraction yet for the provider, model and prompt version, creates one queued row per photo and pushes one job for each.',
            'sample' => "Queued 3 photos on ai-vision for gemma4:12b (photo-vision@v1); 47 skipped.\nCompleted 0, failed 0, still queued 3, running 0.\nRun a worker: ddev artisan queue:work --queue=ai-vision",
            'snippet' => 'App\Console\Commands\ExtractPhotosCommand::handle',
        ],
        [
            'id' => 'queue',
            'title' => 'The vision queue',
            'outcomes' => ['LO-4'],
            'what' => 'The service stores the row as queued and dispatches the job. The job names a queue of its own, so slow image calls never hold up the other AI features.',
            'sample' => "photo_extractions row: status queued, attempts 0\njob: ExtractPhotoJob on queue ai-vision",
            'snippet' => 'App\Services\PhotoExtractionService::queue',
        ],
        [
            'id' => 'job',
            'title' => 'The job',
            'outcomes' => ['LO-3', 'LO-4'],
            'what' => 'A worker on the vision queue picks the job up and hands the row to the extraction service. The job has one try: every attempt happens inside this one run, and a job that dies marks its row failed.',
            'sample' => "queue: ai-vision\ntries: 1\ntimeout: 420 s",
            'snippet' => 'App\Jobs\ExtractPhotoJob',
        ],
        [
            'id' => 'agent',
            'title' => 'The agent',
            'outcomes' => ['LO-1', 'LO-5'],
            'what' => 'The agent attaches the stored photo to the prompt and sends it to the vision model the row was queued for. The prompt version comes from the prompt file name.',
            'sample' => "agent: PhotoVisionAgent\nmodel: gemma4:12b\nprompt version: photo-vision@v1",
            'snippet' => 'App\Ai\PhotoVisionAgent::describe',
        ],
        [
            'id' => 'model-server',
            'title' => 'The model server',
            'outcomes' => ['LO-5'],
            'what' => 'The call goes to the provider chosen for photo reading, local or cloud. The model is the one passed to the command, or the configured one.',
            'sample' => "provider: ollama\nmodel: gemma4:12b",
            'snippet' => 'App\Ai\PhotoVisionAgent::model',
        ],
        [
            'id' => 'schema',
            'title' => 'The schema',
            'outcomes' => ['LO-2'],
            'what' => 'The reply must fit a schema: caption, tags, one to three colours, body type, damage and people count.',
            'sample' => "{\n  \"caption\": \"A red hatchback parked by a hedge\",\n  \"tags\": [\"alloy wheels\", \"hedge\"],\n  \"dominant_colours\": [\"red\"],\n  \"body_type\": \"hatchback\",\n  \"visible_damage\": \"none\",\n  \"people_count\": 0\n}",
            'snippet' => 'App\Ai\PhotoVisionAgent::schema',
        ],
        [
            'id' => 'php-rules',
            'title' => 'The PHP rules',
            'outcomes' => ['LO-2', 'LO-3'],
            'what' => 'The same limits are checked again in PHP. A failing answer is asked for again, up to three attempts, each with its own run row.',
            'sample' => "attempt 1: dominant_colours.0: The selected dominant_colours.0 is invalid.\nattempt 2: valid",
            'snippet' => 'App\Rules\PhotoExtractionRules::rules',
        ],
        [
            'id' => 'row',
            'title' => 'The stored row',
            'outcomes' => ['LO-1', 'LO-3', 'LO-5'],
            'what' => 'The answer is stored with the number of attempts and the time it took, and every attempt left a run row naming the model and the prompt version.',
            'sample' => "photo_extractions: status completed, attempts 2\nai_runs: PhotoVisionAgent, ollama, gemma4:12b, photo-vision@v1, status completed, validation_failed true\nai_runs: PhotoVisionAgent, ollama, gemma4:12b, photo-vision@v1, status completed, validation_failed false",
            'snippet' => 'App\Services\PhotoExtractionService::extract',
        ],
    ],
];
