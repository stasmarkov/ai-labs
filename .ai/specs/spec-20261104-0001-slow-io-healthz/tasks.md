# Slow I/O and healthz — Tasks

**Spec**: spec-20261104-0001-slow-io-healthz | **Size**: M | **Tasks**: 8

## Phase 1: Backend and services

- [x] 1. Stub VIN service (devops)
  Covers: AC-3, NFR-2
  - [x] 1.1 `.ddev/docker-compose.vinstub.yaml` + a small Node server file (e.g. `.ddev/vinstub/server.mjs`), `VINSTUB_DELAY_MS` default 300; reachable as `http://vinstub:3000` from all server modes
  - [x] 1.2 **Verify**: `ddev restart`; from each of the 6 servers, the median of 10 idle `curl` calls to `http://vinstub:3000/vin/TEST` is 280–320 ms

- [x] 2. Slow endpoint (backend)
  Covers: AC-1, MNC-1, MNC-3
  - [x] 2.1 `config('services.vinstub')`, `VinReportService` + interface, `ShowSlowListingController`, route `listings.slow`
  - [x] 2.2 **Verify**: feature tests with `Http::fake` (200 with waited_ms, 404 before any call, 503 on failure and on timeout)

- [x] 3. Health endpoint (backend)
  Covers: AC-2, MNC-1
  - [x] 3.1 Route file loaded without middleware groups; `HealthzController` returns `{"status":"ok"}`
  - [x] 3.2 **Verify**: test asserts 200, no `Set-Cookie`, zero queries (`DB::listen`), no session started, no HTTP calls (`Http::preventStrayRequests`)

## Phase 2: Bench

- [x] 4. Autoscale in concurrency.sh (bench)
  Covers: AC-6, MNC-2
  - [x] 4.1 `autoscale --threads --max-threads --max-wait` for frankenphp via the overlay; `--show`; `default`; error on other servers
  - [x] 4.2 **Verify**: `BenchConcurrencyTest` cases (stubs as the existing tests do); manual `--show` on frankenphp

- [x] 5. slow-load.sh (bench)
  Covers: AC-4, AC-5, NFR-1, NFR-2, MNC-4
  - [x] 5.1 Load + probe containers (oha for http; `bench/k6-sse.js` closed-loop reader for `sse`); `bench/slow-load-matrix.sh` wrapper for the 8 cells; BR-21 method, `--quick`, `.env` set and restore, JSON output with metadata and spreads
  - [x] 5.2 **Verify**: tests in the style of the existing bench script tests; a `--quick` run on nginx-fpm prints a table

## Phase 3: Measure and document

- [x] 6. Committed 8-cell run (measurement)
  Covers: AC-7, NFR-1
  - [x] 6.1 nginx-fpm 8 and 32, frankenphp fixed 14 and autoscale 14→64 / 5 s, both workloads, 3 repeats; restore defaults and nginx-fpm after
  - [x] 6.2 **Verify**: 8 JSON files committed; `bench/concurrency.sh --show` prints `default` on nginx-fpm at the end
    - Result: `bench/slow-load-matrix.sh` exit 0 at commit 920d764; 6 files in `bench/results/slow-load/2026-11-03/` and the 2 autoscale cells in `2026-11-04/` (the run crossed midnight UTC); ended on nginx-fpm with `default`; all health-p95 spreads below 20 % (largest 8.5 %).

- [x] 7. BENCHMARKS.md, guide section 19, code map (docs)
  Covers: AC-7, AC-8
  - [x] 7.1 BENCHMARKS section with the tables, the Mermaid chart and the three subsections
  - [x] 7.2 Guide section 19; section 16 note on the SSE workload
  - [x] 7.3 **Verify**: `LearningGuideTest` and `BenchmarksDocTest` needles read from the result files

- [x] 8. Parity, fingerprint and bench (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 8.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 8.2 **Verify**: outputs recorded here
    - Result: 965 tests, Pint, PHPStan, typecheck, build green; fingerprint unchanged; parity identical on all 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree after the docs commit.
    - Suite 928 passed (7887 assertions); Pint passed; PHPStan no errors; typecheck and build clean.
    - Fingerprint: app.css `assets/app-CjRfEL-0.css` `82042c94c3ab...ce71`, matches BENCHMARKS.md baseline.
    - Parity: `identical` on nginx-fpm, apache-fpm, frankenphp, frankenphp-octane, frankenphp-worker, openlitespeed; snapshots restored, nginx-fpm active again.
    - `bench/run.sh ping --quick`: exits 2 (run condition: working tree has uncommitted changes outside bench/results/ and .ai/session/). run.sh is unmodified; rerun after committing.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | slow endpoint 200/404/503 | `tests/Feature/SlowListingTest.php` | test | 2 |
| AC-2 | healthz with no DB, session, cookie or HTTP | `tests/Feature/HealthzTest.php` | test | 3 |
| AC-3 | stub service delay | `manual-test.md#step-1` | manual | 1 |
| AC-4 | http workload script | `tests/Feature/BenchSlowLoadTest.php` | test | 5 |
| AC-5 | sse workload script | `tests/Feature/BenchSlowLoadTest.php` | test | 5 |
| AC-6 | autoscale overlay | `tests/Feature/BenchConcurrencyTest.php` | test | 4 |
| AC-7 | committed matrix and BENCHMARKS | `tests/Feature/BenchmarksDocTest.php` | test | 6, 7 |
| AC-8 | guide section 19 | `tests/Feature/LearningGuideTest.php` | test | 7 |
| MNC-1 | existing routes and parity | `manual-test.md#step-5` | manual | 8 |
| MNC-2 | defaults restored, no config.yaml edits | `manual-test.md#step-4` | manual | 4, 6 |
| MNC-3 | fingerprint, offline suite | `manual-test.md#step-5` | manual | 8 |
| MNC-4 | run.sh unchanged | `tests/Feature/BenchRunConditionsTest.php`, `tests/Feature/BenchScriptsTest.php` | test | 8 |
| NFR-1 | spread recorded and flagged | `manual-test.md#step-3` | manual | 6 |
| NFR-2 | probe, load and stub isolated | `manual-test.md#step-2` | manual | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 9. Stub reply matches the Q7 contract (devops)
  Covers: AC-3, AC-1
  - `.ddev/vinstub/server.mjs` answers `{vin, status: 'clean', waited_ms}`; Clarification Q7 fixes the reply as `{"vin":"…","make_check":"ok","recalls":0,"source":"vinstub"}`. `SlowListingTest` fakes the Q7 shape, so the live `vin_report` differs from what the tests prove. Change the stub body and make `VinStubTest` assert the exact JSON.
  - **Verify**: `VinStubTest` asserts the exact Q7 body for `/vin/TEST`; `curl -s https://ai-lab.ddev.site/listings/1/slow` shows `vin_report.source` = `vinstub` and `make_check` = `ok`.

- [x] 10. Stub checked from all 6 servers (devops)
  Covers: AC-3
  - Task 1.2's Verify asks for the 10-call median from each of the 6 servers (Q12), plus `docker ps` showing the `node` container. Task 1's record only has the active server's web container (median about 307 ms).
  - [x] **Verify**: for nginx-fpm, apache-fpm, frankenphp, frankenphp-octane, frankenphp-worker and openlitespeed, the median of 10 idle `ddev exec curl` calls to `http://vinstub:3000/vin/TEST` is 280–320 ms, recorded here; `docker ps` shows `ddev-interview-vinstub` running `node`; the server is back on nginx-fpm.
  - Recorded medians of 10 idle curls (ms): nginx-fpm 303, apache-fpm 304, frankenphp 304, frankenphp-octane 304, frankenphp-worker 307, openlitespeed 305; final nginx-fpm run 309. `ddev-interview-vinstub` runs `node /app/server.mjs`. Covered by the AC-3 tests in `tests/Feature/VinStubTest.php`.

- [x] 11. slow-load.sh method, flags and output paths per spec (bench)
  Covers: AC-4, AC-5, AC-7
  - `bench/slow-load.sh` has no `--users` flag (it uses `--connections`, default 64 for both workloads). The spec asks for `--users`, default 100 for `http` and 30 for `sse`.
  - The warm-up is 10 s (line 62), but the BR-21 method in `run.sh` is 30 s. `--quick` is 3 s / 10 s (lines 57–58), but AC-4 asks for 5 s / 15 s / 1 repeat.
  - Quick runs write to `bench/results/slow-load/` (line 198). Q14 sends them to the gitignored `bench/results/raw/`. Committed runs should go to `bench/results/slow-load/<UTC date>/`, but they are written flat as `<workload>-<label>.json`.
  - Update `bench/slow-load-matrix.sh` and the `BenchSlowLoadTest` assertions to match (the tests currently assert `-c 64` and `VUS=48` via `--connections`).
  - [x] **Verify**: `BenchSlowLoadTest` asserts `--users` with defaults 100/30, a 30 s warm-up with 60 s measure and 3 repeats by default, 5/15/1 with `--quick`, quick output under `bench/results/raw/`, and full output under `bench/results/slow-load/<date>/`. `--dry-run` of the matrix passes `--users`.

- [x] 12. Health probe figures per Scope and Q5/Q11 (bench)
  Covers: AC-4, AC-7, NFR-1
  - The probe runs at 20 req/s with a 30 s timeout (`PROBE_QPS=20`, `-t 30s`). The spec asks for 5 req/s with a 10 s client timeout, which Decision 4 needs so that it stays above `max_wait_time` 5 s.
  - `parse_oha` lumps every error into a single `errors` field. Q5 asks for separate 502, 504, other-5xx, other-non-2xx and timeout counts, which the AC-7 table needs.
  - When nothing responds, the percentiles are replaced with 30 s. Q5 asks for percentiles over responded requests only, with timeouts excluded and counted as errors.
  - `median` only holds `health_p95_ms` and `health_errors`. It has no health p50/p99, health error share or breakdown.
  - `spread_percent` covers only `health_p95` and `load_rps`. Q11 asks for a spread for every figure.
  - [x] **Verify**: `BenchSlowLoadTest` asserts `-q 5` and a 10 s timeout on the probe, and a breakdown `{502, 504, other_5xx, other_non_2xx, timeouts}` from a stubbed oha output that mixes 200, 502 and 504 responses with timeouts. It also asserts health p50/p95/p99 computed without the timeouts, an error share of (non-2xx + timeouts) ÷ sent, medians for every figure, and spreads for every figure, with the flag still set from the health p95 spread.

- [x] 13. SSE workload reads to [DONE] over rotating ids with the spec's fake stream (bench)
  Covers: AC-5
  - `bench/k6-sse.js` only checks for `"type":"text_delta"` in the body. It never checks for `data: [DONE]`, so a stream cut off part-way counts as a success, and AC-5's "incomplete stream" share is never measured.
  - It also always requests listing `LISTING_ID=1`. Q3 asks for rotating ids from 1 to 50 000.
  - `slow-load.sh` does not set `AI_FAKE_TOKENS=120` or `AI_FAKE_TOKEN_DELAY_MS=50` (Scope). The stream therefore runs on the config default of 200 tokens, about 10 s, instead of about 6 s.
  - The load figures should be time to `[DONE]` p50/p95/p99, streams per second, and the share of streams that were non-200 or incomplete.
  - [x] **Verify**: `BenchSlowLoadTest` asserts that `k6-sse.js` checks for `[DONE]` and rotates the listing id, and that `.env` during an sse run contains `AI_FAKE_TOKENS=120` and `AI_FAKE_TOKEN_DELAY_MS=50` and is restored afterwards. A `--quick` sse run on nginx-fpm shows several distinct `/listings/{id}/describe` ids in the access log.

- [x] 14. Autoscale verified on the live frankenphp server (bench)
  Covers: AC-6, MNC-2
  - Task 4.2's Verify ("manual `--show` on frankenphp") was ticked without being run. The task 4 record says the overlay was only exercised against a stubbed `ddev`, and that "whether FrankenPHP itself accepts `max_wait_time` is not checked".
  - Run `manual-test.md#step-4`.
  - **Verify**: on `ddev server frankenphp`, `bench/concurrency.sh autoscale --threads 14 --max-threads 64 --max-wait 5` restarts cleanly. `/healthz` answers 200, `ddev logs` shows no Caddyfile error, and `--show` prints the autoscale values. `default`, then `--show`, prints `default`. `ddev server nginx-fpm && git status --short .ddev` shows no tracked change. Record the output here.
  - [x] Recorded 2026-11-04 on the live server (run as `bash bench/concurrency.sh ...`, the file is mode 644):
    - `ddev server frankenphp` -> `Active: frankenphp`.
    - `autoscale --threads 14 --max-threads 64 --max-wait 5` -> restarted cleanly, `frankenphp: autoscale 14 to 64 threads, max-wait 5s`.
    - `--show` -> `autoscale 14 to 64 threads, max-wait 5s`. In the container `FRANKENPHP_CONFIG` = `num_threads 14 max_threads 64 max_wait_time 5s`.
    - `GET /healthz` -> 200 `{"status":"ok"}`. `ddev logs` has only info lines ("using config from file", "adapted config to JSON"), no error or Caddyfile error, so FrankenPHP accepts `max_wait_time`.
    - `default` -> `frankenphp: default`; `--show` -> `default`.
    - `ddev server nginx-fpm` -> `Active: nginx-fpm`; `git status --short .ddev` shows only the untracked `.ddev/docker-compose.vinstub.yaml` and `.ddev/vinstub/` (from task 1), no tracked change. `/healthz` 200.

## Phase: Gaps (converge round 2)

- [x] 15. SSE error share counts streams, not k6 checks (bench)
  Covers: AC-5, AC-7
  - `bench/k6-sse.js` runs two checks per stream (`status is 200`, `stream ends with [DONE]`). `parse_k6` in `bench/slow-load.sh` sets `errors` to `.metrics.checks.fails` and `error_percent` to `(1 - .metrics.checks.value) * 100`, which are per check, not per stream. A non-200 stream fails both checks and counts twice in `errors`. A 200 stream cut off before `[DONE]` fails one of two checks and counts as half in `error_percent`. Example: 10 streams with 2 incomplete 200s give 2 fails out of 20 checks, so 10 % instead of the 20 % AC-5 asks for.
  - The `BenchSlowLoadTest` k6 stub hides this: `http_reqs.count` is 300 while `checks` has 297 passes and 3 fails, which is one check per stream.
  - When no stream completes, `parse_k6` falls back to `http_req_duration`. Those are the durations of failed requests, so the figure is no longer the time to `[DONE]`.
  - Fix: add a k6 `Rate` metric (for example `stream_failed`) that is set once per stream (true when the status is not 200 or `[DONE]` is missing). `parse_k6` should take `errors` and `error_percent` from it. When `stream_done_ms` is missing, record null percentiles plus a flag, and do not use `http_req_duration`.
  - **Verify**: in `BenchSlowLoadTest`, a k6 stub with 2 checks per stream and a separate per-stream failure metric (for example 100 streams, 5 non-200 and 5 incomplete) gives `load.errors` = 10 and `load.error_percent` = 10. A stub with no `stream_done_ms` gives no time-to-`[DONE]` percentiles taken from `http_req_duration`. `k6-sse.js` declares the per-stream failure metric.

- [x] 16. A starved health probe is shown in the medians and the table, not as a measured 10 s p95 (bench)
  Covers: AC-4, AC-7, NFR-1
  - Q5 says the probe percentiles cover only requests that got an HTTP response, and timeouts are counted but left out of the percentiles. When no probe request got a response, `parse_oha` in `bench/slow-load.sh` writes 10 000 ms into p50, p95 and p99. The `no_response: true` marker stays only in `runs[]`. `median.health_p95_ms` and the stdout table then report 10000 with no marker (the test `NFR-1 slow-load records a probe that got no answer as the 10 s timeout` asserts exactly this). So the AC-7 table built from the committed JSON would show a 10 s health p95 as if it were measured. This can happen in the committed sse cell on nginx-fpm static 8: the task 5 quick run on nginx-fpm already starved the probe.
  - The script header still says the probe runs at "a steady 20 requests per second". The script uses `PROBE_QPS=5`, and BENCHMARKS and the guide link this header.
  - Fix: record the number of no-response repeats in the summary (for example `median.health_no_response_repeats` or a top-level `health_no_response`). Mark these values in the stdout table (for example `>=10000 (no response)`) so the docs table can flag them. Correct the header to 5 requests per second with a 10 s timeout.
  - **Verify**: `BenchSlowLoadTest` with `STUB_HEALTH_P95=none` asserts that the summary JSON has a top-level no-response count of 1 and that the stdout table marks the health p95 as no response. A test asserts that the `slow-load.sh` header says 5 requests per second and does not say 20.

- [x] 17. Run and record manual-test step 2 for probe and load isolation (bench)
  Covers: NFR-2
  - The Traceability table verifies NFR-2 with `manual-test.md#step-2`: during `bench/slow-load.sh --workload http --quick`, `docker ps` must show separate load and probe containers. The task 5 record only has the quick-run table figures. Nothing records the `docker ps` output, the `--cpus=2` cap on the live containers, or that the stub served the load from its own `node` container. The `NFR-2 ...` tests in `BenchSlowLoadTest` only check the arguments passed to a stubbed `docker`.
  - **Verify**: while a live `bench/slow-load.sh --workload http --quick` runs on nginx-fpm, `docker ps` shows `slow-load-load-<pid>` and `slow-load-probe-<pid>` as separate containers, `docker inspect -f '{{.HostConfig.NanoCpus}}'` reports 2000000000 for each, and `ddev-interview-vinstub` is running. Record the output here, with `.env` restored afterwards.

  Gap 15 result (review fix pass item 1): `bench/k6-sse.js` declares the `stream_failed` Rate (one add per iteration) and the `stream_done_ms` Trend; `parse_k6` reads only those. `BenchSlowLoadTest` has a stub with 2 checks per stream (100 streams, 10 failed -> errors 10, error_percent 10) and a stub with no `stream_done_ms` (null percentiles, `no_stream_completed: true`). Verified on the live harness that a k6 Rate summary exports `passes` = true adds.

  Gap 16 result (review fix pass item 3): the marker is `starved` rather than a no-response count alone: `runs[].health.starved`, `median.health_starved`, top-level `health_starved_repeats`, table cell "10000 starved". Header corrected to 5 requests per second. Tests: `NFR-1 slow-load marks a probe that got no answer as starved ...`, `NFR-1 slow-load header describes the probe as 5 requests per second, not 20`.

  Gap 17 record (live `bench/slow-load.sh --workload http --quick --label fixcheck` on nginx-fpm, 2026-11-04, taken about 5 s into the run, `.env` restored afterwards):
  ```
  docker ps (names, images):
  slow-load-load-39179    autopulse-oha:1.16.0
  slow-load-probe-39179   autopulse-oha:1.16.0
  ddev-interview-vinstub  node:24-alpine   (node /app/server.mjs)
  docker inspect -f '{{.HostConfig.NanoCpus}}':
  slow-load-load-39179   2000000000
  slow-load-probe-39179  2000000000
  ```
  The load and the probe are separate containers, each capped at 2 CPUs, and the stub runs in its own `node` service.

## Phase: Review fix pass

Fixed (each with tests in `tests/Feature/BenchSlowLoadTest.php`, `SlowListingTest.php`, `VinStubTest.php`, `BenchConcurrencyTest.php` unless noted):

- [x] 1. `k6-sse.js`: one failure per stream through the `stream_failed` Rate and a `stream_done_ms` Trend; `parse_k6` never reads per-check counts and never falls back to `http_req_duration` (null times plus `no_stream_completed` instead). `VUS` defaults to 30.
- [x] 2. Probe coordinated omission: `PROBE_CONNECTIONS` = qps x timeout = 50 and oha `--latency-correction` (supported by the pinned oha 1.16.0, checked with `--help` and a live run). Header comment now says 5 rps.
- [x] 3. Spec Q5 revised. `health_p95_answered_ms` (answered only, null when starved) and `health_p95_ms` (all sent probes, an unanswered probe counted as the 10 s timeout value, read off oha's percentile points by linear interpolation). `starved` carried into runs, the median block, a top-level repeat count and the printed table. Transport errors (not only timeouts) count as the timeout value in the all-sent p95; this goes slightly beyond the brief and is documented in Q5.
- [x] 4. Listing-id rotation: `ID_OFFSET` is 0 in warm-ups and `repeat x 10007 mod 50000` in measure windows. k6 starts its counter at the offset; oha reads `--urls-from-file` with 2000 consecutive ids from the offset (oha cannot rotate by itself; it picks from the list). The offset is recorded as `runs[].id_offset`. Tests check the offsets, the list length and the wrap-around.
- [x] 5. `JsonErrorRenderer`: `$request->is('listings/*/describe', 'listings/*/slow')`. Tests: `/listings/0/slow` and `/listings/abc/slow` give a JSON 404 without an Accept header.
- [x] 6. median is the true median (mean of the two middle values for an even count); a spread with median 0 and max > 0 is null, printed "n/a (median 0)"; all-zero is 0; `BENCH_REPEATS` below 1 or not a number exits 2 before anything runs.
- [x] 7. `listings.slow` is behind `EnsureDebugRoutes` and `throttle:slow` (`services.vinstub.per_minute`, env `VINSTUB_PER_MINUTE`, default 60, limiter registered in `AiServiceProvider` next to `describe`). Spec Scope and AC-1 amended. `slow-load.sh` sets `DEBUG_ROUTES=true` and `VINSTUB_PER_MINUTE=1000000` for `http` and restores `.env`. Tests: 404 when off, 429 over the limit, default 60.
- [x] 8. Stub: malformed percent-encoding gives a 400 JSON and the server keeps serving; compose `restart: unless-stopped`. Tested in `VinStubTest` by starting the real node server.
- [x] 9. Removed `VinReportServiceInterface` and `VinReportClientInterface` (no test faked either; tests use `Http::fake`). `VinReportService` and `VinStubClient` are concrete `final readonly` classes; the `AppServiceProvider` bindings are gone and the file has no diff.
- [x] 10. `concurrency.sh`: a single `usage()` listing all forms including `autoscale`, and one `die2` exit-2 path (the "autoscale only on frankenphp" error uses it too).
- [x] 11. Gates: `bash -n` on every `bench/*.sh`, full suite, Pint, PHPStan, then one `--quick` run per workload on nginx-fpm (figures in the review fix pass report). `.env` diff empty, `concurrency.sh --show` = default, server nginx-fpm.

Skipped or changed from the brief:

- Gap 16's Verify asked for a "top-level no-response count"; implemented as `health_starved_repeats` (same meaning, name follows Q5's "starved").
- No change to `.ddev/config.yaml`, `.ddev/servers/**`, `.env`, `BENCHMARKS.md` or `docs/learning-guide.md`. Spec Q6 (the slow route forcing `Accept: application/json` in a route middleware) is satisfied by the renderer's route-name and path match instead; not changed.
