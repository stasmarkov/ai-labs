# Manual test plan: Mercure live price (SL-13)

Run everything from `/Users/stasmarkov/work/interview`. The app is at https://interview.ddev.site.

## Before you start

- DDEV is running (`ddev start`) and you can open https://interview.ddev.site in a browser.
- The listings are seeded: listing 1 and listing 2 exist (`/listings/1`, `/listings/2` return 200).
- `.env` has `MERCURE_PUBLISHER_JWT_KEY` and `MERCURE_SUBSCRIBER_JWT_KEY` set. If they are empty, run `ddev artisan mercure:keys` first (it writes `.env` and `.ddev/.env.web`).
- Note the starting prices of listing 1 and listing 2 (open both pages). Prices are in cents in the commands: `19990` shows as `199.90 EUR`.
- Rollback for the whole plan: put the prices back with `ddev artisan listings:reprice 1 --restore=<original cents>` (and the same for listing 2), remove `DEBUG_ROUTES=true` from `.env` then `ddev artisan config:clear`, and run `ddev server default`. Check with `ddev server` that frankenphp is active.

## Step 1: Keys and the hub (AC-3, NFR-1)

```sh
git status --short .env .ddev/.env.web
ddev server frankenphp
curl -sN -m 3 'https://interview.ddev.site/.well-known/mercure?topic=https://interview.ddev.site/listings/1/price' -o /dev/null -w '%{http_code} %{content_type}\n'
```

Expected:
- `git status` prints nothing for the two files, because both are ignored.
- The curl prints `200 text/event-stream` (curl may exit 28 after 3 s because the stream stays open; that is fine).
- `git diff HEAD -- .ddev/config.yaml` prints nothing.

## Step 2: A live public update (AC-1, NFR-2)

1. On frankenphp, open https://interview.ddev.site/listings/1 in tab 1 and https://interview.ddev.site/listings/2 in tab 2.
2. In DevTools, open the Network tab of tab 1: there is one request to `/.well-known/mercure?topic=…/listings/1/price`, status 200, type `eventsource`.
3. In a terminal run `ddev artisan listings:reprice 1 19990`.
4. Watch tab 1 without touching it. Then inspect the status element: in the Elements tab find `<p id="listing-price-status" class="sr-only" aria-live="polite">`.

Expected:
- The command exits 0 and prints `Listing 1: <old> -> 199.90 EUR (… -> 19990 cents)`.
- Tab 1 shows `199.90 EUR` within 1 second, and the page did not reload (the Network tab shows no new document request).
- The status element reads `Price updated to 199.90 EUR` and has `aria-live="polite"` (not `role="alert"`).
- Tab 2 keeps its old price.

Restore: `ddev artisan listings:reprice 1 --restore=<original cents>`.

## Step 3: A private update through the cookie (AC-2, NFR-1)

1. Add `DEBUG_ROUTES=true` to `.env`, then `ddev artisan config:clear`.
2. Open https://interview.ddev.site/listings/1?watch=private in tab 1 (DevTools Network open) and https://interview.ddev.site/listings/2 in tab 2.
3. Check the document response headers and Application > Cookies for tab 1.
4. Run `ddev artisan listings:reprice 1 18990 --private`.
5. Run `ddev artisan listings:reprice 2 17990 --private`.

Expected:
- The document response has `Set-Cookie: mercureAuthorization=<jwt>; … path=/.well-known/mercure; secure; httponly; samesite=strict`. The cookie shows HttpOnly, Secure, SameSite Strict in the cookie list.
- The hub request in tab 1 lists both topics (`…/listings/1/price` and `…/listings/1/price/private`) and returns 200.
- After step 4 both commands exit 0 and tab 1 shows `189.90 EUR` without a reload.
- After step 5 tab 1 does not change (listing 2's private update is not delivered to listing 1's token). Tab 2 has no cookie and no private subscription.
- Without `?watch=private`, or with `DEBUG_ROUTES` off, no `mercureAuthorization` cookie is set.

Restore: `ddev artisan listings:reprice 1 --restore=<original cents>`, `ddev artisan listings:reprice 2 --restore=<original cents>`, remove `DEBUG_ROUTES=true`, `ddev artisan config:clear`.

## Step 4: No hub, no retry storm (AC-5, AC-4 live)

1. Run `ddev server nginx-fpm`.
2. Open https://interview.ddev.site/listings/1 with the Console and Network tabs open. Wait 11 seconds.
3. Run `ddev artisan listings:reprice 1 19990 --private`.

Expected:
- The page renders normally with its price.
- Exactly one request to `/.well-known/mercure` (404) and one console error for it; no further requests during the 11 seconds.
- The command exits 0 (`echo $?` prints 0), warns `The public update was not published; the price is saved.` and `The private update was not published; the price is saved.`, and the new price shows after a reload. `storage/logs/laravel.log` has one error for the public and one for the private publish failure.

Restore the price as above.

## Step 5: Fingerprint, parity and config (MNC-1, MNC-2, MNC-3)

```sh
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do ddev server $s >/dev/null; BENCH_PARITY_DIR=/tmp/sl13 bench/parity.sh capture >/dev/null; bench/parity.sh compare bench/parity/$s.txt /tmp/sl13/$s.txt; done
ddev server default
git diff --stat HEAD -- .ddev/config.yaml
grep -n "app-PTzu1QaN\|second re-baseline" BENCHMARKS.md
```

Expected:
- The fingerprint lines (`app-DNob7CFE.css`, `app-PTzu1QaN.js` with their hashes) match the ones in `BENCHMARKS.md`.
- `identical` is printed for each of the six servers.
- The `.ddev/config.yaml` diff is empty.
- `BENCHMARKS.md` "Response parity" records the reason for the SL-13 re-baseline.

## Step 6: Load bench and its record (AC-6)

1. On frankenphp run the full method (about 2 minutes each; the tree must be clean, so commit first):
   `bench/mercure-load.sh --subscribers 100 --label frankenphp` then `bench/mercure-load.sh --subscribers 1000 --label frankenphp`.
2. Open the JSON files under `bench/results/mercure-load/<UTC date>/` and the section "Mercure subscribers and the health check (2026-11-05)" in `BENCHMARKS.md`.

Expected:
- Each run exits 0 and each JSON has health p95 and timeouts, busy threads max and median, latency p50/p95/max, delivered/expected, and private `{authorized_received: 1, wrong_token_received: 0, no_token_received: 0, passed: true}`.
- The `BENCHMARKS.md` section has the table, the Mermaid chart including the SL-09 10 000 ms cell, and the subsections "What the numbers say", "Surprises" and "Interview story".


## Step 7: The guide (AC-7)

1. Open `docs/learning-guide.md`, section `## 23. Live updates with Mercure`, and the section 16 code map.

Expected:
- Section 23 explains the hub inside Caddy, publish vs subscribe, private topics and JWT claims, the measured numbers (at most 1 of 14 threads busy at 1000 subscribers, health p95 16 ms, vs 10 000 ms for SL-09), keys via process env vs `.env`, and EventSource reconnect and the give-up rule.
- The links to code, the Caddyfile, scripts, results, mercure.rocks, frankenphp.dev and MDN open.
- Section 16 has a row "Notify the browser when something changes" pointing to section 23.
