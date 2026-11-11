---
id: spec-20261111-1701-similar-photos
title: Similar car photos with a local image encoder
type: feature
size: L
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-11; standing approval)
approved_at: 2026-11-11T15:03:49Z
created: 2026-11-11
stacks: [laravel]
slice: SL-36
brs: [BR-26, BR-27, BR-90, BR-91, BR-96]
---

# Similar car photos with a local image encoder

## Summary

Every imported listing photo (`listing_photos`, SL-27) is embedded by a local image encoder running as a DDEV sidecar. The vectors go into pgvector with the encoder's model label. For a photo, the app shows up to 8 nearest photos of **other** listings, in two places:

- on `/ai/photos`, next to the extraction detail;
- on the listing page, under the hero image.

A minimum score and a near-duplicate cutoff (0.98) apply, and results are cached in Redis (BR-96). The encoder is a feature `embeddings-photos` in `AiProviders`, shown on `/ai/settings`. An eval measures same make and model precision@8 and latency, and a capability guide explains it.

## Context

- **Problem**: the lab has 156 real photos with extractions, but nothing compares photos with each other. SL-37 (text-to-photo search), SL-38 (hybrid similarity) and SL-39 (duplicates) all need photo vectors. Ollama does not serve image-embedding models, so a separate encoder is needed.
- **User impact**: a learner sees image embeddings work end to end: a second model server beside Ollama, vectors in pgvector, nearest-neighbour search with thresholds, caching, and a measured quality number.

## Scope

### In scope

- **Encoder sidecar**: `.ddev/docker-compose.photo-encoder.yaml` (a new file; `.ddev/config.yaml` is not touched).
  - **Default:** the Infinity embedding server (`michaelf34/infinity`, CPU build, pinned by digest), serving `openai/clip-vit-base-patch32` (512 dimensions) through its OpenAI-style `/embeddings` endpoint. The endpoint accepts images, sent as base64 data URLs.
  - **Fallback:** the build checks that the image runs on arm64 under OrbStack. If it does not, it uses a small pinned `python:3.12-slim` image with `open_clip` and a 30-line HTTP endpoint with the same request and response shape. The choice and the reason go in `decisions.md`.
  - **Model cache:** model weights are cached in a named Docker volume, so a restart does not download them again.
  - **Resources:** memory is limited to 2 GB, and the service answers `GET /health`.
- **Provider and feature**:
  - A provider entry `photo-encoder` in `config/ai.php`: driver `image-encoder` (an app-level driver, not an SDK one), `url` (default `http://photo-encoder:7997`), `timeout`, and `models.image_embeddings.default` with its `dimensions` (512) and an `available` list.
  - A feature key `embeddings-photos` of kind `image-embeddings`, with `AI_EMBEDDINGS_PHOTOS_PROVIDER` / `_MODEL`.
  - The settings page (SL-52) lists it and offers only image-encoder providers and their `available` models.
  - A model whose dimensions are not 512 is refused, the same rule SL-51 applies to embeddings.
- **Storage**:
  - A migration adds to `listing_photos` the columns `embedding vector(512)` (nullable), `embedding_model` (string, nullable) and `embedded_at`.
  - It adds an HNSW cosine index.
  - The search filters on the model label with an iterative scan, as in SL-51.
- **Embedding command** (`photos:embed {--limit=} {--fresh} {--wait}`):
  - It queues jobs on the `ai-vision` queue, in batches of 16 photos per encoder call.
  - It skips photos already embedded with the current label.
  - It writes one `ai_runs` row per batch (agent `PhotoEncoder`, provider, model, latency, photo count; BR-90).
  - It prints progress and a summary in the `listings:embed` style.
  - A dimension mismatch or an unreachable encoder exits 1 before any batch.
- **Similar photos** (`SimilarPhotosService::for(int $photoId)`):
  - **Query:** the nearest neighbours by cosine similarity among photos embedded with the current label, excluding every photo of the same listing.
  - **Filter:** it drops matches with similarity at or above `near_duplicate_cutoff` (default 0.98) and below `min_score` (default 0.80, calibrated once on the eval set and recorded in `decisions.md`).
  - **Limit:** at most 8 results, in order (BR-96).
  - **Cache:** results are cached in Redis under `similar-photos:v1:{label}:{photo_id}` as plain arrays (SL-52 lesson), for 1 hour. `photos:embed` forgets the affected keys, and a `--fresh` run bumps a version counter in the key.
  - **No vector:** a photo without a vector, or a label mismatch, returns an empty result with a reason (`not embedded` / `index built with another model; run photos:embed --fresh`) and never errors.
- **UI**:
  - **`/ai/photos` detail panel:** a "Similar photos" strip of up to 8 thumbnails, each with similarity, the listing title, and whether its make and model match. A "how it was found" line shows the model label, the cutoffs and whether the result came from the cache.
  - **Listing page** (`resources/views/listings/show.blade.php`): under the hero image, a "Similar cars" block of up to 8 linked photos, rendered server-side only when the listing has a photo and at least one match qualifies; otherwise no markup at all (BR-96). The block uses existing Tailwind utilities where possible.
- **Eval** (`photos:similar-eval {--k=8}`):
  - Over every embedded photo it records:
    - precision@8 for the same make and model as the query photo's class;
    - precision@8 for the same make;
    - the share of queries with at least one result;
    - query latency p50 and p95, with and without the cache;
    - embedding throughput (photos per second, from `photos:embed` runs).
  - It writes `bench/results/photo-similar/<date>/run.json`, and `BENCHMARKS.md` gets a house-format section (BR-26).
  - The orchestrator runs it once after the build.
- **Guide and docs**:
  - `resources/guides/similar-photos.php` (kind capability, six tabs), and the photos guide's `related` field points to it.
  - The providers guide capability table gains the image-encoder row.
  - Learning guide §16 lists the new files.
  - The README covers starting the sidecar and running `photos:embed`.

### Out of scope

- Text-to-photo search (SL-37), hybrid scores (SL-38), duplicate clustering (SL-39).
- Cloud image embeddings.
- A GPU build.

## Must NOT change

- **MNC-1**: For a listing with no photo (listing 1 among them), the listing page HTML is byte-identical, so the parity snapshots of the three modes hold.
- **MNC-2**: SL-27 extraction behaviour, its tables and results are unchanged. The new columns are additive.
- **MNC-3**: Existing features and the settings rows keep their behaviour; `embeddings-photos` is a new row.
- **MNC-4**: The suite stays offline: the encoder is faked with `Http::fake`, and vectors in tests are generated.
- **MNC-5**: `.ddev/config.yaml` is unchanged.

## Acceptance criteria

- **AC-1**: WHEN `ddev restart` runs with the new compose file THE SYSTEM SHALL start the encoder, answer `GET /health`, and keep the model weights in a named volume across restarts.
- **AC-2**: WHEN `photos:embed` runs THE SYSTEM SHALL store a 512-d vector and the model label for every imported photo not yet embedded with that label, in batches with one `ai_runs` row per batch, and IF the encoder is unreachable or its dimensions are not 512 THEN exit 1 before any batch.
- **AC-3** (BR-96): WHEN similar photos are requested for a photo THE SYSTEM SHALL return at most 8 photos of other listings, ordered by similarity, excluding any photo of the same listing, any match at or above the near-duplicate cutoff, and any below the minimum score.
- **AC-4**: WHEN the same request repeats within the hour THE SYSTEM SHALL serve it from Redis, and WHEN `photos:embed` changes vectors THE SYSTEM SHALL not serve stale results for the affected photos.
- **AC-5** (BR-96): WHEN a listing page is rendered for a listing that has a photo with qualifying matches THE SYSTEM SHALL show the "Similar cars" block with up to 8 linked photos; OTHERWISE it SHALL render no block markup at all.
- **AC-6**: WHEN `/ai/photos` shows a photo's detail THE SYSTEM SHALL show the similar strip with similarity, listing, make and model match, the model label, the cutoffs and the cache status.
- **AC-7** (BR-91): WHEN `embeddings-photos` is switched on `/ai/settings` to another available model THE SYSTEM SHALL require the re-index acknowledgement, and similar photos SHALL report "index built with another model" until `photos:embed --fresh` runs.
- **AC-8** (BR-26): WHEN `photos:similar-eval` runs THE SYSTEM SHALL write the metrics listed in scope, and `BENCHMARKS.md` SHALL present them in the house format.
- **AC-9** (BR-27): WHEN `/ai/guides/similar-photos` renders THE SYSTEM SHALL show six non-empty tabs, and the no-references test SHALL pass on it and on `/ai/photos`.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Latency | Similar photos for one photo: p95 under 50 ms uncached and under 5 ms from cache, on 156 photos, frankenphp | eval run.json, manual step 3 |
| **NFR-2** | Resources | The encoder container is capped at 2 GB and is idle-safe; embedding runs on the `ai-vision` queue, never in a web request | compose file review plus `PhotoEmbedTest` (queue name) |
| **NFR-3** | Safety | The listing page block takes only ids from the database; the encoder URL comes from config; the page never shows encoder errors to visitors | `SimilarPhotosPageTest` |

## Rollout

- **Flag**: none. With no vectors the block and strip are empty.
- **Deploy order**: the compose file, then `ddev restart`, the migration, `photos:embed`, then the eval.
- **Rollback**:
  - Revert the code.
  - Run `migrate:rollback` (it drops the columns).
  - Remove the compose file and `ddev restart`.
  - The model-weights volume may stay or be pruned by the owner.

## Boundaries

- **Always**:
  - Pin the image by digest.
  - Fake the encoder in tests.
  - Go through `AiProviders`.
  - Cache plain arrays.
  - Update the guide, §16, the README and `BENCHMARKS.md`.
- **Ask first**:
  - Any `.ddev/config.yaml` change.
  - A GPU or a model over 1 GB.
  - A new Composer or npm package.
- **Never**:
  - Show a photo of the same listing as "similar".
  - Embed in a web request.
  - Commit model weights or photos.

## Technical context

- **Related code**:
  - SL-27: `listing_photos`, `PhotoLabService`, `EloquentPhotoLabRepository`, `Photos.vue`, `resources/guides/photos.php`.
  - SL-51/52: `AiProviders` features and kinds, `AiSettingsService`, the `/ai/settings` rows.
  - Embeddings: `ListingEmbeddingService` (batch and job pattern), `EloquentSemanticSearchRepository` (HNSW, iterative scan, label filter).
  - `resources/views/listings/show.blade.php` (hero image).
  - `.ddev/docker-compose.*.yaml` (existing sidecars).
- **Already implemented**: 156 photos with files on the public disk and the storage link; the `ai-vision` queue; the guide engine.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **CLIP ViT-B/32 at 512 dimensions is the default.**
  - It is the smallest common image encoder (about 600 MB), fast on CPU, and well known.
  - SigLIP base (768-d) is listed as an experiment in the guide but needs its own column size. The `available` list holds only 512-d models.
- **The minimum score is calibrated once** on the eval set: the score where precision@8 for the same make stops rising, rounded to 0.05, recorded in `decisions.md`. The near-duplicate cutoff stays at 0.98 (BR-96).
- **The listing page shows the block only when there is something to show.** That keeps MNC-1: a listing with no photo is byte-identical.
- **The main asset fingerprint may change** if the block uses Tailwind classes not used before. That re-baseline is allowed only with its reason in the `BENCHMARKS.md` "Response parity" note. The parity request set must stay identical (listing 1 has no photo).

## Clarity gate answers (binding, self-decided 2026-11-11)

1. **Which photo.** The listing page uses the listing's photo (SL-27 gives each listing at most one; with several, the lowest id). Results hold at most one photo per other listing, the best-scoring one.
2. **Second model for AC-7.** `available` lists `openai/clip-vit-base-patch32` and `laion/CLIP-ViT-B-32-laion2B-s34B-b79K`; both are 512-d. The build checks that the chosen server can load the second one. If it cannot, AC-7 is verified with a faked second model only, and that is recorded.
3. **Cache.**
   - The key is `similar-photos:{label}:{generation}:{photo_id}` in the default cache store (Redis locally), holding plain arrays.
   - The generation is an integer under `similar-photos:generation:{label}`. It is incremented after every `photos:embed` run that stored at least one vector, which invalidates all results for that label (156 photos, so "affected" means all). `--fresh` does the same. There is no literal `v1`.
4. **Calibrating `min_score`.**
   - For each threshold from 0.60 to 0.95 in steps of 0.05, the eval records precision@8 for the same make and for the same make and model. The denominator is the number of results returned (at most 8), alongside coverage (the share of queries with at least one result).
   - The chosen threshold maximises precision for the same make × coverage. It becomes the config default `ai.similar_photos.min_score` (env `AI_SIMILAR_MIN_SCORE`), and the curve is kept in `run.json` and `decisions.md`. 0.80 is only the placeholder until then.
5. **Ground truth.** The make and model of the photo's listing, which equal the photo's Stanford class by the SL-27 import rule. Both come from the same catalogue, so an exact string compare is enough. The UI's "same make and model" uses the same rule.
6. **Score scale.** Vectors are L2-normalised before storage, and similarity is 1 − pgvector cosine distance. CLIP image-to-image scores sit high for any two car photos, which is exactly why the threshold is calibrated rather than guessed.
7. **`photos:embed` mechanics.**
   - **Probe:** it first makes one synchronous probe call that encodes a generated 8×8 white JPEG, checking reachability and that the vector has 512 dimensions; any failure exits 1 before queueing.
   - **Jobs:** one job per batch of 16 photos.
   - **Failures:** a missing file is skipped and counted. A failing batch writes one failed `ai_runs` row and leaves its photos un-embedded, while the other batches continue.
   - **`--wait`:** it polls every 2 s until the command's photos are embedded or failed, with a 30-minute limit. If no progress happens for 60 s it prints "start a worker: ddev artisan queue:work --queue=ai-vision".
8. **Fetching candidates.**
   - The query fetches 40 nearest candidates for the current label (`ef_search` 100, iterative scan `strict_order`).
   - In PHP it then drops the same listing, scores at or above the cutoff, scores below `min_score`, and repeat listings.
   - It returns up to 8, possibly fewer. Ties are ordered by photo id ascending.
9. **Encoder checks.**
   - **Who checks:** the build agent checks the image's `linux/arm64` manifest (`docker manifest inspect`) and, after `ddev restart`, a live `/health` and embed probe. A failure switches to the fallback, recorded in `decisions.md`.
   - **API shape (both servers):**
     - request: `POST /embeddings` with `{"model": "<id>", "input": ["data:image/jpeg;base64,…"]}`;
     - response: `{"data": [{"index": 0, "embedding": [ … ]}]}`;
     - health: `GET /health` answers 200.
   - `embedded_at` is a nullable `timestampTz`.
10. **Measurement terms.**
    - NFR-1 latency is measured by the eval as the in-process service call (the database query, filtering and cache), excluding HTTP, against the same PostgreSQL the app uses; the manual step reloads the lab page for a feel check.
    - Throughput is photos per second from the `PhotoEncoder` `ai_runs` rows of the latest `photos:embed` run: the sum of photos divided by the sum of latency.
    - "Three modes" means frankenphp, frankenphp-worker and frankenphp-octane.
    - "House format" means the existing `BENCHMARKS.md` sections: a table, "What the numbers say" and "Interview story".
    - The base URL https://ai-lab.ddev.site is correct (SL-49).

## Open questions

- [x] Clarity gate (10 items) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90, BR-91 and BR-96 come from a draft brief applied in every slice (standing approval).
