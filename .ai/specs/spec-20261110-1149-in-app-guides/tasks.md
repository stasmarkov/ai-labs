# In-app interactive guides — Tasks

**Spec**: spec-20261110-1149-in-app-guides | **Size**: L | **Tasks**: 9

## Phase 1: Guide engine

- [ ] 1. Guide schema, loader and snippet resolver (backend)
  Covers: AC-3, NFR-3
  - [ ] 1.1 `App\Guides\GuideRepository` loads `resources/guides/<key>.php`. It validates the schema into DTOs: `Guide`, `GuideStep`, `GuideItem`, `GuideRecipe`, `GuideSupport`, `GuideNumber`, `GuideExperiment`.
  - [ ] 1.2 `App\Guides\SnippetResolver` covers the three reference forms (PHP symbol through Reflection, a whole file of 80 lines or fewer, an anchored range) and the path allowlist. It returns `{file, startLine, endLine, code}`.
  - [ ] 1.3 `App\Guides\GuideRenderer` renders Markdown with `Str::markdown` (`html_input: escape`, `allow_unsafe_links: false`) and caches per key by content hash and the mtimes of referenced files.
  - [ ] 1.4 **Verify**: `tests/Unit/SnippetResolverTest.php` (each form, a moved method still resolves, `.env` and `../` refused, HTML escaped) and `tests/Unit/GuideRendererTest.php` (escaping, cache key changes when a source file changes)

- [ ] 2. Numbers and live facts (backend)
  Covers: AC-2, AC-7
  - [ ] 2.1 `GuideNumber` reads a JSON path from `bench/results/**`. `{file, path}` read with `Arr::get`. A missing file or path fails `GuideContentTest`; "not measured yet" only for `pending: true`. Numbers and live facts are read after the render cache.
  - [ ] 2.2 Live facts for the `providers` guide (provider and model from config) and the `run-log` guide (`ai_runs` columns via `Schema::getColumnListing`)
  - [ ] 2.3 **Verify**: a `tests/Feature/GuideFactsTest.php` config override changes the rendered provider and model

## Phase 2: Content

- [ ] 3. Capability guides (docs and backend)
  Covers: AC-2, AC-3
  - [ ] 3.1 `resources/guides/describe.php`, `semantic.php`, `nl-search.php`, `docs.php` and `chat.php`, each with all seven sections, written from the real code and the guide sections 9–24 and 16
  - [ ] 3.2 **Verify**: `tests/Feature/GuideContentTest.php` checks for every file: schema valid, minimum counts per AC-2, every snippet resolves, every `related` key exists, every number path exists, every step event type is known to the protocols

- [ ] 4. Foundation and planned guides (docs and backend)
  Covers: AC-4, AC-7
  - [ ] 4.1 `providers.php`, `prompts.php`, `run-log.php`, `streaming.php`, `guards.php`, and `photos.php` (planned: works plus plan only)
  - [ ] 4.2 **Verify**: `GuideContentTest` covers them (the planned guide has a relaxed minimum)

## Phase 3: Pages

- [ ] 5. Routes, controllers, guide pages (backend and frontend)
  Covers: AC-2, AC-4, NFR-2
  - [ ] 5.1 `ai.guides.index` and `ai.guides.show` (404 on an unknown key); the Inertia pages `Lab/Guides.vue` and `Lab/Guide.vue` (tabs with the hash, a code block component `LabCode.vue` with line numbers and caption, recipe, failure-mode and experiment components); a navigation entry "Guides"
  - [ ] 5.2 **Verify**: `tests/Feature/GuidePageTest.php` (props per tab, 404, tab ARIA attributes asserted on the `Lab/Guide.vue` source); typecheck; build

- [ ] 6. Live "How it works" panel (frontend)
  Covers: AC-5, MNC-2
  - [ ] 6.1 `LabHowItWorks.vue` on describe, semantic, nl-search, docs and chat. It takes the guide `works` steps as a prop and highlights a step from the `useEventStream` events (pure function `activeStep(steps, events, state)` in `composables/useGuideSteps.ts`).
  - [ ] 6.2 **Verify**: `tests/Feature/AiLabGuidePanelTest.php` (each page has `howItWorks` props); `GuideEventTypes` test (every protocol type listed); `activeStep` checked in the browser during a stream (manual step 2)

- [ ] 7. Experiments (frontend)
  Covers: AC-6
  - [ ] 7.1 Experiment card: the prediction (in `localStorage`), "Run it" links to the live page with preset query params, and the measured result. Live pages prefill their query field from `q` (client-side, no auto-run, no endpoint change).
  - [ ] 7.2 **Verify**: `GuidePageTest` checks every experiment's preset URL against the target page's known parameters; manual step 3

## Phase 4: Remove references, docs, checks

- [ ] 8. Strip slice, anchor and path references (backend and frontend)
  Covers: AC-1, AC-8, MNC-1, MNC-2, MNC-5
  - [ ] 8.1 `LabCapability`: drop `slice`, `guide` and `code`; add `summary` and `guideKey`. Update `LabOverviewService`, `LabHomeService`, the placeholder controller, Home, Overview and Photos. Update the existing tests that asserted `slice` or `guide` to assert `guideKey` (assertion changes only; endpoint tests untouched).
  - [ ] 8.2 Learning guide §16: a subsection "In-app guides" (format, reference forms, adding a guide, the list of guide files) plus a map test for `resources/guides/*.php`
  - [ ] 8.3 **Verify**: `tests/Feature/NoReferencesInUiTest.php` (every lab route and `/`: props JSON and HTML contain no `SL-\d+`, no `\.md\b`, and no `app/|resources/|config/|routes/|database/` paths outside `snippets[*].file`); `LearningGuideTest` green

- [ ] 9. Checks (checks)
  Covers: MNC-3, MNC-4, MNC-6, NFR-1
  - [ ] 9.1 Full suite, Pint, PHPStan, typecheck, build; `bench/parity.sh` identical; `bench/asset-fingerprint.sh` main entry unchanged; NFR-1 timing per manual step 4
  - [ ] 9.2 **Verify**: outputs recorded here; `bench/run.sh ping --quick` exit 0 after the commit

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | no slice, `.md` or path references in UI | `tests/Feature/NoReferencesInUiTest.php` | test | 8 |
| AC-2 | six non-empty tabs per capability guide | `tests/Feature/GuideContentTest.php` | test | 3 |
| AC-3 | snippets are current source; bad refs fail | `tests/Unit/SnippetResolverTest.php` | test | 1, 3 |
| AC-4 | guide index, planned label, 404 | `tests/Feature/GuidePageTest.php` | test | 4, 5 |
| AC-5 | live step highlight | `manual-test.md#step-2` | manual | 6 |
| AC-6 | experiments predict, run, compare | `tests/Feature/GuidePageTest.php` | test | 7 |
| AC-7 | provider, model and columns read live | `tests/Feature/GuideFactsTest.php` | test | 2 |
| AC-8 | home, overview and photos link to guides | `tests/Feature/NoReferencesInUiTest.php` | test | 8 |
| AC-9 | guide §16 lists guide files | `tests/Feature/LearningGuideTest.php` | test | 8 |
| MNC-1 | endpoint contracts unchanged | verify existing AiLab*Test endpoint tests pass unchanged | test | 8 |
| MNC-2 | live page behaviour unchanged | `manual-test.md#step-2` | manual | 6, 8 |
| MNC-3 | non-lab routes and parity | `manual-test.md#step-5` | manual | 9 |
| MNC-4 | main entry fingerprint | `manual-test.md#step-5` | manual | 9 |
| MNC-5 | guide anchors and tests | `tests/Feature/LearningGuideTest.php` | test | 8 |
| MNC-6 | suite offline | `manual-test.md#step-5` | manual | 9 |
| NFR-1 | guide page p95 < 150 ms | `manual-test.md#step-4` | manual | 9 |
| NFR-2 | tabs ARIA, step not colour-only, 390 px | `tests/Feature/GuidePageTest.php` | test | 5 |
| NFR-3 | escaping and path allowlist | `tests/Unit/SnippetResolverTest.php` | test | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
