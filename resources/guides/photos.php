<?php

declare(strict_types=1);

return [
    'kind' => 'planned',
    'title' => 'Photos',
    'summary' => 'Planned: describing and searching a car by its photos. This guide shows the plan, not working code.',
    'works' => [
        [
            'title' => 'A photo is sent with the question',
            'body' => 'The listing photo travels to a model that can read images, together with the same short instructions the text description uses.',
        ],
        [
            'title' => 'The model describes what it sees',
            'body' => 'The answer streams the same way as a text description, so the page and the log need no new parts.',
        ],
        [
            'title' => 'Photos become searchable',
            'body' => 'A short caption per photo is embedded like any other text, so a search for a colour or a body type can match the picture.',
        ],
    ],
    'failures' => [],
    'recipes' => [],
    'support' => [],
    'numbers' => [],
    'experiments' => [],
    'plan' => [
        'Send the listing photo to a vision-capable model with the same short instructions the text description uses.',
        'Stream the answer through the existing description path, so the page and the event log need no new parts.',
        'Store a short **caption per photo**, embed it like any other text, and let the semantic search match it.',
        'Keep the photo step optional: a listing without photos still gets its text description.',
    ],
    'related' => ['describe'],
];
