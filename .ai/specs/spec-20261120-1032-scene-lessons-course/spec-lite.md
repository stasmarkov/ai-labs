---
id: spec-20261120-1032-scene-lessons-course
title: Scene lessons for the remaining course outline
type: feature
size: L
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-20; standing approval)
approved_at: 2026-11-20T08:35:05Z
created: 2026-11-20
stacks: [laravel]
slice: SL-56
brs: [BR-26, BR-27]
---

# Scene lessons for the remaining course outline

## Summary

SL-55 shipped the scene-lesson engine, the player at `/ai/learn` and the pilot lesson "One model, many users". `resources/lessons/course.php` lists seven more lessons as "coming soon". This slice writes all seven as lesson data files and makes them playable. Each lesson:

- has 8–13 steps (problem → solution, careful, useful, summary);
- uses the existing engine and player;
- reads every number from committed files under `bench/results`.

Where no measurement exists, the lesson uses a non-numeric literal ("not measured") and says so in the text. This keeps BR-26 and SL-55's no-numeric-literal rule.

The engine stays as it is. The one exception is up to three new node kinds, added only if a lesson cannot be drawn honestly with the current set (see Scope 2).

## Context

- **Engine (SL-55):**
  - `app/Lessons/*` (repository, service, numbers);
  - node kinds `browser, app, threads, workers, queue, model, vector-db, cache, cloud, users`;
  - packet kinds `request, token, tool, embedding, cache-hit, failure, health`;
  - callouts `careful, useful, key-idea`;
  - step state is a partial patch;
  - `deeper` points at a real guide tab;
  - `trend: false` shows a value without a better/worse colour;
  - the player is scene-first, with a 1200×600 authoring grid cropped to the node bounds.
- **Measurements available**, all under `bench/results/`:

  | Topic | Files |
  |---|---|
  | Streaming and stop | `chat-stop/2026-11-03`, `slow-load/*` (SSE cells), `llm/2026-11-13/run.json` (TTFT, tokens/s) |
  | Models and providers | `llm/2026-11-13/run.json` (two models: speed, memory, failover, evals copied from the feature evals); `nl-search/2026-11-10`, `moderation/2026-11-12`, `assistant/2026-11-12`, `photo-vision/2026-11-11` (per-model validity and accuracy) |
  | Structured output | `nl-search`, `moderation`, `photo-vision` (first-try validity, retry and invalid rates, accuracy) |
  | Retrieval | `rag-eval/2026-11-03` (recall@k, MRR, relevance threshold), `2026-11-02-docs-index/{index,search-bench}.json` |
  | Caching | `docs-cache/2026-11-03` (calibration, latency per path) |
  | Tool loop | `assistant/2026-11-12` (tool precision, steps, first-try validity, step-limit) |
  | Research agent | `research/2026-11-14/stats.json`; MCP has no measurements |

## Scope

### In scope

1. **Seven lesson files** under `resources/lessons/<module>/<slug>.php`, one per course entry. In `course.php` each one loses `coming_soon` and gains its real `minutes` and `level`.

   | Module | Lesson | Story (problem → solution) | Main data |
   |---|---|---|---|
   | running-models | `streaming-an-answer` | A blank wait → stream tokens (TTFT against total) → a user leaves: Stop must stop the model too → stop on each server mode → what it costs to hold a worker per stream | `chat-stop`, `llm`, `slow-load` SSE |
   | running-models | `choosing-a-model-and-provider` | Two local models side by side (speed, memory, JSON-valid, accuracy) → the provider chosen per feature in settings → cloud as an option with a cost guard → failover times → a summary rule of thumb | `llm` run and the per-feature evals |
   | answers-you-can-trust | `structured-output-and-validation` | Free text breaks parsing → ask for a schema → check it again in PHP → retry once → invalid output after the retry → the measured validity and accuracy per model | `nl-search`, `moderation`, `photo-vision` |
   | answers-you-can-trust | `retrieval-with-citations` | Model guesses → chunk and embed docs → vector against hybrid search → top-k and recall → relevance threshold says "I don't know" → citations checked | `rag-eval`, `docs-index` |
   | answers-you-can-trust | `caching-answers` | The same questions cost a model call each → exact cache → semantic cache with a similarity threshold → a wrong hit (near-miss) → calibration → invalidation when docs change | `docs-cache` |
   | agents | `a-tool-loop-with-caps-and-confirmation` | The model needs data → a read tool → bad arguments rejected in PHP → step cap → a write needs a person's confirm → logged steps | `assistant` (measured); admin-assistant confirm steps as "not measured" |
   | agents | `mcp-and-a-research-agent` | The same tools for outside clients → MCP server, propose-only writes confirmed in the browser → a research agent plans, searches, notes, reports → caps and replay | `research` stats; MCP steps as "not measured" |

   Each lesson must have:
   - 3–6 outcomes, each taught by at least one step;
   - a `guide` key and `deeper` links to real tabs of the related in-app guide;
   - a scene that fits the cropped player at 1440 and 1024 px without overlap, checked by the UI audit.

2. **Node kinds:** at most three new kinds may be added (candidates: `tools`, `person`, `docs`). Each needs:
   - a simple icon of our own in `SceneIcon.vue`;
   - the colour and contrast rules of the existing kinds;
   - validator support;
   - one test.

   No new packet or callout kinds, and no other engine change.
3. **Guides:** each related guide (`streaming`, `providers`, `nl-search`, `moderation`, `photos`, `docs`, `chat`, `assistant`, `admin-assistant`, `mcp`, `research`, `local-models`) lists its lessons through the existing `lessons` key, so its page shows "Learn it step by step".
4. **Tests:** `LessonContentTest` is extended to every lesson:
   - all numbers resolve from tracked files;
   - every outcome is taught;
   - every `deeper` opens a real tab;
   - the step count is 8–13;
   - the lesson renders 200;
   - a spot-check of two numbers per lesson against `BENCHMARKS.md`, rounded as shown there, where `BENCHMARKS.md` has them.
5. **Docs:**
   - the `lessons` guide gets the course list;
   - the learning guide gets a short course note;
   - the README lists `/ai/learn` lessons.

### Out of scope

- New measurements or benchmark runs.
- Engine features (quizzes, practice, lab).
- Translations.
- Changing the pilot lesson.

## Must NOT change

- **MNC-1:** the pilot lesson and the engine behave as today; the SL-55 tests are unchanged.
- **MNC-2:** no numeric literal in any lesson. Every number comes from a tracked file.
- **MNC-3:** no slice ids, `.md` names or repo paths in the UI. The fingerprint and parity are unchanged.
- **MNC-4:** no new dependencies, tables or model calls.

## Acceptance criteria

- **AC-1:** WHEN a learner opens `/ai/learn` THE SYSTEM SHALL show all eight lessons as playable with real minutes, level and step count. No lesson is "coming soon".
- **AC-2 (BR-26):** WHEN any of the seven lessons loads, every KPI value, `changed` number and `{num}` placeholder SHALL be read from a tracked file under `bench/results`. Steps without a measurement SHALL say "not measured" in the KPI and in the text.
- **AC-3:** each lesson SHALL have 8–13 steps, 3–6 outcomes each taught by a step, a final `summary` step, and `deeper` links that open existing guide tabs.
- **AC-4:** each lesson's scene SHALL render at 1440 and 1024 px with no overlap or truncation. `bench/ui-audit.mjs` on `/ai/learn` and all eight lessons SHALL exit 0.
- **AC-5:** keyboard, reduced motion, aria-live and progress SHALL work on every lesson, as on the pilot. Progress SHALL count eight lessons.
- **AC-6 (BR-27):** the related guides SHALL link their lessons. The `lessons` guide, the learning guide and the README SHALL list the course.
- **AC-7:** any new node kind SHALL have its own icon, pass the contrast rule (text ≥ 4.5:1) and be validated.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Accuracy | Every claim in a lesson's text matches the code or the cited number; a fresh reviewer checks each lesson against code and result files | review record in tasks.md |
| **NFR-2** | Smoothness | Each lesson's busiest step keeps median frame ≤ 20 ms | `manual-test.md#step-2` |

## Boundaries

- **Always:**
  - read numbers from tracked result files;
  - check claims against the code;
  - keep each step short (body 1–3 short paragraphs);
  - keep our own visual style;
  - update `/ai/guides` (owner rule).
- **Ask first:** none (standing approval).
- **Never:**
  - invent or round numbers into the data;
  - add numeric literals;
  - change engine behaviour beyond up to three node kinds.

## Technical context

- **Related code:**
  - `resources/lessons/course.php`;
  - `resources/lessons/running-models/one-model-many-users.php` (the pattern);
  - `app/Lessons/*`;
  - `resources/js/ai/components/lesson/*` (`SceneIcon.vue`, `SceneCanvas.vue`);
  - `tests/Feature/Lesson*Test.php`;
  - `resources/guides/*.php`;
  - `BENCHMARKS.md`.
- **Already implemented:** the engine, the player, progress, validation, the guide links and the UI audit.

## Decisions

- **D-1:** all seven lessons ship in one slice. They share one format, and the engine needs no change. (self-decided, standing approval)
- **D-2:** concept steps without measurements are allowed, with an honest "not measured" and no invented numbers. (self-decided, standing approval)
- **D-3:** two node kinds are appended to `NODE_KINDS`, after the existing ten and in this order: `tools` and `person`. The other ten kinds and their order are unchanged. `tools` is used by `a-tool-loop-with-caps-and-confirmation` (the tool set the loop calls) and by `mcp-and-a-research-agent` (the MCP server). `person` is used by the same two lessons (the human who confirms a tool call, and the developer on the MCP side). The reason: no existing kind fits: `app` is our own code and would hide that tools are a separate set the model asks for, `model` and `cloud` are inference, and `users` is a crowd of browsers sending requests, not one person who decides. Each new kind has its own icon. (self-decided, standing approval)

## Open questions

All 30 points raised by the clarity gate on 2026-11-20 are answered below in C-1 to C-14 (self-decided, standing approval). SL-55's engine rules (spec-20261119-2146-scene-lessons, C-1 to C-16 and D-1 to D-13) apply unchanged.

- [x] Literals, "tracked", sources and paths, rounding, minutes and levels → C-1 to C-4
- [x] Node kinds against MNC-1 → C-5
- [x] Guide mapping and deeper tabs → C-6
- [x] Story beats against steps, roles, outcome links → C-7
- [x] Audit scope, frame timing, terms, MNC-4 test → C-8, C-9
- [x] Reviewer and record, claims without data → C-10, C-11
- [x] Docs checks, contrast, step length, slugs, progress, trend, approval, ping, gates, keys, reduced motion, 1024 → C-12 to C-14

## Clarifications

- **C-1 Literals and sources:**
  - **Literals:** the engine already accepts non-numeric KPI literals such as `"not measured"` (SL-55 C-3). `LessonContentTest` checks that every numeric value is a file pointer and every literal has no digit.
  - **"Tracked"** means listed by `git ls-files`. The existing SL-55 test runs `git ls-files --error-unmatch` on every file a lesson reads, so the same applies here.
  - **Sources:** cite the feature eval's own file (`nl-search`, `moderation`, `assistant` and `photo-vision` run files), not the copy inside `llm/run.json`.
  - **Folders:** use the newest committed dated folder per topic as of this spec: `nl-search/2026-11-10`, `moderation/2026-11-12`, `assistant/2026-11-12`, `photo-vision/2026-11-11`, `rag-eval/2026-11-03`, `docs-cache/2026-11-03`, `chat-stop/2026-11-03`, `research/2026-11-14`, `llm/2026-11-13` and `slow-load` (the SL-09 and SL-15 cells under `slow-load/2026-11-03` and `2026-11-04`, plus `metrics/2026-11-16`).
  - **Paths:** the builder reads each file and picks the exact JSON paths, and records them per lesson in tasks.md.
- **C-2 Rounding:**
  - **Data files:** lesson files never round. The engine's `round` and `format` handle display (SL-55 C-5).
  - **Spot-check:** the test formats the resolved value with the KPI's own format and round, then looks for that display string, or the number rounded as `BENCHMARKS.md` prints it, as a substring. Up to two numbers per lesson are checked. A lesson with fewer than two numbers in `BENCHMARKS.md` checks what exists, and a lesson with none is listed as skipped in the test, with the reason.
- **C-3 `course.php`:**
  - **Minutes:** about 1 minute per step, rounded up. This is metadata, not a measurement, so it is allowed as an integer.
  - **Level:** `beginner`, `intermediate` or `advanced` (the engine's `LEVELS`).
  - **Step count:** derived by the engine, not stored.
  - **MNC-2** covers KPI values, `changed` and placeholders only.
- **C-4 Slugs:** the module folders and lesson slugs are exactly the existing `course.php` entries (`running-models`, `answers-you-can-trust`, `agents`, and the seven slugs). Nothing is renamed.
- **C-5 Node kinds:**
  - **When a new kind is allowed:** only when a lesson needs a part whose role is none of the existing kinds. The candidates are `tools` (a tool registry), `person` (a human confirming) and `docs` (a document set).
  - **How:** added to `LessonRepository::NODE_KINDS` with an icon and a test. Existing SL-55 tests keep passing.
  - **MNC-1:** means the existing kinds and behaviour are unchanged. Appending to the list is allowed. Every addition and its reason goes in Decisions.
- **C-6 Guide mapping.** Each guide's `lessons` key lists the lessons below, and every `deeper` must pass the existing validation against the guide's real tab keys.

  | Lesson | Guides |
  |---|---|
  | streaming | `streaming`, `chat` |
  | choosing | `providers`, `local-models` |
  | structured | `nl-search`, `moderation`, `photos` |
  | retrieval | `docs` |
  | caching | `docs` |
  | tool loop | `assistant`, `admin-assistant` |
  | mcp-research | `mcp`, `research` |

  A guide key that does not exist is a build error.
- **C-7 Steps:**
  - **Roles:** problem, solution, careful, useful and summary (SL-55 C-1). Callout kinds are separate.
  - **Beats to steps:** each story beat becomes 1–3 steps, so the total is 8–13, with exactly one final `summary` step.
  - **Outcomes:** a step teaches outcomes through its `learn` ids (SL-55 C-6). The validator already requires that every outcome is taught.
- **C-8 Audit and frames:**
  - **The audit:** `node bench/ui-audit.mjs --only learn,learn/<module>/<slug>,…` runs over all eight lessons at the default widths (1440 and 1024), with its existing overlap, narrow-control and overflow checks. The build passes the explicit list.
  - **Frame timing (NFR-2):**
    - the busiest step is the one with the highest sum of edge rates;
    - it is measured with requestAnimationFrame sampling for 5 s in Chrome via Playwright, on this machine, with reduced motion off;
    - the median frame time is recorded in tasks.md.
- **C-9 Terms and the MNC-4 test:**
  - **Fingerprint:** `bench/asset-fingerprint.sh`, which hashes the public CSS and JS, expecting `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
  - **Parity:** `bench/parity.sh capture`, where only `captured=` may differ.
  - **MNC-4:** `LessonPagesTest` asserts that a lesson page sends no HTTP request and writes no app table. No new dependency is checked through `composer.lock` and `package-lock.json`, which stay unchanged.
- **C-10 Accuracy reviewer:**
  - **Who:** a fresh-context review agent gets each lesson file, the cited result files and the related code, and lists any claim that is not supported.
  - **Record:** the result goes into tasks.md as a table with the lesson, the claims checked, the problems found and the fixes.
- **C-11 Claims without data:**
  - **Stop:** `chat-stop/2026-11-03` has `frankenphp`, `frankenphp-worker` and `frankenphp-octane` files. Only these three modes are used; the files for removed servers are not.
  - **Not measured:** the cloud cost guard, exact-cache hits, cache invalidation, MCP confirmation and research replay are concept steps with "not measured" KPIs.
  - **Vector against hybrid search and citations:** use `rag-eval` and `docs-index` if they hold those figures; otherwise "not measured".
  - **Failover:** measured in `llm/run.json` (`models.<m>.failover`).
  - **Claims about behaviour:** behaviour claims, such as the provider set per feature or propose-only writes, are checked against the code by the C-10 review.
- **C-12 Docs and contrast:**
  - **Docs checks:**
    - `GuideContentTest` covers the `lessons` guide;
    - `DocsLinksTest` covers the learning guide and README links;
    - a small test asserts that the `lessons` guide lists all eight lessons.
  - **Contrast:** text ≥ 4.5:1 on the node's own fill in the light theme, the only theme. The SL-55 SVG contrast test is extended to new kinds.
- **C-13 Writing and progress:**
  - **Step text:** at most 3 paragraphs and 600 characters per step body. `LessonContentTest` checks it.
  - **Progress:** stays in localStorage `ai-lab:learn:v1` (SL-55 C-7). Older records stay valid, and the count becomes eight lessons from `course.php`.
  - **`trend: false`:** use it for values with no better direction, such as memory or a model switch, and for scenario switches.
- **C-14 Process:**
  - **Approval:** the spec is approved when this section is written, through the owner's standing approval.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
  - **Branch:** the workflow builds on a feature branch, and the orchestrator fast-forwards `master` and pushes, as in every slice.
  - **PHPStan:** configured and run in every slice.
  - **Keys:** ← → move, R replays, Space pauses (SL-55).
  - **Reduced motion:** packets become static markers (SL-55 C-8).
  - **At 1024:** the step panel moves below the scene (SL-55 visual pass). Scene text is legible at 1024 when the card titles render at 12 px or more.

