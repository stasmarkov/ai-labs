---
id: spec-20261110-2141-cloud-providers
title: Optional cloud providers (Claude, OpenAI, Gemini)
type: feature
size: L
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-10; standing approval)
approved_at: 2026-11-10T19:43:57Z
created: 2026-11-10
stacks: [laravel]
slice: SL-51
brs: [BR-27, BR-90, BR-91]
---

# Optional cloud providers (Claude, OpenAI, Gemini)

## Summary

ai-lab runs on Ollama by default (owner decision 2026-11-10: "Ollama default + Claude, OpenAI, Gemini optional"). This slice adds Anthropic (Claude), OpenAI and Gemini as optional providers through laravel/ai. Each one is enabled only by its API key in `.env`, and the key is never committed, logged or rendered.

Provider and model are chosen per feature by configuration (BR-91). The lab pages get a provider selector that offers only enabled providers, every run row records the provider and model that answered (BR-90), and the in-app providers guide gains a capability table and shows which providers have a key. Tests stay offline.

## Context

- **Problem**: today one setting, `ai.default`, picks the text provider for every agent, through `UsesActiveProvider`, `DocsAnswerAgent:46`, `ListingFilterAgent:58`, `RagGraderAgent:49`, `NlSearchService:47` and `NlEvalCommand:109`. A second setting, `ai.default_for_embeddings`, picks the embeddings provider. `config/ai.php` has only `fake-stream`, `fake-instant` and `ollama`. A learner cannot compare a local model with Claude, OpenAI or Gemini without editing code.
- **User impact**: a learner adds one key to `.env` and sees the same feature answer from a cloud model, with latency, tokens and the model recorded side by side in `ai_runs`.

## Scope

### In scope

- **Provider entries** in `config/ai.php`: `anthropic`, `openai` and `gemini`, using the SDK drivers. Each entry has:
  - `key` from `ANTHROPIC_API_KEY` / `OPENAI_API_KEY` / `GEMINI_API_KEY`;
  - `max_tokens` (default 400, as for Ollama);
  - `timeout` (default 60 s);
  - `models.text.default`, overridable by `ANTHROPIC_MODEL` / `OPENAI_MODEL` / `GEMINI_MODEL`;
  - for OpenAI and Gemini, `models.embeddings` with `dimensions => 1024`, overridable by `OPENAI_EMBED_MODEL` / `GEMINI_EMBED_MODEL`.

  The default text model is each provider's cheapest current general model, and the default embedding model one that can return 1024 dimensions. The build picks the ids from the provider docs (context7 or the SDK docs) and records the source in `decisions.md`. Anthropic has no embeddings entry.
- **Enabled providers**: `AiProviders::enabled()` returns the providers that can run here. The fakes are always enabled (local only), `ollama` is always enabled (its reachability is shown separately), and a cloud provider is enabled when its key is non-empty. Keys are never exposed; only booleans leave the class.
- **Per-feature selection** (`config/ai.php` → `features`):
  - The feature keys are `describe`, `nl-search`, `docs-answer`, `rag-grader`, `embeddings-listings` and `embeddings-docs`.
  - Each has `provider` and `model`, read from `AI_<FEATURE>_PROVIDER` / `AI_<FEATURE>_MODEL`. For text features an empty value falls back to `ai.default` and that provider's default text model; for embeddings it falls back to `ai.default_for_embeddings`.
  - One resolver, `App\Ai\AiProviders::forFeature(string $feature, ?string $override = null): ProviderChoice` (provider, model, driver), replaces every direct read of `ai.default` and `ai.default_for_embeddings` in the files listed above, in `ListingEmbeddingService`, `RecordEmbeddingsRun`, `LabOverviewService` and `GuideFacts`.
  - `UsesActiveProvider` reads the entry of the agent's resolved provider, not `ai.default`.
- **Lab selector**: the Describe, NL search and Chat pages show a provider dropdown listing the enabled text providers. The default option is the configured one and is labelled "(configured)".
  - The choice goes to the existing endpoints as an optional `provider` parameter, validated `Rule::in(AiProviders::enabledTextProviders())`.
  - Absent means configured.
  - A provider that is not enabled gets 422 `provider_not_enabled`.
  - No model parameter: the model comes from configuration (BR-91).
  - The semantic and docs search pages show the embeddings provider as text only, not as a choice (see the embeddings point).
- **Embeddings switch**: changing `AI_EMBEDDINGS_LISTINGS_PROVIDER` or `AI_EMBEDDINGS_DOCS_PROVIDER` takes effect only after a re-index (`listings:embed`, `docs:index`), because rows store `embedding_model` and search filters on it.
  - When no row matches the configured embedding model, semantic and docs search answer 409 `index_not_built`. The message names the command to run, instead of returning an empty list.
  - A provider whose dimensions are not 1024 is rejected at boot of the embeddings command with a clear error.
- **Run log**: `ai_runs.provider` and `model` hold the resolved provider and model, including a lab override. No schema change.
- **Cache safety**: the docs answer cache key already includes `answer_model`. The build verifies that provider and model changes miss the cache, and adds `provider` to the lookup if the model ids could collide across providers.
- **Rate limit**: a request that names a cloud provider passes the existing per-route limiter plus a shared `cloud-ai` limiter (`AI_CLOUD_PER_MINUTE`, default 20 per IP), so a public demo cannot spend a key quickly.
- **In-app providers guide** (`resources/guides/providers.php`, kind `foundation`):
  - A capability table as a new guide section `capabilities`, rendered in the How it works tab. Columns: provider, text, streaming, structured output, embeddings (dims), local or cloud, key variable name. It is built from config plus a static capability map in `AiProviders`, so it never drifts.
  - The live facts gain one line per cloud provider: `key set` / `no key` (never the key).
  - The recipes: add a key, pick a provider per feature, switch embeddings and re-index.
  - Failure modes: wrong key (401), rate limit (429), model id renamed (404), and dimension mismatch.
  - An experiment comparing the time to first token, local against cloud.
- **Learning guide §16** (AI map): the new files, the resolver flow, and a recipe "add a provider". The section 16 map test stays green.
- **`.env.example`**: commented lines for every new variable. `README` gets a short "Optional cloud providers" paragraph.

### Out of scope

- Failover between providers (SL-28), cost dashboards, and prompt caching for cloud providers.
- Tool calling or agents on cloud models beyond what the existing agents do.
- A per-request embeddings provider.
- Storing keys anywhere but `.env`.

## Must NOT change

- **MNC-1**: With no cloud key set and no new variable set, every feature uses exactly today's provider and model: the fake by default, Ollama when `AI_PROVIDER=ollama`. All existing tests pass unchanged except those asserting the removed direct config reads.
- **MNC-2**: Existing endpoint contracts are unchanged for requests without `provider`: same status codes, JSON shapes and SSE event types.
- **MNC-3**: Semantic and docs search results are unchanged while the embeddings provider stays Ollama `bge-m3`.
- **MNC-4**: The test suite makes no network call. `Http::preventStrayRequests()` is on in the base TestCase for this suite's new tests, and cloud providers are exercised only through the SDK fakes or `Http::fake`.
- **MNC-5**: The main asset fingerprint and the parity of non-lab routes are unchanged.

## Acceptance criteria

- **AC-1** (BR-91): WHEN `AI_DESCRIBE_PROVIDER=anthropic` and `ANTHROPIC_API_KEY` is set THE SYSTEM SHALL run the describe agent on the Anthropic driver with the configured model, while the other features keep their own configured providers.
- **AC-2**: IF a feature is configured with a cloud provider whose key is empty THEN THE SYSTEM SHALL fail that request with a clear error (`provider_not_enabled`, HTTP 422 for API requests, a console error for commands) and SHALL NOT call any provider.
- **AC-3**: WHEN a learner picks an enabled provider in the lab selector THE SYSTEM SHALL send `provider` with the request, and the answer and its `ai_runs` row SHALL carry that provider and its configured model. IF the provider is not enabled THEN THE SYSTEM SHALL answer 422 `provider_not_enabled`.
- **AC-4** (BR-90): WHEN any AI call completes, fails or stops THE SYSTEM SHALL record the resolved provider and model in `ai_runs`.
- **AC-5**: WHEN the configured embedding model has no indexed rows THE SYSTEM SHALL answer semantic and docs search with 409 `index_not_built`, naming the re-index command. WHEN an embeddings provider's dimensions are not 1024 THEN `listings:embed` and `docs:index` SHALL exit non-zero before calling it.
- **AC-6**: WHEN `/ai/guides/providers` renders THE SYSTEM SHALL show the capability table for fake, Ollama, Anthropic, OpenAI and Gemini, and per cloud provider whether a key is set, without ever including a key value in props or HTML.
- **AC-7**: WHEN more than `AI_CLOUD_PER_MINUTE` requests per minute from one IP have a resolved provider that is a cloud provider THE SYSTEM SHALL answer 429 to the extra ones, while requests on local providers keep their current limits.
- **AC-8**: WHEN a file is added under `app/Ai/` THE SYSTEM SHALL fail the section 16 map test until `docs/learning-guide.md` lists it, so the new resolver files are listed.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Secrets | Key values never appear in Inertia props, HTML, logs, `ai_runs`, exceptions shown to the browser or guide output. A test sets known fake keys and searches every lab page, the guide and the log file for them. | `tests/Feature/CloudKeysNeverLeakTest.php` |
| **NFR-2** | Offline tests | No test opens a socket. The new tests run with `Http::preventStrayRequests()`, and a test that would call a provider fails loudly. | `tests/Feature/CloudProvidersTest.php` |
| **NFR-3** | Cost safety | The cloud limiter (AC-7), and `max_tokens` applied to cloud entries. | `tests/Feature/CloudRateLimitTest.php` |

## Rollout

- **Flag**: none. Cloud providers are off until a key is set.
- **Deploy order**: one deploy. A learner adds keys to `.env` and restarts.
- **Rollback**: remove the keys, or revert. There is no migration.

## Boundaries

- **Always**:
  - Go through `AiProviders` for every provider read.
  - Use SDK fakes in tests.
  - Keep keys in `.env` only.
  - Update the providers guide and §16.
- **Ask first**:
  - A new Composer package (the SDK already ships the drivers).
  - A schema change.
  - Raising default `max_tokens` for cloud providers.
- **Never**:
  - Commit or print a key.
  - Call a cloud API in tests or in the build.
  - Add a per-request model parameter.
  - Mix vectors from different embedding models in one search.

## Technical context

- **Related code**:
  - `config/ai.php`;
  - `app/Ai/UsesActiveProvider.php`, `DocsAnswerAgent.php`, `ListingFilterAgent.php`, `RagGraderAgent.php`, `CarDescriptionAgent.php`, `RecordEmbeddingsRun.php`;
  - `app/Services/NlSearchService.php`, `ListingEmbeddingService.php`, `LabOverviewService.php`, `DocsAnswerService.php`;
  - `app/Guides/GuideFacts.php`;
  - `app/Http/Requests/NlSearchRequest.php`, `DocsAskRequest.php`, and the describe request or controller;
  - `routes/catalog.php` (throttles);
  - `app/Providers/AppServiceProvider.php` (limiters);
  - `resources/guides/providers.php`;
  - `vendor/laravel/ai/src/Providers/{Anthropic,OpenAi,Gemini}Provider.php`.
- **Already implemented**:
  - `ai_runs.provider` and `model` columns.
  - The `embedding_model` column on `listing_documents` and `docs_chunks`, with search filtering on it.
  - The docs cache keyed by answer and embedding model.
  - Per-route limiters.
  - The guide engine (SL-48).

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **Model ids**: chosen at build time from the providers' current docs, cheapest general text model and an embeddings model with 1024 dimensions, env-overridable. Hard-coding them from memory is not allowed.
- **The selector picks the provider, never the model**, so BR-91 holds: the model of every provider comes from configuration.
- **Embeddings are configuration only, plus a re-index.** A per-request embeddings switch would query an index built by another model.

## Clarity gate answers (binding, self-decided 2026-11-10)

1. **Pages and features.**
   - Describe page → `describe` (`/listings/{id}/describe`, `CarDescriptionAgent`).
   - NL search page → `nl-search` (`/api/listings/nl`, `ListingFilterAgent`; `NlEvalCommand` keeps its own `--model` option and reads `nl-search` for the provider).
   - Chat page → `docs-answer` (the docs ask endpoint, `DocsAnswerAgent`).
   - `RagGraderAgent` → `rag-grader`.

   The selector is on those three pages, and `provider` is accepted by those three endpoints.
2. **The cloud limiter** applies to every HTTP request whose resolved provider is a cloud one, whether chosen by configuration or by `provider`. One hit per request; a stream counts once. Console commands are not limited. AC-7 reads "whose resolved provider is a cloud provider".
3. **Models.**
   - An override whose provider differs from the feature's configured provider uses that provider's `models.text.default`.
   - `AI_<FEATURE>_MODEL` applies only while the feature runs on its configured provider, whether set explicitly or by fallback.
   - A model that does not exist at the provider is not validated up front; it fails at call time and is a guide failure mode (404).
   - The embeddings fallback model is the provider's `models.embeddings.default`.
4. **Env names.** The feature key is uppercased with hyphens turned into underscores: `AI_NL_SEARCH_PROVIDER`, `AI_DOCS_ANSWER_MODEL`, `AI_EMBEDDINGS_LISTINGS_PROVIDER`, `AI_RAG_GRADER_PROVIDER`.
5. **The 409 `index_not_built`** is sent only when the table has embedded rows and none of them carry the configured embedding model, that is, the index was built by another model. An empty index keeps today's response.

   The routes are `/api/listings/semantic`, the docs search endpoint and the docs ask endpoint. For docs ask, the check runs before the SSE stream opens.
6. **The dimension check** reads the provider's `models.embeddings.dimensions`; Ollama's entry already has 1024. It runs at the start of `listings:embed` and `docs:index`, before the first batch. A missing or different value exits 1.
7. **Misconfiguration.**
   - **Client error:** a `provider` override that is not enabled is a client error: 422 `provider_not_enabled`.
   - **Server error:** a feature configured with a key-less cloud provider is a server misconfiguration: 503 `provider_not_configured`, raised before any stream opens. Commands exit 1 with the same message. Lab pages still render, and the selector marks the configured provider "no key".
   - **Test:** AC-2 is verified by a feature test (HTTP status, and no SDK call through the fake), not only a unit test.
8. **Fakes** (`fake-stream`, `fake-instant`) count as local. They appear in the selector only when `app()->environment('local', 'testing')`. Manual step 1 expects the local providers: the fakes and `ollama`.
9. **`Http::preventStrayRequests()`** goes in the `beforeEach` of each new test file, not in the base TestCase. The build checks whether the laravel/ai drivers use Laravel's HTTP client. If they do, the guard catches stray calls. Either way, the tests drive providers through the SDK fakes, so no gateway is reached.
10. **Remaining terms.**
    - **Cache:** the docs answer cache lookup adds a `provider` condition (the column exists). Entries stored by the current provider still match, and a test shows a different provider misses.
    - **Tests MNC-1 may change:** only tests that assert a direct `config('ai.default')` / `ai.default_for_embeddings` read. The build lists each changed test in tasks.md.
    - **Log:** `storage/logs/laravel.log`.
    - **Base URL:** https://ai-lab.ddev.site (`CLAUDE.md` was updated in SL-49).
    - **Capability table:** "text", "embeddings" and "structured output" come from the SDK provider classes' interfaces where they exist. "local or cloud" and the key variable come from `AiProviders`' map. The build records which column comes from where in `decisions.md`.

## Open questions

- [x] Clarity gate (10 items) answered above.
- [x] Accepted assumption: BR-27, BR-90 and BR-91 come from a draft brief applied in every slice (standing approval).
