---
id: spec-20261123-1440-local-model-fallback
title: Fall back to a smaller local model when the main model is down or busy
type: feature
size: L
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-23; standing approval)
approved_at: 2026-11-23T12:43:08Z
created: 2026-11-23
stacks: [laravel]
slice: SL-61
brs: [BR-21, BR-26, BR-27, BR-91]
---

# Fall back to a smaller local model when the main model is down or busy

## Summary

Q-04 (answered 2026-11-05) chose the failover target: **a second, smaller model in the same Ollama**, used when the first model times out or errors; no second server. SL-28 measured what happens today without it (`BENCHMARKS.md`, "Failover"): with the server unreachable, the model missing or a 1 s timeout, describe and the assistant send an SSE `error` event and NL search and moderation answer 503, within 5 ms to about 1 s. Under load the one model queues: at 16 parallel describes the first token takes 31 s at p50.

This slice adds a per-feature fallback to a smaller local model, records in the run log when it answered, and measures what it buys: how many requests are answered instead of failed, how much sooner a busy request gets its first token, and how much quality is lost.

## Context

- Providers and features: `app/Ai/AiProviders.php` (`TEXT_FEATURES`, `forFeature`, `configured`, `effective`), `app/Ai/UsesActiveProvider.php` (agents read provider, model, timeout from the feature), `config/ai.php` (provider entries, features), settings page (SL-52, BR-91).
- The laravel/ai SDK already fails over across a provider list on `FailoverableException` (connection error, rate limit, credits), keyed by provider (`Promptable::getProvidersAndModels`, `Provider::formatProviderAndModelList`). A list cannot hold the same provider twice, so two models on one Ollama need app-level handling.
- Failure bench: `app/Support/LlmFailoverBench.php` (faults `unreachable`, `model_missing`, `timeout`; entries `describe`, `nl-search`, `moderation`, `assistant`), command `llm:bench --only=speed,context,failover`, results `bench/results/llm/2026-11-13/run.json`; concurrency in `bench/llm-load.sh` / `bench/results/llm-load/2026-11-13`.
- Run log: `ai_runs` (`App\Models\AiRun`, `AiRunRecorder`); every AI call writes one row with provider, model, status, ttft, latency.
- Installed Ollama models: `qwen3.5:9b` (default text), `gemma4:12b`, `qwen3:0.6b` (the smoke-test model, about 0.5 GB), `bge-m3`.
- Evals: NL search and moderation evals (SL-25/26) and their per-model figures in `LlmEvalFigures`.
- Guides: `resources/guides/local-models.php`, `providers.php`; lesson `running-models/choosing-a-model-and-provider`.
- Rules: BR-21 (bench method), BR-26 (numbers from committed files), BR-27 (guide duty), BR-91 (provider and model per feature, without code change).

## Scope

### In scope

1. **Configuration (BR-91):** per text feature, an optional fallback model on the feature's own provider, plus a first-token budget:
   - `config/ai.php` features gain `fallback_model` (env `AI_<FEATURE>_FALLBACK_MODEL`, empty = off) and `fallback_after_ms` (time to the first token before the main model counts as busy; empty = only errors trigger).
   - Defaults: `qwen3:0.6b` for `describe`, `nl-search`, `moderation`, `docs-answer`, `assistant`; off for `research`, `rag-grader`, `photo-vision` (needs a vision model), `admin-assistant` (writes data), and for every feature whose provider is a cloud provider.
   - A fallback model equal to the main model is ignored.
2. **Fallback behaviour** (one place, used by every agent through `UsesActiveProvider` or a small wrapper service):
   - Triggers: connection error, model missing (404), provider 5xx, request timeout, and — when `fallback_after_ms` is set — no first token within that budget (streaming) or no response within it (non-streaming).
   - One fallback attempt per request, on the same provider with the fallback model; no chain.
   - Streaming: fallback only before any token reached the client; after the first token an error ends the stream as today.
   - The main attempt is cancelled (stream closed) when the budget trigger fires, so it does not keep the model busy.
   - Validation, guards, tools and caps stay the same on the fallback answer (structured output still validated; a fallback answer that fails validation fails like a main one).
3. **Run log:** the row of a request answered by the fallback names the fallback model in `model` and records the main model and the trigger in a new nullable column pair `fallback_from_model` and `fallback_reason` (`unreachable`, `model_missing`, `server_error`, `timeout`, `busy`). The failed main attempt writes no separate row (one request, one row). Migration is additive.
4. **Lab UI:** wherever a run's model is shown (run page `/ai/runs/{id}`, feature pages that show the model), a fallback answer shows "answered by the fallback model <model> (<reason>)". The settings page shows each feature's fallback model and budget read-only (changing them stays in `.env`).
5. **Measurement** (`llm:bench --only=fallback`, results `bench/results/llm-fallback/<date>/run.json`):
   - **Faults:** the SL-28 matrix (3 faults × 4 entries) with the fallback on: status, which model answered, time to answer; against the SL-28 rows.
   - **Busy:** describe streams at 4 and 16 parallel (the SL-28 levels), with and without `fallback_after_ms`, BR-21 method where it applies to load (warm-up, 3 repeats, median): first-token p50/p95, share answered by the fallback, failed count. This shows honestly whether a second model on the same Ollama helps when the server itself is the bottleneck.
   - **Quality:** NL search and moderation evals on the fallback model (JSON-valid %, accuracy) next to the main model's committed figures.
6. **Docs (BR-26, BR-27):** `BENCHMARKS.md` section with the three tables and the load and host conditions; `local-models` and `providers` guides (how it works, numbers from the new file, "watch out for": the fallback shares the server, quality drop); `docs/learning-guide.md` section; backlog. An interview story with at least one measured number.

### Out of scope

- A second Ollama server or another host (Q-04 says no).
- Fallback across providers (cloud ↔ local); the SDK's own provider failover is untouched.
- Chains of more than one fallback; retries of the main model.
- Changing fallback settings from the UI.
- New scene lessons (a lesson step may be added in a later slice).

## Must NOT change

- MNC-1: With no `fallback_model` set for a feature, its behaviour, responses, errors and run rows are exactly as today (SL-28 failure matrix unchanged for that feature).
- MNC-2: Provider and model selection precedence (BR-91: request `provider` > saved setting > configuration) for the main model.
- MNC-3: Validation, guards, tool caps, confirmation of writes and the masked SSE error event are unchanged; cloud providers never fall back to a local model and vice versa.
- MNC-4: Tests stay offline; no new dependencies; asset fingerprint unchanged.

## Acceptance criteria

- AC-1 (BR-91): WHEN a feature has `fallback_model` set and the main model is unreachable, missing, answers 5xx or times out THE SYSTEM SHALL answer the request with the fallback model, and the run row SHALL record `model` = fallback model, `fallback_from_model` = main model and the matching `fallback_reason`.
- AC-2: WHEN `fallback_after_ms` is set and no first token arrives within it THE SYSTEM SHALL cancel the main attempt and answer with the fallback model, `fallback_reason` = `busy`.
- AC-3: WHEN the main model has already sent a token to the client THE SYSTEM SHALL NOT fall back; a later error ends the stream with the existing masked error event.
- AC-4: WHEN the fallback model also fails THE SYSTEM SHALL answer as today without fallback (same status and error shape) and write one failed row naming both models.
- AC-5: WHEN a feature has no fallback model, or its provider is a cloud provider, THE SYSTEM SHALL behave exactly as before (MNC-1).
- AC-6: WHEN a run answered by the fallback is shown in the lab THE SYSTEM SHALL say which fallback model answered and why; the settings page lists each feature's fallback model and budget.
- AC-7 (BR-21, BR-26): `llm:bench --only=fallback` SHALL write `bench/results/llm-fallback/<date>/run.json` with the fault matrix, the busy runs (4 and 16 parallel, with and without the budget) and the fallback model's eval figures; BENCHMARKS.md and the guides read their numbers from that file.
- AC-8 (BR-27): The `local-models` and `providers` guides and `docs/learning-guide.md` explain the fallback, its numbers and its limits.

## Quality attributes

- NFR-1: Time to a fallback answer on a fault is under 2 s plus the fallback model's own first-token time (measured in the fault matrix).
- NFR-2: With no fault and no budget hit, the fallback adds no measurable latency: describe first-token p50 at 1 parallel stays within the SL-28 spread (196–296 ms p50–p95), or the difference is explained.

## Boundaries

- **Always:** run PHP through `ddev`; keep tests offline with fakes; bench only on an idle host (load average below 4 at start, recorded in the result); commit every run, never replace one.
- **Ask first:** none (owner standing approval).
- **Never:** pull new models automatically (`qwen3:0.6b` is already installed; the bench fails with a clear message if the fallback model is missing); edit `.ddev/config.yaml`; hand-write numbers.

## Technical context

- Approach: a `FallbackPolicy` (per feature: model, budget, enabled) from `AiProviders`, and one place that runs an agent call with it (wrapping `prompt()` / `stream()`), so each agent keeps its own code. Exceptions: map the SDK's `ProviderConnectionException`, HTTP 404/5xx and timeouts to the reasons above.
- Streaming budget: a timer on the first chunk; on expiry close the main stream, then start the fallback stream on the same response, before any byte is flushed to the client.
- Ollama serves both models from one process: whether the fallback runs in parallel with a busy main model depends on `OLLAMA_MAX_LOADED_MODELS` and memory; the bench records what happens rather than assuming.

## Decisions

- D-1: Fallback model `qwen3:0.6b`: already installed, small enough to stay loaded beside the main model (self-decided, standing approval).
- D-2: Fallback off for research, rag-grader, photo-vision, admin-assistant and cloud providers: long multi-step work, a grader whose scores must stay comparable, a vision task, and a write path are worse with a much weaker model.
- D-3: One request, one run row: the row tells the whole story (fallback model, main model, reason) instead of a failed row plus a success row.

## Open questions

- [x] 1–17 raised by the fresh-context clarity gate on 2026-11-23; all answered below as C-1 to C-17 (owner standing approval: recommended answers).

## Clarifications

- C-1: When both models fail: `model` = fallback model, `fallback_from_model` = main model, `fallback_reason` = the main model's trigger, status failed; the client gets the main failure's status and error shape, as today.
- C-2: The busy timer stops on any chunk from the provider (thinking, text or tool delta). "Sent to the client" = the first content or tool event written to the response; headers and meta events are held until then.
- C-3: A tool that has already run counts like a sent token: no fallback after it (no double tool calls).
- C-4: The budget is enforced with the HTTP client's first-byte/read timeout set to `fallback_after_ms` (no blocking timer in PHP). The bench records whether Ollama stops generating after the disconnect.
- C-5: `fallback_after_ms` applies to streaming features only; non-streaming features (nl-search, moderation) fall back on errors and on their request timeout.
- C-6: `fallback_after_ms` defaults to empty (only errors trigger); the bench sets it explicitly.
- C-7: The bench's busy runs use `fallback_after_ms` = 2000 ms, recorded in `run.json`.
- C-8: A provider is local when its config entry's driver is `ollama`; every other provider is cloud and never falls back.
- C-9: The effective provider and model (BR-91 precedence) are resolved first; fallback applies only if the effective provider is local, and "equal to main" compares with the effective model.
- C-10: The fallback attempt gets the feature's full timeout; worst case = main timeout (or budget) + fallback timeout, stated in the guide.
- C-11: `ttft_ms` and `latency_ms` in a fallback row are measured from the original request start (what the visitor waited).
- C-12: A fixed mapping table in `FallbackPolicy` maps exceptions to the five reasons; whichever trigger fires first wins. The app fallback wraps a single-provider call; the SDK's provider failover only runs within each attempt.
- C-13: Answers from the fallback are never written to any answer cache (docs-answer cache, NL search cache).
- C-14: Quality rule: if the fallback's JSON-valid rate on a feature's eval is under 90 % or its accuracy is more than 15 points below the main model's committed figure, that feature's default fallback is turned off in config and the docs say why.
- C-15: NFR-2 passes when the new no-fault describe first-token p50 at 1 parallel is at most 296 ms and its p95 within SL-28's p95 plus the run's own spread; otherwise the difference is explained.
- C-16: Feature pages that show the fallback: describe, nl-search, moderation, assistant and docs-answer result panels, reusing the existing model tag.
- C-17: Manual test preconditions: clear saved per-feature settings for describe and nl-search on `/ai/settings` first (saved choices outrank `.env`); check the env names in `config/ai.php`; roll back by the migration's name, not `--step=1`.
