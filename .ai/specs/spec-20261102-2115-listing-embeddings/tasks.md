# Listing embeddings and semantic search — Tasks

**Spec**: spec-20261102-2115-listing-embeddings | **Size**: M | **Tasks**: 7

## Phase 1: Text

- [x] 1. Descriptions in `listing_documents` (backend)
  Covers: AC-1, MNC-1
  - [x] 1.1 Failing tests: the generator gives the same text for the same listing id and different texts across ids; text is 25–80 words and names year, make, model, body type, fuel and mileage; every model in `Catalog::MODELS_BY_MAKE` has a body type; `listings:describe-all` creates one row per listing and a second run changes nothing; the global `mt_rand` sequence is unchanged by the generator (the next `mt_rand()` after seeding is the same with and without generating a description); `listings` rows hash the same before and after
  - [x] 1.2 Migration for `listing_documents` (listing_id primary key and foreign key with cascade delete, body_type, description text, embedding `vector(1024)` nullable, embedding_model, embedded_text_hash, embedded_at, timestamps; HNSW `vector_cosine_ops` index); body types in `Catalog`; generator with its own `Random\Randomizer(new Mt19937(...))`; the command; the seeder calls it after inserting listings
  - [x] 1.3 **Verify**: tests green; `ddev artisan listings:describe-all` fills 50 000 rows; MNC-1 hash identical

## Phase 2: Vectors

- [x] 2. Embedding config and logging (backend)
  Covers: AC-2, AC-5
  - [x] 2.1 Failing tests (`Embeddings::fake()`): embeddings use `ai.default_for_embeddings` (`AI_EMBEDDINGS_PROVIDER`, default `ollama`) and model `OLLAMA_EMBED_MODEL` (default `bge-m3`) with dimension 1024; a completed call writes an `ai_runs` row (agent `embeddings`, provider, model, `inputs` = batch size, status `completed`); a failed call writes a `failed` row
  - [x] 2.2 Config keys (pinned empty in `phpunit.xml` like SL-22); migration adding nullable `ai_runs.inputs`; listeners on the SDK's embedding events
  - [x] 2.3 **Verify**: tests green

- [x] 3. `listings:embed` and the batch job (backend)
  Covers: AC-2, AC-3, AC-4
  - [x] 3.1 Failing tests: `--batch=2` over 5 listings dispatches 3 jobs and makes 3 model calls; vectors stored with 1024 numbers; a 768-number vector fails the job with a message naming both sizes and stores nothing for that batch; a second run makes no calls; changing one description or the model re-embeds only what changed; `--limit`, `--fresh`, `--wait`, `--summary-only` and `--output` work as in Clarifications; a run refuses while previous jobs are queued; the summary JSON has the documented keys
  - [x] 3.2 Implement the command, job (3 tries) and summary writer
  - [x] 3.3 **Verify**: tests green

## Phase 3: Search

- [x] 4. `GET /api/listings/semantic` (backend)
  Covers: AC-6, AC-7, MNC-2
  - [x] 4.1 Failing tests (fake vectors): results ordered by similarity with the documented fields, at most 10; listings without a vector are skipped; `q` missing, empty or over 200 characters → 422 JSON; the query embedding is logged; the rate limit answers 429 JSON; with fake vectors built so estates/SUVs/MPVs are nearest to the query vector, they come first
  - [x] 4.2 Implement route, controller, service and repository; register the rate limiter (shared with describe or its own, same setting)
  - [x] 4.3 **Verify**: tests green; existing listing tests unchanged

## Phase 4: Measure

- [x] 5. Run and record (bench, docs)
  Covers: AC-2, AC-7, AC-8, MNC-1, MNC-2
  - [x] 5.1 Add `bench/semantic-queries.txt` (20 queries) and `listings:search-bench` (latency, recall, `--index` build timing). Batch sweep: `listings:embed --fresh --limit=2000 --batch=<16|64|256> --wait` with one queue worker (`--queue=embeddings`); full run with the best size; time the HNSW build separately (drop, then `CREATE INDEX` and time it); sizes with `pg_relation_size` / `pg_total_relation_size`; 100 queries for p50/p95 (embedding time and database time separately); recall@10 over 20 queries (HNSW vs `SET LOCAL enable_indexscan = off` exact scan); "family car with a big boot" body-type count
  - [x] 5.2 Save the JSON summaries under `bench/results/<date>-embeddings/`; add a BENCHMARKS.md section with versions (Ollama, bge-m3 digest, pgvector) and the numbers
  - [x] 5.3 **Verify**: AC-7 holds live; MNC-1 hash identical; `bench/parity.sh compare` identical on all six servers; back on nginx-fpm

## Phase 5: Docs and gates

- [x] 6. Learning guide section 11 (docs)
  Covers: AC-9
  - [x] 6.1 Failing doc test: heading `## 11. Embeddings and vector search` with links to ADR 0001, the code, the spec, the results folder, https://github.com/pgvector/pgvector and https://huggingface.co/BAAI/bge-m3
  - [x] 6.2 Write the section with the measured numbers; update the status line and Planned list

- [x] 7. Gates (all)
  Covers: MNC-3
  - [x] 7.1 **Verify**: Pint, PHPStan, full suite green (network-free); after committing, `bench/run.sh ping --quick` passes on nginx-fpm

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | deterministic descriptions | `tests/Feature/ListingDescriptionGeneratorTest.php::AC-1 the same listing always gets the same description` + manual-test.md#step-1 | test + manual | 1 |
| AC-2 | batched embedding from config | `tests/Feature/ListingEmbedCommandTest.php::AC-2 embed sends one model call per batch and stores 1024-number vectors` + manual-test.md#step-2 | test + manual | 2, 3 |
| AC-3 | wrong dimension fails | `tests/Feature/ListingEmbedCommandTest.php::AC-3 a vector of the wrong length fails the batch` + manual-test.md#step-2 | test + manual | 3 |
| AC-4 | re-embed only changes | `tests/Feature/ListingEmbedCommandTest.php::AC-4 a second run embeds only what changed` + manual-test.md#step-2 | test + manual | 3 |
| AC-5 | embedding calls logged | `tests/Feature/EmbeddingRunLogTest.php::AC-5 embedding calls write ai_runs rows` + manual-test.md#step-6 + manual-test.md#step-7 | test + manual | 2 |
| AC-6 | semantic endpoint | `tests/Feature/SemanticSearchTest.php::AC-6 semantic search returns the ten most similar listings` + manual-test.md#step-6 + manual-test.md#step-7 | test + manual | 4 |
| AC-7 | big-boot query → estates/SUVs/MPVs | `tests/Feature/SemanticSearchTest.php::AC-7 the nearest vectors come first` + manual-test.md#step-3 | test + manual | 4, 5 |
| AC-8 | measurements recorded | manual-test.md#step-4 | manual | 5 |
| AC-9 | guide section 11 | `tests/Feature/LearningGuideTest.php::AC-9 learning guide explains embeddings and vector search` + manual-test.md#step-8 | test + manual | 6 |
| MNC-1 | listings unchanged | `tests/Feature/ListingDescriptionGeneratorTest.php::MNC-1 the listings table hash is identical before and after describe-all` + `tests/Feature/SeederTest.php::MNC-1 a full seed gives the known listings hash` + manual-test.md#step-1 + manual-test.md#step-5 | test + manual | 1, 5 |
| MNC-2 | existing responses identical | manual-test.md#step-5 | manual | 4, 5 |
| MNC-3 | suite and bench green | manual-test.md#step-5 | manual | 7 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project on nginx-fpm; 50 000 listings described and embedded; no queue worker left running

## Phase: Gaps (converge round 1)

- [x] 8. Make recall@10 compare HNSW with an exact scan, and guard index use (backend, bench, docs)
  Covers: AC-8, AC-9
  - `EloquentSemanticBenchRepository::NEAREST` orders by `embedding <=> ?, listing_id`, the same two-key sort that BENCHMARKS.md says the HNSW index cannot serve. `EXPLAIN` on the dev database shows `Seq Scan on listing_documents` for that query and `Index Scan using listing_documents_embedding_hnsw_idx` only when ordering by distance alone. So `approximateIds` is an exact scan too, and the recorded recall@10 of 1.0 (`search-bench.json`, BENCHMARKS.md, guide section 11) compares exact with exact. No test fails if a query stops using the index.
  - Order `approximateIds` by distance alone (inner query with the limit, tie-break outside, as in `EloquentSemanticSearchRepository::nearest`). Add a test that runs `EXPLAIN` with `SET LOCAL enable_seqscan = off` and asserts the HNSW index name appears for both the endpoint query and the bench approximate query, and does not appear for `exactIds`. Re-run `listings:search-bench`, then update `search-bench.json`, the BENCHMARKS.md recall line and its "Surprises" note, and the guide's recall numbers.
  - [x] **Verify**: the new EXPLAIN test passes; `EXPLAIN` of the bench approximate query on the dev database shows the HNSW index scan; `bench/results/2026-11-02-embeddings/search-bench.json`, BENCHMARKS.md and guide section 11 show the re-measured recall@10 at `ef_search` 40; full suite green

- [x] 9. Record the Ollama version and sub-second timing in the embedding summaries (backend, bench)
  Covers: AC-8
  - Clarifications say "The summary records Ollama's version and `OLLAMA_NUM_PARALLEL` if set", but `EmbeddingRunSummary::toArray()` has no such keys and none of the `embed-*.json` files or `search-bench.json` contains an Ollama version. `listing_documents.embedded_at` is `timestampTz` with Laravel's default precision 0 (stored values are whole seconds, e.g. `2026-11-02 20:05:15+00`), and `summarize()` uses `startOfSecond()`, so `seconds` is whole seconds. The sweep shows 64 and 256 tied at exactly 40 s and 50.00 embeddings/s, so the "best batch size" rule could not choose.
  - Add `ollama_version` (from Ollama's `/api/version`, null for other providers or when unreachable) and `ollama_num_parallel` (null if unset) to the embed summary and the search-bench report. Time runs at sub-second precision: give `embedded_at` microsecond precision in a new migration, or take the run end from the queue drain time. Do not change `listings`. Re-run the 2 000-listing sweep at 16, 64 and 256, then update BENCHMARKS.md and guide section 11 with the decided batch size. Change the `ai.embeddings.batch` default if a different size wins.
  - [x] **Verify**: a test asserts the summary JSON has `ollama_version` and `ollama_num_parallel` and a fractional `seconds` (HTTP faked, network-free); the new `embed-<batch>-2000.json` files contain the Ollama version and non-integer `seconds`; BENCHMARKS.md names a single best batch size; full suite green

- [x] 10. Log tokens in as null when the provider reports none, and assert tokens out null (backend)
  Covers: AC-5
  - AC-5 says "tokens in when the provider reports them (else null), tokens out null". `RecordEmbeddingsRun::onGenerated` passes `$event->response->usage->inputTokens`, and the SDK's `Usage` defaults to `0`. `OllamaGateway` uses `$data['prompt_eval_count'] ?? 0`, and `FakeEmbeddingGateway` uses `new Usage`. So an unreported count is stored as `0`, not null. `tests/Feature/EmbeddingRunLogTest.php` never asserts `tokens_in` or `tokens_out`.
  - Map an unreported count (0) to null in the embeddings listener (`AiRunRecorder::completed` accepts `?int $tokensIn`). Extend the AC-5 test to assert `tokens_out` is null, `tokens_in` is null under `Embeddings::fake()`, and `tokens_in` equals `prompt_eval_count` when Ollama's `/api/embed` is faked over HTTP with that field.
  - [x] **Verify**: `ddev artisan test --compact --filter='AC-5'` passes with the new assertions; full suite green

- [x] 11. Prove the seeder writes descriptions and a fresh seed keeps the listings hash (backend)
  Covers: AC-1, MNC-1
  - AC-1 says "or the seeder runs", and `ListingSeeder` now ends with `describeAll()` and uses `TRUNCATE ... CASCADE`, but no test checks that seeding fills `listing_documents` (`tests/Feature/SeederTest.php` is unchanged). MNC-1 needs the hash to stay the same after "a fresh `migrate:fresh --seed`". Task 1 did not run that, and manual-test.md has no step for it. The Traceability MNC-1 row names `MNC-1 describing listings leaves the listings table and the seed sequence unchanged`, a test that does not exist; the real tests are `MNC-1 the listings table hash is identical before and after describe-all` and `AC-1 leaves the global mt_rand sequence untouched`.
  - In `SeederTest`, assert that seeding writes one `listing_documents` row per listing, that a second seed leaves no orphan rows, and that the full `$this->seed()` gives the listings `md5(string_agg(listings::text, ',' ORDER BY id))` of `6a0f96d2c7139919cc215a520072d3f7`. Fix the MNC-1 Traceability row to name real tests.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/SeederTest.php` passes with the new assertions; the Traceability MNC-1 row names tests that exist; full suite green

## Phase: Gaps (converge round 2)

- [x] 12. Re-run the full 50 000 embedding at the best batch size and re-time the HNSW build (bench, docs)
  Covers: AC-8, AC-2, AC-9
  - AC-8 asks for "the full run's embeddings/s and total time with the best batch size". Task 9 made 256 the best size and the default (`config/ai.php` `AI_EMBED_BATCH ?: 256`), but the only full run is `bench/results/2026-11-02-embeddings/embed-64-all.json`. It used batch 64, has whole-second `seconds` (1153), and has no `ollama_version` or `ollama_num_parallel` keys. BENCHMARKS.md says "The full run below was taken earlier at batch 64", and guide section 11 shows "Full run, 50 000 listings, batch 64". The HNSW build time is only in `search-bench-before-subquery.json` (`index_build_seconds` 35.42). The current `search-bench.json` has `index_build_seconds: null`, and the "41.1 s" run quoted in BENCHMARKS.md and the guide has no results file. The sizes quoted (391 MB index, 677 MB total) come from the batch-64 run, while `search-bench.json` now reports `index_bytes` 423 723 008. AC-2 still says "(default 64)", which no longer matches the code.
  - With one worker on `--queue=embeddings`, run `listings:embed --fresh --batch=256 --wait`, which writes `embed-256-all.json` with the Ollama version and fractional seconds. Then run `listings:search-bench --index` so one `search-bench.json` holds the build time, index size, latency and recall of the final state. Update the BENCHMARKS.md full-run table and sizes, the search table and recall, and the "What the numbers say" bullets. Update the guide section 11 numbers table and its HNSW build, memory and insert-cost bullets. Keep `embed-64-all.json` as the earlier run and label it that way. Point `BenchmarksDocTest` and `LearningGuideTest` at the new file and figures. In spec-lite.md, change AC-2's "(default 64)" to the decided default, with a note that Clarifications (best batch size) allowed the change. Afterwards check the listings hash (MNC-1) again.
  - [x] **Verify**: `bench/results/2026-11-02-embeddings/embed-256-all.json` exists with `batch` 256, `listings_embedded` 50000, `failed_batches` 0, `ollama_version` set and non-integer `seconds`; `search-bench.json` has non-null `index_build_seconds`; BENCHMARKS.md and guide section 11 name the batch-256 full run and the new build time, sizes, latency and recall; the listings hash is still `6a0f96d2c7139919cc215a520072d3f7`; full suite green; no queue worker left running

- [x] 13. Log a wrong-length query embedding as failed (backend)
  Covers: AC-6, AC-5
  - Clarifications (query-time failures): "Ollama down or a wrong-length query vector → 503 JSON ... with the call logged `failed`". In `SemanticSearchService::embedQuery`, the length check runs after `generate()` has returned. By then `RecordEmbeddingsRun::onGenerated` has already written the `embeddings:query` row as `completed`, and nothing marks it failed. The test `AC-6 semantic search answers 503 JSON when the query vector has the wrong length` (`tests/Feature/SemanticSearchTest.php`) asserts only the status and message, not the `ai_runs` row.
  - When the run's agent is `embeddings:query` and a returned vector's length differs from the requested dimensions, record the call as `failed` with `InvalidEmbeddingException` (class and message) instead of `completed`. For example, check this in `RecordEmbeddingsRun::onGenerated` against `$event->prompt->dimensions`, using the agent kept by `AiRunRecorder::started`. Batch calls keep the batch rule (`completed`, because the model did answer). Extend the wrong-length test to assert one `embeddings:query` row with status `failed` and an `InvalidEmbeddingException` error class. Assert that the AC-3 batch path still logs `completed`.
  - [x] **Verify**: `ddev artisan test --compact --filter='wrong length'` passes with the new `ai_runs` assertions; full suite green

- [x] 14. Make guide section 11 say what the AC-7 test proves, and what template text limits (docs)
  Covers: AC-9
  - Clarifications ("Live check for AC-7") say "the test with arranged fake vectors only proves ordering, and that is said plainly in the guide". Decision 1 says "The guide notes that template text limits how much 'meaning' there is to find". Section 11 of `docs/learning-guide.md` says neither: it never mentions the fake-vector test or what it proves. It also states the cause of the low recall as fact ("on template text many vectors sit at nearly equal distance, so HNSW returns different ids"), while BENCHMARKS.md says "That tie explanation is not isolated here".
  - Add one sentence saying the AC-7 test uses arranged fake vectors and proves only the ordering, and that the "7 of 10" claim rests on the live run. Add one sentence on how template descriptions limit the meaning a model can find (every text says the same kinds of things in the same order). Reword the recall bullet so the tie explanation reads as an untested hypothesis, matching BENCHMARKS.md. Extend `AC-9 learning guide explains embeddings and vector search` to check for the fake-vector and template notes.
  - [x] **Verify**: `ddev artisan test --compact --filter='AC-9 learning guide explains embeddings'` passes with the new needles; section 11 contains the three statements; full suite green
