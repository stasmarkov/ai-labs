---
id: spec-20261116-1120-metrics-dashboard
title: Metrics dashboard for the FrankenPHP family
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-16; standing approval)
approved_at: 2026-11-16T09:23:46Z
created: 2026-11-16
stacks: [laravel]
slice: SL-15
brs: [BR-21, BR-26, BR-27]
---

# Metrics dashboard for the FrankenPHP family

## Summary

FrankenPHP already exposes Prometheus metrics: `frankenphp_busy_threads`, `frankenphp_total_threads`, `frankenphp_queue_depth`, the per-worker gauges, and Caddy's HTTP request histograms. The SL-09 and SL-13 bench scripts read a few of them with `curl` once a second. This slice adds two opt-in DDEV services:
- **Prometheus**, which scrapes the web container's metrics;
- **Grafana**, with a provisioned dashboard: RPS, p50 and p95 latency, busy vs total threads, busy and ready workers, queue depth, and the memory of the FrankenPHP process.

It then re-runs the SL-09 slow-I/O scenarios on the three FrankenPHP modes with the stack running, and commits dashboard screenshots and the numbers to `BENCHMARKS.md`.

The backlog line mentions FPM pool metrics. The FPM servers were removed in SL-50, so the comparison is between the three FrankenPHP modes (classic, worker, Octane).

## Context

- **Problem:** worker and thread saturation (SL-09) and the stall under AI streams (SL-28) were seen only as before and after numbers. There is no time series that shows the thread pool fill up while a load runs.
- **Today:**
  - `servers { metrics }` in `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` turns on Caddy's HTTP metrics.
  - The metrics endpoint is Caddy's admin endpoint `localhost:2019/metrics`, reachable only inside the web container.
  - The worker Caddyfile and the Octane mode (Octane starts FrankenPHP with its own Caddyfile) still need checking.
- **Existing patterns:**
  - pinned images on `ddev_default`, as in `.ddev/docker-compose.photo-encoder.yaml`;
  - `bench/slow-load.sh` and `bench/slow-load-matrix.sh`, the BR-21 method;
  - `bench/lib/servers.sh` for server switching.

## Scope

### In scope

1. **Metrics endpoint per mode:**
   - **A read-only listener inside the web container:** port 9180, with `metrics` only. The Caddy admin API is not exposed to the network.
   - **classic `frankenphp` and `frankenphp-worker`:** a `:9180 { metrics }` site block in their Caddyfiles.
   - **`frankenphp-octane`:** the same listener added through the means the Octane FrankenPHP Caddyfile allows (its server extra directives or env). If Octane's Caddyfile cannot carry it, `ddev server` for Octane adds the block through the stash config. The build finds out which works and records it.
   - **Per-host HTTP metrics:** `servers { metrics { per_host } }` is turned on where it is supported, so the histograms are labelled by host.
2. **Prometheus service** (`.ddev/docker-compose.metrics.yaml`, Compose profile `metrics`):
   - **Image:** `prom/prometheus`, pinned by digest.
   - **Config:** `.ddev/metrics/prometheus.yml`. It scrapes `web:9180` every 1 s and keeps data for 2 days, in a named volume.
   - **Labels:** the active server mode is not a target label. Prometheus scrapes whatever server runs, and the dashboard uses Prometheus time ranges per run.
3. **Grafana service** (same file and profile):
   - **Image:** `grafana/grafana-oss`, pinned by digest.
   - **Reachable at:** `https://ai-lab.ddev.site:3000`, through the DDEV router (`HTTPS_EXPOSE`).
   - **Provisioned:** the Prometheus datasource and one dashboard, `.ddev/metrics/grafana/dashboards/frankenphp.json`.
   - **Access:**
     - anonymous Viewer access;
     - admin login disabled through `GF_AUTH_DISABLE_LOGIN_FORM=true`;
     - no admin password stored in the repo.
4. **Dashboard panels:**
   - request rate (`rate(caddy_http_requests_total[15s])`);
   - p50 and p95 latency, from `caddy_http_request_duration_seconds_bucket`;
   - busy and total threads (`frankenphp_busy_threads`, `frankenphp_total_threads`);
   - busy and ready workers (`frankenphp_busy_workers`, `frankenphp_ready_workers`, or what the running version exposes), when worker metrics exist;
   - queue depth (`frankenphp_queue_depth`);
   - the resident memory of the FrankenPHP process (`process_resident_memory_bytes`);
   - request rate by status code.
   - A panel whose metric does not exist on the running mode shows "No data", not an error.
5. **Start and stop:**
   - `ddev metrics on|off|status`, a DDEV host command, `.ddev/commands/host/metrics`.
   - `on`:
     1. runs `ddev start --profiles=metrics`, or the method the installed DDEV v1.25.4 supports for Compose profiles;
     2. waits until Prometheus has its target up;
     3. prints the Grafana URL.
   - `off` stops the two containers. `status` prints the target state.
   - The default `ddev start` does not start them.
6. **Re-run of the slow-I/O scenarios:**
   - **The runs:** `bench/slow-load.sh` runs for `http` and `sse` on `frankenphp` (fixed and autoscale), `frankenphp-worker` and `frankenphp-octane`, with the full BR-21 method, while the metrics stack is on.
   - **Snapshot script:** `bench/metrics-snapshot.sh` writes, per cell:
     - a Prometheus query export over the measured window (max busy threads, max queue depth, max memory, p95 from histograms), as `bench/results/metrics/<date>/<cell>.json`;
     - a PNG screenshot of the dashboard for that window, rendered with Playwright from the pinned image `mcr.microsoft.com/playwright`, at `bench/results/metrics/<date>/<cell>.png`.
   - **What is compared:** the slow-load results are compared with the SL-09 numbers. They are not replaced, and the new run is its own dated folder.
7. **Results and docs:**
   - **BENCHMARKS.md:** a section "Metrics during slow I/O (date)" with:
     - the table per cell;
     - 3 to 4 embedded screenshots;
     - "What the numbers say", "Surprises" and an interview story with a measured number (BR-26).
   - **Learning guide:** a new I-11 section (replacing the SL-15 reserved bullet), with what each metric means, one load walked through on the graphs, and how to add a panel.
   - **README:** the `ddev metrics` commands.
   - **`.gitignore`:** none needed, because the screenshots are committed and small (PNG, 1600×900, each under 400 KB).

### Out of scope

- Logs (SL-16).
- Alerting.
- Metrics from PHP userland (application counters).
- Production monitoring.
- Exposing the Caddy admin API.
- FPM metrics: the FPM servers no longer exist.

## Must NOT change

- **MNC-1:** without the `metrics` profile, `ddev start` starts the same containers as today, and the app behaves identically.
- **MNC-2:** the public pages, the fingerprint and parity are unchanged on all three modes (only `captured=` may differ). The new port 9180 is not routed by the DDEV router.
- **MNC-3:** the existing bench scripts keep their arguments and outputs. The snapshot script only reads their result files.
- **MNC-4:** `.ddev/config.yaml` is not modified.
- **MNC-5:** the default test suite needs no Prometheus or Grafana.

## Acceptance criteria

- **AC-1:** WHEN any of the three server modes runs, THE SYSTEM SHALL answer `GET http://web:9180/metrics` on `ddev_default` with Prometheus text that includes `frankenphp_total_threads` and `caddy_http_requests_total`. The Caddy admin port SHALL NOT be reachable from another container.
- **AC-2:** WHEN `ddev metrics on` runs, THE SYSTEM SHALL start Prometheus and Grafana, report the target up, and print the Grafana URL. WHEN `ddev metrics off` runs, both containers SHALL stop. A plain `ddev start` SHALL NOT start them.
- **AC-3:** WHEN Grafana opens, THE provisioned dashboard SHALL show every Scope 4 panel with live data for the running mode, with no login. Saving changes SHALL not be possible for the anonymous user.
- **AC-4 (BR-21, BR-26):** WHEN the slow-I/O re-run is done, THE SYSTEM SHALL have, per cell:
  - the slow-load result;
  - the metrics export (max busy threads, max queue depth, max memory, p95);
  - a screenshot under `bench/results/metrics/<date>/`.
- **AC-5 (BR-26, BR-27):** `BENCHMARKS.md` SHALL have the metrics section, with the table, the screenshots and the story. The learning guide SHALL have I-11, replacing the reserved bullet. `DocsLinksTest` and `LearningGuideTest` SHALL pass.
- **AC-6:** No secret or password SHALL be committed for Grafana or Prometheus.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Exposure | The admin API stays on localhost; port 9180 is reachable only on `ddev_default`, not via the router or the host | `tests/Feature/MetricsStackConfigTest.php` + `manual-test.md#step-2` |
| **NFR-2** | Footprint | Prometheus + Grafana stay below 512 MB together (`mem_limit`), and the scrape interval stays 1 s for at most 2 days of retention | `tests/Feature/MetricsStackConfigTest.php` |

## Boundaries

- **Always:**
  - pin every image by digest;
  - follow the existing compose-file pattern;
  - test the config files statically in PHPUnit (YAML and JSON parse; the panels' queries name existing metric names, from a fixture captured from the live endpoint);
  - quote only measured numbers.
- **Ask first:** none (standing approval).
- **Never:**
  - modify `.ddev/config.yaml`;
  - expose the Caddy admin API;
  - commit credentials;
  - delete Docker volumes or images.

## Technical context

- **Related files:**
  - `.ddev/servers/*/` (the Caddyfiles, the Octane config) and `.ddev/commands/host/server`;
  - `.ddev/docker-compose.photo-encoder.yaml` (the pattern);
  - `bench/slow-load.sh`, `bench/slow-load-matrix.sh` and `bench/lib/*`;
  - `BENCHMARKS.md` (the SL-09 section) and `docs/learning-guide.md` (I-6, I-11 reserved).
- **Already implemented:**
  - the Caddy metrics directive in classic mode;
  - the BR-21 bench method;
  - server switching;
  - the doc tests.

## Decisions

- **D-1:** the comparison is between the three FrankenPHP modes, not FPM. FPM was removed in SL-50. (self-decided, standing approval)
- **D-2:** a separate metrics-only listener on 9180, not the admin endpoint. The admin API can change the server's config, so it stays on localhost. (self-decided, standing approval)
- **D-3:** the stack is opt-in through a Compose profile and `ddev metrics`, so daily `ddev start` stays as light as today. (self-decided, standing approval)
- **D-4:** Grafana is anonymous Viewer with the login form off. It is a local lab, and no password should live in the repo. (self-decided, standing approval)
- **D-5:** screenshots are rendered from the pinned Playwright image, so the result does not depend on a browser on the host. (self-decided, standing approval)

## Open questions

All 30 items the clarity gate raised on 2026-11-16 are answered in C-1 to C-15 below (self-decided, standing approval).

- [x] Window source, cells, folders, comparison → C-1, C-2
- [x] Octane mechanism, worker Caddyfile, memory metric, admin port → C-3
- [x] Fixtures, optional panels, label names, queries → C-4, C-5
- [x] Hostnames, scrape noise, ports, exposure → C-6
- [x] Profiles, on/off/status, volume → C-7
- [x] Screenshots → C-8
- [x] Read-only Grafana, URL, footprint, pinning → C-9, C-10
- [x] `.env`, clean tree, baselines, traceability, BR text, docs placement, PHPStan, approval → C-11 to C-15

## Clarifications

- **C-1 Window and cells:**
  - **The driver:** a new driver, `bench/metrics-matrix.sh [--quick]`, wraps `bench/slow-load.sh`, which is not changed (MNC-3). It records the epoch milliseconds just before and just after each `slow-load.sh` call, and then calls `bench/metrics-snapshot.sh <cell> <from-ms> <to-ms>`. The window covers the whole cell, warm-up and repeats included. The PNG shows the whole run, and the JSON reports the maximum values over it.
  - **Cells:** there are 8, named `<workload>-<server>-<threads>`:
    - `http` and `sse`, each on:
      - `frankenphp-14` (fixed 14 threads);
      - `frankenphp-auto` (`bench/concurrency.sh autoscale --threads 14 --max-threads 64`, the SL-09 autoscale cell);
      - `frankenphp-worker-4`;
      - `frankenphp-octane-4`.
    - The driver switches settings with the existing `bench/concurrency.sh` and `ddev server`, and restores the default concurrency and the starting server at the end.
  - **Folder:** `bench/results/metrics/<UTC YYYY-MM-DD>/`. A second run on the same day refuses to overwrite unless `--force` is given.
- **C-2 Comparison:** the BENCHMARKS table puts each cell's health p95 and load p95 next to its SL-09 value, with the difference in %.
  - A difference within the SL-09 spread (5 %) is called "same".
  - Larger differences are reported as observed, with the note that Prometheus scraping at 1 s and Grafana were running.
  - A one-off control cell, `http-frankenphp-14`, runs again with the stack off. Its difference from the stack-on run is reported as the cost of the stack.
- **C-3 Server mechanics:**
  - **Where the change goes:** the `:9180 { metrics }` block goes into both stash Caddyfiles, `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` and `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp`. "Stash" is the folder `ddev server` copies into `.ddev/` on a switch.
  - **Octane:**
    - **First choice:** Octane's FrankenPHP reads `CADDY_SERVER_EXTRA_DIRECTIVES` and `CADDY_GLOBAL_OPTIONS`. The build tries a global `metrics` option plus a `:9180` server through Octane's `--caddyfile` option, pointing to a copy of Octane's own Caddyfile with the extra block, stored in `.ddev/servers/frankenphp-octane/`.
    - **If neither works:** Octane is documented as having no metrics endpoint. Its cells still run and record the slow-load results without a snapshot, and the guide says why.
  - **Memory:** `process_resident_memory_bytes` from the listener's process. In every mode that is the FrankenPHP binary that runs PHP, Octane included, because Octane runs the same `frankenphp` binary.
  - **Admin API:** it stays on its default `localhost:2019` in every mode. The check expects a connection failure (curl exit 7 or 28). Any HTTP answer, 4xx included, fails it.
- **C-4 Fixtures and panels:**
  - **Fixtures:** one file per mode, `tests/Fixtures/metrics/{frankenphp,frankenphp-worker,frankenphp-octane}.txt`, captured from each live endpoint.
  - **The test:** every metric a panel uses must be in at least one fixture. A panel listed as mode-specific (workers) must be in the worker or Octane fixture.
  - **Display:** every panel is always shown. A mode that lacks a metric shows "No data", and AC-3's "live data" applies only to the metrics that mode exposes.
- **C-5 Queries:**
  - **Requests:** `sum(rate(caddy_http_requests_total{server!="metrics"}[$__rate_interval]))`.
  - **Latency:** `histogram_quantile(0.95, sum by (le) (rate(caddy_http_request_duration_seconds_bucket{server!="metrics"}[$__rate_interval])))`, the same for 0.5.
  - **Status codes:** grouped by the label `code`.
  - **JSON export:** "p95" is `histogram_quantile` over `increase(...[<window>])` for the whole window, one value. "Max" values are `max_over_time` over the window.
  - **Per-host labels:** `per_host` is on only if the Caddy version accepts it, checked by `caddy validate`. When it is on, the queries filter the host to `ai-lab.ddev.site`.
- **C-6 Network:**
  - **Server name:** the 9180 site is named `metrics` in the Caddyfile, so scrape traffic carries `server="metrics"` and is excluded.
  - **Scrape target:** `ddev-ai-lab-web:9180`, the container name, because `web` can resolve to other projects on `ddev_default`. The manual test uses the same name.
  - **Exposure:**
    - no `expose`, `ports` or `HTTPS_EXPOSE` entry for 9180 anywhere, because containers on one network reach each other without one;
    - the `MetricsStackConfigTest` asserts that no compose file or config maps 9180 or 2019.
  - **Rebuild:** switching servers already rebuilds the web image, which picks up the Caddyfile change.
- **C-7 Profiles and the command:**
  - **On:** DDEV v1.25.4 supports `ddev start --profiles=metrics`. `on` runs it, then polls Prometheus `/api/v1/targets` for up to 60 s. It exits 0 with the URL, or exits 1 with "target not up" and the last error.
  - **Off:** `docker stop ddev-ai-lab-prometheus ddev-ai-lab-grafana`.
  - **Restarts:** a later plain `ddev start` or `ddev restart`, and every server switch, does not start them again, so they have to be turned on again. The driver turns them on itself.
  - **Status:** prints `prometheus: running|stopped`, `grafana: running|stopped` and `target: up|down|n/a`, and exits 0.
  - **Data:** the Prometheus volume `ddev-ai-lab-prometheus-data` is kept. Old data does not mix into a cell, because each cell has its own time window. A reset command is out of scope: delete the volume by hand, and the README prints the command.
- **C-8 Screenshots:**
  - **The script:** `bench/metrics-screenshot.mjs`, run in `mcr.microsoft.com/playwright` pinned by digest, with the matching `playwright` package version installed in the container at run time from its own npm.
  - **Connection:** it opens `http://ddev-ai-lab-grafana:3000/d/frankenphp/frankenphp?from=<ms>&to=<ms>&kiosk` on `ddev_default`, over plain HTTP inside the network, with no certificate.
  - **The image:** a full-dashboard PNG at 1600×900, saved with Playwright's default compression. The size target is a guide. Over 400 KB is a warning, not a failure.
- **C-9 Grafana:**
  - **Port:** Grafana listens on 3000 inside its container, and the router exposes `HTTPS_EXPOSE=3000:3000`. Vite uses 5173, so it does not clash.
  - **Settings:** `GF_SERVER_ROOT_URL=https://ai-lab.ddev.site:3000`. The datasource URL is `http://ddev-ai-lab-prometheus:9090`.
  - **Read-only:** `GF_AUTH_ANONYMOUS_ENABLED=true`, `GF_AUTH_ANONYMOUS_ORG_ROLE=Viewer` and `GF_AUTH_DISABLE_LOGIN_FORM=true`. The dashboard provider has `allowUiUpdates: false` and `editable: false`.
  - **AC-3 checks:** the static test checks these settings, and the manual test checks the live view.
- **C-10 Footprint and pinning:**
  - **Memory:** Prometheus has `mem_limit: 256m` and Grafana `mem_limit: 256m`.
  - **Retention:** `--storage.tsdb.retention.time=2d`, with a 1 s scrape interval.
  - **Images:**
    - **Prometheus:** `prom/prometheus:v3.x` and `grafana/grafana-oss:12.x`, both at the current stable at build time, recorded in the compose file. Each is pinned by its multi-arch index digest.
    - **Playwright and curl:** `mcr.microsoft.com/playwright:v1.x-noble` and `curlimages/curl:8.x` are pinned the same way, including in the manual test.
- **C-11 `.env` and the clean tree:**
  - **`.env`:** the driver changes no `.env` key itself. `slow-load.sh` and `concurrency.sh` keep their own backup and restore. "Restored" means `.env` is byte-identical afterwards.
  - **Clean tree:** the run starts from a committed tree, as `run.sh` requires. Result files are written under `bench/results/metrics/` and `bench/results/slow-load/<date>/`, and are committed after the run.
- **C-12 Baselines:**
  - **Fingerprint:** `app-DNob7CFE.css` / `app-PTzu1QaN.js`.
  - **Parity:** the committed `bench/parity/<mode>.txt`. The manual test captures parity on each of the three modes, and only `captured=` may differ.
- **C-13 Traceability and rules:**
  - **MNC-5:** proved by the full suite passing with the stack off (manual-test step 4 adds `ddev artisan test --compact`).
  - **MNC-3:** "existing bench tests" means `tests/Feature/Bench*Test.php`.
  - **`BenchmarksDocTest`:** it checks that the sections keep a header and that the linked result files exist.
  - **BR-21:** warm-up 30 s, measure 60 s, 3 repeats, median, spread over 5 % reruns once.
  - **BR-26:** a passing test, a committed result table, and a short interview story containing at least one measured number.
  - **BR-27:** a "how it works" walkthrough in the learning guide.
- **C-14 Docs placement:**
  - **Learning guide:** the bullet "SL-15" in `## I-11. Coming next…` is removed, and a new `## I-11. Metrics: watching the thread pool` takes I-11. The reserved section moves to I-12. The `DocsLinksTest` order list is updated.
  - **Screenshots:** for `http-frankenphp-14`, `sse-frankenphp-14`, `sse-frankenphp-auto` and `sse-frankenphp-worker-4`.
  - **README:** the commands go under the existing bench section.
- **C-15 Gates and approval:** PHPStan is installed and configured (`phpstan.neon`) and has run in every slice. The spec is approved through the owner's standing approval when this section is written, and work starts only after `status: approved`.

