---
id: spec-20261119-2146-scene-lessons
title: AI lab scene lessons
type: feature
size: L
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-19; standing approval)
approved_at: 2026-11-19T19:49:42Z
created: 2026-11-19
stacks: [laravel]
slice: SL-55
brs: [BR-26, BR-27]
---

# AI lab scene lessons

## Summary

The owner wants interactive guides in the style of step-by-step animated system-design lessons (reference: designed-to-scale.vercel.app, studied on 2026-11-19), not the SL-54 formats.

A **scene lesson** is a sequence of 8–13 steps, each with a role: Problem, Solution, Careful or Useful. Every step changes four things at once:

- **A live SVG scene** of our own parts, with animated packets moving along its edges:
  - **Parts:** browser, Laravel app, FrankenPHP threads or workers, queue, Ollama, pgvector, Redis, cloud.
  - **Packets:** request, streamed token, tool call, embedding, cache hit, failure.
  - **Badges on parts:** load %, queue count, a "busy" state.
- **A KPI strip** of measured numbers, read from committed result files.
- **A "What changed" list** of deltas, such as `+ 4 streams` or `search p95 64 ms → 2724 ms`.
- **A narrative panel** with a callout.

Controls:

- a step bar with Back and Next;
- keys: ← → to move, R to replay, Space to pause;
- full-screen scene;
- a "You will learn" checklist that ticks as steps pass;
- a course sidebar with modules, lessons and progress, saved in `localStorage`.

This slice builds the engine, the course shell, the **pilot lesson "One model, many users"** from the SL-28 and SL-15 measurements, and a course outline. It closes SL-54: the owner chose this format over its five variants (2026-11-19).

The visual design is our own, using the lab's palette and type with flat or 2.5D SVG. No artwork, layout or text is copied from the reference.

## Context

The reference pattern was observed on the load-balancing lesson (steps 1 and 4) and caching step 3:

- **Course shell:** 9 modules and 40 lessons, with a progress counter.
- **Lesson header:** time, level, step count.
- **KPI strip:** users, RPS, p95, errors, healthy servers.
- **Scene:** an isometric SVG, with CPU % badges, "23 in queue" badges and colour-coded packets with a legend.
- **Bottom stepper:** numbered steps.
- **Right panel:** step role, title, text, a callout ("Careful"/"Useful"), and "What changed" with `+` and `~` deltas.
- **Learn list:** ticks as you go.
- **Keyboard hints:** ← →, R, Space.
- The reference's Practice and Lab tabs are "coming soon" and are out of scope here.

**Our data:** `bench/results/llm/2026-11-13/run.json` (speed, failover), `bench/results/llm-load/2026-11-13/concurrency.json` (1/4/16 streams), `bench/results/llm-load/2026-11-13/search-under-load.json` (search p95 per mode), `bench/results/metrics/2026-11-16/*.json` (threads, queue, workers).

**Existing pieces:**

- the guide engine (`app/Guides/*`, `resources/guides/*.php`, numbers via `file` and `path` into `bench/results`);
- the lab shell and `bench/ui-audit.mjs`;
- `NoReferencesInUiTest`;
- the SL-54 format pages under `/ai/guides/photos/formats`.

## Scope

### In scope

1. **Lesson data format.** Lessons are PHP files at `resources/lessons/<module>/<lesson>.php`, plus `resources/lessons/course.php`, which lists modules and lessons in order. Each lesson has:
   - `title`, `summary`, `minutes`, `level`, `learn` (3–6 outcomes) and `guide` (an existing guide key for "go deeper" links);
   - `scene`:
     - `nodes`: id, kind (from a fixed set: `browser`, `app`, `threads`, `workers`, `queue`, `model`, `vector-db`, `cache`, `cloud`, `users`), label, x and y on a fixed grid;
     - `edges`: from and to;
     - `legend`;
   - `kpis`: id, label, unit, format;
   - `steps` (8–13). Each step has:
     - `role` (`problem`, `solution`, `careful`, `useful` or `summary`, C-1), `title`, `body` (1–3 short paragraphs);
     - `callout`: kind and text;
     - `state`:
       - per node: a load % (0–100), a queue count, a status (`ok`, `busy`, `down`, `hidden`) and an optional badge text;
       - per edge: packet kinds and a rate (0–5), and whether the edge is visible;
     - `kpis`: each value is either a literal or `{file, path, scale?, round?}`, read from `bench/results`;
     - `changed`: a list of `{sign: + | ~ | -, text}`. Text may embed `{kpi:id}` or `{num:file#path}` placeholders, resolved server-side;
     - `learn`: the outcome ids this step ticks;
     - `deeper`: an optional guide tab anchor.
2. **Loading and validation** (`App\Lessons\LessonRepository` and `LessonService`, following the guide engine patterns; see C-2 to C-10):
   - the schema is checked when a lesson loads; unknown node kinds, missing edges, missing outcomes, a step count outside 8–13 and unknown roles are errors;
   - every `{file, path}` must resolve to a number in a committed file, otherwise loading fails;
   - the resolved lesson is memoized for the request.
3. **Routes and pages:**
   - `/ai/learn` is the course page. It lists modules and lessons, with duration, level, step count and a progress bar from `localStorage`.
   - `/ai/learn/{module}/{lesson}/{step?}` is the lesson page.
   - An unknown lesson or step returns 404.
   - The step number is in the URL, so a step can be linked to and Back and Forward work.
   - The lab navigation gets "Learn". `/ai/guides` and the related guides link to the lessons, and each lesson links back to its guide.
4. **Lesson page** (`Lab/Lesson.vue`, with components `SceneCanvas`, `KpiStrip`, `StepPanel`, `StepBar`, `LearnList` and `CourseSidebar`):
   - **SceneCanvas:** an SVG rendering of nodes and edges, plus packets animated along edges with `requestAnimationFrame`, at a density set by the step's rates.
     - Node load is shown by fill and a bar; queue counts appear as a badge.
     - It transitions smoothly between steps (CSS and SVG transitions, about 400 ms).
     - Pause and Replay control it.
     - It honours `prefers-reduced-motion`: packets become static dashed markers, with no movement.
     - A full-screen toggle uses the Fullscreen API with an Esc fallback.
   - **KpiStrip:** the KPIs for the current step. A value that got worse is shown in red, and one that improved in green.
   - **StepPanel:** role badge, title, body, callout, "What changed" and a "Go deeper" link to the guide tab.
   - **StepBar:** numbered steps with titles, Back and Next ("Next: <next title>"), and keyboard hints.
   - **Keyboard:** ← → move, R replays, Space pauses; these are ignored while typing in an input.
   - **Accessibility:** an `aria-live` region announces the step title. The scene has a text equivalent: a visually hidden list of node states and the step's changes.
   - **LearnList:** shows the outcomes, ticked as their steps are passed.
   - **CourseSidebar:** modules and lessons with done counts. A lesson is done once its last step has been viewed. The sidebar collapses below 1024 px.
5. **Pilot lesson** `running-models/one-model-many-users`, 10 steps, numbers from committed files only:
   1. **Problem, "One user":** the browser asks for a description. First token in about 0.3 s, then about 52 tokens/s (`llm` run).
   2. **Useful, "What a token stream is":** packets are tokens. TTFT against generation speed.
   3. **Problem, "Four users":** the model queue fills. TTFT p50 is about 6.6 s; a started stream still writes at full speed (`concurrency` level 4).
   4. **Problem, "Sixteen users":** TTFT p50 is about 31 s. The model answers one request at a time (`concurrency` level 16).
   5. **Careful, "The rest of the site":** on worker mode, search p95 goes from 64 ms to about 2724 ms, because 4 streams hold all 4 workers (`search-under-load`).
   6. **Solution, "More threads than streams":** with classic autoscale threads, search only slows from 132 to 237 ms (`search-under-load`). Metrics show busy against total threads (`metrics` 2026-11-16).
   7. **Solution, "Take streams off the request workers":** queue plus live updates (Mercure). A concept step with literal KPIs marked "not measured".
   8. **Careful, "When the model is gone":** an unreachable server fails within about 134 ms; a timeout holds the request for about 1 s (`llm` failover).
   9. **Useful, "A smaller model":** speed and memory figures from the `llm` run for both models.
   10. **Summary:** the four ideas and links to guides.

   Every number in the list above is read from a file in the build. The approximate values are only for orientation.
6. **Course outline:** `course.php` lists three modules with eight lessons. Only the pilot is playable; the rest show as "coming soon" with a one-line description:
   - **Running models:**
     - one model, many users (the pilot);
     - streaming an answer;
     - choosing a model and provider.
   - **Answers you can trust:**
     - structured output and validation;
     - retrieval with citations;
     - caching answers.
   - **Agents:**
     - a tool loop with caps and confirmation;
     - MCP and a research agent.
7. **SL-54 closure:**
   - Record the owner's pick in `.ai/specs/spec-20261117-2243-guide-format-variants/decisions.md`: the scene-lesson format replaces the five variants. "Follow one photo" content may be reused as a later lesson.
   - Check task 8, set that spec to `done`, and mark backlog SL-54 shipped with the pick.
   - Keep the SL-54 pages; removing them is a follow-up decision.
8. **Docs:**
   - learning guide: a short section on scene lessons and how to author one;
   - README;
   - a foundation guide `lessons` on `/ai/guides` (six tabs plus the teach sections), with "how to write a lesson" in its recipes.

### Out of scope

- Practice and Lab modes, and quizzes.
- Lessons other than the pilot.
- Server-side progress storage or accounts.
- Translations.
- Isometric 3D artwork beyond simple 2.5D SVG shapes.
- Live data from running servers: the lessons replay measured numbers only.

## Must NOT change

- **MNC-1:** existing lab pages, guides and the SL-54 format pages behave as before (their tests are unchanged).
- **MNC-2:** no slice ids, `.md` names or repo paths in lesson UI (`NoReferencesInUiTest` extended to `/ai/learn` pages).
- **MNC-3:** the public asset fingerprint and parity are unchanged.
- **MNC-4:** no new npm or Composer dependency, no database tables, and no model or HTTP calls from lesson pages.

## Acceptance criteria

- **AC-1:** WHEN a learner opens `/ai/learn` THE SYSTEM SHALL list the three modules and eight lessons. Only the pilot is playable; the others show "coming soon".
- **AC-2:** WHEN a learner opens `/ai/learn/running-models/one-model-many-users` THE SYSTEM SHALL show step 1, with:
  - the scene;
  - the KPI strip;
  - the step panel, with role, title, body, callout and "What changed";
  - the step bar;
  - the learn list;
  - the course sidebar.
- **AC-3:** WHEN the learner presses Next, →, or a step number THE SYSTEM SHALL change the URL step, the scene state (node loads, queue badges, packet rates), the KPI values and the panel within one transition. ← and Back SHALL go back, R SHALL replay the step animation, and Space SHALL pause and resume.
- **AC-4 (BR-26):** every KPI value and every `{num:…}` in the pilot SHALL be resolved from a committed file under `bench/results`. A missing file or path SHALL fail loading, and a test SHALL fail.
- **AC-5:** WHEN a lesson file breaks the schema THE SYSTEM SHALL reject it with a clear error. Validation SHALL cover: node kinds, edges, roles, outcomes, a step count of 8–13, and the `learn` ids.
- **AC-6:** the learn list SHALL tick an outcome once a step that teaches it has been viewed. The sidebar SHALL show the lesson as done after its last step. Progress SHALL survive a reload (`localStorage`), and "reset progress" SHALL clear it.
- **AC-7:** WHEN `prefers-reduced-motion` is set THE scene SHALL show static packet markers and no movement. Every control SHALL work by keyboard, with visible focus. Step changes SHALL be announced through `aria-live`.
- **AC-8:** `/ai/learn/{module}/{lesson}/{step}` with an unknown lesson or step SHALL answer 404.
- **AC-9:** SL-54 SHALL be closed with the pick recorded (`decisions.md`, task 8 checked, backlog shipped).
- **AC-10 (BR-27):** the `lessons` guide SHALL exist on `/ai/guides`, with the teach sections. The learning guide and README SHALL describe authoring. `bench/ui-audit.mjs` SHALL pass on `/ai/learn` and the pilot lesson at 1440 and 1024 px.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Smoothness | Packet animation keeps ≥ 50 fps on the pilot's busiest step in Chrome on this machine (measured with `performance.now` frame times over 5 s) | `manual-test.md#step-3` |
| **NFR-2** | Payload | The lesson page props stay < 60 KB for the pilot (resolved data only, no source code) | `tests/Feature/LessonPagesTest.php` |
| **NFR-3** | Accessibility | Keyboard-only walk, reduced motion, aria-live, contrast ≥ 4.5:1 for text | `manual-test.md#step-2` |

## Boundaries

- **Always:**
  - read numbers from committed result files;
  - use our own visual style;
  - test the validation with broken fixtures;
  - run the UI audit;
  - update `/ai/guides` (owner rule).
- **Ask first:** none (standing approval).
- **Never:**
  - copy artwork, text or layout from the reference site;
  - invent numbers;
  - add dependencies or database tables.

## Technical context

- **Related code:**
  - `app/Guides/*` (repository, validation and number-resolving patterns);
  - `resources/guides/local-models.php` and `resources/guides/metrics.php` (number paths into the same result files);
  - `resources/js/ai/Pages/Lab/*` and `resources/js/ai/components/*`;
  - `bench/ui-audit.mjs`;
  - `tests/Feature/NoReferencesInUiTest.php`.
- **Already implemented:**
  - number resolution from `bench/results`;
  - the lab shell;
  - the UI audit;
  - the SL-54 format pages, kept.

## Decisions

- **D-1:** lessons are data files, so new lessons need no code; the engine renders a fixed set of node kinds and packet kinds. (self-decided, standing approval)
- **D-2:** step state is declarative (per node and edge). The animation is computed on the client from the rates, so the files stay small and diffable. (self-decided, standing approval)
- **D-3:** progress lives in `localStorage` only. The lab has no accounts. (self-decided, standing approval)
- **D-4:** SL-54 is closed in favour of scene lessons, on the owner's instruction of 2026-11-19, and its pages are kept for now. (owner decision)
- **D-5:** pilot step 9 is titled "Another model, same queue" instead of "A smaller model", because the result files show the second model (gemma4:12b, 8.36 GB) is not smaller than the first (qwen3.5:9b, 8.40 GB); the step teaches that a model change moves the first token but not the writing speed or the queue. (self-decided, standing approval)
- **D-6:** pilot change lines say "slowest searches" instead of "search p95", because the engine rejects any digit outside a placeholder in change lines and "p95" has one; the KPI label and body text keep "p95". (self-decided, standing approval)
- **D-7:** pilot step 6 shows the C-2 figures, `metrics.max_busy_threads` (30) and `metrics.max_total_threads` (14) from `sse-frankenphp-auto.json`, and says what they mean: the total gauge does not count the threads autoscale adds, so 30 busy against 14 total is not a server that grew past its limit. Next to them it shows `metrics.max_busy_workers` (80) and `metrics.total_workers` (4) from `sse-frankenphp-worker-4.json`, a cell of the same metrics run (2026-11-16, answer streams open). The step names this run as separate from the search test, whose p95 figures are in its first paragraph; no conclusion mixes the two. (review fix F8, standing approval)
- **D-8:** a KPI value may carry `trend: false`. The strip then shows it without a better or worse colour. The pilot uses it where a step switches scenario rather than changing one thing: step 5 goes back from sixteen users to four, step 9 switches model, and step 10 sums up. Step 10 also restates the first model's writing speed, so the summary shows one model only and its text says so. Step 3 still compares its first-token time (concurrency run, four users) with step 1's (speed run, one user): same model and the same median, so the colour stays. (review fix F5, standing approval)
- **D-9:** moving between steps is a client-side Inertia visit (`router.push` with `preserveState`), not a server visit: the lesson is sent once with the page, each step change pushes a history entry with the new step, and Back and Forward restore it from history. A deep link is still rendered by the server at the requested step. This replaces C-6's "Inertia visit". (review fix F15, standing approval)
- **D-10:** the callout is optional (the `lessons` guide always said so). The pilot gives one on every step. (review fix F7, standing approval)
- **D-11:** `deeper` names a tab key the guide page shows: `works`, `implementation`, `modify`, `support`, `numbers`, `experiments`, `plan`, `build`, `watch` or `elsewhere`, taken from `GuidePageService`. The old names in C-5 and C-14 are mapped: `failures` to `support` and `recipes` to `modify`. Any other name is an error. (review fix F1, standing approval)
- **D-12:** the guide page takes lesson titles from `course.php` only, through `LessonService::titles()`, and builds no lesson. They are not stored in the guide render cache. A cache entry would need `course.php` in its key, so it would read that file anyway, and the cache store is the database. Guides only declare `<module>/<lesson>` keys; `GuideContentTest` checks that each one is a playable lesson. (review fixes F16 and F21, standing approval)
- **D-13:** only lessons listed in `course.php` and not marked coming soon can be played. A lesson file the course does not list (a draft) answers 404. (review fixes F9 and F12, standing approval)

## Open questions

All 31 items raised by the clarity gate on 2026-11-19 are answered in C-1 to C-16 below (self-decided, standing approval). The JSON paths were checked against the committed files.

- [x] Roles, the summary step → C-1
- [x] Exact number paths for the pilot → C-2
- [x] KPI direction, literals and BR-26 → C-3
- [x] State inheritance, edge ids, packet kinds, rates, legend → C-4
- [x] Field syntax: format, scale, round, path, placeholders, callout, deeper, grid → C-5
- [x] Outcomes, URLs, history, coming soon → C-6
- [x] Progress rules and storage → C-7
- [x] Keys, pause, replay, reduced motion, fullscreen, transitions → C-8
- [x] Test scopes: DB writes, payload, static checks, Playwright → C-9
- [x] Memoization under workers, error form → C-10
- [x] Fingerprint, the spot-check rule, guide links, terms, audit flag, sidebar at 1024 → C-11 to C-15
- [x] Content format, hidden nodes, SL-54 state → C-16

## Clarifications

- **C-1 Roles:** the allowed roles are `problem`, `solution`, `careful`, `useful` and `summary`. A `summary` step may appear only as the last step. The pilot's step 10 is `summary`.
- **C-2 Pilot number paths.** All of these exist today. The files, relative to `bench/results/`, are:
  - `llm/2026-11-13/run.json`
  - `llm-load/2026-11-13/concurrency.json`
  - `llm-load/2026-11-13/search-under-load.json`
  - `metrics/2026-11-16/<cell>.json`

  | Step | Figure | File | Path |
  |---|---|---|---|
  | 1 | TTFT | `run.json` | `models.qwen3.5:9b.speed.ttft_ms.median` (286.1) |
  | 1 | Generation speed | `run.json` | `models.qwen3.5:9b.speed.generation_tokens_per_s.median` (51.6) |
  | 3 | TTFT p50 and events per second per stream | `concurrency.json` | `models.0.levels.1.ttft_p50_ms` and `models.0.levels.1.events_per_s_per_stream_median` |
  | 4 | TTFT p50 | `concurrency.json` | `models.0.levels.2.ttft_p50_ms` |
  | 5 | Worker-mode search p95 | `search-under-load.json` | `servers.1.without_load.p95_ms` (63.91) and `servers.1.with_load.p95_ms` (2723.57) |
  | 6 | Classic search p95 | `search-under-load.json` | `servers.0.without_load.p95_ms` (132.3) and `servers.0.with_load.p95_ms` (237.37) |
  | 6 | Busy against total threads | `metrics/2026-11-16/sse-frankenphp-auto.json` | `metrics.max_busy_threads` (30) and `metrics.max_total_threads` (14) |
  | 8 | Time to fail | `run.json` | `models.qwen3.5:9b.failover.unreachable.describe.time_to_fail_ms` (134) and `models.qwen3.5:9b.failover.timeout.describe.time_to_fail_ms` |
  | 9 | Memory | `run.json` | `models.<model>.speed.size` (bytes) for both models |
  | 9 | Speed | `run.json` | `models.<model>.speed.generation_tokens_per_s.median` for both models |

  - **Model:** the pilot uses `qwen3.5:9b`, the default text model.
  - **Path syntax:** dot notation. A model key that contains a dot is quoted in the path (`models."qwen3.5:9b".speed…`); the resolver supports quoted segments.
- **C-3 KPIs:**
  - **Direction:** each KPI declares `better: lower | higher | none`. A step's value is red when it is worse than the previous step's value and green when better. On step 1, and for `none`, it is neutral.
  - **Literals:** allowed only as non-numeric strings, such as `"not measured"` or `"—"`. The validator rejects a numeric literal in `kpis` and in `changed`, so every number comes from a file (BR-26). A non-numeric value is never compared.
- **C-4 State:**
  - **Inheritance:** each step's state is a partial patch applied on top of the previous step's resolved state. Step 1 patches the lesson's base state, which is everything `ok`, load 0, queue 0, edges visible, no packets.
  - **Edges:** each edge has an `id` (unique). Validation fails when an edge points to an unknown node, or a state patch names an unknown node or edge id.
  - **Packets:** packet kinds are a fixed enum: `request`, `token`, `tool`, `embedding`, `cache-hit`, `failure`, `health`. Each has its own colour and is shown in a fixed legend; only the kinds used in the lesson are listed.
  - **Rate:** an integer from 0 to 5. Rate n spawns n packets per second per kind on that edge, and each packet takes 1.2 s to cross. A node with `status: hidden` hides its edges.
- **C-5 Field syntax:**
  - **`format`:** `ms`, `s`, `percent`, `count`, `tokens-per-s`, `bytes` (shown as MB or GB) or `text`.
  - **`scale`:** a multiplier applied before formatting, for example 0.001 to turn ms into s.
  - **`round`:** the number of decimals.
  - **Placeholders:**
    - `{kpi:id}` renders that KPI's formatted current value with its unit;
    - `{num:file#path|format|round}` renders a file value.
  - **Callout `kind`:** `careful`, `useful` or `key-idea`.
  - **`deeper`:** a `<guide-key>#<tab>` reference. The guide and the tab must exist; this is validated against the guide repository.
  - **Grid:** node x and y are integers on a 0..1200 × 0..600 authoring grid (the card centres). The player crops the `viewBox` to the bounds of all the lesson's nodes plus room for cards and badges, kept between 1.45:1 and 1.8:1 and the same on every step, so a lesson that uses less of the grid gets larger cards. It scales to the container while keeping the aspect ratio, and in full-screen it fills the screen the same way.
- **C-6 Outcomes, URLs and coming-soon lessons:**
  - **Outcomes:** ids are `lo-1` … `lo-6`, 3 to 6 per lesson. Validation fails when a step names an unknown outcome, or when an outcome is taught by no step.
  - **URLs:**
    - steps are 1-based;
    - `/ai/learn/{m}/{l}` with no step renders step 1;
    - a step of 0, past the end or not a number gives 404;
    - moving between steps pushes a history entry (Inertia visit with `preserveState`), so Back and Forward step through the lesson.
  - **Coming-soon lessons:**
    - they are entries in `course.php` with a slug, title, one-line summary and `coming_soon: true`, and no lesson file;
    - the course page shows "coming soon" with no duration or step count;
    - their URLs give 404;
    - `level` is one of `beginner`, `intermediate` or `advanced`.
- **C-7 Progress:**
  - **Storage:** the `localStorage` key `ai-lab:learn:v1` holds `{lessons: {"<module>/<lesson>": {viewed: [step numbers], done: bool}}}`.
  - **Ticks:** an outcome ticks when any step that teaches it has been viewed.
  - **Done:** a lesson is done when its last step has been viewed, a deep link counting as viewing.
  - **Course bar:** shows lessons done out of playable lessons.
  - **Reset:** "reset progress" sits on the course page and resets all lessons; the lesson sidebar has "reset this lesson".
- **C-8 Keys and motion:**
  - **Replay (R):** restarts the step: it re-runs the transition from the previous step's state and restarts the packets.
  - **Pause (Space):** freezes the packets and the transitions. Moving to another step while paused keeps the pause, and the new state is shown without packets until resumed.
  - **Key handling:** keys are ignored when a modifier is held (Ctrl, Cmd, Alt) or focus is in an input, textarea, select or contenteditable element. Space is also ignored when focus is on a button or link, so it keeps its native action there; otherwise Space is prevented from scrolling.
  - **Reduced motion:** turns the transitions off too (state is swapped at once) and shows packets as static markers.
  - **Full-screen:** covers the scene with the KPI strip. If the Fullscreen API is missing, a CSS overlay is used that closes with Esc.
  - **On change:** KPI and panel content swap at once, and the number changes are announced. Packets start playing on load unless reduced motion is set or the lesson is paused.
- **C-9 Test scope:**
  - **Writes:** "No database writes" means no write to the app tables. Session writes from the framework are excluded; the test uses the array session driver.
  - **NFR-2:** measured as `strlen(json_encode($page->props))` from the Inertia test response, including the sidebar and course data.
  - **Static checks:** the "static tests" in task 3.2 read the Vue sources: `aria-live`, a `prefers-reduced-motion` branch, and the guard that ignores keys in inputs and with modifiers.
  - **Browser walk:** it uses the cached Playwright 1.55 through `npx`, as `bench/ui-audit.mjs` already does, which is not a new dependency. The walk is recorded as text under task 3 in tasks.md.
- **C-10 Memoization and errors:**
  - **Memoization:** the repository is a scoped binding (`$this->app->scoped`), so its cache lives for one request and is safe in worker mode.
  - **Errors:** a broken lesson throws `InvalidLessonException`, with the file and the rule broken in the message. The lesson routes then answer 500 for that lesson only, and `/ai/learn` lists it as unavailable rather than failing. `LessonRepositoryTest` loads every committed lesson, so a broken file fails the suite before it ships.
- **C-11 Fingerprint:** the fingerprint covers only the public site's `app-*.css` and `app-*.js` (the `resources/css/app.css` and `resources/js/app.js` entries). The lab bundle is separate, so new lab pages do not change it; SL-53 and SL-54 kept the same values.
- **C-12 The spot-check:** `LessonContentTest` checks three values exactly: the TTFT median (286.1), worker-mode search p95 with load (2723.57) and classic with load (237.37). Each must appear in BENCHMARKS.md as rounded there (286, 2724, 237), checked with a plain substring search. No Markdown parsing.
- **C-13 Guide links:** guides declare lessons with a new optional `lessons` key, a list of `<module>/<lesson>`. The `local-models` and `metrics` guides list the pilot, and the guide page shows "Learn it step by step" links. "Learn" goes in the lab navigation after "Guides".
- **C-14 Terms:**
  - **Foundation guide:** a guide not tied to one lab page.
  - **Guide tabs:** the six engine tabs (works, failures, recipes, support, numbers, experiments), plus the build, watch and elsewhere sections.
  - **Go deeper:** the step's `deeper` link.
  - **README:** a "Lessons" paragraph with the URL and how to write one.
  - **BR-26:** measured numbers with a test.
  - **BR-27:** explain how it works in the guides.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
- **C-15 Audit and layout:**
  - **The flag:** `bench/ui-audit.mjs --only <path>` exists (`--only learn,learn/running-models/one-model-many-users`).
  - **The sidebar:** collapses below 1024 px, and is open at 1024.
- **C-16 Content and SL-54:**
  - **Text format:** `body` and callout text are plain text with `**bold**` and `` `code` `` only, rendered by a small escaped formatter with no HTML.
  - **"What changed" signs:** `+` added, `-` removed, `~` changed.
  - **Scene text equivalent:** a visually hidden `<ul>` with one line per visible node ("Model server: busy, load 100 %, 23 queued"), followed by the step's changes.
  - **Hidden nodes:** they and their edges are not drawn.
  - **SL-54:** if its task 8 or its status is already set, step 5 only verifies that and does not repeat it.

