---
id: spec-20261117-1052-logs-worker-restart
title: Structured logs and worker restart
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-17; standing approval)
approved_at: 2026-11-17T08:56:01Z
created: 2026-11-17
stacks: [laravel]
slice: SL-16
brs: [BR-70, BR-71, BR-21, BR-26, BR-27]
---

# Structured logs and worker restart

## Summary

Two server-side lessons:

1. **Structured logs (BR-70).**
   - A Monolog channel `frankenphp` writes every PHP log entry as one JSON object with request fields: request id, method, path, route name, duration so far, the server mode and the worker flag (C-3).
   - The entry is written with `frankenphp_log()` when it exists, which is inside a FrankenPHP request. It then lands in Caddy's structured log stream next to the access log.
   - Otherwise, in the CLI, in queue workers, or when the function is missing, the entry goes to stderr as JSON.
2. **Worker restart (BR-71).**
   - `ddev artisan frankenphp:workers:restart` restarts PHP workers through the Caddy admin API, `POST localhost:2019/frankenphp/workers/restart`, on `frankenphp-worker`, and through `octane:reload` on `frankenphp-octane`.
   - On classic `frankenphp` it reports that there are no workers to restart and exits 0.
   - A load test measures how many requests fail while a restart happens, for the native restart and for Octane's reload.

## Context

- **Versions:** FrankenPHP v1.13.5 (Caddy v2.11.9) runs in the web container.
  - `frankenphp_log()` is not defined in the CLI PHP (checked live), so it is only available inside a served request.
  - The admin endpoint `POST localhost:2019/frankenphp/workers/restart` answers 200 inside the container on classic mode (checked live).
  - The admin API is bound to localhost and stays there (SL-15 D-2), so the command must run inside the web container. `ddev artisan` does that.
- **Logging today:** `config/logging.php` has `stack` → `single` (a file in `storage/logs`). There is no request id and no JSON.
- **Bench helpers:** SL-15 added the metrics endpoint, and `bench/run.sh`/oha with the BR-21 method is the load tool.

## Scope

### In scope

1. **Log channel**:
   - **The handler:** `App\Logging\FrankenphpLogHandler`, a Monolog handler, with `App\Logging\RequestContextProcessor`.
   - **Config:** a channel `frankenphp` in `config/logging.php`. `.env.example` documents `LOG_STACK=single,frankenphp` as the recommended dev setting. `.env` is not changed.
   - **Processor fields:** `request_id`, `method`, `path`, `route`, `server_mode`, `worker` (bool), `elapsed_ms` and `context` (C-3).
     - `request_id` is the incoming `X-Request-Id` when it matches `^[A-Za-z0-9-]{8,64}$`, otherwise a new ULID. It is also set on the response as `X-Request-Id`.
     - `server_mode` is from `ai.server.mode` or env, `worker` from `$_SERVER['FRANKENPHP_WORKER']`, and `elapsed_ms` since the request start.
     - In the CLI the request fields are null and `context: "cli"`.
   - **Handler behaviour:**
     - **Inside a request:** if `function_exists('frankenphp_log')`, it calls `frankenphp_log($message, $level, $context)`, mapping Monolog levels to FrankenPHP's levels as documented for v1.13.
     - **Otherwise:** it writes one line of JSON to `php://stderr`.
     - **Redaction:** sensitive keys in the context and extra (C-4) are redacted before writing.
   - **Worker mode:** the request context is reset per request (scoped or terminating), so worker mode never leaks one request's id into the next (the SL-07 lesson).
   - **Middleware:** `AssignRequestId` sets the id early for web and API routes.
2. **Restart command:** `frankenphp:workers:restart {--timeout=10}`.
   - **Detects the mode** from the running server.
   - **`frankenphp-worker`:** POSTs to `http://localhost:2019/frankenphp/workers/restart` and reports the status and time taken.
   - **`frankenphp-octane`:** runs `octane:reload` (Octane's own command).
   - **Classic:** prints "classic mode has no workers to restart" and exits 0.
   - **Errors:** an admin API failure exits 1 with the message.
   - **Where it runs:** it refuses to run outside the web container (no admin API) with a clear message.
3. **Load measurement** (`bench/restart-load.sh [--quick]`):
   - **Modes:** for `frankenphp-worker` and `frankenphp-octane`.
   - **The load:** oha holds a steady load on `GET /api/ping` and on `/listings?make=BMW` (50 connections, the BR-21 warm-up of 30 s and measure of 60 s, 3 repeats).
   - **The restarts:** during each measured window it triggers 3 restarts at fixed offsets (15, 30, 45 s).
   - **Recorded:** failed requests (non-2xx, connection errors, timeouts, from a timestamped probe), the load's completed requests, non-2xx and p99 latency, each inside the restart windows against outside them, plus each restart's duration (C-7).
   - **Output:** `bench/results/restart/<date>/<mode>-<route>.json`.
4. **Results and docs**:
   - **BENCHMARKS.md:** a section "Worker restart under load" (the table, What the numbers say, Surprises, interview story with measured numbers).
   - **Learning guide:** a new I-12 "Logs and worker restart", replacing the SL-16 reserved bullet.
   - **In-app guide:** a new foundation guide `logs-restart` on `/ai/guides`, with the six tabs plus build, watch and elsewhere (the SL-53 teach sections). Its numbers come from the result files.
   - **README:** the commands.

### Out of scope

- A log shipping stack (Loki and others).
- Changing the default log channel in `.env`.
- Hot reload or file watching (SL-17).
- Restarting the whole server.
- Exposing the admin API.

## Must NOT change

- **MNC-1:** with `LOG_STACK` unchanged (`single`), logging behaves exactly as today.
- **MNC-2:** response bodies, status codes and content types are unchanged. The only addition is the `X-Request-Id` header, and parity records status, content type and a body hash, not headers, so the parity capture differs only in `captured=`. The asset fingerprint is unchanged.
- **MNC-3:** the Caddy admin API stays on localhost, and no new port is exposed.
- **MNC-4:** the default test suite needs no running server or admin API (HTTP is faked).

## Acceptance criteria

- **AC-1 (BR-70):** WHEN code logs inside a FrankenPHP request with the `frankenphp` channel, THE SYSTEM SHALL emit one structured entry through `frankenphp_log()` carrying the message, the level, the context and `request_id`, `method`, `path`, `route`, `server_mode`, `worker` and `elapsed_ms`.
- **AC-2 (BR-70):** WHEN `frankenphp_log()` is not available, THE SYSTEM SHALL write the same entry as one JSON line to stderr.
- **AC-3:** THE response SHALL carry `X-Request-Id`. A valid incoming id SHALL be reused, and an invalid one replaced.
- **AC-4:** In worker mode, two consecutive requests SHALL log different `request_id`s, and no request field SHALL carry over.
- **AC-5:** Sensitive context keys SHALL be redacted in every log entry.
- **AC-6 (BR-71):** WHEN `frankenphp:workers:restart` runs:
  - on `frankenphp-worker`, THE SYSTEM SHALL call the admin restart endpoint and report success, exiting 0;
  - on `frankenphp-octane`, it SHALL run `octane:reload`;
  - on classic, it SHALL report that there is nothing to restart and exit 0;
  - on an admin API failure, it SHALL exit 1 with the message.
- **AC-7 (BR-21, BR-26):** WHEN `bench/restart-load.sh` runs, THE SYSTEM SHALL record, per mode and route, the failed requests during restarts, the p99 latency inside against outside the restart seconds, and the restart durations. BENCHMARKS SHALL report them with the story.
- **AC-8 (BR-27):** `/ai/guides/logs-restart` SHALL answer 200 with the six tabs and the teach sections, with no slice ids or paths. The learning guide SHALL have I-12. `DocsLinksTest`, `GuideContentTest` and `NoReferencesInUiTest` SHALL pass.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Privacy | No password, token, cookie or authorization value appears in any log entry; the request id is validated before reuse | `tests/Feature/StructuredLogTest.php` |
| **NFR-2** | Overhead | The `frankenphp` channel adds < 5 % to the p95 of a request that logs one entry, on worker mode (`bench/log-overhead.sh`: `/debug/log-ping` with `LOG_STACK=single` against `single,frankenphp`, full BR-21 method, C-8) | `manual-test.md#step-4` |

## Boundaries

- **Always:**
  - fake the admin API with `Http::fake` and the FrankenPHP function with a test double;
  - follow the BR-21 method;
  - update `/ai/guides` (owner rule);
  - restore the starting server and `.env` after bench runs.
- **Ask first:** none (standing approval).
- **Never:**
  - expose the admin API;
  - change the default log channel in `.env`;
  - modify `.ddev/config.yaml`.

## Technical context

- **Related code:**
  - **Logging:** `config/logging.php` and `bootstrap/app.php` (middleware).
  - **Servers:** the `.ddev/servers/*` Caddyfiles (log config) and `bench/lib/servers.sh` (mode detection).
  - **Bench scripts:** `bench/run.sh` and `bench/slow-load.sh` (patterns).
  - **Guides:** `resources/guides/*.php` (guide format with build, watch and elsewhere).
- **Already implemented:**
  - the admin API on localhost;
  - Octane's `octane:reload`;
  - the BR-21 harness;
  - SL-07's state-leak tests (the pattern for AC-4).

## Decisions

- **D-1:** the channel is opt-in through `LOG_STACK`, so existing logging (MNC-1) stays as it is. The guide shows how to switch. (self-decided, standing approval)
- **D-2:** one command for both worker modes, dispatching to the native admin API or to `octane:reload`, so the learner compares the two on the same load. (self-decided, standing approval)
- **D-3:** the command runs inside the web container. The admin API stays on localhost (SL-15 D-2). (self-decided, standing approval)

## Open questions

All 27 items from the clarity gate on 2026-11-17 are answered below in C-1 to C-14 (self-decided, standing approval).

- [x] Mode detection, container check, worker flag under Octane → C-1
- [x] The `frankenphp_log` signature and levels, test seams → C-2
- [x] Elapsed time, reset mechanism, `status`, `route` and `path`, `context` → C-3
- [x] Entry schema, redaction, scope with the `single` channel → C-4
- [x] Where the request-id middleware runs, invalid ids → C-5
- [x] Command timeout, failures, output, `octane:reload` → C-6
- [x] Restart windows, p99 inside and outside, triggering, `--quick`, servers → C-7
- [x] NFR-2 method, the manual step, Caddyfiles, terms → C-8 to C-14

## Clarifications

- **C-1 Mode:**
  - **Where the mode comes from:** each stash server config (`.ddev/servers/<mode>/config.<mode>.yaml`) gets `web_environment: [AI_SERVER_MODE=<mode>]`. `ddev server` copies it into `.ddev/`, and `.ddev/config.yaml` is never touched.
  - **Reading it:** `config('ai.server.mode')` reads `env('AI_SERVER_MODE', 'unknown')`. The command and the processor use the same value.
  - **The container check (amended 2026-11-17, review F8/F11):** the command goes on only when `IS_DDEV_PROJECT` is `true` (config `ai.server.in_container`) **and** the admin API answers a `GET /config/` with 2xx within 1 s. When `IS_DDEV_PROJECT` is not `true` it refuses before any probe, so it never calls a `localhost:2019` on the host, which would be some other Caddy. Inside the container a probe that fails exits 1 with `admin API: no answer from <url>`. Tests: `in_container` false with every HTTP call faked as 200 sends nothing; `in_container` true with the probe faked as a connection error sends no restart POST. The admin calls live in `App\Support\AdminApiClient`.
  - **`worker`:** true when `$_SERVER['FRANKENPHP_WORKER']` is set, or Octane is running (`$_SERVER['LARAVEL_OCTANE']`). So it is true on both worker modes.
- **C-2 FrankenPHP log:**
  - **Signature:** `frankenphp_log(string $message, int $level = 0, array $context = []): void`, using slog levels: debug -4, info 0, warn 4, error 8.
  - **Level mapping:** debug → -4; info and notice → 0; warning → 4; error, critical, alert and emergency → 8. The Monolog level name stays in the context as `level_name`.
  - **Checking the stub:** the build checks the signature against the extension stub, or `ReflectionFunction` inside a request. If it differs, the spec is updated before coding.
  - **Test seams:** the handler takes an injectable `callable $writer`, defaulting to `frankenphp_log` when the function exists, plus a `resource $stderr`, defaulting to `php://stderr`. Tests inject both.
- **C-3 Fields:**
  - **`elapsed_ms`:** an integer, measured from `$request->server('REQUEST_TIME_FLOAT')`, which is per request in worker mode too, not from `LARAVEL_START`.
  - **Reset:** the request fields are set through Laravel's `Context` facade (`Context::add`), which is reset per request by the framework in both worker modes. The worker-mode test (AC-4) proves it.
  - **`status`:** dropped from the Summary, because it is not known while the request is still running.
  - **`route`:** the route name, or the URI pattern when the route has no name.
  - **`path`:** the path only, never the query string.
  - **`context`:** `"http"` inside a request, `"cli"` for commands, and `"queue"` for queue jobs run by a worker. A sync job inside a request counts as `"http"`.
    - **Queue (amended, review F1/F6):** the queue flag is set when a worker starts a non-sync job and cleared only when the worker loops to the next job or stops. So the job's `JobProcessed` listeners and, for a failed job, the exception the worker reports afterwards log `"queue"`. A sync job inside a queued job neither sets nor clears it, so the rest of the outer job stays `"queue"`.
    - **Before the middleware (amended, review F7):** an entry written in a served request before `AssignRequestId` runs (bootstrap, an earlier global middleware) has `context: "http"` with `request_id` and the request fields null. HTTP is detected by `app()->runningInConsole()` being false.
- **C-4 Entry and redaction:**
  - **The JSON line:** `{"time": RFC3339 with ms, "level": "info", "message": "...", "channel": "...", "context": {...}, "request": {request_id, method, path, route, server_mode, worker, elapsed_ms, context}}`.
  - **Formatting (amended, review F4/F17):** the entry is built by `App\Logging\FrankenphpJsonFormatter`, a Monolog `NormalizerFormatter`. Exceptions become `class`, `message`, `code`, `file` (`path:line`) and a trace of at most 20 frames; other objects become `{Class: their JSON form}`; dates become strings. The handler sends that array to `frankenphp_log()` or writes it as one JSON line to stderr.
  - **Redaction (amended, review F5/F12):**
    - **Keys:** the key is normalized (lowercase, `-` and camelCase turned into `_`) and is sensitive when it equals or ends with `password`, `passwd`, `secret`, `token`, `authorization`, `api_key`, `apikey`, `access_key`, `private_key`, `cookie` or `bearer`. That covers `X-Api-Key`, `Set-Cookie`, `access_token`, `clientSecret` and `csrftoken`, and leaves counters alone: `tokens`, `tokens_in`, `tokens_out`, `token_count` and `*_tokens` do not end with `token`.
    - **Depth:** applied recursively in `context` and `extra`, after normalization, so values inside objects are covered too.
    - **Replacement:** the value becomes `"[redacted]"`.
    - **Limit:** values inside the message text are not inspected. NFR-1 is narrowed to context and extra values, and the guide says why.
  - **Scope:** the processor and the redaction attach only to the `frankenphp` channel, so the `single` file is unchanged (MNC-1).
- **C-5 Request id:**
  - **Middleware:** `AssignRequestId` is prepended globally in `bootstrap/app.php`, so every web and API response, 404s and exceptions included, carries the header. Static assets that Caddy serves directly do not.
  - **Invalid ids:** an invalid incoming id is replaced silently, and the new id is used. Nothing is logged about it.
- **C-6 Command:**
  - **`--timeout`:** in seconds, applies to the admin HTTP call only.
  - **Failure:** connection refused, a timeout or a non-2xx answer exits 1 with "admin API: <status or error>".
  - **Output:**
    - worker mode: `workers restarted in <n> ms`;
    - Octane: `octane:reload` is run through `Artisan::call`, its output is shown, and its exit code is propagated;
    - classic: `classic mode has no workers to restart`;
    - unknown mode: exit 1 with `server mode unknown`.
- **C-7 Load script (amended 2026-11-17, review F2/F15 and gap 13):**
  - **Triggering:** `bench/restart-load.sh` switches servers with `ddev server`, restores the starting one, and triggers each restart with `ddev artisan frankenphp:workers:restart`, logging the host wall-clock start and end in ms. `.env` is switched only through `bench/lib/env-switch.sh`.
  - **Load start:** the moment the oha container is running (`docker inspect` polled every 50 ms). oha writes its `--db-url` file only when the run ends, so the file cannot mark the start. The offsets count from that moment.
  - **Two sources, because oha's request database stores only answered requests:**
    - **The load:** oha's sqlite database, aggregated in sqlite (never loaded whole into jq): completed requests, non-2xx and nearest-rank p99, each inside against outside the windows, placed by oha's own `start` column.
    - **The probe:** `bench/lib/request-probe.pl` sends GET on the same route from the host at 20 requests per second for the whole measure window, one curl process per request, and logs each with its host ms start, status and curl exit. Its failures (non-2xx, connection errors, curl exit 28 timeouts) inside against outside the windows are the `failures` the result reports. Connection errors only show up here.
    - oha's whole-run error count, not split by window, is kept as `oha_errors_whole_run`.
  - **Restart window:** from the trigger to 2 s after the command returns.
  - **Inside vs outside:**
    - per repeat: the probe failures and the load p99 inside the restart windows against outside them;
    - over the 3 repeats: the median of the p99 values and the sum of the failures.
  - **`--quick`:** warm-up 5 s, measure 30 s, 1 repeat, one restart at 15 s.
- **C-8 NFR-2 (amended 2026-11-17, review F3/F13/F14/F23):**
  - **What is compared:** `/debug/log-ping`, a debug-only route (hidden unless `DEBUG_ROUTES=true`, like the other `/debug/*` routes) that answers like `/api/ping` and writes one info entry to the default channel. `/api/ping` stays a plain public endpoint that never logs; the earlier `LOG_PING` switch is removed.
  - **The two runs:** `LOG_STACK=single` against `LOG_STACK=single,frankenphp`, set through `bench/lib/env-switch.sh` and restored afterwards. Both runs write the entry to the file, so the difference is the `frankenphp` channel alone.
  - **Method:** the full BR-21 method on worker mode, not `--quick` (`bench/run.sh ping` with `BENCH_PING_PATH=/debug/log-ping`).
  - **Log growth:** the file channel grows `storage/logs/laravel.log` by one line per request; the script cuts it back to its starting size on exit.
  - **Threshold:** < 5 % p95. A difference within the spread is reported as "no measurable cost".
  - **Traceability:** manual-test step 4 and task 3.
  - **Result (2026-11-17):** +8 % p95 (8.96 to 9.67 ms), outside the spread. NFR-2 is not met; the channel stays opt-in, and the decision on the budget is open for the owner. Re-measured in SL-60 (spec-20261121-1905-log-channel-overhead): every run was on a busy host, the miss stands, see BENCHMARKS.md.
- **C-9 Manual step 1 (amended):** uses the `bench/lib/env-switch.sh` functions: set `LOG_STACK=single,frankenphp` and `DEBUG_ROUTES=true`, run `ddev restart`, curl `/debug/log-ping`, read `ddev logs -s web`, then restore with `env_switch_restore`. The manual test is rewritten to these exact commands.
- **C-10 Caddyfiles:** no Caddyfile change is expected. `frankenphp_log` writes to Caddy's default logger at the given level. If entries don't show at info, the build adds `log { level INFO }` to the global options, records it, and checks parity.
- **C-11 Guide terms:**
  - **The tabs:** the six engine tabs are works, failures, recipes, support, numbers and experiments. The teach sections are build, watch and elsewhere (SL-53).
  - **Foundation guide:** a guide that is not tied to one lab page.
  - **Allowed on the site:** env names (`LOG_STACK`) and Artisan commands. Not allowed: slice ids, `.md` names and repo paths.
  - **Tests:** the tests run are those in AC-8 plus `GuidePageTest` and `ui-audit` on `/ai/guides`.
- **C-12 Terms:**
  - **BR-70:** structured JSON logs with a stderr fallback.
  - **BR-71:** worker restart is one Artisan command.
  - **BR-21:** warm-up 30 s, measure 60 s, 3 repeats, median, with a 5 % spread check.
  - **BR-26:** tests, a committed result table, and a story with a measured number.
  - **BR-27:** the guides explain how it works.
  - **SL-07:** the state-leak lab.
  - **SL-15 D-2:** the admin API stays local.
  - **The reserved bullet:** I-12 "Coming next" in the learning guide.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
- **C-13 MNC-3 check:** a config test asserts that no compose or Caddyfile file publishes 2019. The SL-15 `MetricsStackConfigTest` already covers this, and it is reused.
- **C-14 Test counts:** the full test list per AC is in tasks.md's Traceability. AC-8 means all four tests listed there.

