# Manual test plan: classic vs worker mode (SL-08)

Everything runs from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://ai-lab.ddev.site.

## Preconditions

- DDEV is running (`ddev start`). This plan starts on nginx-fpm on purpose: run `ddev server nginx-fpm`, then `ddev server --current` prints `nginx-fpm`.
- The git tree is clean apart from `bench/results` and `.ai/session` (`git status --porcelain -- . ':!bench/results' ':!.ai/session'` prints nothing). `bench/run.sh` and `bench/memory.sh` refuse a dirty tree.
- `.env` has its normal values. Note them: `grep -E '^(APP_ENV|APP_DEBUG|DEBUG_ROUTES)=' .env` (normally `APP_ENV=local`, `APP_DEBUG=true`).
- Pick a results folder that does not exist yet and use it in every run step: `export BENCH_DATE=2026-11-01-classic-vs-worker`. Today's date is 2026-11-01, which is the SL-06 folder, so never run the suite or memory script without this variable.
- Steps 5 and 6 take hours. Do not use the machine for anything else while they run (BR-20 run conditions).

Rollback for any aborted run: `bench/concurrency.sh default && ddev server default`, then restore `.env` from `.env.bench-backup` if that file exists (`mv .env.bench-backup .env`), then `git checkout -- bench/results/2026-11-01` if SL-06 files changed.

## Step 1: expectations were committed first (AC-4)

```sh
git log --reverse --format='%h %ad %s' --date=iso -- BENCHMARKS.md | tail -5
grep -n 'Expectations (classic vs worker)' BENCHMARKS.md
sed -n '/## Expectations (classic vs worker)/,/^## How the numbers work/p' BENCHMARKS.md
```

Expected: the heading is found; the section predicts ping at least 2x frankenphp classic, search at most 1.3x, case A linear growth, case B and both classic cases within 5 KiB per 1 000 requests, and case C dropping about every 500 requests. The commit that added it (`c82f462`) is older than the commit that adds any SL-08 result files or tables (check again after step 9).

## Step 2: frankenphp-octane worker count, recycling and default (AC-1)

```sh
ddev server frankenphp-octane
bench/concurrency.sh 8
bench/concurrency.sh --show
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
bench/concurrency.sh default
bench/concurrency.sh --show
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
git status --short .ddev
```

Expected, in order: `8 workers, max-requests 1000000`, `8`, then `4 workers, max-requests 500`, `4`. `git status --short .ddev` prints nothing (overlays are gitignored).

## Step 3: frankenphp-worker worker count, recycling and default (AC-1)

```sh
ddev server frankenphp-worker
bench/concurrency.sh 8
bench/concurrency.sh --show
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
bench/concurrency.sh default
bench/concurrency.sh --show
ddev exec curl -s localhost:2019/frankenphp/threads | grep -c 'Worker PHP Thread'
git status --short .ddev
```

Expected: same four outputs as step 2 (`8 workers, max-requests 1000000`, `8`, `4 workers, max-requests 500`, `4`) and nothing from `git status`. Then `ddev server default`.

## Step 4: the suite refuses to overwrite an existing results folder (reported failing case)

The reported problem: with today's date being the SL-06 date, a run without a fresh `BENCH_DATE` would overwrite `bench/results/2026-11-01`. This must now be refused before anything changes.

```sh
unset BENCH_DATE
bench/suite.sh --servers frankenphp,frankenphp-octane,frankenphp-worker; echo "exit=$?"
bench/memory.sh; echo "exit=$?"
ddev server --current
git diff --exit-code bench/results/2026-11-01 && echo sl06-untouched
export BENCH_DATE=2026-11-01-classic-vs-worker
```

Expected: both commands print a message naming the existing folder and telling you to set `BENCH_DATE`, with `exit=2`; `ddev server --current` still prints `nginx-fpm` (no server was switched); `sl06-untouched`. If the suite starts switching servers instead, press Ctrl-C and use the rollback note above, since this check has failed.

## Step 5: classic vs worker suite run (AC-2)

```sh
export BENCH_DATE=2026-11-01-classic-vs-worker
bench/suite.sh --servers frankenphp,frankenphp-octane,frankenphp-worker
ls bench/results/$BENCH_DATE
ddev server --current
bench/concurrency.sh --show
```

Expected: the run goes through frankenphp first (baseline), then frankenphp-octane, then frankenphp-worker, each on ping and search with fair (8 workers, no recycling), sweep 8/16/32 and tuned. The folder lists `frankenphp-ping-fair.json`, `frankenphp-ping-tuned.json`, `frankenphp-search-fair.json`, `frankenphp-search-tuned.json` and the same four for `frankenphp-octane` and `frankenphp-worker` (12 files), and no `timeouts-*.json`. In the JSON, frankenphp has ratio 1.0 against itself; the other two reference frankenphp as `baseline_server`. At the end `ddev server --current` prints `nginx-fpm` and `--show` prints `4 workers, max-requests 500` (ignore the worker line when nginx-fpm is active; the script restores defaults on exit). Check each fair file: `php -r 'echo json_encode(json_decode(file_get_contents("bench/results/$BENCH_DATE/frankenphp-ping-fair.json"))->baseline_server);'` (shell expands `$BENCH_DATE` only inside double quotes, so run it in the shell, not in single quotes, if it prints empty).

Known mismatch to watch: if the baseline of a list without frankenphp matters to you, note that task 12 in tasks.md is still open; for the list above the baseline is frankenphp either way.

## Step 6: memory curves (AC-3)

```sh
export BENCH_DATE=2026-11-01-classic-vs-worker
ddev server frankenphp-octane && bench/memory.sh
ddev server frankenphp-worker && bench/memory.sh
ddev server frankenphp        && bench/memory.sh
ddev server default
ls bench/results/$BENCH_DATE/memory-*.json
grep -E '^(APP_ENV|APP_DEBUG|DEBUG_ROUTES)=' .env
ls .env.bench-backup 2>&1
```

Expected: three files `memory-frankenphp-octane.json`, `memory-frankenphp-worker.json`, `memory-frankenphp.json`. Octane and worker files hold cases A, B, C; the classic file holds A and B only. Each case has 101 samples (requests 0, 100, ... 10000) with `memory_bytes`, `worker_hits`, `first_seen_at`, plus `restarts` and `growth_kib_per_1000`. Spot check:

```sh
php -r '$d=json_decode(file_get_contents("bench/results/$BENCH_DATE/memory-frankenphp-worker.json"),true); foreach($d["cases"] as $c){printf("%s %s restarts=%d growth=%.1f samples=%d\n",$c["case"],$c["mode"],$c["restarts"],$c["growth_kib_per_1000"],count($c["samples"]));}'
```

Expected shape: case A restarts 0 and clearly positive growth; B restarts 0 and growth within 5; C restarts about 20 (10 000 / 500). `.env` is back to the values you noted, `ls .env.bench-backup` says "No such file", and you can browse https://ai-lab.ddev.site/up afterwards without a debug page.

## Step 7: ping and search tables (AC-5)

```sh
php bench/summary.php --tables=bench/results/$BENCH_DATE
grep -n 'frankenphp-octane\|frankenphp-worker' BENCHMARKS.md | head
```

Expected: ping and search tables with fair and tuned rows for the three FrankenPHP servers, BR-21 columns, spread and ratio against frankenphp classic, and the same lines (verbatim) present in BENCHMARKS.md.

## Step 8: memory table (AC-6)

```sh
php bench/summary.php --tables=bench/results/$BENCH_DATE | grep -i -A20 memory
grep -n -i 'growth_kib_per_1000\|KiB per 1000' BENCHMARKS.md
```

Expected: one row per server and case with memory at requests 0, 1 000, 5 000 and 10 000, restarts, highest memory reached and growth_kib_per_1000; the rows are in BENCHMARKS.md. Compare against step 1: A grows, B and the classic cases stay under 5 KiB per 1 000, C shows restarts.

## Step 9: explanation and interview story (AC-7)

```sh
grep -n 'Surprises (classic vs worker)\|bootstrap' BENCHMARKS.md
sed -n '/Surprises (classic vs worker)/,/^## /p' BENCHMARKS.md
```

Expected: for ping and search a bootstrap share equal to `1 - worker p50 / classic p50` at the fair setting, with numbers you can recompute from the fair files (`p50` of frankenphp vs the worker server). Every prediction from step 1 that missed is explained. A story of 3 to 6 sentences with at least one number from the tables. Also confirm order: `git log --format='%h %s' -- BENCHMARKS.md bench/results` shows the Expectations commit before the first SL-08 result commit.

## Step 10: learning guide section (AC-8)

```sh
grep -n 'Classic vs worker mode: what the numbers say' docs/learning-guide.md
sed -n '/Classic vs worker mode: what the numbers say/,/^## /p' docs/learning-guide.md
```

Expected: the section covers why bootstrap matters more for a tiny request, how to read the memory curve, why `MAX_REQUESTS=0` is not unlimited in Octane's worker script, and when recycling is a fix versus hiding a leak. It links to `https://laravel.com/docs/13.x/octane`, `https://frankenphp.dev/docs/worker/`, the scripts in `bench/`, and the tables in `BENCHMARKS.md`. Open both URLs in a browser and confirm they load.

## Step 11: SL-06 results untouched (MNC-1)

```sh
git diff --exit-code bench/results/2026-11-01 && echo sl06-untouched
git status --short bench/results/2026-11-01
ddev artisan test --compact --filter=BenchSuiteServersTest
```

Expected: `sl06-untouched`, no status lines, tests green. The suite without `--servers` still stops with `Active server is frankenphp-octane. The suite runs classic servers only.` when run from a worker server (check with `ddev server frankenphp-octane && bench/suite.sh --quick; echo $?` which prints that message and `2`; then `ddev server nginx-fpm`).

## Step 12: defaults restored and parity identical (MNC-2)

```sh
for s in frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  bench/concurrency.sh --show
  mkdir -p /tmp/sl08 && BENCH_PARITY_DIR=/tmp/sl08 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl08/$s.txt
done
ddev server frankenphp && bench/concurrency.sh --show; ddev server default
ddev server --current
grep -E '^(APP_ENV|APP_DEBUG|DEBUG_ROUTES)=' .env
ls .env.bench-backup 2>&1
git status --short .ddev
```

Expected: `4 workers, max-requests 500` for both worker servers; `identical` twice; frankenphp classic shows threads on auto; `nginx-fpm`; `.env` at the values you noted before step 2; no backup file; no `.ddev` changes.

## Step 13: tests and CI (MNC-3)

```sh
ddev artisan test --compact
ddev exec vendor/bin/pint --dirty --format agent
ddev exec vendor/bin/phpstan analyse
gh run list --limit 3
```

Expected: all tests pass (300 passed at the last check), Pint and PHPStan clean, and the latest CI run on the branch is green.
