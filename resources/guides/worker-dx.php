<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'Worker-mode developer experience',
    'summary' => 'In worker mode the app stays in memory, so a saved PHP file is not served until the workers are replaced. A watcher fixes that, Xdebug needs a trigger to stop inside a worker request, and the time from save to new code is measured for five ways of working. Everything here is opt-in and local.',
    'works' => [
        [
            'title' => 'Why a saved file is not served',
            'body' => 'A worker boots the app once and serves many requests from the same memory, so the class files it loaded stay as they were. Classic mode starts PHP fresh for every request and reads the file again. In a worker, only a replaced worker reads the new file. That is why a worker needs either a manual restart (`ddev artisan frankenphp:workers:restart`) or a watcher.',
        ],
        [
            'title' => 'One watcher for both worker modes',
            'body' => 'FrankenPHP has a native watcher: a `watch` line in the `worker` block of its Caddyfile, one glob per line. Octane uses the same watcher when its command gets `--watch`. `ddev dev-watch on` switches it for the active mode through a gitignored overlay and restarts the web container. On worker mode the overlay sets the env var `WORKER_WATCH` to one `watch` line per directory (the app, config, routes, resources and bootstrap folders, as absolute paths under `/var/www/html`), the file `composer.lock`, plus `.env`. On Octane the overlay adds `--watch` to the daemon command. The benchmark scripts that tune the server write an overlay for the same daemon command, so the two are never active together: `dev-watch on` refuses on Octane while such an overlay exists, and those scripts refuse while dev-watch is on. `ddev dev-watch off` removes the overlay, and so does `ddev server`. Classic mode has nothing to watch, so the command says so and writes nothing.',
        ],
        [
            'title' => 'Why the value is whole lines',
            'body' => "A bare `watch` with an empty value falls back to FrankenPHP's default patterns and watches everything, so an empty setting would no longer mean off. The Caddyfile holds `{\$WORKER_WATCH}` on a line of its own, and the overlay fills it with complete `watch ...` lines. With dev-watch off the line is empty, nothing is watched and the server behaves as before. The project's DDEV configuration and the env file are never changed by the command.",
        ],
        [
            'title' => 'Xdebug stops in a worker only on a trigger',
            'body' => 'The settings file sets `xdebug.start_with_request=trigger`, the client host `host.docker.internal` and port `9003`, so a request opens a session only when it carries `XDEBUG_TRIGGER`, `XDEBUG_SESSION` or `XDEBUG_SESSION_START` as a query parameter, a POST field or a cookie (and the value of `xdebug.trigger_value` when one is set). Without the trigger mode every worker request would try to connect. A worker has a catch: Xdebug checks the trigger once, when the worker starts, so a per-request query or cookie is never seen. A middleware calls `xdebug_connect_to_client()` for a request with the trigger, only in a worker; classic mode is left to Xdebug, which checks the trigger on every request. When Xdebug is off that function does not exist and the middleware does nothing, so no request tries to reach a debugger. Xdebug cannot end a session from PHP: the session lasts as long as the PHP script, and in a worker the script is the whole worker. So a request that opened a session ends its worker once the response is sent: the worker script leaves its request loop and FrankenPHP starts a fresh worker on that thread, as it does at the request limit. The next request, with or without the trigger, runs without the debugger. The cost is one worker boot per debugged request, about the time of a first request after a restart.',
            'snippet' => 'App\Http\Middleware\ConnectXdebugOnTrigger::handle',
        ],
        [
            'title' => 'Measuring the time from save to new code',
            'body' => 'The reload script overwrites a constant in a small class with a unique value, records the host time and polls a debug route every 50 ms until the answer holds that value. The result is milliseconds, or `never` when 30 s pass. It runs five rows: classic, Octane with and without watch, and worker mode with and without watch. Median, p90 and max are computed over the numeric trials only. The file sync of Mutagen is part of every number. It is also timed apart, after each timed trial so it adds no load to it: one command inside the container polls the file every 10 ms and says when it is ready, then a new value is written and the wait until the container sees it is the sync time.',
            'snippet' => 'App\Support\ReloadProbe',
        ],
        [
            'title' => 'Browser hot reload',
            'body' => 'A hot reload would refresh the open page after the server has the new code, so the browser needs no manual reload. FrankenPHP v1.13.5 has no such module: `frankenphp list-modules` shows none and `php-server` offers only `--watch`. It is reported as not available, and nothing here depends on it.',
        ],
    ],
    'failures' => [
        [
            'title' => 'I saved the file and the page did not change',
            'body' => "**Cause:** worker mode without a watcher, or a cached config or routes file. A worker serves the code it loaded when it started.\n\n**Fix:** run `ddev dev-watch status`. If it says off, run `ddev dev-watch on`, or replace the workers once with `ddev artisan frankenphp:workers:restart`. If the command warned about cached config or routes, run `ddev artisan optimize:clear`.",
        ],
        [
            'title' => 'dev-watch says there is nothing to watch',
            'body' => "**Cause:** the server is classic `frankenphp`, which starts PHP fresh for every request.\n\n**Fix:** none needed. To try the watcher, run `ddev server frankenphp-worker` or `ddev server frankenphp-octane` first, then `ddev dev-watch on`.",
        ],
        [
            'title' => 'The watcher turned itself off',
            'body' => "**Cause:** `ddev server` removes the overlay when it switches the server, so dev-watch is off after every switch.\n\n**Fix:** run `ddev dev-watch on` again after a server switch. A plain `ddev restart` keeps it.",
        ],
        [
            'title' => 'The breakpoint is never hit',
            'body' => "**Cause:** Xdebug is off, the request has no `XDEBUG_TRIGGER`, the IDE is not listening on port 9003, or the worker started before the middleware existed. A restart also switches Xdebug off.\n\n**Fix:** run `ddev xdebug on` after the last restart, listen in the IDE with the path mapping `/var/www/html` to the project folder, and request the page with `?XDEBUG_TRIGGER=1`. On Octane, replace the workers once so they load the middleware. Only the request with the trigger stops: its worker is replaced after the response, so add the trigger to every request you want to debug.",
        ],
        [
            'title' => 'The listener says the port is in use',
            'body' => "**Cause:** the IDE already listens on port 9003, and only one process can.\n\n**Fix:** close the IDE listener for the check, or run the listener with another port and set the same port in the Xdebug settings. Close the check before you start the IDE again.",
        ],
        [
            'title' => 'dev-watch on refuses on Octane',
            'body' => "**Cause:** a benchmark overlay that sets the worker count or the request limit replaces the Octane daemon command, `--watch` included, so the watcher would silently not run.\n\n**Fix:** run `ddev server frankenphp-octane` to clear the benchmark overlay, then `ddev dev-watch on`. The benchmark scripts refuse in the other direction: run `ddev dev-watch off` before them.",
        ],
        [
            'title' => 'A no-watch measurement reloaded by itself',
            'body' => "**Cause:** a worker replaces itself after 500 requests, and a 50 ms poll reaches that limit in about 25 s, so the new file was loaded by accident, once after 11.5 s.\n\n**Fix:** the reload script lifts the request limit for the rows without a watcher. If you poll by hand, do the same or poll more slowly.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Switch the watcher on and off',
            'body' => "```sh\nddev server frankenphp-worker\nddev dev-watch on\nddev dev-watch status    # dev-watch: on (server: frankenphp-worker)\nddev dev-watch off\n```\n\nWith it on, edit any PHP file under the watched folders and reload the page: the new code is there after about half a second. Off removes the overlay and restarts the web container.",
        ],
        [
            'title' => 'Debug a controller in PhpStorm',
            'body' => "1. Map `/var/www/html` to the project root in the server settings.\n2. Start listening for debug connections on port 9003 and set a breakpoint in a controller.\n3. Run `ddev xdebug on`.\n4. Open the page with `?XDEBUG_TRIGGER=1` added to the URL.\n5. Run `ddev xdebug off` when you are done.\n\nA restart switches Xdebug off, so repeat step 3 after any `ddev restart`. On Octane, `ddev xdebug on` restarts the Octane process to load the extension: wait a few seconds until a page answers again, or the first request still goes to the old process without Xdebug.",
        ],
        [
            'title' => 'Check Xdebug without an IDE',
            'body' => "Run the `xdebug-check.php` listener from the bench folder with PHP on the host, in the background, with `--wait=10 --follow`. It listens on the loopback address only; `host.docker.internal` reaches it there. Then:\n\n```sh\nddev xdebug on\ncurl -sk \"https://ai-lab.ddev.site/api/ping?XDEBUG_TRIGGER=1\" >/dev/null\n```\n\nThe listener prints `connections=1 init=1 ended=1 after=4ms` or similar: one connection, for the request with the trigger, and with `--follow` it acts like an IDE that says run and sees the session end a few ms later, because the worker was replaced. `ended=0` would mean the session is still open and later requests on that worker run under the debugger. Start the listener again with `--wait=3`, then send the request without the trigger:\n\n```sh\ncurl -sk https://ai-lab.ddev.site/api/ping >/dev/null\nddev xdebug off\n```\n\nThis listener prints `connections=0`. After `ddev xdebug off`, a triggered request makes no connection either.",
        ],
        [
            'title' => 'Measure it yourself',
            'body' => 'Run the `reload-latency.sh` script from the bench folder, with no arguments. It switches servers and watch itself, writes one JSON file per row and puts the starting server, the starting watch state, the probe file and `.env` back on any exit, Ctrl-C included. It needs a few minutes: the rows without a watcher wait 30 s per trial.',
        ],
        [
            'title' => 'The same pattern in other places',
            'body' => "Two ideas: **replace long-lived memory when its source changes**, and **measure from the user's action to the visible effect**.\n\nAny long-lived process has the first problem: a queue worker (`ddev artisan queue:restart`), a Node server, a JVM. The second is the honest way to time any feedback loop: put a marker in at one end and poll for it at the other, with a cap.",
        ],
    ],
    'support' => [
        [
            'name' => 'What the watcher touches',
            'status' => 'logged',
            'note' => 'Only the overlay named for dev-watch in the project folder, which is gitignored. `ddev dev-watch status` prints whether it is on and which server is active. The Xdebug settings file is committed, and it changes only when a debug session starts.',
        ],
        [
            'name' => 'Safety of the setup and the measurement',
            'status' => 'guarded',
            'note' => 'Tests check that the watch command never changes the project configuration or `.env`, that the middleware connects only when Xdebug is loaded and the request has the trigger, that the debug route answers only where debug routes are on, and that the reload script puts everything back on exit and on Ctrl-C. The default test suite needs no server and no debugger.',
        ],
        [
            'name' => 'An edit is not served in worker mode',
            'status' => 'runbook',
            'note' => "1. Run `ddev server` and read the mode. Classic mode reloads on every request.\n2. Run `ddev dev-watch status`. If it is off, run `ddev dev-watch on`.\n3. Edit a file under the app, config, routes, resources or bootstrap folders, or `composer.lock`. A file outside them, such as one in `vendor`, is not watched.\n4. Check whether config or routes are cached: `ddev artisan optimize:clear`.\n5. Replace the workers by hand with `ddev artisan frankenphp:workers:restart` and read the time it prints.",
        ],
    ],
    'numbers' => [
        ['label' => 'Classic mode: median edit-to-reload time (ms)', 'file' => 'reload/2026-11-17/classic.json', 'path' => 'median_ms'],
        ['label' => 'Worker mode with watch: median edit-to-reload time (ms)', 'file' => 'reload/2026-11-17/worker-watch.json', 'path' => 'median_ms'],
        ['label' => 'Worker mode with watch: slowest of 10 trials (ms)', 'file' => 'reload/2026-11-17/worker-watch.json', 'path' => 'max_ms'],
        ['label' => 'Octane with watch: median edit-to-reload time (ms)', 'file' => 'reload/2026-11-17/octane-watch.json', 'path' => 'median_ms'],
        ['label' => 'Worker mode without watch: trials that never showed the new code in 30 s', 'file' => 'reload/2026-11-17/worker-nowatch.json', 'path' => 'never'],
        ['label' => 'Octane without watch: trials that never showed the new code in 30 s', 'file' => 'reload/2026-11-17/octane-nowatch.json', 'path' => 'never'],
        ['label' => 'Worker mode without watch: median restart, end to end from the command to the new answer (ms)', 'file' => 'reload/2026-11-17/worker-nowatch.json', 'path' => 'restart_ms.median'],
        ['label' => 'Worker mode without watch: median of the admin API restart call alone (ms)', 'file' => 'reload/2026-11-17/worker-nowatch.json', 'path' => 'restart_call_ms.median'],
        ['label' => 'Octane without watch: median restart, end to end from the command to the new answer (ms)', 'file' => 'reload/2026-11-17/octane-nowatch.json', 'path' => 'restart_ms.median'],
        ['label' => 'Octane without watch: median of the reload call alone (ms)', 'file' => 'reload/2026-11-17/octane-nowatch.json', 'path' => 'restart_call_ms.median'],
        ['label' => 'Worker mode with watch: median time until the file sync made the file visible in the container (ms)', 'file' => 'reload/2026-11-17/worker-watch.json', 'path' => 'mutagen_median_ms'],
    ],
    'experiments' => [
        [
            'title' => 'Edit a file with the watcher off',
            'predictions' => [
                ['id' => 'a', 'text' => 'In worker mode the new code is served on the next request, as in classic mode.'],
                ['id' => 'b', 'text' => 'In worker mode the old code is served until the workers are replaced, which takes a restart or a watcher.'],
                ['id' => 'c', 'text' => 'The server notices the change after about a minute and reloads by itself.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Switch to `ddev server frankenphp-worker`, change the text a page returns and reload: the old text stays. In 30 s of polling, all three trials of the measurement ended as `never`. Run `ddev artisan frankenphp:workers:restart` and the new text appears, after a median of 2468 ms end to end, of which the restart call itself is about 70 ms. With `ddev dev-watch on` it appears after about half a second without any restart.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['logs-restart', 'metrics'],
    'build' => [
        ['step' => 'Put the watch patterns behind one variable', 'detail' => 'Give the worker block a line that holds whole watch lines, so an empty value watches nothing.', 'tab' => 'recipes', 'doc' => 'https://frankenphp.dev/docs/worker/'],
        ['step' => 'Switch it with one overlay', 'detail' => 'Write a gitignored overlay for the variable or the Octane flag, and remove it to turn the watcher off.', 'tab' => 'recipes'],
        ['step' => 'Use the trigger mode of the debugger', 'detail' => 'Start a debug session only for a request that asks for one, so a long-lived worker never tries to connect on its own.', 'tab' => 'recipes'],
        ['step' => 'Connect from a middleware', 'detail' => 'Check the trigger on every request and start the session by hand, because a worker reads it only once.', 'tab' => 'support'],
        ['step' => 'End the worker after a debugged request', 'detail' => 'Let the request set a flag that the worker loop checks, so the script ends and the debugger session with it.', 'tab' => 'recipes'],
        ['step' => 'Prove the path without an IDE', 'detail' => 'Run a small socket listener that counts the connections, with and without the trigger, and sees the session end.', 'tab' => 'recipes'],
        ['step' => 'Time the loop end to end', 'detail' => 'Write a unique marker into a file, poll a route until it shows up, and cap the wait.', 'tab' => 'recipes'],
    ],
    'watch' => [
        ['point' => 'Which files are watched', 'detail' => 'Only PHP files in the app, config, routes, resources and bootstrap folders, `composer.lock` and the env file, not vendor or storage.', 'tab' => 'support'],
        ['point' => 'Cached config', 'detail' => 'A cached config or routes file hides your edit, so clear it first.', 'tab' => 'support'],
        ['point' => 'A restart switches the debugger off', 'detail' => 'Turn Xdebug on again after every restart and leave it off when you are done.', 'tab' => 'support'],
        ['point' => 'A debugged request replaces its worker', 'detail' => 'The session can only end with the script, so each triggered request costs one worker boot.', 'tab' => 'support'],
        ['point' => 'The file sync is part of the wait', 'detail' => 'On macOS the sync between the host and the container is part of the wait, about 130 to 330 ms of a half-second reload.', 'tab' => 'support'],
        ['point' => 'Classic mode waits for OPcache', 'detail' => 'Classic PHP rereads a file only at OPcache\'s timestamp check, every 2 s here, so its edits took longer than a watcher restart.', 'tab' => 'support'],
    ],
    'elsewhere' => [
        ['domain' => 'Queue workers and daemons', 'problem' => 'A long-running worker keeps the old code after a deploy.', 'how' => 'Signal it to restart after the files change, the same idea as a watcher for the web worker.'],
        ['domain' => 'Front-end builds', 'problem' => 'A developer waits for a build to show a change.', 'how' => 'Time it from save to visible change with a marker, then decide whether a watcher is worth it.'],
    ],
];
