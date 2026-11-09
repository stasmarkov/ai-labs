---
id: spec-20261109-1213-nl-search
title: Natural-language search turned into the listing filters by a structured-output agent
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-09T10:15:26Z
created: 2026-11-09
stacks: [laravel]
slice: SL-23
brs: [BR-92, BR-90, BR-03, BR-100, BR-26, BR-27]
---

# Natural-language search turned into the listing filters by a structured-output agent

## Summary

`GET /api/listings/nl?q=…` turns a free-text query such as "diesel BMW after 2018 under 20k in Munich" into the `/listings` filters (BR-03), using a local model with structured output. Four guarantees hold:

- **Validation.** The model's output is validated by exactly the same rules as `GET /listings`. Output that fails validation is a 422 (BR-92).
- **The model only fills fields.** It never writes a query: it fills a fixed JSON schema, and the app runs the normal listing search with those values.
- **Honest limits.** Parts of the query the filters cannot express (body type such as "estate", colour, mileage) are returned as `unsupported`, not guessed.
- **Guard and trace.** The SL-45 input guard runs first. Every call writes an `ai_runs` row (BR-90).

A labelled set of 40 queries measures exact-filter match, per-field accuracy, how well `unsupported` is caught, the 422 rate and latency, for qwen3.5:9b and gemma4:12b. The lab gets `/ai/nl-search`, which shows the query, the extracted JSON, the validation result, the listings and the logged run.

## Context

- **The filters (BR-03)**, from `ListListingsRequest`:
  - `make` (string ≤ 50) and `model` (string ≤ 80);
  - `city` (string ≤ 80);
  - `fuel` (enum `petrol|diesel|hybrid|electric|lpg`);
  - `min_year` (1980 to next year);
  - `max_price` (integer **euros**, 1 to 100 000 000; the request multiplies by 100 to get cents);
  - `per_page` and `page`.
  `make` and `city` match case-insensitively (`lower(make) = lower(?)`).
- **The data (checked 2026-11-09):**
  - 14 makes (Audi … Volvo), all in EUR;
  - 20 European cities (Amsterdam … Warsaw), so "Lviv" is valid input but finds nothing;
  - no body-type column, so "estate" cannot be filtered.
- **Existing parts:**
  - `RagGraderAgent` (SL-33): `HasStructuredOutput`, a versioned prompt, a model override, the `ai_runs` row through the recorder;
  - `PromptInjectionGuard` (SL-45);
  - `ListingSearchService::search(ListingFilter)`;
  - the SL-33 contamination test (eval files must not be in the indexed corpus);
  - `bench/results` conventions.
- **The default server is frankenphp (SL-47).**

## Scope

### In scope

- **Shared rules.** The filter rules move into one class, `ListingFilterRules::rules()`, used by `ListListingsRequest` and by the NL path, so the two can never drift. Behaviour is unchanged (MNC-1).
- **`ListingFilterAgent`.**
  - **Interfaces and prompt:** it implements `HasStructuredOutput` and `VersionedPrompt`; the prompt is `resources/prompts/listing-filters.v1.md`.
  - **Schema:** `make`, `model`, `city`, `fuel` (enum), `min_year`, `max_price_eur` and `unsupported` (an array of short strings). Every field except `unsupported` is nullable.
  - **The prompt** lists the allowed fuels and says:
    - prices are euros;
    - "20k" means 20000;
    - "after 2018" means `min_year` 2019, and "from 2018" means 2018;
    - anything the filters cannot express goes into `unsupported`;
    - the model must never invent a make or city that is not in the query.
  - **Model override** as in the grader.
- **`NlSearchService::search(query, ?model)`:**
  1. trim the query and check its length (≤ 200 code points; NUL and UTF-8 rule as in SL-32);
  2. run the input guard, and refuse with 422 and `refused: true` and the reason;
  3. call the agent;
  4. map its output to filter input (`max_price_eur` → `max_price`);
  5. validate with `ListingFilterRules`, answering 422 with the validation errors and the extracted JSON;
  6. run `ListingSearchService::search`.
  The response is `{query, extracted, unsupported, filters, listings: [...], meta: {total, per_page}, invocation_id}`. A model or provider failure is a masked 503.
- **The endpoint:** `GET /api/listings/nl` with its own throttle `nl-search` (config `ai.nl_search.per_minute`, default 20) and `Server-Timing` (`model`, `db`).
- **The trace:** one `ai_runs` row per call, with agent `ListingFilterAgent`, prompt version `listing-filters@v1`, tokens and latency, and status `completed`, `refused` or `failed`. A run whose output fails validation is still `completed`; the 422 is the app's answer, recorded in a new nullable column `validation_failed` (bool).
- **The eval set:** `bench/nl-search/queries.v1.jsonl`, 40 lines of `{id, query, expected: {make, model, city, fuel, min_year, max_price_eur}, unsupported: [...]}`.
  - **Coverage:** at least 8 queries with an unsupported part, 4 with an out-of-data city, 4 with relative years ("after 2018"), 4 with price shorthand ("20k", "€15 000") and 4 with no filter at all.
  - **Clean:** it lives outside the indexed corpus, and the SL-33 contamination test is extended to cover it.
- **`nl:eval {--models=} {--label=}`** (Artisan), per model:
  - exact match: all six fields equal after normalization (lowercase strings, trimmed);
  - per-field accuracy;
  - `unsupported` recall: an expected unsupported term counts as caught if any returned unsupported string contains it, case-insensitively;
  - the 422 rate;
  - median and p95 latency from `ai_runs`.
  It writes `bench/results/nl-search/<UTC date>/<label>.json` atomically, using SL-33's result-writer path rules.
- **The lab page `/ai/nl-search`:**
  - **Controls:** a query box and a model selector (the configured text model, or gemma4:12b).
  - **Results:** the extracted JSON (pretty), the `unsupported` chips, the validation result (OK, or the 422 errors), the listings table, the timings, and the logged run.
  - **Navigation:** a capability card on `/ai` and the nav entry, from `LabOverviewService`.
- **Docs:**
  - a `BENCHMARKS.md` section `## Natural-language search (<date>)`;
  - guide section 24 (structured output, validation as the boundary, `unsupported` as honesty, the eval method);
  - the section 16 code map (new files, the request flow, and a feature-request row "turn free text into structured input").

### Out of scope

- New filters, such as body type or mileage. The eval shows what users ask for; adding filters is a later slice.
- Fuzzy matching of make or city names beyond case-insensitivity ("Merc" → "Mercedes-Benz" is reported as a miss).
- Multi-turn refinement, and the cache from SL-40.

## Must NOT change

- **MNC-1**: `GET /listings` keeps its validation, responses and 422s byte for byte; its tests stay green, unedited.
- **MNC-2**: the other AI endpoints, `rag:eval` and the committed results are unchanged.
- **MNC-3**: the asset fingerprint of the Blade pages and parity on 6 servers are unchanged. The lab is a separate Vite entry.
- **MNC-4**: the suite runs offline (fake provider, structured fake output).

## Acceptance criteria

- **AC-1 (BR-92)**: WHEN `GET /api/listings/nl?q=diesel BMW after 2018 under 20k in Munich` is requested, THE SYSTEM SHALL return 200. `filters` SHALL equal `{make: "BMW", fuel: "diesel", min_year: 2019, max_price: 20000, city: "Munich"}`, and `listings` SHALL equal the result of `GET /listings` with those parameters. A test SHALL check this with a faked structured output.
- **AC-2 (BR-92)**: WHEN the model's output fails the shared rules (`fuel: "steam"`, `min_year: 1700`, `max_price_eur: 0`, or an over-long string), THE SYSTEM SHALL answer 422 with the standard error body, the field errors, and the extracted JSON, and SHALL run no listing query.
- **AC-3**: The model SHALL only fill the schema. A test SHALL assert that whatever text the model's string fields contain (for example `"' OR 1=1 --"`), the search runs only through `ListingSearchService` with bound parameters, and an injection-looking value is treated as plain data.
- **AC-4**: Parts of the query the filters cannot express SHALL appear in `unsupported`, and SHALL NOT be turned into another filter. A test with a faked output checks the pass-through. The eval measures how often the model does this.
- **AC-5 (BR-100)**: WHEN the query matches the input-guard patterns, THE SYSTEM SHALL answer 422 with `refused: true` and the stated reason, call no model, and write a `refused` row.
- **AC-6 (BR-90)**: Every call SHALL write one `ai_runs` row with agent, provider, model, prompt version `listing-filters@v1`, tokens, latency and status, plus `validation_failed`.
- **AC-7**: The lab page SHALL show the query, the extracted JSON, the unsupported chips, the validation outcome (with errors on a 422), the listings and the logged run. It SHALL meet the SL-46 accessibility bar: no horizontal scroll at 390 px, visible focus, and the results region `aria-live="polite"`.
- **AC-8 (BR-26)**: `nl:eval --models=qwen3.5:9b,gemma4:12b` SHALL produce, per model:
  - exact-match rate;
  - per-field accuracy;
  - unsupported recall;
  - 422 rate;
  - latency median and p95.
  The committed run SHALL be in `bench/results/nl-search/`. `BENCHMARKS.md` SHALL have the section with a table, "What the numbers say", "Surprises" and "Interview story". The eval file SHALL pass the contamination test.
- **AC-9 (BR-27)**: The guide SHALL have `## 24. From words to filters: structured output with a validation boundary`. It SHALL link the code, prompt, eval set, results and official docs. Section 16 SHALL be updated, and the map test SHALL still pass.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Security | Model output is data only: validated, bound as parameters, never executed or concatenated into SQL. | `tests/Feature/NlSearchTest.php` |
| **NFR-2** | Performance | p95 latency on the eval set is recorded per model; no budget, but the lab shows it. | `bench/results/nl-search/` |

## Boundaries

- **Always**:
  - shared rules;
  - fakes in tests;
  - keep the eval set out of the corpus;
  - update section 16;
  - test on the default server (frankenphp).
- **Ask first**: new filters or columns; new dependencies.
- **Never**:
  - let the model output reach SQL as text;
  - commit `.env`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `app/Http/Requests/ListListingsRequest.php`;
  - `app/DTOs/ListingFilter.php`;
  - `app/Services/ListingSearchService.php`;
  - `app/Repositories/EloquentListingRepository.php` (`lower()` matching);
  - `app/Ai/RagGraderAgent.php` and `app/Ai/UsesActiveProvider.php`;
  - `app/Services/PromptInjectionGuard.php`;
  - `app/Ai/AiRunRecorder.php`;
  - `app/Services/RagEvalResultWriter.php`;
  - `tests/Feature/RagEvalContaminationTest.php`;
  - `resources/js/ai/Pages/Lab/*` and `app/Services/LabOverviewService.php`;
  - `docs/learning-guide.md` section 16.

## Decisions (self-decided, standing approval)

1. **Euros in the schema (`max_price_eur`), cents in the database.** People say "20k"; the model should not do unit conversion. The app maps euros to the request's existing `max_price` (euros), which already multiplies by 100.
2. **`unsupported` instead of silent dropping.** Silently ignoring "estate" returns wrong results that look right. Listing it tells the user what was not applied, and gives the eval a measure of honesty.
3. **Validation is the boundary, not the prompt.** The prompt asks for valid values; the shared rules enforce them. A model that drifts produces a 422, never a strange query.
4. **No fuzzy name matching yet.** Case-insensitive exact matching keeps the eval interpretable. "Merc" counts as a miss, and the guide names alias tables or embeddings as the next step.
5. **GET with `q`.** It is consistent with `/api/listings/semantic`. The query is not personal, and it is throttled.

## Clarifications (binding; resolve the clarity gate)

**Response bodies (Q1).**
- **200:** `{query, model, extracted: {make, model, city, fuel, min_year, max_price_eur}, unsupported: [..], filters: {…non-null only}, listings: [ListingResource…], meta: {total, per_page, page}, invocation_id}`.
- **Validation 422:** the app's standard body `{success:false, status:422, message, errors}`, where the error keys are filter names (`max_price`, not `max_price_eur`), plus `extracted`, `unsupported` and `invocation_id`.
- **Refusal 422:** the standard body, with `message` set to the guard reason, plus `refused: true`, `pattern` and `invocation_id`.
- **Masked 503:** the standard body, with `message` "Natural-language search is unavailable right now."; no host names.

**Which calls write a row (Q2).** A row is written for calls that pass request validation:
- **Completed:** `completed`, with `validation_failed` true or false.
- **Refused:** `refused`, with provider and model from the active configuration, tokens null and `validation_failed` null (the SL-45 `recordOutcome` path).
- **Failed:** `failed`, with `validation_failed` null.
Missing, blank (whitespace-only), over-long, NUL or invalid-UTF-8 `q` is a request 422 from `NlSearchRequest` with no row, and a 429 has no row either.

**Filters (Q3).** `filters` holds the request-level `/listings` parameters, with null fields stripped. `max_price` is in euros, as `/listings` expects. The endpoint also accepts `per_page` and `page`, with the same rules, passed through, and they appear in `meta`.

**The model parameter (Q4).**
- **Input:** an optional `model`, allowlisted by `config('ai.nl_search.models')`, default `[the configured text model, 'gemma4:12b']`; anything else is a 422.
- **Default:** the configured text model is `config('ai.providers.<default>.models.text.default')`.
- **The lab** calls the same API route, so the throttle is shared.

**Eval scoring (Q5).**
- **What runs:** `nl:eval` runs the full `NlSearchService`, guard and validation included, and links rows by the returned `invocation_id`.
- **422s:** a validation 422 counts as a miss on exact match and on every field. A refused or failed call also counts as a miss, and is reported separately.
- **Fields:** an expected `null` matches a returned `null`. Strings are compared trimmed and lowercased; integers as integers.
- **`unsupported` recall:** as in Scope, over the queries that list unsupported terms.

**Phrasing (Q6).** `max_price` is inclusive, so "under 20k" means 20000. "Before 2018", "cheap", colour, body type and mileage all go to `unsupported`; there is no `max_year` filter. The eval set uses these readings, and the manual step expects "cheap" in `unsupported` too.

**Case (Q7).** The agent returns names as the user wrote them. The app normalizes `fuel` to lowercase before validation, so "Diesel" passes and "steam" is a 422. `make` and `city` are kept as returned, because matching is case-insensitive. AC-1 compares `make` and `city` case-insensitively.

**Input rules (Q8).** `NlSearchRequest` holds: `q` required, a string, trimmed, 1–200 code points, plus the existing `ValidUtf8Text` rule (no NUL, valid UTF-8). A failure is the standard 422. Blank is a 422.

**Eval files vs a clean tree (Q9).** Manual and check runs use `--label` values under the gitignored `bench/results/raw/` (`--output-dir`), so only the committed run (label `run`) lives in `bench/results/nl-search/`. The doc tests read `bench/results/nl-search/*/run.json`.

**Lab details (Q10).**
- **"The logged run":** the `/ai/runs/{id}` panel from SL-46 (agent, model, prompt version, tokens, TTFT, latency, status).
- **Timings:** `Server-Timing` (`model`, `db`).
- **Card:** "NL search" with a one-line description, `ready`, after "Semantic search" in the nav.
- **Tests:** `AiLabNlSearchTest` asserts the props, the capability and the nav order. The a11y bar is checked by the Playwright run in task 5.2.

## Open questions

Clarity gate 2026-11-09: 10 items, all answered under standing approval above.

- [x] Q1: Response bodies.
- [x] Q2: Which calls write a row.
- [x] Q3: The `filters` shape; paging.
- [x] Q4: The model parameter allowlist; the lab uses the API.
- [x] Q5: Eval scoring.
- [x] Q6: Phrasing rules.
- [x] Q7: Case handling.
- [x] Q8: Input rules.
- [x] Q9: Where eval files go.
- [x] Q10: Lab details.

## Dependencies

- **Requires**: SL-22, SL-33 (grader pattern, result writer, contamination test), SL-45 (guard), SL-46 (lab), SL-47 (default server).
- **Blocks**: SL-25 (the buyer assistant may call this as a tool).

---
Next: `/sdd spec-20261109-1213-nl-search` (approval → build)
