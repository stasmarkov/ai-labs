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
                ['slug' => 'streaming-an-answer', 'title' => 'Streaming an answer', 'summary' => 'How text reaches the page piece by piece, and what Stop does to the work behind it.', 'level' => 'beginner', 'coming_soon' => true],
                ['slug' => 'choosing-a-model-and-provider', 'title' => 'Choosing a model and provider', 'summary' => 'Compare local and cloud models on speed, cost and what happens when one is unreachable.', 'level' => 'intermediate', 'coming_soon' => true],
            ],
        ],
        [
            'slug' => 'answers-you-can-trust',
            'title' => 'Answers you can trust',
            'summary' => 'Keeping a model honest: a fixed shape, a source for every claim, and an answer you already have.',
            'lessons' => [
                ['slug' => 'structured-output-and-validation', 'title' => 'Structured output and validation', 'summary' => 'Ask for a fixed shape, check it before using it, and decide what to do when it is wrong.', 'level' => 'intermediate', 'coming_soon' => true],
                ['slug' => 'retrieval-with-citations', 'title' => 'Retrieval with citations', 'summary' => 'Find the passages that answer a question and show where each claim came from.', 'level' => 'intermediate', 'coming_soon' => true],
                ['slug' => 'caching-answers', 'title' => 'Caching answers', 'summary' => 'Reuse an answer for a question that was already asked, and know when not to.', 'level' => 'intermediate', 'coming_soon' => true],
            ],
        ],
        [
            'slug' => 'agents',
            'title' => 'Agents',
            'summary' => 'Models that call tools: how far to let them go and how to keep a person in charge.',
            'lessons' => [
                ['slug' => 'a-tool-loop-with-caps-and-confirmation', 'title' => 'A tool loop with caps and confirmation', 'summary' => 'Let a model call tools in a loop, with a step cap and a person confirming every change.', 'level' => 'advanced', 'coming_soon' => true],
                ['slug' => 'mcp-and-a-research-agent', 'title' => 'MCP and a research agent', 'summary' => 'Expose tools over MCP and let an agent research a question across them.', 'level' => 'advanced', 'coming_soon' => true],
            ],
        ],
    ],
];
