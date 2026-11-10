---
id: spec-20261110-1149-in-app-guides
title: In-app interactive guides for every AI capability
type: feature
size: L
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-10; standing approval)
approved_at: 2026-11-10T09:53:08Z
created: 2026-11-10
stacks: [laravel]
slice: SL-48
brs: [BR-27, BR-90, BR-91]
---

# In-app interactive guides for every AI capability

## Summary

Today the site points learners away from itself: the home page, the lab overview and the Photos placeholder show slice ids (`SL-22`), guide anchors (`docs/learning-guide.md#9-streaming-an-ai-answer`) and bare repo paths (`app/Ai/CarDescriptionAgent.php`). The owner wants the site to teach by itself. Each AI capability gets an interactive guide inside the app with four fixed parts:

1. how it works;
2. how it is implemented;
3. how to modify it;
4. how to support it.

The guide also shows the measured numbers and guided experiments. Code is shown as real snippets read from the source at render time, so the guide cannot drift from the code. `docs/learning-guide.md` stays in the repo for GitHub readers, but the rendered site never links to a `.md` file or names a slice.

Source: owner request 2026-11-10 (Stanislav Markov): "instead of referencing md files on the website - let's show this info there and add interactive guides about each AI feature on how it: 1. Works; 2. How it's implemented; 3. How it can be modified; 4. How it should be supported. And the rest of useful info."

## Context

- **Problem**: `LabOverviewService::capabilities()` (`app/Services/LabOverviewService.php:63-70`) carries `slice`, `guide` (anchor) and `code` (path) for each capability. `Overview.vue:110`, `Home.vue:65,73` and `Photos.vue:26,36` render them as text. A learner on the site sees ids and paths with nothing behind them.
- **User impact**: a learner (brief: Learner, Visitor) can understand, change and run each feature without leaving the browser or opening the repo.

## Scope

### In scope

- **Guide content files**, one per guide in `resources/guides/<key>.php`. Each returns a PHP array, following the `resources/guards/prompt-injection.v1.php` pattern. Every text field is Markdown, rendered server-side.
  - **Capability guides:** `describe`, `semantic`, `nl-search`, `docs`, `chat`. The `chat` guide covers the cache, Stop and conversations.
  - **Foundation guides**, shared by every capability: `providers` (Ollama by default, cloud providers optional, switching by configuration, BR-91); `prompts` (versioned prompts); `run-log` (`ai_runs`, BR-90); `streaming` (SSE, Stop, worker mode); `guards` (input and output guards).
  - **Planned guide:** `photos`. It holds only the "how it works" part and the plan, shown as "planned".
- **Content sections** (fixed keys, all required for capability guides):
  - `works`: ordered steps. Each step has a title, a body, and optionally the stream event types that light it up.
  - `implementation`: a code walkthrough of ordered items, each with a body and one or more snippet references.
  - `modify`: recipes. Each has a goal (for example "swap the model"), steps, the snippet or config to change, and how to check it.
  - `support`: what is logged (which `ai_runs` fields), failure modes with symptom → cause → fix, which eval or test guards it, and a runbook.
  - `numbers`: measured values read from `bench/results/**` JSON at render time, each with a label and a unit.
  - `experiments`: guided experiments with a question, 2–4 predictions, the action to run on the live page, and what the measured result shows.
  - `related`: links to other guides by key.
- **Snippet references.** Three forms:
  - a PHP symbol (`App\Ai\CarDescriptionAgent::instructions`, or a class), resolved with Reflection to its exact lines;
  - a whole file of 80 lines or fewer (prompts, small config);
  - an anchored range (`file`, `from` text, `to` text), resolved by the first match of each.

  A snippet renders as code with a file caption and line numbers. A reference that does not resolve fails a test, never the page.
- **Routes and pages.**
  - `GET /ai/guides` (`ai.guides.index`): the guide index, with capability guides and foundation guides as cards.
  - `GET /ai/guides/{key}` (`ai.guides.show`): an Inertia page `Lab/Guide` with tabs **How it works / Implementation / Modify / Support / Numbers / Experiments**. The tab is kept in the URL hash. An unknown key returns 404.
- **Live sync.** Each capability page shows a collapsible "How it works" panel with the guide's `works` steps. While a run streams, the step whose event types match the latest event is highlighted, driven by the existing `useEventStream` events. A link "Open the full guide" goes to `ai.guides.show`.
- **Experiments.** The learner picks a prediction (stored in `localStorage` only), clicks "Run it" to open the live page with the experiment's `q` preset in the URL (see clarity answer 7), and then sees the experiment's answer and whether the prediction matched. No server state.
- **Remove references from the UI.**
  - `LabCapability` loses `slice`, `guide` and `code`, and gains `guideKey` (and `summary`).
  - Home, Overview and Photos show the summary and a link to the in-app guide instead.
  - The Photos placeholder shows its planned guide instead of `backlog.md` and `docs/learning-guide.md`.
  - The home page's guide link (from SL-49) points to `/ai/guides`.
  - Links to GitHub or to `BENCHMARKS.md` are not shown either. Numbers come in through the `numbers` section.
- **Guide test.** Every guide file validates against the schema (sections, keys, step event types known to the protocol), every snippet resolves, every `related` key exists, and every `numbers` path exists in `bench/results`.
- **`docs/learning-guide.md`.** Section 16 (AI code map) gets one paragraph naming `resources/guides/` as the in-app source and a new subsection describing the guide format. Its "recipes" now point to the matching guide file. Other sections stay; SL-44 reconciles them.

### Out of scope

- Rewriting `docs/learning-guide.md` sections 1–24 (that is SL-44).
- Guides for server-only topics (worker mode, Early Hints, HTTP/3, Mercure) beyond what `streaming` needs. Those are SL-44 or a later slice.
- Running code edits from the browser, or an in-browser editor.
- Syntax highlighting by a new dependency. Code is monospace with line numbers, using the lab design tokens.
- Search across guides, and translations.

## Must NOT change

- **MNC-1**: Every existing `/ai/*` live endpoint (describe stream, semantic, docs search, chat stream, NL search, run detail) keeps its request and response contract. The existing endpoint tests pass unchanged.
- **MNC-2**: Every lab page keeps the live behaviour it has today: streaming, Stop, cache badge, citations, event log and run detail. Only the capability metadata props change, and the guide panel is added.
- **MNC-3**: Non-lab routes (`/api/listings*`, catalog pages, `/up`, Mercure price pages) are unchanged, and response parity on all servers stays identical.
- **MNC-4**: The asset fingerprint of the main (non-lab) Vite entry is unchanged. Only the lab entry changes.
- **MNC-5**: `docs/learning-guide.md` keeps all its section anchors, and `LearningGuideTest` and the section-16 map test stay green.
- **MNC-6**: The test suite stays offline: no model call and no network.

## Acceptance criteria

- **AC-1**: WHEN any lab page or the home page is rendered THE SYSTEM SHALL not include, in Inertia props or in the HTML, a slice id (`SL-\d+`), a reference to a `.md` file, or a repo path outside a snippet's `file` caption. A test walks every lab route and `/` and asserts this on the serialized props and the rendered HTML.
- **AC-2** (BR-27): WHEN a learner opens `/ai/guides/{key}` for each of `describe`, `semantic`, `nl-search`, `docs` and `chat` THE SYSTEM SHALL show the six tabs, each non-empty: How it works (at least 3 steps), Implementation (at least 2 items with snippets), Modify (at least 2 recipes, including swapping the model or provider), Support (logged fields, at least 2 failure modes, the guarding eval or test, and a runbook), Numbers (at least 1 measured value, or an explicit "not measured yet" line), and Experiments (at least 1).
- **AC-3**: WHEN a guide references code THE SYSTEM SHALL render the exact current source lines of that symbol or range, with the file caption and line numbers. IF a reference does not resolve THEN `GuideContentTest` fails, naming the guide and the reference.
- **AC-4**: WHEN `/ai/guides` is opened THE SYSTEM SHALL list every capability guide and foundation guide with title and summary. The planned guide `photos` is labelled "planned". An unknown key on `/ai/guides/{key}` returns 404.
- **AC-5**: WHILE a run streams on a capability page THE SYSTEM SHALL highlight in the "How it works" panel the step mapped to the latest event type. A step with no mapped event is highlighted only when reached in order. When the stream ends, the final step is marked done.
- **AC-6**: WHEN a learner picks a prediction and clicks "Run it" on an experiment THE SYSTEM SHALL open the live page with the experiment's `q` preset prefilled (no auto-run, no endpoint change), and the guide shows the experiment's answer next to the stored prediction and whether they match.
- **AC-7** (BR-90, BR-91): WHEN the `providers` or `run-log` guide is rendered THE SYSTEM SHALL show the current provider and model from configuration and the `ai_runs` columns read from the schema, so switching `.env` changes what the guide shows without editing it.
- **AC-8**: WHEN Home, Overview or Photos is rendered THE SYSTEM SHALL link each capability to its guide (`ai.guides.show`) and its live page, showing no slice, anchor or path text.
- **AC-9**: WHEN a guide file is added under `resources/guides/` THE SYSTEM SHALL fail the map test until `docs/learning-guide.md` section 16 lists it; section 16 describes `resources/guides/` (format, the snippet reference forms, how to add a guide).

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Performance | `GET /ai/guides/{key}` p95 under 150 ms warm on frankenphp, measured with 20 sequential requests after 5 warm-up requests. Rendered guides are cached per key in the default cache, keyed by the content hash of the guide file plus the mtimes of the referenced files. | manual-test step 4 |
| **NFR-2** | Accessibility | Tabs follow the WAI-ARIA tabs pattern (roving focus, arrow keys, `aria-selected`). The step highlight is not conveyed by colour alone (an icon plus `aria-current="step"`). No horizontal scroll at 390 px except inside code blocks. | `GuidePageTest` markup assertions plus a browser check |
| **NFR-3** | Safety | Guide Markdown is rendered with `html_input: escape` and `allow_unsafe_links: false`. Snippets are HTML-escaped, and only files under `app/`, `config/`, `resources/`, `routes/` and `database/` can be read: no `.env`, no keys. | unit test with a hostile path and HTML in a body |

## Rollout

- **Flag**: none. This is a study lab with no production users; old props are removed in the same change.
- **Deploy order**: one deploy. Run `ddev npm run build` for the lab entry.
- **Rollback**: revert the commit. There is no migration and no data change.

## Boundaries

- **Always**:
  - Read existing lab components (`LabTag`, `LabSegmented`, `LabButton`, `LabMetric`) and reuse them.
  - Add a feature test per AC.
  - Keep guide text short, as plain-language steps.
  - Read numbers from result JSON, never hard-coded.
  - Run Pint, PHPStan, typecheck and build.
- **Ask first**:
  - Any new Composer or npm dependency (Markdown is rendered with the bundled `league/commonmark` through `Str::markdown`).
  - Any `.ddev/config.yaml` change.
  - A new database table.
- **Never**:
  - Show a slice id, a `.md` path or a GitHub link in the rendered UI.
  - Read `.env`, keys or `storage/` in a snippet.
  - Call a model in tests.
  - Change the MNC endpoints.
  - Edit `docs/learning-guide.md` anchors.

## Technical context

- **Related code**:
  - `app/Services/LabOverviewService.php` (capabilities list, top bar);
  - `app/DTOs/LabCapability.php`;
  - `resources/js/ai/Pages/Home.vue`, `Lab/Overview.vue`, `Lab/Photos.vue`;
  - `resources/js/ai/composables/useEventStream.ts` (event log);
  - `app/Ai/SseProtocol.php` and `DocsAnswerProtocol.php` (event types: `text_delta`, `sources`, `citations`, `cache_hit`, `no_context`, `refused`, `error`, plus the stream start and end events);
  - `resources/guards/prompt-injection.v1.php` (pattern for PHP array content files);
  - `bench/results/**` (numbers);
  - `docs/learning-guide.md` §16 (AI map, recipes).
- **Already implemented**: the lab layout and design system (`AiLabDesignSystemTest`), the event log, the run detail page, `LabHomeService` and the home page (SL-49, in progress), and the per-capability live pages.
- **Depends on**: SL-49 shipped (home page and rename). The build starts after the SL-49 commit.

## Clarifications (binding)

Self-decided under the owner's standing approval ("use proposed and recommended always").

- **Single source.** In-app guides live in `resources/guides/*.php`. `docs/learning-guide.md` stays for GitHub readers, and section 16 points to the guide files. This slice accepts duplicated prose in guide sections 9–24, and SL-44 removes it by linking those sections to the guide files.
- **PHP arrays, not YAML or front matter.** There is no new parser dependency, the content is typed and testable, and it matches the guard file pattern.
- **Snippets come from Reflection or anchors, never line numbers in content**, so moving code does not break the guide.
- **A file caption inside a snippet is allowed.** It is context for code that is shown, not a reference to something that is not shown. Bare paths elsewhere are banned (AC-1).

## Clarity gate answers (binding, self-decided 2026-11-10)

1. **Experiment result.** "Measured result" is the experiment's static `answer`: the id of the correct prediction plus an explanation and, optionally, a `numbers` reference from `bench/results`. "Matched" means the stored prediction id equals the `answer` id. The learner's live run is not sent back to the guide. The guide says "run it and compare with what you see".
2. **Numbers.**
   - A number is `{label, unit, file, path}`. `file` is a fixed path relative to `bench/results/` (a named run, never "latest"), and `path` is a dot path read with `Arr::get` (for example `qwen.exact_match`).
   - A missing file or path fails `GuideContentTest`.
   - "Not measured yet" is shown only for a number declared `{label, pending: true}`, and for a guide with an empty `numbers` list.
3. **Cache scope.** Only the static render is cached (Markdown HTML plus resolved snippets), keyed by the guide file hash and the mtimes of the referenced source files. Live facts (provider, model, `ai_runs` columns) and `numbers` are read on every request after the cache, so `.env`, schema and new bench JSON show at once.
4. **Paths in prose.**
   - Recipes, steps and support text name classes, config keys (`ai.default`) and env vars (`AI_DEFAULT_PROVIDER`), never repo paths.
   - Anything to change is shown as a snippet, and its caption carries the path.
   - AC-1 covers every lab route including `/ai/guides` and `/ai/guides/{key}` for every key, plus `/`. Only `snippets[*].file` is excluded.
   - The run detail route is walked with a factory `ai_runs` row.
5. **Step events.**
   - `App\Guides\GuideEventTypes::KNOWN` is the one source of truth. Stream events: `stream_start`, `text_delta`, `stream_end`, `sources`, `citations`, `cache_hit`, `no_context`, `refused`, `error`. Request lifecycle events, emitted client-side by the non-streaming pages (semantic, nl-search, docs): `request_sent`, `response_received`, `validation_failed`, `request_failed`.
   - Describe and chat drive the panel from `useEventStream`. Semantic, nl-search and docs emit the lifecycle events from their existing request code.
   - The panel tracks a current step index. An event mapped to step N moves the index to N. A step with no mapped event is "reached" when the next mapped step fires, and is then marked done along with every earlier step.
   - `stream_end` or `response_received` marks every step done. `error`, `request_failed` and `validation_failed` mark the current step failed (icon plus text).
6. **Schema per guide kind.**
   - `kind: capability` requires `works`, `implementation`, `modify`, `support`, `numbers` and `experiments`, with the AC-2 minimums.
   - `kind: foundation` requires `works`, `implementation`, `modify` and `support`. `numbers` and `experiments` are optional, and empty tabs are hidden.
   - `kind: planned` requires `summary`, `works` (at least 1 step) and `plan` (a list of Markdown bullets). Its tabs are How it works and Plan.
   - Every guide has `key`, `title`, `summary`, `kind` and `related`.
7. **Presets.**
   - The only preset parameter is `q`. It prefills the query field on semantic, nl-search, docs and chat, and the free-text field on describe if one exists. When describe has none, its experiments have no preset and link to the page.
   - Prefill happens client-side from the URL, and the page does not auto-run.
   - No endpoint gains an input. There is no `model` preset: the model is switched only through `.env` (the Modify recipe).
8. **Snippets.**
   - A Reflection range starts at the docblock (or the first attribute) and ends at the closing line.
   - Anchored range: `from` is the first line containing the text, and `to` is the first line containing its text after `from`, inclusive. Each anchor text must occur exactly once in the file; the test enforces it.
   - A whole-file reference over 80 lines fails the test.
   - Non-PHP files (Vue, TS, Markdown prompts, config) are referenced only by whole file or by anchor.
9. **Verification without a JS runner.** No JS test runner is added (that would be a new dependency, so ask first). `activeStep` is a pure function in `useGuideSteps.ts`. Its behaviour is verified in the browser (manual step 2) and by acceptance in the build's visual phase. ARIA (NFR-2) is asserted by `GuidePageTest` on the `Lab/Guide.vue` source (`role="tablist"`, `role="tab"`, `aria-selected`, `aria-current="step"`), the same way `AiLabDesignSystemTest` checks sources, plus the browser check.
10. **Event source of truth.** This is `GuideEventTypes::KNOWN` (point 5). A test asserts that every server-emitted type in `SseProtocol` and `DocsAnswerProtocol` is in the list. The chat cache-hit step maps to `cache_hit`.
11. **Summary, navigation and panel props.**
   - `summary` lives only in the guide file. `LabOverviewService::capabilities()` reads `summary` and sets `guideKey` from `GuideRepository`.
   - The navigation order is Overview, Guides, then the capabilities.
   - Each capability `Show*Controller` adds `howItWorks` (steps with their event types) from `GuideRepository::steps($key)`.
12. **AC-9 listing.** Section 16 lists each guide as `` `resources/guides/<key>.php` `` in code ticks, one bullet per file. The map test checks every file on disk is present. The build starts only after the SL-49 commit has landed; if SL-49 is not shipped, ship-feature is not launched.

## Open questions

- [x] Clarity gate (12 items) answered above.
- [x] Accepted assumption: BR-27, BR-90 and BR-91 are rules in a brief whose status is `draft`. The owner wrote them and has applied them in every slice since 2026-10-30 (standing approval).
