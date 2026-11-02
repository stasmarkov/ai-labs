# Ship report: spec-20261102-1552-streamed-car-description (SL-20)

## Summary

`GET /listings/{id}/describe` streams a car description as server-sent events from the slow fake provider, and each run is logged to `ai_runs`. The live journey works end to end on all six servers, and Pint, PHPStan, the suite (330 tests) and the debug scan all pass. The build is not finished, though. Task 6 (Gates) is still open, converge gap 10 was not built, and review confirmed three major findings: the fake gateway's token and timing behaviour, and where TTFT capture lives. The feature is also uncommitted WIP, so `bench/run.sh ping --quick` refuses to run.

## Stack & repos

| Repo | Role | Stack | Tracked |
|---|---|---|---|
| `.` | backend | laravel | yes, but this feature is uncommitted WIP (most files untracked, several modified) |

Repo warnings: none.

Stack overlay: discovery installed-plugins (plugin); added: none; ignored: 0; rejected: 0; untrusted: none

## Spec

- Id: `spec-20261102-1552-streamed-car-description`
- Folder: `.ai/specs/spec-20261102-1552-streamed-car-description`

## Investigation highlights

The workflow's investigation step returned no relevant files, risks or pieces already shipped. The acceptance trace shows the code path:

- `routes/catalog.php` (`listings.describe`), `app/Http/Controllers/DescribeListingController.php`, `app/Services/ListingDescriptionService.php`
- `app/Ai/CarDescriptionAgent.php`, `app/Ai/FakeStreamGateway.php`, `app/Ai/SseProtocol.php`, `app/Ai/AiRunRecorder.php`, `app/Providers/AiServiceProvider.php`, `config/ai.php`
- `app/Repositories/EloquentAiRunRepository.php`, `app/Models/AiRun.php`, `database/migrations/2026_11_02_160000_create_ai_runs_table.php`
- Already in place and reused: `ListingDetailService::find`, `JsonErrorRenderer`, `bench/parity.sh`, `bench/run.sh`

## Tasks completed

Build: 8/10.

- Done: 1 (SDK config and `fake-stream` driver), 2 (agent and route), 3 (`ai_runs` log), 4 (`bench/sse.sh` and server fixes), 5 (learning guide section 9), 8 (`fake-instant` model and tokens), 9 (AC-4 live check on three servers).
- **Incomplete: 6 (Gates).** Pint, PHPStan and the suite are green, but 6.1 also needs `bench/run.sh ping --quick` to pass its run conditions, and it refuses on a dirty working tree.
- Task 7 (prompt file from `ai.prompts.car_description`) is still unchecked in `tasks.md`. The code has it now (`config/ai.php` has a `prompts` key, and `CarDescriptionAgent` reads `ai.prompts.car_description`), and converge round 2 did not raise it again. Tick the box once its 7.1 verification (a v2 prompt test) has been run.
- Task 10 was not built (see Converge).

## Converge

- Rounds run: 2
- Gap tasks appended: 4 (round 1: 7, 8, 9; round 2: 10)
- **Gaps left unbuilt: 1.** Gap 10: the AC-1 test should check one `data:` line per SDK event, the full description and the SSE headers. `ListingDescribeTest` only checks `toStartWith(...)` and the final `data: [DONE]`. It does not check the non-delta events, the delta count, the full text or `X-Accel-Buffering: no`, and nginx-fpm streaming (AC-8) depends on that header.

## Quality gates per repo

| Repo | Gate | Status | Details |
|---|---|---|---|
| `.` | Pint | pass | 122 files (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | PHPUnit | pass | 330 passed, 1750 assertions (via ddev exec) |
| `.` | Debug scan | pass | No `dd(`/`dump(`/`ray(` in app/ routes/ database/ |
| `.` | `bench/run.sh ping --quick` (task 6.1, MNC-2b) | fail | Refused: git working tree has changes. The checks before that guard (runtime, OPcache on, Xdebug off) passed. Re-run after committing. |

No contract-sync gate: single repo.

## Visual tests

None. This is an SSE API with no UI, and the spec names curl as the client.

## Acceptance

- Original scenario: `GET /listings/{id}/describe` streams a car description as SSE from the slow fake provider on all six servers, and each run is logged to `ai_runs`. **Resolved end to end.**
- Hops verified live: route, controller, service, agent, `fake-stream` provider/gateway, `SseProtocol`, web server, DDEV router, curl. Row 23 in `ai_runs` matches the stream's `invocation_id`. Broken hops: none.
- AC-1 to AC-9 and MNC-1 were checked live or in code. `bench/sse.sh` exits 0 on all six servers (first text 123–436 ms, last 10531–10875 ms, 200 events), and `bench/parity.sh` prints identical on all six. MNC-2b could not run (dirty tree, see gates).
- Caveats: `tokens_in` is 14 live, but the spec defines it as instructions plus prompt. The gateway waits once before the first delta (200 waits, not 199). The guide quotes "19 tokens in" and "first token arrives at once", and both are wrong. No test covers AC-7 with a second prompt file.
- E2E regression test: none written. The stack has no frontend e2e setup. AC-1 to AC-7 are covered by feature tests (`ListingDescribeTest`, `AiRunLogTest`, `FakeStreamProviderTest`, `BenchSseTest`, `LearningGuideTest`), and all of them pass.
- Environment restored afterwards: nginx-fpm, `.env` unchanged, config cleared.

## Confirmed review findings

### Major

- `app/Ai/FakeStreamGateway.php:101` (and `:55`): `tokens_in` counts only the user prompt and ignores `$instructions`. Fix: `countWords(trim(($instructions ?? '').' '.$prompt))`, and correct the assertion in `FakeStreamProviderTest.php:42` and the "19 tokens in" in the guide.
- `app/Ai/FakeStreamGateway.php:85`: it sleeps before every delta, including the first, which goes against the spec's "no wait before the first token". Fix: guard the sleep with `$index > 0 && $this->delayMs > 0`, and fix "arrives at once" in the guide.
- `app/Ai/SseProtocol.php:64`: TTFT is recorded only by the SSE encoder, so any other consumer of the agent writes `ttft_ms = null`. Fix: move `firstToken()` capture into the logging layer (a stream hook on `StreamableAgentResponse` or the listener path).

### Minor

- `config/ai.php:47` (and `:45`): an empty `AI_FAKE_FAIL_AFTER=` or `AI_FAKE_TOKENS=` becomes 0, so every stream fails or has no text. Fix: treat `''` like null (`filled(env(...))`).
- `app/Ai/SseProtocol.php:61`: provider `Error` events are forwarded raw, and a second masked error follows them. Fix: on `$event instanceof Error`, set `$this->errored = true` and yield only the masked part.
- `app/Http/Responses/JsonErrorRenderer.php:19`: ids that fail the route pattern (`/listings/0/describe`, `/listings/abc/describe`) get an HTML 404. Fix: also match `$request->is('listings/*/describe')`.
- `app/Ai/AiRunRecorder.php:72`: the run is unset before `save()`, so a failed insert loses the row and the client gets an error after the full text. Fix: save first (or catch and report), then unset.
- `app/Ai/AiRunRecorder.php:74`: the `ai_runs` INSERT runs before `data: [DONE]`, inside the stream. Fix: defer the write (`defer()` or a queued job) so the terminator does not wait on it.
- `app/Ai/FakeStreamGateway.php:101`: usage `outputTokens` comes from config, not the deltas actually sent, and a negative `AI_FAKE_TOKENS` breaks the check constraint. Fix: use `count($text)` and clamp tokens to >= 0.

(One more design finding repeats the `tokens_in` issue above and is merged into it.)

## Documentation updated

- `docs/learning-guide.md`: new section "9. Streaming an AI answer" (task 5, AC-9). `LearningGuideTest` passes. Two numbers in it need correcting (see the findings).
- `docs/adr/` was added. The workflow's docs step reported nothing else.

## Manual test plan

- Written to `/Users/stasmarkov/work/interview/.ai/specs/spec-20261102-1552-streamed-car-description/manual-test.md` (10 steps covering AC-1 to AC-9 and MNC-1/2, plus rollback).
- How to run: on nginx-fpm after `ddev artisan migrate`, commit or stash first (step 5 needs a clean tree), then follow steps 1–10 starting with `curl -N https://interview.ddev.site/listings/1/describe`. Roll back by removing any added `AI_*` lines and the v2 prompt, then `ddev artisan config:clear && ddev server nginx-fpm`.

## Recommendation

**FIX-FIRST.** Task 6 is still open, converge gap 10 was not built, the `bench/run.sh ping --quick` gate cannot pass on the uncommitted tree, and three major findings are in the new code.

Next: fix `app/Ai/FakeStreamGateway.php` (count instructions plus prompt for `tokens_in` at :55/:101 and skip the sleep before the first delta at :85), update `FakeStreamProviderTest` and the guide's numbers, build gap 10, then commit and re-run `bench/run.sh ping --quick` to close task 6.
