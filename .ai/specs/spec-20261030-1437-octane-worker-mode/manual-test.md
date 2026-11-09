# Manual test plan: FrankenPHP worker mode two ways

Run everything from the project root (`/Users/stasmarkov/work/interview`) in a host terminal. Site: https://interview.ddev.site

## Preconditions

- DDEV is running. This plan starts on nginx-fpm on purpose: run `ddev server nginx-fpm`, then `ddev server --current` prints `nginx-fpm`.
- Database is seeded (`/listings/1` opens a listing).
- `DEBUG_ROUTES` is unset or `false` in `.env`.
- The frankenphp web image has been built once before, or allow several minutes for the first switch.
- The new files (`.ddev/servers/frankenphp-octane/`, `.ddev/servers/frankenphp-worker/`, `config/octane.php`, `public/frankenphp-worker.php`, `bench/parity/frankenphp-*.txt`) are present in your checkout.

## Rollback

If anything goes wrong, put the project back on the default server with debug routes off:

```sh
ddev server default
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
git checkout -- bench/parity
```

## Step 1: switch to frankenphp-octane (AC-1, AC-3)

```sh
ddev server
ddev server frankenphp-octane
head -1 .ddev/config.zz-server.yaml
ddev server --current
ddev server --url
ddev server frankenphp-octane
curl -sI https://interview.ddev.site/up | grep -iE '^(HTTP|server)'
```

Expected:
- `ddev server` prints `Supported: nginx-fpm apache-fpm frankenphp openlitespeed frankenphp-octane frankenphp-worker`.
- The switch ends with `Active: frankenphp-octane`.
- First line of the overlay is `# server: frankenphp-octane`.
- `--current` prints `frankenphp-octane`, `--url` prints `https://interview.ddev.site`.
- The second switch prints `frankenphp-octane already active` and does not restart.
- `/up` answers `200` with `server: FrankenPHP Caddy`.

## Step 2: Octane is the only server, 4 workers (AC-4)

```sh
ddev exec ps -eo pid,args | grep -E 'octane:frankenphp|frankenphp run' | grep -v grep
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
echo DEBUG_ROUTES=true >> .env
ddev artisan octane:reload --server=frankenphp
curl -s -H 'Accept: application/json' https://interview.ddev.site/debug/runtime
```

Expected:
- ps shows `env OCTANE_HTTPS=true php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=4 --max-requests=500 --log-level=warn` and one child `frankenphp run -c .../vendor/laravel/octane/src/Commands/stubs/Caddyfile`. No `frankenphp run --config /etc/frankenphp/Caddyfile` process.
- The thread count is `4` (the admin output also lists regular threads, which is fine).
- `octane:reload` prints `Reloading workers...`.
- The runtime JSON contains `"sapi":"frankenphp"` and `"opcache_enabled":true`.

## Step 3: parity and versions on Octane (AC-6, AC-7)

```sh
mkdir -p /tmp/wm-check && BENCH_PARITY_DIR=/tmp/wm-check bench/parity.sh capture
bench/parity.sh compare nginx-fpm /tmp/wm-check/frankenphp-octane.txt
bench/parity.sh compare nginx-fpm frankenphp-octane
bench/versions.sh
ddev composer show laravel/octane | grep -E '^versions'
```

Expected: capture is accepted (Server header token `caddy`); both compares print `identical`; versions prints a row starting `| frankenphp-octane | 1.13.4 |` and ending with `| frankenphp | true |` (date column follows); Octane version `v2.22.0`.

## Step 4: switch to frankenphp-worker, no Octane (AC-1, AC-5, AC-6, AC-7)

```sh
ddev server frankenphp-worker
head -1 .ddev/config.zz-server.yaml
ddev exec ps -eo pid,args | grep -E 'octane|frankenphp run' | grep -v grep
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
curl -s -H 'Accept: application/json' https://interview.ddev.site/debug/runtime
for u in $(curl -s https://interview.ddev.site/listings/1 | grep -oE '/build/assets/[^"]+' | sort -u); do
  curl -s -o /dev/null -w "%{http_code} %{content_type} $u\n" "https://interview.ddev.site$u"
done
BENCH_PARITY_DIR=/tmp/wm-check bench/parity.sh capture
bench/parity.sh compare nginx-fpm /tmp/wm-check/frankenphp-worker.txt
bench/parity.sh compare nginx-fpm frankenphp-worker
bench/versions.sh
cat .ddev/servers/frankenphp-worker/CHANGES.md
```

Expected:
- `Active: frankenphp-worker`; overlay first line `# server: frankenphp-worker`.
- ps shows only `frankenphp run --config /etc/frankenphp/Caddyfile --adapter=caddyfile`. No `octane` process.
- Thread count `4`.
- The runtime JSON has `"sapi":"frankenphp"` and `"opcache_enabled":true`. (If you get 404, the worker still has the old `.env`: run `ddev restart` first.)
- Every asset line starts with `200` (JS as `text/javascript`, CSS as `text/css`), and at least one `.js` and one `.css` line appear.
- Both compares print `identical`; versions prints a `| frankenphp-worker | 1.13.4 |` row ending `| frankenphp | true |`.
- `CHANGES.md` lists each edit against add-on v1.3.0 (single worker block, `try_files {path} frankenphp-worker.php`, env lines, merged server blocks, :443 reverse_proxy).

## Step 5: other servers unchanged (AC-2, MNC-2)

```sh
ddev server frankenphp
cmp .ddev/web-build/Caddyfile.frankenphp .ddev/servers/frankenphp/web-build/Caddyfile.frankenphp && echo classic-caddyfile-restored
git diff --exit-code HEAD -- .ddev/servers/frankenphp && echo stash-unchanged
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
ddev server apache-fpm
ls .ddev/web-build .ddev/docker-compose.* 2>&1 | grep -i frankenphp || echo no-frankenphp-files
ddev server nginx-fpm
BENCH_PARITY_DIR=/tmp/wm-check bench/parity.sh capture
bench/parity.sh compare nginx-fpm /tmp/wm-check/nginx-fpm.txt
git diff --exit-code .ddev/config.yaml && echo untouched
```

Expected: `classic-caddyfile-restored`; `stash-unchanged`; classic frankenphp has `0` worker threads; `no-frankenphp-files` after leaving frankenphp; `identical`; `untouched` (also MNC-1). Optionally repeat the apache-fpm and openlitespeed captures against their committed snapshots with `bench/parity.sh compare`.

## Step 6: no binary, nothing untracked needed (MNC-4)

```sh
ls frankenphp 2>&1
git check-ignore -v .ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml .ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp .ddev/servers/frankenphp-worker/CHANGES.md config/octane.php public/frankenphp-worker.php bench/parity/frankenphp-octane.txt bench/parity/frankenphp-worker.txt || echo none-ignored
git status --short
```

Expected: `ls: frankenphp: No such file or directory`; `none-ignored`. In `git status`, the listed new files must be committed before sharing the branch (they show as `??` until then, and the worker servers will not work on a fresh clone without them). This is the check for AC-6 "committed".

## Step 7: restart after a .env change and octane:reload (AC-10)

```sh
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
ddev server frankenphp-octane
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
echo DEBUG_ROUTES=true >> .env
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
bench/versions.sh; echo "exit $?"
ddev artisan octane:reload --server=frankenphp
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
ddev logs | tail -20
```

Expected: `404`, `404` (workers hold the old config), then versions.sh prints `GET /debug/runtime returned 404. Set DEBUG_ROUTES=true in .env ... 'ddev restart', or 'ddev artisan octane:reload' for frankenphp-octane.` and `exit 1`; reload prints `Reloading workers...`; then `200`. `ddev logs` shows Caddy JSON lines, including `FrankenPHP stopped` and a new start.

Repeat on frankenphp-worker:

```sh
sed -i '' '/^DEBUG_ROUTES=/d' .env
ddev server frankenphp-worker
echo DEBUG_ROUTES=true >> .env
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
ddev restart >/dev/null
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
ddev logs | tail -10
```

Expected: `404`, then after `ddev restart` `200`; `ddev logs` shows Caddy's own log lines.

On frankenphp-worker `ddev artisan octane:reload` fails without the Octane daemon's state file (`storage/logs/octane-server-state.json`) and exits 1; use `ddev restart` or `ddev exec curl -X POST localhost:2019/frankenphp/workers/restart` (verified 2026-10-30, as the README says).

## Step 8: overlay and Caddyfile contents (stash details)

```sh
cat .ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml
diff <(sed 1d .ddev/config.zz-server.yaml) /dev/null | head -0
for f in docker-compose.frankenphp.yaml web-build/Caddyfile.frankenphp web-build/Dockerfile.frankenphp web-build/pre.Dockerfile.frankenphp; do cmp .ddev/servers/frankenphp/$f .ddev/servers/frankenphp-octane/$f 2>&1 || true; done
diff .ddev/servers/frankenphp/config.frankenphp.yaml <(sed 1d .ddev/config.zz-server.yaml) >/dev/null; 
grep -c 'worker {' .ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp
grep -nE 'num |try_files|match \*|FRANKENPHP_WORKER|APP_BASE_PATH|APP_PUBLIC_PATH|LARAVEL_OCTANE|MAX_REQUESTS' .ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp
```

Expected:
- Octane overlay has the same `webimage_extra_packages` as `.ddev/servers/frankenphp/config.frankenphp.yaml`, `webserver_type: generic`, one `web_extra_daemons` entry `env OCTANE_HTTPS=true php artisan octane:frankenphp --host=0.0.0.0 --port=8000 --workers=4 --max-requests=500 --log-level=warn` with `directory: /var/www/html`, and `web_extra_exposed_ports` with `container_port: 8000`, `http_port: 80`, `https_port: 443`. There is no `frankenphp run` daemon in it.
- The stash `frankenphp` and `frankenphp-octane` files that get activated are identical (cmp prints nothing; the octane stash may only hold the overlay, in which case cmp reports a missing file and the files come from the frankenphp stash).
- Worker Caddyfile: `worker {` count is `1`; `num 4`; `try_files {path} frankenphp-worker.php`; no `match *` line; env `FRANKENPHP_WORKER 1`, `APP_BASE_PATH /var/www/html`, `APP_PUBLIC_PATH /var/www/html/public`, `LARAVEL_OCTANE 1`, `MAX_REQUESTS 500`, `REQUEST_MAX_EXECUTION_TIME 30`, and `map {header.X-Forwarded-Proto} {forwarded_https}` with `env HTTPS {forwarded_https}`.

## Step 9: failure handling on a worker server (AC-3)

```sh
mv .ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp /tmp/Caddyfile.worker.bak
ddev server frankenphp-worker; echo "exit $?"
ddev server --current
mv /tmp/Caddyfile.worker.bak .ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp
```

Expected: the command exits non-zero with a message about the missing stash file, nothing is activated, and `--current` still prints the previously active server. Put the file back afterwards (last command).

## Step 10: docs read-through (AC-8, AC-9, AC-10)

Open `BENCHMARKS.md` and `README.md` and check:

- BENCHMARKS.md: rows for `frankenphp-octane` and `frankenphp-worker` matching the versions output from Steps 3 and 4; a line with `laravel/octane v2.22.0`, 4 workers and max requests 500; both snapshots listed in Response parity as `identical`; the note that FrankenPHP and ZTS packages are unpinned is still there.
- README, section "How worker mode works": explains boot-once per worker, FrankenPHP handing the request to the waiting PHP loop, a fresh container copy per request and services flushed after the request, the `MAX_REQUESTS` (500) loop counter and fresh worker, that FrankenPHP's own `max_requests` is not used, and why code or `.env` changes need a restart. Has the comparison table (config owner, restart command, logs) and the Swoole-only list (Octane tables, ticks/intervals, `Octane::concurrently`, octane cache store).
- Known doc gaps found in testing: the README says flush services are "dropped before every request" (they are flushed after the request); it does not say FrankenPHP's own `max_requests` is unused; and it claims `octane:reload` works on frankenphp-worker (see Step 7). These should fail this step until fixed.
- The restart note tells you to run `ddev restart` (and `ddev artisan octane:reload` for frankenphp-octane) after changing `.env`.

## Step 11: automated suite still green (MNC-3)

```sh
ddev artisan test --compact
ddev exec vendor/bin/phpstan analyse
git diff HEAD --stat -- tests
```

Expected: all tests pass (165 at last run), phpstan reports no errors, and the test diff only shows changed server lists or BENCHMARKS rows plus added tests.

## Final cleanup

```sh
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
ddev server default
rm -rf /tmp/wm-check
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
```

Expected: `Active: nginx-fpm` and `404`.
