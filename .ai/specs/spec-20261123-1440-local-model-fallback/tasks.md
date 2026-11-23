# Fall back to a smaller local model — Tasks

**Spec**: spec-20261123-1440-local-model-fallback | **Size**: L | **Tasks**: 8

## Phase 1: Backend

- [x] 1. Configuration and fallback policy per feature (laravel)
  Covers: AC-5, MNC-1, MNC-2
  - [x] 1.1 Failing tests: defaults per feature, env override, off when equal to the main model, off for cloud providers and the listed features.
  - [x] 1.2 Add `fallback_model`, `fallback_after_ms` to `config/ai.php` and a `FallbackPolicy` from `AiProviders`.
  - [x] 1.3 **Verify**: tests green; existing provider tests unchanged.

- [x] 2. Run log columns (laravel)
  Covers: AC-1, AC-4
  - [x] 2.1 Additive migration `fallback_from_model`, `fallback_reason`; model casts; recorder accepts them.
  - [x] 2.2 **Verify**: migration up/down; recorder tests green.

- [x] 3. Fallback on errors for non-streaming and streaming agents (laravel)
  Covers: AC-1, AC-3, AC-4, MNC-3
  - [x] 3.1 Failing tests with fakes per trigger (unreachable, 404, 5xx, timeout) for nl-search, moderation (non-streaming) and describe, assistant, docs-answer (streaming): fallback answers, one row, reasons; token already sent → no fallback; both fail → today's response, one failed row.
  - [x] 3.2 Implement in one place used by every agent.
  - [x] 3.3 **Verify**: tests green; SL-28 failure behaviour tests for features without fallback unchanged.

- [x] 4. Busy budget (laravel)
  Covers: AC-2, NFR-2
  - [x] 4.1 Failing tests: no first token within the budget → main cancelled, fallback answers, reason `busy`; first token in time → no fallback.
  - [x] 4.2 Implement.
  - [x] 4.3 **Verify**: tests green.

## Phase 2: UI and measurement

- [x] 5. Lab display and settings (frontend)
  Covers: AC-6
  - [x] 5.1 Run page and feature pages show the fallback; settings page lists fallback model and budget read-only.
  - [x] 5.2 **Verify**: page tests; screenshots at 1440 and 1024; `NoReferencesInUiTest`.

- [x] 6. `llm:bench --only=fallback` (laravel)
  Covers: AC-7
  - [x] 6.1 Fault matrix, busy runs (4 and 16 parallel, with and without the budget, BR-21 for load), fallback evals; result file with host load at start and end; clear error if the fallback model is missing.
  - [x] 6.2 **Verify**: `LlmBenchCommandTest` with fakes.

- [ ] 7. Manual: run the bench on an idle host and commit the result
  Covers: AC-7, NFR-1, NFR-2
  - [ ] 7.1 **Verify**: `manual-test.md#step-9`.

## Phase 3: Docs

- [x] 8. BENCHMARKS, guides, learning guide, backlog (docs)
  Covers: AC-8, MNC-4
  - [x] 8.1 Tables from the result file, guide numbers by file pointer, watch-outs, interview story.
  - [x] 8.2 **Verify**: `GuideContentTest`, `DocsLinksTest`, `NoReferencesInUiTest` green; fingerprint unchanged.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | fallback on errors, row records it | `tests/Feature/LocalModelFallbackTest.php`, `manual-test.md#step-1` | test | 2, 3 |
| AC-2 | busy budget | `tests/Feature/LocalModelFallbackTest.php`, `manual-test.md#step-4` | test | 4 |
| AC-3 | no fallback after first token | `tests/Feature/LocalModelFallbackTest.php` | test | 3 |
| AC-4 | both fail → today's answer, one row | `tests/Feature/LocalModelFallbackTest.php` | test | 3 |
| AC-5 | off → unchanged | `tests/Feature/LocalModelFallbackTest.php` | test | 1 |
| AC-6 | lab shows fallback | `tests/Feature/LabFallbackDisplayTest.php` | test | 5 |
| AC-7 | bench result file | `manual-test.md#step-9` | manual | 6, 7 |
| AC-8 | guides and docs | `tests/Feature/GuideContentTest.php`, `manual-test.md#step-10` | test | 8 |
| MNC-1 | no fallback → unchanged | `tests/Feature/LocalModelFallbackTest.php` | test | 1, 3 |
| MNC-2 | BR-91 precedence | `tests/Unit/AiProvidersTest.php` | test | 1 |
| MNC-3 | guards, validation, cloud | `tests/Feature/LocalModelFallbackTest.php` | test | 3 |
| MNC-4 | offline, deps, fingerprint | `manual-test.md#step-11` | manual | 8 |
| NFR-1 | fault fallback time | `manual-test.md#step-3` | manual | 7 |
| NFR-2 | no added latency | `manual-test.md#step-3` | manual | 7 |

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

## Phase: Gaps (converge round 1)

- [x] 9. Enforce the first-token budget while the main model is still silent (laravel)
  Covers: AC-2
  - [x] 9.1 **Verify**: a new AC-2 test in `tests/Feature/LocalModelFallbackTest.php` whose fake main model would answer only after 2000 ms (the fake reads the request's timeout option and throws `cURL error 28` when it is below the delay) and a 100 ms budget: the fallback answers with reason `busy`, and the run's `latency_ms` is under 1000; the existing AC-2 tests stay green.

- [x] 10. Bench defaults match C-7 and produce the NFR-2 figure (laravel)
  Covers: AC-7, NFR-2
  - [x] 10.1 **Verify**: an `LlmBenchCommandTest` case run without `--budget-ms` and `--parallel` writes `metadata.budget_ms` = 2000 and `busy` keys 1, 4 and 16, with `busy.1.without_budget.ttft_ms.p50` present; the local-models guide recipe names the same defaults.

- [x] 11. MNC-3 test: a fallback answer that fails validation fails like a main one (laravel)
  Covers: MNC-3
  - [x] 11.1 **Verify**: in `LocalModelFallbackTest`, with the main model unreachable and the fallback returning JSON that breaks the listing filter rules, `/api/listings/nl` answers with the same status and error shape as an invalid main answer, and the one row has `model` = fallback, `fallback_from_model` = main, `fallback_reason` = `unreachable` and `validation_failed` = true.

- [ ] 12. Manual: after the bench run, add the three BENCHMARKS tables, a measured number in the interview story, and apply the C-14 quality rule
  Covers: AC-7, AC-8

## Phase: Gaps (converge round 2)

- [x] 13. Prove the first-byte budget on the real streaming transport, not only on a faked cURL error 28 (laravel)
  Covers: AC-2
  - [x] 13.1 **Verify**: a new AC-2 test in `tests/Feature/LocalModelFallbackTest.php` without `Http::fake` for the main model: a local TCP socket on 127.0.0.1 accepts the describe request and sends nothing for 2000 ms, `fallback_after_ms` = 100. The request then goes through the real Guzzle stream handler that `withOptions(['stream' => true])` selects. The fallback answers, the row has `fallback_reason` = `busy` and `latency_ms` < 1000. If the handler's timeout message does not match `FallbackPolicy::timedOut()`, the mapping in `FirstTokenBudget` is fixed until the test passes. The test stays offline (loopback only).

- [x] 14. AC-6 test: the invocation id a streamed feature page reads is the fallback row's id (laravel)
  Covers: AC-6
  - [x] 14.1 **Verify**: in `tests/Feature/LabFallbackDisplayTest.php`, for describe, assistant and docs-answer, the main model is unreachable and the fallback answers. The first `invocation_id` in the SSE events (the one `useEventStream` keeps and the pages pass to `/ai/runs/{id}`) equals the single `ai_runs` row's `invocation_id`. `GET /ai/runs/{that id}` returns `model` = `qwen3:0.6b`, `fallback_from_model` = `qwen3.5:9b` and `fallback_reason` = `unreachable`.

- [x] 15. C-3 test: a tool call already made counts as a sent token, so no fallback (laravel)
  Covers: AC-3, MNC-3
  - [x] 15.1 **Verify**: in `LocalModelFallbackTest`, the assistant's main model streams a tool call and then fails with a trigger (500 or connection error). Only the main model is requested, the tool runs once, and the stream ends with the masked error event. The one row is `failed` with `model` = main and null `fallback_from_model` and `fallback_reason`.

- [ ] 16. Run the MNC-4 check that Task 8 left unrun: asset fingerprint and dependency diff (laravel)
  Covers: MNC-4
  - [ ] 16.1 **Verify**: after `ddev npm run build`, `bench/asset-fingerprint.sh` prints `app-DNob7CFE.css` and `app-PTzu1QaN.js` (as in `manual-test.md#step-11`). `git diff --stat master -- composer.json composer.lock package.json package-lock.json` prints nothing.
