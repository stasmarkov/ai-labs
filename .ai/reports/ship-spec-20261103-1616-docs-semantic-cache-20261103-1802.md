# Ship report: spec-20261103-1616-docs-semantic-cache (SL-40)

## Summary

This slice puts a whole-answer cache in front of `POST /api/docs/ask`. There are two lookups, exact and semantic, plus the `docs:cache` (stats/prune) and `docs:cache-calibrate` commands and the cache UI in the lab `/ai/chat`. The live acceptance trace resolved end to end, and all gates are green (809 tests passed).

The build is not finished. 10 of 15 tasks are done, tasks 6 and 9 are incomplete, and 3 converge gaps (13-15) were never built. Review also confirmed 7 major findings, two of them security issues: the cache exposes other users' questions, and any client can poison or overwrite a cached answer. All of the work is uncommitted on `master`.

**Recommendation: FIX-FIRST.**

## Stack & repos

| Repo | Role | Stack | Tracked | Notes |
|---|---|---|---|---|
| `.` | backend | laravel | yes | The repo is tracked, but the whole feature is uncommitted WIP on branch `master` (about 30 modified files plus the new ones). The default branch is `main`. |

- Repo warnings: none.
- Stack overlay: discovery installed-plugins (plugin); added: none; ignored: 0; rejected: 0; untrusted: none.
  - Present: `plugin:sdd-kit-laravel@sdd-kit`, `plugin:sdd-kit-drupal@sdd-kit`. One discovery error: the `sdd-kit-nextjs` plugin manifest could not be read (it was not returned in full).

## Spec

- Id: `spec-20261103-1616-docs-semantic-cache`
- Folder: `.ai/specs/spec-20261103-1616-docs-semantic-cache` (`spec-lite.md`, `tasks.md`, `manual-test.md`)

## Investigation highlights

The investigation step recorded no key files, risks or already-shipped pieces (all three lists came back empty). The context below comes from the spec and the build:

- Key files: `app/Services/DocsAnswerService.php` (plan, serveHit), `app/Services/DocsAnswerCache.php`, `app/Repositories/EloquentDocsAnswerCacheRepository.php`, `app/Services/DocsIndexService.php` / `DocsIndexVersion`, `app/Services/DocsCacheCalibrator.php`, `app/Repositories/EloquentAiRunRepository.php`, `app/Console/Commands/DocsCache*.php`, `resources/js/ai/Pages/Lab/Chat.vue`.
- The slice builds on code that already shipped: SL-45 (docs answering, SSE contract, guards) and SL-33 (RAG eval harness, threshold calibration).
- Risks seen during the build: the hit rate's denominator was inflated by eval rows (fixed in task 10), the index version could drift during re-indexing (see findings), and the cache is global with no authentication (see findings).

## Tasks completed

10 of 15.

| # | Task | State |
|---|---|---|
| 1 | Schema, entry model, index version | done |
| 2 | `DocsAnswerCache` service | done |
| 3 | Pipeline, hit stream, bypass | done |
| 4 | Calibration command and pair set | done |
| 5 | Stats and prune command | done |
| 6 | Lab chat cached state and checkbox | **incomplete**: 6.1 is done; 6.2 (typecheck/build plus a browser check at 1440 and 390) is not ticked |
| 7 | Committed calibration and hit/miss run | done |
| 8 | BENCHMARKS.md, guide section 17, code map | done |
| 9 | Parity, fingerprint and bench | **incomplete**: suite, Pint, PHPStan, typecheck, build, fingerprint and 6/6 parity are recorded. `bench/run.sh ping --quick` refused to run on a dirty tree and must be rerun after the commit |
| 10 | Hit rate over endpoint rows only (`origin`) | done (gap, round 1) |
| 11 | NFR-1 on a median of 10 hits per path | done (gap, round 1) |
| 12 | MNC-1 guard for miss and bypass streams | done (gap, round 1) |
| 13 | Recommit hit/miss `--stats` after the origin fix | **not built** (gap, round 2) |
| 14 | Section 16 maps the two paraphrase data files | **not built** (gap, round 2) |
| 15 | Test that `saved_latency_ms` is floored at 0 | **not built** (gap, round 2) |

## Converge

- Rounds run: 2.
- Round 1 added tasks 10, 11 and 12, and all three were built:
  - origin filter for the hit rate
  - NFR-1 sample size
  - MNC-1 guard for enabled-cache miss and bypass
- Round 2 added tasks 13, 14 and 15. **None of them were built:**
  - 13: the committed `hit-miss.json` `stats` (2.6% = 11/(11+414)) was captured before the origin fix. BENCHMARKS.md and guide section 17 explain that bug as if it were design, and `BenchmarksDocTest` pins the literal `2.6%`.
  - 14: `bench/rag-eval/cache-hitmiss-paraphrases.v1.jsonl` and `cache-latency-paraphrases.v1.jsonl` are not named in guide section 16 or in its test list.
  - 15: no test exercises the `max(0, ...)` floor on `saved_latency_ms`.

## Quality gates

| Repo | Gate | Status | Details |
|---|---|---|---|
| `.` | Pint | pass | 349 files pass (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | PHPUnit | pass | 809 passed, 7197 assertions (via ddev exec) |
| `.` | Debug scan | pass | No `dd(` / `dump(` / `ray(` in app/, routes/, database/ |

Typecheck and build were not run as pipeline gates. Task 9 records them as clean.

## Visual tests

The pipeline ran no visual stage (`Visual: null`). The acceptance trace did a browser pass on `/ai/chat` and `/ai` with Playwright: the Cached exact and semantic marks rendered, the checkbox is on by default, there were 0 console errors and no requests failed. The 1440 and 390 viewport checks that task 6.2 asks for were not recorded.

## Acceptance

- **Resolved: yes.** The original scenario (exact and semantic cache in front of `POST /api/docs/ask`, the two commands, the lab UI) worked end to end against the live DDEV stack: nginx-fpm, pgsql with pgvector, Redis, Ollama qwen3.5:9b and bge-m3, and the browser lab.
- Broken hops: none. Hops 1-6 passed: route, then plan, then miss stream with store, then hit stream, then lab chat, then overview. AC-1 to AC-11, MNC-1 to MNC-4 and NFR-1 to NFR-3 were all marked ok.
- Points noted during the trace that do not block:
  1. An exact hit serializes `similarity` as `1`, not `1.0`.
  2. The stored source latency runs from request start to citations (12197 ms against the source row's 10744 ms), so `saved_latency_ms` reads about 1.4 s high compared with Clarification Q12.
  3. The committed `--stats` 2.6% predates origin stamping. This is the same issue as gap 13.
  4. MNC-4 parity was re-captured live on nginx-fpm only. Task 9 records 6/6 from the build.
  5. The work is uncommitted, so this is not a verified deploy target.
- E2E regression test: **none written** (`E2E: null`). No AC ids are traced to an e2e test, and none could run.

## Confirmed review findings

### Major

- **Security.** `app/Services/DocsAnswerService.php:269`: `cache_hit.cached_question` sends another anonymous user's question word for word, and questions are now kept for 168 h. Fix: stop returning the original question to other callers (send kind and similarity only), or scope or redact it, and state the retention in the spec.
- **Security.** `app/Repositories/EloquentDocsAnswerCacheRepository.php:45`: the cache is global and any client's answer gets stored, so it can be poisoned. On top of that, `cache:false` lets a client keep regenerating and overwrite an existing entry, which resets `created_at`. Fix: never store from bypass requests, or make writes insert-only (`ON CONFLICT DO NOTHING`), and consider limiting writes to trusted traffic.
- **Correctness.** `app/Services/DocsAnswerCache.php:114`: `store()` reads the index version after the stream ends. If `docs:index` runs during the stream, an answer built from the old docs is cached under the new version. Fix: compute `DocsCacheVersions` once in `plan()` and carry it in `DocsCacheCandidate`.
- **Correctness.** `BENCHMARKS.md:495` (also lines 519 and 524, and `docs/learning-guide.md:763`): the committed 2.6% `--stats` comes from code that predates the origin filter, and the docs explain it as design. Fix: rerun `bench/docs-cache.sh --label=hit-miss --force` on the shipped code, update the docs, and make `BenchmarksDocTest` read the rate from the stats string (gap 13).
- **Performance.** `app/Repositories/EloquentDocsAnswerCacheRepository.php:38`: the `, id` tie-break in `ORDER BY` stops pgvector from using the HNSW index, so every semantic lookup is an exact sort over all current rows. Fix: order the inner query by distance only, with `LIMIT` and `SET LOCAL enable_seqscan = off`, and apply the id tie-break in an outer query, as `EloquentDocsSearchRepository::nearest` does.
- **Design.** `app/Services/DocsIndexService.php:75`: the index version changes before the queued re-embedding finishes, so answers from a half-embedded index are cached under the final version. Also, the memo key does not include the model label. Fix: add embedding completeness to the fingerprint (stale-chunk count or max `embedded_at`), or refuse to store while `staleChunkIds()` is not empty.
- **Design.** `app/Services/DocsCacheCalibrator.php:106`: the class copies `ThresholdCalibrator`'s Youden, point-at-threshold and stats logic, and the copies already disagree on how they compare with the threshold (EPSILON is used in one and not the other). Fix: move the shared threshold math into one class that both calibrators use.

### Minor

- `app/Services/DocsIndexService.php:74`: a minor version of the half-embedded-index issue above. Fix: forget the version memo when the embed jobs finish, or fingerprint the stale-chunk state.
- `app/Services/DocsIndexService.php:75`: `forget()` on the Redis store has no guard, so `docs:index` fails before queuing embeddings when Redis is down. Fix: wrap it in `report()` the way the rest of the slice does.
- `app/Services/DocsAnswerCache.php:40`: `rtrim('?!.')` runs before `trim()`, so `"retry? ?"` keeps its trailing punctuation. Fix: use `preg_replace('/[\s?!.]+$/u', '', ...)`.
- `app/Repositories/EloquentDocsAnswerCacheRepository.php:58`: upsert replaces the `question` text but keeps the old embedding, and the select-then-insert is not atomic. Fix: use `ON CONFLICT ... DO UPDATE` and write the embedding together with the question.
- `app/Services/DocsCacheCalibrator.php:89`: `ceil(round(x*100, 6))/100` can produce a suggested threshold just below the highest near-miss, for example 0.930000004 gives 0.93. Fix: apply `ceil` to `max*100` minus a tolerance, or check `rounded < max` explicitly.

## Documentation updated

The pipeline ran no docs stage (`Docs: null`). Docs written during the build (task 8):

- `BENCHMARKS.md`: `## Answer cache (2026-11-03)`
- `docs/learning-guide.md`: section 17 added, section 16 updated
- Threshold comments in `config/ai.php` and `.env.example`

Gaps 13 and 14 mean parts of these docs are still inaccurate or incomplete.

## Manual test plan

- Written to `/Users/stasmarkov/work/interview/.ai/specs/spec-20261103-1616-docs-semantic-cache/manual-test.md`.
- How to run: with DDEV, Redis and a warm Ollama, run `ddev artisan docs:index`, then follow steps 1-11, which cover:
  - curl `/api/docs/ask` for a miss, an exact hit, a semantic hit, `cache:false`, 422s and an injection question
  - `docs:cache-calibrate` and `docs:cache --stats/--prune`
  - the `/ai/chat` and `/ai` UI
  - a re-index invalidation check

  Roll back with `docs:cache --prune` and `git checkout` of the step 7 edit.

## Recommendation

**FIX-FIRST.** This cannot be SHIP:
- the build is partial (tasks 6 and 9 are incomplete)
- converge gaps 13-15 were not built
- there is no E2E regression test
- 7 major findings are confirmed, two of them security issues
- all of the work is uncommitted on `master`

Next: stop serving `cached_question` to other callers, and stop `cache:false` requests from overwriting cache entries (`app/Services/DocsAnswerService.php:269`, `app/Repositories/EloquentDocsAnswerCacheRepository.php:45`). Then build gaps 13-15, and rerun `bench/run.sh ping --quick` once the work is committed on a feature branch.
