# Hybrid similar photos — Tasks

**Spec**: spec-20261112-1000-hybrid-similar-photos | **Size**: M | **Tasks**: 6

## Phase 1: Shared repository and text vectors

- [ ] 1. Shared nearest-by-vector repository (backend, refactor)
  Covers: AC-6, MNC-1, MNC-2
  - [ ] 1.1 `PhotoVectorRepository::nearest(VectorQuery)`; SL-36 `nearest()` and SL-37 `nearestToVector()` delegate; the duplicate SQL is removed.
  - [ ] 1.2 **Verify**: the existing SimilarPhotos, PhotoTextSearch and Photo eval tests pass unchanged (characterization, green first); a test that no other repository holds `iterative_scan`.

- [ ] 2. Caption vectors (database, backend)
  Covers: AC-1
  - [ ] 2.1 Migration (text columns, HNSW index); `photos:embed --text [--text-source=caption|title]` (stale by hash and label, batches on `ai-vision`, probe, `--wait`, one `ai_runs` row per batch); generation bump.
  - [ ] 2.2 **Verify**: `tests/Feature/PhotoTextEmbedTest.php` (caption from the right extraction, the template applied, skips current rows, no caption → null, title only with the option).

## Phase 2: Hybrid ranking and UI

- [ ] 3. Hybrid in `SimilarPhotosService` (backend)
  Covers: AC-2, AC-3, NFR-1
  - [ ] 3.1 `for($photoId, $w)`: image candidates, one caption-similarity query, the weighted score, the image-only fallback flag, the image near-duplicate cutoff, `hybrid_min_score`, the limit; the cache key with `w` and the text generation.
  - [ ] 3.2 **Verify**: `tests/Feature/HybridSimilarPhotosTest.php` (w = 1 equals SL-36 exactly; reorder at w = 0.5 on crafted vectors; a missing caption flagged; duplicate cutoff on image; the cache varies by `w`).

- [ ] 4. `/ai/photos` side by side and the slider (frontend)
  Covers: AC-4
  - [ ] 4.1 `GET /ai/photos/{photo}/similar?w=` (validated 0–1, throttled); the two columns, the slider, the parts and flags, the caption coverage line.
  - [ ] 4.2 **Verify**: `PhotoLabPageTest` and an endpoint test; typecheck; build; browser check.

## Phase 3: Eval, guide, run

- [ ] 5. Hybrid eval and guide (backend, docs)
  Covers: AC-5, AC-7, NFR-2
  - [ ] 5.1 `photos:similar-eval --hybrid` (the `w` sweep, per-`w` calibration, baseline, the title run flagged); guide updates; §16.
  - [ ] 5.2 **Verify**: `tests/Feature/PhotoHybridEvalTest.php` (the maths on fixed vectors; the title run flagged and not chosen); `GuideContentTest`.

- [ ] 6. Run and checks (checks)
  Covers: AC-5, MNC-3, MNC-4
  - [ ] 6.1 Orchestrator: `photos:extract` for all photos plus a worker; `photos:embed --text --wait`; `photos:similar-eval --hybrid`; set the config defaults; the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity; `bench/run.sh ping --quick` after the commit.
  - [ ] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | caption vectors stored, stale detection | `tests/Feature/PhotoTextEmbedTest.php` | test | 2 |
| AC-2 | weighted rerank with exclusions | `tests/Feature/HybridSimilarPhotosTest.php` | test | 3 |
| AC-3 | w = 1 equals SL-36 | `tests/Feature/HybridSimilarPhotosTest.php` | test | 3 |
| AC-4 | slider and side by side | `tests/Feature/PhotoSimilarEndpointTest.php` | test | 4 |
| AC-5 | eval sweep, baseline, title flagged | `tests/Feature/PhotoHybridEvalTest.php` | test | 5, 6 |
| AC-6 | shared repository only | `tests/Feature/PhotoVectorRepositoryTest.php` | test | 1 |
| AC-7 | guide updated | `tests/Feature/GuideContentTest.php` | test | 5 |
| MNC-1 | SL-36 unchanged | verify existing SimilarPhotos tests pass unchanged | test | 1, 3 |
| MNC-2 | SL-37 unchanged | verify existing PhotoTextSearch tests pass unchanged | test | 1 |
| MNC-3 | offline and parity | `manual-test.md#step-3` | manual | 6 |
| MNC-4 | fingerprint unchanged | `manual-test.md#step-3` | manual | 6 |
| NFR-1 | hybrid latency | `manual-test.md#step-2` | manual | 6 |
| NFR-2 | title never headline | `tests/Feature/PhotoHybridEvalTest.php` | test | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
