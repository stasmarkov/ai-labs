---
id: spec-20261111-2206-photo-text-search
title: Search car photos by text
type: feature
size: M
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-11; standing approval)
approved_at: 2026-11-11T20:07:58Z
created: 2026-11-11
stacks: [laravel]
slice: SL-37
brs: [BR-26, BR-27, BR-90, BR-91]
---

# Search car photos by text

## Summary

A learner types a description ("red estate with a roof box"). The app encodes it with the **text side** of the same CLIP model that embedded the photos in SL-36. Text and image vectors share one space only when they come from the same model, so the query is checked against the stored photo label. The app then returns the best-matching photos from `listing_photos` through the existing HNSW index.

- **Where:** a lab page `/ai/photo-search` shows the query, the prompt sent to the encoder, the results with scores and their listings, and the cutoff.
- **Cutoff:** a minimum score is calibrated on a small labelled query set.
- **Checks and logging:** the input guard runs first, and every query is an `ai_runs` row.
- **Cache:** results are cached in Redis as plain arrays.
- **Guide:** a capability guide explains it.

## Context

- **Problem**: SL-36 compares photos with photos; there is no way to find photos from words. CLIP's text tower makes that possible with the encoder already running.
- **User impact**: the learner sees cross-modal retrieval, sees why text-to-image scores are low and need a calibrated cutoff, and sees how prompt wording ("a photo of …") changes results.

## Scope

### In scope

- **Text encoding.**
  - `PhotoEncoderClient::embedText(string $text): list<float>` calls the same `/embeddings` endpoint with `"modality": "text"`, the configured `embeddings-photos` model, and a 512-d check.
  - Vectors are L2-normalised.
  - The build checks with a live call that Infinity encodes text for both available CLIP models. If it does not, it records the gap in `decisions.md`.
- **Prompt template.**
  - The query is wrapped by a versioned template before encoding.
  - `resources/prompts/photo-search.v1.md` holds the single line `a photo of {query}`.
  - The prompt version is recorded on the run row.
  - Config `ai.photo_search.prompt` (env `AI_PROMPT_PHOTO_SEARCH`) selects it.
- **Search service** (`PhotoTextSearchService::search(string $query)`):
  1. **Validation:** the query is trimmed text of 2–200 characters (`ValidUtf8Text`).
  2. **Input guard:** a hit is a refusal (422 `refused`, a `refused` run row, no encoder call).
  3. **Index check:** if the index holds no vector with the current label, it answers 409 `index_not_built` with `ddev artisan photos:embed --fresh`.
  4. **Encode** the templated text.
  5. **Search:** fetch the nearest 40 photos for the current label (`ef_search` 100, iterative scan, as in SL-36).
  6. **Filter:** keep at most one photo per listing and drop scores below `min_score`.
  7. **Return** up to 12 results, best first, ties ordered by photo id.
- **Run log.** One `ai_runs` row per query (BR-90): agent `PhotoTextSearch`, the provider and model of `embeddings-photos`, the prompt version, latency, status `completed` or `refused`, and `inputs` 1. A cache hit writes no row; the page says "from cache".
- **Cache.**
  - **Key:** `photo-search:{label}:{generation}:{prompt_version}:{thresholds hash}:{sha1(normalised query)}`. The normalised query is lowercased with spaces collapsed.
  - **Generation:** the SL-36 generation counter, so `photos:embed` invalidates searches too.
  - **Value and TTL:** plain arrays, 1 hour.
- **HTTP.**
  - `GET /api/photos/search?q=` returns JSON with `query`, `prompt`, `label`, `min_score`, `cached` and `results`. Each result has `photo_id`, `url`, `listing_id`, `listing_title` and `score`.
  - It is throttled at 30 requests per minute per IP.
  - Errors: 422 for validation or a refusal, 409 `index_not_built`, 503 `encoder_unavailable`.
- **Lab page `/ai/photo-search`.**
  - A query field (prefilled from `?q=`, with the SL-48 preset convention).
  - Example chips: "red convertible", "white SUV in the snow", "BMW 3 Series", "a car on a race track".
  - The results grid shows a thumbnail, score, listing title, and a link to `/ai/photos?photo={id}`.
  - Tags show the label, the prompt sent, the cutoff and the source (database or cache).
  - A "How it works" panel uses the steps of the photo-search guide (lifecycle events `request_sent`, `response_received`, `refused`, `request_failed`).
  - Empty states: no photos embedded, and no result above the cutoff.
  - There is a navigation entry.
- **Labelled set and eval.**
  - **Query set.** `bench/photo-search/queries.v1.jsonl` holds 30 queries written for this eval, not copied from any indexed text.
    - Each query has a relevance rule: any of `make`, `model`, `body_type` (catalogue body types) and `colour`.
    - The make, model and body type come from the photo's listing and the catalogue.
    - Colour comes from the photo's completed SL-27 extraction (`dominant_colours`, from the gemma4:12b rows). Queries with a colour rule are graded only over photos that have a completed extraction, and the pool size is recorded.
    - The mix: 12 make or make-and-model queries, 8 body-type queries, 10 colour-plus-body queries.
  - **`photos:search-eval`.** It runs every query uncached and writes `bench/results/photo-search/<date>/run.json`, recording:
    - precision@8 and MRR;
    - the share of queries with at least one relevant result in the top 8;
    - latency p50 and p95 (in-process, as in SL-36);
    - a threshold curve from 0.15 to 0.35 in steps of 0.025.

    The chosen `min_score` maximises mean precision@8 × coverage; it becomes the config default `ai.photo_search.min_score` (env `AI_PHOTO_SEARCH_MIN_SCORE`) and is recorded in `decisions.md`.
  - **Template comparison.** A second run compares the bare query with the `a photo of {query}` template (same metrics) and records it.
  - `BENCHMARKS.md` gets a section in the existing format: a table, "What the numbers say" and "Interview story" (BR-26).
- **Guide and docs.**
  - `resources/guides/photo-search.php` (capability, six tabs).
  - The similar-photos and photos guides link to it in `related`.
  - §16 lists the new files, and the README gets a short paragraph.

### Out of scope

- Hybrid text-and-image ranking (SL-38) and duplicates (SL-39).
- Showing search on the public listing pages.
- Multilingual queries; CLIP ViT-B/32 is English-only, and the guide says so.

## Must NOT change

- **MNC-1**: SL-36 similar photos, their cache and `photos:embed` behave as before; the generation counter is only read here.
- **MNC-2**: Listing pages, other lab pages and parity are unchanged.
- **MNC-3**: The suite stays offline, with the encoder faked by `Http::fake`.
- **MNC-4**: The main asset fingerprint is unchanged.

## Acceptance criteria

- **AC-1**: WHEN a learner searches "red convertible" THE SYSTEM SHALL encode the templated text with the `embeddings-photos` model, and return up to 12 photos with the current label, at most one per listing, best first, each at or above `min_score`.
- **AC-2**: IF the query matches the input guard THEN THE SYSTEM SHALL answer 422 `refused` with a `refused` run row and SHALL NOT call the encoder.
- **AC-3**: IF no photo is embedded with the current label THEN THE SYSTEM SHALL answer 409 `index_not_built` naming `ddev artisan photos:embed --fresh`, without calling the encoder.
- **AC-4** (BR-90): WHEN a query is encoded THE SYSTEM SHALL write one `ai_runs` row with provider, model, prompt version and latency; a cache hit SHALL write none and SHALL be reported as cached.
- **AC-5**: WHEN `photos:embed` stores vectors THE SYSTEM SHALL not serve photo-search results cached before it.
- **AC-6**: WHEN `/ai/photo-search?q=…` is opened THE SYSTEM SHALL show the query, the prompt sent, the label, the cutoff, the source and the results with score, title and a link to the photo detail.
- **AC-7** (BR-26): WHEN `photos:search-eval` runs THE SYSTEM SHALL write the metrics and the threshold curve for both prompt variants. `BENCHMARKS.md` SHALL present them.
- **AC-8** (BR-27): WHEN `/ai/guides/photo-search` renders THE SYSTEM SHALL show six non-empty tabs. The no-references test SHALL pass on it and on `/ai/photo-search`.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Latency | An uncached search p95 under 400 ms (one text encode plus HNSW) on the emulated encoder; cached under 10 ms; both in-process | eval run.json |
| **NFR-2** | Safety | The query is only ever text sent to the encoder and a hashed cache key; it is never put in SQL or a path; the guard runs first | `PhotoTextSearchTest` |

## Rollout

- **Flag**: none.
- **Deploy order**: one deploy. Run the eval once afterwards.
- **Rollback**: revert. There is no migration.

## Boundaries

- **Always**:
  - Use the SL-36 label and generation.
  - Fake the encoder in tests.
  - Write plain arrays to the cache.
  - Keep the guide, §16 and `BENCHMARKS.md` current.
- **Ask first**:
  - A new model or container.
  - A new Composer or npm package.
  - A schema change.
- **Never**:
  - Compare text vectors with photo vectors made by another model.
  - Call the encoder for a refused query.
  - Copy indexed text into the eval queries.

## Technical context

- **Related code**:
  - `app/Ai/PhotoEncoderClient.php`;
  - `app/Services/SimilarPhotosService.php` (generation, label, iterative scan);
  - `app/Contracts/SimilarPhotosRepositoryInterface.php`;
  - `app/Services/PromptInjectionGuard.php`;
  - `app/Ai/AiRunRecorder.php`;
  - `resources/js/ai/Pages/Lab/Photos.vue` (thumbnail pattern);
  - `app/Services/LabOverviewService.php` (navigation and capabilities);
  - `resources/guides/similar-photos.php`;
  - `docs/learning-guide.md` §16.
- **Already implemented**: the encoder sidecar, 156 photo vectors, the HNSW index, SL-27 extractions for 51 photos, the guide engine, the SL-48 presets.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **The calibration range is low on purpose.** CLIP text-to-image cosine scores for a good match sit around 0.25–0.35, unlike image-to-image (about 0.8), so the curve runs from 0.15 to 0.35.
- **The search service owns the "same model" rule** through the label check. The settings page switch (SL-52) and `photos:embed --fresh` therefore keep text and image vectors in one space.
- **Colour relevance uses the SL-27 extractions.** They are model output, not ground truth. The guide and `BENCHMARKS.md` say so, and the colour queries report their pool size.

## Clarity gate answers (binding, self-decided 2026-11-11)

1. **Relevance and calibration.**
   - **Rule:** a photo is relevant when it matches **every** field in its query's rule.
   - **Colour:** a match means the colour word is any entry of `dominant_colours` in the photo's completed extraction for `gemma4:12b` and prompt `photo-vision@v1` (the most recently updated row if there are several). `grey` and `silver` count as one colour; there are no other synonyms.
   - **precision@8:** relevant results ÷ results returned (at most 8, after the cutoff); a query with no results counts 0.
   - **Coverage:** the share of queries with at least one result.
   - **Threshold:** the eval reports the first threshold that maximises mean precision@8 × coverage. When the maximum is a plateau, the shipped default is the middle of the plateau, which keeps a margin before the drop and answers the same today. (Amended 2026-11-11 after the run: the curve was flat from 0.15 to 0.225 with a cliff from 0.25, so 0.2 ships.)
2. **Eval versus search.** The eval calls the same service method uncached, so it uses the same 40 candidates, one photo per listing and the threshold under test. It writes `ai_runs` rows with origin `eval`.
   - The bare query and the template are compared at the template's chosen threshold, and the full curve is kept for both.
   - "Indexed text" means `listing_documents.description`, `docs_chunks.body` and the SL-27 extraction captions. The test checks that no query string appears as a substring, case-insensitive, in any of them.
3. **Label.**
   - It is `{provider}/{model}` of the effective `embeddings-photos` choice, the same string SL-36 stores in `listing_photos.embedding_model`.
   - If the settings page picks a model whose label no stored vector carries, the search answers 409 `index_not_built` (no encode).
   - The live check covers `openai/clip-vit-base-patch32` and `laion/CLIP-ViT-B-32-laion2B-s34B-b79K`.
4. **Guard and refusals.**
   - A hit is `PromptInjectionGuard::match()` returning a pattern. The answer is 422 `{"code":"refused","message":…}`; a validation failure is 422 with `errors`.
   - A refused query writes a run row with status `refused`, the provider and model of the feature, latency 0 and no `inputs`. A validation failure writes no row.
5. **Casing and the cache key.**
   - The template is applied to the trimmed query with spaces collapsed and its casing **kept**. The cache key hashes that same text, so "Red Convertible" and "red convertible" are separate entries, and the `prompt` field always matches what was encoded.
   - The thresholds hash covers `min_score`, the limit (12) and the candidate count (40).
6. **Encoder failures.** A failed encode (connection, timeout or a non-2xx answer) writes a run row with status `failed`, the redacted error and latency, and answers 503 `encoder_unavailable`. The timeout is `ai.providers.photo-encoder.timeout` (default 10 s for text), with no retry.
7. **NFR-1.**
   - "Emulated encoder" means the SL-36 sidecar running as `linux/amd64` under emulation on Apple Silicon.
   - The eval measures uncached p95 by timing the service call around the encode and the database query.
   - Cached latency is measured by the eval re-running every query once from the cache and reporting that p95.
8. **Terms.**
   - **SL-48 preset convention:** `?q=` prefills the field client-side and does not auto-run.
   - **No-references test:** `tests/Feature/NoReferencesInUiTest.php`.
   - **Six tabs:** How it works, Implementation, Modify, Support, Numbers, Experiments.
   - **§16:** section 16 of `docs/learning-guide.md`.
   - **Parity:** `bench/parity.sh`.
   - **`inputs`:** the `ai_runs` column counting items in one call.
   - **Lab page:** it calls `GET /api/photos/search` from the browser, so the 30-per-minute limit applies to it too.

## Open questions

- [x] Clarity gate (8 items) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90 and BR-91 come from a draft brief applied in every slice (standing approval).
