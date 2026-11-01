# Benchmarks

## Versions

Rows come from `bench/versions.sh` (needs `/debug/runtime` enabled with `DEBUG_ROUTES=true`), one per server.

- **nginx-fpm and apache-fpm** are pinned: PHP 8.4 in `.ddev/config.yaml`, and the web server and PHP patch versions come from the DDEV web image, so the DDEV version pins them.
- **openlitespeed is pinned by the image tag** `litespeedtech/openlitespeed:1.9.4-lsphp84`, which fixes OpenLiteSpeed and the lsphp 8.4 build. The one exception is the `lsphp84-pgsql` package, installed at build time without a version, so a rebuild that misses the Docker cache can pull a newer PHP patch release. The row is a point-in-time record: re-run `bench/versions.sh` after any rebuild.
- **frankenphp is not pinned.** The add-on is pinned at v1.3.0, but it installs the `frankenphp` and `php-zts-*` packages without versions from pkg.henderkes.com. Any web-image rebuild that misses the Docker cache (a DDEV upgrade, `ddev utility rebuild`, another machine) can install newer FrankenPHP, Caddy and PHP patch releases. The frankenphp row is a point-in-time record: re-run `bench/versions.sh` after any rebuild and before every benchmark, and update the row.

| Active server | Web server version | DDEV version | PHP | SAPI | OPcache | Date |
|---------------|--------------------|--------------|-----|------|---------|------|
| nginx-fpm | 1.26.3 | 1.25.4 | 8.4.24 | fpm-fcgi | true | 2026-10-29 |
| apache-fpm | 2.4.68 | 1.25.4 | 8.4.24 | fpm-fcgi | true | 2026-10-29 |
| frankenphp | 1.13.4 | 1.25.4 | 8.4.27 | frankenphp | true | 2026-10-29 |
| openlitespeed | 1.9.4 | 1.25.4 | 8.4.27 | litespeed | true | 2026-10-30 |
| frankenphp-octane | 1.13.4 | 1.25.4 | 8.4.27 | frankenphp | true | 2026-10-30 |
| frankenphp-worker | 1.13.4 | 1.25.4 | 8.4.27 | frankenphp | true | 2026-10-30 |

FrankenPHP row: ddev/ddev-frankenphp v1.3.0, Caddy v2.11.7

Worker rows: laravel/octane v2.22.0, FrankenPHP worker mode with 4 workers and max requests 500 (a worker restarts after 500 requests). frankenphp-octane runs `octane:frankenphp` on port 8000 behind the DDEV router; frankenphp-worker runs the add-on's `frankenphp run` with one `worker` block (`num 4`) on Octane's `public/frankenphp-worker.php`. Both reuse the frankenphp web image, so the FrankenPHP, Caddy and PHP versions match the frankenphp row and carry the same point-in-time caveat.

The FrankenPHP row runs classic mode (no worker) on thread-safe PHP, so its PHP patch version differs from the FPM rows.

## Expectations

Written before any full run and committed first, so the results can be judged against a prediction made without them. After the runs, a "Surprises" section explains every result that differs from what is written here.

- **ping (no database, tiny JSON).** At fair settings (8 workers each) nginx-fpm, apache-fpm, frankenphp (classic) and openlitespeed should land close together, as DDEV's own benchmark found, because the PHP work per request is small and the same app boots on every request. The worker-mode servers (frankenphp-octane, frankenphp-worker) boot the app once and should be clearly ahead, plausibly 2 to 5 times the RPS and a lower p95.
- **search (database-bound).** The query dominates, so server differences should shrink. Classic servers should sit within about 10 percent of each other, and worker mode should gain less than on ping. Past 8 workers throughput should stop rising because MariaDB becomes the limit.
- **Concurrency sweep (8, 16, 32).** FPM and lsphp are process-based, so more workers should help only while CPU is free. FrankenPHP threads are cheaper, so it should scale a little further. Tuned results should not change the ranking much.
- **memory.** Process models pay per worker, so nginx-fpm and apache-fpm should use the most memory at 32 workers. FrankenPHP threads share one process and should use less per worker. Worker mode keeps the booted app in memory, so it should have a larger baseline but grow less with load.
- **timeout behaviour.** `max_execution_time` counts CPU time under PHP-FPM on Linux, so `/debug/sleep` should not be stopped by it there. Under thread-safe PHP (FrankenPHP) it uses wall-clock timers and should stop the request. With 10 s limits, a sleep above the limit should fail on the servers whose own timeout applies and succeed elsewhere.
- **noise.** Runs with spread above 5 percent are rerun once and marked noisy if they stay above it. Anything within 5 percent of the baseline is reported as `~`, not as a win.

## How the numbers work

- **RPS** is completed requests per second during the 60 s measure window, after a 30 s warm-up. **p50, p95 and p99** are the latencies under which 50, 95 and 99 percent of requests finished; p95 and p99 show the slow tail that an average hides. **Error %** (the error rate) is the share of requests that failed: a status outside 2xx and 3xx, a transport error or a generator timeout. **Spread** is the run-to-run spread: (max - min) / median RPS over the 3 repeats; above 5 percent the set is rerun once and the row is marked `noisy` if it stays above.
- **Traffic path.** The load container (oha for ping, k6 for search, limited to 2 CPUs) sends HTTPS to the DDEV router, which forwards to the serving container, where a PHP worker handles the request and queries the `db` container. OpenLiteSpeed and FrankenPHP run in their own containers behind the same router. All containers, the load generator included, share the 7 CPUs of the OrbStack VM, and the load container is capped at 2 CPUs of them, so the generator and the server compete for the same cores.
- **Where a request waits when every worker is busy.** PHP-FPM: in the socket listen backlog until a child is free. OpenLiteSpeed: in the LSAPI connection queue (`maxConns`) in front of the lsphp children. FrankenPHP: in its request queue until a PHP thread is free. In all three models extra load raises latency first and RPS stays flat.
- **Why only ratios are compared.** Absolute numbers depend on this laptop, Docker's VM and what else is running, so they are not portable. Every row is shown as a ratio to the nginx-fpm row of the same table, measured in the same session, and the ratio is shown as `~` only when the nginx-fpm baseline of that table is marked `noisy`, because then no difference can be trusted.

## Results (2026-11-01)

All throughput runs used commit `b29c1ff`, 50 concurrent connections (closed model, no think time), 30 s warm-up and 60 s measure, 3 repeats, median by RPS. Load came from oha 1.16.0 (built locally from the checksum-pinned release, `bench/oha/Dockerfile`) for ping and grafana/k6 1.0.0 for search, capped at 2 CPUs on the OrbStack VM (7 CPUs, 16 GB) shared with every DDEV container. "fair" is 8 PHP workers with no recycling on every server; "tuned" is the best of 8/16/32 from a 10 s + 30 s sweep. CPU % is of one core (600 % = 6 cores). Ratios compare fair rows with fair nginx-fpm and tuned rows with tuned nginx-fpm; `~` means that baseline was `noisy`. The OpenLiteSpeed timeout rows were re-measured at `e5a3dfd` after fixing their classification. Raw runs: `bench/results/raw/` (not committed); summaries: `bench/results/2026-11-01/`.

### /api/ping (bootstrap-dominated)

| Server | Setting | RPS | p50 ms | p95 ms | p99 ms | Errors % | Peak MiB (serving) | CPU % (serving) | Spread | RPS vs nginx-fpm | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| nginx-fpm | fair: 8 workers | 4812 | 9.7 | 15.1 | 20.5 | 0.00 | 185 | 495 | 15.7% noisy | ~1.00x |  |
| apache-fpm | fair: 8 workers | 5654 | 8.3 | 12.1 | 15.0 | 0.00 | 166 | 591 | 0.3% | ~1.17x |  |
| openlitespeed | fair: 8 workers | 5844 | 7.8 | 13.4 | 16.8 | 0.00 | 80 | 606 | 0.4% | ~1.21x |  |
| frankenphp | fair: 8 workers | 6235 | 7.5 | 12.2 | 16.2 | 0.00 | 221 | 595 | 0.9% | ~1.30x |  |
| nginx-fpm | tuned: 16 workers | 6205 | 7.7 | 11.1 | 14.2 | 0.00 | 180 | 602 | 1.0% | 1.00x |  |
| apache-fpm | tuned: 16 workers | 5937 | 8.1 | 11.5 | 14.7 | 0.00 | 194 | 620 | 0.2% | 0.96x |  |
| openlitespeed | tuned: 8 workers | 5761 | 7.5 | 15.1 | 19.0 | 0.00 | 95 | 606 | 1.0% | 0.93x |  |
| frankenphp | tuned: 8 workers | 6254 | 7.4 | 12.3 | 16.2 | 0.00 | 233 | 591 | 0.3% | 1.01x |  |

### /listings?make=BMW (DB-dominated)

| Server | Setting | RPS | p50 ms | p95 ms | p99 ms | Errors % | Peak MiB (serving) | CPU % (serving) | Spread | RPS vs nginx-fpm | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| nginx-fpm | fair: 8 workers | 528 | 92.8 | 116.9 | 130.1 | 0.00 | 183 | 310 | 4.8% | 1.00x |  |
| apache-fpm | fair: 8 workers | 537 | 91.1 | 115.1 | 127.2 | 0.00 | 205 | 312 | 0.9% | 1.02x |  |
| openlitespeed | fair: 8 workers | 554 | 83.2 | 130.4 | 154.9 | 0.00 | 93 | 311 | 2.5% | 1.05x |  |
| frankenphp | fair: 8 workers | 495 | 97.9 | 126.7 | 153.2 | 0.00 | 254 | 378 | 2.9% | 0.94x |  |
| nginx-fpm | tuned: 16 workers | 625 | 77.8 | 101.1 | 119.3 | 0.00 | 210 | 361 | 0.4% | 1.00x |  |
| apache-fpm | tuned: 16 workers | 625 | 78.1 | 100.7 | 117.0 | 0.00 | 202 | 364 | 0.8% | 1.00x |  |
| openlitespeed | tuned: 16 workers | 644 | 75.1 | 100.0 | 122.6 | 0.00 | 113 | 353 | 1.4% | 1.03x |  |
| frankenphp | tuned: 16 workers | 537 | 90.4 | 117.2 | 145.2 | 0.00 | 261 | 404 | 0.6% | 0.86x |  |

### Memory and CPU at 50 concurrent users (fair ping run)

| Server | Serving container | Idle MiB | Peak MiB | Avg CPU % | db idle MiB | db peak MiB | db avg CPU % | Idle `web` MiB (OLS only) |
|---|---|---|---|---|---|---|---|---|
| nginx-fpm | web | 143 | 185 | 495 | 18 | 39 | 0 | — |
| apache-fpm | web | 151 | 166 | 591 | 19 | 33 | 0 | — |
| openlitespeed | openlitespeed | 68 | 80 | 606 | 19 | 41 | 0 | 153 |
| frankenphp | web | 156 | 221 | 595 | 19 | 26 | 0 | — |

### Timeouts: GET /debug/sleep/30

| Server | Timeouts | Status | Seconds | Ended by |
|---|---|---|---|---|
| nginx-fpm | defaults | 200 | 30.2 | none (request completed) |
| nginx-fpm | short | 504 | 10.0 | nginx fastcgi_read_timeout |
| apache-fpm | defaults | 200 | 30.2 | none (request completed) |
| apache-fpm | short | 504 | 10.1 | Apache ProxyTimeout |
| openlitespeed | defaults | 200 | 30.3 | none (request completed) |
| openlitespeed | short | 500 | 11.0 | OpenLiteSpeed external app timeout |
| frankenphp | defaults | 200 | 30.2 | none (request completed) |
| frankenphp | short | 500 | 10.1 | PHP max_execution_time |

The memory table comes from the ping run, which never touches the database, so `db` CPU is 0 there by design; on search the serving container's CPU (310–404 %) is what grows.

## Surprises

- **The fair ping baseline is noisy.** nginx-fpm's fair ping run spread 27 % and still 15.7 % after the automatic rerun, so every fair ping ratio carries `~`. It was the very first run of the suite, right after DDEV started; the tuned nginx-fpm run an hour later had 1.0 % spread and 6205 RPS, about 29 % above that noisy median. The fair ping ranking (frankenphp 1.30x, openlitespeed 1.21x, apache-fpm 1.17x) is therefore not trustworthy; the tuned ping table, where all four land within 7 % (0.93x–1.01x), is. That matches the expectation and DDEV's own benchmark.
- **FrankenPHP classic is slower on search, not equal.** Expected: classic servers within about 10 %. Measured: frankenphp 0.94x fair and 0.86x tuned (537 vs 625 RPS), with the highest CPU (378–404 %) of all four. Likely contributors, not yet proven: the thread-safe (ZTS) PHP build, which pays for thread-local storage on every global access, and a different PHP patch release (8.4.27 ZTS vs 8.4.24 NTS). On ping, where the request does almost nothing, the gap disappears.
- **Throughput rose from 8 to 16 workers on search, not only on CPU-light ping.** Expected: past 8 workers the database becomes the limit. Measured: 16 workers won the search sweep on every server (RPS +16 % to +18 % on nginx-fpm, apache-fpm and openlitespeed, +8 % on frankenphp; p95 down by 10–30 ms), and 32 never won. The database (PostgreSQL 16, not MariaDB as the Expectations said) was not yet the limit at 8; waiting on it simply left CPU idle that a few more workers could use.
- **FrankenPHP's 8 threads used more memory than 8 FPM children.** Expected: one process with threads uses less. Measured at 50 users: 221 MiB peak for the frankenphp `web` container vs 185 MiB for nginx-fpm and 166 MiB for apache-fpm. Caddy and the Go runtime live in the same process, and the comparison is at only 8 workers, where per-process overhead is small; the expected advantage would show at higher worker counts. OpenLiteSpeed's own container was smallest (80 MiB), but the idle `web` container next to it adds another 153 MiB.
- **Timeouts behaved as expected, with one detail.** With every limit at 10 s, nginx and Apache ended the 30 s sleep with a 504 at their proxy timeout, OpenLiteSpeed with its own 500 at about 11 s, and only FrankenPHP's PHP stopped it itself (500, Laravel logged "Maximum execution time of 10 seconds exceeded"). Under nginx and Apache the web server and FPM's `request_terminate_timeout` race each other at 10 s, so in a dry run some requests were ended by FPM instead; which one wins is luck. The OpenLiteSpeed row is attributed by elimination: no PHP fatal was logged and its error log records nothing at ERROR level.
- **Worker mode was not measured here.** The Expectations predicted 2–5x for the worker-mode servers; that comparison is SL-08.
- **Tooling note.** The first draft of "How the numbers work" said a difference within 5 % is reported as `~`. The harness does not do that: `~` replaces the ratio only when the nginx-fpm baseline is `noisy`.

## Interview story

I ran the same Laravel app behind nginx-fpm, Apache, OpenLiteSpeed and FrankenPHP on DDEV and measured them with a harness that warms up, repeats each run three times and refuses to run unless OPcache is on and debug mode is off. Once each server was tuned, `/api/ping` landed within 7 % across all four (nginx-fpm 6205 RPS, FrankenPHP 6254), and on the database-bound search FrankenPHP classic was actually 14 % slower (537 vs 625 RPS). So swapping the web server alone does not buy speed; the gains, if any, come from worker mode. The most useful lesson was timeouts: with `max_execution_time=10`, a 30-second `sleep()` was not stopped under PHP-FPM, because on Linux that limit counts CPU time, while FrankenPHP's thread-safe PHP stopped it at 10 s. Under FPM, the real guard is the web server's timeout or `request_terminate_timeout`.

## Response parity

`bench/parity/nginx-fpm.txt`, `bench/parity/apache-fpm.txt`, `bench/parity/frankenphp.txt` and `bench/parity/openlitespeed.txt` hold the status, media type and body SHA-256 of a fixed request set of 7 requests, captured on 2026-10-29 (openlitespeed on 2026-10-30) from the same seeded database on stock DDEV server configs (FrankenPHP and OpenLiteSpeed from their stashes). `bench/parity.sh compare nginx-fpm apache-fpm`, `bench/parity.sh compare nginx-fpm frankenphp` and `bench/parity.sh compare apache-fpm frankenphp` all report `identical`, and so does `bench/parity.sh compare nginx-fpm openlitespeed`.

`bench/parity/frankenphp-octane.txt` and `bench/parity/frankenphp-worker.txt` were captured on 2026-10-30 from the same seeded database. `bench/parity.sh compare nginx-fpm frankenphp-octane` and `bench/parity.sh compare nginx-fpm frankenphp-worker` both report `identical`. Getting there needed two things that only matter in a long-lived worker. The worker must know the client used HTTPS, or asset URLs come out as `http://`: frankenphp-worker sets `HTTPS=on` from `X-Forwarded-Proto` in its Caddyfile and frankenphp-octane uses `OCTANE_HTTPS=true`, with no proxy trust in app code. And `public/frankenphp-worker.php` defines `LARAVEL_START` so `/up` has the same shape; in worker mode that number is the worker's uptime, not the request time, and parity masks it. Octane runs with `--log-level=warn` and the worker Caddyfile has no access log, so neither way logs each request.

OpenLiteSpeed answers on its own port, `https://interview.ddev.site:8091`, while the `web` container stays idle on nginx-fpm. `capture` rewrites that base URL to the plain site URL before hashing, so the snapshot compares directly with the others.

### PHP settings per server (what differs and why)

| Setting | DDEV web (PHP-FPM) | FrankenPHP | lsphp default | lsphp as configured |
|---------|--------------------|------------|---------------|---------------------|
| `opcache.memory_consumption` | 500 | from FPM `php.ini` | 128 | 500 |
| `opcache.max_accelerated_files` | 1000000 | from FPM `php.ini` | 10000 | 1000000 |
| `opcache.interned_strings_buffer` | 16 | from FPM `php.ini` | 8 | 16 |
| `opcache.revalidate_freq` | 2 | from FPM `php.ini` | 2 | 2 |
| `date.timezone` | host timezone | host timezone | unset | UTC |

- **Why OPcache matters for fairness.** OPcache keeps compiled PHP in shared memory. With 10 000 slots and about 10 600 PHP files in this app, the lsphp default would fill up and keep evicting or skipping files, which makes OpenLiteSpeed look slower for a reason that has nothing to do with the server. `.ddev/servers/openlitespeed/conf/zz-opcache.ini` sets the same values as the FPM pool. `revalidate_freq=2` (check file timestamps at most every 2 s) is already the same everywhere.
- **Timezone.** PHP's `date.timezone` only affects PHP's own messages; every date in a response comes from Laravel's `config('app.timezone')` (UTC). lsphp uses UTC instead of copying the host timezone.
- **`X-Forwarded-Proto`.** The OLS vhost sets `HTTPS=on` when the request carries `X-Forwarded-Proto: https`, which is how the DDEV router tells the backend that the client used TLS. It trusts the header from any sender, exactly like DDEV's own nginx config does; that is fine on a dev network, but in production only the proxy's address should be trusted (Laravel `trustProxies`).
- **`.htaccess`.** OpenLiteSpeed applies only the rewrite rules from `public/.htaccess`, reads them at start-up, and needs `ddev restart` after a change. With the routing left entirely to `.htaccess`, the trailing-slash redirect behaves as on Apache (`/listings/` → 301).

Known differences that the comparison deliberately ignores:

- **Content-Type charset.** nginx serves JSON as `application/json; charset=utf-8`; Apache serves `application/json`. The app sends the same body; the parameter is added by nginx's `charset_types`. Parity compares the media type only.
- **`/up` render time.** Laravel's health page prints `rendered in <n>ms`, which changes on every request; it is masked before hashing, for `/up` only.
- **Headers** such as `Server` and `Date` differ by design and are not compared.
