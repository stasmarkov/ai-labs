# Rename to ai-lab — Tasks

**Spec**: spec-20261110-1014-rename-ai-lab | **Size**: M | **Tasks**: 6

## Phase 1: Rename with data

- [ ] 1. Export, rename, import, verify (devops)
  Covers: AC-1, MNC-1, MNC-3, MNC-4
  - [ ] 1.1 `bench/rename-project.sh`: export (gitignored path), counts JSON, stop, `name: ai-lab`, start, import, counts compared, `ddev server default`, `ddev setup`; prints the old-project delete command without running it
  - [ ] 1.2 **Verify**: counts equal; 5 sample listings equal JSON; `ddev describe` shows ai-lab; `ddev server --check default` exit 0; keys unchanged

## Phase 2: Names everywhere

- [ ] 2. Code, scripts, tests (backend, bench)
  Covers: AC-2, MNC-2
  - [ ] 2.1 APP_NAME/APP_URL in `.env.example`; server command default host; tests read the URL from config or one constant; scripts' URLs; specs' run/command/manual lines
  - [ ] 2.2 **Verify**: `RenameTest` asserts no `interview.ddev.site` outside the allowed history paths and no AutoPulse outside the rename note and history

## Phase 3: Landing page and docs

- [ ] 3. Home page (frontend)
  Covers: AC-3
  - [ ] 3.1 Inertia `Home` page on the lab entry, generated capability map (lab link, guide anchor, code path), run steps, server modes, numbers and guide links
  - [ ] 3.2 **Verify**: `HomeLandingTest` (props, every link resolves to a route, guide anchor or file); typecheck, build; browser check at 1440 and 390

- [ ] 4. README and docs (docs)
  Covers: AC-4
  - [ ] 4.1 README for learners; `CLAUDE.md`, guide intro, `BENCHMARKS.md` top note, product brief and backlog names
  - [ ] 4.2 **Verify**: doc test for README content; `LearningGuideTest` green

## Phase 4: Checks

- [ ] 5. Parity and fingerprint (checks)
  Covers: AC-5, MNC-2
  - [ ] 5.1 Re-baseline parity once for `/` (reason in BENCHMARKS "Response parity"); fingerprint unchanged; full suite, Pint, PHPStan, typecheck, build
  - [ ] 5.2 **Verify**: outputs recorded here

- [ ] 6. Bench on a clean tree (checks)
  Covers: AC-5
  - [ ] 6.1 After the commit, `bench/run.sh ping --quick` exit 0 on the default server at the new URL
  - [ ] 6.2 **Verify**: recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | project renamed, data intact | `manual-test.md#step-1` | manual | 1 |
| AC-2 | no stale names outside history | `tests/Feature/RenameTest.php` | test | 2 |
| AC-3 | landing page and links | `tests/Feature/HomeLandingTest.php` | test | 3 |
| AC-4 | README for learners | `tests/Feature/ReadmeTest.php` | test | 4 |
| AC-5 | checks | `manual-test.md#step-3` | manual | 5, 6 |
| MNC-1 | DB identical | `manual-test.md#step-1` | manual | 1 |
| MNC-2 | other routes and fingerprint | `manual-test.md#step-3` | manual | 5 |
| MNC-3 | keys kept, suite offline | `manual-test.md#step-1` | manual | 1 |
| MNC-4 | config.yaml only name | `manual-test.md#step-3` | manual | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
