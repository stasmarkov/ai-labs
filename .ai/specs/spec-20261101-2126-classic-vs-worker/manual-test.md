# Manual test plan: classic vs worker mode (SL-08)

Run from the project root (`/Users/stasmarkov/work/interview`).

## Step 1: expectations were committed first

```sh
git log --oneline -- BENCHMARKS.md | head -5
```

Expected: the commit adding "Expectations (classic vs worker)" is older than the commit with the SL-08 tables.

## Step 2: worker counts change and restore

```sh
ddev server frankenphp-octane
bench/concurrency.sh 8 && bench/concurrency.sh --show
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
bench/concurrency.sh default && bench/concurrency.sh --show
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
```

Expected: `8 workers, max-requests 1000000` and `8`; then `4 workers, max-requests 500` and `4`. Repeat on frankenphp-worker.

## Step 3: defaults restored after the runs

```sh
for s in frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  bench/concurrency.sh --show
  mkdir -p /tmp/sl08 && BENCH_PARITY_DIR=/tmp/sl08 bench/parity.sh capture >/dev/null && bench/parity.sh compare $s /tmp/sl08/$s.txt
done
ddev server nginx-fpm
grep -E '^(APP_ENV|DEBUG_ROUTES)=' .env
git diff --exit-code bench/results/2026-11-01 && echo sl06-untouched
```

Expected: `4 workers, max-requests 500` twice; `identical` twice; `.env` at its pre-run values; `sl06-untouched`.
