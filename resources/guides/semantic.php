<?php

declare(strict_types=1);

return [
    'kind' => 'capability',
    'title' => 'Search by meaning',
    'summary' => 'Find cars that match what you mean, not the words you typed, with embeddings and a vector index.',
    'works' => [
        [
            'title' => 'Your query becomes a vector',
            'body' => 'The query goes to the embedding model, which returns a list of 1024 numbers. Texts with a similar meaning get vectors that point the same way. The call is logged as an `embeddings:query` run.',
            'event' => 'request_sent',
            'snippet' => 'App\Services\SemanticSearchService::embedQuery',
        ],
        [
            'title' => 'The vector is checked',
            'body' => 'A reply with the wrong number of values is refused. Comparing vectors of different sizes would not fail loudly, it would return nonsense.',
        ],
        [
            'title' => 'The index finds the nearest ten',
            'body' => 'Each listing has a stored vector built from its description. The database walks an HNSW graph and returns the ten nearest by cosine distance. `ef_search` decides how many candidates the walk keeps.',
            'snippet' => 'App\Repositories\EloquentSemanticSearchRepository::nearest',
        ],
        [
            'title' => 'The matches come back',
            'body' => 'Each match carries a similarity (1 minus the distance). The page shows them in order, with the time spent embedding and in the database.',
            'event' => 'response_received',
            'snippet' => 'App\Services\SemanticSearchService::search',
        ],
    ],
    'failures' => [
        [
            'title' => 'The search answers 503',
            'body' => "**Cause:** the query could not be embedded: the embedding model is not running, is cold, or answered with the wrong size.\n\n**Fix:** start the model server and retry. The first call after a pause is slow because the model loads.",
            'snippet' => 'App\Exceptions\SemanticSearchUnavailableException',
        ],
        [
            'title' => 'Results miss cars that look like perfect matches',
            'body' => "**Cause:** the index is approximate. Descriptions are template text, so every vector is close to every other, and a graph walk with few candidates skips neighbours.\n\n**Fix:** raise `ef_search`. It costs a few milliseconds and the measured recall rises with it (see the numbers).",
        ],
        [
            'title' => 'Nothing is found for any query',
            'body' => "**Cause:** the listings have no vectors yet, or they were made by another model than the one used for queries.\n\n**Fix:** rebuild the descriptions and embeddings with the embedding commands; a row is embedded again when its model name or text hash changed.",
        ],
    ],
    'recipes' => [
        [
            'title' => 'Swap the embedding model',
            'body' => "Set `OLLAMA_EMBED_MODEL` (or the embeddings provider) and rebuild every vector. The stored dimension must match the new model, otherwise the batch is refused.\n\n**Check:** run a search and open its run row: the `model` is the new one.",
            'snippet' => 'config/ai.php#provider-ollama',
        ],
        [
            'title' => 'Trade speed for recall',
            'body' => "Change `AI_EF_SEARCH`. Higher finds more of the true neighbours and takes longer.\n\n**Check:** compare the recall and database time in the numbers tab with a fresh run of the semantic bench.",
            'snippet' => 'config/ai.php#embeddings',
        ],
    ],
    'support' => [
        [
            'name' => 'Query run row',
            'status' => 'logged',
            'note' => 'Each search logs one `embeddings:query` row with the embedding provider, model and total time. Batches of descriptions are logged as `embeddings`.',
        ],
        [
            'name' => 'Tests',
            'status' => 'guarded',
            'note' => 'The semantic search feature test fakes the embedding call and checks the validation, the wrong-size reply and the 503. The semantic bench measures recall against an exact scan.',
        ],
        [
            'name' => 'Search is slow or empty',
            'status' => 'runbook',
            'note' => "1. Read the `Server-Timing` header: `embed` slow means the model, `db` slow means the index.\n2. Check that the embedding model is loaded.\n3. Count embedded listings; zero means the embedding command has not run.\n4. If recall dropped after an index change, rebuild the index and rerun the bench.",
        ],
    ],
    'numbers' => [
        [
            'label' => 'Recall@10 at ef_search 40',
            'file' => '2026-11-02-embeddings/search-bench.json',
            'path' => 'ef_sweep.40.recall_at_10',
        ],
        [
            'label' => 'Recall@10 at ef_search 400 (the app setting)',
            'file' => '2026-11-02-embeddings/search-bench.json',
            'path' => 'ef_sweep.400.recall_at_10',
        ],
        [
            'label' => 'Database time at ef_search 400, median',
            'unit' => 'ms',
            'file' => '2026-11-02-embeddings/search-bench.json',
            'path' => 'ef_sweep.400.db_ms_p50',
        ],
        [
            'label' => 'Query embedding, p95',
            'unit' => 'ms',
            'file' => '2026-11-02-embeddings/search-bench.json',
            'path' => 'embed_ms.p95',
        ],
    ],
    'experiments' => [
        [
            'title' => 'Words you did not type',
            'predictions' => [
                ['id' => 'a', 'text' => 'No estates are returned because no listing contains those words.'],
                ['id' => 'b', 'text' => 'Estates are returned even though no listing contains those words.'],
                ['id' => 'c', 'text' => 'Only listings with the word "boot" are returned.'],
            ],
            'answer' => ['id' => 'b', 'body' => 'A query describing a use ("family car with a big boot") returns estates even though no listing contains those words.'],
            'page' => 'ai.semantic',
            'params' => ['q' => 'family car with a big boot'],
        ],
        [
            'title' => 'A rare exact word',
            'predictions' => [
                ['id' => 'a', 'text' => 'Fewer exact matches than the meaning query, because vectors measure meaning, not spelling.'],
                ['id' => 'b', 'text' => 'Every listing with that model name ranks first.'],
                ['id' => 'c', 'text' => 'No results at all.'],
            ],
            'answer' => ['id' => 'a', 'body' => 'A query made of one exact model name returns fewer exact matches than the meaning query, because vectors measure meaning, not spelling.'],
            'page' => 'ai.semantic',
            'params' => ['q' => 'Octavia'],
        ],
    ],
    'related' => ['nl-search', 'docs'],
    'lessons' => ['search-and-photos/search-by-meaning'],
    'build' => [
        ['step' => 'Choose one embedding model and keep it', 'detail' => 'Vectors from different models cannot be compared. Store the model name next to the index and refuse a search when it differs.', 'tab' => 'recipes'],
        ['step' => 'Store vectors in pgvector', 'detail' => 'Add a vector column and an HNSW index, then query by cosine distance with an ordinary SQL order by.', 'tab' => 'recipes', 'doc' => 'https://github.com/pgvector/pgvector'],
        ['step' => 'Embed the query the same way', 'detail' => 'Run the sentence through the same model and the same normalising as the stored rows.', 'tab' => 'recipes'],
        ['step' => 'Guard the input first', 'detail' => 'Reject empty or oversized text before it reaches the embedding model, and cache the vector for repeated queries.', 'tab' => 'support', 'doc' => 'https://laravel.com/docs/validation'],
        ['step' => 'Return a small ranked list', 'detail' => 'Ask the index for the nearest ten and show rows with their score, so a bad match is visible.', 'tab' => 'recipes'],
        ['step' => 'Re-index after a model change', 'detail' => 'Write a command that re-embeds every row and an indicator that says whether the index is current.', 'tab' => 'support'],
    ],
    'watch' => [
        ['point' => 'Exact words get lost', 'detail' => 'Part numbers and names embed badly. Add a keyword search or a hybrid merge when exact terms matter.', 'tab' => 'failures'],
        ['point' => 'Always some result', 'detail' => 'A vector search returns the nearest rows even when none is close. Use a minimum score.', 'tab' => 'failures'],
        ['point' => 'Index and model must agree', 'detail' => 'Switching the model without a re-index gives nonsense that looks valid. Check for it on every search.', 'tab' => 'support'],
        ['point' => 'Embedding costs add up', 'detail' => 'Each document is embedded once, each query once. Cache queries and embed on a queue.', 'tab' => 'support'],
    ],
    'elsewhere' => [
        ['domain' => 'Recruiting', 'problem' => 'A recruiter types a role and wants profiles that fit, whatever words the CV uses.', 'how' => 'Embed each profile once, embed the query and return the nearest profiles with a score.'],
        ['domain' => 'Legal', 'problem' => 'A lawyer looks for past cases that match a situation.', 'how' => 'Embed the case summaries and search by the described facts instead of keywords.'],
        ['domain' => 'Customer support', 'problem' => 'Customers describe a problem in their own words.', 'how' => 'Embed the help articles and suggest the nearest ones before a ticket is opened.'],
    ],
];
