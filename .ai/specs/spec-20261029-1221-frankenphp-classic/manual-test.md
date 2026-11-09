# Manual test plan: FrankenPHP classic mode

Run from the project root (`/Users/stasmarkov/work/interview`) in a host terminal. App: https://interview.ddev.site.

## Preconditions

- DDEV is running. Step 1 begins with `ddev server nginx-fpm`, so the switch to frankenphp is a real one even though frankenphp is the default.
- Database seeded once: `ddev artisan migrate:fresh --seed` (skip if already seeded since the SL-02 captures).
- The first switch to frankenphp builds the web image; allow several minutes.

## Rollback

```sh
ddev server default
rm -f .ddev/docker-compose.frankenphp.yaml .ddev/web-build/*.frankenphp   # only if any remain
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
git checkout -- bench/parity
```

## Step 1: switch to FrankenPHP

```sh
ddev server nginx-fpm
ddev server frankenphp
ddev server --current
curl -sI https://interview.ddev.site/up | grep -i '^server'
```

Expected: `Active: frankenphp`, `frankenphp`, and a `Server` header containing `Caddy`.

## Step 2: files and config

```sh
ls .ddev/docker-compose.frankenphp.yaml .ddev/web-build/*.frankenphp
git status --short .ddev
git diff --exit-code .ddev/config.yaml && echo untouched
```

Expected: the four activated files exist and do not appear in `git status`; `untouched`.

## Step 3: runtime, OPcache, extensions

```sh
echo DEBUG_ROUTES=true >> .env && ddev artisan optimize:clear
curl -s -H 'Accept: application/json' https://interview.ddev.site/debug/runtime
ddev exec php -m | grep -E '^(pdo_pgsql|redis)$'
```

Expected: `"sapi":"frankenphp"`, `"opcache_enabled":true`, `php_version` 8.4.x; both extensions listed.

## Step 4: parity and versions

```sh
bench/parity.sh capture
bench/parity.sh compare nginx-fpm frankenphp
bench/parity.sh compare apache-fpm frankenphp
bench/versions.sh
ddev exec frankenphp -v
```

Expected: `identical` twice; a markdown row with the FrankenPHP version, SAPI `frankenphp`, OPcache `true`.

## Step 5: FPM servers stay clean

```sh
mkdir -p /tmp/parity-check
ddev server apache-fpm && ddev exec php -v | head -1
BENCH_PARITY_DIR=/tmp/parity-check bench/parity.sh capture
bench/parity.sh compare apache-fpm /tmp/parity-check/apache-fpm.txt
ddev server nginx-fpm && ddev exec php -v | head -1
BENCH_PARITY_DIR=/tmp/parity-check bench/parity.sh capture
bench/parity.sh compare nginx-fpm /tmp/parity-check/nginx-fpm.txt
ls .ddev/docker-compose.frankenphp.yaml .ddev/web-build/*.frankenphp 2>&1
```

Expected: `php -v` lines without `ZTS`; `identical` twice; `No such file` for the FrankenPHP files.

## Step 6: the switch command output and no-op

```sh
ddev server frankenphp
ddev server frankenphp
ddev server
ddev server --current
```

After step 5 the project is on nginx-fpm, so the first command is a real switch (prints `Active: frankenphp`); run the three commands once more afterwards if you want to see the no-op.

Expected: the second `ddev server frankenphp` prints `frankenphp already active` and does not restart ddev (it returns at once). `ddev server` prints `Active: frankenphp` and `Supported: nginx-fpm apache-fpm frankenphp`. `--current` prints `frankenphp`. Then `ddev server default` to go back.

## Step 7: stash matches the add-on v1.3.0 and nothing is left behind

```sh
d=$(mktemp -d)
for f in config.frankenphp.yaml docker-compose.frankenphp.yaml web-build/Caddyfile.frankenphp web-build/Dockerfile.frankenphp web-build/pre.Dockerfile.frankenphp; do
  mkdir -p "$d/$(dirname $f)"
  curl -fsSL "https://raw.githubusercontent.com/ddev/ddev-frankenphp/v1.3.0/$f" -o "$d/$f"
  cmp "$d/$f" ".ddev/servers/frankenphp/$f" && echo "same: $f"
done
cat .ddev/servers/frankenphp/VERSION
ls .ddev/addon-metadata
git check-ignore -v .ddev/docker-compose.frankenphp.yaml .ddev/web-build/Caddyfile.frankenphp .ddev/web-build/Dockerfile.frankenphp .ddev/web-build/pre.Dockerfile.frankenphp
git status --short .ddev/servers
```

Expected: five `same:` lines and no `cmp` output; `VERSION` reads `ddev/ddev-frankenphp v1.3.0`; `addon-metadata` has no `frankenphp` entry; four lines from `git check-ignore`, one per activated path; the stash files are the committed ones (no `??` or `M` after the commit is made).

## Step 8: failed restart is rolled back (optional, uses a fake ddev)

```sh
mkdir -p /tmp/fakebin && printf '#!/bin/sh\necho "$@" >> /tmp/fakebin/calls.log\nexit 1\n' > /tmp/fakebin/ddev && chmod +x /tmp/fakebin/ddev
rm -f /tmp/fakebin/calls.log
cp .ddev/config.zz-server.yaml /tmp/overlay.before
PATH=/tmp/fakebin:$PATH .ddev/commands/host/server frankenphp; echo "exit=$?"
cmp .ddev/config.zz-server.yaml /tmp/overlay.before && echo overlay-restored
ls .ddev/docker-compose.frankenphp.yaml 2>&1
cat /tmp/fakebin/calls.log
```

Start on nginx-fpm. Expected: recovery messages are printed, `exit=1`, `overlay-restored`, `No such file` for the FrankenPHP compose file, and `calls.log` shows two `restart` lines (the failed one and one retry). Clean up with `rm -rf /tmp/fakebin /tmp/overlay.before`.

## Step 9: restore

```sh
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/debug/runtime
ddev server default
ddev server --current
git diff --exit-code .ddev/config.yaml && echo untouched
```

Expected: `404`, the name `ddev server --default` prints (`frankenphp`), `untouched`.

## Step 10: docs and suite

```sh
grep -n 'frankenphp' README.md BENCHMARKS.md | head -30
ddev artisan test --compact
```

Expected: README "Switching servers" lists `frankenphp`, says the first build takes several minutes, explains ZTS (threads in one process) vs NTS (processes), and mentions `ddev logs -f`. BENCHMARKS.md has a frankenphp row, the line `FrankenPHP row: ddev/ddev-frankenphp v1.3.0, Caddy <version>`, and the three snapshot files with both compares `identical`. The suite is green.
