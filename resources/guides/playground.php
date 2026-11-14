<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Prompt playground with versions',
    'summary' => 'Write a system prompt, save it as a numbered version that never changes, and run one input against two or three models side by side. Every run is stored, so a comparison can be opened again and two versions can be compared line by line.',
    'works' => [
        [
            'title' => 'A prompt is a name with numbered versions',
            'body' => 'A prompt has a name (3 to 80 characters, lowercase letters, digits and a few separators) and versions 1, 2, 3 and so on. A version holds the system prompt, a short note, the temperature and the maximum tokens. Saving a new prompt creates version 1, and every later save creates the next number.',
            'snippet' => 'App\Services\PlaygroundPromptService::create',
        ],
        [
            'title' => 'A version is immutable',
            'body' => 'There is no way to edit a saved version: no update route, no update method and no update timestamp. Saving always adds a version, inside a transaction that locks the prompt row before it reads the highest number, so two saves at once cannot take the same number. A run that used version 3 can therefore always show the exact text it ran with.',
            'snippet' => 'App\Repositories\EloquentPlaygroundPromptRepository::addVersion',
        ],
        [
            'title' => 'A save that changes nothing is refused',
            'body' => 'If the system prompt (after trimming and normalising line endings), the temperature and the maximum tokens all equal the latest version, the save is answered with 422 and the reason `unchanged`. A change to the note alone counts as unchanged. Text equal to an older version is allowed and becomes a new version.',
            'snippet' => 'App\Services\PlaygroundPromptService::addVersion',
        ],
        [
            'title' => 'Everything is checked before the first call',
            'body' => 'A run needs exactly one source: a saved version or a system prompt typed on the page (labelled as unsaved). The input is 1 to 4000 characters, there are 2 or 3 targets, each provider and model pair is distinct, the provider is enabled and the model is one the provider lists. Any failure answers 422 and no model is called.',
            'event' => 'validation_failed',
            'snippet' => 'App\Services\PlaygroundRunner::validateTargets',
        ],
        [
            'title' => 'The targets run one after another',
            'body' => 'Each target is one non-streaming chat call with the same system prompt, input, temperature and maximum tokens. They run in turn, not in parallel, because a local model server answers one request at a time: parallel calls would only make the latency figures wrong. The response comes back when all targets are finished.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\PlaygroundRunner::run',
        ],
        [
            'title' => 'Each column carries its own numbers',
            'body' => 'A result stores the output, tokens in and out, and the wall time around the call. Every call is also a row in the run log with the provider, model, the version label (`playground:name@v3`, or `playground:unsaved`) and the same figures, and the column links to it. Time to first token is not shown: it needs streaming, and a plain call has only a total.',
            'event' => 'response_received',
            'snippet' => 'App\Services\PlaygroundRunner::call',
        ],
        [
            'title' => 'A bad input is flagged, not refused',
            'body' => 'The input is matched against the same injection patterns as the other features, but a match does not stop the run: the playground exists to see how a model handles such text. The names of the matching patterns are stored on the run and shown as a badge, so the result is read with that in mind.',
            'snippet' => 'App\Services\PlaygroundRunner::run',
        ],
    ],
    'failures' => [
        [
            'title' => 'One column failed or timed out',
            'body' => "**Cause:** a target ended with an error or went over its time limit (60 seconds by default). Its result is stored as `failed` or `timeout` with a short error from which keys and URLs were removed.\n\n**Fix:** the other columns still ran and are valid. Read the error, open the run-log row of the failed call, and run again, or pick a smaller model if the timeout repeats.",
            'event' => 'request_failed',
            'snippet' => 'App\Services\PlaygroundRunner::redact',
        ],
        [
            'title' => 'The save is answered with unchanged',
            'body' => "**Cause:** the text, temperature and maximum tokens equal the latest version. Only the note differs, or nothing does.\n\n**Fix:** change the prompt, or accept that the latest version already is what you wanted. A note cannot be added to a version afterwards, by design.",
            'snippet' => 'App\Services\PlaygroundPromptService::addVersion',
        ],
        [
            'title' => 'The run is refused with 422 before anything runs',
            'body' => "**Cause:** a model that is not installed, a provider that is not enabled, two identical targets, one target or four, an input over 4000 characters, or both a version and a typed prompt.\n\n**Fix:** the picker lists only installed models, so this mostly comes from a request sent by hand. Read the field named in the error.",
            'snippet' => 'App\Services\PlaygroundRunner::validateTargets',
        ],
        [
            'title' => 'Two models look different but the test was unfair',
            'body' => "**Cause:** a temperature above 0 makes every answer vary, so a difference between two columns can be chance. A different input or maximum tokens per column would add more noise.\n\n**Fix:** compare at temperature 0, with one input and one setting for all columns, and repeat the run before you trust a difference.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Compare two models on one prompt',
            'body' => "Pick the two targets (the page chooses `qwen3.5:9b` and `gemma4:12b` first when they are installed), set the temperature to 0, type one input and run. Read the output first and the numbers second: tokens in and out and total time per column.\n\n**Check:** both columns show the same version label, and each links to its own run-log row.",
            'snippet' => 'App\Services\PlaygroundTargetCatalog::defaultTargets',
        ],
        [
            'title' => 'Change the default models or the provider list',
            'body' => "The first targets the page picks come from `AI_PLAYGROUND_TARGETS`, a comma separated list of `provider:model` entries (the model is everything after the first colon). Entries that are not available are dropped, and with fewer than two left the page picks the first installed text models and asks you to choose. A cloud provider needs its key and counts against the cloud limit.\n\n**Check:** a new page load shows the new defaults.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Compare two versions of one prompt',
            'body' => "Run version 1 and version 2 on the same input and the same two models, then open the diff for the two versions: a line diff of the system prompt, with the settings side by side. The stored runs keep their own copy of the text, so the comparison stays readable even if the prompt is deleted later.\n\n**Check:** the runs name `playground:name@v1` and `playground:name@v2` in the run log.",
        ],
        [
            'title' => 'Move a winning prompt into a feature',
            'body' => "The playground never changes how a feature behaves. To use a winner, copy its text into a new numbered prompt file in the app's prompts folder and point the agent of the feature at it. The old file stays, so old runs still name the version they used.\n\n**Check:** a new run of the feature logs the new prompt version.",
        ],
        [
            'title' => 'Change the limits',
            'body' => "Set `AI_PLAYGROUND_TIMEOUT` (seconds one target may take) and `AI_PLAYGROUND_PER_MINUTE` (runs per minute per IP, 6 by default) in the environment. The caps of 3 targets, 2048 maximum tokens and 4000 input characters are fixed in code.\n\n**Check:** the seventh run in a minute is answered with 429.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
    ],
    'support' => [
        [
            'name' => 'Runs, results and model calls',
            'status' => 'logged',
            'note' => 'Every run is stored with its system prompt, input, flags, temperature, maximum tokens and version label, and every target is a result with its output, error, tokens and times. Each call is also a run-log row linked from its column. Deleting a prompt keeps its runs, with the version link cleared.',
        ],
        [
            'name' => 'Input, output and cost',
            'status' => 'guarded',
            'note' => 'A run makes at most 3 calls, with at most 2048 tokens each and an input of at most 4000 characters, at 6 runs a minute per IP. On a cloud provider a run counts as many hits on the cloud limit as it has cloud targets. Output is shown as escaped plain text, never as HTML, errors are cleaned of keys, URLs, hosts, addresses and ports, and the endpoints refuse cross-site requests. The whole playground exists on a local machine only: a free-form model call with a stored history has no place on a public deploy, so there its page and every endpoint answer 404 and it is left out of the navigation.',
        ],
        [
            'name' => 'A run looks wrong',
            'status' => 'runbook',
            'note' => "1. Open the run and read each column's status and error.\n2. A timeout: raise the time limit or use a smaller model; a failed call has its own run-log row.\n3. An odd answer: check the input badge for a flagged pattern, then the temperature; at 0 the same input should give nearly the same text.\n4. A version that is missing from the list: the prompt was deleted, but its runs are still listed under the stored label.\n5. Two columns that disagree: run again at temperature 0 before drawing a conclusion.",
        ],
    ],
    'numbers' => [
        ['label' => 'Runs compared', 'file' => 'playground/2026-11-14/stats.json', 'path' => 'summary.runs'],
        ['label' => 'Models per run, average', 'file' => 'playground/2026-11-14/stats.json', 'path' => 'summary.average_models_per_run'],
        ['label' => 'Prompts saved', 'file' => 'playground/2026-11-14/stats.json', 'path' => 'summary.prompts'],
        ['label' => 'Versions saved', 'file' => 'playground/2026-11-14/stats.json', 'path' => 'summary.versions'],
        ['label' => 'Total time of a two-model run on local models, average', 'unit' => 's', 'file' => 'playground/2026-11-14/stats.json', 'path' => 'summary.average_two_model_local_duration_s'],
    ],
    'experiments' => [
        [
            'title' => 'The same input on two models',
            'predictions' => [
                ['id' => 'a', 'text' => 'Both columns give the same text and the same numbers.'],
                ['id' => 'b', 'text' => 'The columns differ in wording, tokens and time, and each links to its own run-log row.'],
                ['id' => 'c', 'text' => 'Only the first model runs.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Write a short system prompt, type one question and run it on two models. The models answer in their own way, with their own token counts and times, and every call is its own run-log row.'],
            'page' => 'ai.playground',
            'params' => [],
        ],
        [
            'title' => 'Save the same text twice',
            'predictions' => [
                ['id' => 'a', 'text' => 'A second version is created with the same text.'],
                ['id' => 'b', 'text' => 'The save is refused with the reason unchanged.'],
                ['id' => 'c', 'text' => 'The first version is overwritten.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Save a prompt, then save it again with only a different note. The second save is refused as unchanged, and version 1 is still the only version: a version is never overwritten.'],
            'page' => 'ai.playground',
            'params' => [],
        ],
        [
            'title' => 'An input that tries to steer the model',
            'predictions' => [
                ['id' => 'a', 'text' => 'The run is refused and no model is called.'],
                ['id' => 'b', 'text' => 'The run goes on and the badge names the matching pattern.'],
                ['id' => 'c', 'text' => 'The text is removed from the input and the run goes on.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Type "ignore all previous instructions and ..." as the input. Unlike the other features the playground runs it, because it is where you test how a model reacts, and shows the flag next to the columns.'],
            'page' => 'ai.playground',
            'params' => [],
        ],
    ],
    'related' => ['prompts', 'run-log', 'local-models', 'guards'],
];
