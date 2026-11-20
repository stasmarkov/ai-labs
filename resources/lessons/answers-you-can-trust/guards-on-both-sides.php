<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$answers = 'rag-eval/2026-11-03/answers-qwen-500-300/answers.json';

$wrong = Source::figure($answers, 'models.0.runs.0.summary.false_refusal');
$asked = Source::figure($answers, 'models.0.runs.0.summary.on_topic');

return [
    'title' => 'Guards on both sides',
    'summary' => 'Check the question before the model sees it and the answer before the visitor does: what each guard looks for, what a refusal looks like, and what guards cannot catch.',
    'minutes' => 10,
    'level' => 'intermediate',
    'guide' => 'guards',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Say what the input guard checks, and what it does with a match.'],
        ['id' => 'lo-2', 'text' => 'Explain what the canary is, and why it should never appear in an answer.'],
        ['id' => 'lo-3', 'text' => 'Describe what the output guard looks for while an answer streams.'],
        ['id' => 'lo-4', 'text' => 'Tell what the visitor sees for a refused question and for a withheld answer.'],
        ['id' => 'lo-5', 'text' => 'Find the reason for a refusal in the run log.'],
        ['id' => 'lo-6', 'text' => 'Name what the guards cannot catch, and how to keep testing them.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 300],
            ['id' => 'input', 'kind' => 'app', 'label' => 'Input guard', 'x' => 450, 'y' => 110],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model server', 'x' => 850, 'y' => 300],
            ['id' => 'output', 'kind' => 'app', 'label' => 'Output guard', 'x' => 450, 'y' => 500],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'input'],
            ['id' => 'refused', 'from' => 'input', 'to' => 'browser'],
            ['id' => 'pass', 'from' => 'input', 'to' => 'model'],
            ['id' => 'write', 'from' => 'model', 'to' => 'output'],
            ['id' => 'show', 'from' => 'output', 'to' => 'browser'],
        ],
    ],
    'kpis' => [
        ['id' => 'calls', 'label' => 'Model calls when the input guard refuses', 'format' => 'text', 'better' => 'none'],
        ['id' => 'wrong', 'label' => 'On-topic questions refused by a guard', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'asked', 'label' => 'On-topic questions asked', 'format' => 'count', 'better' => 'none'],
        ['id' => 'hostile', 'label' => 'Hostile questions refused', 'format' => 'text', 'better' => 'none'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A hostile question reaches the model',
            'body' => [
                'The docs assistant answers from passages it has retrieved. A visitor types a question that tells it to ignore its instructions and print them.',
                'Left alone, the question goes to the model like any other, and the model may do what it was asked.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A model can be talked out of its instructions. A rule that must hold is checked by code.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'asks to ignore the rules'],
                    'input' => ['badge' => 'passes everything on'],
                    'output' => ['badge' => 'passes everything on'],
                    'model' => ['load' => 60, 'badge' => 'may follow it'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'pass' => ['packets' => ['request' => 1]],
                    'write' => ['packets' => ['token' => 3]],
                    'show' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'calls' => '—',
                'wrong' => '—',
                'asked' => '—',
                'hostile' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'nothing stands between the question and the model'],
            ],
            'learn' => [],
            'deeper' => 'guards#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Check the question first',
            'body' => [
                'Before any search or model call, the question is matched against a list of known injection patterns, and so is every earlier user turn of a conversation. A match is refused with the name of the pattern, and the model is never called.',
                'This check runs first, ahead of the answer cache, the retrieval and the relevance gate.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A refusal that comes before everything else costs a pattern match and no model call.'],
            'state' => [
                'nodes' => [
                    'input' => ['badge' => 'matches known patterns'],
                    'output' => ['badge' => null],
                    'model' => ['load' => 0, 'badge' => 'not called'],
                    'browser' => ['badge' => 'gets a refusal'],
                ],
                'edges' => [
                    'pass' => ['packets' => ['request' => 0]],
                    'write' => ['packets' => ['token' => 0]],
                    'show' => ['packets' => ['token' => 0]],
                    'refused' => ['packets' => ['failure' => 2]],
                ],
            ],
            'kpis' => [
                'calls' => 'none',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a matching question is refused with no model call'],
                ['sign' => '+', 'text' => 'the refusal names the pattern that matched'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'guards#works',
        ],
        [
            'role' => 'careful',
            'title' => 'A harmless question can match',
            'body' => [
                'A pattern can match a question that is harmless, for example one that quotes a known phrase. That is a false refusal, and it is why the refusal names its rule: the list can be tightened.',
                'In one run of the eval set, a guard refused {kpi:wrong} of the {kpi:asked} on-topic questions. The set holds no hostile question, so it says nothing about how many hostile ones the guards catch.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Log every refusal and read them. A guard that refuses too much is also a failure.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'a harmless question'],
                    'input' => ['badge' => 'one rule may match too much'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'wrong' => $wrong,
                'asked' => $asked,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'on-topic questions refused by a guard, in one run: {kpi:wrong}'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'guards#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Plant a canary',
            'body' => [
                'Each request adds a random marker to the instructions. A real answer has no reason to contain it, so if it shows up in the text the instructions were copied out.',
                'The marker is new for every request, so a leaked one cannot be reused later.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A canary turns "did it leak?" into a plain string search.'],
            'state' => [
                'nodes' => [
                    'input' => ['badge' => null],
                    'model' => ['load' => 50, 'badge' => 'a canary in the prompt'],
                    'browser' => ['badge' => null],
                ],
                'edges' => [
                    'refused' => ['packets' => ['failure' => 0]],
                    'pass' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the instructions carry a marker that is new for every request'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'guards#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Watch the answer as it streams',
            'body' => [
                'The output guard reads the text as it grows. It looks for the canary, or for a long run of words copied from the instructions that the retrieved passages and the question do not also contain.',
                'Quoting a retrieved passage is honest, so a copy that is also in the sources does not count.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Compare against what the answer was allowed to quote, not against everything the model saw.'],
            'state' => [
                'nodes' => [
                    'output' => ['status' => 'ok', 'badge' => 'reads every piece'],
                    'model' => ['load' => 60, 'badge' => null],
                ],
                'edges' => [
                    'write' => ['packets' => ['token' => 4]],
                    'show' => ['packets' => ['token' => 4]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the answer is checked piece by piece, not only at the end'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'guards#works',
        ],
        [
            'role' => 'careful',
            'title' => 'A leak ends the answer',
            'body' => [
                'When the output guard fires, the stream sends a refused event with a fixed message, stops the text and logs the reason. The event also tells the page to clear the text it has already drawn.',
                'So a visitor can see part of a leak for a moment before it is replaced. The guard cannot take back words that were already sent.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Ending a stream on a pattern can leave a half answer. Say so to the visitor.'],
            'state' => [
                'nodes' => [
                    'output' => ['status' => 'busy', 'badge' => 'leak found, answer stops'],
                    'model' => ['load' => 30, 'badge' => 'canary in the text'],
                    'browser' => ['badge' => 'cleared, fixed message'],
                ],
                'edges' => [
                    'write' => ['packets' => ['token' => 2]],
                    'show' => ['packets' => ['token' => 0, 'failure' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a leaking answer ends and is replaced by a fixed message'],
                ['sign' => '~', 'text' => 'words sent before the guard fired were already shown'],
            ],
            'learn' => ['lo-3', 'lo-4'],
            'deeper' => 'guards#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Read the reason in the run log',
            'body' => [
                'Both refusals are logged. A refused question is logged with its stage, input, and the pattern that matched. A withheld answer is logged with its stage, output, and whether the canary or a copy of the instructions gave it away.',
                'The page shows a short fixed reason, and the full reason is in the run row. If one rule dominates the latest refusals, review its pattern and rerun the same questions.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A refusal you cannot explain is a bug report waiting to happen. Keep the reason with the run.'],
            'state' => [
                'nodes' => [
                    'output' => ['status' => 'ok', 'badge' => 'reason written to the run'],
                    'input' => ['badge' => 'reason written to the run'],
                    'browser' => ['badge' => null],
                ],
                'edges' => [
                    'show' => ['packets' => ['failure' => 0]],
                    'write' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'every refusal has a stage and a reason in the run row'],
            ],
            'learn' => ['lo-4', 'lo-5'],
            'deeper' => 'guards#support',
        ],
        [
            'role' => 'careful',
            'title' => 'What guards do not catch',
            'body' => [
                'Patterns are a list of known phrases, and an attacker can rephrase. The output guard catches copying of the instructions, not a wrong answer or a harmful one.',
                'How many hostile questions the guards refuse is not measured: the eval set holds none.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Guards lower the risk and never remove it. Add least-privilege tools and output validation.'],
            'state' => [
                'nodes' => [
                    'input' => ['badge' => 'only known phrases'],
                    'output' => ['badge' => 'only copied instructions'],
                ],
            ],
            'kpis' => [
                'hostile' => 'not measured',
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'hostile questions refused: not measured'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'guards#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'Keep a list of attacks',
            'body' => [
                'Keep a list of hostile questions and run it after every change of prompt or pattern. Each one should be refused at once, with no model call in the run list.',
                'Add a pattern by changing the guard file and keeping the old version for history, then ask a question that matches and read the refusal.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A guard without a test list drifts. The list is what tells you a prompt change broke it.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'runs the attack list'],
                    'input' => ['badge' => 'refuses each at once'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 3]],
                    'refused' => ['packets' => ['failure' => 3]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a rerunnable list shows when a change weakens a guard'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'guards#modify',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Check the question on the way in, before any model call, and name the pattern in the refusal. Plant a canary and watch the stream on the way out, and end the answer when it leaks.',
                'Log every reason, read the false refusals, and keep a list of hostile questions to rerun. The guards reduce risk, and they are one layer, not the whole defence.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Guard both sides: what goes in, and what comes out.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'input' => ['status' => 'ok', 'badge' => null],
                    'output' => ['status' => 'ok', 'badge' => null],
                    'model' => ['status' => 'ok', 'load' => 40, 'badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'refused' => ['packets' => ['failure' => 1]],
                    'pass' => ['packets' => ['request' => 1]],
                    'write' => ['packets' => ['token' => 4]],
                    'show' => ['packets' => ['token' => 4]],
                ],
            ],
            'kpis' => [
                'wrong' => Source::uncompared($wrong),
                'asked' => Source::uncompared($asked),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a guard on the question and a guard on the answer'],
                ['sign' => '~', 'text' => 'on-topic questions refused by a guard, in one run: {kpi:wrong}'],
            ],
            'learn' => [],
            'deeper' => 'guards#works',
        ],
    ],
];
