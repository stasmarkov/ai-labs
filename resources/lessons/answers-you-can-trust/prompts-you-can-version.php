<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$stats = 'playground/2026-11-14/stats.json';

$runs = Source::figure($stats, 'summary.runs');
$prompts = Source::figure($stats, 'summary.prompts');
$versions = Source::figure($stats, 'summary.versions');
$time = Source::figure($stats, 'summary.average_two_model_local_duration_s', 1);

return [
    'title' => 'Prompts you can version',
    'summary' => 'Keep the wording of an answer in files and numbered versions, compare two versions or two models side by side, and open a saved comparison again with no model call.',
    'minutes' => 11,
    'level' => 'intermediate',
    'guide' => 'prompts',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain how a prompt file and a setting turn the wording of an answer into data.'],
        ['id' => 'lo-2', 'text' => 'Say what the run row stores about the prompt, and why an edit in place breaks it.'],
        ['id' => 'lo-3', 'text' => 'Describe what a saved playground version is, and why a save that changes nothing is refused.'],
        ['id' => 'lo-4', 'text' => 'Say what is checked before a comparison calls any model, and how the models run.'],
        ['id' => 'lo-5', 'text' => 'Explain what a stored comparison keeps, and what opening it again does not need.'],
        ['id' => 'lo-6', 'text' => 'Name what a side-by-side comparison cannot tell you about quality.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Playground page', 'x' => 150, 'y' => 300],
            ['id' => 'playground', 'kind' => 'app', 'label' => 'Lab app', 'x' => 500, 'y' => 300],
            ['id' => 'first', 'kind' => 'model', 'label' => 'First model', 'x' => 850, 'y' => 160],
            ['id' => 'second', 'kind' => 'model', 'label' => 'Second model', 'x' => 850, 'y' => 440],
            ['id' => 'versions', 'kind' => 'app', 'label' => 'Prompt versions', 'x' => 500, 'y' => 80],
            ['id' => 'saved', 'kind' => 'app', 'label' => 'Saved runs', 'x' => 500, 'y' => 520],
        ],
        'edges' => [
            ['id' => 'ask', 'from' => 'browser', 'to' => 'playground'],
            ['id' => 'save', 'from' => 'playground', 'to' => 'versions'],
            ['id' => 'call-first', 'from' => 'playground', 'to' => 'first'],
            ['id' => 'call-second', 'from' => 'playground', 'to' => 'second'],
            ['id' => 'store', 'from' => 'playground', 'to' => 'saved'],
            ['id' => 'replay', 'from' => 'saved', 'to' => 'browser'],
        ],
    ],
    'kpis' => [
        ['id' => 'runs', 'label' => 'Comparisons stored', 'format' => 'count', 'better' => 'none'],
        ['id' => 'prompts', 'label' => 'Prompts saved', 'format' => 'count', 'better' => 'none'],
        ['id' => 'versions', 'label' => 'Versions saved', 'format' => 'count', 'better' => 'none'],
        ['id' => 'time', 'label' => 'Two local models, average total', 'format' => 's', 'better' => 'lower'],
        ['id' => 'replay', 'label' => 'Model calls to open a saved run', 'format' => 'text', 'better' => 'none'],
        ['id' => 'quality', 'label' => 'Quality per prompt version', 'format' => 'text', 'better' => 'none'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'Wording buried in code',
            'body' => [
                'The instructions that shape an answer are easy to leave inside a class. Then a change of wording is a change of code, and nobody can say later which wording wrote which answer.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'If a result does not name its wording, a better or worse answer has no cause you can look up.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'playground' => ['badge' => 'wording inside code'],
                    'first' => ['status' => 'hidden'],
                    'second' => ['status' => 'hidden'],
                    'versions' => ['status' => 'hidden'],
                    'saved' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'runs' => '—',
                'prompts' => '—',
                'versions' => '—',
                'time' => '—',
                'replay' => '—',
                'quality' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'no record of which wording wrote an answer'],
            ],
            'learn' => [],
            'deeper' => 'prompts#works',
        ],
        [
            'role' => 'solution',
            'title' => 'One file per version',
            'body' => [
                'Each prompt is a plain text file whose name carries its version, and the old file stays in the repository. One setting per agent names the file in use, so switching a version is an environment change, not a code change.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'History lives in source control. A change never rewrites an older version.'],
            'state' => [
                'nodes' => [
                    'playground' => ['badge' => 'reads the file the setting names'],
                    'versions' => ['status' => 'ok', 'badge' => 'one file per version'],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'wording is a file, picked by a setting'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'prompts#works',
        ],
        [
            'role' => 'solution',
            'title' => 'The run names its wording',
            'body' => [
                'The agent derives a label from the file name, name and version, and the run row stores it. Two rows made with different wording can be set side by side later.',
                'Editing a file in place breaks that link: old rows would name text they never used. Copy to a new version, edit the copy and point the setting at it.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'An edit in place changes old results after the fact. Add a version instead.'],
            'state' => [
                'nodes' => [
                    'playground' => ['badge' => 'logs name and version'],
                    'saved' => ['status' => 'ok', 'badge' => 'row names the version'],
                ],
                'edges' => [
                    'store' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'every run row names the wording that wrote it'],
                ['sign' => '~', 'text' => 'an edit in place would break old rows, so there is none'],
            ],
            'learn' => ['lo-1', 'lo-2'],
            'deeper' => 'prompts#support',
        ],
        [
            'role' => 'solution',
            'title' => 'The playground saves versions',
            'body' => [
                'In the playground a prompt is a name with numbered versions. A version holds the system prompt, a short note, the temperature and the maximum tokens. Saving a new prompt creates the first version, and every later save creates the next number.',
                'The save locks the prompt row before it reads the highest number, so two saves at once cannot take the same one.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'A run that used a version can always show the exact text it ran with.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'saves a prompt'],
                    'versions' => ['badge' => 'numbered, never edited'],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a prompt is a name with numbered versions'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'playground#works',
        ],
        [
            'role' => 'careful',
            'title' => 'A save that changes nothing',
            'body' => [
                'There is no way to edit a saved version: no update route and no update method. If the text, the temperature and the maximum tokens all equal the latest version, the save is refused as unchanged, and a change to the note alone counts as unchanged.',
                'Text equal to an older version is allowed and becomes a new version.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A note cannot be added to a version afterwards. Write it when you save.'],
            'state' => [
                'nodes' => [
                    'playground' => ['badge' => 'refuses an unchanged save'],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 0, 'failure' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'a save equal to the latest version is refused'],
                ['sign' => '~', 'text' => 'a new note alone does not make a new version'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'playground#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Two models, one input',
            'body' => [
                'A run takes one source for the wording, a saved version or a prompt typed on the page and labelled as unsaved, and one input. It needs two or three models, each a distinct provider and model pair.',
                'Everything is checked before the first call. A bad run is answered with a validation error and no model is called.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Validate the whole run first, so a typo never costs a model call.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => 'picks a version and two models'],
                    'playground' => ['badge' => 'checks everything first'],
                    'first' => ['status' => 'ok', 'load' => 0],
                    'second' => ['status' => 'ok', 'load' => 0],
                    'versions' => ['badge' => null],
                ],
                'edges' => [
                    'save' => ['packets' => ['failure' => 0]],
                    'ask' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'a run is refused before any model call when its input is not valid'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'playground#works',
        ],
        [
            'role' => 'careful',
            'title' => 'One after the other',
            'body' => [
                'The models run in turn, not in parallel, because a local model server answers one request at a time: parallel calls would only make the timings wrong. The answer comes back when all are done, {kpi:time} on average for two local models.',
                'The input is checked against the injection patterns, but a match only flags the run. The playground exists to see how a model reacts to such text.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Compare at temperature zero, with one input and one setting for every column, and repeat before you trust a difference.'],
            'state' => [
                'nodes' => [
                    'first' => ['status' => 'busy', 'load' => 100, 'badge' => 'runs first'],
                    'second' => ['load' => 0, 'badge' => 'waits its turn'],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 0]],
                    'call-first' => ['packets' => ['request' => 2]],
                    'call-second' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'time' => $time,
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'the models run in turn, so each time is its own'],
                ['sign' => '~', 'text' => 'two local models took {kpi:time} on average'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'playground#support',
        ],
        [
            'role' => 'solution',
            'title' => 'Every column is stored',
            'body' => [
                'Each column keeps its output, tokens in and out, and the time around the call. Each call is also a row in the run log with a label such as the prompt name and version, or unsaved, and the column links to it.',
                'A failed or timed out column is stored as such and the others still stand. The lab\'s own store holds {kpi:runs} comparisons, and {kpi:prompts} prompts saved as {kpi:versions} versions.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'One failure must not hide the other columns.'],
            'state' => [
                'nodes' => [
                    'first' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'second' => ['status' => 'ok', 'load' => 30, 'badge' => null],
                    'saved' => ['badge' => 'output, tokens, time, status'],
                ],
                'edges' => [
                    'call-first' => ['packets' => ['request' => 0]],
                    'call-second' => ['packets' => ['request' => 0]],
                    'store' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'runs' => $runs,
                'prompts' => $prompts,
                'versions' => $versions,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'each column keeps its own numbers and its own run row'],
                ['sign' => '~', 'text' => 'comparisons stored: {kpi:runs}'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'playground#numbers',
        ],
        [
            'role' => 'useful',
            'title' => 'A saved run opens again',
            'body' => [
                'A stored comparison opens by its id with its own copy of the system prompt, the input and the results, so it stays readable even if the prompt is deleted later. Opening it calls no model.',
                'To compare two versions, run both on the same input and the same models, then open the line diff of their system prompts with the settings side by side.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A comparison you can reopen is evidence. One you can only rerun is a memory.'],
            'state' => [
                'nodes' => [
                    'saved' => ['badge' => 'a copy of everything'],
                    'browser' => ['badge' => 'opens a stored run'],
                ],
                'edges' => [
                    'store' => ['packets' => ['request' => 0]],
                    'replay' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'replay' => 'none',
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a stored run replays with no model call'],
                ['sign' => '+', 'text' => 'two versions can be read as a line diff'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'playground#modify',
        ],
        [
            'role' => 'careful',
            'title' => 'What it cannot tell you',
            'body' => [
                'The playground shows how two versions or two models differ on one input. It does not say which is better. There is no quality figure for each prompt version here, so quality per version is not measured.',
                'Above temperature zero an answer can change from run to run, so a difference between two columns can be chance.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A side-by-side view helps you look. Deciding needs a labelled set of questions and a score.'],
            'state' => [
                'nodes' => [
                    'browser' => ['badge' => null],
                    'playground' => ['badge' => 'shows differences, not winners'],
                ],
                'edges' => [
                    'replay' => ['packets' => ['request' => 0]],
                ],
            ],
            'kpis' => [
                'quality' => 'not measured',
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'quality per version: not measured'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'prompts#numbers',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'Keep the wording in a versioned file, name the version in a setting and log it with every run. Add a version instead of editing one, and refuse a save that changes nothing.',
                'Compare on one input at temperature zero and keep every column. To use a winner, copy its text into a new prompt file and point the setting at it. The old file stays, so old runs still name what they used.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Prompts are code. Version them, review them and keep secrets out of them.'],
            'state' => [
                'nodes' => [
                    'playground' => ['badge' => null],
                    'first' => ['status' => 'ok', 'load' => 30],
                    'second' => ['status' => 'ok', 'load' => 30],
                    'versions' => ['badge' => 'numbered, never edited'],
                    'saved' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                    'save' => ['packets' => ['request' => 1]],
                    'call-first' => ['packets' => ['request' => 1]],
                    'call-second' => ['packets' => ['request' => 1]],
                    'store' => ['packets' => ['request' => 1]],
                    'replay' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'runs' => Source::uncompared($runs),
                'prompts' => Source::uncompared($prompts),
                'versions' => Source::uncompared($versions),
                'time' => Source::uncompared($time),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'wording is versioned and named on every run'],
                ['sign' => '+', 'text' => 'comparisons are stored and open with no model call'],
            ],
            'learn' => [],
            'deeper' => 'prompts#modify',
        ],
    ],
];
