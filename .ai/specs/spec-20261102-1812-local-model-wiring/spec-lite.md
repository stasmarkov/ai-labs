---
id: spec-20261102-1812-local-model-wiring
title: Run the car-description agent on the local model (Ollama, qwen3.5:9b)
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-02T16:16:42Z
created: 2026-11-02
stacks: [laravel]
slice: SL-22
brs: [BR-90, BR-91, BR-95, BR-27]
---

# Run the car-description agent on the local model (Ollama, qwen3.5:9b)

## Summary

`GET /listings/{id}/describe` (SL-20) streams from a fake provider today. This slice wires it to the real local model chosen in ADR 0001: Ollama on the Mac host, reached from the DDEV container, through the `ollama` driver that ships with `laravel/ai` 1.3, running `qwen3.5:9b` with thinking off and a pinned context. Switching between the fake and the real model is a `.env` change only. `ai_runs` then records the model's real token counts and real time to first token, and `bench/sse.sh` proves the real stream arrives token by token on all six servers.

## Context

- SL-20 shipped: `config/ai.php` has `fake-stream` and `fake-instant` entries on a custom driver, default `AI_PROVIDER=fake-stream`, model from `AI_MODEL`; `CarDescriptionAgent` (instructions from `resources/prompts/car-description.v1.md`), `SseProtocol` (SSE lines, first-token report, masked errors), `AiRunRecorder` + `RecordAiRun` (one `ai_runs` row per run from the SDK's `StreamingAgent` / `AgentStreamed` / `AgentFailed` events), `bench/sse.sh` (thresholds tuned for the fake: 200 events, ≥ 9.5 s).
- ADR 0001 (accepted 2026-11-02): Ollama on the host as the only model server; the SDK's native `ollama` driver for app slices; `qwen3.5:9b` for chat; pin the context (8192); `think` off when not wanted.
- The app's `config/ai.php` replaces the vendor `providers` array (shallow merge), so the vendor `ollama` entry is not available; an `ollama` entry must be added.
- Verified on this machine (2026-11-02):
  - Ollama 0.40.13 runs as the macOS app; `qwen3.5:9b` is pulled (9.7B parameters, quantisation `nvfp4`, MLX runner, thinking on by default, 262 144-token maximum context); 39.8 tokens/s generation in a smoke test.
  - A request with `options.num_ctx: 8192` loads the model at context 8192 (7.7 GB); a model already loaded keeps its context until it is unloaded.
  - **The DDEV web container cannot resolve `host.docker.internal`**: the global OrbStack Docker config (`~/.orbstack/config/docker.json`) sets `dns: [1.1.1.1, 8.8.8.8, 8.8.4.4]`, which replaces OrbStack's resolver. With `--add-host=host.docker.internal:host-gateway` the name resolves to `0.250.250.254` and Ollama answers.
  - The Ollama app setting "Expose Ollama to the network" is on (owner's setting, predates this work), so Ollama listens on all interfaces.
- The SDK's Ollama driver sends `think`, `keep_alive` (top level) and other keys such as `num_ctx` (inside `options`) from the agent's provider options (`HasProviderOptions`), and reads token usage from the final stream chunk (`prompt_eval_count`, `eval_count`). Its HTTP client timeout defaults to 60 s unless the prompt sets one.
- Rules from `product-brief.md`:
  - **BR-90** Every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
  - **BR-91** Provider and model are chosen by configuration only; switching models needs no code change.
  - **BR-95** The direct streaming variant sends the answer as server-sent events while it generates.
  - **BR-27** Every shipped slice adds its section to `docs/learning-guide.md`.

## Scope

- An `ollama` provider entry in `config/ai.php`: URL from `OLLAMA_URL` (default `http://host.docker.internal:11434`), text model from `OLLAMA_MODEL` (default `qwen3.5:9b`; `AI_MODEL` stays the fakes' model label), request options `think` (`AI_THINK`, default false), `keep_alive` (`OLLAMA_KEEP_ALIVE`, default `10m`) and `num_ctx` (`OLLAMA_NUM_CTX`, default 8192), and a request timeout (`AI_TIMEOUT`, default 120 s).
- `CarDescriptionAgent` passes the active provider's request options (via `HasProviderOptions`) and timeout, and caps the answer length (`AI_MAX_TOKENS`, default 400).
- A project-level DDEV compose file that makes `host.docker.internal` resolve to the host gateway in the web container, for every server.
- `.env.example` documents the AI settings; the developer's `.env` switches to `AI_PROVIDER=ollama`. Tests keep using the fake (`phpunit.xml`).
- `bench/sse.sh --real`: thresholds for a real model.
- Live check on all six servers with the real model; `ai_runs` rows with real numbers.
- Learning guide section 10, "Running the model locally".

## Out of scope

- The OpenAI-compatible `/v1` entry and the hand-written client (SL-34).
- Embeddings (SL-24), tools (SL-25), vision (SL-27), the grader (SL-33).
- Changing global machine settings: the OrbStack Docker DNS config and the Ollama app's network setting are reported, not changed.
- A failover provider (Q-04).
- Load tests of the real model.

## Must NOT change

- MNC-1: with `AI_PROVIDER=fake-stream` the describe endpoint behaves exactly as in SL-20 (same SSE lines, 200 deltas at 50 ms by default, same `ai_runs` row shape).
- MNC-2: the test suite needs no network and no running Ollama; it stays green.
- MNC-3: `bench/parity.sh compare` prints `identical` on all six servers; `/`, `/up`, `/api/ping`, `/listings`, `/listings/{id}` unchanged.
- MNC-4: `bench/run.sh ping --quick` still passes its run conditions on nginx-fpm.

## Acceptance criteria

- AC-1 (BR-91): WHEN `.env` has `AI_PROVIDER=ollama` (and the config cache is cleared, workers restarted on worker servers) THE SYSTEM SHALL stream the description from Ollama with model `qwen3.5:9b`; WHEN it is set back to `fake-stream` THE SYSTEM SHALL stream from the fake again, with no code change either way.
- AC-2: WHEN the agent calls Ollama THE SYSTEM SHALL send `think: false`, `keep_alive: "10m"` and `options.num_ctx: 8192` (and `options.num_predict` = the max-tokens cap) unless `.env` overrides them; a test with the HTTP client faked asserts the request body.
- AC-3 (BR-90): WHEN a run on Ollama completes THE SYSTEM SHALL write an `ai_runs` row with provider `ollama`, model `qwen3.5:9b`, tokens in and out taken from Ollama's final chunk (`prompt_eval_count`, `eval_count`), time to first token and latency; a faked NDJSON stream with `prompt_eval_count: 87, eval_count: 142` gives `tokens_in = 87`, `tokens_out = 142`.
- AC-4: WHEN Ollama is unreachable or answers with an error THE SYSTEM SHALL send the masked `error` event then `data: [DONE]`, and write a `failed` row naming the error class; WHEN the listing does not exist THE SYSTEM SHALL still answer the JSON 404 without calling Ollama.
- AC-5: WHEN the web container (any of the six servers) resolves `host.docker.internal` THE SYSTEM SHALL get the host gateway address, and `curl http://host.docker.internal:11434/api/version` from the container SHALL answer 200.
- AC-6 (BR-95): WHEN `bench/sse.sh --real` runs on each of the six servers with Ollama active and the model loaded THE SYSTEM SHALL pass: status 200, first text event within 5 000 ms, at least 20 text events, distinct arrival times for at least 40 % of them (Ollama itself delivers about 55 to 60 % distinct milliseconds, because tokens come in bursts), and at least 1 000 ms between the first and last text event. Without `--real` the SL-20 thresholds apply unchanged.
- AC-7 (BR-90): WHEN the six live runs are done THE SYSTEM SHALL have six `completed` `ai_runs` rows with provider `ollama`, non-zero tokens in and out, and their numbers (tokens, ttft_ms, latency_ms, tokens/s = tokens out ÷ (latency − ttft)) recorded in the guide.
- AC-8 (BR-27): THE SYSTEM SHALL have guide section "10. Running the model locally" explaining what Ollama does for one request (load, quantised weights, MLX runner, context and KV cache, keep-alive, thinking), why the container needed `host-gateway`, tokens versus words (the fake vs the real counts), what makes up real time to first token (model load when cold, prompt processing), the measured numbers, and the security note on Ollama's network setting, with links to ADR 0001, the code, the spec, and the Ollama and Laravel AI SDK docs.

## Boundaries

- Always: keep the suite network-free (fake the HTTP client for Ollama tests); read every model setting from config; restore nginx-fpm after live checks.
- Ask first: changing `.ddev/config.yaml`, the global OrbStack Docker config, or the Ollama app's settings; pulling other models; adding a dependency.
- Never: commit `.env`; call a cloud model; hard-code `0.250.250.254` or a model name in PHP.

## Technical context

- `HasProviderOptions::providerOptions(Lab|string $provider)` receives the driver; the agent returns `config("ai.providers.{active}.request_options")` for the active provider (`config('ai.default')`), or an empty array. The prompt timeout comes from the same provider entry.
- Ollama's final stream chunk carries `prompt_eval_count`, `eval_count`, `load_duration`, `prompt_eval_duration` and `eval_duration` (nanoseconds); the SDK maps only the counts to usage. Load and prompt times are read with `curl` for the guide, not stored.
- The compose file goes under `.ddev/` (for example `.ddev/docker-compose.host-gateway.yaml`), adding `extra_hosts: ["host.docker.internal:host-gateway"]` to the `web` service. It must apply to all six servers (OpenLiteSpeed has its own compose overlay; check the merged config with `ddev debug compose-config`).
- A run on a cold model includes the load (several seconds); live checks first warm the model with one request.

## Clarifications (clarity gate, 2026-11-02)

Resolved under standing approval (self-decided). SDK facts checked in `vendor/laravel/ai` 1.3.0; machine facts checked on 2026-11-02.

- **Tests stay on the fake.** `phpunit.xml` already sets `AI_PROVIDER=fake-stream` (SL-20), so the suite ignores `.env`. Ollama tests switch `ai.default` in the test and fake the HTTP client.
- **Faking.** The Ollama gateway builds its requests with Laravel's HTTP client (`Gateway/Ollama/Concerns/CreatesOllamaClient.php` returns an `Illuminate\Http\Client\PendingRequest`), so `Http::fake()` intercepts them; a streamed answer is faked as an NDJSON body (one JSON object per line). If `Http::fake` cannot replay a streamed body, the test fakes at the gateway boundary instead and the spec's assertions stay the same.
- **Max tokens and timeout.** Both use the SDK's own agent settings: `maxTokens` (the driver maps it to `options.num_predict`) and the prompt timeout (the driver's HTTP timeout, total per request, default 60 s in the SDK). Values come from per-provider config keys `max_tokens` and `timeout` on the active provider entry (env `AI_MAX_TOKENS`, default 400, and `AI_TIMEOUT`, default 120); the fake entries leave them unset and ignore them. A timeout is covered by the AC-4 failure tests as a connection error.
- **Provider options.** `providerOptions()` keys on the `$provider` argument it receives: for a `Lab` value, its `->value`; it returns `config("ai.providers.{name}.request_options", [])` for the matching entry name when the driver is `ollama`, otherwise `[]`. The agent does not pin a provider: `stream()` without a provider uses `ai.default` (as in SL-20).
- **Casting.** `num_ctx` and `max_tokens` are integers, `think` a boolean (`filter_var(..., FILTER_VALIDATE_BOOL)`), `keep_alive` a string; the AC-2 test asserts the JSON types.
- **URL.** `OLLAMA_URL` is the base URL without `/api` and without a trailing slash (`http://host.docker.internal:11434`); the driver adds `/api/chat`.
- **Time to first token** is the first `text_delta` with non-empty text (as in SL-20, reasoning events do not count). In the faked test only "not null and ≥ 0" is asserted.
- **Failures in scope:** connection refused or timeout (`Illuminate\Http\Client\ConnectionException`), an HTTP error answer such as 404 model not found or 500 (the SDK's exception class, whatever it throws, stored in `error_class`), and a failure after some deltas were sent. All give the masked `error` event then `data: [DONE]` and one `failed` row with null token counts (SL-20 rule); deltas already sent stay sent. An `{"error":...}` line inside a 200 stream is observed live if it happens, not specifically tested.
- **Thinking on (comparison run only).** The SDK emits reasoning events; `SseProtocol` forwards them as `reasoning_*` lines like any event. Time to first token still waits for the first text. `num_predict` covers reasoning too, so the comparison run sets `AI_MAX_TOKENS=1200`; the guide reports both runs.
- **`bench/sse.sh --real`** works with the live mode and with `--analyse <file>` in any order; the log format and the PHP test (`BenchSseTest` shells out to the script) are as in SL-20. Rules, all on non-empty `text_delta` events, times in ms from the start of curl: status 200; first text event ≤ 5 000 (5 000 passes); at least 20 text events; at least 40 % of them have a millisecond timestamp different from the previous text event; last − first ≥ 1 000. Task 4.1 has one failing case per rule.
- **Compose file.** Name `.ddev/docker-compose.host-gateway.yaml`. Adding a `.ddev/docker-compose.*.yaml` file is not "changing `.ddev/config.yaml`". It takes effect after `ddev restart` (or the next `ddev server <name>`, which restarts). Compose merges `extra_hosts` lists, but OpenLiteSpeed's overlay is checked with `ddev debug compose-config`. AC-5 passes when `getent hosts host.docker.internal` prints any address and the version call answers; the check may print and compare against `0.250.250.254` in the manual step, never in PHP.
- **Ollama's network setting does not matter for DDEV.** Checked: with host-gateway, a container reaches a host service bound only to `127.0.0.1` (OrbStack relays it). So "Expose Ollama to the network" can be turned off; the guide recommends turning it off and says why (any network the laptop joins could use the model server). Turning it off is the owner's action (Boundaries).
- **`.env.example`.** Adds a commented block with every AI key at its default and `AI_PROVIDER=fake-stream`, plus one line explaining how to switch to `ollama`.
- **Doc test (AC-8).** Heading matched exactly: `## 10. Running the model locally`. Links checked for presence: `adr/0001-local-model-server-models-php-client.md`, `../config/ai.php`, `../app/Ai/CarDescriptionAgent.php`, the spec folder, `https://docs.ollama.com/api`, `https://laravel.com/docs/13.x/ai-sdk`. The AC-7 numbers go in a table with one row per server: tokens in, tokens out, ttft_ms, latency_ms, tokens/s (tokens out ÷ ((latency_ms − ttft_ms) / 1000)). The status line and Planned list are the existing ones at the top and bottom of the guide.
- **Manual-test preconditions** that already hold from earlier slices: listing 1 exists in the seeded PostgreSQL database; `bench/parity/<server>.txt` exists for all six servers; `bench/parity.sh` honours `BENCH_PARITY_DIR`; `ddev server <name>` accepts the six names; PHPStan (Larastan level 5) is installed. Step 1 first unloads the model (`ollama stop qwen3.5:9b`) so the context shown is the one this slice sends. Step 5 needs the work committed first.
- **Task 2.2** stays "fix what the tests show": if usage arrives empty, mapping it is in scope of this slice.

## Decisions

1. Fix the name resolution in the project (`extra_hosts: host-gateway`) instead of changing the global OrbStack DNS config or hard-coding OrbStack's host IP. This departs from ADR 0001's "do not add host-gateway on macOS", which assumed OrbStack's resolver is in use; here it is replaced by the global `dns` override (self-decided, standing approval).
2. Keep `fake-stream` as the code default in `config/ai.php` and set `AI_PROVIDER=ollama` only in the developer's `.env`, so a fresh clone and the test suite never need a model (self-decided, standing approval).
3. `think` off by default: qwen3.5 thinks by default, which spends tokens and seconds before the first visible text; the guide shows one run with it on for comparison (self-decided, standing approval).
4. A separate `--real` mode for `bench/sse.sh` rather than changing the SL-20 thresholds: the fake's exact counts stay a regression check (self-decided, standing approval).
5. Each provider has its own model variable (`OLLAMA_MODEL` for Ollama, `AI_MODEL` for the fakes as in SL-20), so switching `AI_PROVIDER` never pairs a provider with another provider's model name (self-decided, standing approval; replaces the first draft's shared `AI_MODEL`).
6. Amend ADR 0001 with a dated note instead of superseding it: the host-gateway line and the measured facts (nvfp4/MLX, thinking on by default, context kept by a loaded model) (self-decided, standing approval).
7. Fix pass (2026-11-02): `--real` requires 40 % (not the approved 80 %) of text events to have a timestamp different from the previous text event, reported as `new_ms`. Evidence: Ollama called directly (no PHP, no web server) delivered 112 chunks with 55 % new millisecond timestamps and up to 3 per millisecond, matching `draft_num_predict 3` (speculative decoding) in `ollama show qwen3.5:9b`; the bursts come from the model server, not from buffering. The span rule (≥ 1 000 ms first to last) still catches a buffered stream. Also from review: one provider entry supplies request options, max tokens and timeout (matched by driver); numeric `OLLAMA_KEEP_ALIVE` is sent as a number; `phpunit.xml` pins every AI env key so `.env` cannot change the suite; describe is rate-limited per IP (`AI_DESCRIBE_PER_MINUTE`, default 20, 429 as JSON); the unused cloud provider entries were removed from `config/ai.php` (cloud models are a non-goal) (self-decided, standing approval).

## Open questions

- [x] Assumption: BR-27, BR-90, BR-91 and BR-95 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, standing approval 2026-11-01).
- [x] Clarity gate (22 items: test isolation, HTTP faking, max tokens, timeout, provider-option key, how the provider is picked, ttft definition, failure modes, `--real` thresholds and interface, thinking, casting, URL format, shared model variable, `.env.example`, doc test, ADR follow-up, Ollama network setting, compose mechanics, manual preconditions, task 2.2, PHPStan) — all answered in Clarifications (self-decided, standing approval).

## Dependencies

- SL-20 (shipped), ADR 0001 (accepted), Ollama 0.40.13 with `qwen3.5:9b` pulled.
