# Local model wiring — Tasks

**Spec**: spec-20261102-1812-local-model-wiring | **Size**: M | **Tasks**: 6

## Phase 1: Config and agent

- [ ] 1. `ollama` provider entry and request options (backend)
  Covers: AC-1, AC-2, MNC-1, MNC-2
  - [ ] 1.1 Failing tests (HTTP client faked, no network): with `ai.default=ollama`, streaming `/listings/{id}/describe` posts to `{OLLAMA_URL}/api/chat` with model `qwen3.5:9b`, `stream: true`, `think: false` (bool), `keep_alive: "10m"` (string), `options.num_ctx: 8192` (int), `options.num_predict: 400` (int); overriding `ai.providers.ollama.request_options`, `max_tokens` and `models.text.default` in config changes the body; the HTTP timeout is 120; with `ai.default=fake-stream` no HTTP request is sent and the SL-20 tests stay green
  - [ ] 1.2 Add the `ollama` entry to `config/ai.php` (`url` from `OLLAMA_URL`, model from `OLLAMA_MODEL`, `request_options`, `max_tokens`, `timeout`, all from env with the spec defaults and casts); `CarDescriptionAgent` implements `HasProviderOptions` keyed on the provider argument, and takes max tokens and timeout from the active provider entry
  - [ ] 1.3 **Verify**: `ddev artisan test --compact --filter=Ollama` green; full suite green

- [ ] 2. Real usage, TTFT and failures in `ai_runs` (backend)
  Covers: AC-3, AC-4
  - [ ] 2.1 Failing tests: a faked NDJSON stream (several content chunks, then a final chunk with `done: true`, `prompt_eval_count: 87`, `eval_count: 142`) writes a `completed` row with provider `ollama`, model `qwen3.5:9b`, tokens 87 / 142, ttft set; a connection error and a 500 answer each give the masked `error` event, `data: [DONE]` and a `failed` row; an unknown listing gives the JSON 404 and no HTTP call
  - [ ] 2.2 Fix whatever the tests show (expected: nothing beyond task 1, since SL-20 logs from SDK events)
  - [ ] 2.3 **Verify**: tests green

## Phase 2: Reaching the host

- [ ] 3. `host.docker.internal` in the web container (ddev)
  Covers: AC-5, MNC-3
  - [ ] 3.1 Add the project compose file with `extra_hosts: ["host.docker.internal:host-gateway"]` for `web`; confirm it is in `ddev debug compose-config` for every server (including OpenLiteSpeed's overlay)
  - [ ] 3.2 **Verify**: for each of the six servers, `ddev exec getent hosts host.docker.internal` prints the gateway and `ddev exec curl -s http://host.docker.internal:11434/api/version` answers; `bench/parity.sh compare` prints `identical`; back on nginx-fpm

## Phase 3: Live runs

- [ ] 4. `bench/sse.sh --real` (bench)
  Covers: AC-6
  - [ ] 4.1 Failing tests for `--analyse <file> --real` (and `--real --analyse <file>`): 40 spread-out text events exit 0; first event at exactly 5 000 ms exits 0; one failing case per rule — 15 events (count), first at 6 000 ms (first), 40 events in 10 timestamps (distinct < 80 %), 40 distinct events spanning 600 ms (span), non-200 (status, exit 2); the SL-20 cases without `--real` unchanged
  - [ ] 4.2 Implement the flag
  - [ ] 4.3 **Verify**: tests green

- [ ] 5. Six-server live check and numbers (manual, docs)
  Covers: AC-1, AC-6, AC-7
  - [ ] 5.1 Set `AI_PROVIDER=ollama` in `.env` (and the other keys at their defaults), clear config; warm the model once; on each server run `bench/sse.sh --real` and record the newest `ai_runs` row; one extra run with `AI_THINK=true` on nginx-fpm for the guide; read `load_duration` / `prompt_eval_duration` / `eval_duration` for one cold and one warm request with `curl` against Ollama directly
  - [ ] 5.2 **Verify**: six passes, six `completed` rows with provider `ollama`; switch `AI_PROVIDER=fake-stream`, clear config, `bench/sse.sh` (fake thresholds) passes; leave `.env` on `ollama`; back on nginx-fpm

## Phase 4: Docs and gates

- [ ] 6. Guide section 10, `.env.example`, ADR note, gates (docs, all)
  Covers: AC-8, MNC-2, MNC-4
  - [ ] 6.1 Failing doc test: section "10. Running the model locally" with links to ADR 0001, the code, the spec, the Ollama docs and the Laravel AI SDK docs
  - [ ] 6.2 Write the section with the measured numbers (table per server) and the recommendation to turn off "Expose Ollama to the network"; document the AI keys in `.env.example`; add a dated note to ADR 0001 (host-gateway, nvfp4/MLX, thinking default, context kept by a loaded model); update the guide's status line and Planned list
  - [ ] 6.3 **Verify**: Pint, PHPStan, full suite green; `bench/run.sh ping --quick` passes on nginx-fpm (clean tree)

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | provider switch via .env, fake and ollama | `tests/Feature/OllamaProviderTest.php::AC-1 the active provider comes from config` + manual-test.md#step-3 | test + manual | 1, 5 |
| AC-2 | think/keep_alive/num_ctx/num_predict sent | `tests/Feature/OllamaProviderTest.php::AC-2 the agent sends the configured request options` | test | 1 |
| AC-3 | real token counts and ttft logged | `tests/Feature/OllamaProviderTest.php::AC-3 a streamed ollama run logs the model's token counts` | test | 2 |
| AC-4 | unreachable/error → masked error, failed row | `tests/Feature/OllamaProviderTest.php::AC-4 an unreachable or failing ollama gives a failed run` | test | 2 |
| AC-5 | host.docker.internal resolves on all servers | manual-test.md#step-2 | manual | 3 |
| AC-6 | real stream unbuffered on all six servers | `tests/Feature/BenchSseTest.php::AC-6 sse.sh --real checks a real model's stream` + manual-test.md#step-3 | test + manual | 4, 5 |
| AC-7 | six completed ollama rows, numbers in guide | manual-test.md#step-3 | manual | 5 |
| AC-8 | guide section 10 | `tests/Feature/LearningGuideTest.php::AC-8 learning guide explains running the model locally` | test | 6 |
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
