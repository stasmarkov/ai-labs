# Docs index and hybrid retrieval — Tasks

**Spec**: spec-20261102-2352-docs-index-retrieval | **Size**: M | **Tasks**: 7

## Phase 1: Corpus and chunks

- [ ] 1. Tables and Laravel docs fetch (backend)
  Covers: AC-1, AC-3
  - [ ] 1.1 Failing tests: migrations create `docs_documents` (source, path unique per source, title, content_hash, indexed_at) and `docs_chunks` (document FK cascade, position, heading, body, content with prefix, token_estimate, oversize, content_hash (indexed), unique (document_id, position), embedding vector(1024) nullable, embedding_model, embedded_at, generated `tsv`, HNSW and GIN indexes); `docs:fetch-laravel` runs `git clone --depth 1 --branch <branch>` into the configured path (process faked in tests) and prints the commit; a failing git leaves the old copy in place and exits 1
  - [ ] 1.2 Implement migrations, models, config keys (`ai.docs.*`), the command
  - [ ] 1.3 **Verify**: tests green; live fetch shows commit `625d1df…` or newer

- [ ] 2. Markdown chunker (backend)
  Covers: AC-2
  - [ ] 2.1 Failing unit tests on fixture markdown: prefix with heading path; code block and table never split; a 700-token code block is one oversize chunk; long paragraph split at sentence ends; overlap 50–80 estimated tokens of whole units from the previous chunk in the same section, none across sections; front matter, anchors and comments stripped; title from first `#` or file name; deterministic output
  - [ ] 2.2 Implement
  - [ ] 2.3 **Verify**: tests green

## Phase 2: Index

- [ ] 3. `docs:index` and embedding jobs (backend)
  Covers: AC-3, AC-4
  - [ ] 3.1 Failing tests (fake files in a temp directory, `Embeddings::fake()`): first run stores documents and chunks and queues jobs in batches of `AI_DOCS_EMBED_BATCH`; second run changes nothing and queues nothing; changing one file re-chunks only it and keeps vectors of unchanged chunks; deleting a file removes its rows; excluded paths are never read; vectors of the wrong length fail the batch; calls logged as `embeddings:docs` with `inputs`
  - [ ] 3.2 Implement, reusing SL-24's embedding pieces (timeout, dimension check, logging, backoff)
  - [ ] 3.3 **Verify**: tests green

## Phase 3: Search

- [ ] 4. Retrieval modes and RRF (backend)
  Covers: AC-5, AC-6
  - [ ] 4.1 Failing tests with fake vectors and real full text: vector mode orders by similarity and uses the HNSW index (`EXPLAIN`); text mode finds an exact identifier; hybrid score equals Σ 1/(60 + rank) over the two lists, ranks reported per list, null when absent; k bounds; the endpoint shape, 422 cases, rate limit and `embeddings:query` logging
  - [ ] 4.2 Implement service, repository, `docs:search` command and `GET /api/docs/search`
  - [ ] 4.3 **Verify**: tests green

## Phase 4: Measure and docs

- [ ] 5. Live index and measurements (bench, docs)
  Covers: AC-1, AC-3, AC-7, AC-8, MNC-1, MNC-2
  - [ ] 5.1 Add `bench/docs-queries.txt` exactly as listed in Clarifications; add `docs:search-bench` writing the per-query, per-mode table and the index stats (including the 20-chunk token check) to `bench/results/<date>-docs-index/`
  - [ ] 5.2 Fetch, index, run one queue worker until empty; run the bench; record results and an interview story in BENCHMARKS.md
  - [ ] 5.3 **Verify**: AC-7 thresholds met (or the miss explained and the spec updated by decision); listings hash unchanged; parity identical on six servers

- [ ] 6. Guide section 12 (docs)
  Covers: AC-9
  - [ ] 6.1 Failing doc test: heading `## 12. Chunking and hybrid retrieval`, links to `.ai/research/ai-chatbot-reference.md`, the code, the spec, the results folder, https://www.postgresql.org/docs/16/textsearch.html and https://github.com/pgvector/pgvector, and the words "overlap", "RRF", "oversize"
  - [ ] 6.2 Write the section with the measured numbers; update the status line and Planned list

- [ ] 7. Gates (all)
  Covers: MNC-3
  - [ ] 7.1 **Verify**: Pint, PHPStan, full suite green; after committing, `bench/run.sh ping --quick` passes

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | Laravel docs fetched | `tests/Feature/DocsFetchLaravelTest.php::AC-1 fetch clones the configured branch and prints the commit` + manual-test.md#step-1 | test + manual | 1, 5 |
| AC-2 | structure-aware chunks | `tests/Unit/MarkdownChunkerTest.php::AC-2 code blocks and tables are never split` | test | 2 |
| AC-3 | idempotent indexing | `tests/Feature/DocsIndexCommandTest.php::AC-3 a second run with no change does nothing` | test | 3 |
| AC-4 | embedding from config, logged | `tests/Feature/DocsIndexCommandTest.php::AC-4 chunk embeddings are logged as embeddings:docs` | test | 3 |
| AC-5 | three modes and RRF | `tests/Feature/DocsSearchTest.php::AC-5 hybrid scores are reciprocal rank fusion of both lists` | test | 4 |
| AC-6 | endpoint | `tests/Feature/DocsSearchTest.php::AC-6 the search endpoint returns ranked chunks` | test | 4 |
| AC-7 | identifier and meaning queries | manual-test.md#step-3 | manual | 5 |
| AC-8 | measurements recorded | manual-test.md#step-4 | manual | 5 |
| AC-9 | guide section 12 | `tests/Feature/LearningGuideTest.php::AC-9 learning guide explains chunking and hybrid retrieval` | test | 6 |
| MNC-1 | SL-24 untouched | manual-test.md#step-5 | manual | 5 |
| MNC-2 | endpoints and parity | manual-test.md#step-5 | manual | 5 |
| MNC-3 | suite and bench | manual-test.md#step-5 | manual | 7 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; both corpora indexed and embedded; no queue worker left running
