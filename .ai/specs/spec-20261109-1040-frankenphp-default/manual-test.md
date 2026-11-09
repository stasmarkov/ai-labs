# Manual test plan: FrankenPHP default (SL-47)

Run from `/Users/stasmarkov/work/interview`.

## Step 1

The default and setup (AC-1, AC-2).

```sh
ddev server --default
ddev server
b=/tmp/sl47-$$; mkdir -p $b; cp .ddev/config.zz-server.yaml .ddev/docker-compose.frankenphp.yaml .ddev/web-build/Caddyfile.frankenphp $b/ 2>/dev/null; rm -f .ddev/config.zz-server.yaml .ddev/docker-compose.frankenphp.yaml .ddev/web-build/Caddyfile.frankenphp; ddev server
ddev setup
curl -sN -m 3 -o /dev/null -w '%{http_code} %{content_type}\n' 'https://interview.ddev.site/.well-known/mercure?topic=x'
ddev setup
rm -rf $b
```

Expected:
- `frankenphp` from the first command.
- The first `ddev server` shows `Active: frankenphp (default: frankenphp)`.
- With the overlay moved aside, `ddev server` adds `Not set up: run ddev setup`.
- `ddev setup` ends on frankenphp, and the curl prints `200 text/event-stream`.
- The second `ddev setup` says "already set up".

## Step 2

Other servers (MNC-3).

Run `ddev server nginx-fpm`, open the site, then run `ddev server default`.

Expected: both switches work, and `ddev server --current` ends as `frankenphp`.

## Step 3

Checks (AC-5, MNC-1, MNC-2).

```sh
git diff --stat HEAD -- .ddev/config.yaml
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do ddev server $s >/dev/null; BENCH_PARITY_DIR=/tmp/sl47p bench/parity.sh capture >/dev/null; bench/parity.sh compare bench/parity/$s.txt /tmp/sl47p/$s.txt; done
ddev server default
bench/run.sh ping --quick; echo "exit=$?"
```

Expected:
- An empty `config.yaml` diff.
- The fingerprint unchanged.
- `identical` six times.
- `exit=0`, on a clean tree after the commit.
