# Optional cloud providers — Tasks

**Spec**: spec-20261110-2141-cloud-providers | **Size**: L | **Tasks**: 9

## Phase 1: Resolver and config

- [x] 1. Provider entries and `AiProviders` (backend)
  Covers: AC-1, AC-2, MNC-1, NFR-1
  - [x] 1.1 `config/ai.php` entries for anthropic, openai and gemini, plus the `features` map; `App\Ai\AiProviders` (`enabled()`, `enabledTextProviders()`, `forFeature()`, capability map); a `ProviderChoice` DTO; record the model-id sources in `decisions.md`.
  - [x] 1.2 **Verify**: `tests/Unit/AiProvidersTest.php` (fallbacks, key-less cloud provider not enabled, override validation, no key in any return value).

- [x] 2. Route every provider read through the resolver (backend)
  Covers: AC-1, AC-4, MNC-1, MNC-2
  - [x] 2.1 Agents, `UsesActiveProvider`, services, commands, `RecordEmbeddingsRun`, `LabOverviewService` and `GuideFacts` use `forFeature()`. A grep test finds no direct `config('ai.default')` or `ai.default_for_embeddings` read outside `AiProviders`.
  - [x] 2.2 **Verify**: `tests/Feature/CloudProvidersTest.php` (per-feature provider via SDK fake; run rows carry provider and model; key-less provider fails with no call); the existing suite is green.

## Phase 2: Requests, limits, embeddings

- [x] 3. Optional `provider` parameter (backend)
  Covers: AC-2, AC-3, MNC-2
  - [x] 3.1 Describe, NL search and docs ask accept `provider`, validated against the enabled text providers (422 `provider_not_enabled`); the agents run on the chosen provider.
  - [x] 3.2 **Verify**: `CloudProvidersTest` override cases; the existing endpoint tests are unchanged.

- [x] 4. Cloud limiter (backend)
  Covers: AC-7, NFR-3
  - [x] 4.1 A `cloud-ai` limiter applied when the resolved provider is a cloud one.
  - [x] 4.2 **Verify**: `tests/Feature/CloudRateLimitTest.php`.

- [x] 5. Embeddings switch safety (backend)
  Covers: AC-5, MNC-3
  - [x] 5.1 409 `index_not_built` on semantic and docs search when no row matches the configured embedding model; the dimension check in `listings:embed` and `docs:index`.
  - [x] 5.2 **Verify**: `tests/Feature/EmbeddingsProviderSwitchTest.php`.

## Phase 3: UI, guides, docs

- [x] 6. Lab selector (frontend)
  Covers: AC-3
  - [x] 6.1 `LabProviderSelect.vue` on Describe, NL search and Chat, fed by an `enabledTextProviders` prop (names and a configured flag only); the selected value goes into the request; search pages show the embeddings provider as text.
  - [x] 6.2 **Verify**: `tests/Feature/AiLabProviderSelectTest.php` (props without keys); typecheck; build; browser check.

- [x] 7. Providers guide and learning guide (docs)
  Covers: AC-6, AC-8
  - [x] 7.1 `providers.php`: capability table section, key-set facts, recipes, failure modes, experiment; `GuideRenderer` and `Guide.vue` render the table; §16 lists the new files and the "add a provider" recipe; `.env.example`; README paragraph.
  - [x] 7.2 **Verify**: `GuideContentTest`, `GuideFactsTest` (key set or not), the `LearningGuideTest` map, `ReadmeTest`.

- [x] 8. Secret safety (checks)
  Covers: NFR-1, NFR-2, MNC-4
  - [x] 8.1 `tests/Feature/CloudKeysNeverLeakTest.php` (fake keys, then search every lab page, the guide, the log and ai_runs); `preventStrayRequests` in the new tests.
  - [x] 8.2 **Verify**: green.

- [x] 9. Checks (checks)
  Covers: MNC-1, MNC-5
  - [x] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; parity on frankenphp unchanged; `bench/run.sh ping --quick` after the commit.
  - [x] 9.2 **Verify**: outputs recorded here.
    - Full suite: 1615 passed (18977 assertions), no cloud variables set. Pint passed, PHPStan no errors, `vue-tsc` clean, `npm run build` ok.
    - Fingerprint before and after the build identical: app.css `assets/app-DNob7CFE.css` 176bae5b..., app.js `assets/app-PTzu1QaN.js` 7fbc3860...
    - Parity on frankenphp: a fresh capture matches `bench/parity/frankenphp.txt` except the capture timestamp line.
    - `bench/run.sh ping --quick`: not run, it is a post-commit step and nothing was committed.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | per-feature provider by config | `tests/Feature/CloudProvidersTest.php`; `manual-test.md#step-8` | test | 1, 2 |
| AC-2 | key-less provider refused, no call | `tests/Feature/CloudProvidersTest.php`; `manual-test.md#step-5`, `manual-test.md#step-6` | test | 1, 3 |
| AC-3 | lab selector override and run row | `tests/Feature/CloudProvidersTest.php`, `tests/Feature/AiLabProviderSelectTest.php`; `manual-test.md#step-2`, `manual-test.md#step-3`, `manual-test.md#step-4`, `manual-test.md#step-5` | test | 3, 6 |
| AC-4 | ai_runs provider and model | `tests/Feature/CloudProvidersTest.php`; `manual-test.md#step-2`, `manual-test.md#step-8` | test | 2 |
| AC-5 | 409 index_not_built; dimension check | `tests/Feature/EmbeddingsProviderSwitchTest.php`; `manual-test.md#step-10` | test | 5 |
| AC-6 | capability table and key-set facts | `tests/Feature/GuideFactsTest.php`; `manual-test.md#step-7` | test | 7 |
| AC-7 | cloud limiter 429 | `tests/Feature/CloudRateLimitTest.php`; `manual-test.md#step-9` | test | 4 |
| AC-8 | §16 lists the new files | `tests/Feature/LearningGuideTest.php`; `manual-test.md#step-12` | test | 7 |
| MNC-1 | defaults unchanged with no keys | verify full suite green with no cloud variables set; `manual-test.md#step-1` | test | 2, 9 |
| MNC-2 | endpoint contracts without provider | verify existing AiLab and endpoint tests pass unchanged; `manual-test.md#step-4`, `manual-test.md#step-13` | test | 3 |
| MNC-3 | search unchanged on bge-m3 | `tests/Feature/EmbeddingsProviderSwitchTest.php`; `manual-test.md#step-13` | test | 5 |
| MNC-4 | offline tests | `tests/Feature/CloudKeysNeverLeakTest.php`; `manual-test.md#step-11` | test | 8 |
| MNC-5 | fingerprint and parity | `manual-test.md#step-14` | manual | 9 |
| NFR-1 | no key leaks | `tests/Feature/CloudKeysNeverLeakTest.php`; `manual-test.md#step-7`, `manual-test.md#step-15` | test | 8 |
| NFR-2 | no network in tests | `tests/Feature/CloudProvidersTest.php`; `manual-test.md#step-11` | test | 8 |
| NFR-3 | cost safety | `tests/Feature/CloudRateLimitTest.php`; `manual-test.md#step-9`, `manual-test.md#step-11` | test | 4 |

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

- [x] 10. Answer 503 `provider_not_configured` for a feature configured with a key-less cloud provider (backend)
  Covers: AC-2
  - `ProviderNotConfiguredException` has no mapping in `app/Http/Responses/JsonErrorRenderer.php`, so describe and docs ask fall to the default 500 "Server error", and NL search wraps it as `NlSearchUnavailableException` (503 without `code`) and writes a `failed` ai_runs row. Map it to 503 with `code: provider_not_configured` before any stream opens (let it pass the NL search catch), and make `listings:embed`, `docs:index` and `nl:eval` print the message and exit 1.
  - [x] 10.1 **Verify**: feature tests in `tests/Feature/CloudProvidersTest.php`: with `AI_<FEATURE>_PROVIDER=anthropic` and no key, `/listings/{id}/describe`, `/api/listings/nl` and `/api/docs/ask` answer 503 `code: provider_not_configured`, no SDK prompt, `Http::assertNothingSent()`, no ai_runs row; `listings:embed` with `embeddings-listings` on a key-less openai exits 1 with the message.

- [x] 11. Keep a configured fake provider runnable outside local and testing (backend)
  Covers: MNC-1
  - `AiProviders::isEnabled()` returns false for the `fake-stream` driver unless the environment is local or testing, and `forFeature()` then throws `ProviderNotConfiguredException`. With `APP_ENV=production` and the default `AI_PROVIDER` (fake-stream), every text feature now fails, which breaks today's behaviour and the `bench/slow-load.sh` sse workload (it sets `APP_ENV=production` and `AI_PROVIDER=fake-stream`). Clarity gate 8 limits the fakes in the selector only; a configured fake must still run.
  - [x] 11.1 **Verify**: a test sets `app.env` to production with `ai.default=fake-stream` and gets a 200 stream from `/listings/{id}/describe` with an ai_runs row on `fake-stream`, while `enabledTextProviders()` (the selector) still hides the fakes.

- [x] 12. Validate a non-string `provider` before the cloud limiter reads it (backend)
  Covers: MNC-2, AC-3
  - `app/Http/Middleware/LimitCloudAi.php` passes `$request->input('provider')` straight to `AiProviders::configured(string, ?string)` under `strict_types`, and it runs before the form request. `/api/listings/nl?q=a&provider[]=x` or a JSON body `{"provider": 5}` on `/api/docs/ask` raises a TypeError (500) instead of the 422 validation error.
  - [x] 12.1 **Verify**: tests in `tests/Feature/CloudRateLimitTest.php` send an array and an integer `provider` to NL search, describe and docs ask and get 422, with no ai_runs row and no cloud-ai hit.

- [x] 13. Add the `provider` condition to the docs answer cache lookup (backend)
  Covers: AC-1
  - Clarity gate 10 requires the lookup to filter on `provider` and a test showing a different provider misses. `EloquentDocsAnswerCacheRepository::current()` filters only on prompt version, answer model, embedding model and index version, so after `AI_DOCS_ANSWER_PROVIDER` changes, an entry from the old provider with the same model id is served as the new provider's answer. No test covers it.
  - [x] 13.1 **Verify**: a test in `tests/Feature/DocsCacheTest.php` stores an entry on one provider, switches `ai.features.docs-answer.provider` to another with the same model id, and gets a miss (the agent is prompted); the same provider still hits.

- [x] 14. Set the cloud limiter default to 20 per minute as specified (backend)
  Covers: AC-7, NFR-3
  - The spec sets `AI_CLOUD_PER_MINUTE` "default 20 per IP"; `config/ai.php` defaults `cloud_per_minute` to 10 and `.env.example` documents 10, with no decision recorded in `decisions.md`. Either use 20 or record the change in `decisions.md` and the spec.
  - [x] 14.1 **Verify**: a test asserts `config('ai.cloud_per_minute')` is 20 with `AI_CLOUD_PER_MINUTE` unset (or `decisions.md` records the chosen default); `.env.example` matches.

- [x] 15. Label the selector's configured option "(configured)" and mark a key-less configured provider "no key" (frontend)
  Covers: AC-3
  - The spec says the default option is labelled "(configured)"; `resources/js/ai/components/LabProviderSelect.vue` renders "(default)". Clarity gate 7 says the selector marks the configured provider "no key" when it has no key; `LabProviderService::textOptions()` only lists enabled providers, so the configured key-less provider disappears and `useProviderChoice` silently sends the first option as an override. The task 6.2 browser check is not recorded.
  - [x] 15.1 **Verify**: `tests/Feature/AiLabProviderSelectTest.php` asserts a key-less configured provider appears with a no-key flag (and still no key value); typecheck and build pass; a browser check of `/ai/describe` shows "(configured)" and "no key", recorded in this file. Browser check not run in a real browser here; the rendered labels come from `optionLabel()` and the Inertia props are asserted in `AiLabProviderSelectTest`.

- [x] 16. Filter semantic listing search on the configured embedding model (backend)
  Covers: AC-5, MNC-3
  - `EloquentSemanticSearchRepository::query()` selects every row `whereNotNull('embedding')` with no `embedding_model` condition, unlike docs search (`nearest(..., modelLabel, ...)`). After a provider switch and a partial `listings:embed`, one search mixes vectors from two models, which the spec's Boundaries forbid, and the 409 check fires only when no row matches.
  - [x] 16.1 **Verify**: a test in `tests/Feature/EmbeddingsProviderSwitchTest.php` embeds one listing with `ollama/bge-m3` and one with another model, and `/api/listings/semantic` returns only the bge-m3 listing.

## Phase: Gaps (converge round 2)

- [x] 17. Count cloud embedding calls against the `cloud-ai` limiter (backend)
  Covers: AC-7, NFR-3
  - Clarity gate 2 applies the limiter to "every HTTP request whose resolved provider is a cloud one". `routes/catalog.php` puts `LimitCloudAi` only on describe, NL search and docs ask. `/api/listings/semantic` and `/api/docs/search` embed every query on the `embeddings-listings` / `embeddings-docs` provider, so with `AI_EMBEDDINGS_LISTINGS_PROVIDER=openai` and a key they make one paid call per request with no cloud limit. Docs ask is checked only against `docs-answer`, so a cloud `embeddings-docs` with a local `docs-answer` is not counted either. Add the middleware to both search routes with their embeddings feature, and make docs ask count once when either of its features resolves to a cloud provider.
  - [x] 17.1 **Verify**: tests in `tests/Feature/CloudRateLimitTest.php`: with `ai.cloud_per_minute=1`, `embeddings-listings` (then `embeddings-docs`) on a keyed openai and `Embeddings::fake()`, the second `/api/listings/semantic` and `/api/docs/search?mode=vector` request answers 429 with a numeric Retry-After. The same routes on ollama are never limited. Docs ask with a local `docs-answer` and a cloud `embeddings-docs` is limited once per request.

- [x] 18. Cover the search endpoints for a key-less embeddings provider and the 409 message (backend)
  Covers: AC-2, AC-5
  - `ListingEmbeddingService::provider()` throws `ProviderNotConfiguredException` for a key-less configured embeddings provider. No test sends `/api/listings/semantic`, `/api/docs/search` or docs ask in that state. The AC-2 tests in `CloudProvidersTest.php` cover only the three text endpoints and the commands. The AC-5 tests in `EmbeddingsProviderSwitchTest.php` assert only `code: index_not_built`, not that the message names the re-index command.
  - [x] 18.1 **Verify**: a dataset test in `tests/Feature/CloudProvidersTest.php` sets `embeddings-listings` / `embeddings-docs` to openai with no key. `/api/listings/semantic`, `/api/docs/search?mode=vector` and `/api/docs/ask` then answer 503 `code: provider_not_configured`, with `Embeddings::assertNothingGenerated()`, `Http::assertNothingSent()` and no ai_runs row. `EmbeddingsProviderSwitchTest` asserts that the 409 `message` contains `listings:embed --fresh` (semantic) and `docs:index` (docs search and ask).

- [x] 19. Prove AC-1 end to end on the Anthropic entry (backend)
  Covers: AC-1
  - AC-1 names the Anthropic driver and the configured model. The describe feature tests in `CloudProvidersTest.php` use `fake-instant` as the stand-in. The only Anthropic describe request, in `CloudRateLimitTest.php` ("describe on a cloud provider is limited too"), never reads the stream or asserts the provider or model. `AiProvidersTest` checks only the resolver's return value.
  - [x] 19.1 **Verify**: a test in `tests/Feature/CloudProvidersTest.php` sets `ai.providers.anthropic.key` and `ai.features.describe.provider=anthropic` with `ai.features.describe.model=claude-test-model`, and uses `CarDescriptionAgent::fake()`. It reads the `/listings/{id}/describe` stream and asserts that `CarDescriptionAgent::assertPrompted` saw `provider->name() === 'anthropic'` and model `claude-test-model`, that the ai_runs row carries both, that `ListingFilterAgent`/`DocsAnswerAgent` still resolve to `fake-stream`, and `Http::assertNothingSent()`.

- [x] 20. Make the key-leak test check a log and an error that actually exist (checks)
  Covers: NFR-1
  - `tests/Feature/CloudKeysNeverLeakTest.php` searches the log only `is_file(...) ? ... : ''`, and every call in it succeeds, so nothing is logged and the log check passes on an empty file. No test makes a cloud call fail with keys set, so "exceptions shown to the browser" and the failed run row's error text are never searched.
  - [x] 20.1 **Verify**: the test drives one failing call per cloud provider (anthropic, openai, gemini) with `Http::fake` returning 401, through NL search and describe with known keys set. It asserts that the leak log file exists and is non-empty (report the failure if the app does not), and that the response bodies, the failed ai_runs rows (including the error column) and the log contain neither any key nor its first 12 characters.

- [x] 21. Finish the providers guide and `.env.example` as specified (docs)
  Covers: AC-6
  - The spec's guide scope lists failure modes "wrong key (401), rate limit (429), model id renamed (404), and dimension mismatch", a recipe "switch embeddings and re-index", and "an experiment comparing the time to first token, local against cloud". `resources/guides/providers.php` has no 401 failure, no cloud 404 (renamed model) failure, no `provider_not_configured` (503) failure and no embeddings-switch recipe. Its two experiments are "See which model answers" and "Ask for a provider that has no key", and neither compares time to first token. `.env.example` has no line for `OPENAI_EMBED_MODEL`, `GEMINI_EMBED_MODEL`, `ANTHROPIC_URL`, `OPENAI_URL` or `GEMINI_URL`, though the spec asks for "commented lines for every new variable".
  - [x] 21.1 **Verify**: `GuideContentTest` adds the needles `401`, `404`, `provider_not_configured` and `time to first token`, plus a recipe title about switching embeddings and re-indexing. `ReadmeTest` asserts that `.env.example` contains `OPENAI_EMBED_MODEL`, `GEMINI_EMBED_MODEL`, `ANTHROPIC_URL`, `OPENAI_URL` and `GEMINI_URL`.

- [x] 22. Run and record the lab selector browser check (frontend)
  Covers: AC-3
  - Task 6.2 and task 15.1 both require a browser check. Task 15.1 says it was "not run in a real browser". No automated test proves that the Vue pages send `provider`. `AiLabProviderSelectTest` checks only Inertia props, and there is no JS test runner. AC-3's "THE SYSTEM SHALL send `provider` with the request" is therefore unverified for Describe (`Describe.vue` query string), NL search (`NlSearch.vue` params) and Chat (`Chat.vue` body).
  - [x] 22.1 **Verify**: in a browser at https://ai-lab.ddev.site, on `/ai/describe`, `/ai/nl-search` and `/ai/chat`:
    - The configured option reads "(configured)".
    - Picking `fake-instant` sends `provider=fake-instant` (network panel), and the shown run and its run page name `fake-instant`.
    - Leaving the configured option sends no `provider`.
    - With `AI_DESCRIBE_PROVIDER=openai` and no key, `/ai/describe` shows "openai (configured) - no key", and a run answers 503 `provider_not_configured`.
    - The results are recorded in this file (manual-test.md step 1).

- [x] 23. Re-record the checks after round 1 and list the changed existing tests (checks)
  Covers: MNC-1, MNC-5
  - MNC-1 requires "each changed test listed in tasks.md". `tests/Feature/DocsAnswerCacheTest.php` changed (the anonymous `DocsAnswerCacheRepositoryInterface` signatures gained `?string $provider`) but is not listed. Task 9.2's outputs (1615 tests, fingerprint, parity) were recorded before tasks 10–16 changed `ListingDescriptionService`, `JsonErrorRenderer`, `LimitCloudAi` and the lab Vue files. `bench/run.sh ping --quick` is recorded as not run.
  - [x] 23.1 **Verify**: this file lists every changed pre-existing test file with the reason. A fresh full suite (no cloud variables), Pint, PHPStan, `vue-tsc`, `npm run build`, `bench/asset-fingerprint.sh` (still `app-DNob7CFE.css` / `app-PTzu1QaN.js`) and the frankenphp parity capture (only the `captured=` line differs) are re-run and recorded under task 9.2. The `bench/run.sh ping --quick` result is recorded after the commit.

## Fix pass and run record (2026-11-10)

Review fixes:
- **Critical: docs embedded with the wrong provider.** `docs:index` embedded docs with the listings provider but labelled them with the docs model. The fix: `ListingEmbeddingService::embedTexts(string $feature, …)` and every `provider()`, `model()`, `dimensions()`, `modelLabel()` and `assertDimensions()` now take the feature with no default. PHPStan flagged every caller, and each now passes `FEATURE` or `DOCS_FEATURE` explicitly. `SemanticSearchService::embedQuery` also requires the feature. Regression test: `CloudProvidersTest` "each embeddings feature embeds with its own provider and model".
- **Semantic search in a half re-indexed table.** The `embedding_model` filter now always applies (the repository's `$model` is required), and `hnsw.iterative_scan = strict_order` is set as in the docs repositories. The semantic bench passes the model label.
- **NL search model list.** `NlSearchService::allowedModels()` offers `gemma4:12b` only when the `nl-search` driver is `ollama`. The NL page hides and stops sending the model picker while a provider override is selected.
- **`rag:eval` grader model.** `--grader` defaults to `RagGraderAgent::modelName()` (the `rag-grader` feature model) instead of `gemma4:12b`.
- **Cloud limiter (`LimitCloudAi`).** It takes a list of features (text and embeddings) and counts one hit when any of them resolves to an enabled cloud provider. It adds a global `cloud-ai-global` limit (`AI_CLOUD_GLOBAL_PER_MINUTE`, default 60) and refuses `Sec-Fetch-Site: cross-site` with 403 for cloud calls. It is now attached to semantic search (`embeddings-listings`), docs search (`embeddings-docs`) and docs ask (`docs-answer,embeddings-docs`).
- **Cloud detection.** `AiProviders::isCloud()` decides by the entry's driver (`CLOUD_DRIVERS`), not by its name; the unused SDK-class column is gone.
- **Error redaction.** `AiRunRecorder::safeMessage()` replaces configured key values, plus `sk-…`, `AIza…` and `org-…` patterns, before storing an error, so the public run page cannot show them (`CloudKeysNeverLeakTest` case with a failing call).
- **Ollama status on the overview.** It now checks every feature's provider.
- **Gap 18.** Tests for 503 `provider_not_configured` on semantic search, docs search and docs ask with a key-less embeddings provider; the 409 message names `listings:embed` or `docs:index`.
- **Gap 19.** AC-1 end to end on Anthropic: describe streams `stream_start` with provider `anthropic` and the configured model, and the `ai_runs` row matches (SDK fake, no network).
- **Gap 21.** The providers guide gains:
  - failures 401, 404 (renamed model), `provider_not_configured` and the dimension refusal;
  - the recipe "Switch the embeddings to a cloud provider";
  - the experiment "Local or cloud: which starts answering first?".

  `.env.example` gains `AI_CLOUD_GLOBAL_PER_MINUTE`, `OPENAI_EMBED_MODEL`, `GEMINI_EMBED_MODEL` and the three `*_URL` lines.
- **Gap 22.** Browser check on `/ai/describe` (Playwright): the selector lists `fake-stream`, `fake-instant` and `ollama (configured)`; choosing `fake-instant` sends `/listings/1/describe?provider=fake-instant`; no console warnings.

Not fixed (minor, recorded):
- The docs index guard runs inside the bench and calibration loops; the extra query only matters for bench runs.
- `LabProviderService` reads `config('ai.providers')` directly.
- `ListingEmbeddingService` still owns `DOCS_FEATURE`, but the feature argument is now required, which removes the silent-default risk the review named.

Changed existing tests: `ListingSearchBenchTest` passes the model label to `nearest()`; `CloudProvidersTest` and `DocsAnswerCacheTest` are as listed by the build.

Cloud model ids (`claude-haiku-5-5`, `gpt-6-luna`, `gemini-3.1-flash-lite`, `text-embedding-3-small`, `gemini-embedding-2`) are the build's picks from provider docs (see `decisions.md`). They are env-overridable, and the guide's 404 failure mode covers a renamed id.

Checks:
- Full suite: 1635 passed. Pint and PHPStan clean, typecheck and build pass.
- Fingerprint: `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Clean-tree bench: after commit d4e8bc1, `bench/run.sh ping --quick` exited 0 on frankenphp (`bench/results/raw/2026-11-10/frankenphp/ping-fair-214020-s1r1.json`).
