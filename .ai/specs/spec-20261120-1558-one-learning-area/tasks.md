# One learning area for guides and scene lessons — Tasks

**Spec**: spec-20261120-1558-one-learning-area | **Size**: L | **Tasks**: 9

## Phase 1: Data and backend

- [x] 1. Course map: `references` per module, two new modules, validation (backend)
  Covers: AC-2
  - [x] 1.1 Failing tests in `LessonRepositoryTest`: unknown reference key, key in two modules, key both referenced and lesson-linked, guide reachable by neither → `InvalidLessonException` naming the key; the real course passes (every guide except `lessons` owned once)
  - [x] 1.2 Add `references` to course.php (Scope 1 placement) and the validation
  - [x] 1.3 **Verify**: tests green; gates green

- [x] 2. Guides index redirect, home link, format-trial entry (backend)
  Covers: AC-1, AC-8, MNC-3
  - [x] 2.1 Failing tests: `GET /ai/guides` 301 → `/ai/learn`; home `guidesHref` `/ai/learn`; photos guide prop exposes the trial link; format URLs still 200
  - [x] 2.2 Route to redirect, remove `Guides.vue` page use, update trial breadcrumbs, update `HomeLandingTest`, `GuideFormatPagesTest`, `GuideContentTest`, `NoReferencesInUiTest`, `CloudKeysNeverLeakTest`
  - [x] 2.3 **Verify**: tests green

- [x] 3. Topic data for pages: lesson → guides, guide → lessons + module, course props on guide pages (backend)
  Covers: AC-3, AC-4, AC-5, AC-6, NFR-1
  - [x] 3.1 Failing tests in `LessonPagesTest` / `GuidePageTest`: course props carry references and lesson guides; lesson page carries its guides; guide page carries module, lessons and course; payload < 60 000
  - [x] 3.2 Implement (scoped memoized map service)
  - [x] 3.3 **Verify**: tests green

- [x] 4. Grouped navigation (backend)
  Covers: AC-7, MNC-4
  - [x] 4.1 Failing tests: `lab.navigation` groups and order; conditional items appear/disappear as today; empty group omitted
  - [x] 4.2 Implement in `LabOverviewService`
  - [x] 4.3 **Verify**: tests green

## Phase 2: Frontend

- [x] 5. `TopicHeader.vue` on lesson and guide pages (frontend)
  Covers: AC-4, AC-5, NFR-2, MNC-1, MNC-2
  - [x] 5.1 Build the header and switch; replace lesson breadcrumb/"Read the guide" and the guide bottom lessons nav
  - [x] 5.2 **Verify**: typecheck, build; lesson keys and guide tabs unchanged by hand at 1440/1024

- [x] 6. Course page and course sidebar with reference topics; guide pages at 1440 px with the rail (frontend)
  Covers: AC-3, AC-6, NFR-2
  - [x] 6.1 Update `Learn.vue`, `CourseSidebar.vue`, `Guide.vue`, `LabLayout.vue` width rule
  - [x] 6.2 **Verify**: typecheck, build

- [x] 7. Grouped lab sidebar in `LabLayout.vue` (frontend)
  Covers: AC-7, NFR-2
  - [x] 7.1 Group labels, current "Course" on learn and guide pages, compact wrap below md
  - [x] 7.2 **Verify**: typecheck, build; 1440 and 1024 screenshots

## Phase 3: Docs and checks

- [x] 8. Docs, guide, backlog, audit discovery
  Covers: AC-9, AC-10, MNC-5, MNC-6
  - [x] 8.1 Update `lessons` guide, README, `docs/learning-guide.md`, backlog SL-57/SL-58; `bench/ui-audit.mjs` discovers guides from `/ai/learn`; `UiAuditGuidePagesTest`
  - [x] 8.2 **Verify**: `node bench/ui-audit.mjs` 0 failing; fingerprint unchanged; parity only `captured=`

- [x] 9. Manual: walk manual-test.md
  Covers: AC-1..AC-10, NFR-2
  - [x] 9.1 **Verify**: checked 2026-11-20: redirect 301 (step 1), payloads 8 968 / 53 476 (step 8), screenshots 1440/1024/640 of learn, streaming, semantic, lessons guide, lesson step 3, describe (steps 1-4), ui-audit 120 pages 0 failing and fingerprint unchanged (step 5); steps 2, 6, 7 covered by tests (LessonPagesTest, NoReferencesInUiTest, LessonRepositoryTest)

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | /ai/guides 301 → /ai/learn | `tests/Feature/GuidePageTest.php::guides index redirects to learn` + `manual-test.md#step-1` | test+manual | 2 |
| AC-2 | every guide owned once, validation | `tests/Feature/LessonRepositoryTest.php::course references rules` + `manual-test.md#step-7` | test+manual | 1 |
| AC-3 | course page topics and links | `tests/Feature/LessonPagesTest.php::course lists lesson and reference topics` + `manual-test.md#step-1` | test+manual | 3, 6 |
| AC-4 | lesson topic header | `tests/Feature/LessonPagesTest.php::lesson page carries its guides` + `manual-test.md#step-2` | test+manual | 3, 5 |
| AC-5 | guide topic header | `tests/Feature/GuidePageTest.php::guide page carries topic and course` + `manual-test.md#step-3` | test+manual | 3, 5 |
| AC-6 | course sidebar on guide pages | `tests/Feature/GuidePageTest.php::guide page carries topic and course` + `manual-test.md#step-3` | test+manual | 3, 6 |
| AC-7 | grouped navigation | `tests/Feature/GuidePageTest.php::navigation is grouped` + `manual-test.md#step-4` | test+manual | 4, 7 |
| AC-8 | home link, format trial link | `tests/Feature/HomeLandingTest.php` + `tests/Feature/GuideFormatPagesTest.php::AC-8 the format pages breadcrumb to the learning area and the photos guide shows a Format trial link in its header` + `manual-test.md#step-1` + `manual-test.md#step-8` | test+manual | 2, 15 |
| AC-9 | ui audit 0 failing | `manual-test.md#step-5` | manual | 8 |
| AC-10 | docs and backlog | `manual-test.md#step-6` | manual | 8 |
| MNC-1 | lessons unchanged | `tests/Feature/LessonPagesTest.php` + `manual-test.md#step-2` | test+manual | 5 |
| MNC-2 | guide tabs unchanged | `tests/Feature/GuidePageTest.php` + `manual-test.md#step-3` | test+manual | 5 |
| MNC-3 | format trial URLs | `tests/Feature/GuideFormatPagesTest.php` + `manual-test.md#step-8` | test+manual | 2 |
| MNC-4 | lab pages and availability | `tests/Feature/GuidePageTest.php::navigation is grouped` + `manual-test.md#step-8` | test+manual | 4 |
| MNC-5 | no refs, deps, DB | `tests/Feature/NoReferencesInUiTest.php` + `manual-test.md#step-6` | test+manual | 8 |
| MNC-6 | fingerprint and parity | `manual-test.md#step-5` | manual | 8 |
| NFR-1 | payload < 60 000 | `tests/Feature/LessonPagesTest.php::course payload stays small` + `manual-test.md#step-8` | test+manual | 3 |
| NFR-2 | keyboard, aria-current, labels | `manual-test.md#step-4` | manual | 5, 6, 7 |

## Verification gates

- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev artisan test --compact`
- `ddev npm run typecheck`, `ddev npm run build`
- `node bench/ui-audit.mjs`, `bench/asset-fingerprint.sh`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 10. Topic header as specified: Learn / module / topic breadcrumb, Walk through | Reference switch, "lesson coming", "Also:", meta line (frontend)
  Covers: AC-4, AC-5, NFR-2
  - `TopicHeader.vue` today renders "Course / <module>" (no topic title, module not linked to `/ai/learn#<slug>`) and a list of "Lesson: …" / "Guide: …" items; there is no "Walk through" / "Reference" switch, no disabled `<span aria-disabled="true">Walk through · lesson coming</span>` on reference guides, no "Also: …" list, no guide meta line "reference"; `/ai/guides/lessons` gets no header (`v-if="topic"`); `LessonPageService::topic()` sets `module.href` to `/ai/learn` without `#<slug>`. `TopicHeaderTest` is source-level and asserts none of these strings.
  - **Verify**: `TopicHeaderTest` asserts "Walk through", "Reference", "lesson coming", `aria-disabled="true"`, "Also:" and the `Learn` / module / topic breadcrumb; `GuidePageTest` asserts `topic.module.href` `/ai/learn#running-models`; typecheck and build green; `/ai/guides/semantic` shows a non-link "lesson coming" and `/ai/guides/streaming` links its lesson
- [x] 11. Course page topics: Walk through and Reference links, "reference" tag with summary, "How these lessons work", module ids (frontend)
  Covers: AC-3
  - `Learn.vue` has no "How these lessons work" link to `/ai/guides/lessons`; lesson cards show a title link plus a "Guide" list instead of "Walk through" and "Reference" links; reference topics render as bare title links with no "reference" tag and no summary (`LessonPageService::guideLinks()` sends no `summary`); module sections carry no `id="<slug>"` (only `learn-<slug>` on the h2), so `/ai/learn#<slug>` anchors do not resolve. `LessonPagesTest` checks props and source tokens only.
  - [x] **Verify**: `LessonPagesTest` asserts `course.modules.*.references.*.summary`, and the `Learn.vue` source contains "How these lessons work", `/ai/guides/lessons`, "Walk through", "Reference", a "reference" tag and `:id="module.slug"`; NFR-1 payload tests still under 60 000; typecheck and build green
- [x] 12. UI audit reaches every guide, the lessons guide included, and a test proves the discovered set (backend)
  Covers: AC-9
  - `bench/ui-audit.mjs` dropped `guides/lessons` from `LAYOUT_ONLY_PAGES` and `guidePages()` discovers only links on `/ai/learn`, which has no link to `/ai/guides/lessons`, so that guide is no longer audited. `UiAuditGuidePagesTest` only asserts the fixed list holds no guide; it does not assert the discovered set equals every guide key (C-20).
  - [x] **Verify**: `UiAuditGuidePagesTest` asserts the guide hrefs reachable from `/ai/learn` (course props plus the "How these lessons work" link) equal every key in `resources/guides`; `node bench/ui-audit.mjs` prints `guides 25 from /ai/learn` and 0 failing pages, exit 0
- [x] 13. Course sidebar on a lesson-linked guide marks its first linked lesson current (frontend)
  Covers: AC-6
  - `Guide.vue` passes only `:current-guide="guide.key"`; `CourseSidebar.vue` compares it to reference rows only, so on the 14 lesson-linked guides (e.g. `/ai/guides/streaming`) no row carries `aria-current="page"` (C-3). Passing `current` would also show "reset this lesson" on a guide page; `topic.lessons` carries no lesson key to compare.
  - [x] **Verify**: `GuidePageTest` asserts `topic.lessons.0.key` for a lesson-linked guide; a `LessonPagesTest` source check shows `Guide.vue` passes the first lesson key to `CourseSidebar` and the reset button stays tied to a lesson page; typecheck and build green
- [x] 14. Validation: every course lesson is named by at least one guide (backend)
  Covers: AC-2
  - Scope 2 / C-4 requires `InvalidLessonException` when a lesson in `course.php` is named by no guide's `lessons` key; `LessonRepository::assertGuidesOwnedOnce()` checks only guide ownership, and `test_ac_2_course_references_rules` has no such case.
  - **Verify**: new case in `LessonRepositoryTest::test_ac_2_course_references_rules` (lesson no guide names → exception naming the lesson key) fails first, then passes; the real course still loads; full suite, Pint and PHPStan green
- [x] 15. Format trial entry: breadcrumbs to /ai/learn and a "Format trial" link on the photos guide (frontend)
  Covers: AC-8
  - `GuideFormats.vue` and the five `GuideFormat/*.vue` pages still link `<Link href="/ai/guides">Guides</Link>` (a redirect hop; Scope 4 asks for `/ai/learn`); the photos guide renders the link as "Guide format trial" at the page bottom, not a "Format trial" link (C-9: in the topic header meta line); `docs/learning-guide.md` II-23 still says "A trial card on `/ai/guides`".
  - [x] **Verify**: a source test asserts no `href="/ai/guides"` in the format pages and a "Format trial" link in `Guide.vue`/`TopicHeader.vue`; `GuideFormatPagesTest` green; format URLs still 200; build green

## Phase: Gaps (converge round 2)

- [x] 16. Lessons guide authoring notes: a new guide or lesson must be placed in the course, or the course fails to load (backend)
  Covers: AC-10, AC-2
  - C-6 accepts fail-fast "caught by `LessonRepositoryTest`; the `lessons` guide's authoring notes say so", and C-4 requires every lesson to be named by a guide's `lessons` key. `resources/guides/lessons.php` does not say either: the "add a lesson" steps (around line 71) never say to add `<module>/<lesson>` to a guide's `lessons` key, no tab says a new guide must be linked by a lesson or listed in one module's `references` in the course file, and the support entry (around line 49) still says a server error means a broken lesson file, although an unplaced guide now makes `/ai/learn` and every lesson page fail.
  - [x] **Verify**: a `GuidePageTest` (or `GuideContentTest`) case asserts the `lessons` guide's text names the `references` list and the guide's `lessons` link as the two ways to place a topic, and says the course stops loading when a guide or lesson is not placed; `NoReferencesInUiTest` and the guide tab-count tests stay green; Pint green

- Clean-tree bench after the review fix pass: after commit 3ec1530, `bench/run.sh ping --quick` exited 0 on frankenphp.
