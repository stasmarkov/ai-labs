<?php

declare(strict_types=1);

return [
    'stops' => [
        [
            'id' => 'command',
            'title' => 'The command queues the work',
            'outcomes' => ['LO-1', 'LO-5'],
            'snippet' => 'App\Console\Commands\ExtractPhotosCommand::handle',
            'notes' => [
                ['from' => 3, 'to' => 9, 'text' => 'Reads the limit and refuses anything but a positive whole number.'],
                ['from' => 21, 'to' => 27, 'text' => 'The model is the one you pass, or the configured one. On Ollama a model without vision is refused.'],
                ['from' => 29, 'to' => 31, 'text' => 'The request is the provider, the model and the prompt version. Only photos with no row for those three are pending; fresh deletes those rows first.'],
                ['from' => 41, 'to' => 47, 'text' => 'One queued row and one job per photo.'],
                ['from' => 52, 'to' => 57, 'text' => 'The summary names the queue, the model and the prompt version, and prints the worker command while jobs wait.'],
            ],
        ],
        [
            'id' => 'job',
            'title' => 'The job on its own queue',
            'outcomes' => ['LO-4'],
            'snippet' => 'App\Jobs\ExtractPhotoJob',
            'notes' => [
                ['from' => 5, 'to' => 5, 'text' => 'The queue of its own, so slow image calls never hold up the other features.'],
                ['from' => 10, 'to' => 10, 'text' => 'One try: the job never retries by itself, the attempts happen inside it.'],
                ['from' => 15, 'to' => 18, 'text' => 'The constructor puts every job on that queue.'],
                ['from' => 20, 'to' => 23, 'text' => 'The job only hands the row to the extraction service.'],
                ['from' => 25, 'to' => 28, 'text' => 'A job that dies (a timeout or a killed worker) marks its row failed instead of leaving it running.'],
            ],
        ],
        [
            'id' => 'agent',
            'title' => 'The agent sends the photo',
            'outcomes' => ['LO-1', 'LO-5'],
            'snippet' => 'App\Ai\PhotoVisionAgent::describe',
            'notes' => [
                ['from' => 4, 'to' => 4, 'text' => 'Takes a path on the storage disk from the row, never request input.'],
                ['from' => 7, 'to' => 7, 'text' => 'Sends the short prompt with the stored photo attached; the instructions and the schema go with the call.'],
            ],
        ],
        [
            'id' => 'schema',
            'title' => 'The schema',
            'outcomes' => ['LO-2'],
            'snippet' => 'App\Ai\PhotoVisionAgent::schema',
            'notes' => [
                ['from' => 7, 'to' => 7, 'text' => 'Each field is one line with its type and limits. A new field starts here.'],
                ['from' => 13, 'to' => 17, 'text' => 'The colours come from the same list the PHP rules use, one to three of them.'],
                ['from' => 18, 'to' => 20, 'text' => 'Body type and damage are fixed lists; the people count is a bounded whole number.'],
            ],
        ],
        [
            'id' => 'rules',
            'title' => 'The PHP rules',
            'outcomes' => ['LO-2', 'LO-3'],
            'snippet' => 'App\Rules\PhotoExtractionRules::rules',
            'notes' => [
                ['from' => 7, 'to' => 9, 'text' => 'The same limits again, because the schema is a request, not a promise. A new field needs its rule here too.'],
                ['from' => 10, 'to' => 11, 'text' => 'A colour outside the list fails here, and the answer is asked for again.'],
            ],
        ],
        [
            'id' => 'extract',
            'title' => 'Attempts and the stored row',
            'outcomes' => ['LO-3', 'LO-4', 'LO-5'],
            'snippet' => 'App\Services\PhotoExtractionService::extract',
            'notes' => [
                ['from' => 13, 'to' => 21, 'text' => 'The agent uses the model the row was queued for; if the provider changed since, the row fails and asks to be queued again.'],
                ['from' => 29, 'to' => 34, 'text' => 'Up to three attempts, one model call each.'],
                ['from' => 43, 'to' => 64, 'text' => 'A failed call leaves a failed run row. An empty answer is retried; any other error is a transport error and ends the photo after one attempt.'],
                ['from' => 66, 'to' => 81, 'text' => 'The answer is checked with the PHP rules, and every attempt writes a run row with the model, the prompt version and whether it failed the rules.'],
                ['from' => 83, 'to' => 88, 'text' => 'A valid answer is stored with the number of attempts and the run ids.'],
                ['from' => 95, 'to' => 95, 'text' => 'After the third invalid answer the photo is stored as failed with the last errors.'],
            ],
        ],
    ],
];
