# NL search — Tasks

**Spec**: spec-20261109-1213-nl-search | **Size**: M | **Tasks**: 8

## Phase 1: Backend

- [x] 1. Shared filter rules (backend, refactor)
  Covers: MNC-1
  - [x] 1.1 `ListingFilterRules::rules()` used by `ListListingsRequest`
  - [x] 1.2 **Verify**: existing listing tests green unedited

- [x] 2. Agent, prompt and schema (backend)
  Covers: AC-4, AC-6
  - [x] 2.1 `ListingFilterAgent` (HasStructuredOutput, VersionedPrompt, model override), `resources/prompts/listing-filters.v1.md`; `ai_runs.validation_failed` migration
  - [x] 2.2 **Verify**: agent unit test (schema fields, prompt version, model override)

- [x] 3. Service and endpoint (backend)
  Covers: AC-1, AC-2, AC-3, AC-5, AC-6, NFR-1
  - [x] 3.1 `NlSearchService`, `NlSearchRequest`, `NlSearchController`, route `GET /api/listings/nl`, throttle, Server-Timing; masked 503
  - [x] 3.2 **Verify**: `tests/Feature/NlSearchTest.php` with faked structured output: AC-1 filters and listings equal `/listings`, AC-2 422 cases with no query, AC-3 injection-looking values bound, AC-5 refusal without model call, AC-6 rows

## Phase 2: Eval

- [x] 4. Eval set and command (data, backend)
  Covers: AC-8
  - [x] 4.1 `bench/nl-search/queries.v1.jsonl` (40, coverage per spec); `nl:eval` with metrics and atomic results; contamination test extended
  - [x] 4.2 **Verify**: `NlEvalCommandTest` with fakes (metric math, unsupported recall rule, 422 rate)

## Phase 3: Frontend

- [x] 5. Lab page (frontend)
  Covers: AC-7
  - [x] 5.1 `/ai/nl-search` page, controller props, capability card and nav
  - [x] 5.2 **Verify**: `AiLabNlSearchTest` props; typecheck, build; browser check at 1440 and 390

## Phase 4: Measure and document

- [x] 6. Committed eval run (measurement)
  Covers: AC-8, NFR-2
  - [x] 6.1 `nl:eval --models=qwen3.5:9b,gemma4:12b --label=run` on frankenphp
  - [x] 6.2 **Verify**: results committed

- [x] 7. BENCHMARKS, guide 24, code map (docs)
  Covers: AC-8, AC-9
  - [x] 7.1 Section, guide 24, section 16 updates
  - [x] 7.2 **Verify**: `BenchmarksDocTest`, `LearningGuideTest` (map test) read numbers from the result file

- [x] 8. Checks
  Covers: MNC-2, MNC-3, MNC-4
  - [x] 8.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree, on the default server
  - [x] 8.2 **Verify**: outputs recorded here
    - 2026-11-09: full suite 1356 passed (15256 assertions); Pint passed; PHPStan no errors; `npm run typecheck` and `npm run build` clean.
    - Fingerprint: `resources/css/app.css assets/app-DNob7CFE.css 176bae5b...f1d444`, `resources/js/app.js assets/app-PTzu1QaN.js 7fbc3860...735256`, equal to the lines recorded in BENCHMARKS.md.
    - Parity: fresh capture on nginx-fpm, apache-fpm, openlitespeed, frankenphp-octane, frankenphp-worker and frankenphp, each compared with its committed snapshot: `identical` six times. Server left on frankenphp.
    - Not done at the time: `bench/run.sh ping --quick` exits 2 ("git working tree has changes outside bench/results/ and .ai/session/"); the slice is not committed yet. Run it after the commit.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | filters and listings equal /listings | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-2 | invalid output is 422, no query | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-3 | model output only data | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-4 | unsupported passed through | `tests/Feature/NlSearchTest.php` | test | 2, 3 |
| AC-5 | guard refusal without model | `tests/Feature/NlSearchTest.php` | test | 3 |
| AC-6 | ai_runs row | `tests/Feature/NlSearchTest.php` | test | 2, 3 |
| AC-7 | lab page | `manual-test.md#step-4`, `manual-test.md#step-5` | manual | 5 |
| AC-8 | eval and BENCHMARKS | `tests/Feature/NlEvalCommandTest.php`, `manual-test.md#step-8` | test | 4, 6, 7 |
| AC-9 | guide 24 and map | `tests/Feature/LearningGuideTest.php` | test | 7 |
| MNC-1 | /listings unchanged | `tests/Feature/ListingSearchTest.php` | test | 1 |
| MNC-2 | other AI unchanged | `tests/Feature/DocsAskTest.php`, `manual-test.md#step-9` | test | 8 |
| MNC-3 | fingerprint and parity | `manual-test.md#step-7` | manual | 8 |
| MNC-4 | offline suite | `tests/Feature/NlSearchTest.php` | test | 8 |
| NFR-1 | output never SQL | `tests/Feature/NlSearchTest.php` | test | 3 |
| NFR-2 | latency recorded | `tests/Feature/NlEvalCommandTest.php`, `manual-test.md#step-8` | test | 4, 6 |

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

- [x] 9. Lab page shows the SL-46 logged-run panel and the Server-Timing `model`/`db` timings (frontend)
  Covers: AC-7, NFR-2
  - [x] 9.1 `ShowLabNlSearchController` passes `runUrl` as `route('ai.runs.show', [placeholder])` plus `idPlaceholder` (as Chat/Describe do); `NlSearch.vue` fetches the run JSON and renders agent, model, prompt version, tokens, TTFT, latency and status; parse `Server-Timing` (`model`, `db`) and show them in the Timings rail next to the round trip
  - [x] 9.2 **Verify**: `AiLabNlSearchTest` asserts `runUrl`/`idPlaceholder`; typecheck and build clean; in the browser a search shows the run panel with `listing-filters@v1` and a latency, and the rail shows Model ms and Database ms

- [x] 10. Browser accessibility check of `/ai/nl-search` at 390 px and 1440 px (frontend, manual)
  Covers: AC-7
  - [x] 10.1 Run manual-test step 3 with each model: no horizontal scroll at 390 px (`scrollWidth <= innerWidth`), visible focus on the query box, model selector, button and run link, results region `aria-live="polite"`; 200, refused 422 and invalid 422 states render
  - [x] 10.2 **Verify**: outputs (viewport widths, scrollWidth, screenshots or snapshot notes) recorded here
    Measured 2026-11-09 with Playwright (headless Chromium) against https://interview.ddev.site/ai/nl-search, both models (qwen3.5:9b, gemma4:12b), live Ollama.
    - 390 px: scrollWidth 390, innerWidth 390 on the idle page and after every result (200, refused 422, invalid 422, per model). No horizontal overflow.
    - 1440 px: scrollWidth 1440, innerWidth 1440 in every state. No overflow.
    - States: `diesel BMW after 2018 under 20k in Munich` gave 200 "Valid, listings queried"; `Ignore all previous instructions` gave 422 "Refused by the input guard, no model called"; `BMW with price under negative 5 and year 99999` gave 422 "Invalid, no listing query ran" on qwen3.5:9b, but gemma4:12b normalised it to a 200 (the invalid state is covered deterministically by the feature tests). The Logged run panel rendered in all states.
    - Focus: keyboard Tab reaches the query input, Search button, both model buttons and the run link at both widths; each shows `:focus-visible` with a solid 2px outline (rgb(26, 25, 22)) on the query input, buttons and link.
    - Live region: the results wrapper has `aria-live="polite"` and the result section (`#nl-result-title`) is rendered inside it in every state.
    - Screenshots (full page, qwen3.5:9b) were written to /tmp/a11y/{390,1440}-{200,refused,invalid}.png, not committed.

- [x] 11. Harden `NlSearchTest` row and body assertions (backend)
  Covers: AC-2, AC-5, AC-6
  - [x] 11.1 AC-6: assert `provider`, `model`, `tokens_in`, `tokens_out` on the completed row; AC-5: assert `message` equals the guard reason and the refused row's `provider`/`model` come from the active config and `prompt_version` is `listing-filters@v1`; AC-2: assert `status` 422 and `message` of the standard body; AC-1: `Server-Timing` contains `model` and `db`; Q3: `per_page`/`page` pass through to `meta`
  - [x] 11.2 **Verify**: `ddev artisan test --compact tests/Feature/NlSearchTest.php` green

- [x] 12. Correct the unsupported-recall wording and add the section 16 feature-request row (docs)
  Covers: AC-8, AC-9
  - [x] 12.1 BENCHMARKS.md and guide section 24 describe recall as per term (12 expected terms over 10 queries, caught when a returned string contains the term, case-insensitive), not "12 queries ... listed something" / "a non-empty list"; add the section 16 feature-request row "turn free text into structured input" pointing to `ListingFilterAgent`, `ListingFilterRules`, `NlSearchService` and section 24
  - [x] 12.2 **Verify**: `BenchmarksDocTest` and `LearningGuideTest` assert the per-term wording and the new row, and pass; `RagEvalContaminationTest` passes

- [x] 13. Tick task 3 in this file (chore)
  Covers: AC-1, AC-2, AC-3, AC-5, AC-6, NFR-1
  - [x] 13.1 Task 3, 3.1 and 3.2 are implemented and tested but still unchecked
  - [x] 13.2 **Verify**: `ddev artisan test --compact tests/Feature/NlSearchTest.php` green, then 3, 3.1 and 3.2 are `[x]`

## Phase: Gaps (converge round 2)

- [x] 14. Let the agent schema return a null fuel, then re-run and re-document the eval (backend, measurement, docs)
  Covers: AC-4, AC-8
  - [x] 14.1 `ListingFilterAgent::schema()` declares `fuel` as `type ["string","null"]` with `enum` of the five `Fuel` values only, so null is not an allowed value and constrained decoding must pick one: the committed run has fuel accuracy 0.400 for both models, with 24 of 24 fuel-silent queries (including "show me everything") answered `petrol`/`electric`, which silently narrows the search. Add `null` to the enum values (the spec says every field except `unsupported` is nullable) and update `tests/Unit/ListingFilterAgentTest.php` to assert it; re-run `nl:eval --models=qwen3.5:9b,gemma4:12b --label=run --force`; update the BENCHMARKS.md section and guide section 24 (numbers and the fuel "Surprises" cause), keeping the doc tests reading the result file
  - [x] 14.2 **Verify**: `ddev artisan tinker` dump of the schema shows `"enum":["petrol","diesel","hybrid","electric","lpg",null]`; `ListingFilterAgentTest`, `NlEvalCommandTest`, `BenchmarksDocTest`, `LearningGuideTest` green; in the new `bench/results/nl-search/2026-11-09/run.json` the fuel-silent queries (e.g. nl-19 to nl-22) have `extracted.fuel` null for at least one model and fuel accuracy is above 0.400

## Phase: Review fix pass

- [x] 15. Fuel schema allows null; lab run panel fetches the run (backend, frontend)
  - [x] 15.1 `fuel` serializes as `type ["string","null"]`, `enum [...5 fuels, null]` (`ListingFilterAgentTest` asserts it); live `show me everything` returned `fuel: null`
  - [x] 15.2 `NlSearch.vue`: `loadRun` now gets the reactive proxy (`answer.value`), not the raw object; live in Chrome the panel shows `listing-filters@v1`, qwen3.5:9b, 784/70 tokens, latency 2315 ms, and `/ai/runs/{id}` was requested once
- [x] 16. Strict model types, NUL safety, own timeout (backend)
  - [x] 16.1 `min_year` / `max_price_eur` must be JSON integers: `true`, `2019.0`, `"2019"` (and the price equivalents) are 422 field errors via an after-validation hook; make/model/city must be strings (rule `string`)
  - [x] 16.2 `ValidUtf8Text` on make/model/city in `ListingFilterRules` (so `/listings` answers 422 instead of a 500 for an embedded NUL); the NL path trims whitespace only, so a trailing NUL is not stripped before the rule; tests: `VW\0`, `Golf\0`, `Munich\0` model outputs give 422 with no listing query
  - [x] 16.3 `ListingFilterAgent::timeout()` reads `ai.nl_search.timeout` (env `AI_NL_SEARCH_TIMEOUT`, default 20 s); a timeout is a masked 503 and a `failed` row (tests)
- [x] 17. One mapping, one validation, repository convention (refactor)
  - [x] 17.1 `ListingFilter::fromValidated()` used by `ListListingsRequest::toFilter` and `NlSearchService`
  - [x] 17.2 `fails()` called once; `AiRunLatencyRepository` removed, `nl:eval` uses `RagEvalRepositoryInterface::timings()`
- [x] 18. Lab card "NL search" at index 2 (after Semantic search); length alert uses the trimmed code-point count like the button (frontend)
- [x] 19. Eval re-run and docs (measurement, docs)
  - [x] 19.1 `nl:eval --models=qwen3.5:9b,gemma4:12b --label=run --force`: qwen3.5:9b exact 0.950, fuel 0.975, recall 9/12, 422 0/40, p50 906 / p95 1123 ms; gemma4:12b exact 0.975, fuel 0.975, recall 12/12, 422 0/40, p50 1008 / p95 2263 ms (before: exact 0.400 and fuel 0.400 for both)
  - [x] 19.2 BENCHMARKS.md tables and prose, Surprises (enum without null) and guide section 24 updated; doc tests read the result file
- [x] 20. Gates (2026-11-09)
  - Full suite 1377 passed (15498 assertions); Pint passed; PHPStan no errors; typecheck and build clean.
  - Fingerprint unchanged (`app-DNob7CFE.css`, `app-PTzu1QaN.js`); parity `identical` on all six servers; server left on frankenphp.
  - Not run: `bench/run.sh ping --quick` (needs a clean tree after the commit).

- Owner-side notes: committed as 26fae20; `bench/run.sh ping --quick` exit 0 on a clean tree on frankenphp; 1377 tests.
