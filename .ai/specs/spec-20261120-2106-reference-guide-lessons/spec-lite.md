---
id: spec-20261120-2106-reference-guide-lessons
title: Scene lessons for the reference-only guides
type: feature
size: L
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-20; standing approval)
approved_at: 2026-11-20T19:08:29Z
created: 2026-11-20
stacks: [laravel]
slice: SL-58
brs: [BR-26, BR-27]
---

# Scene lessons for the reference-only guides

## Summary

SL-57 joined guides and scene lessons into one learning area at `/ai/learn`. Every guide is a topic there. 11 guides have no scene lesson yet and show as "reference" topics with "Walk through · lesson coming": `describe, prompts, playground, guards, semantic, photo-search, similar-photos, photo-duplicates, run-log, logs-restart, worker-dx`.

The owner asked for all guides in the learning format (2026-11-20). This slice writes **nine scene lessons** that cover those 11 guides, so every topic has a Walk through and the course has no reference-only topics left. The lessons use the existing engine and player and follow the SL-55/SL-56 rules:
- 8–13 steps (problem → solution, careful, useful, summary);
- every number comes from a committed file under `bench/results` (BR-26);
- no measurement → a non-numeric literal ("not measured") and the text says so.

## Context

- Engine and rules: `app/Lessons/*` (`LessonRepository`, `LessonService`, `LessonNumbers`, `LessonSources`), node kinds `browser, app, threads, workers, queue, model, vector-db, cache, cloud, users, tools, person`; packet kinds `request, token, tool, embedding, cache-hit, failure, health`; callouts `careful, useful, key-idea`; roles `problem, solution, careful, useful, summary`; `deeper` must name a real tab (`GuidePageService::tabKeys`).
- Example lessons: `resources/lessons/**/*.php` (eight lessons from SL-55/SL-56), `app/Lessons/LessonSources.php` shared helpers.
- Course data (SL-57): `resources/lessons/course.php` modules with `lessons` and `references`; validation in `LessonRepository` (each content guide owned once: linked by a lesson through its guide file's `lessons` key, or in exactly one module's `references`; every lesson named by at least one guide).
- Guide number sources (the `numbers` tabs already read these):
  - `semantic`: `2026-11-02-embeddings/search-bench.json` (also `embed-*.json` in that folder)
  - `photo-search`: `photo-search/2026-11-11/run.json`
  - `similar-photos`: `photo-similar/2026-11-11/run.json`, `photo-similar/2026-11-12/hybrid.json`
  - `photo-duplicates`: `photo-duplicates/2026-11-12/run.json`
  - `describe`: `chat-stop/2026-11-03/frankenphp.json`; also `llm/2026-11-13` (SL-28)
  - `playground`: `playground/2026-11-14/stats.json`
  - `logs-restart`: `restart/2026-11-17/*.json`, `restart/2026-11-17/log-overhead.json`
  - `worker-dx`: `reload/2026-11-17/{classic,worker-watch,worker-nowatch,octane-watch,octane-nowatch}.json`
  - `prompts`, `guards`, `run-log`: no guide numbers. Candidates: `rag-eval/2026-11-03` (guard refusals), `docs-cache/2026-11-03`; otherwise "not measured".
- Rules: BR-26 (measured numbers come from committed result files), BR-27 (guide duty); project rule: no slice ids, `.md` names or repo paths in the UI; pages checked at 1440 and 1024 px.

## Scope

### In scope

1. **Nine lesson files** under `resources/lessons/<module>/<slug>.php`, added to `course.php` with real `minutes` and `level`:

   | Module | Lesson slug | Guides (their `lessons` key) | Story (problem → solution) | Main data |
   |---|---|---|---|---|
   | running-models | `describing-a-car` | describe | A blank box while a long description is written → ask for one short description with a fixed prompt → stream it word by word → Stop ends the model call → what a description costs in time and tokens | `chat-stop`, `llm` |
   | answers-you-can-trust | `prompts-you-can-version` | prompts, playground | Wording buried in code → prompts as versioned files → a playground run stores prompt version, model and output → compare two versions or two models side by side → a saved run replays with no model call | `playground` stats |
   | answers-you-can-trust | `guards-on-both-sides` | guards | Off-topic or hostile question reaches the model → input guard refuses before any model call → answer guard checks the output (citations, leaks) → what a refusal looks like → what guards cannot catch | `rag-eval` where it holds guard counts; else "not measured" |
   | search-and-photos | `search-by-meaning` | semantic | Keyword search misses "family car for winter" → embed the query → nearest neighbours in pgvector (HNSW) → `ef_search` trades recall for time → template text makes all vectors close | `2026-11-02-embeddings` |
   | search-and-photos | `searching-photos-by-text-and-likeness` | photo-search, similar-photos | Photos have no words → embed each photo with a local image encoder → text query in the same space → nearest photos → "more like this" from a photo vector, near duplicates and weak matches left out, hybrid | `photo-search`, `photo-similar` |
   | search-and-photos | `finding-duplicate-photos` | photo-duplicates | The same photo uploaded many times → group near-identical vectors → a vision model picks the best → a person reviews; marking never deletes | `photo-duplicates` |
   | running-the-server | `the-run-log` | run-log | An AI call fails and nobody knows why → every call writes one run row (feature, provider, model, time, outcome) → errors masked on the page, cause in the row → open a run from the lab | "not measured" unless a committed file has run counts |
   | running-the-server | `logs-and-a-safe-worker-restart` | logs-restart | Plain log lines with no request id → JSON lines with the request id → what the opt-in channel costs → restarting workers drops requests on reload → native restart drops none | `restart/2026-11-17` |
   | running-the-server | `seeing-your-change-in-worker-mode` | worker-dx | You save a file and the page does not change → worker keeps old code in memory → a watcher replaces workers → time from save to new code per mode → Xdebug needs a trigger in a worker | `reload/2026-11-17` |

   Each lesson must have:
   - 3–6 outcomes, each taught by at least one step;
   - `deeper` links to real tabs of its guide(s);
   - a scene that fits the player at 1440 and 1024 px without overlap, checked by the UI audit.
2. **Course and guides:** each of the 11 guide files gains a `lessons` key naming its lesson. Their keys are removed from the modules' `references` lists (now empty) so the SL-57 ownership rule holds. The course map then shows only lesson topics; the reference-topic support stays in code.
3. **Tests:** `LessonContentTest` covers the nine new lessons as it does the eight existing ones:
   - all numbers resolve from tracked files;
   - every outcome is taught;
   - every `deeper` opens a real tab;
   - the step count is 8–13;
   - the lesson renders 200;
   - a spot-check of two numbers per lesson where `BENCHMARKS.md` has them.
   `LessonPagesTest` and `LessonRepositoryTest` expectations move to 17 lessons and no reference topics.
4. **Docs:** the `lessons` guide lists 17 lessons; README "Lessons" and `docs/learning-guide.md` "Scene lessons" list the course.

### Out of scope

- New measurements or benchmark runs.
- Engine features (new packet or callout kinds, quizzes).
- New node kinds, unless a lesson cannot be drawn honestly without one (at most two, each with its own icon, contrast and validator support, and a test).
- Changing the eight existing lessons or any guide tab content (beyond the `lessons` key).

## Must NOT change

- MNC-1: The eight existing lessons, the engine, step URLs, keyboard controls and progress storage behave as today.
- MNC-2: No numeric literal in any lesson. Every number comes from a tracked file.
- MNC-3: No slice ids, `.md` names or repo paths in the UI; the asset fingerprint (`app-DNob7CFE.css`, `app-PTzu1QaN.js`) and parity (only `captured=` differs) are unchanged.
- MNC-4: No new dependencies, tables, routes or model calls.
- MNC-5: SL-57 pages: `/ai/guides` redirect, topic header, course sidebar and grouped lab sidebar behave as shipped.

## Acceptance criteria

- AC-1: WHEN a learner opens `/ai/learn` THE SYSTEM SHALL show 17 playable lessons in five modules, with real minutes, level and step count, and no reference-only topic.
- AC-2 (BR-26): WHEN any of the nine new lessons loads, every KPI value, `changed` number and `{num}` placeholder SHALL be read from a tracked file under `bench/results`; steps without a measurement SHALL say "not measured" in the KPI and in the text.
- AC-3: Each new lesson SHALL have 8–13 steps, 3–6 outcomes each taught by a step, a final `summary` step, and `deeper` links that open existing guide tabs.
- AC-4: WHEN any of the 11 guides opens THE SYSTEM SHALL show its topic header with "Walk through" linking its lesson (no "lesson coming").
- AC-5: Each new lesson's scene SHALL render at 1440 and 1024 px with no overlap or truncation; `node bench/ui-audit.mjs` SHALL exit 0 over `/ai/learn`, all 17 lessons and all guides.
- AC-6: Keyboard, reduced motion, aria-live and progress SHALL work on every new lesson; progress SHALL count 17 lessons.
- AC-7 (BR-27): The `lessons` guide, README and `docs/learning-guide.md` SHALL list the 17 lessons.

## Quality attributes

- NFR-1: Accuracy — every claim in a new lesson's text matches the code or the cited number; a fresh reviewer checks each lesson against code and result files and the record goes into tasks.md.
- NFR-2: Smoothness — each new lesson's busiest step keeps the median frame time ≤ 20 ms (`manual-test.md#step-2`).
- NFR-3: Payload — the lesson page `data-page` JSON stays under 60 000 characters (existing `LessonPagesTest` budget).

## Boundaries

- **Always:** read numbers from tracked result files; check claims against the code; keep each step body to 1–3 short paragraphs; run PHP/npm through `ddev`; keep tests offline.
- **Ask first:** none (owner standing approval).
- **Never:** invent or round numbers into the data; add numeric literals; change engine behaviour beyond the node-kind allowance; edit `.ddev/config.yaml`.

## Technical context

- Follow the shape of `resources/lessons/agents/mcp-and-a-research-agent.php` and helpers in `LessonSources`.
- Number paths use `GuideFacts::document()/at()` with quoted path segments, as the existing lessons do.
- `bench/ui-audit.mjs` already discovers lessons and guides from `/ai/learn` (SL-57), so new lessons are audited without changes.

## Decisions

- D-1: Nine lessons for 11 guides: `prompts` + `playground` and `photo-search` + `similar-photos` share a story (versioned prompts compared in the playground; one image vector space serves text and "more like this"), so each pair becomes one lesson. The other seven guides get one lesson each (self-decided, standing approval).
- D-2: `describe` sits in Running models (its story is one model call streamed and stopped), the three server guides in Running the server, the photo and vector guides in Search and photos, as SL-57 placed them.
- D-3: Where no committed measurement exists (`prompts`, `guards`, `run-log`, parts of others), lessons say "not measured" rather than running new benchmarks; new runs are out of scope.

## Open questions

- [x] 1–19 raised by the fresh-context clarity gate on 2026-11-20; all answered below as C-1 to C-19 (owner standing approval: recommended answers).

## Clarifications

- C-1: The fingerprint covers only the landing bundle (`resources/js/app.js`, `resources/css/app.css`); the lab is a separate Vite entry (`resources/js/ai/app.ts`), so a node-kind icon does not change it. Still prefer no new node kind; at most two if a scene cannot be drawn honestly.
- C-2: MNC-2 covers learner-visible text (body, KPI, `changed`, callouts, titles). `minutes`, `level`, structural indexes and `GuideFacts::at()` path segments are allowed, as in the existing lessons.
- C-3: Use the existing non-numeric KPI literal mechanism the SL-56 lessons use for "not measured"; no engine change.
- C-4: Keep `references => []` in each module; the validator must accept an empty list (test it).
- C-5: The reference-topic path stays covered by a test-only course fixture in `LessonRepositoryTest`/`LessonPagesTest` (one module, one reference guide), asserting the reference topic shape and "lesson coming" on its guide.
- C-6: A result file is used only when it has an explicit field for the claim. `rag-eval` feeds `guards-on-both-sides` only if it has refusal/guard fields; `docs-cache` only if a story needs cache numbers.
- C-7: Run-log numbers: search `bench/results/**` for run, outcome or error count fields; none → "not measured"; record the search result in the tasks.md review table.
- C-8: The code wins. Any story beat in the Scope table that the code does not support is rewritten or dropped, and recorded in the NFR-1 review table.
- C-9: `describe` uses the fields the describe guide's numbers tab already reads (`chat-stop/2026-11-03/frankenphp.json`) plus `llm/2026-11-13` model speed fields as the SL-56 lessons read them.
- C-10: Spot-check two values per lesson when `BENCHMARKS.md` has two or more, one when it has one, none otherwise (noted in the test).
- C-11: `level` uses the existing vocabulary (beginner, intermediate, advanced); `minutes` follows the SL-56 lessons (about one per step).
- C-12: Role rules are the engine's existing validation: first step `problem`, at least one `solution`, last step `summary`; `careful`/`useful` as the story needs.
- C-13: "Taught by a step" uses the existing step → outcome link checked by `LessonContentTest`.
- C-14: AC-5 is the UI audit at 1440 and 1024 px plus a screenshot pass over every step of each new lesson at both widths during the fix pass.
- C-15: Parity = `PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity` (only `captured=` differs; restore with `git checkout -- bench/parity`).
- C-16: Busiest step = the step with the most packets moving at once. The frame-timing snippet is the one in the SL-55 manual test step 3; it is copied into this manual test.
- C-17: Review table columns: lesson, step, claim or number, source (file and field, or code location), result (ok / fixed / dropped). The reviewer is a fresh agent with no context from writing the lessons.
- C-18: Docs list modules with lesson title, level and minutes, in the existing format. Guide content changes only through the `lessons` key (and the `lessons` guide).
- C-19: "Done" stays the SL-55 trigger (reaching the last step).
