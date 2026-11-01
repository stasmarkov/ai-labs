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

## Surprises

The first draft of "How the numbers work" said a difference within 5 percent is reported as `~`. The harness does not do that: `~` replaces the ratio only when the nginx-fpm baseline is `noisy`, and the Expectations line about 5 percent describes the noise rule, not a rounding rule. The results-based surprises follow after the full runs.

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
