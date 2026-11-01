---
id: spec-20261101-1822-octane-leak-lab
title: Octane state-leak lab with worker inspection
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov
approved_at: 2026-11-01T16:28:25Z
created: 2026-11-01
stacks: [laravel]
slice: SL-07
brs: [BR-30, BR-31, BR-32, BR-27]
---

# Octane state-leak lab with worker inspection

## Summary
Four debug routes each show one way state can leak between requests in a long-lived worker. Each has a `leak` mode that leaks under Octane and a `fixed` mode that never does. A `/debug/worker` route shows the worker persisting: process id, application instance ids, memory and a per-worker request counter. Pest tests prove each leak and fix by running requests through Octane's real worker and sandbox in-process. A live script fires two "users" at the running server and counts how often the second one sees the first one's data. The results and a "How it works" section go into BENCHMARKS.md and `docs/learning-guide.md`.

## Context
- **Problem**: SL-04 runs the app in worker mode (frankenphp-octane, frankenphp-worker), where the booted application lives across requests. Nothing yet shows what leaks, what Octane already resets, and how to fix the rest.
- **What Octane already resets** (vendor/laravel/octane 2.x): `CreateConfigurationSandbox` gives each request a clone of the config repository; `FlushLocaleState` resets the translator locale and Carbon's locale; `GiveNewRequestInstanceToApplication` and similar listeners replace request-bound instances in first-party services; `flush` bindings and scoped instances are dropped after each request. Static properties, singletons built from the request, and other libraries' global state (e.g. Laravel's `Number::useLocale()`) are not reset.
- **Business rules (product-brief.md, draft)**:
  - **BR-30** Each of the four leak routes has a leak mode and a fixed mode. Under Octane in leak mode, a request for dealer/locale B after one for A shows A's data; in fixed mode it never does.
  - **BR-31** On non-worker servers no leak route shows another request's data in either mode.
  - **BR-32** The worker-inspection endpoint returns process id, application instance id, memory usage and a per-worker request counter.
  - **BR-27** Every shipped slice includes a "How it works" note and adds its section to `docs/learning-guide.md`.

## Scope
### In scope
- `GET /debug/leaks/{scenario}?dealer=<id>&locale=<uk|de|en>&mode=leak|fixed` for scenarios `dealer-scope`, `price-cache`, `number-locale`, `request-at-boot`.
- `GET /debug/worker`.
- Pest tests that run requests through Octane's worker and sandbox in-process, and fresh-app tests for classic behaviour.
- `bench/leaks.sh`: live leak counts on the active server; a `--max-requests` option for frankenphp-octane.
- BENCHMARKS.md leak table; `docs/learning-guide.md` "State leaks in worker mode" section.
### Out of scope
- Memory growth over 10 000 requests and classic-vs-worker throughput (SL-08).
- Fixing anything outside the lab; the lab code is debug-only.
- `--watch`, hot reload (SL-17).

## Must NOT change
- **MNC-1**: Existing endpoints and parity snapshots are unchanged; `/debug/*` routes return the same 404 as an unknown path unless `DEBUG_ROUTES=true`.
- **MNC-2**: Lab bindings and statics live only in lab classes used only by lab routes, and the lab service provider registers nothing unless `DEBUG_ROUTES=true`. One documented exception: a leak-mode `number-locale` request changes Laravel's process-wide `Number` locale for that worker until it restarts. No current app code uses `Number`, and the guide explains this as part of the lesson.
- **MNC-3**: The Pest suite and CI stay green.

## Acceptance criteria

All routes below sit behind `DEBUG_ROUTES` like `/debug/runtime`. The lab needs **no database**: `dealer` is a positive integer id taken as-is (not looked up), `locale` one of `uk`, `de`, `en` (default `en` when absent), `mode` `leak` or `fixed` (default `leak`), `amount` an integer number of cents (default `7150300`). An unknown `{scenario}` is a 404 (route constraint); an invalid `dealer`, `locale`, `mode` or `amount` is a 422 in the `ApiResponse` error envelope, checked before any lab code runs. Every lab response is the `ApiResponse` envelope with `data.requested`, `data.observed` and `data.leaked` (`true` exactly when `observed` ≠ `requested`), defined per scenario below; extra fields are informational and never affect `leaked`.

**How a leak reaches the next request under Octane.** Octane clones the booted application for every request and throws the clone away afterwards, so a `singleton()` first resolved *during* a request dies with its clone and does not leak. State leaks only when it lives outside the clone: in the booted (base) application because it was resolved while the app booted, or in PHP statics, which live for the whole worker process. The lab's service provider therefore resolves the leak-mode services in its `boot()` method, so they belong to the base application, which is the pattern Octane's docs warn about. Fixed-mode services use separate container keys bound with `scoped()` and are never resolved at boot.

- **AC-1 (BR-30) dealer-scope**: A `CurrentDealer` holder that remembers the first dealer it is given (a common memoisation pattern). In `leak` mode it is a `singleton()` resolved at boot, so it lives in the base application and keeps the first request's dealer for the worker's life. In `fixed` mode a separate `scoped()` binding is used, so every request gets a fresh holder. `requested` = the request's `dealer`; `observed` = the dealer id the holder returns.
- **AC-2 (BR-30) price-cache**: A price formatter formats `amount` for the request's locale and caches `[locale, formatted]`. In `leak` mode the cache is a `static` array keyed by amount only, so a later request in another locale gets the first locale's entry, and the array grows for the worker's life. In `fixed` mode it uses `Cache::remember` keyed by amount and locale (TTL 60 s). `requested` = the request's locale; `observed` = the locale stored with the returned entry; `data.formatted` and `data.cache_entries` (the static array's size) are informational.
- **AC-3 (BR-30) number-locale**: In `leak` mode the request calls Laravel's global `Number::useLocale($locale)` only when `locale` is given; a later request without `locale` (so `requested` = `en`) formats with the previous request's locale. In `fixed` mode the locale is passed explicitly to `Number::currency()` on every call and the global is never changed. `observed` = the locale `Number` used (`Number::defaultLocale()` in leak mode, the explicit locale in fixed mode). In leak mode the same requests also set `config(['app.locale' => $locale])` and `App::setLocale($locale)`; the response reports `config_locale` and `app_locale`, which under Octane come back as `en` on the next request because Octane resets them (`CreateConfigurationSandbox`, `FlushLocaleState`).
- **AC-4 (BR-30) request-at-boot**: A service that receives the `Illuminate\Http\Request` in its constructor, bound with `singleton()` and resolved at boot. In `leak` mode it reads `dealer` from that captured request, which under Octane is the request object that existed when the worker booted (it carries no `dealer`), so every later request sees a stale request instead of its own: `observed` = `null` and `leaked` = `true`. In `fixed` mode it reads `request()` inside the method at call time. `requested` = the request's `dealer`; `observed` = the `dealer` the service read. Note: here the leak shows the boot-time request rather than A's data; BR-30's wording is read as "a request other than B's own".
- **AC-5 (BR-32)**: `GET /debug/worker` SHALL return `pid` (`getmypid()`), `app_id` (`spl_object_id(app())`; informational, PHP may reuse ids of freed objects, so no test relies on it changing), `memory_bytes`, `peak_memory_bytes`, `worker_hits` (a static counter of calls to this endpoint in this worker process; it is the BR-32 "request counter", counted on this endpoint only so no middleware touches normal routes), `first_seen_at` (a static set on this worker's first call to the endpoint), `octane` (`true` when the `LARAVEL_OCTANE` environment variable is set).
- **AC-6 (BR-30)**: Pest tests SHALL run a helper script in a **separate PHP process** (so lab statics never pollute the test process or other tests). The script boots Octane's real `Laravel\Octane\Worker` once, with the listeners from `config/octane.php`, Octane's `FakeClient`, and the environment `DEBUG_ROUTES=true`, `LARAVEL_OCTANE=1`. It sends request A (dealer 1, locale `uk`) then request B (dealer 2, locale `de`; no locale for `number-locale`) and prints both responses as JSON. Tests assert that in `leak` mode B's `leaked` is `true` (and for dealer-scope, price-cache and number-locale `observed` equals A's value), and in `fixed` mode B's `leaked` is `false`. One test per scenario and mode.
- **AC-7 (BR-31)**: Pest tests SHALL send A and B each in its **own PHP process** with its own application boot (exactly what a classic server does) and assert `leaked` is `false` for every scenario in both modes. The live nginx-fpm column of AC-9 confirms it on a real classic server.
- **AC-8**: `bench/leaks.sh` SHALL, on the active server, set `DEBUG_ROUTES=true` in `.env` (backup and restore on exit, like the bench harness) and restart the workers. Then, per scenario and mode, it restarts the workers so each row starts clean, sends A 20 times so every worker in the pool has served A, sends B 20 times, and prints `leaked n/20` (n = B responses with `leaked: true`). `--max-requests <n>` on frankenphp-octane restarts the daemon with that value through a gitignored overlay and restores the stash's value (500) on exit.
- **AC-9 (BR-30, BR-31)**: BENCHMARKS.md SHALL have a leak table from `bench/leaks.sh`: rows per scenario × mode, columns nginx-fpm, frankenphp-octane (max-requests 500), frankenphp-octane (max-requests 1), frankenphp-worker. Expected: every `fixed` row and every nginx-fpm cell `0/20`; `leak` rows at least `1/20` (a leak) on both worker servers at max-requests 500; max-requests 1 expected `0/20`. Whatever is measured is recorded as-is, and any cell that differs from the expectation is explained under the table. frankenphp-worker runs Octane's own worker script (`public/frankenphp-worker.php`), so it has the same sandbox and listeners as frankenphp-octane.
- **AC-10 (BR-27)**: `docs/learning-guide.md` SHALL contain a "State leaks in worker mode" section that covers:
  - what lives across requests in a worker;
  - what Octane resets, naming the listeners;
  - each of the four leaks and its fix;
  - why `--max-requests` hides leaks without fixing them;
  - how to read `/debug/worker`;
  - links to the lab code, the tests, the leak table and the Octane docs.

## Boundaries
- **Always**: test first per scenario (leak test red before the leaky code exists, then green); keep lab classes under `app/Debug/Leaks/` (or similar) used only by lab routes; leave the project on nginx-fpm with `DEBUG_ROUTES` off.
- **Ask first**: changing `config/octane.php` listeners or `flush`/`warm` lists; touching non-lab code.
- **Never**: make a leak reachable from a normal route; commit `.env` changes or overlays.

## Technical context
- **Related code**: `routes/debug.php`, `DebugRuntimeController`/`DebugSleepController` (guard pattern), `config/octane.php`, `vendor/laravel/octane/src/Worker.php`, `vendor/laravel/octane/src/Testing/Fakes/FakeClient.php` and `FakeWorker.php`, `vendor/laravel/octane/src/Listeners/*`, `bench/run.sh` (`.env` switch and restore pattern), `.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml` (daemon `--max-requests=500`), `bench/concurrency.sh` (gitignored overlay pattern).
- **Data**: SL-01 seed; dealer 1 and 2 and listing 1 exist.
- **Approach**: lab services and bindings registered by a dedicated service provider; one invokable controller per scenario or one controller with a scenario switch; the in-process worker test helper wraps Octane's `Worker` with its `FakeClient`.
- **Repos**: single repo `/Users/stasmarkov/work/interview`, dev URL https://interview.ddev.site.

## Decisions
1. Identity and mode by query parameters, both modes side by side (user choice, 2026-11-01).
2. Tests run requests through Octane's real worker in-process, plus fresh-app classic tests (user choice).
3. Live checker `bench/leaks.sh`, including max-requests 1 vs 500 on Octane (user choice).
4. The roadmap's "config(['app.locale']) leak" is replaced by `Number::useLocale()`, because Octane already sandboxes the config repository and resets the translator and Carbon locales (`CreateConfigurationSandbox`, `FlushLocaleState`); the scenario reports both, to show what Octane protects and what it does not.
5. All `/debug/*` routes are registered for every method behind one `EnsureDebugRoutes` middleware: while `DEBUG_ROUTES` is off every method gets the same 404 as an unknown path (a GET-only route answered 405 to other methods and revealed itself); when on, only GET and HEAD are allowed (review fix, 2026-11-01).
6. `bench/leaks.sh` restarts the Octane daemon (`ddev restart`) after switching `.env`, because `octane:frankenphp` copies `.env` into the FrankenPHP process environment at start and a reload keeps the old values; it parses each response on its own, flags failed A phases, requires `jq`, and checks `/debug/worker` is back to 404 after restoring (review fix).
7. A lab request answers 503 with a restart hint when `DEBUG_ROUTES` was enabled after the worker booted, since the lab bindings are registered at boot (review fix). `phpunit.xml` pins `DEBUG_ROUTES=false`. `/debug/worker` reports exact memory (`memory_get_usage()`), not the 2 MiB-rounded real usage (review fix).

## Open questions
- [x] Assumption: BR-27, BR-30, BR-31 and BR-32 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-11-01).
- [x] Clarity-gate answers folded in above (leaks via boot-time resolution and statics, separate fixed bindings, DB-free lab, out-of-process tests, per-scenario requested/observed, `worker_hits` counted on its own endpoint, request-at-boot shows a stale boot request, `.env` switched by the script, workers restarted per row, validation, Number caveat). — **Accepted** (Stanislav Markov, 2026-11-01).

## Dependencies
- **Requires**: SL-04, SL-06 (shipped).
- **Blocks**: SL-08.

---
Next: `/sdd spec-20261101-1822-octane-leak-lab` (approval → build)
