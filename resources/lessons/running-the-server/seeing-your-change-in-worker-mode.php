<?php

declare(strict_types=1);

use App\Lessons\LessonSources as Source;

/**
 * Every figure is read from a committed result file; the text only places it. State is a patch on the step before.
 */
$classic = 'reload/2026-11-17/classic.json';
$workerWatch = 'reload/2026-11-17/worker-watch.json';
$workerPlain = 'reload/2026-11-17/worker-nowatch.json';
$octaneWatch = 'reload/2026-11-17/octane-watch.json';
$octanePlain = 'reload/2026-11-17/octane-nowatch.json';

$classicTime = Source::figure($classic, 'median_ms');
$watchTime = Source::figure($workerWatch, 'median_ms');
$restartTime = Source::figure($workerPlain, 'restart_ms.median');
$syncTime = Source::figure($workerWatch, 'mutagen_median_ms');

return [
    'title' => 'Seeing your change in worker mode',
    'summary' => 'Why a saved file is not served while workers keep the app in memory, how a manual restart and a watcher bring the new code in, how long each takes, and how Xdebug stops inside a worker.',
    'minutes' => 12,
    'level' => 'intermediate',
    'guide' => 'worker-dx',
    'learn' => [
        ['id' => 'lo-1', 'text' => 'Explain why a saved file is not served in worker mode.'],
        ['id' => 'lo-2', 'text' => 'Name two ways to replace the workers and how long each takes.'],
        ['id' => 'lo-3', 'text' => 'Say what the watcher watches, and what hides an edit anyway.'],
        ['id' => 'lo-4', 'text' => 'Read how much of the wait is the file sync.'],
        ['id' => 'lo-5', 'text' => 'Explain how Xdebug is triggered in a worker and what a debugged request does to its worker.'],
        ['id' => 'lo-6', 'text' => 'Describe how the time from save to new code is measured.'],
    ],
    'scene' => [
        'nodes' => [
            ['id' => 'editor', 'kind' => 'person', 'label' => 'Editor', 'x' => 150, 'y' => 200],
            ['id' => 'files', 'kind' => 'app', 'label' => 'Files in the container', 'x' => 500, 'y' => 120],
            ['id' => 'workers', 'kind' => 'workers', 'label' => 'PHP workers', 'x' => 500, 'y' => 380],
            ['id' => 'browser', 'kind' => 'browser', 'label' => 'Browser', 'x' => 150, 'y' => 480],
            ['id' => 'debugger', 'kind' => 'tools', 'label' => 'Debugger', 'x' => 850, 'y' => 380],
        ],
        'edges' => [
            ['id' => 'save', 'from' => 'editor', 'to' => 'files'],
            ['id' => 'load', 'from' => 'files', 'to' => 'workers'],
            ['id' => 'ask', 'from' => 'browser', 'to' => 'workers'],
            ['id' => 'reply', 'from' => 'workers', 'to' => 'browser'],
            ['id' => 'debug', 'from' => 'workers', 'to' => 'debugger'],
        ],
    ],
    'kpis' => [
        ['id' => 'classic', 'label' => 'Classic mode, save to new code (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'watch', 'label' => 'Worker mode with watcher (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'restart', 'label' => 'Manual restart in worker mode, end to end (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'sync', 'label' => 'File sync part of the wait (median)', 'format' => 'ms', 'better' => 'lower'],
        ['id' => 'boot', 'label' => 'Cost of a debugged request', 'format' => 'text', 'better' => 'none'],
    ],
    'steps' => [
        [
            'role' => 'problem',
            'title' => 'You save a file and the page does not change',
            'body' => [
                'In worker mode the app boots once and serves many requests from the same memory. The classes it has loaded stay as they were, so a saved file is not read again.',
                'Without a watcher, {num:'.$workerPlain.'#never|count|0} trials in a row never showed the new code before the time limit ran out. Only a replaced worker reads the new file.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A worker serves the code it loaded when it started, until something replaces it.'],
            'state' => [
                'nodes' => [
                    'editor' => ['badge' => 'saves a file'],
                    'files' => ['badge' => 'new code'],
                    'workers' => ['load' => 40, 'badge' => 'still the old code'],
                    'browser' => ['badge' => 'sees the old page'],
                    'debugger' => ['status' => 'hidden'],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 1]],
                    'ask' => ['packets' => ['request' => 1]],
                    'reply' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'classic' => '—',
                'watch' => '—',
                'restart' => '—',
                'sync' => '—',
                'boot' => '—',
            ],
            'changed' => [
                ['sign' => '-', 'text' => 'the saved file is not served'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'worker-dx#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Classic mode starts fresh each time',
            'body' => [
                'Classic mode starts PHP anew for every request, so it reads the file again. Even so, an edit took a median of {kpi:classic} to show.',
                'The opcode cache rechecks file timestamps only every so often, so an edit waits for that check, plus the file sync into the container.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Classic mode is not instant either. It waits for the cache to notice the file.'],
            'state' => [
                'nodes' => [
                    'workers' => ['badge' => 'fresh PHP per request'],
                    'browser' => ['badge' => 'sees the new page after a wait'],
                ],
            ],
            'kpis' => [
                'classic' => $classicTime,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the edit shows after {kpi:classic}'],
            ],
            'learn' => ['lo-1'],
            'deeper' => 'worker-dx#works',
        ],
        [
            'role' => 'solution',
            'title' => 'Replace the workers by hand',
            'body' => [
                'One command replaces the workers: on the server\'s own worker mode it calls the admin API, on Octane it runs the framework reload. End to end, the new code showed after a median of {kpi:restart} in worker mode and {num:'.$octanePlain.'#restart_ms.median|ms|0} on Octane.',
                'In worker mode the call itself took {num:'.$workerPlain.'#restart_call_ms.median|ms|0}. The rest is the container command, the app boot and the first request to the new workers.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Most of a manual restart is the command around it, not the swap.'],
            'state' => [
                'nodes' => [
                    'workers' => ['load' => 80, 'badge' => 'replaced by hand'],
                    'browser' => ['badge' => 'sees the new page'],
                ],
                'edges' => [
                    'load' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'restart' => $restartTime,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a restart brings the new code in after {kpi:restart}'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'worker-dx#works',
        ],
        [
            'role' => 'solution',
            'title' => 'A watcher replaces them for you',
            'body' => [
                'The server has a native watcher: watch lines in the worker block of its config. Octane uses the same watcher when its command gets the watch flag. One command switches it on for the active mode and restarts the project.',
                'With the watcher on, the new code showed after a median of {kpi:watch} in worker mode and {num:'.$octaneWatch.'#median_ms|ms|0} on Octane, with no restart by hand.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'A watcher turns restart into save. The wait drops to the time of one worker swap.'],
            'state' => [
                'nodes' => [
                    'files' => ['badge' => 'watched'],
                    'workers' => ['load' => 60, 'badge' => 'replaced on change'],
                ],
                'edges' => [
                    'load' => ['packets' => ['request' => 3]],
                ],
            ],
            'kpis' => [
                'watch' => $watchTime,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'a save shows after {kpi:watch}, with no restart by hand'],
            ],
            'learn' => ['lo-2'],
            'deeper' => 'worker-dx#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Whole lines, so empty means off',
            'body' => [
                'A bare watch with no value falls back to the server\'s default patterns and watches everything, so an empty setting would no longer mean off. The config holds one variable on a line of its own, and the overlay fills it with complete watch lines.',
                'With the watcher off, that line is empty, nothing is watched and the server behaves as before. The project configuration and the env file are never changed by the command.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Make the off state the empty state. A default that means everything turns off into on.'],
            'state' => [
                'nodes' => [
                    'files' => ['badge' => 'whole lines or nothing'],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'an empty setting watches nothing'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'worker-dx#works',
        ],
        [
            'role' => 'careful',
            'title' => 'Some edits are still not served',
            'body' => [
                'The watcher covers PHP files in the app, config, routes, resources and bootstrap folders, the lock file and the env file. A file in vendor or storage is not watched.',
                'A cached config or routes file hides your edit even when the workers were replaced, so clear it first. A server switch also removes the overlay, so turn the watcher on again after one.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'When an edit does not show, check the mode, the watcher and the caches, in that order.'],
            'state' => [
                'nodes' => [
                    'files' => ['badge' => 'not vendor, not storage'],
                    'browser' => ['badge' => 'old page: cached config?'],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '~', 'text' => 'cached config or routes hide an edit'],
            ],
            'learn' => ['lo-3'],
            'deeper' => 'worker-dx#support',
        ],
        [
            'role' => 'useful',
            'title' => 'How much of the wait is the file sync',
            'body' => [
                'On a Mac, files reach the container through a sync, and that is part of every wait. Timed apart, the sync took a median of {kpi:sync} of the {kpi:watch} reload.',
                'So most of the wait is the swap of workers, or in classic mode the cache check, not the sync.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Time the parts of a loop apart. The part you blame is often the small one.'],
            'state' => [
                'nodes' => [
                    'files' => ['badge' => 'sync is a small part'],
                    'editor' => ['badge' => null],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 2]],
                ],
            ],
            'kpis' => [
                'sync' => $syncTime,
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'the sync takes {kpi:sync} of the {kpi:watch}'],
            ],
            'learn' => ['lo-4'],
            'deeper' => 'worker-dx#numbers',
        ],
        [
            'role' => 'problem',
            'title' => 'A breakpoint in a long-lived worker',
            'body' => [
                'Xdebug checks its trigger once, when the worker starts, so a trigger in a later request\'s query or cookie is never seen. The breakpoint is never hit.',
                'Without a trigger mode every worker request would try to reach a debugger. So the settings use the trigger mode, and a request opens a session only when it carries the trigger.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'A debugger that starts on every request slows a worker. Ask for it with a trigger.'],
            'state' => [
                'nodes' => [
                    'debugger' => ['status' => 'ok', 'badge' => 'waits for a trigger'],
                    'workers' => ['badge' => 'trigger seen only at start'],
                    'files' => ['badge' => null],
                ],
                'edges' => [
                    'ask' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '-', 'text' => 'a trigger on a later request is never seen'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'worker-dx#works',
        ],
        [
            'role' => 'solution',
            'title' => 'A middleware connects, the worker is replaced',
            'body' => [
                'A middleware checks the trigger on every request and opens the session by hand, only in a worker. Classic mode is left to Xdebug, which checks the trigger each time.',
                'Xdebug cannot end a session from PHP: it lasts as long as the script, and in a worker the script is the whole worker. So after the response, a debugged request ends its worker and a fresh one starts.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'Add the trigger to every request you want to debug. Each one gets its own fresh worker afterwards.'],
            'state' => [
                'nodes' => [
                    'debugger' => ['badge' => 'session opened'],
                    'workers' => ['load' => 70, 'badge' => 'replaced after the response'],
                ],
                'edges' => [
                    'debug' => ['packets' => ['request' => 2]],
                    'reply' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'only the request with the trigger stops'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'worker-dx#implementation',
        ],
        [
            'role' => 'careful',
            'title' => 'What a debugged request costs',
            'body' => [
                'Each debugged request costs one worker boot, about the time of a first request after a restart. How long that is on this machine has not been measured: it is not measured.',
                'Turn Xdebug off when you are done, and turn it on again after every restart, because a restart switches it off.',
            ],
            'callout' => ['kind' => 'careful', 'text' => 'Leave the debugger off by default. Switch it on for the minutes you need it.'],
            'state' => [
                'nodes' => [
                    'debugger' => ['badge' => 'off by default'],
                    'workers' => ['badge' => 'one boot per debugged request'],
                ],
                'edges' => [
                    'debug' => ['packets' => ['request' => 0]],
                ],
            ],
            'kpis' => [
                'boot' => 'not measured',
            ],
            'changed' => [
                ['sign' => '~', 'text' => 'cost of a debugged request: not measured'],
            ],
            'learn' => ['lo-5'],
            'deeper' => 'worker-dx#support',
        ],
        [
            'role' => 'useful',
            'title' => 'How the wait was measured',
            'body' => [
                'The script writes a unique value into a small class, notes the time and asks a debug route again and again until the answer holds that value. The result is a number of milliseconds, or never when the time limit passes.',
                'It runs five rows: classic on its own, since it has no watcher, then Octane and worker mode, each with and without the watcher. Median and slowest are taken over the numeric trials only.',
            ],
            'callout' => ['kind' => 'useful', 'text' => 'To time any feedback loop, plant a marker at one end and poll for it at the other, with a cap.'],
            'state' => [
                'nodes' => [
                    'debugger' => ['badge' => null],
                    'editor' => ['badge' => 'writes a marker'],
                    'browser' => ['badge' => 'polls until it shows'],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 1]],
                    'ask' => ['packets' => ['request' => 3]],
                    'reply' => ['packets' => ['request' => 3]],
                ],
            ],
            'kpis' => [],
            'changed' => [
                ['sign' => '+', 'text' => 'one marker and one poll time every row the same way'],
            ],
            'learn' => ['lo-6'],
            'deeper' => 'worker-dx#modify',
        ],
        [
            'role' => 'summary',
            'title' => 'What to take away',
            'body' => [
                'A worker serves the code it loaded, so a saved file needs a replaced worker. Do it by hand in {kpi:restart}, or let the watcher do it, so a save shows after {kpi:watch}.',
                'Check the mode, the watcher and the caches when an edit does not show. Debug with a trigger, expect a fresh worker after each debugged request, and keep the debugger off otherwise.',
            ],
            'callout' => ['kind' => 'key-idea', 'text' => 'Replace long-lived memory when its source changes, and time the loop from your action to the visible effect.'],
            'state' => [
                'nodes' => [
                    'editor' => ['badge' => null],
                    'files' => ['badge' => null],
                    'workers' => ['load' => 40, 'badge' => null],
                    'browser' => ['badge' => null],
                    'debugger' => ['badge' => null],
                ],
                'edges' => [
                    'save' => ['packets' => ['request' => 1]],
                    'load' => ['packets' => ['request' => 1]],
                    'ask' => ['packets' => ['request' => 1]],
                    'reply' => ['packets' => ['request' => 1]],
                ],
            ],
            'kpis' => [
                'classic' => Source::uncompared($classicTime),
                'watch' => Source::uncompared($watchTime),
                'restart' => Source::uncompared($restartTime),
                'sync' => Source::uncompared($syncTime),
            ],
            'changed' => [
                ['sign' => '+', 'text' => 'with a watcher a save shows after {kpi:watch}'],
                ['sign' => '~', 'text' => 'by hand it takes {kpi:restart}'],
            ],
            'learn' => [],
            'deeper' => 'worker-dx#modify',
        ],
    ],
];
