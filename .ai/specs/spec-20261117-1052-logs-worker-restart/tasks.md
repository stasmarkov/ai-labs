# Structured logs and worker restart — Tasks

**Spec**: spec-20261117-1052-logs-worker-restart | **Size**: M | **Tasks**: 5

## Phase 1: Code

- [x] 1. Structured log channel (backend)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, NFR-1, MNC-1
  - [x] 1.1 Add `FrankenphpLogHandler`, `RequestContextProcessor` and `AssignRequestId` middleware, the `frankenphp` channel and the `.env.example` note.
  - [x] 1.2 **Verify**: `tests/Feature/StructuredLogTest.php`:
    - the function path, using a test double;
    - the stderr JSON fallback;
    - the fields;
    - `X-Request-Id` reused and replaced;
    - two requests with different ids;
    - redaction;
    - the default stack unchanged.

- [x] 2. Restart command (backend)
  Covers: AC-6, MNC-3, MNC-4
  - [x] 2.1 Add `frankenphp:workers:restart`, with mode detection, the admin POST, `octane:reload`, the classic no-op, errors and the container check.
  - [x] 2.2 **Verify**: `tests/Feature/WorkersRestartCommandTest.php` (`Http::fake` for the admin API; the Octane call faked).

## Phase 2: Measure and document

- [x] 3. Restart under load (checks)
  Covers: AC-7, NFR-2
  - [x] 3.1 Add `bench/restart-load.sh`, run it on worker and Octane with the full method on a clean tree, and do the channel overhead ping.
  - [x] 3.2 **Verify**: the result files exist; `.env` and the server are restored.
  - **Record (2026-11-17):** run on the clean tree at commit 492fdd0, results committed in 87f2f8f (`bench/results/restart/2026-11-17/`, 4 cells plus `log-overhead.json`). Both scripts exited 0. `.env` was byte-identical to the copy taken before, the server was back on `frankenphp`, no `.env*backup*` was left, and `storage/logs/laravel.log` was cut back to its starting size.
    - **Worker:** probe failures inside 0/658 (ping) and 0/624 (BMW), load errors 0, admin call 51 to 85 ms, p99 inside/outside 11.4/10.9 and 244.5/44.4 ms.
    - **Octane:** 31/665 and 35/658 inside, 0 outside, load 5xx inside 526 and 94, load errors whole run 776 and 294 (250 and 200 timeouts at 30 s).
    - **NFR-2:** p95 8.96 ms with `single` against 9.67 ms with `single,frankenphp`: **+8 %, not within the 5 % threshold** (every log-on repeat is slower than every log-off repeat). Reported as measured; the channel stays opt-in (D-1). Whether to raise the budget, sample entries or accept the cost is the owner's call.

- [x] 4. Docs and guide (docs)
  Covers: AC-8
  - [x] 4.1 Add the BENCHMARKS section, the learning guide I-12, the `resources/guides/logs-restart.php` guide (six tabs plus teach) and README.
  - [x] 4.2 **Verify**: `DocsLinksTest`, `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, and `bench/ui-audit.mjs` on `/ai/guides`.

- [x] 5. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 5.1 Run the full suite, Pint and PHPStan; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | frankenphp_log entry with fields | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-2 | stderr JSON fallback | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-3 | X-Request-Id | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-4 | no leak between worker requests | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-5 | redaction | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-6 | restart command per mode | `tests/Feature/WorkersRestartCommandTest.php` | test | 2 |
| AC-7 | restart under load measured | `manual-test.md#step-3` | manual | 3 |
| AC-8 | guide and docs | `tests/Feature/GuideContentTest.php` | test | 4 |
| MNC-1 | default logging unchanged | `tests/Feature/StructuredLogTest.php` | test | 1 |
| MNC-2 | responses, fingerprint, parity | `manual-test.md#step-4` | manual | 5 |
| MNC-3 | admin API stays local | `tests/Feature/MetricsStackConfigTest.php` | test | 2 |
| MNC-4 | suite needs no server | `tests/Feature/WorkersRestartCommandTest.php` | test | 2 |
| NFR-1 | privacy | `tests/Feature/StructuredLogTest.php` | test | 1 |
| NFR-2 | overhead (`bench/log-overhead.sh`, `/debug/log-ping`) | `manual-test.md#step-4` | manual | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [x] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run (NFR-2 was run and **fails**: +8 % against < 5 %)
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 6. Make bench/restart-load.sh follow the spec's restart method (bash)
  Covers: AC-7
  - The committed script loads `/listings/1`, not `/listings?make=BMW`. It uses 15 s warm-up and 30 s measure, not BR-21's 30 s and 60 s with 3 repeats and a median. It restarts once at the middle, not 3 times at 15, 30 and 45 s. `--quick` uses 3 s and 10 s, not 5 s and 30 s with one restart at 15 s. It keeps only oha's summary: no per-request data, no restart windows (trigger to 2 s after the command returns), no p99 or failures inside against outside those windows, and no restart durations from host wall-clock ms (C-7). Add `tests/Feature/BenchRestartLoadTest.php` with stubbed ddev, docker and curl, following `BenchSlowLoadTest`.
  - **Verify**: `ddev artisan test --compact tests/Feature/BenchRestartLoadTest.php` passes. It asserts the routes, the 30/60/3 method, 3 restart offsets (1 at 15 s with `--quick`), per-repeat `inside`/`outside` p99 and failures, the median p99 and summed failures over the repeats, `restarts[].duration_ms`, and that `.env` and the starting server are restored on exit. `bench/restart-load.sh --quick --modes frankenphp-worker` writes a JSON with those fields.
- [x] 7. Add the LOG_PING ping entry and the channel overhead run for NFR-2 (laravel)
  Covers: NFR-2, AC-1
  - Nothing in `app/`, `config/`, `routes/` or `bench/` reads `LOG_PING`, but C-8 and step 1 of manual-test.md depend on it. `/api/ping` should write one info entry when `LOG_PING=true` (default off). Also add a bench path that runs the full BR-21 ping on worker mode twice: once with `LOG_STACK=single`, once with `LOG_STACK=single,frankenphp` and `LOG_PING=true`. It switches through `bench/lib/env-switch.sh`, restores `.env` afterwards, and writes a result file under `bench/results/restart/<date>/` with both p95 values and the difference in %.
  - **Verify**: a feature test shows `/api/ping` logs nothing on the `frankenphp` channel by default and exactly one info entry with `LOG_PING=true`, with the body unchanged. A bench test with stubs shows the overhead run switches and restores `.env` and records both p95 values.
- [x] 8. Log queue jobs with context "queue", not as "http" requests (laravel)
  Covers: AC-1, AC-4
  - `AssignRequestId` stores the id with `Context::addHidden`. Laravel dehydrates hidden context into every queued job's payload and hydrates it in the worker (`ContextServiceProvider`). So `RequestContextProcessor::requestFields()` sees a `request_id` in the queue worker and reports `context: "http"`, with method, path and route taken from the console's fake `request()`. C-3 requires `"queue"` for jobs run by a worker, and the guide says the fields are empty there. The fix is to mark job execution, for example with `Queue::before` and `Queue::after` setting a hidden flag. A job then logs with null request fields and `context: "queue"`, and a sync job inside a request stays `"http"`.
  - **Verify**: `StructuredLogTest` dispatches a logging job from a request onto the `database` connection and runs `queue:work database --once`. The entry has `context: "queue"` and null method, path and route. A sync job dispatched inside a request still logs `"http"`.
- [x] 9. Prove the per-request reset and the worker flag in the AC-4 test (laravel)
  Covers: AC-4, AC-1
  - The AC-4 test in `tests/Feature/StructuredLogTest.php` sends two requests to the same route. The middleware overwrites the id on each request, so the test would still pass if Context were never reset between worker requests. It never sets `$_SERVER['FRANKENPHP_WORKER']`, so `worker` is only checked to be a bool. The test should simulate worker mode and send two requests to different routes. Between them it should run the scoped-instance flush that the worker runs (`app()->forgetScopedInstances()`, as Octane's worker does) and log once there. That entry must have a null `request_id` and `context: "cli"`, and the second entry must carry its own path and route.
  - **Verify**: `ddev artisan test --compact tests/Feature/StructuredLogTest.php --filter=AC-4` passes, with `worker === true` asserted and no field of request 1 found in the later entries.
- [x] 10. Fill the BENCHMARKS section and the guide numbers from the result files (docs)
  Covers: AC-7, AC-8
  - `BENCHMARKS.md` "Worker restart under load" is a placeholder ("Not measured yet", "Pending the measured runs"), and its Method text describes the old method: one restart in the middle, on `/listings/1`. The `numbers` in `resources/guides/logs-restart.php` are all `pending: true`. The spec needs the table, What the numbers say, Surprises and the story with measured numbers, and guide numbers taken from the result files. Do this after tasks 3, 6 and 7 have produced the committed results.
  - **Verify**: the BENCHMARKS table values match `bench/results/restart/<date>/*.json`, and the NFR-2 overhead is reported against the 5 % threshold. The guide has no `pending` rows. `GuideContentTest`, `DocsLinksTest` and `NoReferencesInUiTest` pass.
  - **Record:** BENCHMARKS "Worker restart under load" has the table, overhead table, What the numbers say, Surprises and the story. The guide has 10 numbers read from the result files and no `pending` rows. Learning guide I-12 has the measured numbers.
- [x] 11. Run the UI audit on the logs-restart guide page itself (node)
  Covers: AC-8
  - Task 4's `bench/ui-audit.mjs --only /ai/guides` matched only the guides list page, so `/ai/guides/logs-restart` was never audited (C-11).
  - [x] **Verify**: `bench/ui-audit.mjs --only /ai/guides/logs-restart`, or a filter that matches it, reports the page with 0 failures.
- [x] 12. Manual: live log entry and restart command on the running servers
  Covers: AC-1, AC-6
  - **Record (2026-11-17, manual-test steps 1 and 2):** `.env` switched only through `bench/lib/env-switch.sh` (`LOG_STACK=single,frankenphp`, `DEBUG_ROUTES=true`) and restored byte-identical afterwards.
    - **Signature (C-2):** `/debug/runtime` on frankenphp-worker and frankenphp-octane, through `ReflectionFunction` inside the request: `frankenphp_log(string $message, int $level = 0, array $context = []): void`. It matches C-2, so the spec stands.
    - **Entry in `ddev logs -s web`:** `{"level":"info","logger":"http.handlers.php","msg":"ping","request.request_id":"abcdefgh-1234","request.method":"GET","request.path":"/debug/log-ping","request.route":"debug.log-ping","request.server_mode":"frankenphp-worker","request.worker":true,"request.elapsed_ms":1,"request.context":"http","level_name":"INFO","channel":"local"}`. It shows at info with no Caddyfile change (C-10). Two requests without an id got two different ULIDs, matching their `X-Request-Id` headers; a valid incoming id was reused. On Octane the entry has `server_mode` `frankenphp-octane` and `worker` true; on classic, `frankenphp` and false.
    - **Finding, fixed:** FrankenPHP 1.13.5 hands nested PHP arrays to slog as ordered maps, printed as `{"Map": {...}, "Order": [...]}`. The handler now passes flat dotted keys (`FrankenphpLogHandler::slogAttributes`, test `gap 12 frankenphp_log gets flat dotted attributes`). The stderr line keeps the nested C-4 shape.
    - **Restart command:** worker `workers restarted in 62 ms`, exit 0, and the next request ran the code edited after the worker had booted. Octane printed `INFO Reloading workers...`, exit 0. Classic printed `classic mode has no workers to restart`, exit 0. Admin URL pointed at `localhost:9`: `admin API: no answer from http://localhost:9`, exit 1. On the host, PHP 8.3 stops at Composer's platform check before the command runs; the refusal outside the container is covered by `WorkersRestartCommandTest` (F11).

## Phase: Gaps (converge round 2)

- [x] 13. Time the restarts from the load start, not from oha's database file (bash)
  Covers: AC-7
  - `run_repeat` in `bench/restart-load.sh` waits for `$WORK/oha.db` to exist and only then sets `load_start_ms` and fires the restarts. oha 1.16 writes the `--db-url` file after the run ends: a 6 s run with `ai-lab-oha:1.16.0` created `oha.db` at about 6.5 s ("Storing results to /out/oha.db"). With the full method the 60 s deadline in that loop runs out before the 60 s load ends, so the script exits with "oha did not start within 60 s". With `--quick` the restarts fire after the load has finished, and the inside windows mark requests that never saw a restart. The test stub in `BenchRestartLoadTest` creates `oha.db` as soon as docker starts, so it hides this. Fix: take the load start from the running container, for example by polling `docker inspect` for `State.Running` and `State.StartedAt`, without waiting for the db file. Schedule the offsets from that start, and keep the window placement relative to oha's `start` column.
  - [x] **Verify**: `BenchRestartLoadTest` uses a docker stub that writes `oha.db` only when the load ends. It asserts that every restart call is logged before the load stub exits, and that each window lies inside `[0, measure_s]`. A live `bench/restart-load.sh --quick --modes frankenphp-worker` writes `restarts[0].window_from_s` near 15 and `inside.requests > 0`.
- [x] 14. Probe the admin API before refusing outside the web container (laravel)
  Covers: AC-6, MNC-4
  - C-1 defines "outside the web container" as `IS_DDEV_PROJECT` not true and `localhost:2019` not answering within 1 s, and says the test fakes that HTTP call to prove the refusal. `FrankenphpWorkersRestartCommand::insideContainer()` checks only `config('ai.server.in_container')` and never probes the admin API. So the command refuses wherever `IS_DDEV_PROJECT` is unset, even if the admin API answers. The MNC-3 refusal test in `WorkersRestartCommandTest` uses an empty `Http::fake()`, which answers 200, so it would not prove the refusal under the spec's rule.
  - [x] **Verify**: `WorkersRestartCommandTest` covers three cases. With `in_container` false and the 1 s probe to `localhost:2019` faked as a connection error, the command exits 1 with the web-container message and sends no restart POST. With `in_container` false and the probe answering, worker mode restarts and exits 0. With `in_container` true, no probe is sent. `Http::preventStrayRequests` stays on.
- [x] 15. Name the overhead run in the README, the learning guide, the manual test and the NFR-2 row (docs)
  Covers: NFR-2
  - `bench/log-overhead.sh` is the NFR-2 measurement, but `README.md` lists only `bench/restart-load.sh`. Learning guide I-12 says the channel cost is measured with `bench/run.sh ping` and lists only `bench/restart-load.sh` under Bench. `manual-test.md` step 4 expects "the overhead ping in tasks.md is below 5 %" but gives no command, and the overhead is recorded in `bench/results/restart/<date>/log-overhead.json`, not in tasks.md. The Traceability NFR-2 row points at `manual-test.md#step-3`, the restart-load step, while the spec points at step 4.
  - **Verify**: the README commands block and learning guide I-12 name `bench/log-overhead.sh`. `manual-test.md` step 4 runs it and checks `difference_percent` < 5 in `log-overhead.json`. The NFR-2 row points at that step. `DocsLinksTest` and `GuideContentTest` pass.

## Phase: Review fixes (2026-11-17, after the ship report)

Every behaviour fix had a failing test first.

- [x] F1/F6 queue context. The flag is set on a worker's `JobProcessing` (non-sync) and cleared only on `Looping`/`WorkerStopping`, so `JobProcessed` listeners and a failed job's reported exception log `queue`. A sync job inside a queued job leaves it set. Tests: `F1 a failing queue job…`, `F1 a JobProcessed listener still logs with context queue…`, `F6 a sync job run inside a queued job…` (a real nested `dispatchSync`).
- [x] F7 an entry written in a served request before `AssignRequestId` runs has `context: "http"` with null fields (`app()->runningInConsole()` false). Test: `F7 an HTTP entry written before AssignRequestId runs…`.
- [x] F4/F17/F5/F12 `FrankenphpJsonFormatter` (Monolog `NormalizerFormatter`) builds the entry; the handler sends it on. Redaction is now an exact or suffix match on normalized keys, applied after normalization, so values inside objects are covered. Tests: `F4 F17 the formatter keeps an exception message and class…`, `F4 F17 the stderr line is the formatter output…`, `F5 F12 AC-5 redacts by exact key and well-known suffix…` (`X-Api-Key` redacted, `tokens_in` kept).
- [x] F8/F11/gap 14 container guard: `IS_DDEV_PROJECT=true` and the admin probe answers. Outside the container it refuses before any HTTP call. Tests: `F11 MNC-4 outside the web container the command refuses before any probe` (worker and Octane), `F8 inside the container an admin API that does not answer…`, `F8 a non-2xx answer on the admin port…`, `F8 inside the container the probe is sent before the restart`.
- [x] F19/F20/F21 `App\Support\AdminApiClient` wraps the admin calls. The command uses `$this->call('octane:reload')`, and `insideContainer()` returns a bool with no side effects. Tests: `F19 the admin API client…`.
- [x] F22 the `server` section in `config/ai.php` has its banner.
- [x] F13/F23/F3/F14 `LOG_PING` removed from `PingController`, `config/logging.php` and `.env.example`. The debug-only `/debug/log-ping` (hidden unless `DEBUG_ROUTES=true`) writes one entry. `bench/log-overhead.sh` loads it with `LOG_STACK=single` against `single,frankenphp` through `BENCH_PING_PATH`, so both runs log and the difference is the channel alone (C-8 amended). Tests: `F13 /api/ping writes no log entry…`, `NFR-2 /debug/log-ping…` (two), and `DebugRoutesGuardTest` rows.
- [x] F10/F16/F18/F24 bench `.env` handling: `restart-load.sh` uses `bench/lib/env-switch.sh` only. `.gitignore` gains `.env*backup*`. `log-overhead.sh` cuts `laravel.log` back to its starting size (removes it if the run created it). Its dead trap is gone. Tests: `F18 restart-load switches .env only through…`, `F10 every .env backup name…`, and the two `F16` tests.
- [x] F2/F15/gap 13 restart timing and failures. The load start is taken from the running oha container. Failures come from the timestamped probe `bench/lib/request-probe.pl` (20 rps, one curl per request, connection errors and timeouts included). The load rows are aggregated in sqlite (C-7 amended). Tests: `F2 restart-load counts connection errors inside the restart windows…` (a stub that refuses connections while the restart runs), `F15 restart-load aggregates the request rows in sqlite…`, and the load-ends-later ordering test. A live `--quick` run on frankenphp-worker gave `window_from_s` 15.016, 33 400 load requests inside, and 77 probe requests inside.
- [x] Gaps 6/9/11/15/F9: the method is BR-21 30/60 ×3 with restarts at 15/30/45 s and `--quick` at 5/30 ×1 with one restart at 15 s (`BenchRestartLoadTest`). The AC-4 worker test sets `FRANKENPHP_WORKER` and uses different routes with a reset between them. The ui-audit covers `/ai/guides/logs-restart`. README, learning guide I-12 and manual-test step 4 name `bench/log-overhead.sh`, and the NFR-2 row points at step 4.

## Orchestrator decision (2026-11-17)

- NFR-2 missed: the opt-in `frankenphp` channel costs +8 % p95 (8.96 → 9.67 ms) against the 5 % budget. The slice ships with the miss recorded, not hidden, and the threshold is not moved. The channel stays opt-in (D-1), so default logging (MNC-1) has no cost. Lowering it (fewer fields, sampling, async writes) is a follow-up for the owner to choose.
