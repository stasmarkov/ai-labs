# Ship report: spec-20261102-2115-listing-embeddings

## Summary

SL-24 adds deterministic listing descriptions (`listing_documents`), bge-m3 embeddings through a queued `listings:embed` command, `GET /api/listings/semantic` (HNSW cosine search), benchmarks, and learning guide section 11. The main user journey works live on DDEV: 50 000 listings are described and embedded, and "family car with a big boot" returns 10 of 10 estates. All gates pass (417 tests). The build is still partial. Gate task 7 and converge round 2 gap tasks 12, 13 and 14 are open. Review confirmed two major issues (the embedding HTTP timeout, which two reviewers reported separately) and nine minor ones. All work is uncommitted on `master`.

## Stack & repos

| Repo | Role | Stack | Tracked | Notes |
|---|---|---|---|---|
| `.` | backend | laravel | yes | **WIP: uncommitted.** Many modified and untracked files on `master`, nothing committed for SL-24 beyond the spec |

- Repo warnings: none
- Stack overlay: discovery installed-plugins (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `plugin:sdd-kit-laravel@sdd-kit`. Discovery error: "user stacks unreadable".

## Spec

- id: `spec-20261102-2115-listing-embeddings`
- folder: `.ai/specs/spec-20261102-2115-listing-embeddings`

## Investigation highlights

The investigation step recorded no key files, risks or already-shipped pieces (all empty). Context from the build:
- Key files: `app/Services/ListingDocumentGenerator.php`, `app/Services/ListingEmbeddingService.php`, `app/Jobs/EmbedListingBatchJob.php`, `app/Ai/RecordEmbeddingsRun.php`, `app/Services/SemanticSearchService.php`, `app/Repositories/EloquentSemanticSearchRepository.php`, `app/Repositories/EloquentSemanticBenchRepository.php`, `routes/catalog.php`, `config/ai.php`, `docs/learning-guide.md` §11, `BENCHMARKS.md`.
- Already shipped and reused: `ai_runs` logging (`AiRunRecorder`), the describe rate limiter, SL-22 local Ollama wiring, `JsonErrorRenderer`.
- Risks: a seed must leave the listings hash unchanged (MNC-1). Existing endpoints must stay identical across six servers (MNC-2). The suite must not touch the network (MNC-3).

## Tasks completed

10 of 14 done: 1–6 and converge tasks 8–11.

Incomplete:
- **7**: Gates. Pint, PHPStan and the suite pass. `bench/run.sh ping --quick` after commit has not run because the work is not committed.
- **12, 13, 14**: converge round 2 gaps (below).

## Converge

- Rounds run: 2
- Round 1 gaps appended and built: 8 (recall@10 now compares HNSW with an exact scan, plus an EXPLAIN guard), 9 (Ollama version and sub-second timing in summaries), 10 (`tokens_in` null when unreported, `tokens_out` asserted null), 11 (seeder writes descriptions, fresh seed keeps the hash)
- Round 2 gaps appended, **left unbuilt**:
  - 12. Re-run the full 50 000 embedding at the best batch size and re-time the HNSW build
  - 13. Log a wrong-length query embedding as failed
  - 14. Make guide section 11 say what the AC-7 test proves, and what template text limits

## Quality gates

| Repo | Gate | Status | Details |
|---|---|---|---|
| `.` | Pint | pass | 167 files (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | PHPUnit | pass | 417 passed, 4095 assertions (via ddev exec) |
| `.` | Debug scan | pass | No `dd(`/`dump(`/`ray(` in app/, routes/, database/ |
| `.` | `bench/run.sh ping --quick` | skip | Needs a committed tree (task 7) |
| `.` | `bench/parity.sh compare` (6 servers) | skip | Not run in acceptance (MNC-2) |

## Visual tests

Not applicable (no UI; API and CLI only).

## Acceptance

**Original scenario not resolved end-to-end.** The flow is descriptions, then embeddings, then `GET /api/listings/semantic`, then benchmarks and guide §11. The main hops work live on DDEV (nginx-fpm, Postgres 16 + pgvector, Ollama):
- 50 000 descriptions of 28–39 words. 50 000 vectors of 1024 numbers.
- The endpoint returns 10 ranked results with the documented fields. A missing, empty or 201-character `q` gets a 422 in the app's JSON error shape. The 429 limit works.
- "family car with a big boot" returns 10 of 10 estates (AC-7).
- The listings hash is unchanged: `6a0f96d2c7139919cc215a520072d3f7` (MNC-1). The jobs table is empty and no worker is left running.

Broken or unverified hops:
1. **Wrong-length query vector (AC-5/AC-6, Clarifications).** The request gets a 503, but the `ai_runs` row says `completed`, not `failed`. `RecordEmbeddingsRun::onGenerated` writes the row before `SemanticSearchService::embedQuery` checks the length (task 13).
2. **AC-8 measurements.** The only full run is `embed-64-all.json`: batch 64, whole seconds, no `ollama_version`. No `embed-256-all.json` exists, although 256 is now the default. `search-bench.json` has `index_build_seconds: null`. The 41.1 s build time quoted in BENCHMARKS.md has no results file (task 12).
3. **AC-9 guide §11.** It is missing the fake-vector note (the AC-7 test proves ordering only) and the template-text limit. It states the cause of the 0.37 recall as fact, while BENCHMARKS.md says that cause was not tested (task 14).
4. **AC-2 text.** spec-lite.md still says "default 64". `config/ai.php` defaults to 256.
5. **MNC-2.** Six-server parity was not run. **MNC-3.** The bench ping is waiting on a commit.

E2E regression test: none written. The backend-only stack declares no browser e2e (E2E: null). AC coverage relies on the PHPUnit feature tests listed in the Traceability table of tasks.md.

## Confirmed review findings

### Major

- `app/Services/ListingEmbeddingService.php:120`: Embedding calls (and `SemanticSearchService::embedQuery`) never call `->timeout()`, so they use the SDK's fixed 30 s HTTP timeout. That ignores `AI_TIMEOUT` and the job's 300 s / `retry_after` 360 budget. Batch 256 on a cold model, or a large `--batch`, fails all 3 tries. Fix: set `->timeout()` from config (for example `ai.embeddings.timeout`, kept below 300 s).
- `app/Console/Commands/EmbedListingsCommand.php:79-88` (same root cause, second reviewer): `--batch` has no upper bound. Batch 2048 or more always times out, and 21 845 or more goes over Postgres's 65 535 bind limit in `storeEmbeddings`. Fix: cap `--batch` (for example 1024).

### Minor

- `app/Services/ListingEmbeddingService.php:77`: `$startedAt` is taken before `--fresh` clears vectors and before the worker starts, so the reported embeddings/s is too low. Fix: time from the first job start (the earliest `ai_runs.created_at` of the run) or after the clear.
- `app/Repositories/EloquentEmbeddingStatsRepository.php:18`: The queue guard and `--wait` read the `jobs` table on the default connection whatever the queue driver is. Fix: use `Queue::connection()->size('embeddings')`.
- `app/Repositories/EloquentListingDocumentRepository.php:137`: Run-window timestamps are bound as `Y-m-d H:i:s` with no microseconds or offset. Fix: bind `$since->format('Y-m-d H:i:s.uP')`, as `storeEmbeddings()` already does.
- `app/Repositories/EloquentSemanticBenchRepository.php:27`: `SET LOCAL enable_indexscan` leaks past `exactIds()` inside an outer transaction (tests), so the recall test compares exact with exact. Fix: run `RESET enable_indexscan` (or `SET ... = on`) right after the query.
- `app/Repositories/EloquentSemanticBenchRepository.php:52`: `rebuildIndex()` drops the HNSW index before creating it, outside a transaction. A failed build leaves no index and breaks `documentSizes()`. Fix: build under a temporary name and swap, or wrap drop and create in one transaction.
- `config/ai.php:40`: A negative `AI_EMBED_BATCH` is accepted (`array_chunk` throws `ValueError`), and `--timeout` is not validated. Fix: apply the positive-integer check used for `--batch` and `--limit`.
- `app/Providers/AiServiceProvider.php:60`: Semantic search reuses the describe budget, keys on the full IP (an IPv6 /64 can rotate addresses) and has no global cap on the shared Ollama. Fix: add `ai.semantic_search_per_minute`, key IPv6 clients by /64, and add a global `Limit` such as `->by('semantic-search:global')`.
- `app/Jobs/EmbedListingBatchJob.php:18`: `$tries = 3` with no backoff. Fix: add `public array $backoff = [10, 30];`.
- `database/migrations/2026_11_02_210000_create_listing_documents_table.php:28`: The migration reads `ListingDocument::EMBEDDING_INDEX`. Fix: use the literal index name.

## Documentation updated

The docs step recorded no output (Docs: null). Changed in the build: `docs/learning-guide.md` §11 (incomplete against Clarifications, see task 14), the `BENCHMARKS.md` embeddings section (full run still labelled batch 64), `.env.example`, and the spec files (`spec-lite.md`, `tasks.md`, `manual-test.md`). The AC-2 default in spec-lite.md is stale.

## Manual test plan

Written to `.ai/specs/spec-20261102-2115-listing-embeddings/manual-test.md`. Before starting: DDEV on nginx-fpm, Ollama running with bge-m3 pulled, the listings hash noted, and the tree committed. Then follow steps 1–8 in order (`ddev artisan listings:describe-all`, `listings:embed --fresh --batch=256` with `queue:work --queue=embeddings --stop-when-empty`, curl the semantic endpoint, check bench results, then parity, tests and the bench ping). Steps 4, 7 and 8 are expected to fail until tasks 12, 13 and 14 are done.

## Recommendation

**FIX-FIRST.** Reasons:
- The build is partial: task 7 is open.
- Converge gaps 12, 13 and 14 are unbuilt.
- The acceptance trace is unresolved (the wrong-length logging, AC-8 and AC-9 hops).
- Two major review findings are open.
- The work is uncommitted, so the bench ping and six-server parity have not run.

Next: In `app/Services/ListingEmbeddingService.php:120` and `SemanticSearchService::embedQuery`, set `->timeout()` from a config value below the job's 300 s and cap `--batch`. Then do task 13 (mark wrong-length query runs `failed`), task 14 (guide §11), task 12 (re-run the full batch-256 embed and the index build) and task 7 (commit, then gates and the bench ping).
