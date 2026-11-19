# Five interactive guide formats, trialled on the photos guide — Tasks

**Spec**: spec-20261117-2243-guide-format-variants | **Size**: L | **Tasks**: 8

## Phase 1: Engine and compare page

- [x] 1. Format loading, routes and the compare page (laravel)
  Covers: AC-1, AC-3, AC-4, AC-11, MNC-1, MNC-2, NFR-3
  - [x] 1.1 Failing tests:
    - `/ai/guides/photos/formats` answers 200 with six entries and LO-1 to LO-5;
    - an unknown key or format answers 404;
    - a broken format file (missing field, unknown LO id, unresolvable snippet) raises `InvalidGuideException` naming file and field;
    - `/ai/guides` shows the trial card and keeps every existing entry in order;
    - `/ai/guides/photos` props are unchanged.
  - [x] 1.2 `GuideFormatRepository` (per-format schema, `SnippetResolver`), `GuideFormatPageService`, two invokable controllers, routes next to `ai.guides.show`, `Lab/GuideFormats.vue`, the trial card in `Guides.vue`.
  - [x] 1.3 **Verify**: `tests/Feature/GuideFormatPagesTest.php`, `GuidePageTest`, `GuideContentTest` green.

## Phase 2: The five formats (content + page each)

- [x] 2. Build-along format (laravel)
  Covers: AC-2, AC-5, AC-6, NFR-1
  - [x] 2.1 Failing tests: the page answers 200; every LO is covered by a step; every step has goal, code, command and expected result.
  - [x] 2.2 `resources/guides/formats/photos/build-along.php`, `Lab/GuideFormat/BuildAlong.vue` with `localStorage` progress and reset.
  - [x] 2.3 **Verify**: tests green; manual-test step 2 (progress survives reload).

- [x] 3. Follow one photo (trace) format (laravel)
  Covers: AC-2, AC-5, AC-8, NFR-1
  - [x] 3.1 Failing tests: 200; the stages in pipeline order; every stage has a panel with sample data and code; LO coverage.
  - [x] 3.2 `trace.php`, `Lab/GuideFormat/Trace.vue` (SVG/CSS flow, Play/Pause, keyboard).
  - [x] 3.3 **Verify**: tests green; manual-test step 2.

- [x] 4. Predict and check (quiz) format (laravel)
  Covers: AC-2, AC-5, AC-7, NFR-1
  - [x] 4.1 Failing tests: 200; 8 to 10 questions, each with exactly one correct option, an explanation and code; LO coverage.
  - [x] 4.2 `quiz.php`, `Lab/GuideFormat/Quiz.vue` (locked answers, score, `aria-live` feedback).
  - [x] 4.3 **Verify**: tests green; manual-test step 2.

- [x] 5. Break and fix (debug) format (laravel)
  Covers: AC-2, AC-5, AC-9, NFR-1
  - [x] 5.1 Failing tests: 200; 4 or 5 scenarios, each with symptom, at least two clues, cause options with one correct, fix and preventing test reference; LO coverage.
  - [x] 5.2 `debug.php`, `Lab/GuideFormat/Debug.vue`.
  - [x] 5.3 **Verify**: tests green; manual-test step 2.

- [x] 6. Code tour format (laravel)
  Covers: AC-2, AC-5, AC-10, NFR-1
  - [x] 6.1 Failing tests: 200; every stop resolves to real source lines; LO coverage; no stop shows a repository path.
  - [x] 6.2 `tour.php`, `Lab/GuideFormat/Tour.vue` (code panel via `LabCode`, annotations, previous/next, map).
  - [x] 6.3 **Verify**: tests green; manual-test step 2.

## Phase 3: Checks, docs and the pick

- [x] 7. UI rules, audit, docs and gates (laravel)
  Covers: MNC-3, MNC-4, MNC-5, NFR-2
  - [x] 7.1 Extend `NoReferencesInUiTest` (and `labUiPaths`) with the compare page and the five format pages; add them to the ui-audit page list.
  - [x] 7.2 Short note in `docs/learning-guide.md` (in-app guides section) on the trial and how the formats are built; `DocsLinksTest` green.
  - [x] 7.3 **Verify**: full suite, Pint, PHPStan, `npm run typecheck`, `npm run build`, `node bench/ui-audit.mjs --only guides,guides/photos/formats,guides/photos/formats/build-along,guides/photos/formats/trace,guides/photos/formats/quiz,guides/photos/formats/debug,guides/photos/formats/tour` exit 0, `bench/asset-fingerprint.sh` unchanged, parity capture on the three modes differs only in `captured=`.

## Phase: Gaps (converge round 1)

- [x] 9. NFR-3 test requests the five format pages too (laravel)
  Covers: NFR-3
  - [x] **Verify**: `tests/Feature/GuideFormatPagesTest.php` NFR-3 case also GETs `/ai/guides/photos/formats/{build-along,trace,quiz,debug,tour}` (200 each) under the `DB::listen` collector and `Http::preventStrayRequests()`, and still asserts no INSERT/UPDATE/DELETE; `ddev artisan test --compact tests/Feature/GuideFormatPagesTest.php` green.

- [x] 10. Quiz shows one question at a time, Next after answering, score after the last (laravel)
  Covers: AC-7, NFR-1
  - [x] **Verify**: `Quiz.vue` renders only the current question in build order; Next is disabled until it is answered; the answer locks; the score `n / total` shows only after the last question is answered; feedback stays in the `aria-live="polite"` region; `ddev npm run typecheck` and `ddev npm run build` clean; ui-audit `--only guides/photos/formats/quiz` exit 0; manual-test step 2 (Predict and check) passes.

- [x] 11. Trace: 2.5 s Play step, Left/Right arrow keys between stages, sample labelled "example" (laravel)
  Covers: AC-8, NFR-1
  - [x] **Verify**: `Trace.vue` uses a 2500 ms step (not 3000); with focus in the stage flow, ArrowLeft/ArrowRight select the previous/next stage and move focus to it; the per-stage sample heading carries the word "example"; `ddev npm run typecheck` and `ddev npm run build` clean; ui-audit `--only guides/photos/formats/trace` exit 0; manual-test steps 2 and 3 (Follow one photo) pass.

- [x] 12. Debug: cause locked until a clue is shown, "Show answer" control, symptom labelled "example" (laravel)
  Covers: AC-9, NFR-1
  - [x] **Verify**: in `Debug.vue` the cause radios are disabled until at least one clue is revealed for that scenario; a "Show answer" button per scenario reveals the cause, fix and preventing test and announces it in the `aria-live` region; the symptom block is labelled as an example; `ddev npm run typecheck` and `ddev npm run build` clean; ui-audit `--only guides/photos/formats/debug` exit 0; manual-test step 2 (Break and fix) passes.

- [x] 13. Manual: run manual-test steps 2 and 3 (browser behaviour and keyboard-only walk of the five formats) and record the result
  Covers: AC-6, AC-7, AC-8, AC-9, AC-10, NFR-1
  - [x] **Record (2026-11-18)**: steps 2a–2e and 3 driven in Chrome through the cached Playwright 1.55.0 (the one `bench/ui-audit.mjs` pins) against https://ai-lab.ddev.site after `ddev npm run build`: **54 / 54 checks passed**, no page or console errors.
    - 2a build-along: two ticks read "2 of 6 steps done", survive a reload, `localStorage['ai-lab.guide-format.photos.build-along']` is `["command","schema"]`; Reset progress clears them and stays cleared after a reload; every test command on the page uses a quoted title filter.
    - 2b trace: Play turns into Pause and moves one stage after 2.7 s and two after 5.2 s (2.5 s step); Pause keeps the stage; clicking stage 7 shows its panel with "Example data at this stage" and code; ArrowLeft selects and focuses stage 6; with stage 6 shown and focus moved to stage 3, ArrowRight selects stage 4 (moves from the focused stage); Play starts from the selected stage.
    - 2c quiz: 9 questions, one at a time; Next disabled before answering; Space and the arrow keys move the selection without locking or announcing; Check answer reveals the verdict, the explanation and the code, locks the options and marks "(correct answer)"; the score after the last question equals the number of "Correct" answers (4 / 9 with the first option each time), is announced in the `aria-live` region, and no Next is shown; Start over returns to question 1.
    - 2d debug: symptom first, labelled example; causes disabled until a clue is shown; clues one at a time; arrow keys move the cause selection without committing; a wrong cause says why and keeps "Try another cause" and the radios; the right cause shows "(the cause)", the fix, the real test title and the code, without the retry text; on scenario 2 a wrong pick then Show answer checks and marks the right cause, shows "Answer." with its text, the fix and the test, drops "Try another cause", and announces "Answer: …"; progress reads "1 of 4 scenarios solved".
    - 2e tour: stop 1 shows the real first line of `ExtractPhotosCommand::handle` with notes on lines 27–33, 45–51, 53–55, 65–71 and 76–81 (absolute, matching the code gutter); Previous disabled on stop 1; Next and Previous move one stop and the map follows.
    - 3 keyboard only: checkbox (Space) and Reset (Enter) on build-along; Play/Pause by Enter and arrows between stages with focus following (0 → 1 → 2 → 1) on trace; radio, Check answer and Next by keyboard on the quiz; clue, cause and Check cause by keyboard on debug, each announced in the `aria-live` region; Next and Previous stop by keyboard on the tour (announced "Stop 1 of 6 …"). Every focused control showed a visible focus ring (`:focus-visible` with an outline) and every control on the five pages has an accessible name.

## Phase: Gaps (converge round 2)

- [x] 14. Build-along test commands run real tests (laravel)
  Covers: AC-2, AC-6
  - [x] **Verify**: in `resources/guides/formats/photos/build-along.php` the `php-rules` and `attempts` steps no longer use `--filter=PhotoExtractionRules` / `--filter=PhotoExtractionService` (both print "No tests found" and exit 1 today); each `ddev artisan test … --filter=X` command names a filter that matches at least one existing test (review fix: the three commands now filter on test titles, `--filter="declares every key of the extraction"` (1 passed), `--filter="rejects output that breaks a rule in PHP"` (13 passed) and `--filter="after three invalid answers"` (1 passed), each run by hand on 2026-11-18); a new `GuideFormatContentTest` case extracts every `--filter=` value from the build-along steps and asserts it matches a test file name or an `it('…')` title under `tests/` (review fix: matched against `it()` titles and `*Test.php` names only, no longer against any text in a test file); `ddev artisan test --compact tests/Feature/GuideFormatContentTest.php` green, and each listed command run by hand reports passing tests.

- [x] 15. Debug "preventing test" names a real test title (laravel)
  Covers: AC-9
  - [x] **Verify**: every `test` field in `resources/guides/formats/photos/debug.php` quotes the title of an existing test (today none of "An answer outside the colour list is rejected and asked for again", "A transport error ends the photo after one attempt", "The extraction job is pushed to the vision queue", "Fresh removes the answers of the chosen model and prompt version first" exists; the real ones are e.g. "AC-4 retries invalid answers and stores the third attempt when it is valid", "AC-4 records a transport error as one failed attempt with no retry", "NFR-2 queues each job on ai-vision and does not retry in the queue", "AC-3 --fresh redoes only the rows of the model and prompt version"), optionally followed by words that describe it, with no test file path; a new `GuideFormatContentTest` case asserts each scenario's quoted title appears as an `it('…')` title under `tests/`; `ddev artisan test --compact tests/Feature/GuideFormatContentTest.php tests/Feature/NoReferencesInUiTest.php` green.

## Phase: Review fixes (2026-11-18)

- [x] 16. Fix pass over the confirmed review findings F1–F20 (laravel)
  Covers: AC-1 to AC-11, MNC-1, NFR-1, NFR-3
  - [x] F1 Quiz and Debug commit only on "Check answer" / "Check cause"; a radio only selects, arrow keys move freely (`GuideFormatPagesTest` "a radio only selects" plus the browser walk in task 13).
  - [x] F2 Show answer or a right pick checks and marks the right cause "(the cause)" and shows its text; "Try another cause" shows only while a retry is possible (`GuideFormatPagesTest` "marks the right cause", task 13).
  - [x] F3 debug "old-model" rewritten to match the code: the command skips a photo when any `photo_extractions` row (completed or failed) exists for the same provider, model and prompt version (`EloquentPhotoExtractionRepository::pendingPhotoIds`, the unique key photo_id, provider, model, prompt_version); the snippet is now that query; `--fresh` deletes those rows first. The other three scenarios were checked against `PhotoExtractionService::extract`, `PhotoExtractionRules` and `ExtractPhotoJob`: real validator and transport error formats, "server down" fix now says a failed row needs `--fresh`, "bad colour" test is "AC-3 rejects output that breaks a rule in PHP" (`GuideFormatContentTest` "the skip scenario shows the command output and the query").
  - [x] F4 every tour note now starts on the line of code it describes (5, 5, 2, 3, 2 and 6 notes per stop); the repository rejects a note that starts on a blank, comment, docblock or brace-only line or runs past the snippet (`GuideFormatContentTest` "a tour note that points at a docblock…", "each note starts on a line of code").
  - [x] F5 a folder without all six files is not a format set: no trial card and 404 for the compare and format pages, never 500 (`GuideFormatPagesTest` "an incomplete format folder…").
  - [x] F6 / F13 trace arrow keys move from the focused stage button, found by its `data-stage` index; no template ref array and no inline ref callback (`GuideFormatContentTest` trace source case, task 13).
  - [x] F7 quiz "describe" question (tagged LO-2 without teaching it) replaced by "Where the instructions live" (prompt file, prompt version); "One failing photo" tagged LO-1 only; trace samples use the real command output and the `photo-vision@v1` format read from `PhotoVisionAgent::promptVersion` (`GuideFormatContentTest` "the trace samples use the command output…").
  - [x] F8 MNC-1 compares `/ai/guides/photos` props with `tests/Fixtures/guides/photos-guide-props.json`, captured on `master` (byte-identical to this branch); snippet line numbers and code are left out of the baseline because they follow the quoted source.
  - [x] F9 this file corrected (task 12 Verify ticked, task 13 recorded, this phase added); no stray sed backup files in the repo.
  - [x] F10 the compare page reads items through `GuideFormatRepository::items()` without resolving snippets (`GuideFormatPagesTest` "the compare page reads the format items without resolving their code").
  - [x] F11 completeness is checked once per key per request (`has()` memoised; `assertComplete` reuses it).
  - [x] F12 a format page sends each snippet once in a `snippets` list and items point at it by position; quiz "One failing photo" and trace "The vision queue" no longer overlap another snippet (`GuideFormatPagesTest` AC-2, `GuideFormatPageServiceTest`).
  - [x] F14 the unused `outcomes` prop is gone from the five format pages (props and Vue).
  - [x] F15 `GuideFormatFile` holds only what is read (type, items, snippets).
  - [x] F16 outcome coverage moved from the DTO into `GuideFormatPageService::coverage()` (`GuideFormatPageServiceTest`).
  - [x] F17 items cross the boundary as `App\DTOs\GuideFormatItem` (id, title, outcomes, snippet reference, format fields).
  - [x] F18 the controller picks the Inertia component; the service returns props only.
  - [x] F19 `trial()` uses `GuideFormatRepository::keys()` (complete sets found in the folder) and builds the summary from the set's format names (`GuideFormatPagesTest` "the trial card follows the format sets found in the folder").
  - [x] F20 one validation and mapping place: the `App\Enums\GuideFormatType` enum holds the format list, each format's items field and field schema; the route binds `{format}` to it (unknown format is 404 by enum binding), and the repository and service take the enum.
  - [x] **Verify**: see the gate record below.

- [x] 8. Gate: the owner tries the six formats and records the pick
  Covers: none (decision). The pick, with the rubric reasons, goes into `decisions.md`; the follow-up rollout slice is added to `backlog.md`.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | compare page, six entries, LOs | `tests/Feature/GuideFormatPagesTest.php::compare page lists the baseline and five formats with the outcomes` | test | 1 |
| AC-2 | five format pages answer 200 | `tests/Feature/GuideFormatPagesTest.php::each format page answers 200` | test | 2–6 |
| AC-3 | 404 for unknown key/format | `tests/Feature/GuideFormatPagesTest.php::unknown key or format is 404` | test | 1 |
| AC-4 | format files validated | `tests/Feature/GuideFormatContentTest.php::a broken format file names the file and field` | test | 1 |
| AC-5 | every format covers LO-1..LO-5 | `tests/Feature/GuideFormatContentTest.php::every format covers every learning outcome` | test | 2–6 |
| AC-6 | build-along progress persists | `manual-test.md#step-2` (walked 2026-11-18, task 13) | manual | 2 |
| AC-7 | quiz reveal and score | `tests/Feature/GuideFormatPagesTest.php::a radio only selects; the answer is committed by the Check button`, `manual-test.md#step-2` (walked 2026-11-18) | test + manual | 4 |
| AC-8 | trace Play/Pause and stages | `manual-test.md#step-2` (walked 2026-11-18) | manual | 3 |
| AC-9 | debug scenario flow | `tests/Feature/GuideFormatPagesTest.php::after the answer is shown the debug page marks the right cause…`, `manual-test.md#step-2` (walked 2026-11-18) | test + manual | 5 |
| AC-10 | tour stops and navigation | `tests/Feature/GuideFormatContentTest.php::every tour stop resolves to real source lines and each note starts on a line of code`, `manual-test.md#step-2` (walked 2026-11-18) | test + manual | 6 |
| AC-11 | trial card on /ai/guides | `tests/Feature/GuideFormatPagesTest.php::guides index shows the trial card` | test | 1 |
| MNC-1 | existing guide pages unchanged | `tests/Feature/GuidePageTest.php`, `tests/Feature/GuideFormatPagesTest.php::the photos guide renders the same props as on master` (baseline from master) | test | 1 |
| MNC-2 | guides index keeps entries and order | `tests/Feature/GuideFormatPagesTest.php::guides index keeps every guide in order` | test | 1 |
| MNC-3 | no slice ids, .md or paths in UI | `tests/Feature/NoReferencesInUiTest.php` | test | 7 |
| MNC-4 | fingerprint and parity unchanged | `manual-test.md#step-4` | manual | 7 |
| MNC-5 | suite offline | `manual-test.md#step-4` | manual | 7 |
| NFR-1 | keyboard and aria-live | `manual-test.md#step-3` (walked 2026-11-18, task 13) | manual | 2–6 |
| NFR-2 | layout at 1440/1024 | `manual-test.md#step-4` | manual | 7 |
| NFR-3 | offline, no writes | `tests/Feature/GuideFormatPagesTest.php::format pages write nothing and call no model` | test | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck` and `ddev npm run build`
- `node bench/ui-audit.mjs --only <the new pages>`

Gate record (2026-11-18, after the review fix pass): full suite 3468 passed, 3 skipped (44648 assertions); Pint passed; PHPStan no errors; `npm run typecheck` clean; `npm run build` ok; `node bench/ui-audit.mjs` on every lab page, the compare page and the five format pages included: 60 page(s) checked, 0 failing, exit 0; `bench/asset-fingerprint.sh` prints `app-DNob7CFE.css` / `app-PTzu1QaN.js`; parity capture on frankenphp, frankenphp-octane and frankenphp-worker differs only in `captured=` lines, then `git checkout -- bench/parity` and back to `ddev server frankenphp`; ArchTest green.

## Done when

- [x] Tasks 1–7 checked (ship)
- [ ] Task 8 checked when the owner records the pick (spec then `done`)
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green (record below)
- Clean-tree bench: after commit 7b10067, `bench/run.sh ping --quick` exited 0 on frankenphp.
