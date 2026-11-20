<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$text = 'photo-search/2026-11-11/run.json';
$similar = 'photo-similar/2026-11-11/run.json';
$hybrid = 'photo-similar/2026-11-12/hybrid.json';

$precision = Source::percent($text, 'variants.template.metrics.precision');
$bare = Source::percent($text, 'variants.bare.metrics.precision');
$hits = Source::percent($text, 'variants.template.metrics.hit_rate');
$uncached = Source::figure($text, 'latency.uncached.p95_ms', 1);
$cached = Source::figure($text, 'latency.cached.p95_ms', 2);
$make = Source::percent($similar, 'metrics.precision_make');
$likeness = Source::percent($similar, 'metrics.precision_make_model');
$mixed = Source::percent($hybrid, 'best.precision_make_model');

return [
    'title' => 'Searching photos by text and likeness',
    'summary' => 'Search car photos with words and with another photo: one image encoder puts both in the same space, and the measured results show how good the matches are.',
    'minutes' => 12,
    'level' => 'intermediate',
    'guide' => 'photo-search',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why a photo and a sentence can be compared at all.'],
        ['id' => 'lo-2', 'text' => 'Describe what happens to a text query before and after the index search.'],
        ['id' => 'lo-3', 'text' => 'Read the measured precision and say what it does and does not prove.'],
        ['id' => 'lo-4', 'text' => 'Tell how more-like-this differs from text search, and which matches it drops.'],
        ['id' => 'lo-5', 'text' => 'Explain what the caption mix adds, and why a listing title must stay out of the test.'],
        ['id' => 'lo-6', 'text' => 'Say what the cache key holds and what a cache hit skips.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 130, 'y' => 300],
            ['id' => 'service', 'kind' => 'app', 'label' => 'Photo search', 'x' => 470, 'y' => 300],
            ['id' => 'encoder', 'kind' => 'model', 'label' => 'Image encoder', 'x' => 470, 'y' => 90],
            ['id' => 'index', 'kind' => 'vector-db', 'label' => 'Photo index', 'x' => 860, 'y' => 300],
            ['id' => 'cache', 'kind' => 'cache', 'label' => 'Result cache', 'x' => 470, 'y' => 510],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'service'],
            ['id' => 'encode', 'from' => 'service', 'to' => 'encoder'],
            ['id' => 'vector', 'from' => 'encoder', 'to' => 'service'],
            ['id' => 'lookup', 'from' => 'service', 'to' => 'index'],
            ['id' => 'nearest', 'from' => 'index', 'to' => 'service'],
            ['id' => 'show', 'from' => 'service', 'to' => 'browser'],
            ['id' => 'store', 'from' => 'service', 'to' => 'cache'],
            ['id' => 'hit', 'from' => 'cache', 'to' => 'service'],
        ],
    ],
    'kpis' => [
        ['id' => 'precision', 'label' => 'Average precision per query, text search', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'hits', 'label' => 'Queries with a relevant photo in the results', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'bare', 'label' => 'Relevant photos, bare query without the template', 'format' => 'percent', 'better' => 'none'],
        ['id' => 'uncached', 'label' => 'Text search time, slow end (not cached)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'cached', 'label' => 'Text search time, from the cache', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'make', 'label' => 'Similar photos with the same make', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'likeness', 'label' => 'Similar photos with the same make and model', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'mixed', 'label' => 'Same make and model with the caption mix', 'format' => 'percent', 'better' => 'higher'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A photo has no words',
            'body' => [
                'A visitor types a red sports car and wants the photos that show one. The photos are files of pixels. The listing text may say nothing about colour or shape, and a keyword search cannot read a picture.',
                'The same visitor also opens a photo and asks for more cars like it. That is a question about looks, and words do not answer it either.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A search that only reads text cannot see what a photo shows.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'a red sports car'],
                    'service' => ['badge' => 'finds no words in a photo'],
                    'encoder' => ['badge' => 'not used'],
                    'index' => ['badge' => 'not used'],
                    'cache' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'precision' => '—',
                'hits' => '—',
                'bare' => '—',
                'uncached' => '—',
                'cached' => '—',
                'make' => '—',
                'likeness' => '—',
                'mixed' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'nothing in a photo can be matched with a word'],
            ],
            'learn' => [],
            'deeper' => 'photo-search#works',
        ],
        [
            'role' => 'solution',
            'title' => 'One encoder, a vector for every photo',
            'body' => [
                'A small image encoder runs next to the app as its own container. Every listing photo is turned into a vector, in batches on a queue, so a web request never waits for the encoder.',
                'Each vector is scaled to a fixed length and keeps the label of the model that made it, so a vector from another model is never compared with it.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Do the slow work once, in the background, and store the result.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'service' => ['badge' => 'embeds each photo on a queue'],
                    'encoder' => ['status' => 'busy', 'load' => 60, 'badge' => 'image side'],
                    'index' => ['badge' => 'one vector per photo'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 0]],
                    'encode' => ['packets' => ['request' => 2]],
                    'vector' => ['packets' => ['embedding' => 2]],
                    'lookup' => ['packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'every photo has a stored vector and the label of its model'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'photo-search#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The words use the other side of the encoder',
            'body' => [
                'The same encoder has a text side, trained on pairs of pictures and captions, so a sentence and a photo land in one space. The query is first checked against the injection patterns, and a match is refused before the encoder is called.',
                'Then it is wrapped in a short template, a photo of the words, because the encoder matches captions better than bare words.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'One model with two sides is what makes a sentence comparable with a photo.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'a red sports car'],
                    'service' => ['badge' => 'checks, then wraps the words'],
                    'encoder' => ['status' => 'busy', 'load' => 50, 'badge' => 'text side'],
                    'index' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'encode' => ['packets' => ['request' => 1]],
                    'vector' => ['packets' => ['embedding' => 1]],
                    'lookup' => ['packets' => ['embedding' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a sentence becomes a vector in the same space as the photos'],
            ],
            'learn' => ['lo-1', 'lo-2'],
            'deeper' => 'photo-search#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The nearest photos, one per listing',
            'body' => [
                'The index returns the nearest photos by cosine distance. The service keeps the best photo of each listing, drops those below a minimum score and shows a short list.',
                'An empty page says why: no vectors yet, a refused query, an encoder that is down, or a query nothing matched each have their own message.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'An empty result needs a reason. Show which of the causes it was.'],
            'state' => [
                'nodes' => [
                    'encoder' => ['status' => 'ok', 'load' => 0, 'badge' => null],
                    'service' => ['badge' => 'one photo per listing'],
                    'index' => ['status' => 'busy', 'load' => 40, 'badge' => 'nearest photos'],
                ],
                'edges' => [
                    'encode' => ['packets' => ['request' => 0]],
                    'vector' => ['packets' => ['embedding' => 0]],
                    'lookup' => ['packets' => ['embedding' => 2]],
                    'nearest' => ['packets' => ['request' => 2]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a short list of photos, each from a different listing'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'photo-search#works',
        ],
        [
            'role' => 'careful',
            'title' => 'About half the photos are right',
            'body' => [
                'The test ran labelled queries against the embedded photos. on average per query {kpi:precision} of the returned photos were relevant, and {kpi:hits} of the queries had a relevant photo among the results.',
                'Colour labels come from a vision model, not from people, so they are a guide and not ground truth.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A score is only as good as its labels. Say where the labels came from.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => null],
                    'index' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'lookup' => ['packets' => ['embedding' => 0]],
                    'nearest' => ['packets' => ['request' => 0]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'precision' => $precision,
                'hits' => $hits,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'average precision per query: {kpi:precision}'],
                ['sign' => '~', 'text' => 'queries with a relevant photo in the results: {kpi:hits}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'photo-search#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'The template did not win here',
            'body' => [
                'The template is a versioned prompt, and the run row names its version. Against the bare query the precision was {kpi:bare}, against {kpi:precision} with the template.',
                'The set is small, so one query moves the result a lot. Read it as a tie, not as a ranking.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A small test set can only separate big differences. Do not pick a winner from a tie.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => 'template against bare words'],
                ],
                'edges' => [
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'bare' => $bare,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'bare query, relevant photos: {kpi:bare}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'photo-search#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'Cache the search by a hash',
            'body' => [
                'The cache key holds the model label, the index generation, the prompt version and a hash of the query, never the text itself. A hit skips the encoder and writes no run row, and a new batch of photo vectors moves the generation and drops every stored search.',
                'At the slow end of the test an uncached search took {kpi:uncached}, and a cached one {kpi:cached}.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Put the model and the index generation in the key, so an index change cannot serve an old answer.'],
            'state' => [
                'nodes' => [
                    'service' => ['status' => 'ok', 'badge' => 'keys the result by a hash'],
                    'cache' => ['status' => 'ok', 'load' => 30, 'badge' => 'holds the list'],
                ],
                'edges' => [
                    'store' => ['packets' => ['request' => 1]],
                    'hit' => ['packets' => ['cache-hit' => 2]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'uncached' => $uncached,
                'cached' => $cached,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a repeated query is answered from the cache, in {kpi:cached}'],
                ['sign' => '~', 'text' => 'uncached search, slow end: {kpi:uncached}'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'photo-search#numbers',
        ],
        [
            'role' => 'solution',
            'title' => 'More like this, from a photo',
            'body' => [
                'For a photo that is already stored, the index is asked for its nearest neighbours with the photo\'s own vector, and no encoder call is needed. Every photo of the same listing is dropped, one photo per other listing is kept, and a match too close to be anything but a copy is left out.',
                'Matches below a minimum score are dropped too, and the cutoffs are calibrated, because the encoder scores any two car photos high.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A stored vector is a query that costs no model call.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'more like this photo'],
                    'service' => ['badge' => 'uses the photo\'s own vector'],
                    'encoder' => ['badge' => 'not called'],
                    'index' => ['status' => 'busy', 'load' => 30, 'badge' => null],
                    'cache' => ['status' => 'ok', 'load' => 0, 'badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'lookup' => ['packets' => ['embedding' => 2]],
                    'nearest' => ['packets' => ['request' => 2]],
                    'store' => ['packets' => ['request' => 0]],
                    'hit' => ['packets' => ['cache-hit' => 0]],
                    'show' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'similar photos come from a stored vector, with no encoder call'],
                ['sign' => '+', 'text' => 'copies and weak matches are left out'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'similar-photos#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Same make is easier than same model',
            'body' => [
                'The test asked whether a similar photo came from a listing of the same make as the photo it started from. {kpi:make} did, and {kpi:likeness} shared the make and the model.',
                'Part of the gap is the label: a photo of another model of the same brand is a plausible neighbour, even if the test counts it as a miss.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A strict label turns a reasonable neighbour into a failure. Say what counts as relevant.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'service' => ['badge' => 'checks make and model'],
                    'index' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 0]],
                    'lookup' => ['packets' => ['embedding' => 0]],
                    'nearest' => ['packets' => ['request' => 0]],
                ],
            ],
            'kpis' => [
                'make' => $make,
                'likeness' => $likeness,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'similar photos with the same make: {kpi:make}'],
                ['sign' => '~', 'text' => 'similar photos with the same make and model: {kpi:likeness}'],
            ],
            'learn' => ['lo-3', 'lo-4'],
            'deeper' => 'similar-photos#numbers',
        ],
        [
            'role' => 'solution',
            'title' => 'Mix in what the caption says',
            'body' => [
                'An image vector misses what a photo shows but does not look like. A caption from the vision model is encoded with the same text side, and the candidates are scored as a weighted mix of the image and the caption similarity.',
                'A pair without a caption vector keeps its image similarity and is flagged as image only. With the best weight the same make and model went from {kpi:likeness} to {kpi:mixed}, a small gain.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A second signal helps when the first one is blind to something. Keep the old result beside it in the same run.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'service' => ['badge' => 'mixes image and caption'],
                    'encoder' => ['status' => 'ok', 'load' => 20, 'badge' => 'text side for the caption'],
                    'index' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'encode' => ['packets' => ['request' => 1]],
                    'vector' => ['packets' => ['embedding' => 1]],
                    'lookup' => ['packets' => ['embedding' => 1]],
                    'nearest' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'mixed' => $mixed,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'same make and model with the caption mix: {kpi:mixed}'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'similar-photos#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'A listing title would look perfect',
            'body' => [
                'The listing title holds the make and the model that the test grades. A text vector made from the title finds the same make and model by construction, so the score jumps without the photos adding anything.',
                'The test runs the title once, marks it as leaking the answer, and never picks a weight from it. The caption is the only text source the app uses.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Keep the answer out of the input. A leak makes a bad method look excellent.'],
            'state' => [
                'nodes' => [
                    'service' => ['badge' => 'captions only, never titles'],
                    'encoder' => ['load' => 0, 'badge' => null],
                ],
                'edges' => [
                    'encode' => ['packets' => ['request' => 0]],
                    'vector' => ['packets' => ['embedding' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'a title run is reported apart and never chosen'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'similar-photos#support',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'One encoder with two sides puts photos and words in one space. Photos are embedded once on a queue, words are wrapped and encoded on demand, and the nearest photos come back one per listing.',
                'The matches are useful and not perfect: text search averaged {kpi:precision} precision per query and similar photos {kpi:likeness}. Cache by a hash, label every vector, and keep the answer out of the test.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Search by looks needs one space for both sides and an honest test.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'service' => ['status' => 'ok', 'badge' => null],
                    'encoder' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                    'index' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'cache' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'encode' => ['packets' => ['request' => 1]],
                    'vector' => ['packets' => ['embedding' => 1]],
                    'lookup' => ['packets' => ['embedding' => 1]],
                    'nearest' => ['packets' => ['request' => 1]],
                    'show' => ['packets' => ['request' => 1]],
                    'store' => ['packets' => ['request' => 1]],
                    'hit' => ['packets' => ['cache-hit' => 1]],
                ],
            ],
            'kpis' => [
                'precision' => Source::uncompared($precision),
                'hits' => Source::uncompared($hits),
                'bare' => Source::uncompared($bare),
                'uncached' => Source::uncompared($uncached),
                'cached' => Source::uncompared($cached),
                'make' => Source::uncompared($make),
                'likeness' => Source::uncompared($likeness),
                'mixed' => Source::uncompared($mixed),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'search by words and by likeness over the same photo vectors'],
                ['sign' => '~', 'text' => 'relevant results: {kpi:precision} by text, {kpi:likeness} by likeness'],
            ],
            'learn' => [],
            'deeper' => 'photo-search#numbers',
        ],
    ],
];
