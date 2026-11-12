---
id: spec-20261112-1157-photo-duplicates
title: Duplicate photos and the best-photo pick
type: feature
size: L
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-12; standing approval)
approved_at: 2026-11-12T09:59:39Z
created: 2026-11-12
stacks: [laravel]
slice: SL-39
brs: [BR-26, BR-27, BR-90, BR-91]
---

# Duplicate photos and the best-photo pick

## Summary

Near-duplicate photos are grouped by image vector: pairs at or above a cutoff, joined into connected components. For each group the local vision model (feature `photo-vision`, gemma4:12b by default) is asked which photo is best. The answer has a fixed shape, `{best_photo_id, reasons}`, is validated in PHP, and is retried as in SL-27.

- **Review page:** `/ai/photos/duplicates` shows each group, the chosen photo and "keep this one, remove N". It only **marks** photos and never deletes files.
- **Logging:** every judgement is an `ai_runs` row.
- **Calibration:** the cutoff is calibrated, and the pick is measured against a known answer.
- **Guide:** a capability guide explains it all.

## Context

**Finding (2026-11-12, measured on the dev database).** The 156 imported Stanford Cars photos contain **no near-duplicates**:

- the highest image similarity between two photos is 0.9398;
- 32 pairs reach 0.90 and 5 reach 0.93;
- none reach 0.95.

The import already removed exact copies by sha256. A 0.98 cutoff on this data finds nothing, and there would be nothing to calibrate or judge.

**Consequence.** The slice adds a generator of **known near-duplicates**: degraded copies of selected photos, a common real-world case (re-uploads, recompressed or cropped copies). The copies give ground truth for two questions:

- **Cutoff:** which copies a cutoff catches, and which original pairs it wrongly joins.
- **Best photo:** the original is by construction the best of its group, so the model's pick can be scored.

The copies are kept out of every earlier feature and eval (MNC-1).

**User impact.** A learner sees vector clustering, threshold calibration with precision and recall, multi-image prompting of a vision model with a checked answer, and a human-in-the-loop review that never destroys data.

## Scope

### In scope

- **Duplicate generator** (`photos:make-duplicates {--photos=20} {--seed=7} {--fresh}`):
  - **Selection:** it picks originals deterministically (seeded, ordered by sha256) among photos with an image vector.
  - **Variants:** it writes 2–4 variants per original with GD, on the same listing. The kinds are fixed and recorded per row:
    - `downscaled`: to 320 px on the long side, then back up to the original size;
    - `recompressed`: JPEG quality 25;
    - `cropped`: the central 85 %;
    - `darkened`: brightness −40;
    - `mirrored`: horizontal flip.
  - **Rows:** each variant is a `listing_photos` row with `source = 'augmented'`, `duplicate_of` (the original's id, a new nullable column), `variant` (the kind), a file on the public disk and a new sha256.
  - **`--fresh`:** removes the augmented rows and their files first.
  - **Embedding:** variants are then embedded with `photos:embed` (the command prints this).
  - **Never committed:** generated files live on the public disk like the imported photos.
- **Isolation of the variants.**
  - The shared nearest-by-vector repository (SL-38) gains a filter, `excludeAugmented`, **on by default**. Similar photos, text search, the hybrid ranking, their evals, the listing page block and the photo-search page never see augmented rows.
  - The `/ai/photos` grid shows them with an "augmented · variant" tag.
  - Only the duplicate review passes `excludeAugmented: false`.
- **Clustering** (`DuplicateClusterService`):
  - For every photo with a vector, it reads the nearest candidates (`candidates` 20, `ef_search` 100) through the shared repository, including augmented rows and **including the same listing**. The SL-36 same-listing exclusion does not apply here, because duplicates usually sit on one listing.
  - It keeps pairs with similarity ≥ `ai.photo_duplicates.cutoff` (calibrated; 0.98 until calibration) and builds connected components with union-find.
  - **Group cap:** a group larger than 6 photos is split by keeping each photo's strongest edges until every part has at most 6. Split groups are flagged, and the guide explains why.
  - **Persisted:** groups are stored in `photo_duplicate_groups` (id, `cutoff`, `label`, `created_at`) and `photo_duplicate_members` (`group_id`, `photo_id`, `max_similarity`).
  - **Re-running:** `photos:duplicates {--judge} {--wait}` rebuilds the groups for the current label and cutoff, deleting older groups of that label and their unreviewed judgements.
- **Best-photo judgement.**
  - **Agent:** `PhotoBestPickAgent` uses `HasStructuredOutput`, the versioned prompt `resources/prompts/photo-best-pick.v1.md` and the `photo-vision` feature (provider and model by configuration, BR-91).
  - **Input:** it attaches every photo of a group in one call (1–6 images), each introduced by a text line `Photo id <id>:`.
  - **Schema:**
    - `best_photo_id`: an integer that must be one of the group's ids;
    - `reasons`: 1–4 short strings of at most 120 characters each;
    - `issues`: per non-best photo, an object `{photo_id, problem}`, where `problem` is one of `blurry`, `low_resolution`, `compression`, `cropped`, `too_dark`, `mirrored`, `other`.
  - **Validation and retry:** the answer is validated in PHP. On invalid output it retries up to 3 attempts in total; a transport error is one attempt with no retry (SL-27 rules). Each attempt is an `ai_runs` row (BR-90).
  - **Stored:** in `photo_duplicate_judgements` (`group_id`, provider, model, prompt version, status, attempts, `best_photo_id`, `reasons`, `issues`, `invocation_ids`, `latency_ms`).
  - **Batch:** `photos:duplicates --judge` queues one job per group on `ai-vision`.
- **Review page** `/ai/photos/duplicates` (lab, local-only like the other lab pages, no login):
  - **Groups:** one card per group (paginated, 10 per page). Each card shows the thumbnails with similarity, the variant tag and the original or augmented source.
  - **Judgement:** the model's pick is highlighted, with its reasons and the issue per other photo, plus the run link.
  - **"Keep this one, remove N":** it marks the kept photo `review_status = 'kept'` and the others `'marked_for_removal'`, new nullable columns on `listing_photos`. It never deletes a row or a file.
  - **Other actions:** "Undo" resets the marks. "Pick another" lets the learner keep a different photo, and that override is stored and shown.
  - **Summary bar:** groups, judged, reviewed, and photos marked for removal.
  - **Extras:** a "How it works" panel, a navigation entry, and empty states (no groups: shows the generator and cluster commands).
- **Eval and calibration** (`photos:duplicates-eval`).
  - **Cutoff curve:** over thresholds 0.90 to 0.99 in steps of 0.01, for every true pair (an original with each of its variants, and two variants of one original), it records recall per variant kind and overall. It also records false pairs (any pair joining two different originals, or across groups). Precision is true pairs over all pairs found.
  - **Chosen cutoff:** the highest threshold with overall recall ≥ 0.9 and precision 1.0. If none exists, the threshold maximising F1 is chosen (ties: the higher one). It becomes the `config/ai.php` default `ai.photo_duplicates.cutoff` (env `AI_PHOTO_DUPLICATE_CUTOFF`).
  - **Judging:** over the generated groups it records the share of groups where the model picked the original, per model (`gemma4:12b`, `qwen3.5:9b`). Each judgement is live, outside the queue, with `ai_runs` origin `eval`. It also records valid on the first attempt and latency p50 and p95.
  - **Output:** `bench/results/photo-duplicates/<date>/run.json`, plus a `BENCHMARKS.md` section in the existing format (BR-26).
- **Guide and docs.**
  - `resources/guides/photo-duplicates.php` (capability, six tabs).
  - The photos and similar-photos guides link to it.
  - §16 lists the new files, and the README gets the three commands.

### Out of scope

- Deleting rows or files (marks only).
- Duplicates across listings as a moderation feature.
- Perceptual hashes (pHash) as a second signal; the guide mentions them as an exercise.
- Cloud vision runs (they work through SL-51 but are not measured).

## Must NOT change

- **MNC-1**: SL-36, SL-37 and SL-38 results, their caches and eval numbers are unchanged with augmented rows present; the shared repository excludes them by default. Their tests pass unchanged.
- **MNC-2**: Listing pages stay byte-identical (parity), and the asset fingerprint is unchanged.
- **MNC-3**: SL-27 extraction counts and the `/ai/photos` grid behaviour for imported photos are unchanged; augmented photos are tagged.
- **MNC-4**: The suite stays offline, with the encoder and agents faked and images generated in tests.
- **MNC-5**: No file or row is ever deleted by the review page.

## Acceptance criteria

- **AC-1**: WHEN `photos:make-duplicates --photos=20` runs THE SYSTEM SHALL write 2–4 augmented variants per selected original on the same listing, each with `duplicate_of`, `variant`, a new sha256 and a file, deterministically for a given seed. `--fresh` SHALL remove only augmented rows and their files.
- **AC-2**: WHEN SL-36 similar photos, SL-37 search or SL-38 hybrid run THE SYSTEM SHALL never return an augmented photo.
- **AC-3**: WHEN `photos:duplicates` runs THE SYSTEM SHALL store connected components of photos whose image similarity is at or above the cutoff, including same-listing pairs, with no group larger than 6.
- **AC-4** (BR-90, BR-91): WHEN a group is judged THE SYSTEM SHALL send all its photos with their ids in one call to the `photo-vision` provider and model, accept only an answer whose `best_photo_id` is in the group and whose shape passes the rules, retry invalid output up to 3 attempts, and record each attempt in `ai_runs`.
- **AC-5**: WHEN the learner clicks "Keep this one, remove N" THE SYSTEM SHALL mark the kept and the other photos and delete nothing. "Undo" SHALL restore the unmarked state, and "Pick another" SHALL store the learner's choice next to the model's.
- **AC-6**: WHEN `/ai/photos/duplicates` renders THE SYSTEM SHALL show each group with similarities, variant tags, the model's pick, its reasons and issues, the run link and the review state, and a summary bar.
- **AC-7** (BR-26): WHEN `photos:duplicates-eval` runs THE SYSTEM SHALL write the cutoff curve (recall per variant kind, precision), the chosen cutoff and the pick accuracy per model to `run.json`. `BENCHMARKS.md` SHALL present them.
- **AC-8** (BR-27): WHEN `/ai/guides/photo-duplicates` renders THE SYSTEM SHALL show six non-empty tabs. The no-references test SHALL pass on it and on the review page.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Safety | Review actions take ids only, validate that the photo belongs to the group, and never call `Storage::delete` or delete a row | `PhotoDuplicateReviewTest` (spies on the storage disk) |
| **NFR-2** | Resources | Judging runs on `ai-vision`; at most 6 images per call, each at most 1024 px (SL-27 sizes) | `PhotoBestPickTest` |
| **NFR-3** | Cost of clustering | One nearest query per photo; clustering 300 photos takes under 5 s in-process | eval timing in run.json |

## Rollout

- **Flag**: none.
- **Deploy order**:
  1. Migrations.
  2. `photos:make-duplicates`.
  3. `photos:embed`.
  4. `photos:duplicates --judge` with a worker.
  5. The eval.
- **Rollback**: revert, `migrate:rollback` (drops the new tables and columns), and `photos:make-duplicates --fresh` before the rollback to remove the generated files.

## Boundaries

- **Always**:
  - Exclude augmented rows from earlier features.
  - Validate the pick in PHP.
  - Fake the agents in tests.
  - Marks only.
  - Update the guide, §16, the README and `BENCHMARKS.md`.
- **Ask first**:
  - Deleting files.
  - A new package or model.
  - Including augmented rows in earlier features.
- **Never**:
  - Delete a photo.
  - Count augmented photos in the SL-36, SL-37 or SL-38 numbers.
  - Accept a `best_photo_id` outside the group.

## Technical context

- **Related code**:
  - `app/Repositories/PhotoVectorRepository.php` and `VectorQuery` (SL-38 shared repository);
  - `app/Support/PhotoImageResizer.php` (GD);
  - `app/Services/PhotoImportService.php` (storing photos);
  - `app/Ai/PhotoVisionAgent.php` and `app/Services/PhotoExtractionService.php` (structured output and retry pattern);
  - `app/Rules/PhotoExtractionRules.php`;
  - `resources/js/ai/Pages/Lab/Photos.vue`;
  - `app/Services/LabOverviewService.php` (navigation and capability);
  - `resources/guides/photos.php` and `resources/guides/similar-photos.php`.
- **Already implemented**:
  - 156 photos with image and caption vectors;
  - the encoder;
  - the `ai-vision` queue;
  - the guide engine;
  - local-only lab routes.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **Generated duplicates, not a bigger import.** Stanford Cars photos of one class are different shots, not duplicates. Only generated copies give a known answer for both the cutoff and the best-photo question.
- **`mirrored` is a deliberate hard case.** CLIP vectors of a flipped photo often fall below 0.98. The curve shows which kinds a vector cutoff misses, which is the teaching point.
- **Same-listing pairs are included** in duplicate review, unlike similar photos, because duplicates are usually re-uploads on one listing.
- **One call with all images**, rather than pairwise comparisons, keeps it to one judgement per group. Groups are capped at 6 to bound the prompt.

## Clarity gate answers (binding, self-decided 2026-11-12)

1. **Pairs for precision.**
   - A pair is a raw edge (image similarity ≥ threshold) between two photos, counted before grouping and before the 6-cap split.
   - **True pair:** both photos come from one original (the original and one of its variants, or two variants of one original).
   - **False pair:** any other edge, including two different imported photos (any of the 156) or variants of two different originals.
   - Precision = true edges ÷ all edges at that threshold. Recall = true edges found ÷ all true pairs.
   - The 32 imported pairs at 0.90 or above count as false. That is why precision 1.0 is reachable only above about 0.94, which is the point of the curve.
2. **Pick accuracy.**
   - The eval builds its groups at the chosen cutoff (after the cap). It judges only groups that contain exactly one original and at least one of its variants.
   - Groups with no original, or with two or more originals, are counted and reported, not judged.
   - Accuracy = groups where the pick is the original ÷ judged groups.
   - Eval judgements are written with `ai_runs` origin `eval` only, not to `photo_duplicate_judgements`, so they never show on the review page.
3. **Review state.**
   - A table `photo_duplicate_reviews` stores the review: `group_id` (unique), `kept_photo_id`, `source` (`model` or `override`) and `reviewed_at`.
   - `listing_photos.review_status` is the per-photo mark this review writes: `kept` or `marked_for_removal`, null when unmarked.
   - **Rebuilds:**
     - a **reviewed** group is a group with a review row;
     - a rebuild keeps reviewed groups, their members, judgement and marks, and replaces only unreviewed groups;
     - photos already in a reviewed group are left out of new groups.
   - "Undo" deletes the review row and nulls the marks of that group's photos.
4. **Cap split.**
   - The component's maximum spanning tree is built with Kruskal: edges sorted by similarity descending, ties by the smaller photo id, then the larger.
   - While a part has more than 6 photos, the weakest tree edge inside that part is removed (ties: the edge with the higher photo ids).
   - The result is deterministic.
5. **Variant selection.**
   - Originals: imported photos with an image vector, ordered by sha256, take the first N after a seeded shuffle (`mt_srand(seed)`, PHP `shuffle` over that order).
   - The variant count is per original, seeded `2 + mt_rand(0, 2)`. The kinds are a seeded subset without repetition from the five.
   - `cropped` is the central 85 % scaled back to the original size, so only content changes, not resolution.
6. **Label.** `label` is the `embeddings-photos` label (`{provider}/{model}`, as in SL-36). The current label is the effective one, and `photos:duplicates` rebuilds the unreviewed groups of that label.
7. **Other pipelines.**
   - `photos:embed` creates image vectors for augmented rows.
   - `photos:embed --text` and `photos:extract` skip them: `source = 'augmented'` is excluded from their pending queries, so the SL-27 and SL-38 counts are unchanged.
   - `excludeAugmented` is enforced in the shared repository and in every count or eval query of SL-36, SL-37 and SL-38 (photo sets, pools, coverage). A test checks each eval's photo count against imported photos only.
8. **Models and cutoff.**
   - `qwen3.5:9b` is already installed (SL-23, SL-27); comparing it is not a new model. The eval skips a model that is not installed and records the skip.
   - The eval prints the chosen cutoff and writes it into `run.json`. The orchestrator edits the `config/ai.php` default in task 7, recorded in `decisions.md`; the eval never edits config.

## Open questions

- [x] Clarity gate (8 items) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90 and BR-91 come from a draft brief applied in every slice (standing approval).
