---
id: spec-20261114-2142-prompt-playground
title: Prompt playground with versions
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-14; standing approval)
approved_at: 2026-11-14T19:44:51Z
created: 2026-11-14
stacks: [laravel]
slice: SL-43
brs: [BR-90, BR-91, BR-100, BR-27]
---

# Prompt playground with versions

## Summary

A lab page at `/ai/playground` where the developer:
1. writes a **prompt** (a system prompt with a name);
2. saves it as a numbered, immutable **version**;
3. runs one **input** against 2 or 3 models side by side.

Each column shows the output, tokens in and out, time to first token, total latency, and a link to the logged run. Every run is stored, so earlier comparisons can be opened again and versions can be diffed. The feature's own prompts live in files (`resources/prompts/*.vN.md`). The playground is a separate store for experiments, so nothing it saves changes how a feature behaves.

## Context

- **Problem:** the prompt files are versioned (`VersionedPrompt`, `prompt_version` in `ai_runs`), but trying a new wording means editing a file and rerunning an eval. Nothing shows two models on the same input next to each other, with their cost and speed.
- **Existing code:**
  - `AiProviders` and `OllamaServerInfo::available()` list the models;
  - `AiRunRecorder` handles BR-90 logging;
  - the SL-20 and SL-41 streaming helpers;
  - the `/ai/runs/{id}` run page;
  - the lab shell and the guide engine;
  - `LimitCloudAi` for cloud providers.

## Scope

### In scope

1. **Storage:**
   - **`playground_prompts`:** `id`, `name` (unique, 3 to 80 characters, `^[a-z0-9][a-z0-9 _.-]*$` lowercased), `created_at`, `updated_at`.
   - **`playground_prompt_versions`:**
     - `id`, `playground_prompt_id` (foreign key, cascade), `version` (int, starts at 1, unique per prompt);
     - `system_prompt` (text, 1 to 8000 characters), `note` (nullable, at most 200);
     - `temperature` (0..2, default 0.2), `max_tokens` (16..2048, default 512);
     - `created_at`.
     - **Immutable:** there is no update route. Saving always creates `max(version) + 1`, and saving text identical to the latest version is refused with 422 `unchanged`.
   - **`playground_runs`:**
     - `id` (uuid), `playground_prompt_version_id` (nullable: a run can use a version that is not saved), `system_prompt` (a snapshot);
     - `input` (text, 1 to 4000), `input_flags` (jsonb: a list of the guard patterns the input matched, empty when none), `temperature`, `max_tokens`, `version_label`, `created_at`, `updated_at` (C-1).
   - **`playground_results`:**
     - `id`, `playground_run_id` (foreign key, cascade), `position` (1..3), `provider`, `model`;
     - `status`: `ok`, `failed` or `timeout`;
     - `output` (text), `error`, `tokens_in`, `tokens_out`, `ttft_ms`, `duration_ms`;
     - `ai_run_invocation_id` (string).
2. **Running a comparison:**
   - **Request:** `POST /api/playground/runs` with `{prompt_version_id? | system_prompt, input, targets: [{provider, model}] (2..3, distinct), temperature?, max_tokens?}`.
   - **Targets:** each target must be an enabled text provider and a model that `available()` lists for it, otherwise 422.
   - **The input:** passes `PromptInjectionGuard`. A match is not refused: it is flagged on the run (`input_flags`) and shown. The playground exists to test prompts, but the guard result is visible.
   - **Execution:**
     - Each target runs as one non-streaming chat call through a single `PlaygroundAgent` (laravel/ai, `usingProvider` and `usingModel`, instructions = `system_prompt`).
     - The targets run **one after another**, because Ollama serves one request at a time (SL-28).
     - The response is returned when all targets are finished.
     - Each call is logged in `ai_runs` with feature `playground`, the model, `prompt_version` = `playground:{name}@v{n}` (or `playground:unsaved`), tokens and latency (BR-90).
     - TTFT is not available without streaming, so `ttft_ms` is null in this slice and the column shows "—". See D-2.
   - **Failures:** a target that fails or times out gets `status` `failed` or `timeout` with a redacted error, and the others still run.
   - **Response:** 200 with the run and its results.
   - **Limits:** a rate limit of 6 runs per minute per IP. `LimitCloudAi:playground` weights each run by its number of cloud targets.
3. **Lab page** `Lab/Playground.vue`:
   - **Left side:** a list of prompts. Selecting one shows its versions (newest first).
   - **The editor:** the system prompt, temperature, max tokens and a note, with "Save as new version" and "Run without saving".
   - **The input box and targets:** 2 to 3 target pickers (provider and model), with qwen3.5:9b and gemma4:12b chosen by default when available.
   - **Results:** 2 or 3 columns, each with the model name, the output (plain text, `white-space: pre-wrap`, escaped), tokens in and out, the duration and a run-log link.
   - **Diff:** a "Diff with previous version" view, a line diff computed on the client between two versions.
   - **History:** a "History" list of the last 20 runs; opening one shows its columns again.
   - **Gate:** the page is available in every environment, like the other lab pages. Saving and deleting prompts are local-only (404 otherwise), so a public deploy cannot be filled with prompts. Running stays allowed, and is rate-limited.
4. **Endpoints** (all JSON, with the cross-site refusal on writes):
   - `GET /api/playground/prompts`;
   - `POST /api/playground/prompts` (create with version 1);
   - `POST /api/playground/prompts/{id}/versions`;
   - `GET /api/playground/prompts/{id}`;
   - `DELETE /api/playground/prompts/{id}`, local only, which keeps runs and sets their version id to null;
   - `POST /api/playground/runs`, `GET /api/playground/runs?limit=20` and `GET /api/playground/runs/{id}`.
5. **Settings:** the playground picks its models per run, so it has no settings row. The default targets come from `config('ai.playground.default_targets')`, with env `AI_PLAYGROUND_TARGETS=ollama:qwen3.5:9b,ollama:gemma4:12b`.
6. **Docs:**
   - a guide `resources/guides/playground.php` (six tabs): what a prompt version is, why versions are immutable, how to read tokens and latency, temperature, comparing models fairly (same input, temperature 0, run one after another), and how to move a winning prompt into a `resources/prompts/*.vN.md` file;
   - a learning-guide §16 section;
   - README;
   - `.env.example`.

### Out of scope

- Streaming and TTFT.
- Editing a feature's prompt files from the page.
- Running comparisons in parallel.
- Eval sets or scoring.
- Sharing prompts between machines.
- Variables or templating in prompts.

## Must NOT change

- **MNC-1:** every feature's prompt files and behaviour are unchanged. The playground never writes to `resources/prompts`.
- **MNC-2:** the settings page and its rows are unchanged.
- **MNC-3:** the public pages, the fingerprint and parity are unchanged (only `captured=` differs).
- **MNC-4:** the default test suite makes no network call.

## Acceptance criteria

- **AC-1:** WHEN the developer saves a prompt, THE SYSTEM SHALL create version 1. Each later save SHALL create the next version number and leave earlier versions unchanged. Saving text identical to the latest version SHALL return 422 `unchanged`.
- **AC-2:** WHEN a run is requested with 2 or 3 distinct valid targets, THE SYSTEM SHALL call each target once with the same system prompt and input, one after another, and store one result per target with status, output, tokens and duration.
- **AC-3 (BR-90):** Each target call SHALL write an `ai_runs` row with feature `playground`, the provider, the model, the prompt version label, tokens and latency. The result SHALL link to it.
- **AC-4 (BR-91):** IF a target names a provider that is not enabled, or a model that is not listed for it, THEN THE SYSTEM SHALL answer 422 and call nothing.
- **AC-5:** WHEN one target fails or times out, THE SYSTEM SHALL store that result as `failed` or `timeout` with a redacted error and still run the other targets.
- **AC-6 (BR-100):** WHEN the input matches the injection guard, THE SYSTEM SHALL still run it and store and show the flag.
- **AC-7:** WHEN a stored run is opened, THE SYSTEM SHALL show its columns as stored, without calling a model.
- **AC-8:** Outside the local environment, creating, versioning and deleting prompts SHALL answer 404. Running SHALL remain available with the rate limit.
- **AC-9 (BR-27):** `/ai/guides/playground` SHALL answer 200 with six tabs and no slice ids or paths. The learning guide SHALL have its section.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Bounded cost | ≤ 3 calls per run, `max_tokens` ≤ 2048, input ≤ 4000, 6 runs per minute per IP; cloud targets counted | `tests/Feature/PlaygroundRunTest.php` |
| **NFR-2** | Safety | Outputs rendered as escaped text; errors redacted; writes refused cross-site | `tests/Feature/PlaygroundEndpointTest.php` |

## Boundaries

- **Always:**
  - fake the agent in tests;
  - log through the existing recorder;
  - keep website text free of slice ids and paths.
- **Ask first:** none.
- **Never:**
  - write to `resources/prompts`;
  - change feature prompts or settings;
  - render model output as HTML.

## Technical context

- **Related code:**
  - **Providers and logging:** `app/Ai/AiProviders.php`, `app/Ai/OllamaServerInfo.php` (`available`), `app/Ai/AiRunRecorder.php` and `app/Ai/UsesActiveProvider.php` (`usingProvider` and `usingModel`).
  - **Guard and limits:** `PromptInjectionGuard`, `LimitCloudAi` and `RefuseCrossSiteRequests`.
  - **Page:** the lab shell, `/ai/runs/{id}` and the guide engine.
- **Already implemented:**
  - provider and model listing;
  - run logging;
  - the cloud limiter;
  - the lab layout.

## Decisions

- **D-1:** the targets run one after another, not in parallel. Ollama serves one request at a time (SL-28 measured this), and running them in parallel would only make the latency numbers wrong. (self-decided, standing approval)
- **D-2:** non-streaming in this slice, so the figures per column are total latency and tokens, which are comparable across models. TTFT needs streaming per column, and SL-28's `llm:bench` already measures it. (self-decided, standing approval)
- **D-3:** playground prompts are stored separately from the feature prompt files. Experiments must never change a running feature. The guide shows how to promote a winner into a file. (self-decided, standing approval)
- **D-4:** an injection match is flagged but still runs here. The playground is where you test how a model handles such text. Every other feature keeps refusing it. (self-decided, standing approval)

## Open questions

All 34 points the clarity gate raised on 2026-11-14 are answered below in C-1 to C-15 (self-decided, standing approval).

- [x] `input_flags`, the stored run parameters, prompt version vs system prompt, what "unchanged" means → C-1, C-2, C-3
- [x] Timeouts, time budget, redaction, failures → C-4
- [x] The local gate, path wording in the guide → C-5
- [x] Target validation, distinct targets, default targets → C-6
- [x] Run links, rate limits, cloud budget → C-7
- [x] Routes, CSRF, the order of checks → C-8
- [x] Repositories, navigation, response shapes, history → C-9
- [x] Diff, names, version race, timestamps, delete → C-10, C-11
- [x] Guard scope, tokens, duration → C-12
- [x] Manual-test flow, temperature, guide tabs → C-13, C-14
- [x] Terms → C-15

## Clarifications

- **C-1 Stored run fields:**
  - **`input_flags`:** jsonb on `playground_runs`, holding the list of guard pattern names (the shape of `PromptInjectionGuard::match`). It is empty when nothing matched.
  - **Settings and label:** the run also stores the `temperature` and `max_tokens` it used, plus `version_label`, so a reopened run shows them.
  - **Timestamps:** `playground_runs` has `created_at` and `updated_at`. Versions and results have `created_at` only (`UPDATED_AT = null`).
- **C-2 Request precedence:**
  - **One source:** exactly one of `prompt_version_id` or `system_prompt` is required, otherwise 422.
  - **With a version id:** the version's system prompt is used, and the request's `temperature` and `max_tokens` override the version's when given.
  - **Labels:** `playground:{name}@v{n}` when a version id is used. With `system_prompt`, the label is `playground:unsaved`, even if the text matches a saved version.
- **C-3 "Unchanged":** a save is unchanged when the `system_prompt` (after trimming and normalising line endings to `\n`), `temperature` and `max_tokens` all equal the latest version. A note-only change also counts as unchanged. Text equal to an older version is allowed (it becomes a new version).
- **C-4 Timeouts and errors:**
  - **Timeouts:** each target uses `ai.playground.target_timeout`, 60 s by default. A connection or read timeout from the client becomes status `timeout`, and every other error becomes `failed`.
  - **Time budget:** at most 3 targets × 60 s fit under the 150 s request limit, applied with `RequestTimeLimit::atLeast(200)` as in SL-34.
  - **Redaction:** errors are cleaned with `AiRunRecorder::safeMessage()` (keys and URLs removed) and cut to 300 characters.
  - **All targets fail:** this is still 200, with the run stored and all of its results failed.
- **C-5 Gate and wording:**
  - **"Local":** means `app()->environment('local')`, through a new `EnsureLocalLab` middleware. Tests switch it the way the SL-34 and SL-35 tests do.
  - **Outside local:** the page hides the save and delete controls and shows "Saving prompts is available on a local machine". The endpoints answer 404.
  - **Guide wording:** the guide describes promoting a prompt as "copy it into a new numbered prompt file in the app's prompts folder and point the agent at it", with no path (BR-27, NoReferencesInUiTest). README and the learning guide, which are repo docs, may give the path.
- **C-6 Targets:**
  - **Which providers count:** a provider is valid when `AiProviders` lists it as an enabled text provider, Ollama or a cloud provider whose key is set.
  - **Model check:** for Ollama, the model must be in `OllamaServerInfo::available('ollama')`. For a cloud provider, it must match `AiSettingChoice::MODEL_PATTERN`, because those providers are not listed live.
  - **Tests:** they bind a fake `OllamaServerInfo`.
  - **Distinct:** means distinct `provider:model` pairs.
  - **Default targets:** `AI_PLAYGROUND_TARGETS` is split on commas, and each entry is split on the first colon into provider and model. Defaults that are not available are dropped. If fewer than 2 remain, the page picks the first available text models and asks the developer to choose.
- **C-7 Links and limits:**
  - **Run link:** `ai_run_invocation_id` stores the invocation id, the same value the existing `/ai/runs/{invocationId}` page uses. The recorder already returns it to the caller, as in SL-25 and SL-34.
  - **Rate limit:** `throttle:playground-run`, 6 per minute per IP, applies to `POST /api/playground/runs` only. Reads have no limit. Prompt writes are local-only and have no limit.
  - **Cloud budget:** `LimitCloudAi:playground` weights a run by its number of cloud targets. "Cloud" uses `AiProviders`' cloud flag. The limit is checked before any target is called, against the existing `cloud-ai` budgets.
- **C-8 Routes and order:**
  - **Where:** the API routes go in `routes/catalog.php` next to the other `/api/*` lab routes, with the same session, CSRF and middleware style as `/api/moderation`.
  - **Writes:** they get `RefuseCrossSiteRequests`.
  - **Order of checks:** the local gate (404), then cross-site (403), then the throttle (429), then validation (422).
- **C-9 Layering and shapes:**
  - **Contracts:** `PlaygroundPromptRepositoryInterface` and `PlaygroundRunRepositoryInterface`, with Eloquent implementations in `AiServiceProvider`.
  - **Navigation and overview:** the navigation gets "Playground", and `LabOverviewService` gets a capability line.
  - **Prompt list:** `[{id, name, latest_version, versions_count, updated_at}]`.
  - **Prompt detail:** `{id, name, versions: [{id, version, system_prompt, note, temperature, max_tokens, created_at}]}`, newest first.
  - **Run:** `{id, version_label, system_prompt, input, input_flags, temperature, max_tokens, created_at, results: [{position, provider, model, status, output, error, tokens_in, tokens_out, ttft_ms, duration_ms, ai_run_invocation_id}]}`.
  - **History:** `GET runs?limit` with a limit of 1..50 (default 20) returns `[{id, version_label, input (first 80 characters), models, created_at}]`. A run whose prompt was deleted keeps its stored label.
  - **Escaping (NFR-2):** the page shows output with `{{ }}`, never `v-html`. The NFR-2 test asserts that the Vue page has no `v-html` for output, and that the API returns the raw text unchanged (JSON-encoded).
- **C-10 Diff and names:**
  - **Diff:** a dropdown picks the two versions, defaulting to the latest and the one before. The diff covers `system_prompt` only, with the settings shown side by side. It is a small line LCS written in TypeScript, with no new dependency.
  - **Names:** trimmed and lowercased, then checked against `^[a-z0-9]([a-z0-9 _.-]*[a-z0-9])?$`. There is no rename.
- **C-11 Concurrency and delete:**
  - **Version race:** a new version is created inside a transaction that locks the prompt row (`lockForUpdate`) before `max(version) + 1`. The unique key is the backstop, and a collision returns 409.
  - **Delete:** `playground_runs.playground_prompt_version_id` is `nullOnDelete`, and versions cascade with their prompt.
- **C-12 Guard, tokens and duration:**
  - **Guard:** it checks `input` only. The badge reads "Input matches an injection pattern: <name>".
  - **Missing tokens:** stored as null and shown as "—".
  - **Duration:** `duration_ms` is wall time around each agent call.
- **C-13 Manual test:**
  - **Step 1:** the editor has "New prompt", a name with a save that creates v1, and "Save as new version" for an existing prompt.
  - **Step 3.1:** sent directly with curl to `POST /api/playground/runs` with a model that is not installed, because the picker lists only installed models.
- **C-14 Temperature and the guide:**
  - **Temperature:** the default stays 0.2, and the editor shows a hint that 0 gives the fairest comparison. The guide explains why.
  - **The six tabs:** the engine's own six, in their usual order: works, failures, recipes, support, numbers (counts of runs and versions from the tables, through a `playground:stats` command like `research:stats`) and experiments.
  - **Learning guide:** a new subsection in the existing §16.
- **C-15 Terms:**
  - **Parity:** `bench/parity.sh`, which compares rendered public pages.
  - **Fingerprint:** `bench/asset-fingerprint.sh`, which hashes the public CSS and JS only; lab pages have their own bundle.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
  - **BR and SL ids:** entries in the product brief and the backlog.

