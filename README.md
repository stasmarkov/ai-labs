<p align="center"><a href="https://laravel.com" target="_blank"><img src="https://raw.githubusercontent.com/laravel/art/master/logo-lockup/5%20SVG/2%20CMYK/1%20Full%20Color/laravel-logolockup-cmyk-red.svg" width="400" alt="Laravel Logo"></a></p>

<p align="center">
<a href="https://github.com/laravel/framework/actions"><img src="https://github.com/laravel/framework/workflows/tests/badge.svg" alt="Build Status"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/dt/laravel/framework" alt="Total Downloads"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/v/laravel/framework" alt="Latest Stable Version"></a>
<a href="https://packagist.org/packages/laravel/framework"><img src="https://img.shields.io/packagist/l/laravel/framework" alt="License"></a>
</p>

## About Laravel

Laravel is a web application framework with expressive, elegant syntax. We believe development must be an enjoyable and creative experience to be truly fulfilling. Laravel takes the pain out of development by easing common tasks used in many web projects, such as:

- [Simple, fast routing engine](https://laravel.com/docs/routing).
- [Powerful dependency injection container](https://laravel.com/docs/container).
- Multiple back-ends for [session](https://laravel.com/docs/session) and [cache](https://laravel.com/docs/cache) storage.
- Expressive, intuitive [database ORM](https://laravel.com/docs/eloquent).
- Database agnostic [schema migrations](https://laravel.com/docs/migrations).
- [Robust background job processing](https://laravel.com/docs/queues).
- [Real-time event broadcasting](https://laravel.com/docs/broadcasting).

Laravel is accessible, powerful, and provides tools required for large, robust applications.

## Learning Laravel

Laravel has the most extensive and thorough [documentation](https://laravel.com/docs) and video tutorial library of all modern web application frameworks, making it a breeze to get started with the framework.

In addition, [Laracasts](https://laracasts.com) contains thousands of video tutorials on a range of topics including Laravel, modern PHP, unit testing, and JavaScript. Boost your skills by digging into our comprehensive video library.

You can also watch bite-sized lessons with real-world projects on [Laravel Learn](https://laravel.com/learn), where you will be guided through building a Laravel application from scratch while learning PHP fundamentals.

## Agentic Development

Laravel's predictable structure and conventions make it ideal for AI coding agents like Claude Code, Cursor, and GitHub Copilot. Install [Laravel Boost](https://laravel.com/docs/ai) to supercharge your AI workflow:

```bash
composer require laravel/boost --dev

php artisan boost:install
```

Boost provides your agent 15+ tools and skills that help agents build Laravel applications while following best practices.

## Switching servers

The default server is frankenphp (`.ddev/config.yaml` is never modified and still says nginx-fpm; the default lives in the `ddev server` command). On a fresh clone, run `ddev setup`: it copies `.env.example` to `.env` if `.env` is missing, starts DDEV, runs `composer install` and `key:generate` when they are needed, switches to the default server, creates the Mercure keys if they are missing, migrates, and builds the front end (`npm install && npm run build`) when `public/build` is missing. A second run prints `Already set up: frankenphp`. `ddev server --default` prints the default name, and `ddev server default` switches to it. Switch the web server with the DDEV host command:

```sh
ddev server                 # show the active and supported servers
ddev server apache-fpm      # write the gitignored overlay .ddev/config.zz-server.yaml and restart
ddev server frankenphp      # FrankenPHP in classic mode (no worker), Caddy in front
ddev server openlitespeed   # OpenLiteSpeed with LSAPI on https://interview.ddev.site:8091
ddev server frankenphp-octane  # FrankenPHP worker mode, started by Laravel Octane
ddev server frankenphp-worker  # FrankenPHP worker mode, started by the add-on's Caddyfile
ddev server nginx-fpm       # plain DDEV nginx + PHP-FPM
ddev server default         # back to the default server
```

The first switch to `frankenphp` builds the web image and can take several minutes; later switches reuse it. FrankenPHP runs PHP inside its own process as threads, so it needs the thread-safe (ZTS) build of PHP. nginx-fpm and apache-fpm run PHP-FPM worker processes on the non-thread-safe (NTS) build. Switching away from `frankenphp` removes the copied add-on files, so `php -v` shows the NTS build again. FrankenPHP logs are in `ddev logs -f`.

FrankenPHP is not a DDEV `webserver_type`, so its files come from the `ddev/ddev-frankenphp` add-on (version in `.ddev/servers/frankenphp/VERSION`), committed unchanged under `.ddev/servers/frankenphp/` rather than installed with `ddev add-on get`. `ddev server frankenphp` copies `docker-compose.frankenphp.yaml` and `web-build/{Caddyfile,Dockerfile,pre.Dockerfile}.frankenphp` from there into `.ddev/` (all four are gitignored) and writes the add-on's `config.frankenphp.yaml` into the overlay. If `ddev restart` fails, the command puts back the previous overlay and add-on files and restarts the previous server.

`openlitespeed` runs as its own `openlitespeed` service from `.ddev/servers/openlitespeed/`; the `web` container stays on its nginx-fpm base and idle, so the main URL keeps answering. Open the app at `https://interview.ddev.site:8091`. The first switch builds the image and can take several minutes; later switches reuse it. Switching away removes the copied `docker-compose.openlitespeed.yaml` and the service stops.

OpenLiteSpeed is not a DDEV `webserver_type` either. `ddev server openlitespeed` copies `docker-compose.openlitespeed.yaml` from `.ddev/servers/openlitespeed/` into `.ddev/` (gitignored) and writes an overlay that keeps `webserver_type: nginx-fpm`. The service image is built from the stash's `Dockerfile`: the base image tag is recorded in `VERSION`, `lsphp84-pgsql` is added at build time, and the server and vhost configs come from `conf/httpd_config.conf` and `conf/vhosts/interview/vhconf.conf` (document root `public/`, PHP through LSAPI). If any of these files is missing, the command stops, names the missing file and changes nothing. PHP runs as a user with your DDEV UID and GID, so files it writes to `storage/` stay owned by you. Server output is in `ddev logs -s openlitespeed`; the access and error logs are in `/usr/local/lsws/logs/` inside that container. OpenLiteSpeed reads the `.htaccess` rewrite rules at start-up, so changes to `public/.htaccess` need a `ddev restart` (unlike Apache).

Apache reads `public/.htaccess` on every request, so rewrite and header rules in that file apply to Apache only and are not cached between requests; nginx ignores it.

Check that the servers answer identically. Seed once so every capture sees the same data, then capture on each server and compare:

```sh
ddev artisan migrate:fresh --seed
ddev server frankenphp
bench/parity.sh capture                       # reference: writes bench/parity/frankenphp.txt
ddev server nginx-fpm
bench/parity.sh capture                       # writes bench/parity/nginx-fpm.txt
bench/parity.sh compare frankenphp nginx-fpm  # prints "identical" (exit 0) or the differing requests (exit 1)
ddev server apache-fpm
bench/parity.sh capture                       # writes bench/parity/apache-fpm.txt
bench/parity.sh compare frankenphp apache-fpm
ddev server openlitespeed
bench/parity.sh capture                       # uses port 8091, writes bench/parity/openlitespeed.txt
bench/parity.sh compare frankenphp openlitespeed
ddev server frankenphp-octane
bench/parity.sh capture                       # writes bench/parity/frankenphp-octane.txt
bench/parity.sh compare frankenphp frankenphp-octane
ddev server frankenphp-worker
bench/parity.sh capture                       # writes bench/parity/frankenphp-worker.txt
bench/parity.sh compare frankenphp frankenphp-worker
ddev server default                           # back to the default server
```

Each snapshot line holds the path, status, media type (charset and other parameters dropped) and body SHA-256. Only the `/up` page has its render time masked before hashing. `capture` writes nothing if a request fails (exit 3) or the `Server` header does not match the active server (exit 4); set `PARITY_INSECURE=1` if the DDEV certificate is not trusted. The header must contain `nginx` on nginx-fpm, `apache` on apache-fpm, `caddy` on frankenphp and `litespeed` on openlitespeed (case-insensitive); a server missing from that list makes `capture` stop with exit 2. `capture` and `bench/versions.sh` take their base URL from `ddev server --url` (`https://interview.ddev.site:8091` on openlitespeed, `https://interview.ddev.site` on the others); set `PARITY_BASE_URL` to override it. When the base URL is not `https://interview.ddev.site`, `capture` replaces it in each body with that URL before hashing (using `perl` on the host), so snapshots taken on port 8091 compare directly with the others.

To record versions in `BENCHMARKS.md`, enable `/debug/runtime` and run `bench/versions.sh` on each server:

```sh
# set DEBUG_ROUTES=true in .env, then
ddev artisan optimize:clear
bench/versions.sh                             # one markdown row for BENCHMARKS.md
```

These two scripts never change `.env` (`bench/leaks.sh`, below, does, and restores it). Set `DEBUG_ROUTES` back to `false` afterwards; it stays `false` in `.env.example` and for benchmarks.

## How worker mode works

Classic PHP boots the framework on every request: autoload, service providers, config, routes, then the response, then everything is thrown away. In worker mode FrankenPHP starts a fixed number of PHP threads (workers) that each run `public/frankenphp-worker.php` once. That script boots Laravel a single time and then loops, waiting for requests. Both worker servers here use 4 workers and restart a worker after 500 requests.

One request, step by step:

1. The browser reaches the DDEV router, which ends TLS and forwards plain HTTP to the container with `X-Forwarded-Proto: https`. Each server turns that header into `HTTPS=on` for PHP in its own place: frankenphp-worker in its Caddyfile (`map` + `env HTTPS` on `php_server`), frankenphp-octane through `OCTANE_HTTPS=true` on the daemon, which makes Octane's `EnforceRequestScheme` listener set `HTTPS=on` and force `https://` URLs. The app itself does not trust proxy headers.
2. Caddy (inside FrankenPHP) checks whether the path is an existing file under `public/`, such as `/build/*` assets, and serves it directly.
3. Otherwise `try_files` rewrites the request to `frankenphp-worker.php` and hands it to an idle worker thread.
4. The worker, which already holds a booted application, builds a request from the handed-over data and runs it through the kernel. Routing, middleware, controller and view run, with no autoload or provider boot.
5. The response goes back to Caddy and the client, and Octane resets request-scoped state (sessions, resolved request instances and similar) so the next request starts clean. State kept in static properties or singletons is not reset, which is the main risk of worker mode.
6. After 500 requests the worker script leaves its loop and exits, and FrankenPHP starts a fresh worker, which limits memory growth. FrankenPHP's own worker `max_requests` option is not used; the counter lives in Octane's worker script.

The limit is the `MAX_REQUESTS` environment variable, which the worker script `vendor/laravel/octane/bin/frankenphp-worker.php` (the file `public/frankenphp-worker.php` is built on) reads and compares with its request counter; without it the script defaults to 1000. On frankenphp-octane it comes from `--max-requests=500` in the daemon command (the option's own default is also 500; `config/octane.php` has no `max_requests` key). On frankenphp-worker it is `env MAX_REQUESTS 500` in the Caddyfile `worker` block.

Octane does not rebuild the application for each request. Each request runs on a fresh clone of the container made from the booted application, so bindings resolved during the request do not leak into the next one. Bindings listed under `warm` in `config/octane.php` are resolved once at boot, and those under `flush` are dropped after every request, when the `OperationTerminated` event runs `FlushTemporaryContainerInstances` (the list is empty by default); the `listeners` list there holds these reset steps.

The two ways differ in who starts and owns the server:

| | frankenphp-octane | frankenphp-worker |
|---|---|---|
| Switch with | `ddev server frankenphp-octane` | `ddev server frankenphp-worker` |
| Process that serves | Octane daemon: `php artisan octane:frankenphp` with its own `frankenphp run` child, on port 8000 behind the router | one `frankenphp run --config /etc/frankenphp/Caddyfile` from the add-on |
| Octane daemon | yes | no |
| Who owns the config | Octane writes its Caddyfile at start-up (generated by `vendor/laravel/octane`); worker count and max requests come from the daemon command in the overlay | `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp` (`worker` block, `num 4`), edited from the add-on file as listed in its `CHANGES.md` |
| Restart workers | `ddev artisan octane:reload --server=frankenphp` (prints `Reloading workers...`), or `ddev restart` | `ddev restart`, or the admin API directly: `ddev exec curl -X POST localhost:2019/frankenphp/workers/restart`. `octane:reload` does not work here: it first checks Octane's state file (`storage/logs/octane-server-state.json`), which only the Octane daemon writes |
| Logs | `ddev logs -f`: Caddy JSON lines at warning level and above (`--log-level=warn`), plus start/stop messages | `ddev logs -f`: Caddy JSON lines, no per-request access log |

Both use the same thread-safe PHP and web image as `ddev server frankenphp`. Neither writes a log line per request, so both do the same work per request (that matters for the SL-08 benchmark). To watch every request while exploring, change the daemon's `--log-level=warn` to `info` in `.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml`, or add a `log` directive to the worker Caddyfile, then run `ddev server <name>` again.

One side effect to know: `/up` prints "Response rendered in Nms" from the `LARAVEL_START` constant. `public/frankenphp-worker.php` defines it so `/up` looks the same as on the other servers, but a constant is set once per worker, so in worker mode the number is the time since the worker booted, not the request time. The parity check masks it.

Octane features that only work on Swoole (checked against the Octane docs and source) do not work on either of these servers: concurrent tasks (`Octane::concurrently`), interval callbacks (`Octane::tick`), Octane tables (`Octane::table`) and the `octane` cache driver. Calling them on FrankenPHP fails or has no effect. Use the normal queue, scheduler and cache drivers instead.

A worker reads `.env` once when it boots. After changing `.env`, such as setting `DEBUG_ROUTES=true` for `/debug/runtime`, run `ddev artisan optimize:clear` and then `ddev restart` so the workers boot again. On frankenphp-octane `ddev artisan octane:reload --server=frankenphp` is quicker; on frankenphp-worker use the admin API call from the table above. After either, `/debug/runtime` answers 200. Without a restart or reload the running workers keep the old values and `/debug/runtime` still answers 404. The same goes for code: a code change needs a restart too, because the booted application and its loaded classes stay in memory until the worker exits.

To watch a worker persist and see state leak between requests, enable `DEBUG_ROUTES=true` (and restart the workers as above), then call `/debug/worker` and `/debug/leaks/{scenario}?dealer=<id>&locale=<uk|de|en>&mode=<leak|fixed>`. The scenarios are `dealer-scope`, `price-cache`, `number-locale` and `request-at-boot`; `dealer` is required, `mode` defaults to `leak`, and `amount` (in cents) is optional. With `DEBUG_ROUTES` off both routes answer 404 and the lab registers no container bindings. To count the leaks on the active server, run:

```sh
bench/leaks.sh                    # per scenario and mode: "leaked n/20"
bench/leaks.sh --max-requests 1   # frankenphp-octane only: rerun with workers restarting after every request
```

The script sets `DEBUG_ROUTES=true` in `.env` itself (backed up to `.env.bench-backup` and restored on exit) and restarts the workers before every row. It sends request A (dealer 1, locale uk) 20 times, then request B (dealer 2, locale de) 20 times, and counts the B responses with `leaked: true`. It exits 2 if `/debug/worker` does not answer 200, if a stale `.env.bench-backup` exists, or if `--max-requests` is given on another server. `--max-requests` writes the gitignored overlay `.ddev/config.zzz-bench-leaks.yaml`, a copy of the daemon config with the new value, and runs `ddev restart`; on exit it removes the overlay and restarts again. Each leak and its fix are explained in [section 7 of the learning guide](docs/learning-guide.md#7-state-leaks-in-worker-mode), and the measured counts are in [BENCHMARKS.md](BENCHMARKS.md#state-leaks).

## Contributing

Thank you for considering contributing to the Laravel framework! The contribution guide can be found in the [Laravel documentation](https://laravel.com/docs/contributions).

## Code of Conduct

In order to ensure that the Laravel community is welcoming to all, please review and abide by the [Code of Conduct](https://laravel.com/docs/contributions#code-of-conduct).

## Security Vulnerabilities

If you discover a security vulnerability within Laravel, please send an e-mail to Taylor Otwell via [taylor@laravel.com](mailto:taylor@laravel.com). All security vulnerabilities will be promptly addressed.

## License

The Laravel framework is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
