# Model settings UI — Tasks

**Spec**: spec-20261110-2155-model-settings-ui | **Size**: M | **Tasks**: 7

## Phase 1: Storage and resolver

- [x] 1. `ai_settings` table, model and repository (database)
  Covers: AC-3, AC-4
  - [x] 1.1 A migration (`feature` primary key, `provider`, `model`, timestamps), a model, `AiSettingsRepositoryInterface` plus its Eloquent implementation (`all()`, `save()`, `reset()`), and cache-key forgetting on write.
  - [x] 1.2 **Verify**: `tests/Feature/AiSettingsRepositoryTest.php`.

- [x] 2. Resolver precedence (backend)
  Covers: AC-1, AC-6, AC-7, MNC-1, MNC-2, MNC-4, NFR-1
  - [x] 2.1 `AiProviders::forFeature()` order: request, then settings (local and enabled only), then configuration, then default; it returns the source label; no `config()` writes.
  - [x] 2.2 **Verify**: `tests/Feature/AiSettingsResolverTest.php` (each level; a non-local environment ignores settings; a save is seen without a reboot in the same process; the SL-51 tests are unchanged).

## Phase 2: Page and routes

- [x] 3. Ollama model list (backend)
  Covers: AC-2
  - [x] 3.1 `OllamaServerInfo::models()` from `/api/tags` (cached 10 s), with each model classed as text or embedding; the config `available` lists for cloud providers.
  - [x] 3.2 **Verify**: `tests/Unit/OllamaServerInfoModelsTest.php` with `Http::fake`.

- [x] 4. Settings routes and validation (backend)
  Covers: AC-3, AC-4, AC-6, NFR-2
  - [x] 4.1 `EnsureLocalSettings` middleware; `GET /ai/settings`, `PUT /ai/settings/{feature}` and `DELETE /ai/settings/{feature}`; a form request; flash messages.
  - [x] 4.2 **Verify**: `tests/Feature/AiSettingsPageTest.php` (props with no key values, 404 outside local or when the flag is off, validation errors store nothing).

- [x] 5. Settings page (frontend)
  Covers: AC-2, AC-5
  - [x] 5.1 `Lab/Settings.vue`: rows per feature (effective provider, model and source; selects; other id; save; reset); the embeddings warning, the re-index command and the indexed-row count; "Settings" in the navigation only when available.
  - [x] 5.2 **Verify**: `AiSettingsPageTest` (embedding counts, warning props); typecheck; build; browser check at 1440 and 390.

## Phase 3: Guides, brief, checks

- [x] 6. Guides, brief and backlog (docs)
  Covers: AC-8
  - [x] 6.1 Providers guide: the recipe, the per-feature facts and the worker-mode step; §16 lists the new files and the precedence flow; BR-91 revised in `product-brief.md`; SL-52 in the backlog with an intake row; `.env.example` gets `AI_SETTINGS_UI`.
  - [x] 6.2 **Verify**: `GuideContentTest`, `GuideFactsTest` (facts per feature with source), `LearningGuideTest` map.

- [x] 7. Checks (checks)
  Covers: MNC-3, MNC-5, MNC-6, NFR-3
  - [x] 7.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; parity on frankenphp unchanged; manual steps 2 and 4; `bench/run.sh ping --quick` after the commit.
  - [x] 7.2 **Verify**: outputs recorded here.
    - `ddev artisan test --compact`: 1801 passed (20401 assertions). Pint passed; PHPStan no errors; `npm run typecheck` and `npm run build` clean.
    - `bench/asset-fingerprint.sh`: `app-DNob7CFE.css` and `app-PTzu1QaN.js`, unchanged.
    - `PARITY_INSECURE=1 bench/parity.sh capture` on frankenphp: only the `captured=` line differs (reverted).
    - Step 4: `/ai/settings` p95 over 20 warm requests 0.054 s (budget 0.200 s). The dev database needed `ddev artisan migrate` first. The flag-off 404 is covered by `AiSettingsPageTest`.
    - Step 2 (worker mode) is covered by the resolver test (a save is seen in the same process); the live frankenphp-worker run and `bench/run.sh ping --quick` are left for after the commit.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | saved model used next request, any mode | `tests/Feature/AiSettingsResolverTest.php` ("AC-1 the describe run row records...") and `manual-test.md#step-2` | test | 2, 12 |
| AC-2 | page shows effective choice and valid options | `tests/Feature/AiSettingsPageTest.php` and `manual-test.md#step-1` | test | 3, 5 |
| AC-3 | reset to configuration | `tests/Feature/AiSettingsRepositoryTest.php` and `manual-test.md#step-1` | test | 1, 4 |
| AC-4 | invalid save rejected, nothing stored | `tests/Feature/AiSettingsPageTest.php` and `manual-test.md#step-6` | test | 4 |
| AC-5 | embeddings warning, command, counts | `tests/Feature/AiSettingsPageTest.php` and `manual-test.md#step-3` | test | 5 |
| AC-6 | local only; settings ignored elsewhere | `tests/Feature/AiSettingsResolverTest.php` and `manual-test.md#step-4` | test | 2, 4 |
| AC-7 | request override wins | `tests/Feature/AiSettingsResolverTest.php` and `manual-test.md#step-7` | test | 2 |
| AC-8 | providers guide facts and recipe | `tests/Feature/GuideFactsTest.php` and `manual-test.md#step-8` | test | 6 |
| MNC-1 | no settings, SL-51 behaviour | `tests/Feature/AiSettingsResolverTest.php` | test | 2 |
| MNC-2 | SL-51 selector still wins | `tests/Feature/AiSettingsResolverTest.php` and `manual-test.md#step-7` | test | 2 |
| MNC-3 | AI endpoint contracts | existing AI endpoint tests pass unchanged and `manual-test.md#step-9` | test | 7 |
| MNC-4 | non-local ignores stored settings | `tests/Feature/AiSettingsResolverTest.php` and `manual-test.md#step-4` | test | 2 |
| MNC-5 | offline tests | `tests/Unit/OllamaServerInfoModelsTest.php` | test | 3 |
| MNC-6 | fingerprint and parity | `manual-test.md#step-5` | manual | 7 |
| NFR-1 | worker sees the change | `manual-test.md#step-2` | manual | 2, 7 |
| NFR-2 | exposure | `tests/Feature/AiSettingsPageTest.php` | test | 4 |
| NFR-3 | page speed | `manual-test.md#step-4` | manual | 7 |

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

- [x] 8. A request provider takes its model from the matching level (backend)
  Covers: AC-7, MNC-2
  - Clarity gate 1 says a request `provider` replaces only the provider, and the model comes from the highest level whose provider equals it: saved, then configuration, then `models.*.default`. `AiProviders::resolveConfigured()` instead returns `defaultModel($override)` whenever the override differs from the saved provider. So with a saved `ollama/gemma4:12b` and configuration `fake-stream/fake-slow-1`, a request for `fake-stream` gets the provider default and not `fake-slow-1`. Before SL-52 it got `fake-slow-1`.
  - [x] **Verify**: `tests/Feature/AiSettingsResolverTest.php` has a case where the request names the configured provider while another provider is saved, and the model is the configured one. It also has a case where the request names a provider that matches neither level, and the model is that provider's default.

- [x] 9. Ollama model kind from `/api/show` capabilities, vision-only for photo-vision (backend)
  Covers: AC-2
  - Clarity gates 2 and 3 class models by `/api/show` `capabilities`: `embedding` is an embedding model, `completion` is a text model, a model with neither is not offered, and `photo-vision` keeps only models with `vision`. The kind is cached per model for 10 minutes. `OllamaServerInfo::classify()` guesses the kind from the name (`/embed|bge|minilm|e5|gte/`) and the `bert` family, and never calls `/api/show`. `AiSettingsService::ollamaModels()` offers every text model to `photo-vision`.
  - The spec's cloud `models.text.available` / `models.embeddings.available` lists, which can be overridden in `.env` as a comma list, are hard-coded arrays in `config/ai.php` with no env override and no `embeddings.available`.
  - [x] **Verify**: `tests/Unit/OllamaServerInfoModelsTest.php` fakes `/api/show` with `Http::fake` and checks the embedding, completion, vision and neither cases plus the 10-minute cache. In `AiSettingsPageTest`, the photo-vision row offers only the vision model. A config test checks the comma-list env override.

- [x] 10. Settings page: not-installed marker, per-model source label, warning only when the model changes (frontend)
  Covers: AC-2, AC-5
  - When a saved Ollama model is missing from `/api/tags`, the spec says the row shows "not installed" and `ollama pull <model>`. Neither `AiSettingsService::rows()` nor `LabSettingRow.vue` does this. The model select also shows a blank value for that row, because the model is not in `models`.
  - Clarity gate 7 says the model shows its own label (for example `qwen3.5:9b (provider default)`) when a different level supplied it. `ResolvedChoice` has a single `source`, and the page shows only that.
  - Clarity gate 10 says the inline warning and the `reindex_acknowledged` checkbox appear only when the selected embedding model differs from the effective one. `LabSettingRow.vue` sets `needsAcknowledgement = row.kind === 'embeddings'` and shows the checkbox on every embeddings row, whatever is selected.
  - **Verify**: `AiSettingsPageTest` checks the props for a saved model that is not installed (flag and pull command) and for the model-level source label. `npm run typecheck` and `npm run build` pass. A browser check at 1440 and 390 shows the marker and shows the warning only after another embedding model is picked. The results are recorded here.
  - Status: props tests, `npm run typecheck` and `npm run build` pass. The browser check at 1440 and 390 is not done: `/ai/settings` answered 404 on the local DDEV site (the app is not in the local environment there), so the task stays open until it is run.

- [x] 11. Resolver cache: one read per request, key `ai-settings:v1` with a 1-hour TTL (backend)
  Covers: NFR-3, NFR-1
  - NFR-3 and clarity gate 8 require at most one cache read per request, the key `ai-settings:v1`, and a 1-hour TTL. `EloquentAiSettingsRepository::all()` uses `Cache::rememberForever('ai-settings')`, so the key name and TTL are wrong. It also does not memoize: every `forFeature()`/`configured()` call reads Redis (`CACHE_STORE=redis`) again, and a describe request calls these several times (`LimitCloudAi`, `UsesActiveProvider::provider()/model()/configured*`). The settings page reads it 7 or more times. Manual step 4 measured only latency, not the read count.
  - Any memo must be request-scoped (for example a `scoped` binding) so a worker still sees a save on its next request.
  - **Verify**: a test in `AiSettingsResolverTest` listens for cache events and asserts one `ai-settings:v1` read across several `forFeature()` calls in one request. A test asserts the TTL and key. The existing "save is seen without a reboot" test still passes after the scope is reset.

- [x] 12. AC-1 end to end: the run row shows the saved model, and the worker and Octane runs are recorded (checks)
  Covers: AC-1, NFR-1
  - No test sends a describe request after a save and checks the `ai_runs` row's `model`. `AiSettingsResolverTest` checks only the resolver's return value.
  - tasks.md 7.2 says manual step 2 (frankenphp-worker and frankenphp-octane) was "left for after the commit", so AC-1's worker and Octane modes and NFR-1's manual verification were never run.
  - **Verify**: a feature test saves `describe` → a fake provider and model, calls the describe endpoint, and asserts the new `ai_runs.model`. Manual step 2 is run on `frankenphp-worker` and `frankenphp-octane`, and its output (run models before and after the reset) is recorded here.
    - Test: `AiSettingsResolverTest` "AC-1 the describe run row records the saved provider and model, and the reset restores the configured one" passes (15 tests in the file).
    - Manual step 2, saved via the repository (save and reset forget `ai-settings:v1`), describe requested over HTTP, newest `ai_runs` row (id provider model):
      - frankenphp-worker: before `9813 ollama qwen3.5:9b`; after save `9814 fake-instant fake-instant-1`; after reset `9815 ollama qwen3.5:9b`.
      - frankenphp-octane: before `9816 ollama qwen3.5:9b`; after save `9817 fake-instant fake-instant-1`; after reset `9818 ollama qwen3.5:9b`.
      - No restart between requests in either mode; server returned to `frankenphp`.

## Phase: Gaps (converge round 2)

- [x] 13. Settings row form re-syncs to the effective choice after a save or a reset (frontend)
  Covers: AC-2, AC-5
  - `LabSettingRow.vue` builds `useForm` once from `row.effective` (line 58). Nothing watches `row.effective`, and `save()` and `reset()` pass no `onSuccess`. In Inertia 3.11, `put` and `delete` default to `preserveState: true` (`node_modules/@inertiajs/core/dist/index.js:3588`, `:3594`), so the row component and its form survive the redirect. After "Reset to configuration", the "Using" line shows the configuration, but the provider and model selects still hold the reset model, and Save would store it again. On an embeddings row, `needsAcknowledgement` then turns true with no user change, so the re-index checkbox and the warning appear right after a reset. That breaks clarity gate 10: the warning should appear only when the learner picks another model.
  - **Verify**: after a successful save or reset, the form takes the new `row.effective` provider and model and clears `reindex_acknowledged` (for example with `onSuccess` plus `form.defaults()`/`form.reset()`, or a watch on `row.effective`). `npm run typecheck` and `npm run build` pass. A browser check records two things. First, after a reset of a saved describe model, both selects show the configuration provider and model. Second, after a reset of a saved embeddings model, the row shows no re-index checkbox and no warning. The results are recorded here.

- [x] 14. AC-5 after a save: the count, warning and command follow the newly saved embedding model, and manual step 3 is run (checks)
  Covers: AC-5
  - The only AC-5 page test (`AiSettingsPageTest` "AC-5 an embeddings row carries the counts...") renders with no saved setting. No test saves an embeddings feature to another model and then checks the row. After that save, `index.current` should be 0 for the new model label, `foreign` should equal the indexed rows, and `warning` should be true with the re-index command. Manual step 3 (warn before saving, the command after saving, the count dropping to 0, docs search answering 409 until the re-index) is not recorded in this file.
  - **Verify**: a test in `AiSettingsPageTest` inserts vectors for the configured model and does `PUT /ai/settings/embeddings-listings` with another installed embedding model and `reindex_acknowledged`. On the next `GET /ai/settings`, it asserts `rows.5.effective.model` is the new model, `index.current` is 0, `index.foreign` equals `index.indexed`, `index.warning` is true and `index.command` is `ddev artisan listings:embed --fresh --wait`. Manual step 3 is run, and its output (counts before and after, the 409 code) is recorded here.

- [x] 15. Learning guide §16 names the current cache key and the request-scoped memo (docs)
  Covers: NFR-1
  - `docs/learning-guide.md:610` says `all()` "reads through the `ai-settings` cache key". Task 11 renamed the key to `ai-settings:v1` (`AiSettingsRepositoryInterface::CACHE_KEY`, 1-hour TTL). It also added the per-request memo through the `scoped()` binding (`AppServiceProvider`), which is what keeps a worker correct, and §16 does not mention it.
  - **Verify**: the §16 row names `ai-settings:v1`, the 1-hour TTL, and the `scoped` per-request memo that save and reset clear. `grep -rn "\`ai-settings\`" docs resources/guides` returns nothing, and `LearningGuideTest` passes.

## Fix pass and run record (2026-11-11)

Review fixes:
- **Critical: every request failed after a save.** Saved settings were cached as `AiSettingChoice` objects, and with `cache.serializable_classes = false` they came back as `__PHP_Incomplete_Class`, so every request answered 500 once a setting was saved. The repository now caches plain arrays and rebuilds the DTOs. A test reads back through a file store, which serializes, unlike the array store the suite uses.
- **Major: `:latest` tags.** Ollama names drop the default `:latest` tag (`OllamaServerInfo::canonical`), so `bge-m3` and `bge-m3:latest` are one model for installation, validation, saving and the embeddings-change check. There is no false "not installed" and no forced re-index for the configured model.
- **Major: Ollama lookups per page load.** The classified model list is cached as plain rows for 10 s, so the page costs one cache read per call instead of about 21 × (N + 1) round trips. A failed `/api/show` guess is held for the same 10 s instead of being re-posted on every call. `installation()` checks the driver before fetching models.
- **The Ollama fallback list.** It (unreachable, or no model of this kind) offers only Ollama ids: the configured feature model only when the feature's provider is Ollama, the saved model only when it is Ollama, and for `photo-vision` the vision default, never the text model. The note says which case applies.
- **Source label.** The row's source is the level that supplied the provider; only `AI_<FEATURE>_MODEL` set now reads source `default` with model source `configuration` (test updated).
- **Model ids.**
  - The pattern is `AiSettingChoice::MODEL_PATTERN`: it starts with a letter or digit and has no `..` segment.
  - Stored ids that do not match are ignored when read.
  - The copyable `ollama pull` command is shown only for ids matching the pattern.
- **Gap 13.** The settings row re-syncs its selects to the effective choice after a save, a reset, or any change of the effective choice, so no stale model and no spurious re-index prompt.
- **Gap 14.** A test saves `embeddings-listings` to another model: without the acknowledgement nothing is stored; with it, the row shows 0 current vectors, 2 from another model, the warning and the command.
- **Gap 15.** §16 names the `ai-settings:v1` key with its 1 h TTL, the plain-array caching and the scoped (per request or job) memo that makes worker mode safe.
- **Manual test.** It uses `ddev psql -c`; the database is PostgreSQL, not MySQL.

Not fixed (minor, recorded): `available()` (the cloud model lists) still lives on `OllamaServerInfo`; moving it is a refactor with no behaviour change.

Live checks (Redis cache, real Ollama):
- **Classic mode.** `describe` was saved as `ollama/gemma4:12b`. `/ai/settings`, `/ai`, `/ai/describe` and `/ai/guides/providers` each answered 200 twice (once filling the cache, once reading it), and describe streamed `stream_start` with provider `ollama` and model `gemma4:12b`.
- **frankenphp-worker, no restart between steps:**
  - saved → `gemma4:12b`;
  - reset → `qwen3.5:9b`;
  - saved again → `gemma4:12b`.

  The server is back on `frankenphp`.
- **Browser, `/ai/settings`.** 7 rows; `bge-m3` is not flagged "not installed"; `photo-vision` reads `gemma4:12b (provider default)`; "Settings" is in the navigation; no horizontal scroll and no console warnings.
- **Gates.** Full suite: 1819 passed. Pint and PHPStan clean, typecheck and build pass. Fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Clean-tree bench: after commit 05ce6b3, `bench/run.sh ping --quick` exited 0 on frankenphp.
