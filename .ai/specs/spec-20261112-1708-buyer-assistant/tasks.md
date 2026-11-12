# Buyer assistant — Tasks

**Spec**: spec-20261112-1708-buyer-assistant | **Size**: L | **Tasks**: 7

## Phase 1: Agent and tool

- [x] 1. Tool, agent and feature key (backend)
  Covers: AC-1, AC-2, AC-3, AC-6, AC-7, NFR-1, NFR-2
  - [x] 1.1 `SearchListings` (schema, `ListingFilterRules`, the existing search, compact result, the invalid and final errors, a per-turn invalid counter); `BuyerAssistantAgent` (`HasTools`, `#[MaxSteps(4)]`, prompt v1, the `assistant` feature, history); config and `.env.example`.
  - [x] 1.2 **Verify**: `tests/Feature/BuyerAssistantToolTest.php` (valid → search; invalid → errors, no query; second invalid → final and `gave_up`); `tests/Feature/BuyerAssistantAgentTest.php` (the step cap, the provider from config and settings, read-only tools only).

- [x] 2. Step log (database, backend)
  Covers: AC-4
  - [x] 2.1 Migration `ai_tool_steps` plus `ai_runs.tool_steps` / `tool_errors`; a listener on the tool events; the result summary only.
  - [x] 2.2 **Verify**: `tests/Feature/AssistantStepLogTest.php`.

## Phase 2: Endpoint and lab

- [x] 3. Streaming endpoint (backend)
  Covers: AC-1, AC-5, MNC-2
  - [x] 3.1 `POST /api/assistant/ask` (question, history, provider; the input guard; SSE with `tool_call` / `tool_result` / `tool_error` plus the SL-45 events; Stop; throttle; the cloud limiter).
  - [x] 3.2 **Verify**: `tests/Feature/AssistantEndpointTest.php` (the event order on a faked tool loop; refusal; Stop); the SL-45 chat tests unchanged.

- [x] 4. Lab page (frontend)
  Covers: AC-5
  - [x] 4.1 `/ai/assistant` (chat, provider selector, Stop, step timeline, How it works, chips, empty state); navigation, overview and home capability.
  - [x] 4.2 **Verify**: `tests/Feature/AssistantPageTest.php`; `NoReferencesInUiTest`; typecheck; build; browser check.

## Phase 3: Eval, guide, run

- [x] 5. Question set and eval (backend)
  Covers: AC-8, NFR-3
  - [x] 5.1 `questions.v1.jsonl` (30 questions, with the mix as specified); `assistant:eval` (metrics, the hard check on the step maximum, hallucinated ids).
  - [x] 5.2 **Verify**: `tests/Feature/AssistantEvalTest.php` (the maths on scripted fake turns).

- [x] 6. Guide and docs (docs)
  Covers: AC-9
  - [x] 6.1 `assistant.php` guide; §16; README; settings row.
  - [x] 6.2 **Verify**: `GuideContentTest`, `LearningGuideTest`, `AiSettingsPageTest` (the new row).

- [x] 7. Run and checks (checks)
  Covers: AC-8, MNC-1, MNC-3, MNC-4, MNC-5
  - [x] 7.1 Orchestrator: `assistant:eval` live; the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity; `bench/run.sh ping --quick` after the commit; push.
  - [x] 7.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | tool called, validated, search run | `tests/Feature/BuyerAssistantToolTest.php` | test | 1 |
| AC-2 | at most 4 steps | `tests/Feature/BuyerAssistantAgentTest.php`; `manual-test.md#step-7` | test | 1 |
| AC-3 | one repair, then gave_up | `tests/Feature/BuyerAssistantToolTest.php`; `manual-test.md#step-7` | test | 1 |
| AC-4 | ai_runs and ai_tool_steps rows | `tests/Feature/AssistantStepLogTest.php`; `manual-test.md#step-5` | test | 2 |
| AC-5 | streamed answer and timeline | `tests/Feature/AssistantEndpointTest.php`; `manual-test.md#step-3` | test | 3, 4 |
| AC-6 | read-only tools only | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| AC-7 | provider by config and settings | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| AC-8 | eval metrics and BENCHMARKS | `tests/Feature/AssistantEvalTest.php`; `manual-test.md#step-10` | test | 5, 7 |
| AC-9 | guide, no references | `tests/Feature/GuideContentTest.php`; `manual-test.md#step-11` | test | 6 |
| MNC-1 | listings and NL search unchanged | verify existing NlSearch and listings tests pass unchanged | test | 1 |
| MNC-2 | SL-45 chat unchanged | verify existing AiLabChat and DocsAnswer tests pass unchanged | test | 3 |
| MNC-3 | settings rows unchanged | verify existing AiSettings tests pass unchanged | test | 6 |
| MNC-4 | offline suite | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| MNC-5 | fingerprint and parity | `manual-test.md#step-13` | manual | 7 |
| NFR-1 | arguments only through rules | `tests/Feature/BuyerAssistantToolTest.php` | test | 1 |
| NFR-2 | bounded cost | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| NFR-3 | hallucinated ids reported | `tests/Feature/AssistantEvalTest.php`; `manual-test.md#step-10` | test | 5, 9 |

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

- [x] 8. Listing links in the step timeline (frontend)
  Covers: AC-5
  - `resources/js/ai/Pages/Lab/Assistant.vue` `parseResult()` keeps only `total` and `shown`, and the timeline renders `outcomeLabel()` ("3 of 12 listings") with no `/listings/{id}` links. AC-5 and manual-test step 1 expect "a total and listing links". No test asserts the ids reach the page.
  - Approach: parse `listings[].id` from the `tool_result` payload into the step, render up to 10 ids as links to `/listings/{id}` under the result summary, and show the arguments as pretty JSON as the scope says.
  - [x] 8.1 **Verify**: `ddev npm run typecheck && ddev npm run build`; `AssistantEndpointTest` asserts that `tool_result` carries the listing ids; a browser check on `/ai/assistant` shows the step with clickable `/listings/{id}` links.

- [x] 9. Hallucinated-id check must see the ids the prompt asks for (backend)
  Covers: NFR-3, AC-8
  - `resources/prompts/buyer-assistant.v1.md` tells the model to cite listings as "listing 123". `AssistantEvalScorer::ID_PATTERN` only matches `/listings/N`, `#N` and `id N`, so a made-up "listing 999" is never counted. As a result, "0 of 30 hallucinated" in `bench/results/assistant/2026-11-12/run.json` and `BENCHMARKS.md` is not evidence. Clarity gate 6 says the prompt must require `/listings/{id}` links and that answers mentioning listings without links must be reported. The scope also asks the tool result to carry a URL path, which `SearchListings::compact()` does not include.
  - Approach: add a URL path to each compact listing. Add a new prompt version (`buyer-assistant.v2`, set as the config default) that requires every listing to be a `/listings/{id}` link. In the scorer, count ids from links, and also report answers that name a "listing N" without a link (`unlinked_mentions`). Re-run `assistant:eval` and update the `BENCHMARKS.md` section.
  - [x] 9.1 **Verify**: an `AssistantEvalTest` case where the answer "listing 999" (not returned) is counted, either as hallucinated or as unlinked; `BuyerAssistantToolTest` expects a `url` on each listing; the new run.json has the extra metric.

- [x] 10. Prove that a saved `/ai/settings` choice drives the assistant (backend)
  Covers: AC-7
  - `tests/Feature/BuyerAssistantAgentTest.php` covers only `config(['ai.features.assistant.*'])`. Task 1.2 Verify ("the provider from config and settings") and AC-7 ("switched on `/ai/settings`") have no test that saves the `assistant` row and checks that `BuyerAssistantAgent` (or a recorded `ai_runs` row from `/api/assistant/ask`) uses that provider and model. `AiSettingsPageTest` only checks that `rows.5.feature` is `assistant`.
  - Approach: in the local environment with settings on, `PUT /ai/settings/assistant` with a provider and model, then assert `providerName()` and `modelName()` of a new `BuyerAssistantAgent`, plus the `ai_runs` provider and model after a faked ask.
  - [x] 10.1 **Verify**: `ddev artisan test --compact tests/Feature/BuyerAssistantAgentTest.php` contains an AC-7 settings test that passes.

- [x] 11. Unsupported-filter questions and city aliases in the eval (backend)
  Covers: AC-8
  - Clarity gate 1 requires 4 eval questions that ask for filters the tool lacks ("estate", "near", another currency), each labelled with `unsupported` words the answer must mention. `bench/assistant/questions.v1.jsonl` has none. `AssistantEvalSet` does not parse such a label, and `AssistantEvalScorer` has no metric for it. Gate 5 also requires city aliases (`kiev` to `kyiv`) in filter matching, but `AssistantEvalScorer::normalize()` only trims and lowercases.
  - Approach: within the 18 search questions, relabel or replace 4 so they carry `unsupported: [...]`. Validate the label in `AssistantEvalSet`. Report an `unsupported_mentioned` rate per model. Add the alias map to `matches()`. Re-run the eval as part of task 7.
  - [x] 11.1 **Verify**: `AssistantEvalTest` checks the 4 labelled questions, the `unsupported_mentioned` maths and `kiev` matching `Kyiv`; the existing mix test (18/6/6) still passes.

- [x] 12. Follow-up history carries the last search arguments (frontend, backend)
  Covers: AC-1
  - Clarity gate 7 says each earlier answer sent as history must carry `(searched: {json})` with the arguments of its last valid call, so that a follow-up like "and in Kyiv?" keeps the filters. `history()` in `Assistant.vue` sends only `turn.text.trim()`, and the eval's scripted histories in `questions.v1.jsonl` (as-19 to as-24) have no `searched` line either.
  - Approach: store the arguments of the last `tool_result` step for each turn and append `\n(searched: {json})` to the assistant content in `history()`, within `MAX_TURN_LENGTH`. Mirror this in the follow-up lines of the eval set.
  - [x] 12.1 **Verify**: typecheck and build pass; an `AssistantEndpointTest` case posts a history whose answer carries `(searched: …)` and asserts that the agent receives it as message content; manual-test step 1 shows the follow-up keeping make and fuel.

- [x] 13. Spec defaults for limit and throttle (backend)
  Covers: Scope (Tool), clarity gate 8
  - The scope sets `limit` to 1–10 with a default of 5. `SearchListings::handle()` uses `$arguments['limit'] ?? self::MAX_LIMIT`, so the default is 10, and `BuyerAssistantToolTest` pins 10 for an empty call. Gate 8 sets the `assistant` throttle at 20 per minute per IP, but `config/ai.php` `ai.assistant.per_minute` defaults to 10 (and `.env.example` says 10).
  - Approach: add a `DEFAULT_LIMIT = 5` constant used when `limit` is null, and set the `per_minute` default to 20 in config and `.env.example`. Update the tool test: empty call returns 5, `limit` 10 returns 10.
  - [x] 13.1 **Verify**: `ddev artisan test --compact tests/Feature/BuyerAssistantToolTest.php tests/Feature/AssistantEndpointTest.php` passes.

## Phase: Gaps (converge round 2)

- [x] 14. Tool error codes follow the spec contract (backend)
  Covers: AC-3, AC-4
  - The Scope (Tool, step 3) and clarity gates 2, 3 and 8 fix the codes `invalid_arguments` and `step_limit`. `SearchListings::handle()` returns `invalid` (line 90) and `call_limit` (line 74). These codes then flow into `ai_tool_steps.error`, the `tool_error` SSE event and `AssistantEvalRunner` (line 65 maps `'invalid'`).
  - Approach: rename the codes to `invalid_arguments` and `step_limit` in `SearchListings`. Update the `AssistantEvalRunner` mapping and the tool, step-log, endpoint and eval tests. Keep the `outcome` column (`ok`, `error`, `failed`) with the code in `error`.
  - [x] 14.1 **Verify**: `BuyerAssistantToolTest` expects `invalid_arguments` and `step_limit`; `AssistantStepLogTest` and `AssistantEndpointTest` expect `error` = `invalid_arguments`; `AssistantEvalTest` passes.

- [x] 15. Step cap and give-up proven through the agent loop (backend)
  Covers: AC-2, AC-3, NFR-2
  - AC-2 is checked only by constants (`BuyerAssistantAgentTest` line 16 reads `MaxSteps` and `MAX_CALLS`) and by calling `SearchListings::handle()` directly (`BuyerAssistantToolTest` line 102). AC-3's "recorded as `gave_up`" has no test on a logged step: `AssistantStepLogTest` feeds only an `invalid` result, and `AssistantEndpointTest` line 112 scripts a single invalid call. Nothing proves that the faked SDK loop keeps one tool instance across steps, so that the 4-search cap and the single repair hold in a real turn.
  - Approach: add two `AssistantEndpointTest` cases with `assistantScript`.
    - Five searches then an answer: assert 4 `tool_result` events, then a final `tool_error` for the step limit, exactly 4 repository searches (spy or mock) and `ai_runs.tool_steps` 5 / `tool_errors` 1.
    - Two invalid calls then a valid one: assert `tool_error` invalid, then `gave_up` with `final: true`, then `gave_up` again, no repository search, and `ai_tool_steps.error` = [invalid, `gave_up`, `gave_up`].
  - [x] 15.1 **Verify**: `ddev artisan test --compact tests/Feature/AssistantEndpointTest.php` contains both cases and passes.

- [x] 16. Prompt names the unsupported wishes the eval scores (backend)
  Covers: AC-8
  - Clarity gate 1 requires the prompt to tell the model to say plainly that it cannot filter on distance ("near"), and that currencies other than EUR are unsupported. `resources/prompts/buyer-assistant.v2.md` lists only "body type, colour or mileage" and says "Prices are euros". Yet `as-13` ("near Munich") and `as-17` ("12000 dollars") in `bench/assistant/questions.v1.jsonl` are labelled `unsupported`. The `unsupported_mentioned` metric therefore scores an instruction the model never gets.
  - Approach: add `buyer-assistant.v3.md`, which is v2 plus two lines:
    - the tool filters by exact city only, with no distance or radius;
    - only euros are supported, so for another currency the model says so and does not convert.

    Make v3 the config default and update the `.env.example` comment. The eval is re-run in task 7.
  - [x] 16.1 **Verify**: `BuyerAssistantAgentTest` asserts `promptVersion()` is `buyer-assistant@v3` and that `instructions()` mention distance and currency; after task 7, `run.json` shows `buyer-assistant@v3` and `unsupported_mentioned` per model.

- [x] 17. Guide explains read-only tools, shows measured numbers, docs current (docs)
  Covers: AC-9
  - The Scope requires `resources/guides/assistant.php` to explain why only read-only tools exist (BR-97) and how to add a write tool with confirmation. The guide does neither: its recipes cover the model, the prompt and a new filter.
  - Its Numbers tab holds a single `pending => true` entry, although `bench/results/assistant/2026-11-12/run.json` exists. The sibling guides `nl-search.php` and `photo-duplicates.php` read their numbers from `run.json` paths.
  - Two docs are stale:
    - `docs/learning-guide.md` line 1293 says ids are found by `#12`, `/listings/12` and `id 12`, but the scorer now uses `/listings/N` links and "listing N" and reports `unlinked_mentions`;
    - `.env.example` shows `AI_PROMPT_BUYER_ASSISTANT=buyer-assistant.v1`, while the default is v2.
  - Approach:
    - Add a support item on read-only tools (the `ReadOnlyTool` marker and the test that fails on a writing tool).
    - Add a recipe "Add a tool that writes" (owner approval, a confirmation step before the write, the `ReadOnlyTool` snippet).
    - Replace the pending number with `file`/`path` entries from the assistant `run.json` (hallucinated rate, tool-call recall, latency p50).
    - Fix the §25 sentence and the `.env.example` default.
  - [x] 17.1 **Verify**: the AC-9 test in `GuideContentTest` also asserts that the rendered guide mentions read-only tools and confirmation and has no pending numbers. The number-resolution test passes for `assistant`, and `LearningGuideTest` passes.

## Fix pass and run record (2026-11-12)

The session restarted mid-build; the build resumed from its journal.

Review fixes:
- **Major: a blank argument crashed the turn.** `{"fuel":""}` passed the rules and threw in `Fuel::from('')`, failing the whole turn. The tool now trims strings and drops blanks before validating, the same as the HTTP middleware (test).
- **Gap 14.** The error codes follow the spec: `invalid_arguments` and `step_limit` (tool, eval runner, tests).
- **Gap 16 and the prompt finding.** New prompt `buyer-assistant@v3` (the default): it names the tool `SearchListings`, says plainly that distance ("near"), body type, colour, mileage and sorting by price are not supported, and does not convert other currencies.
- **Eval scorer.**
  - "Unsupported said" matches whole words only ("red" no longer matches "offered"), and the as-17 label no longer contains "euro".
  - Made-up ids are only `/listings/{id}` links; "#N" and "id N" no longer count.
  - Tests are updated to that rule.
- **Gap 17.**
  - The guide has a recipe explaining why only read-only tools exist (BR-97) and how a tool that changes data would need confirmation.
  - The guide Numbers read `run.json`.
  - The learning guide describes the new id rule.
  - `.env.example` names v3.
- **Fuel list.** It comes from `Fuel::cases()` instead of being hard-coded.
- **Live eval re-run on v3** (`bench/results/assistant/2026-11-12/run.json`, 3 min 55 s). `run.json` and `BENCHMARKS.md` now include "unsupported said".

| Model | Precision | Recall | Valid first try | Filter accuracy | Made-up ids | Unsupported said | p50 |
|-------|-----------|--------|-----------------|-----------------|-------------|------------------|-----|
| qwen3.5:9b | 1.000 | 0.958 | 1.000 | 0.958 | 1/30 | 3/4 | 3782 ms |
| gemma4:12b | 1.000 | 0.958 | 1.000 | 0.917 | 0/30 | 1/4 | 3683 ms |

The recall miss is the currency question: both models asked for euros as v3 allows. Recorded as a label-versus-prompt conflict.

Not fixed (minor, recorded):
- **Gap 15:** the step cap and the single repair are proven on the tool and with a scripted stream, not through a live multi-step loop. Live, no question needed more than one call.
- **`ReadOnlyTool`** is a marker, not an enforcement: a writing tool could still implement it. The guide recipe says so.
- **Duplicated guard handling** between `BuyerAssistantService` and `DocsAnswerService`.

Checks:
- Full suite: 2188 passed. Pint and PHPStan clean, typecheck and build pass.
- Fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Parity on frankenphp identical.
- `/ai/assistant`, `/ai/guides/assistant` and `/ai/settings` answer 200.
