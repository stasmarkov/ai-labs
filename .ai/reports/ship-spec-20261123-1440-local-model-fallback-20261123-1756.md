# Ship report: local model fallback (SL-61)

## Summary

SL-61 adds a fallback to a smaller local model (`qwen3:0.6b`) when the main local model is missing, unreachable, returns a 5xx, times out or is busy past a first-token budget. Run rows record `fallback_from_model` and `fallback_reason`, and the lab pages show them. All 14 build tasks are done. Pint, PHPStan, the test suite (3877 passed, 3 skipped) and the debug scan pass. All 7 major review findings are fixed, and 10 minor findings remain. Acceptance has not resolved end-to-end: AC-7, AC-8, NFR-1 and NFR-2 all wait on the idle-host bench run (manual tasks 7 and 12). Converge stopped at its round cap, so the round 2 gap tasks were built but not re-audited.

**Recommendation: FIX-FIRST.**

## Stack & repos

| Repo | Role | Stack | Tracked | Branch | Left from |
| --- | --- | --- | --- | --- | --- |
| `.` | backend | laravel | yes | `feature/local-model-fallback` (created by Prepare) | `master` |

- No untracked or WIP repo. No repo warnings.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `plugin:sdd-kit-laravel@sdd-kit`. Discovery reported one error: "user stacks unreadable".

## Spec

- Id: `spec-20261123-1440-local-model-fallback`
- Folder: `.ai/specs/spec-20261123-1440-local-model-fallback`

## Investigation highlights

The run recorded no investigation output: no key files, no risks and no shipped pieces. For the files actually touched, see the converge and review sections below. The main ones are `app/Ai/LocalFallback.php`, `app/Ai/FallbackPolicy.php`, `app/Ai/FirstTokenBudget.php`, `app/Ai/AiRunRecorder.php`, `app/Services/ModerationService.php`, `app/Support/LlmFallbackBench.php` and `config/ai.php`.

## Tasks completed

- Build: 14/14. Incomplete: none.
- Manual tasks, neither built nor counted as incomplete:
  - 7. Run the bench on an idle host and commit the result.
  - 12. After the bench run, add the three BENCHMARKS tables, a measured number in the interview story, and apply the C-14 quality rule.

## Converge

- Rounds: 2. Stop reason: **cap**.
- Round 1 gaps, appended as tasks:
  - 9. Enforce the first-token budget while the main model is still silent. Before this, the budget was only checked after the first content event, so a queued main model was never cut short (AC-2, C-4).
  - 10. Bench defaults match C-7 (2000 ms budget) and produce the NFR-2 figure at 1 parallel.
  - 11. MNC-3 test: a fallback answer that fails validation fails the same way a main one does.
- Round 1 manual gap, sent to the manual test plan:
  - 12. After the bench run: BENCHMARKS tables, a measured number in the interview story, the C-14 quality rule.
- Round 2 gaps, appended as tasks:
  - 13. Prove the first-byte budget on the real streaming transport (Guzzle StreamHandler), not only on a faked cURL error 28.
  - 14. AC-6 test: the invocation id a streamed feature page reads is the fallback row's id.
  - 15. C-3 test: a tool call already made counts as a sent token, so no fallback happens.
  - 16. Run the MNC-4 check Task 8 left unrun: asset fingerprint and dependency diff.
- Built but not re-audited (last round): 13, 14, 15, 16.
- Failed to build: none.
- Gaps left unbuilt: none.

Gap 13 carries the most risk. It was raised because `FallbackPolicy::timedOut()` matches only `/timed out|timeout|cURL error 28/`. If the real stream handler reports the timeout differently, the busy budget could give no fallback or record the wrong reason. Because gap 13 was not re-audited, no audit confirms the fix holds against a real Ollama stream. Step 4 of the manual plan is the live check.

## Quality gates

| Repo | Gate | Status | Details |
| --- | --- | --- | --- |
| `.` | Pint | pass | 1109 files, no style issues (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | Pest/PHPUnit | pass | 3877 passed, 3 skipped (57057 assertions) (via ddev exec) |
| `.` | Debug scan | pass | No `dd(`, `dump(`, `ray(` in app/, routes/, database/ |

## Visual tests

Not run. The run recorded no visual phase. Step 7 of the manual plan checks the lab display at 1440 and 1024 px.

## Acceptance

- **Original scenario resolved end-to-end: no.** 20 steps were checked. These 4 failed:
  - AC-7: `llm:bench --only=fallback` writes `bench/results/llm-fallback/<date>/run.json`, and BENCHMARKS and the guides read from it.
  - AC-8: the local-models guide, the providers guide and the learning guide explain the fallback, its numbers and its limits.
  - NFR-1: time to a fallback answer on a fault is under 2 s plus the fallback's first-token time.
  - NFR-2: no added latency (describe ttft p50 at most 296 ms at 1 parallel).
- Broken hops: none recorded. The four failures share one cause: no committed bench result yet (manual tasks 7 and 12). The fallback path itself is covered by the offline tests.
- E2E regression test: none written. The run recorded no E2E phase, so no AC ids are traced to an E2E test and nothing ran.

## Confirmed review findings

### Major

| Id | File:line | Title | Status |
| --- | --- | --- | --- |
| F1 | `app/Ai/LocalFallback.php:91` | An in-stream provider Error before any content counts as sent, so no fallback happens | fixed |
| F2 | `app/Ai/FallbackPolicy.php:59` | Streamed timeouts without a budget are recorded as 'unreachable', not 'timeout' | fixed |
| F3 | `app/Services/ModerationService.php:67` | A moderation retry after a fallback tries the failed main model again (two main attempts and two fallbacks per request) | fixed |
| F9 | `config/ai.php:111` | Moderation silently downgrades to an unevaluated 0.6b model with an uncalibrated, looser risk threshold (security) | fixed |
| F10 | `app/Ai/LocalFallback.php:35` | First-token budget ignored for non-streamed features (nl-search, moderation); busy requests wait the full timeout (performance) | fixed |
| F11 | `app/Services/ModerationService.php:67` | Moderation retry loop re-tries the failed main model and pays its timeout twice (performance) | fixed |
| F17 | `config/ai.php:85` | `fallback_after_ms` for nl-search and moderation is dead config, yet the settings page shows it as active (design) | fixed |

### Minor

| Id | File:line | Title | Status |
| --- | --- | --- | --- |
| F4 | `app/Ai/FirstTokenBudget.php:51` | The budget replaces a shorter feature timeout and turns real timeouts into 'busy' | remaining |
| F5 | `app/Ai/AiRunRecorder.php:404` | A failed run row is lost when the client leaves after an in-stream error | remaining |
| F6 | `app/Ai/AiProviders.php:253` | The settings page shows a first-token budget for non-streaming features where it never applies | remaining |
| F7 | `app/Ai/LocalFallback.php:134` | A fallback stream that fails mid-stream rethrows the main failure, not its own | fixed |
| F8 | `app/Services/ModerationService.php:149` | An invalid moderation result names the main model and its threshold when the fallback gave the answers | fixed |
| F12 | `app/Ai/LocalFallback.php:129` | Timeout-triggered fallback on the same server doubles time-to-fail when both attempts time out (performance) | remaining |
| F13 | `app/Ai/LocalFallback.php:77` | Every fallback-wrapped stream buffers all its events twice (performance) | remaining |
| F14 | `app/Support/LlmFallbackBench.php:244` | Fallback bench looks up runs one at a time per parallel result (N+1) (performance) | remaining |
| F15 | `app/Services/ModerationService.php:67` | Services fetch LocalFallback through `app()` instead of their constructors (design) | remaining |
| F16 | `app/Ai/LocalFallback.php:73` | LocalFallback resolves AiRunRecorder and FirstTokenBudget from the container inside its methods (design) | remaining |
| F18 | `app/Ai/FirstTokenBudget.php:34` | Unused method `FirstTokenBudget::armed()` (design) | fixed |
| F19 | `app/Ai/FirstTokenBudgetExceeded.php:16` | Unused public property `FirstTokenBudgetExceeded::$afterMs` (design) | fixed |
| F20 | `resources/js/ai/Pages/Lab/Overview.vue:147` | Overview.vue re-implements the fallback-reason label instead of reusing LabFallbackNote's exported helper (design) | fixed |
| F21 | `app/Ai/AiRunRecorder.php:146` | Fallback-run cap reuses the constant named for latencies (design) | fixed |
| F22 | `app/Support/LlmFallbackBench.php:332` | A support class drives console commands through `Artisan::call` (design) | remaining |
| F23 | `app/Ai/LocalFallback.php:35` | `LocalFallback::prompt` returns its metadata through a mutable output argument (design) | remaining |

The remaining findings are all minor and do not block SHIP. F4 is the one worth reading before the bench, because a feature with its own shorter timeout could log a real timeout as `busy`.

## Fix

- Rounds: 2. Stop reason: **clean**.
- Fixed: F1, F2, F3, F7, F8, F9, F10, F11, F17, F18, F19, F20, F21.
- Remaining: F4, F5, F6, F12, F13, F14, F15, F16, F22, F23 (all minor).

## Documentation updated

The run recorded no docs phase. The working tree has edits to `BENCHMARKS.md`, `docs/learning-guide.md`, `resources/guides/local-models.php`, `resources/guides/providers.php` and `backlog.md`. These cover how the feature works and its limits. The measured numbers are still missing: the "Local model fallback (SL-61)" section of BENCHMARKS.md has no table yet, and the interview story has no measured number. That is manual task 12, and it keeps AC-7 and AC-8 open.

## Manual test plan

- Written to: `/Users/stasmarkov/work/interview/.ai/specs/spec-20261123-1440-local-model-fallback/manual-test.md`. It has 11 steps. This run added the two bench and docs steps, Step 9 (task 7) and Step 10 (task 12).
- How to run: run `ddev start` with Ollama on the host serving `qwen3.5:9b` and `qwen3:0.6b`, then work through Steps 1 to 11 against https://ai-lab.ddev.site. Run Step 9 only when `sysctl -n vm.loadavg` is below 4.
- Ids to tick in `tasks.md` after the steps pass:
  - Task 7, after Step 9.
  - Task 12, after Step 10.
  - Task 16, after Step 11. The plan says this one is still open in `tasks.md`.

## Cost

| Phase | Agents | Tokens |
| --- | --- | --- |
| Detect | 1 | tokens: n/a |
| Spec | 1 | tokens: n/a |
| Prepare | 1 | tokens: n/a |
| Build | 14 | tokens: n/a |
| Gates | 5 | tokens: n/a |
| Converge | 2 | tokens: n/a |
| Review | 54 | tokens: n/a |
| Fix | 25 | tokens: n/a |
| Acceptance | 1 | tokens: n/a |
| TestPlan | 1 | tokens: n/a |

`tokens: n/a` means the run had no token budget. The Report agent is not in this table. Failed agent-name lookups: 0.

## Recommendation

**FIX-FIRST.**

1. Acceptance did not resolve. AC-7, AC-8, NFR-1 and NFR-2 fail because no bench result is committed and the docs carry no measured numbers.
2. Converge stopped at its round cap. Gaps 13 to 16 were built but never re-audited. That includes the real-transport proof of the first-byte budget (gap 13), which is the core of AC-2.
3. No E2E regression test was written for the reported scenario.

For these reasons, the code is not ready to ship yet. Gates are green, every major finding is fixed, and the remaining 10 minor findings do not block.

Next: run the fallback bench on an idle host, `ddev artisan llm:bench --only=fallback --models=qwen3.5:9b` (manual Step 9, task 7), check the busy rows report `fallback_reason` `busy`, and commit `bench/results/llm-fallback/<date>/`. Then do Step 10 (task 12) and Step 11 (task 16).
