# Scene lessons for the reference-only guides — Tasks

**Spec**: spec-20261120-2106-reference-guide-lessons | **Size**: L | **Tasks**: 7

## Phase 1: Lessons

- [x] 1. Running-models and answers lessons: `describing-a-car`, `prompts-you-can-version`, `guards-on-both-sides` (content)
  Covers: AC-2, AC-3, AC-4
  - [x] 1.1 Write the three lesson files; add `lessons` keys to `describe`, `prompts`, `playground`, `guards`; remove them from `references`.
  - [x] 1.2 **Verify**: `LessonContentTest` covers the three lessons.

- [x] 2. Search-and-photos lessons: `search-by-meaning`, `searching-photos-by-text-and-likeness`, `finding-duplicate-photos` (content)
  Covers: AC-2, AC-3, AC-4
  - [x] 2.1 Write the three lesson files; add `lessons` keys to `semantic`, `photo-search`, `similar-photos`, `photo-duplicates`; remove them from `references`.
  - [x] 2.2 **Verify**: `LessonContentTest` covers the three lessons.

- [x] 3. Running-the-server lessons: `the-run-log`, `logs-and-a-safe-worker-restart`, `seeing-your-change-in-worker-mode` (content)
  Covers: AC-2, AC-3, AC-4
  - [x] 3.1 Write the three lesson files; add `lessons` keys to `run-log`, `logs-restart`, `worker-dx`; remove them from `references`.
  - [x] 3.2 **Verify**: `LessonContentTest` covers the three lessons.

- [x] 4. Course and page tests move to 17 lessons and no reference topics (laravel)
  Covers: AC-1, AC-6, MNC-1, MNC-5, NFR-3
  - [x] 4.1 Update `LessonPagesTest`, `LessonRepositoryTest`, `GuidePageTest` expectations; keep a fixture-based test for reference topics so that code path stays covered.
  - [x] 4.2 **Verify**: tests green.

## Phase 2: Docs and checks

- [x] 5. Docs: `lessons` guide, README, learning guide (docs)
  Covers: AC-7, MNC-3
  - [x] 5.1 List the 17 lessons; no slice ids or paths in guide text.
  - [x] 5.2 **Verify**: `NoReferencesInUiTest`, `DocsLinksTest` green.

- [x] 6. Accuracy review of the nine lessons against code and result files (review)
  Covers: NFR-1, MNC-2
  - [x] 6.1 Fresh reviewer checks every claim and number; record the table in this file.
  - [x] 6.2 **Verify**: every row resolved.

- [x] 7. Manual: walk manual-test.md
  Covers: AC-5, AC-6, NFR-2, MNC-3, MNC-4
  - [x] 7.1 **Verify**: checked 2026-11-20 by script: every step of the nine lessons at 1440 and 1024 with 0 overlaps or clipped labels; ArrowRight/ArrowLeft, Space to Resume and aria-live text on all nine with reduced motion; progress "9 of 17" after walking them; ui-audit 138 pages 0 failing; fingerprint unchanged; parity only captured=; uncapped frame cost under 1 ms median (the machine caps rAF at 30 Hz, so the 60 Hz check in a real Chrome is not repeated).

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | 17 lessons, no reference topics | `tests/Feature/LessonPagesTest.php` | test | 4 |
| AC-2 | numbers from tracked files | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-3 | steps, outcomes, summary, deeper | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-4 | guides link their lesson | `tests/Feature/GuidePageTest.php` | test | 1, 2, 3 |
| AC-5 | scenes fit, audit exit 0 | `manual-test.md#step-5` | manual | 7 |
| AC-6 | keyboard, motion, progress 17 | `manual-test.md#step-6` | manual | 7 |
| AC-7 | docs list 17 lessons | `manual-test.md#step-7` | manual | 5 |
| MNC-1 | existing lessons unchanged | `tests/Feature/LessonRepositoryTest.php` | test | 4 |
| MNC-2 | no numeric literals | `tests/Feature/LessonContentTest.php` | test | 6 |
| MNC-3 | no refs, fingerprint, parity | `tests/Feature/NoReferencesInUiTest.php` | test | 5, 7 |
| MNC-4 | no deps, tables, routes | `manual-test.md#step-11` | manual | 7 |
| MNC-5 | SL-57 pages as shipped | `tests/Feature/GuidePageTest.php` | test | 4 |
| NFR-1 | accuracy review | `manual-test.md#step-13` | manual | 6 |
| NFR-2 | frame time ≤ 20 ms | `manual-test.md#step-14` | manual | 7 |
| NFR-3 | payload < 60 000 | `tests/Feature/LessonPagesTest.php` | test | 4 |

## Accuracy review (NFR-1)

Each claim was read in the lesson text and checked against the code or the result file named in the third column. "fixed" means the lesson text was corrected in this task. No numeric literal sits in any lesson text; every figure is a placeholder that `LessonContentTest` resolves from a tracked result file.

| Lesson | Claim | Checked against | Result |
|--------|-------|-----------------|--------|
| `describing-a-car` | The listing is looked up and the provider resolved before the stream opens | `ListingDescriptionService::describe` | ok |
| `describing-a-car` | The prompt is five lines: car, fuel, mileage, price, city; the wording is a versioned file named by one setting | `CarDescriptionAgent`, `resources/prompts/car-description.v1.md`, `config/ai.php` prompts | ok |
| `describing-a-car` | One line per piece, flush, abort check after every flush, the stream is released, a stopped row is written | `SseProtocol::response`, `recordAbort` | ok |
| `describing-a-car` | The page gets one fixed error event, the cause stays in the row with class and message | `SseProtocol::maskedErrorParts`, `AiRunRecorder`, `ai_runs` columns | ok |
| `describing-a-car` | Pieces after Stop and the probe times (idle and after Stop) on classic FrankenPHP | `chat-stop/2026-11-03/frankenphp.json` | ok |
| `prompts-you-can-version` | A version holds prompt, note, temperature and max tokens; the save locks the prompt row before reading the highest number | `EloquentPlaygroundPromptRepository::addVersion`, `PlaygroundVersionRules` | ok |
| `prompts-you-can-version` | No update route or method; a save equal to the latest version is refused and the note alone does not count | `routes/catalog.php`, `NewPlaygroundVersion::isUnchangedFrom` | ok |
| `prompts-you-can-version` | Two or three distinct models, everything checked before the first call, run in turn, injection match only flags | `PlaygroundRunner`, `JsonErrorRenderer` | ok |
| `prompts-you-can-version` | The lab record "used" 3 prompts and 7 versions, but the file counts every stored prompt and version | `EloquentPlaygroundRunRepository::statistics`, `playground/2026-11-14/stats.json` | fixed |
| `prompts-you-can-version` | "Above temperature zero every answer varies" was too strong | model behaviour, reworded to "can change" | fixed |
| `guards-on-both-sides` | The question and every earlier user turn are matched before the cache, retrieval and the gate; the refusal names the pattern | `DocsAnswerService::plan`, `PromptInjectionGuard`, `resources/guards/prompt-injection.v1.php` | ok |
| `guards-on-both-sides` | The canary is new for every request; the output guard checks the canary and a run of copied prompt words not in the sources | `CanaryGenerator`, `OutputGuard` | ok |
| `guards-on-both-sides` | A leak ends the stream with a fixed refused event that clears the drawn text; the reason is stored with stage input or output | `DocsAnswerProtocol`, `AiRunRecorder::refused` | ok |
| `guards-on-both-sides` | The Scope story beat "answer guard checks citations" does not hold: `OutputGuard::leak` checks only the canary and copied prompt-word runs, so the lesson names those two checks and no citation check | `OutputGuard::leak` | dropped |
| `guards-on-both-sides` | Zero false refusals of the on-topic questions, and no hostile question in the eval set (stated as not measured) | `RagAnswerEvaluator::score`, `rag-eval/2026-11-03/answers-qwen-500-300/answers.json` | ok |
| `search-by-meaning` | Ten nearest by cosine distance, similarity is one minus the distance, a wrong vector length is refused | `SemanticSearchService`, `EloquentSemanticSearchRepository` | ok |
| `search-by-meaning` | The app setting is 400 candidates; the search forbids a sequential scan for its transaction | `config/ai.php` embeddings, `SET LOCAL enable_seqscan` in the repository | ok |
| `search-by-meaning` | Recall at the low and high settings, the index time, the embed time and the estate probe | `2026-11-02-embeddings/search-bench.json`, `BENCHMARKS.md` | ok |
| `search-by-meaning` | A search is refused when the listings were embedded by another model; a row is embedded again when its model name or text changed | `IndexNotBuiltException`, `resources/guides/semantic.php` | ok |
| `searching-photos-by-text-and-likeness` | The query is checked by the injection guard before the encoder; empty results have their own causes; the template is "a photo of" plus the query | `PhotoTextSearchService`, `resources/prompts/photo-search.v1.md` | ok |
| `searching-photos-by-text-and-likeness` | The cache key holds the label, the generation, the prompt version and a hash of the query; a hit writes no run row | `PhotoTextSearchService::cacheKey`, `search` | ok |
| `searching-photos-by-text-and-likeness` | Precision, hit rate, the tie between bare and template, cached and uncached tail times | `photo-search/2026-11-11/run.json` | ok |
| `searching-photos-by-text-and-likeness` | Same make against same make and model, and the small hybrid gain at the best weight; the title run is marked as leaking | `photo-similar/2026-11-11/run.json`, `photo-similar/2026-11-12/hybrid.json` | ok |
| `finding-duplicate-photos` | Five kinds of degraded copy, each remembering its source and kind | `PhotoVariant`, `PhotoDuplicateGeneratorService` | ok |
| `finding-duplicate-photos` | Groups are connected components over the pairs at or above the cutoff, cut at the weakest link past the cap | `DuplicateClusterService` | ok |
| `finding-duplicate-photos` | The cutoff, the recall at it and at the old default, the crop recall, the group counts and the grouping time | `photo-duplicates/2026-11-12/run.json` | ok |
| `finding-duplicate-photos` | "The model picked the original in 26 %" hid that two models were judged and the second picked more | `judging.models` in the run file, `BENCHMARKS.md` | fixed |
| `finding-duplicate-photos` | A bad id is rejected and the call repeated up to a cap; the pick is stored as the model's or the reviewer's; nothing is deleted | `DuplicateJudgementService`, `DuplicateReviewService` | ok |
| `the-run-log` | The recorder notes agent, provider, model and prompt version at the start and writes one row at the end | `AiRunRecorder::started`, `ai_runs` model | ok |
| `the-run-log` | Failed, stopped, refused, no-context and cached calls each get a status; the message is trimmed and configured or known-prefix keys are replaced | `AiRunRecorder` (redaction and length cap), `SseProtocol::recordAbort` | ok |
| `the-run-log` | The overview lists tokens, first token, total time and status, a failed one in red; one run opens as JSON with its error message | `Overview.vue`, `ShowLabRunController`, `AiRunDetail` | ok |
| `the-run-log` | First-token time, failover time to fail, and the five stopped rows | `llm/2026-11-13/run.json`, `chat-stop/2026-11-03/frankenphp.json` | ok |
| `the-run-log` | No job or command removes old rows; growth per day is not measured | search of `app/` and `routes/console.php` | ok |
| `the-run-log` | No count of runs, outcomes or errors per status exists in the results: the `runs`, `errors`, `failed` and `outcome` fields found by searching `bench/results` belong to load and benchmark files, none counts `ai_runs` rows, so the lesson says "not measured" | search of `bench/results` for run, outcome and error count fields | ok |
| `logs-and-a-safe-worker-restart` | An incoming id is reused only when it passes a pattern, else a new ULID; the id is hidden context and a response header | `AssignRequestId` | ok |
| `logs-and-a-safe-worker-restart` | The text said "short safe string"; the pattern allows a range of lengths, so a short one is replaced | `AssignRequestId` regex | fixed |
| `logs-and-a-safe-worker-restart` | Fields per entry, cli and queue contexts, the path without a query string, secrets redacted by key suffix at any depth | `RequestContextProcessor`, `FrankenphpJsonFormatter` | ok |
| `logs-and-a-safe-worker-restart` | Entries go through the server log function in a request and to stderr as JSON elsewhere; nested arrays are flattened | `FrankenphpLogHandler` | ok |
| `logs-and-a-safe-worker-restart` | The 526 figure counts answers that were not 2xx, not only server errors, and "connections hung" is not in the file | `load_non_2xx` and its note in `restart/2026-11-17/frankenphp-octane-api-ping.json` | fixed |
| `logs-and-a-safe-worker-restart` | Overhead of the channel against its budget, lost probes per restart kind, tail latency and whole-run errors | `restart/2026-11-17/` result files | ok |
| `seeing-your-change-in-worker-mode` | Medians for classic, restart by hand, watcher on worker and Octane, the restart call time and the sync time; three "never" trials without a watcher | `reload/2026-11-17/*.json` | ok |
| `seeing-your-change-in-worker-mode` | The watcher watches app, config, routes, resources, bootstrap, the lock file and the env file; empty means off; cached config hides edits | `.ddev/commands/host/dev-watch`, worker Caddyfile | ok |
| `seeing-your-change-in-worker-mode` | The command restarts the project, not only the web container | `.ddev/commands/host/dev-watch` (`ddev restart`) | fixed |
| `seeing-your-change-in-worker-mode` | The middleware opens the session by hand only in a worker, and the worker is recycled after the debugged response | `ConnectXdebugOnTrigger`, `WorkerRecycle` | ok |
| `seeing-your-change-in-worker-mode` | How the wait was measured: a unique marker polled with a cap, five rows, statistics over numeric trials | `bench/reload-latency.sh` | ok |
| `describing-a-car` | (F2) The stop figures came from the docs answer stream but read as a description measurement; the step now says which stream was measured | `chat-stop/2026-11-03/frankenphp.json`, `SseProtocol` | fixed |
| `describing-a-car` | (F9) The callout said the page is never told why a call failed; the run page serves the stored message | `routes/web.php` `ai.runs.show`, `AiRunDetail::toArray` | fixed |
| `search-by-meaning` | (F8) The recall KPIs used count format, so a fraction read as a count of neighbours | `2026-11-02-embeddings/search-bench.json`, `Source::percent` | fixed |
| `searching-photos-by-text-and-likeness` | (F5) Text-search precision was described as a share of returned photos; the metric is a mean per query with empty queries counted as zero | `photo-search/2026-11-11/run.json` | fixed |
| `the-run-log` | (F1) The summary said the log leaves out calls that never reached the model, which contradicted the failed-row step | `AiRunRecorder::failed`, the lesson's failure step | fixed |
| `the-run-log` | (F9) The failure step said the page never learns why and that anything shaped like a key is masked; only configured keys of eight or more characters and `sk-`, `AIza`, `org-` keys are masked, and the public run page serves the message | `AiRunRecorder::safeMessage`, `routes/web.php` `ai.runs.show`, `AiRunDetail::toArray` | fixed |
| `logs-and-a-safe-worker-restart` | (F6) The lesson said two requests never share an id; a valid client-supplied id is reused as is | `AssignRequestId` | fixed |
| `logs-and-a-safe-worker-restart` | (F10) "Put values in the context" implied every value is redacted; only values under sensitive key names are | `RequestContextProcessor`, `FrankenphpJsonFormatter` | fixed |
| `seeing-your-change-in-worker-mode` | (F3) Five rows were called three modes each with and without the watcher, which would be six | `reload/2026-11-17/*.json` | fixed |
| `seeing-your-change-in-worker-mode` | (F4) The manual-restart step described both server modes but quoted only the worker-mode figure | `reload/2026-11-17/*.json` | fixed |

Review findings: the rows marked (F1) to (F10) are the lesson findings of the adversarial review, all fixed. F7 (the learning guide still named the old last lesson and left out this spec) was fixed in `docs/learning-guide.md`. Two non-lesson findings were fixed in the finishing pass: F11 (the lesson and guide sidebars required every lesson file; they now read only the course file through `LessonService::outline`, covered by the `LessonPagesTest` F11 test) and F12 (the lessons guide typed the lesson count by hand; it no longer states a count and `GuidePageTest` finds the list entry by its course-derived body).

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

- [x] 8. Test that each of the 11 guides links its own new lesson in the topic header (laravel)
  Covers: AC-4
  - [x] 8.1 Add the 11 guides to the `$links` map in `GuidePageTest` "AC-6 every course lesson is linked from the guide it teaches": `describe` to `describing-a-car`, `prompts` and `playground` to `prompts-you-can-version`, `guards` to `guards-on-both-sides`, `semantic` to `search-by-meaning`, `photo-search` and `similar-photos` to `searching-photos-by-text-and-likeness`, `photo-duplicates` to `finding-duplicate-photos`, `run-log` to `the-run-log`, `logs-restart` to `logs-and-a-safe-worker-restart`, `worker-dx` to `seeing-your-change-in-worker-mode`.
  - [x] 8.2 **Verify**: `ddev artisan test --compact tests/Feature/GuidePageTest.php` is green, and pointing any one of those guides' `lessons` key at another lesson makes it fail.

- [x] 9. List the 17 lessons in the learning guide's "Scene lessons" paragraph and test it (docs)
  Covers: AC-7
  - [x] 9.1 In `docs/learning-guide.md` II-23 "Scene lessons", name every course lesson by module, in the format the README uses. Extend `GuidePageTest` "AC-6 the lessons guide page and the README name every course lesson" so it also checks every `course.php` lesson title is in `docs/learning-guide.md`.
  - [x] 9.2 **Verify**: `ddev artisan test --compact tests/Feature/GuidePageTest.php tests/Feature/LearningGuideTest.php tests/Feature/DocsLinksTest.php` is green, and the extended test fails if one title is removed from the learning guide.

- [x] 10. Assert the reference-topic guide page shape in the fixture test (laravel)
  Covers: MNC-5
  - [x] 10.1 In the `GuidePageTest` fixture test that adds `ref-ok` to `running-the-server` references, replace the bare `assertOk()` on `/ai/guides/ref-ok` with an Inertia assertion: `topic.module.slug` is `running-the-server` and `topic.lessons` is `[]`, which is the state `TopicHeader.vue` draws as "Walk through · lesson coming".
  - [x] 10.2 **Verify**: `ddev artisan test --compact tests/Feature/GuidePageTest.php` is green, and giving `ref-ok` a `lessons` link (or leaving it out of the references) makes the assertion fail.

## Phase: Gaps (converge round 2)

- [x] 11. Restore the parse-error guide case in the broken-guide fixture test (laravel)
  Covers: MNC-5
  - [x] 11.1 In `GuidePageTest` "MNC-5 F1 a broken guide file fails only its own page", add a third reference guide `ref-parse` whose file is `"<?php\nreturn ['kind' => "` (the case the old test had on `run-log.php`), list it in `running-the-server` references next to `ref-ok` and `ref-broken`, and assert `/ai/learn` and `/ai/guides/chat` still list only `ref-ok` and `/ai/guides/ref-parse` answers 500.
  - [x] 11.2 **Verify**: `ddev artisan test --compact tests/Feature/GuidePageTest.php` is green, and making `GuideRepository::read` rethrow the `\Throwable` instead of an `InvalidGuideException` makes the test fail.

- [x] 12. Record the C-7 run-count search and the C-8 dropped story beats in the NFR-1 review table (review)
  Covers: NFR-1
  - [x] 12.1 Add rows to "Accuracy review (NFR-1)": for `the-run-log`, the search of `bench/results/**` for run, outcome or error count fields and its result (C-7); for `guards-on-both-sides`, that the Scope story's "answer guard checks citations" was dropped because `OutputGuard::leak` checks only the canary and copied prompt-word runs (C-8); and any other Scope-table beat a lesson rewrote or dropped, each with result `dropped` or `fixed`. Extend the row pattern in `LessonContentTest` "NFR-1 MNC-2 the accuracy review records ..." to accept `dropped` (C-17), since it now matches only `ok|fixed|open` and would skip a `dropped` row.
  - [x] 12.2 **Verify**: `ddev artisan test --compact --filter="accuracy review" tests/Feature/LessonContentTest.php` is green and the table has a `the-run-log` row naming `bench/results` and a `guards-on-both-sides` row with result `dropped`.

- Clean-tree bench after the review fix pass: after commit e5ced8f, `bench/run.sh ping --quick` exited 0 on frankenphp.
