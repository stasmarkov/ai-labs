# Hybrid similar photos — Tasks

**Spec**: spec-20261112-1000-hybrid-similar-photos | **Size**: M | **Tasks**: 6

## Phase 1: Shared repository and text vectors

- [x] 1. Shared nearest-by-vector repository (backend, refactor)
  Covers: AC-6, MNC-1, MNC-2
  - [x] 1.1 `PhotoVectorRepository::nearest(VectorQuery)`; SL-36 `nearest()` and SL-37 `nearestToVector()` delegate; the duplicate SQL is removed.
  - [x] 1.2 **Verify**: the existing SimilarPhotos, PhotoTextSearch and Photo eval tests pass unchanged (characterization, green first); a test that no other repository holds `iterative_scan`.

- [x] 2. Caption vectors (database, backend)
  Covers: AC-1
  - [x] 2.1 Migration (text columns; HNSW index dropped from scope); `photos:embed --text` (stale by hash and label, batches on `ai-vision`, probe, `--wait`, one `ai_runs` row per batch); generation bump.
  - [x] 2.2 **Verify**: `tests/Feature/PhotoTextEmbedTest.php` (caption from the right extraction, the template applied, skips current rows, no caption → null, no title source).

## Phase 2: Hybrid ranking and UI

- [x] 3. Hybrid in `SimilarPhotosService` (backend)
  Covers: AC-2, AC-3, NFR-1
  - [x] 3.1 `for($photoId, $w)`: image candidates, one caption-similarity query, the weighted score, the image-only fallback flag, the image near-duplicate cutoff, `hybrid_min_score`, the limit; the cache key with `w` and the text generation.
  - [x] 3.2 **Verify**: `tests/Feature/HybridSimilarPhotosTest.php` (w = 1 equals SL-36 exactly; reorder at w = 0.5 on crafted vectors; a missing caption flagged; duplicate cutoff on image; the cache varies by `w`).

- [x] 4. `/ai/photos` side by side and the slider (frontend)
  Covers: AC-4
  - [x] 4.1 `GET /ai/photos/{photo}/similar?w=` (validated 0–1, throttled); the two columns, the slider, the parts and flags, the caption coverage line.
  - [x] 4.2 **Verify**: `PhotoLabPageTest` and an endpoint test; typecheck; build; browser check.

## Phase 3: Eval, guide, run

- [x] 5. Hybrid eval and guide (backend, docs)
  Covers: AC-5, AC-7, NFR-2
  - [x] 5.1 `photos:similar-eval --hybrid` (the `w` sweep, per-`w` calibration, baseline, the title run flagged); guide updates; §16.
  - [x] 5.2 **Verify**: `tests/Feature/PhotoHybridEvalTest.php` (the maths on fixed vectors; the title run flagged and not chosen); `GuideContentTest`.

- [x] 6. Run and checks (checks)
  Covers: AC-5, MNC-3, MNC-4
  - [x] 6.1 Orchestrator: `photos:extract` for all photos plus a worker; `photos:embed --text --wait`; `photos:similar-eval --hybrid`; set the config defaults; the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity; `bench/run.sh ping --quick` after the commit.
  - [x] 6.2 **Verify**: outputs recorded here.
    - `photos:extract`: no photos to extract, 156 already have an extraction for gemma4:12b (photo-vision@v1). `photos:embed --text --wait`: 155 caption vectors, 1 photo without a caption (needed `queue:work --queue=ai-vision --stop-when-empty`; the command waited for a worker).
    - `photos:similar-eval --hybrid` (`bench/results/photo-similar/2026-11-12/hybrid.json`): baseline P@8 make+model 0.677, coverage 0.987; best w 0.9 at min_score 0.75 gives 0.693 at coverage 0.987 (beats the baseline by 0.016, rule needs 0.01); title run flagged as leaking, not chosen; uncached p95 4.317 ms at w 0.9 (budget 80 ms, within).
    - Config defaults: `ai.similar_photos.default_weight` 0.9, `hybrid_min_score` 0.75. `BENCHMARKS.md` section "Hybrid similar photos (2026-11-12)"; guide numbers point at `hybrid.json`. `PhotoSimilarEndpointTest` now reads the weight from config instead of the literal 0.5.
    - Gates: full suite 2013 tests green, Pint passed, PHPStan no errors, `npm run typecheck` and `npm run build` clean.
    - MNC-4: `bench/asset-fingerprint.sh` prints `app-DNob7CFE.css` (176bae5b...) and `app-PTzu1QaN.js` (7fbc3860...), identical to BENCHMARKS.md.
    - MNC-3: `PARITY_INSECURE=1 bench/parity.sh capture` changed only the `captured=` line of `bench/parity/frankenphp.txt` (restored, not committed).
    - NFR-1: within budget (above). `bench/run.sh ping --quick` runs after the commit, see the commit report.

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
