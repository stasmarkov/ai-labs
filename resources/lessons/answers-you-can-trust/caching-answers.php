<?php

declare(strict_types=1);

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$latency = 'docs-cache/2026-11-03/latency/hit-miss.json';
$mixed = 'docs-cache/2026-11-03/hit-miss/hit-miss.json';
$pairs = 'docs-cache/2026-11-03/run/calibration.json';

$figure = static fn (string $file, string $path, int $round = 0): array => ['file' => $file, 'path' => $path, 'round' => $round];
$seconds = static fn (string $file, string $path): array => ['file' => $file, 'path' => $path, 'scale' => 0.001, 'round' => 1];
$percent = static fn (string $file, string $path): array => ['file' => $file, 'path' => $path, 'scale' => 100, 'round' => 0];
$uncompared = static fn (array $value): array => [...$value, 'trend' => false];

return [
    'title' => 'Caching answers',
    'summary' => 'Reuse an answer for a question that was already asked, see why a reworded question is the hard case, and know when not to store.',
    'minutes' => 9,
    'level' => 'intermediate',
    'guide' => 'chat',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain what an exact cache hit saves and what the key is made of.'],
        ['id' => 'lo-2', 'text' => 'Say why a reworded question needs a similarity threshold and why the threshold is risky.'],
        ['id' => 'lo-3', 'text' => 'Name the answers that must not be stored.'],
        ['id' => 'lo-4', 'text' => 'Say when a stored answer stops being served.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'visitor', 'kind' => 'browser', 'label' => 'Visitor', 'x' => 150, 'y' => 300],
            ['id' => 'app', 'kind' => 'app', 'label' => 'The app', 'x' => 500, 'y' => 300],
            ['id' => 'cache', 'kind' => 'cache', 'label' => 'Answer cache', 'x' => 850, 'y' => 130],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model', 'x' => 850, 'y' => 470],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'visitor', 'to' => 'app'],
            ['id' => 'to-cache', 'from' => 'app', 'to' => 'cache'],
            ['id' => 'from-cache', 'from' => 'cache', 'to' => 'app'],
            ['id' => 'to-model', 'from' => 'app', 'to' => 'model'],
            ['id' => 'from-model', 'from' => 'model', 'to' => 'app'],
        ],
    ],
    'kpis' => [
        ['id' => 'fresh', 'label' => 'Fresh answer, median', 'format' => 's', 'better' => 'lower'],
        ['id' => 'exact', 'label' => 'Exact repeat, median', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'semantic', 'label' => 'Reworded repeat, median', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'served', 'label' => 'Requests served from the cache', 'format' => 'percent', 'better' => 'higher'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'The same question, the same wait',
            'body' => [
                'People ask the docs the same things again and again. Each time the app searches, builds a prompt and waits for a model to write the whole answer from the start.',
                'A fresh answer took {kpi:fresh} at the median, and the second visitor with the same question pays all of it again.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'An answer you kept can be served again for almost nothing. One you threw away is paid for twice.'],
            'state' => [
                'nodes' => [
                    'visitor' => ['load' => 10],
                    'app' => ['load' => 10],
                    'cache' => ['status' => 'hidden'],
                    'model' => ['status' => 'busy', 'load' => 70, 'badge' => 'writes it again'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-cache' => ['visible' => false],
                    'from-cache' => ['visible' => false],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'fresh' => $seconds($latency, 'paths.fresh.median_ms_all'),
                'exact' => '—',
                'semantic' => '—',
                'served' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'every repeat pays the full {kpi:fresh}'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'chat#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Look it up first',
            'body' => [
                'A finished answer is stored under a key made from the question. The next time the same question arrives, the app looks the key up before it searches or calls the model.',
                'A repeat of the same question was served in {kpi:exact} at the median. That is a lookup and a replay of stored text.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Ask the cache before the expensive work, not after it.'],
            'state' => [
                'nodes' => [
                    'cache' => ['status' => 'ok', 'load' => 20, 'badge' => 'looks the key up'],
                    'model' => ['status' => 'ok', 'load' => 0, 'badge' => 'not called'],
                    'app' => ['badge' => 'replays the stored answer'],
                ],
                'edges' => [
                    'to-cache' => ['visible' => true, 'packets' => ['request' => 1]],
                    'from-cache' => ['visible' => true, 'packets' => ['cache-hit' => 2]],
                    'to-model' => ['packets' => ['request' => 0]],
                    'from-model' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [
                'exact' => $figure($latency, 'paths.exact.median_ms_by_path.exact'),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'an exact repeat in {kpi:exact}'],
                ['sign' => '+', 'text' => 'the model is not called'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'chat#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'What the key is made of',
            'body' => [
                'The question is tidied first: lower case, trimmed, runs of spaces collapsed and trailing question marks, exclamation marks and full stops removed. Two spellings of one question share a key.',
                'The key also holds the prompt version, the answer model, the embedding model and the version of the docs index. Change any of them and the old key matches nothing.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Put in the key everything that would change the answer. Then a stale answer simply is not found.'],
            'state' => [
                'nodes' => [
                    'cache' => ['badge' => 'key: question and four versions'],
                ],
                'edges' => [
                    'to-cache' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a key from the tidied question and four versions'],
            ],
            'learn' => ['lo-1', 'lo-4'],
            'deeper' => 'chat#works',
        ],
        [
            'role' => 'problem',
            'title' => 'A reworded question misses',
            'body' => [
                'Most repeats are not word for word. "How do I stop two runs overlapping?" and "Prevent a job from running twice" ask the same thing and share almost no key.',
                'So on a miss the question is turned into a vector, and the nearest stored question is used if it is similar enough. A reworded repeat that hit this way took {kpi:semantic} at the median, one embedding and one lookup instead of a model run.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A semantic cache costs one embedding per miss. It pays for itself only when rewordings are common.'],
            'state' => [
                'nodes' => [
                    'cache' => ['badge' => 'nearest stored question'],
                    'app' => ['badge' => 'embeds the question'],
                ],
                'edges' => [
                    'to-cache' => ['packets' => ['request' => 1, 'embedding' => 2]],
                    'from-cache' => ['packets' => ['cache-hit' => 1]],
                ],
            ],
            'kpis' => [
                'semantic' => $uncompared($figure($latency, 'paths.paraphrase.median_ms_by_path.semantic')),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a reworded repeat served in {kpi:semantic}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'chat#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Close in meaning is not the same question',
            'body' => [
                'Two questions can share most of their words and ask for different things, such as how to retry a job and how to delete one. A wrong cached answer comes back with valid-looking citations.',
                'On a set of labelled pairs the closest near-miss scored {num:'.$pairs.'#near_miss.max|count|2}, higher than the closest true paraphrase, {num:'.$pairs.'#paraphrase.max|count|2}. No threshold separates the two groups.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Similarity measures closeness, not intent. A wrong answer served fast is worse than a right one served slowly.'],
            'state' => [
                'nodes' => [
                    'cache' => ['status' => 'busy', 'badge' => 'close, but not the same'],
                ],
                'edges' => [
                    'from-cache' => ['packets' => ['cache-hit' => 0, 'failure' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'the pairs overlap: no threshold is both useful and safe'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'chat#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Set the threshold on the safe side',
            'body' => [
                'The threshold is set above the highest near-miss, at {num:'.$pairs.'#metadata.configured_threshold|count|2}. On the pair set that serves none of the {num:'.$pairs.'#paraphrase.count|count|0} paraphrases, and none of the near-misses either.',
                'It is still useful in practice: in a run of fresh, repeated and lightly reworded questions, {kpi:served} of the requests were served from the cache. A threshold tuned on hard pairs is a safety bound, not a forecast.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Choose the threshold from labelled pairs, favour zero wrong hits, and recalibrate when the embedding model changes.'],
            'state' => [
                'nodes' => [
                    'cache' => ['status' => 'ok', 'load' => 30, 'badge' => 'strict threshold'],
                ],
                'edges' => [
                    'from-cache' => ['packets' => ['cache-hit' => 2, 'failure' => 0]],
                ],
            ],
            'kpis' => [
                'served' => $uncompared($percent($latency, 'hit_rate')),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'no near-miss is served'],
                ['sign' => '~', 'text' => '{kpi:served} of the requests in that run served from the cache'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'chat#modify',
        ],
        [
            'role' => 'careful',
            'title' => 'Not every answer is stored',
            'body' => [
                'Only a finished, cited answer that was not stopped is stored. A refusal, a failure, an empty text or an answer without a citation is not, and nothing is stored while some chunks still wait for a vector.',
                'The rule has a price. In a mixed run, {num:'.$mixed.'#paths.exact.by_path.miss|count|0} of {num:'.$mixed.'#paths.exact.requests|count|0} exact repeats still missed, because the first answer to that question had no citation and was never kept.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A cache replays mistakes. Store only what you would be happy to serve a hundred times.'],
            'state' => [
                'nodes' => [
                    'cache' => ['badge' => 'refuses uncited answers'],
                    'model' => ['badge' => 'wrote it without a source'],
                ],
                'edges' => [
                    'to-cache' => ['packets' => ['request' => 1, 'failure' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'an uncited answer is not stored, so its repeat is a miss'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'chat#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'Old answers go away',
            'body' => [
                'A stored answer is served only while its prompt version, answer model, embedding model and index version are still the current ones, and only until it expires.',
                'A command lists how many entries are current and how many are stale, and prunes the stale and expired ones together with their exact keys. Turning the cache off for one question is a switch on the request.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Give every cached answer a way to stop being true: versions in the key, an expiry, and a way to clear.'],
            'state' => [
                'nodes' => [
                    'cache' => ['status' => 'ok', 'badge' => 'serves only current entries'],
                    'model' => ['badge' => null],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 0]],
                    'from-model' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'stale and expired entries are pruned'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'chat#modify',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Look up an exact key first, then the nearest stored question above a strict threshold, and call the model only on a miss. Put every version that changes an answer into the key, store only answers you trust, and let old ones expire.',
                'The figures here are the lookup times of the three paths from one run: a fresh answer, an exact repeat and a reworded repeat.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A cache is a promise that an old answer is still right. Keep the promise small.'],
            'state' => [
                'nodes' => [
                    'visitor' => ['load' => 10],
                    'app' => ['badge' => null],
                    'cache' => ['status' => 'ok', 'load' => 30, 'badge' => 'exact, then similar'],
                    'model' => ['status' => 'ok', 'load' => 20, 'badge' => 'only on a miss'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-cache' => ['packets' => ['request' => 1, 'embedding' => 0]],
                    'from-cache' => ['packets' => ['cache-hit' => 2]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [
                'fresh' => $uncompared($seconds($latency, 'paths.fresh.median_ms_all')),
                'exact' => $uncompared($figure($latency, 'paths.exact.median_ms_by_path.exact')),
                'semantic' => $uncompared($figure($latency, 'paths.paraphrase.median_ms_by_path.semantic')),
                'served' => $uncompared($percent($latency, 'hit_rate')),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'fresh {kpi:fresh}, exact {kpi:exact}, reworded {kpi:semantic}'],
            ],
            'learn' => [],
            'deeper' => 'chat#modify',
        ],
    ],
];
