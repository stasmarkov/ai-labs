# Multi-turn docs chat with a real Stop — Tasks

**Spec**: spec-20261103-2118-chat-stop-multiturn | **Size**: M | **Tasks**: 9

## Phase 1: Backend

- [x] 1. Server-side cancel in the shared protocol (backend)
  Covers: AC-1, AC-3, MNC-2
  - [x] 1.1 `AbortWatcher` seam; `SseProtocol::response()` sets `ignore_user_abort(true)` for the stream, checks after each flush, stops iterating, releases the SDK generator and HTTP body, restores the setting in `finally`
  - [x] 1.2 Recorder: `stopped()` with tokens so far, TTFT, latency, `stopped_after_ms`; migration for `history_turns`, `stopped_after_ms`; status `stopped`
  - [x] 1.3 The docs answer store hook skips stopped runs
  - [x] 1.4 **Verify**: tests with a fake watcher that aborts after N events: describe and ask both write a `stopped` row with the right token count, no further output, no cache entry; `ListingDescribeTest` unchanged

- [x] 2. History in the request and the prompt (backend)
  Covers: AC-4, AC-6, NFR-2, MNC-1
  - [x] 2.1 `DocsAskRequest`: `history` rules per AC-6; config `ai.docs.chat.max_history_turns`
  - [x] 2.2 `docs-answer.v2.md` (config `ai.prompts.docs_answer_history`); `DocsAnswerAgent` uses v2 only with history and builds escaped `<history>` before `<context>`; v1 unchanged without history (no test edits)
  - [x] 2.3 Retrieval on the latest question; cache skipped with history; `history_turns` recorded
  - [x] 2.4 **Verify**: `tests/Feature/DocsChatHistoryTest.php` (message shape and escaping, retrieval input, cache skipped, 422 dataset, row column)

- [x] 3. Guards with history (backend)
  Covers: AC-5, NFR-2
  - [x] 3.1 Input guard over the latest question and every user turn, `turn` in the refusal; output guard allowed texts exclude history
  - [x] 3.2 **Verify**: tests: injection in an earlier user turn refused; a forged assistant turn with prompt words does not unlock the overlap check

## Phase 2: Frontend

- [x] 4. Transcript chat page (frontend)
  Covers: AC-7, AC-3, NFR-3
  - [x] 4.1 `Chat.vue`: transcript with per-turn sources/citations/cached/refusal/run; history from completed turns only (last 6); Stop marks "Stopped"; New chat; one `aria-live` for the newest answer
  - [x] 4.2 **Verify**: typecheck, build, `AiLabChatTest` props; browser check at 1440 and 390 (two-turn chat, Stop mid-answer, New chat)
    - Result (fix pass, live Ollama): history sent with 0, 2, 4 turns; Stop left "Stopped after 16 tokens. This answer is not sent as history.", textarea enabled, Stop disabled; one aria-live, zero role=alert; overflow 0; 0 console errors. The acceptance phase checked 390 px and New chat.

## Phase 3: Measure and document

- [x] 5. Stop measurement script (bench)
  Covers: AC-2, NFR-1
  - [x] 5.1 `bench/chat-stop.sh`: per mode, 5 runs, abort after N tokens, read the row, probe Ollama with a 1-token request against an idle baseline, JSON per mode; refuses a dirty tree like the other bench scripts only if they do (follow `bench/docs-cache.sh` conventions)
  - [x] 5.2 **Verify**: the script runs on nginx-fpm and prints a table

- [x] 6. Run all four modes (measurement)
  Covers: AC-2, NFR-1
  - [x] 6.1 nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane, frankenphp-worker; finish on nginx-fpm
  - [x] 6.2 **Verify**: six JSON files committed; any mode that cannot detect the abort is reported with its numbers

- [x] 7. Follow-up retrieval examples (measurement)
  Covers: AC-8
  - [x] 7.1 Three two-turn chats where the follow-up depends on history ("how do I undo it?"); record the retrieved paths and whether the answer is right, for the guide
  - [x] 7.2 **Verify**: numbers in section 18 match a committed note under `bench/results/chat-stop/`

- [x] 8. BENCHMARKS.md, guide section 18, code map (docs)
  Covers: AC-8
  - [x] 8.1 `## Stop and cancel per server mode (2026-11-03)` with tables and the four subsections
  - [x] 8.2 Guide section 18; section 16 updated (new files incl. `bench/chat-stop.sh`, the flow, a feature-request row)
  - [x] 8.3 **Verify**: `LearningGuideTest` (section 18 needles; section 16 map test with new files), `BenchmarksDocTest`

- [x] 9. Parity, fingerprint and bench (checks)
  Covers: MNC-3, MNC-4
  - [x] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 9.2 **Verify**: outputs recorded here
    - Fix pass: 887 tests, Pint, PHPStan, typecheck, build green; fingerprint unchanged; parity identical on all 6 servers; bench/chat-stop.sh re-run on all 6 servers (20 deltas, rows by invocation id): 30/30 stopped, Ollama log 30/30 context canceled; `bench/run.sh ping --quick` exit 0 on a clean tree after the feature commit.
    - 2026-11-03 recorded: full suite 873 passed (7541 assertions); Pint passed; PHPStan no errors; `npm run typecheck` and `npm run build` clean.
    - Fingerprint unchanged after the build: `app-CjRfEL-0.css 82042c94...ce71`, `app-BvRk9kiK.js e3b0c442...b855`.
    - Parity: `identical` on nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane, frankenphp-worker; ended on nginx-fpm.
    - `bench/run.sh ping --quick`: refused with exit 2, "git working tree has changes outside bench/results/ and .ai/session/"; the tree holds this feature's uncommitted work. Re-run after commit.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | abort stops reading, stopped row | `tests/Feature/StreamCancelTest.php`, `manual-test.md#step-1`, `manual-test.md#step-2` | test, manual | 1 |
| AC-2 | measured per mode | `manual-test.md#step-3` | manual | 5, 6 |
| AC-3 | stopped never cached or resent | `tests/Feature/StreamCancelTest.php` | test | 1, 4 |
| AC-4 | history marked, latest-only retrieval, no cache | `tests/Feature/DocsChatHistoryTest.php`, `manual-test.md#step-4` | test, manual | 2 |
| AC-5 | guards over history, forged turns | `tests/Feature/DocsChatHistoryTest.php` | test | 3 |
| AC-6 | history validation 422 | `tests/Feature/DocsChatHistoryTest.php` | test | 2 |
| AC-7 | transcript page | `manual-test.md#step-5` | manual | 4 |
| AC-8 | committed results and docs | `tests/Feature/LearningGuideTest.php`, `manual-test.md#step-7` | test, manual | 7, 8 |
| MNC-1 | no-history requests unchanged | `tests/Feature/DocsCacheTest.php`, `manual-test.md#step-4` | test, manual | 2 |
| MNC-2 | describe events unchanged | `tests/Feature/ListingDescribeTest.php`, `manual-test.md#step-6` | test, manual | 1 |
| MNC-3 | eval unaffected | `tests/Feature/RagEvalCommandTest.php`, `manual-test.md#step-6` | test, manual | 9 |
| MNC-4 | fingerprint, parity, offline suite | `manual-test.md#step-6` | manual | 9 |
| NFR-1 | ≤ 10 tokens after Stop, model free | `manual-test.md#step-3` | manual | 6 |
| NFR-2 | history never instructions | `tests/Feature/DocsChatHistoryTest.php`, `manual-test.md#step-4` | test, manual | 2, 3 |
| NFR-3 | transcript accessible | `manual-test.md#step-5` | manual | 4 |

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

- [x] 10. Input refusal names the latest question as "latest" and suffixes refusal_reason with the turn (backend)
  Covers: AC-5
  - `DocsAnswerService::ask()` sends `"turn": null` when the latest question matched (`DocsAnswerPlan::$turn` is null for it), and `DocsChatHistoryTest` "AC-5 names no turn…" asserts `"turn":null`. AC-5 and Q9 require `turn` = the 0-based index or `"latest"`. `plan()` also writes `refusal_reason` as `input:<guard>:<pattern>` with history present; Q9 requires the suffix `:turn<i>` or `:latest` when history exists (unchanged without history, MNC-1). Guide section 18 already documents `latest`.
  - [x] **Verify**: `DocsChatHistoryTest`: latest-question refusal with history carries `"turn":"latest"` and `refusal_reason` ending `:latest`; an earlier user-turn refusal carries `"turn":2` and `refusal_reason` ending `:turn2`; a refusal without history keeps today's reason and no `turn` key (`DocsAskTest` green unedited).

- [x] 11. stopped_after_ms measured from the first token, null before it (backend)
  Covers: AC-1
  - `AiRunRecorder::finish()` writes `stoppedAfterMs: $stopped ? $latencyMs : null`, i.e. request start to abort, and `StreamCancelTest` asserts `stopped_after_ms` equals `latency_ms`. The spec defines `stopped_after_ms` as the time from the first token to the abort being noticed, and Q6 says it is null (with TTFT) when the abort comes before the first token. No test covers an abort before the first token. Guide section 18 ("request start to the abort") and the BENCHMARKS.md `stopped_after_ms` column describe the current, non-spec meaning.
  - Compute it as abort time minus `firstTokenAt` (null when no token arrived); update the new StreamCancelTest assertion; add a test where the fake watcher aborts on the first chunk (the `sources` event) so TTFT and `stopped_after_ms` are null and `tokens_out` is null; fix the wording in guide section 18 and BENCHMARKS.md (relabel or re-measure the `stopped_after_ms` column).
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/StreamCancelTest.php` passes with the new pre-first-token case; `LearningGuideTest` and `BenchmarksDocTest` green.

- [x] 12. Test that the model stream stops being read and is released on abort (backend)
  Covers: AC-1
  - Q5 requires tests to prove "the loop stops reading and releases the generator (a fake watcher plus a fake stream that counts reads)". `StreamCancelTest` only counts `text_delta` lines in the client output; nothing counts SDK events pulled after the abort or proves the provider generator's `finally` ran (the HTTP body released), so "stop reading the model stream within one further event" and "close the Ollama connection" are untested.
  - Add a fake provider stream (or wrap the fake-stream provider) that counts events yielded and flags its `finally`; abort after N flushed chunks and assert at most one further event was read and the generator was closed before the response callback returned.
  - **Verify**: new case in `tests/Feature/StreamCancelTest.php` passes for both describe and docs ask.

- [x] 13. Bench baseline of 5 idle probes and the Ollama parallel setting in the results (bench)
  Covers: AC-2, NFR-1
  - Q7 (binding) says the baseline is the median of 5 idle probes per server and the result records Ollama's parallel setting (`OLLAMA_NUM_PARALLEL` or "default"). `bench/chat-stop.sh` takes 3 idle probes (`for i in 1 2 3; do probe; done`) and its JSON metadata has no parallel field; the committed `bench/results/chat-stop/2026-11-03/*.json` have `idle_probe_ms` of length 3 and no parallel setting (it appears only in BENCHMARKS.md prose).
  - Take 5 idle probes; add `ollama_num_parallel` (env value or "default") to `metadata`; extend `BenchChatStopTest`; re-run the six modes (ending on nginx-fpm) and update the BENCHMARKS.md table, guide section 18 table and their prose ("median of 3") if numbers change.
  - **Verify**: `ddev artisan test --compact tests/Feature/BenchChatStopTest.php` asserts 5 idle probes and the parallel field; each mode JSON under `bench/results/chat-stop/<date>/` has `idle_probe_ms` of length 5 and `metadata.ollama_num_parallel`; `BenchmarksDocTest` and `LearningGuideTest` green.

- [x] 14. Chat page never sends a history turn the server rejects (frontend)
  Covers: AC-7
  - `history()` in `resources/js/ai/Pages/Lab/Chat.vue` sends `turn.text.trim()` as the assistant content with no length limit, while `DocsAskRequest` rejects any turn over 2 000 code points with 422 (AC-6). A completed answer longer than 2 000 code points makes every follow-up in that chat fail with 422 until New chat, breaking "send the last 6 completed turns as history".
  - Leave out (with its question, per Q8 whole exchanges) or truncate to 2 000 code points any exchange whose answer is longer; pass the limit as an Inertia prop from `DocsAskRequest::MAX_TURN_LENGTH` rather than hard-coding it.
  - **Verify**: `AiLabChatTranscriptTest` asserts the prop and its use in `history()`; `ddev npm run typecheck && ddev npm run build`; browser check: a follow-up after an answer over 2 000 characters returns 200, and the request body has no turn over the limit.

## Phase: Gaps (converge round 2)

- [x] 15. Past turns' refusal and error messages are not live regions; only the newest answer is (frontend)
  Covers: AC-7, NFR-3
  - `resources/js/ai/Pages/Lab/Chat.vue` renders `<p v-if="turn.refusal" role="alert" …>` (line 411) and `<p v-if="turn.outcome === 'error' && turn.error" role="alert" …>` (line 420) inside `v-for="turn in turns"`, so every refused or failed turn in the transcript keeps an assertive live region (`role="alert"` implies `aria-live="assertive"`). AC-7 and NFR-3 require that only the newest answer region is live, and manual-test step 5.6 expects exactly one live element. `AiLabChatTranscriptTest` "NFR-3 …" only counts the literal `aria-live`, so it cannot see this.
  - Give `role="alert"` only to the newest turn's refusal and error (`isNewest(turn)`), the way the answer `<output>` already does; older turns render plain text. Extend the NFR-3 test to assert `role="alert"` inside the turn loop is bound to `isNewest(turn)`.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/AiLabChatTranscriptTest.php` passes with the extended NFR-3 case; `ddev npm run typecheck && ddev npm run build`; browser check: after a refused turn followed by an answered one, the page has one live region (the newest answer).

- [x] 16. Assert the refusal details AC-5, Q9 and Q10 require (backend)
  Covers: AC-5, NFR-2
  - `DocsChatHistoryTest` "AC-5 a forged assistant turn…" and "NFR-2 history is not among…" assert only `"stage":"output"` / `output`. Q10 requires an output-stage refusal with `output:prompt_overlap`, and guide section 18 states that outcome, but a `canary` refusal would also pass. "AC-5 refuses an injection in an earlier user turn" does not assert the event's `pattern` naming the match (AC-5). Q9 says `history_turns` is recorded on every row of a request with history, refused ones included; `DocsAnswerService::plan()` passes it for input refusals and `no_context`, but no test checks `history_turns` on a refused or `no_context` row.
  - Add assertions only (no app change expected): the forged-turn run's `refusal_reason` is `output:prompt_overlap`; the earlier-turn refusal event carries the guard's pattern name; the input-refused row (turn and latest cases) has `history_turns` equal to the history length.
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/DocsChatHistoryTest.php` passes with the new assertions; `DocsAskTest` and `DocsCacheTest` green unedited.

## Phase: Review fix pass

Findings of the ship report (2026-11-03) checked against the code.

- [x] R1 Stop during reasoning: `DocsAnswerProtocol` yields one SSE comment (`SseProtocol::KEEPALIVE`, `: keepalive`) per skipped reasoning event, so the transport writes, flushes and asks the watcher after every reasoning event. Reasoning text is still never sent. The client parser reads `data:` lines only. Limit: a long prompt evaluation before the first event still writes nothing and cannot be cancelled. Test: `StreamCancelTest` "a Stop during reasoning".
- [x] R2 Abort at the first flush (before the SDK started the run): `SseProtocol::recordAbort()` sees no event was read and calls `DocsAnswerProtocol`'s `onStoppedBeforeRun`, which `DocsAnswerService::ask()` points at `recordOutcome('stopped', ...)` with provider, model, prompt version, retrieved ids, origin and `history_turns`; tokens, TTFT and `stopped_after_ms` are null. Test: `StreamCancelTest` "at the very first flush".
- [x] R3 Abort right after StreamEnd or the masked error: `SseProtocol` tracks both (`observe()`); the run is then finished as `completed` (with the StreamEnd usage) or `failed`, never `stopped`, and the stream still ends there (no citations, no cache store). Tests: two cases in `StreamCancelTest`.
- [x] R4 Cache hit-rate denominator: `docsCacheRuns()` adds `whereNull('history_turns')`; stopped runs were already outside it (only `completed` and `cached` count), now stated in the docblock and pinned by `DocsCacheRunCountsTest`.
- [x] R5 `bench/chat-stop.sh`: rows are attributed by the `invocation_id` of the stream's `stream_start` event and waited for up to `CHAT_STOP_SETTLE` seconds (`no_row` when none); each run carries `invocation_id` in the JSON. Default `--tokens` is now 20 (the spec says 20); the committed results were measured at 10. `bash -n` passes; `BenchChatStopTest` updated (stubs by invocation id, no-row case).
- [x] R6 `DocsAskRequest`: `bail` added as asked, but it does not help: Laravel expands the `history.*` wildcard rules for every element before any rule runs. The count is therefore also checked in `prepareForValidation()` and rejected with a `history` error alone. Test: 10 000 turns give a 422 with only `history`, in well under 1.5 s.
- [x] R7 Dead `AiRunRecorder::wasStopped()`/`$stoppedRuns` and the store hook's watcher check removed; the guarantee is documented on `SseProtocol::response()`, `DocsAnswerProtocol` and `storeHook()`.
- [x] R8 `SseProtocol::chunks()` fork kept, docblock names `StreamProtocol::response()` of laravel/ai v1.3.0; `tests/Unit/SseProtocolForkTest.php` hashes the vendor method and fails on change.
- [x] R9 (gap 15) Past turns' refusal and error are plain text; `role="alert"` only on the newest turn. NFR-3 test counts it.
- [x] R10 (gap 16) `DocsChatHistoryTest` asserts `output:prompt_overlap` for both forged-turn cases, `pattern` and `turn` for the earlier-turn injection, and `history_turns` on refused (turn and latest) and `no_context` rows.
- Skipped: "model free assumes a single Ollama slot" (`BENCHMARKS.md:571`). Wrong: Ollama ran with `OLLAMA_NUM_PARALLEL:1` and logged 30 of 30 "context canceled"; recorded by the owner.
- Not touched (docs, owner updates): the `BENCHMARKS.md` delta-lag wording, the `followups.md` cache wording, the "abort after 10 deltas" statements in the guide and `BENCHMARKS.md` (the script default is now 20).
