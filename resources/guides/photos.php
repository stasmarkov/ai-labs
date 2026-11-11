<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Read a car photo',
    'summary' => 'Send a stored listing photo to a vision model and get a checked caption, tags, colours, body type, damage and people count back.',
    'works' => [
        [
            'title' => 'Photos come from a public dataset',
            'body' => 'A download script fetches the Stanford Cars photos after you accept their terms. The import keeps only the classes whose make and model are in the catalogue and gives each photo to the listing of that model with the nearest year, one photo per listing.',
            'snippet' => 'App\Services\PhotoMatcher::nearest',
        ],
        [
            'title' => 'A photo is stored small',
            'body' => 'Each photo is cut to at most 1024 pixels on the long side and saved as a JPEG. That bounds the tokens and the time of every model call. The same file is never imported twice, because its hash is unique.',
        ],
        [
            'title' => 'One photo travels with the instructions',
            'body' => 'The agent attaches the stored file to a versioned prompt and asks for a fixed set of fields. The path always comes from the database, never from the request.',
            'event' => 'request_sent',
            'snippet' => 'App\Ai\PhotoVisionAgent::describe',
        ],
        [
            'title' => 'The model answers in a schema',
            'body' => 'The reply must be a caption, tags, up to three colours from a fixed list, a body type, a damage level and a people count. The answer is read as structured output, not as free text.',
            'event' => 'response_received',
        ],
        [
            'title' => 'The answer is checked again',
            'body' => 'The schema is a request, not a promise. The same limits are enforced in PHP, and an answer that fails them is asked for again, up to three times, with one run row per attempt.',
            'event' => 'validation_failed',
            'snippet' => 'App\Rules\PhotoExtractionRules::rules',
        ],
        [
            'title' => 'The server may not answer',
            'body' => 'If the model server is down or the call times out, the photo is marked failed after one attempt with no retry, so a stopped server cannot hide behind repeated tries.',
            'event' => 'request_failed',
        ],
        [
            'title' => 'The work runs on its own queue',
            'body' => 'The command queues one job per photo on a queue of its own (`ai-vision`), worked by one process you start with `ddev artisan queue:work --queue=ai-vision`, so slow image calls never hold up the other AI features. The result is stored with the attempts and the time it took.',
            'snippet' => 'App\Services\PhotoExtractionService::extract',
        ],
    ],
    'failures' => [
        [
            'title' => 'The extraction command refuses the model',
            'body' => "**Cause:** the model is not installed on the local server, or it cannot read images.\n\n**Fix:** pull a vision model, or pick one from the list the command prints.",
        ],
        [
            'title' => 'An answer keeps failing the checks',
            'body' => "**Cause:** the model returns a colour or a body type that is not in the fixed lists, or a caption that is too long. After three attempts the row is marked failed with the reasons.\n\n**Fix:** try a larger model, or tighten the prompt and raise its version so old and new rows stay apart.",
        ],
        [
            'title' => 'The call times out or the server is down',
            'body' => "**Cause:** the model server is not running, or the first call is loading the model.\n\n**Fix:** start the server and queue the photo again. A transport error is one attempt with no retry, so it cannot hide a down server.",
        ],
        [
            'title' => 'Few photos are imported',
            'body' => "**Cause:** most dataset classes are makes the catalogue does not sell, and each listing takes one photo.\n\n**Fix:** raise the limit or the per-model cap, or add listings for more models.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Change the vision model',
            'body' => "Set `AI_PHOTO_VISION_MODEL`, or pass a model to the extraction command for one run. The provider is set with `AI_PHOTO_VISION_PROVIDER`.\n\n**Check:** the next run row names the new model.",
            'snippet' => 'App\Ai\PhotoVisionAgent::modelName',
        ],
        [
            'title' => 'Add a field to the schema',
            'body' => "Add the field to the agent schema, add its limit to `PhotoExtractionRules` so PHP checks it too, then save the prompt under a new prompt version and set `AI_PROMPT_PHOTO_VISION`. Old extractions keep their version.\n\n**Check:** a new extraction stores the field, and an answer without it is asked for again.",
            'snippet' => 'App\Ai\PhotoVisionAgent::schema',
        ],
        [
            'title' => 'Change what is asked for',
            'body' => "Edit the prompt file, save it under a new version name and set `AI_PROMPT_PHOTO_VISION`. Extractions are kept per model and prompt version, so the old answers stay.\n\n**Check:** the run rows show the new prompt version.",
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows and extraction status',
            'status' => 'logged',
            'note' => 'Every model call writes an `ai_runs` row with the model, prompt version, tokens, time and whether validation failed. The extraction row keeps the attempts and the final errors.',
        ],
        [
            'name' => 'Rules, size cap and queue',
            'status' => 'guarded',
            'note' => 'The PHP rules reject any answer outside the schema, the photo is at most 1024 pixels, and the work uses its own queue. The tests run offline with a faked agent.',
        ],
        [
            'name' => 'Extractions look wrong',
            'status' => 'runbook',
            'note' => "1. Open the photo in the lab and read the stored answer and its attempts.\n2. Check the run rows for failed or invalid attempts.\n3. Run the eval command and compare body type agreement with the last result file.\n4. If one model is worse, switch the model before changing the prompt.",
        ],
    ],
    'numbers' => [
        ['label' => 'gemma4:12b, valid on the first attempt', 'file' => 'photo-vision/2026-11-11/run.json', 'path' => 'models.0.valid_first_attempt.rate'],
        ['label' => 'qwen3.5:9b, valid on the first attempt', 'file' => 'photo-vision/2026-11-11/run.json', 'path' => 'models.1.valid_first_attempt.rate'],
        ['label' => 'gemma4:12b, latency per photo, median', 'unit' => 'ms', 'file' => 'photo-vision/2026-11-11/run.json', 'path' => 'models.0.latency_ms.p50'],
        ['label' => 'qwen3.5:9b, latency per photo, median', 'unit' => 'ms', 'file' => 'photo-vision/2026-11-11/run.json', 'path' => 'models.1.latency_ms.p50'],
        ['label' => 'gemma4:12b, body type agreement', 'file' => 'photo-vision/2026-11-11/run.json', 'path' => 'models.0.body_type_agreement.rate'],
        ['label' => 'qwen3.5:9b, body type agreement', 'file' => 'photo-vision/2026-11-11/run.json', 'path' => 'models.1.body_type_agreement.rate'],
    ],
    'experiments' => [
        [
            'title' => 'A body type against the real class',
            'predictions' => [
                ['id' => 'a', 'text' => 'The model names the body type of most photos the way the dataset class does.'],
                ['id' => 'b', 'text' => 'The model almost never agrees with the dataset class.'],
                ['id' => 'c', 'text' => 'The model always answers unknown.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'Open a photo and compare its body type with the class name. Most agree; the eval command measures how many.'],
            'page' => 'ai.photos',
            'params' => [],
        ],
    ],
    'related' => ['describe', 'providers'],
];
