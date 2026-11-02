# Streamed car description — Tasks

**Spec**: spec-20261102-1552-streamed-car-description | **Size**: M | **Tasks**: 6

## Phase 1: Model call and stream

- [x] 1. SDK config and the `fake-stream` driver (backend)
  Covers: AC-3, AC-4
  - [x] 1.1 Failing tests: with `AI_FAKE_TOKENS=20`, `AI_FAKE_TOKEN_DELAY_MS=0` (set in `phpunit.xml`), streaming a prompt whose first line is `Car: 2019 Land Rover Defender` yields exactly 20 text deltas, the first `2019`, later ones space-prefixed, concatenation starting `2019 Land Rover Defender`, and a stream end with usage (in = word count, out = 20); a non-streamed prompt returns the same text; `fail_after` makes it throw after that many tokens; switching `ai.default` to `fake-instant` and the model to `fake-slow-2` changes what the run reports
  - [x] 1.2 Publish and trim `config/ai.php` (provider and model from `.env`, `fake-stream` entry with its token count and delay); register the driver with the SDK manager in a service provider; implement the provider and its gateway under `app/Ai/`
  - [x] 1.3 **Verify**: `ddev artisan test --compact --filter=FakeStream` green

- [x] 2. Car-description agent and `GET /listings/{id}/describe` (backend)
  Covers: AC-1, AC-2, AC-7
  - [x] 2.1 Failing tests: existing listing → 200, `text/event-stream`, `data:` lines with text deltas whose joined text starts with the listing's make, model and year, last line `data: [DONE]`; unknown id → 404 JSON error, no stream; the agent's prompt version is `car-description@v1` from `resources/prompts/car-description.v1.md`
  - [x] 2.2 Implement the agent (instructions from the prompt file, prompt built from the listing's attributes), the invokable controller and the route
  - [x] 2.3 **Verify**: `curl -N https://interview.ddev.site/listings/1/describe` on nginx-fpm shows deltas arriving over ~10 s

- [x] 3. `ai_runs` log (backend)
  Covers: AC-5, AC-6, AC-7, AC-4
  - [x] 3.1 Failing tests: a completed stream writes one row (columns as in Clarifications, status `completed`); `fail_after` 0 and 5 each write one `failed` row (error class, message ≤ 1 000 chars, ttft null for 0 and set for 5, null tokens) and the client sees an `error` event then `[DONE]`; a 404 writes no row; the run recorder is bound `scoped()`
  - [x] 3.2 Migration, model, scoped run recorder, the SSE protocol class that reports the first `text_delta`, listeners on `StreamingAgent`, `AgentStreamed`, `AgentFailed`, keyed by invocation id
  - [x] 3.3 **Verify**: tests green; `ddev artisan tinker --execute 'dump(App\Models\AiRun::latest()->first()?->toArray());'` after a live run shows the row

## Phase 2: Every server streams

- [x] 4. `bench/sse.sh` and server fixes (bench, ddev)
  Covers: AC-8, MNC-1
  - [x] 4.1 Failing test for `bench/sse.sh --analyse <file>`: a log with 200 spread-out text events exits 0 with the summary line; one with all events at ~10 000 ms exits 1; one with 120 events exits 1; an empty log exits 2
  - [x] 4.2 Implement `bench/sse.sh` (curl with `--no-buffer`, a timestamp per received line); run it on all six servers; fix any server that buffers (candidates: Apache proxy flushing, OpenLiteSpeed response buffering, Caddy `encode` on `text/event-stream`, PHP `output_buffering`) in the committed per-server config
  - [x] 4.3 **Verify**: AC-8 thresholds met on all six servers; `bench/parity.sh compare` prints `identical` on all six; back on nginx-fpm

## Phase 3: Docs

- [x] 5. Learning guide section 9 (docs)
  Covers: AC-9
  - [x] 5.1 Failing doc test: section "9. Streaming an AI answer" exists with links to the code, the spec, `bench/sse.sh`, the Laravel AI SDK docs and the MDN SSE docs
  - [x] 5.2 Write the section with the per-server `bench/sse.sh` numbers and the buffering fixes; update the guide's status line and Planned list
  - [x] 5.3 **Verify**: doc test green; links return 200

- [ ] 6. Gates (all)
  Covers: MNC-2
  - [ ] 6.1 **Verify**: Pint, PHPStan and the full suite green; `bench/run.sh ping --quick` passes its run conditions on nginx-fpm

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | SSE stream of text deltas ending in [DONE] | `tests/Feature/ListingDescribeTest.php::AC-1 describe streams the description as server-sent events` + manual-test.md#step-1 | test + manual | 2 |
| AC-2 | unknown listing 404, no row | `tests/Feature/ListingDescribeTest.php::AC-2 describe answers 404 for an unknown listing and logs nothing` + manual-test.md#step-6 | test + manual | 2, 3 |
| AC-3 | fake emits N deltas with delay; live 10 s | `tests/Feature/FakeStreamProviderTest.php::AC-3 fake-stream emits the configured number of deltas` + manual-test.md#step-2 | test + manual | 1, 4 |
| AC-4 | provider and model from config | `tests/Feature/FakeStreamProviderTest.php::AC-4 provider and model come from config` + manual-test.md#step-7 | test + manual | 1, 3 |
| AC-5 | completed run logged | `tests/Feature/AiRunLogTest.php::AC-5 a completed stream writes one ai_runs row` + manual-test.md#step-1 | test + manual | 3 |
| AC-6 | failed run logged | `tests/Feature/AiRunLogTest.php::AC-6 a failing provider writes a failed ai_runs row` + manual-test.md#step-8 | test + manual | 3 |
| AC-7 | prompt version from file name | `tests/Feature/ListingDescribeTest.php::AC-7 the prompt version comes from the prompt file name` + manual-test.md#step-9 | test + manual | 2, 3 |
| AC-8 | unbuffered on all six servers | `tests/Feature/BenchSseTest.php::AC-8 sse.sh reports timings and fails on buffering` + manual-test.md#step-3 | test + manual | 4 |
| AC-9 | guide section 9 | `tests/Feature/LearningGuideTest.php::AC-9 learning guide explains streaming an AI answer with links` + manual-test.md#step-10 | test + manual | 5 |
| MNC-1 | existing endpoints unchanged | manual-test.md#step-4 | manual | 4 |
| MNC-2 | suite and bench harness green | manual-test.md#step-5 | manual | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; `.env` back to its normal values

## Phase: Gaps (converge round 1)

- [x] 7. Prompt file chosen by `ai.prompts.car_description`, prompt lines as specified (backend)
  Covers: AC-7
  - Gap: `app/Ai/CarDescriptionAgent.php` hardcodes `private const PROMPT_FILE = 'car-description.v1.md'` and `config/ai.php` has no `prompts` key, so a new prompt version needs a PHP edit instead of "a new file plus that config value" (Clarifications, Prompt). The AC-7 test only asserts the hardcoded v1. `promptFor()` also deviates from the five specified lines: it writes `Location:` instead of `City:`, adds a sixth `Dealer:` line, and formats mileage with `number_format` (`42,000 km` instead of `{mileage_km} km`).
  - Approach: add `'prompts' => ['car_description' => env('AI_PROMPT_CAR_DESCRIPTION', 'car-description.v1')]` to `config/ai.php`; have the agent read the file `resources/prompts/{config value}.md` and derive the version by replacing the last `.` with `@`; build exactly the five lines `Car:`, `Fuel:`, `Mileage: {mileage_km} km`, `Price: {price/100, 2 decimals} {currency}`, `City: {city}`. Add a test that writes a temporary `car-description.v2.md`, sets `ai.prompts.car_description` to `car-description.v2`, streams `/listings/{id}/describe` and asserts the `ai_runs` row has `prompt_version = car-description@v2` (remove the file afterwards); assert the prompt is exactly the five specified lines.
  - [x] 7.1 **Verify**: `ddev artisan test --compact --filter='AC-7'` green, including the new v2 test; `grep -n PROMPT_FILE app/Ai/CarDescriptionAgent.php` finds nothing

- [x] 8. `fake-instant` takes its model from `AI_MODEL` and uses 20 tokens (backend)
  Covers: AC-4
  - Gap: in `config/ai.php` the `fake-instant` entry has `'models' => ['text' => ['default' => 'fake-instant-1']]` (a literal, not `env('AI_MODEL', …)`) and `'tokens' => (int) env('AI_FAKE_TOKENS', 200)` (spec: 20 tokens). With `AI_PROVIDER=fake-instant` and `AI_MODEL=fake-slow-2` in `.env`, the run reports model `fake-instant-1`, so AC-4 ("AI_MODEL changes … use that model") fails for the second provider. The AC-4 tests in `FakeStreamProviderTest` and `AiRunLogTest` set `ai.providers.fake-instant.models.text.default` directly, so they never exercise `.env` and miss this.
  - Approach: set `fake-instant` to `'tokens' => 20` and `'default' => env('AI_MODEL', 'fake-instant-1')`. Add a test that sets `AI_PROVIDER=fake-instant` and `AI_MODEL=fake-slow-2` in the environment, reloads `config/ai.php` (for example `config(['ai' => require config_path('ai.php')])`), streams `/listings/{id}/describe`, and asserts the `ai_runs` row has provider `fake-instant` and model `fake-slow-2`; restore the env afterwards.
  - [x] 8.1 **Verify**: `ddev artisan test --compact --filter='AC-4'` green, including the env-driven test

- [x] 9. AC-4 live check on nginx-fpm, frankenphp-octane and frankenphp-worker (manual)
  Covers: AC-4
  - Gap: AC-4 requires the switch to take effect after `config:clear` and, on frankenphp-octane and frankenphp-worker, after a worker restart, with the `ai_runs` row showing the new values. `manual-test.md` step 5 covers only nginx-fpm, and no task summary reports running it. Task 4 checked only stream timing on the worker servers, so nothing shows that `ai_runs` rows are written there at all (scoped `AiRunRecorder` with the streamed callback running inside the Octane client).
  - Approach: extend `manual-test.md` step 5 with the worker-server variant: set `AI_MODEL=fake-slow-2` (and separately `AI_PROVIDER=fake-instant`), run `ddev artisan config:clear`, restart the worker (`ddev server <name>` or `ddev artisan octane:reload` for Octane), run step 1, and dump the latest `ai_runs` row. Run it on nginx-fpm, frankenphp-octane and frankenphp-worker; restore `.env` and switch back to nginx-fpm.
  - [x] 9.1 **Verify**: on each of the three servers the newest `ai_runs` row after the switch shows the new provider and model with status `completed`; `.env` restored; `ddev server --current` prints `nginx-fpm`

## Phase: Gaps (converge round 2)

- [x] 10. AC-1 test checks one `data:` line per SDK event, the full description and the SSE headers (backend)
  Covers: AC-1
  - Gap: AC-1 requires "one SSE `data:` line per SDK stream event" and text deltas "whose text joined in order is the full description". `tests/Feature/ListingDescribeTest.php::AC-1 describe streams the description as server-sent events` only asserts that the joined text `toStartWith('2019 Land Rover Defender')` and that the last line is `data: [DONE]`. It never checks that the non-delta events (`stream_start`, `text_start`, `text_end`, `stream_end`) are sent, that every line before `[DONE]` is a `data: {json}` event, or that the deltas make up the whole 20-token description. Dropping events in `SseProtocol::parts()` or truncating the text would pass. The `X-Accel-Buffering: no` header from `SseProtocol::headers()` is also untested, though the guide credits it with nginx-fpm's unbuffered stream and AC-8 depends on it there.
  - Approach: extend the AC-1 test so that every non-empty line except the last starts with `data: ` and decodes to JSON with a `type`; the event types, in order, start with `stream_start` and include `text_delta` and `stream_end`; there are exactly 20 `text_delta` events (`AI_FAKE_TOKENS` in `phpunit.xml`); and the joined deltas equal `(new CarDescriptionAgent)->prompt(...)->text` for the same listing (the fake's non-streamed text). Also assert the `X-Accel-Buffering` header is `no` and `Cache-Control` contains `no-cache`.
  - [x] 10.1 **Verify**: `ddev artisan test --compact --filter='AC-1'` green; making `SseProtocol::parts()` skip non-`TextDelta` events (try it locally, then revert) makes the test fail
