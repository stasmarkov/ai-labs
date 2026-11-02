# Ship report: spec-20261102-2352-docs-index-retrieval (SL-32)

## Summary

SL-32 adds a docs index with hybrid retrieval. `docs:fetch-laravel` clones the Laravel 13.x docs. `docs:index` and its queued embedding jobs chunk and embed the repo docs and the Laravel docs into PostgreSQL/pgvector. `docs:search` and `GET /api/docs/search` serve text, vector and hybrid (RRF) results. A live run on DDEV nginx-fpm with Ollama bge-m3 worked from start to finish, and the recorded bench meets the AC-7 thresholds (hybrid 5/5 identifier, 5/5 meaning). All gates are green.

The slice is not ready to ship:

- 9 of 13 tasks are built. Task 7 (gates and commit) is incomplete.
- Three converge gaps are still unbuilt (11, 12, 13).
- AC-8, AC-9 and MNC-3 are not resolved.
- The whole slice is still uncommitted working-tree changes.

## Stack & repos

| Repo | Role | Stack | Tracked |
|------|------|-------|---------|
| `.` | backend | laravel | yes |

No repo warnings. The repo is tracked, but every file in the slice is uncommitted (many are untracked). Until it is committed, nothing here is a verified deploy target.

Stack overlay: discovery installed-plugins (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `plugin:sdd-kit-laravel@sdd-kit`, `plugin:sdd-kit-drupal@sdd-kit`. Discovery error: `trustStore unreadable`.

## Spec

- Id: `spec-20261102-2352-docs-index-retrieval`
- Folder: `.ai/specs/spec-20261102-2352-docs-index-retrieval`
- Status in spec-lite.md: `in-progress`

## Investigation highlights

The investigation step recorded no key files, risks or already-shipped pieces. From the build and the review:

- Key files:
  - `app/Services/MarkdownChunker.php`
  - `app/Services/DocsCorpus.php`
  - `app/Services/DocsEmbeddingService.php`
  - `app/Services/DocsSearchService.php`
  - `app/Repositories/EloquentDocsIndexRepository.php`
  - `app/Repositories/EloquentDocsSearchRepository.php`
  - `app/Repositories/EloquentDocsBenchRepository.php`
  - `app/Console/Commands/{FetchLaravelDocs,IndexDocs,SearchDocs,SearchDocsBench}Command.php`
  - `routes/catalog.php`
- Reused from SL-24: the Ollama and pgvector stack, `SemanticSearchService::embedQuery`, the `ai_runs` logging and the `ApiResponse` envelope. The embedding batch code and the batch job were copied rather than reused (see findings).
- Risks seen in the build:
  - Oversize chunks that are not code or tables.
  - An embedding window that is not bounded to the measured run.
  - Parity baselines that were re-captured with the slice.

## Tasks completed

9 of 13. The build lists task 7 as incomplete. tasks.md has five unchecked boxes:

| Task | State |
|------|-------|
| 1. Tables and Laravel docs fetch | Built and verified (DocsFetchLaravelTest green, commit 625d1df recorded), but not ticked. Gap 13 covers this. |
| 7. Gates | Incomplete. 7.1 (bench ping run conditions) can only pass after the commit. |
| 11, 12, 13 | Converge gap tasks, not built. |

Done: 2, 3, 4, 5, 6, 8, 9, 10.

## Converge

Two rounds ran and appended six gap tasks.

- Round 1 appended tasks 8 to 10, and all three were built:
  - 8: prove MNC-2 parity against the committed baselines.
  - 9: limit the embedding window to the measured run.
  - 10: test the "git not found" path of `docs:fetch-laravel`.
- Round 2 appended tasks 11 to 13, and none were built:
  - 11: correct what BENCHMARKS.md and guide section 12 say about oversize chunks.
  - 12: count a real single-chunk embedding batch in the embedding window.
  - 13: tick task 1 and remove the stray `tasks.md-E`.

## Quality gates

| Repo | Gate | Result | Details |
|------|------|--------|---------|
| `.` | Pint | pass | 220 files (ddev exec) |
| `.` | PHPStan | pass | No errors (ddev exec) |
| `.` | PHPUnit | pass | 487 passed, 4 549 assertions (ddev exec) |
| `.` | Debug scan | pass | No `dd(`, `dump(` or `ray(` in app/, routes/, database/ |
| `.` | `bench/run.sh ping --quick` (MNC-3) | fail (precondition) | Refused: "git working tree has changes outside bench/results/ and .ai/session/". It can only pass after the commit. |

There are no contract-sync gates because this is a single repo.

## Visual tests

None run. This slice is API and CLI only, with no UI.

## Acceptance

**Not resolved.** The working journey reached its end state on DDEV nginx-fpm + PostgreSQL/pgvector + Ollama bge-m3: fetch, index, embed, re-index (a true no-op), then search over CLI and HTTP in all three modes. The open problems:

| Hop / criterion | Result | Note |
|-----------------|--------|------|
| Route, HTTP, validation, rate limit, query logging, defaults, CLI | ok | All AC-6 fields, the 422 cases, `x-ratelimit-limit: 60` and the `embeddings:query` row were verified live |
| AC-1 fetch | ok | Commit 625d1df matches `git ls-remote`. A bad branch and a missing git both fail cleanly and change nothing |
| AC-2 chunker | ok (caveat) | 2 703 chunks with 0 prefix, limit or fence violations. 15 oversize chunks: 7 tables, 8 link-list paragraphs, 1 base64 image |
| AC-3, AC-4, AC-5, AC-7 | ok | Incremental re-index, provider logging, HNSW plan and RRF arithmetic all hold. Hybrid scored 5/5 + 5/5 |
| **AC-8** results and BENCHMARKS | **broken** | BENCHMARKS.md:307 and :365 describe the oversize chunks wrongly. The `inputs > 1` window filter drops 1-chunk batches (EloquentDocsBenchRepository.php:56) |
| **AC-9** guide section 12 | **broken** | learning-guide.md:370 gives the same wrong oversize breakdown |
| MNC-1, MNC-2 | ok | Listings hash `6a0f96d2c7139919cc215a520072d3f7` unchanged. Parity is identical against the nginx-fpm baselines. The other five servers were not re-captured live |
| **MNC-3** | **broken** | Suite green and network-free, but the bench ping refuses until the slice is committed |
| Spec housekeeping | **broken** | Tasks 1, 7, 11, 12, 13 unchecked. Stray `tasks.md-E` present |

No broken links were reported.

E2E regression test: none was written. The stack has no e2e configuration and there is no browser journey, so no AC ids are traced to an e2e test.

Side effects on the dev database from the acceptance run:

- BENCHMARKS.md and learning-guide.md were re-chunked.
- 13 chunks were embedded, adding one `embeddings:docs` row.
- About 10 `embeddings:query` rows were logged.
- docs:fetch-laravel re-cloned the same commit.
- `.env` was restored after the ping attempt.

## Confirmed review findings

### Major

- `app/Services/MarkdownChunker.php:314`: paragraphs with no sentence ends (link lists, base64 data URIs) become oversize chunks of up to 8 780 tokens, which is above bge-m3's 8 192 context. Fix: split oversize paragraphs at newlines and then at a hard character limit, and strip `data:` URIs.
- `BENCHMARKS.md:307` (also :328, :365 and `docs/learning-guide.md:370`): these lines say the oversize chunks are code or tables and that "nothing is cut", which the data contradicts. Fix: rewrite them from the live breakdown (7 tables, 8 link lists, 1 base64 image) and say that the 8 780-token chunk is truncated.
- `app/Services/DocsEmbeddingService.php:87`: embedBatch, countTokens and EmbedDocsBatchJob copy the SL-24 code, although spec-lite.md:26 says to reuse it. Fix: pull out a shared embedding gateway and a shared batch job.

### Minor

- `app/Repositories/EloquentDocsBenchRepository.php:56`: `inputs > 1` drops real 1-chunk batches, and the window can count calls from earlier runs. Fix: use a run marker, or give the token-check calls their own agent name.
- `app/Services/MarkdownChunker.php:135`: an unclosed fence ends at the first `# comment` line inside the code. Fix: make the heading check fence-aware, or run an unclosed fence to the end of its section.
- `app/Services/MarkdownChunker.php:204`: the HTML strip also deletes autolinks and generic types such as `Collection<int, User>`. Fix: match only known HTML tag names and leave `<scheme:...>` and `<x@y>` alone.
- `app/Console/Commands/FetchLaravelDocsCommand.php:46`: the result of `moveDirectory` is not checked, so the command reports success after a failed swap. Fix: check the result, keep the old copy until the move succeeds, and return FAILURE on error.
- `app/Console/Commands/SearchDocsBenchCommand.php:37`: `FILE_SKIP_EMPTY_LINES` makes the error line numbers wrong. Fix: read all lines and skip blank ones in the loop.
- `app/Services/DocsSearchService.php:46`: hybrid can return fewer than k results when rrf_depth is below k. Fix: use `max($depth, $k)` as the depth.
- `app/Services/DocsCorpus.php:75`: the unauthenticated `/api/docs/search` serves internal spec files, including absolute local paths and the developer's name. Fix: limit the corpus to user-facing docs, scrub paths, or gate the endpoint.
- `app/Http/Requests/DocsSearchRequest.php:24`: a NUL byte or invalid UTF-8 in `q` probably causes a 500 in text mode (traced in code, not reproduced). Fix: add `'regex:/^[^\x00]*$/u'`.
- `app/Repositories/EloquentDocsIndexRepository.php:44`: re-chunking deletes and re-inserts every chunk and moves the vectors through PHP. Fix: match on content_hash in SQL and update unchanged rows in place, or copy vectors with INSERT ... SELECT.
- `app/Repositories/EloquentDocsSearchRepository.php:35`: queries fetch the full chunk bodies (up to about 35 KB × 40 rows), but only 300 characters are used. Fix: select `left(c.body, 301)` for the API and CLI paths.
- `app/Repositories/EloquentDocsSearchRepository.php:26`: the HNSW post-filter on `embedding_model` without iterative scan can return fewer than k rows. Fix: `SET LOCAL hnsw.iterative_scan = relaxed_order`, or a partial index per model.
- `app/Services/DocsSearchBenchService.php:39`: the bench embeds each query twice (vector, then hybrid). Fix: embed once and reuse the vector list.
- `app/Console/Commands/SearchDocsCommand.php:25`: the query and k limits are defined in two places with different sources. Fix: read them from one place (config or the service) in both the CLI and the request.
- `app/Console/Commands/SearchDocsCommand.php:59`: the outer `mb_substr` always cuts off the `…`. Fix: use `snippet(80)` directly.
- `.env.example:90`: `AI_DOCS_LARAVEL_REPOSITORY` is missing. Fix: add it with the default repository URL.

## Documentation updated

The docs step recorded nothing. The slice itself changes `BENCHMARKS.md` (the docs index section), `docs/learning-guide.md` (section 12 and the status line) and `bench/results/2026-11-02-docs-index/`. The oversize passages in BENCHMARKS.md and the guide are still wrong (gap 11).

## Manual test plan

Written to `/Users/stasmarkov/work/interview/.ai/specs/spec-20261102-2352-docs-index-retrieval/manual-test.md`. It has 15 steps covering fetch, failures, index, chunk SQL checks, incremental changes, the HTTP modes, validation, the CLI, the bench, the artefacts, the guide, SL-24 data, parity, gates and housekeeping. Rollback: `ddev artisan migrate:rollback --step=2` and `rm -rf storage/app/docs`.

How to run: with ddev up (nginx-fpm), Ollama serving bge-m3 and network access to github.com, follow the steps in order, starting with `ddev artisan docs:fetch-laravel`.

## Recommendation

**FIX-FIRST.** This cannot ship yet:

- The build is partial: 9 of 13 tasks, with task 7 incomplete.
- Three converge gaps are unbuilt (11, 12, 13).
- The acceptance trace is unresolved (AC-8, AC-9, MNC-3).
- Three findings are major.
- The slice is uncommitted.

The gates themselves are green.

Next: fix gap 12 and the window finding. Drop `AND inputs > 1` in `app/Repositories/EloquentDocsBenchRepository.php:56`, give the token-check calls their own agent or a run marker, and update the AC-8 window test to expect the 1-input batch. Then do gap 11 (the oversize wording in BENCHMARKS.md and guide section 12) and gap 13, and commit so `bench/run.sh ping --quick` can close task 7.
