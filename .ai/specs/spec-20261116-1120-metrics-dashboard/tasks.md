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

- [ ] 4. Re-run the slow-I/O scenarios (checks)
  Covers: AC-4
  - [ ] 4.1 On a clean committed tree, run `bench/metrics-matrix.sh` (8 cells plus the control, full BR-21 method), then commit the results.
  - [ ] 4.2 **Verify**: every cell has its slow-load JSON, metrics JSON and PNG. `.env` is restored and the starting server is restored.

- [ ] 5. Docs (docs)
  Covers: AC-5
  - [ ] 5.1 Add the BENCHMARKS section (the table, 3 to 4 screenshots, what the numbers say, surprises, the story), the learning guide I-11 replacing the reserved bullet, and README.
  - [ ] 5.2 **Verify**: `DocsLinksTest`, `LearningGuideTest` and `BenchmarksDocTest`.

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
| MNC-2 | pages, fingerprint, parity | `manual-test.md#step-4`, `tests/Feature/MetricsGatesTest.php` (MNC-2 test) | manual, test | 1, 6 |
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

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green

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
