# Worker-mode developer experience — Tasks

**Spec**: spec-20261117-1422-worker-dx | **Size**: M | **Tasks**: 5

## Phase 1: Watch, hot reload, Xdebug

- [ ] 1. Watch for both worker modes (devops)
  Covers: AC-1, AC-2, AC-3, AC-5, MNC-1, MNC-2
  - [ ] 1.1 Add:
    - the `watch` directive with `{$WORKER_WATCH}`;
    - `config/octane.php` watch paths;
    - `ddev dev-watch on|off|status` through a gitignored overlay;
    - the hot-reload check and wiring if available.
  - [ ] 1.2 **Verify**: `tests/Feature/DevWatchCommandTest.php`, a static check of the overlays, the Caddyfile and the command. Then live: an edit is served without a restart on both modes, and parity is unchanged with watch off.

- [ ] 2. Xdebug in Octane (devops)
  Covers: AC-4, MNC-3
  - [ ] 2.1 Add the trigger-mode settings for the worker modes, `bench/xdebug-check.php` (a DBGp listener), and the guide steps.
  - [ ] 2.2 **Verify**: live, a request with the trigger opens a connection and one without it does not; `ddev xdebug off` afterwards.

## Phase 2: Measure and document

- [ ] 3. Reload latency (checks)
  Covers: AC-6, NFR-1, NFR-2
  - [ ] 3.1 Add `bench/reload-latency.sh` (5 ways of working, 10 trials each, the Mutagen delay) and the `/debug/reload-probe` route. Run it.
  - [ ] 3.2 **Verify**: `tests/Feature/BenchReloadLatencyTest.php` (with stubs, the restore on interrupt), and the result files.

- [ ] 4. Docs and guide (docs)
  Covers: AC-7
  - [ ] 4.1 Add the BENCHMARKS section, the learning guide I-13, the `resources/guides/worker-dx.php` guide and README.
  - [ ] 4.2 **Verify**: `DocsLinksTest`, `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, and ui-audit on `/ai/guides` and `/ai/guides/worker-dx`.

- [ ] 5. Gates and ship (checks)
  Covers: MNC-1, MNC-4
  - [ ] 5.1 Run the full suite, Pint and PHPStan; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | worker watch < 2 s median | `manual-test.md#step-2` | manual | 1, 3 |
| AC-2 | octane --watch recorded | `manual-test.md#step-2` | manual | 1, 3 |
| AC-3 | no watch = never, restart timed | `manual-test.md#step-2` | manual | 3 |
| AC-4 | Xdebug trigger in Octane | `manual-test.md#step-4` | manual | 2 |
| AC-5 | dev-watch command | `tests/Feature/DevWatchCommandTest.php` | test | 1 |
| AC-6 | reload table and story | `tests/Feature/BenchReloadLatencyTest.php` | test | 3 |
| AC-7 | guide and docs | `tests/Feature/GuideContentTest.php` | test | 4 |
| MNC-1 | watch off unchanged | `manual-test.md#step-1` | manual | 1, 5 |
| MNC-2 | config and .env untouched | `tests/Feature/DevWatchCommandTest.php` | test | 1 |
| MNC-3 | no debugger connect when off | `manual-test.md#step-4` | manual | 2 |
| MNC-4 | suite offline | `tests/Feature/BenchReloadLatencyTest.php` | test | 3 |
| NFR-1 | restore on exit | `tests/Feature/BenchReloadLatencyTest.php` | test | 3 |
| NFR-2 | watcher idle CPU | `manual-test.md#step-3` | manual | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
