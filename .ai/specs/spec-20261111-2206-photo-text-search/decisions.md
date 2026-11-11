# Decisions

## D-1 Infinity encodes text for both CLIP models (2026-11-11)

Live check against the running `photo-encoder` sidecar, `POST /embeddings` with `"modality": "text"` and `input: ["a photo of a red estate"]`:

- `openai/clip-vit-base-patch32`: 200, one row with index 0, 512 dimensions.
- `laion/CLIP-ViT-B-32-laion2B-s34B-b79K`: 200, one row with index 0, 512 dimensions.

No gap: text search works on both available models. The `min_score` default of 0.2 in `ai.photo_search` is a placeholder until task 6 calibrates it.

## D-2 min_score stays 0.2 after calibration (2026-11-11)

`photos:search-eval` on 156 photos and 30 queries: coverage, precision and MRR are identical for every threshold from 0.15 to 0.225 (0.967, 0.506, 0.569) and drop from 0.25 (0.867, 0.455, 0.503). The command reports the first maximum, 0.15, but the plateau means 0.15 to 0.225 give the same answers. `ai.photo_search.min_score` stays 0.2, the middle of the plateau, for a margin before the cliff; no config change. The bare query (`photo-search.v0`) ties the template (`v1`): P@8 0.511 against 0.506, MRR 0.576 against 0.569, coverage 0.933 against 0.967. The default prompt stays `v1`. Uncached p95 136.1 ms (budget 400), cached p95 0.97 ms (budget 10).
