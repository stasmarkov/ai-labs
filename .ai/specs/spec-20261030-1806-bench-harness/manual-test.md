# Manual test plan: benchmark harness (SL-06)

Run from the project root (`/Users/stasmarkov/work/interview`). Close heavy apps first; the machine is under load during runs.

## Preconditions

- Project on nginx-fpm, default concurrency, clean git tree, seeded database.
- Docker (OrbStack) running.

## Rollback

```sh
bench/concurrency.sh default
ddev server nginx-fpm
cp .env.bench-backup .env 2>/dev/null || true   # only if a run was killed
ddev artisan optimize:clear
```

## Step 1: expectations were committed first

```sh
git log --oneline -- BENCHMARKS.md | head -5
```

Expected: a commit adding "Expectations" appears before (below) the commit adding the result tables.

## Step 2: one run end to end

```sh
bench/run.sh ping --quick
ls bench/results/*/nginx-fpm/
grep -c DEBUG_ROUTES .env
```

Expected: one result file with RPS, p50/p95/p99, error %, memory, CPU, setting, commit and tool versions; `.env` back to its pre-run content (`DEBUG_ROUTES` count as before).

## Step 3: fair and tuned settings

```sh
bench/concurrency.sh 16 && ddev exec 'grep max_children /etc/php/8.4/fpm/pool.d/*.conf'
bench/concurrency.sh default
```

Expected: `max_children = 16`, then back to 8. In BENCHMARKS.md every fair row says 8 workers, every tuned row names the winning value from 8/16/32.

## Step 4: timeouts

```sh
curl -s -o /dev/null -w '%{http_code} %{time_total}\n' https://interview.ddev.site/debug/sleep/30   # with DEBUG_ROUTES=true
```

Expected: matches the timeout table row for the active server and setting; the table explains which layer ended the request.

## Step 5: everything restored

```sh
ddev server --current
bench/concurrency.sh --show
grep -E '^(APP_ENV|APP_DEBUG|DEBUG_ROUTES)=' .env
mkdir -p /tmp/sl06 && BENCH_PARITY_DIR=/tmp/sl06 bench/parity.sh capture && bench/parity.sh compare nginx-fpm /tmp/sl06/nginx-fpm.txt
git diff --exit-code .ddev/config.yaml && echo untouched
```

Expected: `nginx-fpm`; default concurrency; pre-run `.env` values; `identical`; `untouched`.
