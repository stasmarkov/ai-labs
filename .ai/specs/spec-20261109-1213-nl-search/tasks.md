# NL search — Tasks

**Spec**: spec-20261109-1213-nl-search | **Size**: M | **Tasks**: 8

## Phase 1: Backend

- [ ] 1. Shared filter rules (backend, refactor)
  Covers: MNC-1
  - [ ] 1.1 `ListingFilterRules::rules()` used by `ListListingsRequest`
  - [ ] 1.2 **Verify**: existing listing tests green unedited

- [ ] 2. Agent, prompt and schema (backend)
  Covers: AC-4, AC-6
  - [ ] 2.1 `ListingFilterAgent` (HasStructuredOutput, VersionedPrompt, model override), `resources/prompts/listing-filters.v1.md`; `ai_runs.validation_failed` migration
  - [ ] 2.2 **Verify**: agent unit test (schema fields, prompt version, model override)

- [ ] 3. Service and endpoint (backend)
  Covers: AC-1, AC-2, AC-3, AC-5, AC-6, NFR-1
  - [ ] 3.1 `NlSearchService`, `NlSearchRequest`, `NlSearchController`, route `GET /api/listings/nl`, throttle, Server-Timing; masked 503
  - [ ] 3.2 **Verify**: `tests/Feature/NlSearchTest.php` with faked structured output: AC-1 filters and listings equal `/listings`, AC-2 422 cases with no query, AC-3 injection-looking values bound, AC-5 refusal without model call, AC-6 rows

## Phase 2: Eval

- [ ] 4. Eval set and command (data, backend)
  Covers: AC-8
  - [ ] 4.1 `bench/nl-search/queries.v1.jsonl` (40, coverage per spec); `nl:eval` with metrics and atomic results; contamination test extended
  - [ ] 4.2 **Verify**: `NlEvalCommandTest` with fakes (metric math, unsupported recall rule, 422 rate)

## Phase 3: Frontend

- [ ] 5. Lab page (frontend)
  Covers: AC-7
  - [ ] 5.1 `/ai/nl-search` page, controller props, capability card and nav
  - [ ] 5.2 **Verify**: `AiLabNlSearchTest` props; typecheck, build; browser check at 1440 and 390

## Phase 4: Measure and document

- [ ] 6. Committed eval run (measurement)
  Covers: AC-8, NFR-2
  - [ ] 6.1 `nl:eval --models=qwen3.5:9b,gemma4:12b --label=run` on frankenphp
  - [ ] 6.2 **Verify**: results committed

- [ ] 7. BENCHMARKS, guide 24, code map (docs)
  Covers: AC-8, AC-9
  - [ ] 7.1 Section, guide 24, section 16 updates
  - [ ] 7.2 **Verify**: `BenchmarksDocTest`, `LearningGuideTest` (map test) read numbers from the result file

- [ ] 8. Checks
  Covers: MNC-2, MNC-3, MNC-4
  - [ ] 8.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree, on the default server
  - [ ] 8.2 **Verify**: outputs recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | filters and listings equal /listings | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-2 | invalid output is 422, no query | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-3 | model output only data | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-4 | unsupported passed through | `tests/Feature/NlSearchTest.php` | test | 2, 3 |
| AC-5 | guard refusal without model | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-6 | ai_runs row | `tests/Feature/NlSearchTest.php` | test | 2, 3 |
| AC-7 | lab page | `manual-test.md#step-3` | manual | 5 |
| AC-8 | eval and BENCHMARKS | `tests/Feature/NlEvalCommandTest.php` | test | 4, 6, 7 |
| AC-9 | guide 24 and map | `tests/Feature/LearningGuideTest.php` | test | 7 |
| MNC-1 | /listings unchanged | `tests/Feature/ListingSearchTest.php` | test | 1 |
| MNC-2 | other AI unchanged | `tests/Feature/DocsAskTest.php` | test | 8 |
| MNC-3 | fingerprint and parity | `manual-test.md#step-4` | manual | 8 |
| MNC-4 | offline suite | `tests/Feature/NlSearchTest.php` | test | 8 |
| NFR-1 | output never SQL | `tests/Feature/NlSearchTest.php` | test | 3 |
| NFR-2 | latency recorded | `tests/Feature/NlEvalCommandTest.php` | test | 4, 6 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
