---
id: spec-20261102-1552-streamed-car-description
title: Streamed car description with a slow fake provider and an AI-run log
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-02T13:56:52Z
created: 2026-11-02
stacks: [laravel]
slice: SL-20
brs: [BR-90, BR-91, BR-95, BR-27]
---

# Streamed car description with a slow fake provider and an AI-run log

## Summary

A visitor opens `GET /listings/{id}/describe` and receives a short description of that car, streamed as server-sent events (SSE) while it is generated. The description comes from a Laravel AI SDK agent. Until a local model is chosen (Q-01), the agent runs on a **slow fake provider** that sends one token every 50 ms for 10 seconds, so streaming can be seen, tested and later load-tested without a model. Every run is logged with provider, model, prompt version, tokens, time to first token and total latency. The stream must arrive token by token on all six server setups, not buffered into one response at the end.

## Context

- The Laravel AI SDK (`laravel/ai` 1.3.0) is installed but not configured (no `config/ai.php` in the app). It provides agents, a streaming API that yields events (`StreamStart`, `TextDelta`, `StreamEnd`, …), an SSE response for a streamed agent (`Content-Type: text/event-stream`, `Cache-Control: no-cache, no-transform`, `X-Accel-Buffering: no`, one `data: <event json>` per event, then `data: [DONE]`), lifecycle events (`StreamingAgent`, `AgentStreamed`, `AgentFailed`, all carrying an invocation id), a test fake, and a way to register a custom driver on its manager (`extend`).
- The SDK's own test fake emits all words at once with no delay, so it cannot show streaming over time and is not usable at runtime.
- Listings: `GET /listings/{id}` (`routes/catalog.php`, `ShowListingController`, `ListingDetailService`). A listing has make, model, fuel, year, price (cents, currency), mileage in km, city and dealer.
- Servers: nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane, frankenphp-worker, switched with `ddev server <name>`; all sit behind the DDEV router. Response buffering can happen in PHP (output buffering), in the web server (FastCGI or proxy buffering, compression) or in the router.
- Rules from `product-brief.md`:
  - **BR-90** Every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
  - **BR-91** Provider and model are chosen by configuration only; switching models needs no code change.
  - **BR-95** The direct streaming variant sends the answer as server-sent events while it generates. (The queued Mercure variant in the same rule was dropped on 2026-11-01 and is out of scope.)
  - **BR-27** Every shipped slice includes a "How it works" note and adds its section to `docs/learning-guide.md`.

## Scope

- SDK configuration (`config/ai.php`) with the provider and model read from `.env` (`AI_PROVIDER`, `AI_MODEL`); default provider `fake-stream`.
- A `fake-stream` driver registered with the SDK: streams `AI_FAKE_TOKENS` tokens (default 200), `AI_FAKE_TOKEN_DELAY_MS` apart (default 50), so 10 seconds with the defaults, then ends with token usage. The text is the first line of the prompt after `Car: ` (year, make, model, e.g. `2019 Toyota Corolla`) followed by a fixed English sample description, one word per token, the sample repeating from its start until the count is reached; a multi-word make counts as several tokens. The first delta is the bare first word, every later delta is a space plus the next word, so the deltas concatenated give the text. There is no wait before the first token, only between tokens (199 gaps with the defaults). Non-streamed prompts return the same text at once.
- A car-description agent whose instructions come from a versioned prompt file; the prompt sent to the model is built from the listing's attributes.
- `GET /listings/{id}/describe` returning the SDK's SSE response.
- An `ai_runs` table and a listener on the SDK events that writes one row per run.
- `bench/sse.sh`: measures, on the active server, time to the first event, time to the last event and the number of events.
- Server config changes needed so all six servers stream without buffering.
- Learning guide section 9 (how an LLM call works end to end; how SSE travels through each server).

## Out of scope

- Any real model or model server (SL-22, after the Q-01 ADR).
- The queued variant and load tests (dropped).
- A web page or JavaScript client for the stream (SL-41); `curl -N` is the client here.
- Detecting a client that disconnects mid-stream and cancelling generation (SL-41).

## Must NOT change

- MNC-1: `GET /listings`, `GET /listings/{id}`, `/api/ping` and `/up` respond exactly as before; `bench/parity.sh compare` against the committed snapshots prints `identical` on all six servers.
- MNC-2: the bench harness results and scripts keep working: the existing test suite stays green and `bench/run.sh ping --quick` still passes its run conditions on nginx-fpm.

## Acceptance criteria

- AC-1 (BR-95): WHEN a visitor requests `GET /listings/{id}/describe` for an existing listing THE SYSTEM SHALL answer 200 with `Content-Type: text/event-stream`, send one SSE `data:` line per SDK stream event, including one or more `text_delta` events whose text joined in order is the full description, and end with `data: [DONE]`.
- AC-2: WHEN the listing id does not exist THE SYSTEM SHALL answer 404 with the app's JSON error shape before any stream starts, and write no `ai_runs` row.
- AC-3: WHEN the provider is `fake-stream` THE SYSTEM SHALL emit exactly `AI_FAKE_TOKENS` text deltas (200 by default) with `AI_FAKE_TOKEN_DELAY_MS` between them (50 by default): in a test with the delay set to 0 the count is exact and the joined text starts with the listing's make, model and year; in a live run with the defaults `bench/sse.sh` reports the first text event within 1 s and the last after at least 9.5 s.
- AC-4 (BR-91): WHEN `AI_PROVIDER` or `AI_MODEL` changes in `.env` (after `config:clear`, and on frankenphp-octane and frankenphp-worker after a worker restart) THE SYSTEM SHALL use that provider and model for the next run with no code change; the `ai_runs` row shows the new values.
- AC-5 (BR-90): WHEN a run completes THE SYSTEM SHALL write one `ai_runs` row with the invocation id, agent name, provider, model, prompt version, tokens in, tokens out, time to first token (ms), total latency (ms) and status `completed`.
- AC-6 (BR-90): WHEN a run fails (the provider throws, before or after the first token) THE SYSTEM SHALL send the client a final `{"type":"error",...}` event with a generic message followed by `data: [DONE]` (the 200 status is already sent), and write one `ai_runs` row with status `failed`, the error class, the message cut to 1 000 characters, the latency up to the failure, time to first token if a token was sent (else null), and null token counts.
- AC-7: WHEN the prompt file changes version THE SYSTEM SHALL record the new prompt version in `ai_runs`; the version is part of the prompt file's name (`car-description.v1.md` → `car-description@v1`).
- AC-8 (BR-95): WHEN `bench/sse.sh` runs against each of the six servers THE SYSTEM SHALL deliver the stream unbuffered: first text event within 1 s, last at 9.5 s or later, exactly `AI_FAKE_TOKENS` (200) text events, and at least 150 distinct arrival times at millisecond resolution, measured from the start of the curl request.
- AC-9 (BR-27): THE SYSTEM SHALL have learning guide section "9. Streaming an AI answer" explaining one call end to end (route → agent → provider → gateway → events → SSE lines → server → router → client), what each server needed to stop buffering, how `ai_runs` is filled, and what the fake provider hides that a real model will show (tokenizer, real latency), with links to the code, the spec, `bench/sse.sh` results, and the official docs for the Laravel AI SDK and SSE.

## Boundaries

- Always: read provider, model and fake timings from config; keep the fake provider's output deterministic; restore the active server to nginx-fpm after live checks.
- Ask first: adding a dependency; changing `.ddev/config.yaml`.
- Never: call a network model or paid API; put the provider or model name in code outside config; commit `.env`.

## Technical context

- New: `config/ai.php` (published, trimmed to the providers used), the `fake-stream` provider and gateway under `app/Ai/`, a car-description agent, `resources/prompts/car-description.v1.md`, an invokable controller, a route in `routes/catalog.php`, a migration and model for `ai_runs`, an event listener, `bench/sse.sh`.
- Time to first token is measured from `StreamingAgent` to the first `TextDelta` the listener sees; total latency from `StreamingAgent` to `AgentStreamed` or `AgentFailed`, matched by invocation id. Under Octane the listener's per-run state must not outlive the request (SL-07 lesson): key it by invocation id and remove it at the end of the run.
- For the fake provider, tokens in = number of whitespace-separated words in the instructions plus prompt, tokens out = number of text deltas. These are labelled estimates; SL-22 records the model's real counts.
- Each streaming request holds one PHP worker for about 10 seconds. That is expected here and is part of the guide's explanation (classic and worker servers alike).

## Clarifications (clarity gate, 2026-11-02)

Resolved under standing approval (self-decided). Facts about the SDK were checked in `vendor/laravel/ai` 1.3.0.

- **SDK facts.** `extend()` comes from `Illuminate\Support\MultipleInstanceManager` (line 202), which the SDK manager extends. Streamed runs dispatch `StreamingAgent` when the stream starts and `AgentStreamed` after it drains (`Providers/Concerns/StreamsText.php` lines 77 and 131); a throw inside the stream calls `recordAgentFailure`, which dispatches `AgentFailed`. All three carry the invocation id and the `AgentPrompt` (with the agent). Event `type` values: `stream_start`, `text_delta`, `stream_end`, `error`. The SDK's `StreamProtocol` base class owns the SSE response, encodes `data: <json>` parts, and on a mid-stream exception sends masked error parts and a terminator. Octane's FrankenPHP client sends the Symfony response with `send()`, so a streamed response streams in worker mode too (to be confirmed live by AC-8).
- **Time to first token.** A small protocol class (extends `StreamProtocol`) writes every SDK event as `data: <event json>`, ends with `data: [DONE]`, sends `{"type":"error","message":"The description could not be generated."}` on failure, and tells the run recorder when the first `text_delta` passes. Its headers add `X-Accel-Buffering: no` to the base class's `Cache-Control: no-cache, no-transform` and `Content-Type: text/event-stream` (the base class omits it, unlike the SDK's default response). Start comes from `StreamingAgent`, end from `AgentStreamed` or `AgentFailed`. Clock: `hrtime()`; ms are integers rounded down. All SDK events go to the client, including `stream_start` and `stream_end` with usage (useful to see; the endpoint is local only).
- **Per-run state.** The run recorder is bound with `scoped()`, so under Octane it is dropped after every request and cannot grow (SL-07). A client that disconnects mid-stream may leave no row; that is accepted and noted for SL-41.
- **Tokens.** The listener reads usage from the stream's end (`StreamEnd` usage on the `AgentStreamed` response). The fake sets tokens in = number of `preg_split('/\s+/', trim(...))` parts of instructions plus prompt, tokens out = number of deltas.
- **Providers in config.** Two entries, both on the `fake-stream` driver: `fake-stream` (200 tokens, 50 ms) and `fake-instant` (20 tokens, 0 ms). Default provider `fake-stream` (`AI_PROVIDER`), default model `fake-slow-1` (`AI_MODEL`); for a fake the model is only a label. The driver also accepts `fail_after` (tokens before it throws; null = never), used by tests. `phpunit.xml` sets `AI_FAKE_TOKENS=20` and `AI_FAKE_TOKEN_DELAY_MS=0`.
- **Listing and 404.** Existing = a row in `listings` (no soft deletes or status column); the lookup reuses `ListingDetailService::find`. `JsonErrorRenderer::wantsJson` adds the route name `listings.describe`, so the 404 is the app's existing JSON error body whatever the `Accept` header. Live checks use listing 1 (seeded).
- **Prompt.** `resources/prompts/car-description.v1.md` holds the instructions (plain markdown, no front matter). The user prompt is five lines: `Car: {year} {make} {model}`, `Fuel: {fuel}`, `Mileage: {mileage_km} km`, `Price: {price_cents/100 with 2 decimals} {currency}`, `City: {city}`. The file is chosen by `config('ai.prompts.car_description')` (default `car-description.v1`); a new version is a new file plus that config value. The version label replaces the last `.` with `@` (`car-description@v1`); the agent exposes it and the recorder reads it from the agent on `StreamingAgent`.
- **`ai_runs` columns.** `id`, `invocation_id` (string, unique), `agent` (short class name), `provider`, `model`, `prompt_version` (strings), `tokens_in`, `tokens_out`, `ttft_ms` (nullable unsigned integers), `latency_ms` (unsigned integer), `status` (`completed` or `failed`), `error_class`, `error_message` (nullable), timestamps. One insert at the end of the run.
- **`bench/sse.sh`.** `bench/sse.sh [listing-id]` (default 1) reads the URL from `ddev server --url`, streams with `curl -sN`, and timestamps every received line with Perl `Time::HiRes` in ms since the curl start (works on macOS). A text event is a line containing `"type":"text_delta"`. Output: one line `status=200 first_text_ms=… last_text_ms=… text_events=… distinct_ms=…`. Exit 0 when AC-8 holds, 1 when it fails, 2 on a non-200 or no data. `bench/sse.sh --analyse <file>` reads a saved log (`<ms><TAB><line>` per line) and applies the same checks; `BenchSseTest` runs that mode in the container (Perl is there).
- **Server fixes.** Allowed in committed per-server files (`.ddev/servers/*`, `.ddev/nginx_full`, `.ddev/apache`, `.ddev/php`), limited to `text/event-stream` responses or the describe route where the server allows it; `.ddev/config.yaml` and the DDEV router are not changed (if the router buffers, stop and ask). MNC-1 parity must stay identical.
- **No rate limit or auth.** Local-only learning endpoint; each stream holds a worker for ~10 s by design (decided, not forgotten).
- **Docs.** BR-27's note is guide section 9 itself, as for earlier slices. The doc test checks the links are present; "returns 200" is a manual check. Links: https://laravel.com/docs/13.x/ai-sdk and https://developer.mozilla.org/en-US/docs/Web/API/Server-sent_events/Using_server-sent_events.
- **Deterministic** means the same listing gives the same text and token count; timings follow the configured delay on the wall clock.

## Decisions

1. Runtime fake as an in-process SDK driver (`extend`), not a separate fake OpenAI-compatible HTTP server: no extra container, and the real wire format arrives with the real server in SL-22 (self-decided, standing approval).
2. Keep the SDK's default SSE format (`data: <event json>` per event, then `[DONE]`) rather than the Vercel data protocol: it is the plainest format to read with `curl -N` and explain (self-decided, standing approval).
3. Log through the SDK's own events instead of wrapping each call, so every future agent is logged without extra code (self-decided, standing approval).
4. Record time to first token as well as total latency: for a streamed answer it is the number the user feels (self-decided, standing approval).
5. GET, not POST, because the browser `EventSource` API only sends GET (self-decided, standing approval).

## Open questions

- [x] Assumption: BR-27, BR-90, BR-91 and BR-95 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, standing approval 2026-11-01).
- [x] Clarity gate (24 items: fake text and token rules, delta format, TTFT hook, AC-4 second provider, default model, 404 JSON, listing lookup, prompt content and versioning, failure path, `ai_runs` schema, disconnects and state growth, token source, clock and gaps, test speed, `bench/sse.sh` interface, thresholds, allowed server changes, worker reloads, rate limiting, events sent to clients, docs, SDK evidence, determinism) — all answered in Clarifications (self-decided, standing approval).

## Dependencies

- SL-01 (listings). No dependency on Q-01: the fake provider stands in for a model.
