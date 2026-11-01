# Manual test plan: Octane state-leak lab (SL-07)

App: https://interview.ddev.site. Everything below runs from the project root. No login and no seeded data are needed; the lab does not touch the database.

## Preconditions

- DDEV is installed and the project can start (`ddev start`).
- You start on the default server, nginx-fpm (`ddev server` shows it as active).
- `.env` has no `DEBUG_ROUTES=true` line, or has `DEBUG_ROUTES=false`.
- The commands use `curl -sk`; drop `-k` if your machine trusts the DDEV certificate.

Rollback (run this at the end, or any time something goes wrong):

```sh
sed -i.bak '/^DEBUG_ROUTES=/d' .env && rm -f .env.bak   # flag off again
ddev server nginx-fpm                                   # back to the default server
git status --short .ddev .env.bench-backup              # overlay config.zzz-bench-leaks.yaml and .env.bench-backup must not exist
ddev artisan config:clear
```

## Step 1: start the project on nginx-fpm

Action:

```sh
ddev start
ddev server
```

Expected: the project starts; `ddev server` marks `nginx-fpm` as the active server.

## Step 2: the automated suite is green (MNC-3)

Action:

```sh
ddev artisan test --compact
```

Expected: all tests pass (last run: 260 passed, 0 failed).

## Step 3: note what an unknown path returns

Action:

```sh
curl -sk -i https://interview.ddev.site/no-such-path
```

Expected: `HTTP/2 404` and the body `{"success":false,"status":404,"message":"Not found","errors":{}}`. Keep this in mind for the next step.

## Step 4: with the flag off, `/debug/*` looks like an unknown path and nothing existing changed (MNC-1)

Action (flag still off):

```sh
for p in /debug/worker "/debug/leaks/dealer-scope?dealer=1" /debug/leaks/dealer-scope /debug/runtime; do
  echo "== $p"; curl -sk -i "https://interview.ddev.site$p" | sed -n '1p;$p'
done
curl -sk -o /dev/null -w "up: %{http_code}\n" https://interview.ddev.site/up
git status --short bench/parity
```

Expected:

- Every `/debug/...` path prints `HTTP/2 404` and exactly the body from step 3 (`"message":"Not found"`), including the one with no query string (so validation does not run first).
- `up: 200`.
- `git status --short bench/parity` prints nothing: the parity snapshots are untouched.
- Optional: `ddev exec bench/parity.sh` (if you normally use it) reports no differences.

## Step 5: turn the lab on

Action:

```sh
grep -q '^DEBUG_ROUTES=' .env && sed -i.bak 's/^DEBUG_ROUTES=.*/DEBUG_ROUTES=true/' .env || echo 'DEBUG_ROUTES=true' >> .env
rm -f .env.bak
ddev artisan config:clear
curl -sk -i https://interview.ddev.site/debug/worker | sed -n '1p'
```

Expected: `HTTP/2 200`.

## Step 6: happy path on a classic server, one scenario at a time (preamble, AC-1 to AC-4, AC-7)

On nginx-fpm every request boots the app from scratch, so nothing may leak. Each response is the usual envelope with `data.requested`, `data.observed` and `data.leaked`.

Action (run A, then B, for each scenario; leak mode is the default):

```sh
B=https://interview.ddev.site/debug/leaks
# AC-1 dealer-scope
curl -sk "$B/dealer-scope?dealer=1&locale=uk&mode=leak"; echo
curl -sk "$B/dealer-scope?dealer=2&locale=de&mode=leak"; echo
# AC-2 price-cache
curl -sk "$B/price-cache?dealer=1&locale=uk&mode=leak"; echo
curl -sk "$B/price-cache?dealer=2&locale=de&mode=leak"; echo
# AC-3 number-locale (B has no locale)
curl -sk "$B/number-locale?dealer=1&locale=uk&mode=leak"; echo
curl -sk "$B/number-locale?dealer=2&mode=leak"; echo
# AC-4 request-at-boot
curl -sk "$B/request-at-boot?dealer=1&locale=uk&mode=leak"; echo
curl -sk "$B/request-at-boot?dealer=2&locale=de&mode=leak"; echo
```

Expected (status 200 on all; look at the `data` object):

| Request B | requested | observed | leaked |
|-----------|-----------|----------|--------|
| dealer-scope | 2 | 2 | false |
| price-cache | de | de | false |
| number-locale | en | en | false (`config_locale` and `app_locale` also `en`) |
| request-at-boot | 2 | 2 | false |

Then repeat the same eight requests with `mode=fixed`: again `leaked` is `false` everywhere. Price-cache in fixed mode returns `cache_entries` as `null`.

Defaults check: `curl -sk "$B/dealer-scope?dealer=3"` returns 200 with `requested` 3, `observed` 3 (mode defaults to leak, locale to en). `curl -sk "$B/price-cache?dealer=1"` returns `formatted` containing `71` (amount defaults to 7150300 cents).

## Step 7: bad input and unknown scenario (preamble)

Action:

```sh
B=https://interview.ddev.site/debug/leaks
curl -sk -i "$B/dealer-scope?dealer=0" | sed -n '1p;$p'
curl -sk -i "$B/dealer-scope?dealer=1&locale=fr" | sed -n '1p;$p'
curl -sk -i "$B/dealer-scope?dealer=1&mode=sideways" | sed -n '1p;$p'
curl -sk -i "$B/price-cache?dealer=1&amount=abc" | sed -n '1p;$p'
curl -sk -i "$B/bogus?dealer=1" | sed -n '1p;$p'
```

Expected:

- The first four print `HTTP/2 422` and a body with `"success":false`, `"message":"Validation failed"` and an `errors` object naming the bad field (`dealer`, `locale`, `mode`, `amount`).
- The last one prints `HTTP/2 404` with `"message":"Not found"`.

## Step 8: `/debug/worker` (AC-5)

Action:

```sh
curl -sk https://interview.ddev.site/debug/worker; echo
curl -sk https://interview.ddev.site/debug/worker; echo
```

Expected: 200, and `data` holds `pid`, `app_id`, `memory_bytes`, `peak_memory_bytes`, `worker_hits`, `first_seen_at`, `octane` (plus an informational `worker_id`). On nginx-fpm `octane` is `false` and `worker_hits` is `1` both times, because each request is a fresh process.

## Step 9: the live checker on the classic server (AC-8)

Action:

```sh
bench/leaks.sh
```

Expected: eight rows (4 scenarios x leak/fixed), every one ending `leaked 0/20`, no errors. Afterwards:

```sh
grep -c '^DEBUG_ROUTES=' .env; ls .env.bench-backup 2>&1 | tail -1
curl -sk -o /dev/null -w "%{http_code}\n" https://interview.ddev.site/debug/worker
```

`.env` is back to its pre-script state (the flag line from step 5 is gone if it was not there before the script ran, otherwise restored), `.env.bench-backup` does not exist, and `/debug/worker` answers `404` again. Re-do step 5 before continuing.

## Step 10: the reported problem, a leak on a worker server (AC-1 to AC-4, AC-6)

This is the case the lab exists for: the same worker serves A and then B.

Action:

```sh
ddev server frankenphp-octane
# (step 5 again so the flag is on)
grep -q '^DEBUG_ROUTES=' .env && sed -i.bak 's/^DEBUG_ROUTES=.*/DEBUG_ROUTES=true/' .env || echo 'DEBUG_ROUTES=true' >> .env; rm -f .env.bak
ddev artisan octane:reload --server=frankenphp
B=https://interview.ddev.site/debug/leaks
run() { # scenario mode
  for i in $(seq 20); do curl -sk "$B/$1?dealer=1&locale=uk&mode=$2" >/dev/null; done
  for i in $(seq 20); do
    if [ "$1" = number-locale ]; then curl -sk "$B/$1?dealer=2&mode=$2"; else curl -sk "$B/$1?dealer=2&locale=de&mode=$2"; fi; echo
  done | grep -c '"leaked":true'
}
for s in dealer-scope price-cache number-locale request-at-boot; do
  for m in leak fixed; do ddev artisan octane:reload --server=frankenphp >/dev/null; sleep 5; echo "$s $m leaked $(run $s $m)/20"; done
done
```

Expected: every `leak` line reports a high count (documented result: `20/20`; at least 1 is a pass, since which worker serves B is not under your control) and every `fixed` line reports `leaked 0/20`.

Look at one leaking response by hand to read it:

```sh
ddev artisan octane:reload --server=frankenphp; sleep 5
curl -sk "$B/dealer-scope?dealer=1&locale=uk&mode=leak" >/dev/null   # repeat a few times so all workers see A
curl -sk "$B/dealer-scope?dealer=2&locale=de&mode=leak"; echo
```

Expected: `requested` 2, `observed` 1, `leaked` true. For the other scenarios in leak mode: price-cache shows `observed` `uk` while `requested` is `de` (and `cache_entries` of 1 or more); number-locale (B without locale) shows `requested` `en`, `observed` `uk`, with `config_locale` and `app_locale` back at `en`; request-at-boot shows `observed` null and `leaked` true.

## Step 11: worker counter under Octane (AC-5)

Action:

```sh
for i in 1 2 3; do curl -sk https://interview.ddev.site/debug/worker; echo; done
```

Expected: `octane` is `true`; the same `pid` and `first_seen_at` show up again, and `worker_hits` goes up on the worker that answers (it can vary between workers if the pool has more than one, but never goes down for a given `pid`).

## Step 12: why `--max-requests` hides leaks (AC-8, AC-9)

Action:

```sh
bench/leaks.sh --max-requests 1
```

Expected: dealer-scope, price-cache and number-locale rows (leak and fixed) read `leaked 0/20`; request-at-boot leak reads `leaked 20/20`; request-at-boot fixed reads `0/20`. When it finishes, `ls .ddev/config.zzz-bench-leaks.yaml` reports no such file and `.env` / `.env.bench-backup` are restored. `bench/leaks.sh --max-requests 5` on a non-Octane server must refuse with exit code 2 (check on nginx-fpm after step 13).

## Step 13: back to the classic server

Action:

```sh
sed -i.bak '/^DEBUG_ROUTES=/d' .env && rm -f .env.bak
ddev server nginx-fpm
ddev artisan config:clear
bench/leaks.sh --max-requests 5; echo "exit $?"
curl -sk -o /dev/null -w "%{http_code}\n" https://interview.ddev.site/debug/worker
```

Expected: the server is nginx-fpm; `leaks.sh --max-requests 5` prints a message that it only works with frankenphp-octane and `exit 2` (and does not change `.env`); `/debug/worker` is `404`.

## Step 14: leak table in BENCHMARKS.md (AC-9)

Action: open `BENCHMARKS.md`, section "State leaks".

Expected: a table with one row per scenario x mode (8 rows) and the columns nginx-fpm, frankenphp-octane (max-requests 500), frankenphp-octane (max-requests 1), frankenphp-worker. Every fixed row and every nginx-fpm cell is `0/20`; leak rows read `20/20` on both worker servers at max-requests 500; max-requests 1 is `0/20` except request-at-boot leak (`20/20`), and the bullets under the table explain why.

## Step 15: learning guide section (AC-10)

Action: open `docs/learning-guide.md`, section "7. State leaks in worker mode" (anchor `#state-leaks`).

Expected, each present: what lives across requests in a worker; what Octane resets, naming `CreateConfigurationSandbox`, `FlushLocaleState` and `GiveNewRequestInstanceToApplication`; each of the four leaks with its fix; why `--max-requests` hides leaks without fixing them; how to read `/debug/worker`; the note that a leak-mode number-locale request changes the worker's process-wide Number locale until restart (MNC-2 exception); working links to the lab code (`app/Debug/Leaks/`), the tests, the leak table and the Octane docs. Click each link; none may 404.

## Step 16: lab code stays out of normal routes (MNC-2)

Action, with the flag off:

```sh
ddev artisan route:list --path=debug
ddev artisan tinker --execute 'echo json_encode([app()->bound("lab.dealer.leak"), app()->bound("lab.dealer.fixed"), app()->bound("lab.boot-request.leak"), app()->bound("lab.boot-request.fixed")]);'
```

Expected: the routes exist (`debug.leaks`, `debug.worker`, and the earlier debug routes) but the tinker line prints `[false,false,false,false]`: the lab provider registers nothing while `DEBUG_ROUTES` is off.

## Final check

Run the rollback block at the top. The project should end on nginx-fpm with `DEBUG_ROUTES` off.
