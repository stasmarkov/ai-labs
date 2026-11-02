# Local model wiring — Tasks

**Spec**: spec-20261102-1812-local-model-wiring | **Size**: M | **Tasks**: 6

## Phase 1: Config and agent

- [x] 1. `ollama` provider entry and request options (backend)
  Covers: AC-1, AC-2, MNC-1, MNC-2
  - [x] 1.1 Failing tests (HTTP client faked, no network): with `ai.default=ollama`, streaming `/listings/{id}/describe` posts to `{OLLAMA_URL}/api/chat` with model `qwen3.5:9b`, `stream: true`, `think: false` (bool), `keep_alive: "10m"` (string), `options.num_ctx: 8192` (int), `options.num_predict: 400` (int); overriding `ai.providers.ollama.request_options`, `max_tokens` and `models.text.default` in config changes the body; the HTTP timeout is 120; with `ai.default=fake-stream` no HTTP request is sent and the SL-20 tests stay green
  - [x] 1.2 Add the `ollama` entry to `config/ai.php` (`url` from `OLLAMA_URL`, model from `OLLAMA_MODEL`, `request_options`, `max_tokens`, `timeout`, all from env with the spec defaults and casts); `CarDescriptionAgent` implements `HasProviderOptions` keyed on the provider argument, and takes max tokens and timeout from the active provider entry
  - [x] 1.3 **Verify**: `ddev artisan test --compact --filter=Ollama` green; full suite green

- [x] 2. Real usage, TTFT and failures in `ai_runs` (backend)
  Covers: AC-3, AC-4
  - [x] 2.1 Failing tests: a faked NDJSON stream (several content chunks, then a final chunk with `done: true`, `prompt_eval_count: 87`, `eval_count: 142`) writes a `completed` row with provider `ollama`, model `qwen3.5:9b`, tokens 87 / 142, ttft set; a connection error and a 500 answer each give the masked `error` event, `data: [DONE]` and a `failed` row; an unknown listing gives the JSON 404 and no HTTP call
  - [x] 2.2 Fix whatever the tests show (expected: nothing beyond task 1, since SL-20 logs from SDK events)
  - [x] 2.3 **Verify**: tests green

## Phase 2: Reaching the host

- [x] 3. `host.docker.internal` in the web container (ddev)
  Covers: AC-5, MNC-3
  - [x] 3.1 Add the project compose file with `extra_hosts: ["host.docker.internal:host-gateway"]` for `web`; confirm it is in `ddev debug compose-config` for every server (including OpenLiteSpeed's overlay)
  - [x] 3.2 **Verify**: for each of the six servers, `ddev exec getent hosts host.docker.internal` prints the gateway and `ddev exec curl -s http://host.docker.internal:11434/api/version` answers; `bench/parity.sh compare` prints `identical`; back on nginx-fpm

## Phase 3: Live runs

- [x] 4. `bench/sse.sh --real` (bench)
  Covers: AC-6
  - [x] 4.1 Failing tests for `--analyse <file> --real` (and `--real --analyse <file>`): 40 spread-out text events exit 0; first event at exactly 5 000 ms exits 0; one failing case per rule — 15 events (count), first at 6 000 ms (first), 40 events in 10 timestamps (new timestamps < 40 %), 40 distinct events spanning 600 ms (span), non-200 (status, exit 2); the SL-20 cases without `--real` unchanged
  - [x] 4.2 Implement the flag
  - [x] 4.3 **Verify**: tests green

- [x] 5. Six-server live check and numbers (manual, docs)
  Covers: AC-1, AC-6, AC-7
  - [x] 5.1 Set `AI_PROVIDER=ollama` in `.env` (and the other keys at their defaults), clear config; warm the model once; on each server run `bench/sse.sh --real` and record the newest `ai_runs` row; one extra run with `AI_THINK=true` on nginx-fpm for the guide; read `load_duration` / `prompt_eval_duration` / `eval_duration` for one cold and one warm request with `curl` against Ollama directly
  - [x] 5.2 **Verify**: six passes, six `completed` rows with provider `ollama`; switch `AI_PROVIDER=fake-stream`, clear config, `bench/sse.sh` (fake thresholds) passes; leave `.env` on `ollama`; back on nginx-fpm

## Phase 4: Docs and gates

- [ ] 6. Guide section 10, `.env.example`, ADR note, gates (docs, all)
  Covers: AC-8, MNC-2, MNC-4
  - [x] 6.1 Failing doc test: section "10. Running the model locally" with links to ADR 0001, the code, the spec, the Ollama docs and the Laravel AI SDK docs
  - [x] 6.2 Write the section with the measured numbers (table per server) and the recommendation to turn off "Expose Ollama to the network"; document the AI keys in `.env.example`; add a dated note to ADR 0001 (host-gateway, nvfp4/MLX, thinking default, context kept by a loaded model); update the guide's status line and Planned list
  - [ ] 6.3 **Verify**: Pint, PHPStan, full suite green; `bench/run.sh ping --quick` passes on nginx-fpm (clean tree)

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | provider switch via .env, fake and ollama | `tests/Feature/OllamaProviderTest.php::AC-1 the active provider comes from config` + manual-test.md#step-3 | test + manual | 1, 5 |
| AC-2 | think/keep_alive/num_ctx/num_predict sent | `tests/Feature/OllamaProviderTest.php::AC-2 the agent sends the configured request options` + manual-test.md#step-7 | test + manual | 1 |
| AC-3 | real token counts and ttft logged | `tests/Feature/OllamaProviderTest.php::AC-3 a streamed ollama run logs the model's token counts` | test | 2 |
| AC-4 | unreachable/error → masked error, failed row | `tests/Feature/OllamaProviderTest.php::AC-4 an unreachable or failing ollama gives a failed run` + manual-test.md#step-6 | test + manual | 2 |
| AC-5 | host.docker.internal resolves on all servers | manual-test.md#step-2 | manual | 3 |
| AC-6 | real stream unbuffered on all six servers | `tests/Feature/BenchSseTest.php::AC-6 sse.sh --real checks a real model's stream` + manual-test.md#step-3 | test + manual | 4, 5 |
| AC-7 | six completed ollama rows, numbers in guide | manual-test.md#step-3 | manual | 5 |
| AC-8 | guide section 10 | `tests/Feature/LearningGuideTest.php::AC-8 learning guide explains running the model locally` + manual-test.md#step-7 | test + manual | 6 |
| MNC-1 | fake path unchanged | `tests/Feature/ListingDescribeTest.php::AC-1 describe streams the description as server-sent events` + manual-test.md#step-4 | test + manual | 1, 5 |
| MNC-2 | suite network-free and green | manual-test.md#step-5 | manual | 1, 6 |
| MNC-3 | parity identical, endpoints unchanged | manual-test.md#step-2 | manual | 3 |
| MNC-4 | bench run conditions pass | manual-test.md#step-5 | manual | 6 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; `.env` on `AI_PROVIDER=ollama` with the other AI keys at their defaults

## Phase: Gaps (converge round 1)

- [x] 7. Record `ttft_ms` on frankenphp-octane and frankenphp-worker (backend)
  Covers: AC-3, AC-7
  - Live rows on the two worker servers have `ttft_ms = null` while `completed` and latency are set (task 5 table). `ListingDescriptionService` keeps the `AiRunRecorder` from its constructor and passes it to `new SseProtocol($this->recorder)`. `RecordAiRun` gets a recorder too. `AiRunRecorder` is `scoped()` in `AiServiceProvider`. Under Octane a kept controller or service can hold a recorder from an earlier request, so `firstToken()` lands on an instance that never saw `started()`. Write a failing test that resolves the service once and runs two describe requests with the scoped instances flushed in between (`app()->forgetScopedInstances()`). Then resolve the recorder at stream time in one place, not in the constructor.
  - [x] 7.1 **Verify**: the new test fails first and then passes; `--filter=Ollama` and `ListingDescribeTest` pass; `bench/sse.sh --real` on frankenphp-octane and frankenphp-worker writes `completed` rows with a non-null `ttft_ms`; the project is back on nginx-fpm

- [x] 8. Complete guide section 10 for AC-7 and AC-8 (docs)
  Covers: AC-7, AC-8
  - The `docs/learning-guide.md` section 10 table has no tokens-in or tokens/s column (the Clarifications ask for tokens in, tokens out, ttft_ms, latency_ms, tokens/s), and it shows `null` ttft for two servers. The section does not explain quantised weights (`nvfp4`), the MLX runner, context and KV cache (`num_ctx` 8192, a loaded model keeps its context), tokens versus words (the fake's 200 word "tokens" against the real token counts), or the parts of real time to first token (load when cold plus prompt processing). The thinking comparison ran with `AI_MAX_TOKENS=400`, not 1200 as the Clarifications say, so the "both runs" comparison is missing. The code link is `../app/Ai/`, not `../app/Ai/CarDescriptionAgent.php`. `.env.example` is missing `AI_FAKE_FAIL_AFTER`, `AI_PROMPT_CAR_DESCRIPTION` and `OLLAMA_API_KEY`. `LearningGuideTest` checks none of the explanation topics.
  - After task 7, re-run the two worker servers and do one `AI_THINK=true AI_MAX_TOKENS=1200` run on nginx-fpm. Rebuild the table with tokens in and tokens/s (tokens out ÷ ((latency_ms − ttft_ms) / 1000)). Add the missing explanations, link `CarDescriptionAgent.php`, and add the missing keys to `.env.example`. Extend the AC-8 doc test with needles for `MLX`, `KV cache`, `num_ctx`, `tokens/s` and `CarDescriptionAgent.php`.
  - [x] 8.1 **Verify**: the extended `LearningGuideTest` fails before the guide edit and passes after it; the table has six rows with non-null ttft and tokens/s; Pint passes

- [x] 9. Guard the OpenLiteSpeed `extra_hosts` mapping (ddev)
  Covers: AC-5
  - Task 5 added `extra_hosts: host.docker.internal:host-gateway` to the `openlitespeed` service in `.ddev/servers/openlitespeed/docker-compose.openlitespeed.yaml`, and no test guards it. `tests/Feature/DdevHostGatewayTest.php` checks only the `web` service and only that overlays do not override `web`'s `extra_hosts`. Removing the line would break OpenLiteSpeed's `ProviderConnectionException` fix, and the suite would stay green.
  - Add a test that every server overlay defining its own PHP-serving service (here `openlitespeed`) lists `host.docker.internal:host-gateway` in that service's `extra_hosts`.
  - [x] 9.1 **Verify**: with the line temporarily removed the new test fails, and it passes with the line restored; `DdevHostGatewayTest` passes

## Phase: Gaps (converge round 2)

- [x] 10. Record the AC-6 distinct-arrival change (80 % to 40 %) as a spec decision and align task 4.1 (docs)
  Covers: AC-6
  - Task 5 lowered the `--real` distinct-arrival threshold from 80 % to 40 % in `bench/sse.sh` and edited AC-6 and the `bench/sse.sh --real` Clarification in `spec-lite.md` in place (`git diff` shows the 80 → 40 edit). The approved AC-6 said 80 %. The `## Decisions` list (1–6) has no entry for the change, and task 4.1 in `tasks.md` still says "40 events in 10 timestamps (new timestamps < 40 %)". The task 5 summary leaves "confirm that 40 % is acceptable" open for a human. The guide and the ADR note already explain the 40 %.
  - Add Decision 7 to `spec-lite.md`: the 80 % to 40 % change, the evidence (Ollama called directly gives about 55 to 60 % distinct milliseconds; the first live run gave 47 to 64 % on all six servers; first text arrived in 218 to 678 ms, so nothing buffers), and its approval status (standing approval, or flagged for the owner). Change "distinct < 80 %" in task 4.1 to "distinct < 40 %".
  - [x] 10.1 **Verify**: task 4.1 says "distinct < 40 %"; in `spec-lite.md`, "80 %" appears only in Decision 7; `spec-lite.md` Decisions has item 7; `ddev artisan test --compact tests/Feature/BenchSseTest.php` passes

- [x] 11. Test that `bench/sse.sh --real` ignores empty text deltas (bench)
  Covers: AC-6
  - The Clarifications say every `--real` rule counts only non-empty `text_delta` events, and `bench/sse.sh` does this with `next if $real && $line =~ /"delta":""/`. No test covers it: `sseLog()` in `tests/Feature/BenchSseTest.php` always writes `"delta":"word "`. Ollama's final chunk has `content: ""`, so a regression would let empty deltas set `first_text_ms` and inflate `text_events`, and the suite would stay green.
  - Add `--real` cases that use a log with empty deltas: (a) one `"delta":""` line at 100 ms before 40 spread-out non-empty events from 300 ms prints `first_text_ms=300` and `text_events=40`; (b) 15 non-empty events plus 10 empty ones exits 1 (count rule).
  - [x] 11.1 **Verify**: with the empty-delta filter line removed from `bench/sse.sh` the new assertions fail, and with it restored `ddev artisan test --compact tests/Feature/BenchSseTest.php` passes; Pint passes
