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
            'body' => "**Cause:** a proxy or the web server holds the bytes back until its buffer fills.\n\n**Fix:** the response already sends `X-Accel-Buffering: no`; Apache needs `flushpackets` on its PHP proxy. Check with `curl --no-buffer` to see the lines arrive one by one.",
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
];
