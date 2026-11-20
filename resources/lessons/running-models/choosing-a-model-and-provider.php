<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$run = 'llm/2026-11-13/run.json';
$photos = 'photo-vision/2026-11-11/run.json';
$assistant = 'assistant/2026-11-12/run.json';
$first = 'models."qwen3.5:9b"';
$second = 'models."gemma4:12b"';

return [
    'title' => 'Choosing a model and provider',
    'summary' => 'Compare two local models and a cloud option on speed, memory and quality, see who picks which one for a feature, and see what happens when the provider is not there.',
    'minutes' => 9,
    'level' => 'intermediate',
    'guide' => 'providers',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Compare two models on first-token time, writing speed and memory, and say which of the three differ.'],
        ['id' => 'lo-2', 'text' => 'Explain what a cold start is and who pays for it.'],
        ['id' => 'lo-3', 'text' => 'Say why the model that wins one feature can lose another.'],
        ['id' => 'lo-4', 'text' => 'Describe how a provider and a model are chosen for one feature.'],
        ['id' => 'lo-5', 'text' => 'Name what a cloud provider needs to be switched on, and what keeps its cost in check.'],
        ['id' => 'lo-6', 'text' => 'Say what a feature does when its provider is unreachable, has no such model, or stays silent.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'app', 'kind' => 'app', 'label' => 'The app', 'x' => 150, 'y' => 300],
            ['id' => 'local', 'kind' => 'model', 'label' => 'Local model', 'x' => 850, 'y' => 130],
            ['id' => 'cloud', 'kind' => 'cloud', 'label' => 'Cloud model', 'x' => 850, 'y' => 470],
            ['id' => 'users', 'kind' => 'browser', 'label' => 'Visitors', 'x' => 150, 'y' => 520],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'users', 'to' => 'app'],
            ['id' => 'to-local', 'from' => 'app', 'to' => 'local'],
            ['id' => 'from-local', 'from' => 'local', 'to' => 'app'],
            ['id' => 'to-cloud', 'from' => 'app', 'to' => 'cloud'],
            ['id' => 'from-cloud', 'from' => 'cloud', 'to' => 'app'],
        ],
    ],
    'kpis' => [
        ['id' => 'ttft', 'label' => 'First token (median)', 'format' => 's', 'better' => 'lower'],
        ['id' => 'speed', 'label' => 'Writing speed', 'format' => 'tokens-per-s', 'better' => 'higher'],
        ['id' => 'memory', 'label' => 'Memory the model holds', 'format' => 'bytes', 'better' => 'none'],
        ['id' => 'valid', 'label' => 'Valid on the first try', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'accuracy', 'label' => 'Right by the feature\'s own check', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'fail', 'label' => 'Time to fail', 'format' => 'ms', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'Two local models',
            'body' => [
                'The lab can run two local models. The first has its first token ready after {kpi:ttft} and then writes at {kpi:speed}. It holds {kpi:memory} of memory while it is loaded.',
                'Those three numbers are the first things anyone compares. They are not the whole question.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Speed and memory describe the machine. They say nothing yet about whether the answers are any good.'],
            'state' => [
                'nodes' => [
                    'app' => ['load' => 10],
                    'local' => ['load' => 40, 'badge' => 'the first model'],
                    'cloud' => ['status' => 'hidden'],
                    'users' => ['load' => 5],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-local' => ['packets' => ['request' => 1]],
                    'from-local' => ['packets' => ['token' => 5]],
                ],
            ],
            'kpis' => [
                'ttft' => Source::seconds($run, "{$first}.speed.ttft_ms.median"),
                'speed' => Source::figure($run, "{$first}.speed.generation_tokens_per_s.median", 1),
                'memory' => Source::figure($run, "{$first}.speed.size", 1),
                'valid' => '—',
                'accuracy' => '—',
                'fail' => '—',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'first token after {kpi:ttft}'],
                ['sign' => '+', 'text' => 'writing at {kpi:speed}'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'local-models#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Same speed, slower start',
            'body' => [
                'The second model writes at {kpi:speed}, about the same as the first, and holds {kpi:memory}, about the same. Its first token takes {kpi:ttft} against {num:'.$run.'#'.$first.'.speed.ttft_ms.median|ms|0}.',
                'The difference is the start: it reads the prompt at {num:'.$run.'#'.$second.'.speed.prompt_eval_tokens_per_s.median|tokens-per-s|0} against {num:'.$run.'#'.$first.'.speed.prompt_eval_tokens_per_s.median|tokens-per-s|0}. Once it writes, you cannot tell them apart by speed.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Compare the start and the writing speed separately. A model can differ in one and match in the other.'],
            'state' => [
                'nodes' => [
                    'local' => ['badge' => 'the second model'],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared(Source::seconds($run, "{$second}.speed.ttft_ms.median")),
                'speed' => Source::uncompared(Source::figure($run, "{$second}.speed.generation_tokens_per_s.median", 1)),
                'memory' => Source::figure($run, "{$second}.speed.size", 1),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'first token after {kpi:ttft}, later than the first model'],
                ['sign' => '~', 'text' => 'writing at {kpi:speed}, about the same'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'local-models#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'The first call after a pause',
            'body' => [
                'A local model is loaded into memory and unloaded again after a while without requests. The first call after that has to load it first: for the first model the cold first token took {kpi:ttft}, and loading alone took {num:'.$run.'#'.$first.'.speed.load_ms|ms|0}.',
                'The warm calls in the other steps do not pay it. Whoever happens to ask first does.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Compare warm runs with warm runs. A cold start is one slow call, not the model\'s speed.'],
            'state' => [
                'nodes' => [
                    'local' => ['status' => 'busy', 'load' => 100, 'badge' => 'loading into memory'],
                    'app' => ['badge' => 'waiting for the first token'],
                ],
                'edges' => [
                    'from-local' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared(Source::seconds($run, "{$first}.speed.cold_ttft_ms")),
                'speed' => Source::uncompared(Source::figure($run, "{$first}.speed.generation_tokens_per_s.median", 1)),
                'memory' => Source::figure($run, "{$first}.speed.size", 1),
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the first call waits for the model to load'],
                ['sign' => '~', 'text' => 'cold first token at {kpi:ttft}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'providers#support',
        ],
        [
            'role' => 'problem',
            'title' => 'Speed is not quality: photos',
            'body' => [
                'Both models read car photos and return a fixed record, which is checked again in PHP. The second passed on the first try on {num:'.$photos.'#models.0.valid_first_attempt.count|count|0} of {num:'.$photos.'#models.0.photos|count|0} photos. The first passed on {num:'.$photos.'#models.1.valid_first_attempt.count|count|0}, and a retry did not fix the rest.',
                'The first model agreed with the photo\'s own label for the body type on {num:'.$photos.'#models.1.body_type_agreement.agreed|count|0} photos and the second on {num:'.$photos.'#models.0.body_type_agreement.agreed|count|0}. One is stricter with the format, the other reads the picture better.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'If a broken record costs more than a wrong guess, pick for validity. If the guess is what matters, pick for accuracy.'],
            'state' => [
                'nodes' => [
                    'local' => ['status' => 'ok', 'load' => 40, 'badge' => 'reading photos'],
                    'app' => ['badge' => 'checks the record again'],
                ],
                'edges' => [
                    'to-local' => ['packets' => ['request' => 2]],
                    'from-local' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared(Source::seconds($run, "{$second}.speed.ttft_ms.median")),
                'speed' => Source::uncompared(Source::figure($run, "{$second}.speed.generation_tokens_per_s.median", 1)),
                'memory' => Source::figure($run, "{$second}.speed.size", 1),
                'valid' => Source::uncompared(Source::percent($photos, 'models.0.valid_first_attempt.rate')),
                'accuracy' => Source::uncompared(Source::percent($photos, 'models.0.body_type_agreement.rate')),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'the second model: valid on the first try {kpi:valid}, body type agreed {kpi:accuracy}'],
                ['sign' => '~', 'text' => 'the first model is less often valid and more often right'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'local-models#numbers',
        ],
        [
            'role' => 'careful',
            'title' => 'The assistant: a one-question gap',
            'body' => [
                'On the assistant that searches with tools both models sent valid arguments on every first call, and the gaps are one question wide. The first model found the right filters in {num:'.$assistant.'#models.0.filter_accuracy.count|count|0} of {num:'.$assistant.'#models.0.filter_accuracy.of|count|0} questions, the second in {num:'.$assistant.'#models.1.filter_accuracy.count|count|0}.',
                'But the first model once linked a listing the search never returned: its answers cited only returned listings in {num:'.$assistant.'#models.0.answers_citing_only_returned_ids.count|count|0} of {num:'.$assistant.'#models.0.answers_citing_only_returned_ids.of|count|0}, the second in {num:'.$assistant.'#models.1.answers_citing_only_returned_ids.count|count|0}. The same split as on the photos, the first reading the content a little better and the second keeping to the rules, but too small to rank them.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'No model wins everywhere. Measure on the task itself, with its own check.'],
            'state' => [
                'nodes' => [
                    'local' => ['badge' => 'searching with tools'],
                    'app' => ['badge' => null],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared(Source::seconds($run, "{$first}.speed.ttft_ms.median")),
                'speed' => Source::uncompared(Source::figure($run, "{$first}.speed.generation_tokens_per_s.median", 1)),
                'memory' => Source::figure($run, "{$first}.speed.size", 1),
                'valid' => Source::uncompared(Source::percent($assistant, 'models.0.argument_validity_first_try.rate')),
                'accuracy' => Source::uncompared(Source::percent($assistant, 'models.0.filter_accuracy.rate')),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'the first model: filters right {kpi:accuracy}, arguments valid {kpi:valid}'],
                ['sign' => '~', 'text' => 'the second model cites only what the search returned'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'local-models#numbers',
        ],
        [
            'role' => 'solution',
            'title' => 'Choose per feature',
            'body' => [
                'Each feature asks one resolver for its provider and model, and a saved choice sits between a single request and the configuration. On a local machine the Settings page saves a provider and a model per feature, so the description can use one model and the assistant another.',
                'No agent or service reads those settings itself. Switching a feature is a change of data, not of code, and the run row records which model really answered.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Record the provider and model on every run. A later comparison then never has to guess which setup produced a number.'],
            'state' => [
                'nodes' => [
                    'app' => ['badge' => 'one resolver per feature'],
                    'local' => ['badge' => 'chosen for a feature'],
                ],
                'edges' => [
                    'to-local' => ['packets' => ['request' => 2]],
                    'from-local' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'valid' => 'not measured',
                'accuracy' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a provider and a model per feature'],
                ['sign' => '~', 'text' => 'no figure for the choice itself: not measured'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'providers#works',
        ],
        [
            'role' => 'careful',
            'title' => 'A cloud model, with a guard',
            'body' => [
                'A cloud provider is switched on only when its key is set in the environment. Without a key it is simply absent: it is not offered, and a request that names it is refused before any model call.',
                'Two guards sit on the cost. A limiter counts cloud requests per visitor, on top of the feature\'s own limit, and every cloud entry has a cap on the length of an answer. This lesson has no measurement of a cloud model, so every figure shows as not measured.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A paid model needs a cap on how often it is called and on how much it may write. Without both, one loop is a bill.'],
            'state' => [
                'nodes' => [
                    'local' => ['load' => 10, 'badge' => null],
                    'cloud' => ['status' => 'ok', 'load' => 30, 'badge' => 'switched on by a key'],
                    'app' => ['badge' => 'limits cloud calls'],
                ],
                'edges' => [
                    'to-local' => ['packets' => ['request' => 0]],
                    'from-local' => ['packets' => ['token' => 0]],
                    'to-cloud' => ['packets' => ['request' => 2]],
                    'from-cloud' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'ttft' => 'not measured',
                'speed' => 'not measured',
                'memory' => 'not measured',
                'valid' => 'not measured',
                'accuracy' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a cloud option, behind a key and a limiter'],
                ['sign' => '~', 'text' => 'no cloud figures: not measured'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'providers#works',
        ],
        [
            'role' => 'careful',
            'title' => 'When the provider is not there',
            'body' => [
                'The lab broke the local model on purpose. With the server unreachable, the description failed in {kpi:fail}. With a model name the server does not have, it failed in {num:'.$run.'#'.$first.'.failover.model_missing.describe.time_to_fail_ms|ms|0}.',
                'With a server that accepts the call and never answers, it waited {num:'.$run.'#'.$first.'.failover.timeout.describe.time_to_fail_ms|ms|0} until its timeout, the limit set for that test. In every case the page got a safe message and the run log got a failed row. No fallback to another provider was built.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A second provider is not a free backup. It has to be built, and tested like any other path.'],
            'state' => [
                'nodes' => [
                    'local' => ['status' => 'down', 'load' => 0, 'badge' => 'unreachable'],
                    'cloud' => ['status' => 'hidden', 'badge' => null],
                    'app' => ['badge' => 'shows a safe message'],
                ],
                'edges' => [
                    'to-cloud' => ['packets' => ['request' => 0]],
                    'from-cloud' => ['packets' => ['token' => 0]],
                    'to-local' => ['visible' => true, 'packets' => ['request' => 0, 'failure' => 2]],
                ],
            ],
            'kpis' => [
                'ttft' => 'no answer',
                'speed' => '—',
                'memory' => '—',
                'valid' => '—',
                'accuracy' => '—',
                'fail' => Source::figure($run, "{$first}.failover.unreachable.describe.time_to_fail_ms"),
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the provider stops answering'],
                ['sign' => '~', 'text' => 'an unreachable server fails in {kpi:fail}'],
                ['sign' => '~', 'text' => 'a silent one holds the call for {num:'.$run.'#'.$first.'.failover.timeout.describe.time_to_fail_ms|ms|0}'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'local-models#support',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Compare models on the start and the writing speed separately, and compare warm runs with warm runs. Then measure each feature with its own check, because the better model changes with the task.',
                'Choose per feature, record which model answered, put a key and a limit on any cloud option, and give every call a timeout. The figures here are the first local model\'s warm run and its failure when unreachable.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Pick the model for the feature, not for the app. Then plan for the day the provider does not answer.'],
            'state' => [
                'nodes' => [
                    'app' => ['badge' => null],
                    'local' => ['status' => 'ok', 'load' => 40, 'badge' => 'chosen for this feature'],
                    'cloud' => ['status' => 'ok', 'load' => 0, 'badge' => 'optional, behind a key'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-local' => ['packets' => ['request' => 1, 'failure' => 0]],
                    'from-local' => ['packets' => ['token' => 5]],
                    'to-cloud' => ['packets' => ['request' => 0]],
                    'from-cloud' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [
                'ttft' => Source::uncompared(Source::seconds($run, "{$first}.speed.ttft_ms.median")),
                'speed' => Source::uncompared(Source::figure($run, "{$first}.speed.generation_tokens_per_s.median", 1)),
                'memory' => Source::uncompared(Source::figure($run, "{$first}.speed.size", 1)),
                'valid' => '—',
                'accuracy' => '—',
                'fail' => Source::uncompared(Source::figure($run, "{$first}.failover.unreachable.describe.time_to_fail_ms")),
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'first token after {kpi:ttft}, writing at {kpi:speed}'],
                ['sign' => '~', 'text' => 'an unreachable provider fails in {kpi:fail}'],
            ],
            'learn' => [],
            'deeper' => 'providers#modify',
        ],
    ],
];
