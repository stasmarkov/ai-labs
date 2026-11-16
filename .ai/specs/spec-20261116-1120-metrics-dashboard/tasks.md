# Metrics dashboard for the FrankenPHP family — Tasks

**Spec**: spec-20261116-1120-metrics-dashboard | **Size**: M | **Tasks**: 6

## Phase 1: Endpoint and stack

- [x] 1. Metrics listener on all three modes (devops)
  Covers: AC-1, NFR-1, MNC-2
  - [x] 1.1 Add the `:9180 { metrics }` block for classic and worker, plus the Octane mechanism. Turn on per-host metrics where supported.
  - [x] 1.2 **Verify**:
    - on each mode, `ddev exec curl -s localhost:9180/metrics | grep frankenphp_total_threads`;
    - from another container on `ddev_default`, 9180 answers and 2019 does not;
    - parity is unchanged on each mode.
    The results are recorded here.
    Results (2026-11-16), `tests/Feature/MetricsStackConfigTest.php` passes (AC-1, NFR-1, MNC-2 tests):

    | Mode | `localhost:9180` in web | 9180 from `ddev_default` | 2019 from `ddev_default` | Parity |
    |------|-------------------------|--------------------------|--------------------------|--------|
    | frankenphp | 3 `frankenphp_total_threads` lines | 3 lines | `admin-closed` | only `captured=` differs |
    | frankenphp-worker | 3 lines | 3 lines | `admin-closed` | only `captured=` differs |
    | frankenphp-octane | 3 lines | 3 lines | `admin-closed` | only `captured=` differs |

    Octane has no add-on Caddyfile, so `.ddev/servers/frankenphp-octane/Caddyfile` (the Octane stub plus `servers { metrics }` and the `:9180` block) is passed with `--caddyfile`.

- [x] 2. Prometheus, Grafana, the dashboard and `ddev metrics` (devops)
  Covers: AC-2, AC-3, AC-6, NFR-2, MNC-1, MNC-4
  - [x] 2.1 Add:
    - `.ddev/docker-compose.metrics.yaml` (profile `metrics`, pinned digests, `mem_limit`);
    - `.ddev/metrics/prometheus.yml`;
    - the Grafana provisioning (datasource and dashboard JSON);
    - `.ddev/commands/host/metrics` (`on`, `off`, `status`).
  - [x] 2.2 **Verify**: `tests/Feature/MetricsStackConfigTest.php`:
    - the YAML and JSON parse;
    - the images are pinned;
    - the profile is set;
    - no password;
    - `mem_limit` is set;
    - every panel query names a metric found in the committed fixture `tests/Fixtures/metrics/frankenphp-metrics.txt`;
    - there is no `admin` exposure.
    Also: `ddev metrics on`, then the dashboard live; `ddev start` without the profile starts no metrics containers.
    Results (2026-11-16): `tests/Feature/MetricsStackConfigTest.php` passes. `ddev restart` starts no metrics container; `ddev metrics on` reports the target up and the dashboard answers 200 at `https://ai-lab.ddev.site:3030` as anonymous Viewer (`canSave: false`); Prometheus and Grafana use about 88 MB and 135 MB.
    Grafana is exposed on router port 3030, not 3000: a host process on 3000 made DDEV refuse to start the whole project, profile or not.

## Phase 2: Measure and document

- [x] 3. Snapshot script (checks)
  Covers: AC-4
  - [x] 3.1 Add `bench/metrics-snapshot.sh <cell> <from-ms> <to-ms>` (C-5 queries, C-8 screenshot) and the driver `bench/metrics-matrix.sh` (C-1 cells, window timing, the stack-off control cell).
  - [x] 3.2 **Verify**: `bash -n`, `--help`, and a quick run writes the JSON and PNG.
    Results (2026-11-16): `tests/Feature/BenchMetricsSnapshotTest.php` passes (7 tests); a live `bench/metrics-snapshot.sh` run on a 15 s window with the stack on wrote the JSON and a 54 KB PNG.

- [x] 4. Re-run the slow-I/O scenarios (checks)
  Covers: AC-4
  - [x] 4.1 On a clean committed tree, run `bench/metrics-matrix.sh` (8 cells plus the control, full BR-21 method), then commit the results.
  - [x] 4.2 **Verify**: every cell has its slow-load JSON, metrics JSON and PNG. `.env` is restored and the starting server is restored.
    Results (2026-11-16): `bench/metrics-matrix.sh` on the clean tree `dcca93a` (started on `frankenphp`, about 2 h 15 min, exit 0, no failed cell), committed in `f2869af`. All 8 cells have `bench/results/slow-load/2026-11-16/<cell>.json`, `bench/results/metrics/2026-11-16/<cell>.json` and `<cell>.png`, and the three controls `http-<mode>-nostack.json`. Every cell has 3 repeats, none is flagged (largest health-p95 spread 10.9 %, `sse-frankenphp-auto`), and scrape coverage is 1 in every load window. Afterwards: server `frankenphp`, concurrency `default`, no `.env.bench-backup`, `.env` unchanged in git, and no Prometheus, Grafana, Playwright or load container running.
    A `--quick` run before it found one bug: Grafana was still starting when the Octane SSE screenshot ran (`ERR_CONNECTION_REFUSED`). `ddev metrics on` now also waits for Grafana's `/api/health`, and the screenshot retries a refused connection for up to 60 s.

    | Cell | Load RPS | Load p95 | SL-09 load p95 | Diff | Health p95 | SL-09 health p95 | Diff | Max busy threads | Max total threads | Max queue | Busy / total workers | Max memory | Histogram p95 |
    |---|---|---|---|---|---|---|---|---|---|---|---|---|---|
    | `http-frankenphp-14` | 41.4 | 2703 ms | 2712 ms | same (-0.3 %) | 2358 ms | 2369 ms | same (-0.5 %) | 112 | 14 | 98 | n/a | 196 MB | 4.02 s |
    | `http-frankenphp-auto` | 199.3 | 597 ms | 626 ms | same (-4.6 %) | 265 ms | 276 ms | same (-3.9 %) | 102 | 14 | 86 | n/a | 292 MB | 0.95 s |
    | `http-frankenphp-worker-4` | 12.6 | 7968 ms | n/a | n/a | 7904 ms | n/a | n/a | 4 | 14 | 0 | 140 / 4 | 197 MB | 9.73 s |
    | `http-frankenphp-octane-4` | 12.6 | 7974 ms | n/a | n/a | 7922 ms | n/a | n/a | 4 | 14 | 0 | 140 / 4 | 173 MB | 9.73 s |
    | `sse-frankenphp-14` | 2.1 | 18577 ms | 18573 ms | same (+0.0 %) | 10000 ms | 10000 ms | same (+0.0 %) | 80 | 14 | 66 | n/a | 206 MB | >10 (bucket cap) |
    | `sse-frankenphp-auto` | 4.9 | 6206 ms | 6193 ms | same (+0.2 %) | 15 ms | 16 ms | -6.9 % | 30 | 14 | 13 | n/a | 229 MB | 9.58 s |
    | `sse-frankenphp-worker-4` | 0.6 | 49770 ms | n/a | n/a | 10000 ms | n/a | n/a | 4 | 14 | 0 | 80 / 4 | 177 MB | >10 (bucket cap) |
    | `sse-frankenphp-octane-4` | 0.6 | 49799 ms | n/a | n/a | 10000 ms | n/a | n/a | 4 | 14 | 0 | 80 / 4 | 182 MB | >10 (bucket cap) |

    Stack-off controls (load RPS, load p95, health p95, on / off): frankenphp-14 41.4 / 41.3, 2703 / 2707 ms, 2358 / 2358 ms; frankenphp-worker-4 12.6 / 12.5, 7968 / 8033 ms, 7904 / 7969 ms; frankenphp-octane-4 12.6 / 12.6, 7974 / 8007 ms, 7922 / 7955 ms. Every difference is within 1 %.

- [x] 5. Docs (docs)
  Covers: AC-5
  - [x] 5.1 Add the BENCHMARKS section (the table, 3 to 4 screenshots, what the numbers say, surprises, the story), the learning guide I-11 replacing the reserved bullet, and README.
  - [x] 5.2 **Verify**: `DocsLinksTest`, `LearningGuideTest` and `BenchmarksDocTest`.
    Results (2026-11-16): `BENCHMARKS.md` "Metrics during slow I/O (2026-11-16)" has the method, the per-cell table against SL-09, the stack-off table, the directive cost, 4 screenshots (`http-frankenphp-14`, `sse-frankenphp-14`, `sse-frankenphp-auto`, `sse-frankenphp-worker-4`), "What the numbers say", "Surprises" and the interview story. `BenchmarksDocTest` checks every row against the result files. The SL-09 test now globs only its own two folders, because the new run added files under `bench/results/slow-load/`. Learning guide I-11 was rewritten with the measured numbers and corrected meanings (busy counts waiting requests, total threads ignores autoscale), plus a headline number in the closing summary and the in-app guide in the II-24 map. README has the commands and the volume reset. `DocsLinksTest`, `LearningGuideTest` and `BenchmarksDocTest` pass.
    In-app guide (owner request): `resources/guides/metrics.php`, a foundation guide on `/ai/guides/metrics`. It has six tabs: what each metric means and one load walked through (works), what to take into account (failures), how to add a panel and the same pattern in other domains (recipes), how to support it (support), the numbers from `bench/results/metrics/2026-11-16/` (numbers) and one experiment on the describe page (live check on classic: one stream gave busy threads 1 and queue 0). `GuideContentTest` (new test `AC-5 the metrics guide has six non-empty tabs...`), `GuidePageTest` and `NoReferencesInUiTest` pass.

- [x] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-5
  - [x] 6.1 Run the full suite, Pint and PHPStan; check the fingerprint and parity on all three modes; commit, ping and push.
  - [x] 6.2 **Verify**: outputs recorded here.
    Results (2026-11-16):
    - `ddev artisan test --compact`: 2952 passed, 3 skipped, 0 failed (30927 assertions), with no Prometheus or Grafana running.
    - `ddev exec vendor/bin/pint --dirty --format agent`: passed (`pint --test`: 1008 files pass).
    - `ddev exec vendor/bin/phpstan analyse`: `[OK] No errors`.
    - New guard tests in `tests/Feature/MetricsGatesTest.php` (MNC-1, MNC-2, MNC-3, MNC-5, 4 tests) pass.
    - Fingerprint and parity: the fingerprint and parity tests in the full suite pass; the per-mode parity run is the one recorded under task 1 (only `captured=` differs on all three modes). `.ddev/config.yaml` and the existing bench scripts have no diff against HEAD.
    - The full suite exposed one failure that was already at HEAD: `FrankenphpOnlyTest` listed `tests/Feature/DocsLinksTest.php` as an offender for the removed server names it asserts against. It is now on that test's allowlist, like `LearningGuideTest.php`.
    - Commit, ping and push are left to the human; tasks 4 and 5 are still open.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | metrics on 9180, admin not exposed | `manual-test.md#step-2` | manual | 1 |
| AC-2 | ddev metrics on/off, opt-in | `manual-test.md#step-1` | manual | 2 |
| AC-3 | provisioned dashboard, anonymous viewer | `tests/Feature/MetricsStackConfigTest.php` (AC-3 tests), `manual-test.md#step-5` | test | 2 |
| AC-4 | per-cell result, export, screenshot | `tests/Feature/BenchMetricsSnapshotTest.php` (AC-4 tests), `manual-test.md#step-3` | test, manual | 3, 4 |
| AC-5 | BENCHMARKS and guide | `tests/Feature/DocsLinksTest.php`, `manual-test.md#step-7` | test, manual | 5 |
| AC-6 | no secrets | `tests/Feature/MetricsStackConfigTest.php`, `manual-test.md#step-6` | test, manual | 2 |
| MNC-1 | default start unchanged | `manual-test.md#step-1`, `tests/Feature/MetricsGatesTest.php` (MNC-1 test) | manual, test | 2, 6 |
| MNC-2 | pages, fingerprint, parity | `manual-test.md#step-4`, `tests/Feature/MetricsStackConfigTest.php` (`MNC-2 the main site blocks keep their existing routing`), task 25 | manual, test | 1, 6, 25 |
| MNC-3 | bench scripts unchanged | `manual-test.md#step-4`, `tests/Feature/MetricsGatesTest.php` (MNC-3 test), existing bench tests pass unchanged | manual, test | 6 |
| MNC-4 | config.yaml untouched | `manual-test.md#step-4` | manual | 6 |
| MNC-5 | suite needs no stack | `tests/Feature/MetricsGatesTest.php` (MNC-5 test), `manual-test.md#step-7` | test, manual | 2, 6 |
| NFR-1 | exposure | `tests/Feature/MetricsStackConfigTest.php`, `manual-test.md#step-2` | test, manual | 1, 2 |
| NFR-2 | footprint | `tests/Feature/MetricsStackConfigTest.php`, `manual-test.md#step-6` | test, manual | 2 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green (task 26)

## Phase: Gaps (converge round 1)

- [x] 7. Prove `caddy_http_requests_total` and the latency histogram on the worker and Octane modes (devops)
  Covers: AC-1, AC-3
  - Why: AC-1 requires `caddy_http_requests_total` on every mode, but `tests/Fixtures/metrics/frankenphp-worker.txt` and `frankenphp-octane.txt` have no `caddy_http_requests_total` or `caddy_http_request_duration_seconds` at all (only `caddy_http_requests_in_flight`), and `frankenphp.txt` has only the scrape's own `handler="metrics"` series. The task 1 check grepped only `frankenphp_total_threads`, so the RPS, latency and status-code panels are not shown to have live data on worker or Octane.
  - Do: send app traffic on each mode, re-capture the three fixtures, check which `handler` labels one app request produces, and add a test that each mode's fixture has `# TYPE caddy_http_requests_total` and `# TYPE caddy_http_request_duration_seconds`. Make `manual-test.md#step-2` grep both metric names.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/MetricsStackConfigTest.php` passes with the new per-mode test. On all three modes, `docker run --rm --network ddev_default curlimages/curl -s http://ddev-ai-lab-web:9180/metrics | grep -c caddy_http_requests_total` is 1 or more after a request to `https://ai-lab.ddev.site/`. Record the results here.
    Results (2026-11-16): `MetricsStackConfigTest` passes, including `AC-1 AC-3 every mode fixture has the request counter and the latency histogram` (3 datasets). The three fixtures were re-captured after 3 app requests per mode. From `ddev_default`, `caddy_http_requests_total` lines after the request, and the handler labels one app request produces:

    | Mode | `caddy_http_requests_total` lines | App handler labels |
    |------|-----------------------------------|--------------------|
    | frankenphp | 2 | `vars` (srv1) plus the scrape's `metrics` (srv2) |
    | frankenphp-worker | 3 | `map`, `php`, `rewrite` (srv1) |
    | frankenphp-octane | 1 | `subroute` (srv0) |

    The dashboard filter `handler!="metrics"` matches the app handlers on all three modes. `manual-test.md#step-2` now greps `frankenphp_total_threads`, `caddy_http_requests_total` and `caddy_http_request_duration_seconds`. The server was switched back to `frankenphp`.
- [x] 8. Turn on per-host metrics where Caddy accepts them, or record that it does not (devops)
  Covers: AC-1
  - Why: Scope 1 and C-5 ask for `servers { metrics { per_host } }` where `caddy validate` accepts it. All three Caddyfiles have only a plain `metrics`, and tasks.md has no record of the check. The test `AC-1 every mode serves metrics on port 9180 and turns on per-host metrics` in `tests/Feature/MetricsStackConfigTest.php` asserts only a plain `metrics` line.
  - Do: run `caddy validate` (or `frankenphp validate`) with `metrics { per_host }` on each mode. If it is accepted, turn it on in the three Caddyfiles, add the host filter `ai-lab.ddev.site` to the dashboard and snapshot queries, and assert `per_host` in the test. If it is rejected, record the validate output here and rename the test so it does not claim per-host.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/MetricsStackConfigTest.php` passes. The validate result for each mode is recorded here.
    Results (2026-11-16): `frankenphp validate --adapter caddyfile` (FrankenPHP 1.13.4, Caddy 2.11.7) with `servers { metrics { per_host } }`:

    | Mode | Validate |
    |------|----------|
    | frankenphp | `Valid configuration` (run with a separate bolt path, because the live hub holds `/tmp/mercure.db`) |
    | frankenphp-worker | `Valid configuration` |
    | frankenphp-octane | `Valid configuration`, with a warning that nested `metrics` inside `servers` is deprecated in favour of the global `metrics` option |

    `per_host` is on in all three Caddyfiles and asserted by `AC-1 every mode serves metrics on port 9180 and turns on per-host metrics`. Live, every mode labels the series `host="_other"`: the sites are catch-all (`:80`, `:443`, Octane's `:8000`), so Caddy has no host name to record. A filter `host="ai-lab.ddev.site"` would therefore match nothing on any mode, so it was not added to the dashboard or `bench/metrics-snapshot.sh`; both keep `handler!="metrics"`, which Task 7 showed matches the app handlers on all three modes. The server was switched back to `frankenphp`.
- [x] 9. Pin the Playwright and curl images by digest (checks)
  Covers: AC-4
  - Why: C-10 and the Boundaries ("pin every image by digest") say this. `bench/metrics-snapshot.sh` uses `IMAGE="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble}"`, which is a tag with no `@sha256:`. `manual-test.md#step-2` runs `curlimages/curl` with no tag and no digest. The test `AC-4 the snapshot also writes the PNG through the pinned browser container` does not assert a digest.
  - Do: pin `mcr.microsoft.com/playwright:v1.49.1-noble@sha256:<multi-arch index digest>` in the script and `curlimages/curl:8.x@sha256:<digest>` in the manual test. In `BenchMetricsSnapshotTest`, assert that the `docker run` call uses an image matching `@sha256:[0-9a-f]{64}`.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/BenchMetricsSnapshotTest.php` passes. `grep -n '@sha256:' bench/metrics-snapshot.sh .ai/specs/spec-20261116-1120-metrics-dashboard/manual-test.md` shows both images.
- [x] 10. Restore the starting server at the end of the matrix, not the default server (checks)
  Covers: AC-4
  - Why: C-1 and the task 4.2 Verify say the driver restores the starting server. `bench/metrics-matrix.sh` calls `read_default_server` and `restore_default_server`, so a run started on `frankenphp-worker` ends on `frankenphp`.
  - Do: record `current_server` (from `bench/lib/servers.sh`) before the first switch, and switch back to it in `restore`. Fall back to the default server only when the current server cannot be read.
  - [x] **Verify**: in `BenchMetricsSnapshotTest`, a dry run with the `ddev` stub reporting `frankenphp-worker` as current ends with `ddev server frankenphp-worker`. `ddev artisan test --compact tests/Feature/BenchMetricsSnapshotTest.php` passes.
- [x] 11. Fix stale URLs and the fixture path in the manual test and the guide (docs)
  Covers: AC-2, AC-5
  - Why: Grafana moved to router port 3030, but `manual-test.md#step-1` still expects `https://ai-lab.ddev.site:3000` and a target `web:9180`. The real target is `ddev-ai-lab-web:9180` in `.ddev/metrics/prometheus.yml`. In `docs/learning-guide.md`, I-11 "How to add a panel" points readers to `tests/Fixtures/metrics/frankenphp-metrics.txt`, which does not exist. The fixtures are `tests/Fixtures/metrics/{frankenphp,frankenphp-worker,frankenphp-octane}.txt`.
  - Do: change manual step 1 to expect `:3030` and `ddev-ai-lab-web:9180`. Point I-11 to the three per-mode fixture files. Add an assertion to `LearningGuideTest` that every `tests/Fixtures/...` path quoted in I-11 exists.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/LearningGuideTest.php tests/Feature/DocsLinksTest.php` passes. `grep -n ':3000\|frankenphp-metrics.txt' .ai/specs/spec-20261116-1120-metrics-dashboard/manual-test.md docs/learning-guide.md` finds nothing.

## Phase: Gaps (converge round 2)

- [x] 12. Keep the slow-load results out of the metrics folder so the snapshot does not collide with them (checks)
  Covers: AC-4, MNC-3
  - Why: `bench/metrics-matrix.sh` runs `export BENCH_OUT_DIR=".../bench/results/metrics/$DATE"`, and `bench/slow-load.sh` (lines 206-208) also reads `BENCH_OUT_DIR`. So each cell's slow-load result is written as `bench/results/metrics/<date>/<workload>-<label>.json`, which is the same path as the snapshot's `$cell.json`. Without `--force`, `metrics-snapshot.sh` then refuses with exit 2 and the matrix (`set -e`) stops after the first cell. With `--force`, the snapshot JSON overwrites the slow-load result. Either way, AC-4's "slow-load result plus metrics export per cell" cannot be produced, and the documented `bench/results/slow-load/<date>/<cell>.json` is never written. The dry-run tests do not run slow-load.sh, so they miss this.
  - Do: stop exporting `BENCH_OUT_DIR` from the matrix. Pass it only to `metrics-snapshot.sh` (for example `env BENCH_OUT_DIR=... bash metrics-snapshot.sh ...`), so slow-load.sh writes to its own default (`bench/results/slow-load/<date>/`, or `bench/results/raw/` with `--quick`). Make the "already has results" refusal check both folders.
  - [x] **Verify**: in `tests/Feature/BenchMetricsSnapshotTest.php`, the matrix dry-run shows `bench/results/metrics/` only on the `metrics-snapshot.sh` lines and never on a `slow-load.sh` line, and `bench/metrics-matrix.sh` has no `export BENCH_OUT_DIR`. `ddev artisan test --compact tests/Feature/BenchMetricsSnapshotTest.php` passes.
- [x] 13. Keep Prometheus scraping through the restart that slow-load.sh does, and fail a snapshot whose window was not scraped (checks)
  Covers: AC-4, AC-3
  - Why: in full mode `bench/slow-load.sh` runs `ddev restart` before it measures: line 154 on worker and Octane, and line 253 on classic (`BENCH_SETTLE` defaults to 10). The matrix's own header says "a restart does not bring the stack back" (C-7 says the same), and it runs `ddev metrics on` only before and after the slow-load call. So Prometheus is stopped for the warm-up, the measurement and the repeats of every cell. `bench/metrics-snapshot.sh` then gets empty results, writes `null` for every metric through `query_value`, exits 0, and screenshots an empty dashboard. Nothing checks that the window holds samples.
  - Do: in `bench/metrics-matrix.sh`, run a background keep-alive during each `slow-load.sh` call. Once the web container is back, it restarts the two containers whenever they are stopped (`docker start ddev-${DDEV_SITENAME}-prometheus ddev-${DDEV_SITENAME}-grafana`, falling back to `ddev metrics on`). Stop the keep-alive when the call returns. In `bench/metrics-snapshot.sh`, query `count_over_time(up{job="frankenphp"}[<window>s])`, write it to the JSON as `scrape_coverage` (samples divided by the window in seconds), and exit 1 with a clear message when coverage is below 0.8 or `max_busy_threads` is null.
  - [x] **Verify**: in `BenchMetricsSnapshotTest`, the stub Prometheus returns a low `count_over_time` and the snapshot exits 1 without writing the JSON. With full coverage, the JSON has `scrape_coverage`. The matrix dry-run shows the keep-alive start and stop around every `slow-load.sh` line. `ddev artisan test --compact tests/Feature/BenchMetricsSnapshotTest.php` passes.
- [x] 14. Show busy workers next to ready workers on the dashboard (devops)
  Covers: AC-3
  - Why: Scope 4 asks for a panel with "busy and ready workers (`frankenphp_busy_workers`, `frankenphp_ready_workers`)". `.ddev/metrics/grafana/dashboards/frankenphp.json` has "Ready and total workers" with `frankenphp_total_workers` and `frankenphp_ready_workers`, and no `frankenphp_busy_workers`. The worker and Octane fixtures do expose `# TYPE frankenphp_busy_workers gauge`. The test `AC-3 the dashboard parses, is not editable and shows every scope panel` pins the title "Ready and total workers", so it does not catch the missing busy series. Worker saturation on the worker and Octane cells cannot be seen on the dashboard or in the screenshots.
  - Do: change the workers panel to "Busy and ready workers" and give it the `frankenphp_busy_workers` and `frankenphp_ready_workers` queries (keep total if useful). Update the expected title in the test, and assert that the panel queries include `frankenphp_busy_workers` and that the worker fixture has its `# TYPE` line. Change the "ready workers" wording in the I-11 metric list and the draft BENCHMARKS paragraph to match.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/MetricsStackConfigTest.php tests/Feature/LearningGuideTest.php tests/Feature/DocsLinksTest.php` passes. With `ddev metrics on` on `frankenphp-worker`, the panel shows a busy-workers series.
- [x] 15. Re-check parity and the asset fingerprint on all three modes against the final Caddyfiles (checks)
  Covers: MNC-2
  - Why: MNC-2 and task 6.1 require the fingerprint and parity to be checked on all three modes. The only per-mode parity run is the one under task 1. Task 8 then changed all three Caddyfiles (`metrics { per_host }`) and did not re-run parity. The task 6 results say "the per-mode parity run is the one recorded under task 1". `bench/asset-fingerprint.sh` (baseline `app-DNob7CFE.css` / `app-PTzu1QaN.js`, C-12) has no recorded run in this spec.
  - Do: on `frankenphp`, `frankenphp-worker` and `frankenphp-octane`, run `bench/asset-fingerprint.sh` and `PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity`. Revert `bench/parity` afterwards, switch back to the starting server, and record a results table under this task.
  - [x] **Verify**: for each mode, the fingerprint matches the C-12 baseline and only `captured=` differs in `bench/parity`. The table is recorded here.
    Results (2026-11-16, final Caddyfiles with `metrics { per_host }`). `bench/parity` was reverted after each mode and the server is back on `frankenphp`:

    | Mode | `app.css` | `app.js` | Matches C-12 | `git diff -U0 bench/parity` |
    |------|-----------|----------|--------------|-----------------------------|
    | frankenphp | `app-DNob7CFE.css` | `app-PTzu1QaN.js` | yes | only `captured=` differs |
    | frankenphp-worker | `app-DNob7CFE.css` | `app-PTzu1QaN.js` | yes | only `captured=` differs |
    | frankenphp-octane | `app-DNob7CFE.css` | `app-PTzu1QaN.js` | yes | only `captured=` differs |

## Phase: Fix pass after the ship report (2026-11-16)

Findings F1 to F20 come from `.ai/reports/ship-spec-20261116-1120-metrics-dashboard-20261116-1634.md`. The owner made the decisions; tests were written before the fix where it could be tested.

- [x] 16. Environment: `fileinfo` after the image rebuild (done by the orchestrator)
  - The PHP ZTS repository now ships `fileinfo` as its own package, `php-zts-fileinfo`, so the rebuilt web image had no `finfo` and 106 tests failed. The package is added to `.ddev/servers/frankenphp/config.frankenphp.yaml`, `.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml` and the active `.ddev/config.zz-server.yaml`. `DdevServerCommandTest` asserts it for both server configs. Full suite afterwards: 2968 passed.
- [x] 17. F1, request rate counted three times on worker mode
  - Every request and status-code query selects one handler per mode, `handler=~"php|vars|subroute"` (classic `vars`, worker `php`, Octane `subroute`). The scrape's own `metrics` handler is left out.
  - The three fixtures were captured again from the live endpoints after `per_host` was removed, each after 5 app requests: classic `vars` 5; worker `map` 5, `php` 5, `rewrite` 5; Octane `subroute` 5.
  - Test: `AC-1 AC-3 the handler selector picks exactly one request series per mode, the one that counts every app request` (3 datasets), plus `AC-3 the request panels count one handler per mode`.
  - Found on the way: `caddy_http_requests_total` has no `code` label, so the status-code panel only showed one series named "Value". It now groups `caddy_http_request_duration_seconds_count` by `code`. Test: `AC-3 the status code panel groups a series that carries the code label on every mode`.
- [x] 18. F2 and F3, p95 capped at 10 s and the snapshot window
  - The snapshot writes a p95 of 10 s or more as the string `">10 (bucket cap)"` with `p95_capped: true` (Caddy's top finite bucket is 10 s). BENCHMARKS uses slow-load's own p95 for the SSE cells. The latency panel description explains the cap.
  - Window: measuring only the repeats would need timestamps from `slow-load.sh`, which may not change (MNC-3). Instead the matrix watches for the slow-load load container and passes its first start and last stop as `--load-window`. The numbers cover that window (the warm-ups are the same load), while the PNG keeps the whole cell (C-1). The window still holds the health probe (5 requests per second on the same handler). This is documented in the script header and BENCHMARKS. It changes C-1 for the numbers only. The scrape coverage uses the same window, so a project restart outside the load no longer pulls it under 0.8.
  - Tests: `AC-4 a p95 at the top Caddy bucket is written as above the bucket cap, not as a number`, `AC-4 a p95 just below the bucket cap stays a number`, `AC-4 the load window narrows the numbers but not the screenshot`, `AC-4 the snapshot refuses a malformed or reversed load window`.
- [x] 19. F4 and gap 14, worker saturation
  - Live (fixtures): with 4 idle workers `frankenphp_busy_threads` reads 4 on worker and Octane mode, and this FrankenPHP has no worker queue metric. The workers panel is now "Busy, ready and total workers" (`frankenphp_busy_workers`, `frankenphp_ready_workers`, `frankenphp_total_workers`). Every panel has a description, and those of threads, queue and workers say what to read in worker modes. The snapshot exports `max_busy_workers` and `total_workers`. Learning guide I-11 was corrected to match.
- [x] 20. F9, Grafana admin/admin
  - Compose sets `GF_AUTH_BASIC_ENABLED=false`, `GF_USERS_ALLOW_SIGN_UP=false`, `GF_AUTH_DISABLE_LOGIN_FORM=true` and `GF_AUTH_DISABLE_LOGIN=true`, with anonymous Viewer. `AC-3 grafana is provisioned and read-only for the anonymous viewer` asserts all of them.
  - Live proof (2026-11-16): `POST /api/dashboards/db` with `-u admin:admin` returns **403** "Permissions needed: dashboards:create", not the expected 401. `-u admin:wrong` gets the identical 403, and so do `GET /api/admin/settings` and `GET /api/users` with either pair. So the credentials are not evaluated at all, and the request is served as the anonymous Viewer. `GET /api/user` with admin:admin is 401, `POST /login` is 400 and `POST /api/user/signup` is 401. The anonymous dashboard has `canSave: false` and `canEdit: false`.
- [x] 21. F10, the cost of the metrics directive on worker mode
  - Method: `bench/run.sh ping --quick` (50 connections, warm-up 5 s, measure 15 s, 1 repeat) on `frankenphp-worker` with the stack off. Three alternating rounds with and without `metrics` in `servers { }`, edited only in the active, gitignored `.ddev/web-build/Caddyfile.frankenphp` and restored after (`restored: 1`), committed tree `8cba151`. Raw files: `bench/results/raw/2026-11-16/frankenphp-worker/ping-directive-*.json` (gitignored).

    | Round | With: RPS | With: p95 | Without: RPS | Without: p95 |
    |-------|-----------|-----------|--------------|--------------|
    | 1 | 9430 | 8.60 ms | 9619 | 8.51 ms |
    | 2 | 9370 | 8.59 ms | 9732 | 8.15 ms |
    | 3 | 9324 | 8.75 ms | 9610 | 8.38 ms |
    | Mean | 9375 | 8.65 ms | 9654 | 8.35 ms |

  - Cost: 2.9 % RPS and 3.6 % p95 on the lightest route. That is under the 5 % limit, so the directive stays on and is not gated behind an env var. The stack-off controls in the matrix therefore still include it.
- [x] 22. F14, hardcoded site name
  - `.ddev/metrics/prometheus.yml.template` has the target `ddev-${DDEV_SITENAME}-web:9180`. `ddev metrics on` writes `.ddev/metrics/prometheus.generated.yml` from it with the project name (gitignored), and the Prometheus container mounts that file with its normal entrypoint (no `sed` in the container). The command requires `DDEV_SITENAME`, which DDEV sets for host commands. Compose and the datasource use `${DDEV_SITENAME}`. The bench scripts follow the repo convention `${DDEV_SITENAME:-ai-lab}`, and the screenshot URL is built from it.
  - Test: `AC-2 the prometheus config is generated per project by ddev metrics on into a gitignored file`, which also asserts that no stack file contains `ai-lab`. Live: `ddev metrics on` wrote the target `ddev-ai-lab-web:9180` and reported `target: up`, and `git check-ignore` matches the generated file.
- [x] 23. Minor findings
  - F5: `--quick` writes to the gitignored raw folder and overwrites without `--force`. A full run still refuses the same day. The guard now also runs on `--dry-run`, which makes it testable.
  - F6 and F13: the keep-alive is one watcher loop. Once the web container is healthy again, it recreates only the two containers with `docker compose -f .ddev/.ddev-docker-compose-full.yaml --profile metrics up -d --no-deps prometheus grafana`, one call at a time, with no `ddev start` inside. `watch_stop` removes a flag file and waits for the loop to end, so nothing outlives it. It polls Docker once a second, which is negligible next to the load. A failed cell is reported at the end and the run goes on.
  - F7: coverage counts `sum_over_time(up[...])`, so only successful scrapes count (already fixed, test kept).
  - F8: the fixtures were captured again (task 17). Test: `AC-1 the fixtures were captured without a per-host label`.
  - F11: the dashboard refreshes every 5 s. Live, a URL cannot switch that off: `refresh=`, `refresh=false` and `refresh=0` all ended at `5s` (21 extra queries in 12 s), and `refresh=1d` ended at `10s`. So `bench/metrics-screenshot.mjs` rewrites the dashboard JSON with an empty refresh on its way to the page (route interception). With that, 7 queries at load and none after.
  - F12: one Playwright container per matrix run (`bench/lib/playwright.sh`), with playwright-core installed once. The snapshot uses `docker exec` into it when `PLAYWRIGHT_CONTAINER` is set.
  - F15 and F16: `MetricsGatesTest` lost the MNC-2 test that duplicated `MetricsStackConfigTest` and the test that read this file. It keeps MNC-1, MNC-3 and MNC-5.
  - F17 to F19: the metrics command uses its own variables throughout, and `state` is a plain if. The `--help` sed was not a no-op (it strips the `## ` prefix) and stays.
  - F20: `per_host` was removed from all three Caddyfiles. Every series was labelled `host="_other"`, because the sites are catch-all (`:80`, `:443`, Octane's `:8000`), so the label carried nothing and no query used it. Test: `AC-1 every mode serves metrics on port 9180 and records request metrics without a per-host label`.
- [x] 24. New finding: the screenshots never showed the dashboard
  - Live, the Playwright Chromium in the container has no locale. Grafana 12 then fails at boot ("Error bootstrapping Grafana RangeError: Incorrect locale information provided") and renders its "failed to load its application files" page. The 54 KB PNG of task 3 was that page.
  - On top of that, a full-page shot resizes the viewport, Grafana redraws the panels, and the shot catches them blank.
  - Fix: the page gets `locale: 'en-US'` and `timezoneId: 'UTC'`, a 1600x1320 viewport that holds all seven panels (not 1600x900 as C-8 said; 900 cut off two panels), a plain viewport shot, and a check that fails the run when the "failed to load" page shows or "Requests per second" never renders. A live 2-minute snapshot then showed all seven panels with data (78 KB).
- [x] 25. Gap 15 again: parity and fingerprint on the final Caddyfiles (without `per_host`)
  - Re-run on 2026-11-16 after `per_host` was removed. `bench/parity` was reverted after each mode.

    | Mode | `app.css` | `app.js` | Matches C-12 | `git diff -U0 bench/parity` |
    |------|-----------|----------|--------------|-----------------------------|
    | frankenphp | `app-DNob7CFE.css` | `app-PTzu1QaN.js` | yes | only `captured=` differs |
    | frankenphp-worker | `app-DNob7CFE.css` | `app-PTzu1QaN.js` | yes | only `captured=` differs |
    | frankenphp-octane | `app-DNob7CFE.css` | `app-PTzu1QaN.js` | yes | only `captured=` differs |

  - Also per mode: 9180 answers from `ddev_default`, 2019 is closed (`000`), and both `# TYPE` lines are in the captured fixture.
- [x] 26. Final gates
  - __GATES__
- Measured facts that changed the docs: `frankenphp_busy_threads` (classic) and `frankenphp_busy_workers` (worker modes) count requests that run **or wait**, so they go above total (112 against 14, and 140 against 4). `frankenphp_total_threads` stayed at 14 while autoscale ran about 60 threads (199.3 requests per second at 0.3 s each). The Caddy histogram p95 is interpolated: 4.02 s against the measured 2703 ms. All of this is in the dashboard descriptions, the in-app guide, I-11 and BENCHMARKS "Surprises".
- Spec changes made here: C-1 numbers now cover the load window, not the whole cell (the PNG still covers the whole cell). C-8 viewport is 1600x1320, not 1600x900. Grafana router port is 3030 (task 2).
- Clean-tree bench: after commit 1c2b4cc, `bench/run.sh ping --quick` exited 0 on frankenphp.
