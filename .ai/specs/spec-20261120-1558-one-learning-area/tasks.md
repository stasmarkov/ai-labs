# One learning area for guides and scene lessons — Tasks

**Spec**: spec-20261120-1558-one-learning-area | **Size**: L | **Tasks**: 9

## Phase 1: Data and backend

- [ ] 1. Course map: `references` per module, two new modules, validation (backend)
  Covers: AC-2
  - [ ] 1.1 Failing tests in `LessonRepositoryTest`: unknown reference key, key in two modules, key both referenced and lesson-linked, guide reachable by neither → `InvalidLessonException` naming the key; the real course passes (every guide except `lessons` owned once)
  - [ ] 1.2 Add `references` to course.php (Scope 1 placement) and the validation
  - [ ] 1.3 **Verify**: tests green; gates green

- [ ] 2. Guides index redirect, home link, format-trial entry (backend)
  Covers: AC-1, AC-8, MNC-3
  - [ ] 2.1 Failing tests: `GET /ai/guides` 301 → `/ai/learn`; home `guidesHref` `/ai/learn`; photos guide prop exposes the trial link; format URLs still 200
  - [ ] 2.2 Route to redirect, remove `Guides.vue` page use, update trial breadcrumbs, update `HomeLandingTest`, `GuideFormatPagesTest`, `GuideContentTest`, `NoReferencesInUiTest`, `CloudKeysNeverLeakTest`
  - [ ] 2.3 **Verify**: tests green

- [ ] 3. Topic data for pages: lesson → guides, guide → lessons + module, course props on guide pages (backend)
  Covers: AC-3, AC-4, AC-5, AC-6, NFR-1
  - [ ] 3.1 Failing tests in `LessonPagesTest` / `GuidePageTest`: course props carry references and lesson guides; lesson page carries its guides; guide page carries module, lessons and course; payload < 60 000
  - [ ] 3.2 Implement (scoped memoized map service)
  - [ ] 3.3 **Verify**: tests green

- [ ] 4. Grouped navigation (backend)
  Covers: AC-7, MNC-4
  - [ ] 4.1 Failing tests: `lab.navigation` groups and order; conditional items appear/disappear as today; empty group omitted
  - [ ] 4.2 Implement in `LabOverviewService`
  - [ ] 4.3 **Verify**: tests green

## Phase 2: Frontend

- [ ] 5. `TopicHeader.vue` on lesson and guide pages (frontend)
  Covers: AC-4, AC-5, NFR-2, MNC-1, MNC-2
  - [ ] 5.1 Build the header and switch; replace lesson breadcrumb/"Read the guide" and the guide bottom lessons nav
  - [ ] 5.2 **Verify**: typecheck, build; lesson keys and guide tabs unchanged by hand at 1440/1024

- [ ] 6. Course page and course sidebar with reference topics; guide pages at 1440 px with the rail (frontend)
  Covers: AC-3, AC-6, NFR-2
  - [ ] 6.1 Update `Learn.vue`, `CourseSidebar.vue`, `Guide.vue`, `LabLayout.vue` width rule
  - [ ] 6.2 **Verify**: typecheck, build

- [ ] 7. Grouped lab sidebar in `LabLayout.vue` (frontend)
  Covers: AC-7, NFR-2
  - [ ] 7.1 Group labels, current "Course" on learn and guide pages, compact wrap below md
  - [ ] 7.2 **Verify**: typecheck, build; 1440 and 1024 screenshots

## Phase 3: Docs and checks

- [ ] 8. Docs, guide, backlog, audit discovery
  Covers: AC-9, AC-10, MNC-5, MNC-6
  - [ ] 8.1 Update `lessons` guide, README, `docs/learning-guide.md`, backlog SL-57/SL-58; `bench/ui-audit.mjs` discovers guides from `/ai/learn`; `UiAuditGuidePagesTest`
  - [ ] 8.2 **Verify**: `node bench/ui-audit.mjs` 0 failing; fingerprint unchanged; parity only `captured=`

- [ ] 9. Manual: walk manual-test.md
  Covers: AC-1..AC-10, NFR-2
  - [ ] 9.1 **Verify**: every step passes

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | /ai/guides 301 → /ai/learn | `tests/Feature/GuidePageTest.php::guides index redirects to learn` | test | 2 |
| AC-2 | every guide owned once, validation | `tests/Feature/LessonRepositoryTest.php::course references rules` | test | 1 |
| AC-3 | course page topics and links | `tests/Feature/LessonPagesTest.php::course lists lesson and reference topics` | test | 3, 6 |
| AC-4 | lesson topic header | `tests/Feature/LessonPagesTest.php::lesson page carries its guides` + `manual-test.md#step-2` | test+manual | 3, 5 |
| AC-5 | guide topic header | `tests/Feature/GuidePageTest.php::guide page carries topic and course` + `manual-test.md#step-3` | test+manual | 3, 5 |
| AC-6 | course sidebar on guide pages | `tests/Feature/GuidePageTest.php::guide page carries topic and course` | test | 3, 6 |
| AC-7 | grouped navigation | `tests/Feature/GuidePageTest.php::navigation is grouped` + `manual-test.md#step-4` | test+manual | 4, 7 |
| AC-8 | home link, format trial link | `tests/Feature/HomeLandingTest.php` | test | 2 |
| AC-9 | ui audit 0 failing | `manual-test.md#step-5` | manual | 8 |
| AC-10 | docs and backlog | `manual-test.md#step-6` | manual | 8 |
| MNC-1 | lessons unchanged | `tests/Feature/LessonPagesTest.php` | test | 5 |
| MNC-2 | guide tabs unchanged | `tests/Feature/GuidePageTest.php` | test | 5 |
| MNC-3 | format trial URLs | `tests/Feature/GuideFormatPagesTest.php` | test | 2 |
| MNC-4 | lab pages and availability | `tests/Feature/GuidePageTest.php::navigation is grouped` | test | 4 |
| MNC-5 | no refs, deps, DB | `tests/Feature/NoReferencesInUiTest.php` | test+manual | 8 |
| MNC-6 | fingerprint and parity | `manual-test.md#step-5` | manual | 8 |
| NFR-1 | payload < 60 000 | `tests/Feature/LessonPagesTest.php::course payload stays small` | test | 3 |
| NFR-2 | keyboard, aria-current, labels | `manual-test.md#step-4` | manual | 5, 6, 7 |

## Verification gates

- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev artisan test --compact`
- `ddev npm run typecheck`, `ddev npm run build`
- `node bench/ui-audit.mjs`, `bench/asset-fingerprint.sh`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
