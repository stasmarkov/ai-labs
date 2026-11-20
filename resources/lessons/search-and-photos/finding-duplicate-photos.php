<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$run = 'photo-duplicates/2026-11-12/run.json';

$cutoff = Source::figure($run, 'cutoff.threshold', 2);
$old = Source::percent($run, 'curve.8.recall');
$recall = Source::percent($run, 'curve.2.recall');
$cropped = Source::percent($run, 'curve.2.recall_by_kind.cropped.recall');
$groups = Source::figure($run, 'groups.count');
$judged = Source::figure($run, 'groups.judged');
$picked = Source::percent($run, 'judging.models.0.picked_original.rate');
$grouping = Source::figure($run, 'clustering.clustering_ms');

return [
    'title' => 'Finding duplicate photos',
    'summary' => 'Group near-identical photos by their vectors, ask a vision model which photo of a group is best, and let a person review: the review only marks photos and never deletes one.',
    'minutes' => 11,
    'level' => 'intermediate',
    'guide' => 'photo-duplicates',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why the lab makes its own degraded copies.'],
        ['id' => 'lo-2', 'text' => 'Describe how photos become groups, and how a chain of links can join strangers.'],
        ['id' => 'lo-3', 'text' => 'Read the cutoff trade-off and name the copies that slip through.'],
        ['id' => 'lo-4', 'text' => 'Say what the vision model is asked, how its answer is checked and what its pick is worth.'],
        ['id' => 'lo-5', 'text' => 'Tell what the review marks, and what it never does.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'index', 'kind' => 'vector-db', 'label' => 'Photo index', 'x' => 130, 'y' => 150],
            ['id' => 'grouping', 'kind' => 'app', 'label' => 'Grouping', 'x' => 470, 'y' => 150],
            ['id' => 'queue', 'kind' => 'queue', 'label' => 'Vision queue', 'x' => 840, 'y' => 150],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Vision model', 'x' => 840, 'y' => 450],
            ['id' => 'reviewer', 'kind' => 'person', 'label' => 'Reviewer', 'x' => 470, 'y' => 450],
        ],
        'edges' => [
            ['id' => 'near', 'from' => 'index', 'to' => 'grouping'],
            ['id' => 'enqueue', 'from' => 'grouping', 'to' => 'queue'],
            ['id' => 'run', 'from' => 'queue', 'to' => 'model'],
            ['id' => 'pick', 'from' => 'model', 'to' => 'reviewer'],
            ['id' => 'mark', 'from' => 'reviewer', 'to' => 'grouping'],
        ],
    ],
    'kpis' => [
        ['id' => 'cutoff', 'label' => 'Cutoff chosen by the test', 'format' => 'count', 'better' => 'none'],
        ['id' => 'old', 'label' => 'True pairs found at the old cutoff', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'recall', 'label' => 'True pairs found at the chosen cutoff', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'cropped', 'label' => 'Cropped copies found at the chosen cutoff', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'groups', 'label' => 'Groups made', 'format' => 'count', 'better' => 'none'],
        ['id' => 'judged', 'label' => 'Groups with one original, judged in the test', 'format' => 'count', 'better' => 'none'],
        ['id' => 'picked', 'label' => 'Groups where the first model picked the original', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'grouping', 'label' => 'Time to group every photo', 'format' => 'ms', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'The same photo, uploaded again',
            'body' => [
                'A seller uploads the same photo more than once, a little smaller, a little darker, or cropped. An exact comparison of the files finds nothing, because the bytes differ.',
                'The marketplace wants to show the best copy and let a person remove the rest, without a model ever deleting anything on its own.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A near copy is not an equal file. Compare what the photos show.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'photo vectors'],
                    'grouping' => ['badge' => 'no way to tell copies'],
                    'queue' => ['badge' => null],
                    'model' => ['badge' => null],
                    'reviewer' => ['badge' => 'sees every upload'],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 1]],
                ],
            ],
            'kpis' => [
                'cutoff' => '—',
                'old' => '—',
                'recall' => '—',
                'cropped' => '—',
                'groups' => '—',
                'judged' => '—',
                'picked' => '—',
                'grouping' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'a resized or cropped copy is not an exact match'],
            ],
            'learn' => [],
            'deeper' => 'photo-duplicates#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Make copies you can check',
            'body' => [
                'The imported photos are all different shots, and exact copies were removed on import. So one command writes degraded copies of chosen photos on the same listing: scaled down and back up, recompressed, cropped, darkened and mirrored.',
                'Each copy remembers which photo it came from and how it was changed. That is an answer key, so precision and recall can be measured and not guessed.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'To measure a detector, make the thing it should detect, and keep the answer.'],
            'state' => [
                'nodes' => [
                    'index' => ['badge' => 'originals and their copies'],
                    'grouping' => ['badge' => null],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'copies with a known original and a known change'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'photo-duplicates#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Join close vectors into groups',
            'body' => [
                'Every photo with a vector reads its nearest neighbours, and each pair at or above the cutoff becomes a link. Photos joined by a chain of links form one group, found with union-find.',
                'Pairs on the same listing count, because duplicates are usually re-uploads. A group that grows past a cap is cut at its weakest link until every part fits.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A cap on group size keeps a review page readable.'],
            'state' => [
                'nodes' => [
                    'index' => ['status' => 'busy', 'load' => 40, 'badge' => 'nearest neighbours'],
                    'grouping' => ['status' => 'busy', 'load' => 40, 'badge' => 'links above the cutoff'],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 4]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'connected photos form one group'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'photo-duplicates#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Where to cut',
            'body' => [
                'No cutoff finds every copy and joins nothing else. The test swept the cutoff and chose {kpi:cutoff}, where {kpi:recall} of the true pairs were found and a few different photos were joined too.',
                'The earlier default, picked by feel, found only {kpi:old} of the true pairs. The sweep moved the cutoff down.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Do not pick a cutoff by feel. Sweep it against pairs you know.'],
            'state' => [
                'nodes' => [
                    'index' => ['status' => 'ok', 'load' => 10, 'badge' => null],
                    'grouping' => ['status' => 'ok', 'load' => 20, 'badge' => 'cutoff from a sweep'],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [
                'cutoff' => $cutoff,
                'old' => $old,
                'recall' => $recall,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'true pairs found at the chosen cutoff: {kpi:recall}'],
                ['sign' => '~', 'text' => 'true pairs found at the old cutoff: {kpi:old}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'photo-duplicates#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'Crops slip through',
            'body' => [
                'Cropping changes what the photo shows, so its vector drifts furthest from the original. At the chosen cutoff {kpi:cropped} of the cropped copies were found. Mirrored, darkened and downscaled copies were all found.',
                'A lower cutoff catches more copies and joins more different photos. A second signal, such as a perceptual hash, is the usual answer.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Read recall per kind of change. An average hides the kind that fails.'],
            'state' => [
                'nodes' => [
                    'grouping' => ['badge' => 'misses some crops'],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 2]],
                ],
            ],
            'kpis' => [
                'cropped' => $cropped,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'cropped copies found at the chosen cutoff: {kpi:cropped}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'photo-duplicates#support',
        ],
        [
            'role' => 'careful',
            'title' => 'A chain can join strangers',
            'body' => [
                'Links are transitive: A is close to B, B is close to C, and C lands in A\'s group though A and C are not close. The test made {kpi:groups} groups, and only {kpi:judged} held one original with its copies.',
                'The rest held several originals, and the test left them out of its judging because no single photo is the right pick. Review large groups first.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Connected is not the same as alike. Look at a group before you trust it.'],
            'state' => [
                'nodes' => [
                    'grouping' => ['badge' => 'chains join some strangers'],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 3]],
                ],
            ],
            'kpis' => [
                'groups' => $groups,
                'judged' => $judged,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'groups made: {kpi:groups}, with one original: {kpi:judged}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'photo-duplicates#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Show the model the whole group',
            'body' => [
                'The vision model gets every photo of a group in one request, each introduced by its id. It must answer with the id of the best photo, short reasons, and a problem for every other photo.',
                'The answer is checked in code. An id that is not in the group is rejected and the call is repeated, up to a cap on attempts, and every attempt is a logged run.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Never accept an id from a model without checking that it exists in what you sent.'],
            'state' => [
                'nodes' => [
                    'grouping' => ['badge' => 'queues one job per group'],
                    'queue' => ['status' => 'busy', 'load' => 40, 'badge' => 'waiting groups'],
                    'model' => ['status' => 'busy', 'load' => 60, 'badge' => 'sees the whole group'],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 0]],
                    'enqueue' => ['packets' => ['request' => 2]],
                    'run' => ['packets' => ['request' => 2]],
                    'pick' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a best photo, reasons and a problem for each other photo'],
                ['sign' => '+', 'text' => 'an id outside the group is rejected and asked again'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'photo-duplicates#works',
        ],
        [
            'role' => 'careful',
            'title' => 'The pick is a judgement',
            'body' => [
                'The original is the expected pick, since every copy is degraded. Of the two models tried, the first picked the untouched original in {kpi:picked} of the groups it judged and the other picked more, still not most, although every answer had a valid shape.',
                'A darkened or recompressed copy can look as good, so the rate is a floor for the quality of the pick. The weakness is the choice, not the format.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A valid answer is not a correct one. Measure the choice, not only the shape.'],
            'state' => [
                'nodes' => [
                    'queue' => ['status' => 'ok', 'load' => 10, 'badge' => null],
                    'model' => ['status' => 'ok', 'load' => 30, 'badge' => 'valid, maybe not original'],
                    'reviewer' => ['badge' => 'sees the pick and the reasons'],
                ],
                'edges' => [
                    'enqueue' => ['packets' => ['request' => 0]],
                    'run' => ['packets' => ['request' => 0]],
                    'pick' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [
                'picked' => $picked,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'groups where the first model picked the original: {kpi:picked}'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'photo-duplicates#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'A person reviews, and nothing is deleted',
            'body' => [
                'Keep this one marks the kept photo and the others, and stores whether the model\'s pick or the reviewer\'s own was kept. Undo takes the marks back. A photo that is not in the group is refused.',
                'No photo row and no file is ever deleted, and a rebuild keeps every group that was reviewed, with its marks.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'An action a model can trigger should be one a person can undo.'],
            'state' => [
                'nodes' => [
                    'model' => ['load' => 0, 'badge' => null],
                    'reviewer' => ['status' => 'busy', 'load' => 40, 'badge' => 'keeps one, marks the rest'],
                    'grouping' => ['badge' => 'stores the marks'],
                ],
                'edges' => [
                    'pick' => ['packets' => ['token' => 0]],
                    'mark' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a review marks photos and can be undone'],
                ['sign' => '+', 'text' => 'reviewed groups survive a rebuild'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'photo-duplicates#support',
        ],
        [
            'role' => 'useful',
            'title' => 'Group on demand, judge in the background',
            'body' => [
                'Grouping every photo took {kpi:grouping} in the test, so it can run on demand. The model call is slow, so each group is judged by a job on its own queue, and a web request never waits for it.',
                'A rebuild replaces the groups nobody reviewed and leaves the reviewed ones as they are.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Run the cheap step in the request and the slow step on a queue.'],
            'state' => [
                'nodes' => [
                    'reviewer' => ['status' => 'ok', 'load' => 10, 'badge' => null],
                    'grouping' => ['status' => 'ok', 'load' => 30, 'badge' => 'groups on demand'],
                    'queue' => ['status' => 'ok', 'load' => 20, 'badge' => 'judges in the background'],
                    'index' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'mark' => ['packets' => ['request' => 0]],
                    'near' => ['packets' => ['embedding' => 2]],
                    'enqueue' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'grouping' => $grouping,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'time to group every photo: {kpi:grouping}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'photo-duplicates#numbers',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Make copies you can check, join close vectors into groups, and choose the cutoff by a sweep: {kpi:cutoff} found {kpi:recall} of the true pairs, and crops were the weak kind at {kpi:cropped}.',
                'Let a vision model pick, check its answer in code, and judge it by the original it should find. Then let a person review. The marks can be undone, and nothing is deleted.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Find with vectors, suggest with a model, decide with a person.'],
            'state' => [
                'nodes' => [
                    'index' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                    'grouping' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'queue' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                    'model' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'reviewer' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                ],
                'edges' => [
                    'near' => ['packets' => ['embedding' => 1]],
                    'enqueue' => ['packets' => ['request' => 1]],
                    'run' => ['packets' => ['request' => 1]],
                    'pick' => ['packets' => ['token' => 1]],
                    'mark' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'cutoff' => Source::uncompared($cutoff),
                'old' => Source::uncompared($old),
                'recall' => Source::uncompared($recall),
                'cropped' => Source::uncompared($cropped),
                'groups' => Source::uncompared($groups),
                'judged' => Source::uncompared($judged),
                'picked' => Source::uncompared($picked),
                'grouping' => Source::uncompared($grouping),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'groups of near copies with a person in charge of the result'],
                ['sign' => '~', 'text' => 'true pairs found: {kpi:old} at the old cutoff, {kpi:recall} at the chosen one'],
            ],
            'learn' => [],
            'deeper' => 'photo-duplicates#works',
        ],
    ],
];
