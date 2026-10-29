# Manual test plan: switching between nginx-fpm and Apache (apache-fpm)

Run everything from the project root (`/Users/stasmarkov/work/interview`) in a host terminal. The app is at https://interview.ddev.site.

## Preconditions

- DDEV is installed and the project is running: `ddev start`.
- You are on `nginx-fpm` and have no `.ddev/config.zz-server.yaml` (`ls .ddev/config.zz-server.yaml` says "No such file").
- Note your current `.env` value of `DEBUG_ROUTES` (it should be unset or `false`). Step 10 changes it temporarily.
- Switching servers runs `ddev restart`, so steps 7 and 11 take a minute or two each.

## Rollback (if anything goes wrong)

```sh
ddev server nginx-fpm            # or: rm -f .ddev/config.zz-server.yaml && ddev restart
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear   # only if you added it in step 10
git checkout -- bench/parity     # only if you overwrote the committed snapshots
rm -rf /tmp/parity-apache /tmp/parity-bad
```

The project must end up on nginx-fpm with `.ddev/config.yaml` unchanged.

## Step 1: baseline

Action:

```sh
ddev describe | grep -i -E 'nginx|apache|webserver'
ddev server --current
```

Expected: the project runs `nginx-fpm`, and `ddev server --current` prints exactly `nginx-fpm` and nothing else.

## Step 2: no argument and "already active" (AC-3)

Action:

```sh
ddev server; echo "exit=$?"
ddev server nginx-fpm; echo "exit=$?"
```

Expected:

- First command prints `Active: nginx-fpm` and `Supported: nginx-fpm apache-fpm`, then `exit=0`. No restart happens.
- Second command prints `nginx-fpm already active`, `exit=0`, and returns at once without restarting DDEV.
- `ls .ddev/config.zz-server.yaml` still reports the file does not exist.

## Step 3: unsupported argument (AC-4, first part)

Action:

```sh
ddev server foo; echo "exit=$?"
ls .ddev/config.zz-server.yaml
```

Expected: `Unknown server 'foo'. Supported: nginx-fpm apache-fpm`, then `exit=1`. The overlay file still does not exist and DDEV did not restart.

## Step 4: overlay is gitignored (AC-5)

Action:

```sh
git check-ignore -v .ddev/config.zz-server.yaml
```

Expected: one line pointing at `.gitignore` (for example `.gitignore:29:.ddev/config.zz-server.yaml`) and exit code 0.

## Step 5: debug route is hidden by default (AC-7, live check)

Precondition: `DEBUG_ROUTES` is not `true` in `.env`.

Action:

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/not-a-route
curl -s -H 'Accept: application/json' https://interview.ddev.site/debug/runtime | shasum -a 256
curl -s -H 'Accept: application/json' https://interview.ddev.site/debug/not-a-route | shasum -a 256
```

Expected: both status codes are `404`, and the two JSON bodies produce the same hash.

## Step 6: seed once and capture a snapshot on nginx-fpm (AC-8)

Action:

```sh
ddev artisan migrate:fresh --seed
BENCH_PARITY_DIR=/tmp/parity-nginx bench/parity.sh capture
cat /tmp/parity-nginx/nginx-fpm.txt
```

Expected:

- The script prints `Wrote /tmp/parity-nginx/nginx-fpm.txt`.
- Line 1 looks like `# server=nginx-fpm base=https://interview.ddev.site captured=2026-10-29T12:00:00Z` (any current UTC time).
- Then exactly 7 tab-separated lines, in this order: `/up`, `/api/ping`, `/listings`, `/listings?make=BMW&fuel=diesel&per_page=5`, `/listings?per_page=99`, `/listings/1`, `/listings/999999`. Each has path, status, content type, 64-character hash.
- `/listings?per_page=99` has status `422` and `/listings/999999` has status `404`. The others are `200`.
- Content types are lowercase, for example `application/json; charset=utf-8`.
- Note: the `/up` body hash is computed after replacing "rendered in 12ms" with "rendered in Nms". This is a pending decision (decision 6 in spec-lite.md).

Then compare to the committed snapshot:

```sh
bench/parity.sh compare /tmp/parity-nginx/nginx-fpm.txt bench/parity/nginx-fpm.txt; echo "exit=$?"
```

Expected: `identical`, `exit=0`.

## Step 7: switch to Apache (AC-1, AC-4 with an existing overlay)

Action:

```sh
ddev server apache-fpm
cat .ddev/config.zz-server.yaml
curl -sI https://interview.ddev.site/api/ping | grep -i -E '^(HTTP|server|content-type)'
ddev server foo; echo "exit=$?"
cat .ddev/config.zz-server.yaml
git diff --exit-code .ddev/config.yaml; echo "diff=$?"
```

Expected:

- DDEV restarts, then prints `Active: apache-fpm`.
- The overlay contains exactly `webserver_type: apache-fpm`.
- Response headers: `HTTP/2 200`, `server: Apache/2.4.x (Debian)`, `content-type: application/json; charset=utf-8`.
- `ddev server foo` prints the unknown-server message with both supported values, `exit=1`, and the overlay still reads `webserver_type: apache-fpm`.
- `diff=0`: `.ddev/config.yaml` is untouched.

## Step 8: capture on Apache and compare with nginx (AC-10, AC-9 happy path)

Action:

```sh
BENCH_PARITY_DIR=/tmp/parity-apache bench/parity.sh capture
head -n 1 /tmp/parity-apache/apache-fpm.txt
bench/parity.sh compare /tmp/parity-apache/apache-fpm.txt bench/parity/nginx-fpm.txt; echo "exit=$?"
bench/parity.sh compare nginx-fpm apache-fpm; echo "exit=$?"
```

Expected:

- The first line starts with `# server=apache-fpm base=https://interview.ddev.site captured=`.
- Both comparisons print `identical` and `exit=0`. The `# captured=` timestamps differ but are ignored.
- `git ls-files bench/parity` lists both `bench/parity/nginx-fpm.txt` and `bench/parity/apache-fpm.txt` (they must be committed; if they show as untracked, AC-10 is not met).

## Step 9: compare detects differences (AC-9)

Action:

```sh
mkdir -p /tmp/parity-bad
sed 's#^/listings/999999\t404#/listings/999999\t200#' bench/parity/nginx-fpm.txt > /tmp/parity-bad/nginx-fpm.txt
bench/parity.sh compare /tmp/parity-bad/nginx-fpm.txt bench/parity/apache-fpm.txt; echo "exit=$?"
head -n 6 bench/parity/nginx-fpm.txt > /tmp/parity-bad/short.txt
bench/parity.sh compare /tmp/parity-bad/short.txt bench/parity/apache-fpm.txt; echo "exit=$?"
bench/parity.sh compare nginx-fpm nope; echo "exit=$?"
```

Expected:

- First compare: `Differs: /listings/999999`, followed by both full lines (the edited one with `200`, the original with `404`), then `exit=1`.
- Second compare (fewer data lines): a `Line count differs:` message and `exit=1`.
- Third compare: `Missing snapshot: .../bench/parity/nope.txt` and `exit=2`.

## Step 10: versions row and debug route (AC-6, AC-11, AC-7 enabled)

Still on apache-fpm.

Action, first without the flag:

```sh
bench/versions.sh; echo "exit=$?"
```

Expected: a message saying `GET /debug/runtime returned 404` that tells you to set `DEBUG_ROUTES=true` in `.env` and run a cache clear, then `exit=1`. (Note: the script says `config:clear` where the spec says `optimize:clear`; tracked as task 13.)

Action, with the flag:

```sh
echo 'DEBUG_ROUTES=true' >> .env
ddev artisan optimize:clear
curl -s -H 'Accept: application/json' https://interview.ddev.site/debug/runtime
bench/versions.sh; echo "exit=$?"
```

Expected:

- The curl response is HTTP 200 JSON with `data` holding `sapi` = `fpm-fcgi`, `php_version` = `8.4.x`, `opcache_enabled` = `true`, `server_software` = `Apache/2.4.x (Debian)`.
- `bench/versions.sh` prints one row, 7 columns, like `| apache-fpm | 2.4.68 | 1.25.4 | 8.4.x | fpm-fcgi | true | 2026-10-29 |` (date is today in UTC), `exit=0`.
- `bench/versions.sh` and `ddev server` did not edit `.env` themselves: the only change is the line you added.

Then undo the flag:

```sh
sed -i '' '/^DEBUG_ROUTES=/d' .env
ddev artisan optimize:clear
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
```

Expected: `404`.

## Step 11: switch back to nginx-fpm (AC-2, MNC-1, MNC-2)

Action:

```sh
ddev server nginx-fpm
cat .ddev/config.zz-server.yaml
curl -sI https://interview.ddev.site/api/ping | grep -i -E '^(HTTP|server)'
git diff --exit-code .ddev/config.yaml; echo "diff=$?"
grep '^webserver_type' .ddev/config.yaml
bench/parity.sh compare /tmp/parity-nginx/nginx-fpm.txt bench/parity/nginx-fpm.txt
```

Expected:

- DDEV restarts and prints `Active: nginx-fpm`.
- The overlay now contains `webserver_type: nginx-fpm`.
- Header `server: nginx/...`, status `HTTP/2 200`.
- `diff=0`, and `.ddev/config.yaml` still has `webserver_type: nginx-fpm`.
- Compare prints `identical`: nginx responses for `/up`, `/api/ping`, `/listings`, `/listings/{id}` are unchanged from the committed snapshot.

## Step 12: docs (AC-12, AC-13)

Action:

```sh
grep -n -i -A8 '^## Versions' BENCHMARKS.md
grep -n -i -A40 'Switching servers' README.md
grep -n -i 'htaccess' README.md
```

Expected:

- BENCHMARKS.md has a "Versions" table with header `| Active server | Web server version | DDEV version | PHP | SAPI | OPcache | Date |` (same 7 columns, same order as the `bench/versions.sh` row), exactly one `nginx-fpm` row and one `apache-fpm` row, each with `fpm-fcgi` and `true`.
- README "Switching servers" mentions `ddev server` with `nginx-fpm` and `apache-fpm`, running `ddev artisan migrate:fresh --seed` once, capture on each server, then compare, setting `DEBUG_ROUTES=true` in `.env` and `ddev artisan optimize:clear`, that the scripts never change `.env` (and it stays false in `.env.example`), and that Apache reads `public/.htaccess` on every request.
- `grep DEBUG_ROUTES .env.example` shows `DEBUG_ROUTES=false`.

## Step 13: failed restart rolls back (AC-4, second part)

This uses a fake `ddev` that always fails, so your real project is not touched. Start on nginx-fpm with an overlay present from step 11.

Action:

```sh
mkdir -p /tmp/fake-ddev && printf '#!/bin/sh\nexit 1\n' > /tmp/fake-ddev/ddev && chmod +x /tmp/fake-ddev/ddev
cat .ddev/config.zz-server.yaml
PATH=/tmp/fake-ddev:$PATH .ddev/commands/host/server apache-fpm; echo "exit=$?"
cat .ddev/config.zz-server.yaml
rm .ddev/config.zz-server.yaml
PATH=/tmp/fake-ddev:$PATH .ddev/commands/host/server apache-fpm; echo "exit=$?"
ls .ddev/config.zz-server.yaml
rm -rf /tmp/fake-ddev
```

Expected:

- First attempt: message `ddev restart failed; reverted to nginx-fpm`, `exit=1`, overlay still reads `webserver_type: nginx-fpm` (previous content restored).
- Second attempt (no overlay before): same message, `exit=1`, and `ls` reports the overlay file does not exist (it was removed).
- Final state: nginx-fpm, no overlay; `ddev server --current` prints `nginx-fpm`.

Clean up: `rm -rf /tmp/parity-nginx /tmp/parity-apache /tmp/parity-bad`.
