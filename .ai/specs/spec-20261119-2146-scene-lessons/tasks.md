# AI lab scene lessons — Tasks

**Spec**: spec-20261119-2146-scene-lessons | **Size**: L | **Tasks**: 7

## Phase 1: Engine

- [ ] 1. Lesson format, repository and validation (laravel)
  Covers: AC-4, AC-5, AC-8
  - [ ] 1.1 Build the lesson and course schema, `LessonRepository`/`LessonService` and DTOs, number resolution from `bench/results`, and per-request memoization.
  - [ ] 1.2 **Verify**: `tests/Feature/LessonRepositoryTest.php`:
    - broken fixtures, one per rule;
    - an unresolvable number fails;
    - a lesson that resolves.

- [ ] 2. Routes, course page and lesson props (laravel)
  Covers: AC-1, AC-8, NFR-2, MNC-4
  - [ ] 2.1 Add `/ai/learn` and `/ai/learn/{module}/{lesson}/{step?}`, the navigation entry, links from guides, and the "coming soon" entries.
  - [ ] 2.2 **Verify**: `tests/Feature/LessonPagesTest.php`:
    - 200 and 404;
    - the props shape;
    - payload < 60 KB;
    - no database writes and no HTTP calls.

## Phase 2: Player

- [ ] 3. Scene and player components (vue)
  Covers: AC-2, AC-3, AC-6, AC-7, NFR-1, NFR-3
  - [ ] 3.1 Build `SceneCanvas` (SVG plus requestAnimationFrame packets, transitions, reduced motion, fullscreen), `KpiStrip`, `StepPanel`, `StepBar` (keyboard), `LearnList`, `CourseSidebar` (`localStorage` progress, reset) and `Lab/Lesson.vue`.
  - [ ] 3.2 **Verify**:
    - typecheck and build;
    - static tests in `tests/Feature/LessonPagesTest.php` (aria-live, reduced-motion branch, keyboard handler ignores inputs);
    - a Playwright walk recorded here.

## Phase 3: Content and closure

- [ ] 4. Pilot lesson and course outline (content)
  Covers: AC-1, AC-2, AC-4
  - [ ] 4.1 Write `resources/lessons/course.php` (3 modules, 8 lessons) and `running-models/one-model-many-users.php` (10 steps, numbers via file paths).
  - [ ] 4.2 **Verify**: `tests/Feature/LessonContentTest.php`:
    - every KPI and `{num}` resolves;
    - each outcome is taught;
    - spot-check values against BENCHMARKS.md.

- [ ] 5. Close SL-54 (docs)
  Covers: AC-9
  - [ ] 5.1 Write the SL-54 `decisions.md` pick, check task 8, set the status to done, and mark the backlog shipped.

- [ ] 6. Guide, docs, audit (docs)
  Covers: AC-10, MNC-2
  - [ ] 6.1 Add the `lessons` foundation guide (six tabs plus teach), the learning guide section and README. Extend `NoReferencesInUiTest` and ui-audit to the `/ai/learn` pages.
  - [ ] 6.2 **Verify**: `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, `DocsLinksTest`; ui-audit exits 0.

- [ ] 7. Gates and ship (checks)
  Covers: MNC-1, MNC-3
  - [ ] 7.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | course page | `tests/Feature/LessonPagesTest.php` | test | 2, 4 |
| AC-2 | lesson page parts | `tests/Feature/LessonPagesTest.php` | test | 2, 3 |
| AC-3 | step navigation and keys | `manual-test.md#step-1` | manual | 3 |
| AC-4 | numbers from committed files | `tests/Feature/LessonContentTest.php` | test | 1, 4 |
| AC-5 | schema validation | `tests/Feature/LessonRepositoryTest.php` | test | 1 |
| AC-6 | learn list and progress | `manual-test.md#step-1` | manual | 3 |
| AC-7 | reduced motion, keyboard, aria-live | `manual-test.md#step-2` | manual | 3 |
| AC-8 | 404s | `tests/Feature/LessonPagesTest.php` | test | 2 |
| AC-9 | SL-54 closed | `manual-test.md#step-4` | manual | 5 |
| AC-10 | guide, docs, audit | `tests/Feature/GuideContentTest.php` | test | 6 |
| MNC-1 | existing pages unchanged | verify existing guide and lab tests pass unchanged | test | 7 |
| MNC-2 | no references | `tests/Feature/NoReferencesInUiTest.php` | test | 6 |
| MNC-3 | fingerprint, parity | `manual-test.md#step-4` | manual | 7 |
| MNC-4 | no deps, tables, calls | `tests/Feature/LessonPagesTest.php` | test | 2 |
| NFR-1 | ≥ 50 fps | `manual-test.md#step-3` | manual | 3 |
| NFR-2 | payload < 60 KB | `tests/Feature/LessonPagesTest.php` | test | 2 |
| NFR-3 | accessibility | `manual-test.md#step-2` | manual | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
