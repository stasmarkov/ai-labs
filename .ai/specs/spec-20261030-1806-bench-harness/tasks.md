# Benchmark harness and process-vs-thread comparison — Tasks

**Spec**: spec-20261030-1806-bench-harness | **Size**: M | **Tasks**: 7

## Phase 1: Harness

- [ ] 1. `/debug/sleep/{seconds}` (backend)
  Covers: AC-8, MNC-3
  - [ ] 1.1 Failing tests: enabled sleeps and returns 200 JSON; out-of-range 422; disabled 404 like an unknown path
  - [ ] 1.2 Final invokable controller in `routes/debug.php`, guarded like `/debug/runtime`
  - [ ] 1.3 **Verify**: tests green; suite green

- [ ] 2. Run conditions, traffic path, result files (bench)
  Covers: AC-1, AC-3
  - [ ] 2.1 Failing tests for the run-condition checks with stubbed `curl`/`ddev`/`git`
  - [ ] 2.2 `bench/run.sh`: `.env` backup/switch/restore (trap), `optimize`, checks, router `--add-host`, pinned oha/k6 images with `--cpus=2`, memory/CPU sampling via `docker stats` for serving container and db, one raw file per run, automatic rerun set; `--quick`; `bench/suite.sh` orchestrator and `bench/timeouts.sh`
  - [ ] 2.3 **Verify**: `bench/run.sh ping --quick` writes a result file on nginx-fpm; `.env` restored

- [ ] 3. Summary math (bench)
  Covers: AC-2, AC-6
  - [ ] 3.1 Failing tests with fixture result files: median by RPS, spread, rerun trigger, `noisy`, ratio vs nginx-fpm, printed row
  - [ ] 3.2 Implement `bench/summary.php`
  - [ ] 3.3 **Verify**: tests green

- [ ] 4. Concurrency switch (devenv)
  Covers: AC-4, MNC-1
  - [ ] 4.1 Failing tests with stubs: per-server setting written, `default` restores, setting recorded
  - [ ] 4.2 `bench/concurrency.sh` with committed overlays per server; restart via `ddev restart` / `ddev server`
  - [ ] 4.3 **Verify**: live: FPM `max_children`, OLS `PHP_LSAPI_CHILDREN`, FrankenPHP threads change and restore

## Phase 2: Measurements and docs

- [ ] 5. Expectations first (docs)
  Covers: AC-10
  - [ ] 5.1 Write "Expectations" in BENCHMARKS.md and commit it before any full run
  - [ ] 5.2 **Verify**: `git log` shows that commit before the results commit

- [ ] 6. Full runs: fair, sweep, tuned, memory, timeouts (bench)
  Covers: AC-5, AC-6, AC-7, AC-9, MNC-2, MNC-4
  - [ ] 6.1 Fair runs (8 workers) for ping and search on all four servers
  - [ ] 6.2 Quick sweep 8/16/32, then tuned full runs
  - [ ] 6.3 Memory table; timeout runs with defaults and with 10 s limits
  - [ ] 6.4 Restore defaults; parity captures on nginx-fpm and frankenphp compare `identical`
  - [ ] 6.5 **Verify**: manual-test.md steps 1–5

- [ ] 7. Tables, notes, story (docs)
  Covers: AC-6, AC-7, AC-9, AC-10, AC-11, AC-12
  - [ ] 7.1 Fill tables from the summaries; Surprises; How the numbers work; interview story
  - [ ] 7.2 **Verify**: doc tests green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | warm-up/measure/repeat, one file per run | manual-test.md#step-2 | manual | 2 |
| AC-2 | median, spread, rerun, noisy, row | `tests/Feature/BenchSummaryTest.php::AC-2 spread above 5 percent triggers one rerun then marks noisy` | test | 3 |
| AC-3 | refuses when run conditions fail | `tests/Feature/BenchRunConditionsTest.php::AC-3 run refuses when a run condition fails` | test | 2 |
| AC-4 | concurrency set and restored | `tests/Feature/BenchConcurrencyTest.php::AC-4 concurrency sets and restores the worker limit per server` | test | 4 |
| AC-5 | fair 8; tuned from 8/16/32 sweep | manual-test.md#step-3 | manual | 6 |
| AC-6 | ping and search tables, ratio column | `tests/Feature/BenchmarksDocTest.php::AC-6 BENCHMARKS.md has fair and tuned ping and search tables` | test | 3, 7 |
| AC-7 | memory table | `tests/Feature/BenchmarksDocTest.php::AC-7 BENCHMARKS.md has the memory table` | test | 6, 7 |
| AC-8 | /debug/sleep guarded | `tests/Feature/DebugSleepTest.php::AC-8 sleep returns the slept seconds when enabled` | test | 1 |
| AC-9 | timeout table and explanation | manual-test.md#step-4 | manual | 6, 7 |
| AC-10 | expectations committed before results | manual-test.md#step-1 | manual | 5, 7 |
| AC-11 | How the numbers work | `tests/Feature/BenchmarksDocTest.php::AC-11 BENCHMARKS.md explains how the numbers work` | test | 7 |
| AC-12 | interview story with a number | `tests/Feature/BenchmarksDocTest.php::AC-12 BENCHMARKS.md has an interview story with a measured number` | test | 7 |
| MNC-1 | config.yaml untouched | verify `git diff --exit-code .ddev/config.yaml` after the runs | manual | 4, 6 |
| MNC-2 | responses unchanged | manual-test.md#step-5 | manual | 6 |
| MNC-3 | suite green; sleep 404 when off | `tests/Feature/DebugSleepTest.php::MNC-3 sleep is not found when disabled` | test | 1 |
| MNC-4 | project restored after runs | manual-test.md#step-5 | manual | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm, default concurrency, `DEBUG_ROUTES` off, `.env` restored
