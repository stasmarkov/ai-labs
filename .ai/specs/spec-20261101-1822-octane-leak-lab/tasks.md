# Octane state-leak lab — Tasks

**Spec**: spec-20261101-1822-octane-leak-lab | **Size**: M | **Tasks**: 6

## Phase 1: Lab

- [ ] 1. In-process worker test helper (backend)
  Covers: AC-6, AC-7
  - [ ] 1.1 Helper script run in a separate PHP process: boots Octane's `Worker` once (listeners from `config/octane.php`, `FakeClient`, `DEBUG_ROUTES=true`, `LARAVEL_OCTANE=1`) and prints JSON responses for a list of requests; a classic mode that boots a fresh process per request
  - [ ] 1.2 **Verify**: a smoke test sends two requests to `/debug/runtime` through the helper and gets two 200 responses

- [ ] 2. dealer-scope and request-at-boot (backend)
  Covers: AC-1, AC-4, AC-6, AC-7, MNC-2
  - [ ] 2.1 Failing tests: leak mode leaks in the worker helper; fixed mode does not; classic boots never leak
  - [ ] 2.2 Lab service provider, `CurrentDealer` (singleton vs scoped), request-capturing service, routes
  - [ ] 2.3 **Verify**: tests green

- [ ] 3. price-cache and number-locale (backend)
  Covers: AC-2, AC-3, AC-6, AC-7
  - [ ] 3.1 Failing tests as in 2.1, plus `cache_entries` growth and the config/translator locales reported as reset
  - [ ] 3.2 Static-cache formatter vs `Cache::remember`; `Number::useLocale` vs explicit locale
  - [ ] 3.3 **Verify**: tests green

- [ ] 4. `/debug/worker` (backend)
  Covers: AC-5, MNC-1
  - [ ] 4.1 Failing tests: fields present; `worker_hits` increases across calls in the worker helper and is 1 on each classic boot; 404 when disabled
  - [ ] 4.2 Controller and route
  - [ ] 4.3 **Verify**: tests green; suite green

## Phase 2: Live and docs

- [ ] 5. `bench/leaks.sh` and live runs (bench)
  Covers: AC-8, AC-9
  - [ ] 5.1 Failing stub tests: output format, refusal when disabled, `.env` restored, max-requests overlay written and restored
  - [ ] 5.2 Script; run on nginx-fpm, frankenphp-octane (500 and 1), frankenphp-worker
  - [ ] 5.3 **Verify**: manual-test.md steps 1–4; project back on nginx-fpm, `DEBUG_ROUTES` off

- [ ] 6. BENCHMARKS.md leak table and learning-guide section (docs)
  Covers: AC-9, AC-10, MNC-3
  - [ ] 6.1 Leak table from the live runs; guide section with links
  - [ ] 6.2 **Verify**: doc tests green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | dealer-scope singleton vs scoped | `tests/Feature/LeakLabTest.php::AC-1 dealer-scope leaks in a worker in leak mode and not in fixed mode` | test | 2 |
| AC-2 | price-cache static vs Cache | `tests/Feature/LeakLabTest.php::AC-2 price-cache leaks in a worker in leak mode and not in fixed mode` | test | 3 |
| AC-3 | Number::useLocale vs explicit | `tests/Feature/LeakLabTest.php::AC-3 number-locale leaks in a worker in leak mode and not in fixed mode` | test | 3 |
| AC-4 | request captured at boot | `tests/Feature/LeakLabTest.php::AC-4 request-at-boot leaks in a worker in leak mode and not in fixed mode` | test | 2 |
| AC-5 | /debug/worker fields | `tests/Feature/DebugWorkerTest.php::AC-5 worker endpoint reports pid, ids, memory and a request counter` | test | 4 |
| AC-6 | worker simulation proves leak/fix | `tests/Feature/LeakLabTest.php::AC-6 every scenario leaks only in leak mode inside one worker` | test | 1, 2, 3 |
| AC-7 | classic boots never leak | `tests/Feature/LeakLabTest.php::AC-7 no scenario leaks when every request boots the app` | test | 1, 2, 3 |
| AC-8 | live checker | `tests/Feature/BenchLeaksTest.php::AC-8 leaks.sh prints a count per scenario and mode and restores .env` | test | 5 |
| AC-9 | leak table | `tests/Feature/BenchmarksDocTest.php::AC-9 BENCHMARKS.md has the leak table` | test | 6 |
| AC-10 | guide section | `tests/Feature/LearningGuideTest.php::AC-10 learning guide explains state leaks with links` | test | 6 |
| MNC-1 | endpoints and parity unchanged; 404 when off | manual-test.md#step-4 | manual | 4, 5 |
| MNC-2 | lab state not reachable from normal routes | `tests/Unit/ArchTest.php::MNC-2 lab classes are used only by lab controllers` | test | 2 |
| MNC-3 | suite green | verify full Pest suite green | test | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm, `DEBUG_ROUTES` off
