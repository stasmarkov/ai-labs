# Manual test plan: OpenLiteSpeed + LSAPI (SL-05)

Goal: confirm that `ddev server openlitespeed` runs the unchanged Laravel app on OpenLiteSpeed (OLS) with LSAPI at `https://interview.ddev.site:8091`, while the main URL `https://interview.ddev.site` stays up, and that switching away leaves nothing behind.

Run every command from the project root in a host terminal:

```sh
cd /Users/stasmarkov/work/interview
```

## Preconditions

- DDEV is running. This plan starts on nginx-fpm on purpose: run `ddev server nginx-fpm`, then `ddev server --current` prints `nginx-fpm`.
- The database is seeded (`ddev artisan migrate:fresh --seed` if unsure). Listing 1 must exist.
- Docker is running. The first switch builds the OLS image, so allow several minutes.
- `.env` has `DEBUG_ROUTES=false` (or no such line). Step 4 turns it on and step 6 turns it off again.

## Rollback

If anything goes wrong, or when you are done:

```sh
ddev server default
rm -f .ddev/docker-compose.openlitespeed.yaml && ddev restart   # only if the file is still there
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
git checkout -- bench/parity/nginx-fpm.txt bench/parity/openlitespeed.txt
```

`.ddev/config.yaml` is never edited by the switch, so nothing needs restoring there.

## Step 1: stash is complete and the switch works

Check the committed files first:

```sh
ls .ddev/servers/openlitespeed .ddev/servers/openlitespeed/conf/vhosts/interview
cat .ddev/servers/openlitespeed/VERSION
grep -nE 'container_name|HTTPS_EXPOSE|VIRTUAL_HOST|com.ddev|context:|/var/www/html|ddev_default' .ddev/servers/openlitespeed/docker-compose.openlitespeed.yaml
grep -nE 'HTTP_EXPOSE|ports:' .ddev/servers/openlitespeed/docker-compose.openlitespeed.yaml || echo "no http-only port"
grep -n openlitespeed .gitignore
```

Expected:
- Files listed: `Dockerfile`, `VERSION`, `docker-compose.openlitespeed.yaml`, `conf` and `vhconf.conf`, `httpd_config.conf`.
- VERSION prints `litespeedtech/openlitespeed:1.9.4-lsphp84`.
- The grep shows `container_name: ddev-${DDEV_SITENAME}-openlitespeed`, `context: ./servers/openlitespeed`, `HTTPS_EXPOSE=8091:8088`, `VIRTUAL_HOST=$DDEV_HOSTNAME`, both `com.ddev.site-name` and `com.ddev.approot` labels, the `${DDEV_APPROOT}:/var/www/html` mount and `ddev_default`.
- The second grep prints `no http-only port`.
- `.gitignore` has `.ddev/docker-compose.openlitespeed.yaml`.

Now switch:

```sh
ddev server openlitespeed
ddev server --current
cat .ddev/config.zz-server.yaml
ls .ddev/docker-compose.openlitespeed.yaml
git status --short .ddev | grep docker-compose.openlitespeed || echo "copy is ignored by git"
```

Expected: the switch ends with `Active: openlitespeed`; `--current` prints `openlitespeed`; the overlay is exactly
```
# server: openlitespeed
webserver_type: nginx-fpm
```
the compose file exists in `.ddev/`; and the last line prints `copy is ignored by git`.

## Step 2: OLS answers on 8091 and the main URL stays up

```sh
curl -sI https://interview.ddev.site:8091/up | grep -iE '^(HTTP|server)'
curl -sI https://interview.ddev.site/up | grep -iE '^(HTTP|server)'
```

Expected: first command shows a 200 status line and `server: LiteSpeed` (any case); second shows 200 and `server: nginx`. (Add `-k` to curl if your certificate is not trusted.)

## Step 3: Laravel routing and writable storage

```sh
for p in /up /listings /listings/1 /api/ping /no-such-page /favicon.ico; do
  printf '%s ' "$p"; curl -s -o /dev/null -w '%{http_code}\n' "https://interview.ddev.site:8091$p"
done
curl -s https://interview.ddev.site:8091/api/ping
ddev artisan view:clear
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site:8091/listings/1
ls storage/framework/views | head -3
ddev logs -s openlitespeed | tail -20
grep -ci 'permission denied' storage/logs/*.log 2>/dev/null || true
```

Expected:
- `/up` 200, `/listings` 200, `/listings/1` 200, `/api/ping` 200, `/no-such-page` 404 (the Laravel not-found page, not an OLS error page), `/favicon.ico` 200.
- The ping JSON body (same as on the main URL).
- After `view:clear`, `/listings/1` still returns 200 and new compiled `.php` files appear in `storage/framework/views`.
- No "permission denied" lines in the OLS logs or in `storage/logs`.

## Step 4: PHP runtime, OPcache, extensions, parity, versions

```sh
echo DEBUG_ROUTES=true >> .env && ddev artisan optimize:clear
curl -s -H 'Accept: application/json' https://interview.ddev.site:8091/debug/runtime
ddev exec -s openlitespeed php -m | grep -E '^(pdo_pgsql|redis)$'
bench/parity.sh capture
bench/parity.sh compare nginx-fpm openlitespeed
bench/versions.sh
```

Expected:
- The runtime JSON contains `"sapi":"litespeed"`, `"opcache_enabled":true` and a `php_version` starting `8.4.`.
- `php -m` prints `pdo_pgsql` and `redis`.
- `capture` prints `Wrote .../bench/parity/openlitespeed.txt` (it used port 8091 on its own).
- `compare` prints `identical`.
- `versions.sh` prints one row like `| openlitespeed | 1.9.4 | <ddev version> | 8.4.x | litespeed | true | 2026-10-30 |`.

Also check the docs:

```sh
grep -n 'openlitespeed' BENCHMARKS.md
grep -n '8091\|htaccess' README.md
```

Expected: `BENCHMARKS.md` has an openlitespeed row, the `1.9.4-lsphp84` pinning note naming the unversioned `lsphp84-pgsql` package, `bench/parity/openlitespeed.txt` with its `identical` result, and the note that OLS has its own port while `web` stays idle. `README.md` lists `ddev server openlitespeed`, the URL `https://interview.ddev.site:8091`, says the first switch builds the image, and says `.htaccess` changes need a `ddev restart`.

## Step 5: switching away leaves nothing behind

```sh
ddev server nginx-fpm
docker ps -a --format '{{.Names}}' | grep ddev-interview-openlitespeed || echo none
ls .ddev/docker-compose.openlitespeed.yaml 2>&1
mkdir -p /tmp/ols-check && BENCH_PARITY_DIR=/tmp/ols-check bench/parity.sh capture
bench/parity.sh compare nginx-fpm /tmp/ols-check/nginx-fpm.txt
```

Expected: `Active: nginx-fpm`; `none`; `No such file or directory`; `identical`.

Optionally repeat from other servers: `ddev server apache-fpm`, then `ddev server openlitespeed` (200 with `Server: LiteSpeed` on 8091), then `ddev server frankenphp` (the OLS compose file is gone, no OLS container, the main URL answers from FrankenPHP/Caddy), then `ddev server nginx-fpm`.

## Step 6: command behaviour, then restore

```sh
ddev server
ddev server openlitespeed >/dev/null && ddev server openlitespeed
mv .ddev/servers/openlitespeed/VERSION /tmp/VERSION.bak
ddev server nginx-fpm >/dev/null; ddev server openlitespeed; echo "exit=$?"
mv /tmp/VERSION.bak .ddev/servers/openlitespeed/VERSION
```

Expected:
- `ddev server` prints `Active: ...` and `Supported: nginx-fpm apache-fpm frankenphp openlitespeed`.
- A second `ddev server openlitespeed` prints `openlitespeed already active`.
- With VERSION moved away the command prints `The openlitespeed stash is incomplete: .ddev/servers/openlitespeed/VERSION is missing...`, `exit=1`, and changes nothing (the project stays on nginx-fpm).

Restore:

```sh
ddev server default
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
git diff --exit-code .ddev/config.yaml && echo untouched
ddev artisan test --compact
```

Expected: `/debug/runtime` returns `404`; `untouched`; the full test suite passes (no application code was changed by this feature).
