<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$large = 'rag-eval/2026-11-03/recall-500-300/recall.json';
$small = 'rag-eval/2026-11-03/answers-qwen-300-180/recall.json';
$answers = 'rag-eval/2026-11-03/answers-qwen-500-300/answers.json';
$vector3 = 'cells.0';
$vector6 = 'cells.1';
$text6 = 'cells.4';
$hybrid3 = 'cells.6';
$hybrid6 = 'cells.7';

return [
    'title' => 'Retrieval with citations',
    'summary' => 'Find the passages that answer a question, hand only those to the model, and show where each claim came from.',
    'minutes' => 10,
    'level' => 'intermediate',
    'guide' => 'docs',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why a model answers from passages you hand it and not from memory.'],
        ['id' => 'lo-2', 'text' => 'Compare search by meaning, by words and both together, and say what recall measures.'],
        ['id' => 'lo-3', 'text' => 'Say what chunk size changes and what it does not.'],
        ['id' => 'lo-4', 'text' => 'Describe the gate that stops a question the docs do not cover from reaching the model.'],
        ['id' => 'lo-5', 'text' => 'Explain what a citation marker proves and what it does not.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'visitor', 'kind' => 'browser', 'label' => 'Visitor', 'x' => 150, 'y' => 300],
            ['id' => 'app', 'kind' => 'app', 'label' => 'The app', 'x' => 500, 'y' => 300],
            ['id' => 'index', 'kind' => 'vector-db', 'label' => 'Chunk index', 'x' => 850, 'y' => 130],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model', 'x' => 850, 'y' => 470],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'visitor', 'to' => 'app'],
            ['id' => 'to-index', 'from' => 'app', 'to' => 'index'],
            ['id' => 'from-index', 'from' => 'index', 'to' => 'app'],
            ['id' => 'to-model', 'from' => 'app', 'to' => 'model'],
            ['id' => 'from-model', 'from' => 'model', 'to' => 'app'],
        ],
    ],
    'kpis' => [
        ['id' => 'recall', 'label' => 'Right page found in the top six', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'early', 'label' => 'Right page found in the top three', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'cited', 'label' => 'Answers with a citation', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'grounded', 'label' => 'Grounded, share of full marks', 'format' => 'percent', 'better' => 'higher'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A model alone answers from memory',
            'body' => [
                'Ask a model about your own documentation and it answers from what it remembers of the world, in a confident voice. It cannot know your version, your settings or what changed last week.',
                'Nothing in such an answer says where it came from, so nobody can check it.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'An answer worth trusting has a source the reader can open.'],
            'state' => [
                'nodes' => [
                    'visitor' => ['load' => 10],
                    'app' => ['load' => 10],
                    'index' => ['status' => 'hidden'],
                    'model' => ['load' => 30, 'badge' => 'answers from memory'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-index' => ['visible' => false],
                    'from-index' => ['visible' => false],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'recall' => '—',
                'early' => '—',
                'cited' => '—',
                'grounded' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'no source behind the answer'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'chat#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Cut the pages into chunks',
            'body' => [
                'The pages are cut into chunks along headings, then paragraphs, then sentences, with code and tables kept whole. The title and section go in front of every chunk so it carries its context.',
                'Each chunk is stored twice: as a vector that stands for its meaning, and as a text column for its exact words.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A page is too big to have one useful vector. Chunks are the unit you search and the unit you cite.'],
            'state' => [
                'nodes' => [
                    'index' => ['status' => 'ok', 'load' => 10, 'badge' => 'chunks, twice each'],
                    'model' => ['badge' => null],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 0]],
                    'from-model' => ['packets' => ['token' => 0]],
                    'to-index' => ['visible' => true, 'packets' => ['request' => 1]],
                    'from-index' => ['visible' => true, 'packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'chunks with a title, a section and a vector'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'docs#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Search by meaning',
            'body' => [
                'The question is turned into a vector and compared with the vectors of the chunks. A question about a problem finds the page that explains it, even when the page uses other words.',
                'On {num:'.$large.'#'.$vector6.'.questions|count|0} labelled questions the right page was among the top six for {num:'.$large.'#'.$vector6.'.hits|count|0}, which is {kpi:recall}.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Recall asks one thing: was the right page in the list at all? A model cannot cite what it was never given.'],
            'state' => [
                'nodes' => [
                    'model' => ['badge' => null],
                    'index' => ['status' => 'ok', 'load' => 30, 'badge' => 'by meaning'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-index' => ['packets' => ['request' => 2, 'embedding' => 1]],
                    'from-index' => ['packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [
                'recall' => Source::percent($large, "{$vector6}.recall"),
                'early' => Source::percent($large, "{$vector3}.recall"),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the right page in the top six for {kpi:recall}'],
                ['sign' => '~', 'text' => 'in the top three for {kpi:early}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'docs#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'Words alone find nothing here',
            'body' => [
                'Search by exact words is the other half. It is the right tool for a rare function name, and the wrong one for a whole sentence, because it needs every word of the question inside one chunk.',
                'On the same questions it found the right page in the top six {num:'.$large.'#'.$text6.'.hits|count|0} times, which is {kpi:recall}. That is a property of whole-sentence questions, not a bug in the search.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Test each way of searching on questions shaped like the real ones. A method that suits keywords can come back empty on sentences.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'by exact words'],
                ],
                'edges' => [
                    'to-index' => ['packets' => ['request' => 2, 'embedding' => 0]],
                    'from-index' => ['packets' => ['embedding' => 0, 'failure' => 1]],
                ],
            ],
            'kpis' => [
                'recall' => Source::uncompared(Source::percent($large, "{$text6}.recall")),
                'early' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'whole-sentence questions found {kpi:recall} by words'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'docs#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Merge the two lists',
            'body' => [
                'Hybrid search merges the list by meaning and the list by words with reciprocal rank fusion: a chunk high in both wins, and no score scale has to be tuned.',
                'Here it did not beat search by meaning. The top six held the right page {kpi:recall} of the time, the same as before, and the top three {kpi:early}, a little lower, because the empty list still had an equal say.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Hybrid is not automatically better. Its worth shows on questions that hinge on an exact name, so test it on those too.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'both lists, merged'],
                ],
                'edges' => [
                    'to-index' => ['packets' => ['request' => 2, 'embedding' => 1]],
                    'from-index' => ['packets' => ['embedding' => 2, 'failure' => 0]],
                ],
            ],
            'kpis' => [
                'recall' => Source::uncompared(Source::percent($large, "{$hybrid6}.recall")),
                'early' => Source::uncompared(Source::percent($large, "{$hybrid3}.recall")),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'top six {kpi:recall}, the same as by meaning'],
                ['sign' => '~', 'text' => 'top three {kpi:early}, lower than by meaning'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'docs#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Smaller chunks rank better',
            'body' => [
                'Chunk size changes what the search can rank. With smaller chunks the right page was in the top three for {num:'.$small.'#'.$vector3.'.hits|count|0} questions against {num:'.$large.'#'.$vector3.'.hits|count|0} before, by meaning.',
                'The top six did not change at all: {kpi:recall} either way. Smaller chunks help the order, not the coverage, and the answer grades could not tell the two sizes apart.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Change one setting, rerun the same labelled questions, and compare. A small set shows a trend; it is too small to tune to a decimal.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'smaller chunks'],
                ],
                'edges' => [
                    'to-index' => ['packets' => ['request' => 2, 'embedding' => 1]],
                ],
            ],
            'kpis' => [
                'recall' => Source::uncompared(Source::percent($small, "{$vector6}.recall")),
                'early' => Source::uncompared(Source::percent($small, "{$vector3}.recall")),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'top three {kpi:early} with smaller chunks, by meaning'],
                ['sign' => '~', 'text' => 'top six {kpi:recall}, unchanged'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'docs#modify',
        ],
        [
            'role' => 'solution',
            'title' => 'The gate: no passage, no model',
            'body' => [
                'If no retrieved chunk is relevant enough, the app answers that it does not know, and the model is never called. The sources are sent to the page first, so the reader sees where an answer will come from before it starts.',
                'In the first graded run, {num:'.$answers.'#models.0.runs.0.summary.declined_correctly|count|0} of the {num:'.$answers.'#models.0.runs.0.summary.off_topic|count|0} questions outside the docs were declined and no on-topic question was refused.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'The cheapest wrong answer to prevent is the one you never ask the model for.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'relevance gate'],
                    'model' => ['status' => 'hidden'],
                    'app' => ['badge' => 'answers: not in the docs'],
                ],
                'edges' => [
                    'to-model' => ['visible' => false],
                    'from-model' => ['visible' => false],
                    'from-index' => ['packets' => ['embedding' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a question the docs do not cover gets a plain "I do not know"'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'chat#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Passages as data, numbered for citing',
            'body' => [
                'The chunks that pass the gate are escaped and wrapped as data, each with its number, and the instructions tell the model to cite with a number in square brackets. Text inside a chunk is never an instruction.',
                'Over repeated runs the answer model cited a source in {kpi:cited} of its answers, at the median.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Number the sources in the prompt. A short marker in the answer is easy to parse and easy to show.'],
            'state' => [
                'nodes' => [
                    'model' => ['status' => 'ok', 'load' => 50, 'badge' => 'answers from the passages'],
                    'app' => ['badge' => 'wraps the passages as data'],
                ],
                'edges' => [
                    'to-model' => ['visible' => true, 'packets' => ['request' => 2]],
                    'from-model' => ['visible' => true, 'packets' => ['token' => 4]],
                ],
            ],
            'kpis' => [
                'cited' => Source::uncompared(Source::percent($answers, 'models.0.across_runs.citation_rate.median')),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'answers carry numbered markers'],
                ['sign' => '~', 'text' => 'a source cited in {kpi:cited} of answers'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'chat#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'A marker is not a proof',
            'body' => [
                'At the end the text is parsed once for its markers. A number that matches no source is listed as unknown and never becomes a link, and code blocks and array indexes are left alone.',
                'That proves the marker points at a real source. It does not prove the source says what the answer claims, so a second model grades each answer against its passages: {kpi:grounded} of full marks at the median.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Check that a citation exists, then check that it supports the claim. The first is code; the second needs a grader.'],
            'state' => [
                'nodes' => [
                    'app' => ['badge' => 'resolves the markers'],
                    'model' => ['badge' => 'a second model grades'],
                ],
                'edges' => [
                    'from-model' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [
                'cited' => Source::uncompared(Source::percent($answers, 'models.0.across_runs.citation_rate.median')),
                'grounded' => Source::uncompared(Source::figure($answers, 'models.0.across_runs.groundedness.median', 0, 50)),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'an unknown marker is listed, not linked'],
                ['sign' => '~', 'text' => 'grounded {kpi:grounded} by the grader'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'chat#support',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Search first and hand the model only what was found. Measure the search on labelled questions, stop a question the docs do not cover before it reaches the model, and number the passages so the answer can cite them.',
                'Then check the citations in code and the claims with a grader. The figures here are search by meaning at the first chunk size and the first answer model.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Retrieval decides what the model can say. Citations let the reader check that it said it.'],
            'state' => [
                'nodes' => [
                    'visitor' => ['load' => 10],
                    'app' => ['badge' => null],
                    'index' => ['status' => 'ok', 'load' => 30, 'badge' => 'finds the passages'],
                    'model' => ['status' => 'ok', 'load' => 40, 'badge' => 'cites them'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-index' => ['packets' => ['request' => 1]],
                    'from-index' => ['packets' => ['embedding' => 2]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'recall' => Source::uncompared(Source::percent($large, "{$vector6}.recall")),
                'early' => Source::uncompared(Source::percent($large, "{$vector3}.recall")),
                'cited' => Source::uncompared(Source::percent($answers, 'models.0.across_runs.citation_rate.median')),
                'grounded' => Source::uncompared(Source::figure($answers, 'models.0.across_runs.groundedness.median', 0, 50)),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'found {kpi:recall}, cited {kpi:cited}, grounded {kpi:grounded}'],
            ],
            'learn' => [],
            'deeper' => 'docs#modify',
        ],
    ],
];
