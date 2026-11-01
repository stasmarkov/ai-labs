# Octane state-leak lab — Tasks

**Spec**: spec-20261101-1822-octane-leak-lab | **Size**: M | **Tasks**: 6

## Phase 1: Lab

- [x] 1. In-process worker test helper (backend)
  Covers: AC-6, AC-7
  - [x] 1.1 Helper script run in a separate PHP process: boots Octane's `Worker` once (listeners from `config/octane.php`, `FakeClient`, `DEBUG_ROUTES=true`, `LARAVEL_OCTANE=1`) and prints JSON responses for a list of requests; a classic mode that boots a fresh process per request
  - [x] 1.2 **Verify**: a smoke test sends two requests to `/debug/runtime` through the helper and gets two 200 responses

- [x] 2. dealer-scope and request-at-boot (backend)
  Covers: AC-1, AC-4, AC-6, AC-7, MNC-2
  - [x] 2.1 Failing tests: leak mode leaks in the worker helper; fixed mode does not; classic boots never leak
  - [x] 2.2 Lab service provider, `CurrentDealer` (singleton vs scoped), request-capturing service, routes
  - [x] 2.3 **Verify**: tests green

- [x] 3. price-cache and number-locale (backend)
  Covers: AC-2, AC-3, AC-6, AC-7
  - [x] 3.1 Failing tests as in 2.1, plus `cache_entries` growth and the config/translator locales reported as reset
  - [x] 3.2 Static-cache formatter vs `Cache::remember`; `Number::useLocale` vs explicit locale
  - [x] 3.3 **Verify**: tests green

- [x] 4. `/debug/worker` (backend)
  Covers: AC-5, MNC-1
  - [x] 4.1 Failing tests: fields present; `worker_hits` increases across calls in the worker helper and is 1 on each classic boot; 404 when disabled
  - [x] 4.2 Controller and route
  - [x] 4.3 **Verify**: tests green; suite green

## Phase 2: Live and docs

- [x] 5. `bench/leaks.sh` and live runs (bench)
  Covers: AC-8, AC-9
  - [x] 5.1 Failing stub tests: output format, refusal when disabled, `.env` restored, max-requests overlay written and restored
  - [x] 5.2 Script; run on nginx-fpm, frankenphp-octane (500 and 1), frankenphp-worker
  - [x] 5.3 **Verify**: manual-test.md steps 1–4; project back on nginx-fpm, `DEBUG_ROUTES` off

- [x] 6. BENCHMARKS.md leak table and learning-guide section (docs)
  Covers: AC-9, AC-10, MNC-3
  - [x] 6.1 Leak table from the live runs; guide section with links
  - [x] 6.2 **Verify**: doc tests green; full suite, Pint, Larastan green

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
| MNC-2 | lab state not reachable from normal routes | `tests/Feature/LeakLabTest.php::MNC-2 lab service provider registers nothing when debug routes are off` | test | 2, 8 |
| MNC-3 | suite green | verify full Pest suite green | test | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project on nginx-fpm, `DEBUG_ROUTES` off

## Phase: Gaps (converge round 1)

- [x] 7. /debug/worker returns the AC-5 fields: app_id, first_seen_at, octane (backend)
  Covers: AC-5, AC-10
  - [x] 7.1 `DebugWorkerController` returns `worker_id` and `app_instance_id` and has no `first_seen_at` or `octane`. AC-5 requires `app_id` (`spl_object_id(app())`), `first_seen_at` (static, set on the first call) and `octane` (true when `LARAVEL_OCTANE` is set). The guide (section 7) already documents `app_id` and `octane`, which the code does not return. Rename `app_instance_id` to `app_id`, add `first_seen_at` and `octane`, and update `DebugWorkerTest` (written red first) and the guide's field list if `worker_id` stays
  - [x] 7.2 **Verify**: `ddev artisan test --compact tests/Feature/DebugWorkerTest.php` green and asserting `pid`, `app_id`, `memory_bytes`, `peak_memory_bytes`, `worker_hits`, `first_seen_at` and `octane`. In the worker helper, `first_seen_at` is the same on both calls and `octane` is true. In classic boots `first_seen_at` differs per boot. The guide's field list matches the response keys

- [x] 8. Guard: lab service provider registers nothing when DEBUG_ROUTES is off (backend)
  Covers: MNC-2
  - [x] 8.1 No test proves `LeakLabServiceProvider` binds nothing and resolves nothing at boot when `app.debug_routes` is not true. `ArchTest` only checks class usage. Add a test that boots with debug routes off and asserts `app()->bound('lab.dealer.leak')`, `lab.dealer.fixed`, `lab.boot-request.leak` and `lab.boot-request.fixed` are all false
  - [x] 8.2 **Verify**: the new test is green, and fails if the `debug_routes` guard in `register()` is removed

- [x] 9. request-at-boot fixed mode bound with scoped(); invalid amount is a 422 (backend)
  Covers: AC-4
  - [x] 9.1 The spec says fixed-mode services "use separate container keys bound with scoped()". `LeakLabServiceProvider::register()` binds `BOOT_REQUEST_FIXED` with `bind()`. Change it to `scoped()`. The validation test in `LeakLabTest` covers dealer, mode and locale but not `amount`, so also assert that `amount=abc` returns 422 in the error envelope
  - [x] 9.2 **Verify**: `ddev artisan test --compact tests/Feature/LeakLabTest.php` green (AC-4 fixed is still not leaked), including the new amount 422 assertion

## Phase: Gaps (converge round 2)

- [x] 10. Test the lab defaults, the fixed price-cache key and TTL, and that fixed number-locale never changes the global (backend)
  Covers: Preamble, AC-2, AC-3
  - [x] 10.1 Every lab request in `tests/Feature/LeakLabTest.php` passes `mode` explicitly and none asserts the default `amount`, so changing the default mode from `leak` to `fixed` (or the default amount from `7150300`) in `DebugLeakController` breaks no test. `CachedPriceFormatter` uses key `lab:price:{amount}:{locale}` with TTL 60, but no test checks the key or the TTL. AC-3 says that in fixed mode "the global is never changed", but no test checks `Number::defaultLocale()` after a fixed request. Add tests, red first where the code allows it: (a) a request without `mode` behaves as leak mode in the worker helper (dealer-scope B `leaked` true), and a request without `amount` formats 71 503 (`formatted` contains `71`); (b) a fixed price-cache request calls `Cache::remember` with `lab:price:7150300:de` and `60` (spy or `Cache::shouldReceive`); (c) in the worker helper, a fixed number-locale A (`locale=uk`) followed by a leak number-locale B without `locale` gives B `observed` `en` and `leaked` false
  - [x] 10.2 **Verify**: `ddev artisan test --compact tests/Feature/LeakLabTest.php` is green with the three new assertions. Temporarily changing the default mode to `fixed`, or adding `Number::useLocale($probe->locale)` to the fixed branch of `LeakProbeRunner::numberLocale()`, makes a test fail

- [x] 11. MNC-1 guard: /debug/leaks and /debug/worker return the same 404 as an unknown path (backend)
  Covers: MNC-1
  - [x] 11.1 MNC-1 requires `/debug/*` to return "the same 404 as an unknown path" while `DEBUG_ROUTES` is off. The sibling convention in `tests/Feature/DebugRuntimeTest.php` ("AC-7 MNC-3 is not found when disabled" and "...without an Accept header") compares status and body with `/debug/unregistered-path`. The new tests (`LeakLabTest` "lab routes answer 404 when debug routes are off" and `DebugWorkerTest` "MNC-1 worker endpoint is a 404 when debug routes are off") only call `assertNotFound()`. Add the same status and body comparison against an unknown path, with and without `Accept: application/json`, for `/debug/leaks/dealer-scope?dealer=1`, `/debug/leaks/dealer-scope` with no query (so validation cannot run first), and `/debug/worker`
  - [x] 11.2 **Verify**: `ddev artisan test --compact tests/Feature/LeakLabTest.php tests/Feature/DebugWorkerTest.php` is green, and moving `abort_unless(...)` below `$request->validate(...)` in `DebugLeakController` makes the no-query case fail (422 instead of the unknown-path 404)

- [x] 12. Tick task 2 in tasks.md after re-running its Verify (docs)
  Covers: AC-1, AC-4
  - [x] 12.1 Task 2 (dealer-scope and request-at-boot) and its subitems 2.1 to 2.3 are still `[ ]`, but the work has shipped: `CurrentDealer`, `BootCapturedRequest`, `LeakLabServiceProvider`, `DebugLeakController`, the route in `routes/debug.php`, and the green AC-1, AC-4, AC-6 and AC-7 tests in `tests/Feature/LeakLabTest.php`. Because of this, "Done when: All tasks checked" can never pass. Re-run task 2's Verify and tick 2, 2.1, 2.2 and 2.3
  - [x] 12.2 **Verify**: `ddev artisan test --compact tests/Feature/LeakLabTest.php` is green, and task 2 and its subitems are `[x]` in tasks.md
