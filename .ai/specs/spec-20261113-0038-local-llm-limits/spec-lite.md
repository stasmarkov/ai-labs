---
id: spec-20261113-0038-local-llm-limits
title: Local LLM comparison and limits
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-13; standing approval)
approved_at: 2026-11-12T22:45:05Z
created: 2026-11-13
stacks: [laravel]
slice: SL-28
brs: [BR-21, BR-26, BR-27, BR-90, BR-91]
---

# Local LLM comparison and limits

## Summary

The lab runs two local text models in Ollama, qwen3.5:9b and gemma4:12b, and every AI feature has its own eval. Nothing yet answers the questions a learner asks first about local models. How fast is each one? How much memory does it take? What happens with 4 or 16 users at once? What happens when the prompt is longer than the context window, or when Ollama is down? Does a running model slow the rest of the site? This slice measures all of that. It writes the numbers to `BENCHMARKS.md` and to a guide, and adds an opt-in smoke test group that runs the real describe, natural-language search and moderation code against a tiny model.

## Context

- **Problem:** each eval (`nl:eval`, `assistant:eval`, `moderation:eval`, `photos:eval`) reports quality and latency for one feature. There is no comparison of the models themselves:
  - no tokens per second, time to first token (TTFT), load time or memory;
  - no behaviour under concurrency;
  - no check of what Ollama does with a prompt longer than `num_ctx` (8192 in `config/ai.php`);
  - no record of what each endpoint returns when the model is unreachable, missing or too slow;
  - no measure of how local AI load affects the non-AI pages per server mode.
- **Hardware:** an Apple M3 Max with 36 GB. Ollama 0.40.16 runs on the host with Metal, and the app reaches it at `http://host.docker.internal:11434`.
- **Rate limit:** `/listings/{id}/describe` is limited to `AI_DESCRIBE_PER_MINUTE` (default 20) per IP, which a 16-stream run would hit.
- **User impact:** a learner sees measured trade-offs between the two models and the limits of a local setup, with the reasons behind them.

## Scope

### In scope

1. **`ddev artisan llm:bench`** (new artisan command): `--models=qwen3.5:9b,gemma4:12b`, `--runs=5`, `--only=speed,context,failover` (default all). It writes `bench/results/llm/<date>/run.json` and prints a summary table.
   - **Speed** (per model, against Ollama's `/api/chat` with `stream: true`, outside the app so the model is measured alone):
     - load time: the first request after `keep_alive: 0` unloaded the model, from Ollama's `load_duration`;
     - TTFT measured by the client, and the prompt-eval rate (`prompt_eval_count / prompt_eval_duration`);
     - generation tokens per second (`eval_count / eval_duration`), over `--runs` warm runs of the describe prompt for listing 1, rendered by the same prompt file the describe feature uses;
     - memory from `/api/ps`: `size` and `size_vram` while the model is loaded, at the configured `num_ctx`.
     - The report gives the median, the minimum and the maximum of the warm runs.
   - **Context** (per model): one prompt of about 1.5 × `num_ctx` tokens.
     - The prompt opens with a marker sentence carrying a random code and ends with the question "what was the code in the first sentence?".
     - It is sent at the configured `num_ctx` and again at `num_ctx` = 2 × configured.
     - The record keeps the estimated prompt tokens (tokenizer-free estimate, the same as the docs index uses), `prompt_eval_count`, whether `prompt_eval_count` < estimate (truncated), and whether the answer contains the code (recalled).
   - **Failover** (in-process, for each of these entry points: the describe stream, natural-language search, moderation and the buyer assistant). Three faults:
     - (a) Ollama unreachable: `OLLAMA_URL` pointed at a closed port;
     - (b) model not pulled: a model name that does not exist;
     - (c) timeout: the provider timeout set to 1 s with a long prompt.
     - For each fault and entry point it records the HTTP status, the error code or SSE event the client receives, the time to fail in ms, and whether an `ai_runs` row was written with a failed status and a redacted message.
     - It measures behaviour only. No fallback chain is added (see Decisions).
   - **Model table:** `run.json` also copies, per model, the JSON-valid rate and the main accuracy figure from the latest committed run of each feature eval. Each row names its source run and date, and the evals are not re-run. The figures:
     - `bench/results/nl-search`: exact-match rate;
     - `assistant`: first-try validity and filter accuracy;
     - `moderation`: JSON-valid and accuracy at the best-F1 threshold;
     - `photo-vision`: valid on the first attempt.
     - A model missing from a source run gets `null` with the reason.
2. **`bench/llm-load.sh`** (new bench script, run from the host like the other bench scripts; reuses `bench/lib/servers.sh`, `bench/lib/env-switch.sh` and `bench/k6-sse.js`, and the load runs in a one-off container on `ddev_default` per BR-22). Two modes:
   - **`concurrency [--models=…] [--quick]`:** for each model and each level in 1, 4 and 16, run `max(8, 2 × level)` real describe streams with `level` held open at once against `GET /listings/{id}/describe`. Ids rotate over 1..50000 so no cache helps.
     - Records per level: TTFT p50/p95, time to `[DONE]` p50/p95, text events per second per stream (median), aggregate text events per second, and the non-200 or incomplete share.
     - The model is chosen by `AI_DESCRIBE_PROVIDER=ollama` and `AI_DESCRIBE_MODEL=<model>`, and `AI_DESCRIBE_PER_MINUTE` is raised to 1000 for the run, all through `env-switch.sh`, so `.env` is restored on exit.
     - It records `OLLAMA_NUM_PARALLEL` as the app sees it (`'default'` when unset).
   - **`search-under-load [--quick]`:** for each server mode in `SUPPORTED_SERVERS` (frankenphp, frankenphp-worker, frankenphp-octane), the existing `bench/run.sh search` measurement (BR-21: warm-up 30 s, measure 60 s, 3 repeats, median) runs twice. One run has no AI load; the other runs while 4 real describe streams are kept open continuously on the default text model.
     - Records search RPS, p50, p95, p99 and error % for both runs, the p95 delta, and how many describes completed during the measured window.
     - The script switches servers with `ddev server` and restores the server that was active at the start.
   - The output is `bench/results/llm-load/<date>/<mode>.json`.
3. **Smoke test group `local-llm`:** `tests/Feature/LocalLlmSmokeTest.php`, a PHPUnit group `local-llm`, excluded from the default run.
   - It runs only when `LOCAL_LLM_SMOKE=1`. Otherwise every test is skipped with the message that says how to run it.
   - It uses the real Ollama at `OLLAMA_URL` with the model `OLLAMA_SMOKE_MODEL` (default `qwen3:0.6b`). If Ollama is unreachable or the model is not pulled, the test is skipped with the exact `ollama pull` command.
   - Three tests:
     - the describe stream yields at least one non-empty text event and ends with `[DONE]`;
     - the natural-language search returns either filters that pass `ListingFilterRules` or the 422 path, never a 500;
     - moderation returns `classified` or `invalid`, never an exception, and a classified result has a risk in 0..1 and reasons from the fixed list.
   - The run command is documented: `ddev exec env LOCAL_LLM_SMOKE=1 php artisan test --group=local-llm`.
4. **Results and docs:**
   - A `BENCHMARKS.md` section "Local models and limits (<date>)":
     - the model table (load time, TTFT, prompt-eval rate, tokens/s, memory, JSON-valid, accuracy);
     - the concurrency table;
     - the context check;
     - the failover matrix;
     - search p95 per server mode with and without AI load;
     - "What the numbers say", "Surprises" and an interview story with at least one measured number (BR-26).
   - A section in `docs/learning-guide.md` §16 (BR-27).
   - An in-app guide `resources/guides/local-models.php` with the six tabs. Its Numbers tab reads from `bench/results/llm` and `bench/results/llm-load`.
   - README commands.
   - The guide index lists the new guide.

### Out of scope

- An automatic fallback to another provider or model.
- Cloud providers in the comparison.
- The embedding model (bge-m3) and the photo encoder: their speed is in their own sections.
- Changing `num_ctx`, `keep_alive` or `OLLAMA_NUM_PARALLEL` defaults. The numbers inform that choice, and the guide explains it.
- New lab pages beyond the guide.

## Must NOT change

- **MNC-1:** application behaviour. Routes, responses, rate limits, prompts and config defaults are identical. The new code is only commands, a bench script, tests, the guide and docs, plus guide-index registration.
- **MNC-2:** the default `ddev artisan test --compact` run makes no network call to Ollama. The `local-llm` group is skipped or excluded, and the suite passes without a model.
- **MNC-3:** existing bench scripts (`run.sh`, `slow-load.sh`, `sse.sh`, `chat-stop.sh`, `suite.sh`) keep their arguments, output and result files. `llm-load.sh` only calls them or their libraries.
- **MNC-4:** after any bench run, including one interrupted with Ctrl-C, `.env` is byte-identical to before, the active server is the one active at the start, and `.ddev/config.yaml` is never modified.
- **MNC-5:** the asset fingerprint is unchanged, and the parity capture differs only in `captured=`.
- **MNC-6:** committed eval results in `bench/results/{nl-search,assistant,moderation,photo-vision}` are read, never rewritten.

## Acceptance criteria

- **AC-1 (BR-26):** WHEN `llm:bench --only=speed` runs, THE SYSTEM SHALL write, per model, load time ms, TTFT ms (median, min, max), prompt-eval tokens/s, generation tokens/s (median, min, max), `size` and `size_vram` bytes, the `num_ctx`, the run count and the Ollama version to `run.json`, and print one summary row per model.
- **AC-2:** WHEN `llm:bench --only=context` runs, THE SYSTEM SHALL record per model and per `num_ctx` (configured and 2×) the estimated prompt tokens, `prompt_eval_count`, `truncated` (bool) and `recalled` (bool).
- **AC-3:** WHEN `llm:bench --only=failover` runs, THE SYSTEM SHALL record, for each of the 3 faults × 4 entry points, the HTTP status, the client-visible error code or SSE event, the time to fail in ms and whether a failed `ai_runs` row was written. No secret or full URL with a key appears in the output.
- **AC-4:** WHEN `llm:bench` runs, THE SYSTEM SHALL add per model the JSON-valid and accuracy figures from the latest committed run of each feature eval, each with its source path and date, and `null` with a reason when the model is absent from that run.
- **AC-5:** WHEN `bench/llm-load.sh concurrency` runs, THE SYSTEM SHALL write per model and per level (1, 4, 16):
  - the stream count;
  - TTFT p50/p95 and time to `[DONE]` p50/p95;
  - text events/s per stream (median) and aggregate;
  - the non-200 or incomplete share.
  It also records `OLLAMA_NUM_PARALLEL`, and no stream is refused by the describe rate limit.
- **AC-6 (BR-21):** WHEN `bench/llm-load.sh search-under-load` runs, THE SYSTEM SHALL write, per server mode in `SUPPORTED_SERVERS`, the search RPS, p50, p95, p99 and error % from the median of 3 repeats (30 s warm-up, 60 s measure) without and with 4 continuous real describe streams, the p95 delta and the completed describe count.
- **AC-7:** WHEN the default test suite runs, THE SYSTEM SHALL skip or exclude the `local-llm` group and make no request to Ollama. WHEN `LOCAL_LLM_SMOKE=1` and the smoke model is available, THE SYSTEM SHALL run the 3 smoke tests against it. WHEN Ollama or the model is unavailable, the tests SHALL be skipped with the `ollama pull` command in the message.
- **AC-8 (BR-26, BR-27):** WHEN the slice is done, `BENCHMARKS.md` SHALL have the "Local models and limits" section, with:
  - the five tables filled from committed run files;
  - "What the numbers say", "Surprises" and an interview story with at least one measured number.
  The learning guide SHALL have its section, and `/ai/guides/local-models` SHALL answer 200 with six tabs whose Numbers come from the run files and with no slice ids or repo paths.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Safety of bench runs | `.env` and the active server are restored on any exit, including a signal; `.ddev/config.yaml` is untouched; a stale `.env.bench-backup` stops the run with exit 2 | test on the trap path in manual-test step 4 + `git diff --quiet .ddev/config.yaml` |
| **NFR-2** | Secrets | No API key, `Authorization` header or key-bearing URL appears in `run.json`, console output or `ai_runs` messages from the failover runs | `tests/Feature/LlmBenchCommandTest.php` |
| **NFR-3** | Offline suite | The default suite passes with Ollama stopped | `tests/Feature/LlmBenchCommandTest.php` (Http fake), MNC-2 run |

## Boundaries

- **Always:**
  - test the command's maths and file output with `Http::fake` and scripted Ollama responses;
  - restore `.env` and the server through the existing helpers;
  - follow the eval command conventions (`bench/results/<feature>/<date>/run.json`, `--force` to overwrite a day);
  - keep website text free of slice ids and paths.
- **Ask first:** none needed in this slice (all decisions recorded below under standing approval).
- **Never:**
  - change app routes, prompts, rate limits or config defaults;
  - rewrite committed eval results;
  - modify `.ddev/config.yaml`;
  - delete models, Docker images or volumes;
  - commit `.env`, raw bench output under `bench/results/raw`, or `.env.bench-backup`.

## Technical context

- **Related code:**
  - `config/ai.php`: the Ollama `url`, `timeout`, `request_options.keep_alive` and `num_ctx`, plus `num_parallel`.
  - `app/Ai/OllamaServerInfo.php`: version, reachability, models and `numParallel`.
  - `app/Ai/CarDescriptionAgent.php` and its prompt: the describe prompt.
  - `app/Ai/AiRunRecorder.php::safeMessage()`: redaction.
  - `app/Console/Commands/*EvalCommand.php`: run.json conventions and the shared `NlEvalScorer::percentile`.
  - `bench/run.sh`, `bench/slow-load.sh`, `bench/k6-sse.js`, `bench/sse.sh --real`, `bench/chat-stop.sh` (`ollama_num_parallel` metadata), `bench/lib/servers.sh` and `bench/lib/env-switch.sh`.
  - `resources/guides/*.php` with `app/Guides/*`.
- **Already implemented:**
  - the BR-21 method in `run.sh`;
  - the SSE load client;
  - env switching and restore;
  - per-feature evals with run.json;
  - the guide engine, which reads Numbers from `bench/results` JSON.

## Decisions

- **D-1:** failover is measured, not built. An automatic switch to a cloud model would bypass BR-91 (provider per feature) and the cloud cost guard, and a switch to the other local model would hide the fault being studied. The guide explains how a fallback chain would be added. (self-decided, standing approval)
- **D-2:** the speed figures come from Ollama's `/api/chat` directly, not through the app. The model is then measured without PHP or SSE overhead, and the app-level end-to-end numbers come from `llm-load.sh concurrency`. (self-decided, standing approval)
- **D-3:** accuracy and JSON-valid figures are copied from the latest committed feature evals instead of re-running them (each eval takes 10 to 40 minutes, and they are already committed and dated). (self-decided, standing approval)
- **D-4:** the smoke model is `qwen3:0.6b` (about 520 MB), the smallest current model that supports structured output and tools. The build may run `ollama pull qwen3:0.6b` on the host: it is local and reversible with `ollama rm`. (self-decided, standing approval)
- **D-5:** the smoke tests live in `tests/Feature` with a group and an env switch, not in a new `tests/Smoke` folder (no new base folder). (self-decided, standing approval)
- **D-6:** search-under-load uses 4 describe streams. 4 is the middle concurrency level and keeps one run under about 15 minutes per mode, while still occupying PHP threads in classic mode. (self-decided, standing approval)

## Open questions

All raised by the clarity gate on 2026-11-13; each item is answered in "Clarifications" below (self-decided, standing approval).

- [x] Default suite and the smoke group: skip vs exclude → C-1
- [x] How failover gets an HTTP status, the entry points → C-2
- [x] Failover overrides, saved settings, inputs, kept rows → C-3, C-4
- [x] Truncation rule, overflow behaviour, the marker → C-5
- [x] Order of speed, context and failover, and model restore → C-6
- [x] Speed request options and the TTFT definition → C-7
- [x] Cold vs warm runs and memory isolation → C-8
- [x] "Latest committed run" and the field mapping, photo-vision → C-9
- [x] Rate limit and model during search-under-load, describe count, server switching → C-10
- [x] `--quick` for both modes → C-11
- [x] Concurrency run conditions, k6 changes, incomplete, events/s, `OLLAMA_NUM_PARALLEL` → C-12
- [x] Env reaching worker servers, stale backup, Ctrl-C cleanup → C-13
- [x] Smoke setup, stray-request guard, listings, reasons, SSE reading, skip message → C-14
- [x] Guide tabs, Numbers with no runs, parity and routes → C-15
- [x] Dates and day collisions, the secrets test → C-16
- [x] Terms, tooling, the smoke model's capabilities → C-17

## Clarifications

- **C-1:** the smoke group is **skipped, not excluded**. `phpunit.xml` is unchanged. Every test in `tests/Feature/LocalLlmSmokeTest.php` (Pest, group `local-llm`) calls `markTestSkipped` unless `LOCAL_LLM_SMOKE=1`, so the default suite lists the tests as skipped, and `--group=local-llm` needs no exclusion override.
- **C-2:** failover runs every request through the HTTP kernel in-process (`app(Kernel)->handle(Request::create(...))`, the same path as a feature test) and reads the real status and body. All four entry points are HTTP:
  - `GET /listings/1/describe` (SSE);
  - `GET /api/listings/nl?q=diesel BMW in Lviv under 20000`;
  - `POST /api/moderation` (JSON body for listing 1, header `Sec-Fetch-Site: same-origin`);
  - `POST /api/assistant/ask` (JSON `{"question":"Do you have a petrol Volkswagen Golf under 15000 EUR?"}`).
  For SSE responses, the client-visible result is the first `error` or `refused` event (its code), or `none`.
- **C-3:** the overrides are config values set in-process and restored in a `finally`; `.env` is never touched.
  - For the run: `ai.features.<f>.provider=ollama` and `.model=<model>` for `describe`, `nl-search`, `moderation` and `assistant`.
  - Saved choices are ignored for the run (the settings-UI switch set off in config, as BR-91 allows).
  - The rate limiters for those routes are cleared before each request.
  - Fault (a): `ai.providers.ollama.url=http://127.0.0.1:9`, a closed port.
  - Fault (b): model `llm-bench-missing:0`.
  - Fault (c): `ai.providers.ollama.timeout=1`, after unloading the model with `keep_alive: 0`, so the load alone takes longer than 1 s.
- **C-4:** the inputs are those in C-2. The failed `ai_runs` rows stay in the dev database as normal run logs, and their ids are listed in `run.json`.
- **C-5:** the context prompt starts with `The code is <CODE>.`. `<CODE>` is 6 characters from A–Z and 0–9, drawn from a generator seeded with the run date. Filler text follows (repeated listing descriptions), and the prompt ends with the question.
  - Its size is chosen with the chars/4 estimate in `DocsAnswerContext` so that it comes to about 1.5 × `num_ctx`.
  - The real size is the `prompt_eval_count` of the 2× run. `over_window` is true when that count exceeds the configured `num_ctx`.
  - `truncated` at the configured `num_ctx` means its `prompt_eval_count` is below the 2× run's count. At 2×, `truncated` is false unless the 2× count equals 2 × `num_ctx`.
  - `recalled`: the answer content (thinking off) contains `<CODE>`, case-insensitive.
  - If Ollama returns an error instead, the row records `error` with the message, and `truncated` and `recalled` are null.
  - Which end Ollama cuts is not assumed. `recalled` is reported as observed, with no expected value.
- **C-6:** the order per model is speed, then context, then failover. After context the model is unloaded (`keep_alive: 0`), so the next use loads it again at the configured `num_ctx`.
- **C-7:** the speed requests send:
  - the describe feature's system prompt and its user prompt for listing 1;
  - `temperature: 0`, `think: false`, `num_predict: 256`;
  - `num_ctx` and `keep_alive` from `config('ai.providers.ollama.request_options')`.
  TTFT is the time to the first chunk with non-empty `message.content`. Generation tokens/s is `eval_count / eval_duration`, and output length is reported next to it (`eval_count`).
- **C-8:** before each model, every model listed by `/api/ps` is unloaded with `keep_alive: 0`.
  - The first request is the cold one: it gives `load_duration` and is left out of the warm statistics.
  - `/api/ps` is read after the warm runs, with only that model loaded.
- **C-9:** "latest committed run" means the lexically greatest folder under `bench/results/<feature>/` whose `run.json` contains the model. Folders like `2026-11-12-gemma4` count.
  - Per model, `run.json` gets `evals.<feature> = {json_valid, accuracy, accuracy_metric, source, date}` for `nl-search`, `assistant`, `moderation` and `photo-vision`. The implementer maps the field names from each file:
    - nl-search: the first-try valid rate and the exact-match rate;
    - assistant: first-try validity and filter accuracy;
    - moderation: the JSON-valid rate on the first try, and accuracy at the best-F1 threshold, which is stored in `best_f1`;
    - photo-vision: valid on the first attempt, with accuracy null when the file has none.
  - Both text models also ran the photo-vision eval in SL-27, so both are expected there.
  - A missing figure is `null` with a `reason`.
- **C-10:** `search-under-load` also switches, through `env-switch.sh`: `AI_DESCRIBE_PROVIDER=ollama`, `AI_DESCRIBE_MODEL=qwen3.5:9b` (the configured default text model) and `AI_DESCRIBE_PER_MINUTE=1000`.
  - One k6 SSE loop (4 VUs, closed loop) starts before `run.sh search`'s warm-up and stops after its last repeat.
  - The completed describe count covers that whole period, reported with the elapsed seconds and as describes per minute.
  - `llm-load.sh` switches servers with `ddev server <mode>` and restores the server active at the start.
  - `run.sh` writes its raw files to `bench/results/raw` (gitignored) as usual, with labels `llm-noload` and `llm-load`.
- **C-11:** `--quick`:
  - **concurrency:** levels 1 and 4 only, 4 streams each. This is an explicit exception to `max(8, 2 × level)`.
  - **search-under-load:** the active server only, using `run.sh search --quick` (5 s, 15 s, 1 repeat).
- **C-12:** concurrency:
  - **Run conditions:**
    - It runs on the active server, whose name is recorded.
    - One describe is sent before level 1 and left out, so the cold load does not count.
    - The k6 per-request timeout is 300 s.
  - **`bench/k6-sse.js` change:** it gains an optional `ITERATIONS` env (a shared-iterations executor). Without it, the current duration behaviour is unchanged (MNC-3).
  - **Incomplete:** a non-200 status, no `[DONE]`, an `error` event or a timeout.
  - **Text events:** these are SSE chunks, not tokens, and are labelled "chunks/s". Tokens/s lives in the speed table.
  - **`OLLAMA_NUM_PARALLEL`:** the app's config value (`'default'` when unset), with a note that the host server's own value is not readable from the app.
- **C-13:** after switching `.env`, the script follows `slow-load.sh`: `ddev artisan optimize`, plus a restart on worker modes.
  - The stale `.env.bench-backup` check already exists in `env-switch.sh` (exit 2). Manual step 4 tests it.
  - The EXIT/INT trap also removes the named k6 container (`docker rm -f`).
  - Ollama generations still in flight finish on their own and are not cancelled.
- **C-14:** smoke tests:
  - **Setup:** they call `Http::allowStrayRequests()`, because `tests/Pest.php` prevents stray requests. They set `ai.features.<f>.provider=ollama` and `.model=env('OLLAMA_SMOKE_MODEL','qwen3:0.6b')`, plus `ai.providers.ollama.url=env('OLLAMA_URL','http://host.docker.internal:11434')`, and create listings with the factory in the testing database.
  - **Assertions:**
    - reasons are checked against `App\Rules\ModerationRules`' reason list;
    - SSE is read the way `tests/Feature/ListingDescribeTest.php` reads it;
    - assertions check shape, not quality.
  - **Skip message:** "run on the host: ollama pull qwen3:0.6b".
- **C-15:** guide and checks:
  - **Tabs:** the six are the engine's tabs: works, failures, recipes, support, numbers and experiments. The topics in task 5.2 are spread across them.
  - **Numbers:** read from the latest date folder. With no run files, the engine's existing pending note shows.
  - **Parity:** the parity captures contain no guide pages (verified), so MNC-5 holds.
  - **Routes:** `/ai/guides/{key}` already exists, so there is no route change.
- **C-16:** dates and secrets:
  - **Dates:** `<date>` is the UTC date (`date -u +%F`), as the evals use.
  - **Day collisions:** `llm-load.sh` refuses an existing day file with exit 2 unless `--force` is given.
  - **NFR-2 test:** it sets a fake cloud key `sk-test-secret-123` in config and an Ollama URL with user info (`http://u:p4ss@127.0.0.1:9`). It asserts neither string appears in `run.json`, the command output or the `ai_runs` messages.
- **C-17:** terms and tooling:
  - **BR-90:** every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
  - **Asset fingerprint and parity capture:** `bench/asset-fingerprint.sh` and `bench/parity.sh`.
  - **Bench ping on the clean tree:** `bench/run.sh ping --quick` after the commit.
  - **Learning guide:** a new subsection under the existing §16.
  - **README:** the three commands (`llm:bench`, `llm-load.sh`, the smoke run).
  - **Tooling:** PHPStan and `npm run typecheck` exist and ran in earlier slices. The active server comes from `current_server` in `bench/lib/servers.sh`, not `ddev server --show`, and `shellcheck` runs only when it is installed.
  - **Smoke model:** qwen3:0.6b's structured output and tools are checked at build time. Because the smoke assertions check shape only, a weak answer still passes as long as it is valid or rejected cleanly.

