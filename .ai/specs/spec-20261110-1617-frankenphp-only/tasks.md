# FrankenPHP family only — Tasks

**Spec**: spec-20261110-1617-frankenphp-only | **Size**: M | **Tasks**: 7

## Phase 1: Server command and DDEV files

- [ ] 1. `ddev server` narrowed (devops)
  Covers: AC-1, MNC-1, MNC-6
  - [ ] 1.1 Set `SUPPORTED` to the three modes, remove the removed servers' cases, add the removal message, make `--current` say "not set up" with no overlay, and update the help text.
  - [ ] 1.2 **Verify**: `DdevServerCommandTest`, `ServerDefaultCommandTest` and `ServerSwitchChecksTest` updated; a removed name exits 1 and leaves the overlay untouched.

- [ ] 2. DDEV leftovers (devops)
  Covers: AC-6, MNC-3
  - [ ] 2.1 Delete `.ddev/servers/openlitespeed/`; strip apache lines from `.ddev/web-build/Dockerfile` (delete the file if empty); trim `.gitignore` lines nothing writes any more.
  - [ ] 2.2 **Verify**: the `FrankenphpOnlyTest` web-build case; `git diff` shows no change under `.ddev/servers/frankenphp*`.

## Phase 2: Bench and parity

Order: 1, 2, 3, 5, 6, then 4, then 7 (parity is captured last; clarity answer 4). Before task 1, record `git rev-parse HEAD` here as the last commit with the old servers.

- [ ] 3. Bench scripts (bench)
  Covers: AC-2, MNC-4
  - [ ] 3.1 Narrow matrices, suites and loops to the three modes; remove the server-specific branches; `summary.php` still reads historical results.
  - [ ] 3.2 **Verify**: the `Bench*Test` files updated; a removed server passed to a script exits non-zero; `BenchSummaryTest` renders a committed historical result.

- [ ] 4. Parity (checks)
  Covers: AC-3, MNC-2
  - [ ] 4.1 Delete the three removed baselines; capture frankenphp, frankenphp-worker and frankenphp-octane after the code change.
  - [ ] 4.2 **Verify**: `compare frankenphp frankenphp-worker` and `compare frankenphp frankenphp-octane` print identical; route lines equal today's frankenphp snapshot.

## Phase 3: Tests, docs and guides

- [ ] 5. Guard test and test cleanup (backend)
  Covers: AC-4
  - [ ] 5.1 Add `tests/Feature/FrankenphpOnlyTest.php` with the allowlist; update or delete tests whose subject was a removed server.
  - [ ] 5.2 **Verify**: the guard test is green; planting `nginx-fpm` in `bench/run.sh` makes it red (revert after).

- [ ] 6. Docs and guides (docs)
  Covers: AC-5, MNC-4
  - [ ] 6.1 README, `CLAUDE.md`, getting-started, architecture, the `BENCHMARKS.md` top note (naming the last commit with the old servers), learning guide history lines and section 2, the streaming guide.
  - [ ] 6.2 **Verify**: `ReadmeSwitchingServersTest`, `LearningGuideTest`, `GuideContentTest` and `HomeLandingTest` (server modes pinned) are green.

- [ ] 7. Checks (checks)
  Covers: MNC-1, MNC-5
  - [ ] 7.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; `ddev server --check default` exit 0; `bench/run.sh ping --quick` exit 0 after the commit.
  - [ ] 7.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | server menu has three modes; removed names refused | `tests/Feature/DdevServerCommandTest.php` | test | 1 |
| AC-2 | bench uses only the three modes | `tests/Feature/BenchSuiteServersTest.php` | test | 3 |
| AC-3 | parity on three modes, identical | `manual-test.md#step-2` | manual | 4 |
| AC-4 | no removed identifiers outside history | `tests/Feature/FrankenphpOnlyTest.php` | test | 5 |
| AC-5 | docs and guides show three modes | `tests/Feature/ReadmeSwitchingServersTest.php` | test | 6 |
| AC-6 | web image has no apache modules | `tests/Feature/FrankenphpOnlyTest.php` | test | 2 |
| MNC-1 | default server behaviour | `tests/Feature/ServerDefaultCommandTest.php` | test | 1, 7 |
| MNC-2 | routes identical on three modes | `manual-test.md#step-2` | manual | 4 |
| MNC-3 | worker and Octane configs untouched | `manual-test.md#step-3` | manual | 2 |
| MNC-4 | history kept | `tests/Feature/BenchmarksDocTest.php` | test | 3, 6 |
| MNC-5 | main fingerprint | `manual-test.md#step-3` | manual | 7 |
| MNC-6 | config.yaml unchanged | `manual-test.md#step-3` | manual | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
