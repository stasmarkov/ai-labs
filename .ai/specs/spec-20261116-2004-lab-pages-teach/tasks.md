# Lab pages that teach, with no broken layouts — Tasks

**Spec**: spec-20261116-2004-lab-pages-teach | **Size**: M | **Tasks**: 6

## Phase 1: Check first

- [x] 1. UI audit script (checks)
  Covers: AC-2
  - [x] 1.1 Add `bench/ui-audit.mjs` and a usage note in its header, with these checks:
    - overlap (ignoring empty live regions);
    - narrow controls;
    - horizontal scroll;
    - a missing teach block;
    - a missing guide link;
    - a field with no placeholder or hint.
    Screenshots go to the gitignored `bench/results/raw/ui-audit/`.
  - [x] 1.2 **Verify**: today it fails, on Moderation and Describe at least. Record the output here.
    Output of `node bench/ui-audit.mjs` (2026-11-16, before any fix; exit 1, 36 page-widths checked, 30 failing):
    ```
    FAIL /ai/moderation @1440: overlap select#moderation-pick x button "reported vin" (10x40 px); narrow select#moderation-pick is 26 px, needs 160
    FAIL /ai/describe @1440: narrow select#describe-listing is 29 px, needs 160
    FAIL /ai/describe @1024: overlap select#describe-listing x input#describe-free-id (10x40 px); narrow select#describe-listing 26 px,
         input#describe-free-id 90 px, select#describe-provider 100 px
    FAIL /ai/docs @1440 and @1024: narrow input#docs-k is 112 px, needs 120
    FAIL every lab page in the C-1 list, both widths: no-teach (no [data-lab-teach]), no-guide (no [data-guide-link])
    FAIL /ai/moderation 13, /ai/playground 11, /ai/settings 26 findings (mostly no-hint)
    ok   /ai, /ai/guides, /ai/guides/describe at both widths
    36 page(s) checked, 30 failing
    ```

## Phase 2: Fix and teach

- [x] 2. Layout fixes (frontend)
  Covers: AC-1
  - [x] 2.1 Put the Moderation and Describe pickers and examples on separate rows, and give the selects a minimum width.
  - [x] 2.2 **Verify**: the audit shows no layout failure on either page.

- [x] 3. Teach data and guide sections (backend, docs)
  Covers: AC-5, AC-6
  - [x] 3.1 Add:
    - the optional `build`, `watch` and `elsewhere` guide sections, with engine support and rendering in `Guide.vue`;
    - `GuideRepository::teach()`;
    - content for every feature guide and the relevant foundation guides.
  - [x] 3.2 **Verify**: `GuideContentTest`, `GuidePageTest` and `NoReferencesInUiTest`, extended for the new sections.

- [x] 4. `LabTeach` on every page (frontend, backend)
  Covers: AC-3, AC-4, NFR-1
  - [x] 4.1 Add:
    - the `LabTeach` component;
    - the controllers pass `teach`;
    - existing "How it works" blocks moved into it;
    - placeholders and hints on every field;
    - example chips where missing;
    - guide links on Changes, Settings, Playground and Research.
  - [x] 4.2 **Verify**: `tests/Feature/LabTeachTest.php`, typecheck and build.

## Phase 3: Run and ship

- [x] 5. Audit run (checks)
  Covers: AC-2
  - [x] 5.1 **Verify**: run `bench/ui-audit.mjs` on all lab pages at 1440 and 1024 px, and record the output here (exit 0).
    Output of `node bench/ui-audit.mjs` (2026-11-16, after tasks 2 to 4; exit 0):
    ```
    ok   every lab page in the C-1 list, /ai, /ai/guides and /ai/guides/describe at 1440
    ok   every lab page in the C-1 list, /ai, /ai/guides and /ai/guides/describe at 1024
    36 page(s) checked, 0 failing
    ```

- [x] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | pickers not collapsed | `tests/Feature/LabLayoutTest.php`; `manual-test.md#step-1` | test | 2 |
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
