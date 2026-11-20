<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$photos = 'photo-vision/2026-11-11/run.json';
$filters = 'nl-search/2026-11-09/run.json';
$firstPhoto = 'models.1';
$secondPhoto = 'models.0';
$firstFilter = 'models.0';
$secondFilter = 'models.1';

return [
    'title' => 'Structured output and validation',
    'summary' => 'Ask a model for a fixed shape, check the answer again in ordinary code, and decide what happens when it is wrong.',
    'minutes' => 10,
    'level' => 'intermediate',
    'guide' => 'nl-search',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why code needs a fixed shape from a model and not a sentence.'],
        ['id' => 'lo-2', 'text' => 'Say why every field of the shape is required and may still be empty.'],
        ['id' => 'lo-3', 'text' => 'Explain why the same limits are checked again in PHP.'],
        ['id' => 'lo-4', 'text' => 'Describe what the app does with an answer that fails: retry, fail or refuse.'],
        ['id' => 'lo-5', 'text' => 'Say what a passing check does not tell you.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'visitor', 'kind' => 'browser', 'label' => 'Visitor', 'x' => 150, 'y' => 300],
            ['id' => 'app', 'kind' => 'app', 'label' => 'The app', 'x' => 500, 'y' => 300],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model', 'x' => 850, 'y' => 130],
            ['id' => 'rules', 'kind' => 'app', 'label' => 'Rules in PHP', 'x' => 850, 'y' => 470],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'visitor', 'to' => 'app'],
            ['id' => 'to-model', 'from' => 'app', 'to' => 'model'],
            ['id' => 'from-model', 'from' => 'model', 'to' => 'app'],
            ['id' => 'to-rules', 'from' => 'app', 'to' => 'rules'],
        ],
    ],
    'kpis' => [
        ['id' => 'valid', 'label' => 'Valid on the first try', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'attempts', 'label' => 'Tries per photo, on average', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'exact', 'label' => 'Filters exactly right', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'caught', 'label' => 'Unsupported terms caught', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'body', 'label' => 'Body type agrees with the label', 'format' => 'percent', 'better' => 'higher'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A sentence is not a record',
            'body' => [
                'A visitor types a sentence. The page needs a make, a fuel and a year to run a search. A model left alone answers in prose, and code cannot filter listings with prose.',
                'Reading the prose back with string tricks breaks the first time the model words it differently.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Code needs a record with known fields. Free text is for people.'],
            'state' => [
                'nodes' => [
                    'visitor' => ['load' => 10],
                    'app' => ['load' => 10],
                    'model' => ['load' => 20, 'badge' => 'answers in prose'],
                    'rules' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 3]],
                    'to-rules' => ['visible' => false],
                ],
            ],
            'kpis' => [
                'valid' => '—',
                'attempts' => '—',
                'exact' => '—',
                'caught' => '—',
                'body' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the answer is text that code cannot use'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'nl-search#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Ask for a fixed shape',
            'body' => [
                'The agent declares a schema: make, model, city, fuel, a minimum year, a maximum price and a list of terms it cannot support. The model fills that shape, and the reply is read as data, never as a command.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Declare the shape in code, next to the agent, so the request and the reader of the reply cannot drift apart.'],
            'state' => [
                'nodes' => [
                    'model' => ['badge' => 'fills a fixed shape'],
                    'app' => ['badge' => 'reads fields'],
                ],
                'edges' => [
                    'from-model' => ['packets' => ['token' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a schema with named fields'],
                ['sign' => '+', 'text' => 'a list for what the search cannot do'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'nl-search#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Required, and still allowed to be empty',
            'body' => [
                'Every field is required, but each may be empty, and the fuel list includes nothing as a choice. A sentence that never names a fuel then gets no fuel.',
                'Without that choice the decoder has to pick one, the value is legal, and every search is narrowed by a fuel nobody asked for. With it, the model got the filters exactly right on {num:'.$filters.'#'.$firstFilter.'.exact_match.count|count|0} of {num:'.$filters.'#'.$firstFilter.'.queries|count|0} test sentences.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Give the model a way to say nothing. If it cannot, it will invent something that passes.'],
            'state' => [
                'nodes' => [
                    'model' => ['badge' => 'may answer nothing'],
                ],
            ],
            'kpis' => [
                'exact' => Source::percent($filters, "{$firstFilter}.exact_match.rate"),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'an empty answer is a legal answer'],
                ['sign' => '~', 'text' => 'filters exactly right on {kpi:exact} of the test sentences'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'nl-search#support',
        ],
        [
            'role' => 'careful',
            'title' => 'A schema is a request, not a promise',
            'body' => [
                'The schema tells the model what is wanted. It does not make the reply true to it. So the same limits are enforced again in PHP, with ordinary validation rules, before anything is used.',
                'For a photo these are the lengths, the colours from a fixed list, the body type and the people count. The reply is only used after it passes them.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Validate what a model returns the way you validate a form: at the boundary, in your own code.'],
            'state' => [
                'nodes' => [
                    'rules' => ['status' => 'ok', 'load' => 20, 'badge' => 'the same limits again'],
                    'model' => ['badge' => 'reads a photo'],
                ],
                'edges' => [
                    'to-rules' => ['visible' => true, 'packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the reply goes through validation rules'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'photos#works',
        ],
        [
            'role' => 'problem',
            'title' => 'What the check finds',
            'body' => [
                'The first model read {num:'.$photos.'#'.$firstPhoto.'.photos|count|0} car photos. Its reply passed the rules on the first try for {num:'.$photos.'#'.$firstPhoto.'.valid_first_attempt.count|count|0} of them, so {kpi:valid} of the photos were fine at once.',
                'The second model passed on the first try for every photo. Same schema, same rules, a different share of replies that break them.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'How often a model breaks the shape depends on the model. Measure it for the one you use.'],
            'state' => [
                'nodes' => [
                    'model' => ['badge' => 'the first model'],
                    'rules' => ['status' => 'busy', 'load' => 70, 'badge' => 'some replies fail'],
                ],
                'edges' => [
                    'to-rules' => ['packets' => ['request' => 3, 'failure' => 1]],
                ],
            ],
            'kpis' => [
                'valid' => Source::uncompared(Source::percent($photos, "{$firstPhoto}.valid_first_attempt.rate")),
                'attempts' => Source::uncompared(Source::figure($photos, "{$firstPhoto}.mean_attempts", 2)),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'valid on the first try {kpi:valid}'],
                ['sign' => '~', 'text' => 'on average {kpi:attempts} tries per photo'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'photos#numbers',
        ],
        [
            'role' => 'solution',
            'title' => 'Retry, then fail loudly',
            'body' => [
                'A reply that fails the rules is asked for again, up to three tries in all, and every try is its own row in the run log. A photo that still fails is marked failed with the reasons.',
                'For the first model it took {kpi:attempts} tries per photo on average, retries included, yet the count of valid photos stayed the same: {num:'.$photos.'#'.$firstPhoto.'.valid_after_retries.count|count|0}. A retry is not a fix for a reply that is wrong in the same way each time.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Cap the retries and record each one. An uncapped retry loop turns one bad reply into a bill.'],
            'state' => [
                'nodes' => [
                    'app' => ['badge' => 'asks again, up to three tries'],
                    'rules' => ['badge' => 'one row per try'],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 3]],
                    'to-rules' => ['packets' => ['request' => 2, 'failure' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'up to three tries, one row each'],
                ['sign' => '~', 'text' => 'valid after the retries: {num:'.$photos.'#'.$firstPhoto.'.valid_after_retries.count|count|0} photos, no more than on the first try'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'photos#support',
        ],
        [
            'role' => 'careful',
            'title' => 'A refusal is not a retry',
            'body' => [
                'Not every failure is a bad reply. When the model server is down or the call times out, the photo is marked failed after one try, so a stopped server cannot hide behind repeated tries.',
                'In the search by sentence there is no retry at all. A failed check is an error that carries what the model extracted and the field errors, and no listing query runs.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Decide per failure: retry a bad reply, fail a dead server at once, and show the visitor an error that says what was wrong.'],
            'state' => [
                'nodes' => [
                    'model' => ['status' => 'down', 'badge' => 'not answering'],
                    'rules' => ['status' => 'ok', 'load' => 10, 'badge' => 'no listing query runs'],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 0, 'failure' => 2]],
                    'to-rules' => ['packets' => ['request' => 0, 'failure' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'a transport error is one try, with no retry'],
                ['sign' => '~', 'text' => 'a failed check in search is an error, not a query'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'nl-search#support',
        ],
        [
            'role' => 'useful',
            'title' => 'Let the model say it cannot',
            'body' => [
                'The shape has a list for terms the search cannot support, such as a colour or a feature the filters do not know. A model that fills it gives the page something honest to show instead of a silent guess.',
                'The first model caught {num:'.$filters.'#'.$firstFilter.'.unsupported_recall.caught|count|0} of the {num:'.$filters.'#'.$firstFilter.'.unsupported_recall.expected|count|0} unsupported terms in the test set, the second model {num:'.$filters.'#'.$secondFilter.'.unsupported_recall.caught|count|0}.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Add an escape field. Whatever the model puts there is a signal you can show, count and test.'],
            'state' => [
                'nodes' => [
                    'model' => ['status' => 'ok', 'badge' => 'lists what it cannot do'],
                    'rules' => ['status' => 'ok', 'load' => 10, 'badge' => null],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 1, 'failure' => 0]],
                    'from-model' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [
                'caught' => Source::uncompared(Source::percent($filters, "{$firstFilter}.unsupported_recall.recall")),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a list for terms the search cannot support'],
                ['sign' => '~', 'text' => 'the first model caught {kpi:caught} of them'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'nl-search#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'Valid is not right',
            'body' => [
                'A reply can pass every rule and still be wrong. The second model never broke the shape on a photo, yet its body type agreed with the photo\'s own label for {num:'.$photos.'#'.$secondPhoto.'.body_type_agreement.agreed|count|0} of {num:'.$photos.'#'.$secondPhoto.'.body_type_agreement.compared|count|0} photos, which is {kpi:body}.',
                'The check proves the shape and the limits. Whether the content is true needs a second measurement, with labelled examples.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Validation catches broken output, not wrong output. Keep a small labelled set and score the content too.'],
            'state' => [
                'nodes' => [
                    'model' => ['badge' => 'the second model'],
                    'rules' => ['status' => 'ok', 'load' => 10, 'badge' => 'every reply passes'],
                ],
                'edges' => [
                    'to-rules' => ['visible' => true, 'packets' => ['request' => 2, 'failure' => 0]],
                ],
            ],
            'kpis' => [
                'valid' => Source::uncompared(Source::percent($photos, "{$secondPhoto}.valid_first_attempt.rate")),
                'attempts' => Source::uncompared(Source::figure($photos, "{$secondPhoto}.mean_attempts", 2)),
                'body' => Source::uncompared(Source::percent($photos, "{$secondPhoto}.body_type_agreement.rate")),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'valid on the first try {kpi:valid}'],
                ['sign' => '~', 'text' => 'body type agrees with the label {kpi:body}'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'photos#numbers',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Ask for a fixed shape, require every field and allow an empty answer. Check the reply again in your own code, retry only what a retry can fix, and fail the rest with a clear reason.',
                'Then remember what the check cannot see: a valid reply can be wrong. The photo figures here are the second model\'s run.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A schema asks, validation checks, a labelled set measures. You need all three.'],
            'state' => [
                'nodes' => [
                    'visitor' => ['load' => 10],
                    'app' => ['badge' => null],
                    'model' => ['status' => 'ok', 'load' => 30, 'badge' => 'fills the shape'],
                    'rules' => ['status' => 'ok', 'load' => 20, 'badge' => 'checks it again'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 2]],
                    'to-rules' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'valid' => Source::uncompared(Source::percent($photos, "{$secondPhoto}.valid_first_attempt.rate")),
                'attempts' => Source::uncompared(Source::figure($photos, "{$secondPhoto}.mean_attempts", 2)),
                'body' => Source::uncompared(Source::percent($photos, "{$secondPhoto}.body_type_agreement.rate")),
                'exact' => '—',
                'caught' => '—',
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'valid on the first try {kpi:valid}, body type {kpi:body}'],
            ],
            'learn' => [],
            'deeper' => 'nl-search#modify',
        ],
    ],
];
