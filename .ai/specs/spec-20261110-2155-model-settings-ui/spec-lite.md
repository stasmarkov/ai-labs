---
id: spec-20261110-2155-model-settings-ui
title: Switch providers and models from the UI (local settings page)
type: feature
size: M
priority: P1
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-10; standing approval)
approved_at: 2026-11-10T19:57:19Z
created: 2026-11-10
stacks: [laravel]
slice: SL-52
brs: [BR-27, BR-90, BR-91]
---

# Switch providers and models from the UI (local settings page)

## Summary

Owner request 2026-11-10 (Stanislav Markov): "be able to switch the llm model or other models via ui. No login needed, it's only for local purposes".

A settings page at `/ai/settings` lets a learner pick, per AI feature, the provider and the model:
- the text model for describe, NL search, chat answers and the RAG grader;
- the embedding model for listings and for docs.

The choice is saved and takes effect on the next request, with no `.env` edit and no restart, also under worker mode and Octane. The page exists only on a local install. It has no login and is never served in production.

## Context

- **Problem**: after SL-51, provider and model are picked per feature by `.env` variables (`AI_<FEATURE>_PROVIDER` / `_MODEL`), read once at boot. Changing a model means editing `.env` and restarting, and in worker mode a long-lived worker keeps the old config until it restarts. The lab selector (SL-51) changes only the provider of one request.
- **User impact**: a learner compares `qwen3.5:9b` with `gemma4:12b`, or Ollama with Claude, by choosing from a list and running the same page again. The `ai_runs` rows show the effect.
- **Rule change**: BR-91 ("chosen by configuration only") becomes: "Provider and model are chosen by configuration, and on a local install also by the settings page, which overrides configuration per feature. Switching never needs a code change." The brief is revised in this slice and cites the owner request.

## Scope

### In scope

- **Storage**: a table `ai_settings` (`feature` primary key, `provider`, `model`, `updated_at`) with a migration, plus a model and a repository.
  - Only rows that differ from configuration are stored.
  - "Reset to configuration" deletes the feature's row.
- **Precedence**, inside the SL-51 resolver `AiProviders::forFeature()`, highest first:
  1. the per-request `provider` from the lab selector (SL-51), with that provider's model as SL-51 defines it;
  2. the saved setting for the feature;
  3. the `AI_<FEATURE>_*` configuration;
  4. `ai.default` / `ai.default_for_embeddings`.

  The resolver reads settings per request through a cache key `ai-settings:v1`. Every save and reset forgets that key, so worker mode and Octane see a change on the next request (no boot-time config mutation).
- **Settings page** `GET /ai/settings` (Inertia `Lab/Settings`, lab design system). One row per feature showing:
  - the effective provider and model, with the source label `request` / `settings` / `configuration` / `default`;
  - a provider select listing enabled providers only (SL-51 `AiProviders::enabled()`, text or embeddings as fits the feature);
  - a model select:
    - for **Ollama**, the models installed on the Ollama server (`/api/tags` through `OllamaServerInfo`, cached 10 s), filtered by kind: embedding models for embedding features, the others for text;
    - for **cloud providers**, the ids in the provider's config list `models.text.available` / `models.embeddings.available` (added to `config/ai.php`, env-overridable as a comma list) plus a free "other model id" field;
    - for **fakes**, their single model;
  - Save and "Reset to configuration" buttons.
- **Saving** goes to `PUT /ai/settings/{feature}` (validated: feature key, provider enabled, model in the allowed set or matching `^[A-Za-z0-9._:/-]{1,120}$` for a cloud "other" id) and `DELETE /ai/settings/{feature}` for a reset.
  - Both redirect back with a flash message naming what changed.
  - CSRF protection as for every web route.
- **Embeddings features**: saving a different embedding model shows a warning before saving ("search answers 409 until you re-index"), plus the exact command after saving (`ddev artisan listings:embed` or `docs:index`).
  - The page shows per embeddings feature how many rows are indexed for the effective model, which is the same count the 409 check uses.
  - No re-index button: indexing 50 000 listings is a long job, so the command is the way.
- **Ollama model not installed**: when a saved Ollama model is no longer in `/api/tags`, the page marks it "not installed" and shows `ollama pull <model>`.
  - The resolver still uses it: the call fails with the provider's error, which the guide's failure mode covers.
  - When Ollama is unreachable, the model select falls back to the configured and saved ids, with a note.
- **Local only**: routes are registered inside a group whose middleware `EnsureLocalSettings` answers 404 unless `app()->environment('local')` and `config('ai.settings_ui')` is true. `AI_SETTINGS_UI` defaults to true in `.env.example`; in config it defaults to `env('APP_ENV') === 'local'`. There is no login. The top navigation shows "Settings" only when the page is available.
- **Run log and cache**:
  - `ai_runs` keeps recording the provider and model actually used (BR-90).
  - The docs answer cache already keys on the provider and answer model (SL-51), so a switch never serves an answer made by another model.
  - The `ai_runs` row gains nothing new; the settings source is not logged.
- **In-app guides**:
  - The providers guide gets a recipe "Switch a model from the Settings page", with the precedence list as a step, and a fact line per feature showing its effective provider, model and source.
  - The worker-mode lesson goes into How it works: why settings are read per request rather than written into config.
- **Learning guide §16**: the new files, plus the precedence flow.
- **Brief**: BR-91 is revised as above, with a source line. The backlog gets SL-52 and an intake row.

### Out of scope

- Pulling or deleting Ollama models from the UI.
- Editing API keys in the UI (keys stay in `.env`).
- Per-user settings and any login or authorization.
- Prompt version switching (a later slice may add it to the same page).
- A re-index button or job progress UI.

## Must NOT change

- **MNC-1**: With no saved settings, every feature resolves exactly as after SL-51 (configuration, then defaults); every SL-51 test passes unchanged.
- **MNC-2**: The SL-51 lab selector keeps working and wins over a saved setting for its request.
- **MNC-3**: Endpoint contracts of the AI routes are unchanged.
- **MNC-4**: In a non-local environment, or with `AI_SETTINGS_UI=false`, `/ai/settings` and its write routes answer 404, and the resolver ignores stored settings, so a copied database cannot change a production model.
- **MNC-5**: The test suite stays offline. Ollama's `/api/tags` is faked with `Http::fake`.
- **MNC-6**: The main asset fingerprint and the parity of non-lab routes are unchanged.

## Acceptance criteria

- **AC-1** (BR-91): WHEN a learner saves provider `ollama` and model `gemma4:12b` for `describe` on the settings page THE SYSTEM SHALL run the next describe request on `gemma4:12b`, without a restart, on the classic, worker and Octane modes alike, and its `ai_runs` row SHALL show that model.
- **AC-2**: WHEN the settings page renders THE SYSTEM SHALL show, per feature, the effective provider, model and source (`request` is never shown here; `settings`, `configuration` or `default` is), offering only enabled providers and, for Ollama, only installed models of the right kind.
- **AC-3**: WHEN a learner clicks "Reset to configuration" for a feature THE SYSTEM SHALL delete its saved setting, and the feature SHALL resolve from configuration on the next request.
- **AC-4**: IF a save names a provider that is not enabled, a feature that does not exist, or a model outside the allowed set THEN THE SYSTEM SHALL reject it with a validation error on the page and store nothing.
- **AC-5**: WHEN an embeddings feature is switched to another embedding model THE SYSTEM SHALL warn before saving, show the re-index command after saving, and show the count of rows indexed for the effective model.
- **AC-6**: WHILE the app is not in the `local` environment, or `AI_SETTINGS_UI` is false, THE SYSTEM SHALL answer 404 on every settings route and ignore stored settings in the resolver.
- **AC-7**: WHEN a lab request carries `provider` THE SYSTEM SHALL use it over a saved setting for that request only.
- **AC-8**: WHEN the providers guide renders THE SYSTEM SHALL list, per feature, the effective provider, model and source, and a recipe for switching from the Settings page.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Worker safety | A saved change is seen by the next request in a long-lived worker: no `config()` writes, settings read per request through the cache key that save and reset forget. | `tests/Feature/AiSettingsResolverTest.php` plus manual step 2 on frankenphp-worker |
| **NFR-2** | Exposure | No settings route outside local; no key values on the page (only provider names and booleans). | `tests/Feature/AiSettingsPageTest.php` |
| **NFR-3** | Performance | The resolver adds at most one cache read per request; the settings page renders under 200 ms p95 warm with Ollama reachable (tags cached 10 s). | manual step 4 |

## Rollout

- **Flag**: `AI_SETTINGS_UI` (local only by default).
- **Deploy order**: migration, then code.
- **Rollback**: revert. The table is new and dropped by `migrate:rollback`.

## Boundaries

- **Always**:
  - Go through the SL-51 resolver.
  - Read settings per request.
  - Fake Ollama in tests.
  - Update the providers guide, §16, the brief and the backlog.
- **Ask first**:
  - Any new Composer or npm dependency.
  - Writing `.env` from the app (not planned).
  - Exposing the page outside local.
- **Never**:
  - Store or show API keys.
  - Write to `config()` at runtime to apply a setting.
  - Call a real provider in tests.
  - Add a login.

## Technical context

- **Related code** (after SL-51):
  - `app/Ai/AiProviders.php` (resolver and enabled providers);
  - `app/Ai/OllamaServerInfo.php` (add `models()` from `/api/tags`);
  - `config/ai.php` (`features`, the provider entries, the new `available` lists and `settings_ui`);
  - `app/Services/LabOverviewService.php` (navigation);
  - `resources/guides/providers.php`;
  - `app/Guides/GuideFacts.php`;
  - `routes/web.php` (the `/ai` group);
  - the embeddings repositories (counts per `embedding_model`).
- **Already implemented**: `ai_runs` provider and model; the `embedding_model` column and the 409 `index_not_built` (SL-51); the lab design system and the components `LabSegmented`, `LabButton`, `LabTag` and `LabTextInput`.
- **Depends on**: SL-51 shipped. The build starts after its commit.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **Saved in the database, not in `.env`.** Writing `.env` from a web request is fragile, does not reach running workers, and mixes secrets with UI state. A table read per request through a forgettable cache key works the same under classic, worker and Octane.
- **Local only, no login, by environment.** This follows the owner's "only for local purposes". The resolver also ignores stored settings outside local (MNC-4), so the table cannot become a hidden production switch.
- **The SL-51 per-request selector stays provider-only**, and the settings page is where models are chosen. BR-91 is revised, not dropped: configuration stays the base.

## Clarity gate answers (binding, self-decided 2026-11-10)

1. **Lab override and saved model.** A request `provider` replaces only the provider. The model then comes from the highest level whose provider equals it: the saved setting, then the configuration, then that provider's `models.*.default`. So a request naming the saved provider uses the saved model.
2. **Feature keys** are the SL-51 keys, exactly: `describe`, `nl-search`, `docs-answer`, `rag-grader`, `embeddings-listings`, `embeddings-docs`. They are used as the table key, the route parameter (`whereIn`) and the validation rule.
3. **Ollama model kind** comes from `/api/show` `capabilities`:
   - `embedding` → an embedding model;
   - `completion` → a text model;
   - a model with neither is not offered.

   Cached per model name for 10 minutes; the tag list is cached for 10 s.
4. **Ollama unreachable.** A save for Ollama is accepted only for ids in the fallback list (configured plus saved). Anything else is a validation error: "Ollama is not reachable; pick a configured model or try again". There is no free id for Ollama.
5. **Saved provider no longer enabled.** For example, its key was removed. The resolver skips the setting and falls through to configuration or default. The page shows the row with a warning ("saved: anthropic · no key, using configuration") and the reset button. There is no automatic delete.
6. **A save equal to the effective configuration** (provider and model both) deletes the feature's row and flashes "matches configuration; nothing saved". A row stays even if configuration later changes to match it; the learner resets it explicitly.
7. **Source labels per value.**
   - The row's source is the level that supplied the provider.
   - When the model comes from a different level, the model shows its own label, for example `qwen3.5:9b (provider default)`.
   - Labels: `settings`, `configuration`, `provider default`, `default`.
8. **Cache.**
   - One key, `ai-settings:v1`, holds all rows as an array, with a TTL of 1 hour, forgotten on every save and reset.
   - A miss is one query.
   - The local and flag check runs before the cache read, so production reads nothing.

   NFR-3 means one cache read per request, plus one query on a miss.
9. **The local gate** is `app()->environment('local') && config('ai.settings_ui')`, used by both the middleware and the resolver.
   - `config('ai.settings_ui')` is `env('AI_SETTINGS_UI', true)` (bool), so `.env.example`'s `AI_SETTINGS_UI=true` and the default agree, and the environment check keeps production closed.
   - Tests switch with `$this->app['env'] = 'local'` in `beforeEach` (or a helper), and assert the closed case with `testing` and `production`.
10. **Embeddings warning.**
    - When the selected embedding model differs from the effective one, the row shows inline text, and the save requires a checkbox "I will re-index" (`reindex_acknowledged`, validated `accepted` only in that case).
    - The indexed-row count matches the stored `embedding_model` value.
    - Each row posts its own form. Errors use the Inertia error bag `settings-{feature}`, and the flash carries the feature key, so messages show on that row only.

## Open questions

- [x] Clarity gate (10 items) answered above.
- [x] Accepted assumption: BR-27, BR-90 and BR-91 come from a draft brief applied in every slice (standing approval); this slice revises BR-91 at the owner's request.
