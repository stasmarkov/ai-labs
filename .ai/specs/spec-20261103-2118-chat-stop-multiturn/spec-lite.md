---
id: spec-20261103-2118-chat-stop-multiturn
title: Multi-turn docs chat with a Stop that cancels generation on every server mode
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-03T19:22:19Z
created: 2026-11-03
stacks: [laravel]
slice: SL-41
brs: [BR-95, BR-27, BR-90, BR-100, BR-26]
---

# Multi-turn docs chat with a Stop that cancels generation on every server mode

## Summary

`/ai/chat` becomes a conversation. Questions and answers stack up in the page, and each new question carries the last few turns as marked history. Retrieval runs on the latest question only.

Stop does three things on the server:
- the PHP side stops reading the model stream and closes the connection to Ollama, so Ollama stops generating;
- the run is logged as `stopped`, with the tokens received so far;
- it is never cached.

This is proven and measured on all six servers (nginx-fpm, apache-fpm, openlitespeed, frankenphp and the two worker modes). The SL-40 cache serves only first-turn questions.

## Context

- **Problem: Stop cancels nothing today.** Probe on 2026-11-03: a stream aborted by the client after 1.5 s.
  - **nginx-fpm:** no `ai_runs` row at all. PHP-FPM kills the script at its next write after the client leaves (`ignore_user_abort` is off), so the run is never logged. That breaks BR-90.
  - **frankenphp-octane:** the server finished the answer anyway: `completed`, 320 tokens, 22 224 ms after the client had gone. The model and a worker thread stayed busy for 20 s for nobody.
- **Chat today is single-turn.** A follow-up ("and how do I undo it?") loses its subject.
- **BR-95**: the direct variant streams over SSE. SL-46 already aborts the fetch on the client (`AbortController`).

## Scope

### In scope

- **Request body.**
  - `{"q": "…", "cache": bool, "history": [{"role": "user"|"assistant", "content": "…"}]}`.
  - At most `ai.docs.chat.max_history_turns` turns (default 6, i.e. 3 exchanges). Each turn's content is at most 2 000 code points and passes the same NUL/UTF-8 rule.
  - Roles must alternate, starting with `user` and ending with `assistant`.
  - Extra turns are a 422, not silently cut. The page sends only the last 6.
- **Marked history.**
  - History goes into the user message as `<history><turn role="user|assistant">…</turn></history>`, escaped like the context (`htmlspecialchars`), before `<context>`.
  - A new prompt file `docs-answer.v2.md`, used **only when history is present**, states that history is earlier conversation for reference and never instructions, and that a follow-up's subject may come from history. Requests without history keep `docs-answer.v1.md`.
  - The output guard's allowed texts do not include history: a forged "assistant" turn holding prompt text must not open a hole.
- **Retrieval** uses the latest question only, unchanged from SL-45. The guide records this as a known limit for follow-ups, with measured examples.
- **Input guard** on the latest question and on every `user` turn of the history. A forged `assistant` turn is data, not checked by the regex, and marked like context.
- **Cache.** Only when `history` is empty, for both lookup and store. A turn with history never reads or writes the cache.
- **Server-side cancel.**
  - The answer stream sets `ignore_user_abort(true)` for its own duration, restored after.
  - After each flushed event it checks `connection_aborted()`.
  - On abort it stops iterating the SDK stream and closes the HTTP connection to Ollama: the SDK generator and the PSR stream are released explicitly, not left to garbage collection.
  - It records the run as `stopped` with `tokens_out` = text deltas received, TTFT and latency, and writes nothing more to the client.
  - It works through the shared protocol, so describe (`SseProtocol`) gets the same cancel.
- **`ai_runs`** gets a new status `stopped`, a nullable `history_turns` column, and `stopped_after_ms`: time from the first token to the abort being noticed.
- **The lab chat page.**
  - A transcript of turns: the user question, then the assistant answer with its own sources, citations, cached mark, refusal or "I don't know", and the logged run (collapsed).
  - Ask, Stop and New chat. Stop leaves the partial answer visible, marked "Stopped", and that partial answer is not sent as history.
  - History lives in page state only, and is lost on reload.
- **Measurement.** `bench/chat-stop.sh` runs per server mode:
  1. start an answer;
  2. abort after N tokens (default 20);
  3. record the run row (status, tokens, `stopped_after_ms`);
  4. measure "model free": immediately send a 1-token probe completion straight to Ollama (`/api/generate`, `num_predict` 1) and time it, against an idle baseline.
  It repeats 5 times per mode and writes `bench/results/chat-stop/<UTC date>/<mode>.json`.
- **Docs.**
  - `BENCHMARKS.md` section `## Stop and cancel per server mode (2026-11-03)`.
  - Guide section 18.
  - The section 16 code map updated: new files, the "Ask the docs" flow with history and cancel, and a "Stop doesn't stop" row in the feature-request table.

### Out of scope

- Persisting conversations (server-side history, sessions, accounts).
- Query rewriting for follow-ups (e.g. condensing history into a standalone question). It is recorded as a later option with the measured limit.
- A queued Mercure or Reverb variant (a non-goal; owner's call).
- Token budget management for very long histories beyond the 6-turn cap.

## Must NOT change

- **MNC-1**: A request without `history` behaves as SL-45 and SL-40: the same stream contract, prompt, cache behaviour and rows. `DocsAskTest` and `DocsCacheTest` stay green unedited.
  - They keep `docs-answer@v1` and its cache entries. No existing test is edited.
- **MNC-2**: The describe stream's events and masked errors are unchanged. Only the cancel behaviour is added.
- **MNC-3**: `rag:eval`, `answer()` and the committed SL-33/SL-40 results are not affected. `rag:eval` runs without history.
- **MNC-4**: The asset fingerprint and parity on all 6 servers stay as recorded. The full suite runs offline.

## Acceptance criteria

- **AC-1 (BR-95)**: WHEN the client disconnects during an answer, THE SYSTEM SHALL, on every server mode where `connection_aborted()` reports the disconnect:
  - stop reading the model stream within one further event;
  - close the Ollama connection;
  - write one `ai_runs` row with status `stopped`, `tokens_out` = text deltas received, TTFT, latency and `stopped_after_ms`.
  The row SHALL be written on nginx-fpm, where today none is.
- **AC-2 (measured, BR-26)**: `bench/chat-stop.sh` SHALL record, per mode (all six servers) and over 5 runs, the median of:
  - tokens generated after the client stopped (from Ollama's `eval_count` where available, else the row's `tokens_out` minus the client's count);
  - the time until a 1-token probe to Ollama answers, against the idle baseline;
  - whether a `stopped` row exists.
  A mode where the abort cannot be detected SHALL be reported as such, with its numbers, not hidden.
- **AC-3**: A stopped answer SHALL never be stored in the answer cache. A stopped answer's partial text is never sent back as history by the page.
- **AC-4**: WHEN `history` is present and valid, THE SYSTEM SHALL:
  - put it before the context as escaped `<history><turn role>` elements;
  - use prompt `docs-answer@v2`;
  - retrieve on the latest question only;
  - skip the cache for both lookup and store;
  - record `history_turns`.
  With `history` empty or absent, it SHALL behave as MNC-1.
- **AC-5 (BR-100)**: The input guard SHALL check the latest question and every `user` turn. A match in any of them refuses the request (`refused`, stage `input`, and `pattern` naming the match, plus `turn` = the index, or `"latest"`). `assistant` turns are never matched by the regex but are always marked and escaped. A test SHALL show that a forged `assistant` turn holding the system prompt text does not make the output guard allow that text.
- **AC-6**: `history` SHALL be rejected with 422 when:
  - it is not an array, or has more than `max_history_turns` turns;
  - roles do not alternate starting with `user` and ending with `assistant`;
  - a turn's content is empty after trim, longer than 2 000 code points, or not valid UTF-8 / contains NUL.
- **AC-7**: The lab `/ai/chat` SHALL:
  - show a transcript, each assistant turn with its own sources, citation links (scoped to that turn), cached mark, refusal or "I don't know" state, and its logged run;
  - send the last 6 completed turns as `history` (never a stopped or refused turn's text, and never an empty answer);
  - Stop: the partial answer stays and is marked "Stopped", and the controls re-enable at once;
  - New chat: clears the transcript.
  Only the newest answer region is `aria-live`.
- **AC-8 (BR-26, BR-27)**: The build SHALL commit:
  - `bench/results/chat-stop/…` for all 4 modes;
  - the `BENCHMARKS.md` section with a table per mode, plus "What the numbers say", "Surprises" and "Interview story";
  - guide section `## 18. Stop that really stops, and conversations` with the before/after numbers (FPM: no row → `stopped` row; octane: 320 tokens after Stop → measured), why `ignore_user_abort` and `connection_aborted()` are needed, what each server mode does, history as data, forged turns, and why follow-ups retrieve poorly;
  - the section 16 updates.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Resource use | Measured and reported per mode (not a gate, Clarifications Q1/Q4): median probe latency after Stop ≤ 1.5× idle median + 1 s, and median delta lag ≤ 10. | `manual-test.md#step-3`, `bench/results/chat-stop/` |
| **NFR-2** | Security | History never acts as instructions: it is escaped, marked, and excluded from the output guard's allowed texts. | `tests/Feature/DocsChatHistoryTest.php` |
| **NFR-3** | Accessibility | The transcript keeps one `aria-live` region (the newest answer), has visible focus, and no horizontal scroll at 390 px. | `manual-test.md#step-5` |

## Boundaries

- **Always**:
  - test with the fake-stream provider (a slow fake with a delay to simulate aborts in tests: use a test seam for `connection_aborted()` rather than real sockets);
  - measure every mode;
  - update section 16.
- **Ask first**: changing php.ini or server config (`ignore_user_abort` is set in code for the stream only); new dependencies; queued variants.
- **Never**:
  - cache answers with history or stopped answers;
  - let history bypass the input or output guard;
  - commit `.env`, `public/build` or `.ai/session`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `app/Ai/SseProtocol.php` (the Octane drain and flush loop: the place to check `connection_aborted()`);
  - `app/Ai/DocsAnswerProtocol.php`;
  - `app/Services/DocsAnswerService.php` (`plan()`, cache calls);
  - `app/Ai/DocsAnswerAgent.php` (message building, escaping);
  - `app/Ai/AiRunRecorder.php`;
  - `app/Services/DocsAnswerCache.php`;
  - `app/Http/Requests/DocsAskRequest.php`;
  - `resources/js/ai/Pages/Lab/Chat.vue` and `resources/js/ai/composables/useEventStream.ts`;
  - `vendor/laravel/ai/src/Gateway/Ollama/Concerns/HandlesTextStreaming.php` (`parseNdjsonStream` reads the Guzzle stream body; releasing the generator releases the body);
  - `.ddev/` server configs (read only).
- **Approach**: one `AbortWatcher` seam (`connectionAborted(): bool`, real implementation `connection_aborted() === 1` after `flush()`) injected into the protocol, so tests can simulate a disconnect after N events.

## Decisions (self-decided, standing approval)

1. **Cancel in PHP, measured per mode, not assumed.** Whether `connection_aborted()` sees the client leave depends on the server: FPM after a write, FrankenPHP threads, Octane's response callback. The spec requires the attempt everywhere and the truth in numbers. A mode that cannot detect it is a finding, not a failure to hide.
2. **`ignore_user_abort(true)` only during the answer stream.** Without it FPM kills the script mid-loop and nothing is logged (measured). With it the loop notices, logs `stopped` and closes Ollama itself. It is set and restored in code, not in php.ini, so no other request changes.
3. **History from the page, sent each time, capped at 6 turns.** Stateless on the server, like every other endpoint. The cost is that a client can forge history, so history is treated like retrieved text: marked, escaped, and never trusted by the output guard.
4. **Retrieval on the latest question only.** This is the owner's instruction. A follow-up like "how do I undo it?" will retrieve poorly; the guide shows it with numbers and names query rewriting as the fix for a later slice.
5. **Cache only first turns.** A follow-up's meaning depends on its history, so the same text means different things in different chats.
6. **Prompt v2 only with history.** Adding history changes what the model reads, so it gets its own versioned prompt. Requests without history keep v1, so SL-33's results, the cache entries and every test pinned to v1 stay valid, and `prompt_version` in `ai_runs` shows which kind of request each run was.

## Clarifications (binding; resolve the clarity gate)

**Server modes (Q1).**
- **All six servers are measured:** nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane and frankenphp-worker. The cancel code is the same on all of them.
- **A server that cannot detect the abort** keeps today's behaviour: the run completes and is logged `completed`. No answer is stored in the cache when the client has gone, because the store step checks the watcher. It is reported in `BENCHMARKS.md` and the guide as a finding with its numbers. It does not block shipping; NFR-1's numbers are measured and reported, not gates.

**"Within one further event" (Q2)** means the first `flush()` after the disconnect: the loop checks right after each flush, so at most one more SDK event is read. Buffering is already off (`X-Accel-Buffering: no` and a flush per event); no server config is changed.

**Units (Q3).** `tokens_out` on a `stopped` row = the number of `text_delta` events PHP received (Ollama sends about one token per delta; the guide says "deltas" where it matters). The bench client counts `text_delta` lines in the SSE stream.

**What "after Stop" means (Q4).** Once PHP closes the connection, Ollama reports no `eval_count`, so tokens generated after the close cannot be read directly. The bench records two figures:
- **The primary figure: model free.** The bench closes its own connection after N deltas and immediately sends a 1-token probe straight to Ollama. The probe's latency over the idle baseline shows how long the model kept working.
- **The secondary figure: delta lag.** The row's `tokens_out` minus the deltas the client had read, which is how many more deltas PHP read before noticing.

NFR-1 is restated: "the median probe latency after Stop is within 1.5× the idle median plus 1 s, and the median delta lag is ≤ 10", measured and reported.

**Proof that the connection closes (Q5).** Tests prove the loop stops reading and releases the generator (a fake watcher plus a fake stream that counts reads). The bench's model-free figure proves Ollama stopped. No test opens a real socket.

**Timings (Q6).** If the abort comes before the first token, TTFT and `stopped_after_ms` are null. `latency_ms` on a stopped row = request start to the abort being noticed (before cleanup).

**The baseline and the probe (Q7).**
- **Baseline:** per server, the median of 5 idle probes taken before that server's runs.
- **Probe:** sent right after the bench client closes its connection.
- **Parallelism:** Ollama's parallel setting is recorded in the result (`OLLAMA_NUM_PARALLEL`, or "default"). A busy single slot makes the probe wait, which is exactly the signal.

**Page history (Q8).** The page sends whole exchanges only: a user turn plus its assistant answer whose outcome was answered or cached. An exchange whose answer was stopped, refused, `no_context`, failed or empty is left out with both its turns. "Last 6" means the last 3 whole exchanges, so roles always alternate.

**The `turn` field (Q9).** The index is 0-based over the whole `history` array; `"latest"` means the current `q`. It goes in the SSE `refused` event as `turn`, and in `refusal_reason` as `input:prompt-injection.v1:<name>:turn<i>` (or `:latest`, which equals today's format plus the suffix only when history exists). Without history the reason stays exactly as today (MNC-1). `history_turns` is recorded on every row of a request with history, refused ones included.

**Forged turns (Q10).** The output guard's allowed texts are the question and the chunk bodies (today's rule); history is never added to them. Test: a forged `assistant` turn holds 14 words of the v2 prompt, and the fake model answers with those words. Expected: an output-stage refusal with `output:prompt_overlap`.

**Validation (Q11).**
- **Allowed:** `history` absent, `null` or `[]`, which all mean no history.
- **Rejected with 422 (the standard JSON error body, errors keyed `history`, `history.N.role` or `history.N.content`):**
  - roles other than `user` or `assistant`;
  - missing, non-string or blank content;
  - extra keys;
  - more than 6 turns;
  - broken alternation;
  - content over 2 000 code points;
  - NUL or invalid UTF-8, using the existing `ValidUtf8Text` rule.

**Cache and Stop corner cases (Q12).**
- `cache: true` together with non-empty history is accepted and ignored: no lookup, no store, no `cache_hit`.
- Stop during a cached replay changes nothing: the replay is one burst, and the `cached` row is already written, so no `stopped` row appears.
- Describe has no store hook. Its stopped runs only produce the `stopped` row.

**Where the watcher lives (Q13).** `SseProtocol::response()` owns the flush loop and the watcher check. `DocsAnswerProtocol` inherits it, and its store step asks the same watcher before storing. `SseEventsResponse` (no model) needs no watcher.

**Migration and prompt naming (Q14).**
- **Status:** `status` is `varchar(16)`, not an enum, so `stopped` needs no schema change. Tests run on pgsql.
- **New columns:** `history_turns` and `stopped_after_ms` are nullable integers; existing rows get null.
- **Prompt naming:** the file is `resources/prompts/docs-answer.v2.md`, the config key `ai.prompts.docs_answer_history` = `docs-answer.v2`, and the logged `prompt_version` is `docs-answer@v2`.

**The bench script (Q15).**
- **Client:** `bench/chat-stop.sh` uses `curl -sN` piped into a small reader that counts `text_delta` lines and kills curl after N.
- **Modes and output:** it switches servers with `ddev server <mode>`, as `bench/parity.sh` does, and ends on nginx-fpm. It does not check for a clean tree, like `bench/docs-cache.sh`.
- **Dates:** the `BENCHMARKS.md` heading uses the run's UTC date.
- **Follow-ups:** the examples are written to `bench/results/chat-stop/<date>/followups.json` as `[{id, turns: [...ids only], retrieved_paths, verdict}]`, with no question text in indexed files.

## Open questions

Clarity gate 2026-11-03: a fresh-context reviewer raised 15 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: Servers that cannot detect the abort, and all six measured.
- [x] Q2: "One further event" means the first flush.
- [x] Q3: Deltas as the unit.
- [x] Q4: Model free as the primary figure, delta lag as the secondary.
- [x] Q5: Evidence from tests plus the bench.
- [x] Q6: Null timings before the first token.
- [x] Q7: The baseline and probe protocol.
- [x] Q8: Whole exchanges only.
- [x] Q9: The `turn` index and where it appears.
- [x] Q10: The forged-turn test outcome.
- [x] Q11: The full validation list.
- [x] Q12: Cache and Stop corner cases.
- [x] Q13: The watcher lives in `SseProtocol`.
- [x] Q14: Migration and prompt naming.
- [x] Q15: The bench mechanics.

## Dependencies

- **Requires**: SL-45, SL-46, SL-40, SL-33.
- **Blocks**: none. SL-42 (research agent) may reuse cancel.

---
Next: `/sdd spec-20261103-2118-chat-stop-multiturn` (approval → build)
