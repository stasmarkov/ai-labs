---
id: spec-20261102-2352-docs-index-retrieval
title: Docs index with structure-aware chunks and hybrid retrieval
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-02T21:58:09Z
created: 2026-11-03
stacks: [laravel]
slice: SL-32
brs: [BR-90, BR-91, BR-26, BR-27]
---

# Docs index with structure-aware chunks and hybrid retrieval

## Summary

The first half of the docs chatbot (the answering half is SL-45). The developer indexes two corpora — this repo's own markdown docs and the Laravel 13 documentation — into chunks that follow the document structure, embeds every chunk with the local bge-m3 model, and searches them three ways: by vector similarity, by PostgreSQL full text, and by both fused with Reciprocal Rank Fusion (RRF). The result is a ranked list of chunks with their source file, section and scores, from a command and a JSON endpoint. The slice shows, with numbers, why hybrid search finds exact identifiers (`flushpackets`, `AI_EF_SEARCH`, `BR-95`) that vector search alone misses, and what chunk sizes and token estimates look like on real text.

## Context

- SL-24 (shipped): bge-m3 through the `laravel/ai` `ollama` driver, `vector(1024)`, cosine, HNSW, `ef_search` 400 with `enable_seqscan = off` for the index query (the planner otherwise picks a sequential scan at high `ef_search`), embedding calls logged in `ai_runs` with `inputs`, `AI_EMBED_TIMEOUT`, dimension check, queued batch jobs on the `embeddings` queue with backoff, the `semantic-search` rate limiter. Reuse these pieces rather than copying them.
- Reference design `.ai/research/ai-chatbot-reference.md` (owner input, 2026-11-02): chunking rules (300–500 tokens, 50–80 overlap, headings → paragraphs → sentences, tables and code never split, "Document › Section" prefix, per-document hash), hybrid search with RRF (top 20 + top 20, score Σ 1/(60 + rank), keep top k), and the list of its mistakes: bge-m3 needs no prefixes, the chunk hash must be unique per document, filtered HNSW.
- Corpora:
  - **repo**: `README.md`, `BENCHMARKS.md`, `product-brief.md`, `backlog.md`, `docs/*.md`, `docs/adr/*.md`, and `.ai/specs/*/spec-lite.md` of specs whose `status` is `done` — about 54 000 words today. Not indexed: `CLAUDE.md`, `AGENTS.md`, `.claude/**`, `.ai/session/**`, `.ai/reports/**`, `vendor/**`, `node_modules/**` (agent instructions and tool logs, not documentation).
  - **laravel**: the `laravel/docs` repository, branch `13.x` (head `625d1df` on 2026-11-02, MIT licence), about 90 markdown files, cloned locally and not committed.
- Tests run on PostgreSQL with pgvector (`phpunit.xml`), network-free (`Embeddings::fake()`).
- Rules from `product-brief.md`:
  - **BR-90** Every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
  - **BR-91** Provider and model are chosen by configuration only.
  - **BR-26** A slice that produces measurements is done only with passing tests, a committed result table, and a short interview story containing at least one measured number (BR-21's load-test columns do not apply to indexing and retrieval).
  - **BR-27** Every shipped slice adds its section to `docs/learning-guide.md`.

## Scope

- `docs:fetch-laravel`: shallow-clones `laravel/docs` at the configured branch into `storage/app/docs/laravel` (gitignored) and records the commit.
- Tables `docs_documents` and `docs_chunks` (vector, generated `tsvector`, HNSW and GIN indexes).
- A markdown chunker following the reference design's rules.
- `docs:index`: reads both corpora, re-chunks only changed documents, removes documents and chunks that disappeared, and queues embedding jobs for chunks without a current vector.
- Retrieval in three modes (`vector`, `text`, `hybrid`) with RRF, via `docs:search` (table output) and `GET /api/docs/search` (JSON).
- Measurements (index stats, token estimate check, identifier and meaning queries per mode) in BENCHMARKS.md; guide section 12.

## Out of scope

- Answering with the chat model, citations in an answer, "I don't know", guards (SL-45).
- Evaluation sets and recall@k across settings (SL-33).
- PDF or HTML loaders; other languages' stemming (the corpora are English).
- Changing the listings search from SL-24.

## Must NOT change

- MNC-1: SL-24 data and behaviour: `listing_documents`, `GET /api/listings/semantic` responses, and the `listings` hash (`6a0f96d2c7139919cc215a520072d3f7`).
- MNC-2: existing endpoints respond as before; `bench/parity.sh compare` prints `identical` on all six servers.
- MNC-3: the suite is network-free and green; `bench/run.sh ping --quick` passes its run conditions.

## Acceptance criteria

- AC-1: WHEN `docs:fetch-laravel` runs THE SYSTEM SHALL have the Laravel docs of the configured branch (`AI_DOCS_LARAVEL_BRANCH`, default `13.x`) under `storage/app/docs/laravel` and print the commit; a second run updates it to the branch head; without `git` or network it fails with a clear message and changes nothing.
- AC-2: WHEN a markdown document is chunked THE SYSTEM SHALL produce chunks that start with `Document: <title> › Section: <heading path>`, keep every fenced code block and every table whole, split long paragraphs only at sentence ends, stay within 500 estimated tokens unless a single code block or table is larger (that chunk is flagged oversize), and repeat 50–80 estimated tokens of whole paragraphs or sentences from the end of the previous chunk of the same section.
- AC-3: WHEN `docs:index` runs THE SYSTEM SHALL store one `docs_documents` row per indexed file (source `repo` or `laravel`, path, title, content hash) and its chunks, and queue embedding jobs for chunks that have no vector for the configured model; WHEN it runs again with no file changed THE SYSTEM SHALL change no rows and queue nothing; WHEN one file changes THE SYSTEM SHALL re-chunk only that file, keep the vectors of chunks whose text is unchanged, and embed only new or changed chunks; WHEN a file is deleted THE SYSTEM SHALL delete its document and chunks.
- AC-4 (BR-90, BR-91): WHEN chunks are embedded THE SYSTEM SHALL use the configured embedding provider and model (as SL-24), check every vector has 1024 numbers, and log each call in `ai_runs` with agent `embeddings:docs` and `inputs`.
- AC-5: WHEN a query is searched in `vector` mode THE SYSTEM SHALL return the k nearest chunks by cosine similarity through the HNSW index; in `text` mode the k best by full-text rank (`websearch_to_tsquery('english', …)`, `ts_rank_cd`); in `hybrid` mode the k best by RRF over the top 20 of each, score Σ 1/(60 + rank). k defaults to 6, 1–20.
- AC-6: WHEN a visitor requests `GET /api/docs/search?q=…&mode=…&k=…` THE SYSTEM SHALL answer 200 `{"data": [...]}` with, per chunk: id, source, path, heading, a snippet (the chunk text without its prefix, first 300 characters), `score`, `vector_rank`, `vector_similarity`, `text_rank`, `text_score` (null when the chunk was not in that list); `q` 1–200 characters after trimming, `mode` one of `hybrid` (default), `vector`, `text`, invalid input → 422 JSON; rate-limited per IP; the query embedding logged as `embeddings:query`.
- AC-7 (BR-26): WHEN the queries in `bench/docs-queries.txt` (5 exact identifiers, 5 questions by meaning) are run in all three modes THE SYSTEM SHALL record per query and mode whether the expected file is in the top 3, and hybrid SHALL have the expected file in the top 3 for at least 4 of the 5 identifier queries and at least 4 of the 5 meaning queries.
- AC-8 (BR-26): THE SYSTEM SHALL record in `bench/results/<date>-docs-index/` and a BENCHMARKS.md section: corpus versions (repo commit, Laravel docs commit), documents and chunks per source, chunk size distribution (min, median, max estimated tokens, oversize count), the token estimate against bge-m3's real count on 20 sampled chunks, indexing and embedding time, table and index sizes, the AC-7 table, and an interview story with at least one number.
- AC-9 (BR-27): THE SYSTEM SHALL have guide section "12. Chunking and hybrid retrieval" explaining why chunks follow structure, the prefix and overlap, why code and tables stay whole, what the token estimate gets wrong, what full text finds that vectors miss and the other way round, how RRF combines two rankings without comparing their scores, and what the measured numbers show, with links to the reference design, the code, the spec, the results, and the PostgreSQL full-text and pgvector docs.

## Boundaries

- Always: keep the suite network-free; read corpora paths, branch, model, batch and k limits from config; keep `listings` and `listing_documents` untouched.
- Ask first: adding a dependency (a markdown parser or tokenizer package); indexing other corpora; changing `.ddev/config.yaml`.
- Never: commit the cloned Laravel docs; index `CLAUDE.md`, `AGENTS.md`, `.claude/**` or tool logs; call a cloud model.

## Technical context

- Chunker in plain PHP (no new dependency): split into sections by ATX headings `#`–`###` (deeper headings stay in the text), keep a heading path ("Title › H2 › H3"); units are paragraphs (blank-line separated), fenced code blocks (``` or ~~~ to the matching fence) and tables (consecutive lines starting with `|`); a paragraph above the limit is split by a sentence-end regex. Strip YAML front matter, HTML anchors (`<a name="…"></a>`) and HTML comments. Title = first `#` heading, else the file name.
- Token estimate = `ceil(mb_strlen / 4)` for English. The check uses Ollama's `prompt_eval_count` from embedding each sampled chunk alone.
- `docs_chunks.tsv` is `GENERATED ALWAYS AS (to_tsvector('english', heading || ' ' || body)) STORED`, where `body` is the chunk text without the prefix (the prefix is for the embedding).
- Unique key for chunks: (`document_id`, `position`); a re-chunked document reuses vectors by `content_hash` (see Clarifications).
- Embedding batches for docs: `AI_DOCS_EMBED_BATCH`, default 32 (chunks are about ten times longer than listing descriptions).

## Clarifications (clarity gate, 2026-11-03)

Resolved under standing approval (self-decided).

**Chunker**
- **Packing.** Greedy: units are added to the current chunk while the estimate (body plus prefix) stays at or below 500; 300 is a target, not a floor. A section whose whole text is under 300 estimated tokens is merged into the next section of the same document only if both fit under 500 together; the merged chunk keeps the first section's heading path. Otherwise small sections stay small chunks.
- **Overlap.** After a chunk closes inside a section, the next chunk of that section starts with the last whole units (paragraphs, list items or sentences, never code or tables) of the previous chunk, taken from the end until 50–80 estimated tokens are covered; if the last unit alone is above 80, its last sentences are used; if no sentence fits, there is no overlap. Overlap counts toward the 500 limit. No overlap after an oversize chunk and none across sections.
- **Prefix and heading.** `docs_chunks.heading` holds the section path without the title (`H2 › H3`), or `Introduction` for text before the first `##`. The prefix is `Document: <title> › Section: <heading>`. The prefix counts toward the 500 limit and `token_estimate`; `tsv` is built from `coalesce(heading, '') || ' ' || body`.
- **Headings** are ATX lines `#` to `###` outside fenced code; the first `#` line is the title, later `#` lines are treated as `##`. Setext headings and trailing `{#anchor}` markers are not special (rare in both corpora).
- **Units.** A list (consecutive lines starting with `-`, `*`, `+` or `1.`) is one unit that may be split between items; fences are opened by three or more backticks or tildes and closed by the same marker of at least that length; an unclosed fence runs to the end of the document, as in CommonMark (changed in the review fix pass: ending it at the next heading line cut code at its first `#` comment); indented code is treated as a paragraph; tables are consecutive lines starting with `|`. Sentence end: `[.!?]` followed by whitespace and an uppercase letter or a digit, not after a single letter or a known abbreviation (`e.g.`, `i.e.`, `etc.`, `vs.`). A sentence or list item above 500 is cut at line breaks, then at the last space before the limit (changed in the review fix pass: link lists with no sentence end became oversize chunks of up to 8 780 tokens), so only code blocks and tables can be oversize.
- **HTML.** Strip `<a name="…"></a>` anchors, HTML comments and `<style>…</style>` blocks; remove other HTML tags but keep their inner text.

**Indexing and hashes**
- **Hashes.** Document hash = sha256 of the raw file bytes. Chunk `content_hash` = sha256 of the chunk text including its prefix (the embedded text), so a title change re-embeds the chunks of that file, which is correct because the embedded text changed.
- **Unique key** (`document_id`, `position`); `content_hash` is indexed, not unique. On re-chunk, a new chunk reuses the vector of an old chunk of the same document with the same `content_hash` and model (identical repeated chunks each get their own row).
- **"No rows change"** means no insert, update or delete on documents and chunks when every document hash is unchanged; `indexed_at` is only set when a document is (re)chunked. Positions of later chunks may change when a file changes.
- **Pending jobs.** `docs:index` refuses to queue while embedding jobs are on the `embeddings` queue (same guard as SL-24) and says so; a job skips chunks that no longer exist or already have a current vector. The manual test runs the worker before the second `docs:index`.
- **Model change.** Chunks whose `embedding_model` differs from the configured model are re-queued; their old vectors are kept until replaced, and search only uses chunks whose `embedding_model` equals the configured model.
- **Wrong length** fails the job at once without retry, stores nothing for the batch (SL-24 rule) and is logged `failed`.
- **Logging.** One `ai_runs` row per model call: agent `embeddings:docs`, `prompt_version` null, `tokens_in` from usage, `tokens_out` null, `inputs` = number of chunks.
- **Corpus paths** are stored relative to the corpus root (repo root, or the Laravel docs folder). `docs/*.md` is not recursive; `docs/adr/*.md` is listed separately. Laravel's `readme.md`, `license.md` and `documentation.md` (the table of contents) are excluded. If the Laravel folder is missing, `docs:index` indexes the repo only and prints a warning naming `docs:fetch-laravel`; its documents already in the table stay until the folder exists again (`--source=laravel` then fails with a message).

**Fetch**
- **Update** clones into a temporary folder next to the target, then swaps it in (rename), so a failure leaves the old copy. The commit (full hash) and branch are written to `storage/app/docs/laravel.json` and printed. Messages for: `git` not found, clone failed (network or unknown branch, with git's error line). A changed branch is just a new clone.

**Search and API**
- **Fields.** `vector_rank` and `text_rank` are 1-based positions in each top-20 list (null when absent); `vector_similarity` = 1 − cosine distance (null when absent); `text_score` = the `ts_rank_cd` value (null when absent); `score` = RRF in hybrid, the similarity in vector mode, the `ts_rank_cd` value in text mode. Ties broken by chunk id ascending.
- **Edge cases.** No full-text match or an empty tsquery → hybrid uses the vector list alone (and the reverse); both empty → `data: []`. List depth `AI_DOCS_RRF_DEPTH`, default 20; RRF constant 60.
- **Validation.** `k` outside 1–20 → 422 (CLI: error, exit 1); Ollama down or wrong-length query vector → 503 `{"message": "Docs search is unavailable."}` in vector and hybrid modes (text mode needs no model); empty index → `data: []`; response has no `meta` block; snippet = first 300 characters (`mb_substr`) of the body, ending with `…` when cut.
- **Rate limiter** `docs-search`, own setting `AI_DOCS_SEARCH_PER_MINUTE`, default 60.
- **No source filter** in this slice (one corpus filter can come with SL-45 if answers need it).
- **EXPLAIN test** runs the repository method inside its own transaction, which sets `hnsw.ef_search` and `SET LOCAL enable_seqscan = off` like SL-24; the test listens for the query and runs `EXPLAIN` on it.
- **Identifiers in text search.** The `english` parser splits `AI_EF_SEARCH` into `ai`, `ef`, `search` and keeps `pg_stat_database`-like words in parts; a match on those parts is what full text does, and the bench records whether the expected file is found, not how. Unit tests use `flushpackets` (one token).
- **`docs:search` columns:** rank, score, source, path, heading, snippet (first 80 characters).

**Benchmarks**
- **Queries, fixed now (before any results)** in `bench/docs-queries.txt`, tab-separated `type<TAB>query<TAB>expected` where expected is one or more `source:path` joined by `|`:
  - identifier — `flushpackets` — `repo:docs/learning-guide.md`
  - identifier — `AI_EF_SEARCH` — `repo:BENCHMARKS.md|repo:docs/learning-guide.md|repo:.ai/specs/spec-20261102-2115-listing-embeddings/spec-lite.md`
  - identifier — `draft_num_predict` — `repo:docs/learning-guide.md|repo:.ai/specs/spec-20261102-1812-local-model-wiring/spec-lite.md`
  - identifier — `pg_stat_database` — `repo:docs/learning-guide.md|repo:BENCHMARKS.md`
  - identifier — `withoutOverlapping` — `laravel:scheduling.md`
  - meaning — `why does apache hold back a streamed response until the end` — `repo:docs/learning-guide.md|repo:.ai/specs/spec-20261102-1552-streamed-car-description/spec-lite.md`
  - meaning — `how do I run a command every five minutes` — `laravel:scheduling.md`
  - meaning — `how can a queued job wait before it is tried again` — `laravel:queues.md`
  - meaning — `why is worker mode so much faster for the database search` — `repo:BENCHMARKS.md|repo:docs/learning-guide.md|repo:.ai/specs/spec-20261101-2126-classic-vs-worker/spec-lite.md`
  - meaning — `how does the container reach the model server on the Mac` — `repo:docs/learning-guide.md|repo:docs/adr/0001-local-model-server-models-php-client.md|repo:.ai/specs/spec-20261102-1812-local-model-wiring/spec-lite.md`
- **Top 3** = the top 3 chunks (k = 3); a query passes when any of them belongs to an expected file.
- **Pass rule.** AC-7's thresholds are the target. If hybrid misses them, the slice still ships with the honest table and a recorded decision explaining the miss; the queries are not changed after the first run.
- **Token check.** 20 chunks sampled with a fixed seed (10 per source); each embedded alone through Ollama's `/api/embed` over HTTP (the SDK reports usage per call, so one chunk per call gives its count); report mean and max of estimate ÷ real and the mean absolute error; no pass threshold. These calls are logged like any embedding call (agent `embeddings:docs`).
- **Times and sizes.** Indexing time = `docs:index` wall clock (chunking and storing); embedding time = first to last `embeddings:docs` call of the run, from `ai_runs` (SL-24 rule); sizes with `pg_relation_size` for the table and each index and `pg_total_relation_size` for the total. Files: `bench/results/<YYYY-MM-DD>-docs-index/index.json` and `search-bench.json`.

**Environment and docs**
- Tests use PostgreSQL with pgvector (`phpunit.xml`); the project CLAUDE.md's SQLite/MariaDB note is out of date.
- Guide sections 1–11 exist; the status line (`**Status (…):**` near the top) and the `## Planned sections` list at the end are updated as in earlier slices.
- Parity: `bench/parity.sh` captures only its fixed endpoints, so the baselines stay byte-identical; the new endpoint is not added to parity.

## Decisions

1. Split the original SL-32 (L, 10–12 tasks) into this slice and SL-45 (answering) (self-decided, standing approval; recorded in the backlog).
2. Plain-PHP chunker instead of a markdown parser package: no new dependency, and the rules are few and visible (self-decided, standing approval).
3. Keep all three retrieval modes in the product, not just in the bench, so SL-33 and SL-45 can compare them by configuration (self-decided, standing approval).
4. English text search configuration (`english` stemming) because both corpora are English; Ukrainian is not in these corpora (self-decided, standing approval).
5. Re-captured the six `bench/parity/*.txt` baselines with this slice applied (self-decided, standing approval). The committed ones differed on `/listings/1` only, because the Vite assets were rebuilt on 2026-11-03. On the same `public/build`, captures at the pre-slice code (the five shared files reverted to `856a86f`) and with the slice are identical for all six servers, so the slice is not the cause.

## Open questions

- [x] Assumption: BR-26, BR-27, BR-90 and BR-91 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, standing approval 2026-11-01).
- [x] Clarity gate (34 items) — all answered in Clarifications (self-decided, standing approval). The benchmark queries are fixed in the Clarifications before any run.

## Dependencies

- SL-22, SL-24 (shipped). Network access once for `docs:fetch-laravel`.
