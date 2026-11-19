<?php

declare(strict_types=1);

$run = 'llm/2026-11-13/run.json';
$search = 'llm-load/2026-11-13/search-under-load.json';

return [
    'kind' => 'foundation',
    'title' => 'Scene lessons',
    'summary' => 'A scene lesson teaches one idea in about ten steps. Each step moves a picture of the system, a strip of measured numbers and a list of what changed together, so you see a problem and its fix instead of reading about them. The course is at /ai/learn.',
    'works' => [
        [
            'title' => 'One step changes four things at once',
            'body' => 'A step is a small change to the same scene. The picture shows boxes for the parts (browsers, PHP workers, the model server, the search database, the queue) and arrows between them, with small dots for requests and tokens moving along the arrows. Beside it sit a strip of numbers, a list of what changed since the step before, and a panel with the step in words. All four come from one step, so they never disagree. A step is either a problem, a fix or a result, and the badge says which.',
        ],
        [
            'title' => 'A lesson is one file of plain data',
            'body' => 'A lesson file returns an array: a title, the outcomes you will learn, the scene (nodes and edges), the numbers to show and the steps. A step does not repeat the whole scene. It holds a patch on the step before, such as a box under load or an arrow that appears. The repository only loads and checks the file: it stops with the name of the file and the rule it broke, so a mistake never reaches a visitor as a half-drawn scene. The lesson service then replays the patches in order, so a step shows exactly what the earlier steps built up. Only lessons the course file lists can be opened.',
            'snippet' => 'App\Lessons\LessonRepository::find',
        ],
        [
            'title' => 'The numbers are read, not typed',
            'body' => 'A number in a lesson is a pointer to a value inside a committed result file, with a scale and a rounding. A step can write `{num:...}` in its text or show a number in the strip, and the lesson service looks the value up with the same reader the guides use. A pointer that finds nothing is an error, and so is a file in the uncommitted raw folder. A number shows green or red when it got better or worse than the step before; a step that switches scenario, such as another model, marks its numbers as not compared. The one exception is a word such as "not measured", for a step where nothing was measured. This is how the lesson stays true: re-run a measurement, commit the new file, and every figure follows.',
            'snippet' => 'App\Lessons\LessonNumbers',
        ],
        [
            'title' => 'The page gets resolved data only',
            'body' => 'The lesson page receives the finished steps: the boxes and arrows that are visible in each step, the numbers already rounded, and links already turned into addresses. It carries no source code and no result files, so the page stays small, and it asks the database and the model for nothing. The browser draws the scene as SVG once per step and moves the dots outside the page framework, with the frame timer, only while the scene is playing, on screen and in a visible tab. A step change happens in the browser without asking the server again: it adds a history entry, so the Back button goes to the step before, and a link to a step opens that step.',
            'snippet' => 'App\Lessons\LessonService::step',
        ],
        [
            'title' => 'Keyboard, motion and progress',
            'body' => 'The arrow keys move between steps, R replays the step and Space pauses the dots. When the visitor asks for reduced motion the dots become still dashed markers and nothing slides. A hidden text list describes the visible boxes and the step for a screen reader, and a live region announces the step title and the numbers. Which steps you passed is kept only in your browser, in local storage; there is no account and nothing is sent.',
        ],
    ],
    'failures' => [
        [
            'title' => 'The lesson page says not found, or fails',
            'body' => "**Cause:** not found means the module, the lesson or the step in the address does not exist, the lesson is only announced as coming soon, or the course file does not list it yet. A server error means the lesson file is broken; the problem is written to the log, and the course page lists the lesson as unavailable.\n\n**Fix:** open the course page and follow a link from there. For a new lesson, add it to the course file. For a broken file, read the log line: it names the file and the rule.",
        ],
        [
            'title' => 'The loader names a number it cannot find',
            'body' => "**Cause:** the result file or the path inside it is wrong, or the measurement was re-run into a new dated folder.\n\n**Fix:** open the result file and copy the path of the value exactly. Keys with dots or colons in them, such as a model name, go in quotes.",
        ],
        [
            'title' => 'A change line is rejected for a digit',
            'body' => "**Cause:** change lines may hold no digit outside a placeholder, so a typed figure cannot go stale.\n\n**Fix:** word the line without the figure, or put the figure in as a number pointer. A name that carries a digit, such as a percentile, is better kept for the body text.",
        ],
        [
            'title' => 'The dots do not move',
            'body' => "**Cause:** the system asks for reduced motion, or the step was paused with Space.\n\n**Fix:** press R to replay the step, or turn off reduced motion in the system settings. With reduced motion the dots are static markers by design.",
        ],
        [
            'title' => 'The ticks are gone',
            'body' => "**Cause:** progress lives in the browser's local storage, so another browser, a private window or cleared site data starts from zero.\n\n**Fix:** nothing to repair. A deep link to the last step marks the lesson done again.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Write a lesson',
            'body' => "1. Add a line for it in the course file, under a module, with a short title, a summary and a level.\n2. Create the lesson file under its module in the lessons folder. Start with three to six outcomes, each one a sentence a learner could answer.\n3. Draw the scene: a handful of boxes with a kind and a position, and the arrows between them. Keep it to what the lesson needs.\n4. Write the steps in the order problem, cause, fix, result. Give every step a role, a title, a body and a patch to the scene, and a callout where one helps.\n5. Point each number at a value in a committed result file. Do not type a figure.\n6. Tie every outcome to the step that teaches it, add a link to the guide tab that goes deeper (a tab the guide page shows, such as works, support or modify), then reload the page: a mistake is reported with the rule it broke.\n\nThe suite loads every lesson, so a broken file fails the tests before it ships.",
        ],
        [
            'title' => 'Add a number to a lesson',
            'body' => "Find the value in a result file under the results folder, then write a pointer with the file, the path inside it, and optionally a scale (for example `0.001` to turn milliseconds into seconds) and a rounding. Use it in the strip of numbers or inside a step's text. If a step switches scenario, for example to another model, add `'trend' => false` so the strip does not call the change better or worse. If you re-run the measurement, point at the new file and the lesson follows.",
        ],
        [
            'title' => 'Walk a lesson with the keyboard',
            'body' => 'Open the course page, start the lesson and use the right and left arrows to move, R to replay and Space to pause. Then switch on reduced motion in the system settings and reload: the dots should be still markers. The page keeps working with no pointer at all.',
        ],
        [
            'title' => 'The same pattern in other places',
            'body' => "A scene lesson is a **state machine drawn as a picture**: a list of states, each a small change on the last, with the evidence beside it. Any system you want a new teammate to understand fits: how a request travels, how a queue backs up, how a cache fills.\n\nThe habit worth taking is the rule about numbers: a figure on a page should come from the measurement that produced it.",
        ],
    ],
    'support' => [
        [
            'name' => 'What a lesson page touches',
            'status' => 'logged',
            'note' => 'It reads lesson files and result files and nothing else: no database row is written and no model is called. A broken lesson file is written to the log with its name and the rule, and only that lesson answers not found; the course page lists it as unavailable.',
        ],
        [
            'name' => 'Safety of the lesson files',
            'status' => 'guarded',
            'note' => 'The loader rejects a lesson that has a broken shape, a number it cannot resolve, a digit typed in a change line, an outcome no step teaches, or a link to a guide that does not exist. Lesson text is drawn from parts (plain, bold, code), never as HTML. Tests load every committed lesson and check the pilot figures against the benchmarks page.',
        ],
        [
            'name' => 'A lesson looks wrong',
            'status' => 'runbook',
            'note' => "1. Open the lesson at its address and read the log for a line naming the file.\n2. If it says a number is missing, open the result file and check the path.\n3. If a box or arrow is missing, check the patch of the step before it: each step builds on the last.\n4. If the dots do not move, press R and check the system setting for reduced motion.\n5. Run the lesson tests; they load every file and name the rule that fails.",
        ],
    ],
    'numbers' => [
        ['label' => 'qwen3.5:9b: median time to first token (ms), the first number the pilot lesson shows', 'file' => $run, 'path' => 'models.qwen3.5:9b.speed.ttft_ms.median'],
        ['label' => 'qwen3.5:9b: median writing speed (tokens per second)', 'file' => $run, 'path' => 'models.qwen3.5:9b.speed.generation_tokens_per_s.median'],
        ['label' => 'Search p95 with no model load (ms), the baseline in the pilot', 'file' => $search, 'path' => 'servers.1.without_load.p95_ms'],
    ],
    'experiments' => [
        [
            'title' => 'Walk the pilot lesson',
            'predictions' => [
                ['id' => 'a', 'text' => 'The time to first token and the writing speed both get worse as users are added.'],
                ['id' => 'b', 'text' => 'The time to first token grows with the queue, while the writing speed of a running answer stays about the same.'],
                ['id' => 'c', 'text' => 'Nothing changes until the model server runs out of memory.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Open the course, start "One model, many users" and watch the strip of numbers while you step from one user to sixteen. The first token comes later because requests wait in line; once an answer is being written, its speed does not depend on how many are waiting.'],
            'page' => 'ai.learn.index',
            'params' => [],
        ],
    ],
    'related' => ['local-models', 'metrics'],
    'build' => [
        ['step' => 'Describe the scene as data', 'detail' => 'List the boxes and arrows once, in a plain file, and let every step change only what it must.', 'tab' => 'recipes'],
        ['step' => 'Replay patches in order', 'detail' => 'Build each step by applying its patch to the step before, so a step can never contradict an earlier one.', 'tab' => 'support'],
        ['step' => 'Read numbers from result files', 'detail' => 'Resolve a pointer when the lesson loads and fail loudly when it finds nothing.', 'tab' => 'support'],
        ['step' => 'Send resolved data only', 'detail' => 'Give the page finished steps and addresses, never source code or raw files.', 'tab' => 'support'],
        ['step' => 'Draw with SVG and one frame timer', 'detail' => 'Move the dots from a single frame callback and offer a still version for reduced motion.', 'tab' => 'support'],
        ['step' => 'Check every lesson in the tests', 'detail' => 'Load all lesson files in a test so a broken one fails before it ships.', 'tab' => 'support'],
    ],
    'watch' => [
        ['point' => 'Numbers come from files', 'detail' => 'A typed figure goes stale; a pointer to a result file does not.', 'tab' => 'support'],
        ['point' => 'Each step builds on the last', 'detail' => 'A missing box usually means an earlier patch did not add it.', 'tab' => 'support'],
        ['point' => 'Reduced motion is a real setting', 'detail' => 'The dots stop and become markers, so the lesson still reads without motion.', 'tab' => 'support'],
        ['point' => 'Progress stays in your browser', 'detail' => 'It is kept in local storage and is lost with the site data.', 'tab' => 'support'],
    ],
    'elsewhere' => [
        ['domain' => 'Onboarding a teammate', 'problem' => 'A new person has to understand how a request moves through several services.', 'how' => 'Draw the services as boxes, add one step per hop and show the real latency beside it.'],
        ['domain' => 'Incident reviews', 'problem' => 'A written timeline hides how a small slowdown spread.', 'how' => 'Replay the incident as steps, with the numbers of the time beside each one.'],
    ],
];
