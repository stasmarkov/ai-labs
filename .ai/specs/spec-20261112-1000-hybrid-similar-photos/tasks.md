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
    - NFR-1: within budget (above). `bench/run.sh ping --quick` ran on the clean tree after commit e05f5af (frankenphp, 1 repeat, spread 0%, raw file under `bench/results/raw/2026-11-12/frankenphp/`, gitignored).

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
| MNC-3 | offline and parity | `manual-test.md#step-9` | manual | 6 |
| MNC-4 | fingerprint unchanged | `manual-test.md#step-9` | manual | 6 |
| NFR-1 | hybrid latency | `manual-test.md#step-5` | manual | 6 |
| NFR-2 | title never headline | `tests/Feature/PhotoHybridEvalTest.php` | test | 5 |

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

- [x] 7. Lab endpoint contract per clarity gate 6 (backend)
  Covers: AC-4
  - `app/Http/Requests/PhotoSimilarRequest.php` makes `w` `nullable` and never rounds it; gate 6 requires `w` (missing is 422) rounded to one decimal, and gate 4 rounds before the 1.0 check. `tests/Feature/PhotoSimilarEndpointTest.php` asserts the opposite (no `w` answers 200 image-only). `SimilarPhotosService::cacheKey()` formats `w` to 4 decimals, so 0.73 and 0.7 are separate cache entries.
  - `PhotoLabSimilar::toHybridArray()` returns `min_score` from `ai.similar_photos.min_score` (not `hybrid_min_score` for a hybrid answer) and lacks `query_has_caption`, `candidates` and `candidates_with_caption` (the 40 image candidates with a caption vector); its `coverage` counts the returned matches instead.
  - Approach: make `w` required, `round((float) $w, 1)` in the request, key the cache on the rounded value; add the three fields (count captioned candidates from the `hybridCandidates()` pool and `captionSimilarities()` result), report the effective min score.
  - [x] 7.1 **Verify**: `ddev artisan test --compact tests/Feature/PhotoSimilarEndpointTest.php` with cases for a missing `w` (422), `w=0.73` answering `"w": 0.7` with the same cache key as 0.7, `query_has_caption` true/false, `candidates`/`candidates_with_caption` counted over the candidate pool, and `min_score` equal to `hybrid_min_score` on a hybrid answer.

- [x] 8. Query photo without a caption and caption-only source in hybrid (backend)
  Covers: AC-2
  - Gate 3: when the query photo has no caption vector the hybrid column must equal image-only and use the SL-36 `min_score`. `SimilarPhotosService::rankHybrid()`/`blend()` always apply `hybrid_min_score`, so with `AI_SIMILAR_HYBRID_MIN_SCORE` different from `AI_SIMILAR_MIN_SCORE` the result differs from image-only. `HybridSimilarPhotosTest` ("query photo has no caption") checks only the flag and the score.
  - Gate 1: hybrid ranking reads only rows with `text_source = 'caption'`; `PhotoVectorRepository::captionSimilarities()` filters on `text_embedding_model` only, although the migration's check constraint allows `title`.
  - Approach: when the caption-similarity map is empty because the query lacks a caption vector, rank with `minScore()` (or return the `rank()` result with image-only parts); add `where('p.text_source', 'caption')` and the same on `q`.
  - [x] 8.1 **Verify**: `ddev artisan test --compact tests/Feature/HybridSimilarPhotosTest.php` with a case where `hybrid_min_score` > `min_score` and the query has no caption (matches equal `for($id)` by id and score), and a case where a candidate row with `text_source = 'title'` is scored image-only.

- [x] 9. Slider, column heading and caption line per spec, and the browser check (frontend)
  Covers: AC-4
  - `resources/js/ai/Pages/Lab/Photos.vue` uses `step="0.05"` (spec: 0 to 1 in steps of 0.1), heads the column "Image and caption" without "(w = …)", shows "N of M matches scored with a caption" (spec: how many of the photo's neighbours have captions) and never shows "no caption for this photo" when the query photo has no caption vector (gate 3).
  - Task 4.2 lists a browser check and gate 8 assigns "without a page reload" to it, but no browser run of `manual-test.md` step 2 is recorded in 6.2.
  - Approach: set `step="0.1"`, render the heading with the current `w`, drive the line from `candidates_with_caption`/`candidates` (task 7) and show "no caption for this photo" when `query_has_caption` is false.
  - [x] 9.1 **Verify**: `ddev npm run typecheck && ddev npm run build`; `ddev exec bench/asset-fingerprint.sh` unchanged for the main asset; browser run of `manual-test.md` step 2 on `/ai/photos?photo=25` (1.0 equals image-only, 0.5 reorders with parts, no full page navigation in the network log, "no caption for this photo" on a photo without a caption vector) recorded here.
    - `Photos.vue`: `step="0.1"`, heading "Image and caption (w = N)", line "N of M neighbours have a caption" from `candidates_with_caption`/`candidates`, and "No caption for this photo." when `query_has_caption` is false. `npm run typecheck` and `npm run build` clean; `bench/asset-fingerprint.sh` main assets unchanged (`app-DNob7CFE.css`, `app-PTzu1QaN.js`).
    - Browser run (headless Chrome driven over the DevTools protocol, since no browser tool was available) on `/ai/photos?photo=25`: at 1.0 the hybrid column equals the image-only column (same eight photos and scores, no parts); at 0.8 the order changes (X3 0.7943, 0.7716, 0.7636 with image and caption parts shown); at 0.5 the column is empty ("no photo of another listing is similar enough") because every blended score falls under `hybrid_min_score` 0.75. Only `/similar?w=` fetches were made after load: one document request in the network log and a page marker kept, so no full page navigation. Photo 3 (no caption vector) shows "No caption for this photo." at 0.9 and 0.8.

## Phase: Gaps (converge round 2)

- [x] 10. Hybrid limit of 8 and photo-id tie order under test (backend, tests)
  Covers: AC-2
  - `SimilarPhotosService::blend()` stops at `$limit` and sorts ties by photo id, and `hybridCandidates()` asks for `max($limit, candidates)` (40), but no case in `tests/Feature/HybridSimilarPhotosTest.php` or `tests/Feature/PhotoHybridEvalTest.php` has more than 8 qualifying candidates or two equal hybrid scores, so AC-2's "the limit of 8" and the spec's "ties are ordered by photo id" are unproven.
  - Approach: add a case with 10 captioned candidates of distinct listings above `hybrid_min_score` at w 0.5 (expect exactly `ai.similar_photos.limit` matches, best first), and a case with two candidates whose blended scores are equal (expect the lower photo id first).
  - [x] 10.1 **Verify**: `ddev artisan test --compact tests/Feature/HybridSimilarPhotosTest.php` passes with the two new AC-2 cases.

- [x] 11. Endpoint `min_score` for a query photo without a caption (backend)
  Covers: AC-4, AC-2
  - Gate 3: a query photo without a caption vector ranks with the SL-36 `min_score`, and `SimilarPhotosService::scoreHybrid()` does so (task 8). `PhotoLabService::similar()` (line 97) still reports `hybrid_min_score` whenever `w` < 1, so the endpoint's `min_score` is not the cutoff that was applied when `query_has_caption` is false and the two settings differ.
  - Approach: report `ai.similar_photos.min_score` when `$result->pool?->queryHasCaption === false`, otherwise keep the current choice.
  - [x] 11.1 **Verify**: `ddev artisan test --compact tests/Feature/PhotoSimilarEndpointTest.php` with a case where `min_score` 0.60 and `hybrid_min_score` 0.55 and the query photo has no caption: `w=0.5` answers `query_has_caption: false` and `min_score: 0.6`.

- [x] 12. `AI_SIMILAR_HYBRID_WEIGHT` env for the default weight (backend, config)
  Covers: AC-5
  - The spec's eval scope says the best `w` and its `hybrid_min_score` become config defaults with env `AI_SIMILAR_HYBRID_WEIGHT` and `AI_SIMILAR_HYBRID_MIN_SCORE`. `config/ai.php` line 111 hardcodes `'default_weight' => 0.9` with no env override (only `hybrid_min_score` reads its env), and the guide recipe in `resources/guides/similar-photos.php` names only `AI_SIMILAR_HYBRID_MIN_SCORE`.
  - Approach: `'default_weight' => filled(env('AI_SIMILAR_HYBRID_WEIGHT')) ? (float) env('AI_SIMILAR_HYBRID_WEIGHT') : 0.9`, matching the `min_score` pattern; name the env in the recipe; add it to `.env.example` if the sibling `AI_SIMILAR_*` keys are listed there.
  - [x] 12.1 **Verify**: `ddev artisan test --compact tests/Feature/PhotoSimilarEndpointTest.php tests/Feature/GuideContentTest.php` passes; `ddev artisan config:show ai.similar_photos.default_weight` prints 0.9 with the env unset.

- [x] 13. Manual step 2 weight that the shipped `hybrid_min_score` lets through, and the browser re-run (docs, checks)
  Covers: AC-4
  - `manual-test.md` step 2 and task 9.1 expect "at 0.5 the order changes, and each row shows its image and caption parts". The recorded browser run (9.1) found the column empty at 0.5, because every blended score falls under `hybrid_min_score` 0.75 (calibrated for w 0.9; `hybrid.json` calibrates 0.65 for w 0.5). The reorder was only seen at 0.8, so the written step and its Verify criterion were not met as stated.
  - Approach: change step 2 to move the slider from 1.0 to 0.8 (reorder with parts) and then to 0.5, expecting the empty column with the "no photo of another listing is similar enough" reason, and state that one `hybrid_min_score` is calibrated for the default weight.
  - [x] 13.1 **Verify**: browser run of the revised `manual-test.md` step 2 on `/ai/photos?photo=25` (1.0 equals image-only; 0.8 reorders with image and caption parts; 0.5 shows the reason; only `/similar?w=` fetches after load) recorded here.

## Fix pass and run record (2026-11-12)

The session was restarted mid-build. The build resumed from its journal, and the caption extraction was re-queued: 52 of its 53 failures were "could not connect to Ollama", so the final count is 155 completed and 1 failed (an uppercase tag).

Review fixes:
- **Major (gap 11): the endpoint reported the wrong cutoff.** It reported `hybrid_min_score` while a query photo without a caption had been cut at `min_score`. `HybridPool` now carries `appliedMinScore`, set by the ranking, and the lab reports that (test).
- **Gap 13: empty hybrid column at w 0.5.** The cutoff is now taken per weight. The eval's calibrated table is `ai.similar_photos.hybrid_min_scores` (w 0 → 0.60 … w 0.9 → 0.75), with `hybrid_min_score` as the fallback; `hybridMinScore(w)` is the one accessor. Live: `?w=0.5` gives cutoff 0.65 and 8 photos, where the column was empty before (test).
- **Gap 12.** `AI_SIMILAR_HYBRID_WEIGHT` now overrides `default_weight`, and the four similar-photo and photo-search variables are in `.env.example`.
- **Gap 10.** Tests for the limit of 8 with 12 qualifying photos, and for equal scores ordered by photo id ascending.
- **Cache.** The hybrid key has an `h2` segment, so entries written before this version (no pool, older cutoff rules) are never read.
- **Eval.** The latency pass moves only the text generation, so the image-only cache (and the listing page block) stays warm.
- **Slider endpoint.** The photo load selects `id`, `listing_id` and `path` only, so the two 512-d vectors are not read on every slider move.
- **Browser check of `/ai/photos?photo=25`:**
  - the slider loads at 0.9 (`/similar?w=0.9`);
  - at 0.5, 8 rows each show image and caption parts (for example 0.7416 = image 0.8295, caption 0.6538), and "39 of 39 neighbours have a caption";
  - at 1.0 the column equals image-only;
  - no page reload and no console warnings.

Not fixed (minor, recorded): the eval does not reproduce the production fallback for a query photo without a caption (1 of 156 photos lacks a caption, so the effect on the calibrated numbers is at most one query).

Run (from the build, kept): best w 0.9, precision@8 for the same make and model 0.693 against 0.677 image only, at equal coverage 0.987.
- w 0.8 scores 0.707 but with coverage 0.968, below image only, so the rule rejects it.
- The title run (marked as leaking) reaches 0.987 at w 0.
- Hybrid p95: 4.3 ms.

Checks:
- Full suite: 2024 passed. PHPStan clean, typecheck and build pass.
- Fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Parity on frankenphp identical.
- Clean-tree bench: after commit b621f09, `bench/run.sh ping --quick` exited 0 on frankenphp.
