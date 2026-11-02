# AutoPulse learning guide

This guide explains how everything in this project works, in the order it was built. Each section says what the mechanism is, walks through what happens for one request, links to the code and specs in this repo, quotes what was measured, and ends with official docs to read next.

It is a living document: every slice adds or updates its section when it ships (rule BR-27 in [`product-brief.md`](../product-brief.md)). The plan and its order are in [`backlog.md`](../backlog.md).

**Status (2026-11-03):** sections 1–12 cover the shipped slices SL-01 to SL-08, SL-20, SL-22, SL-24 and SL-32. Planned sections are listed at the end.

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

## 9. Streaming an AI answer

**What it is.** `GET /listings/{id}/describe` returns a short description of one car and sends it while it is generated, as server-sent events (SSE): a normal HTTP response that stays open, with `Content-Type: text/event-stream`, where the server writes lines of the form `data: <payload>` followed by a blank line. The browser or `curl -N` sees each line as soon as it is written. Until a local model is chosen, the text comes from a fake provider that sends one word every 50 ms for 10 seconds, so the stream is visible, testable and load-testable without a model.

**One call, end to end.**
1. **Route and controller.** [`DescribeListingController`](../app/Http/Controllers/DescribeListingController.php) calls `ListingDescriptionService`, which loads the listing (404 as JSON if missing) and builds the prompt from its attributes.
2. **Agent.** `CarDescriptionAgent` takes its instructions from a versioned prompt file under `resources/prompts/` (`car-description.v1.md` is recorded as `car-description@v1`).
3. **Provider and gateway.** `ai.default` and the model come from `.env` (`AI_PROVIDER`, `AI_MODEL`), so switching models needs no code change. The `fake-stream` driver is registered on the SDK's manager by `AiServiceProvider`; its gateway yields the SDK's events (stream start, one text delta per word, stream end with token usage). `AI_FAKE_TOKENS` (200) and `AI_FAKE_TOKEN_DELAY_MS` (50) set the length and pace.
4. **Events to SSE lines.** `SseProtocol` turns every event into one `data: {json}` line (`"type":"text_delta"` for text) and ends with `data: [DONE]`. If the run fails midway, the client gets an `error` event, then `data: [DONE]`.
5. **Server, router, client.** The response leaves PHP, passes the web server, then the DDEV router, then reaches the client. Any of those can hold bytes back until a buffer fills, which turns a 10-second stream into one late burst.

**How `ai_runs` is filled.** `RecordAiRun` listens to the SDK's lifecycle events and `AiRunRecorder` writes one row per run: provider, model, prompt version, tokens in and out, time to first token, total latency and status (`completed` or `failed`). A live run on nginx-fpm wrote provider `fake-stream`, model `fake-slow-1`, prompt `car-description@v1`, 53 tokens in (instructions plus prompt), 200 out, first token after 1 ms, 10 399 ms in total. Time to first token is measured inside PHP, from the start of the run to the first text delta; `bench/sse.sh` measures it from the client and also counts TLS, the router and the server (126 ms in the same check).

**What each server needed.** `bench/sse.sh` streams the endpoint with `curl --no-buffer`, timestamps every line, and passes when the first text event comes within 1 s, the last at 9.5 s or later, there are exactly 200 text events and at least 150 distinct arrival times (the last run per server):

| Server | first_text_ms | last_text_ms | text_events | distinct_ms | Needed |
|---|---|---|---|---|---|
| nginx-fpm | 84 | 10771 | 200 | 200 | nothing; `X-Accel-Buffering: no` from the app turns FastCGI buffering off |
| apache-fpm | 228 | 10699 | 200 | 200 | `ProxySet flushpackets=on` on the PHP-FPM proxy worker in `.ddev/apache/apache-site.conf` |
| openlitespeed | 409 | 11013 | 200 | 200 | nothing |
| frankenphp | 255 | 10929 | 200 | 200 | nothing |
| frankenphp-octane | 119 | 10676 | 200 | 199 | `SseProtocol::response()` drains the generator and flushes per chunk |
| frankenphp-worker | 166 | 10811 | 200 | 200 | same fix as Octane |

- **Apache** delivered about 13 chunks, the first at roughly 1.3 s: `mod_proxy_fcgi` waits for a buffer to fill. `proxy-flushall` had no effect; `flushpackets=on` on the proxy worker did.
- **Octane and the FrankenPHP worker** returned an empty body. Laravel's `response()->stream()` hands the server client an un-iterated generator callback when `LARAVEL_OCTANE` is set, and Octane's FrankenPHP client calls it without draining it.
- The first nginx-fpm run after a server switch showed `first_text_ms=1071` (fail); two reruns gave 216 and 84. Treat the first request after a switch as a cold start.

**What the fake provider hides.** A real model streams *tokens* from its tokenizer, which are word pieces, not words: "Defender" may arrive as two or three pieces, and spaces and punctuation are part of the pieces. Real latency is not a flat 50 ms: there is a wait before the first token (queueing, reading the prompt), a pace that varies with load, and token counts that come from the provider rather than a word count. Here the first token arrives at once and the pace is perfectly even, so a buffering bug is easy to see, but timing-based tuning must be redone with a real model (SL-22).

**In this repo.**
- Code: [`app/Ai/`](../app/Ai/), [`DescribeListingController`](../app/Http/Controllers/DescribeListingController.php), [`config/ai.php`](../config/ai.php).
- Measuring: [`bench/sse.sh`](../bench/sse.sh) (`--analyse <file>` checks a saved log).
- Tests: [`ListingDescribeTest`](../tests/Feature/ListingDescribeTest.php), [`AiRunLogTest`](../tests/Feature/AiRunLogTest.php), [`FakeStreamProviderTest`](../tests/Feature/FakeStreamProviderTest.php), [`BenchSseTest`](../tests/Feature/BenchSseTest.php).
- Spec: [`.ai/specs/spec-20261102-1552-streamed-car-description/`](../.ai/specs/spec-20261102-1552-streamed-car-description/).

**Read next.** [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk) · [MDN: Using server-sent events](https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events) · [Apache `ProxySet` (`flushpackets`)](https://httpd.apache.org/docs/2.4/mod/mod_proxy.html#proxyset) · [nginx `X-Accel-Buffering`](https://nginx.org/en/docs/http/ngx_http_proxy_module.html#proxy_buffering)

---

## 10. Running the model locally

**What it is.** The same endpoint, `GET /listings/{id}/describe`, now streams text from a real model: `qwen3.5:9b` served by Ollama on the Mac. Nothing in the controller, the SSE protocol or `ai_runs` changed. Switching is configuration: `AI_PROVIDER=ollama` in `.env` (default `fake-stream`) selects the `ollama` entry in [`config/ai.php`](../config/ai.php), and `AI_PROVIDER=fake-stream` brings back the fake provider that the test suite and `bench/sse.sh` without `--real` rely on.

**One call, end to end.**
1. **Provider entry.** The app's `providers` array replaces the package's, so the `ollama` entry has to exist in `config/ai.php`: URL from `OLLAMA_URL` (default `http://host.docker.internal:11434`), model from `OLLAMA_MODEL` (`qwen3.5:9b`), `max_tokens` (`AI_MAX_TOKENS`, 400) and `timeout` (`AI_TIMEOUT`, 120 s).
2. **Request options.** [`CarDescriptionAgent`](../app/Ai/CarDescriptionAgent.php) adds `think`, `keep_alive` and `options.num_ctx` to the body of `POST /api/chat`, and the SDK sends `num_predict` from `max_tokens`. `think: false` matters: a thinking model spends its token budget on reasoning that the client never sees.
3. **Reaching the host.** Ollama runs on macOS, the PHP code in a container. `host.docker.internal` has to resolve inside the web container on every server, so the project adds `extra_hosts: host.docker.internal:host-gateway` in `.ddev/docker-compose.host-gateway.yaml`. OpenLiteSpeed runs in its own container and needed the same line in its overlay.
4. **Stream to SSE.** Ollama answers with newline-delimited JSON, one object per chunk, the last one with `done: true` and the real `prompt_eval_count` and `eval_count`. The SDK turns them into the same events as the fake provider, so `SseProtocol` and `RecordAiRun` are unchanged and `ai_runs` now holds real token counts.

**Cold versus warm.** Ollama loads the weights on the first request and unloads them after `keep_alive` (10 minutes here). Two requests with `curl` straight to Ollama, the first after `ollama stop`, read from its `load_duration`, `prompt_eval_duration` and `eval_duration` fields:

| Request | Total (ms) | load_duration (ms) | Prompt eval (ms) | Eval (ms) |
|---|---|---|---|---|
| Cold | 6987 | 4927 | 333 | 1720 |
| Warm | 1729 | 3 | 27 | 1698 |

Loading cost five seconds once. The generation itself took about the same in both. Warm the model before measuring anything else.

**What goes into the first token.** On a warm model, time to first token is prompt processing plus the first decode step: the 111 prompt tokens took about 27 ms to evaluate above, and `ai_runs.ttft_ms` of 89 to 145 ms adds PHP, the HTTP call to Ollama and the first chunk. On a cold model the load is added in front (about 5 s above), which is why the model is warmed before measuring.

**What the model is.** `qwen3.5:9b` has 9.7 billion parameters stored as `nvfp4`, a 4-bit floating-point format: each weight takes about half a byte, so the file is a few GB instead of about 19 GB at 16 bits, at a small cost in quality. Ollama 0.40 runs it on its MLX runner, Apple's array framework for Apple Silicon, which keeps the weights in unified memory and uses the GPU. ADR 0001 first assumed the Q4_K_M build on the llama.cpp engine; the pulled model differs, so its sizes were not re-measured.

**Context and the KV cache.** While generating, the model keeps the keys and values of every token seen so far, the KV cache, so each new token only attends to them instead of recomputing the prompt. Its size grows with the context window: `num_ctx` (`OLLAMA_NUM_CTX`, 8192) reserves room for it, and prompt plus output must fit. A loaded model keeps its runner and memory until `keep_alive` expires, which is why the warm request had `load_duration` of 3 ms. The model supports 262144 tokens, but a bigger `num_ctx` costs memory for no gain on a 111-token prompt.

**Tokens versus words.** The fake provider emits 200 "tokens" that are words, so `AI_FAKE_TOKENS=200` always means 200 events. A real tokenizer splits text into sub-word pieces: this prompt is 111 tokens, and a description of 79 to 126 output tokens arrived as nearly as many text events. Token counts, not word counts, are what `ai_runs.tokens_in` and `tokens_out` now hold, and what limits `num_predict`.

**What each server showed.** `bench/sse.sh --real` streams the endpoint with the same prompt and checks the stream instead of fixed counts. Times are from the client; `ttft` and latency are `ai_runs.ttft_ms` and `latency_ms`, measured inside PHP. Tokens/s is tokens out divided by ((latency - ttft) / 1000):

| Server | Tokens in | Tokens out | First text (ms) | Last text (ms) | Text events | ttft (ms) | Latency (ms) | tokens/s |
|---|---|---|---|---|---|---|---|---|
| nginx-fpm | 111 | 79 | 450 | 1762 | 76 | 140 | 1459 | 59.9 |
| apache-fpm | 111 | 101 | 330 | 1857 | 99 | 124 | 1653 | 66.1 |
| openlitespeed | 111 | 92 | 514 | 2383 | 90 | 145 | 2035 | 48.7 |
| frankenphp | 111 | 126 | 635 | 2899 | 123 | 89 | 2361 | 55.5 |
| frankenphp-octane | 111 | 111 | 225 | 2079 | 108 | 118 | 1974 | 59.8 |
| frankenphp-worker | 111 | 106 | 240 | 2121 | 104 | 107 | 2003 | 55.9 |

- All six servers delivered the text as it was generated: first text between 225 and 635 ms, spread over one to three seconds. The model writes a different text each time, so token counts differ per row; the speeds of 49 to 66 tokens/s are within the run-to-run noise of one model on one laptop. This table shows the pipeline is not buffering, not which server is faster.
- **ttft on the Octane servers.** An earlier run showed an empty `ttft_ms` on `frankenphp-octane` and `frankenphp-worker`. Under Octane the event dispatcher keeps the container it was booted with, so the listener and the SSE protocol used different scoped `AiRunRecorder` instances; both now resolve the recorder from the current container, and the rows above have a value.
- The number of text events is close to the number of output tokens, since each token is one SSE line.
- **Arrival times are clumpier than the fake provider's, and the model server causes it.** `ollama show qwen3.5:9b` lists `draft_num_predict 3`: Ollama uses speculative decoding, where a small draft step proposes up to 3 tokens and the model accepts several at once, so they leave Ollama together. Called directly (no PHP, no web server), 112 chunks had only 55 % new millisecond timestamps, with up to 3 chunks in the same millisecond. `--real` therefore counts `new_ms` (text events whose timestamp differs from the previous one) and requires 40 %, not the 80 % first tried (the fake stream needs 150 distinct of 200). Speculative decoding also explains why generation here (49 to 66 tokens/s) is faster than the 39.8 tokens/s of the first smoke test with a 3-token answer.
- **Thinking.** With `AI_THINK=true` the model reasons before it answers, and the reasoning counts against `num_predict`. At `AI_MAX_TOKENS=400` the run on nginx-fpm used all 400 tokens on reasoning, took 5904 ms and produced no visible text. With `AI_THINK=true AI_MAX_TOKENS=1200` the run still used all 1200 tokens (109 in), took 19216 ms (about 62 tokens/s over the whole latency) and showed no text, so `sse.sh --real` failed again and `ttft_ms` stayed empty, because it is set on the first visible text. For this prompt, thinking costs a multiple of the time of the non-thinking run (1459 ms) and needs a budget well above 1200 tokens; it was not worth raising further here.

**Network setting.** All the numbers above were taken with Ollama listening on every interface (`lsof` showed `*:11434`), because the macOS app's "Expose Ollama to the network" switch was on. The container does not need that. Tested on 2026-11-02: a throwaway HTTP server bound only to `127.0.0.1` on the Mac answered a container started with `--add-host=host.docker.internal:host-gateway`, because OrbStack relays the host's loopback. Recommendation: turn "Expose Ollama to the network" off, so that only this machine can use the model, and re-run `ddev exec curl -s http://host.docker.internal:11434/api/version` to confirm.

**In this repo.**
- Decision and later notes: [`ADR 0001`](../docs/adr/0001-local-model-server-models-php-client.md).
- Code: [`CarDescriptionAgent.php`](../app/Ai/CarDescriptionAgent.php), [`app/Ai/`](../app/Ai/), [`config/ai.php`](../config/ai.php); the AI keys are listed in `.env.example`.
- Measuring: [`bench/sse.sh`](../bench/sse.sh) with `--real`.
- Tests: [`OllamaProviderTest`](../tests/Feature/OllamaProviderTest.php), [`DdevHostGatewayTest`](../tests/Feature/DdevHostGatewayTest.php), [`BenchSseTest`](../tests/Feature/BenchSseTest.php); none needs a network or a running Ollama.
- Spec: [`.ai/specs/spec-20261102-1812-local-model-wiring/`](../.ai/specs/spec-20261102-1812-local-model-wiring/).

**Read next.** [Ollama docs](https://docs.ollama.com/) · [Ollama usage fields](https://docs.ollama.com/api/usage) · [Ollama thinking](https://docs.ollama.com/capabilities/thinking) · [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk)

---

## 11. Embeddings and vector search

**What it is.** An embedding turns a text into a list of numbers, a vector, so that texts with similar meaning end up close together. Here every listing has a generated description of 28 to 39 words; `bge-m3`, served by Ollama, turns each description into 1024 numbers, and `GET /api/listings/semantic?q=family car with a big boot` embeds the query the same way and returns the 10 listings whose vectors are nearest. No keyword has to match: the nearest ten for that query were 10 estates out of 10.

**The pipeline, step by step.**
1. **Descriptions.** `listings:describe-all` writes a deterministic description per listing into `listing_documents`. It is a template over the listing's own fields, so the same listing always gets the same text and a second run changes 0 rows. It is a separate table and a separate command on purpose: the generator must not touch the seed, because the `listings` table has to stay byte-identical (its `md5` over all rows was `6a0f96d2c7139919cc215a520072d3f7` before and after) and the parity hashes of the other endpoints depend on it.
2. **Embedding.** `listings:embed` sends the descriptions in batches (default 256) to the model as queued jobs on the `embeddings` queue. A vector of the wrong length fails the whole batch, stores nothing, and the call is logged `failed`. A call that errors is retried after 5 s and then 30 s: Ollama's tokenizer crashed twice in the full run, and both retries succeeded. A row is embedded again only if its vector is missing, the model name differs, or the sha256 of its description changed, which is why a second run embeds only what changed.
3. **Storing.** The vector goes into a pgvector column with an HNSW index using cosine distance.
4. **Searching.** The query is embedded with the same provider and model, and the database returns the nearest ten, searching the HNSW graph with `ef_search` 400 (`AI_EF_SEARCH`). Query embeddings are logged in `ai_runs` as agent `embeddings:query`, batches as `embeddings`.

**Why cosine, and why normalised vectors.** Cosine similarity compares the direction of two vectors and ignores their length, which is what "same meaning" needs: a longer text must not look further away just because of its size. `bge-m3` returns vectors normalised to length 1. For unit vectors, cosine distance and Euclidean distance rank neighbours the same way and the dot product equals the cosine, so any of the three would find the same ten listings; cosine is the one that stays correct if a model ever returns unnormalised vectors. The endpoint shows `similarity` (1 minus the distance, 4 decimals).

**What HNSW trades.** Finding the exact nearest neighbours means comparing the query with all 50 000 vectors. HNSW (hierarchical navigable small world) builds a layered graph of neighbours and walks it, looking at a few hundred vectors instead. The price:
- **Exactness.** The result is approximate. `ef_search` (40 by default in pgvector) is how many candidates the walk keeps: higher finds more of the true neighbours and costs time. Measured with the index forced, recall@10 against an exact scan was 0.405 at 40, 0.485 at 100, 0.605 at 200 and 0.795 at 400, for 3.6, 3.2, 6.8 and 10.7 ms; the exact scan takes about 260 ms. The app uses 400.
- **Why recall is low here.** The descriptions are template text, so every vector is close to every other: for "family car with a big boot" the 30 nearest of 50 000 listings all lie within a cosine distance of 0.008. A graph walk in such a crowd easily misses neighbours that are only a hair closer, and more candidates win them back. The "missed" listings are near-identical cars, so the answers still look right (10 estates at every setting).
- **Build time.** Building the index over all 50 000 vectors took 39.1 s (42.2 s in an earlier run).
- **Memory and disk.** Built at once, the index is 391 MB; grown by 50 000 inserts during the embedding run it was 455 MB. The table itself is 34 MB. Either index is bigger than `shared_buffers` (128 MB), so the first queries read from the OS cache.
- **Insert cost.** Every insert updates the graph, which is part of why the index built at once is smaller.

**What was measured.** Details and versions are in [`BENCHMARKS.md`](../BENCHMARKS.md#embeddings-and-vector-search-2026-11-02).

| Measure | Value |
|---|---|
| Full run, 50 000 listings, batch 256 | 1 020.8 s, 48.98 embeddings/s, 196 jobs, 198 model calls (2 retried), 0 failed batches |
| Batch 16 / 64 / 256 (2 000 listings) | 41.57 / 48.31 / 49.96 embeddings/s; 256 won every pair, so it is the default |
| Search, embedding the query | p50 21.1 ms, p95 44.2 ms |
| Search, database (HNSW, `ef_search` 100) | p50 6.2 ms, p95 13.6 ms |
| recall@10 at `ef_search` 40 / 100 / 200 / 400 | 0.405 / 0.485 / 0.605 / 0.795 |
| HNSW build, index size | 39.1 s, 391 MB |

Throughput counts from the first model call of a run, not from dispatch: worker start-up and clearing old vectors are not embedding time. Embedding the query costs more than the database lookup even at `ef_search` 400, so at this size the model call is most of the latency of a semantic search (about 60 ms over HTTPS end to end).

**A query that quietly skipped the index.** The first version of the endpoint ordered by distance and then by listing id (a tie-break) and joined `listings`. PostgreSQL cannot serve a two-key sort from an HNSW index, so it scanned all vectors: database p50 296.5 ms. Ordering by distance alone in an inner query with the limit, and joining and tie-breaking outside, brought it to 2.9 ms. The answers were identical; only the speed told the two apart, which is why the benchmark keeps the slow run. The same mistake hid in the benchmark itself: its approximate query had the two-key sort, so it ran an exact scan and the first recall of 1.0 compared an exact scan with itself. It now uses the index, and an `EXPLAIN` test guards that.

**The planner can also choose to skip the index on its own.** pgvector's cost estimate for an HNSW search grows with `ef_search`. On this table, at 200 and above PostgreSQL decided that reading all 50 000 rows and sorting them was cheaper, ran a sequential scan (about 260 ms), and the benchmark reported recall 1.0. `EXPLAIN` showed `Seq Scan on listing_documents`. The estimate is wrong here (the index search at 400 takes about 11 ms), so both the endpoint and the benchmark set `enable_seqscan = off` inside the transaction of their HNSW query, and tests check the plan. Lesson: a perfect recall or a sudden 100x slowdown is a reason to read the plan.

**What the tests prove, and what the template text limits.** The AC-7 test gives listings arranged fake vectors and checks that the nearest come first; it proves the ordering and the SQL, not that the model understands "big boot". That part is only shown by the live run. And the descriptions are generated from a handful of templates and feature lists, so the model can only find meaning that the templates put there: "big boot" works because estates get "large boot" in their feature pool, while a query about something no template mentions (rust, a towbar, a nervous dog) has nothing to match. Real descriptions written by people would carry far more meaning, and far more noise.

**In this repo.**
- Decision: [`ADR 0001`](../docs/adr/0001-local-model-server-models-php-client.md).
- Code: [`app/Services/`](../app/Services/) (`ListingDocumentService`, `ListingEmbeddingService`, `SemanticSearchService`), `listings:describe-all`, `listings:embed` and `listings:search-bench` in `app/Console/Commands/`, the config in [`config/ai.php`](../config/ai.php).
- Results: [`bench/results/2026-11-02-embeddings/`](../bench/results/2026-11-02-embeddings/), queries in [`bench/semantic-queries.txt`](../bench/semantic-queries.txt).
- Tests: `ListingEmbedCommandTest`, `SemanticSearchTest`, `EmbeddingRunLogTest`; none needs a network or a running Ollama.
- Spec: [`.ai/specs/spec-20261102-2115-listing-embeddings/`](../.ai/specs/spec-20261102-2115-listing-embeddings/).

**Read next.** [pgvector](https://github.com/pgvector/pgvector) · [bge-m3 model card](https://huggingface.co/BAAI/bge-m3) · [Ollama embeddings](https://docs.ollama.com/capabilities/embeddings) · [Laravel AI SDK](https://laravel.com/docs/13.x/ai-sdk)

---

## 12. Chunking and hybrid retrieval

**What it is.** Section 11 searched one short description per listing. A documentation set is different: a page can be thousands of words, and one vector for a whole page is a blur of everything in it. So each document is cut into chunks of about 300 to 500 tokens, each chunk is embedded on its own, and a search returns chunks, not files. This slice indexes two corpora, this repository's own markdown (19 files) and the Laravel 13 docs (102 files), into 2 706 chunks, and searches them three ways: `vector`, `text` and `hybrid`. It is the first half of a docs chatbot; answering from the chunks comes later.

**Chunking, step by step.** The chunker is plain PHP ([`MarkdownChunker`](../app/Services/MarkdownChunker.php)), with no model call.
1. **Follow the structure.** Front matter, anchors, comments and HTML tags are dropped (autolinks like `<https://…>` and types like `Collection<int, User>` stay), inline `data:` images are shortened to `data:…`, the title comes from the first `#` or the file name, and the text is split by headings first, then paragraphs, then sentences. A paragraph with no sentence end (a link list) is cut at line breaks, and as a last resort at the last space before the limit.
2. **Prefix.** Every chunk starts with `Document: <title> › Section: <heading>`, so a chunk that says only "set it to 400" still carries what it is about. `bge-m3` needs no instruction prefix, so this line is context, not a model convention.
3. **Overlap.** When a long section needs several chunks, the next one starts with the last whole paragraphs or sentences of the previous one, enough to cover 50 to 80 estimated tokens, so a thought split across the boundary is found from either side. An overlap of fewer than 50 tokens is dropped, and there is none across sections.
4. **Code and tables stay whole.** A code block or table is never split, because half a table answers nothing. A block above 500 estimated tokens becomes an oversize chunk on purpose: 7 of 2 706 (0.3 %), all tables, the largest 2 738 estimated tokens (the contracts table in `contracts.md`). The model reads up to 8 192 tokens, so none is cut, but such a chunk embeds as a blur of its whole content. The first run had 15 oversize chunks: 8 were link lists and a base64 screenshot of 8 780 tokens, which is more than the model reads. The write-up first called them code blocks; querying the rows showed otherwise.
5. **Hash per document.** A file is chunked again only when its hash changed, so a second `docs:index` run changes nothing. The hash covers the chunker version as well as the text, so a change to the rules above re-chunks every file; chunks whose text did not change keep their vectors. Each chunk is stored with a `tsvector` (generated column, `english`) and a 1024-number embedding (HNSW, cosine), as in section 11.

**What the token estimate gets wrong.** The chunker needs token counts but does not load a tokenizer; it estimates `ceil(characters / 4)`. Measured against Ollama's own count on 20 chunks, the estimate was lower than the real count in all 20: the mean estimate / real was 0.824 (18 % low), the worst chunk 397 estimated against 593 real. Code, tables and punctuation split into more tokens than prose. So the "500" limit is a limit on the estimate; a chunk at the limit is about 600 tokens to the model. Median chunk size was 390 to 400 estimated tokens in both corpora, inside the target.

**What each search finds.**
- **Vector** finds meaning: "why does apache hold back a streamed response until the end" lands on the right file without sharing its words. Its weakness is exact strings: an identifier like `flushpackets` or an error code has no "meaning" to be near, and other chunks about similar topics can crowd it out.
- **Full text** finds exact words. It uses `websearch_to_tsquery('english', …)` ranked by `ts_rank_cd`, on the generated `tsvector`, and never calls the model. Its weakness is the opposite: the words are joined with AND, so a question phrased differently from the docs matches no chunk at all, and on the first run two of the three misses returned nothing.
- **Hybrid** runs both and fuses them.

**Reciprocal Rank Fusion (RRF).** The two lists cannot be added up: a cosine similarity (0.71) and a `ts_rank_cd` value (0.06) are on different scales and mean different things. RRF ignores the scores and uses only positions. Take the top 20 of each list; a chunk at rank r in a list earns 1 / (60 + r) from it; the chunk's score is the sum over both lists, and the best k are returned. A chunk high in both lists wins, a chunk found by one list still scores, and no weights need tuning. The 60 damps the gap between first and second place. Ties go by chunk id. If one list is empty, the other is used alone. The response shows each chunk's rank and score in each list (null when absent), so a result can be explained. The cost: RRF sees only ranks, so when full text is confidently wrong it gets the same say as the vector list.

**What was measured.** Ten queries were written and fixed before the first run: five identifiers (`flushpackets`, `AI_EF_SEARCH`, `draft_num_predict`, `pg_stat_database`, `withoutOverlapping`) and five questions in other words; a query counts as found when a chunk of an expected file is in the top 3. Details are in [`BENCHMARKS.md`](../BENCHMARKS.md#docs-index-and-hybrid-retrieval-2026-11-02).

| Measure | Value |
|---|---|
| Index | 121 documents, 2 706 chunks (repo 289, laravel 2 417), 7 oversize |
| Embedding, one worker | 146.8 s for 85 calls, 18.4 chunks/s, about 7 900 tokens/s |
| Size | table 5.8 MB, HNSW 21.1 MB, GIN 5.7 MB |
| Identifier queries found (of 5) | vector 5, text 5, hybrid 5 |
| Meaning queries found (of 5) | vector 5, text 3, hybrid 5 |
| All queries | vector 10 of 10, text 8 of 10, hybrid 10 of 10 |

**What surprised.** The textbook says vectors fail on identifiers; here they did not (5 of 5), because each identifier is rare and sits in a short, distinctive chunk. That does not make hybrid unnecessary: the corpus is small, the identifiers came from our own text, and the noise is visible (`AI_EF_SEARCH` had an unrelated Laravel page in third place). Hybrid never lost a query, but "how do I run a command every five minutes" moved from first (vector) to second because full text put another page first. The text score rose from 2 to 3 meaning questions after the fixes, but not because full text got better: this guide and BENCHMARKS.md are in the corpus and quote the queries, so full text found the quote. A test set must stay out of the corpus it tests. A bigger corpus and a proper evaluation are needed to see the difference, which is why the larger evaluation is a later slice.

**In this repo.**
- Reference design: [`.ai/research/ai-chatbot-reference.md`](../.ai/research/ai-chatbot-reference.md).
- Code: [`MarkdownChunker`](../app/Services/MarkdownChunker.php) and [`DocsSearchService`](../app/Services/DocsSearchService.php) in `app/Services/`; commands `docs:fetch-laravel`, `docs:index`, `docs:search` and `docs:search-bench`; endpoint `GET /api/docs/search?q=…&mode=hybrid&k=6`.
- Results: [`bench/results/2026-11-02-docs-index/`](../bench/results/2026-11-02-docs-index/), queries in [`bench/docs-queries.txt`](../bench/docs-queries.txt).
- Tests: `MarkdownChunkerTest`, `DocsIndexCommandTest` and the search tests; none needs a network or Ollama.
- Spec: [`.ai/specs/spec-20261102-2352-docs-index-retrieval/`](../.ai/specs/spec-20261102-2352-docs-index-retrieval/).

**Read next.** [PostgreSQL full text search](https://www.postgresql.org/docs/16/textsearch.html) · [pgvector](https://github.com/pgvector/pgvector) · [bge-m3 model card](https://huggingface.co/BAAI/bge-m3)

---

## Planned sections

Added as each slice ships (see [`backlog.md`](../backlog.md) for the order):

- **Server track:** slow I/O and thread autoscaling (SL-09), Early Hints and compression (SL-10), file downloads offloaded to the web server (SL-11), HTTP/3 (SL-12), real-time updates with Mercure and Reverb (SL-13, SL-14), metrics and logs (SL-15, SL-16), hot reload and Xdebug in workers (SL-17).
- **AI track:** how an LLM call works end to end, from model weights through the inference engine, model server and SDK to the app; structured output (SL-23); tool calling (SL-25); evals (SL-26, SL-33); vision (SL-27); model comparison (SL-28); RAG with citations (SL-45, on top of the index from SL-32); a hand-written agent loop and MCP (SL-34, SL-35); image embeddings for similar photos (SL-36…SL-39); semantic caching (SL-40); streaming chat UI (SL-41); a research agent (SL-42); a prompt playground (SL-43).
