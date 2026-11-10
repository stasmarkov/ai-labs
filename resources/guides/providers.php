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
            'title' => 'Two settings pick the active providers',
            'body' => 'One setting picks the provider for text (describe, chat, NL search), a second one picks the provider for embeddings (semantic and docs search), so a cloud text model can sit next to a local embedding model. No agent or service names a provider: they ask for the active one.',
            'snippet' => 'App\Ai\UsesActiveProvider',
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
            'title' => 'Add a cloud provider',
            'body' => "Add one entry to the provider list with its driver, key and model, then point the default setting at it. The agents pick up its options on the next call.\n\n**Check:** the `provider` column of the next run row.",
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
    ],
    'related' => ['prompts', 'run-log'],
];
