<?php

declare(strict_types=1);

return [
    'kind' => 'foundation',
    'title' => 'Logs and worker restart',
    'summary' => 'Every PHP log line as one JSON entry that carries the request id, and one Artisan command that restarts the PHP workers without a stop of the whole server. Both are opt-in and local.',
    'works' => [
        [
            'title' => 'One request id from the response header to the log line',
            'body' => "A global middleware gives every request an id. An incoming `X-Request-Id` is reused when it is 8 to 64 letters, digits or dashes; anything else is replaced by a new ULID, silently. The id goes into Laravel's hidden context, which the framework clears for every request, and onto the response as `X-Request-Id`. Hidden context is never added to a log entry by Laravel itself, so only the log channel below prints it. That clearing is what keeps worker mode honest: two requests on the same worker never share an id.",
            'snippet' => 'App\Http\Middleware\AssignRequestId::handle',
        ],
        [
            'title' => 'The request fields on every entry',
            'body' => "A Monolog processor adds the request id, method, path (never the query string), route name, server mode, a worker flag and the milliseconds since the request started. The start time is the request's own `REQUEST_TIME_FLOAT`, which is new for each request in worker mode too. In a console command there is no request: the fields are empty and the context says `cli`. A job run by a queue worker says `queue`, and so does the exception the worker reports when that job fails. A line written in a request before the id exists says `http` with an empty id.",
            'snippet' => 'App\Logging\RequestContextProcessor::requestFields',
        ],
        [
            'title' => 'frankenphp_log() inside a request, stderr outside',
            'body' => "The handler of the `frankenphp` channel calls `frankenphp_log()` when the function exists. That function only exists inside a request served by FrankenPHP, and the entry then lands in Caddy's own structured log stream, next to the access log, at the matching level (debug, info, warn or error). The server prints a nested PHP array as a pair of `Map` and `Order` objects, so the handler passes flat keys such as `request.request_id` and `context.user.id`, which print as plain fields. In the console, in a queue worker, or when the function is missing, the same entry is written as one JSON line to stderr. Monolog's normalizer builds it either way, so an exception arrives as its class, message, code and file with the line, not as an empty object.",
            'snippet' => 'App\Logging\FrankenphpLogHandler::write',
        ],
        [
            'title' => 'Redaction before writing',
            'body' => 'A key of the context or the extra data that equals or ends with `password`, `passwd`, `secret`, `token`, `authorization`, `api_key`, `apikey`, `access_key`, `private_key`, `cookie` or `bearer` has its value replaced by `[redacted]`, at any depth, inside objects too, and in any letter case or spelling (`X-Api-Key`, `Set-Cookie`, `accessToken`). Counters such as `tokens_in` or `prompt_tokens` do not end with `token` and stay readable. Only the values under those keys are checked: a secret written into the message text itself is not, so do not put one there.',
        ],
        [
            'title' => 'Restarting the workers',
            'body' => 'On worker mode the command sends one POST to the Caddy admin API on `localhost:2019` and prints how long it took. The admin API listens on localhost only and no port is published, so the command works inside the web container, which is where `ddev artisan` runs it. Outside the container (no `IS_DDEV_PROJECT=true`) it refuses before it sends anything, because a `localhost:2019` there would be some other server; inside, it first checks that the admin API answers. On Octane mode it runs `octane:reload` and passes on its exit code. On classic mode it says there are no workers to restart and exits 0. An unknown mode, a refused connection, a timeout or a non-2xx answer exits 1.',
            'snippet' => 'App\Console\Commands\FrankenphpWorkersRestartCommand::restartThroughAdminApi',
        ],
    ],
    'failures' => [
        [
            'title' => 'The channel is set but nothing appears',
            'body' => "**Cause:** the default stack is `single`, a file. The `frankenphp` channel is opt-in.\n\n**Fix:** set `LOG_STACK=single,frankenphp` in `.env` and run `ddev restart`. Read the server log stream with `ddev logs -s web`.",
        ],
        [
            'title' => 'A log line from a console command has no request id',
            'body' => "**Cause:** a command is not a request, so the request fields are empty and the context says `cli`. The entry goes to stderr as JSON.\n\n**Fix:** none is needed. Put your own id in the context if a job must be followed from the request that queued it.",
        ],
        [
            'title' => 'The restart command says to run it inside the web container',
            'body' => "**Cause:** the admin API is bound to localhost of the web container and is never published.\n\n**Fix:** run `ddev artisan frankenphp:workers:restart`, not `php artisan` on the host.",
        ],
        [
            'title' => 'The restart exits 1 with an admin API error',
            'body' => "**Cause:** the server is not in worker mode, the admin API did not answer within `--timeout` seconds, or it answered with an error status.\n\n**Fix:** check `ddev server` for the current mode, then try again with a longer `--timeout`.",
        ],
        [
            'title' => 'A secret shows up in the log',
            'body' => "**Cause:** it was written into the message text, or under a key the redaction does not know.\n\n**Fix:** put values in the context under a clear key, never in the message, and add the key name to the redaction list if it is new.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Switch the structured logs on',
            'body' => "```sh\nLOG_STACK=single,frankenphp    # in .env\nddev restart\ncurl -si https://ai-lab.ddev.site/api/ping | grep -i x-request-id\nddev logs -s web\n```\n\n**Check:** the response has an `X-Request-Id` header, and a log call made during that request shows the same id in the web container's log.",
        ],
        [
            'title' => 'Restart the workers after a change',
            'body' => "```sh\nddev server frankenphp-worker\nddev artisan frankenphp:workers:restart\n```\n\nIt prints the time taken. Use it after you edit code that worker mode keeps in memory.\n\n**Check:** the next request runs the new code.",
        ],
        [
            'title' => 'Compare the two restarts under load',
            'body' => "Keep a steady load of 50 connections on one route for 60 s, restart at 15, 30 and 45 s, and compare the seconds from each restart to 2 s after it with the rest. The load tool only stores the requests that got an answer, so a second, light probe sends 20 requests a second and logs each one with its start time and outcome: a refused connection shows up there. Do it on worker mode (admin API) and on Octane mode (`octane:reload`) with the same load, three times each. The numbers tab shows the committed result.\n\n**Check:** both runs have the same load, the same restart times and the same window.",
        ],
        [
            'title' => 'Change the log destination or the provider of the logs',
            'body' => 'The entry is plain JSON, so any collector that reads the server log stream or the container stderr takes it unchanged. To send it somewhere else, change the handler of the channel and keep the processor: the request fields stay. A model provider is not involved here, so there is no model to swap.',
        ],
        [
            'title' => 'The same pattern in other places',
            'body' => "Two ideas: **one id that follows the work**, and **replace workers without stopping the door**.\n\n- **A parcel:** the tracking number is on every scan along the route, so a lost parcel can be found at any step.\n- **A hospital:** a patient wristband is read at every desk, and the staff change shifts while the ward stays open.\n- **A restaurant:** the order ticket number follows a dish from the table to the kitchen and back; the cooks swap at shift change and the guests keep eating.\n- **A trace id across services:** the id arrives in a header and goes into every log line of every service.",
        ],
    ],
    'support' => [
        [
            'name' => 'Where the entries go',
            'status' => 'logged',
            'note' => 'Inside a request the entry is written by the server into its own log stream, next to the access log. In the console and in queue workers it goes to stderr as one JSON line. The file channel stays as it was and keeps its own format.',
        ],
        [
            'name' => 'Privacy and exposure',
            'status' => 'guarded',
            'note' => 'Tests check that sensitive context keys are redacted, that an incoming request id is validated before it is reused, that two requests on one worker never share an id, that the default logging is unchanged, and that the restart needs no running server (the admin API call is faked). The admin API stays on localhost and no port for it is published.',
        ],
        [
            'name' => 'A restart did not take effect',
            'status' => 'runbook',
            'note' => "1. Run `ddev server` and read the mode. Classic mode has no workers, so there is nothing to restart.\n2. Run `ddev artisan frankenphp:workers:restart` and read the output: the time in ms means it worked.\n3. An admin API error: confirm you ran it inside the web container, then raise `--timeout`.\n4. On Octane mode read the `octane:reload` output it prints.",
        ],
    ],
    'numbers' => [
        ['label' => 'Requests that failed during a worker restart under load, worker mode', 'pending' => true],
        ['label' => 'Requests that failed during a reload under load, Octane mode', 'pending' => true],
        ['label' => 'Time the restart command took, worker mode (ms)', 'pending' => true],
    ],
    'experiments' => [
        [
            'title' => 'Follow one request id',
            'predictions' => [
                ['id' => 'a', 'text' => 'The id in the response header is new on every request, even when I send my own.'],
                ['id' => 'b', 'text' => 'My own id comes back when it is 8 to 64 letters, digits or dashes, and a new one replaces anything else.'],
                ['id' => 'c', 'text' => 'The id comes back only when the logs are switched on.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'The middleware runs on every request, with the channel on or off. Send `curl -si -H "X-Request-Id: my-test-0001" https://ai-lab.ddev.site/api/ping` and the same value returns. Send `bad id` or a 100-character value and a new id comes back. The header is the only change to the response.'],
            'page' => 'ai.describe',
            'params' => [],
        ],
    ],
    'related' => ['metrics', 'streaming'],
    'build' => [
        ['step' => 'Give each request an id', 'detail' => 'Accept a safe incoming id or make a new one, keep it for this request only, and return it in a header.', 'tab' => 'recipes'],
        ['step' => 'Add the request fields', 'detail' => 'Attach id, method, path, route and elapsed time to every entry with a processor.', 'tab' => 'recipes'],
        ['step' => 'Write one JSON entry', 'detail' => 'Use the server log function inside a request and stderr elsewhere, with the same fields.', 'tab' => 'recipes'],
        ['step' => 'Redact before writing', 'detail' => 'Replace the values under sensitive keys, at any depth.', 'tab' => 'support'],
        ['step' => 'Restart through one command', 'detail' => 'Call the admin API on localhost for the native workers, and the framework reload for Octane.', 'tab' => 'recipes'],
    ],
    'watch' => [
        ['point' => 'State between requests', 'detail' => 'A worker lives for many requests, so request data must be cleared for each one.', 'tab' => 'failures'],
        ['point' => 'Secrets in messages', 'detail' => 'Redaction checks keys, not the text of the message.', 'tab' => 'failures'],
        ['point' => 'Where the command runs', 'detail' => 'The admin API is on localhost, so run the command inside the web container.', 'tab' => 'failures'],
        ['point' => 'Classic mode', 'detail' => 'It has no workers, so the command only says so.', 'tab' => 'support'],
    ],
    'elsewhere' => [
        ['domain' => 'Support and incidents', 'problem' => 'A customer reports one slow request and nobody can find it.', 'how' => 'Ask for the request id from the response header and search every log for it.'],
        ['domain' => 'Releases', 'problem' => 'A new version must go live without dropping users.', 'how' => 'Replace workers one by one behind a steady door, and count the failed requests while you do.'],
    ],
];
