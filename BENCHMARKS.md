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

## Expectations (classic vs worker)

Written before any classic-vs-worker run and committed first, so the results can be judged against a prediction made without them. Compared servers: frankenphp (classic), frankenphp-octane and frankenphp-worker, with frankenphp classic as the baseline.

- **ping.** Worker mode should reach at least 2x the RPS of frankenphp classic, because the app boots once instead of on every request and the request itself does almost no work.
- **search.** Worker mode should reach at most 1.3x the RPS of frankenphp classic, because the database query dominates the request and bootstrap is a small share of it.
- **memory, case A (leak, no recycling).** `memory_bytes` should grow roughly linearly with requests, since the static cache gets a new key every request.
- **memory, case B (fixed, no recycling) and both classic cases.** Growth should stay within 5 KiB per 1 000 requests: the fixed route keeps nothing, and classic mode throws everything away after each request.
- **memory, case C (leak, max-requests 500).** `memory_bytes` should drop at every worker restart, about every 500 requests, giving a sawtooth instead of a straight line.

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

## State leaks

`bench/leaks.sh` counts, per scenario and mode, how many of 20 "B" requests (dealer 2, locale de; no locale for number-locale) saw something other than their own data after 20 "A" requests (dealer 1, locale uk). It restarts the workers before every row. Run on 2026-11-01 against the lab routes (`/debug/leaks/{scenario}`, code in `app/Debug/Leaks/`, enabled with `DEBUG_ROUTES=true`). Each cell is `leaked n/20`.

| Scenario | Mode | nginx-fpm | frankenphp-octane (max-requests 500) | frankenphp-octane (max-requests 1) | frankenphp-worker |
|----------|------|-----------|--------------------------------------|------------------------------------|-------------------|
| dealer-scope | leak | 0/20 | 20/20 | 0/20 | 20/20 |
| dealer-scope | fixed | 0/20 | 0/20 | 0/20 | 0/20 |
| price-cache | leak | 0/20 | 20/20 | 0/20 | 20/20 |
| price-cache | fixed | 0/20 | 0/20 | 0/20 | 0/20 |
| number-locale | leak | 0/20 | 20/20 | 0/20 | 20/20 |
| number-locale | fixed | 0/20 | 0/20 | 0/20 | 0/20 |
| request-at-boot | leak | 0/20 | 20/20 | 20/20 | 20/20 |
| request-at-boot | fixed | 0/20 | 0/20 | 0/20 | 0/20 |

- **Matches the expectation.** Every `fixed` row and every nginx-fpm cell is `0/20`. Every `leak` row is `20/20` on both worker servers at max-requests 500. frankenphp-worker runs Octane's own worker script, so it behaves like frankenphp-octane.
- **max-requests 1 hides three leaks, not the fourth.** With a restart after every request no worker serves A and then B, so dealer-scope, price-cache and number-locale drop to `0/20`. The leak is hidden, not fixed: the code is unchanged, and the cost is a full boot per request. request-at-boot stays `20/20` because it does not need an earlier request: every freshly booted worker captures the boot-time request, which carries no dealer, so B always sees a stale request instead of its own. A restart cannot hide that.
- **`/debug/worker` and the lab are off by default.** With `DEBUG_ROUTES` unset the routes answer 404, and the script restores `.env` on exit.

## Classic vs worker (2026-11-02)

FrankenPHP classic against both worker setups (Octane's daemon and the add-on worker), same machine and rules as SL-06, run on 2026-11-02 from commit `7aca1d0`. Fair = 8 workers, no recycling; tuned = best of 8/16/32. Raw files: [`bench/results/2026-11-02-classic-vs-worker/`](bench/results/2026-11-02-classic-vs-worker/). Expectations were committed first ([above](#expectations-classic-vs-worker), commit `c82f462`).

#### /api/ping (bootstrap-dominated)

| Server | Setting | RPS | p50 ms | p95 ms | p99 ms | Errors % | Peak MiB (serving) | CPU % (serving) | Spread | RPS vs frankenphp | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| frankenphp | fair: 8 workers | 4766 | 9.3 | 18.0 | 27.7 | 0.00 | 227 | 563 | 8.8% noisy | ~1.00x |  |
| frankenphp-octane | fair: 8 workers, max-requests 1000000 | 10604 | 4.3 | 9.2 | 12.3 | 0.00 | 343 | 448 | 1.8% | ~2.23x |  |
| frankenphp-worker | fair: 8 workers, max-requests 1000000 | 8945 | 4.9 | 11.4 | 16.4 | 0.00 | 309 | 412 | 9.4% noisy | ~1.88x |  |
| frankenphp | tuned: 8 workers | 4669 | 10.1 | 16.7 | 22.0 | 0.00 | 220 | 560 | 20.4% noisy | ~1.00x |  |
| frankenphp-octane | tuned: 8 workers, max-requests 1000000 | 7817 | 5.7 | 12.6 | 18.2 | 0.00 | 348 | 412 | 36.4% noisy | ~1.67x |  |
| frankenphp-worker | tuned: 8 workers, max-requests 1000000 | 8271 | 5.1 | 12.6 | 20.4 | 0.00 | 298 | 412 | 20.6% noisy | ~1.77x |  |

#### /listings?make=BMW (DB-dominated)

| Server | Setting | RPS | p50 ms | p95 ms | p99 ms | Errors % | Peak MiB (serving) | CPU % (serving) | Spread | RPS vs frankenphp | Notes |
|---|---|---|---|---|---|---|---|---|---|---|---|
| frankenphp | fair: 8 workers | 365 | 130.6 | 195.0 | 231.7 | 0.00 | 277 | 329 | 18.5% noisy | ~1.00x |  |
| frankenphp-octane | fair: 8 workers, max-requests 1000000 | 1363 | 33.9 | 55.2 | 74.7 | 0.00 | 393 | 286 | 9.9% noisy | ~3.74x |  |
| frankenphp-worker | fair: 8 workers, max-requests 1000000 | 1461 | 33.2 | 44.4 | 51.1 | 0.00 | 316 | 287 | 20.2% noisy | ~4.01x |  |
| frankenphp | tuned: 32 workers | 485 | 99.5 | 147.8 | 180.8 | 0.00 | 319 | 390 | 1.6% | 1.00x |  |
| frankenphp-octane | tuned: 32 workers, max-requests 1000000 | 1206 | 36.1 | 78.1 | 122.9 | 0.00 | 548 | 288 | 9.8% noisy | 2.49x |  |
| frankenphp-worker | tuned: 16 workers, max-requests 1000000 | 1468 | 32.9 | 45.8 | 56.6 | 0.00 | 387 | 292 | 11.3% noisy | 3.03x |  |

#### Memory and CPU at 50 concurrent users (fair ping run)

| Server | Serving container | Idle MiB | Peak MiB | Avg CPU % | db idle MiB | db peak MiB | db avg CPU % | Idle `web` MiB (OLS only) |
|---|---|---|---|---|---|---|---|---|
| frankenphp | web | 293 | 227 | 563 | 29 | 44 | 0 | — |
| frankenphp-octane | web | 405 | 343 | 448 | 25 | 53 | 0 | — |
| frankenphp-worker | web | 233 | 309 | 412 | 19 | 38 | 0 | — |

#### Worker memory growth (memory_bytes, 1 worker, sequential requests)

| Server | Case | Mode | Max-requests | Bytes at 0 | at 1000 | at 5000 | at 10000 | Restarts | Highest bytes | KiB per 1000 requests |
|---|---|---|---|---|---|---|---|---|---|---|
| frankenphp | A | leak | — | 799104 | 799104 | 799104 | 799104 | 0 | 799104 | 0.00 |
| frankenphp | B | fixed | — | 799104 | 799104 | 799104 | 799104 | 0 | 799104 | 0.00 |
| frankenphp-octane | A | leak | 1000000 | 5685624 | 6594576 | 8705296 | 11312976 | 0 | 11312976 | 508.44 |
| frankenphp-octane | B | fixed | 1000000 | 5683952 | 6174488 | 6174488 | 6174488 | 0 | 6174488 | 0.00 |
| frankenphp-octane | C | leak | 500 | 5683952 | 1172384 | 1192544 | 1228712 | 20 | 6298824 | 494.64 |
| frankenphp-worker | A | leak | 1000000 | 5682368 | 6591320 | 8702040 | 11309720 | 0 | 11309720 | 508.44 |
| frankenphp-worker | B | fixed | 1000000 | 5680696 | 6171232 | 6171232 | 6171232 | 0 | 6171232 | 0.00 |
| frankenphp-worker | C | leak | 500 | 5680696 | 1169128 | 1189288 | 1225456 | 20 | 6295568 | 494.64 |

Memory rows: one worker, 10 000 sequential lab requests per case, read every 100 requests from `/debug/worker?gc=1` (garbage cycles collected before reading, see below). Growth is the least-squares slope per stretch between restarts, without each stretch's first (warm-up) sample.

### What the numbers say

**Bootstrap share** = 1 − (worker p50 ÷ classic p50) at the fair setting: the part of a classic request's latency that worker mode removes.

| Scenario | Classic p50 | Octane p50 | Add-on worker p50 | Bootstrap share (Octane / worker) |
|---|---|---|---|---|
| ping | 9.3 ms | 4.3 ms | 4.9 ms | 54 % / 47 % |
| search | 130.6 ms | 33.9 ms | 33.2 ms | 74 % / 75 % |

p50 is measured at 50 concurrent connections, so it includes queueing as well as work; the share is a comparison between modes under the same load, not a pure service-time split.

**Why search gains more than ping.** Each classic request opens a new PostgreSQL connection, and PostgreSQL starts a new backend process for every connection. Counted with `pg_stat_database.sessions`: 500 search requests opened 501 sessions on FrankenPHP classic (one per request plus the counting query) and 2 on Octane, where each worker keeps its connection for its whole life. Connection setup, plus booting the framework, Eloquent and the view layer, is most of a search request; the query itself is small. The "DB-dominated" label from SL-06 was wrong about the cause: the cost is the connection, not the query.

**Why classic search tunes to 32 workers.** A classic thread spends much of each request waiting for PostgreSQL to fork a backend and authenticate, so more threads keep the CPU busy (485 RPS at 32, 365 at 8). Worker mode has no such wait and does not gain from more workers.

**Memory.** Case A grows 508 KiB per 1 000 requests (about 0.5 KB per request: one static array entry per new amount) and reached 11.3 MB after 10 000 requests on both worker servers. Case B, the same work with the cache in Redis, is flat from request 100 to 10 000. Case C, the leak with max-requests 500, restarted 20 times and never went above 6.3 MB: recycling caps the leak, it does not remove it (the slope inside each stretch is still 495 KiB per 1 000). Classic FrankenPHP shows 0.8 MB on every read and no growth, because nothing survives a request.

### Surprises (classic vs worker)

- **Search, predicted at most 1.3x: got 3.7x (Octane) and 4.0x (worker) fair, 2.5x and 3.0x tuned.** Missed, because the prediction assumed the query dominates the request. It is the per-request connection and boot that dominate (see above).
- **Ping, predicted at least 2x: Octane 2.23x, add-on worker 1.88x fair.** Met by Octane, narrowly missed by the add-on worker. The two run the same Laravel worker loop, the worker row is marked noisy (9.4 %), and in the tuned rows the order flips (1.67x vs 1.77x), so the gap between them is inside the noise.
- **Case B, predicted within 5 KiB per 1 000: 0.00, but only after a fix to the measurement.** The dry run read `memory_get_usage()` directly and saw every mode, fixed included, climb about 4.8 KB per request and drop every ~750 requests. That is garbage (reference cycles) waiting for PHP's cycle collector, not a leak; the sawtooth swings about 3 MB, far more than case A's real leak. Reading memory after `gc_collect_cycles()` removed it.
- **Case C, predicted a drop about every 500 requests: 20 drops in 10 000, as predicted. Unexplained:** after a max-requests restart the worker reports about 1.2 MB, while the first worker after a container start reported 5.7 MB for the same app and route. Both had booted Laravel (`worker_hits` restarts at 1). Not investigated further; a likely place to look is what the first boot after a container start loads that a recycled worker reuses.
- **Noise.** 10 of the 12 ping and search rows are marked noisy (spread above 5 % after one rerun), worst on classic ping, which keeps 5.6 of the VM's 7 CPUs busy and is the most sensitive to other apps on the host. The tuned Octane ping row picked the same setting as fair (8 workers) and came out at 7 817 RPS against 10 604: the same configuration measured twice differs by 26 %. The ratios above are much larger than that, so the conclusions hold; the exact ping ratios do not.

### Interview story (classic vs worker)

I expected worker mode to help a tiny endpoint a lot and a database search hardly at all, and wrote that down before measuring. Ping did gain about 2x, but search gained 3–4x, and the reason was not the query: classic PHP opened one PostgreSQL connection per request (501 sessions for 500 requests), while a worker kept its connection open. The memory test needed fixing first, because PHP's cycle collector made even leak-free code look like it grew 5 KB per request until I collected garbage before reading memory. After that the static-array leak showed a clean 0.5 KB per request, and max-requests 500 capped it at 6.3 MB instead of 11.3 MB, which limits the damage but leaves the bug in place.

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
