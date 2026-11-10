<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'Providers and models',
    'summary' => 'Which AI service answers, which model it runs, and how to change either one without touching code.',
    'works' => [
        [
            'title' => 'One list of providers',
            'body' => 'Every provider the app can use is one entry in a single list: its driver, its address, its limits and the model for each job (text and embeddings). A fake provider sits in the same list, so tests and demos run without a real model.',
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'A key switches a cloud provider on',
            'body' => 'Claude, OpenAI and Gemini sit in the same list, but each one is enabled only when its key is set in the environment (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY`). With no key the provider is simply absent: it is not offered in the lab and a request that names it is refused before any model call. The table above shows which keys are set, never their values.',
            'snippet' => 'config/ai.php#provider-cloud',
        ],
        [
            'title' => 'Each feature asks one resolver',
            'body' => 'Two settings pick the default provider, one for text and one for embeddings, and each feature (describe, NL search, docs answer, the grader, the two embedding jobs) can override it with its own provider and model. No agent or service reads those settings: they ask the resolver for their feature, so a text model in the cloud can sit next to a local embedding model.',
            'snippet' => 'App\Ai\AiProviders::forFeature',
        ],
        [
            'title' => 'A request can choose among the enabled providers',
            'body' => 'Describe, NL search and docs ask accept an optional `provider`. A name that is not an enabled text provider is refused with `provider_not_enabled` (422), and a cloud provider is counted by the `cloud-ai` limiter, so a paid model cannot be hammered.',
            'snippet' => 'App\Http\Middleware\LimitCloudAi::handle',
        ],
        [
            'title' => 'Embeddings stay on one model until you re-index',
            'body' => 'Vectors from two models cannot be compared. The index keeps 1024 dimensions, so a provider with other dimensions is refused by the indexing commands, and a search against an index built with another model answers `index_not_built` (409) and names the command that rebuilds it.',
            'snippet' => 'App\Services\ListingEmbeddingService::assertDimensions',
        ],
        [
            'title' => 'Options travel with the call',
            'body' => 'The entry\'s request options (thinking on or off, how long the model stays loaded, context size), the token cap and the timeout are read from the active entry on every call, so a change in the environment is enough.',
            'snippet' => 'App\Ai\UsesActiveProvider::providerOptions',
        ],
        [
            'title' => 'Every run names its provider and model',
            'body' => 'The run row stores the provider and the model that actually answered, so a later comparison never has to guess which setup produced a number.',
            'snippet' => 'App\Ai\AiRunRecorder::started',
        ],
    ],
    'failures' => [
        [
            'title' => 'A cloud call fails with 401',
            'body' => "**Cause:** the key is wrong, expired or belongs to another account. The run row is `failed`; its stored message has any key-shaped text replaced by `[redacted]`.\n\n**Fix:** create a new key in the provider console, put it in the environment and restart. Never paste a key into a page or a ticket.",
        ],
        [
            'title' => 'A cloud call fails with 404 after it used to work',
            'body' => "**Cause:** the provider renamed or retired the model id.\n\n**Fix:** look up the current id in the provider docs and set `ANTHROPIC_MODEL`, `OPENAI_MODEL` or `GEMINI_MODEL`; the default in the configuration is only a starting point.",
        ],
        [
            'title' => 'Every request of a feature answers provider_not_configured',
            'body' => "**Cause:** the feature is configured on a cloud provider (`AI_DESCRIBE_PROVIDER` and its siblings) whose key is empty. The request stops with 503 before any call; a command exits 1 with the same message.\n\n**Fix:** set the key, or point the feature back at a local provider.",
        ],
        [
            'title' => 'An embeddings command refuses to start',
            'body' => "**Cause:** the provider's embeddings entry does not produce 1024 dimensions, the size of the stored vectors.\n\n**Fix:** pick a model that can return 1024 dimensions and set `dimensions` to 1024 in its entry, or keep the local embedding model.",
        ],
        [
            'title' => 'A cloud provider is missing from the selector',
            'body' => "**Cause:** its key is not set, so the provider is not enabled. A request that names it answers `provider_not_enabled`.\n\n**Fix:** set the key in the environment, clear the configuration cache and reload the workers. The table on the first tab shows the key as set.",
        ],
        [
            'title' => 'Search answers index_not_built after a switch',
            'body' => "**Cause:** the stored vectors came from another embedding model, so the query vector would be compared with the wrong space.\n\n**Fix:** run the re-index command the error names (`listings:embed --fresh` or `docs:index`). Providers whose dimensions are not 1024 are refused by those commands.",
        ],
        [
            'title' => 'Too many cloud requests',
            'body' => "**Cause:** the `cloud-ai` limiter allows a few requests per minute per IP (`AI_CLOUD_PER_MINUTE`), on top of the feature's own limit.\n\n**Fix:** wait for the `Retry-After` seconds, or raise the limit knowing each call is billed.",
        ],
        [
            'title' => 'The model name is accepted but nothing answers',
            'body' => "**Cause:** the model is not pulled on the machine that runs the provider, or the name has a typo.\n\n**Fix:** list the models the provider has, pull the missing one, then repeat the call. The failed run row holds the provider's message.",
        ],
        [
            'title' => 'A change in the environment has no effect',
            'body' => "**Cause:** the configuration is cached, or a long-running worker still holds the old values.\n\n**Fix:** clear the configuration cache and reload the workers or restart the app.",
        ],
        [
            'title' => 'The first call after a pause is very slow',
            'body' => "**Cause:** the provider unloaded the model after its keep-alive time and loads it again for the next call.\n\n**Fix:** raise the keep-alive option of the entry, or accept one cold start and compare only warm runs.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
    ],
    'recipes' => [
        [
            'title' => 'Swap the model',
            'body' => "Set the model name for the active provider in the environment and restart. No code changes.\n\n**Check:** the live facts above show the new name and the next run row carries it.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Switch a feature to a cloud provider',
            'body' => "Set the provider's key (`ANTHROPIC_API_KEY`, `OPENAI_API_KEY` or `GEMINI_API_KEY`), then either set `AI_PROVIDER` for every text feature or `AI_DESCRIBE_PROVIDER` and its siblings for one feature. Pick the model with `ANTHROPIC_MODEL`, `OPENAI_MODEL` or `GEMINI_MODEL`.\n\n**Check:** the live facts and the table above, then the `provider` column of the next run row.",
            'snippet' => 'config/ai.php#provider-cloud',
        ],
        [
            'title' => 'Switch the embeddings to a cloud provider',
            'body' => "Set the key, then `AI_EMBEDDINGS_LISTINGS_PROVIDER` or `AI_EMBEDDINGS_DOCS_PROVIDER` (for example `openai`), restart, and re-index: `listings:embed --fresh` for listings, `docs:index` for docs. Until then search answers `index_not_built` instead of comparing vectors of two models.\n\n**Check:** search answers again, and every stored vector carries the new model label.",
            'snippet' => 'App\Services\ListingEmbeddingService::embedTexts',
        ],
        [
            'title' => 'Add a provider',
            'body' => "Add one entry to the provider list with its driver, key, URL, token cap and models, add its name and key variable to the resolver, and add the SDK driver to the capability table. Give it a `max_tokens` so a runaway answer cannot be billed.\n\n**Check:** the table lists it, a test with the SDK fake runs a feature on it, and the `provider` column of the next run row names it.",
            'snippet' => 'App\Ai\UsesActiveProvider::maxTokens',
        ],
    ],
    'support' => [
        [
            'name' => 'Run row',
            'status' => 'logged',
            'note' => 'Every call logs the provider and the model that answered, next to its tokens and timings.',
        ],
        [
            'name' => 'Keys',
            'status' => 'guarded',
            'note' => 'A key value never reaches a page, a log line, a run row or this guide: only whether it is set.',
        ],
        [
            'name' => 'Fake providers',
            'status' => 'guarded',
            'note' => 'The test suite uses the fake provider only, so no test calls a model or the network.',
        ],
        [
            'name' => 'The wrong model answers',
            'status' => 'runbook',
            'note' => "1. Read the live facts on this page: they show what the app is configured with now.\n2. Open the latest run and compare its model.\n3. If they differ, clear the configuration cache and restart the workers.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Time to first token per provider',
            'pending' => true,
        ],
    ],
    'experiments' => [
        [
            'title' => 'See which model answers',
            'predictions' => [
                ['id' => 'a', 'text' => 'The run is logged with the provider and model shown on this page.'],
                ['id' => 'b', 'text' => 'The run is logged without a model name.'],
                ['id' => 'c', 'text' => 'The run is logged with a different model than shown here.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'The run you start here is logged with the provider and model shown on this page.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
        [
            'title' => 'Local or cloud: which starts answering first?',
            'predictions' => [
                ['id' => 'a', 'text' => 'The local model, because there is no network on the way.'],
                ['id' => 'b', 'text' => 'The cloud model, because its hardware is faster than a laptop.'],
                ['id' => 'c', 'text' => 'It depends: a warm local model often starts first, a cold one loses to the cloud.'],
            ],
            'answer' => ['id' => 'c', 'body' => 'Run the same description twice on the local model and twice on a cloud provider, then compare the time to first token on the run pages. A cold local model pays its load time once; after that the network round trip is usually what the cloud loses.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
        [
            'title' => 'Ask for a provider that has no key',
            'predictions' => [
                ['id' => 'a', 'text' => 'The request is refused with provider_not_enabled before any model call.'],
                ['id' => 'b', 'text' => 'The request falls back to the default provider.'],
                ['id' => 'c', 'text' => 'The request is sent to the provider and fails there.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'A provider without a key is not enabled, so the request is refused with `provider_not_enabled` and no run row is written.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['prompts', 'run-log'],
];
