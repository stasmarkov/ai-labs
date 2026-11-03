# Ship report: spec-20261103-2118-chat-stop-multiturn (SL-41)

## Summary

SL-41 makes Stop in the docs chat cancel the work on the server, and turns the lab chat into a multi-turn transcript that sends the completed turns as history. The scenario originally reported now works end to end on nginx-fpm with a real browser. On Stop the server writes a `stopped` row (before this change it wrote no row at all), and follow-up questions carry history using prompt `docs-answer@v2`. All four gates pass with 878 tests.

The work is not ready to ship. 11 of 16 tasks are done (4, 9 and 14 are still open). Two converge gaps were never built (15, 16). Review confirmed two major correctness findings: Stop goes unnoticed while the model is thinking or before the first token, and the cache guard relies on dead state. Everything is uncommitted WIP on `master`.

## Stack & repos

| Repo | Role | Stack | Tracked | Notes |
|---|---|---|---|---|
| `.` | backend (+ Inertia/Vue lab pages) | laravel | yes | Uncommitted WIP on `master`: 21 modified and 23 untracked files. No commit and no branch yet. |

- Repo warnings: none.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none (present: `plugin:sdd-kit-laravel@sdd-kit`).

## Spec

- id: `spec-20261103-2118-chat-stop-multiturn`
- folder: `.ai/specs/spec-20261103-2118-chat-stop-multiturn`

## Investigation highlights

The investigation step reported no files, risks or prior implementation. The points below come from the build and review instead.

- **Key files:** `app/Ai/SseProtocol.php` (the abort-aware drain loop), `app/Ai/AbortWatcher.php` and `app/Ai/ConnectionAbortWatcher.php` (new), `app/Ai/AiRunRecorder.php` (the `stopped` outcome and `stopped_after_ms`), `app/Ai/DocsAnswerProtocol.php`, `app/Ai/DocsAnswerAgent.php` (the escaped `<history>` block), `app/Services/DocsAnswerService.php`, `app/Http/Requests/DocsAskRequest.php` (history validation), `resources/js/ai/Pages/Lab/Chat.vue` (the transcript), `resources/prompts/docs-answer.v2.md`, `bench/chat-stop.sh`, and the migration `2026_11_03_210000_add_stop_columns_to_ai_runs_table.php`.
- **Risks:**
  - The abort is only seen after a write and flush (`connection_aborted()`). Any phase that writes nothing (reasoning, prompt evaluation) cannot be cancelled.
  - `SseProtocol::chunks()` is a copy of the SDK's `StreamProtocol::response()` body, so it can drift from the SDK.
  - The guarantees about the single Ollama slot depend on `OLLAMA_NUM_PARALLEL`.
- **Already shipped and reused:** the SL-40 answer cache (it is now bypassed whenever history is present), the SL-33 eval harness (`answer()` keeps v1 and passes no history), and the existing `docs-answer@v1` prompt (kept for requests without history).

## Tasks completed

**11 of 16.** Still open:

- **4. Transcript chat page (frontend).** The checkboxes in `tasks.md` are open. However, `Chat.vue` holds the transcript, and acceptance exercised it in a browser (see Acceptance). The 1440 px browser check from 4.2 is not recorded.
- **9. Parity, fingerprint and bench.** 9.1 and 9.2 are open. Acceptance re-ran the fingerprint (it matches) and parity on nginx-fpm only. The other five servers and `bench/run.sh ping --quick` on a clean tree were not recorded.
- **14. Chat page never sends a history turn the server rejects.** The code is in place: `history()` leaves out any exchange whose answer is longer than `maxTurnLength` (`Chat.vue:291`), and the prop is passed from `DocsAskRequest::MAX_TURN_LENGTH`. The build still marks the task incomplete, because the browser check (a follow-up after an answer over 2000 characters) did not run.

## Converge

- **Rounds run:** 2.
- **Round 1 gaps:** tasks 10–14 were appended:
  - 10: the `"latest"` turn and the `:turnN`/`:latest` refusal suffix
  - 11: `stopped_after_ms` measured from the first token
  - 12: a test that counts stream reads and checks the generator is closed
  - 13: 5 idle probes and `ollama_num_parallel` in the bench output
  - 14: the history length cap in the UI

  Tasks 10–13 were built; 14 is described above.
- **Round 2 gaps:** tasks 15–16 were appended.
- **Unbuilt gaps:**
  - **15.** Past turns' refusal and error messages stay live regions. `Chat.vue:411` and `Chat.vue:420` use `role="alert"` inside `v-for`, which breaks AC-7/NFR-3's rule of "one live region".
  - **16.** The tests do not assert the refusal details that AC-5, Q9 and Q10 require: `output:prompt_overlap`, the pattern name, and `history_turns` on refused and no_context rows.

## Quality gates (repo `.`)

| Gate | Status | Details |
|---|---|---|
| Pint | pass | 359 files (ddev exec) |
| PHPStan | pass | No errors |
| PHPUnit | pass | 878 passed, 7577 assertions, on two clean reruns. The first run, made in parallel with PHPStan, had 4 failures in `DocsSearchTest`: vector-mode ordering and count, plus "Undefined array key 2" in the hybrid test. These did not reproduce, but the test may be flaky. |
| Debug scan | pass | No `dd(`/`dump(`/`ray(` in `app/`, `routes/` or `database/` |
| Contract sync | n/a | Single repo |

## Visual tests

None ran as a separate stage. Acceptance covered the visual checks in a Playwright browser at 390x844: no horizontal scroll, and one `aria-live` region. Focus visibility was not checked, and neither was the 1440 px layout.

## Acceptance

**Did the original scenario resolve end to end?** Yes, on nginx-fpm. Live checks:

- **curl abort:** the client read 10 deltas and was killed. The server wrote row 5063 as `stopped`, with `tokens_out` 11 and `stopped_after_ms` 148. An Ollama probe right after answered in 85 ms.
- **Browser journey on `/ai/chat`:** ask a question, ask a follow-up (it carried history, and row 5071 is `docs-answer@v2` with `history_turns` 2), press Stop mid-answer, then ask again.
  - The partial answer stayed on screen marked "Stopped after 11 tokens", and the controls re-enabled within 50 ms.
  - Row 5073 is `stopped` with `tokens_out` 12.
  - The next request's history left out the stopped exchange.
  - Citation anchors resolved, and New chat cleared the transcript.
- **Committed bench:** all six server modes show 5 of 5 `stopped` rows and a median of 1–4 tokens after Stop.
- **Broken hops:** none.

**Caveats:**

- Parity was re-captured on nginx-fpm only. The other five modes rely on the committed chat-stop bench.
- `bench/chat-stop.sh` aborts after 10 deltas by default; the spec says 20.
- `BenchmarksDocTest` and `LearningGuideTest` gained new cases (additions only). This is in tension with MNC-1's "no existing test edited".
- Raw invalid UTF-8 in the JSON body comes back as a 422 on `q`, because the body fails to parse.
- The live path tested was thinking-off only (see the major finding below).

**E2E regression test:** none was written (E2E: null). The `laravel` stack declares no browser e2e runner. Coverage is through the feature tests `StreamCancelTest`, `DocsChatHistoryTest`, `AiLabChatTranscriptTest`, `BenchChatStopTest` and `ChatFollowupNoteTest`, which cover AC-1 to AC-8, NFR-2 and NFR-3, and all pass.

## Confirmed review findings

### Major

- **`app/Ai/DocsAnswerProtocol.php:57`: Stop is not detected during reasoning or before the first token.** `parts()` passes over the `ReasoningStart`, `ReasoningDelta` and `ReasoningEnd` events without yielding anything, so nothing is written and the watcher is never asked. With `AI_THINK=true`, or during a long prompt evaluation (which history makes longer), Ollama keeps generating for a client that has already left. This breaks AC-1/Q2. Two reviewers reported this separately (correctness and performance).
  Fix: yield an SSE comment (`": keepalive\n\n"`) for each skipped event, or on a timer, so a write happens and `watcher->aborted()` gets checked.
- **`app/Ai/AiRunRecorder.php:50`: `wasStopped()` and `$stoppedRuns` are dead state.** `stopped()` is always followed by `break`, which destroys the generator before `onCompleted` or the store hook can run. That means `DocsAnswerService.php:346` `|| $recorder->wasStopped(...)` is always false, and the dead code hides the real reason the cache stays safe.
  Fix: remove `$stoppedRuns`, `wasStopped()` and that term. Then add a comment or test that pins "break destroys the generator, so a stopped run never stores".

### Minor

- **`app/Ai/SseProtocol.php:66`: an abort at the first flush (the `sources` event) writes no `ai_runs` row.** The run has not started yet, so `stopped()` returns without doing anything. This brings back the nginx-fpm "no row" symptom in a narrow window.
  Fix: record a `stopped` (pre-model) outcome when no run exists yet.
- **`app/Ai/SseProtocol.php:64`: a finished or failed run can be logged as `stopped`** when the abort is seen right after the StreamEnd or Error chunk is flushed.
  Fix: track whether StreamEnd or Error was seen, and finish as completed or failed in that case.
- **`app/Repositories/EloquentAiRunRepository.php:55`: the cache hit-rate denominator counts follow-up runs**, which can never hit the cache.
  Fix: add `whereNull('history_turns')`, and decide whether stopped first-turn runs count as misses.
- **`app/Http/Requests/DocsAskRequest.php:44`: an oversized `history` array is fully validated per element** before `max:6` rejects it.
  Fix: put `bail` first in the rules, or reject on count in `prepareForValidation`.
- **`app/Ai/SseProtocol.php:82`: `chunks()` copies the SDK's `StreamProtocol::response()` body line for line** and writes to the parent's protected state.
  Fix: drain `parent::response()->getCallback()` and check for the abort in that loop, or pin the copy with a test against the SDK version.
- **`app/Services/DocsAnswerService.php:346`: the store hook queries the connection again**, which repeats the transport's own check. The comment at `DocsAnswerProtocol.php:96` is out of date.
  Fix: pass the protocol's abort state into the hook, or drop the `aborted:` input.
- **`bench/chat-stop.sh:77`: `last_row()` attributes rows to runs by "latest id".** In a mode that does not detect the abort, a late `completed` row gets credited to the next run.
  Fix: correlate rows by `invocation_id`, or wait until no DocsAnswerAgent run is open.
- **`BENCHMARKS.md:544`: the delta-lag formula says "minus the 10 deltas the client had read".**
  Fix: change it to "minus the deltas the client had received".
- **`BENCHMARKS.md:571`: the "model free" conclusion assumes a single Ollama slot**, but `OLLAMA_NUM_PARALLEL` was left at its default.
  Fix: pin `OLLAMA_NUM_PARALLEL=1` for the bench, or soften the claim.
- **`bench/results/chat-stop/2026-11-03/followups.md:17`: the cache-clear explanation leans on the word "cache"**, which is not in the retrieved question (only the latest question is searched).
  Fix: reword it, and check that the guide and `ChatFollowupNoteTest` don't repeat the claim.

## Documentation updated

- `docs/learning-guide.md`:
  - new section 18, "Stop that really stops, and conversations"
  - section 16: an `AbortWatcher` row, a flow step, and a "Stop doesn't stop" troubleshooting row
- `BENCHMARKS.md`: new section "Stop and cancel per server mode (2026-11-03)".
- `bench/results/chat-stop/2026-11-03/`: six mode JSONs, plus `followups.json` and `followups.md`.
- `backlog.md`.
- `resources/prompts/docs-answer.v2.md` (new prompt).

All new relative links resolve. Fix the two BENCHMARKS.md wording findings above before shipping.

## Manual test plan

Written to `.ai/specs/spec-20261103-2118-chat-stop-multiturn/manual-test.md`. It has seven steps: curl abort on nginx-fpm and frankenphp-octane, a six-mode bench, history and validation curls, the browser transcript, gates and parity, and the docs read-through.

To run it: `ddev start && ddev server nginx-fpm`, warm the Ollama model, paste the `ask()`/`last()` helpers from the top of the file, and follow steps 1–7. Finish on `ddev server nginx-fpm`.

## Recommendation

**FIX-FIRST.** This cannot ship yet, for these reasons:

- The build is partial: tasks 4, 9 and 14 are open.
- Two converge gaps were not built (15, 16).
- Two major findings are confirmed (the AC-1 abort blind spot, and the dead cache guard).
- Parity was re-captured on only one of six servers.
- All the work is uncommitted on `master`.

The core scenario works and the gates are green, so the remaining work is narrow.

Next: make `DocsAnswerProtocol::parts()` yield an SSE comment for skipped reasoning events (and while waiting for the first token), so `SseProtocol` checks the abort watcher. Add a `StreamCancelTest` case that aborts during reasoning.
