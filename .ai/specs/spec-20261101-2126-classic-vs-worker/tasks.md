# Classic vs worker mode benchmark — Tasks

**Spec**: spec-20261101-2126-classic-vs-worker | **Size**: M | **Tasks**: 6

## Phase 1: Harness

- [ ] 1. Worker counts in `bench/concurrency.sh` (bench)
  Covers: AC-1, MNC-2
  - [ ] 1.1 Failing stub tests: octane overlay has `--workers=<n> --max-requests=1000000` (or the `--max-requests` value); worker overlay has `num <n>` and `MAX_REQUESTS`; `default` removes them; `--show` reads the real values
  - [ ] 1.2 Implement through gitignored overlays (daemon restart for octane, `ddev server` re-apply for worker)
  - [ ] 1.3 **Verify**: live: admin API shows 8 then 4 worker threads on each worker server

- [ ] 2. `bench/suite.sh --servers` (bench)
  Covers: AC-2, MNC-1
  - [ ] 2.1 Failing stub tests: the list is honoured; the default list unchanged; the timeout step is skipped for worker servers; frankenphp is the baseline when nginx-fpm is not in the list
  - [ ] 2.2 Implement; `bench/summary.php --tables` takes the server list from the summaries present
  - [ ] 2.3 **Verify**: `bench/suite.sh --quick --servers frankenphp,frankenphp-octane,frankenphp-worker` dry run passes on bash 3.2

- [ ] 3. `bench/memory.sh` (bench)
  Covers: AC-3, AC-6
  - [ ] 3.1 Failing stub tests: cases A/B/C on worker servers and A/B on classic, samples every 100 requests, restarts counted from `worker_hits`/`first_seen_at`, slope per stretch, `.env` restored, 1 worker set and restored
  - [ ] 3.2 Implement; `bench/summary.php` renders the memory table
  - [ ] 3.3 **Verify**: a 1 000-request dry run writes a memory file

## Phase 2: Runs and docs

- [ ] 4. Expectations first (docs)
  Covers: AC-4
  - [ ] 4.1 Commit "Expectations (classic vs worker)" before any full run

- [ ] 5. Full runs (bench)
  Covers: AC-2, AC-3, MNC-2
  - [ ] 5.1 Suite on the three FrankenPHP servers; memory runs on the three
  - [ ] 5.2 Restore defaults; parity on frankenphp-octane and frankenphp-worker `identical`
  - [ ] 5.3 **Verify**: manual-test.md steps 1–3

- [ ] 6. Tables, explanation, story, guide (docs)
  Covers: AC-5, AC-6, AC-7, AC-8, MNC-3
  - [ ] 6.1 Tables from summaries; explanation with numbers; story; guide section with links
  - [ ] 6.2 **Verify**: doc tests green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | worker counts set and restored | `tests/Feature/BenchConcurrencyTest.php::AC-1 worker servers get their worker count and no recycling, and default restores 4 and 500` | test | 1 |
| AC-2 | suite on chosen servers, frankenphp baseline | `tests/Feature/BenchSuiteServersTest.php::AC-2 suite runs only the listed servers with frankenphp as the baseline` | test | 2 |
| AC-3 | memory run cases and samples | `tests/Feature/BenchMemoryTest.php::AC-3 memory run samples every 100 requests across its cases and restores .env` | test | 3 |
| AC-4 | expectations committed first | manual-test.md#step-1 | manual | 4 |
| AC-5 | classic-vs-worker tables | `tests/Feature/BenchmarksDocTest.php::AC-5 BENCHMARKS.md has classic vs worker ping and search tables` | test | 6 |
| AC-6 | memory table | `tests/Feature/BenchmarksDocTest.php::AC-6 BENCHMARKS.md has the worker memory table` | test | 3, 6 |
| AC-7 | explanation and story | `tests/Feature/BenchmarksDocTest.php::AC-7 BENCHMARKS.md explains the classic vs worker gap with a story` | test | 6 |
| AC-8 | guide section | `tests/Feature/LearningGuideTest.php::AC-8 learning guide explains classic vs worker numbers with links` | test | 6 |
| MNC-1 | SL-06 results untouched; default suite unchanged | verify `git diff --exit-code bench/results/2026-11-01` after the runs | manual | 2, 5 |
| MNC-2 | defaults restored; parity identical | manual-test.md#step-3 | manual | 1, 5 |
| MNC-3 | suite green | verify full Pest suite green | test | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; worker servers at 4 workers / max-requests 500; `.env` restored
