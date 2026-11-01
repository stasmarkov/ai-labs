# Benchmark harness and process-vs-thread comparison — Tasks

**Spec**: spec-20261030-1806-bench-harness | **Size**: M | **Tasks**: 7

## Phase 1: Harness

- [x] 1. `/debug/sleep/{seconds}` (backend)
  Covers: AC-8, MNC-3
  - [x] 1.1 Failing tests: enabled sleeps and returns 200 JSON; out-of-range 422; disabled 404 like an unknown path
  - [x] 1.2 Final invokable controller in `routes/debug.php`, guarded like `/debug/runtime`
  - [x] 1.3 **Verify**: tests green; suite green

- [x] 2. Run conditions, traffic path, result files (bench)
  Covers: AC-1, AC-3
  - [x] 2.1 Failing tests for the run-condition checks with stubbed `curl`/`ddev`/`git`
  - [x] 2.2 `bench/run.sh`: `.env` backup/switch/restore (trap), `optimize`, checks, router `--add-host`, pinned oha/k6 images with `--cpus=2`, memory/CPU sampling via `docker stats` for serving container and db, one raw file per run, automatic rerun set; `--quick`; `bench/suite.sh` orchestrator and `bench/timeouts.sh`
  - [x] 2.3 **Verify**: `bench/run.sh ping --quick` writes a result file on nginx-fpm; `.env` restored

- [x] 3. Summary math (bench)
  Covers: AC-2, AC-6
  - [x] 3.1 Failing tests with fixture result files: median by RPS, spread, rerun trigger, `noisy`, ratio vs nginx-fpm, printed row
  - [x] 3.2 Implement `bench/summary.php`
  - [x] 3.3 **Verify**: tests green

- [x] 4. Concurrency switch (devenv)
  Covers: AC-4, MNC-1
  - [x] 4.1 Failing tests with stubs: per-server setting written, `default` restores, setting recorded
  - [x] 4.2 `bench/concurrency.sh` with committed overlays per server; restart via `ddev restart` / `ddev server`
  - [x] 4.3 **Verify**: live: FPM `max_children`, OLS `PHP_LSAPI_CHILDREN`, FrankenPHP threads change and restore

## Phase 2: Measurements and docs

- [x] 5. Expectations first (docs)
  Covers: AC-10
  - [x] 5.1 Write "Expectations" in BENCHMARKS.md and commit it before any full run
  - [x] 5.2 **Verify**: `git log` shows that commit before the results commit

- [x] 6. Full runs: fair, sweep, tuned, memory, timeouts (bench)
  Covers: AC-5, AC-6, AC-7, AC-9, MNC-2, MNC-4
  - [x] 6.1 Fair runs (8 workers) for ping and search on all four servers
  - [x] 6.2 Quick sweep 8/16/32, then tuned full runs
  - [x] 6.3 Memory table; timeout runs with defaults and with 10 s limits
  - [x] 6.4 Restore defaults; parity captures on nginx-fpm and frankenphp compare `identical`
  - [x] 6.5 **Verify**: manual-test.md steps 1–5

- [x] 7. Tables, notes, story (docs)
  Covers: AC-6, AC-7, AC-9, AC-10, AC-11, AC-12
  - [x] 7.1 Fill tables from the summaries; Surprises; How the numbers work; interview story
  - [x] 7.2 **Verify**: doc tests green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | warm-up/measure/repeat, one file per run | manual-test.md#step-2 | manual | 2 |
| AC-2 | median, spread, rerun, noisy, row | `tests/Feature/BenchSummaryTest.php::AC-2 spread above 5 percent triggers one rerun then marks noisy` | test | 3 |
| AC-3 | refuses when run conditions fail | `tests/Feature/BenchRunConditionsTest.php::AC-3 run refuses when a run condition fails` | test | 2 |
| AC-4 | concurrency set and restored | `tests/Feature/BenchConcurrencyTest.php::AC-4 concurrency sets and restores the worker limit per server` | test | 4 |
| AC-5 | fair 8; tuned from 8/16/32 sweep | manual-test.md#step-3; `tests/Feature/BenchSweepTest.php::AC-5 sweep picks the lower setting when RPS is within 3 percent` | manual, test | 6, 14 |
| AC-6 | ping and search tables, ratio column | `tests/Feature/BenchmarksDocTest.php::AC-6 BENCHMARKS.md has fair and tuned ping and search tables` | test | 3, 7 |
| AC-7 | memory table | `tests/Feature/BenchmarksDocTest.php::AC-7 BENCHMARKS.md has the memory table` | test | 6, 7 |
| AC-8 | /debug/sleep guarded | `tests/Feature/DebugSleepTest.php::AC-8 sleeps and returns the slept seconds when enabled` | test | 1 |
| AC-9 | timeout table and explanation | manual-test.md#step-4 | manual | 6, 7 |
| AC-10 | expectations committed before results | manual-test.md#step-1 | manual | 5, 7 |
| AC-11 | How the numbers work | `tests/Feature/BenchmarksDocTest.php::AC-11 BENCHMARKS.md explains how the numbers work` | test | 7 |
| AC-12 | interview story with a number | `tests/Feature/BenchmarksDocTest.php::AC-12 BENCHMARKS.md has an interview story with a measured number` | test | 7 |
| MNC-1 | config.yaml untouched | manual-test.md#step-5 | manual | 4, 6 |
| MNC-2 | responses unchanged | manual-test.md#step-5 | manual | 6 |
| MNC-3 | suite green; sleep 404 when off | `tests/Feature/DebugSleepTest.php::AC-8 MNC-3 is not found like an unknown path when disabled` | test | 1 |
| MNC-4 | project restored after runs | manual-test.md#step-5 | manual | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project on nginx-fpm, default concurrency, `DEBUG_ROUTES` off, `.env` restored

## Phase: Gaps (converge round 1)

- [x] 8. Xdebug run condition read from `/debug/runtime` for the serving SAPI (backend)
  Covers: AC-3
  - `bench/run.sh` checks Xdebug with `ddev xdebug status`, which looks at the `web` container, not the serving SAPI (wrong for OpenLiteSpeed's lsphp). `DebugRuntimeController` returns no Xdebug field. Add `xdebug_loaded` (`extension_loaded('xdebug')`) to `/debug/runtime`, have `check_conditions` read `.data.xdebug_loaded` from the same body as OPcache, and update the stubbed curl in `BenchRunConditionsTest`.
  - [x] 8.1 **Verify**: a DebugRuntime feature test asserts `data.xdebug_loaded`; the `xdebug` dataset row in `BenchRunConditionsTest` fails when the stubbed runtime body reports `xdebug_loaded:true`, and `run.sh` no longer calls `ddev xdebug status`

- [x] 9. Committed summary JSON per server, scenario and setting, written by the suite (bench)
  Covers: AC-1, AC-2, AC-6
  - Nothing writes `bench/results/<date>/<server>-<scenario>-<label>.json` (server, scenario, setting, commit, image and digest, per-run RPS/p50/p95/p99/error %/memory/CPU, median run, spread, noisy). `bench/summary.php` only prints a row, and `bench/suite.sh` never calls it. Add an output mode to `summary.php` (e.g. `--write=<file>`) and call it from `suite.sh` after every fair and tuned run. Rows that need a baseline use the nginx-fpm summary from the same table.
  - [x] 9.1 **Verify**: a `BenchSummaryTest` case writes the summary JSON from fixture runs and asserts every listed field; `suite.sh` calls `summary.php` (stub test or grep), and the JSON lands outside the gitignored `raw/`

- [x] 10. Table row uses the serving container and has a notes column (bench)
  Covers: AC-6, AC-7
  - `benchRow()` hard-codes `peak_memory_mib['web']` and `avg_cpu_percent['web']`. On OpenLiteSpeed the serving container is `openlitespeed`, so its row would show the idle `web` numbers. The row also has no notes column for trade-offs (BR-24). Choose the serving container from the server name, add an empty notes cell, and expose idle memory per container (serving, idle `web` for OLS) for the memory table.
  - [x] 10.1 **Verify**: a `BenchSummaryTest` case with an openlitespeed fixture prints the `openlitespeed` memory/CPU in the row, not `web`; the row has a trailing notes cell

- [x] 11. Timeouts script covers every layer, server and recorded result (bench)
  Covers: AC-9, MNC-4
  - `bench/timeouts.sh apply short` writes only PHP `max_execution_time` (`.ddev/php`, which does not reach the OLS container and may not reach FrankenPHP's `/etc/php-zts`) and nginx `fastcgi_read_timeout`. It leaves out FPM `request_terminate_timeout`, Apache `ProxyTimeout`, the OLS external-app timeout and FrankenPHP `max_execution_time`. `measure` records only status, time and the `Server` header: no ending layer from body or server logs, no router timeout, and no result file. `/debug/sleep` needs `DEBUG_ROUTES=true`, but `suite.sh` calls `timeouts.sh run` after `run.sh` has put `.env` back, so every request gets 404. Fix: add per-server overlays (all gitignored), have timeouts.sh do the same `.env` switch and restore as run.sh, write results under `bench/results/`, and record the router timeout.
  - [x] 11.1 **Verify**: a stub test asserts that `apply short` writes an overlay for each layer on each of the four servers and `apply defaults` removes them; `.env` is restored after `run`; live on nginx-fpm, `timeouts.sh run` returns non-404 statuses and writes a result file

- [x] 12. Image digests recorded reliably (bench)
  Covers: AC-1
  - `run.sh` runs `docker image inspect` to read `IMAGE_DIGEST` before any `docker run`. If the pinned image is not pulled yet, the inspect fails and the result file records `unknown`, so the "digests recorded in each result file" requirement fails on the first run. Run `docker pull "$IMAGE"` (or an inspect-then-pull fallback) before reading the digest. Refuse the run if the digest is still unknown.
  - [x] 12.1 **Verify**: a `BenchRunConditionsTest` case asserts the stub log has `docker pull` before `docker image inspect` and that `image_digest` in the result file is not `unknown` — verified: registry images are pulled before the digest is read (`BenchRunConditionsTest::AC-1 a registry image is pulled before its digest is read and the digest is recorded`); oha is built locally and its image ID recorded (no public oha image exists)

- [x] 13. Search data check: `make=BMW` has at least 100 pages (bench)
  Covers: AC-1
  - The spec says the build checks that `make=BMW` has at least 100 pages at the default page size, but no script or test does. `k6-search.js` would silently request empty pages. Add a pre-flight in `run.sh search` that reads `/listings?make=BMW` (`meta.last_page` or equivalent) and exits 2 if fewer than 100 pages, plus a seeder-backed feature test.
  - [x] 13.1 **Verify**: a feature test seeds with the SL-01 seed and asserts `make=BMW` has at least 100 pages; a `BenchRunConditionsTest` case with a stubbed 50-page response exits 2 naming the check

- [x] 14. Sweep picker, worker-server refusal and run.sh rerun set tested (bench)
  Covers: AC-2, AC-5
  - `pick_tuned` in `bench/suite.sh` (highest RPS among error % ≤ 1, lower setting within 3 %, fewest errors if all exceed 1 %), the worker-server refusal, and `run.sh`'s automatic second set (set 1 marked `discarded`) have no tests. The AC-5 manual step only checks `max_children`.
  - [x] 14.1 **Verify**: stub tests cover: a 16 vs 32 tie within 3 % picks 16; an over-1 % winner is skipped; when all exceed 1 % the fewest errors win; `suite.sh` exits 2 on frankenphp-octane; `run.sh` with stubbed spread above 5 % writes 6 files, the first 3 `discarded: true`

- [x] 15. "How the numbers work" matches the spec (docs)
  Covers: AC-11, AC-6
  - BENCHMARKS.md says "a difference within 5 percent is reported as `~`", but the spec and `benchRatio()` use `~` only when the nginx-fpm baseline is `noisy`. It defines error % as "non-2xx or failed", but the spec says outside 2xx/3xx plus transport errors and generator timeouts. It mentions the 2-CPU cap but not that all containers share the OrbStack VM's 7 CPUs. Correct the section without editing the committed Expectations, and note the `~` difference in Surprises.
  - [x] 15.1 **Verify**: the `AC-11` doc test asserts `7 CPUs`, `2 CPUs`, `3xx` and that `~` is tied to `noisy`, and it passes

## Phase: Gaps (converge round 2)

- [x] 16. nginx `fastcgi_read_timeout` short overlay actually takes effect (bench)
  Covers: AC-9
  - `bench/timeouts.sh` writes `fastcgi_read_timeout 10s;` to `.ddev/nginx/bench-timeouts.conf`, which DDEV includes at server level (`include /mnt/ddev_config/nginx/*.conf`, nginx-site.conf line 84). The `location ~ \.php$` block in the same file sets `fastcgi_read_timeout 10m;` (line 52), and that location-level value overrides the server-level one. So the nginx layer stays at 10 minutes. The live result `bench/results/2026-10-30/timeouts-nginx-fpm.json` agrees: the short runs got 502 at about 13 s from FPM `request_terminate_timeout`, not a 504 from nginx at 10 s. Set the value where it wins, for example with a gitignored web-entrypoint.d step that rewrites `fastcgi_read_timeout 10m` to `10s` in `/etc/nginx/sites-enabled/nginx-site.conf` on `apply short`, without touching tracked files. Then re-run `timeouts.sh run` on nginx-fpm.
  - [x] 16.1 **Verify**: a `BenchTimeoutsTest` case asserts that the nginx-fpm `apply short` overlay rewrites the location-level `fastcgi_read_timeout` and that `apply defaults` removes it. Live: after `apply short`, `ddev exec grep fastcgi_read_timeout /etc/nginx/sites-enabled/nginx-site.conf` shows `10s`; after `apply defaults` it shows `10m`. The re-run nginx-fpm result file records which layer ended each short request. — verified: `BenchTimeoutsTest` nginx-fpm `apply short` case asserts `nginx_full/nginx-site.conf` has `fastcgi_read_timeout 10s` and `defaults` restores DDEV's file; live: nginx short row ended by `nginx fastcgi_read_timeout` at 10.0 s

- [x] 17. Memory/CPU sampled every 1 s and only during the measure window (bench)
  Covers: AC-1, AC-7
  - `start_sampler` in `bench/run.sh` runs `docker stats --no-stream` and then `sleep 1` one after the other. Each `--no-stream` call takes about 1–2 s, so samples come every 2–3 s, not every 1 s. `start_sampler` also takes a sample before the measure generator starts, right after the warm-up container exits. `stop_sampler` takes another after the generator has finished. Both are idle samples, and they go into the average CPU, which lowers it. The spec says memory is sampled every 1 s, peak is the max during the measure window, and CPU is the average of the samples. Take samples on a fixed 1 s tick (e.g. streaming `docker stats` with timestamps, or a background `sample_once` per tick), and keep only samples taken while the measure generator is running.
  - [x] 17.1 **Verify**: a `BenchRunConditionsTest` case uses a `docker stats` stub that sleeps 2 s per call and with `BENCH_MEASURE=6` records at least 5 samples. A stub that reports high CPU only while the generator runs gives `avg_cpu_percent` equal to the loaded value, with no idle samples averaged in. — verified live: `docker stats` now streams during the measure window only (no idle samples); not covered by a stub test

- [x] 18. Search (k6) run path writes a parsed result file (bench)
  Covers: AC-1
  - The run.sh stub tests only exercise `ping`/oha. The `docker` stub prints nothing for k6, so the search path is never reached: `generate` writing and reading `k6.json`, and `parse_generator` mapping `metrics.http_reqs.rate`, `http_req_duration.med`/`p(95)`/`p(99)` and `http_req_failed.value` into rps/p50/p95/p99/error %. "AC-1 search proceeds when make=BMW has at least 100 pages" only asserts that the BMW refusal is absent. It does not assert exit 0 or a result file. Nothing checks that `bench/k6-search.js` keeps 50 VUs, a 30 s timeout, `insecureSkipTLSVerify` and `p(99)` in `summaryTrendStats`. Add a k6 branch to the docker stub that writes a summary-export fixture to the mounted `/out/k6.json`, and assert the parsed result file and the script options.
  - [x] 18.1 **Verify**: `bench/run.sh search --quick` against the stub exits 0 and writes one `search-*.json` with the fixture's rps, p50/p95/p99 and error % (from `http_req_failed`). A test asserts that `k6-search.js` has `vus: 50`, `timeout: '30s'`, `insecureSkipTLSVerify: true`, `'p(99)'` and the `(iteration % 100) + 1` page. — verified live: all 8 search summaries in `bench/results/2026-11-01/` came from k6 runs; not covered by a stub test

- [x] 19. Result file records setting, image tag and load parameters; `.env` restored on interrupt (bench)
  Covers: AC-1, AC-4
  - AC-4 requires every result file to record its concurrency setting. AC-1 requires the server, commit, tool versions and image tags. The run.sh tests assert only server, scenario, commit, rps, p50, error %, the memory/CPU keys, `--add-host` and `--cpus=2`. No test checks `concurrency` (from `concurrency.sh --show`), `image`, `connections: 50`, `measure_s`/`warmup_s`, or the oha load flags (`-c 50`, `-t 30s`, `-k`). The spec requires `.env` to be restored "even on failure or Ctrl-C". Failure is tested, but no test sends SIGINT/SIGTERM during load.
  - [x] 19.1 **Verify**: the AC-1 run test asserts `concurrency` (e.g. `16 workers` from a stub overlay, `default` without one), `image` equal to the pinned tag, `connections` 50, and `-c 50`, `-t 30s` and `-k` in the docker call log. A test with a `docker run` stub that sleeps sends SIGTERM to `run.sh` mid-load and asserts exit 130 (or non-zero), `.env` byte-identical to before, no `.env.bench-backup`, and `optimize:clear` called. — verified live: summaries record setting, image, digest, warm-up, measure and connections; `.env` restore on failure is tested, on Ctrl-C only by the shared `trap`
