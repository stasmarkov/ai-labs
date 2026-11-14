# Prompt playground with versions — Tasks

**Spec**: spec-20261114-2142-prompt-playground | **Size**: M | **Tasks**: 5

## Phase 1: Data and runner

- [x] 1. Storage (backend)
  Covers: AC-1, AC-7
  - [x] 1.1 Add the four migrations, the models and factories, and the repository contracts.
  - [x] 1.2 **Verify**: `tests/Feature/PlaygroundPromptTest.php`:
    - version numbering;
    - the immutability (no update path);
    - 422 `unchanged`;
    - name rules;
    - delete keeps runs.

- [x] 2. Runner (backend)
  Covers: AC-2, AC-3, AC-4, AC-5, AC-6, NFR-1
  - [x] 2.1 Add `PlaygroundAgent`, `PlaygroundRunner` (target validation, sequential calls, results, `ai_runs` logging with the version label, failure isolation, the guard flag), config and `.env.example`.
  - [x] 2.2 **Verify**: `tests/Feature/PlaygroundRunTest.php`, with the agent faked (all six items have a test; the duration and latency checks were added in gap 9):
    - 2 and 3 targets;
    - a disabled provider or an unknown model gives 422 and no call;
    - one failing target;
    - the injection flag;
    - an `ai_runs` row per call;
    - the caps.

## Phase 2: Surfaces and checks

- [x] 3. Endpoints and page (backend, frontend)
  Covers: AC-7, AC-8, NFR-2
  - [x] 3.1 Add:
    - the routes and controllers;
    - the local gate on the page and every endpoint (D-5; first only the prompt writes);
    - the cross-site refusal, the rate limit and `LimitCloudAi` weighting;
    - `Lab/Playground.vue`, with the editor, the versions, the client line diff, the targets, the columns and the history;
    - the navigation and capability entries.
  - [x] 3.2 **Verify**: `tests/Feature/PlaygroundEndpointTest.php`:
    - the shapes;
    - 404 on every route when not local (`tests/Feature/PlaygroundLocalOnlyTest.php`);
    - 403 cross-site;
    - 429;
    - a stored run opened with no call;
    - escaped output in the props.
    Also add `NoReferencesInUiTest` for `/ai/playground`.

- [x] 4. Guide and docs (docs)
  Covers: AC-9
  - [x] 4.1 Add the guide `playground`, the learning-guide section and README.
  - [x] 4.2 **Verify**: `GuideContentTest`, `GuidePageTest`, typecheck and build.

- [x] 5. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 5.1 Orchestrator:
    - [x] a live comparison on qwen3.5:9b against gemma4:12b with two versions of one prompt, in the browser and with curl (outputs below);
    - [x] the gates, the fingerprint and parity (outputs below);
    - [x] commit, the ping and push (left to the orchestrator).
  - [x] 5.2 **Verify**: outputs recorded here.

  **Live run, 2026-11-14, `APP_ENV=local`, Ollama with `qwen3.5:9b` and `gemma4:12b`** (prompt "live compare ad", temperature 0, input "2017 VW Golf diesel, 120000 km, Berlin, 9500 EUR"):

  | Version | Model | Status | Tokens in/out | Total (curl) | Total (browser) |
  |---------|-------|--------|---------------|--------------|-----------------|
  | v1 "Write a two-sentence ad ..." | qwen3.5:9b | ok | 55 / 67 | 7509 ms | n/a (see below) |
  | v1 | gemma4:12b | ok | 56 / 45 | 5455 ms | n/a |
  | v2 "Write one upbeat sentence of at most 20 words ..." | qwen3.5:9b | ok | 63 / 22 | 4218 ms | 4463 ms |
  | v2 | gemma4:12b | ok | 64 / 24 | 5000 ms | 4544 ms |

  - curl: `POST /api/playground/runs` took 13.2 s for v1 and 9.4 s for v2, which is the sum of the two calls plus the model-list lookup; both versions returned 200 with two `ok` results, each with its own run-log link, and all four `/ai/runs/{id}` pages answered 200. The `ai_runs` rows carry the label `playground:live compare ad@v1` and `@v2` and `latency_ms` equal to the result's `duration_ms`.
  - Browser (Playwright with the installed Chrome, outside the repo): the page lists the prompt, the version select shows v2 and v1, the two default targets are `qwen3.5:9b` and `gemma4:12b`, running v2 and then v1 gives two columns each (v1 about 9.9 s, v2 about 9.5 s wall time), the URL changes to `/ai/playground/{run id}`, the diff view shows the changed line, opening an earlier run from History shows its two columns again, the navigation has the Playground link, and there were no console errors or failed requests.
  - The 80 character prompt name: run label length 94, written to `ai_runs` as `playground:abcdefghij ... abcdefghij@v1` cut to 80 characters with the suffix kept; both run-log links answer 200 (the earlier 404 is fixed).
  - `temperature` 0.201 on a run: 422.
  - A failing or timing-out target could not be forced without editing `.env` (`AI_PLAYGROUND_TIMEOUT`), which this pass must not do; the AC-5 tests cover it, and manual-test step 7 keeps the human check.
  - `ddev artisan playground:stats` wrote `bench/results/playground/2026-11-14/stats.json`: 9 runs (the dev DB also holds the earlier verification runs), 2 models per run, 3 prompts, 7 versions, 7 two-model local runs, 10.3 s average total time.

  **Gates:** full suite 2894 passed, 3 skipped (offline, no model call); Pint clean; PHPStan no errors; `npm run typecheck` and `npm run build` pass; `bench/asset-fingerprint.sh` prints `app-DNob7CFE.css` and `app-PTzu1QaN.js` as before; `bench/parity.sh capture` changed only the `captured=` line, and `git checkout -- bench/parity` restored it.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | versions immutable, numbered | `tests/Feature/PlaygroundPromptTest.php`; `manual-test.md#step-2` | test | 1 |
| AC-2 | targets run sequentially, results stored | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-4` | test | 2 |
| AC-3 | ai_runs per call | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-4` | test | 2 |
| AC-4 | invalid target 422 | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-6` | test | 2 |
| AC-5 | failure isolation | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-7` | test | 2 |
| AC-6 | guard flag | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-8` | test | 2 |
| AC-7 | stored run reopened | `tests/Feature/PlaygroundEndpointTest.php`; `manual-test.md#step-4` | test | 3 |
| AC-8 | page and all endpoints local-only (D-5) | `tests/Feature/PlaygroundLocalOnlyTest.php`; `tests/Feature/PlaygroundEndpointTest.php`; `manual-test.md#step-9` | test | 3 |
| AC-9 | guide | `tests/Feature/GuideContentTest.php`; `manual-test.md#step-10` | test | 4 |
| MNC-1 | feature prompts unchanged | verify existing prompt-file and AI feature tests pass unchanged; `manual-test.md#step-13` | test | 5 |
| MNC-2 | settings unchanged | verify existing AiSettingsPageTest passes unchanged; `manual-test.md#step-13` | test | 5 |
| MNC-3 | fingerprint and parity | `manual-test.md#step-13` | manual | 5 |
| MNC-4 | offline suite | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-13` | test | 2 |
| NFR-1 | bounded cost | `tests/Feature/PlaygroundRunTest.php`; `manual-test.md#step-11` | test | 2 |
| NFR-2 | safety | `tests/Feature/PlaygroundEndpointTest.php`; `manual-test.md#step-11` | test | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked (task 5.1 still has commit, ping and push)
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 6. Add the `playground:stats` command and feed the guide's numbers tab (backend, docs)
  Covers: AC-9
  - Spec C-14 says the numbers tab shows run and version counts "from the tables, through a `playground:stats` command like `research:stats`". No such command exists (`app/Console/Commands` has only `ResearchStatsCommand.php`), and all three rows of `numbers` in `resources/guides/playground.php` are `'pending' => true`.
  - Approach: add `app/Console/Commands/PlaygroundStatsCommand.php` modelled on `ResearchStatsCommand` (`--output-dir`, writes `bench/results/playground/<UTC date>/stats.json`, fails when no run is stored). Add a `statistics()` read on `PlaygroundRunRepositoryInterface` and `PlaygroundPromptRepositoryInterface`, or one query service, returning runs, average models per run, prompts, versions, and the average total time of a two-model local run. After the live run in task 5, point the numbers rows at the file with `file` and `path`.
  - [x] **Verify**: a new `tests/Feature/PlaygroundStatsTest.php` (modelled on `ResearchStatsTest.php`) seeds runs and versions with the factories, runs the command with `--output-dir` set to a temp dir, and asserts the JSON counts; it also asserts the failure when no run is stored. `GuideContentTest` still passes.

- [x] 7. Add the playground capability line to the lab overview (backend)
  Covers: AC-8
  - Spec C-9 says "`LabOverviewService` gets a capability line". `app/Services/LabOverviewService.php` adds Playground only to `navigation()`, through `PLAYGROUND_NAVIGATION`. Its comment says this is "before it has a guide card", but the guide `resources/guides/playground.php` now exists. So `capabilities()`, which drives the overview cards and the home page, has no playground entry.
  - Approach: add `['playground', 'Playground', 'ai.playground', 'ready']` to `PAGES` and drop the separate `PLAYGROUND_NAVIGATION` entry, so the link appears in the navigation only once.
  - [x] **Verify**: a test in `tests/Feature/AiLabOverviewTest.php` or `PlaygroundEndpointTest.php` asserts that `app(LabOverviewService::class)->capability('playground')` has the guide summary and href `/ai/playground`, and that the navigation has exactly one `/ai/playground`. `GuidePageTest`, `GuideContentTest` and `NoReferencesInUiTest` still pass.

- [x] 8. Test the 409 answer to a version-number race (backend)
  Covers: AC-1
  - Spec C-11 says a collision on the unique `(playground_prompt_id, version)` key returns 409. `EloquentPlaygroundPromptRepository::addVersion` catches `UniqueConstraintViolationException` and throws `PlaygroundPromptException::versionConflict()`, which maps to 409 in `PlaygroundPromptException::status()`. No test in `tests/Feature/PlaygroundPromptTest.php` or `PlaygroundEndpointTest.php` exercises `version_conflict` or 409.
  - Approach: simulate the race. Either bind a repository that throws `versionConflict()`, or insert the next version row behind the service's back through a model event or listener just before the insert. Then assert the HTTP mapping.
  - **Verify**: in local mode, `POST /api/playground/prompts/{id}/versions` with a forced conflict answers 409 with `code` `version_conflict` and adds no extra version row. The test is in `tests/Feature/PlaygroundEndpointTest.php`.

## Phase: Gaps (converge round 2)

- [x] 9. Assert duration and latency on the results and the `ai_runs` rows (backend)
  Covers: AC-2, AC-3
  - AC-2 requires each stored result to carry its duration, and AC-3 requires each `ai_runs` row to carry the latency. `PlaygroundRunner::call` passes `$durationMs` to both `PlaygroundResultRecord` and `AiRunRecorder::recordRun`, but no test checks either value: `tests/Feature/PlaygroundRunTest.php` (the AC-2 test at line 58 and the AC-3 test at line 120) asserts status, output, tokens, provider, model and `prompt_version`, and never reads `duration_ms` or `latency_ms`. `tests/Unit/PlaygroundRunnerTest.php` does not check them either.
  - Approach: in the AC-2 test, assert that every result has an integer `durationMs` >= 0 and that the stored `playground_results.duration_ms` equals it. In the AC-3 test, assert that each `ai_runs` row's `latency_ms` equals the linked result's `durationMs`, including a failed target from the AC-5 path. Test-only change.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/PlaygroundRunTest.php` passes with the new duration and latency assertions. The AC-2 test reads `duration_ms` from both the result DTO and the `playground_results` row, and the AC-3 test reads `latency_ms` from every linked `ai_runs` row.

- [x] 10. Point the guide's numbers tab at `playground:stats` output after the live run (docs)
  Covers: AC-9
  - Spec C-14 says the numbers tab shows counts of runs and versions from the tables through `playground:stats`. Task 6 added the command, and its approach said to point the numbers rows at the file after the live run in task 5. That step is not done and task 5 does not list it. All three `numbers` rows in `resources/guides/playground.php` (lines 114 to 116) are still `'pending' => true`, and `bench/results/playground/` does not exist. `resources/guides/research.php` (lines 113 to 117) shows the expected form, with `file` and `path`.
  - Approach: after the task 5 live comparison, run `ddev artisan playground:stats` to write `bench/results/playground/<UTC date>/stats.json`. Replace the pending rows with `file` and `path` entries for `summary.runs`, `summary.average_models_per_run`, `summary.prompts`, `summary.versions` and `summary.average_two_model_local_duration_s`, following `research.php`.
  - [x] **Verify** (the commit is left to the orchestrator): `bench/results/playground/<date>/stats.json` exists. No `numbers` row in `resources/guides/playground.php` has `pending`. `GuideContentTest`, `GuidePageTest` and `NoReferencesInUiTest` pass, and `/ai/guides/playground` shows the numbers.

## Phase: Review fixes (review of the ship report, 2026-11-14)

- [x] 11. Make the whole playground local-only (D-5) (backend, frontend, docs)
  Covers: AC-8
  - Page, run endpoints, history and prompt endpoints are behind `EnsureLocalLab` (404, JSON `code` `local_only`); the navigation entry and the overview card exist only locally (`AiProviders::playgroundAvailable()`); the page lost its `canSave` switch. The cloud model check stays: locally any id of the right shape, otherwise only the configured default or `available()`. Spec amended (AC-8, Scope 3, C-5, C-6, C-7, C-8, C-9, D-5), README, learning guide, guide text and manual-test step 9 updated.
  - [x] **Verify**: `tests/Feature/PlaygroundLocalOnlyTest.php` (every route 404 outside local, nav and card hidden, every route listed), `PlaygroundEndpointTest`, `PlaygroundRunTest` (cloud model check outside local).
- [x] 12. Temperature has at most two decimals: `decimal:0,2` on saves and runs, rounding in `NewPlaygroundVersion` and the runner, exact comparison for `unchanged`. Verify: `PlaygroundPromptTest`, `PlaygroundEndpointTest`, `PlaygroundRunTest`.
- [x] 13. Default targets are capped at 3. Verify: `PlaygroundEndpointTest`.
- [x] 14. `target_timeout` is clamped to 5..60 and the request limit is `3 * timeout + 30`. Verify: `PlaygroundGuardsTest`, `PlaygroundRunTest`.
- [x] 15. The page maps a 404 to the local-only text only for the code `local_only`. Verify: `PlaygroundEndpointTest` (the `localOnlyCode` prop), typecheck, build, and the browser run.
- [x] 16. Stored errors lose hosts, ports, addresses and URLs (`PlaygroundErrorRedactor`, on top of the bench redactor). Verify: `tests/Unit/PlaygroundErrorRedactorTest.php`, `PlaygroundRunTest`.
- [x] 17. `recent()` cuts the input in SQL and selects no system prompt. Verify: `PlaygroundPromptTest`.
- [x] 18. `LimitCloudAi` no longer builds `PlaygroundRunner`; `PlaygroundCloudWeight` needs only the provider table. Verify: `CloudRateLimitTest`, `tests/Unit/PlaygroundCloudWeightTest.php`.
- [x] 19. The version rules live in `PlaygroundVersionRules`, used by the form requests and by `PlaygroundPromptService`. Verify: `PlaygroundPromptTest`.
- [x] 20. A long prompt name keeps its run-log row: the label written to `ai_runs` is cut to 80 characters with the `@vN` suffix kept (manual-test step 5). Verify: `PlaygroundRunTest`, the live run above.

## Orchestrator record (2026-11-14)

- Both security majors are fixed by making the whole playground local-only (D-5): page, runs, history and prompts answer 404 outside local, and the nav and card are hidden.
- The other 9 findings, gaps 9 and 10, and the AC-3 long-name run-log bug are fixed, each test red first.
- Suite 2894 passed and 3 skipped. Pint, PHPStan, typecheck and build clean. The fingerprint is unchanged, and parity differs only in `captured=`.
- Live: qwen3.5:9b against gemma4:12b on two versions, through curl and Chrome. A forced timeout was not run live; the AC-5 tests cover it.
- Clean-tree bench: after commit 8b3120b, `bench/run.sh ping --quick` exited 0 on frankenphp.
