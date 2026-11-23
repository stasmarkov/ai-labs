# Fall back to a smaller local model — Tasks

**Spec**: spec-20261123-1440-local-model-fallback | **Size**: L | **Tasks**: 8

## Phase 1: Backend

- [ ] 1. Configuration and fallback policy per feature (laravel)
  Covers: AC-5, MNC-1, MNC-2
  - [ ] 1.1 Failing tests: defaults per feature, env override, off when equal to the main model, off for cloud providers and the listed features.
  - [ ] 1.2 Add `fallback_model`, `fallback_after_ms` to `config/ai.php` and a `FallbackPolicy` from `AiProviders`.
  - [ ] 1.3 **Verify**: tests green; existing provider tests unchanged.

- [ ] 2. Run log columns (laravel)
  Covers: AC-1, AC-4
  - [ ] 2.1 Additive migration `fallback_from_model`, `fallback_reason`; model casts; recorder accepts them.
  - [ ] 2.2 **Verify**: migration up/down; recorder tests green.

- [ ] 3. Fallback on errors for non-streaming and streaming agents (laravel)
  Covers: AC-1, AC-3, AC-4, MNC-3
  - [ ] 3.1 Failing tests with fakes per trigger (unreachable, 404, 5xx, timeout) for nl-search, moderation (non-streaming) and describe, assistant, docs-answer (streaming): fallback answers, one row, reasons; token already sent → no fallback; both fail → today's response, one failed row.
  - [ ] 3.2 Implement in one place used by every agent.
  - [ ] 3.3 **Verify**: tests green; SL-28 failure behaviour tests for features without fallback unchanged.

- [ ] 4. Busy budget (laravel)
  Covers: AC-2, NFR-2
  - [ ] 4.1 Failing tests: no first token within the budget → main cancelled, fallback answers, reason `busy`; first token in time → no fallback.
  - [ ] 4.2 Implement.
  - [ ] 4.3 **Verify**: tests green.

## Phase 2: UI and measurement

- [ ] 5. Lab display and settings (frontend)
  Covers: AC-6
  - [ ] 5.1 Run page and feature pages show the fallback; settings page lists fallback model and budget read-only.
  - [ ] 5.2 **Verify**: page tests; screenshots at 1440 and 1024; `NoReferencesInUiTest`.

- [ ] 6. `llm:bench --only=fallback` (laravel)
  Covers: AC-7
  - [ ] 6.1 Fault matrix, busy runs (4 and 16 parallel, with and without the budget, BR-21 for load), fallback evals; result file with host load at start and end; clear error if the fallback model is missing.
  - [ ] 6.2 **Verify**: `LlmBenchCommandTest` with fakes.

- [ ] 7. Manual: run the bench on an idle host and commit the result
  Covers: AC-7, NFR-1, NFR-2
  - [ ] 7.1 **Verify**: `manual-test.md#step-3`.

## Phase 3: Docs

- [ ] 8. BENCHMARKS, guides, learning guide, backlog (docs)
  Covers: AC-8, MNC-4
  - [ ] 8.1 Tables from the result file, guide numbers by file pointer, watch-outs, interview story.
  - [ ] 8.2 **Verify**: `GuideContentTest`, `DocsLinksTest`, `NoReferencesInUiTest` green; fingerprint unchanged.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | fallback on errors, row records it | `tests/Feature/LocalModelFallbackTest.php` | test | 2, 3 |
| AC-2 | busy budget | `tests/Feature/LocalModelFallbackTest.php` | test | 4 |
| AC-3 | no fallback after first token | `tests/Feature/LocalModelFallbackTest.php` | test | 3 |
| AC-4 | both fail → today's answer, one row | `tests/Feature/LocalModelFallbackTest.php` | test | 3 |
| AC-5 | off → unchanged | `tests/Feature/LocalModelFallbackTest.php` | test | 1 |
| AC-6 | lab shows fallback | `manual-test.md#step-2` | manual | 5 |
| AC-7 | bench result file | `manual-test.md#step-3` | manual | 6, 7 |
| AC-8 | guides and docs | `tests/Feature/GuideContentTest.php` | test | 8 |
| MNC-1 | no fallback → unchanged | `tests/Feature/LocalModelFallbackTest.php` | test | 1, 3 |
| MNC-2 | BR-91 precedence | `tests/Unit/AiProvidersTest.php` | test | 1 |
| MNC-3 | guards, validation, cloud | `tests/Feature/LocalModelFallbackTest.php` | test | 3 |
| MNC-4 | offline, deps, fingerprint | `manual-test.md#step-4` | manual | 8 |
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
