<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Search the docs',
    'summary' => 'Cut documents into chunks, search them by meaning and by words, and merge the two lists.',
    'works' => [
        [
            'title' => 'Documents are cut into chunks',
            'body' => 'A page is too big to have one useful vector. The chunker follows headings, then paragraphs, then sentences, keeps code and tables whole and puts the title and section in front of every chunk so it carries its context.',
            'snippet' => 'App\Services\MarkdownChunker',
        ],
        [
            'title' => 'Each chunk is stored twice',
            'body' => 'A chunk keeps a vector for meaning and a text-search column for words. A file is chunked again only when its hash changed, so a second index run changes nothing.',
        ],
        [
            'title' => 'The query runs in the chosen mode',
            'body' => 'The query is embedded for the vector mode and parsed as a text query for the word mode. The page shows all three modes side by side: vector, text and hybrid.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\DocsSearchService::search',
        ],
        [
            'title' => 'Hybrid merges the two lists',
            'body' => 'Reciprocal rank fusion gives each chunk a score from its rank in each list, so a chunk that is high in both wins. No score scale has to be tuned.',
            'snippet' => 'App\Services\DocsSearchService::fuse',
        ],
        [
            'title' => 'Chunks come back with their source',
            'body' => 'Every hit has its path, heading, text and the ranks it got. The ranks are shown so you can see which list found it.',
            'event' => 'response_received',
        ],
    ],
    'failures' => [
        [
            'title' => 'A function name finds nothing in vector mode',
            'body' => "**Cause:** a vector measures meaning; a rare identifier has none, and similar chunks crowd it out.\n\n**Fix:** use text or hybrid mode. This is the reason hybrid exists.",
        ],
        [
            'title' => 'A long question finds nothing in text mode',
            'body' => "**Cause:** the text query needs every word of the question to be in one chunk.\n\n**Fix:** use fewer, sharper words, or hybrid, where the vector list fills the gap.",
        ],
        [
            'title' => 'The search answers 503 or is empty',
            'body' => "**Cause:** the embedding model is down, or the index has no chunks because the indexing command has not run.\n\n**Fix:** start the model, then run the index command and check its report for chunks waiting for a vector.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Change the chunk size',
            'body' => "Set `AI_DOCS_CHUNK_MAX_TOKENS` and `AI_DOCS_CHUNK_MERGE_BELOW_TOKENS`, then index again; the chunker version is part of the hash, so changed rules re-chunk every file.\n\n**Check:** run the retrieval eval for the new size and compare recall with the committed run before you keep it.",
            'snippet' => 'config/ai.php#docs-chunk',
        ],
        [
            'title' => 'Swap the embedding model',
            'body' => "Set `OLLAMA_EMBED_MODEL` or the embeddings provider and index again. The vector size is stored with the model, so mixing models is refused.\n\n**Check:** the next index report names the new model.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Return more or fewer chunks',
            'body' => "Change the default and the maximum number of results, or the fusion depth, in the docs settings.\n\n**Check:** the number of hits on the page.",
            'snippet' => 'App\Services\DocsSearchService::defaultK',
        ],
    ],
    'support' => [
        [
            'name' => 'Index report and query time',
            'status' => 'logged',
            'note' => 'The index command reports chunks, sizes and waiting vectors. Each query logs an `embeddings:query` run, and `Server-Timing` splits the embedding from the database.',
        ],
        [
            'name' => 'Retrieval eval and tests',
            'status' => 'guarded',
            'note' => 'The eval asks 24 labelled questions in each mode and scores recall at 3, 6 and 10 plus rank. The search tests cover the three modes, the limits and the failure replies.',
        ],
        [
            'name' => 'Results look wrong after an update',
            'status' => 'runbook',
            'note' => "1. Run the index command; it re-chunks only changed files.\n2. Read the report for chunks without a vector.\n3. Rerun the retrieval eval and compare with the committed numbers.\n4. A drop in one mode points at the model (vector) or the text configuration (text).",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Recall at 6, vector mode',
            'file' => 'rag-eval/2026-11-03/recall-500-300/recall.json',
            'path' => 'cells.1.recall',
        ],
        [
            'label' => 'Recall at 6, text mode',
            'file' => 'rag-eval/2026-11-03/recall-500-300/recall.json',
            'path' => 'cells.4.recall',
        ],
        [
            'label' => 'Recall at 6, hybrid mode',
            'file' => 'rag-eval/2026-11-03/recall-500-300/recall.json',
            'path' => 'cells.7.recall',
        ],
        [
            'label' => 'Meaning questions answered by text mode (of 5)',
            'file' => '2026-11-02-docs-index/search-bench.json',
            'path' => 'summary.meaning.text',
        ],
    ],
    'experiments' => [
        [
            'title' => 'An exact word against a meaning',
            'predictions' => [
                ['id' => 'a', 'text' => 'Text and hybrid mode find the rare setting name, vector mode may miss it.'],
                ['id' => 'b', 'text' => 'Vector mode finds it and text mode misses it.'],
                ['id' => 'c', 'text' => 'All three modes return the same page first.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'A rare setting name is found by text and hybrid mode but may be missed by vector mode.'],
            'page' => 'ai.docs',
            'params' => ['q' => 'flushpackets'],
        ],
        [
            'title' => 'Words you did not use',
            'predictions' => [
                ['id' => 'a', 'text' => 'Nothing is found because no page uses those words.'],
                ['id' => 'b', 'text' => 'Only pages containing the exact words are returned.'],
                ['id' => 'c', 'text' => 'The right page is found by meaning even though it uses other words.'],
            ],
            'answer' => ['id' => 'c', 'body' => 'A question about a problem finds the right page by meaning even when the page uses other words.'],
            'page' => 'ai.docs',
            'params' => ['q' => 'why does the server hold back a streamed response'],
        ],
    ],
    'related' => ['chat', 'semantic'],
];
