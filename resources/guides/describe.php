<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Describe a car',
    'summary' => 'One car, one short description, sent word by word while the model writes it.',
    'works' => [
        [
            'title' => 'The listing is looked up first',
            'body' => 'The request names one listing. The service loads it **before** any stream starts, so an unknown id is a plain JSON 404 and never a half-open stream.',
            'snippet' => 'App\Services\ListingDescriptionService::describe',
        ],
        [
            'title' => 'The car becomes a prompt',
            'body' => 'The agent turns the listing into five short lines: car, fuel, mileage, price and city. The model sees facts, not a database row.',
            'snippet' => 'App\Ai\CarDescriptionAgent::promptFor',
        ],
        [
            'title' => 'The model starts',
            'body' => "The agent's instructions come from a **versioned prompt file**, so the wording of the answer is data, not code. The stream opens as soon as the provider accepts the call.",
            'event' => 'stream_start',
            'snippet' => 'resources/prompts/car-description.v1.md',
        ],
        [
            'title' => 'Words arrive',
            'body' => 'Every piece of text the model writes becomes one `data:` line. The server flushes after each line, so the page can show the text as it grows instead of after a long wait.',
            'event' => 'text_delta',
            'snippet' => 'App\Ai\SseProtocol::response',
        ],
        [
            'title' => 'The run is finished and logged',
            'body' => 'The last event carries the token counts. One `ai_runs` row records the provider, model, prompt version, tokens, time to first token, total time and status.',
            'event' => 'stream_end',
            'snippet' => 'App\Ai\AiRunRecorder::completed',
        ],
    ],
    'failures' => [
        [
            'title' => 'All the text appears at once at the end',
            'body' => "**Cause:** a proxy or the web server holds the bytes back until its buffer fills.\n\n**Fix:** the response already sends `X-Accel-Buffering: no` for any proxy in front, and FrankenPHP flushes each chunk on all three modes (Apache needed `flushpackets` when it was measured earlier on PHP-FPM). Check with `curl --no-buffer` to see the lines arrive one by one.",
            'snippet' => 'App\Ai\SseProtocol::headers',
        ],
        [
            'title' => 'The page shows an empty answer in worker mode',
            'body' => "**Cause:** a framework streaming helper hands the server an un-iterated generator, and the worker never drains it.\n\n**Fix:** the protocol drains its own generator and flushes per chunk, so it works under php-fpm and under a worker alike. Do not replace it with the stock helper.",
        ],
        [
            'title' => 'The text stops and an error event follows',
            'body' => "**Cause:** the provider failed in the middle of the answer: a timeout, a crash or a closed connection.\n\n**Fix:** the client gets a masked `error` event (no provider details leave the server). Open the run from the page: the `failed` row holds the error class and message.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Swap the model',
            'body' => "Set the model name for the active provider in the environment (`OLLAMA_MODEL` for the default local provider) and restart the app. No code changes.\n\n**Check:** run a description and open its run row: the `model` column shows the new name.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Switch to another provider',
            'body' => "Set `AI_PROVIDER` to another entry of the `providers` list, or add an entry for a cloud provider with its own key and model. The agent asks the active provider for its options, so nothing else changes.\n\n**Check:** the `provider` column of the next run row.",
            'snippet' => 'App\Ai\UsesActiveProvider',
        ],
        [
            'title' => 'Change the wording of the description',
            'body' => "Copy the prompt file to a new version (`car-description.v2`), edit the copy and point `AI_PROMPT_CAR_DESCRIPTION` at it. The old version stays in the repository and old rows keep their version.\n\n**Check:** the run row shows `car-description@v2`.",
            'snippet' => 'config/ai.php#prompts',
        ],
    ],
    'support' => [
        [
            'name' => 'Run row',
            'status' => 'logged',
            'note' => 'Every call writes one row: provider, model, prompt version, tokens in and out, time to first token, total latency and status (`completed`, `failed` or `stopped`).',
        ],
        [
            'name' => 'Tests',
            'status' => 'guarded',
            'note' => 'The describe feature test covers the stream, the 404 and the masked error; the run-log test covers the row; the SSE bench test guards the buffering fixes per server.',
        ],
        [
            'name' => 'A stream that hangs',
            'status' => 'runbook',
            'note' => "1. Open the latest run and read its status.\n2. `failed`: read the error class; a timeout means the model is cold or overloaded.\n3. No row at all: the request never reached the model; check the web server and the rate limit (20 a minute).\n4. Run the SSE bench against the server to see whether buffering is back.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Deltas still sent after Stop (shared stream code, median of 5)',
            'unit' => 'deltas',
            'file' => 'chat-stop/2026-11-03/frankenphp.json',
            'path' => 'median_tokens_after_stop',
        ],
        [
            'label' => 'Time to first token on a local model',
            'pending' => true,
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
    'related' => ['chat'],
    'lessons' => ['running-models/describing-a-car'],
    'build' => [
        ['step' => 'Build the prompt from stored facts', 'detail' => 'Look the record up first and turn its fields into the prompt, so the model never has to guess a make, year or price.', 'tab' => 'recipes'],
        ['step' => 'Use an agent class with fixed instructions', 'detail' => 'Keep the system prompt in one class or file and pass the facts as the user message, so the wording can be versioned and changed on its own.', 'tab' => 'recipes', 'doc' => 'https://laravel.com/docs/ai-sdk'],
        ['step' => 'Stream the answer', 'detail' => 'Return a streamed response and flush every event, so words appear while the model is still writing.', 'tab' => 'support', 'doc' => 'https://laravel.com/docs/responses#streamed-responses'],
        ['step' => 'Close the run in all three ways', 'detail' => 'Log finished, stopped and failed runs with their tokens and timings, so a cut stream still leaves a row.', 'tab' => 'support'],
        ['step' => 'Mask provider errors', 'detail' => 'Send the page a short error event and keep the provider message in the log, never in the stream.', 'tab' => 'support'],
        ['step' => 'Rate limit the route', 'detail' => 'Put a throttle on the endpoint, because each call costs money and holds a PHP worker for seconds.', 'doc' => 'https://laravel.com/docs/routing#rate-limiting'],
    ],
    'watch' => [
        ['point' => 'Cost grows with every click', 'detail' => 'A long description costs more output tokens than a short one. Cap the length in the prompt and in the options.', 'tab' => 'support'],
        ['point' => 'A streamed call holds a worker', 'detail' => 'A request that streams for ten seconds blocks one PHP thread for ten seconds. Size the pool for it.', 'tab' => 'failures'],
        ['point' => 'Buffering hides the stream', 'detail' => 'A proxy or output buffer can hold every word until the end. Test streaming on the server you deploy to.', 'tab' => 'failures'],
        ['point' => 'Invented details', 'detail' => 'The model may add a feature the car does not have. Give it only the stored facts and say so in the prompt.', 'tab' => 'failures'],
    ],
    'elsewhere' => [
        ['domain' => 'Real estate', 'problem' => 'A listing needs a readable summary from rooms, area and price.', 'how' => 'Pass the stored fields as facts, stream a short description and let the agent edit before publishing.'],
        ['domain' => 'E-commerce', 'problem' => 'Thousands of products have specs but no copy.', 'how' => 'Build the prompt from the product row and generate one description per product on a queue.'],
        ['domain' => 'Support', 'problem' => 'An agent needs a one-line summary of a ticket history.', 'how' => 'Feed the thread as data, stream a summary into the sidebar and log every run.'],
    ],
];
