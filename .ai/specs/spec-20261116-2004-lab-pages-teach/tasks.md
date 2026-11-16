# Lab pages that teach, with no broken layouts — Tasks

**Spec**: spec-20261116-2004-lab-pages-teach | **Size**: M | **Tasks**: 6

## Phase 1: Check first

- [ ] 1. UI audit script (checks)
  Covers: AC-2
  - [ ] 1.1 Add `bench/ui-audit.mjs` and a usage note in its header, with these checks:
    - overlap (ignoring empty live regions);
    - narrow controls;
    - horizontal scroll;
    - a missing teach block;
    - a missing guide link;
    - a field with no placeholder or hint.
    Screenshots go to the gitignored `bench/results/raw/ui-audit/`.
  - [ ] 1.2 **Verify**: today it fails, on Moderation and Describe at least. Record the output here.

## Phase 2: Fix and teach

- [ ] 2. Layout fixes (frontend)
  Covers: AC-1
  - [ ] 2.1 Put the Moderation and Describe pickers and examples on separate rows, and give the selects a minimum width.
  - [ ] 2.2 **Verify**: the audit shows no layout failure on either page.

- [ ] 3. Teach data and guide sections (backend, docs)
  Covers: AC-5, AC-6
  - [ ] 3.1 Add:
    - the optional `build`, `watch` and `elsewhere` guide sections, with engine support and rendering in `Guide.vue`;
    - `GuideRepository::teach()`;
    - content for every feature guide and the relevant foundation guides.
  - [ ] 3.2 **Verify**: `GuideContentTest`, `GuidePageTest` and `NoReferencesInUiTest`, extended for the new sections.

- [ ] 4. `LabTeach` on every page (frontend, backend)
  Covers: AC-3, AC-4, NFR-1
  - [ ] 4.1 Add:
    - the `LabTeach` component;
    - the controllers pass `teach`;
    - existing "How it works" blocks moved into it;
    - placeholders and hints on every field;
    - example chips where missing;
    - guide links on Changes, Settings, Playground and Research.
  - [ ] 4.2 **Verify**: `tests/Feature/LabTeachTest.php`, typecheck and build.

## Phase 3: Run and ship

- [ ] 5. Audit run (checks)
  Covers: AC-2
  - [ ] 5.1 Run `bench/ui-audit.mjs` on all lab pages at 1440 and 1024 px, and record the output here (exit 0).

- [ ] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 6.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | pickers not collapsed | `manual-test.md#step-1` | manual | 2 |
| AC-2 | audit clean on all pages | `manual-test.md#step-1` | manual | 1, 5 |
| AC-3 | teach block on every page | `tests/Feature/LabTeachTest.php` | test | 4 |
| AC-4 | examples and hints | `tests/Feature/LabTeachTest.php`; `manual-test.md#step-1` | test | 4, 5 |
| AC-5 | guides show the new sections | `tests/Feature/GuidePageTest.php` | test | 3 |
| AC-6 | guide tests pass | `tests/Feature/GuideContentTest.php` | test | 3 |
| MNC-1 | behaviour unchanged | verify existing feature tests pass unchanged | test | 6 |
| MNC-2 | public pages, fingerprint, parity | `manual-test.md#step-3` | manual | 6 |
| MNC-3 | no references | `tests/Feature/NoReferencesInUiTest.php` | test | 3, 4 |
| MNC-4 | existing guide tabs | `tests/Feature/GuideContentTest.php` | test | 3 |
| NFR-1 | accessibility | `tests/Feature/LabTeachTest.php`; `manual-test.md#step-2` | test | 4 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
