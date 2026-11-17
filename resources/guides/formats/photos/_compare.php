<?php

declare(strict_types=1);

return [
    'outcomes' => [
        'LO-1' => 'Trace one photo from the extraction command to the stored extraction and the run row.',
        'LO-2' => 'Add a field: the agent schema, its PHP rule and a new prompt version, together.',
        'LO-3' => 'Explain why the answer is validated again in PHP and what happens after three failed attempts.',
        'LO-4' => 'Explain the separate vision queue and what happens when the model server is down.',
        'LO-5' => 'Change the vision model and confirm it from the run rows.',
    ],
    'rubric' => [
        ['name' => 'Clarity', 'meaning' => 'After one pass you can say what each part does and why it is there.'],
        ['name' => 'Time to first working change', 'meaning' => 'How soon you can make a small change, such as a new field, and see it work.'],
        ['name' => 'Retention', 'meaning' => 'A day later you can still explain the flow without reopening the page.'],
        ['name' => 'Effort to author', 'meaning' => 'How much work it is to write the same format for another guide.'],
    ],
    'baseline' => [
        'name' => 'Reference tabs',
        'idea' => 'The guide as it is today: tabs for how it works, the code, how to modify it, what to check when it breaks, numbers and experiments.',
        'time' => '10–15 min',
        'covers' => [
            'LO-1' => 'How it works',
            'LO-2' => 'Modify',
            'LO-3' => 'How it works',
            'LO-4' => 'Support',
            'LO-5' => 'Modify',
        ],
    ],
    'formats' => [
        'build-along' => [
            'name' => 'Build-along',
            'idea' => 'Rebuild the feature from zero in small steps. Each step has a goal, the real code, a command to run and the result to expect.',
            'time' => '25–35 min',
        ],
        'trace' => [
            'name' => 'Follow one photo',
            'idea' => 'Click through the stages one photo passes, from the command to the stored row, with sample data and code at each stage.',
            'time' => '10–15 min',
        ],
        'quiz' => [
            'name' => 'Predict and check',
            'idea' => 'Answer a question in build order before you see the answer, then read why and look at the code.',
            'time' => '15–20 min',
        ],
        'debug' => [
            'name' => 'Break and fix',
            'idea' => 'Start from a symptom, read the clues one at a time, name the cause, then see the fix and the test that prevents it.',
            'time' => '15–25 min',
        ],
        'tour' => [
            'name' => 'Code tour',
            'idea' => 'Read the real source in reading order, with notes tied to the lines that matter and previous and next buttons.',
            'time' => '15–20 min',
        ],
    ],
];
