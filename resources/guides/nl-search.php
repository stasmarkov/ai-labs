<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Search with a sentence',
    'summary' => 'A model turns a sentence into filters; ordinary code checks them and runs the same search as the listings page.',
    'works' => [
        [
            'title' => 'The sentence is checked at the door',
            'body' => 'The request validates the text and the model name. A model that is not on the allowed list is a 422 before any model runs.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\NlSearchService::allowedModels',
        ],
        [
            'title' => 'The input guard runs first',
            'body' => 'A sentence that tries to change the assistant\'s instructions is refused with a 422 and `refused: true`. No model is called and a `refused` row is written.',
            'event' => 'refused',
            'snippet' => 'App\Services\PromptInjectionGuard::match',
        ],
        [
            'title' => 'The model fills a fixed shape',
            'body' => 'The agent declares a schema: make, model, city, fuel, minimum year, maximum price and a list of **unsupported** terms. Every key is required and nullable, so the model can say "nothing". The output is data, never a command.',
            'snippet' => 'App\Ai\ListingFilterAgent::schema',
        ],
        [
            'title' => 'The filters are validated',
            'body' => 'The fields go through the same rules as the listings page. A failure is a 422 that carries what the model extracted and the field errors, and no listing query runs.',
            'event' => 'validation_failed',
            'snippet' => 'App\Rules\ListingFilterRules',
        ],
        [
            'title' => 'The same search runs',
            'body' => 'Valid filters go to the service behind the listings page, as bound parameters. The reply lists the model, the extracted filters, what was ignored, the listings and the id of the logged run.',
            'event' => 'response_received',
            'snippet' => 'App\Services\NlSearchService::search',
        ],
    ],
    'failures' => [
        [
            'title' => 'Every query is filtered by a fuel you never mentioned',
            'body' => "**Cause:** the schema listed the fuels but not \"none\", so the decoder had to pick one. The value was legal, validation passed it and the search narrowed silently.\n\n**Fix:** allow `null` in the enum. The eval caught it: exact match went from 0.40 to 0.95.",
            'snippet' => 'App\Ai\ListingFilterAgent::schema',
        ],
        [
            'title' => 'The page shows a 422 with extracted values',
            'body' => "**Cause:** the model returned a value the rules reject, for example a year as text.\n\n**Fix:** nothing to fix in the page; the boundary did its job. Read the field errors, then check the prompt version and the model in the run row. Integers must be real JSON integers.",
        ],
        [
            'title' => 'The page shows 503',
            'body' => "**Cause:** the model call failed or ran past its own 20 second limit.\n\n**Fix:** check that the model is loaded. The limit is deliberately short so a slow model cannot hold a worker for minutes.",
            'snippet' => 'App\Ai\ListingFilterAgent::timeout',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Allow another model',
            'body' => "By default the active provider's text model and one extra model are allowed. Set `ai.nl_search.models` to a list to choose your own, then pick the model on the page.\n\n**Check:** the reply names the model, and the run row has the same name.",
            'snippet' => 'config/ai.php#nl-search',
        ],
        [
            'title' => 'Swap the model for everyone',
            'body' => "Set the text model of the active provider in the environment (`OLLAMA_MODEL` for the default one) and restart.\n\n**Check:** rerun the eval for the new model and compare exact match before you keep it.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Add a filter',
            'body' => "Add the key to the schema, to the list of fields and to the listing rules, then to the filter the listings page already uses. Add labelled queries for it to the eval set so the change is measured.\n\n**Check:** the eval's per-field accuracy shows the new field.",
            'snippet' => 'App\Services\NlSearchService::filterInput',
        ],
    ],
    'support' => [
        [
            'name' => 'Run row',
            'status' => 'logged',
            'note' => 'One row per call: model, prompt version (`listing-filters@v1`), tokens, latency and status. `validation_failed` marks outputs the boundary rejected, so the rate is countable.',
        ],
        [
            'name' => 'Eval and tests',
            'status' => 'guarded',
            'note' => 'The eval command scores 40 labelled queries per model: exact match, per-field accuracy, unsupported recall, 422 rate and latency. The feature tests fake the structured output and cover each response code.',
        ],
        [
            'name' => 'Answers got worse',
            'status' => 'runbook',
            'note' => "1. Filter the run rows by `validation_failed`.\n2. Run the eval for the model and prompt in use and compare it with the committed run.\n3. Look at the per-field accuracy: a single field that dropped points at the schema or the prompt, not at the model.\n4. Roll back by pointing the prompt setting at the previous version.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Exact match, first model (rate)',
            'file' => 'nl-search/2026-11-09/run.json',
            'path' => 'models.0.exact_match.rate',
        ],
        [
            'label' => 'Exact match, second model (rate)',
            'file' => 'nl-search/2026-11-09/run.json',
            'path' => 'models.1.exact_match.rate',
        ],
        [
            'label' => 'Latency p95, first model',
            'unit' => 'ms',
            'file' => 'nl-search/2026-11-09/run.json',
            'path' => 'models.0.latency_ms.p95',
        ],
        [
            'label' => 'Unsupported terms caught, second model (recall)',
            'file' => 'nl-search/2026-11-09/run.json',
            'path' => 'models.1.unsupported_recall.recall',
        ],
    ],
    'experiments' => [
        [
            'title' => 'What the filters cannot say',
            'predictions' => [
                ['id' => 'a', 'text' => 'The words colour and "cheap" are listed as ignored instead of becoming a filter.'],
                ['id' => 'b', 'text' => 'The word "cheap" becomes a price filter.'],
                ['id' => 'c', 'text' => 'The search fails with a validation error.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'A sentence with a colour or the word "cheap" lists those words as ignored instead of bending them into a filter.'],
            'page' => 'ai.nl-search',
            'params' => ['q' => 'cheap red diesel BMW after 2018'],
        ],
        [
            'title' => 'Say nothing about fuel',
            'predictions' => [
                ['id' => 'a', 'text' => 'The fuel filter defaults to petrol.'],
                ['id' => 'b', 'text' => 'The model guesses diesel from the price.'],
                ['id' => 'c', 'text' => 'The fuel filter stays empty.'],
            ],
            'answer' => ['id' => 'c', 'body' => 'A sentence that names no fuel leaves the fuel filter empty.'],
            'page' => 'ai.nl-search',
            'params' => ['q' => 'a car in Munich under 20000 euro'],
        ],
    ],
    'related' => ['semantic', 'docs'],
];
