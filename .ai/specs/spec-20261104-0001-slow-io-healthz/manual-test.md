# Manual test plan: slow I/O and healthz (SL-09)

Run everything from `/Users/stasmarkov/work/interview` on your Mac, with DDEV installed and Docker running. The app is at https://ai-lab.ddev.site.

## Before you start

- `ddev start`, then `ddev server nginx-fpm` (this plan starts on nginx-fpm on purpose, because step 4 expects it at its first command; steps 4 and 5 end with `ddev server default`, and the later steps run on any server).
- `ddev server --current` prints `nginx-fpm`.
- Listing 1 exists in the database (the slow endpoint looks up `/listings/1`). Quick check: `curl -s https://ai-lab.ddev.site/listings/1 -H 'Accept: application/json' | head -c 200` returns JSON, not a 404.
- For the bench steps (2, 3, 6): the `autopulse-oha:1.16.0` image exists locally (`docker image inspect autopulse-oha:1.16.0 >/dev/null && echo ok`), `jq` is installed, and for the sse workload the `grafana/k6:1.0.0` image can be pulled.
- Commit or stash your changes before a full (non-`--quick`) bench run. `--quick` skips the clean-tree check.

Rollback for anything that goes wrong in the bench steps: `bench/concurrency.sh default && ddev server default`, then `git checkout .env` only if `git diff .env` shows a leftover (the script restores `.env` itself on exit). If the stub is stopped, `docker start ddev-interview-vinstub` or `ddev restart`.

## Step 1

The slow endpoint, the health check and the stub (AC-1, AC-2, AC-3). Covers the reported problem: slow requests used to have no way to be tested, and a health check had to stay fast while they hold workers.

1. Stub answers after its delay, with no PHP:

   ```sh
   ddev restart
   docker ps --format '{{.Names}}  {{.Image}}  {{.Status}}' | grep vinstub
   ddev exec curl -s -w '\n%{time_total}s\n' http://vinstub:3000/vin/AP000000000000001
   ```

   Expected: a `ddev-interview-vinstub` line with image `node:24-alpine` and status `Up`. The curl prints `{"vin":"AP000000000000001","make_check":"ok","recalls":0,"source":"vinstub"}` and a time between 0.28s and 0.32s.

2. Happy path:

   ```sh
   curl -s -w '\nHTTP %{http_code} in %{time_total}s\n' -H 'Accept: application/json' https://ai-lab.ddev.site/listings/1/slow
   ```

   Expected: HTTP 200 in under 2 s, JSON with `"listing_id":1`, a `vin` string, `"vin_report":{"vin":"...","make_check":"ok","recalls":0,"source":"vinstub"}` and `"waited_ms"` of 300 or more (for example 319).

3. Unknown id is a 404 with no wait:

   ```sh
   curl -s -w '\nHTTP %{http_code} in %{time_total}s\n' -H 'Accept: application/json' https://ai-lab.ddev.site/listings/99999999/slow
   ```

   Expected: `{"success":false,"status":404,"message":"Not found","errors":{}}`, HTTP 404, well under 0.3 s.

4. Stub down gives 503:

   ```sh
   docker stop ddev-interview-vinstub
   curl -s -w '\nHTTP %{http_code} in %{time_total}s\n' -H 'Accept: application/json' https://ai-lab.ddev.site/listings/1/slow
   docker start ddev-interview-vinstub
   sleep 3
   curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/listings/1/slow
   ```

   Expected: `{"success":false,"status":503,"message":"VIN service unavailable.","errors":{}}` with HTTP 503 in under 2 s (no hang), then `200` after the restart.

5. Health check:

   ```sh
   curl -si https://ai-lab.ddev.site/healthz
   ```

   Expected: `HTTP/2 200`, a JSON content type, body `{"status":"ok"}`, and no `set-cookie` line anywhere in the headers.

6. Health check does no database work: stop the database and call it.

   ```sh
   docker pause ddev-interview-db
   curl -s -m 5 -w '\nHTTP %{http_code}\n' https://ai-lab.ddev.site/healthz
   docker unpause ddev-interview-db
   ```

   Expected: still `{"status":"ok"}` and HTTP 200 within 5 s. The database is running again afterwards: `ddev artisan db:show --counts | head -3` works.

7. Delay is configurable: `ddev exec printenv VINSTUB_DELAY_MS` prints nothing or a number; the default is 300. To check a different delay, run `VINSTUB_DELAY_MS=600 ddev restart`, repeat sub-step 1, expect about 0.60s, then `ddev restart` again to go back to 300.

## Step 2

Probe and load run as separate capped containers (NFR-2, AC-4 quick run).

In terminal A:

```sh
bench/slow-load.sh --workload http --quick --label manual
```

In terminal B, within the first 20 seconds:

```sh
docker ps --format '{{.Names}}  {{.Image}}' | grep -E 'slow-load|vinstub'
for n in $(docker ps --format '{{.Names}}' | grep -E '^slow-load-(load|probe)-'); do
  echo "$n $(docker inspect -f '{{.HostConfig.NanoCpus}}' "$n")"
done
```

Expected in B: two containers named `slow-load-load-<pid>` and `slow-load-probe-<pid>`, plus `ddev-interview-vinstub`. Each slow-load container prints `2000000000` (two CPUs).

Expected in A: it finishes with exit code 0 (`echo $?`), prints a table with load RPS and p95, health p95 and the health error counts (502, 504, timeout), and writes `bench/results/raw/http-manual.json` (gitignored). `jq 'del(.runs)' bench/results/raw/http-manual.json` shows the server, commit, concurrency setting, users, warm-up and measure fields, the medians and the spreads. `git diff --stat .env` shows nothing, and `git status --short` does not list the new JSON.

Also check the method in the JSON: `jq '{warmup_s, measure_s, repeats, probe_qps, probe_connections, quick}' bench/results/raw/http-manual.json` prints 5, 15, 1, 5, 2 and true.

## Step 3

Spread is recorded and flagged (NFR-1, AC-7). Needs the committed run in `bench/results/slow-load/<date>/` and the BENCHMARKS.md section; if they are missing, this step fails.

```sh
ls bench/results/slow-load/*/ | head -20
jq '{label, health_p95: .median.health_p95_ms, spread: .spread_percent.health_p95, flagged, repeats}' bench/results/slow-load/*/*.json
grep -n -i 'spread\|xychart-beta\|What the numbers say\|Surprises\|Interview story' BENCHMARKS.md
```

Expected: 8 JSON files (http and sse, 4 configurations each), every file has `"repeats": 3`, a median, a `spread_percent.health_p95` and a `flagged` field (true when the spread is above 20). BENCHMARKS.md has one table per workload, a Mermaid `xychart-beta` block, and the three headings, with at least one measured number under each. Any cell whose spread is above 20 is marked in both the JSON and the table. Open BENCHMARKS.md on GitHub and check the chart renders.

## Step 4

Autoscale and restoring the defaults (AC-6, MNC-2).

```sh
bench/concurrency.sh autoscale --threads 14 --max-threads 64 --max-wait 5; echo "exit $?"
```

Expected on nginx-fpm: non-zero exit and a message that autoscale is for frankenphp only.

```sh
ddev server frankenphp
bench/concurrency.sh autoscale --threads 14 --max-threads 64 --max-wait 5
bench/concurrency.sh --show
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/healthz
bench/concurrency.sh default
bench/concurrency.sh --show
ddev server default
git status --short .ddev
git diff --stat HEAD -- .ddev/config.yaml .ddev/servers
bench/concurrency.sh --show
```

Expected: the first `--show` prints `autoscale 14 to 64 threads, max-wait 5s`; `/healthz` returns `200` while it is active; the second `--show` prints `default`. `git status --short .ddev` lists only the untracked vinstub files (`.ddev/docker-compose.vinstub.yaml`, `.ddev/vinstub/`), the diff stat prints nothing, and the last `--show` on nginx-fpm prints `default`. Check that today's limits are back on nginx-fpm: `ddev exec grep -E 'pm.max_children|pm.max_requests' /etc/php/8.4/fpm/pool.d/www.conf` shows 8 and 200 (or whatever the file showed before you started).

## Step 5

Nothing else changed (MNC-1, MNC-3, MNC-4). Commit your work first so the tree is clean.

```sh
bench/asset-fingerprint.sh
ls public/build/assets | grep -E '^app-.*\.css'
for s in nginx-fpm apache-fpm frankenphp frankenphp-octane frankenphp-worker openlitespeed; do
  ddev server $s && bench/parity.sh capture && bench/parity.sh compare $s nginx-fpm
done
ddev server default
ddev artisan test --compact
bench/run.sh ping --quick; echo "exit $?"
curl -s -o /dev/null -w '/up %{http_code}\n' https://ai-lab.ddev.site/up
```

Expected: the fingerprint output matches the baseline from before this slice (`app-CjRfEL-0.css` is the CSS name recorded at spec time). Parity prints `identical` six times (the new `/healthz` and `/listings/{id}/slow` are not in the captured request set). The suite passes with no network (turn Wi-Fi off first if you want to be sure; the stub is faked). `bench/run.sh ping --quick` exits 0, and `/up` returns 200. `git diff HEAD --stat -- bench/run.sh` prints nothing.

## Step 6

The sse workload and the matrix script (AC-5, AC-4, AC-7).

```sh
bench/slow-load-matrix.sh --dry-run
bench/slow-load.sh --workload sse --quick --label manual
jq '.runs[0] | {load, health}' bench/results/raw/sse-manual.json
```

Expected: the dry run prints the commands for 8 cells (nginx-fpm 8 and 32 workers, frankenphp fixed 14 and autoscale 14 to 64; http with 100 users, sse with 30) and runs none. The sse run exits 0 and its load figures are the time to `[DONE]` (about 6 s for a 120-token, 50 ms stream), streams per second, and the share of streams that ended non-200 or incomplete. Known gap while task 15 is open: the share is counted per check (two per stream) and the time falls back to request duration when no stream finishes. With a healthy run, expect an error share of 0 %. Afterwards `git diff .env` is empty and `bench/concurrency.sh --show` prints `default`.

## Step 7

The learning guide (AC-8).

```sh
grep -n '^## 19\. Slow I/O, worker pools and a health check that stays fast' docs/learning-guide.md
grep -n -i 'sse\|slow-load' docs/learning-guide.md | grep -i '16\|slow-load'
```

Expected: one match for the section 19 heading, and the section 16 code map names the SSE workload. Read section 19 once: it covers a slow request on FPM and on FrankenPHP, `pm.max_children` versus threads, `max_threads` and `max_wait_time`, why `/healthz` skips the web middleware, why SSE is the same as slow I/O for the pool (with measured numbers), when to queue or broadcast, and links to code, scripts, results and the official docs. Open every link; none returns a 404.
