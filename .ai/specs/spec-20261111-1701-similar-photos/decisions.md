# Decisions

## Encoder image (task 1)

- Default kept: the Infinity embedding server, `michaelf34/infinity:latest-cpu`, pinned by the index digest `sha256:b9cb35a93a793a2808d5c1bd78ecafacc8c0dafb2e233f961b96e405b8bc4a30` (checked with `docker buildx imagetools inspect`, 2026-11-11).
- The image publishes only `linux/amd64`. The compose file sets `platform: linux/amd64`, so on Apple silicon it runs under emulation. Whether it starts and answers `/health` under OrbStack is the manual step 1 check. If it does not, switch to the fallback in the spec (pinned `python:3.12-slim` with `open_clip`) and record the reason here.
- The model is served by `--model-id openai/clip-vit-base-patch32 --engine torch --device cpu`; weights are cached in the volume `photo-encoder-models` (`HF_HOME=/models`).

## Encoder health (task 9, manual step 1)

- The pinned image started under OrbStack (emulated `linux/amd64`), reported healthy and answered `/health` from the web container. No fallback is needed.
- The 156 photos embedded in 11.4 s through the `ai-vision` worker (13.7 photos/s wall clock; 11.55 photos/s of encoder time in `ai_runs`).

## min_score calibration (task 9)

Rule from the spec: maximise precision for the same make times coverage, on the 156 photos. Curve from `bench/results/photo-similar/2026-11-11/run.json`:

| Threshold | Coverage | P@8 make | P@8 make and model | Make x coverage |
|-----------|----------|----------|--------------------|-----------------|
| 0.60 | 1.000 | 0.773 | 0.665 | 0.773 |
| 0.65 | 1.000 | 0.774 | 0.667 | 0.774 |
| 0.70 | 0.994 | 0.775 | 0.667 | 0.770 |
| 0.75 | 0.987 | 0.785 | 0.677 | 0.775 |
| 0.80 | 0.897 | 0.846 | 0.735 | 0.759 |
| 0.85 | 0.603 | 0.967 | 0.895 | 0.583 |
| 0.90 | 0.205 | 1.000 | 1.000 | 0.205 |
| 0.95 | 0.000 | 0.000 | 0.000 | 0.000 |

- Chosen: `ai.similar_photos.min_score` = 0.75 (was the 0.80 placeholder). The product is nearly flat from 0.60 to 0.75 and 0.75 is the maximum, though only by 0.001 over 0.65, so the choice is a judgment call. 0.80 buys about 6 points of precision for 9 points of coverage; `AI_SIMILAR_MIN_SCORE=0.80` gives that trade.
- Latency at 0.75 over 156 photos: uncached p95 1.26 ms, cached p95 0.07 ms, far inside NFR-1 (50 ms and 5 ms). It is the in-process service call, not an HTTP round trip.

## Second model (AC-7)

- Both models are served: `openai/clip-vit-base-patch32` and `laion/CLIP-ViT-B-32-laion2B-s34B-b79K` (both 512 dimensions). The compose `command` now passes two `--model-id` flags, and `ai.providers.photo-encoder.models.image_embeddings.available` lists both.
- Memory check (2026-11-11, `--memory 2g --memory-swap 2g`, emulated linux/amd64): the container loaded both models, ended at 1.4 to 1.5 GiB, was not OOM-killed, and `/embeddings_image` returned 512-dimension vectors for each model id. The cost is a longer start (second weights download and warm-up on first start), so `start_period` stays at 120 s and the first start can take longer.
- `AiSettingsPageTest` now switches to the real second model id instead of the faked `other/clip`; `PhotoEncoderComposeTest` asserts every available model is in the compose command.
- Manual step 5 (partial): after `ddev restart` the sidecar came up with both ids and `/models` from the web container listed both. I did not switch the setting on the dev database and re-embed the 156 photos with the laion model, so the full switch plus `photos:embed --fresh` round trip is still unrun; the settings flow itself is covered by the AC-7 tests.
