<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from the assistant run file; the confirmation steps have no measurement and say so. State is a
 * patch on the step before.
 */
$run = 'assistant/2026-11-12/run.json';
$model = 'models.0';

$valid = Source::percent($run, "{$model}.argument_validity_first_try.rate");
$needed = Source::percent($run, "{$model}.tool_call_precision.rate");
$most = Source::figure($run, "{$model}.steps.max");
$cap = Source::uncompared(Source::figure($run, "{$model}.steps.limit"));
$time = Source::seconds($run, "{$model}.latency_ms.p50");
$said = Source::percent($run, "{$model}.unsupported_mentioned.rate");

return [
    'title' => 'A tool loop with caps and confirmation',
    'summary' => 'Let a model call tools in a loop, check every call in PHP, bound the loop with caps, and keep a person in charge of every change.',
    'minutes' => 12,
    'level' => 'advanced',
    'guide' => 'admin-assistant',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why a model is given a tool instead of data, and how it asks for a call.'],
        ['id' => 'lo-2', 'text' => 'Say what PHP checks before a tool runs, and what a bad call gets back.'],
        ['id' => 'lo-3', 'text' => 'Name the caps that bound a loop, and what the last call may do.'],
        ['id' => 'lo-4', 'text' => 'Explain why a write becomes a card, and which request is the only one that writes.'],
        ['id' => 'lo-5', 'text' => 'Say what is logged for every step of the loop.'],
        ['id' => 'lo-6', 'text' => 'Name what the measured loop still gets wrong.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 300],
            ['id' => 'loop', 'kind' => 'app', 'label' => 'The loop in PHP', 'x' => 500, 'y' => 300],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model', 'x' => 850, 'y' => 130],
            ['id' => 'tools', 'kind' => 'tools', 'label' => 'Tools', 'x' => 850, 'y' => 300],
            ['id' => 'person', 'kind' => 'person', 'label' => 'Person', 'x' => 850, 'y' => 470],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'loop'],
            ['id' => 'answer', 'from' => 'loop', 'to' => 'browser'],
            ['id' => 'to-model', 'from' => 'loop', 'to' => 'model'],
            ['id' => 'from-model', 'from' => 'model', 'to' => 'loop'],
            ['id' => 'call', 'from' => 'loop', 'to' => 'tools'],
            ['id' => 'result', 'from' => 'tools', 'to' => 'loop'],
            ['id' => 'card', 'from' => 'loop', 'to' => 'person'],
            ['id' => 'confirm', 'from' => 'person', 'to' => 'loop'],
        ],
    ],
    'kpis' => [
        ['id' => 'valid', 'label' => 'Tool calls valid on the first try', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'needed', 'label' => 'Searches that were needed', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'most', 'label' => 'Most tool steps in one question', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'cap', 'label' => 'Tool calls allowed per question', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'time', 'label' => 'Answer time (median)', 'format' => 's', 'better' => 'lower'],
        ['id' => 'said', 'label' => 'Says what the tool cannot filter', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'written', 'label' => 'Changes written before Confirm', 'format' => 'text', 'better' => 'lower'],
        ['id' => 'claimed', 'label' => 'Changes claimed but not made', 'format' => 'text', 'better' => 'lower'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'A model that can only talk',
            'body' => [
                'A buyer asks for a diesel Audi under a given price in one city. The model has never seen this site\'s listings, so it can only answer from memory, and it will make cars up.',
                'The listings live in the database. The model needs a way to look at them without being given the whole table.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A model cannot look anything up. It can only ask the program to do it.'],
            'state' => [
                'nodes' => [
                    'browser' => ['load' => 5, 'badge' => 'asks about listings'],
                    'loop' => ['load' => 10],
                    'model' => ['load' => 30, 'badge' => 'answers from memory'],
                    'tools' => ['status' => 'hidden'],
                    'person' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'valid' => '—',
                'needed' => '—',
                'most' => '—',
                'cap' => '—',
                'time' => '—',
                'said' => '—',
                'written' => '—',
                'claimed' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the answer comes from memory, not from the listings'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'assistant#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Give the model a tool',
            'body' => [
                'The request carries a tool definition: a name, a description and a fixed schema of arguments, such as make, model, city, fuel, year and price. The model answers with a call to that tool instead of text.',
                'The program runs the call, and sends the result back as data. The model never writes a query. It fills in the arguments of one.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'The tool is the only door to the data, so its schema is the whole list of things the model can ask.'],
            'state' => [
                'nodes' => [
                    'tools' => ['status' => 'ok', 'load' => 20, 'badge' => 'one read-only search'],
                    'model' => ['badge' => 'asks for a call'],
                ],
                'edges' => [
                    'from-model' => ['packets' => ['tool' => 2]],
                    'call' => ['packets' => ['tool' => 2]],
                    'result' => ['packets' => ['tool' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the answer is built from listings the tool returned'],
                ['sign' => '~', 'text' => 'the tool is read-only, so a call cannot change a listing'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'assistant#works',
        ],
        [
            'role' => 'careful',
            'title' => 'PHP checks every call first',
            'body' => [
                'A call is text written by a model. Before anything runs, PHP checks that the arguments are valid JSON, that the tool exists and that the values pass the same rules as the public search form. A bad call gets the errors back as a message, and no query runs.',
                'In the test, {kpi:valid} of the first calls were valid, and the search was needed in {kpi:needed} of the calls made. The repair path was never used, so how often a small model needs it was not measured.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Never trust arguments because the model wrote them with care. Validate them like a form.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => 'checks the arguments'],
                    'tools' => ['load' => 20],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 2, 'failure' => 1]],
                ],
            ],
            'kpis' => [
                'valid' => $valid,
                'needed' => $needed,
            ],
            'changed' => [
                ['sign' => '+', 'text' => '{kpi:valid} of the calls passed on the first try'],
                ['sign' => '~', 'text' => 'a rejected call is sent back to the model, not run'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'assistant#works',
        ],
        [
            'role' => 'problem',
            'title' => 'A loop that does not stop',
            'body' => [
                'After each result the model may ask for another call, and then another. Every round is a model call that costs time, and a model that keeps repeating nearly the same search can go on for as long as it is allowed.',
                'Nothing in the model decides when enough is enough. That has to be decided outside it.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A loop without a limit is a bill without a limit.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => null],
                    'model' => ['status' => 'busy', 'load' => 100, 'badge' => 'asks again and again'],
                    'tools' => ['status' => 'busy', 'load' => 80],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 4]],
                    'from-model' => ['packets' => ['tool' => 4]],
                    'call' => ['packets' => ['tool' => 4]],
                    'result' => ['packets' => ['tool' => 4]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'nothing ends the loop but the model itself'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'assistant#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Caps in code',
            'body' => [
                'The buyer assistant allows {kpi:cap} searches per question and the tool refuses any call past that. The admin assistant\'s hand-written loop caps three things: tool steps, model calls and seconds.',
                'When a cap is reached, the next call is the last one. It is sent with tool choice set to none, so the model can only answer in text with what it already has.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Let the code say when to stop, and make the last call an answer, not another search.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => 'counts steps, calls and time'],
                    'model' => ['status' => 'ok', 'load' => 50, 'badge' => 'last call: answer only'],
                    'tools' => ['status' => 'ok', 'load' => 20],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 2]],
                    'result' => ['packets' => ['tool' => 2]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 3]],
                    'answer' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'cap' => $cap,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'at most {kpi:cap} tool calls per question for the buyer assistant'],
                ['sign' => '+', 'text' => 'the last call can only answer'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'admin-assistant#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Most questions need one search',
            'body' => [
                'On the fixed questions, the most tool steps any question used was {kpi:most}, against {kpi:cap} allowed. Follow-up questions needed no more, because the history carries the filters of the earlier turn.',
                'The cap was never close to being used. It is there for the question that goes wrong, not for the usual one. The median answer took {kpi:time}.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Set a cap well above what normal questions use, and read the largest real run before you change it.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => null],
                    'model' => ['badge' => null],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 1]],
                    'result' => ['packets' => ['tool' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['tool' => 1]],
                    'answer' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'most' => $most,
                'cap' => $cap,
                'time' => $time,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'the most tool steps in one question: {kpi:most}'],
                ['sign' => '~', 'text' => 'the median answer time: {kpi:time}'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'assistant#numbers',
        ],
        [
            'role' => 'problem',
            'title' => 'A tool that changes data',
            'body' => [
                'The admin assistant can also change a listing: a new title, a new tag. Reading wrongly gives a bad answer. Writing wrongly gives a bad listing, and the model can be wrong, or steered by text it read in a listing.',
                'A write needs a different rule from a read.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A model asking to write is a proposal. It is not a decision.'],
            'state' => [
                'nodes' => [
                    'tools' => ['badge' => 'a tool that writes'],
                    'person' => ['status' => 'ok', 'load' => 0],
                    'model' => ['badge' => 'wants a new title'],
                ],
                'edges' => [
                    'from-model' => ['packets' => ['tool' => 2]],
                    'call' => ['packets' => ['tool' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'a wrong call would now change real data'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'admin-assistant#works',
        ],
        [
            'role' => 'solution',
            'title' => 'A write becomes a card',
            'body' => [
                'The loop does not run the first valid write call. It returns a pending action instead: a random id, the tool, its arguments, a before-and-after preview and an expiry. Further changes in the same answer are deferred until the person has decided.',
                'The page shows that card with Confirm and Reject. Until a click, nothing has changed. The effect of confirmation on the number of wrong writes was not measured.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Show the exact change, before and after, so the person can say yes to something they can read.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => 'stops at the write'],
                    'tools' => ['badge' => null],
                    'person' => ['status' => 'busy', 'load' => 50, 'badge' => 'reads the card'],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 0]],
                    'from-model' => ['packets' => ['tool' => 0]],
                    'card' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'written' => 'none by design',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the change is shown, not made'],
                ['sign' => '~', 'text' => 'the listing stays as it was until a click'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'admin-assistant#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Only Confirm writes',
            'body' => [
                'The confirm request is the only one that writes. It checks that the action belongs to this conversation, is not expired and was not used, validates the arguments again and writes in one transaction. The outcome goes back to the model, which answers or proposes the next change.',
                'A typed "yes" is just a question. A model may also say a change was made before it was, so the listing page is the place to check. How often a model claimed a change that was not made was not measured.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'What the model says about its own change is not evidence. The listing is.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => 'writes only on Confirm'],
                    'person' => ['status' => 'ok', 'load' => 20, 'badge' => 'clicked Confirm'],
                ],
                'edges' => [
                    'card' => ['packets' => ['request' => 0]],
                    'confirm' => ['packets' => ['request' => 2]],
                    'to-model' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'claimed' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'one request writes, after a check of the same action'],
                ['sign' => '~', 'text' => 'the outcome is fed back to the model'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'admin-assistant#support',
        ],
        [
            'role' => 'useful',
            'title' => 'Every step is logged',
            'body' => [
                'Every tool step is stored with its arguments, its outcome and a short summary of the result. The outcome is ok, error, failed, pending, confirmed or rejected. Full listings are never stored.',
                'Every model call is a run row with tokens and time. When an answer looks wrong, you read the steps and see whether the model asked badly or answered badly.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A loop you cannot replay in a log is a loop you cannot debug.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => 'every step is stored'],
                    'person' => ['badge' => null],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 1]],
                    'result' => ['packets' => ['tool' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'each call can be read back with its arguments'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'admin-assistant#support',
        ],
        [
            'role' => 'careful',
            'title' => 'What the loop still gets wrong',
            'body' => [
                'Checking arguments does not check honesty. Asked for something the tool cannot filter, such as a colour or a distance, the first model said so in {kpi:said} of those questions. The second model said it in far fewer, and quietly searched for something near.',
                'One answer from the first model also linked a listing the search had not returned. A cap and a confirmation bound the cost and the harm, but the words of the answer still need an eval.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Caps and cards protect the data. They do not make the answer true.'],
            'state' => [
                'nodes' => [
                    'loop' => ['badge' => null],
                    'model' => ['status' => 'ok', 'load' => 40, 'badge' => 'ignores what it cannot filter'],
                    'person' => ['status' => 'ok', 'load' => 0],
                ],
                'edges' => [
                    'confirm' => ['packets' => ['request' => 0]],
                    'answer' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'said' => $said,
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the limit of the tool is said only some of the time: {kpi:said}'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'assistant#numbers',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Give the model a tool and not the data. Check every call in PHP like a form, cap the loop in code, and make the last call an answer. Let a write only propose, and let one request, after a click, do the writing.',
                'Log every step, and measure the answers, because the loop can be safe and still be wrong.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'The model proposes, the code checks, and a person decides.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'loop' => ['badge' => 'checks, caps, logs'],
                    'model' => ['status' => 'ok', 'load' => 40, 'badge' => null],
                    'tools' => ['status' => 'ok', 'load' => 20],
                    'person' => ['status' => 'ok', 'load' => 20, 'badge' => 'decides'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['tool' => 1]],
                    'call' => ['packets' => ['tool' => 1]],
                    'result' => ['packets' => ['tool' => 1]],
                    'card' => ['packets' => ['request' => 1]],
                    'confirm' => ['packets' => ['request' => 1]],
                    'answer' => ['packets' => ['token' => 3]],
                ],
            ],
            'kpis' => [
                'valid' => Source::uncompared($valid),
                'needed' => Source::uncompared($needed),
                'most' => Source::uncompared($most),
                'cap' => $cap,
                'time' => Source::uncompared($time),
                'said' => Source::uncompared($said),
                'written' => 'none by design',
                'claimed' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => '{kpi:valid} valid first calls'],
                ['sign' => '+', 'text' => 'at most {kpi:most} steps used, against {kpi:cap} allowed'],
                ['sign' => '+', 'text' => 'writes only after a click, by design'],
            ],
            'learn' => [],
            'deeper' => 'admin-assistant#modify',
        ],
    ],
];
