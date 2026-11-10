# Optional cloud providers — Tasks

**Spec**: spec-20261110-2141-cloud-providers | **Size**: L | **Tasks**: 9

## Phase 1: Resolver and config

- [ ] 1. Provider entries and `AiProviders` (backend)
  Covers: AC-1, AC-2, MNC-1, NFR-1
  - [ ] 1.1 `config/ai.php` entries for anthropic, openai and gemini, plus the `features` map; `App\Ai\AiProviders` (`enabled()`, `enabledTextProviders()`, `forFeature()`, capability map); a `ProviderChoice` DTO; record the model-id sources in `decisions.md`.
  - [ ] 1.2 **Verify**: `tests/Unit/AiProvidersTest.php` (fallbacks, key-less cloud provider not enabled, override validation, no key in any return value).

- [ ] 2. Route every provider read through the resolver (backend)
  Covers: AC-1, AC-4, MNC-1, MNC-2
  - [ ] 2.1 Agents, `UsesActiveProvider`, services, commands, `RecordEmbeddingsRun`, `LabOverviewService` and `GuideFacts` use `forFeature()`. A grep test finds no direct `config('ai.default')` or `ai.default_for_embeddings` read outside `AiProviders`.
  - [ ] 2.2 **Verify**: `tests/Feature/CloudProvidersTest.php` (per-feature provider via SDK fake; run rows carry provider and model; key-less provider fails with no call); the existing suite is green.

## Phase 2: Requests, limits, embeddings

- [ ] 3. Optional `provider` parameter (backend)
  Covers: AC-2, AC-3, MNC-2
  - [ ] 3.1 Describe, NL search and docs ask accept `provider`, validated against the enabled text providers (422 `provider_not_enabled`); the agents run on the chosen provider.
  - [ ] 3.2 **Verify**: `CloudProvidersTest` override cases; the existing endpoint tests are unchanged.

- [ ] 4. Cloud limiter (backend)
  Covers: AC-7, NFR-3
  - [ ] 4.1 A `cloud-ai` limiter applied when the resolved provider is a cloud one.
  - [ ] 4.2 **Verify**: `tests/Feature/CloudRateLimitTest.php`.

- [ ] 5. Embeddings switch safety (backend)
  Covers: AC-5, MNC-3
  - [ ] 5.1 409 `index_not_built` on semantic and docs search when no row matches the configured embedding model; the dimension check in `listings:embed` and `docs:index`.
  - [ ] 5.2 **Verify**: `tests/Feature/EmbeddingsProviderSwitchTest.php`.

## Phase 3: UI, guides, docs

- [ ] 6. Lab selector (frontend)
  Covers: AC-3
  - [ ] 6.1 `LabProviderSelect.vue` on Describe, NL search and Chat, fed by an `enabledTextProviders` prop (names and a configured flag only); the selected value goes into the request; search pages show the embeddings provider as text.
  - [ ] 6.2 **Verify**: `tests/Feature/AiLabProviderSelectTest.php` (props without keys); typecheck; build; browser check.

- [ ] 7. Providers guide and learning guide (docs)
  Covers: AC-6, AC-8
  - [ ] 7.1 `providers.php`: capability table section, key-set facts, recipes, failure modes, experiment; `GuideRenderer` and `Guide.vue` render the table; §16 lists the new files and the "add a provider" recipe; `.env.example`; README paragraph.
  - [ ] 7.2 **Verify**: `GuideContentTest`, `GuideFactsTest` (key set or not), the `LearningGuideTest` map, `ReadmeTest`.

- [ ] 8. Secret safety (checks)
  Covers: NFR-1, NFR-2, MNC-4
  - [ ] 8.1 `tests/Feature/CloudKeysNeverLeakTest.php` (fake keys, then search every lab page, the guide, the log and ai_runs); `preventStrayRequests` in the new tests.
  - [ ] 8.2 **Verify**: green.

- [ ] 9. Checks (checks)
  Covers: MNC-1, MNC-5
  - [ ] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; parity on frankenphp unchanged; `bench/run.sh ping --quick` after the commit.
  - [ ] 9.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | per-feature provider by config | `tests/Feature/CloudProvidersTest.php` | test | 1, 2 |
| AC-2 | key-less provider refused, no call | `tests/Feature/CloudProvidersTest.php` | test | 1, 3 |
| AC-3 | lab selector override and run row | `tests/Feature/CloudProvidersTest.php` | test | 3, 6 |
| AC-4 | ai_runs provider and model | `tests/Feature/CloudProvidersTest.php` | test | 2 |
| AC-5 | 409 index_not_built; dimension check | `tests/Feature/EmbeddingsProviderSwitchTest.php` | test | 5 |
| AC-6 | capability table and key-set facts | `tests/Feature/GuideFactsTest.php` | test | 7 |
| AC-7 | cloud limiter 429 | `tests/Feature/CloudRateLimitTest.php` | test | 4 |
| AC-8 | §16 lists the new files | `tests/Feature/LearningGuideTest.php` | test | 7 |
| MNC-1 | defaults unchanged with no keys | verify full suite green with no cloud variables set | test | 2, 9 |
| MNC-2 | endpoint contracts without provider | verify existing AiLab and endpoint tests pass unchanged | test | 3 |
| MNC-3 | search unchanged on bge-m3 | `tests/Feature/EmbeddingsProviderSwitchTest.php` | test | 5 |
| MNC-4 | offline tests | `tests/Feature/CloudKeysNeverLeakTest.php` | test | 8 |
| MNC-5 | fingerprint and parity | `manual-test.md#step-4` | manual | 9 |
| NFR-1 | no key leaks | `tests/Feature/CloudKeysNeverLeakTest.php` | test | 8 |
| NFR-2 | no network in tests | `tests/Feature/CloudProvidersTest.php` | test | 8 |
| NFR-3 | cost safety | `tests/Feature/CloudRateLimitTest.php` | test | 4 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
