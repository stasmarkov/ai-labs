# Scene lessons for the remaining course outline — Tasks

**Spec**: spec-20261120-1032-scene-lessons-course | **Size**: L | **Tasks**: 6

## Phase 1: Lessons

- [x] 1. Running-models lessons (content)
  Covers: AC-2, AC-3
  - [x] 1.1 Write `streaming-an-answer` and `choosing-a-model-and-provider`.
  - [x] 1.2 **Verify**: `LessonContentTest` covers both lessons.

- [x] 2. Answers-you-can-trust lessons (content)
  Covers: AC-2, AC-3
  - [x] 2.1 Write `structured-output-and-validation`, `retrieval-with-citations` and `caching-answers`.
  - [x] 2.2 **Verify**: `LessonContentTest` covers all three lessons.

- [x] 3. Agents lessons, plus node kinds if needed (content, vue)
  Covers: AC-2, AC-3, AC-7
  - [x] 3.1 Write `a-tool-loop-with-caps-and-confirmation` and `mcp-and-a-research-agent`, and add up to 3 node kinds with icons and validation.
  - [x] 3.2 **Verify**: `LessonContentTest`, `LessonRepositoryTest` (new kinds), contrast test.

## Phase 2: Wiring and checks

- [x] 4. Course, guides, docs (content, docs)
  Covers: AC-1, AC-6
  - [x] 4.1 In `course.php`, turn on all eight lessons with real minutes, level and step count. Add `lessons` keys to the related guides. Update the `lessons` guide, the learning guide and the README.
  - [x] 4.2 **Verify**: `LessonPagesTest`, `GuidePageTest`, `GuideContentTest`, `DocsLinksTest`, `NoReferencesInUiTest`.

- [x] 5. Accuracy review and browser walk (checks)
  Covers: AC-4, AC-5, NFR-1, NFR-2
  - [x] 5.1 A fresh reviewer checks each lesson's text against the code and the result files. Then a Playwright walk of all eight lessons covers the keyboard, reduced motion and frame timing on the busiest step. The UI audit runs on `/ai/learn` and all eight lessons.
  - [x] 5.2 **Verify**: the results are recorded here.

- [x] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Review record (task 5)

Test: `UiAuditGuidePagesTest` AC-4 pins the audit page list to the course (written first, failed, then passed after adding the seven lessons to `bench/ui-audit.mjs`).

Accuracy review (NFR-1): each lesson's text checked against the code it describes, and each number against its result file (numbers are `{num:}` or kpi pointers, so the text cannot drift from the files; `LessonContentTest` pins that).

| Lesson | Claims checked against code | Result |
|--------|-----------------------------|--------|
| one-model-many-users | pilot, unchanged (MNC-1) | not re-reviewed |
| streaming-an-answer | `data:` lines, `[DONE]` marker, `X-Accel-Buffering`, `connection_aborted`, run row `stopped` (`SseProtocol`, `ConnectionAbortWatcher`, `AiRunRecorder`) | matches |
| choosing-a-model-and-provider | per-feature resolver and settings, `cloud-ai` limiter (`LimitCloudAi`), three attempts (`PhotoExtractionService::MAX_ATTEMPTS`) | matches |
| structured-output-and-validation | retry cap of three, failed after one try when the server is down, PHP rules after the schema | matches |
| retrieval-with-citations | reciprocal rank fusion, `ai.docs.rrf_depth`, gate before the model call | matches |
| caching-answers | key normalisation (`DocsAnswerCache::normalize`), versions in the key, store only cited answers, prune command | one fix: the text omitted that trailing exclamation marks are also removed; reworded |
| a-tool-loop-with-caps-and-confirmation | `tool_choice` none on the last call (`OllamaChatClient`), `AdminAssistantLoop::MAX_STEPS`, pending action then confirm | matches |
| mcp-and-a-research-agent | `Mcp::local('listings')` (stdio, no port), write tools propose only, research caps (`ai.research.max_model_calls`) | matches |

Browser walk (Playwright 1.55, Chrome, 1440 x 1000, https://ai-lab.ddev.site):

| Check | Result |
|-------|--------|
| Keyboard (ArrowRight through every step, ArrowLeft, R, Space pause and resume) in all eight lessons | works in all eight, steps counted 10, 10, 9, 10, 10, 9, 12, 12 |
| Reduced motion (`prefers-reduced-motion: reduce`) in all eight | steps and keys work, no running animations |
| Frame timing, every step of every lesson, 1.2 s each | median 16.7 ms (one frame at 60 Hz) at every step, well under 20 ms |
| Progress after finishing all eight | "8 of 8 lessons done" |

UI audit: `node bench/ui-audit.mjs --only learn,learn/running-models/one-model-many-users,learn/running-models/streaming-an-answer,learn/running-models/choosing-a-model-and-provider,learn/answers-you-can-trust/structured-output-and-validation,learn/answers-you-can-trust/retrieval-with-citations,learn/answers-you-can-trust/caching-answers,learn/agents/a-tool-loop-with-caps-and-confirmation,learn/agents/mcp-and-a-research-agent` at 1440 and 1024: 18 pages checked, 0 failing. The assets were rebuilt first (`npm run build`).

## Review record (task 15)

Run after the content edits of tasks 9 and 14, on a fresh `npm run build`. The test `UiAuditGuidePagesTest` AC-4 (course page and all eight lessons in the audit list) passes unchanged.

UI audit: `node bench/ui-audit.mjs --only learn,<the eight lesson paths>` at 1440 and 1024: 18 pages checked, 0 failing.

Browser walk (Playwright 1.55, Chrome, 1440 x 1000, reduced motion off). The aria-live text is the `sr-only` region after ArrowRight from step 1 (the text changed in every lesson). The busiest step has the highest sum of packet counts over the step's edges (the first one on a tie). The frame time is the median of requestAnimationFrame deltas over 5 s on that step.

| Lesson | aria-live text after a step change (start) | Busiest step | Median frame, 5 s |
|--------|--------------------------------------------|--------------|-------------------|
| one-model-many-users | Step 2 of 10: What a token stream is. | 7 of 10 | 16.7 ms |
| streaming-an-answer | Step 2 of 10: Send each piece as it is ready. | 7 of 10 | 16.7 ms |
| choosing-a-model-and-provider | Step 2 of 9: Same speed, slower start. | 1 of 9 | 16.7 ms |
| structured-output-and-validation | Step 2 of 10: Ask for a fixed shape. | 6 of 10 | 16.7 ms |
| retrieval-with-citations | Step 2 of 10: Cut the pages into chunks. | 8 of 10 | 16.7 ms |
| caching-answers | Step 2 of 9: Look it up first. | 7 of 9 | 16.7 ms |
| a-tool-loop-with-caps-and-confirmation | Step 2 of 12: Give the model a tool. | 4 of 12 | 16.7 ms |
| mcp-and-a-research-agent | Step 2 of 12: Publish them over MCP. | 8 of 12 | 16.7 ms |

Each announcement continues with the step's KPI labels and values (including `not measured` and the dash for steps before a figure exists). All medians are one frame at 60 Hz, under the 20 ms budget.

## Review record (task 14)

Sentences and KPIs added by task 9, checked against the code (NFR-1). `LessonContentTest` pins the caching case ("NFR-1 the caching invalidation step does not say stale entries are served") and the C-11 rule.

| Lesson, step | Added text or KPI | Checked against | Result |
|--------------|-------------------|-----------------|--------|
| caching-answers, Old answers go away | sentence "How many entries go stale with each change to the docs was not measured" (the earlier wording said stale entries were served, which `DocsAnswerCache::lookupExact` and `findCurrent` with the current versions and `notBefore()` rule out) | `DocsAnswerCache`, no bench file counts stale entries per docs change | reworded; matches |
| caching-answers, Old answers go away | KPI `stale` = not measured (new lesson KPI, "Entries gone stale per docs change", `—` at step 1) | same as above | matches |
| a-tool-loop-with-caps-and-confirmation, Only Confirm writes | sentence "How often a model claimed a change that was not made was not measured" | no run stores or counts false change claims | matches |
| mcp-and-a-research-agent, The confirmation is in the browser | sentence "How often a client tried to confirm on its own was not measured" | MCP tools only propose; no counter for confirm attempts | matches |
| mcp-and-a-research-agent, The confirmation is in the browser | KPI `mcp` = not measured | label "MCP calls that ended in an error"; no error count is recorded | matches |
| mcp-and-a-research-agent, A replay costs nothing | sentence "What a replay saves in time or cost was not measured" | `agent:research --replay` makes no model or search call; no timing of a replay is recorded | matches |
| mcp-and-a-research-agent, A replay costs nothing | KPI `time` = not measured | label "Run time (average)"; replay time is not recorded | superseded in the review fix pass: the measured `time` stays, and a separate text KPI `replay` says not measured |

## Review record (review fix pass, F1 to F21)

Every confirmed review finding, fixed after SL-56 shipped. Tests were written first where the finding is testable and failed before the fix.

| Finding | Fix | Verified by |
|---------|-----|-------------|
| F1 tries per photo | the retry step says "{kpi:attempts} tries per photo on average, retries included" and "up to three tries in all" (`PhotoExtractionService::MAX_ATTEMPTS` = 3, so 7 x 3 + 43 = 64 tries / 50 = 1.28); its change line now shows the valid-after-retries count (43 photos) instead of the first-try KPI. Every KPI sentence of the seven lessons was re-read against its file: caching "exact repeat" now says the repeat that found its key (`median_ms_by_path.exact`), the caching summary names the hit rate as the same run, retrieval "top three ... with smaller chunks", choosing "about the same" speed (51.6 vs 51.9 tok/s) | `LessonContentTest` "NFR-1 the review fixes hold" |
| F2 "the order flips" | step renamed "The assistant: a one-question gap": both models sent valid arguments on every first call (23/23); filters 23/24 vs 22/24; cited only returned listings 29/30 vs 30/30; the same direction as the photos but too small to rank | same test |
| F8 summary mixes models | structured summary: photo figures are the second model's run, `exact` and `caught` show "—" | same test |
| F9 health "slowest answers" | KPI label "Health check wait, slow end"; step 7 and 9 say the value is the test's limit (`probe_timeout_s` 10 s = `health_p95_ms` 10000) and not an answer time; step 8 and the summary say nineteen in twenty checks (p95) | same test |
| F10 '-' on a good outcome | streaming "no check goes unanswered" is '+', structured "a transport error is one try" is '~' | "F10 a solution step never marks a change with the minus sign" |
| F11 results before the solution | tool loop and MCP lessons show "—" on step 1; the loop figures appear from "PHP checks every call first", the research figures from "The caps stop the run" | "F11 the agent lessons show no measured result before the step that builds it" |
| F6, F16 replay overwrote run time | `time` keeps 20 s on the replay step; text KPI `replay` = not measured | "F17 ... a measured KPI is never overwritten" |
| F7, F15 C-11 passed on an inherited KPI | the C-11 test reads the step's own raw KPI patch and requires a not-measured value that the step before did not show; MCP confirmation gets its own text KPI `self` ("Clients that confirmed on their own"), MCP `mcp` is set on the step that names it, tool-loop confirm gets text KPI `claimed` ("Changes claimed but not made"); `written` stays "none by design" | "AC-2 C-11 ... in their own KPI patch" |
| F17 never-measured KPI format | every KPI no step reads from a file is format `text` (`written`, `claimed`, `mcp`, `self`, `replay`, `stale`) | "F17 a KPI that no step reads from a file is a text KPI" |
| F4 minutes (C-3) | the seven lessons have minutes = step count (9 to 12); the pilot (8 minutes, 10 steps) is left as it is under MNC-1 and is not in the test | "F4 C-3 a lesson lasts about a minute per step" |
| F3 guide links (C-6) | retrieval and caching `guide` is `docs`; chat lists streaming only | `GuidePageTest` "F3 AC-6 the guide a course lesson names lists that lesson" and the C-6 table test |
| F5 substring spot-check | each spot-check names its KPI, finds the step that points at the file and path, formats the read value with that KPI's format, scale and round and requires it among the shown values; BENCHMARKS.md must print the figure as a whole number (no digit, dot or comma around it); choosing now checks gemma's photo validity 1.000 (50/50) | "AC-2 two figures ..." and "AC-2 the whole-number match does not find a figure inside a longer one" |
| F18 literal contrast | the contrast test reads the icon stroke and each new kind's own colours from `SceneIcon.vue`, and the icon tile, card title and status fills from `SceneCanvas.vue`; a second test shows a pale stroke fails | `LessonRepositoryTest` AC-7 tests |
| F12 course resolves all lessons | `LessonRepository::summary()` reads `minutes` and the number of `steps` from the file; `LessonService::course()` no longer resolves any lesson, so `/ai/learn` and the sidebar read no result file | `LessonPagesTest` "F12 ... an unreadable result file breaks only its own lesson" |
| F13 git per file | one `git ls-files -- bench/results` per test run | AC-2 dataset test |
| F14, F21 helper closures | `App\Lessons\LessonSources` (`figure`, `seconds`, `percent`, `uncompared`) replaces the closures in the seven lesson files; the pilot is unchanged (MNC-1) | lesson tests, PHPStan |
| F19 hand-copied lists | the lessons guide builds "The eight lessons" from `course.php`; the README keeps its list (Markdown cannot read PHP) and a test requires the same modules, lessons and order as `course.php` | `GuidePageTest` "F19 ..." |
| F20 audit hard-codes lessons | `bench/ui-audit.mjs` collects lesson paths from the links on `/ai/learn` (`lessonPages`), keeping only the course page and the first lesson as fixed pages; no link found is a setup error | `UiAuditGuidePagesTest` "AC-4 F20 ..." |

Gates after the fix pass: full suite 3664 passed, 3 skipped; Pint clean; PHPStan no errors; typecheck and build clean; fingerprint `app-DNob7CFE.css` and `app-PTzu1QaN.js` unchanged; `bench/parity.sh capture` changed only `captured=` (restored with `git checkout -- bench/parity`). UI audit `--only learn,<eight lessons from course.php>`: "lessons 8 from /ai/learn", 18 pages checked, 0 failing. Playwright walk of the seven changed lessons: ArrowRight goes to step 2 and ArrowLeft back, Space pauses the scene, R replays, and the aria-live region reads "Step 2 of N: <title>. <KPI values>" on each; no console errors.

## Gates record (task 6)

- Full suite: 3612 passed, 3 skipped. Pint passed, PHPStan no errors, typecheck and build clean.
- Fingerprint: `app-DNob7CFE.css` and `app-PTzu1QaN.js`, unchanged.
- Parity: `PARITY_INSECURE=1 bench/parity.sh capture` changed only the `captured=` line; restored with `git checkout -- bench/parity`.
- `composer.lock` and `package-lock.json` unchanged.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | all eight playable | `tests/Feature/LessonPagesTest.php` | test | 4 |
| AC-2 | numbers from tracked files | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-3 | steps, outcomes, summary, deeper | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-4 | renders without overlap | `manual-test.md#step-1` | manual | 5 |
| AC-5 | keyboard, motion, progress | `manual-test.md#step-2` | manual | 5 |
| AC-6 | guides link lessons, docs | `tests/Feature/GuidePageTest.php` | test | 4 |
| AC-7 | new node kinds | `tests/Feature/LessonRepositoryTest.php` | test | 3 |
| MNC-1 | pilot and engine unchanged | verify existing SL-55 lesson tests pass unchanged | test | 6 |
| MNC-2 | no numeric literals | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| MNC-3 | no references, fingerprint, parity | `tests/Feature/NoReferencesInUiTest.php` | test | 4, 6 |
| MNC-4 | no deps, tables, calls | `tests/Feature/LessonPagesTest.php` | test | 6 |
| NFR-1 | accuracy | `manual-test.md#step-3` | manual | 5 |
| NFR-2 | smoothness | `manual-test.md#step-2` | manual | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- Clean-tree bench: after the commit, `bench/run.sh ping --quick` exited 0 on frankenphp.

## Phase: Gaps (converge round 1)

- [x] 7. Link the remaining related guides to their lessons, per the C-6 mapping (laravel)
  Covers: AC-6
  - The guides `moderation`, `photos` (structured-output-and-validation), `docs` (retrieval-with-citations, caching-answers), `assistant` (a-tool-loop-with-caps-and-confirmation), `local-models` (choosing-a-model-and-provider) and `chat` (streaming-an-answer) have no `lessons` entry for these lessons. The AC-6 test in `tests/Feature/GuidePageTest.php` lists only 8 guides, so it misses this.
  - [x] **Verify**: the AC-6 test in `GuidePageTest` walks the full C-6 table (all 12 guides) and passes. Each guide page's `guide.lessons` contains every lesson that C-6 maps to it.
- [x] 8. The lessons guide and the README list the eight course lessons (laravel)
  Covers: AC-6
  - `resources/guides/lessons.php` and README.md say only "eight lessons in three modules". Neither lists the lessons. The AC-6 test checks only the string `eight lessons`, not the C-12 rule that the lessons guide lists all eight.
  - [x] **Verify**: a test in `GuidePageTest` or `GuideContentTest` reads `course.php` and asserts that the `lessons` guide page (props) and README.md contain every one of the eight lesson titles (or links). The test fails before the edit and passes after it.
- [x] 9. Say "not measured" on the C-11 concept steps, and test the rule (laravel)
  Covers: AC-2
  - In `caching-answers.php`, the step "Old answers go away" (invalidation) has `'kpis' => []`, so it shows the measured cache latencies carried over from earlier steps. Its text never says "not measured".
  - In `mcp-and-a-research-agent.php`, neither "The confirmation is in the browser" nor "A replay costs nothing" says "not measured" in its text. The replay step also shows the measured research KPIs.
  - No test in `LessonContentTest` checks AC-2's second sentence.
  - [x] **Verify**: a new `LessonContentTest` case covers each C-11 concept step: cloud cost guard, cache invalidation, MCP confirmation, research replay and tool-loop confirm. It asserts that the resolved step has at least one KPI with the value `not measured`, and that the step's body, callout or changed text contains `not measured`. The test passes.
- [x] 10. Tie the course spot-checks to the result value and to BENCHMARKS.md (laravel)
  Covers: AC-2
  - `COURSE_SPOT_CHECKS` in `tests/Feature/LessonContentTest.php` checks only that a hard-coded string such as `'75 ms'` occurs somewhere in BENCHMARKS.md. It never compares that string with the value read from the result file. The lesson display always matches the file, because it is a pointer. So a result file that drifts from BENCHMARKS.md would still pass.
  - [x] **Verify**: each spot-check asserts the exact value read from the result file, as the pilot test does. It also asserts that BENCHMARKS.md contains that value rounded as BENCHMARKS.md prints it, built from the read value and not from a separate literal. Changing one expected value makes the test fail.
- [x] 11. Cover the seven new lesson pages in NoReferencesInUiTest (laravel)
  Covers: MNC-3
  - The page list in `tests/Feature/NoReferencesInUiTest.php` has only `/ai/learn` and the pilot. The seven new lessons are not in it. The raw-text check in `LessonContentTest` leaves out node and edge labels and the other rendered props.
  - [x] **Verify**: the page list adds every playable lesson from `course.php` (or the seven paths), and the test passes for each of them.
- [x] 12. Restore the coming-soon case in the SL-55 guide-link test (laravel)
  Covers: MNC-1
  - The C-13 test in `GuidePageTest` used `running-models/streaming-an-answer` as its coming-soon fixture. This slice replaced it with `agents/not-yet-written`, which is just an unknown key. As a result, `LessonService::titles` dropping a `comingSoon` entry is no longer tested.
  - [x] **Verify**: the C-13 test binds a fixture course with a `coming_soon` entry and asserts that the entry is not linked from the guide page. It passes.

## Phase: Gaps (converge round 2)

- [x] 13. Link the photos guide to its lesson and make the AC-6 test walk the whole C-6 table (laravel)
  Covers: AC-6
  - `resources/guides/photos.php` still has no `lessons` key, although C-6 maps `structured-output-and-validation` to `photos`. The AC-6 test in `tests/Feature/GuidePageTest.php` has 11 keys, not 12: `photos` is missing, and `local-models` checks only the pilot, not `choosing-a-model-and-provider`. So task 7's Verify is not met. The photos guide props are pinned by the SL-54 baseline `tests/Fixtures/guides/photos-guide-props.json` (`GuideFormatPagesTest` "MNC-1 the photos guide renders the same props as on master"), so the link needs that fixture updated, with only the `lessons` field added.
  - [x] **Verify**: the AC-6 test lists all 12 C-6 guides, including `photos` => structured-output-and-validation and `local-models` => the pilot and choosing-a-model-and-provider. It fails before the guide edit and passes after it. `GuideFormatPagesTest` passes, and the baseline fixture differs from master only by `lessons`.
- [x] 14. Fix the caching "not measured" sentence that contradicts the code, and review the sentences added after the accuracy review (laravel)
  Covers: NFR-1
  - Task 9 added text after the task 5 accuracy review. In `resources/lessons/answers-you-can-trust/caching-answers.php`, step "Old answers go away" now says "How many stale entries the cache served before pruning was not measured." The step's first paragraph, and `DocsAnswerCache` (`findCurrent` with the current versions and `notBefore()`), say a stale or expired entry is never served. The new sentences in `a-tool-loop-with-caps-and-confirmation.php` and `mcp-and-a-research-agent.php`, and the three new `not measured` KPIs, are not in the review record either.
  - [x] **Verify**: the caching sentence no longer suggests that stale entries are served, and it still contains `not measured`. The review record in tasks.md has a row for each task 9 sentence and KPI, checked against the code. The C-11 test in `LessonContentTest` passes.
- [x] 15. Re-run the UI audit and the browser walk after the content edits, with aria-live and the C-8 busiest-step timing (laravel)
  Covers: AC-4, AC-5, NFR-2
  - The audit and the walk in the task 5 record ran before tasks 9 and 14 changed step text and KPIs (`not measured` literals in three more KPI strips, longer bodies). The walk record does not check the `aria-live` announcement on the seven new lessons, although AC-5 names it and the SL-55 walk checked it. C-8 asks for each lesson's busiest step (the highest sum of edge rates), sampled for 5 s with reduced motion off, and its median recorded. The record has 1.2 s per step and one shared figure.
  - [x] **Verify**: `node bench/ui-audit.mjs --only learn,<the eight lesson paths>` run after the edits checks 18 pages with 0 failing, and the result is recorded. For each of the eight lessons, the walk record shows the aria-live text after a step change, the busiest step's number and its 5 s median frame time, each at or under 20 ms.
- [x] 16. Record the two new node kinds in the spec's Decisions (laravel)
  Covers: MNC-1
  - C-5 says every added node kind and its reason goes in Decisions. `tools` and `person` were appended to `LessonRepository::NODE_KINDS`, but `spec-lite.md` Decisions still has only D-1 and D-2.
  - [x] **Verify**: `spec-lite.md` Decisions has an entry naming `tools` and `person`, the lessons that use each, and why no existing kind fits. The existing node kinds and their order in `NODE_KINDS` are unchanged.
- Clean-tree bench after the review fix pass: after commit 1d35e91, `bench/run.sh ping --quick` exited 0 on frankenphp.
