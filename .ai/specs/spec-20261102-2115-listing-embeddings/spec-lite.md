---
id: spec-20261102-2115-listing-embeddings
title: Semantic listing search with bge-m3 embeddings in pgvector
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-02T19:20:57Z
created: 2026-11-02
stacks: [laravel]
slice: SL-24
brs: [BR-05, BR-90, BR-91, BR-26, BR-27]
---

# Semantic listing search with bge-m3 embeddings in pgvector

## Summary

A visitor types what they want ("family car with a big boot") and gets the 10 listings whose descriptions mean the closest thing, found by vector similarity rather than by filters. Every listing gets a short English description, each description is turned into a 1024-number vector by the local bge-m3 model (Ollama, per ADR 0001) in queued batches, the vectors live in PostgreSQL with an HNSW index, and the query is embedded the same way and compared by cosine distance. The run records embeddings per second, total time, index build time, index size, query latency, and how often the fast HNSW index returns the same top 10 as an exact scan.

## Context

- Listings (`listings` table, 50 000 rows seeded from a fixed seed, BR-05) have make, model, fuel, year, price, currency, mileage, city and dealer, but **no description text**. Semantic search needs text, and filters cannot answer "big boot".
- The seed uses PHP's global `mt_rand` sequence (`ListingSeeder`, `Catalog::SEED`). Any new `mt_rand` call inside that loop would shift every later row and change the data that SL-06 and SL-08 benchmarked and that `bench/parity/*.txt` snapshots.
- ADR 0001 (accepted): text embedder bge-m3 via the `laravel/ai` `ollama` driver; `vector(1024)`, cosine distance (`vector_cosine_ops`), HNSW, normalised vectors; no query or document prefix; assert the returned dimension so a wrong model fails loudly. bge-m3 is pulled in Ollama (1.2 GB).
- pgvector 0.8.7 is installed (`vector` extension enabled by an existing migration). No pgvector PHP package is installed; vectors are written and read as text literals (`'[0.1,0.2,…]'::vector`).
- `laravel/ai` 1.3: `Embeddings::for([...texts])->dimensions(1024)->generate($provider, $model)` returns one vector per input; `Embeddings::fake()` for tests; events `GeneratingEmbeddings`, `EmbeddingsGenerated`, `EmbeddingsFailed` carry an invocation id, the provider and model. The app's `config/ai.php` has no `default_for_embeddings`, so the vendor default (`openai`) would apply; this slice sets it.
- Queue: `QUEUE_CONNECTION=database` in `.env`, `sync` in `phpunit.xml`. No queue worker runs by default.
- SL-22: `ai_runs` logs every streamed agent run; `/listings/{id}/describe` is rate-limited per IP. `JsonErrorRenderer` decides which routes answer errors as JSON.
- Rules from `product-brief.md`:
  - **BR-05** The seeded dataset is 500 dealers and 50 000 listings, generated from a fixed seed, so every server queries identical data.
  - **BR-90** Every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
  - **BR-91** Provider and model are chosen by configuration only.
  - **BR-26** Results are recorded with versions and settings so they can be reproduced.
  - **BR-27** Every shipped slice adds its section to `docs/learning-guide.md`.

## Scope

- A `listing_documents` table, one row per listing: the description (text), its vector (`vector(1024)`, nullable until embedded), the embedding model, a hash of the embedded text and the embedding time, with an HNSW cosine index. The `listings` table is not changed.
- `listings:describe-all` command (also called by the seeder after its insert) that writes a deterministic English description per listing, built from the listing's attributes plus a body type per model and a few features. The generator uses its own random engine seeded from the listing id, never the global `mt_rand`.
- `listings:embed` command: dispatches queued jobs, each embedding a batch of descriptions in one model call, skips listings whose text hash and model are unchanged, and writes a JSON summary of the run.
- Embedding calls logged in `ai_runs` (BR-90).
- `GET /api/listings/semantic?q=…`: embeds the query and returns the 10 nearest listings with their similarity.
- Measurements and BENCHMARKS.md section; learning guide section 11.

## Out of scope

- Hybrid (vector + full text) search and chunking (SL-32).
- Natural-language-to-filters (SL-23), combining semantic search with BR-03 filters.
- A web page for semantic search; JSON only.
- Changing the existing search, detail page or describe endpoint.
- Other embedding models or Elasticsearch (Q-07).

## Must NOT change

- MNC-1 (BR-05): the `listings` table is unchanged: same columns, and `md5(string_agg(listings::text, ',' ORDER BY id))` is identical before and after the migrations, `listings:describe-all`, `listings:embed`, and a fresh `migrate:fresh --seed`.
- MNC-2: `GET /listings`, `GET /listings/{id}`, `/api/ping`, `/up` and the describe endpoint respond as before; `bench/parity.sh compare` prints `identical` on all six servers.
- MNC-3: the test suite stays network-free and green; `bench/run.sh ping --quick` passes its run conditions.

## Acceptance criteria

- AC-1: WHEN `listings:describe-all` runs (or the seeder runs) THE SYSTEM SHALL give every listing a non-empty English description of 25–80 words that names its year, make, model, body type, fuel and mileage and up to three features; the same listing id always gets the same text; a second run changes nothing.
- AC-2 (BR-91): WHEN `listings:embed` runs with a queue worker THE SYSTEM SHALL fill the vector of every `listing_documents` row with a 1024-dimension vector from the configured embedding provider and model (`AI_EMBEDDINGS_PROVIDER`, default `ollama`; `OLLAMA_EMBED_MODEL`, default `bge-m3`), sending `--batch` descriptions (default 256, chosen by the sweep; first drafted as 64) per model call.
- AC-3: WHEN the model returns a vector whose length is not 1024 THE SYSTEM SHALL fail that job with a clear message and store nothing for its batch.
- AC-4: WHEN `listings:embed` runs again with no description or model change THE SYSTEM SHALL dispatch no model calls; WHEN a description or the model changes THE SYSTEM SHALL re-embed only those listings.
- AC-5 (BR-90): WHEN an embedding call completes or fails THE SYSTEM SHALL write one `ai_runs` row with agent `embeddings`, provider, model, tokens in when the provider reports them (else null), tokens out null, latency, status, and the batch size in a new nullable `inputs` column.
- AC-6: WHEN a visitor requests `GET /api/listings/semantic?q=<text>` (1–200 characters) THE SYSTEM SHALL return 200 JSON with up to 10 listings ordered by cosine similarity (highest first), each with id, year, make, model, body type, price, currency, city, description and `similarity` (1 − cosine distance, 4 decimals); missing, empty or longer `q` returns 422 in the app's JSON error shape; the query embedding is logged (AC-5) and the endpoint is rate-limited per IP like describe.
- AC-7: WHEN the query is "family car with a big boot" THE SYSTEM SHALL return mostly estates, SUVs and MPVs: at least 7 of the 10 results have one of those body types (checked live; in tests, with fake vectors arranged so the expected order is known).
- AC-8 (BR-26): WHEN the full run is done THE SYSTEM SHALL have recorded, in `bench/results/<date>-embeddings/` and a BENCHMARKS.md section: model, Ollama version, batch-size sweep (16, 64, 256) on 2 000 listings with embeddings/s, the full run's embeddings/s and total time with the best batch size, HNSW build time and index size, table size, query latency p50/p95 over 100 queries (embedding vs database time shown separately), and recall@10 of HNSW against an exact scan over 20 queries.
- AC-9 (BR-27): THE SYSTEM SHALL have guide section "11. Embeddings and vector search" explaining what an embedding is, what bge-m3 does with a description, why cosine and normalised vectors, what HNSW trades (speed vs exact results, `ef_search`, build time, memory), why the description generator must not touch the seed, and what the measured numbers say, with links to ADR 0001, the code, the spec, the results, and the pgvector and bge-m3 docs.

## Boundaries

- Always: keep the global `mt_rand` sequence untouched; keep the suite network-free (`Embeddings::fake()`); read provider, model, dimension and batch size from config or options.
- Ask first: adding a dependency (for example a pgvector PHP package); changing `.ddev/config.yaml`; pulling another model.
- Never: commit `.env`; call a cloud model; add columns to `listings` or change its rows.

## Technical context

- Description generator: a `Random\Randomizer` with its own `Mt19937` engine seeded from the listing id (PHP 8.2+), so it does not touch the global sequence. Body type comes from a new `Catalog` map from model to one of: hatchback, saloon, estate, SUV, MPV, coupe; features come from small per-body-type pools (e.g. estate: "large boot", "roof rails", "split-folding rear seats"), plus colour, gearbox and a condition phrase.
- Embedding job: one job per batch of listing ids; it reads the descriptions, calls the SDK once, checks every vector has 1024 numbers, and upserts all rows of the batch in one statement; failure leaves the batch's rows untouched and the job retries (3 tries).
- `listings:embed` options: `--batch=<n>` (default from config), `--limit=<n>` (embed only the first n listings by id, for the sweep), `--fresh` (clear the vectors in `listing_documents` first), `--wait` (waits for the queue to drain and then writes the summary). The summary JSON: model, batch size, listings embedded, model calls, start and end time, total seconds, embeddings/s, and the database sizes.
- HNSW index built with default parameters (`m = 16`, `ef_construction = 64`); the measurement script also times building it after the data is loaded.
- `ai_runs.inputs` (nullable integer) is added by a new migration; agent runs leave it null.
- Search: embed `q`, then `SELECT … FROM listing_documents d JOIN listings l ON l.id = d.listing_id WHERE d.embedding IS NOT NULL ORDER BY d.embedding <=> $1 LIMIT 10`; `similarity = 1 - distance`.

## Clarifications (clarity gate, 2026-11-02)

Resolved under standing approval (self-decided). Facts checked in the repo, `vendor/laravel/ai` 1.3.0 and against Ollama on 2026-11-02.

- **Test database.** `phpunit.xml` runs tests on PostgreSQL (`DB_CONNECTION=pgsql`, database `testing`) with the `vector` extension enabled by the existing migration, so `vector(1024)`, HNSW and `<=>` work in tests. The project CLAUDE.md's SQLite/MariaDB note is out of date; no SQLite fallback is needed.
- **Config keys and env.** `ai.default_for_embeddings` = `env('AI_EMBEDDINGS_PROVIDER') ?: 'ollama'`; on the `ollama` entry: `models.embeddings.default` = `env('OLLAMA_EMBED_MODEL') ?: 'bge-m3'`, `models.embeddings.dimensions` = 1024; `ai.embeddings.batch` = `env('AI_EMBED_BATCH')` or 64. Every read uses `?:` / `filled()` so the empty values pinned in `phpunit.xml` fall back to the defaults (the SL-22 pattern).
- **`ai_runs` for embeddings.** Agent `embeddings` for batch calls and `embeddings:query` for search queries; `prompt_version` null (no prompt); `tokens_in` = the SDK usage (Ollama's `prompt_eval_count`, checked: 20 for two short sentences); `tokens_out` and `ttft_ms` null; `inputs` = number of texts; status `completed` or `failed` (the existing check constraint). Latency pairs `GeneratingEmbeddings` with `EmbeddingsGenerated`/`EmbeddingsFailed` by invocation id in the scoped recorder, as SL-20 does for agents. The events are dispatched by the provider layer (`Providers/Concerns/GeneratesEmbeddings.php`), which also runs under `Embeddings::fake()`; if a test shows they do not fire under the fake, the test fakes Ollama's `/api/embed` over HTTP instead.
- **Retries and logging.** Each attempt is one model call and one `ai_runs` row, so a batch that fails three times leaves three `failed` rows (honest per-call logging). A wrong vector length is not retried: the job fails at once (`$this->fail(...)`) with the message and nothing is stored. Changed in the fix pass: the call is logged `failed` (an unusable answer), for batches and search queries alike, by the embeddings listener.
- **Live check for AC-7.** `listing_documents` gets a `body_type` column (from the catalog map) and the AC-6 response includes it, so the live count is a field count. If the live result has fewer than 7, the feature pools may be tuned (SUV and MPV texts also mention boot space) and the change is recorded in the guide; the test with arranged fake vectors only proves ordering, and that is said plainly in the guide.
- **Measurements run inside the app, not over HTTP**, so the rate limit does not apply: a `listings:search-bench` command reads the query file, times embedding (SDK call) and database (query) separately with `hrtime()`, and writes JSON. The semantic endpoint has its own limiter `semantic-search` using the same `AI_DESCRIBE_PER_MINUTE` setting (separate counter).
- **Query sets.** Committed in `bench/semantic-queries.txt`: 20 hand-written English queries (the recall set), each repeated 5 times for the 100 latency samples. Same file every run (BR-26).
- **Recall@10** = mean over the 20 queries of |HNSW top 10 ∩ exact top 10| / 10. Exact = the same query inside a transaction with `SET LOCAL enable_indexscan = off` (and `SET LOCAL hnsw.ef_search` recorded, default 40). The summary records `ef_search`.
- **Index timing.** `listings:search-bench --index` drops and recreates the migration's index by its name (`listing_documents_embedding_hnsw_idx`, defined once in a constant used by the migration and the command) and times the `CREATE INDEX`. The sweep and the full run keep the index in place, so their embeddings/s include index upkeep; the separate build time shows the cost of building at once. Both are reported.
- **`--wait`.** Polls the `jobs` table for the embedding queue (`embeddings`, on the default connection) every second until no job of this run remains, timeout `--timeout` (default 7200 s), then writes the summary. Without `--wait` the command only dispatches and prints how many jobs it queued; no summary.
- **Summary file.** `--output=<path>`, default `bench/results/<YYYY-MM-DD>-embeddings/embed-<batch>-<limit or all>.json`. Keys: `model`, `provider`, `batch`, `limit`, `listings_embedded`, `model_calls`, `failed_batches`, `started_at`, `finished_at`, `seconds`, `embeddings_per_second`, `table_bytes`, `index_bytes`, `total_bytes`. Counts come from `ai_runs` rows of this run (by time window and agent `embeddings`) and the filled vectors.
- **`--fresh`** sets `embedding`, `embedding_model`, `embedded_text_hash` and `embedded_at` to null for the rows in scope (all, or the first `--limit` by id). **`--limit`** selects the first n listing ids first, then skips unchanged ones.
- **Change detection.** `embedded_text_hash` = `sha256` of the description; `embedding_model` = `provider/model` (e.g. `ollama/bge-m3`); a row is re-embedded when either differs. The skip is decided at dispatch time and checked again in the job. A run refuses to start while jobs of a previous run are still queued (message, exit 1).
- **`describe-all`** upserts every listing's description; rows whose text is unchanged keep their `updated_at`. A changed generator therefore updates descriptions on the next run, which AC-4 then re-embeds.
- **AC-1 rules.** Words counted with `preg_split('/\s+/')` (numbers count). Mileage written as `{n} km` with thin grouping by comma (`42,000 km`). Body types lowercase except `SUV` and `MPV`. Up to three features from the body-type pool; colour, gearbox and condition are extra and not counted.
- **Queue timing.** The job's `$timeout` is 300 s, and `DB_QUEUE_RETRY_AFTER` is documented in `.env.example` as above 300 (default 360 in config) so a slow batch is not picked up twice. The summary records Ollama's version and `OLLAMA_NUM_PARALLEL` if set.
- **Best batch size** = highest embeddings/s in the sweep with no failed batches; the config default changes to it and the decision is recorded in BENCHMARKS.md.
- **Vectors.** Written as text literals with PHP's default float formatting (`json_encode`), no rounding. bge-m3 via Ollama returns unit-length vectors (checked: norm 1.0000), so the app does not normalise; the job asserts the dimension only.
- **Query-time failures.** Ollama down or a wrong-length query vector → 503 JSON `{"message": "Semantic search is unavailable."}` with the call logged `failed`; nothing embedded yet → 200 with an empty `data` list. Ties broken by listing id ascending.
- **Response shape.** `{"data": [ {id, year, make, model, body_type, price_cents, currency, city, description, similarity} ]}` (the app's existing JSON envelope), `similarity` a float rounded to 4 decimals. `q` is trimmed; whitespace-only is 422; the 200 limit counts characters (`mb_strlen`). The route stays under `/api/` like `/api/ping`; no versioning (none exists in the app yet).
- **JSON errors.** `JsonErrorRenderer::wantsJson` already covers `api/*`, so 422, 429 and 503 are JSON.
- **Foreign key** `listing_documents.listing_id` cascades on delete. `listings:embed` only embeds listings that have a document row; it reports how many listings have none and suggests `listings:describe-all`.
- **No listing status** exists (no sold/inactive flag), so search covers all embedded listings.
- **Manual step 2** starts the worker after dispatching (`listings:embed` without `--wait`, then `queue:work --queue=embeddings --stop-when-empty`, then `listings:embed --summary-only`); fixed below in the manual test.
- **Seeder cost.** `describe-all` inserts 50 000 rows in chunks of 1 000 (a few seconds); tests seed only what they create with factories, so they are not affected.
- **Docs.** The doc test also checks the section names the four ideas AC-9 lists (embedding, cosine, HNSW trade-off, seed). The status line and Planned list are the existing ones at the top and bottom of `docs/learning-guide.md`.
- **Parity.** Baselines are the committed `bench/parity/<server>.txt`; `ddev server <name>` restarts long-running workers, so worker servers serve the new code during the check.

## Decisions

1. Generate descriptions from attributes with templates instead of with the LLM: 50 000 LLM calls at about 2 s each would take more than a day, and templates keep the data deterministic (BR-05). The guide notes that template text limits how much "meaning" there is to find (self-decided, standing approval).
2. Text and vector live in a separate `listing_documents` table instead of new columns on `listings`: a vector is 4 KB and a description about 400 bytes, the SL-06/08 benchmarks and the detail page read `listings` rows, and leaving that table byte-for-byte unchanged keeps the benchmarks comparable and the existing responses identical (self-decided, standing approval; replaces the first draft's `description` column).
3. Log embedding calls in `ai_runs` with a new `inputs` column rather than a second log table, so BR-90 has one place to look (self-decided, standing approval).
4. Measure the batch size first on 2 000 listings, then run the full 50 000 with the best size: it teaches how batching changes throughput without three full runs (self-decided, standing approval).
5. Check HNSW recall against an exact scan, because "approximate nearest neighbour" is the main trade-off to understand (self-decided, standing approval).
6. Fix pass (2026-11-02): `ef_search` is configurable (`AI_EF_SEARCH`, default 400 after the sweep: recall 0.405 / 0.485 / 0.605 / 0.795 at 40 / 100 / 200 / 400 for 3.6 / 3.2 / 6.8 / 10.7 ms database time) and the HNSW queries of the endpoint and the bench set `enable_seqscan = off`, because from `ef_search` 200 the planner chose a ~260 ms sequential scan that reported recall 1.0. Also: embedding calls use `AI_EMBED_TIMEOUT` (120 s) instead of the SDK's hidden 30 s; throughput counts from the first model call; the queue guard asks the configured queue; the exact-scan setting is reset explicitly; the index rebuild builds next to the old index and swaps; batch jobs back off 5 s and 30 s; semantic search has its own limit (`AI_SEMANTIC_PER_MINUTE`, 60) (self-decided, standing approval).

## Open questions

- [x] Assumption: BR-05, BR-26, BR-27, BR-90 and BR-91 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, standing approval 2026-11-01).
- [x] Clarity gate (32 items) — all answered in Clarifications (self-decided, standing approval).

## Dependencies

- SL-22 (shipped): Ollama reachable from DDEV, `ai_runs`. ADR 0001 (accepted). bge-m3 pulled.
