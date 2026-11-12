<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Find similar car photos',
    'summary' => 'Turn every listing photo into a vector with a local image encoder, then show the photos of other listings whose vectors are nearest, with near duplicates and weak matches left out.',
    'works' => [
        [
            'title' => 'An image encoder makes the vectors',
            'body' => 'Ollama does not serve image-embedding models, so a small encoder runs next to the app as its own container (CLIP, 512 dimensions, capped at 2 GB). The app sends it the stored photos as base64 images, 16 per call, and checks every vector has the size the settings promise.',
            'snippet' => 'App\Ai\PhotoEncoderClient::embed',
        ],
        [
            'title' => 'Embedding runs on a queue',
            'body' => 'One command queues batches of 16 photos on the `ai-vision` queue, so a web request never waits for the encoder. A batch that fails writes a failed run row and leaves its photos without a vector; the other batches go on.',
            'snippet' => 'App\Services\PhotoEmbeddingService::embedBatch',
        ],
        [
            'title' => 'Vectors are normalised and labelled',
            'body' => 'Each vector is scaled to length one before it is stored, so cosine similarity is a plain dot product. The row also keeps the model label it was made with. A vector made by another model is never compared with the current one.',
        ],
        [
            'title' => 'The nearest photos of other listings',
            'body' => 'The query asks the vector index (HNSW, cosine) for the 40 nearest photos, then keeps one photo per listing and drops every photo of the listing being looked at. Matches at or above the near-duplicate cutoff (0.98) and below the minimum score are dropped, and at most 8 remain.',
            'snippet' => 'App\Services\SimilarPhotosService::rank',
        ],
        [
            'title' => 'An empty strip says why',
            'body' => 'A photo with no vector, a vector from another model and a photo with no close enough neighbour each give a different reason, shown instead of an empty box.',
            'snippet' => 'App\Services\SimilarPhotosService::for',
        ],
        [
            'title' => 'Hybrid: add what the caption says',
            'body' => "Image vectors miss what a photo shows but does not look like. `photos:embed --text` also encodes each photo's caption (from the vision model) with the same encoder's text side. With a weight `w` below 1, the 40 image candidates are re-scored as `w * image + (1 - w) * caption` similarity, then one photo per listing is kept at the hybrid minimum score. A pair without a caption vector keeps its image similarity and is flagged image only, so the mix of both is visible. A weight of 1 is the image-only result, unchanged.",
            'snippet' => 'App\\Services\\SimilarPhotosService::blend',
        ],
        [
            'title' => 'Results are cached as plain arrays',
            'body' => 'The key holds the model label and a generation number. A cache hit skips the database. Every batch that stores a vector, and every fresh run, moves to the next generation, so no stale list outlives an index change.',
        ],
    ],
    'failures' => [
        [
            'title' => 'The embed command refuses to start',
            'body' => "**Cause:** the encoder container is not running, or it answers a test image with a vector of the wrong size, or the configured model does not produce 512 dimensions.\n\n**Fix:** restart the project so the container starts, wait for the first download of the model weights, then run the command again.",
        ],
        [
            'title' => 'The strip says the index was built with another model',
            'body' => "**Cause:** the encoder model was switched in the settings after the vectors were made.\n\n**Fix:** run the embed command with `--fresh` to rebuild every vector with the current model.",
        ],
        [
            'title' => 'The strip is empty for most photos',
            'body' => "**Cause:** the minimum score is higher than the scores car photos reach, or the photos were never embedded.\n\n**Fix:** read the reason shown in the strip, run the embed command, and calibrate the minimum score on the eval curve.",
        ],
        [
            'title' => 'The listing page has no Similar cars block',
            'body' => "**Cause:** the listing has no photo, no photo of another listing is similar enough, or the encoder failed. The page never shows an encoder error to visitors.\n\n**Fix:** open the photo in the photos lab and read the reason there.",
        ],
        [
            'title' => 'Title leakage makes hybrid look perfect',
            'body' => "**Cause:** the listing title holds the make and model that the eval grades. A text vector made from the title finds the same make and model by construction, so precision jumps without the photos adding anything.\n\n**Fix:** use the caption as the only text source. The eval reports the title run separately, marked as leaking the ground truth, and never picks its weight.",
        ],
        [
            'title' => 'Hybrid changes little for most photos',
            'body' => "**Cause:** few photos have a caption vector, so most pairs fall back to the image similarity and are flagged image only.\n\n**Fix:** run `photos:extract` for the photos, then `photos:embed --text`, and read the share of pairs with both vectors in `hybrid.json`.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Change the hybrid weight or the text source',
            'body' => "Run `photos:similar-eval --hybrid`: it sweeps `w` from 0 to 1, calibrates the minimum score for each `w`, and writes `hybrid.json` with the image-only baseline. Set `AI_SIMILAR_HYBRID_MIN_SCORE` to the calibrated value of the weight you choose. The text source is the caption only. The eval also runs listing titles, in memory, to show the leak; never use that run as the source.\n\n**Check:** the best weight in `hybrid.json` beats the baseline by at least 0.01 precision; if not, keep `w` at 1.",
        ],
        [
            'title' => 'Change the encoder model',
            'body' => "Pick the model for the image embeddings row on the settings page, or set `PHOTO_ENCODER_MODEL`. The model must produce 512 dimensions. Saving asks you to confirm the re-index, because the old vectors no longer match.\n\n**Check:** the strip says the index was built with another model until the embed command runs with `--fresh`.",
            'snippet' => 'App\Ai\PhotoEncoderClient::label',
        ],
        [
            'title' => 'Change the minimum score',
            'body' => "Set `AI_SIMILAR_MIN_SCORE`. Run the eval command first: it records precision and coverage for thresholds from 0.60 to 0.95, so you can pick the one that keeps matches of the same make and model.\n\n**Check:** the photos lab shows the new cutoff in its how-it-was-found line.",
        ],
        [
            'title' => 'Rebuild the index',
            'body' => "Run the embed command with `--fresh`, and with `--wait` to follow it. Start a worker for the `ai-vision` queue first.\n\n**Check:** the command ends with a summary and the strip shows no model warning.",
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows and the model label',
            'status' => 'logged',
            'note' => 'Every embedded batch writes one `ai_runs` row with the photo count and the time, and every stored vector keeps the label of the model that made it.',
        ],
        [
            'name' => 'Dimension check, cutoffs and queue',
            'status' => 'guarded',
            'note' => 'The command refuses a model that is not 512 dimensions and an encoder that answers a test image wrongly. The cutoffs hide near duplicates and weak matches, and the encoder only ever runs on its own queue. The tests run offline with a faked encoder and generated vectors.',
        ],
        [
            'name' => 'Similar photos look wrong',
            'status' => 'runbook',
            'note' => "1. Open the photo in the photos lab and read the reason and the model label.\n2. If the label differs from the current model, run the embed command with `--fresh`.\n3. Run the eval command and compare precision with the last result.\n4. If matches are weak, raise the minimum score using the threshold curve before changing the model.",
        ],
    ],
    'numbers' => [
        ['label' => 'Precision at 8, same make and model', 'file' => 'photo-similar/2026-11-11/run.json', 'path' => 'metrics.precision_make_model'],
        ['label' => 'Latency from the database, 95th percentile', 'unit' => 'ms', 'file' => 'photo-similar/2026-11-11/run.json', 'path' => 'latency.uncached.p95_ms'],
        ['label' => 'Latency from the cache, 95th percentile', 'unit' => 'ms', 'file' => 'photo-similar/2026-11-11/run.json', 'path' => 'latency.cached.p95_ms'],
        ['label' => 'Hybrid precision at 8, same make and model (best weight)', 'file' => 'photo-similar/2026-11-12/hybrid.json', 'path' => 'best.precision_make_model'],
        ['label' => 'Image-only precision at 8 in the same run (baseline)', 'file' => 'photo-similar/2026-11-12/hybrid.json', 'path' => 'baseline.precision_make_model'],
    ],
    'experiments' => [
        [
            'title' => 'Similar photos of one car',
            'predictions' => [
                ['id' => 'a', 'text' => 'Most similar photos show the same make and model as the one you opened.'],
                ['id' => 'b', 'text' => 'Similar photos are random cars.'],
                ['id' => 'c', 'text' => 'The strip always shows the same photos of the same listing.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'Open a photo and read the make and model label of each match. Most agree; the eval command measures how many. Photos of the same listing never appear.'],
            'page' => 'ai.photos',
            'params' => [],
        ],
        [
            'title' => 'Do captions help?',
            'predictions' => [
                ['id' => 'a', 'text' => 'Adding the caption always finds the same make and model more often.'],
                ['id' => 'b', 'text' => 'Captions help only some photos; the eval tells whether the best weight beats image only.'],
                ['id' => 'c', 'text' => 'Titles are a fair text source, because they describe the car.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'Move the slider on a photo and compare the two columns: photos with a caption move, photos without one stay as they were. The eval sweeps every weight against the image-only baseline and keeps image only unless a weight wins by 0.01. Titles are not fair: they hold the answer the eval grades, so that run is marked as leaking.'],
            'page' => 'ai.photos',
            'params' => [],
        ],
    ],
    'related' => ['photos', 'photo-search', 'providers'],
];
