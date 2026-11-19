# AI lab scene lessons — Tasks

**Spec**: spec-20261119-2146-scene-lessons | **Size**: L | **Tasks**: 7

## Phase 1: Engine

- [x] 1. Lesson format, repository and validation (laravel)
  Covers: AC-4, AC-5, AC-8
  - [x] 1.1 Build the lesson and course schema, `LessonRepository`/`LessonService` and DTOs, number resolution from `bench/results`, and per-request memoization.
  - [x] 1.2 **Verify**: `tests/Feature/LessonRepositoryTest.php`:
    - broken fixtures, one per rule;
    - an unresolvable number fails;
    - a lesson that resolves.

- [x] 2. Routes, course page and lesson props (laravel)
  Covers: AC-1, AC-8, NFR-2, MNC-4
  - [x] 2.1 Add `/ai/learn` and `/ai/learn/{module}/{lesson}/{step?}`, the navigation entry, links from guides, and the "coming soon" entries.
  - [x] 2.2 **Verify**: `tests/Feature/LessonPagesTest.php`:
    - 200 and 404;
    - the props shape;
    - payload < 60 KB;
    - no database writes and no HTTP calls.

## Phase 2: Player

- [x] 3. Scene and player components (vue)
  Covers: AC-2, AC-3, AC-6, AC-7, NFR-1, NFR-3
  - [x] 3.1 Build `SceneCanvas` (SVG plus requestAnimationFrame packets, transitions, reduced motion, fullscreen), `KpiStrip`, `StepPanel`, `StepBar` (keyboard), `LearnList`, `CourseSidebar` (`localStorage` progress, reset) and `Lab/Lesson.vue`.
  - [x] 3.2 **Verify**:
    - typecheck and build;
    - static tests in `tests/Feature/LessonPagesTest.php` (aria-live, reduced-motion branch, keyboard handler ignores inputs);
    - a Playwright walk recorded here.
    - Playwright walk (Chrome, Playwright 1.55, 2026-11-19), run on a temporary two-node fixture lesson because `resources/lessons` arrives in task 4; the fixture was removed afterwards. Task 4 repeats it on the pilot, and `manual-test.md#step-3` measures NFR-1 on the pilot's step 4:
      - Next and Back keys moved step 1 to 4 and back to 3, changing the URL each time; browser Back returned to step 4.
      - Step 4 showed the queue badge and the "23 waiting" badge; the live region read "Step 4 of 10: ...".
      - Space froze the packets and resumed them; R restarted the step with packets playing again.
      - Deep link to step 10 marked the lesson done and ticked the outcomes; ticks survived a reload; "reset progress" cleared them and the stored key.
      - Reduced motion: 0 moving packets and 3 dashed markers on step 1.
      - Frame times over 5 s on a 5-packets-per-second step: median 16.7 ms, p95 16.7 ms (about 60 fps); no console errors.

## Phase 3: Content and closure

- [x] 4. Pilot lesson and course outline (content)
  Covers: AC-1, AC-2, AC-4
  - [x] 4.1 Write `resources/lessons/course.php` (3 modules, 8 lessons) and `running-models/one-model-many-users.php` (10 steps, numbers via file paths).
  - [x] 4.2 **Verify**: `tests/Feature/LessonContentTest.php`:
    - every KPI and `{num}` resolves;
    - each outcome is taught;
    - spot-check values against BENCHMARKS.md.

- [x] 5. Close SL-54 (docs)
  Covers: AC-9
  - [x] 5.1 Write the SL-54 `decisions.md` pick, check task 8, set the status to done, and mark the backlog shipped.

- [x] 6. Guide, docs, audit (docs)
  Covers: AC-10, MNC-2
  - [x] 6.1 Add the `lessons` foundation guide (six tabs plus teach), the learning guide section and README. Extend `NoReferencesInUiTest` and ui-audit to the `/ai/learn` pages.
  - [x] 6.2 **Verify**: `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, `DocsLinksTest`; ui-audit exits 0.

- [x] 7. Gates and ship (checks)
  Covers: MNC-1, MNC-3
  - [x] 7.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.
    - Gates, fingerprint and parity were re-run after the review fixes on 2026-11-20 and pass (see task 18). The commit, the ping and the push are not done yet: the review-fix pass was told not to commit.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | course page | `tests/Feature/LessonPagesTest.php`, `manual-test.md#step-1` | test, manual | 2, 4 |
| AC-2 | lesson page parts | `tests/Feature/LessonPagesTest.php`, `manual-test.md#step-1` | test, manual | 2, 3 |
| AC-3 | step navigation and keys | `manual-test.md#step-1` | manual | 3 |
| AC-4 | numbers from committed files | `tests/Feature/LessonContentTest.php`, `manual-test.md#step-6` | test, manual | 1, 4 |
| AC-5 | schema validation | `tests/Feature/LessonRepositoryTest.php`, `manual-test.md#step-7` | test, manual | 1 |
| AC-6 | learn list and progress | `manual-test.md#step-1` | manual | 3 |
| AC-7 | reduced motion, keyboard, aria-live | `manual-test.md#step-2` | manual | 3 |
| AC-8 | 404s | `tests/Feature/LessonPagesTest.php`, `manual-test.md#step-5` | test, manual | 2 |
| AC-9 | SL-54 closed | `manual-test.md#step-4` | manual | 5 |
| AC-10 | guide, docs, audit | `tests/Feature/GuideContentTest.php`, `manual-test.md#step-8` | test, manual | 6 |
| MNC-1 | existing pages unchanged | existing guide and lab tests unchanged, `manual-test.md#step-9` | test, manual | 7 |
| MNC-2 | no references | `tests/Feature/NoReferencesInUiTest.php`, `manual-test.md#step-10` | test, manual | 6 |
| MNC-3 | fingerprint, parity | `manual-test.md#step-4` | manual | 7 |
| MNC-4 | no deps, tables, calls | `tests/Feature/LessonPagesTest.php`, `manual-test.md#step-11` | test, manual | 2 |
| NFR-1 | ≥ 50 fps | `manual-test.md#step-3` | manual | 3 |
| NFR-2 | payload < 60 KB | `tests/Feature/LessonPagesTest.php`, `manual-test.md#step-12` | test, manual | 2 |
| NFR-3 | accessibility | `manual-test.md#step-2` | manual | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 8. Guides link to their lessons through a `lessons` key (laravel)
  - Covers: C-13 (scope 3)
  - Add the optional `lessons` key (list of `<module>/<lesson>`, validated against `LessonRepository`) to `app/Guides/GuideRepository.php`, list the pilot in `resources/guides/local-models.php` and `resources/guides/metrics.php`, pass resolved hrefs in the guide props and render "Learn it step by step" links in `resources/js/ai/Pages/Lab/Guide.vue`.
  - **Verify**: a new test in `tests/Feature/GuidePageTest.php` asserts `/ai/guides/local-models` and `/ai/guides/metrics` carry the href `/ai/learn/running-models/one-model-many-users`, and an unknown lesson key fails guide validation; existing guide tests pass unchanged.
- [x] 9. A broken lesson answers 500 for that lesson only (laravel)
  - Covers: C-10
  - `LessonPageService::show()` turns `InvalidLessonException` into a 404; C-10 says the lesson route answers 500 while `/ai/learn` lists it as unavailable. Answer 500 (keep `report()`), or amend C-10 and the learning-guide sentence if 404 is kept on purpose.
  - [x] **Verify**: a new `LessonPagesTest` case writes a broken lesson fixture listed in `course.php` and asserts its URL answers 500 while `/ai/learn` answers 200 with `available` false for it.
- [x] 10. Reject numbers read from uncommitted result files (laravel)
  - Covers: AC-4
  - `LessonNumbers::load()` accepts any `.json` under `bench/results`, including the gitignored `bench/results/raw/`, so a lesson can pass locally on a file that is not committed. Reject `raw/` paths in the loader and assert in a test that every file the pilot reads is tracked by git.
  - **Verify**: `LessonRepositoryTest` gets a broken case pointing at `raw/...json` that fails with a clear message; `LessonContentTest` asserts `git ls-files --error-unmatch` succeeds for every file the pilot references.
- [x] 11. Walk the pilot in the browser and record it (checks)
  - Covers: AC-3, AC-6, AC-7, NFR-3
  - The task 3 Playwright walk ran on a temporary fixture; `manual-test.md#step-1` and `#step-2` were never run on the pilot. Run them on `/ai/learn/running-models/one-model-many-users` with Playwright 1.55 via `npx`: Next, step numbers, → and ←, browser Back, R and Space, the `aria-live` text, reload keeps ticks, done after step 10, "reset progress", reduced-motion emulation, keyboard-only focus, and text contrast (≥ 4.5:1).
  - [x] **Verify**: the walk results, with no console errors, are recorded under task 11 in this file.
    - Playwright walk (Chrome, Playwright 1.55, 2026-11-19) on `/ai/learn/running-models/one-model-many-users` at 1440 px, run with a clean `localStorage` (`manual-test.md#step-1` and `#step-2`; AC-3, AC-6, AC-7, NFR-3):
      - Navigation: the page opens on step 1; the Next button went to 2; the step-number button 4 went to `/4`; → went 4 to 5 to 6 and ← back to 5, each changing the URL; browser Back returned to 6 and Forward to 5. Ctrl+→ was ignored. Enter and Space on a focused Next button both activate the button (Space keeps its native action there).
      - Keys: with focus off the buttons, Space froze the packets (button reads "Resume") and Space again resumed them (reads "Pause"); R replayed and unpaused. Step 4 draws 20 packets.
      - aria-live: the region read "Step 1 of 10: One user. Users at once 1, First token (median) 0.3 s, Writing speed 51.6 tok/s, Search p95 64 ms", and "Step 5 of 10: The rest of the site. ..." after navigating.
      - Progress: ticks (4) were the same before and after a reload on step 6; the stored value lists the viewed steps. Visiting step 10 marked the lesson done (one "done" mark in the sidebar, two on `/ai/learn`). Only the outcomes of the viewed steps (1, 2, 4, 5, 6, 10) were ticked, 4 of 6. "reset progress" on `/ai/learn` left `{"lessons":{}}` and a fresh step 3 showed 1 tick.
      - Keyboard only: 39 Tab stops in a sensible order (skip link, nav, sidebar, full screen, 10 step buttons, Next, Replay, Pause, guide link); every one shows a focus outline.
      - Reduced motion (emulated): 0 moving packets and 11, 13 and 18 dashed markers on steps 1, 4 and 7; positions did not change over 400 ms.
      - Contrast of HTML text (computed colours against the effective background, all visible text on step 10): lowest 4.63:1 (the signal-orange "Learn" link on the page background); every other pair is 5.1:1 or higher. Scene SVG text was listed (dark ink 18 px and 13 px, white 16 px on node fills) but not measured against its fill.
      - Console: no errors or warnings in either the normal or the reduced-motion run.
- [x] 12. Measure NFR-1 on the pilot's busiest step (checks)
  - Covers: NFR-1
  - No frame-time measurement exists on the pilot; the recorded one used a 5-packets-per-second fixture. `manual-test.md#step-3` names step 4, but step 7 is the busiest (about 20 packets per second). Correct the step in `manual-test.md` and measure 5 s of `requestAnimationFrame` frame times on step 7 in Chrome.
  - [x] **Verify**: median frame interval ≤ 20 ms recorded under task 12 in this file, and `manual-test.md#step-3` names step 7.
    - Chrome (Playwright 1.55, 2026-11-19, 1440 px) on `/ai/learn/running-models/one-model-many-users/7` after 1.5 s settle: 300 `requestAnimationFrame` intervals over 5 s, median 16.7 ms, p95 16.7 ms, max 16.8 ms (about 60 fps); 31 circles in the scene SVG. NFR-1 met (limit 20 ms).
- [x] 13. Record the pilot's deviations and the SL-54 pick (docs)
  - Covers: scope 5, scope 7
  - Pilot step 9 is "Another model, same queue", not "A smaller model" (the second model is not smaller in the files), and change lines say "slowest searches" instead of "search p95"; neither is recorded in the spec's Decisions. The backlog SL-54 status reads "shipped" without the pick that scope 7 asks for.
  - [x] **Verify**: `spec-lite.md` Decisions has an entry for both pilot deviations, and `backlog.md` SL-54 status names the pick (scene lessons, SL-55); `DocsLinksTest` passes.

## Phase: Gaps (converge round 2)

- [x] 14. Pause freezes the scene transitions too (vue)
  - Covers: AC-3 (C-8)
  - C-8 says Space "freezes the packets and the transitions". In `resources/js/ai/components/lesson/SceneCanvas.vue`, `paused` only stops `elapsed` in `tick()` and sets `suppressPackets` on a step change. The `.scene-fill` and `.scene-bar` 0.4 s CSS transitions still run while paused; they are switched off only under `prefers-reduced-motion`. Add a paused branch, for example a `scene-paused` class on the scene that sets `transition: none`, so a step change while paused swaps the state at once and shows no packets until resumed.
  - [x] **Verify**: a static case in `tests/Feature/LessonPagesTest.php` asserts that `SceneCanvas.vue` binds a paused class or style that disables the `.scene-fill` and `.scene-bar` transitions. A Playwright check on the pilot is recorded under this task: pause, go to step 4, and the model node's load bar has its final width in the first frame, with 0 packets until resume.
    - Static case `AC-3 pause freezes the scene transitions as well as the packets` passes. Playwright (Chrome, 2026-11-19, 1440 px) on `/ai/learn/running-models/one-model-many-users`: on step 3, Space paused and → went to step 4; in the first frame after the URL changed, the `.scene-bar` widths were already final (6.8, 136, 136, 0) with computed transition duration 0s, and the scene drew 0 packets (1 circle left, none moving) 800 ms later; Space resumed and 21 circles appeared. No console errors.
- [x] 15. Record the "Follow one photo" reuse note in the SL-54 decisions (docs)
  - Covers: AC-9 (scope 7)
  - Scope 7 asks `decisions.md` to record that the scene-lesson format replaces the five variants and that "Follow one photo" content may be reused as a later lesson. `.ai/specs/spec-20261117-2243-guide-format-variants/decisions.md` says "None of the five formats is rolled out as it stands" and that the pages stay, but it does not mention reusing "Follow one photo" content.
  - [x] **Verify**: `decisions.md` names "Follow one photo" as content that a later lesson may reuse. The `Scope-5 Scope-7` test in `tests/Feature/LessonContentTest.php` is extended to assert this, and it passes together with `DocsLinksTest`.
- [x] 16. Record pilot step 6's thread figures against C-2 (docs)
  - Covers: AC-4 (scope 5, C-2)
  - C-2 sets step 6 to show busy against total threads: `metrics/2026-11-16/sse-frankenphp-auto.json` `metrics.max_busy_threads` and `metrics.max_total_threads`. `resources/lessons/running-models/one-model-many-users.php` step 6 instead compares `max_busy_threads` (30) with `metrics.total_workers` (4) from a different file, `sse-frankenphp-worker-4.json`. D-5 and D-6 do not record this deviation. Add a D-7 to the spec's Decisions that explains the swap (the auto file reports 30 busy against 14 total), or change step 6 to the C-2 paths.
  - [x] **Verify**: `spec-lite.md` Decisions has a D-7 that names `total_workers`, or step 6 reads `metrics.max_total_threads`. The `Scope-5 Scope-7` test in `LessonContentTest` asserts whichever was chosen and passes.
- [x] 17. Measure the contrast of the scene's SVG text (checks)
  - Covers: NFR-3
  - NFR-3 requires text contrast ≥ 4.5:1. The task 11 walk measured only HTML text and says "Scene SVG text was listed ... but not measured against its fill". `SceneCanvas.vue` draws ink `#1a1916` labels at 18 px and 13 px on the `#ffffff`, `#fde8d8` and `#fbdcd8` fills, white 16 px queue counts on `#c2410c`, and ink badge text on the white scene background.
  - [x] **Verify**: the contrast ratio of each SVG text and fill pair is recorded under this task, and each is ≥ 4.5:1. Any pair below 4.5:1 is fixed in `SceneCanvas.vue` and measured again.
    - Measured 2026-11-19 (WCAG relative luminance), all pairs pass, so `SceneCanvas.vue` is unchanged:
      - ink `#1a1916` on `#ffffff` (ok node fill, node label, load text, badge on scene background): 17.58:1
      - ink `#1a1916` on `#fde8d8` (busy node fill, label and load text): 14.83:1
      - ink `#1a1916` on `#fbdcd8` (down node fill, label and load text): 13.67:1
      - white `#ffffff` on `#c2410c` (queue count on the queue badge): 5.18:1
    - The test `NFR-3 every scene SVG text colour has a contrast of at least 4.5:1 against its fill` in `tests/Feature/LessonPagesTest.php` recomputes these from the fills in `SceneCanvas.vue` and passes.

## Phase: Review fixes (2026-11-20)

- [x] 18. Fix the confirmed review findings F1 to F24 (laravel, vue, content, docs)
  - Covers: AC-3, AC-4, AC-5, AC-6, AC-7, AC-8, C-5, C-6, C-10, C-13, NFR-1, NFR-2, NFR-3
  - [x] 18.1 Tests first. These failed before the fix and pass after it:
    - `LessonRepositoryTest`: `test_f19_the_repository_only_loads_and_validates_and_the_service_resolves`, `test_f9_a_lesson_file_missing_from_the_course_is_not_playable`, `test_f1_deeper_takes_the_guide_page_tab_keys_and_maps_the_old_names`, `test_f7_the_callout_is_optional`, `test_f11_numeric_looking_node_and_edge_ids_work_in_state_patches`, `test_f5_a_kpi_marked_trend_false_is_never_coloured`, `test_f2_kpis_are_required_on_step_1_and_inherited_later`, plus new broken-rule cases (unknown tab, a tab the guide does not show, a bad `trend`, a callout without text).
    - `LessonPagesTest`: F15 (client-side step change), F9 (a draft lesson answers 404), F14 and F17 (packet layer outside Vue, loop gated), F10 and F13 (progress sanitised), F6 (sidebar follows the breakpoint).
    - `LessonContentTest`: F1 (every pilot "Go deeper" tab is one the guide page shows), F5 (trend colours), F4 (result files tracked by git and none under `raw/`; skipped when git is missing), and the D-7 wording.
    - `GuidePageTest`: C-13 (a malformed key fails, an unlisted one is not linked) and F16 (titles come from the course file: the page renders with no readable result files). `GuideContentTest`: every lesson a guide links to is playable. `GuideFactsTest`: F22 (bare and quoted paths through one reader).
  - [x] 18.2 Fixes:
    - F19, F20, F21, F22: `LessonRepository` only loads and validates. It returns `LessonDefinition` and `LessonStepDefinition` with the patches, pointers and placeholders as written. `LessonService` (scoped, memoized) replays the state, reads the numbers, sets the trend and fills in the placeholders. `GuidePageService` gets `LessonService` through its constructor and reads titles with `LessonService::titles()`. `GuideRepository` checks only the shape of `lessons`, so the Guides to Lessons dependency is gone. `LessonNumbers` reads through `GuideFacts::document()` and `GuideFacts::at()`, one path syntax with quoted segments.
    - F1, F18: `deeper` is checked against `GuidePageService::tabKeys()` and `GuidePageService::TABS` and `TEACH_TABS`. `failures` is mapped to `support` and `recipes` to `modify`; any other name is an error (D-11).
    - F2, F3, F4, F7, F9, F11, F12: step 1 must give every KPI, later steps inherit. A KPI literal may hold no digit. `raw/` is refused after the path's dot segments are folded. The callout is optional (D-10). Only lessons listed in `course.php` play (D-13). Numeric ids work in patches.
    - F5, F8: `trend: false` per KPI value (D-8) on pilot steps 5, 9 and 10. Step 10 restates the first model's writing speed and says so. Step 6 shows the C-2 thread figures with their caveat and names its separate metrics run (D-7).
    - F14, F17: `PacketLayer.vue` has no `v-for`. It keeps a pool of circles, moves them with `setAttribute('transform')` in its own `requestAnimationFrame` loop, and stops the loop when paused, when there are no packets, when `document.hidden`, or when an `IntersectionObserver` reports the scene off screen. `SceneCanvas.vue` has no per-frame state, and its node groups are `v-memo`'d on their state.
    - F15: a step change is `router.push` with `preserveState` and the new `step`, so there is no request and the lesson is not sent again. A deep link is still server-rendered (D-9).
    - F6, F10, F13: the sidebar listens to the `(min-width: 1024px)` query. Progress from `localStorage` goes through `sanitize()`, which keeps only records with an integer `viewed` list and a boolean `done`.
    - F16: guide pages build no lesson. Their titles come from `course.php` (D-12).
    - F23, F24: `LessonRepository::files()` and `LessonNumbers::filesRead()` are removed (the tests glob and walk the lesson file themselves). The unused `PacketKind` type and the course types duplicated in `Learn.vue` are removed too.
    - Docs: the `lessons` guide and the learning-guide section describe the repository and service split, `trend: false`, the optional callout, the deeper tab names and the client-side step change. `manual-test.md` steps 1 and 11 expect no request on a step change.
  - [x] 18.3 Browser walk (Chrome, Playwright 1.55 from the npx cache, 2026-11-20, 1440 px, clean `localStorage`) on `/ai/learn/running-models/one-model-many-users`:
    - Keyboard only: → walked steps 1 to 10, and each step changed the URL, the title and the live region ("Step 7 of 10: Take streams off the request workers. ..."). There were **0 server requests** over the 9 step changes and the Back and Forward that followed. ← went from 10 to 9, and Ctrl+→ was ignored. Browser Back returned to 10 and Forward to 9.
    - Space paused: the button reads "Resume", the packets were frozen and there were 0 `requestAnimationFrame` calls a second. While paused, → went to step 5: the load bars were final at once (transition 0s) and there were 0 packets. Space resumed with 22 packets at 60 calls a second. R replayed on the same URL with "Pause" shown.
    - The loop stops when it is not needed: `document.hidden` gave 0 calls a second (60 after it was visible again), and the scene scrolled off screen gave 0 (60 when back).
    - 38 Tab stops in page order (skip link, navigation, breadcrumb, guide link, sidebar, full screen, steps 1 to 10, Next, Replay, Pause, Go deeper), each with a visible outline.
    - Progress: a reload on step 6 kept the stored record. Step 10 set `done: true`. `/ai/learn` read "1 of 1 lessons done". "reset progress" (Enter) left `{"lessons":{}}` and "0 of 1".
    - Malformed `localStorage` (`viewed` as a string with `done: 1`; `lessons` as a list; `viewed` with mixed entries; not JSON): no page error in any case. The bad record was dropped, or only its valid steps were kept (`[1, 2]`).
    - Sidebar: hidden when loaded at 900 px, visible after widening to 1440, collapsed again at 900.
    - Deep link `/7` opened step 7 server-side.
    - Reduced motion (emulated): 0 moving packets, 11, 13 and 18 dashed markers on steps 1, 4 and 7, positions still, 0 `requestAnimationFrame` calls a second.
    - Console: no errors and no warnings.
  - [x] 18.4 NFR-1 re-measured on step 7 (25 packets on screen) after a 1.5 s settle: 300 frame intervals over 5 s, median 16.7 ms, p95 16.8 ms, max 16.8 ms (about 60 fps), and 0 long tasks in 3 s.
  - [x] 18.5 SVG text contrast, measured in the page on step 7: ink on white 17.58:1, ink on the busy fill 14.83:1, white on the queue badge 5.18:1. The down fill appears only on step 8; its ratio is 13.67:1, recomputed by the NFR-3 test. All pairs are at least 4.5:1.
  - [x] 18.6 Gates on the final tree (2026-11-20):
    - Full suite: 3581 passed, 3 skipped, 46080 assertions.
    - Pint: passed. PHPStan: no errors. Typecheck: clean. Build: clean.
    - Fingerprint unchanged: `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
    - `PARITY_INSECURE=1 bench/parity.sh capture`: only the `captured=` line changed, and `git checkout -- bench/parity` restored it.
    - `node bench/ui-audit.mjs --only learn,learn/running-models/one-model-many-users`: exit 0, 4 pages, 0 failing. Full audit: exit 0, 66 pages, 0 failing.
    - Every result file the pilot reads is tracked by git; git is available in the container, so the test ran rather than skipping.
