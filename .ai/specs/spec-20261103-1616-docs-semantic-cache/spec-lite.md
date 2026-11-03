---
id: spec-20261103-1616-docs-semantic-cache
title: Exact and semantic answer cache for the docs chatbot
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-03T14:19:54Z
created: 2026-11-03
stacks: [laravel]
slice: SL-40
brs: [BR-99, BR-90, BR-27, BR-26]
---

# Exact and semantic answer cache for the docs chatbot

## Summary

Before `POST /api/docs/ask` calls the model, it looks for a stored answer in two places:
- **Exact:** a Redis key for the normalized question.
- **Semantic:** the nearest stored question in pgvector, whose similarity must be at or above a calibrated threshold.

A hit streams the stored answer at once, with its sources and citations and a `cache_hit` event that marks it as cached. A miss runs the SL-45 pipeline unchanged and stores the answer only if it completed with at least one citation. Refused, `no_context`, failed and uncited answers are never stored.

Entries are valid only for the current prompt version, answer model, embedding model and docs index version, so re-indexing or a new prompt makes old entries unreachable. Every hit writes an `ai_runs` row with status `cached`, the kind of hit, its similarity and the latency it saved. That makes the hit rate and the time saved queryable.

The threshold comes from a new calibration command over labelled paraphrase and near-miss pairs. It uses the same TPR/FPR method as SL-33, biased so that no near-miss is served.

## Context

- **Problem**: every question costs a full model call (the SL-33 median answer latency is 8 997 ms for qwen3.5:9b), even when the same question was just answered. BR-99 asks for a cache that only returns an answer above a similarity threshold and marks it as cached.
- **The risk**: a semantic cache returns a different question's answer, confidently and with valid-looking citations. A wrong hit is worse than a miss.
- **Measured before writing (2026-11-03, bge-m3, cosine)**:
  - 6 paraphrase pairs scored 0.839, 0.862, 0.872, 0.885, 0.909 and 0.958.
  - 6 near-miss pairs (same words, a different intent such as "retry" vs "delete a failed job", or "schedule" vs "cancel a queued job") scored 0.533, 0.741, 0.771, 0.776, 0.811 and 0.811.
  - The gap between the groups is 0.028. The reference design's 0.95 would miss 5 of the 6 paraphrases. Any threshold below 0.811 serves "delete" answers to "retry" questions.

## Scope

### In scope

- **A pair set.** `bench/rag-eval/cache-pairs.v1.jsonl` holds at least 40 labelled pairs: 20 paraphrases and 20 near-misses that differ in one word of intent. Each line is `{"id","label":"paraphrase"|"near_miss","a","b"}`. The folder is not indexed, and the SL-33 contamination test is extended to this file.
- **`docs:cache-calibrate`.** It embeds every pair once and computes cosine similarity per pair. It then reports min, median and max per label, the Youden-best threshold, the lowest threshold with FPR = 0 on the set (the "zero-false-hit threshold"), and TPR/FPR at the configured threshold. It writes `bench/results/docs-cache/<UTC date>/<label>/calibration.json`, reusing the SL-33 result writer's path rules.
- **The exact cache.** Redis (the `redis` store) key `docs-answer:exact:<sha256>` over the normalized question, prompt version, answer model and index version. The value is the cache entry id. TTL is `ai.docs.cache.ttl_hours` (default 168).
- **The semantic cache.** A table `docs_answer_cache` with:
  - the question and its embedding (`vector(1024)`, HNSW cosine);
  - answer text, sources (the `sources` event payload), cited chunk ids;
  - prompt version, answer model, embedding model, index version;
  - source invocation id, source latency, hit count, last hit at, created at.
- **The index version.** `sha256` over the ordered `(source, path, hash)` rows of `docs_documents` plus the embedding model, computed per request with one query.
- **The pipeline.**
  1. The input guard runs first.
  2. Exact lookup (no embedding).
  3. Embed the question.
  4. Semantic lookup, filtered to the current versions.
  5. On a miss, retrieval reuses the same question vector (no second embedding), then the unchanged SL-45 steps.
  6. Store after a completed, cited answer.
- **The hit stream.** `sources`, `cache_hit`, one `text_delta` with the full answer, `citations`, `[DONE]`.
- **Bypassing the cache.** A request body field `"cache": false`, a lab checkbox, and `rag:eval`, which always bypasses.
- **`ai_runs` gets four nullable columns:** `cache_kind` (`exact` | `semantic`), `cache_similarity`, `cache_entry_id` and `saved_latency_ms`. There is a new status `cached`.
- **`docs:cache`** with `--stats` (hit rate, hits by kind, saved latency sum and median, entries current vs stale) and `--prune` (delete stale entries and their Redis keys).
- **The lab `/ai/chat` page:** a "Cached" mark with the kind, similarity, a "Matched an earlier question" line (the text itself is never sent; revised in review fix pass) and the saved latency, plus a "Use cache" checkbox (on by default).
- **Results and docs:**
  - a committed calibration plus a hit/miss latency run;
  - a `BENCHMARKS.md` section;
  - guide section 17, and the section 16 code map updated (BR-27).

### Out of scope

- Caching retrieval results or embeddings alone (only whole answers).
- Multi-turn context (SL-41). The cache key is the single question.
- Personalized or per-user caches (there are no users).
- Distributed invalidation beyond the version filter, and an LRU size limit. The TTL and `--prune` are enough for a local lab.

## Must NOT change

- **MNC-1**: with `"cache": false` or on a miss, the stream contract, status codes, `ai_runs` rows and rate limit of `POST /api/docs/ask` stay as in SL-45. `DocsAskTest` stays green unedited.
- **MNC-2**: `DocsAnswerService::answer()` and `rag:eval` never read or write the cache. SL-33's committed numbers can be reproduced.
- **MNC-3**: the input guard still runs before any cache lookup, so an injection question is refused and logged, never served from the cache.
- **MNC-4**: the asset fingerprint and parity on all 6 servers stay as recorded. The full suite runs offline (Redis and pgvector are local services, as today).

## Acceptance criteria

- **AC-1 (BR-99)**: WHEN a question is asked whose normalized form (lowercase, trimmed, whitespace collapsed, trailing `?!.` removed) equals a stored entry's for the current versions, THE SYSTEM SHALL:
  - stream `sources`, then `{"type":"cache_hit","kind":"exact","similarity":1.0,"cached_at":"…","saved_latency_ms":…,"invocation_id":"…","source_invocation_id":"…"}` (revised in review fix pass: `cached_question` removed, because the endpoint is unauthenticated and the cache is global, so it would show other clients' raw questions), then one `text_delta` with the stored answer, then `citations` (the stored cited ids mapped to `n`), then `[DONE]`;
  - embed nothing and call no model.
- **AC-2 (BR-99)**: WHEN no exact entry exists and the nearest current entry has cosine similarity ≥ `ai.docs.cache.min_similarity`, THE SYSTEM SHALL serve that entry as in AC-1, with `kind: semantic` and the measured similarity. Below the threshold it SHALL run the normal pipeline, reusing the question vector it already computed for retrieval (one embedding per request in total).
- **AC-3**: THE SYSTEM SHALL store an entry only when the outcome is `completed`, the answer has at least one cited chunk, and the client did not disconnect before `stream_end`. Refused (input or output), `no_context`, failed, uncited and stopped answers are never stored. Storing writes the row and the exact key in one step after the stream ends; a storing failure is reported and never breaks the response.
- **AC-4**: An entry SHALL be served only when its prompt version, answer model, embedding model and index version all equal the current ones. A run of `docs:index` that changes any document, a new prompt file version, or a model change makes every older entry a miss with no manual step.
- **AC-5 (BR-90)**: Every hit SHALL write an `ai_runs` row with:
  - agent `DocsAnswerAgent`, the entry's provider and model, the current prompt version, status `cached`;
  - `cache_kind`, `cache_similarity`, `cache_entry_id`, `retrieved_chunk_ids` and `cited_chunk_ids` from the entry;
  - `latency_ms` of the lookup, and `saved_latency_ms` = the entry's source latency − this latency;
  - tokens null.
- **AC-6**: `ddev artisan docs:cache --stats` SHALL print, for the last 24 h and for all time:
  - hit rate = cached ÷ (cached + completed) over `DocsAnswerAgent` rows;
  - hits by kind;
  - sum and median of `saved_latency_ms`;
  - entries current vs stale.
  `--prune` SHALL delete stale and expired entries and their exact keys, and print the count.
- **AC-7**: `ddev artisan docs:cache-calibrate` SHALL:
  - validate the pair file (at least 20 per label, unique ids, line-numbered errors);
  - embed every distinct text once;
  - report per-label min, median and max, the Youden threshold (ties → higher), the zero-false-hit threshold (the lowest observed paraphrase similarity strictly above the highest near-miss similarity, or "none" when the ranges overlap), and TPR/FPR at the configured threshold;
  - write `calibration.json` atomically.
- **AC-8**: The default `ai.docs.cache.min_similarity` SHALL follow the rounding rule in Clarifications Q9 (the highest near-miss similarity rounded up to 2 decimals, + 0.01 if equal), with any lost paraphrase hits stated. This holds whether or not the ranges overlap. The value and its TPR go in a comment in `config/ai.php` and `.env.example`.
- **AC-9**: WHEN the request body has `"cache": false`, THE SYSTEM SHALL skip both lookups. It SHALL still store a qualifying answer, so the lab can compare a fresh answer with a cached one. A non-boolean `cache` value is a 422.
- **AC-10**: The lab `/ai/chat` SHALL:
  - show a "Use cache" checkbox (default on);
  - on `cache_hit`, show a "Cached" mark with the kind, the similarity (3 decimals), "Matched an earlier question" (not the question text; revised in review fix pass), its age and the saved latency;
  - show the answer, sources and citations exactly as for a fresh answer.
  The overview's runs table shows status `cached`.
- **AC-11 (BR-26, BR-27)**: The build SHALL commit:
  - one `docs:cache-calibrate` run;
  - one hit/miss run: 10 eval-set questions asked fresh, then asked again (exact), then as a paraphrase (semantic), recording median latency per path and the hit rate from `docs:cache --stats`;
  - a `BENCHMARKS.md` section `## Answer cache (2026-11-03)` with the tables, "What the numbers say", "Surprises" and "Interview story";
  - `docs/learning-guide.md` section `## 17. Caching answers: exact keys, semantic neighbours and invalidation`, covering why a near-miss hit is worse than a miss, the measured gap, the version filter instead of deletes, and why the guard runs before the cache;
  - section 16 updated with every new file and a "make answers cacheable / tune the cache" row.

## Stream contract additions

| Event | Payload |
|---|---|
| `cache_hit` | `{"type":"cache_hit","kind":"exact"\|"semantic","similarity":0.912,"cached_at":"ISO-8601","saved_latency_ms":8840,"invocation_id":"…","source_invocation_id":"…"}` (revised in review fix pass: no `cached_question`) |

Order on a hit: `sources` → `cache_hit` → `text_delta` (the full answer, once) → `citations` → `[DONE]`. A hit sends no `stream_start`, `text_start`, `text_end` or `stream_end`, because no model stream exists. The lab's run lookup uses `cache_hit.invocation_id`.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Performance | An exact hit reaches `[DONE]` in < 100 ms and a semantic hit in < 300 ms (embedding ~60 ms + db), median of 10, Ollama warm. | `manual-test.md#step-4` |
| **NFR-2** | Correctness | On the committed pair set the configured threshold serves 0 near-misses (FPR = 0). | `tests/Feature/DocsCacheCalibrateTest.php` on a fixture and `manual-test.md#step-2` |
| **NFR-3** | Security | A cache hit never bypasses the input guard. A refused or leaked answer can never be stored, so it can never be replayed. | `tests/Feature/DocsCacheTest.php` |

## Boundaries

- **Always**:
  - feature tests with `Embeddings::fake` and the fake-stream provider (no network);
  - Redis through the cache store, so tests use the `array` store;
  - keep the pair file out of indexed folders;
  - update section 16.
- **Ask first**: new dependencies; switching the cache store away from Redis; changing SL-45's prompt, guards or gate.
- **Never**:
  - store a refused, `no_context`, failed, uncited or stopped answer;
  - serve an entry from another prompt, model or index version;
  - commit `.env`, `public/build` or `.ai/session`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `app/Services/DocsAnswerService.php` (`ask()`, `answer()`, `plan()`);
  - `app/Ai/DocsAnswerProtocol.php` and `app/Ai/SseEventsResponse.php` (framing);
  - `app/Services/DocsSearchService.php` (`search(..., $queryVector)`, `embedQuery()`);
  - `app/Ai/AiRunRecorder.php` (`recordOutcome()`, `annotate()`);
  - `app/Services/ThresholdCalibrator.php` and `app/Services/RagEvalResultWriter.php` (calibration and atomic results to reuse or generalize);
  - `app/Http/Requests/DocsAskRequest.php`;
  - `resources/js/ai/Pages/Lab/Chat.vue`;
  - `config/ai.php`;
  - `.ddev/docker-compose.redis.yaml` (Redis exists).
- **Approach**:
  - A `DocsAnswerCache` service (`lookupExact`, `lookupSemantic`, `store`, `indexVersion`) behind a repository interface for the pgvector table, and the cache store for exact keys.
  - `DocsAnswerService::ask()` calls it after the guard. The protocol gets a "store on success" hook, which runs after `stream_end` only when nothing was refused, failed or aborted (`connection_aborted()` checked after the last write).
  - A calibration helper for pairs (positives vs negatives), sharing math with `ThresholdCalibrator`.

## Decisions (self-decided, standing approval)

1. **Exact in Redis, semantic in pgvector.** The exact path should cost no embedding: a hash lookup is ~1 ms. The semantic path needs nearest-neighbour search, which pgvector already provides next to the data. Redis vector search needs Redis Stack, a new dependency. The cache entry lives in Postgres once; Redis holds only the exact pointer.
2. **The guard runs before the cache (MNC-3).** A cache in front of the guard would let a refused phrasing through as soon as a similar honest question was cached, and its refusal would not be logged.
3. **Store only completed and cited answers.** The reference design says caching unstable answers freezes mistakes. An uncited answer (SL-45 saw one) is the weakest kind, so it is not frozen.
4. **A version filter instead of deleting on change.** Invalidation by filtering on prompt, model, embedding model and index version needs no hook into `docs:index` and cannot miss a path. Stale rows are inert until `--prune` or the TTL.
5. **The default threshold leans towards zero false hits, not Youden.** The two errors cost different amounts: a miss costs ~9 s, a wrong hit costs a wrong answer. With the measured 0.028 gap, Youden's balanced choice and the zero-false-hit choice may differ; the rule picks safety.
6. **One embedding per request.** The question vector serves both the semantic lookup and retrieval, so a miss costs only the db query extra (~5 ms), not a second ~60 ms embedding.
7. **The full answer goes in one `text_delta` on a hit.** Replaying fake token timing would misrepresent what happened. The lab's tokens/s reads as one burst, which is the honest picture.
8. **`rag:eval` and `answer()` bypass the cache (MNC-2).** An evaluation that hits a cache measures the cache, not the model.

## Clarifications (binding; resolve the clarity gate)

**The test environment (Q1).** The suite runs on the pgsql `testing` database with pgvector (as SL-24/32/33). `ai.docs.cache.enabled` (env `AI_DOCS_CACHE_ENABLED`, default true) is set to false in `phpunit.xml`. `DocsAskTest` therefore keeps testing the uncached path unedited (MNC-1), and the cache tests turn it on with `config([...])`. Tests use the `array` cache store for exact keys.

**Storing and disconnects (Q2).** The store step runs inside the response callback, after `citations` is written and flushed and before `[DONE]`. Just before storing, it checks `connection_aborted()`; a "stopped" answer is one whose client disconnected before that point. On PHP-FPM with the default `ignore_user_abort=0`, a disconnected client makes PHP abort at the next write, so the store step never runs. That is the intended behaviour; no `ignore_user_abort` change is made. On worker servers the check catches it.

**Duplicates (Q3).** One current entry per (normalized question, prompt version, answer model, embedding model, index version), enforced by a unique index on a `question_hash` column plus the four versions. Storing again (e.g. after `cache:false`) upserts: it replaces the answer, sources, cited ids, source invocation id and source latency, keeps `hit_count`, and resets `created_at`. The exact key is rewritten to the same row id.

**`cache_hit.invocation_id` (Q4)** is the new `cached` row's id (what the lab looks up). The source run's id is sent as `source_invocation_id` too.

**Columns for the hit row (Q5).** The table also stores `provider` and `retrieved_chunk_ids` (jsonb, the ids of the sources given to the model). The hit row copies both.

**TTL (Q6).** Age counts from `created_at`. Both lookups ignore rows older than `ttl_hours`. `--prune` deletes rows that are stale (any version differs from the current ones) or expired, plus their exact keys. The Redis key TTL is the same `ttl_hours`.

**A dangling exact key (Q7).** The row is re-checked: it must exist, match all four versions and be unexpired. Otherwise the key is deleted and the request continues to the semantic lookup. "Embed nothing" holds only for a successful exact hit.

**The index version and its cost (Q8).** `sha256` over `source|path|hash\n` lines ordered by source then path, followed by `embedding_model`. It is memoized in the cache store under `docs-answer:index-version` for 60 s, and `docs:index` forgets that key when it changes any document. So an exact hit costs one cache read and one row read.

**Rounding and comparisons (Q9).** TPR and FPR use ≥ (the same comparison as the lookup). The zero-false-hit threshold is a value from the data: the lowest paraphrase similarity strictly above the highest near-miss. The default is the highest near-miss similarity rounded **up** to 2 decimals, and if that value equals a near-miss, + 0.01. A paraphrase between that value and the next one is a lost hit, and the report says how many. NFR-2 refers to the committed pair set; the fixture test covers the logic.

**The embedded text (Q10).** The raw trimmed question is embedded, exactly as SL-45 does, and the same vector serves the semantic lookup and retrieval. Normalization is for the exact key only: `mb_strtolower`, trim, whitespace runs collapsed to one space, and every trailing run of `?`, `!` and `.` removed.

**Hit rate (Q11).** cached ÷ (cached + completed) over `ai_runs` rows with agent `DocsAnswerAgent` that came from the endpoint. Rows from `answer()` (`rag:eval`) are told apart by a nullable `origin` column (`api` | `eval`) added in task 1, and excluded. Refused and `no_context` rows are excluded because the cache could never serve them. `cache:false` misses count as completed.

**Latencies (Q12).** The source latency is the source row's `latency_ms` (request start to `stream_end`, as logged today). A hit's `latency_ms` is request start to the `cache_hit` event. `saved_latency_ms` = source − hit, floored at 0.

**`hit_count` and `last_hit_at` (Q13).** A hit runs one atomic `UPDATE … SET hit_count = hit_count + 1, last_hit_at = now()` before streaming. A failure is reported and the hit still streams.

**The rate limit and the request (Q14).**
- **Rate limit:** hits count against `docs-ask` as every validated request does (SL-45 rule).
- **Request:** the body is `{"q": "…", "cache": true|false}`.
- **`--label`:** follows SL-33's rules (default `HHMMSS`, `[A-Za-z0-9._-]{1,60}`).
- **Contamination:** the SL-33 contamination test also asserts that no pair text appears in an indexed file. The 12 probe pairs quoted in this spec are therefore **not** reused in the pair file, because this spec becomes indexed when done.

**Write order and "reported" (Q15).** The Postgres upsert goes first, then the Redis key. If the upsert fails, nothing is cached. If the key write fails, the entry is still reachable semantically. "Reported" means Laravel's `report()`; the response is never affected.

## Open questions

Clarity gate 2026-11-03: a fresh-context reviewer raised 15 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: The test environment and the cache flag.
- [x] Q2: Storing in-stream and disconnects.
- [x] Q3: Upsert on duplicates.
- [x] Q4: Which invocation id `cache_hit` carries.
- [x] Q5: The provider and retrieved ids columns.
- [x] Q6: TTL on both lookups.
- [x] Q7: A dangling exact key.
- [x] Q8: The memoized index version.
- [x] Q9: Rounding and comparisons.
- [x] Q10: Raw text embedded; normalization for the exact key only.
- [x] Q11: The hit-rate denominator and `origin`.
- [x] Q12: Latency definitions.
- [x] Q13: The atomic hit counter.
- [x] Q14: Rate limit, request body, label, contamination of pairs.
- [x] Q15: Write order and `report()`.

## Dependencies

- **Requires**: SL-45 (pipeline), SL-33 (calibration method, result writer), Redis (running).
- **Blocks**: SL-41 (the chat page shows the cached state).

---
Next: `/sdd spec-20261103-1616-docs-semantic-cache` (approval → build)
