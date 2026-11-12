---
id: spec-20261112-1000-hybrid-similar-photos
title: Hybrid similar photos (image plus caption)
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-12; standing approval)
approved_at: 2026-11-12T08:04:44Z
created: 2026-11-12
stacks: [laravel]
slice: SL-38
brs: [BR-26, BR-27, BR-90, BR-96]
---

# Hybrid similar photos (image plus caption)

## Summary

Each photo gets a second vector: the CLIP **text** encoding of its SL-27 caption, stored next to the image vector with its label. Similar photos (SL-36) can then rank by a hybrid score:

`score = w · image similarity + (1 − w) · caption similarity`

`w` is configurable (default 0.7). The SL-36 exclusions and cutoffs still apply.

- **`/ai/photos`:** shows image-only and hybrid results side by side, with a weight slider.
- **The eval:** sweeps `w` from 0 to 1 against image-only on precision@8 for the same make and model, and records the best `w`.
- **Shared code:** SL-36 and SL-37 now use one nearest-by-vector repository.

## Context

- **Problem**: image vectors miss what a caption states (a body type, a badge the vision model read), and captions miss what the image shows. The lab cannot yet show how a weighted combination behaves.
- **Leakage risk**: the listing title holds the make and model, which is exactly what the eval grades. A title text vector would make hybrid look perfect while measuring nothing. So **the caption is the only text source by default**. A `title` fallback exists as an option for the lab's comparison, and the eval reports it separately, marked as leaking.
- **User impact**: a learner moves a slider and sees ranks change, and reads in the eval at which weight the caption helps and where it starts to hurt.

## Scope

### In scope

- **Caption vectors.**
  - A migration adds to `listing_photos`: `text_embedding vector(512)` (nullable), `text_embedding_model` and `text_source` (`caption` or `title`), `text_hash` (sha256 of the encoded text) and `text_embedded_at`. It also adds an HNSW cosine index on `text_embedding`, for later use.
  - **`photos:embed --text`** encodes the text of every photo whose `text_hash` or label is stale:
    - the caption of the photo's newest completed extraction with the effective `photo-vision` model and the current prompt version, wrapped by the SL-37 template (`a photo of {caption}`);
    - with `--text-source=title`, photos without a caption use the listing title instead;
    - the label is the same `embeddings-photos` label as the image vectors.
  - Batches of 16 run on `ai-vision`, with one `ai_runs` row per batch (agent `PhotoTextEncoder`). It reuses the SL-36 probe and `--wait` mechanics.
  - Photos with no text source keep a null text vector.
- **One nearest-by-vector repository.**
  - `PhotoVectorRepository` has one method: `nearest(VectorQuery $query)`. The query names the column (`embedding` or `text_embedding`), the label, either a vector literal or a source photo id, the candidate count and `ef_search`.
  - SL-36 `nearest()` and SL-37 `nearestToVector()` both delegate to it. They keep the same SQL hints (`SET LOCAL ef_search`, `enable_seqscan = off`, `iterative_scan = strict_order`) and the same mapping.
  - The duplicate repository code from SL-37 is removed.
- **Hybrid ranking.** `SimilarPhotosService::for(int $photoId, ?float $w = null)`:
  - When `w` is null or 1.0 it behaves exactly as SL-36 (image only).
  - Otherwise it fetches the 40 nearest **image** candidates, then reads each candidate's caption similarity to the query photo in one query: `1 − (text_embedding <=> query text_embedding)` for those ids.
  - It scores each candidate with `w · image + (1 − w) · text`. When either photo lacks a text vector, the candidate's score is its image similarity, and it is flagged `image-only`.
  - It applies the SL-36 rules: same listing excluded; near-duplicate cutoff on **image** similarity (0.98); one per listing; `min_score` on the **hybrid** score, from `ai.similar_photos.hybrid_min_score` (calibrated by the eval, default the SL-36 `min_score`); at most 8.
  - Ties are ordered by photo id.
  - The cache key adds `w` (two decimals) and the text label generation. `photos:embed --text` bumps the generation.
- **`/ai/photos` side by side.**
  - The detail panel shows two columns, "Image only" and "Image + caption (w = …)", each up to 8 photos with their scores. A hybrid row shows the image and caption parts and the image-only flag where it applies.
  - A range slider sets `w` from 0 to 1 in steps of 0.1 (default from config). It fetches `GET /ai/photos/{photo}/similar?w=` (JSON, lab only, throttled at 60 per minute per IP) without a page reload.
  - A line shows how many of the photo's neighbours have captions.
  - The listing page block (SL-36) stays image-only.
- **Eval.** `photos:similar-eval --hybrid`:
  - For `w` in {0, 0.1, …, 1.0} over all photos with an image vector, it records precision@8 for the same make and model, precision@8 for the same make, coverage, and the share of compared pairs that had both text vectors.
  - Within each `w`, the `hybrid_min_score` threshold is calibrated as in SL-36 (maximise precision × coverage over 0.60–0.95; on a plateau, the middle).
  - It reports image-only (w = 1.0) as the baseline, plus a separate `title` run marked `leaks_ground_truth: true`.
  - Output goes to `bench/results/photo-similar/<date>/hybrid.json`. The best `w` and its `hybrid_min_score` become config defaults (env `AI_SIMILAR_HYBRID_WEIGHT`, `AI_SIMILAR_HYBRID_MIN_SCORE`). `BENCHMARKS.md` gets a section.
- **Guide and docs.**
  - The similar-photos guide gains a "Hybrid" step in How it works and a recipe for changing `w` and the text source.
  - Its failure modes gain "title leakage" and "few captions".
  - Its Numbers read `hybrid.json`, and an experiment asks whether captions help.
  - §16 lists the new files.

### Out of scope

- Hybrid on the public listing page.
- Text vectors from any model other than the photo encoder's.
- Duplicate clustering (SL-39).
- Re-running vision extraction automatically (the run step does it once).

## Must NOT change

- **MNC-1**: With `w` null or 1.0, similar photos, their cache, the listing page block and the eval's image-only numbers are identical to SL-36. The SL-36 tests pass unchanged.
- **MNC-2**: SL-37 text search behaves the same (its tests pass unchanged) after its repository delegates to the shared one.
- **MNC-3**: The suite stays offline, and listing pages without matches stay byte-identical (parity).
- **MNC-4**: The main asset fingerprint is unchanged.

## Acceptance criteria

- **AC-1**: WHEN `photos:embed --text` runs THE SYSTEM SHALL store a 512-d caption vector, label, source and hash for every photo with a caption from the effective vision model and the current prompt version. It SHALL skip photos whose hash and label are current, and SHALL leave photos without a caption null, unless `--text-source=title` is given.
- **AC-2**: WHEN hybrid similar photos are requested with `w` THE SYSTEM SHALL rank the 40 nearest image candidates by `w · image + (1 − w) · caption` similarity, use image similarity alone where a caption vector is missing, and apply the same-listing exclusion, the image near-duplicate cutoff, one per listing, `hybrid_min_score` and the limit of 8.
- **AC-3**: WHEN `w` is null or 1.0 THE SYSTEM SHALL return exactly the SL-36 result.
- **AC-4**: WHEN the learner moves the slider on `/ai/photos` THE SYSTEM SHALL show the hybrid column for that `w` next to the unchanged image-only column, with the image and caption parts and the image-only flags, without a page reload.
- **AC-5** (BR-26): WHEN `photos:similar-eval --hybrid` runs THE SYSTEM SHALL write the per-`w` metrics, the image-only baseline and the title run marked as leaking, and record the best `w`. `BENCHMARKS.md` SHALL present them.
- **AC-6**: WHEN SL-36 and SL-37 search THE SYSTEM SHALL use the shared nearest-by-vector repository, and no second copy of the HNSW query SHALL remain.
- **AC-7** (BR-27): WHEN `/ai/guides/similar-photos` renders THE SYSTEM SHALL include the hybrid step, the recipe, the two failure modes and the hybrid numbers.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Latency | A hybrid request adds at most one query over the 40 candidates; uncached p95 under 80 ms in-process | eval latency in hybrid.json |
| **NFR-2** | Honesty of the metric | The title source is never the default and is always reported as leaking | `PhotoHybridEvalTest` |

## Rollout

- **Flag**: none. Without text vectors, hybrid equals image-only.
- **Deploy order**:
  1. Migration.
  2. `photos:extract` for the remaining photos (optional but recommended).
  3. `photos:embed --text`.
  4. The eval.
- **Rollback**: revert. `migrate:rollback` drops the text columns.

## Boundaries

- **Always**:
  - Keep image-only identical at w = 1.
  - Use the shared repository.
  - Cache plain arrays.
  - Fake the encoder and the vision agent in tests.
- **Ask first**:
  - Any new model or container.
  - Making title the default source.
- **Never**:
  - Count title-based results as the headline number.
  - Change the listing page block.
  - Compare text and image vectors from different labels.

## Technical context

- **Related code**:
  - `app/Services/SimilarPhotosService.php`, `app/Repositories/EloquentSimilarPhotosRepository.php`;
  - `app/Repositories/EloquentPhotoTextSearchRepository.php`, `app/Services/PhotoTextSearchService.php`;
  - `app/Ai/PhotoEncoderClient.php` (`embedText`);
  - `app/Services/PhotoEmbeddingService.php` (batches, probe, `--wait`);
  - `app/Services/SimilarEvalService.php`;
  - `photo_extractions` (captions);
  - `resources/js/ai/Pages/Lab/Photos.vue`;
  - `resources/guides/similar-photos.php`.
- **Already implemented**: 156 image vectors, 51 completed gemma extractions, the text encoder, the SL-36 calibration method.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **The caption is the only default text source** because the title is the eval's ground truth (leakage). The title run exists only to show that leakage, with numbers.
- **Hybrid reranks image candidates rather than unioning a separate caption search.** Similar photos stay visually anchored, and one query over 40 ids keeps it cheap. Recall from captions alone is out of scope.
- **The near-duplicate cutoff stays on image similarity**, because a duplicate is a visual fact. Captions can be near-identical for different cars.
- **The run step first extracts captions for all 156 photos** (`photos:extract` plus a worker, about 10 minutes with gemma4:12b). With 51 captions the hybrid effect would be measured on a third of the data. The share of compared pairs with both captions is reported either way.

## Clarity gate answers (binding, self-decided 2026-11-12)

1. **Where title vectors go.**
   - Hybrid ranking and every caption-based eval run read only rows with `text_source = 'caption'`.
   - The column is never shared by a title run: the eval's title run encodes listing titles **in memory** (one `embedText` per photo, in batches) and never writes the database.
   - `photos:embed --text-source=title` is removed from scope; the lab stays caption-only, so nothing has to be reverted.
   - The title run sweeps every `w`, like the caption run.
2. **Best `w`.**
   - The `w` with the highest precision@8 for the same make and model, with coverage at least that of image-only. On a tie, the higher `w` wins (closer to the image-only baseline).
   - It must beat image-only by at least 0.01. Otherwise the default stays 1.0 (image-only), and the guide and `BENCHMARKS.md` say captions did not help.
   - "Config default" means the default in `config/ai.php`.
3. **Missing caption vectors.**
   - **Query photo:** if it has no caption vector, the hybrid column equals image-only, uses the SL-36 `min_score`, and shows "no caption for this photo".
   - **Candidate:** a candidate without a caption vector is scored on image similarity and flagged `image-only`, and `hybrid_min_score` applies to every row.
   - This mixing is intended and taught: it shows why missing modalities need a policy. The eval reports the share of candidates scored image-only.
4. **w = 1.0.**
   - `w` is parsed and rounded to one decimal. Any value equal to 1.0 (or no value) calls the unchanged SL-36 path, with the SL-36 cache key, `min_score` and 40 candidates (SL-36 already uses 40).
   - The text generation enters only the hybrid key, so SL-36 keys are unchanged (MNC-1).
   - `for($id, null)` is image-only. The lab's slider default comes from config (0.7), not from `null`.
5. **Text label and generation.**
   - The text label is the `embeddings-photos` label (the same one as the image vectors).
   - A text row is stale when its label differs, or when `text_hash` differs from the sha256 of the current templated caption.
   - If the current extraction has no caption any more (a new vision model or prompt version without a completed row), the text vector is set to null on the next `--text` run.
   - The generation is stored under `similar-photos:text-generation:{label}` and is bumped when a run changes at least one row.
6. **Lab endpoint.**
   - It sits inside the `/ai` route group, under the same local-only lab middleware as the other lab endpoints.
   - `w` is required, numeric, from 0 to 1, rounded to one decimal; anything else is 422.
   - A photo with no image vector answers 200 with an empty list and the SL-36 reason.
   - The limiter is `photo-similar`, 60 per minute per IP.
   - Response:

     ```json
     {"w": 0.7, "label": "…", "min_score": 0.0, "cached": false, "query_has_caption": true, "candidates": 40, "candidates_with_caption": 31, "results": [{"photo_id": 0, "listing_id": 0, "listing_title": "…", "url": "…", "score": 0.0, "image": 0.0, "caption": 0.0, "image_only": false}]}
     ```

     Neighbours with captions means the 40 candidates with a caption vector.
7. **Eval metrics.**
   - precision@8 = relevant results ÷ results returned (at most 8); a query with no results counts 0.
   - Coverage = the share of queries with at least one result.
   - The grid runs from 0.60 to 0.95 in steps of 0.05. A plateau is consecutive thresholds whose objective is within 0.001 of the maximum; the shipped value is its middle (the lower middle when the count is even).
   - Compared pairs means the returned results.
   - The baseline at w = 1.0 uses the SL-36 `min_score` unchanged.
8. **NFR-1 and the remaining terms.**
   - NFR-1 is measured by the eval over all embedded photos at the chosen `w`, uncached after one warm-up pass, reporting p95. Over 80 ms is recorded as a miss, not a failure.
   - The "no other repository holds the HNSW query" check lives in `tests/Feature/PhotoVectorRepositoryTest.php` (the traceability for AC-6 points there).
   - "Without a page reload" for AC-4 is checked in the browser step; the endpoint shape is covered by `tests/Feature/PhotoSimilarEndpointTest.php`.
   - **Effective `photo-vision` model:** the resolver's choice (SL-52).
   - **§16:** section 16 of `docs/learning-guide.md`.
   - **Ties:** photo id ascending.
   - **HNSW index on `text_embedding`:** dropped from scope (no caller yet).

## Open questions

- [x] Clarity gate (8 items) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90 and BR-96 come from a draft brief applied in every slice (standing approval).
