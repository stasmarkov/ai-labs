# Manual test plan: Mercure live price (SL-13)

Run from `/Users/stasmarkov/work/interview`.

## Step 1

Keys and hub (AC-3, NFR-1).

```sh
ddev artisan mercure:keys
git status --short .env .ddev/.env.web
ddev server frankenphp
curl -sN -m 3 'https://interview.ddev.site/.well-known/mercure?topic=https://interview.ddev.site/listings/1/price' -o /dev/null -w '%{http_code} %{content_type}\n'
```

Expected:
- `git status` lists neither file, because both are ignored.
- The curl prints `200 text/event-stream`.

## Step 2

A live public update (AC-1, NFR-2).

1. Open https://interview.ddev.site/listings/1 and https://interview.ddev.site/listings/2 in two tabs.
2. Run `ddev artisan listings:reprice 1 19990`.

Expected:
- Tab 1 shows the new price within 1 s, with no reload.
- Tab 2 is unchanged.
- The live region is `aria-live="polite"`.

## Step 3

A private update (AC-2, NFR-1).

1. Set `DEBUG_ROUTES=true` and run `config:clear`.
2. Open `/listings/1?watch=private`. Expected: the response sets a `mercureAuthorization` cookie (HttpOnly, Secure, SameSite=Strict, path `/.well-known/mercure`).
3. Run `ddev artisan listings:reprice 1 18990 --private`. Expected: the tab updates.
4. Run `ddev artisan listings:reprice 2 17990 --private`. Expected: nothing changes in that tab.
5. Restore `.env`.

## Step 4

Without a hub (AC-5).

1. Run `ddev server nginx-fpm`.
2. Open `/listings/1` with the console and Network tab.

Expected: one failed request to `/.well-known/mercure`, then nothing for 10 s.

## Step 5

Checks (MNC-1–3).

```sh
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do ddev server $s >/dev/null; BENCH_PARITY_DIR=/tmp/sl13 bench/parity.sh capture >/dev/null; bench/parity.sh compare bench/parity/$s.txt /tmp/sl13/$s.txt; done
ddev server nginx-fpm
git diff --stat HEAD -- .ddev/config.yaml
```

Expected:
- The new fingerprint is the one recorded in `BENCHMARKS.md`.
- `identical` appears six times against the re-captured baseline.
- The `config.yaml` diff is empty.
