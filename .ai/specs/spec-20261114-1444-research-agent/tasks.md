# Multi-step research agent — Tasks

**Spec**: spec-20261114-1444-research-agent | **Size**: M | **Tasks**: 6

## Phase 1: Engine

- [x] 1. Storage (backend)
  Covers: AC-1, AC-7
  - [x] 1.1 Add the `research_runs` and `research_steps` migrations, models and factories, and the repository contracts in the repo's layering style.
  - [x] 1.2 **Verify**: `tests/Feature/ResearchRunnerTest.php` (schema and relations).

- [x] 2. Agents and runner (backend)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, AC-7, AC-8, AC-9, NFR-1
  - [x] 2.1 Add:
    - the three agents and their v1 prompts;
    - the `research` feature key, config and `.env.example` entries;
    - `ResearchRunner`: the phases, the guard, the plan retry, search via `DocsSearchService`, notes in Redis, chunk-id filtering, report citations, the caps and the step logging.
  - [x] 2.2 **Verify**: `tests/Feature/ResearchRunnerTest.php`, with faked agents and embeddings:
    - the happy path;
    - the planner invalid twice;
    - a forged chunk id dropped;
    - no facts gives the fixed report with no call;
    - unknown citations removed;
    - the Redis hash during the run and the steps after;
    - an `ai_runs` row per call;
    - an injection gives `refused`;
    - the caps.

## Phase 2: Surfaces

- [x] 3. Command and replay (backend)
  Covers: AC-6, NFR-3
  - [x] 3.1 Add `agent:research {topic?}` with `--provider`, `--json` and `--replay` (C-8).
  - [x] 3.2 **Verify**: `tests/Feature/ResearchReplayTest.php`:
    - a replay prints the stored steps;
    - `Http::assertNothingSent` and no agent prompt during the replay;
    - the exit codes.

- [x] 4. Endpoints, job, page (backend, frontend)
  Covers: AC-10, NFR-2
  - [x] 4.1 Add:
    - the job;
    - `POST /api/research` (202) and `GET /api/research/{id}`;
    - the rate limit, the cross-site refusal and `LimitCloudAi`;
    - `Lab/Research.vue`, with the timeline, the escaped markdown report, the sources, client-side replay and the recent runs;
    - the navigation and capability entries.
  - [x] 4.2 **Verify**: `tests/Feature/ResearchEndpointTest.php`:
    - 202 and the run, the shape and 404;
    - 429;
    - 403 cross-site;
    - the escaped report in the page props;
    - the settings row.

## Phase 3: Docs and checks

- [x] 5. Guide and docs (docs)
  Covers: AC-11
  - [x] 5.1 Add the `research` guide, the learning-guide section and the README lines.
  - [x] 5.2 **Verify**: `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, typecheck and build.

- [x] 6. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Orchestrator:
    - a live `agent:research` on qwen3.5:9b with 2 topics (one covered by the docs, one not);
    - a replay;
    - the page run in the browser;
    - the gates, the fingerprint and parity;
    - commit, the ping and push.
  - [x] 6.2 **Verify**: outputs recorded here.
    - Live runs on qwen3.5:9b (ollama): the covered topic ended `done` in 8 steps with a cited report; the uncovered topic ended `done` with the fixed no-facts report and no report model call.
    - Replay of the covered run: exit 0, same report, `ai_runs` count unchanged (12716).
    - Guard topic: `refused`, exit 1, one `refused` ai_runs row (the runner logs it, as the AC-8 test asserts).
    - Page and API: `/ai/research` 200; `POST /api/research` 202 then the queue job ended `done` and `GET /api/research/{id}` returned the report (no browser available, checked over HTTP).
    - Gates: 2742 passed, 3 skipped; Pint passed; PHPStan no errors; typecheck and build pass.
    - Fingerprint equals BENCHMARKS.md (app-DNob7CFE.css, app-PTzu1QaN.js); parity diff is `captured=` only.
    - Commit, ping and push not done here.
    - **Review fix pass (2026-11-14), live on qwen3.5:9b after the fixes:**
      - Step 1: `agent:research "How does hybrid search combine vector and text ranking?"` printed 8 steps as they finished, ended `[done] ollama / qwen3.5:9b`, exit 0, cited report with 6 sources (run `01a1da7a-f2a7-7293-b607-d571c3fe4f5c`); the plan, note and report steps had an `ai_run_id` (12838, 12840, 12842, 12844, 12845), the search steps `null`; all five `ai_runs` rows are ollama / qwen3.5:9b with `validation_failed` false.
      - Step 2: while the queued-jobs run went, the Redis hash `research:run:<id>:notes` existed with TTL 86392; after it ended the hash fields (0: facts, 1: no facts, 2: facts) equal the `note` step outputs of `--replay --json`.
      - Step 3: "cooking pasta" ended `done`, exit 0, the fixed not-covered report, `model_calls` 1, every note `skipped` with `no_sources`.
      - Step 4: replay exit 0 (text and `--json`), `ai_runs` count 12767 before and after, the JSON report equals the text printed in step 1.
      - Step 5: unknown id and `--replay=not-a-uuid` print "No research run with this id.", no topic and "ab" print their messages; the application exit is 2 for all four (ddev prints "exit status 2" and returns 1 to the shell).
      - Step 6: the injection topic printed `[refused]`, `Error: input:prompt-injection.v1:ignore-previous`, no step blocks, exit status 3.
      - Step 7: not as written. The step inputs store chunk ids only (C-11), so `<chunk id=` is not in a replay; the marked-data wrapping is asserted by `AC-8 sends chunks to the note agent only as marked data` in `ResearchRunnerTest`.
      - Steps 8 to 10: see 9.1.
    - **Gates after the review fixes:** the whole suite passes (2774 passed, 3 skipped), Pint passes, PHPStan reports no errors, typecheck and build pass, the fingerprint equals BENCHMARKS.md (`app-DNob7CFE.css`, `app-PTzu1QaN.js`), the parity diff was `captured=` only and was reverted with `git checkout -- bench/parity`.
    - **Guide numbers:** `ddev artisan research:stats` wrote `bench/results/research/2026-11-14/stats.json` (15 runs: 11 done, 4 refused; average 3.9 model calls, largest run 5, average 20 s) and the numbers tab reads it.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | phases stored, run done | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-1`, `manual-test.md#step-9` | test | 2 |
| AC-2 | planner fails twice | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-17` | test | 2 |
| AC-3 | forged chunk ids, no-facts report | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-3` | test | 2 |
| AC-4 | citations to sources only | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-1` | test | 2 |
| AC-5 | notes in Redis then in steps | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-2` | test | 2 |
| AC-6 | replay without calls | `tests/Feature/ResearchReplayTest.php`; `manual-test.md#step-4`, `manual-test.md#step-10` | test | 3 |
| AC-7 | ai_runs per call, provider resolution | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-1` | test | 2 |
| AC-8 | guard refusal | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-6`, `manual-test.md#step-7`, `manual-test.md#step-13` | test | 2 |
| AC-9 | caps | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-16` | test | 2 |
| AC-10 | endpoints and page | `tests/Feature/ResearchEndpointTest.php`; `manual-test.md#step-8`, `manual-test.md#step-9`, `manual-test.md#step-14` | test | 4 |
| AC-11 | guide | `tests/Feature/GuideContentTest.php`; `manual-test.md#step-18` | test | 5 |
| MNC-1 | docs search and ask unchanged | verify existing docs search and ask tests pass unchanged; `manual-test.md#step-19` | test | 6 |
| MNC-2 | other features unchanged | verify existing AI feature and settings tests pass unchanged; `manual-test.md#step-20` | test | 6 |
| MNC-3 | fingerprint and parity | `manual-test.md#step-4`; `manual-test.md#step-21` | manual | 6 |
| MNC-4 | offline suite | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-22` | test | 2 |
| NFR-1 | bounded cost | `tests/Feature/ResearchRunnerTest.php`; `manual-test.md#step-15`, `manual-test.md#step-16` | test | 2 |
| NFR-2 | safety | `tests/Feature/ResearchEndpointTest.php`; `manual-test.md#step-12`, `manual-test.md#step-23` | test | 4 |
| NFR-3 | replay fidelity | `tests/Feature/ResearchReplayTest.php`; `manual-test.md#step-4`, `manual-test.md#step-10` | test | 3 |

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

- [x] 7. Command exit codes, unknown-replay message and live step output per C-8 (backend)
  Covers: AC-6
  - `AgentResearchCommand::show()` returns 1 (`FAILURE`) for both `failed` and `refused`, but C-8 says 3 for `refused`. `replay()` prints "No research run {id}." and returns 1, but C-8 says "No research run with this id." and exit 2. `ResearchReplayTest` asserts the wrong codes (exit 1 for an unknown id and for a refused topic). Scope item 5 says the command "prints each step live as it finishes", but `ResearchRunner::run()` has no per-step hook, so the command prints every step only after the run ends.
  - Approach: return 3 for `refused`, and print the C-8 message with exit 2 for an unknown replay id. Add an optional step callback to `ResearchRunner::execute()`/`run()`, called after each `store()`, so the command can print each step as it finishes. Update the replay tests to match.
  - [x] 7.1 **Verify**: `tests/Feature/ResearchReplayTest.php` asserts exit 3 for a refused topic, exit 2 and "No research run with this id." for an unknown `--replay` id, and exit 1 for a `failed` run. A live faked run asserts each `Step N …` line comes out in order through the callback. `ddev artisan test --compact tests/Feature/ResearchReplayTest.php` passes.

- [x] 8. Page/API replay fidelity with no outbound call (backend)
  Covers: AC-6, NFR-3
  - AC-6 covers replay from "the page" too, and C-10 checks NFR-3 for the page on the server: the replay's data must equal the stored rows, with no outbound call. `ResearchEndpointTest` checks only a few fields of `GET /api/research/{id}` and of the `/ai/research/{id}` props (`run.steps.0.phase`, `report_html`). No test compares every step's `input`/`output` with the stored rows, or asserts no agent prompt or embedding call (`assertNeverPrompted`, `Embeddings::assertNothingGenerated`) when a run is loaded for the page.
  - Approach: add a test that stores a finished run with steps, using unicode and nested output, then reads it from `GET /api/research/{id}` and from the `/ai/research/{id}` Inertia props. The test compares each step's `input`/`output` and the `report` with the repository record (`json_encode` equality), and asserts that the agents, embeddings and HTTP were never called.
  - [x] 8.1 **Verify**: the new `NFR-3`/`AC-6` test in `tests/Feature/ResearchEndpointTest.php` (or `ResearchReplayTest.php`) passes with `ddev artisan test --compact --filter=NFR-3`.

- [x] 9. Browser check of the lab page run, timeline, report and Replay (checks)
  Covers: AC-10, AC-6
  - Task 6.1 lists "the page run in the browser", and manual-test.md step 3.2–3.3 asks for the same: start a run on `/ai/research`, watch the timeline fill, then click Replay on a finished run. Task 6.2 records "no browser available, checked over HTTP", so the page has never been checked in a browser. That covers the timeline rendering, the `v-html` report with its source anchors, the run-log links and the client-side 400 ms replay in `resources/js/ai/Pages/Lab/Research.vue`. AC-10's "The lab page SHALL show the timeline and the report" is checked only through the Inertia props.
  - Approach: run manual-test.md step 3.2–3.3 in a browser with a queue worker. Record the outcome in this file: the timeline fills while polling, the run ends `done`, the report shows with `[n]` links to its sources, and Replay plays the stored steps with no new request beyond loading the run.
  - [x] 9.1 **Verify**: the browser outcome of manual-test.md step 3 is recorded here. The network panel shows only the `GET /api/research/{id}` polls during the run and no request during Replay. The browser console has no errors.
    - Recorded 2026-11-14 (review fix pass): headless Google Chrome driven by Playwright against https://ai-lab.ddev.site, qwen3.5:9b, a queue worker (`ddev artisan queue:work`) running. Playwright browser tools were not in this agent's tool set, so the script was run from the shell (kept outside the repo).
    - Step 8: `/ai/research` shows Research in the navigation, the placeholder `e.g. how do queued jobs retry and fail`, 3 example buttons, the Start button and the recent runs list; `/ai/research/not-a-uuid` answers 404; no console error or warning on load.
    - Step 9: the hybrid-search example started with `POST /api/research` (one), the URL became `/ai/research/<uuid>`, and the timeline filled 0, 2, 4, 6, 7, 8 steps while the page polled `GET /api/research/<id>` 18 times over about 27 s (about every 1.5 s) until `Done`. The only API requests were that POST and those GETs. The report had 16 `#research-source-n` anchors, no image, external link, script or iframe; clicking `[1]` set `#research-source-1` and that source entry exists; a `Run log` link answered 200 (5 on the run). No console error or warning during the run.
    - Step 10: Replay was disabled with the note "Replay is available when the run ends" while the run was `Running` (fix 2) and enabled at `Done`. Replay re-showed the 8 steps one at a time (counts 0,0,0,1,1,1,2,...,8 sampled every 150 ms, about 400 ms per step), then the report; no request during the replay; the step text and the report text equal the text before the replay.

## Phase: Gaps (converge round 2)

- [x] 10. Check the research lab page for slice ids and paths (checks)
  Covers: AC-11
  - Task 5.2 lists `NoReferencesInUiTest` as a check, and the spec's boundaries say "keep website text free of slice ids and paths" (BR-27). `labUiPaths()` in `tests/Feature/NoReferencesInUiTest.php` builds its list from the guide files plus a fixed list of lab pages. That list has `/ai/moderation`, `/ai/admin-assistant` and the rest, but not `/ai/research`. So the new guide `/ai/guides/research` is checked, but the new page `/ai/research` (its props and HTML: example topics, URLs, provider options) is never checked for `SL-\d+`, `.md` or repository paths.
  - Approach: add `/ai/research` to `labUiPaths()` in `tests/Feature/NoReferencesInUiTest.php`. Do not add a stored run's page: its steps show docs paths as data.
  - [x] 10.1 **Verify**: `ddev artisan test --compact tests/Feature/NoReferencesInUiTest.php` passes, with a dataset case for `/ai/research`.

- [x] 11. Research guide Numbers tab from the recorded runs, per C-11 (docs)
  Covers: AC-11
  - C-11 says the guide's numbers tab shows "run counts and average calls per run from `research_runs`". In `resources/guides/research.php` (lines 112–115), the `numbers` tab has only two `'pending' => true` rows ("Model calls per run, at most" and "Runs that ended done, share"), so no number is shown. The guide engine reads numbers only from bench JSON files (`GuideRepository` needs `file` and `path` unless a row is pending). Task 6 did live runs on qwen3.5:9b, but nothing from them was recorded as a file the guide can read.
  - Approach: write the run counts from `research_runs` into a bench result file such as `bench/research/<date>/run.json`, in the same shape as the other bench result files. Include the runs by status, the share that ended `done`, and the average model calls and steps per run, with the model named. Then point the research guide's numbers rows at that file with `file`/`path`, and keep a row `pending` only where no value was measured. Do not touch `bench/parity`.
  - [x] 11.1 **Verify**: the research guide's `numbers` rows have `file` and `path`, and `/ai/guides/research` shows the recorded values. `ddev artisan test --compact tests/Feature/GuideContentTest.php tests/Feature/GuidePageTest.php tests/Feature/NoReferencesInUiTest.php` passes, and `git diff -U0 bench/parity` is empty.

## Phase: Review fixes (2026-11-14)

- [x] 12. Review findings of the ship review, all fixed with tests (backend, frontend, docs)
  Covers: AC-2, AC-6, AC-7, AC-9, AC-10, NFR-2
  - [x] 12.1 Replay with an id that is not a UUID: the repository `find()` returns null, so the command prints "No research run with this id." with exit 2 and `GET /api/research/{id}` and `/ai/research/{id}` answer 404, with no database error.
  - [x] 12.2 `Research.vue`: Replay is disabled while the run is `running` (with a note), and polling resumes after a replay if the run is still running.
  - [x] 12.3 A failed search stores a skipped note with reason `search_failed` (counted as a failed note); the fixed "not covered" report is written only when no note failed; a run with no fact and a failed note or search ends `failed` with `no_usable_notes` (this replaces `search_failed` and `all_notes_failed` as run errors).
  - [x] 12.4 The provider and the model are resolved once in `start()`, stored on the run, and pinned on every agent (`ResearchAgent::usingModel`), so the `ai_runs` rows equal the run header even if the settings change between `start` and `execute`.
  - [x] 12.5 A run time budget `ai.research.max_seconds` (`AI_RESEARCH_MAX_SECONDS`, default 600): a model call starts only when it can end, at its provider timeout, inside the budget (the first call always runs); otherwise the run ends `failed` with `deadline_reached`, keeping its steps and counts. The job timeout is `max_seconds + 60`; `failed()` on the job only marks a still-running run `failed`/`job_failed` through a new repository `fail()` and keeps its counts.
  - [x] 12.6 The citation linker reuses the `ResearchCitations` marker pattern and code split: a grouped `[1, 2]` links each number, and nothing inside fenced code or an inline code span is linked.
  - [x] 12.7 `execute()` refuses a run older than `ResearchRunRecord::STALE_AFTER_MINUTES` and marks it `failed` with `stale`.
  - [x] 12.8 An invalid planner, note or report output sets `validation_failed` on its `ai_runs` row.
  - [x] 12.9 Security: `ResearchReportHtml` renders the report with raw HTML escaped and unsafe links off, replaces an image by its alt text and keeps only `#research-source-n` links.
  - [x] 12.10 A cloud run counts as `ai.research.max_model_calls` (8) hits on the `cloud-ai` and `cloud-ai-global` limits (`LimitCloudAi` weight, increment by the weight, refused when fewer hits remain).
  - [x] 12.11 `GET /api/research/{id}` has `throttle:research-read` (`AI_RESEARCH_READ_PER_MINUTE`, 120).
  - [x] 12.12 A model or search failure stores the generic code (`model_call_failed`, `search_failed`, `run_failed`); the exception is reported and its text is not stored on the run.
  - [x] 12.13 The transitional guide-gating code in `LabOverviewService` is removed; Research is a plain entry in `PAGES`.
  - [x] 12.14 `ArrayResearchNotesStore` moved to `tests/Support` (`Tests\Support`); the application binds only the Redis store.
  - [x] 12.15 `ResearchRunStatus`, `ResearchStepPhase` and `ResearchStepStatus` enums are used in the DTOs, models, repository, runner, service, command and job; stored and JSON values are unchanged.
  - [x] 12.16 `/ai/research` is in `labUiPaths()` of `NoReferencesInUiTest`.
  - [x] 12.17 `ddev artisan research:stats` writes `bench/results/research/<date>/stats.json`; the guide's numbers tab reads it.
  - [x] 12.18 **Verify**: `ResearchRunnerTest`, `ResearchEndpointTest`, `ResearchReplayTest`, `ResearchStatsTest` and `tests/Unit/ResearchReportHtmlTest.php` pass.

## Orchestrator record (2026-11-14)

- Review fix pass: 18 findings fixed and both gaps closed. Suite 2774 passed and 3 skipped, run twice. Pint, PHPStan, typecheck and build clean. The fingerprint is unchanged, and parity differs only in `captured=`.
- One full run had 4 `DocsSearchTest` failures that did not reproduce: the file passed 3 times in a row alone, and the next full run passed. This is recorded as a possible flake to watch.
