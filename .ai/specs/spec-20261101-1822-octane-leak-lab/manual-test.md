# Manual test plan: Octane state-leak lab

Run from the project root (`/Users/stasmarkov/work/interview`).

## Preconditions

- Project on nginx-fpm, seeded database.

## Rollback

```sh
ddev server nginx-fpm
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
```

## Step 1: see a leak by hand on Octane

```sh
ddev server frankenphp-octane
echo DEBUG_ROUTES=true >> .env && ddev artisan octane:reload --server=frankenphp
for i in $(seq 1 10); do curl -s 'https://interview.ddev.site/debug/leaks/dealer-scope?dealer=1&mode=leak' >/dev/null; done
curl -s 'https://interview.ddev.site/debug/leaks/dealer-scope?dealer=2&mode=leak' | jq '.data'
curl -s 'https://interview.ddev.site/debug/leaks/dealer-scope?dealer=2&mode=fixed' | jq '.data'
curl -s https://interview.ddev.site/debug/worker | jq '.data'
```

Expected: leak mode shows `observed` dealer 1 for a dealer 2 request and `leaked: true` (if that worker served dealer 1 before); fixed mode `leaked: false`; `/debug/worker` shows a stable `pid` and growing `worker_hits` across calls to the same worker.

## Step 2: live checker on every server

```sh
bench/leaks.sh                      # on frankenphp-octane
bench/leaks.sh --max-requests 1     # same, workers restart after every request
ddev server frankenphp-worker && bench/leaks.sh
ddev server nginx-fpm && bench/leaks.sh
```

Expected: leak rows show leaks on the two worker servers at max-requests 500; every cell is `0/20` on nginx-fpm and with max-requests 1; every fixed row is `0/20`.

## Step 3: docs

```sh
grep -n "State leaks" docs/learning-guide.md
grep -n "leak" BENCHMARKS.md | head
```

Expected: the guide section and the leak table exist with links.

## Step 4: restore and normal behaviour

```sh
ddev server nginx-fpm
sed -i '' '/^DEBUG_ROUTES=/d' .env && ddev artisan optimize:clear
curl -s -o /dev/null -w '%{http_code}\n' 'https://interview.ddev.site/debug/leaks/dealer-scope?dealer=1'
mkdir -p /tmp/sl07 && BENCH_PARITY_DIR=/tmp/sl07 bench/parity.sh capture && bench/parity.sh compare nginx-fpm /tmp/sl07/nginx-fpm.txt
```

Expected: `404`; `identical`.
