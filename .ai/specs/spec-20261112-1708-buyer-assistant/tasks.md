# Buyer assistant — Tasks

**Spec**: spec-20261112-1708-buyer-assistant | **Size**: L | **Tasks**: 7

## Phase 1: Agent and tool

- [ ] 1. Tool, agent and feature key (backend)
  Covers: AC-1, AC-2, AC-3, AC-6, AC-7, NFR-1, NFR-2
  - [ ] 1.1 `SearchListings` (schema, `ListingFilterRules`, the existing search, compact result, the invalid and final errors, a per-turn invalid counter); `BuyerAssistantAgent` (`HasTools`, `#[MaxSteps(4)]`, prompt v1, the `assistant` feature, history); config and `.env.example`.
  - [ ] 1.2 **Verify**: `tests/Feature/BuyerAssistantToolTest.php` (valid → search; invalid → errors, no query; second invalid → final and `gave_up`); `tests/Feature/BuyerAssistantAgentTest.php` (the step cap, the provider from config and settings, read-only tools only).

- [ ] 2. Step log (database, backend)
  Covers: AC-4
  - [ ] 2.1 Migration `ai_tool_steps` plus `ai_runs.tool_steps` / `tool_errors`; a listener on the tool events; the result summary only.
  - [ ] 2.2 **Verify**: `tests/Feature/AssistantStepLogTest.php`.

## Phase 2: Endpoint and lab

- [ ] 3. Streaming endpoint (backend)
  Covers: AC-1, AC-5, MNC-2
  - [ ] 3.1 `POST /api/assistant/ask` (question, history, provider; the input guard; SSE with `tool_call` / `tool_result` / `tool_error` plus the SL-45 events; Stop; throttle; the cloud limiter).
  - [ ] 3.2 **Verify**: `tests/Feature/AssistantEndpointTest.php` (the event order on a faked tool loop; refusal; Stop); the SL-45 chat tests unchanged.

- [ ] 4. Lab page (frontend)
  Covers: AC-5
  - [ ] 4.1 `/ai/assistant` (chat, provider selector, Stop, step timeline, How it works, chips, empty state); navigation, overview and home capability.
  - [ ] 4.2 **Verify**: `tests/Feature/AssistantPageTest.php`; `NoReferencesInUiTest`; typecheck; build; browser check.

## Phase 3: Eval, guide, run

- [ ] 5. Question set and eval (backend)
  Covers: AC-8, NFR-3
  - [ ] 5.1 `questions.v1.jsonl` (30 questions, with the mix as specified); `assistant:eval` (metrics, the hard check on the step maximum, hallucinated ids).
  - [ ] 5.2 **Verify**: `tests/Feature/AssistantEvalTest.php` (the maths on scripted fake turns).

- [ ] 6. Guide and docs (docs)
  Covers: AC-9
  - [ ] 6.1 `assistant.php` guide; §16; README; settings row.
  - [ ] 6.2 **Verify**: `GuideContentTest`, `LearningGuideTest`, `AiSettingsPageTest` (the new row).

- [ ] 7. Run and checks (checks)
  Covers: AC-8, MNC-1, MNC-3, MNC-4, MNC-5
  - [ ] 7.1 Orchestrator: `assistant:eval` live; the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity; `bench/run.sh ping --quick` after the commit; push.
  - [ ] 7.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | tool called, validated, search run | `tests/Feature/BuyerAssistantToolTest.php` | test | 1 |
| AC-2 | at most 4 steps | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| AC-3 | one repair, then gave_up | `tests/Feature/BuyerAssistantToolTest.php` | test | 1 |
| AC-4 | ai_runs and ai_tool_steps rows | `tests/Feature/AssistantStepLogTest.php` | test | 2 |
| AC-5 | streamed answer and timeline | `tests/Feature/AssistantEndpointTest.php` | test | 3, 4 |
| AC-6 | read-only tools only | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| AC-7 | provider by config and settings | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| AC-8 | eval metrics and BENCHMARKS | `tests/Feature/AssistantEvalTest.php` | test | 5, 7 |
| AC-9 | guide, no references | `tests/Feature/GuideContentTest.php` | test | 6 |
| MNC-1 | listings and NL search unchanged | verify existing NlSearch and listings tests pass unchanged | test | 1 |
| MNC-2 | SL-45 chat unchanged | verify existing AiLabChat and DocsAnswer tests pass unchanged | test | 3 |
| MNC-3 | settings rows unchanged | verify existing AiSettings tests pass unchanged | test | 6 |
| MNC-4 | offline suite | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| MNC-5 | fingerprint and parity | `manual-test.md#step-3` | manual | 7 |
| NFR-1 | arguments only through rules | `tests/Feature/BuyerAssistantToolTest.php` | test | 1 |
| NFR-2 | bounded cost | `tests/Feature/BuyerAssistantAgentTest.php` | test | 1 |
| NFR-3 | hallucinated ids reported | `tests/Feature/AssistantEvalTest.php` | test | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
