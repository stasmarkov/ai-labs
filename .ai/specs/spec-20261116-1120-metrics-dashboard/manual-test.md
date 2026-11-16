# Manual test plan: metrics dashboard (SL-15)

## Before you start

- Run every command from the project root (`/Users/stasmarkov/work/interview`) on the host.
- Docker and DDEV are running, and the project is at `https://ai-lab.ddev.site`.
- Start from a clean state: `ddev restart`. A project that was already running when the metrics files were added or edited still holds the old rendered compose file, and the first `ddev metrics on` then fails with `target not up after 60 s (prometheus: running, target: down)`. A `ddev restart` fixes it (see step 1).
- The server mode is the default one: `ddev server frankenphp`.
- Rollback at the end of the plan: `ddev metrics off && ddev server frankenphp && git checkout -- bench/parity`.

## Step 1

Opt-in stack, including the case that failed in the first run (AC-2, MNC-1).

```sh
ddev restart
docker ps --format '{{.Names}}' | grep -E 'prometheus|grafana' || echo none
ddev metrics on
ddev metrics status
```

Expected:
- `none` after the plain restart. `docker ps` lists only redis, db, web, vinstub and photo-encoder.
- `ddev metrics on` prints `Grafana: https://ai-lab.ddev.site:3030` and exits 0 (no `target not up` error; that error is the earlier failure and must not appear).
- `status` prints `prometheus: running`, `grafana: running`, `target: up`.
- Check the scrape target: `docker exec ddev-ai-lab-prometheus wget -qO- http://localhost:9090/api/v1/targets | grep -o 'ddev-ai-lab-web:9180'` prints the name. A target written as `ddev-__SITE__-web:9180` is a bug.
- `ddev metrics off` then prints `metrics stack stopped`, `ddev metrics status` shows both `stopped` and `target: n/a`, and `docker ps` shows neither container. Run `ddev metrics on` again before step 2.

## Step 2

Endpoint and exposure (AC-1, NFR-1).

```sh
CURL="curlimages/curl:8.11.1@sha256:c1fe1679c34d9784c1b0d1e5f62ac0a79fca01fb6377cdd33e90473c6f9f9a69"
for s in frankenphp frankenphp-worker frankenphp-octane; do
  ddev server $s >/dev/null
  curl -sk -o /dev/null https://ai-lab.ddev.site/
  for m in frankenphp_total_threads caddy_http_requests_total caddy_http_request_duration_seconds; do
    docker run --rm --network ddev_default $CURL -s http://ddev-ai-lab-web:9180/metrics | grep -c "^$m"
  done
  docker run --rm --network ddev_default $CURL -s -m 3 http://ddev-ai-lab-web:2019/config/ || echo admin-closed
done
ddev server frankenphp
curl -sk -o /dev/null -w '%{http_code}\n' -m 5 https://ai-lab.ddev.site:9180/metrics
curl -s -o /dev/null -w '%{http_code}\n' -m 5 http://127.0.0.1:9180/metrics
```

Expected:
- For each mode, three counts of 1 or more (threads, requests, latency) and `admin-closed` (curl exit 7, connection refused).
- The last two commands print `000`: port 9180 is not routed by DDEV and not published on the host.
- Switching mode may stop the metrics containers; if `ddev metrics status` shows `stopped`, run `ddev metrics on`.

## Step 3

Results (AC-4). This step passes only after `bench/metrics-matrix.sh` has been run and its results committed (task 4 in `tasks.md`). Before that, expect it to fail.

```sh
ls bench/results/metrics/*/
```

Expected:
- One `.json` and one `.png` per slow-load cell (eight cells, for example `http-frankenphp-14.json` and `http-frankenphp-14.png`), and a matching `bench/results/slow-load/<date>/<cell>.json`.
- Open the metrics `.json` of one cell: it has max busy threads, max queue depth, max memory and p95.
- Open two of the PNGs: the busy-threads panel rises during the load.

## Step 4

Nothing else changed (MNC-2, MNC-3, MNC-4).

```sh
git diff --quiet .ddev/config.yaml && echo cfg-ok
git diff --quiet HEAD -- bench/ && echo bench-scripts-ok
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/
ddev metrics off
docker ps --format '{{.Names}}' | grep -E 'prometheus|grafana' || echo none
```

Expected:
- `cfg-ok` is printed.
- `bench-scripts-ok` is printed (the new `metrics-*` scripts are untracked, so they do not count).
- The fingerprint is unchanged.
- Only the `captured=` line differs in the parity diff. Repeat on `ddev server frankenphp-worker` and `ddev server frankenphp-octane`, then restore with `ddev server frankenphp` and `git checkout -- bench/parity`.
- The home page prints `200`.
- After `off`, no metrics container runs (`none`).

## Step 5

Dashboard with live data, anonymous, read-only (AC-3).

```sh
ddev metrics on
for i in $(seq 1 40); do curl -sk -o /dev/null https://ai-lab.ddev.site/; done
open https://ai-lab.ddev.site:3030/
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site:3030/api/user
curl -sk -X POST -H 'Content-Type: application/json' -d '{"dashboard":{"title":"x"},"overwrite":true}' -w '\n%{http_code}\n' https://ai-lab.ddev.site:3030/api/dashboards/db
```

Expected:
- The page opens with no login screen and the home page is the "FrankenPHP" dashboard (uid `frankenphp`).
- Within about 15 s these panels show lines: Requests per second, Latency p50/p95, Busy and total threads, Queue depth, Resident memory, Requests per second by status code.
- "Busy and ready workers" says "No data" in classic mode. That is expected; it fills in on `frankenphp-worker` and `frankenphp-octane`.
- There is no Save or Edit button on the dashboard.
- The `/api/user` call returns `401` (anonymous) and the POST returns `403`.
- The browser console has no errors other than those two probes.

## Step 6

Footprint and secrets (AC-6, NFR-2).

```sh
docker stats --no-stream --format '{{.Name}} {{.MemUsage}}' ddev-ai-lab-prometheus ddev-ai-lab-grafana
grep -n -E 'mem_limit|memswap_limit' .ddev/docker-compose.metrics.yaml
grep -n -E 'scrape_interval' .ddev/metrics/prometheus.yml.template
grep -n 'retention.time' .ddev/docker-compose.metrics.yaml
grep -rniE 'password|secret|token|GF_SECURITY_ADMIN' .ddev/docker-compose.metrics.yaml .ddev/metrics .ddev/commands/host/metrics || echo no-secrets
```

Expected:
- Each container is far below its limit (about 85 MiB for Prometheus and 145 MiB for Grafana).
- `mem_limit` is `256m` for each service (512 MB together at most).
- `scrape_interval: 1s`, and retention is `2d`.
- `no-secrets` is printed.

## Step 7

Docs and the default suite (AC-5, MNC-5).

```sh
ddev metrics off
ddev artisan test --compact tests/Feature/DocsLinksTest.php tests/Feature/LearningGuideTest.php tests/Feature/BenchmarksDocTest.php
ddev artisan test --compact
grep -n -i 'metrics' BENCHMARKS.md | head
grep -n 'I-11' docs/learning-guide.md | head -3
```

Expected:
- All tests pass with the stack stopped.
- `BENCHMARKS.md` has the metrics section with the per-cell table, the screenshots, "What the numbers say", "Surprises" and the interview story. If it still says these are added when the matrix run is committed, task 5 is open and this step fails.
- The learning guide has I-11 and no reserved SL-15 bullet.
