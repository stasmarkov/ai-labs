# FrankenPHP default — Tasks

**Spec**: spec-20261109-1040-frankenphp-default | **Size**: M | **Tasks**: 5

## Phase 1: Commands

- [ ] 1. Default in the server command and ddev setup (devops)
  Covers: AC-1, AC-2, MNC-1, MNC-3
  - [ ] 1.1 `DEFAULT_SERVER`, `--default`, `default` target, no-args output and not-set-up hint in `.ddev/commands/host/server`
  - [ ] 1.2 `.ddev/commands/host/setup`: start, switch to default, keys if missing, migrate, idempotent
  - [ ] 1.3 **Verify**: `ServerDefaultCommandTest` with stubbed ddev; live: move overlay aside, `ddev setup`, hub answers; second run says already set up; restore

## Phase 2: Scripts and tests

- [ ] 2. Bench restore targets (bench)
  Covers: AC-3, MNC-3
  - [ ] 2.1 `bench/lib/default-server.sh` (`restore_default_server`, read once, exit 2 if empty); every restore in bench/*.sh and bench/lcp.mjs uses it; comments and usage updated; measurement lists unchanged; list the tests that pinned nginx-fpm restores (grep) and update them
  - [ ] 2.2 **Verify**: existing Bench*Test updated for the restore target; grep check in a test

## Phase 3: Docs

- [ ] 3. README, CLAUDE.md, guide, BENCHMARKS, manual tests (docs)
  Covers: AC-4, MNC-4
  - [ ] 3.1 Default-server text and `ddev setup`; guide section 2 note; BENCHMARKS top note; manual-test plans' resting server lines
  - [ ] 3.2 **Verify**: doc test (README and CLAUDE.md never call nginx-fpm the default; mention ddev setup)

## Phase 4: Checks

- [ ] 4. Switch this machine and run the checks (checks)
  Covers: AC-5, MNC-1, MNC-2, MNC-5
  - [ ] 4.1 `ddev server default`; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `git diff HEAD -- .ddev/config.yaml` empty
  - [ ] 4.2 **Verify**: outputs recorded here

- [ ] 5. Bench on a clean tree (checks)
  Covers: AC-5
  - [ ] 5.1 After the commit, `bench/run.sh ping --quick` on the default server, exit 0
  - [ ] 5.2 **Verify**: recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | --default, default, hint | `tests/Feature/ServerDefaultCommandTest.php` | test | 1 |
| AC-2 | ddev setup idempotent, hub answers | `tests/Feature/ServerDefaultCommandTest.php`, `manual-test.md#step-1` | test | 1 |
| AC-3 | scripts restore the default | `tests/Feature/BenchScriptsTest.php` | test | 2 |
| AC-4 | docs name the default | `tests/Feature/DocsDefaultServerTest.php` | test | 3 |
| AC-5 | end state and checks | `manual-test.md#step-3` | manual | 4, 5 |
| MNC-1 | config.yaml unchanged | `manual-test.md#step-3` | manual | 4 |
| MNC-2 | parity and fingerprint | `manual-test.md#step-3` | manual | 4 |
| MNC-3 | every server reachable | `manual-test.md#step-2` | manual | 1, 2 |
| MNC-4 | history unchanged | `tests/Feature/BenchmarksDocTest.php` | test | 3 |
| MNC-5 | suite offline | `verify full suite green` | test | 4 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
