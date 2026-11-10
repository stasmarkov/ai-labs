# Manual test plan: benchmark harness (spec-20261030-1806-bench-harness)

All commands run from the project root (`/Users/stasmarkov/work/interview`) on the Mac host unless a step says otherwise. The app is at https://ai-lab.ddev.site.

## Preconditions

- Docker and DDEV running: `ddev start`. Tools needed on the host: `jq`, `php`, `curl`, `git`.
- Begin on nginx-fpm on purpose, because the early steps read `nginx-fpm` result folders: run `ddev server nginx-fpm`, then `ddev server --current` prints `nginx-fpm` (the suite refuses `frankenphp-octane` and `frankenphp-worker`). Step 5 puts the project back on the default server.
- Quiet machine: close other heavy apps. A full run takes hours (4 servers x 2 scenarios x fair/sweep/tuned x 3 x 90 s, plus the timeout runs). For a smoke test use `--quick` or `BENCH_WARMUP=5 BENCH_MEASURE=15 BENCH_REPEATS=1`.
- Commit order: harness scripts (`bench/*.sh`, `bench/summary.php`, `bench/k6-search.js`, `DebugSleepController`) and the Expectations section must be committed first. Real runs refuse a dirty tree (only `bench/results/` may change).
- Note the starting state to compare later: `cp .env /tmp/env.before; git status --short > /tmp/status.before; ddev server --current`.

## Rollback

If anything is interrupted or looks wrong:

```sh
bench/concurrency.sh default
bench/timeouts.sh apply defaults
ddev server default
test -f .env.bench-backup && mv .env.bench-backup .env
ddev artisan optimize:clear
diff .env /tmp/env.before && echo restored
```

The harness refuses to start while a stale `.env.bench-backup` exists, so the `mv` above is the fix.

## Step 1: Expectations were committed before any result (AC-10)

1. Run `git log --format='%h %ad %s' --date=short --reverse -- BENCHMARKS.md bench/results`.
2. Expected: the commit that adds the Expectations section (currently `8e38d62 docs(bench): write expectations before any full run`) is listed before the first commit that touches `bench/results/`.
3. Open `BENCHMARKS.md` and read `## Expectations`. Expected: one statement per scenario, for example ping (all four close at fair settings) and search (DB-bound, server differences shrink).
4. Open `## Surprises`. Expected, once results exist: every result that differs from an expectation is explained there (not only the note about the `~` wording).

## Step 2: One scenario run writes one result file per run (AC-1)

1. Run `bench/run.sh ping`. It takes about 9 minutes (3 repeats of 30 s warm-up plus 60 s measure, plus 10 s settle).
2. Expected output ends with `Wrote 3 result file(s) to bench/results/raw/<date>/nginx-fpm (spread X.X%)` followed by three file paths. If the spread is above 5 % you first see `Spread ...% is above 5%. Discarding set 1 and rerunning once.` and then 3 more files.
3. Run `jq . $(ls -t bench/results/raw/*/nginx-fpm/* | head -1)`.
4. Expected fields in the file: server `nginx-fpm`, scenario `ping`, the setting (`default`, or `8 workers` when set), the short commit hash of `git rev-parse --short HEAD`, the oha image tag and digest, tool versions, rps, p50, p95, p99, error percent (near 0), idle memory, peak memory and average CPU for the web container and for db, `connections` 50, warm-up 30 and measure 60.
5. Repeat with `bench/run.sh search`. Expected: the same fields, produced by k6 (image `grafana/k6`), and the run is not refused for make=BMW pages.
6. Memory sampling check: the memory values should be plausible (web container tens to a few hundred MB, peak at or above idle). Note: samples are meant every 1 s and only inside the measure window (open item, task 17).

## Step 3: Fair 8 workers and tuned sweep (AC-5)

1. Fair setting: `bench/concurrency.sh 8` then `bench/concurrency.sh --show`. Expected: `8 workers`.
2. On nginx-fpm check the pool: `ddev exec grep -E '^(pm|pm.max_children|pm.max_requests) ' /etc/php/8.4/fpm/pool.d/*.conf` (adjust the path if different). Expected: `pm = static`, `pm.max_children = 8`, `pm.max_requests = 0`.
3. Sweep: `bench/suite.sh --quick` is a short version. For the real thing run `bench/suite.sh`. Expected in the output: sweep runs labelled `sweep-8`, `sweep-16`, `sweep-32` per server and scenario, then a `tuned` run using the winning setting.
4. Pick rule check: the winner is the highest RPS with error % at most 1; a setting within 3 % of the best loses to the lower setting; if all exceed 1 % errors, fewest errors wins. Look at `jq '{label,setting,rps:.median.rps}' bench/results/<date>/nginx-fpm-ping-*.json` and confirm by eye.
5. Expected: the tuned row exists as its own full run even if the winner is 8.
6. Finish: `bench/concurrency.sh default; bench/concurrency.sh --show` prints `default`.

## Step 4: Timeout table (AC-9)

1. For each server in `nginx-fpm`, `apache-fpm`, `openlitespeed`, `frankenphp`: `ddev server <name>` then `bench/timeouts.sh run`. Each takes a few minutes (sleep 30 sent three times with defaults and three times with 10 s limits).
2. Expected output per request: status, seconds, Server header and the layer that ended the request. Expected file: `bench/results/<date>/timeouts-<server>.json`.
3. Expected with defaults: `200` after about 30 s (the PHP max_execution_time of 30 s does not stop `sleep()` under FPM because it counts CPU time). Expected with 10 s: a gateway error (for example 502 or 504) near 10 s from the FPM or proxy layer on FPM servers; a PHP "Maximum execution time" ending near 10 s on FrankenPHP (wall-clock timers in thread-safe PHP). Record what you actually see.
4. Known open problem: on nginx-fpm the short run ended with 502 from FPM at about 13 s because the location-level `fastcgi_read_timeout` overrides the overlay. If you see this, the layer column should say so, or the overlay needs fixing (task 16).
5. Open `BENCHMARKS.md`. Expected: a timeout table with all four servers, defaults and 10 s rows, columns HTTP status, time, ending layer, and the short note about CPU time versus wall-clock. Any result that contradicts the note must be in Surprises.
6. Restore: `bench/timeouts.sh apply defaults; ddev server default`.

## Step 5: Project is back to normal after the runs (MNC-1, MNC-2, MNC-4)

1. Run `bench/concurrency.sh default && bench/timeouts.sh apply defaults && ddev server default`.
2. State check: `ddev server --current` prints the name `ddev server --default` prints (`frankenphp`); `diff .env /tmp/env.before` prints nothing; `ls .env.bench-backup` says no such file; `grep DEBUG_ROUTES .env` is empty or `false`; `ls .ddev/config.zz-bench-* .ddev/docker-compose.bench-* .ddev/web-entrypoint.d/zz-bench-* 2>&1` shows no such files.
3. MNC-1: `git diff --exit-code .ddev/config.yaml; echo $?` prints `0`.
4. MNC-4 route check: `curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/debug/sleep/1` prints `404`.
5. MNC-2 parity on nginx-fpm: `ddev server nginx-fpm && bench/parity.sh capture`, then `bench/parity.sh compare nginx-fpm bench/parity/nginx-fpm.txt` is trivially the same file, so instead check `git diff --stat bench/parity/` after capture. Expected: no change to the committed snapshots.
6. Repeat on FrankenPHP: `ddev server frankenphp && bench/parity.sh capture && git diff --stat bench/parity/ && ddev server default`. Expected: no diff. Also `bench/parity.sh compare nginx-fpm frankenphp` prints `identical`.
7. Expected: `git status --short` matches `/tmp/status.before` plus only new files under `bench/results/` and the docs.

## Step 6: Run conditions are enforced (BR-20)

Each case must exit with code 2, print `Run condition failed: <check>`, and send no load. Use `--quick`.

1. Dirty tree: `touch scratch.txt; bench/run.sh ping --quick; echo $?`. Expected: `Run condition failed: git working tree has changes outside bench/results/` and `2`. Then `rm scratch.txt`.
2. Wrong environment: not directly reachable because the harness writes .env itself; to check the behaviour read `.env` during a run in a second terminal: `grep -E '^(APP_ENV|APP_DEBUG|DEBUG_ROUTES)=' .env`. Expected during a run: `APP_ENV=production`, `APP_DEBUG=false`, `DEBUG_ROUTES=true`.
3. Caches: in a second terminal during the settle period run `ddev exec ls bootstrap/cache`. Expected: `config.php` and a routes file present. To force a refusal, run `ddev artisan optimize:clear` in the second terminal right after `bench/run.sh ping --quick` prints its first line; Expected refusal `config cache is not built` (timing dependent; repeat if it slips through).
4. OPcache and Xdebug: `ddev xdebug on`, then `bench/run.sh ping --quick; echo $?`. Expected: `Run condition failed: Xdebug is not off for the serving SAPI` and `2`. Then `ddev xdebug off`. For OPcache, check `curl -sk https://ai-lab.ddev.site/debug/runtime` while a run is active (DEBUG_ROUTES is on then); expected `opcache_enabled: true`, `xdebug_loaded: false`.
5. Restore on failure: after each refusal run `diff .env /tmp/env.before` (nothing) and `ls .env.bench-backup` (not found).
6. Restore on Ctrl-C: start `bench/run.sh ping`, wait for the first load, press Ctrl-C. Expected: exit code 130, `.env` identical to `/tmp/env.before`, no `.env.bench-backup`, caches cleared (`ddev exec ls bootstrap/cache/config.php` fails).

## Step 7: Load model (50 connections, 30 s timeout, p99)

1. Start `bench/run.sh ping --quick` and, in a second terminal, run `docker ps --format '{{.Image}} {{.Command}}'` while it loads. Expected: a `ghcr.io/hatoo/oha:<tag>` container whose command includes `-c 50`, `-t 30s` and `-k`.
2. Same for `bench/run.sh search --quick`. Expected: a `grafana/k6:<tag>` container.
3. `grep -nE "vus|timeout|insecureSkipTLSVerify|p\(99\)" bench/k6-search.js`. Expected: `vus: 50`, `timeout: '30s'`, `insecureSkipTLSVerify: true`, and `p(99)` in `summaryTrendStats`.
4. Search page rotation: `grep -n "page" bench/k6-search.js`. Expected: `make=BMW` and `(iteration mod 100) + 1` logic. Check data: `curl -sk 'https://ai-lab.ddev.site/listings?make=BMW&page=100' -o /dev/null -w '%{http_code}\n'` prints `200`.
5. Error %: a result file from a run with a stopped server should show a high `error_percent`; skip unless you want to prove it.

## Step 8: Traffic path (BR-22)

1. During a run: `docker inspect $(docker ps -q --filter ancestor=ghcr.io/hatoo/oha:1.12.1) --format '{{.HostConfig.NetworkMode}} {{.HostConfig.NanoCpus}} {{.HostConfig.ExtraHosts}}'`. Expected: `ddev_default`, `2000000000`, and `[ai-lab.ddev.site:<ip>]`.
2. Compare with the router: `docker inspect ddev-router --format '{{range .NetworkSettings.Networks}}{{.IPAddress}} {{end}}'`. Expected: the ip in step 1 appears in the list.
3. Result file: `jq '.images' bench/results/raw/*/nginx-fpm/* | head`. Expected: image tag plus `sha256:` digest.
4. `BENCHMARKS.md` "How the numbers work" mentions 7 CPUs shared and the 2-CPU cap.

## Step 9: Sleep endpoint (AC-8)

Happy path, with DEBUG_ROUTES on:

1. `printf '\nDEBUG_ROUTES=true\n' >> .env; ddev artisan config:clear`
2. `curl -sk -w '\n%{http_code}\n' https://ai-lab.ddev.site/debug/sleep/2`. Expected: after about 2 s, `200` and JSON containing `"slept_seconds":2`.
3. `curl -sk -w '\n%{http_code}\n' https://ai-lab.ddev.site/debug/sleep/0` and `/debug/sleep/121`. Expected: `422` with the error envelope ("Seconds must be between 1 and 120.").
4. `curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/debug/sleep/abc`. Expected: `404`.

Original case, switched off (must be indistinguishable from an unknown path):

5. `cp /tmp/env.before .env; ddev artisan config:clear`
6. `curl -sk -w '%{http_code}\n' https://ai-lab.ddev.site/debug/sleep/1` and `/debug/no-such-path`. Expected: both `404`, same body.

## Step 10: Concurrency script (AC-4)

1. `bench/concurrency.sh 16`, then `bench/concurrency.sh --show`. Expected: `16 workers`.
2. `bench/run.sh ping --quick --label check`, then `jq .concurrency <newest raw file>`. Expected: `16 workers` (open item: this is not yet asserted in tests).
3. Repeat the set/show/default cycle on each server: `ddev server apache-fpm`, `openlitespeed`, `frankenphp`. Expected: `--show` prints `<n> workers` after set and `default` after `default`; OLS ends with 8 or n children, FrankenPHP has `num_threads` n.
4. `bench/concurrency.sh default; bench/concurrency.sh --show`. Expected: `default`, and the overlay files from Step 5 are gone.

## Step 11: Spread, rerun, noisy, ratio rows (AC-2, AC-6)

1. `php bench/summary.php bench/results/raw/<date>/nginx-fpm --baseline=bench/results/raw/<date>/nginx-fpm`. Expected: one table row with median RPS, p50/p95/p99, error %, memory, CPU, spread, setting and ratio `1.00`.
2. To see the noisy path, start a CPU hog (`yes > /dev/null &` x4) during `bench/run.sh ping --quick`. Expected: a spread above 5 % causes the rerun message (full runs only) and a row with `noisy` and a `~` in the ratio. Kill the hogs with `killall yes`.
3. Open `BENCHMARKS.md`. Expected: ping and search tables, a fair row and a tuned row per server, columns for spread, setting, notes, and RPS ratio versus nginx-fpm of the same table.

## Step 12: Memory table and docs (AC-7, AC-11, AC-12)

1. `BENCHMARKS.md` memory table: idle and peak memory for each server (fair ping run); the OpenLiteSpeed row names the OLS container and lists the idle web container separately.
2. "How the numbers work" covers RPS, percentiles, error %, spread, the path load container, router, serving container, PHP worker, the wait queue per model (FPM listen backlog, LSAPI connection queue, FrankenPHP request queue) and why only ratios are compared.
3. Interview story: 3 to 6 sentences with at least one number that matches a table value.
4. `ddev artisan test --compact tests/Feature/BenchmarksDocTest.php` is green.

## Final check

`ddev artisan test --compact` is green, then the Rollback block above shows `restored`.
