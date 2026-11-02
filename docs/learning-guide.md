# AutoPulse learning guide

This guide explains how everything in this project works, in the order it was built. Each section says what the mechanism is, walks through what happens for one request, links to the code and specs in this repo, quotes what was measured, and ends with official docs to read next.

It is a living document: every slice adds or updates its section when it ships (rule BR-27 in [`product-brief.md`](../product-brief.md)). The plan and its order are in [`backlog.md`](../backlog.md).

**Status (2026-11-02):** sections 1–8 cover the shipped slices SL-01 to SL-08. Planned sections are listed at the end.

---

## 1. The app and one request on nginx-fpm

**What it is.** A small car marketplace on Laravel 13 and PHP 8.4: 500 dealers and 50 000 listings in PostgreSQL 16, with Redis for cache and sessions. It runs in DDEV, which starts a `web` container (nginx + PHP-FPM by default), a `db` container and a `redis` container behind a shared router.

**One request, step by step** (`GET /listings?make=BMW`):

1. The browser connects to `https://interview.ddev.site`. The DDEV router (Traefik) ends TLS and forwards plain HTTP to the `web` container.
2. nginx sees a path that is not a file and passes it over FastCGI to a free PHP-FPM child process.
3. That child boots Laravel from scratch: autoloader, service providers, config, routes. With OPcache, the compiled PHP is already in shared memory, so "boot" means running code, not parsing it.
4. The router matches `ListListingsController`. A form request validates the filters (422 on bad input). `ListingSearchService` asks `EloquentListingRepository` for one page, ordered newest first.
5. PostgreSQL answers using the indexes from the migrations. The controller wraps the result in the `ApiResponse` envelope.
6. The child sends the response back through nginx and then throws away everything it built. The next request boots Laravel again.

**In this repo.**
- Routes: [`routes/catalog.php`](../routes/catalog.php) and [`routes/api.php`](../routes/api.php).
- Request path: [`app/Http/Controllers/ListListingsController.php`](../app/Http/Controllers/ListListingsController.php), [`app/Services/ListingSearchService.php`](../app/Services/ListingSearchService.php) and [`app/Repositories/EloquentListingRepository.php`](../app/Repositories/EloquentListingRepository.php).
- Design notes: [`docs/architecture.md`](architecture.md); schemas: [`docs/schemas/`](schemas/).

**Read next.** [Laravel request lifecycle](https://laravel.com/docs/13.x/lifecycle) · [DDEV architecture](https://docs.ddev.com/en/stable/users/usage/architecture/) · [PHP OPcache](https://www.php.net/manual/en/book.opcache.php)

## 2. Switching servers and proving they answer the same

**What it is.** One command, `ddev server <name>`, swaps the web server without touching `.ddev/config.yaml`. It writes a gitignored overlay `.ddev/config.zz-server.yaml`. DDEV merges every `config.*.yaml` over `config.yaml` in name order, so `zz` wins. Then it restarts the project. A parity script then proves every server returns the same responses.

**How parity works.** `bench/parity.sh capture` sends 7 fixed requests and stores, per request, the status, the media type and a SHA-256 of the body. `compare` diffs two snapshots line by line. A few differences are expected and normalised:
- the `/up` page prints its own render time, which is masked;
- nginx adds `; charset=utf-8` to JSON, so only the media type is compared;
- OpenLiteSpeed answers on its own port, so its base URL is rewritten.

The `Server` header must match the active server, so a snapshot can never be filed under the wrong name.

**In this repo.**
- The switch command: [`.ddev/commands/host/server`](../.ddev/commands/host/server).
- The scripts: [`bench/parity.sh`](../bench/parity.sh) and [`bench/versions.sh`](../bench/versions.sh); snapshots in [`bench/parity/`](../bench/parity/).
- Versions and parity results: [`BENCHMARKS.md`](../BENCHMARKS.md).
- How to switch: the "Switching servers" section of the [README](../README.md).
- Spec: [`.ai/specs/spec-20261029-1013-apache-fpm-parity/`](../.ai/specs/spec-20261029-1013-apache-fpm-parity/).

**Read next.** [DDEV config overrides](https://docs.ddev.com/en/stable/users/extend/customization-extendibility/) · [DDEV custom commands](https://docs.ddev.com/en/stable/users/extend/custom-commands/)

## 3. Four ways to run PHP behind a web server

**What it is.** The servers differ less as HTTP servers than in *how PHP is executed*:

| Server | How PHP runs | Concurrency unit | PHP build |
|---|---|---|---|
| nginx-fpm | nginx talks FastCGI to a separate PHP-FPM pool | FPM child process | NTS |
| apache-fpm | Apache `mod_proxy_fcgi` talks to the same FPM pool; reads `public/.htaccess` on every request | FPM child process | NTS |
| openlitespeed | OpenLiteSpeed talks LSAPI to its own `lsphp` processes, in a separate container on port 8091 | lsphp process | NTS |
| frankenphp | PHP is embedded in one Go process (Caddy + FrankenPHP); each request runs on a thread | PHP thread | **ZTS** |

**Why FrankenPHP needs ZTS PHP.** With processes, each request has its own memory, so PHP's globals are safe. With threads, many requests share one process, so PHP must keep per-request state in thread-local storage. That is the "Zend Thread Safety" (ZTS) build, and every extension must be built for it, hence the `php-zts-*` packages. ZTS costs a little on every access to global state. That is one possible reason FrankenPHP classic measured slower on the database-bound search (section 6).

**How the extra servers are wired.** FrankenPHP and OpenLiteSpeed are not DDEV web server types. Their files live in committed "stashes" under [`.ddev/servers/`](../.ddev/servers/), and `ddev server` copies them into `.ddev/` on switch and removes them when you leave.
- **FrankenPHP:** the stash is the `ddev/ddev-frankenphp` add-on v1.3.0. Its Dockerfile switches the container's PHP to ZTS, which is why it must never stay active on the FPM servers.
- **OpenLiteSpeed:** runs as an extra container. Its OPcache settings were matched to the FPM pool, because lsphp's defaults (128 MB, 10 000 files) would overflow on this app's ~10 600 PHP files.

**In this repo.**
- Stashes: [`.ddev/servers/frankenphp/`](../.ddev/servers/frankenphp/) and [`.ddev/servers/openlitespeed/`](../.ddev/servers/openlitespeed/).
- The PHP settings table per server: [`BENCHMARKS.md`](../BENCHMARKS.md).
- Specs: [FrankenPHP](../.ai/specs/spec-20261029-1221-frankenphp-classic/) and [OpenLiteSpeed](../.ai/specs/spec-20261029-2027-openlitespeed/).

**Read next.** [PHP-FPM configuration](https://www.php.net/manual/en/install.fpm.configuration.php) · [nginx FastCGI module](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html) · [Apache mod_proxy_fcgi](https://httpd.apache.org/docs/2.4/mod/mod_proxy_fcgi.html) · [OpenLiteSpeed docs](https://docs.openlitespeed.org/) · [FrankenPHP docs](https://frankenphp.dev/docs/) · [FrankenPHP known issues](https://frankenphp.dev/docs/known-issues/) · [DDEV FrankenPHP add-on](https://github.com/ddev/ddev-frankenphp)

## 4. Worker mode: boot once, serve many

**What it is.** In worker mode, PHP threads boot Laravel once and then loop, waiting for requests. Bootstrap cost disappears from every request after the first. The price is that anything kept in memory survives into the next request.

**One request, step by step** (frankenphp-octane):

1. The router forwards to Octane's FrankenPHP process on port 8000.
2. Caddy serves existing files (such as `/build/*` assets) directly and hands everything else to an idle worker thread.
3. The worker already holds a booted application. Octane clones it into a per-request "sandbox", which includes a fresh copy of the config repository. It runs the request through the kernel, then resets request state: listeners such as `FlushLocaleState`, the `flush` list, and scoped instances.
4. After 500 requests (`--max-requests`), the worker script leaves its loop and FrankenPHP starts a fresh worker, which limits slow memory growth.

**Two ways to get there.**

| Who | frankenphp-octane | frankenphp-worker |
|---|---|---|
| Runs it | `php artisan octane:frankenphp` (a DDEV daemon) | the add-on's `frankenphp run` with an edited Caddyfile |
| Owns the config | Octane generates its Caddyfile from `vendor/laravel/octane` | `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp` and its [`CHANGES.md`](../.ddev/servers/frankenphp-worker/CHANGES.md) |
| HTTPS awareness | `OCTANE_HTTPS=true` makes Octane set `HTTPS=on` | a Caddy `map` turns `X-Forwarded-Proto` into `HTTPS=on` |
| Restart workers | `ddev artisan octane:reload --server=frankenphp` | `ddev restart`, or the admin API `POST /frankenphp/workers/restart` |

**Things that surprised us.**
- `octane:reload` does not work on frankenphp-worker: it looks for the state file that only Octane's own daemon writes.
- The `/up` page's "rendered in N ms" uses the `LARAVEL_START` constant, which is set once per worker. In worker mode it shows the worker's uptime, not the request time.
- A `.env` change is invisible until the workers restart, because the booted app keeps its config in memory.

**In this repo.**
- The detailed walkthrough: the "How worker mode works" section of the [README](../README.md).
- Stashes: [`.ddev/servers/frankenphp-octane/`](../.ddev/servers/frankenphp-octane/) and [`.ddev/servers/frankenphp-worker/`](../.ddev/servers/frankenphp-worker/); the worker script: [`public/frankenphp-worker.php`](../public/frankenphp-worker.php).
- Spec: [`.ai/specs/spec-20261030-1437-octane-worker-mode/`](../.ai/specs/spec-20261030-1437-octane-worker-mode/).

**Read next.** [Laravel Octane](https://laravel.com/docs/13.x/octane) · [FrankenPHP worker mode](https://frankenphp.dev/docs/worker/) · [FrankenPHP configuration](https://frankenphp.dev/docs/config/)

## 5. Measuring fairly

**What it is.** A benchmark is only worth something if you can defend it. The harness enforces the rules instead of trusting you to remember them:
- It refuses to run unless `APP_ENV=production`, `APP_DEBUG=false`, caches are built, OPcache is on, Xdebug is off and the git tree is clean (so every result has one commit).
- Each run is a 30 s warm-up, then a 60 s measurement, repeated 3 times. The median by RPS is reported.
- If the 3 runs differ by more than 5 % (spread = (max − min) / median), it reruns once, then marks the row `noisy`.
- The load generator (oha for ping, k6 for search) runs in its own container capped at 2 CPUs and goes through the router like a browser.
- "Fair" means 8 PHP workers everywhere with no recycling; "tuned" means the best of 8/16/32 per server.
- Only ratios against nginx-fpm are compared, never absolute numbers from a laptop.

**What the numbers mean.**
- **RPS** is completed requests per second.
- **p50/p95/p99** are the latencies that 50/95/99 % of requests stayed under. p95 and p99 show the slow tail an average hides.
- **Spread** shows whether a result is repeatable.
- **Where requests wait** when every worker is busy:
  - FPM: in the socket's listen backlog;
  - OpenLiteSpeed: in the LSAPI connection queue;
  - FrankenPHP: in its request queue.

  Latency rises before RPS falls.

**In this repo.**
- The scripts: [`bench/run.sh`](../bench/run.sh) (one scenario), [`bench/suite.sh`](../bench/suite.sh) (the whole matrix), [`bench/concurrency.sh`](../bench/concurrency.sh), [`bench/timeouts.sh`](../bench/timeouts.sh), [`bench/summary.php`](../bench/summary.php) and the k6 script [`bench/k6-search.js`](../bench/k6-search.js).
- oha has no public image, so [`bench/oha/Dockerfile`](../bench/oha/Dockerfile) builds it from a checksum-pinned release.
- Spec: [`.ai/specs/spec-20261030-1806-bench-harness/`](../.ai/specs/spec-20261030-1806-bench-harness/).

**Read next.** [k6 docs](https://grafana.com/docs/k6/latest/) · [oha](https://github.com/hatoo/oha) · [Brendan Gregg on latency percentiles and methodology](https://www.brendangregg.com/methodology.html)

## 6. What was measured (SL-06)

Full tables, the Expectations written before the runs and the Surprises are in [`BENCHMARKS.md`](../BENCHMARKS.md). The short version:

- **Swapping the web server alone buys almost nothing.** Tuned, `/api/ping` landed within 7 % across all four servers (nginx-fpm 6205 RPS, FrankenPHP classic 6254).
- **FrankenPHP classic was 14 % slower on the database-bound search** (537 vs 625 RPS), with the highest CPU of the four.
- **16 workers beat 8 on search for every server, and 32 never won.** At 8, workers were waiting on PostgreSQL while CPU sat idle.
- **Timeouts are not what they seem.** With every limit at 10 s, a 30 s `sleep()` was *not* stopped by PHP's `max_execution_time` under PHP-FPM or lsphp: on Linux, the NTS build counts CPU time, and sleeping uses none. The web server's own timeout ended the request (504 from nginx and Apache, 500 from OpenLiteSpeed). Only FrankenPHP's thread-safe PHP, which uses wall-clock timers, stopped it itself.
- **Noise is real.** The very first run of the suite was so noisy (15.7 % spread after a rerun) that the fair ping comparison is marked untrustworthy. That is the reason for the spread rule.

**Read next.** [PHP `max_execution_time`](https://www.php.net/manual/en/info.configuration.php#ini.max-execution-time) · [FPM `request_terminate_timeout`](https://www.php.net/manual/en/install.fpm.configuration.php) · [nginx `fastcgi_read_timeout`](https://nginx.org/en/docs/http/ngx_http_fastcgi_module.html#fastcgi_read_timeout) · [Apache `ProxyTimeout`](https://httpd.apache.org/docs/2.4/mod/mod_proxy.html#proxytimeout)

## 7. State leaks in worker mode

**What it is.** A classic server boots the app for every request and throws everything away afterwards. A worker boots once and serves many requests, so whatever outlives a request can be seen by the next one, possibly from another user. The lab in this repo makes each kind of leak visible and shows its fix.

**What lives across requests.**
- The booted (base) application and everything resolved while it booted, such as a `singleton()` resolved in a service provider's `boot()`.
- PHP statics (`static` properties and variables), for the whole worker process.
- Global state of libraries that Octane does not know about, such as Laravel's `Number::useLocale()`.

A `singleton()` first resolved *during* a request does not leak: Octane clones the application for every request and the clone dies with it.

**What Octane resets.** `CreateConfigurationSandbox` gives each request a clone of the config repository, so `config(['app.locale' => ...])` does not carry over. `FlushLocaleState` resets the translator and Carbon locales. `GiveNewRequestInstanceToApplication` (and its siblings) put the new request into the container and first-party services. `flush` bindings and scoped instances are dropped after each request. The list is in `config/octane.php` under `listeners`.

**The four leaks and their fixes** (`GET /debug/leaks/{scenario}?dealer=<id>&locale=<uk|de|en>&mode=leak|fixed`):

| Scenario | Leak | Fix |
|---|---|---|
| `dealer-scope` | A holder that remembers its first dealer, bound with `singleton()` and resolved at boot, keeps the first request's dealer for the worker's life. | Bind it with `scoped()` so every request gets a fresh holder. |
| `price-cache` | A `static` array keyed by amount only: a later request in another locale gets the first locale's entry, and the array grows forever. | `Cache::remember` keyed by amount and locale, with a TTL. |
| `number-locale` | `Number::useLocale()` changes a process-wide locale; a later request without a locale formats with the previous one. The response also reports `config_locale` and `app_locale`, which come back as `en`: Octane protects those, not `Number`. | Pass the locale explicitly to `Number::currency()` and never change the global. |
| `request-at-boot` | A service built with the `Request` in its constructor and resolved at boot holds the request that existed when the worker booted, which has no `dealer`. | Read `request()` inside the method, at call time. |

Each response has `requested`, `observed` and `leaked` (true when they differ). One caveat: a leak-mode `number-locale` request changes the `Number` locale for that worker until it restarts. No app code uses `Number`, but it is the lesson.

**Why `--max-requests` hides leaks without fixing them.** Octane's FrankenPHP daemon restarts a worker after N requests (500 here). At 1, no worker serves A and then B, so three of the four leaks read `0/20`, at the price of booting the app on every request. `request-at-boot` still leaks, because a fresh boot is exactly where it captures the wrong request. A low limit trims memory growth; it does not make code correct.

**How to read `/debug/worker`.** It returns `pid`, `worker_id`, `app_id`, `memory_bytes`, `peak_memory_bytes`, `worker_hits`, `first_seen_at` (when this process first served the endpoint) and `octane`. Call it a few times: on a worker server `pid` stays the same and `worker_hits` climbs (one counter per worker process, so with several workers you see several pids); on a classic server every call is a fresh boot and `worker_hits` is 1. `app_id` is informational, since PHP reuses object ids.

**In this repo.**
- Lab code: [`app/Debug/Leaks/`](../app/Debug/Leaks/) and [`routes/debug.php`](../routes/debug.php).
- Tests: [`tests/Feature/LeakLabTest.php`](../tests/Feature/LeakLabTest.php) runs Octane's real worker in a separate PHP process (leak mode leaks, fixed mode does not) and fresh boots per request (never leaks); [`tests/Feature/DebugWorkerTest.php`](../tests/Feature/DebugWorkerTest.php) covers `/debug/worker`.
- Live counts: [`bench/leaks.sh`](../bench/leaks.sh) and the [leak table](../BENCHMARKS.md#state-leaks).
- Spec: [`.ai/specs/spec-20261101-1822-octane-leak-lab/`](../.ai/specs/spec-20261101-1822-octane-leak-lab/).

**Read next.** [Laravel Octane: dependency injection and Octane](https://laravel.com/docs/13.x/octane#dependency-injection-and-octane) · [Laravel Octane](https://laravel.com/docs/13.x/octane) · [FrankenPHP worker mode](https://frankenphp.dev/docs/worker/)

## 8. Classic vs worker mode: what the numbers say

**What it is.** The same app on the same FrankenPHP binary, run two ways. Classic: every request boots Laravel, handles the request, and throws everything away. Worker: a long-running PHP script boots Laravel once, then loops, handing each incoming request to the booted app (Octane's daemon and the add-on worker both run Octane's loop: `vendor/laravel/octane/bin/frankenphp-worker.php`, which the add-on worker loads through [`public/frankenphp-worker.php`](../public/frankenphp-worker.php)). Full tables are in [`BENCHMARKS.md`](../BENCHMARKS.md#classic-vs-worker-2026-11-02).

**What a classic request pays for that a worker request does not.**
1. Loading and running the bootstrap: autoloading, the config and route caches, every service provider's `register()` and `boot()`.
2. **A new database connection.** PHP closes its PostgreSQL connection at the end of every classic request, and PostgreSQL starts a new backend process for each connection. Counted in `pg_stat_database.sessions`: 500 searches opened 501 sessions on classic FrankenPHP and 2 on Octane.
3. Booting whatever the route touches for the first time: Eloquent models, the view engine.

**Why search gained more than ping.** Measured as 1 − (worker p50 ÷ classic p50), worker mode removed about half of ping's latency and three quarters of search's. The prediction was the opposite (ping gains most, the database dominates search). Ping has no database work, so it pays only for item 1. Search pays for all three, and its query is small next to the connection setup. Lesson: "DB-bound" is a guess until you measure where the time goes; the connection can cost more than the query.

**How to read the memory curve.** `bench/memory.sh` sends 10 000 requests to one worker and reads `/debug/worker` every 100. Two traps, both found in this repo:
- **Garbage looks like a leak.** PHP frees most memory at once by reference counting, but objects that point at each other (cycles) wait for the cycle collector, which runs when its buffer fills (here roughly every 750 requests). Read raw, memory climbs ~5 KB per request and drops, a sawtooth bigger than the leak being measured. `/debug/worker?gc=1` calls `gc_collect_cycles()` first, so the reading is what the worker really keeps.
- **Warm-up looks like growth.** The first request to a route loads its classes once; that step is excluded from the slope.

After both fixes: the static-array leak grows a steady 0.5 KB per request (11.3 MB after 10 000), the Redis-cached version is flat, and classic mode is flat because nothing survives a request.

**`MAX_REQUESTS=0` does not mean unlimited.** Octane's worker loop is `while ($requestCount < $maxRequests && frankenphp_handle_request(...))`. With 0 the condition is false before the first request, so the worker exits at once and FrankenPHP keeps restarting it. "No recycling" in this repo is a large number (1 000 000). The default when the variable is unset is 1 000; the add-on worker here uses 500.

**When recycling is a fix and when it hides a leak.** With max-requests 500 the leaking worker restarted 20 times and peaked at 6.3 MB instead of 11.3 MB. That is a fix for memory you cannot control (a library that grows slowly, fragmentation). For your own leak it only hides the symptom: inside each stretch the slope is unchanged, and wrong answers caused by leaked state (section 7) still happen until the restart. Find the leak with the curve, fix it, and keep recycling as a safety net.

**Noise.** Most rows were marked noisy, worst on classic ping, which keeps almost every CPU busy and so feels any other app on the host. The same Octane setting measured twice gave 10 604 and 7 817 RPS. Trust a ratio only when it is clearly bigger than the spread.

**In this repo.**
- Scripts: [`bench/suite.sh`](../bench/suite.sh) (`--servers`), [`bench/memory.sh`](../bench/memory.sh), [`bench/concurrency.sh`](../bench/concurrency.sh) (worker count and max-requests for both worker servers), [`bench/summary.php`](../bench/summary.php) (tables).
- Endpoint: [`app/Http/Controllers/DebugWorkerController.php`](../app/Http/Controllers/DebugWorkerController.php) (`?gc=1`).
- Results: [`bench/results/2026-11-02-classic-vs-worker/`](../bench/results/2026-11-02-classic-vs-worker/), tables and surprises in [`BENCHMARKS.md`](../BENCHMARKS.md#classic-vs-worker-2026-11-02).
- Spec: [`.ai/specs/spec-20261101-2126-classic-vs-worker/`](../.ai/specs/spec-20261101-2126-classic-vs-worker/).

**Read next.** [Laravel Octane](https://laravel.com/docs/13.x/octane) · [FrankenPHP worker mode](https://frankenphp.dev/docs/worker/) · [PHP garbage collection: collecting cycles](https://www.php.net/manual/en/features.gc.collecting-cycles.php) · [`gc_collect_cycles()`](https://www.php.net/manual/en/function.gc-collect-cycles.php) · [PostgreSQL: `pg_stat_database`](https://www.postgresql.org/docs/16/monitoring-stats.html#MONITORING-PG-STAT-DATABASE-VIEW)

---

## Planned sections

Added as each slice ships (see [`backlog.md`](../backlog.md) for the order):

- **Server track:** slow I/O and thread autoscaling (SL-09), Early Hints and compression (SL-10), file downloads offloaded to the web server (SL-11), HTTP/3 (SL-12), real-time updates with Mercure and Reverb (SL-13, SL-14), metrics and logs (SL-15, SL-16), hot reload and Xdebug in workers (SL-17).
- **AI track:** how an LLM call works end to end, from model weights through the inference engine, model server and SDK to the app; streaming over SSE (SL-20); running models locally (SL-22); structured output (SL-23); embeddings and vector search (SL-24); tool calling (SL-25); evals (SL-26, SL-33); vision (SL-27); model comparison (SL-28); RAG with citations (SL-32); a hand-written agent loop and MCP (SL-34, SL-35); image embeddings for similar photos (SL-36…SL-39); semantic caching (SL-40); streaming chat UI (SL-41); a research agent (SL-42); a prompt playground (SL-43).
