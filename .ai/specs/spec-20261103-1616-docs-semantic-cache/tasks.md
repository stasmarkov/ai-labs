# Exact and semantic answer cache — Tasks

**Spec**: spec-20261103-1616-docs-semantic-cache | **Size**: M | **Tasks**: 9

## Phase 1: Backend

- [x] 1. Schema, entry model and index version (backend, database)
  Covers: AC-4, AC-5
  - [x] 1.1 Migration `docs_answer_cache` (fields from the spec plus `question_hash`, `provider`, `retrieved_chunk_ids`; unique on hash + four versions; HNSW cosine on the embedding, index on the version columns); `ai_runs` nullable `cache_kind`, `cache_similarity`, `cache_entry_id`, `saved_latency_ms`, `origin` (`api` | `eval`); `AiRunRecord` and the repository carry them; `ai.docs.cache.enabled` false in `phpunit.xml`
  - [x] 1.2 Index version per Clarifications Q8 (memoized 60 s, forgotten by `docs:index` on change)
  - [x] 1.3 **Verify**: tests: index version changes when a document hash changes and stays when nothing changes

- [x] 2. `DocsAnswerCache` service (backend)
  Covers: AC-1, AC-2, AC-3, AC-4
  - [x] 2.1 Normalization; exact key over normalized question + prompt version + model + index version; TTL from config
  - [x] 2.2 Semantic lookup: nearest current entry by cosine, hit at ≥ threshold; `store()` writes the row and the exact key after a qualifying answer, failures reported not thrown
  - [x] 2.3 **Verify**: unit and feature tests for normalization, version filtering (each of the four versions), threshold edge (equal passes, below misses), store refusals for every non-qualifying outcome

- [x] 3. Pipeline, hit stream and bypass (backend)
  Covers: AC-1, AC-2, AC-3, AC-5, AC-9, MNC-1, MNC-2, MNC-3, NFR-3
  - [x] 3.1 `ask()`: guard → exact → embed → semantic → retrieval with the same vector → SL-45 steps; `answer()` never touches the cache
  - [x] 3.2 Hit response through the shared SSE framing with the order in "Stream contract additions"; `cached` `ai_runs` row with saved latency
  - [x] 3.3 Store hook after `stream_end` only for completed, cited, not aborted runs; `DocsAskRequest` accepts boolean `cache`
  - [x] 3.4 **Verify**: `tests/Feature/DocsCacheTest.php`: exact hit without embedding or model, semantic hit with one embedding, below-threshold miss, injection refused even when a similar answer is cached, no store for refused/no_context/failed/uncited, `cache:false` bypass still stores, 422 for non-boolean cache, the `cached` row; `DocsAskTest` unedited and green; `rag:eval` path bypass test

- [x] 4. Calibration command and pair set (backend, data)
  Covers: AC-7, AC-8, NFR-2
  - [x] 4.1 `bench/rag-eval/cache-pairs.v1.jsonl` (≥ 20 paraphrase, ≥ 20 near-miss, intent words differ in near-misses); contamination test covers it
  - [x] 4.2 `docs:cache-calibrate` with per-label stats, Youden, zero-false-hit threshold, configured TPR/FPR, atomic result
  - [x] 4.3 **Verify**: `DocsCacheCalibrateTest` with fixed vectors: separated ranges, overlapping ranges ("none"), ties, invalid lines

- [x] 5. Stats and prune command (backend)
  Covers: AC-6
  - [x] 5.1 `docs:cache --stats` and `--prune`
  - [x] 5.2 **Verify**: `DocsCacheCommandTest` with seeded rows and entries

## Phase 2: Frontend

- [x] 6. Lab chat cached state and checkbox (frontend)
  Covers: AC-10
  - [x] 6.1 `Chat.vue`: "Use cache" checkbox sent as `cache`; `cache_hit` mark with kind, similarity, matched question, age, saved latency; run lookup from `cache_hit.invocation_id`; overview shows `cached`
  - [x] 6.2 **Verify**: typecheck and build; browser check at 1440 and 390 (miss, then exact hit, then a paraphrase)
    - Result (fix pass, live): checkbox on by default; miss 6.4 s, exact repeat shows Cached, "Matched an earlier question", similarity 1.000, no stored question text; overflow 0 at 1440 and 390; one aria-live region; 0 console errors. Semantic hits checked live by `bench/docs-cache.sh` (18 of 18 in the latency run).

## Phase 3: Measure and document

- [x] 7. Committed calibration and hit/miss run (measurement)
  Covers: AC-8, AC-11, NFR-1, NFR-2
  - [x] 7.1 `docs:cache-calibrate` on nginx-fpm; set the default per AC-8
  - [x] 7.2 10 eval questions: fresh, exact repeat, paraphrase; medians per path; `docs:cache --stats`
  - [x] 7.3 **Verify**: result files committed; numbers in task 8 match them

- [x] 8. BENCHMARKS.md, guide section 17 and the code map (docs)
  Covers: AC-11
  - [x] 8.1 `## Answer cache (2026-11-03)` per AC-11; question ids only
  - [x] 8.2 Guide section 17; section 16 updated (every new file, the cache flow inside "Ask the docs", a feature-request row)
  - [x] 8.3 **Verify**: `LearningGuideTest` (section 17 needles, and the section 16 map test still green with the new files), `BenchmarksDocTest`

- [x] 9. Parity, fingerprint and bench (checks)
  Covers: MNC-4
  - [x] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 9.2 **Verify**: outputs recorded here
    - Result: 831 tests, Pint, PHPStan, typecheck, build green; fingerprint unchanged; parity identical on 6 servers; bench re-run after the fix pass (hit-miss and latency labels, 2026-11-03 16:17 and 16:22 UTC); `bench/run.sh ping --quick` exit 0 on a clean tree after the feature commit.
    Recorded 2026-11-03: suite 804 passed; Pint passed; PHPStan no errors; `npm run typecheck` and `npm run build` clean.
    Fingerprint: `resources/css/app.css assets/app-CjRfEL-0.css 82042c94...ce71`, `resources/js/app.js assets/app-BvRk9kiK.js e3b0c44298...b855` (as recorded in BENCHMARKS.md).
    Parity: a fresh capture on nginx-fpm, apache-fpm, frankenphp, openlitespeed, frankenphp-octane and frankenphp-worker is `identical` to each committed snapshot (6 of 6).
    Open: `bench/run.sh ping --quick` refuses with "git working tree has changes outside bench/results/ and .ai/session/" because this spec is not committed yet. Rerun after the commit.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | exact hit, no embedding or model | `tests/Feature/DocsCacheTest.php` | test | 2, 3 |
| AC-2 | semantic hit at threshold, one embedding | `tests/Feature/DocsCacheTest.php` | test | 2, 3 |
| AC-3 | store only completed, cited, not aborted | `tests/Feature/DocsCacheTest.php` | test | 2, 3 |
| AC-4 | version filter on four versions | `tests/Feature/DocsAnswerCacheTest.php` | test | 1, 2 |
| AC-5 | cached ai_runs row with saved latency | `tests/Feature/DocsCacheTest.php` | test | 1, 3 |
| AC-6 | stats and prune | `tests/Feature/DocsCacheCommandTest.php` | test | 5 |
| AC-7 | calibration command | `tests/Feature/DocsCacheCalibrateTest.php` | test | 4 |
| AC-8 | default from the committed run | `tests/Feature/DocsCacheCommittedResultsTest.php` | test | 7 |
| AC-9 | cache:false bypass, 422 | `tests/Feature/DocsCacheTest.php` | test | 3 |
| AC-10 | lab cached state | `manual-test.md#step-6` | manual | 6 |
| AC-11 | committed runs, BENCHMARKS, guide 16 and 17 | `tests/Feature/LearningGuideTest.php` | test | 8 |
| MNC-1 | ask unchanged on miss or bypass | `tests/Feature/DocsAskTest.php`, `tests/Feature/DocsCacheTest.php` | test | 3, 12 |
| MNC-2 | answer() and rag:eval bypass | `tests/Feature/DocsCacheTest.php` | test | 3 |
| MNC-3 | guard before cache | `tests/Feature/DocsCacheTest.php` | test | 3 |
| MNC-4 | fingerprint, parity, offline suite | `manual-test.md#step-10` | manual | 9 |
| NFR-1 | hit latency budgets | `tests/Feature/DocsCacheCommittedResultsTest.php`, `manual-test.md#step-4` | test | 7, 11 |
| NFR-2 | zero near-miss hits on the set | `tests/Feature/DocsCacheCalibrateTest.php`, `tests/Feature/DocsCacheCommittedResultsTest.php`, `manual-test.md#step-2` | test | 4, 7 |
| NFR-3 | no bypass of the guard, no replay of refusals | `tests/Feature/DocsCacheTest.php` | test | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 10. Hit rate over endpoint rows only: write and filter `origin` (backend)
  Covers: AC-6
  - `EloquentAiRunRepository::docsCacheRuns()` counts every `DocsAnswerAgent` `completed` row with no `origin` filter, and only `AiRunRecorder::recordCacheHit()` sets `origin: 'api'`. Streamed `ask()` rows, `recordOutcome()` rows and `answer()` (`rag:eval`) rows all keep `origin` null (the dev db shows `origin: null` on every `completed` row of the hit/miss run). So eval runs inflate the denominator, which is why the committed `--stats` reads 2.6% instead of the run's 11 of 30. Clarifications Q11 requires `origin` `api` | `eval` and excludes eval rows. `DocsCacheCommandTest::cmdRun()` never sets `origin`, so no test catches this.
  - Approach: stamp `origin` `api` on the rows `ask()` writes (streamed completed/failed/refused and the early outcomes) and `eval` on the rows `answer()` writes, through the recorder and `AiRunRecord`. Filter `docsCacheRuns()` on `origin = 'api'`. Add `origin` to `cmdRun()`.
  - [x] 10.1 **Verify**: `DocsCacheCommandTest` has a case with `eval` completed rows that leave the hit rate unchanged. `DocsCacheTest` asserts `origin` `api` on a streamed miss row and `eval` on an `answer()` row. `DocsAskTest` stays unedited and green.

- [x] 11. NFR-1 measured on a median of 10 hits per path (measurement)
  Covers: NFR-1
  - NFR-1 asks for a median of 10, Ollama warm. `bench/results/docs-cache/2026-11-03/hit-miss/hit-miss.json` has only 8 exact hits and 3 semantic hits (`by_path`). `DocsCacheCommittedResultsTest` "NFR-1" only asserts `count() > 0`, so the semantic budget is proven on 3 samples.
  - Approach: add a latency step to `bench/docs-cache.sh` (or a new label) that times 10 exact repeats and 10 semantic hits of stored, cited entries. Use paraphrases known to score at least `min_similarity`. Commit the result and point the NFR-1 test and the BENCHMARKS.md row at it.
  - [x] 11.1 **Verify**: the NFR-1 test asserts at least 10 samples each for `exact` and `semantic`, median exact < 100 ms and median semantic < 300 ms. BENCHMARKS.md numbers match the file.

- [x] 12. MNC-1 guard for the enabled-cache miss and bypass streams (backend, test)
  Covers: MNC-1
  - `phpunit.xml` sets `ai.docs.cache.enabled` to false, so `DocsAskTest` only exercises `DocsCacheMode::Off`. The `Lookup`-miss and `Bypass` paths (precomputed vector passed to `retrieve()`, store hook inside the protocol) are checked in `DocsCacheTest` only with `toContain('stream_start', 'stream_end')`. No test proves that the full event sequence, the run row and the rate limit match the SL-45 contract.
  - Approach: in `DocsCacheTest`, ask the same question with the cache off, with `cache:false` and as a below-threshold miss. Assert identical event-type sequences and payload keys (minus ids). Assert the same `ai_runs` fields (status, tokens, retrieved/cited ids, `cache_*` null). Assert that hits and misses both count against the `docs-ask` limiter (429 after `ai.docs.answer.per_minute`).
  - [x] 12.1 **Verify**: the new MNC-1 tests pass, and `DocsAskTest` is unedited and green.

## Phase: Gaps (converge round 2)

- [x] 13. Recommitted hit/miss `--stats` after the `origin` fix, and docs that match it (measurement, docs)
  Covers: AC-11, AC-6
  - `bench/results/docs-cache/2026-11-03/hit-miss/hit-miss.json` `stats` (generated 15:01, before task 10) prints "2.6% | 11 | 414": the 414 completed rows are `rag:eval` and other rows with no `origin`, which `EloquentAiRunRepository::docsCacheRuns()` now excludes (`where('origin', 'api')`). So the committed "hit rate from `docs:cache --stats`" (AC-11) came from an implementation that broke AC-6 / Clarifications Q11. BENCHMARKS.md (latency section, "Surprises", "Interview story") and guide section 17 ("The stats showed 2.6% … because they count every earlier row") explain the bug as if it were design. `BenchmarksDocTest` pins `toContain('2.6%')`.
  - Approach: on nginx-fpm with Ollama warm, rerun `bench/docs-cache.sh --label=hit-miss --force` now that rows carry `origin` (prune the cache first so fresh misses are real). Update the BENCHMARKS.md section and guide section 17 numbers and the stats explanation, and the `LearningGuideTest`/`BenchmarksDocTest` needles. Do not change the AC-6 definition.
  - [x] 13.1 **Verify**: the committed `hit-miss.json` `stats` table is from after task 10 (its "Completed" count matches the `api` rows, not the 414 eval-inflated ones). `BenchmarksDocTest` reads the hit rate it asserts from that `stats` string, not a literal `2.6%`. BENCHMARKS.md and guide section 17 no longer say the stats count every earlier row. `ddev artisan test --compact` is green.

- [x] 14. Section 16 maps the two new paraphrase data files (docs)
  Covers: AC-11
  - AC-11 asks for section 16 to be "updated with every new file". `bench/rag-eval/cache-hitmiss-paraphrases.v1.jsonl` and `bench/rag-eval/cache-latency-paraphrases.v1.jsonl` are new (untracked) files of this slice. `docs/learning-guide.md` never names either: its only data row is `bench/docs-cache.sh` plus `cache-pairs.v1.jsonl`. The section 16 test in `LearningGuideTest` ("AC-11 learning guide section 16 maps every answer cache file") does not list them, so it cannot catch the omission.
  - Approach: extend the `bench/docs-cache.sh` row in section 16 with both paraphrase files and what each run uses them for (the hit/miss run, the NFR-1 latency run). Add both paths to the `$files` list in the section 16 test.
  - [x] 14.1 **Verify**: `ddev artisan test --compact tests/Feature/LearningGuideTest.php` is green with both files in the section 16 test list, and the links resolve.

- [x] 15. Test that `saved_latency_ms` is floored at 0 on a hit (backend, test)
  Covers: AC-5
  - AC-5 sets `saved_latency_ms` to the entry's source latency minus this latency, "floored at 0". `DocsAnswerService::serveHit()` does `max(0, $entry->sourceLatencyMs - $latencyMs)`, but the only AC-5 test (`DocsCacheTest` "AC-5 writes a cached ai_runs row …") sets `source_latency_ms` to 9000, so the floor is never exercised. Removing `max(0, …)` would leave the suite green while writing negative saved latency to `ai_runs` and to `cache_hit`.
  - Approach: in `DocsCacheTest`, store an entry and set its `source_latency_ms` to 0. Serve an exact hit and assert that `cache_hit.saved_latency_ms` and the `cached` row's `saved_latency_ms` are both 0.
  - [x] 15.1 **Verify**: the new test passes. It fails when the `max(0, …)` in `serveHit()` is removed.

## Phase: Review fix pass

All items verified against the code before fixing; none skipped. Gates: 831 tests pass, Pint, PHPStan, typecheck and build clean.

- [x] R1. Cache versions read once in `plan()` (`DocsAnswerPlan::$versions`), passed to both lookups and carried in `DocsCacheCandidate::$versions`; `store()` no longer reads `DocsIndexVersion`. Test: index changes between plan and store, entry stored under the plan-time version.
- [x] R2. Index fingerprint appends `|pending:N` while N chunks lack a vector for the current embedding model (a complete index hashes as before). `EmbedDocsBatchJob` forgets the memo in `finally`; `docs:index` also forgets it when stale chunks are queued. `store()` refuses every answer while any chunk is pending (docblock states the rule). Tests for both.
- [x] R3. `DocsIndexVersion::forget()` reports a cache-store failure instead of throwing, so `docs:index` and the embed job survive Redis being down. Test with a store whose `forget` throws.
- [x] R4. `normalize()` uses one `/[\s?!.]+$/u` removal. Tests for `How do I retry? ?` and `How do I retry. . .`.
- [x] R5. `upsert()` is one `INSERT ... ON CONFLICT (question_hash, prompt_version, answer_model, embedding_model, index_version) DO UPDATE ... RETURNING id`; updates question and embedding, resets `created_at`, keeps `hit_count`. Test: re-store with a different case updates the embedding.
- [x] R6. Calibrator default: `ceil(max*100)/100`, then +0.01 when `rounded <= max`. Test with 0.930000004 and other float cases.
- [x] R7. `cached_question` removed from the `cache_hit` event (unauthenticated endpoint, global cache). Chat.vue shows "Matched an earlier question" plus similarity; TS type and `AiLabChatTest` needle updated; spec-lite contract, AC-1, AC-10 and manual-test revised. Test: event keys are exactly the allowed set and carry no question text.
- [x] R8. `nearestCurrent()` orders by `embedding <=> ?` only, `LIMIT 1`. EXPLAIN on the dev DB: with sort and seqscan disabled the new shape uses `Index Scan using docs_answer_cache_embedding_hnsw_idx ... Order By: (embedding <=> ...)`; the old `, id` shape can only Sort. Query-shape test added.
- [x] R9. Shared `ThresholdMath` (stats, point-at-threshold, Youden best) used by `ThresholdCalibrator` and `DocsCacheCalibrator`; one rule, `>=` threshold, no epsilon (the old cache copy used `threshold - 1e-9`). No existing test needed changing.
- [x] R10. Source latency is the recorder's logged `latency_ms` of the source run (`AiRunRecorder::latencyOf()`), falling back to request-elapsed only if the run was not finished by the recorder. Test: entry `source_latency_ms` equals the source `ai_runs.latency_ms`.
- [x] R11. Test that `saved_latency_ms` is floored at 0 (fails when `max(0, ...)` is removed). Closes gap 15.
- [x] R12. Confirmed the endpoint miss row has `origin` `api` and `docs:cache --stats` counts it (test: miss + hit through the endpoint + one `answer()` eval row gives 50.0%). The bench was not re-run (gap 13 stays open).
- Not in this pass: review finding "cache:false overwrite / poisoning" (security, `EloquentDocsAnswerCacheRepository`), the memo key without the model label, 6.2, 9, 13, 14.

- Owner-side notes (fix pass): the poisoning finding (`cache:false` overwrite, injected questions) was measured, not coded: questions with an injected instruction scored 0.827–0.881 against the clean question, under the 0.94 threshold, and an exact hit needs identical normalized text; recorded in BENCHMARKS.md "Poisoning and privacy checks" and guide section 17. The hit/miss and latency benches were re-run with the fixed code (gap 13); BENCHMARKS.md and guide section 17 now quote those files.
