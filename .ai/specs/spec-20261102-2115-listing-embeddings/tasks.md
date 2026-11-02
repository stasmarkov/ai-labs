# Listing embeddings and semantic search — Tasks

**Spec**: spec-20261102-2115-listing-embeddings | **Size**: M | **Tasks**: 7

## Phase 1: Text

- [ ] 1. Descriptions in `listing_documents` (backend)
  Covers: AC-1, MNC-1
  - [ ] 1.1 Failing tests: the generator gives the same text for the same listing id and different texts across ids; text is 25–80 words and names year, make, model, body type, fuel and mileage; every model in `Catalog::MODELS_BY_MAKE` has a body type; `listings:describe-all` creates one row per listing and a second run changes nothing; the global `mt_rand` sequence is unchanged by the generator (the next `mt_rand()` after seeding is the same with and without generating a description); `listings` rows hash the same before and after
  - [ ] 1.2 Migration for `listing_documents` (listing_id primary key and foreign key with cascade delete, body_type, description text, embedding `vector(1024)` nullable, embedding_model, embedded_text_hash, embedded_at, timestamps; HNSW `vector_cosine_ops` index); body types in `Catalog`; generator with its own `Random\Randomizer(new Mt19937(...))`; the command; the seeder calls it after inserting listings
  - [ ] 1.3 **Verify**: tests green; `ddev artisan listings:describe-all` fills 50 000 rows; MNC-1 hash identical

## Phase 2: Vectors

- [ ] 2. Embedding config and logging (backend)
  Covers: AC-2, AC-5
  - [ ] 2.1 Failing tests (`Embeddings::fake()`): embeddings use `ai.default_for_embeddings` (`AI_EMBEDDINGS_PROVIDER`, default `ollama`) and model `OLLAMA_EMBED_MODEL` (default `bge-m3`) with dimension 1024; a completed call writes an `ai_runs` row (agent `embeddings`, provider, model, `inputs` = batch size, status `completed`); a failed call writes a `failed` row
  - [ ] 2.2 Config keys (pinned empty in `phpunit.xml` like SL-22); migration adding nullable `ai_runs.inputs`; listeners on the SDK's embedding events
  - [ ] 2.3 **Verify**: tests green

- [ ] 3. `listings:embed` and the batch job (backend)
  Covers: AC-2, AC-3, AC-4
  - [ ] 3.1 Failing tests: `--batch=2` over 5 listings dispatches 3 jobs and makes 3 model calls; vectors stored with 1024 numbers; a 768-number vector fails the job with a message naming both sizes and stores nothing for that batch; a second run makes no calls; changing one description or the model re-embeds only what changed; `--limit`, `--fresh`, `--wait`, `--summary-only` and `--output` work as in Clarifications; a run refuses while previous jobs are queued; the summary JSON has the documented keys
  - [ ] 3.2 Implement the command, job (3 tries) and summary writer
  - [ ] 3.3 **Verify**: tests green

## Phase 3: Search

- [ ] 4. `GET /api/listings/semantic` (backend)
  Covers: AC-6, AC-7, MNC-2
  - [ ] 4.1 Failing tests (fake vectors): results ordered by similarity with the documented fields, at most 10; listings without a vector are skipped; `q` missing, empty or over 200 characters → 422 JSON; the query embedding is logged; the rate limit answers 429 JSON; with fake vectors built so estates/SUVs/MPVs are nearest to the query vector, they come first
  - [ ] 4.2 Implement route, controller, service and repository; register the rate limiter (shared with describe or its own, same setting)
  - [ ] 4.3 **Verify**: tests green; existing listing tests unchanged

## Phase 4: Measure

- [ ] 5. Run and record (bench, docs)
  Covers: AC-2, AC-7, AC-8, MNC-1, MNC-2
  - [ ] 5.1 Add `bench/semantic-queries.txt` (20 queries) and `listings:search-bench` (latency, recall, `--index` build timing). Batch sweep: `listings:embed --fresh --limit=2000 --batch=<16|64|256> --wait` with one queue worker (`--queue=embeddings`); full run with the best size; time the HNSW build separately (drop, then `CREATE INDEX` and time it); sizes with `pg_relation_size` / `pg_total_relation_size`; 100 queries for p50/p95 (embedding time and database time separately); recall@10 over 20 queries (HNSW vs `SET LOCAL enable_indexscan = off` exact scan); "family car with a big boot" body-type count
  - [ ] 5.2 Save the JSON summaries under `bench/results/<date>-embeddings/`; add a BENCHMARKS.md section with versions (Ollama, bge-m3 digest, pgvector) and the numbers
  - [ ] 5.3 **Verify**: AC-7 holds live; MNC-1 hash identical; `bench/parity.sh compare` identical on all six servers; back on nginx-fpm

## Phase 5: Docs and gates

- [ ] 6. Learning guide section 11 (docs)
  Covers: AC-9
  - [ ] 6.1 Failing doc test: heading `## 11. Embeddings and vector search` with links to ADR 0001, the code, the spec, the results folder, https://github.com/pgvector/pgvector and https://huggingface.co/BAAI/bge-m3
  - [ ] 6.2 Write the section with the measured numbers; update the status line and Planned list

- [ ] 7. Gates (all)
  Covers: MNC-3
  - [ ] 7.1 **Verify**: Pint, PHPStan, full suite green (network-free); after committing, `bench/run.sh ping --quick` passes on nginx-fpm

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | deterministic descriptions | `tests/Feature/ListingDescriptionGeneratorTest.php::AC-1 the same listing always gets the same description` | test | 1 |
| AC-2 | batched embedding from config | `tests/Feature/ListingEmbedCommandTest.php::AC-2 embed sends one model call per batch and stores 1024-number vectors` | test | 2, 3 |
| AC-3 | wrong dimension fails | `tests/Feature/ListingEmbedCommandTest.php::AC-3 a vector of the wrong length fails the batch` | test | 3 |
| AC-4 | re-embed only changes | `tests/Feature/ListingEmbedCommandTest.php::AC-4 a second run embeds only what changed` | test | 3 |
| AC-5 | embedding calls logged | `tests/Feature/EmbeddingRunLogTest.php::AC-5 embedding calls write ai_runs rows` | test | 2 |
| AC-6 | semantic endpoint | `tests/Feature/SemanticSearchTest.php::AC-6 semantic search returns the ten most similar listings` | test | 4 |
| AC-7 | big-boot query → estates/SUVs/MPVs | `tests/Feature/SemanticSearchTest.php::AC-7 the nearest vectors come first` + manual-test.md#step-3 | test + manual | 4, 5 |
| AC-8 | measurements recorded | manual-test.md#step-4 | manual | 5 |
| AC-9 | guide section 11 | `tests/Feature/LearningGuideTest.php::AC-9 learning guide explains embeddings and vector search` | test | 6 |
| MNC-1 | listings unchanged | `tests/Feature/ListingDescriptionGeneratorTest.php::MNC-1 describing listings leaves the listings table and the seed sequence unchanged` + manual-test.md#step-1 | test + manual | 1, 5 |
| MNC-2 | existing responses identical | manual-test.md#step-5 | manual | 4, 5 |
| MNC-3 | suite and bench green | manual-test.md#step-5 | manual | 7 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; 50 000 listings described and embedded; no queue worker left running
