# Streamed car description — Tasks

**Spec**: spec-20261102-1552-streamed-car-description | **Size**: M | **Tasks**: 6

## Phase 1: Model call and stream

- [ ] 1. SDK config and the `fake-stream` driver (backend)
  Covers: AC-3, AC-4
  - [ ] 1.1 Failing tests: with `AI_FAKE_TOKENS=20`, `AI_FAKE_TOKEN_DELAY_MS=0` (set in `phpunit.xml`), streaming a prompt whose first line is `Car: 2019 Land Rover Defender` yields exactly 20 text deltas, the first `2019`, later ones space-prefixed, concatenation starting `2019 Land Rover Defender`, and a stream end with usage (in = word count, out = 20); a non-streamed prompt returns the same text; `fail_after` makes it throw after that many tokens; switching `ai.default` to `fake-instant` and the model to `fake-slow-2` changes what the run reports
  - [ ] 1.2 Publish and trim `config/ai.php` (provider and model from `.env`, `fake-stream` entry with its token count and delay); register the driver with the SDK manager in a service provider; implement the provider and its gateway under `app/Ai/`
  - [ ] 1.3 **Verify**: `ddev artisan test --compact --filter=FakeStream` green

- [ ] 2. Car-description agent and `GET /listings/{id}/describe` (backend)
  Covers: AC-1, AC-2, AC-7
  - [ ] 2.1 Failing tests: existing listing → 200, `text/event-stream`, `data:` lines with text deltas whose joined text starts with the listing's make, model and year, last line `data: [DONE]`; unknown id → 404 JSON error, no stream; the agent's prompt version is `car-description@v1` from `resources/prompts/car-description.v1.md`
  - [ ] 2.2 Implement the agent (instructions from the prompt file, prompt built from the listing's attributes), the invokable controller and the route
  - [ ] 2.3 **Verify**: `curl -N https://interview.ddev.site/listings/1/describe` on nginx-fpm shows deltas arriving over ~10 s

- [ ] 3. `ai_runs` log (backend)
  Covers: AC-5, AC-6, AC-7, AC-4
  - [ ] 3.1 Failing tests: a completed stream writes one row (columns as in Clarifications, status `completed`); `fail_after` 0 and 5 each write one `failed` row (error class, message ≤ 1 000 chars, ttft null for 0 and set for 5, null tokens) and the client sees an `error` event then `[DONE]`; a 404 writes no row; the run recorder is bound `scoped()`
  - [ ] 3.2 Migration, model, scoped run recorder, the SSE protocol class that reports the first `text_delta`, listeners on `StreamingAgent`, `AgentStreamed`, `AgentFailed`, keyed by invocation id
  - [ ] 3.3 **Verify**: tests green; `ddev artisan tinker --execute 'dump(App\Models\AiRun::latest()->first()?->toArray());'` after a live run shows the row

## Phase 2: Every server streams

- [ ] 4. `bench/sse.sh` and server fixes (bench, ddev)
  Covers: AC-8, MNC-1
  - [ ] 4.1 Failing test for `bench/sse.sh --analyse <file>`: a log with 200 spread-out text events exits 0 with the summary line; one with all events at ~10 000 ms exits 1; one with 120 events exits 1; an empty log exits 2
  - [ ] 4.2 Implement `bench/sse.sh` (curl with `--no-buffer`, a timestamp per received line); run it on all six servers; fix any server that buffers (candidates: Apache proxy flushing, OpenLiteSpeed response buffering, Caddy `encode` on `text/event-stream`, PHP `output_buffering`) in the committed per-server config
  - [ ] 4.3 **Verify**: AC-8 thresholds met on all six servers; `bench/parity.sh compare` prints `identical` on all six; back on nginx-fpm

## Phase 3: Docs

- [ ] 5. Learning guide section 9 (docs)
  Covers: AC-9
  - [ ] 5.1 Failing doc test: section "9. Streaming an AI answer" exists with links to the code, the spec, `bench/sse.sh`, the Laravel AI SDK docs and the MDN SSE docs
  - [ ] 5.2 Write the section with the per-server `bench/sse.sh` numbers and the buffering fixes; update the guide's status line and Planned list
  - [ ] 5.3 **Verify**: doc test green; links return 200

- [ ] 6. Gates (all)
  Covers: MNC-2
  - [ ] 6.1 **Verify**: Pint, PHPStan and the full suite green; `bench/run.sh ping --quick` passes its run conditions on nginx-fpm

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | SSE stream of text deltas ending in [DONE] | `tests/Feature/ListingDescribeTest.php::AC-1 describe streams the description as server-sent events` | test | 2 |
| AC-2 | unknown listing 404, no row | `tests/Feature/ListingDescribeTest.php::AC-2 describe answers 404 for an unknown listing and logs nothing` | test | 2, 3 |
| AC-3 | fake emits N deltas with delay; live 10 s | `tests/Feature/FakeStreamProviderTest.php::AC-3 fake-stream emits the configured number of deltas` + manual-test.md#step-2 | test + manual | 1, 4 |
| AC-4 | provider and model from config | `tests/Feature/FakeStreamProviderTest.php::AC-4 provider and model come from config` | test | 1, 3 |
| AC-5 | completed run logged | `tests/Feature/AiRunLogTest.php::AC-5 a completed stream writes one ai_runs row` | test | 3 |
| AC-6 | failed run logged | `tests/Feature/AiRunLogTest.php::AC-6 a failing provider writes a failed ai_runs row` | test | 3 |
| AC-7 | prompt version from file name | `tests/Feature/ListingDescribeTest.php::AC-7 the prompt version comes from the prompt file name` | test | 2, 3 |
| AC-8 | unbuffered on all six servers | `tests/Feature/BenchSseTest.php::AC-8 sse.sh reports timings and fails on buffering` + manual-test.md#step-3 | test + manual | 4 |
| AC-9 | guide section 9 | `tests/Feature/LearningGuideTest.php::AC-9 learning guide explains streaming an AI answer with links` | test | 5 |
| MNC-1 | existing endpoints unchanged | manual-test.md#step-4 | manual | 4 |
| MNC-2 | suite and bench harness green | manual-test.md#step-5 | manual | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; `.env` back to its normal values
