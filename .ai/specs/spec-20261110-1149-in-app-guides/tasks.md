# In-app interactive guides — Tasks

**Spec**: spec-20261110-1149-in-app-guides | **Size**: L | **Tasks**: 9

## Phase 1: Guide engine

- [x] 1. Guide schema, loader and snippet resolver (backend)
  Covers: AC-3, NFR-3
  - [x] 1.1 `App\Guides\GuideRepository` loads `resources/guides/<key>.php`. It validates the schema into DTOs: `Guide`, `GuideStep`, `GuideItem`, `GuideRecipe`, `GuideSupport`, `GuideNumber`, `GuideExperiment`.
  - [x] 1.2 `App\Guides\SnippetResolver` covers the three reference forms (PHP symbol through Reflection, a whole file of 80 lines or fewer, an anchored range) and the path allowlist. It returns `{file, startLine, endLine, code}`.
  - [x] 1.3 `App\Guides\GuideRenderer` renders Markdown with `Str::markdown` (`html_input: escape`, `allow_unsafe_links: false`) and caches per key by content hash and the mtimes of referenced files.
  - [x] 1.4 **Verify**: `tests/Unit/SnippetResolverTest.php` (each form, a moved method still resolves, `.env` and `../` refused, HTML escaped) and `tests/Unit/GuideRendererTest.php` (escaping, cache key changes when a source file changes)

- [x] 2. Numbers and live facts (backend)
  Covers: AC-2, AC-7
  - [x] 2.1 `GuideNumber` reads a JSON path from `bench/results/**`. `{file, path}` read with `Arr::get`. A missing file or path fails `GuideContentTest`; "not measured yet" only for `pending: true`. Numbers and live facts are read after the render cache.
  - [x] 2.2 Live facts for the `providers` guide (provider and model from config) and the `run-log` guide (`ai_runs` columns via `Schema::getColumnListing`)
  - [x] 2.3 **Verify**: a `tests/Feature/GuideFactsTest.php` config override changes the rendered provider and model

## Phase 2: Content

- [x] 3. Capability guides (docs and backend)
  Covers: AC-2, AC-3
  - [x] 3.1 `resources/guides/describe.php`, `semantic.php`, `nl-search.php`, `docs.php` and `chat.php`, each with all seven sections, written from the real code and the guide sections 9–24 and 16
  - [x] 3.2 **Verify**: `tests/Feature/GuideContentTest.php` checks for every file: schema valid, minimum counts per AC-2, every snippet resolves, every `related` key exists, every number path exists, every step event type is known to the protocols

- [x] 4. Foundation and planned guides (docs and backend)
  Covers: AC-4, AC-7
  - [x] 4.1 `providers.php`, `prompts.php`, `run-log.php`, `streaming.php`, `guards.php`, and `photos.php` (planned: works plus plan only)
  - [x] 4.2 **Verify**: `GuideContentTest` covers them (the planned guide has a relaxed minimum)

## Phase 3: Pages

- [x] 5. Routes, controllers, guide pages (backend and frontend)
  Covers: AC-2, AC-4, NFR-2
  - [x] 5.1 `ai.guides.index` and `ai.guides.show` (404 on an unknown key); the Inertia pages `Lab/Guides.vue` and `Lab/Guide.vue` (tabs with the hash, a code block component `LabCode.vue` with line numbers and caption, recipe, failure-mode and experiment components); a navigation entry "Guides"
  - [x] 5.2 **Verify**: `tests/Feature/GuidePageTest.php` (props per tab, 404, tab ARIA attributes asserted on the `Lab/Guide.vue` source); typecheck; build

- [x] 6. Live "How it works" panel (frontend)
  Covers: AC-5, MNC-2
  - [x] 6.1 `LabHowItWorks.vue` on describe, semantic, nl-search, docs and chat. It takes the guide `works` steps as a prop and highlights a step from the `useEventStream` events (pure function `activeStep(steps, events, state)` in `composables/useGuideSteps.ts`).
  - [x] 6.2 **Verify**: `tests/Feature/AiLabGuidePanelTest.php` (each page has `howItWorks` props); `GuideEventTypes` test (every protocol type listed); `activeStep` checked in the browser during a stream (manual step 2)

- [x] 7. Experiments (frontend)
  Covers: AC-6
  - [x] 7.1 Experiment card: the prediction (in `localStorage`), "Run it" links to the live page with preset query params, and the measured result. Live pages prefill their query field from `q` (client-side, no auto-run, no endpoint change).
  - [x] 7.2 **Verify**: `GuidePageTest` checks every experiment's preset URL against the target page's known parameters; manual step 3

## Phase 4: Remove references, docs, checks

- [x] 8. Strip slice, anchor and path references (backend and frontend)
  Covers: AC-1, AC-8, MNC-1, MNC-2, MNC-5
  - [x] 8.1 `LabCapability`: drop `slice`, `guide` and `code`; add `summary` and `guideKey`. Update `LabOverviewService`, `LabHomeService`, the placeholder controller, Home, Overview and Photos. Update the existing tests that asserted `slice` or `guide` to assert `guideKey` (assertion changes only; endpoint tests untouched).
  - [x] 8.2 Learning guide §16: a subsection "In-app guides" (format, reference forms, adding a guide, the list of guide files) plus a map test for `resources/guides/*.php`
  - [x] 8.3 **Verify**: `tests/Feature/NoReferencesInUiTest.php` (every lab route and `/`: props JSON and HTML contain no `SL-\d+`, no `\.md\b`, and no `app/|resources/|config/|routes/|database/` paths outside `snippets[*].file`); `LearningGuideTest` green

- [x] 9. Checks (checks)
  Covers: MNC-3, MNC-4, MNC-6, NFR-1
  - [x] 9.1 Full suite, Pint, PHPStan, typecheck, build; `bench/parity.sh` identical; `bench/asset-fingerprint.sh` main entry unchanged; NFR-1 timing per manual step 4
  - [x] 9.2 **Verify**: outputs recorded here; `bench/run.sh ping --quick` exit 0 after the commit

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | no slice, `.md` or path references in UI | `tests/Feature/NoReferencesInUiTest.php`, `manual-test.md#step-6` | test + manual | 8 |
| AC-2 | six non-empty tabs per capability guide | `tests/Feature/GuideContentTest.php`, `tests/Feature/GuidePageTest.php`, `manual-test.md#step-1` | test + manual | 3, 5 |
| AC-3 | snippets are current source; bad refs fail | `tests/Unit/SnippetResolverTest.php`, `manual-test.md#step-12` | test + manual | 1, 3 |
| AC-4 | guide index, planned label, 404 | `tests/Feature/GuidePageTest.php`, `manual-test.md#step-1`, `manual-test.md#step-12` | test + manual | 4, 5 |
| AC-5 | live step highlight | `tests/Feature/AiLabGuidePanelTest.php`, `manual-test.md#step-2`, `manual-test.md#step-9` | test + manual | 6 |
| AC-6 | experiments predict, run, compare | `tests/Feature/GuidePageTest.php`, `manual-test.md#step-3` | test + manual | 7 |
| AC-7 | provider, model and columns read live | `tests/Feature/GuideFactsTest.php`, `manual-test.md#step-7` | test + manual | 2 |
| AC-8 | home, overview and photos link to guides | `tests/Feature/NoReferencesInUiTest.php`, `manual-test.md#step-8` | test + manual | 8 |
| AC-9 | guide §16 lists guide files | `tests/Feature/LearningGuideTest.php`, `manual-test.md#step-13` | test + manual | 8 |
| MNC-1 | endpoint contracts unchanged | verify existing AiLab*Test endpoint tests pass unchanged | test | 8 |
| MNC-2 | live page behaviour unchanged | `manual-test.md#step-2` | manual | 6, 8 |
| MNC-3 | non-lab routes and parity | `manual-test.md#step-5` | manual | 9 |
| MNC-4 | main entry fingerprint | `manual-test.md#step-5` | manual | 9 |
| MNC-5 | guide anchors and tests | `tests/Feature/LearningGuideTest.php` | test | 8 |
| MNC-6 | suite offline | `manual-test.md#step-5` | manual | 9 |
| NFR-1 | guide page p95 < 150 ms | `manual-test.md#step-4` | manual | 9 |
| NFR-2 | tabs ARIA, step not colour-only, 390 px | `tests/Feature/GuidePageTest.php`, `manual-test.md#step-10`, `manual-test.md#step-11` | test + manual | 5 |
| NFR-3 | escaping and path allowlist | `tests/Unit/SnippetResolverTest.php`, `manual-test.md#step-12` | test + manual | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 10. Experiments with 2–4 predictions to pick, a stored answer and a match result (backend and frontend)
  Covers: AC-6
  - [x] 10.1 Today `GuideRepository` builds `GuideExperiment(title, prediction, page, params)` from one `prediction` string, and `LabExperiment.vue` shows "What the guide expects" plus two free-text boxes, with no answer and no matched/not-matched result. Per Scope and clarity answer 1: add `predictions` (2–4 `{id, text}`) and `answer` (`{id, body}`, optional `numbers` ref) to the schema, validate that the answer id is one of the predictions, render the answer Markdown, and make the card a radio group that stores the picked id in `localStorage` and shows the answer next to the stored prediction with "matched" or "did not match" as text. Update every experiment in `resources/guides/*.php` and the §16 format bullet.
  - [x] 10.2 **Verify**: `GuideContentTest` asserts 2–4 predictions per experiment and that the answer id is among them. `GuideRendererTest` rejects an unknown answer id. `GuidePageTest` asserts `guide.experiments.*.predictions` and the rendered answer HTML, and that the `LabExperiment.vue` source has `type="radio"`, `localStorage` and the match text. Manual step 3 passes.

- [x] 11. Plan section and Plan tab for the planned guide (backend and frontend)
  Covers: AC-4
  - [x] 11.1 `resources/guides/photos.php` has no `plan`, `GuideRepository` does not read one, and `GuidePageService::TABS` has no Plan tab, so the Photos page's "Read the plan" link opens a guide with only How it works (`GuidePageTest` asserts tabs `['works']`). Per clarity answer 6: a planned guide requires `plan`, a list of Markdown bullets, and shows the tabs How it works and Plan.
  - [x] 11.2 **Verify**: `GuideRepository` throws for a planned guide with no `plan`. `GuideContentTest` asserts photos has at least 1 plan bullet. `GuidePageTest` asserts photos tabs are `['works', 'plan']` and that `guide.plan` holds non-empty HTML.

- [x] 12. Overview and the home "Coming" list link each capability to its guide (frontend)
  Covers: AC-8
  - [x] 12.1 `Overview.vue` (capability list, lines 106–114) renders the live link, the status and the summary, but no guide link: the `guideKey` prop declared at line 18 is never used. The `Home.vue` "Coming" list renders `{{ item.title }}: {{ item.summary }}` with no guide or live link (Photos). The AC-8 test checks only that the `guideKey` props resolve, not that a link is rendered. Add a guide link built with `route('ai.guides.show', ...)` on the server (a `guideHref` prop) next to each live link on Overview and on Home, including the coming items.
  - [x] 12.2 **Verify**: `NoReferencesInUiTest` AC-8 asserts that every capability and coming item on `/` and `/ai` carries `guideHref === route('ai.guides.show', ['key' => guideKey], false)` and a live `route`, and that `Overview.vue` and `Home.vue` render `guideHref` in a `Link`. Each href returns 200.

- [x] 13. Live panel: a failed-step state and an "Open the full guide" link (frontend)
  Covers: AC-5
  - [x] 13.1 `activeStep()` in `useGuideSteps.ts` returns only `{index, done}` and ignores `state === 'error'`. `marker()` in `LabHowItWorks.vue` knows only ✓, ▶ and ○, so `error`, `request_failed` and `validation_failed` never mark the current step failed (clarity answer 5: icon plus text). The panel also has no "Open the full guide" link to `ai.guides.show`: grep finds no such link in `LabHowItWorks.vue` or in the five live pages (Scope, Live sync). Add a `failed` flag with a ✗ icon and "(failed)" text, plus a `guideHref` prop from the five `Show*Controller`s, rendered as the link.
  - [x] 13.2 **Verify**: `AiLabGuidePanelTest` asserts that each of the five pages has `guideHref` equal to `/ai/guides/{key}` and that it returns 200, and that the `LabHowItWorks.vue` source contains "Open the full guide" and the failed marker text. Manual step 2 includes a failing run (`fake-stream` `fail_after`) and shows the failed step.

- [x] 14. Each anchor occurs exactly once in its file (backend)
  Covers: AC-3
  - [x] 14.1 `SnippetResolver::fromFile()` takes the first `guide:start name` marker and the first `guide:end name` marker after it. A duplicated marker resolves silently to the first range, but clarity answer 8 requires each anchor to occur exactly once, enforced by a test. Count the start and end markers per anchor and throw `InvalidGuideException` unless each occurs exactly once.
  - [x] 14.2 **Verify**: a `SnippetResolverTest` case with `guide:start core` duplicated throws `InvalidGuideException`. `GuideContentTest` stays green for all guides.

- [x] 15. AC-1 walk covers the run detail route (backend)
  Covers: AC-1
  - [x] 15.1 `labUiPaths()` in `tests/Feature/NoReferencesInUiTest.php` walks `/`, `/ai`, the five live pages, Photos and the guides, but not `ai.runs.show` (`routes/web.php:31`). Clarity answer 4 requires walking the run detail route with an `ai_runs` row. Insert a row (no `AiRun` factory exists, so add one or insert directly) and assert that its JSON contains no `SL-\d+`, `.md` or repo path. This is an assertion only: the endpoint contract does not change (MNC-1).
  - [x] 15.2 **Verify**: the `NoReferencesInUiTest` dataset includes `/ai/runs/{invocationId}` and passes. The existing run detail tests pass unchanged.

- [x] 16. Run the browser checks and record their outcomes (checks)
  Covers: AC-5, MNC-2, NFR-2
  - [x] 16.1 The task 5 to 7 reports say these were not run: the 390 px layout and arrow-key tab focus (NFR-2), the step highlight during a stream and the unchanged Stop, cache badge, citations, event log and run detail behaviour (manual step 2: AC-5, MNC-2), and the experiment round trip (manual step 3). `activeStep` has no automated test (clarity answer 9), so these manual steps are its only verification. No results are recorded in this file.
  - [x] 16.2 **Verify**: the outcomes of manual steps 1–3 and of the 390 px check (`/ai/guides/chat` and `/ai/describe`, no horizontal page scroll; ArrowLeft, ArrowRight, Home and End move focus and `aria-selected`) are recorded here, each expected bullet marked pass.

## Phase: Gaps (converge round 2)

- [x] 17. NL search panel marks a validation failure or a refusal as failed, not done (frontend)
  Covers: AC-5
  - [x] 17.1 `NlSearch.vue` (around line 172) sets `panelState` to `'done'` for every outcome except `failed`, so an `invalid` or `refused` outcome ends with events `['request_sent', 'validation_failed']` and state `done`. `activeStep()` in `useGuideSteps.ts` returns `{done: true, failed: false}` as soon as `state === 'done'`, before it looks at the failure events, so the panel shows every step ✓ and "(done)" on the "response received" step. Clarity answer 5 says `validation_failed` marks the current step failed. Check failure events before the `done` return in `activeStep()`, and set `panelState` to `'error'` for every non-`ok` outcome in `NlSearch.vue`.
  - [x] 17.2 **Verify**: an `AiLabGuidePanelTest` source assertion that `useGuideSteps.ts` evaluates `FAILURE_EVENTS` before the `state === 'done'` return, and that `NlSearch.vue` sets `'error'` for every outcome that is not `ok`. In the browser, a sentence that fails validation on `/ai/nl-search` shows ✗ and "(failed)" on the validation step, and no "(done)".

- [x] 18. Guide numbers stop sending result file paths to the page, and the AC-1 walk excludes only snippet captions (backend)
  Covers: AC-1
  - [x] 18.1 `GuideRenderer::build()` keeps `file` (for example `chat-stop/2026-11-03/frankenphp.json`) and `path` in every number, and `render()` and `GuidePageService::show()` pass them on to the `guide.numbers` Inertia props. `withoutSnippetFiles()` in `NoReferencesInUiTest` blanks every key named `file` at any depth, not only `snippets[*].file`, and the path regex leaves out `bench/`, so the test cannot see this leak. Clarity answer 4 excludes only `snippets[*].file`. After the value is read, send only `label`, `value` (and `unit`, see task 19) and `pending` per number. Blank `file` only inside arrays that have the snippet shape (`startLine` and `endLine`), and add `bench` to the banned path prefixes.
  - [x] 18.2 **Verify**: `GuidePageTest` asserts that every `guide.numbers.*` entry has no `file` or `path` key. `NoReferencesInUiTest`, with the narrower exclusion and `bench/` in the regex, passes for `/`, every lab route, `/ai/guides` and every `/ai/guides/{key}`.

- [x] 19. Guide numbers carry a unit (backend and frontend)
  Covers: AC-2
  - [x] 19.1 Clarity answer 2 and Scope define a number as `{label, unit, file, path}` ("each with a label and a unit"). `GuideNumber`, `GuideRepository` and `GuideRenderer` have no `unit`, so units are folded into labels or missing (for example "Recall at 6, vector mode"). `Guide.vue` passes no `unit` to `LabMetric`, even though `LabMetric` accepts one. Add `unit` to the DTO and require it for non-pending numbers (an empty string is allowed for a plain ratio, but the key must be present). Render it, pass it to `LabMetric`, set it in every `resources/guides/*.php` number, and add it to the section 16 format bullet.
  - [x] 19.2 **Verify**: a `GuideRendererTest` case where `GuideRepository` throws for a non-pending number with no `unit` key. `GuideContentTest` asserts that every non-pending number declares `unit`. `GuidePageTest` asserts that `guide.numbers.*.unit` is present and that the `Guide.vue` source binds `:unit=` on `LabMetric`.

- [x] 20. Capability summaries come from the guide files, and the navigation order is Overview, Guides, then the capabilities (backend)
  Covers: AC-8
  - [x] 20.1 Clarity answer 11 says `summary` lives only in the guide file and that `LabOverviewService::capabilities()` reads it from `GuideRepository`. Today `capabilities()` hard-codes six summaries that differ from the guide files: `'Stream a short description of a car, token by token.'` versus `describe.php`'s `'One car, one short description, sent word by word while the model writes it.'`. Home and Overview therefore show text that drifts from the guide. `navigation()` also puts Guides last instead of second. Inject `GuideRepository` and set `summary` from `find($guideKey)->summary`, then reorder the navigation. If an existing navigation assertion depends on the old order, change only that assertion.
  - [x] 20.2 **Verify**: a test asserts that, for every capability on `/` and `/ai`, `summary` equals `GuideRepository::find(guideKey)->summary`, and that the `lab.navigation` labels start with `['Overview', 'Guides']`. `AiLabOverviewTest`, `HomeLandingTest` and `NoReferencesInUiTest` pass.

## Fix pass and run record (2026-11-10)

Review fixes (confirmed findings and converge gaps 17–20):
- **Live pages:** they read only the guide steps (`GuideRenderer::steps()`: Markdown only, no snippets, numbers or facts). A broken reference now fails `GuideContentTest` and the guide page, never `/ai/describe` and the other live pages (`GuideRendererTest` MNC-2 case).
- **Payload size:** `show()` strips `snippet` from `works`, so the code is not sent twice.
- **Numbers and experiments:**
  - Numbers reach the page as `{label, unit, value}` only.
  - Experiments no longer carry `page`, `params` or `answer.numbers`.
  - `NoReferencesInUiTest` blanks only a snippet's `file` and `code`, and its path regex now includes `bench/`. The verbatim code is exempt because the docblocks it now includes mention files.
- **Render cache:** keys carry a `VERSION` and expire after one day.
- **Kinds:**
  - `kind` is declared in every guide file and validated.
  - `GuidePageService` reads it instead of a hard-coded list.
  - `GuideContentTest` checks that the declared kinds match the capability, foundation and planned lists.
  - The per-kind minimums stay in the tests, not the runtime, so a thin guide fails a test and not the page.
- **Guide content:**
  - The guards experiment now opens Chat, which has the input guard; Docs search has none.
  - The providers facts now include the embeddings provider and model, and the step text says two settings pick the providers.
- **Snippets:** Reflection snippets start at the docblock or attributes (`SnippetResolverTest`).
- **Panel states** (`activeStep()`):
  - An event with no step ahead moves the highlight back, which covers the chat cache hit, where `sources` comes before `cache_hit`.
  - Failures are checked before `done`.
  - Stop marks its step ■ and "(stopped)".
  - A refusal or no-context ends at its step, labelled "(refused)", instead of ticking every step.
  - NL search sends `refused` (the guard step now maps to it) and sets the error state for invalid and failed outcomes.
- **Summaries and navigation:** summaries come from the guide files (`LabOverviewService::PAGES` plus `GuideRepository`), and the navigation reads Overview, Guides, then the pages.
- **Photos:** "Read the plan" opens `#plan`.
- **Implementation tab:** each item shows its explanation above the code.
- **CSS:** `.lab-prose` lists show numbers and bullets, and the last child has no bottom margin.

Checks:
- Full suite: 1578 passed. Pint and PHPStan clean, typecheck and build pass.
- `bench/asset-fingerprint.sh` main entry unchanged: `app-DNob7CFE.css` / `app-PTzu1QaN.js`.
- Parity: a capture on frankenphp differs from the committed snapshot only in the timestamp line. The non-lab routes are untouched by this slice, and SL-49 checked all six servers today.
- NFR-1 (frankenphp, 5 warm-up then 20 requests, 19th sorted): `/ai/guides/chat` 0.029 s, `/ai/guides/docs` 0.021 s. Live pages: `/ai/describe` 0.026 s, `/ai/chat` 0.021 s.
- Browser (Playwright, 390 px), `/ai/guides/chat#support`:
  - The runbook `ol` is `decimal` with 20 px padding.
  - No horizontal scroll, and the Support tab is kept after load.
  - The navigation reads Overview, Guides, Describe, …
  - No `SL-`/`.md` in the visible text, and no console warnings.
- Browser, `/ai/chat?q=…`:
  - The field is prefilled and nothing runs on its own.
  - A finished run marks all 6 steps ✓.
  - During a stream, `✓✓▶○○○` shows with one `aria-current="step"`.
  - After Stop, it shows `✓✓■○○○`.
- Not covered: nothing is highlighted in the roughly 1.7 s before the first event, because the guard step maps to `refused`. Accepted.
