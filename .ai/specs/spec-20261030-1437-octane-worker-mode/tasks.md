# FrankenPHP worker mode two ways — Tasks

**Spec**: spec-20261030-1437-octane-worker-mode | **Size**: M | **Tasks**: 6

## Phase 1: Octane and servers

- [x] 1. Install Octane (backend)
  Covers: MNC-3, MNC-4
  - [x] 1.1 `ddev composer require laravel/octane`; `ddev server frankenphp`, then `ddev artisan octane:install --server=frankenphp --no-interaction` (binary found on PATH, nothing downloaded); back to nginx-fpm
  - [x] 1.2 Commit `config/octane.php`, `public/frankenphp-worker.php`, `OCTANE_SERVER=frankenphp` in `.env.example`; rewrite the `frankenphp` ignore line as `/frankenphp`, keep `**/caddy`, drop the worker-stub line
  - [x] 1.3 **Verify**: full suite green on nginx-fpm; `git status` shows no binary

- [x] 2. `ddev server` support for both worker servers (devenv)
  Covers: AC-1, AC-2, AC-3, MNC-1
  - [x] 2.1 Failing tests with stub stashes: activation of each, reuse of frankenphp files, Caddyfile override and restore, octane↔worker switch, supported list, `--url`, already-active, incomplete stash, rollback
  - [x] 2.2 Generalise activated files (reuse another stash's file, override one); add the two servers and their stashes (`config.frankenphp-octane.yaml`; edited `web-build/Caddyfile.frankenphp` + `CHANGES.md`)
  - [x] 2.3 **Verify**: `DdevServerCommandTest` green; `git diff --exit-code .ddev/config.yaml`

## Phase 2: Bench tooling

- [x] 3. Tokens and versions (bench)
  Covers: AC-6, AC-7
  - [x] 3.1 Failing tests: both servers accept `Caddy`; versions prints the FrankenPHP version and SAPI `frankenphp`; the 404 hint mentions restarting workers
  - [x] 3.2 Implement in `bench/parity.sh` and `bench/versions.sh`
  - [x] 3.3 **Verify**: `BenchScriptsTest` green

## Phase 3: Live runs and docs

- [x] 4. Live: frankenphp-octane (devenv/bench)
  Covers: AC-4, AC-6
  - [x] 4.1 Switch; check `ps`, threads, `/debug/runtime` (restart workers after setting `DEBUG_ROUTES`)
  - [x] 4.2 Capture, compare with nginx-fpm, versions row
  - [x] 4.3 **Verify**: manual-test.md steps 1–3

- [x] 5. Live: frankenphp-worker, then back to nginx-fpm (devenv/bench)
  Covers: AC-5, AC-6, MNC-2
  - [x] 5.1 Switch; check `ps` (no Octane), threads, `/debug/runtime`; capture, compare, versions row
  - [x] 5.2 Switch to frankenphp: classic Caddyfile byte-identical to the stash; then nginx-fpm: fresh capture `identical`
  - [x] 5.3 **Verify**: manual-test.md steps 4–6; project on nginx-fpm, `DEBUG_ROUTES` off

- [x] 6. BENCHMARKS.md and README (docs)
  Covers: AC-8, AC-9, AC-10
  - [x] 6.1 Two rows, Octane version and worker settings line, two snapshots in Response parity
  - [x] 6.2 README "How worker mode works": per-request walkthrough, comparison table, Swoole-only features checked against the Octane docs, restart-after-`.env` note
  - [x] 6.3 **Verify**: doc tests green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | switching to each worker server | `tests/Feature/DdevServerCommandTest.php::AC-1 frankenphp-octane and frankenphp-worker activate their files and overlays` | test | 2 |
| AC-2 | switching away restores other server's files | `tests/Feature/DdevServerCommandTest.php::AC-2 switching from frankenphp-worker to frankenphp restores the unedited Caddyfile` | test | 2 |
| AC-3 | supported list, --url, no-op, stash check, rollback | `tests/Feature/DdevServerCommandTest.php::AC-3 worker servers are supported and answer on the project URL` | test | 2 |
| AC-4 | Octane serves requests, 4 workers | manual-test.md#step-2 | manual | 4 |
| AC-5 | add-on worker serves requests, no Octane, 4 workers | manual-test.md#step-4 | manual | 5 |
| AC-6 | both snapshots identical to nginx-fpm | `tests/Feature/BenchScriptsTest.php::AC-6 committed worker-mode snapshots match nginx-fpm` | test | 3, 4, 5 |
| AC-7 | versions rows for both | `tests/Feature/BenchScriptsTest.php::AC-7 versions prints FrankenPHP rows for both worker servers` | test | 3 |
| AC-8 | BENCHMARKS.md rows, Octane version, settings | `tests/Feature/BenchmarksDocTest.php::AC-8 BENCHMARKS.md has both worker-mode rows and the Octane version` | test | 6 |
| AC-9 | README how-it-works note and comparison | `tests/Feature/ReadmeSwitchingServersTest.php::AC-9 README explains how worker mode works` | test | 6 |
| AC-10 | restart-after-.env hint | `tests/Feature/BenchScriptsTest.php::AC-10 versions 404 hint mentions restarting workers` | test | 3, 6 |
| MNC-1 | config.yaml untouched | `tests/Feature/DdevServerCommandTest.php::MNC-1 never modifies config.yaml` | test | 2 |
| MNC-2 | other servers unchanged | manual-test.md#step-5 | manual | 5 |
| MNC-3 | suite + CI green | verify full Pest suite green with only server-list and row assertions edited | test | 1, 6 |
| MNC-4 | no binary, no untracked runtime file | manual-test.md#step-6 | manual | 1, 5 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project left on nginx-fpm with `DEBUG_ROUTES` off

## Phase: Gaps (converge round 1)

- [x] 7. README "How worker mode works": add the missing AC-9 explanations (docs)
  Covers: AC-9
  - [x] Add to the walkthrough: Octane gives each request a fresh copy (clone) of the booted container and flushes the services configured in `config/octane.php` after it; the worker script counts handled requests and leaves its loop after `MAX_REQUESTS` (500, set by Octane's `--max-requests` in frankenphp-octane or by `env MAX_REQUESTS` in the frankenphp-worker Caddyfile), after which FrankenPHP starts a fresh worker; FrankenPHP's own worker `max_requests` option is not used; a code change (not only `.env`) needs a worker restart because the booted app stays in memory. In the table, name the Octane-generated Caddyfile from `vendor/laravel/octane` as the config owner and say the Octane logs are its JSON/Caddy output in `ddev logs`
  - [x] **Verify**: extend `ReadmeSwitchingServersTest::AC-9` to assert `MAX_REQUESTS`, `--max-requests`, `max_requests`, `vendor/laravel/octane`, a fresh/cloned container phrase and a code-change restart phrase; red first, then green; full suite green

- [x] 8. versions.sh 404 hint: add `ddev artisan octane:reload` for frankenphp-octane (bench)
  Covers: AC-10
  - Extend the 404 hint in `bench/versions.sh` so it also names `ddev artisan octane:reload` for frankenphp-octane, next to `ddev restart`
  - **Verify**: `BenchScriptsTest::AC-10 versions 404 hint mentions restarting workers` also asserts `ddev restart` and `octane:reload` in the error output; red first, then green

- [x] 9. Live-check `octane:reload` and the log location of both worker servers (devenv)
  Covers: AC-9, AC-10
  - On frankenphp-octane run `ddev artisan octane:reload` and confirm the workers reboot (a changed `.env` value such as `DEBUG_ROUTES=true` takes effect, or the `ddev logs` output shows the reload); check what `ddev logs` shows on each worker server; add the steps to manual-test.md and make the README table and restart note state what was observed (including if reload does not work); leave the project on nginx-fpm with `DEBUG_ROUTES` off
  - [x] **Verify**: manual-test.md has a reload/logs step that was run and its observed output matches the README; `git diff --exit-code .ddev/config.yaml`

## Phase: Gaps (converge round 2)

- [x] 10. README "How worker mode works": fix the flush timing and state that FrankenPHP's own worker `max_requests` is not used (docs)
  Covers: AC-9
  - README says services under `flush` "are dropped before every request", but `config/octane.php` registers `FlushTemporaryContainerInstances` (which reads `octane.flush`) on `OperationTerminated`, i.e. after the request; AC-9 asks for "flushes the configured services after it". The README also never says that FrankenPHP's own worker `max_requests` option is not used (the only `max_requests` mention is Octane's `config/octane.php` key, which is what satisfies the current test). Correct the flush sentence and add one sentence that the `worker` block sets no FrankenPHP `max_requests`; the limit is Octane's `MAX_REQUESTS` loop counter only
  - **Verify**: extend `ReadmeSwitchingServersTest::AC-9 README explains how worker mode works` to assert the section does not contain `dropped before every request`, contains an after-the-request flush phrase, and contains a phrase that FrankenPHP's own `max_requests` is not used; red first, then green; full suite green

- [x] 11. Live-check `/build/*` assets of the detail page on frankenphp-worker (devenv)
  Covers: AC-5
  - AC-5 requires `/build/*` assets of the detail page to return 200 on frankenphp-worker (proves `try_files {path} frankenphp-worker.php` lets Caddy serve existing files), but manual-test.md Step 4 has no asset check and the parity set (`bench/parity/frankenphp-worker.txt`) contains no `/build/*` request. Add to Step 4: extract the `/build/assets/*` URLs from `curl -s https://interview.ddev.site/listings/1` and `curl -s -o /dev/null -w '%{http_code}'` each; run it on frankenphp-worker; leave the project on nginx-fpm with `DEBUG_ROUTES` off
  - **Verify**: manual-test.md Step 4 has the asset check with expected `200` for every asset, and it was run with that result; `git diff --exit-code .ddev/config.yaml`

- [x] 12. Guard the committed worker Caddyfile and Octane overlay details in the stash test (devenv)
  Covers: AC-1, AC-5
  - `DdevServerCommandTest::AC-1 the committed worker stashes carry the overlay, Caddyfile and change list` only checks `frankenphp-worker.php`, `num 4` and `MAX_REQUESTS 500` in the worker Caddyfile and `container_port: 8000` in the overlay. Nothing guards `try_files {path} frankenphp-worker.php`, exactly one `worker {` block (one pool), no `match *`, the env lines `FRANKENPHP_WORKER 1`, `APP_BASE_PATH /var/www/html`, `APP_PUBLIC_PATH /var/www/html/public`, `LARAVEL_OCTANE 1`, or the overlay's `directory: /var/www/html`, `http_port: 80` and `https_port: 443`
  - **Verify**: the test asserts each of these against the committed stash files (one `worker {` via `substr_count`); full suite green
