---
id: spec-20261030-1437-octane-worker-mode
title: FrankenPHP worker mode two ways — Octane daemon and add-on worker directive
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov
approved_at: 2026-10-30T12:43:52Z
created: 2026-10-30
stacks: [laravel]
slice: SL-04
brs: [BR-02, BR-25, BR-27]
---

# FrankenPHP worker mode two ways — Octane daemon and add-on worker directive

## Summary
The app runs in FrankenPHP worker mode, booted once per worker instead of once per request, in two switchable ways. **frankenphp-octane** is Laravel Octane's own `octane:frankenphp` server started as a DDEV daemon. **frankenphp-worker** is the DDEV add-on's FrankenPHP with a `worker` directive pointing at Octane's worker script. Both pass the parity check, and BENCHMARKS.md records their versions. A README "How worker mode works" note compares them and explains who owns the config, who restarts workers and where the logs go. The point is to understand worker mode, not to tune it.

## Context
- **Problem**: SL-03 serves FrankenPHP in classic mode, where every request boots Laravel. Worker mode keeps the booted app in memory. That is where the speed comes from, and where state leaks come from (SL-07). Octane and the plain FrankenPHP worker directive reach the same runtime differently, and seeing both shows which layer does what.
- **User impact**: The fifth server config; SL-07 (leak lab), SL-08 (classic vs worker numbers) and SL-17 (watch, hot reload) build on it.
- **Business rules (product-brief.md, draft)**:
  - **BR-02** For the same request and seed, every endpoint returns the same status and the same JSON body on all five server configs.
  - **BR-25** Server, PHP, DDEV and add-on versions are pinned in config and recorded in BENCHMARKS.md for every result table.
  - **BR-27** Every shipped slice includes a short "How it works" note that names the concepts involved and walks through what happens, step by step, for one request or job.

## Scope
### In scope
- `laravel/octane` (approved dependency, 2026-10-30), `config/octane.php`, `OCTANE_SERVER=frankenphp` in `.env.example`, and Octane's worker stub `public/frankenphp-worker.php` committed (removed from the `.gitignore` lines `octane:install` adds).
- Server **frankenphp-octane**: stash `.ddev/servers/frankenphp-octane/` with its overlay source. It reuses the four activated files of the frankenphp stash unchanged, for the ZTS PHP and the `frankenphp` binary.
- Server **frankenphp-worker**: stash `.ddev/servers/frankenphp-worker/` with an edited copy of the add-on Caddyfile (worker inside `php_server`). It reuses the other three frankenphp activated files unchanged.
- Parity tokens and versions support for both; two committed snapshots; two BENCHMARKS.md rows plus the Octane version.
- README: switch values, "How worker mode works" note, server-specific Octane features, restart and logs per way.
### Out of scope
- State-leak lab, `/debug/worker` (SL-07); classic vs worker benchmarks (SL-08); thread or worker tuning (SL-06/09).
- `--watch`, hot reload, Xdebug in workers (SL-17).
- Admin-API worker restart command (SL-16).
- Any change to app code beyond what `octane:install` generates.

## Must NOT change
- **MNC-1**: `.ddev/config.yaml` is never modified.
- **MNC-2**: nginx-fpm, apache-fpm, frankenphp (classic) and openlitespeed behave as before. The classic frankenphp Caddyfile stays byte-identical to add-on v1.3.0, and after switching back from either worker server a fresh nginx-fpm capture compares `identical` with its committed snapshot.
- **MNC-3**: The Pest suite and CI stay green. Only tests that list supported servers or BENCHMARKS.md rows may change.
- **MNC-4**: No FrankenPHP binary is downloaded into the project, and no untracked file is needed after a fresh clone to run either worker server.

## Acceptance criteria

**frankenphp-octane overlay**: the frankenphp package list (same `webimage_extra_packages` as the frankenphp stash), `webserver_type: generic`, one web extra daemon `env OCTANE_HTTPS=true php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=4 --max-requests=500 --log-level=warn` run from `/var/www/html`, and `web_extra_exposed_ports` mapping container port 8000 to router ports 80/443. Activated files: the frankenphp stash's four files (`docker-compose.frankenphp.yaml`, `web-build/Caddyfile.frankenphp`, `web-build/Dockerfile.frankenphp`, `web-build/pre.Dockerfile.frankenphp`). The classic `frankenphp run` daemon is defined only in the frankenphp overlay's `web_extra_daemons`, and the compose file only maps ports, so with this overlay the Octane daemon is the only server process. The classic Caddyfile is copied into the image by `Dockerfile.frankenphp` but nothing runs it.

**frankenphp-worker overlay**: the frankenphp stash's `config.frankenphp.yaml` (classic daemon `frankenphp run`). Activated files: the frankenphp stash's compose file, `Dockerfile.frankenphp` and `pre.Dockerfile.frankenphp`, plus `web-build/Caddyfile.frankenphp` taken from the frankenphp-worker stash. That edited Caddyfile makes every request that does not match an existing file go to one worker pool of exactly 4 threads running `public/frankenphp-worker.php`, while existing files (e.g. `/build/*` assets) are still served by Caddy first, using the same `try_files {path} frankenphp-worker.php` approach as Octane's own Caddyfile rather than `match *`. The add-on file has three `php_server` blocks (`:80` for router-forwarded HTTPS, plain `:80`, `:443`). If a worker per block would create several pools, restructure the blocks so one pool of 4 serves them, and list that in `CHANGES.md`. The worker gets the environment Octane itself passes that the worker script reads (`FRANKENPHP_WORKER=1`, `APP_BASE_PATH=/var/www/html`, `APP_PUBLIC_PATH=/var/www/html/public`, `LARAVEL_OCTANE=1`, `MAX_REQUESTS=500`), confirmed against `vendor/laravel/octane/bin/frankenphp-worker.php`. The stash keeps a `CHANGES.md` naming every line changed from add-on v1.3.0 and why.

- **AC-1**: WHEN the developer runs `ddev server frankenphp-octane` THE SYSTEM SHALL activate its files, write its overlay (`# server: frankenphp-octane` first), restart, and print `Active: frankenphp-octane`; the same holds for `frankenphp-worker`.
- **AC-2**: WHEN the developer switches from either worker server to any other server THE SYSTEM SHALL remove every activated file that server does not use and leave the other server's files exactly as its stash defines (the frankenphp classic Caddyfile comes back unedited).
- **AC-3**: `ddev server` SHALL print `Supported: nginx-fpm apache-fpm frankenphp openlitespeed frankenphp-octane frankenphp-worker`; `--current`, `--url` (`https://interview.ddev.site` for both), the already-active check, the stash completeness check and rollback SHALL behave for both as for frankenphp.
- **AC-4**: WHILE frankenphp-octane is active, requests SHALL be served by Octane's worker: `/debug/runtime` reports `sapi` = `frankenphp` and `opcache_enabled` = `true`, the Octane server process is visible in `ddev exec ps` (the `octane:frankenphp` command and its `frankenphp run` child), and 4 worker threads run (FrankenPHP admin API `GET /frankenphp/threads` on `localhost:2019` inside the container, or the startup log; regular non-worker threads may also exist).
- **AC-5**: WHILE frankenphp-worker is active, requests SHALL be served by the worker script with no Octane daemon running: `/debug/runtime` reports `sapi` = `frankenphp` and `opcache_enabled` = `true`, `/build/*` assets of the detail page return 200, `ps` shows only `frankenphp run --config /etc/frankenphp/Caddyfile`, and 4 worker threads run.
- **AC-6 (BR-02)**: `bench/parity.sh capture` SHALL accept both servers with the Server header token `caddy` and write `bench/parity/frankenphp-octane.txt` and `bench/parity/frankenphp-worker.txt`; both SHALL be committed and compare `identical` with `nginx-fpm`.
- **AC-7 (BR-25)**: `bench/versions.sh` SHALL print a row for each worker server, with the FrankenPHP version in the web-server column (as for frankenphp) and SAPI `frankenphp`.
- **AC-8 (BR-25)**: BENCHMARKS.md SHALL have frankenphp-octane and frankenphp-worker rows pasted from `bench/versions.sh`, a line with the installed `laravel/octane` version (from `composer show laravel/octane`) and the worker settings (4 workers, max requests 500), and both snapshots listed in Response parity with their compare result. It SHALL keep the note that the FrankenPHP and ZTS packages are unpinned.
- **AC-9 (BR-27)**: README SHALL contain a "How worker mode works" section that explains, for one request:
  - that the app is booted once per worker, and what happens on each request (FrankenPHP hands the request to the waiting PHP loop; Octane gives each request a fresh copy of the container and flushes the configured services after it);
  - that the worker script counts handled requests and leaves its loop after `MAX_REQUESTS` (500; set by Octane's `--max-requests`, or by `env MAX_REQUESTS` in frankenphp-worker), after which FrankenPHP starts a fresh worker; FrankenPHP's own worker `max_requests` option is not used;
  - why a code or `.env` change needs a worker restart.

  It SHALL include a table comparing the two ways on config owner (Octane-generated Caddyfile from `vendor/laravel/octane` vs the stash's edited add-on Caddyfile), how to restart workers (`ddev artisan octane:reload` or `ddev restart` vs `ddev restart`), and where logs go (Octane's JSON output in `ddev logs` vs Caddy's log in `ddev logs`). It SHALL list the Octane features that do not work on FrankenPHP and need Swoole (Octane tables, ticks/intervals, `Octane::concurrently`, the `octane` cache store), each checked against the Octane docs.
- **AC-10**: WHEN `DEBUG_ROUTES` or another `.env` value is changed while a worker server is active, the README SHALL tell the developer to restart the workers, and `bench/versions.sh`, when `/debug/runtime` returns 404 while a worker server is active, SHALL add to its existing hint the same instruction. Both tell the developer to restart the workers (`ddev restart`; for frankenphp-octane also `ddev artisan octane:reload`), because the booted app keeps its config in memory.

**Clarifications from the clarity gate**
- **Install without a binary download.** Run `octane:install --server=frankenphp --no-interaction` while `ddev server frankenphp` is active, so the `frankenphp` binary from the add-on packages is on `PATH` and nothing is downloaded. If it still tries to download, stop and report. The daemon finds the same binary on `PATH`. Octane checks the minimum FrankenPHP version (1.5.0) at start; the installed version is recorded, not pinned (BR-25 note in BENCHMARKS.md).
- **`.gitignore`.** `octane:install` appends an unanchored `frankenphp`, which would also ignore new files under `.ddev/servers/frankenphp/`. Rewrite it as `/frankenphp` (project-root binary only), keep `**/caddy`, and remove the `frankenphp-worker.php` line.
- **TLS and headers.** The DDEV router terminates TLS and forwards plain HTTP, as verified for frankenphp classic in SL-03 (`Server: FrankenPHP Caddy`). Octane is started without `--https`, so it never tries automatic HTTPS. The `caddy` header token is verified in the live runs.
- **Logs and reload.** Where each way's logs appear and whether `ddev artisan octane:reload` reaches the daemon (state file `storage/logs/octane-server-state.json`, admin port 2019 inside the same container) are verified in the live run. The README states what was observed, including if reload does not work.
- **Switching between the two worker servers.** Covered by a test: frankenphp-octane → frankenphp-worker swaps the Caddyfile and the overlay, and back.
- **"Five server configs" (BR-02).** Worker mode counts once as the fifth config, run two ways. Parity applies to every switchable server, so BR-02's wording does not need to change.
- **Tooling.** Pest 5.3 and Larastan level 5 are installed; the project `CLAUDE.md` is out of date on PHPUnit.

## Boundaries
- **Always**: Pest tests for the new `ddev server`, parity and versions behaviour using the stub patterns; gates through DDEV; leave the project on nginx-fpm with `DEBUG_ROUTES` off; read the Octane and FrankenPHP docs before writing the "How it works" note.
- **Ask first**: any change to the classic frankenphp stash; worker counts other than 4 / max requests other than 500; enabling `--watch`; app code changes beyond `octane:install` output.
- **Never**: commit a FrankenPHP binary; edit `.ddev/config.yaml`; let a worker server's files remain active on another server.

## Technical context
- **Related code**: `.ddev/commands/host/server` (`SUPPORTED`, `overlay_for`, `activated_files`, `stash_files`, `base_url_for`, rollback); `.ddev/servers/frankenphp/` (add-on v1.3.0 stash); `bench/parity.sh` (`header_token`); `bench/versions.sh` (per-server `case`); tests in `tests/Feature/DdevServerCommandTest.php`, `BenchScriptsTest.php`, `BenchmarksDocTest.php`, `ReadmeSwitchingServersTest.php`.
- **Octane facts (2.x)**:
  - `octane:install --server=frankenphp` copies `stubs/frankenphp-worker.php` to `public/`, appends `**/caddy`, `frankenphp` and `frankenphp-worker.php` to `.gitignore`, sets `OCTANE_SERVER`, publishes `config/octane.php`, and downloads a FrankenPHP binary only when none is found (minimum 1.5.0).
  - `octane:frankenphp` runs `frankenphp run -c <Octane stub Caddyfile>` with environment such as `APP_BASE_PATH`, `MAX_REQUESTS`, `CADDY_SERVER_WORKER_COUNT` and `LARAVEL_OCTANE=1`.
  - The worker script requires `FRANKENPHP_WORKER` to be set.
- **FrankenPHP facts**: `php_server { worker { file …; num …; match …; env … } }`; `match` is only valid inside `php_server` and routes matching paths to the worker. The global `frankenphp { worker … }` block has no `match`, so it cannot route every request to the worker in the add-on's Caddyfile.
- **Approach**: generalise `activated_files` so a server can reuse another stash's files and override one; add two servers; extend the token map, the versions case and the docs.
- **Repos**: single repo `/Users/stasmarkov/work/interview`, dev URL https://interview.ddev.site.

## Decisions
1. Add `laravel/octane` (user approval, 2026-10-30).
2. frankenphp-worker owns an edited copy of the add-on Caddyfile, with every change listed in `CHANGES.md`, because worker routing needs `match` inside `php_server` (user choice).
3. 4 workers and max requests 500 in both ways (user choice; roadmap values).
4. Names `frankenphp-octane` and `frankenphp-worker` (user choice).
5. Commit `public/frankenphp-worker.php` so frankenphp-worker works after a fresh clone without running `octane:install`.
6. Octane listens on container port 8000 and the router maps 80/443 to it, so Octane never needs to bind a privileged port as the web user.
7. No proxy trust in app code. Each server turns the router's `X-Forwarded-Proto` into `HTTPS=on` itself: frankenphp-worker with a Caddyfile `map` and `php_server` `env HTTPS`, frankenphp-octane with `OCTANE_HTTPS=true` on the daemon (Octane's `EnforceRequestScheme`). The build had added `trustProxies(at: '*')`, which was removed after review (user choice, 2026-10-30).
8. Both ways log at warning level only (Octane `--log-level=warn`, no access log in the worker Caddyfile), so neither writes a line per request and SL-08 compares like for like (user choice, 2026-10-30).
9. frankenphp-worker passes `REQUEST_MAX_EXECUTION_TIME 30`, the per-request limit Octane passes from `octane.max_execution_time`; `config/octane.php` defaults `server` to `frankenphp`.
10. `public/frankenphp-worker.php` keeps one line Octane's stub lacks, defining `LARAVEL_START`, so `/up` has the same shape as on the other servers. The README documents that in worker mode it measures the worker's uptime.

## Open questions
- [x] Assumption: BR-02 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-30).
- [x] Assumption: BR-25 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-30).
- [x] Assumption: BR-27 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-30).
- [x] Clarity-gate answers folded in above (single worker pool of 4 and file serving via try_files, Octane overlay replaces the classic daemon, worker environment, MAX_REQUESTS mechanism, install without download, anchored `.gitignore`, TLS/headers, logs and reload verified live, octane↔worker switch test, BR-02 wording). — **Accepted** (Stanislav Markov, 2026-10-30).

## Dependencies
- **Requires**: SL-03, SL-02 (shipped).
- **Blocks**: SL-07, SL-08, SL-17.

---
Next: `/sdd spec-20261030-1437-octane-worker-mode` (approval → build)
