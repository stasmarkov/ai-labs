# Similar car photos — Tasks

**Spec**: spec-20261111-1701-similar-photos | **Size**: L | **Tasks**: 9

## Phase 1: Encoder and vectors

- [ ] 1. Encoder sidecar (devops)
  Covers: AC-1, NFR-2, MNC-5
  - [ ] 1.1 `.ddev/docker-compose.photo-encoder.yaml`: the Infinity CPU image pinned by digest (or the open_clip fallback, recorded in `decisions.md`), a named weights volume, a 2 GB limit, a health check; README start notes.
  - [ ] 1.2 **Verify**: `tests/Feature/PhotoEncoderComposeTest.php` (digest pinned, volume, memory limit, health check, `config.yaml` untouched); manual step 1.

- [ ] 2. Provider, feature and client (backend)
  Covers: AC-2, AC-7, MNC-3
  - [ ] 2.1 `photo-encoder` provider entry and `embeddings-photos` feature (kind `image-embeddings`) in `AiProviders` and on `/ai/settings`; `PhotoEncoderClient` (base64 images, batch of 16, the dimension check, the timeout).
  - [ ] 2.2 **Verify**: `tests/Feature/PhotoEncoderClientTest.php` (`Http::fake`), `AiSettingsPageTest` (the new row, its models only, re-index acknowledgement).

- [ ] 3. Columns, index and `photos:embed` (database, backend)
  Covers: AC-2, NFR-2, MNC-2
  - [ ] 3.1 Migration (vector(512), label, `embedded_at`, HNSW cosine); `EmbedPhotosBatchJob` on `ai-vision`; the command (`--limit`, `--fresh`, `--wait`, progress, summary); `ai_runs` per batch.
  - [ ] 3.2 **Verify**: `tests/Feature/PhotoEmbedTest.php`.

## Phase 2: Similar photos

- [ ] 4. Service and cache (backend)
  Covers: AC-3, AC-4, AC-7, NFR-1
  - [ ] 4.1 `SimilarPhotosService` (excludes the same listing, cutoffs, at most 8, label filter with iterative scan, a reason when empty, a Redis cache of plain arrays, invalidation on embed and the version bump on `--fresh`).
  - [ ] 4.2 **Verify**: `tests/Feature/SimilarPhotosServiceTest.php` (generated vectors: own listing excluded, a 0.99 match dropped, a low score dropped, order, cache hit and invalidation, label mismatch reason).

- [ ] 5. Listing page block (frontend)
  Covers: AC-5, MNC-1, NFR-3
  - [ ] 5.1 `show.blade.php` "Similar cars" block (server-side, only when there are matches).
  - [ ] 5.2 **Verify**: `tests/Feature/SimilarPhotosPageTest.php` (block present with matches; markup absent otherwise; listing 1 HTML unchanged).

- [ ] 6. `/ai/photos` strip (frontend)
  Covers: AC-6
  - [ ] 6.1 Detail props and `Photos.vue` strip (thumbnails, similarity, make and model match, label, cutoffs, cache flag).
  - [ ] 6.2 **Verify**: `PhotoLabPageTest` (props); typecheck; build; browser check.

## Phase 3: Eval, guide, checks

- [ ] 7. Eval command (backend)
  Covers: AC-8
  - [ ] 7.1 `photos:similar-eval` (precision@8 for make and model and for make, coverage, latency with and without the cache, throughput) → `run.json`.
  - [ ] 7.2 **Verify**: `tests/Feature/PhotoSimilarEvalTest.php` (the maths on fixed vectors).

- [ ] 8. Guide and docs (docs)
  Covers: AC-9
  - [ ] 8.1 `similar-photos.php` guide; the photos guide `related` field; the providers capability row; §16; README.
  - [ ] 8.2 **Verify**: `GuideContentTest`, `NoReferencesInUiTest`, `LearningGuideTest`.

- [ ] 9. Run and checks (checks)
  Covers: AC-1, AC-8, MNC-1, MNC-4
  - [ ] 9.1 Orchestrator: start the sidecar, `photos:embed`, calibrate `min_score`, `photos:similar-eval`, the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; parity on frankenphp; the fingerprint (re-baseline only with a reason); `bench/run.sh ping --quick`.
  - [ ] 9.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | encoder starts, health, weights volume | `manual-test.md#step-1` | manual | 1 |
| AC-2 | photos:embed stores vectors, refuses bad setups | `tests/Feature/PhotoEmbedTest.php` | test | 2, 3 |
| AC-3 | at most 8, other listings, cutoffs | `tests/Feature/SimilarPhotosServiceTest.php` | test | 4 |
| AC-4 | cache hit and invalidation | `tests/Feature/SimilarPhotosServiceTest.php` | test | 4 |
| AC-5 | listing page block only with matches | `tests/Feature/SimilarPhotosPageTest.php` | test | 5 |
| AC-6 | lab strip with details | `tests/Feature/PhotoLabPageTest.php` | test | 6 |
| AC-7 | settings switch needs re-index | `tests/Feature/AiSettingsPageTest.php` | test | 2, 4 |
| AC-8 | eval metrics and BENCHMARKS | `tests/Feature/PhotoSimilarEvalTest.php` | test | 7, 9 |
| AC-9 | capability guide, no references | `tests/Feature/GuideContentTest.php` | test | 8 |
| MNC-1 | listing page without photo unchanged | `tests/Feature/SimilarPhotosPageTest.php` | test | 5 |
| MNC-2 | SL-27 unchanged | verify existing Photo tests pass unchanged | test | 3 |
| MNC-3 | features and settings rows unchanged | verify existing AiSettings tests pass unchanged | test | 2 |
| MNC-4 | offline suite | `tests/Feature/PhotoEncoderClientTest.php` | test | 2 |
| MNC-5 | config.yaml unchanged | `tests/Feature/PhotoEncoderComposeTest.php` | test | 1 |
| NFR-1 | latency budget | `manual-test.md#step-3` | manual | 9 |
| NFR-2 | resources and queue | `tests/Feature/PhotoEmbedTest.php` | test | 1, 3 |
| NFR-3 | page safety | `tests/Feature/SimilarPhotosPageTest.php` | test | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
