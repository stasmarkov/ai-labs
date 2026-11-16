# Metrics dashboard for the FrankenPHP family — Tasks

**Spec**: spec-20261116-1120-metrics-dashboard | **Size**: M | **Tasks**: 6

## Phase 1: Endpoint and stack

- [ ] 1. Metrics listener on all three modes (devops)
  Covers: AC-1, NFR-1, MNC-2
  - [ ] 1.1 Add the `:9180 { metrics }` block for classic and worker, plus the Octane mechanism. Turn on per-host metrics where supported.
  - [ ] 1.2 **Verify**:
    - on each mode, `ddev exec curl -s localhost:9180/metrics | grep frankenphp_total_threads`;
    - from another container on `ddev_default`, 9180 answers and 2019 does not;
    - parity is unchanged on each mode.
    The results are recorded here.

- [ ] 2. Prometheus, Grafana, the dashboard and `ddev metrics` (devops)
  Covers: AC-2, AC-3, AC-6, NFR-2, MNC-1, MNC-4
  - [ ] 2.1 Add:
    - `.ddev/docker-compose.metrics.yaml` (profile `metrics`, pinned digests, `mem_limit`);
    - `.ddev/metrics/prometheus.yml`;
    - the Grafana provisioning (datasource and dashboard JSON);
    - `.ddev/commands/host/metrics` (`on`, `off`, `status`).
  - [ ] 2.2 **Verify**: `tests/Feature/MetricsStackConfigTest.php`:
    - the YAML and JSON parse;
    - the images are pinned;
    - the profile is set;
    - no password;
    - `mem_limit` is set;
    - every panel query names a metric found in the committed fixture `tests/Fixtures/metrics/frankenphp-metrics.txt`;
    - there is no `admin` exposure.
    Also: `ddev metrics on`, then the dashboard live; `ddev start` without the profile starts no metrics containers.

## Phase 2: Measure and document

- [ ] 3. Snapshot script (checks)
  Covers: AC-4
  - [ ] 3.1 Add `bench/metrics-snapshot.sh <cell> <from-ms> <to-ms>` (C-5 queries, C-8 screenshot) and the driver `bench/metrics-matrix.sh` (C-1 cells, window timing, the stack-off control cell).
  - [ ] 3.2 **Verify**: `bash -n`, `--help`, and a quick run writes the JSON and PNG.

- [ ] 4. Re-run the slow-I/O scenarios (checks)
  Covers: AC-4
  - [ ] 4.1 On a clean committed tree, run `bench/metrics-matrix.sh` (8 cells plus the control, full BR-21 method), then commit the results.
  - [ ] 4.2 **Verify**: every cell has its slow-load JSON, metrics JSON and PNG. `.env` is restored and the starting server is restored.

- [ ] 5. Docs (docs)
  Covers: AC-5
  - [ ] 5.1 Add the BENCHMARKS section (the table, 3 to 4 screenshots, what the numbers say, surprises, the story), the learning guide I-11 replacing the reserved bullet, and README.
  - [ ] 5.2 **Verify**: `DocsLinksTest`, `LearningGuideTest` and `BenchmarksDocTest`.

- [ ] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-5
  - [ ] 6.1 Run the full suite, Pint and PHPStan; check the fingerprint and parity on all three modes; commit, ping and push.
  - [ ] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | metrics on 9180, admin not exposed | `manual-test.md#step-2` | manual | 1 |
| AC-2 | ddev metrics on/off, opt-in | `manual-test.md#step-1` | manual | 2 |
| AC-3 | provisioned dashboard, anonymous viewer | `tests/Feature/MetricsStackConfigTest.php` | test | 2 |
| AC-4 | per-cell result, export, screenshot | `manual-test.md#step-3` | manual | 3, 4 |
| AC-5 | BENCHMARKS and guide | `tests/Feature/DocsLinksTest.php` | test | 5 |
| AC-6 | no secrets | `tests/Feature/MetricsStackConfigTest.php` | test | 2 |
| MNC-1 | default start unchanged | `manual-test.md#step-1` | manual | 2 |
| MNC-2 | pages, fingerprint, parity | `manual-test.md#step-4` | manual | 1, 6 |
| MNC-3 | bench scripts unchanged | verify existing bench tests pass unchanged | test | 6 |
| MNC-4 | config.yaml untouched | `manual-test.md#step-4` | manual | 6 |
| MNC-5 | suite needs no stack | `tests/Feature/MetricsStackConfigTest.php` | test | 2 |
| NFR-1 | exposure | `tests/Feature/MetricsStackConfigTest.php` | test | 1, 2 |
| NFR-2 | footprint | `tests/Feature/MetricsStackConfigTest.php` | test | 2 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
