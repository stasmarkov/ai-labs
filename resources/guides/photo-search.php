<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Search car photos by text',
    'summary' => 'Type what you want to see, such as a red sports car, and get the listing photos whose image vectors are nearest to the words, found by the same local CLIP encoder that embeds the photos.',
    'works' => [
        [
            'title' => 'The input guard checks the words',
            'body' => 'The query is trimmed and checked against the same injection patterns as the chat. A refused query stops here: the encoder is never called and the run row says `refused`.',
            'event' => 'refused',
        ],
        [
            'title' => 'The words are put into a prompt',
            'body' => 'The query goes into a short template, `a photo of {query}`, because CLIP matches captions better than bare words. The template is a versioned prompt, so the run row names it.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\PhotoTextSearchService::search',
        ],
        [
            'title' => 'The text side of the encoder makes a vector',
            'body' => 'The same CLIP model that made the photo vectors has a text tower. The app sends it one prompt, checks that the answer has 512 dimensions and scales it to length one, so a photo and a sentence can be compared with cosine similarity.',
            'snippet' => 'App\Ai\PhotoEncoderClient::embedText',
        ],
        [
            'title' => 'The index returns the nearest photos',
            'body' => 'The vector index (HNSW, cosine) returns the 40 nearest photos made by the current model. The service keeps the best photo of each listing, drops those below the minimum score and returns at most 12.',
            'event' => 'response_received',
        ],
        [
            'title' => 'Results are cached by a hash of the query',
            'body' => 'The cache key holds the model label, the index generation, the prompt version and a hash of the query, never the text itself. A cache hit skips the encoder and writes no run row; a new batch of photo vectors moves the generation and drops every stored search.',
            'event' => 'cache_hit',
            'snippet' => 'App\Services\PhotoTextSearchService::cacheKey',
        ],
        [
            'title' => 'An empty page says why',
            'body' => 'No vectors built yet, a query the guard refused, an encoder that is down and a query nothing matched each show a different message instead of an empty grid.',
        ],
    ],
    'failures' => [
        [
            'title' => 'The page says the index is not built',
            'body' => "**Cause:** no photo has a vector made by the current encoder model, or the model was switched after the vectors were made.\n\n**Fix:** run the embed command with `--fresh` and wait for the queue to finish.",
        ],
        [
            'title' => 'The search says the encoder is unavailable',
            'body' => "**Cause:** the encoder container is not running or is still downloading its model weights.\n\n**Fix:** restart the project, wait for the first download, then search again.",
        ],
        [
            'title' => 'Every query returns nothing',
            'body' => "**Cause:** the minimum score is higher than the scores that text reaches against photos, which are far lower than photo against photo.\n\n**Fix:** lower `AI_PHOTO_SEARCH_MIN_SCORE` using the eval curve.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Change the encoder model',
            'body' => "Pick the model for the image embeddings row on the settings page, or set `PHOTO_ENCODER_MODEL`. The model must produce 512 dimensions and have a text side. Photo vectors must be rebuilt with `--fresh`.\n\n**Check:** the page shows the new model label in its tags.",
            'snippet' => 'App\Ai\PhotoEncoderClient::label',
        ],
        [
            'title' => 'Change the prompt template',
            'body' => "Set `AI_PROMPT_PHOTO_SEARCH` to the name of another template. The version is part of the cache key, so old searches are not reused.\n\n**Check:** the prompt tag on the page shows the new version.",
        ],
        [
            'title' => 'Change the minimum score',
            'body' => "Set `AI_PHOTO_SEARCH_MIN_SCORE`. A lower value shows more photos and more wrong ones.\n\n**Check:** the cutoff tag on the page shows the new value.",
        ],
    ],
    'support' => [
        [
            'name' => 'Run rows',
            'status' => 'logged',
            'note' => 'Every search that reaches the encoder writes one `ai_runs` row with the time; a refused query writes a refused row, and a cache hit writes none.',
        ],
        [
            'name' => 'Input guard, limits and cache key',
            'status' => 'guarded',
            'note' => 'The query is 2 to 200 characters, passes the input guard before anything is sent, is limited to 60 searches a minute per address, and never reaches SQL or a cache key as text.',
        ],
        [
            'name' => 'Results look wrong',
            'status' => 'runbook',
            'note' => "1. Read the prompt and the model label in the tags.\n2. If the label differs from the photos, rebuild the vectors.\n3. Compare the query with the eval set.\n4. Move the minimum score using the curve before changing the model.",
        ],
    ],
    'numbers' => [
        ['label' => 'Precision at 8, with the template', 'file' => 'photo-search/2026-11-11/run.json', 'path' => 'variants.template.metrics.precision'],
        ['label' => 'Precision at 8, bare query', 'file' => 'photo-search/2026-11-11/run.json', 'path' => 'variants.bare.metrics.precision'],
        ['label' => 'Queries with a relevant photo in the top 8', 'file' => 'photo-search/2026-11-11/run.json', 'path' => 'variants.template.metrics.hit_rate'],
        ['label' => 'Search latency, uncached, 95th percentile', 'unit' => 'ms', 'file' => 'photo-search/2026-11-11/run.json', 'path' => 'latency.uncached.p95_ms'],
        ['label' => 'Search latency, from cache, 95th percentile', 'unit' => 'ms', 'file' => 'photo-search/2026-11-11/run.json', 'path' => 'latency.cached.p95_ms'],
    ],
    'experiments' => [
        [
            'title' => 'Words to photos',
            'predictions' => [
                ['id' => 'a', 'text' => 'The top photos show cars that fit the words, mostly of the colour or body type named.'],
                ['id' => 'b', 'text' => 'The photos are random, because the words are not photos.'],
                ['id' => 'c', 'text' => 'Only photos whose listing text contains the words appear.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'The encoder was trained on pairs of images and captions, so words and photos share one space. Search for a colour, then a body type, and read the score on each photo.'],
            'page' => 'ai.photo-search',
            'params' => ['q' => 'red sports car'],
        ],
    ],
    'related' => ['similar-photos', 'photos', 'providers'],
];
