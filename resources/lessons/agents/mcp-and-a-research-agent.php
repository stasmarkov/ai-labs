<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * The research figures are read from the stats file; MCP has no measurement, and its steps say so. State is a patch on
 * the step before.
 */
$stats = 'research/2026-11-14/stats.json';

$done = Source::figure($stats, 'summary.done_percent');
$calls = Source::figure($stats, 'summary.average_model_calls', 1);
$most = Source::figure($stats, 'summary.max_model_calls');
$time = Source::figure($stats, 'summary.average_duration_s');
$refused = Source::figure($stats, 'summary.refused');

return [
    'title' => 'MCP and a research agent',
    'summary' => 'Publish the listing tools to outside clients over MCP, keep the confirmation in the browser, and let a bounded agent research a question across the docs.',
    'minutes' => 12,
    'level' => 'advanced',
    'guide' => 'mcp',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain what MCP lets an outside client do with the listing tools, and how it starts the server.'],
        ['id' => 'lo-2', 'text' => 'Say which tools only read, and what they share with the admin assistant.'],
        ['id' => 'lo-3', 'text' => 'Explain why a write only proposes, why the confirmation is in the browser, and what it does not prove.'],
        ['id' => 'lo-4', 'text' => 'Tell what the research agent decides and what the code decides.'],
        ['id' => 'lo-5', 'text' => 'Say what a citation marker proves in a report, and what it does not.'],
        ['id' => 'lo-6', 'text' => 'Name what bounds a research run, and what a replay costs.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'client', 'kind' => 'app', 'label' => 'MCP client', 'x' => 150, 'y' => 130],
            ['id' => 'server', 'kind' => 'tools', 'label' => 'MCP server', 'x' => 500, 'y' => 130],
            ['id' => 'person', 'kind' => 'person', 'label' => 'Developer', 'x' => 850, 'y' => 130],
            ['id' => 'runner', 'kind' => 'app', 'label' => 'Research run', 'x' => 150, 'y' => 470],
            ['id' => 'model', 'kind' => 'model', 'label' => 'Model', 'x' => 500, 'y' => 470],
            ['id' => 'docs', 'kind' => 'vector-db', 'label' => 'Docs index', 'x' => 850, 'y' => 470],
        ],
        'edges' => [
            ['id' => 'call', 'from' => 'client', 'to' => 'server'],
            ['id' => 'reply', 'from' => 'server', 'to' => 'client'],
            ['id' => 'pending', 'from' => 'server', 'to' => 'person'],
            ['id' => 'confirm', 'from' => 'person', 'to' => 'server'],
            ['id' => 'to-model', 'from' => 'runner', 'to' => 'model'],
            ['id' => 'from-model', 'from' => 'model', 'to' => 'runner'],
            ['id' => 'search', 'from' => 'runner', 'to' => 'docs'],
            ['id' => 'found', 'from' => 'docs', 'to' => 'runner'],
        ],
    ],
    'kpis' => [
        ['id' => 'done', 'label' => 'Runs that ended done', 'format' => 'percent', 'better' => 'higher'],
        ['id' => 'refused', 'label' => 'Topics refused before any call', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'calls', 'label' => 'Model calls per run (average)', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'most', 'label' => 'Model calls in the largest run', 'format' => 'count', 'better' => 'lower'],
        ['id' => 'time', 'label' => 'Run time (average)', 'format' => 's', 'better' => 'lower'],
        ['id' => 'mcp', 'label' => 'MCP calls that ended in an error', 'format' => 'text', 'better' => 'lower'],
        ['id' => 'self', 'label' => 'Clients that confirmed on their own', 'format' => 'text', 'better' => 'lower'],
        ['id' => 'replay', 'label' => 'Time a replay saves', 'format' => 'text', 'better' => 'higher'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'The tools stay inside the app',
            'body' => [
                'The admin assistant has tools that search, read and count listings. They work on the lab page. A developer\'s own assistant, such as Claude Code, cannot reach them.',
                'Copying the tools into each client would mean one more set of rules to keep in step with the first.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'One set of tools should serve every client, with one set of rules.'],
            'state' => [
                'nodes' => [
                    'client' => ['load' => 10, 'badge' => 'a developer\'s assistant'],
                    'server' => ['status' => 'hidden'],
                    'person' => ['status' => 'hidden'],
                    'runner' => ['status' => 'hidden'],
                    'model' => ['status' => 'hidden'],
                    'docs' => ['status' => 'hidden'],
                ],
                'edges' => [],
            ],
            'kpis' => [
                'done' => '—',
                'refused' => '—',
                'calls' => '—',
                'most' => '—',
                'time' => '—',
                'mcp' => '—',
                'self' => '—',
                'replay' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the tools are out of reach of any outside client'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'mcp#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Publish them over MCP',
            'body' => [
                'MCP, the Model Context Protocol, lets a client discover the tools of a server and call them. The server has a name, a version and instructions for the client\'s model, and each tool has a name, a description and an input schema.',
                'A client starts the server as a local process and talks to it on its standard input and output. No port is opened and no login is needed, which is why it runs only on the local machine.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Local and unauthenticated is fine for a local tool. It is the reason this server refuses to start elsewhere.'],
            'state' => [
                'nodes' => [
                    'server' => ['status' => 'ok', 'load' => 20, 'badge' => 'started over stdio'],
                ],
                'edges' => [
                    'call' => ['packets' => ['request' => 1]],
                    'reply' => ['packets' => ['tool' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a client finds the tools by asking the server'],
                ['sign' => '~', 'text' => 'the server runs only on the local machine'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'mcp#works',
        ],
        [
            'role' => 'useful',
            'title' => 'The same tools, the same rules',
            'body' => [
                'The read tools wrap the admin assistant\'s own tools. They build their input schema from the same parameters, validate with the same rules and return the same capped result. A refused call comes back as an MCP error with the messages.',
                'Every call is logged with its outcome. How many calls ended in an error was not measured.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Wrap, do not copy. A rule changed in one place changes for both clients.'],
            'state' => [
                'nodes' => [
                    'server' => ['load' => 30, 'badge' => 'wraps the admin tools'],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 2]],
                    'reply' => ['packets' => ['tool' => 2]],
                ],
            ],
            'kpis' => [
                'mcp' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'one set of rules for the page and for the client'],
                ['sign' => '~', 'text' => 'errors from MCP calls: {kpi:mcp}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'mcp#works',
        ],
        [
            'role' => 'problem',
            'title' => 'A client that can change data',
            'body' => [
                'A write tool, offered to an outside client, hands the power to change a listing to whatever model that client runs. It may be wrong, or steered by text it read.',
                'The client\'s own permission prompt does not settle it. A person can set it to always allow.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A prompt that can be switched off is a convenience, not a guard.'],
            'state' => [
                'nodes' => [
                    'client' => ['badge' => 'may always allow'],
                    'person' => ['status' => 'ok', 'load' => 0, 'badge' => null],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'the client\'s own prompt cannot be the guard'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'mcp#works',
        ],
        [
            'role' => 'solution',
            'title' => 'A write only proposes',
            'body' => [
                'The write tools are named for what they do: they propose. A call stores a pending change with a preview and an expiry, and the listing is not touched. The reply holds the change id, the preview, the status pending and a link to the confirmation page.',
                'The server also tells the client\'s model, in its instructions, that a change can only be proposed.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Name a tool for what it does. A model reading "propose" knows nothing has happened yet.'],
            'state' => [
                'nodes' => [
                    'server' => ['badge' => 'stores a pending change'],
                    'person' => ['status' => 'busy', 'load' => 30, 'badge' => 'sees the preview'],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 1]],
                    'pending' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the listing stays as it was'],
                ['sign' => '~', 'text' => 'the reply carries a link, not an outcome'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'mcp#works',
        ],
        [
            'role' => 'careful',
            'title' => 'The confirmation is in the browser',
            'body' => [
                'The only way to apply a change is a click on the Changes page. That request must come from the same origin and carry a one-time value made when the page was rendered. A client that only speaks MCP gets neither, and no MCP tool can confirm.',
                'This is not proof that a person clicked. A client that can run a shell, or drive a full browser, could fake both. Treat such a client as trusted, and read the preview before you confirm. How often a client tried to confirm on its own was not measured.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Headers and a session value raise the bar. They do not prove a human is there.'],
            'state' => [
                'nodes' => [
                    'person' => ['status' => 'ok', 'load' => 20, 'badge' => 'clicks Confirm'],
                    'server' => ['badge' => 'applies it once'],
                ],
                'edges' => [
                    'pending' => ['packets' => ['request' => 0]],
                    'confirm' => ['packets' => ['request' => 2]],
                    'call' => ['packets' => ['tool' => 0]],
                ],
            ],
            'kpis' => [
                'self' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'no MCP tool can confirm a change'],
                ['sign' => '-', 'text' => 'a client with a shell could imitate the click'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'mcp#support',
        ],
        [
            'role' => 'problem',
            'title' => 'A question that needs many looks',
            'body' => [
                'Some questions are not one search. How the docs answer cache works touches several pages and several ideas, and one search returns the passages of only the nearest one.',
                'The agent has to break the question up, look in several places, and put the findings together.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A research question is many small questions, and an answer is only as good as the worst one.'],
            'state' => [
                'nodes' => [
                    'client' => ['status' => 'hidden'],
                    'server' => ['status' => 'hidden'],
                    'person' => ['status' => 'hidden'],
                    'runner' => ['status' => 'ok', 'load' => 10, 'badge' => 'one topic'],
                    'model' => ['status' => 'ok', 'load' => 0],
                    'docs' => ['status' => 'ok', 'load' => 0],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'one search does not cover the topic'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'research#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The model plans, the code searches',
            'body' => [
                'A planner agent splits the topic into sub-questions with structured output, and PHP checks the plan: length, duplicates, and how many are kept. An invalid plan is asked for once more.',
                'Search is not a decision of the model. For every sub-question the code runs a hybrid search of the docs and keeps the first few passages. The phases and their order are fixed, which makes a run bounded and repeatable.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Where the order of work is known, write it in code. Let the model decide only what needs judgement.'],
            'state' => [
                'nodes' => [
                    'runner' => ['load' => 40, 'badge' => 'fixed phases'],
                    'model' => ['load' => 40, 'badge' => 'writes the plan'],
                    'docs' => ['load' => 30, 'badge' => 'searched by code'],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 2]],
                    'search' => ['packets' => ['embedding' => 3]],
                    'found' => ['packets' => ['embedding' => 3]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the plan is checked before any search runs'],
                ['sign' => '~', 'text' => 'the model does not choose when to search'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'research#works',
        ],
        [
            'role' => 'useful',
            'title' => 'Notes, then a report with sources',
            'body' => [
                'A note agent reads the passages as data and returns facts with the passage ids each one relies on. PHP drops any id the search did not return, and a fact left with no valid id is dropped.',
                'The report is written from the notes, and PHP removes every marker that points at no source. With no fact at all, the report is a fixed sentence and no model writes it.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A marker proves that the source exists. It does not prove the source says what the sentence says.'],
            'state' => [
                'nodes' => [
                    'runner' => ['badge' => 'drops unknown ids'],
                    'model' => ['load' => 60, 'badge' => 'notes, then report'],
                    'docs' => ['load' => 10, 'badge' => null],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 3]],
                    'from-model' => ['packets' => ['token' => 3]],
                    'search' => ['packets' => ['embedding' => 0]],
                    'found' => ['packets' => ['embedding' => 0]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'the report cites only sources it was given'],
                ['sign' => '-', 'text' => 'a real source can still be cited for the wrong claim'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'research#support',
        ],
        [
            'role' => 'careful',
            'title' => 'The caps stop the run',
            'body' => [
                'A run has a limit on model calls, a limit on stored steps and a time budget. A run that would go over ends as failed, with the reason, after storing what it had.',
                'In the stored runs, {kpi:done} of the runs that executed ended done. The largest used {kpi:most} model calls and the average was {kpi:calls}, with an average time of {kpi:time}. {kpi:refused} more topics were refused before any call was made.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'One topic makes many calls. The cap is what keeps the bill predictable.'],
            'state' => [
                'nodes' => [
                    'runner' => ['badge' => 'counts calls, steps and time'],
                    'model' => ['load' => 40, 'badge' => null],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 2]],
                    'from-model' => ['packets' => ['token' => 2]],
                ],
            ],
            'kpis' => [
                'done' => $done,
                'refused' => $refused,
                'calls' => $calls,
                'most' => $most,
                'time' => $time,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'every run that executed ended done: {kpi:done}'],
                ['sign' => '~', 'text' => 'the average run takes {kpi:time}'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'research#support',
        ],
        [
            'role' => 'useful',
            'title' => 'A replay costs nothing',
            'body' => [
                'Every phase is stored as a step with its input and output. A replay reads those steps and prints them exactly as they were. It calls no model, no search and no network, so it costs nothing and gives the same text each time.',
                'That makes a run something you can read twice, and compare with the next one. What a replay saves in time or cost was not measured.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Store what each phase saw and produced. Then a bad report can be examined, not guessed at.'],
            'state' => [
                'nodes' => [
                    'runner' => ['badge' => 'reads stored steps'],
                    'model' => ['status' => 'ok', 'load' => 0, 'badge' => 'not called'],
                    'docs' => ['status' => 'ok', 'load' => 0, 'badge' => 'not searched'],
                ],
                'edges' => [
                    'to-model' => ['packets' => ['request' => 0]],
                    'from-model' => ['packets' => ['token' => 0]],
                ],
            ],
            'kpis' => [
                'replay' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a replay makes no model call'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'research#modify',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Publish tools over MCP by wrapping the ones you have, so one set of rules serves every client. Let a write only propose, and keep the confirmation where an MCP client cannot reach it, knowing it is a bar and not a proof.',
                'For research, let the code own the phases, the model own the judgement, and the caps own the cost. Check the citations in PHP, and store every step so a run can be replayed.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Fix the phases in code, let the model judge inside them, and bound the whole.'],
            'state' => [
                'nodes' => [
                    'client' => ['status' => 'ok', 'load' => 10, 'badge' => null],
                    'server' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                    'person' => ['status' => 'ok', 'load' => 10, 'badge' => 'confirms in the browser'],
                    'runner' => ['status' => 'ok', 'load' => 40, 'badge' => 'fixed phases, capped'],
                    'model' => ['status' => 'ok', 'load' => 40, 'badge' => null],
                    'docs' => ['status' => 'ok', 'load' => 20, 'badge' => null],
                ],
                'edges' => [
                    'call' => ['packets' => ['tool' => 1]],
                    'reply' => ['packets' => ['tool' => 1]],
                    'pending' => ['packets' => ['request' => 1]],
                    'confirm' => ['packets' => ['request' => 1]],
                    'to-model' => ['packets' => ['request' => 1]],
                    'from-model' => ['packets' => ['token' => 1]],
                    'search' => ['packets' => ['embedding' => 1]],
                    'found' => ['packets' => ['embedding' => 1]],
                ],
            ],
            'kpis' => [
                'done' => Source::uncompared($done),
                'refused' => Source::uncompared($refused),
                'calls' => Source::uncompared($calls),
                'most' => Source::uncompared($most),
                'time' => Source::uncompared($time),
                'mcp' => 'not measured',
                'self' => 'not measured',
                'replay' => 'not measured',
            ],
            'changed' => [
                ['sign' => '+', 'text' => '{kpi:done} of the executed runs ended done'],
                ['sign' => '~', 'text' => 'MCP calls that ended in an error: {kpi:mcp}'],
            ],
            'learn' => [],
            'deeper' => 'research#modify',
        ],
    ],
];
