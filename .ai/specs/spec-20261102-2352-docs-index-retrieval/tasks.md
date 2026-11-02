# Docs index and hybrid retrieval — Tasks

**Spec**: spec-20261102-2352-docs-index-retrieval | **Size**: M | **Tasks**: 14

## Phase 1: Corpus and chunks

- [x] 1. Tables and Laravel docs fetch (backend)
  Covers: AC-1, AC-3
  - [x] 1.1 Failing tests: migrations create `docs_documents` (source, path unique per source, title, content_hash, indexed_at) and `docs_chunks` (document FK cascade, position, heading, body, content with prefix, token_estimate, oversize, content_hash (indexed), unique (document_id, position), embedding vector(1024) nullable, embedding_model, embedded_at, generated `tsv`, HNSW and GIN indexes); `docs:fetch-laravel` runs `git clone --depth 1 --branch <branch>` into the configured path (process faked in tests) and prints the commit; a failing git leaves the old copy in place and exits 1
  - [x] 1.2 Implement migrations, models, config keys (`ai.docs.*`), the command
  - [x] 1.3 **Verify**: tests green; live fetch shows commit `625d1df…` or newer

- [x] 2. Markdown chunker (backend)
  Covers: AC-2
  - [x] 2.1 Failing unit tests on fixture markdown: prefix with heading path; code block and table never split; a 700-token code block is one oversize chunk; long paragraph split at sentence ends; overlap 50–80 estimated tokens of whole units from the previous chunk in the same section, none across sections; front matter, anchors and comments stripped; title from first `#` or file name; deterministic output
  - [x] 2.2 Implement
  - [x] 2.3 **Verify**: tests green

## Phase 2: Index

- [x] 3. `docs:index` and embedding jobs (backend)
  Covers: AC-3, AC-4
  - [x] 3.1 Failing tests (fake files in a temp directory, `Embeddings::fake()`): first run stores documents and chunks and queues jobs in batches of `AI_DOCS_EMBED_BATCH`; second run changes nothing and queues nothing; changing one file re-chunks only it and keeps vectors of unchanged chunks; deleting a file removes its rows; excluded paths are never read; vectors of the wrong length fail the batch; calls logged as `embeddings:docs` with `inputs`
  - [x] 3.2 Implement, reusing SL-24's embedding pieces (timeout, dimension check, logging, backoff)
  - [x] 3.3 **Verify**: tests green

## Phase 3: Search

- [x] 4. Retrieval modes and RRF (backend)
  Covers: AC-5, AC-6
  - [x] 4.1 Failing tests with fake vectors and real full text: vector mode orders by similarity and uses the HNSW index (`EXPLAIN`); text mode finds an exact identifier; hybrid score equals Σ 1/(60 + rank) over the two lists, ranks reported per list, null when absent; k bounds; the endpoint shape, 422 cases, rate limit and `embeddings:query` logging
  - [x] 4.2 Implement service, repository, `docs:search` command and `GET /api/docs/search`
  - [x] 4.3 **Verify**: tests green

## Phase 4: Measure and docs

- [x] 5. Live index and measurements (bench, docs)
  Covers: AC-1, AC-3, AC-7, AC-8, MNC-1, MNC-2
  - [x] 5.1 Add `bench/docs-queries.txt` exactly as listed in Clarifications; add `docs:search-bench` writing the per-query, per-mode table and the index stats (including the 20-chunk token check) to `bench/results/<date>-docs-index/`
  - [x] 5.2 Fetch, index, run one queue worker until empty; run the bench; record results and an interview story in BENCHMARKS.md
  - [x] 5.3 **Verify**: AC-7 thresholds met (or the miss explained and the spec updated by decision); listings hash unchanged; parity identical on six servers

- [x] 6. Guide section 12 (docs)
  Covers: AC-9
  - [x] 6.1 Failing doc test: heading `## 12. Chunking and hybrid retrieval`, links to `.ai/research/ai-chatbot-reference.md`, the code, the spec, the results folder, https://www.postgresql.org/docs/16/textsearch.html and https://github.com/pgvector/pgvector, and the words "overlap", "RRF", "oversize"
  - [x] 6.2 Write the section with the measured numbers; update the status line and Planned list

- [ ] 7. Gates (all)
  Covers: MNC-3
  - [ ] 7.1 **Verify**: Pint, PHPStan, full suite green; after committing, `bench/run.sh ping --quick` passes

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | Laravel docs fetched | `tests/Feature/DocsFetchLaravelTest.php::AC-1 fetch clones the configured branch and prints the commit` + manual-test.md#step-1, manual-test.md#step-2 | test + manual | 1, 5 |
| AC-2 | structure-aware chunks | `tests/Unit/MarkdownChunkerTest.php::AC-2 code blocks and tables are never split` | test | 2 |
| AC-3 | idempotent indexing | `tests/Feature/DocsIndexCommandTest.php::AC-3 a second run with no change does nothing` | test | 3 |
| AC-4 | embedding from config, logged | `tests/Feature/DocsIndexCommandTest.php::AC-4 chunk embeddings are logged as embeddings:docs` | test | 3 |
| AC-5 | three modes and RRF | `tests/Feature/DocsSearchTest.php::AC-5 hybrid scores are reciprocal rank fusion of both lists` | test | 4 |
| AC-6 | endpoint | `tests/Feature/DocsSearchTest.php::AC-6 the search endpoint returns ranked chunks` | test | 4 |
| AC-7 | identifier and meaning queries | manual-test.md#step-9 | manual | 5 |
| AC-8 | measurements recorded | manual-test.md#step-10 | manual | 5 |
| AC-9 | guide section 12 | `tests/Feature/LearningGuideTest.php::AC-9 learning guide explains chunking and hybrid retrieval` | test | 6 |
| MNC-1 | SL-24 untouched | manual-test.md#step-12 | manual | 5 |
| MNC-2 | endpoints and parity | manual-test.md#step-13 | manual | 5 |
| MNC-3 | suite and bench | manual-test.md#step-14 | manual | 7 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; both corpora indexed and embedded; no queue worker left running

## Phase: Gaps (converge round 1)

- [x] 8. Prove MNC-2 parity against the committed baselines (bench)
  Covers: MNC-2
  - [x] 8.1 BENCHMARKS.md records that the committed `bench/parity/*.txt` baselines differ on `/listings/1`, and task 5 compared the six servers only with each other (against nginx-fpm). That is not what MNC-2 and manual-test.md step 13 ask for (`bench/parity.sh compare bench/parity/$s.txt <fresh capture>`). Show the difference is from the rebuilt Vite assets, not this slice: on the same `public/build`, capture all six servers at the pre-slice commit `856a86f` (stash or worktree) and again with the slice, then compare each pair. If the baselines have to be re-captured, record a decision in spec-lite.md
  - [x] 8.2 **Verify**: `bench/parity.sh compare <pre-slice capture> <post-slice capture>` prints `identical` for nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane and frankenphp-worker, and the BENCHMARKS.md parity note gives the result

- [x] 9. Embedding time window limited to the measured run (backend)
  Covers: AC-8
  - [x] 9.1 `EloquentDocsBenchRepository::embeddingWindow` reads every `embeddings:docs` row in `ai_runs` with `inputs > 1` and has no time bound. After a second indexing run (for example the one-file change in manual-test.md step 5, before steps 9–10), the reported "first to last call" spans both runs and inflates `embedding.seconds`, `calls` and `tokens_in`. The `inputs > 1` filter also drops a real last batch of 1 chunk. Write a failing test first: two runs of `embeddings:docs` rows hours apart, plus single-chunk token-check rows. Then limit the window to the latest run, for example the rows since the latest `indexed_at`, or a `--since` option, while still leaving out the token-check calls
  - [x] 9.2 **Verify**: the new DocsSearchBenchTest case passes. With rows from an earlier run present, `index.json` `embedding.calls` and `seconds` count only the latest run

- [x] 10. Test the "git not found" path of `docs:fetch-laravel` (backend)
  Covers: AC-1
  - [x] 10.1 AC-1 requires a clear failure that changes nothing when `git` is missing. `FetchLaravelDocsCommand` handles this in its `catch (Throwable)` branch (`git could not run: …`); on PHP 8.4 a missing binary makes `posix_spawn` fail, so that branch runs. No test in `tests/Feature/DocsFetchLaravelTest.php` reaches it: the only failure test is a clone that exits 128. Add a test where the faked process throws (as when the binary cannot be launched). It should assert exit 1, the "git could not run" message, the old copy and `laravel.json` unchanged, and no `.tmp-*` folder left
  - [x] 10.2 **Verify**: `ddev artisan test --compact tests/Feature/DocsFetchLaravelTest.php` is green, with the new case included

## Phase: Gaps (converge round 2)

- [x] 11. Correct what BENCHMARKS.md and guide section 12 say about oversize chunks (docs)
  Covers: AC-8, AC-9
  - [x] 11.1 BENCHMARKS.md says "Oversize chunks are single code blocks or tables above 500 estimated tokens … the largest (8 780) is one block in the Laravel docs", and later "The 8 780-token chunk is one Laravel docs table or code block". Guide section 12, step 4, says "A block above 500 estimated tokens becomes an oversize chunk … the largest 8 780 estimated tokens, one block in the Laravel docs". The live index does not match. `select d.path, c.token_estimate, left(c.body, 80) from docs_chunks c join docs_documents d on d.id = c.document_id where c.oversize` lists 15 oversize chunks. 7 are tables (contracts, facades, backlog, scheduling, pagination, migrations, docs/architecture). 8 are paragraphs with no sentence end: link lists of method names in strings.md ×2, collections.md ×2, http-tests.md, dusk.md and helpers.md, plus a base64 `data:image/png` image in mcp.md. That image is the 8 780-token chunk. None of the 15 is a code block. `MarkdownChunker::pack` emits such a paragraph as one oversize "sentence", which the Clarifications allow, but the recorded explanation is wrong. Rewrite both texts with the real breakdown: tables, link lists with no sentence end, one inline base64 image, no code blocks. Say that the largest chunk is the image. Keep the numbers (15, 0.6 %, 8 780) unchanged. Leave the chunker as it is, so the recorded measurements stay valid
  - Done differently (self-decided, standing approval): the review rated the chunker defect major, and the 8 780-token chunk is above bge-m3's context, so documenting it was worse than fixing it. The chunker was fixed (task 14), the index rebuilt from empty and every number re-measured; both texts now describe the first run's breakdown and the new one (7 oversize, all tables, largest 2 738).
  - [x] 11.2 **Verify**: the oversize breakdown in BENCHMARKS.md and in guide section 12 matches the live `where c.oversize` query, and neither file still calls the 8 780-token chunk a code block or table; `ddev artisan test --compact tests/Feature/LearningGuideTest.php` is green

- [x] 12. Count a real single-chunk embedding batch in the embedding window (backend)
  Covers: AC-8
  - [x] 12.1 Task 9.1 said the `inputs > 1` filter "also drops a real last batch of 1 chunk", but `EloquentDocsBenchRepository::embeddingWindow` still has `AND inputs > 1`. The new test `AC-8 the embedding window covers only the latest indexing run` now fixes that behaviour in place: row `new-b` (inputs 1, after the latest `indexed_at`) is a real batch, yet the test expects `calls` 1. After a one-file change that leaves 1 chunk to embed (manual-test.md step 5), the window is empty and `index.json` `embedding` becomes null. The token-check calls run after the last vector is stored, so an upper bound can tell them apart instead of the input count. Keep the lower bound `created_at >= max(docs_documents.indexed_at)`, drop `inputs > 1`, and add `created_at <= max(docs_chunks.embedded_at)` for the configured model. Change the test first: a 1-input row before the last `embedded_at` counts, and a 1-input token-check row after it does not
  - Done differently (self-decided, standing approval): instead of an upper bound on `embedded_at`, the token-check calls are logged under their own agent `embeddings:docs-token-check`, and the window counts only `embeddings:docs`. Simpler, and it does not depend on the order of the calls. The live re-index after the chunker fix queued 33 chunks (32 + 1), exactly this case.
  - [x] 12.2 **Verify**: `ddev artisan test --compact tests/Feature/DocsSearchBenchTest.php` is green, and the updated window test counts the single-chunk batch and leaves out the later token-check row

- [x] 13. Tick task 1 and remove the stray `tasks.md-E` (docs)
  Covers: AC-1, AC-3
  - [x] 13.1 Task 1 is built and its Verify is met. `DocsFetchLaravelTest` passes, and the live fetch recorded commit `625d1dfb61018e6bc50cc7ab67fca89ae018dfa2` in `storage/app/docs/laravel.json` and `bench/results/2026-11-02-docs-index/index.json`. But task 1 and its items 1.1–1.3 are still `[ ]` in this file, so the "All tasks checked" Done-when item cannot pass. `.ai/specs/spec-20261102-2352-docs-index-retrieval/tasks.md-E` is an untracked backup left by a `sed -i -E` edit; it holds an older copy of this file and would be committed with the slice. Tick 1, 1.1, 1.2 and 1.3, and delete `tasks.md-E`
  - [x] 13.2 **Verify**: `grep -n '^- \[ \] 1\.' tasks.md` prints nothing, and `ls .ai/specs/spec-20261102-2352-docs-index-retrieval` shows no `tasks.md-E`

## Phase: Review fix pass

- [x] 14. Fix the confirmed review findings (backend, docs)
  - [x] 14.1 Chunker: paragraphs and items above the limit are cut at line breaks, then at the last space before the limit; `data:` image URIs shortened to `data:…`; an unclosed fence runs to the end of the document (CommonMark), not to the first `#` comment inside it; HTML stripping keeps autolinks and generic types; `MarkdownChunker::VERSION` is part of the document hash so rule changes re-chunk. Four new unit tests
  - [x] 14.2 Search: hybrid depth is at least k; HNSW search sets `hnsw.iterative_scan = strict_order` so the model filter cannot starve the list; the bench embeds each query once; query length limit shared by the request and the command; NUL bytes and invalid UTF-8 give 422; the CLI no longer cuts the snippet's ellipsis
  - [x] 14.3 Reuse: `ListingEmbeddingService::embedTexts` holds the one embedding call and its vector checks, used by both embedding services
  - [x] 14.4 `docs:fetch-laravel` moves the old copy aside and restores it if the swap fails; the bench reports the right line number after blank lines; `.env.example` documents `AI_DOCS_LARAVEL_REPOSITORY`
  - [x] 14.5 Not changed, with reason: the corpus serving `done` spec-lite files publicly (the API is local-only and the spec chose that corpus; the files are in git anyway); re-chunking copying vectors through PHP (121 documents, measured 2.1 s); the full chunk body in search rows (after the chunker fix a body is at most about 2 000 characters except 7 tables, and SL-45 needs whole bodies); the two near-identical embedding jobs (separate queued classes are clearer than a generic one)
  - [x] 14.6 **Verify**: full suite, Pint and PHPStan green; index rebuilt from empty; `docs:search-bench` thresholds met; BENCHMARKS.md and guide section 12 re-measured
