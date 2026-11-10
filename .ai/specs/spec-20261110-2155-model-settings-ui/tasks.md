# Model settings UI — Tasks

**Spec**: spec-20261110-2155-model-settings-ui | **Size**: M | **Tasks**: 7

## Phase 1: Storage and resolver

- [ ] 1. `ai_settings` table, model and repository (database)
  Covers: AC-3, AC-4
  - [ ] 1.1 A migration (`feature` primary key, `provider`, `model`, timestamps), a model, `AiSettingsRepositoryInterface` plus its Eloquent implementation (`all()`, `save()`, `reset()`), and cache-key forgetting on write.
  - [ ] 1.2 **Verify**: `tests/Feature/AiSettingsRepositoryTest.php`.

- [ ] 2. Resolver precedence (backend)
  Covers: AC-1, AC-6, AC-7, MNC-1, MNC-2, MNC-4, NFR-1
  - [ ] 2.1 `AiProviders::forFeature()` order: request, then settings (local and enabled only), then configuration, then default; it returns the source label; no `config()` writes.
  - [ ] 2.2 **Verify**: `tests/Feature/AiSettingsResolverTest.php` (each level; a non-local environment ignores settings; a save is seen without a reboot in the same process; the SL-51 tests are unchanged).

## Phase 2: Page and routes

- [ ] 3. Ollama model list (backend)
  Covers: AC-2
  - [ ] 3.1 `OllamaServerInfo::models()` from `/api/tags` (cached 10 s), with each model classed as text or embedding; the config `available` lists for cloud providers.
  - [ ] 3.2 **Verify**: `tests/Unit/OllamaServerInfoModelsTest.php` with `Http::fake`.

- [ ] 4. Settings routes and validation (backend)
  Covers: AC-3, AC-4, AC-6, NFR-2
  - [ ] 4.1 `EnsureLocalSettings` middleware; `GET /ai/settings`, `PUT /ai/settings/{feature}` and `DELETE /ai/settings/{feature}`; a form request; flash messages.
  - [ ] 4.2 **Verify**: `tests/Feature/AiSettingsPageTest.php` (props with no key values, 404 outside local or when the flag is off, validation errors store nothing).

- [ ] 5. Settings page (frontend)
  Covers: AC-2, AC-5
  - [ ] 5.1 `Lab/Settings.vue`: rows per feature (effective provider, model and source; selects; other id; save; reset); the embeddings warning, the re-index command and the indexed-row count; "Settings" in the navigation only when available.
  - [ ] 5.2 **Verify**: `AiSettingsPageTest` (embedding counts, warning props); typecheck; build; browser check at 1440 and 390.

## Phase 3: Guides, brief, checks

- [ ] 6. Guides, brief and backlog (docs)
  Covers: AC-8
  - [ ] 6.1 Providers guide: the recipe, the per-feature facts and the worker-mode step; §16 lists the new files and the precedence flow; BR-91 revised in `product-brief.md`; SL-52 in the backlog with an intake row; `.env.example` gets `AI_SETTINGS_UI`.
  - [ ] 6.2 **Verify**: `GuideContentTest`, `GuideFactsTest` (facts per feature with source), `LearningGuideTest` map.

- [ ] 7. Checks (checks)
  Covers: MNC-3, MNC-5, MNC-6, NFR-3
  - [ ] 7.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; parity on frankenphp unchanged; manual steps 2 and 4; `bench/run.sh ping --quick` after the commit.
  - [ ] 7.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | saved model used next request, any mode | `tests/Feature/AiSettingsResolverTest.php` | test | 2 |
| AC-2 | page shows effective choice and valid options | `tests/Feature/AiSettingsPageTest.php` | test | 3, 5 |
| AC-3 | reset to configuration | `tests/Feature/AiSettingsRepositoryTest.php` | test | 1, 4 |
| AC-4 | invalid save rejected, nothing stored | `tests/Feature/AiSettingsPageTest.php` | test | 4 |
| AC-5 | embeddings warning, command, counts | `tests/Feature/AiSettingsPageTest.php` | test | 5 |
| AC-6 | local only; settings ignored elsewhere | `tests/Feature/AiSettingsResolverTest.php` | test | 2, 4 |
| AC-7 | request override wins | `tests/Feature/AiSettingsResolverTest.php` | test | 2 |
| AC-8 | providers guide facts and recipe | `tests/Feature/GuideFactsTest.php` | test | 6 |
| MNC-1 | no settings, SL-51 behaviour | `tests/Feature/AiSettingsResolverTest.php` | test | 2 |
| MNC-2 | SL-51 selector still wins | `tests/Feature/AiSettingsResolverTest.php` | test | 2 |
| MNC-3 | AI endpoint contracts | verify existing AI endpoint tests pass unchanged | test | 7 |
| MNC-4 | non-local ignores stored settings | `tests/Feature/AiSettingsResolverTest.php` | test | 2 |
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

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
