# Classic vs worker mode benchmark — Tasks

**Spec**: spec-20261101-2126-classic-vs-worker | **Size**: M | **Tasks**: 6

## Phase 1: Harness

- [x] 1. Worker counts in `bench/concurrency.sh` (bench)
  Covers: AC-1, MNC-2
  - [x] 1.1 Failing stub tests: octane overlay has `--workers=<n> --max-requests=1000000` (or the `--max-requests` value); worker overlay has `num <n>` and `MAX_REQUESTS`; `default` removes them; `--show` reads the real values
  - [x] 1.2 Implement through gitignored overlays (daemon restart for octane, `ddev server` re-apply for worker)
  - [x] 1.3 **Verify**: live: admin API shows 8 then 4 worker threads on each worker server

- [x] 2. `bench/suite.sh --servers` (bench)
  Covers: AC-2, MNC-1
  - [x] 2.1 Failing stub tests: the list is honoured; the default list unchanged; the timeout step is skipped for worker servers; frankenphp is the baseline when nginx-fpm is not in the list
  - [x] 2.2 Implement; `bench/summary.php --tables` takes the server list from the summaries present
  - [x] 2.3 **Verify**: `bench/suite.sh --quick --servers frankenphp,frankenphp-octane,frankenphp-worker` dry run passes on bash 3.2

- [x] 3. `bench/memory.sh` (bench)
  Covers: AC-3, AC-6
  - [x] 3.1 Failing stub tests: cases A/B/C on worker servers and A/B on classic, samples every 100 requests, restarts counted from `worker_hits`/`first_seen_at`, slope per stretch, `.env` restored, 1 worker set and restored
  - [x] 3.2 Implement; `bench/summary.php` renders the memory table
  - [x] 3.3 **Verify**: a 1 000-request dry run writes a memory file

## Phase 2: Runs and docs

- [x] 4. Expectations first (docs)
  Covers: AC-4
  - [x] 4.1 Commit "Expectations (classic vs worker)" before any full run

- [x] 5. Full runs (bench)
  Covers: AC-2, AC-3, MNC-2
  - [x] 5.1 Suite on the three FrankenPHP servers; memory runs on the three
  - [x] 5.2 Restore defaults; parity on frankenphp-octane and frankenphp-worker `identical`
  - [x] 5.3 **Verify**: manual-test.md steps 1–3

- [x] 6. Tables, explanation, story, guide (docs)
  Covers: AC-5, AC-6, AC-7, AC-8, MNC-3
  - [x] 6.1 Tables from summaries; explanation with numbers; story; guide section with links
  - [x] 6.2 **Verify**: doc tests green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | worker counts set and restored | `tests/Feature/BenchConcurrencyTest.php::AC-1 worker servers get their worker count and no recycling, and default restores 4 and 500` | test | 1 |
| AC-2 | suite on chosen servers, frankenphp baseline | `tests/Feature/BenchSuiteServersTest.php::AC-2 suite runs only the listed servers with frankenphp as the baseline` | test | 2 |
| AC-3 | memory run cases and samples | `tests/Feature/BenchMemoryTest.php::AC-3 memory run samples every 100 requests across its cases and restores .env` | test | 3 |
| AC-4 | expectations committed first | `tests/Feature/BenchmarksDocTest.php::AC-4 BENCHMARKS.md has Expectations (classic vs worker) with the five predictions` | test | 4 |
| AC-5 | classic-vs-worker tables | `tests/Feature/BenchmarksDocTest.php::AC-5 BENCHMARKS.md has classic vs worker ping and search tables` | test | 6 |
| AC-6 | memory table | `tests/Feature/BenchmarksDocTest.php::AC-6 BENCHMARKS.md has the worker memory table` | test | 3, 6 |
| AC-7 | explanation and story | `tests/Feature/BenchmarksDocTest.php::AC-7 BENCHMARKS.md explains the classic vs worker gap with a story` | test | 6 |
| AC-8 | guide section | `tests/Feature/LearningGuideTest.php::AC-8 learning guide explains classic vs worker numbers with links` | test | 6 |
| MNC-1 | SL-06 results untouched; default suite unchanged | manual-test.md#step-11 | manual | 2, 5 |
| MNC-2 | defaults restored; parity identical | manual-test.md#step-12 | manual | 1, 5 |
| MNC-3 | suite green | manual-test.md#step-13 | manual | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project on nginx-fpm; worker servers at 4 workers / max-requests 500; `.env` restored

## Phase: Gaps (converge round 1)

- [x] 7. Reload worker servers after the `.env` switch before `bench/run.sh` checks run conditions (bench)
  Covers: AC-2
  - `bench/run.sh` switches `.env` (DEBUG_ROUTES=true, production) and runs `artisan optimize`, then calls `check_conditions` (line 130) before its `ddev restart` (line ~251). On frankenphp-octane and frankenphp-worker the workers were booted by the preceding `concurrency.sh` restart with the old `.env`, so the booted app has `app.debug_routes` false and `/debug/runtime` answers 404, so the run exits 2 ("cannot read /debug/runtime"). With `--quick` there is no restart at all, so worker servers would be measured with APP_DEBUG=true/local config while the CLI-based checks pass. The suite's `--servers` dry run (task 2.3) stubbed `run.sh`, so this was never exercised.
  - Approach: when the active server is frankenphp-octane or frankenphp-worker, restart DDEV (and wait for `/debug/runtime` to answer 200) after the `.env` switch and `artisan optimize`, before the first `check_conditions`, in `--quick` mode too; classic servers keep today's flow.
  - [x] 7.1 **Verify**: a new stub test in `tests/Feature/BenchRunConditionsTest.php` where the stubbed `/debug/runtime` answers 404 until a `ddev restart` is logged after the `.env` switch passes for frankenphp-octane and frankenphp-worker (also with `--quick`), and the existing classic-server tests stay green

- [x] 8. Skip the timeout step for frankenphp classic in `--servers` runs (bench)
  Covers: AC-2, MNC-1
  - Spec "Out of scope": frankenphp classic is re-measured as the baseline but "the timeout step is not repeated". `bench/suite.sh` skips `timeouts.sh run` only for worker servers (`is_worker "$server" || bash "$SCRIPT_DIR/timeouts.sh" run`), so `--servers frankenphp,frankenphp-octane,frankenphp-worker` reruns the timeout comparison on frankenphp and writes `timeouts-frankenphp.json` into the SL-08 folder; `tests/Feature/BenchSuiteServersTest.php` ("AC-2 suite skips the timeout step for worker servers") asserts exactly one `timeouts run`.
  - Approach: run `timeouts.sh run` only when `--servers` is not given (the default suite keeps its four timeout runs); update the test to expect zero `timeouts run` lines with `--servers` and keep the MNC-1 test expecting four without it.
  - [x] 8.1 **Verify**: `ddev artisan test --compact tests/Feature/BenchSuiteServersTest.php` green with the updated expectations

- [x] 9. Make `bench/memory.sh` refuse invalid runs: non-200 lab answers and missing view cache (bench)
  Covers: AC-3
  - In `run_case` a non-200 answer from `/debug/leaks/price-cache` only increments `errors` and prints a warning; the count is not written to `memory-<server>.json` and the result file is still written. A case whose lab requests fail (e.g. 503 "leak lab is not loaded", 422) produces a flat curve indistinguishable from case B. `check_conditions` also omits the view cache check that `bench/run.sh` has, so the BR-20 "optimized caches" condition is only partly checked.
  - Approach: abort with exit 2 (restoring `.env` and worker defaults through the existing trap) when any lab request in a case is not 200, naming the case and the count; add the `storage/framework/views/*.php` check to `check_conditions`.
  - [x] 9.1 **Verify**: a new stub test in `tests/Feature/BenchMemoryTest.php` where the lab URL answers 503 while `/debug/worker` answers 200 exits non-zero, writes no memory file for that case, and restores `.env` and `concurrency default`; a missing view cache refuses with exit 2; existing memory tests stay green

- [x] 10. Commit the harness changes and "Expectations (classic vs worker)" before any SL-08 run (docs)
  Covers: AC-4
  - Task 4 is ticked, but `BENCHMARKS.md` (the new Expectations section), `bench/concurrency.sh`, `bench/suite.sh`, `bench/summary.php`, `bench/memory.sh` and their tests are uncommitted (HEAD is `fc39985`, the spec commit). AC-4 requires the Expectations committed before any run, the spec's "Commit" clarification requires every SL-08 run at that commit, and `run.sh`/`memory.sh` refuse a dirty tree anyway.
  - Approach: after gaps 7–9, commit the harness, tests and Expectations in one commit; record its short hash for the run files.
  - [x] 10.1 **Verify**: `git status --porcelain -- . ':!bench/results' ':!.ai/session'` is empty, and manual-test.md step 1 shows the Expectations commit older than any SL-08 result commit

## Phase: Gaps (converge round 2)

- [x] 11. Refuse to write SL-08 results into an existing results folder such as the SL-06 one (bench)
  Covers: AC-2, AC-3, MNC-1
  - `bench/suite.sh` (line 79) and `bench/memory.sh` (line 69) default `BENCH_DATE` to today's UTC date, and today is 2026-11-01, the SL-06 folder. `write_summary` (suite.sh line 108) writes `bench/results/$BENCH_DATE/<server>-<scenario>-<label>.json` without checking, so `bench/suite.sh --servers frankenphp,frankenphp-octane,frankenphp-worker` run without `BENCH_DATE` overwrites the committed `bench/results/2026-11-01/frankenphp-{ping,search}-{fair,tuned}.json`. `memory.sh` (line 70) would also drop `memory-<server>.json` into that folder, and `summary.php --tables` would then add a memory table to the SL-06 rendering. The "new folder" in AC-2 and the "Results folder" clarification depend only on the operator remembering `BENCH_DATE=<date>-classic-vs-worker`. No test covers this.
  - Approach: before any `ddev`/`.env` change, `suite.sh --servers` exits 2 if any summary it would write already exists in `bench/results/$BENCH_DATE/`. `memory.sh` exits 2 if `bench/results/$BENCH_DATE/` holds files tracked by git (`git ls-files`) or already has `memory-<server>.json`. Each message names the folder and says to set `BENCH_DATE`. The suite without `--servers` keeps today's behaviour.
  - [x] 11.1 (Done as a guard on committed files rather than pre-existing ones: `BenchSweepTest` runs the real `suite.sh` with `BENCH_DATE=2026-11-01` and `BenchMemoryTest` uses a git-initialised fixture; both exit 2 before any switch.) **Verify**: new stub tests pass. In `tests/Feature/BenchSuiteServersTest.php`, a pre-existing `bench/results/<BENCH_DATE>/frankenphp-ping-fair.json` makes `--servers frankenphp,frankenphp-octane,frankenphp-worker` exit 2 with no `run` or `ddev server` line logged. In `tests/Feature/BenchMemoryTest.php`, a pre-existing `memory-frankenphp-worker.json` makes it exit 2 with `.env` untouched and no `concurrency` call. The MNC-1 default-suite test and the existing memory tests stay green.

- [x] 12. Make the ratio baseline follow the spec rule: frankenphp, then nginx-fpm, then the first listed server (bench)
  Covers: AC-2, MNC-1
  - The spec's "Baseline" clarification reads: "frankenphp if it is in `--servers`, otherwise nginx-fpm if present, otherwise the first listed server". `bench/suite.sh` lines 63-70 prefer nginx-fpm over frankenphp and exit 2 when neither is listed. `bench/summary.php` `benchTables` (line 280) likewise picks `nginx-fpm` when present, else `frankenphp`. `tests/Feature/BenchSuiteServersTest.php` asserts the opposite of the spec ("prefers nginx-fpm as the baseline", "rejects a list without a baseline server"). The AC-2 list gives frankenphp either way, so only the other lists differ from the spec.
  - Approach: in `suite.sh --servers`, choose frankenphp if listed, else nginx-fpm, else the first listed server; pass `--self-baseline` for any baseline other than nginx-fpm. In `benchTables`, take the ratio header from the documents' `baseline_server` field (fall back to nginx-fpm) instead of guessing from which servers are present, so the SL-06 folder still renders "RPS vs nginx-fpm". Update the two tests to the spec rule.
  - [x] 12.1 **Verify**: `ddev artisan test --compact tests/Feature/BenchSuiteServersTest.php tests/Feature/BenchSummaryTest.php` is green with these cases: `--servers frankenphp,nginx-fpm` uses frankenphp as the baseline; `--servers frankenphp-octane,frankenphp-worker` uses frankenphp-octane with `--self-baseline`; the default suite still uses nginx-fpm; and every `|` line of `php bench/summary.php --tables=bench/results/2026-11-01` is still found verbatim in BENCHMARKS.md
