<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$bench = '2026-11-02-embeddings/search-bench.json';

$embed = Source::figure($bench, 'embed_ms.p50', 1);
$database = Source::figure($bench, 'ef_sweep.400.db_ms_p50', 1);
$few = Source::percent($bench, 'ef_sweep.40.recall_at_10');
$many = Source::percent($bench, 'ef_sweep.400.recall_at_10');
$estates = Source::figure($bench, 'probe.body_types.estate');

return [
    'title' => 'Search by meaning',
    'summary' => 'Find cars by what a visitor means and not by the words they typed: turn the question into a vector, find the nearest listings in an index, and see what the index trades away for speed.',
    'minutes' => 11,
    'level' => 'intermediate',
    'guide' => 'semantic',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Say what a query becomes before the search, and why similar meanings land close together.'],
        ['id' => 'lo-2', 'text' => 'Explain how the index finds the nearest listings and what the page shows for each.'],
        ['id' => 'lo-3', 'text' => 'Tell why the index is approximate, and what raising the number of candidates buys and costs.'],
        ['id' => 'lo-4', 'text' => 'Explain why template descriptions crowd the vectors, and why the matches can still be right.'],
        ['id' => 'lo-5', 'text' => 'Name how a search can fail or mislead: vectors of another model, and exact words.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 130, 'y' => 300],
            ['id' => 'service', 'kind' => 'app', 'label' => 'Search service', 'x' => 480, 'y' => 300],
            ['id' => 'embedder', 'kind' => 'model', 'label' => 'Embedding model', 'x' => 480, 'y' => 90],
            ['id' => 'index', 'kind' => 'vector-db', 'label' => 'Vector index', 'x' => 870, 'y' => 300],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'service'],
            ['id' => 'embed', 'from' => 'service', 'to' => 'embedder'],
            ['id' => 'vector', 'from' => 'embedder', 'to' => 'service'],
            ['id' => 'lookup', 'from' => 'service', 'to' => 'index'],
            ['id' => 'nearest', 'from' => 'index', 'to' => 'service'],
            ['id' => 'show', 'from' => 'service', 'to' => 'browser'],
        ],
    ],
    'kpis' => [
        ['id' => 'embed', 'label' => 'Embedding the query (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'database', 'label' => 'Index search at the app setting (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'few', 'label' => 'True nearest found with few candidates', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'many', 'label' => 'True nearest found at the app setting', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'estates', 'label' => 'Estates among the nearest for a family-car query', 'format' => 'count', 'better' => 'none'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'Words are not meaning',
            'body' => [
                'A visitor types a family car for winter. A keyword search looks for those words in the listing text, and a listing that says all-wheel drive and estate shares none of them.',
                'The visitor means a kind of car. The search only sees the letters they typed.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Matching words finds the same words. It does not find the same idea.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'a family car for winter'],
                    'service' => ['badge' => 'compares words'],
                    'embedder' => ['badge' => 'not used'],
                    'index' => ['badge' => 'not used'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'embed' => '—',
                'database' => '—',
                'few' => '—',
                'many' => '—',
                'estates' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'a listing with other words for the same idea is missed'],
            ],
            'learn' => [],
            'deeper' => 'semantic#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Turn the question into a vector',
            'body' => [
                'The query goes to the embedding model, which answers with a long list of numbers. Texts with a similar meaning get lists that point the same way, whatever words they use.',
                'The call is logged as an embeddings query run. In the search test it took {kpi:embed} at the median.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A vector is a position. Close positions mean close meanings.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => 'embeds the question'],
                    'embedder' => ['status' => 'busy', 'load' => 50, 'badge' => 'writes a vector'],
                    'index' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 0]],
                    'embed' => ['packets' => ['request' => 1]],
                    'vector' => ['packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [
                'embed' => $embed,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the question becomes a vector of its meaning'],
                ['sign' => '~', 'text' => 'embedding the query, median: {kpi:embed}'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'semantic#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Check the vector before using it',
            'body' => [
                'A reply of the wrong length is refused. Comparing vectors of different lengths would not fail loudly, it would return nonsense that looks valid.',
                'If the embedding model is not running, the search answers that it is unavailable, and the first call after a pause is slow because the model has to load.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Check the shape of every vector. A wrong one produces confident wrong answers.'],
            'state' => [
                'nodes' => [
                    'embedder' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'service' => ['badge' => 'checks the length'],
                ],
                'edges' => [
                    'embed' => ['packets' => ['request' => 0]],
                    'vector' => ['packets' => ['embedding' => 1, 'failure' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a vector of the wrong length is refused'],
            ],
            'learn' => ['lo-1', 'lo-5'],
            'deeper' => 'semantic#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Find the nearest ten',
            'body' => [
                'Every listing has a stored vector built from its description. The index holds them in a graph, and the search walks it to the ten nearest by cosine distance.',
                'Each match comes back with a similarity, one minus the distance, and the page lists the matches in order.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Show the similarity next to each match, so a weak match is visible as one.'],
            'state' => [
                'nodes' => [
                    'embedder' => ['load' => 0, 'badge' => null],
                    'service' => ['badge' => 'asks for the nearest ten'],
                    'index' => ['status' => 'busy', 'load' => 40, 'badge' => 'walks the graph'],
                ],
                'edges' => [
                    'vector' => ['packets' => ['embedding' => 0, 'failure' => 0]],
                    'lookup' => ['packets' => ['embedding' => 2]],
                    'nearest' => ['packets' => ['request' => 2]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the ten nearest listings come back, best first, each with a similarity'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'semantic#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Read where the time went',
            'body' => [
                'The response carries a timing for the embedding and one for the index. With the app setting the index search took {kpi:database} at the median, less than the {kpi:embed} spent embedding the question.',
                'If a search is slow, read these two first: a slow embedding means the model, a slow index means the graph walk.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Time each stage on its own. One total hides which part to fix.'],
            'state' => [
                'nodes' => [
                    'service' => ['status' => 'ok', 'badge' => 'times each stage'],
                    'index' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'lookup' => ['packets' => ['embedding' => 0]],
                    'nearest' => ['packets' => ['request' => 0]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'database' => $database,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'index search at the app setting, median: {kpi:database}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'semantic#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'The index is approximate',
            'body' => [
                'The graph walk keeps a number of candidates, set by ef_search, and it can skip a neighbour that an exact scan would find. Recall measures how many of the true ten nearest the index returned.',
                'With few candidates the recall in the test was {kpi:few}, so more than half of the true neighbours were missing.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Fast indexes guess. Measure the guess against an exact scan before you trust it.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'few candidates, misses'],
                    'service' => ['badge' => null],
                ],
                'edges' => [
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'few' => $few,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'true nearest found with few candidates: {kpi:few}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'semantic#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Buy recall back with candidates',
            'body' => [
                'Raising ef_search makes the walk keep more candidates. At the app setting the recall rose to {kpi:many}, for {kpi:database} of index time.',
                'The query also forbids a full scan for its own transaction. Left free, the planner can swap the index for a slow exact scan and report a perfect score that no index delivers.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A perfect recall on a fast index is a reason to read the query plan.'],
            'state' => [
                'nodes' => [
                    'index' => ['status' => 'busy', 'load' => 60, 'badge' => 'more tried, fewer misses'],
                ],
                'edges' => [
                    'lookup' => ['packets' => ['embedding' => 3]],
                    'nearest' => ['packets' => ['request' => 3]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'many' => $many,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'true nearest found at the app setting: {kpi:many}'],
                ['sign' => '~', 'text' => 'index search at the app setting, median: {kpi:database}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'semantic#modify',
        ],
        [
            'role' => 'careful',
            'title' => 'Template text crowds the vectors',
            'body' => [
                'The descriptions are written from a template, so every vector is close to every other, and the nearest neighbours of a query can sit within a hair of each other. That is why a graph walk with few candidates misses so many.',
                'It also explains why the matches stay good: for a family car with a big boot, {kpi:estates} of the ten nearest were estates, even when the exact ids differed from an exact scan.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Low recall on crowded data is not always a bad result. Check what the misses look like.'],
            'state' => [
                'nodes' => [
                    'index' => ['status' => 'ok', 'load' => 30, 'badge' => 'crowded, all close'],
                    'browser' => ['badge' => 'family car, big boot'],
                ],
                'edges' => [
                    'nearest' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'estates' => $estates,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'estates among the nearest for a family-car query: {kpi:estates}'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'semantic#support',
        ],
        [
            'role' => 'careful',
            'title' => 'Vectors of another model do not mix',
            'body' => [
                'Vectors from two models cannot be compared. If every listing was embedded by another model than the one that embeds the query, the search is refused with a message to embed the listings again.',
                'A single exact word, such as a model name, can also do worse than a sentence about the car, because vectors measure meaning and not spelling.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Store the model name next to every vector, and add a keyword search where exact terms matter.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'service' => ['badge' => 'refuses another model\'s vectors'],
                    'index' => ['badge' => 'tagged with its model'],
                ],
                'edges' => [
                    'show' => ['packets' => ['failure' => 1]],
                    'nearest' => ['packets' => ['request' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a search over vectors of another model is refused, not answered'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'semantic#support',
        ],
        [
            'role' => 'useful',
            'title' => 'Tune it with a re-run',
            'body' => [
                'To change the trade, change the number of candidates and run the semantic bench again. Compare the recall and the index time, not one of them alone.',
                'To change the model, set the new one and embed every listing again. A listing is embedded again when its model name or its text changed.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Change one setting at a time and keep the result file, so a later change has something to beat.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => null],
                    'index' => ['badge' => 'rebuilt for the new model'],
                ],
                'edges' => [
                    'show' => ['packets' => ['failure' => 0, 'request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'recall and time are measured again after each change'],
            ],
            'learn' => ['lo-3', 'lo-5'],
            'deeper' => 'semantic#modify',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Embed the question, check the vector, and ask the index for the nearest listings by cosine distance. The embedding took {kpi:embed} and the index {kpi:database} at the median.',
                'The index is approximate. Raising the candidates moved recall from {kpi:few} to {kpi:many}, and the data decides whether a miss matters. Keep the model name with every vector.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Search by meaning is a guess with a measured error. Measure it.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'service' => ['status' => 'ok', 'badge' => null],
                    'embedder' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'index' => ['status' => 'ok', 'load' => 40, 'badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'embed' => ['packets' => ['request' => 1]],
                    'vector' => ['packets' => ['embedding' => 1]],
                    'lookup' => ['packets' => ['embedding' => 1]],
                    'nearest' => ['packets' => ['request' => 1]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'embed' => Source::uncompared($embed),
                'database' => Source::uncompared($database),
                'few' => Source::uncompared($few),
                'many' => Source::uncompared($many),
                'estates' => Source::uncompared($estates),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a search by meaning with a measured recall'],
                ['sign' => '~', 'text' => 'true nearest found: {kpi:few} with few candidates, {kpi:many} at the app setting'],
            ],
            'learn' => [],
            'deeper' => 'semantic#numbers',
        ],
    ],
];
