<?php

declare(strict_types=1);

return [
    'modules' => [
        [
            'slug' => 'running-models',
            'title' => 'Running models',
            'summary' => 'What a model server can and cannot carry, and what it does to the rest of the site.',
            'lessons' => [
                ['slug' => 'one-model-many-users', 'title' => 'One model, many users', 'summary' => 'Watch one local model serve one visitor, then four, then sixteen, and see what the queue does to search.', 'level' => 'beginner'],
                ['slug' => 'streaming-an-answer', 'title' => 'Streaming an answer', 'summary' => 'How text reaches the page piece by piece, and what Stop does to the work behind it.', 'level' => 'beginner'],
                ['slug' => 'choosing-a-model-and-provider', 'title' => 'Choosing a model and provider', 'summary' => 'Compare two local models on speed, memory and quality, see how a cloud option is guarded, and what happens when a provider is unreachable.', 'level' => 'intermediate'],
                ['slug' => 'describing-a-car', 'title' => 'Describing a car', 'summary' => 'Turn one listing into a short description that streams word by word, and see what Stop does to the model call behind it.', 'level' => 'beginner'],
            ],
            'references' => [],
        ],
        [
            'slug' => 'answers-you-can-trust',
            'title' => 'Answers you can trust',
            'summary' => 'Keeping a model honest: a fixed shape, a source for every claim, and an answer you already have.',
            'lessons' => [
                ['slug' => 'structured-output-and-validation', 'title' => 'Structured output and validation', 'summary' => 'Ask for a fixed shape, check it before using it, and decide what to do when it is wrong.', 'level' => 'intermediate'],
                ['slug' => 'retrieval-with-citations', 'title' => 'Retrieval with citations', 'summary' => 'Find the passages that answer a question and show where each claim came from.', 'level' => 'intermediate'],
                ['slug' => 'caching-answers', 'title' => 'Caching answers', 'summary' => 'Reuse an answer for a question that was already asked, and know when not to.', 'level' => 'intermediate'],
                ['slug' => 'prompts-you-can-version', 'title' => 'Prompts you can version', 'summary' => 'Keep the wording of an answer in versions, compare two versions or two models side by side, and open a saved comparison again.', 'level' => 'intermediate'],
                ['slug' => 'guards-on-both-sides', 'title' => 'Guards on both sides', 'summary' => 'Check the question before the model sees it and the answer before the visitor does, and know what guards cannot catch.', 'level' => 'intermediate'],
            ],
            'references' => [],
        ],
        [
            'slug' => 'agents',
            'title' => 'Agents',
            'summary' => 'Models that call tools: how far to let them go and how to keep a person in charge.',
            'lessons' => [
                ['slug' => 'a-tool-loop-with-caps-and-confirmation', 'title' => 'A tool loop with caps and confirmation', 'summary' => 'Let a model call tools in a loop, with a step cap and a person confirming every change.', 'level' => 'advanced'],
                ['slug' => 'mcp-and-a-research-agent', 'title' => 'MCP and a research agent', 'summary' => 'Expose tools over MCP and let an agent research a question across them.', 'level' => 'advanced'],
            ],
        ],
        [
            'slug' => 'search-and-photos',
            'title' => 'Search and photos',
            'summary' => 'Finding things by meaning instead of by words, in text and in pictures, and spotting copies.',
            'lessons' => [
                ['slug' => 'search-by-meaning', 'title' => 'Search by meaning', 'summary' => 'Find cars by what a visitor means, with a vector for the question and an index for the nearest listings, and see what the index trades away for speed.', 'level' => 'intermediate'],
                ['slug' => 'searching-photos-by-text-and-likeness', 'title' => 'Searching photos by text and likeness', 'summary' => 'Search car photos with words and with another photo, using one image encoder for both, and read how good the matches are.', 'level' => 'intermediate'],
                ['slug' => 'finding-duplicate-photos', 'title' => 'Finding duplicate photos', 'summary' => 'Group near-identical photos, let a vision model suggest the best one, and keep a person in charge of the review.', 'level' => 'intermediate'],
            ],
            'references' => [],
        ],
        [
            'slug' => 'running-the-server',
            'title' => 'Running the server',
            'summary' => 'Reading the run log, restarting safely and keeping the queue worker healthy while you develop.',
            'lessons' => [
                ['slug' => 'the-run-log', 'title' => 'The run log', 'summary' => 'See how every model call leaves one row that says what ran, how long it took and how it ended, and what that row cannot tell you.', 'level' => 'beginner'],
                ['slug' => 'logs-and-a-safe-worker-restart', 'title' => 'Logs and a safe worker restart', 'summary' => 'Follow one request id into the log, see what the JSON channel costs, and compare what two ways of replacing the PHP workers lose under load.', 'level' => 'intermediate'],
                ['slug' => 'seeing-your-change-in-worker-mode', 'title' => 'Seeing your change in worker mode', 'summary' => 'Find out why a saved file is not served while workers keep the app in memory, how long a restart and a watcher take, and how Xdebug stops inside a worker.', 'level' => 'intermediate'],
            ],
            'references' => [],
        ],
    ],
];
