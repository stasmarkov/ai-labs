---
id: spec-20261114-1444-research-agent
title: Multi-step research agent
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-14; standing approval)
approved_at: 2026-11-14T12:47:20Z
created: 2026-11-14
stacks: [laravel]
slice: SL-42
brs: [BR-90, BR-91, BR-98, BR-100, BR-27]
---

# Multi-step research agent

## Summary

The developer gives a topic, and a research agent works through it in fixed phases:
1. It **plans** 2 to 5 sub-questions.
2. For each sub-question it **searches** the SL-32 docs index (hybrid), **reads** the top chunks and writes a **note** with the facts and the chunk ids it relied on.
3. It **writes** a markdown report that cites those chunks.

Every phase is a stored step with its input, its output, the model call and the timing, so a finished run can be **replayed**: shown step by step on a page or in the console, without calling the model again. The entry point is `ddev artisan agent:research "<topic>"`, plus a lab page `/ai/research` to start a run and replay any run.

## Context

- **Problem:** the earlier AI slices were all a single call, or an SDK-run tool loop (SL-25) or hand-written tool loop (SL-34) inside one question. Nothing yet shows a longer job: a plan the agent keeps state across, intermediate notes, and a run that can be inspected after the fact. The backlog asks for notes kept in Redis and every step stored and replayable.
- **Existing code:**
  - `DocsSearchService::search($query, DocsSearchMode, $k)`, which supports hybrid mode;
  - the SL-45 docs answer, with its citation format `[n]` and `CitationParser`;
  - `PromptInjectionGuard`, `AiRunRecorder` (`ai_runs`, BR-90) and `AiProviders` features;
  - the laravel/ai agents with `HasStructuredOutput` (`ListingFilterAgent` pattern);
  - Redis as the cache store.

## Scope

### In scope

1. **Agents** (laravel/ai, structured output where noted):
   - `ResearchPlannerAgent` returns `{sub_questions: string[2..5]}`, each 5 to 200 characters.
   - `ResearchNoteAgent` takes one sub-question and its chunks (wrapped as `<chunk id="…">` data, BR-100). It returns `{facts: [{text, chunk_ids[]}] (0..6), answered: bool}`. A chunk id that was not provided is dropped, and when all are dropped the fact is dropped too.
   - `ResearchReportAgent` takes the topic and the notes, and returns markdown text that cites facts as `[n]`. `n` maps to the numbered source list the service builds from the chunk ids used in the notes.
   - The prompts are versioned files (`research-planner.v1.md`, `research-note.v1.md`, `research-report.v1.md`).
   - The feature key `research` goes in `AiProviders` (settings row, BR-91), and the provider and model are the same for all three agents.
2. **Run engine** (`ResearchRunner`):
   - **Phases:** `plan`, then for each sub-question `search` and `note`, then `report`.
   - **Before the run:** the topic (3 to 300 characters) passes `PromptInjectionGuard`, and a match is refused before any model call.
   - **Plan:** an invalid planner output is retried once, then the run ends `failed` with the step error.
   - **Search:**
     - hybrid with k = 5;
     - no model call;
     - it stores the chunk ids, scores and paths;
     - when nothing passes the docs relevance threshold, the note step is skipped with `answered: false, reason: no_sources` (BR-98).
   - **Report:**
     - it runs if at least one fact exists;
     - otherwise the report is a fixed "the docs do not cover this topic" text with no model call (BR-98);
     - citations that point to no source are removed and counted (`unknown_citations`).
   - **Caps:** at most 13 steps and 8 model calls per run (C-1), and each chunk text is cut to 1200 characters.
3. **Notes in Redis:**
   - **While the run is going:** the working notes live in the hash `research:run:{id}:notes`, one field per sub-question, with a 24-hour TTL. The report agent reads them from there.
   - **Persistence:** the notes are also written into the run's steps, so a replay never needs Redis.
4. **Storage:**
   - **`research_runs` table:**
     - `id` (uuid), `topic`, `status` (`running`, `done`, `failed` or `refused`);
     - `sub_questions` (jsonb), `report` (text, nullable), `sources` (jsonb);
     - the counts;
     - `provider`, `model`, `prompt_versions` (jsonb);
     - `started_at`, `finished_at`, `error`.
   - **`research_steps` table:**
     - `id`, `research_run_id` (foreign key, cascade), `position`;
     - `phase` (`plan`, `search`, `note` or `report`), `sub_question_index` (nullable);
     - `input` (jsonb), `output` (jsonb);
     - `ai_run_id` (nullable foreign key to `ai_runs`; null for search);
     - `status` (`ok`, `retried`, `failed` or `skipped`), `error`, `duration_ms`, `created_at`.
   - **Logging:** each model call writes its `ai_runs` row with feature `research` (BR-90).
5. **Command:** `agent:research {topic?} {--provider=} {--json} {--replay=}` (C-8).
   - It prints each step live as it finishes (phase, short output, duration), then the report and the run id.
   - `agent:research --replay={id}` prints the stored steps and the report with no model or search call.
   - It exits non-zero on `failed` or `refused`.
6. **Lab page** `Lab/Research.vue` at `/ai/research`:
   - a topic input and example topics, and a list of recent runs (last 20);
   - starting a run: `POST /api/research` creates and runs the job **on the queue**, then the page polls `GET /api/research/{id}` every 1.5 s until the run is done;
   - a run view at `/ai/research/{id}` with a step timeline (phase, sub-question, input and output collapsible, model and tokens, duration, a link to the `ai_runs` row) and the rendered report with its numbered sources;
   - a "Replay" button that plays the stored steps one by one with a short delay, client-side, with no request beyond loading the run.
   - **Endpoints:** JSON-only, with the cross-site refusal and a rate limit of 5 runs per minute per IP. Runs are allowed in every environment, like the other lab features.
7. **Docs:**
   - a guide `resources/guides/research.php` with the six tabs: how planning, notes and a run with state across steps work, why notes live in Redis and also in the database, replay versus re-run, the caps, and how to add a phase;
   - a learning-guide §16 section;
   - README;
   - `.env.example`.

### Out of scope

- Web search: only the docs index is searched.
- Tools chosen by the model: the phases are fixed.
- Re-running a run with another model.
- Streaming tokens.
- An eval set.
- Cloud cost caps beyond the existing `LimitCloudAi` (applied to the research endpoint for the feature).

## Must NOT change

- **MNC-1:** docs search and docs ask (`/api/docs/search`, `/api/docs/ask`, the answer cache) behave as today.
- **MNC-2:** other AI features, the settings page rows other than the new one, and their logs are unchanged.
- **MNC-3:** the public pages, the asset fingerprint and the parity capture are unchanged (only `captured=` differs).
- **MNC-4:** the default test suite makes no network call. The queue is `sync` in tests.

## Acceptance criteria

- **AC-1:** WHEN a run starts with a valid topic, THE SYSTEM SHALL store a `plan` step with 2 to 5 sub-questions. For each sub-question it SHALL store a `search` step with the chunk ids and a `note` step (or a `skipped` note when no source passes the threshold), then a `report` step. The run ends `done`.
- **AC-2:** WHEN the planner output is invalid twice, THE SYSTEM SHALL end the run `failed` with the error stored on the step, and run no further phase.
- **AC-3 (BR-98):** WHEN a note cites a chunk id that was not given to it, THE SYSTEM SHALL drop that id. WHEN no fact remains in any note, THE SYSTEM SHALL write the fixed "not covered" report without a report model call.
- **AC-4 (BR-98):** THE report SHALL cite only numbered sources built from the notes' chunk ids. Citations to unknown numbers SHALL be removed and counted.
- **AC-5:** WHILE a run is in progress, the notes SHALL be in the Redis hash `research:run:{id}:notes`. After the run, the same notes SHALL be in the `note` steps.
- **AC-6:** WHEN a finished run is replayed (`--replay` or the page), THE SYSTEM SHALL show every stored step and the report, and make no model or search call.
- **AC-7 (BR-90, BR-91):** Each model call SHALL write an `ai_runs` row with feature `research`, linked from its step. The provider and model SHALL come from the `research` feature resolution.
- **AC-8 (BR-100):** WHEN the topic matches the injection guard, THE SYSTEM SHALL store a `refused` run and make no model call. Chunks reach the model only as marked data.
- **AC-9:** No run SHALL exceed 13 steps or 8 model calls (C-1).
- **AC-10:** `POST /api/research` SHALL return 202 with the run id. `GET /api/research/{id}` SHALL return the status, steps and report. The lab page SHALL show the timeline and the report.
- **AC-11 (BR-27):** `/ai/guides/research` SHALL answer 200 with six tabs and no slice ids or paths. The learning guide SHALL have its section.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Bounded cost | ≤ 8 model calls, ≤ 13 steps, ≤ 5 chunks × 1200 characters per note; 5 runs per minute per IP | `tests/Feature/ResearchRunnerTest.php` |
| **NFR-2** | Safety | Topic guard; chunks marked as data; report links render escaped (no raw HTML from the model) | `tests/Feature/ResearchEndpointTest.php` |
| **NFR-3** | Replay fidelity | A replay shows exactly the stored outputs, byte for byte, with no outbound call | `tests/Feature/ResearchReplayTest.php` |

## Boundaries

- **Always:**
  - fake the agents (`::fake`) and the embedding call in tests;
  - reuse `DocsSearchService` and `CitationParser`;
  - follow the run and step logging conventions;
  - keep website text free of slice ids and paths.
- **Ask first:** none (decisions below, standing approval).
- **Never:**
  - change docs search or the docs answer;
  - render the model's markdown as raw HTML;
  - commit `.env`.

## Technical context

- **Related code:**
  - **Docs search and citations:** `app/Services/DocsSearchService.php`, `DocsAnswerService`, `app/Ai/CitationParser.php` and the relevance threshold config (`ai.docs.*`).
  - **Agents:** `app/Ai/ListingFilterAgent.php` (structured output pattern) and `app/Ai/AiRunRecorder.php`.
  - **Guard and providers:** `PromptInjectionGuard`, `AiProviders` and `LimitCloudAi`.
  - **Page:** the lab shell and the guide engine.
- **Already implemented:**
  - hybrid search;
  - the citation format;
  - run logging;
  - Redis;
  - the queue (the `database` driver in dev, `sync` in tests).

## Decisions

- **D-1:** the phases are fixed: a plan, a note per sub-question, then a report. The model does not pick tools. The lesson is state across steps and replay. Model-chosen tools were already covered by SL-25 and SL-34. (self-decided, standing approval)
- **D-2:** runs execute on the queue from the page, and the page polls. A run makes up to 8 model calls, which can take about a minute, too long for one HTTP request. The command runs in-process. (self-decided, standing approval)
- **D-3:** the notes live in Redis while the run works, as the backlog says, and are copied into the steps, so a replay does not depend on Redis or its TTL. (self-decided, standing approval)
- **D-4:** replay is client-side playback of stored steps, not a deterministic re-execution. A local model at temperature 0 is still not guaranteed to be byte-stable. (self-decided, standing approval)

## Open questions

All 26 items the clarity gate raised on 2026-11-14 are answered below in C-1 to C-14 (self-decided, standing approval).

- [x] Caps vs retry, behaviour at the cap → C-1
- [x] Note/report failures, partial runs, stuck jobs, the retry step model, validity rules → C-2, C-3
- [x] Relevance threshold, citation numbering, the counts and jsonb shapes → C-4, C-5, C-6
- [x] Redis access, tests, key, lifecycle, fallback → C-7
- [x] `--provider`, the signature, replay edge cases, `--json`, exit codes → C-8
- [x] API refusal and validation, GET shape, polling, rate limit, cloud limit, double submit → C-9
- [x] Run links, tokens, markdown rendering, replay delay, routes → C-10
- [x] Guide tabs, embeddings, the chunk cut, skipped notes, file locations, layering, the settings row → C-11, C-12
- [x] Manual-test reliability, run visibility → C-13, C-14

## Clarifications

- **C-1 Caps:**
  - **The numbers:** at most 8 model calls (2 plan attempts, 5 notes and 1 report) and 13 steps (2 plan rows, 5 search, 5 note and 1 report).
  - **When the planner retried:** the runner keeps the first 5 sub-questions only if the calls left are enough for the notes and the report. With 8 calls this always holds, so the cap is a guard that never cuts a valid plan.
  - **If a cap would be exceeded anyway:** the runner stops, ends the run `failed`, and sets the error `cap_reached`.
- **C-2 Failures:**
  - **Notes:** a note whose output is invalid or throws is stored with status `failed` and its error, and the run continues.
  - **Report:** the report is written from the notes that did not fail. A report agent that throws or returns empty text ends the run `failed`, with the notes kept.
  - **Plan:** an invalid output, after one retry, ends the run `failed` (AC-2).
  - **Partial runs:** a run whose notes all failed ends `failed`, and one with at least one usable note ends `done`. Its `failed_notes` count is shown.
- **C-3 Retries and jobs:**
  - **Plan retry:** a planner retry is a second `plan` row. The first row has status `retried`, the second `ok` or `failed`, and each row has its own `ai_run_id`.
  - **The job:** `tries = 1` and `timeout = 300`. Its `failed()` method sets the run `failed`, error `job_failed`, so a crashed job never leaves a run `running`. A run older than 10 minutes in `running` is shown as `failed` (stale) when read.
  - **Validity:**
    - **Plan:** the planner output is valid when, after trimming and dropping duplicates (case-insensitive), it has 2 to 5 sub-questions of 5 to 200 characters. Longer questions are dropped, and more than 5 are cut to the first 5.
    - **Facts:** a note with more than 6 facts keeps the first 6.
- **C-4 Relevance:**
  - **Threshold:** `config('ai.docs.answer.min_similarity')` (0.5), the same as the docs answer. It is compared with each chunk's `vector_similarity`, which hybrid search returns next to its fused rank.
  - **Filtering:** chunks below the threshold are dropped before the note. When none is left, the note is `skipped`.
- **C-5 Citations:**
  - **Numbering:** sources are numbered by first appearance across the notes, in sub-question order. A chunk used by two notes keeps one number.
  - **The report prompt:** it gets the notes rewritten with those numbers (`fact [n]`) and the source list `[n] path — heading`.
  - **Parsing:** the report text is parsed with the SL-45 `CitationParser` (the same `[n]` format). Numbers that are not in the list are removed and counted.
- **C-6 Fields:**
  - **Counts on `research_runs`:** `model_calls`, `steps`, `failed_notes`, `dropped_chunk_ids`, `unknown_citations`, `tokens_in` and `tokens_out`.
  - **`sources`:** `[{n, chunk_id, source, path, heading}]`.
  - **`prompt_versions`:** `{planner, note, report}`.
  - **Step `input` and `output` by phase:**
    - **plan:** in `{topic}`, out `{sub_questions}`;
    - **search:** in `{query}`, out `{chunks: [{id, path, heading, vector_similarity, rank}]}`;
    - **note:** in `{sub_question, chunk_ids}`, out `{facts, answered, dropped_chunk_ids}`;
    - **report:** in `{fact_count, source_count}`, out `{report, unknown_citations}`.
- **C-7 Redis:**
  - **The store:** notes go through a `ResearchNotesStore` contract. The production implementation uses `Redis::connection()` (the default connection, prefix '') with the key `research:run:{id}:notes`, `HSET` per sub-question index and a 24 h `EXPIRE`.
  - **Tests:** they bind an in-memory implementation. One test exercises the Redis implementation against the DDEV `redis` service, the same container network as the database, not an external call. That test is skipped when Redis is unreachable.
  - **Lifecycle:** the report reads the hash. When the hash is missing (Redis down or expired), the runner falls back to the notes from the steps and records `notes_source: steps`.
  - **After the run:** the hash is not deleted, so it can be inspected for 24 h. "In progress" is shown by the run status, not by the key.
- **C-8 Command:**
  - **Signature:** `agent:research {topic?} {--provider=} {--json} {--replay=}`.
  - **`--provider`:** an override among the enabled text providers, the same rule as the lab request override (BR-91: request → saved → config). The model is that provider's resolved model for `research`, and AC-7 covers this resolution.
  - **`--replay`:** `--replay=<id>` with an unknown id prints "No research run with this id." and exits 2. Runs in any status replay their stored steps.
  - **`--json`:** prints one JSON object at the end (the run with its steps), in both run and replay mode.
  - **Exit codes:** 0 for `done`, 1 for `failed`, 3 for `refused`, and 2 for usage errors.
- **C-9 API:**
  - **`POST /api/research`:** `{topic, provider?}`.
    - A topic outside 3 to 300 characters is 422, with no run stored.
    - A guard match is checked in the controller and returns 200 `{id, status: refused, pattern}`, with a `refused` run stored and no job queued.
    - Otherwise 202 `{id, status: running}`.
  - **`GET /api/research/{id}`:** returns `{id, topic, status, error, sub_questions, steps: [{position, phase, sub_question_index, status, error, duration_ms, input, output, ai_run: {invocation_id, model, tokens_in, tokens_out} | null}], report_html, sources, counts, started_at, finished_at}`, or 404.
  - **Polling:** it stops on `done`, `failed` or `refused`.
  - **Rate limit:** `throttle:research` (5 per minute per IP) applies to POST only. GET has the general lab limit, so polling does not hit it.
  - **Other middleware:** cross-site refusal is the existing `RefuseCrossSiteRequests` on POST. `LimitCloudAi:research` applies on POST and counts only when the resolved provider is a cloud one.
  - **Double submit:** the page disables the button while a POST is pending, and the server does not deduplicate.
- **C-10 Rendering and replay:**
  - **Run links:** each step with a model call links to `/ai/runs/{invocationId}` (the existing run page). Tokens come from `ai_runs`.
  - **Report:**
    - **Where it renders:** the report markdown is rendered server-side with `Str::markdown($text, ['html_input' => 'escape', 'allow_unsafe_links' => false])`.
    - **What the page gets:** `report_html` in the props, plus the raw markdown.
    - **Citations:** `[n]` becomes a link to the source list entry.
  - **Replay:** client-side, 400 ms per step. NFR-3 is checked server-side: a replay's data equals the stored rows, with no outbound call.
  - **Routes:** `/ai/research` and `/ai/research/{id}` both render the Inertia page `Lab/Research`, the second with `run` set.
- **C-11 Guide and embeddings:**
  - **The six tabs:** the engine's six: works, failures, recipes, support, numbers (run counts and average calls per run from `research_runs`) and experiments.
  - **Embeddings:** the query embedding in search is not a chat model call. It does not count toward the 8-call cap, and it is logged as the docs search already logs it.
  - **The chunk cut:** 1200 characters with `mb_substr`. The step `input` stores chunk ids only, not the text.
  - **Skipped notes:** they write `{answered: false, reason: no_sources, facts: []}` to the hash and the step. Facts are used only when `answered` is true or facts are not empty.
- **C-12 Locations:**
  - **Prompts:** in `resources/prompts/`.
  - **Contracts:** `ResearchRunRepositoryInterface`, plus `ResearchNotesStore`, bound in `AiServiceProvider`.
  - **Navigation and overview:** the lab navigation gets "Research", and `LabOverviewService` gets a capability line.
  - **Settings row:** feature `research`, env `AI_RESEARCH_PROVIDER` and `AI_RESEARCH_MODEL`, which default to the text default.
- **C-13 Manual test:** step 2's "not covered" result depends on the model. Facts from loosely related chunks are possible and are recorded as observed. Every manual step assumes `research` resolves to qwen3.5:9b, and the manual test checks this first on `/ai/settings`.
- **C-14 Visibility:** the lab has no accounts, so every run, CLI runs included, is listed for anyone who can open the local lab. This is intended for a local study lab, and the guide says so.

