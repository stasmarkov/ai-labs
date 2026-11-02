# AI lab UI — Tasks

**Spec**: spec-20261103-0012-ai-lab-ui | **Size**: M | **Tasks**: 8

## Phase 1: Foundation

- [x] 0. Make the old CSS build reproducible and re-baseline parity (frontend, bench)
  Covers: MNC-1
  - [x] 0.1 `app.css`: `@import 'tailwindcss' source(none);` plus explicit `@source` for the Blade views (excluding `views/ai`), `js/app.js` and the two existing lines; add `bench/asset-fingerprint.sh`
  - [x] 0.2 Build twice with an unrelated markdown change in between: the fingerprint must not change; check `/listings/1` renders the same Tailwind classes it uses (visual check of the detail page)
  - Result (2026-11-03): `storage/framework/views/*.php` dropped from the sources as well: it was empty here, and once the lab's Blade view is compiled it would leak lab classes into `app.css`. Build with a markdown change holding new class names in between: fingerprint unchanged, `app-CjRfEL-0.css` sha256 `82042c94…ce71`, `app-BvRk9kiK.js` sha256 `e3b0c442…b855` (empty file). 40 class names generated only from markdown are gone; every class used on `/` (143) and `/listings/1` (20) has a rule (checked in the browser). Parity: only the `/listings/1` hash changed, six servers identical to each other
  - [x] 0.3 Re-capture `bench/parity/<server>.txt` on all six servers and commit it alone with the reason (non-reproducible CSS from automatic source detection); record the fingerprint

- [ ] 1. Inertia, Vue and the separate entry (frontend, backend)
  Covers: MNC-1, MNC-2
  - [ ] 1.1 Failing tests: before installing, record the manifest entries and sha256 of the built `app.css` and `app.js` (script `bench/asset-fingerprint.sh` prints them); a Pest test asserts `/ai` renders the root view `ai.app` with the `ai` Vite entry and that `/listings/1` does not include it; Inertia middleware is not in the global `web` group
  - [ ] 1.2 Install the dependencies in Scope; add `resources/views/ai/app.blade.php`, `resources/js/ai/app.ts`, `resources/css/ai.css` (tokens from the Design section, `source(none)`), `@source not` lines in `app.css`; Vite config gets the Vue plugin and the two new inputs; `tsconfig.json`; `npm run typecheck` (`vue-tsc --noEmit`)
  - [ ] 1.3 **Verify**: `npm run build` and `npm run typecheck` pass; `bench/asset-fingerprint.sh` prints the same `app.css` and `app.js` names and hashes as before; `bench/parity.sh compare` identical on nginx-fpm

- [ ] 2. Layout and design system (frontend)
  Covers: AC-9
  - [ ] 2.1 Lab layout (top bar with model tags and Ollama status, left navigation, work area plus rail) and the components named in the Design section, with keyboard focus styles and `prefers-reduced-motion`
  - [ ] 2.2 **Verify**: typecheck and build pass; a placeholder page renders with the layout at 1440×900 and 390×844 without horizontal scroll

## Phase 2: Pages

- [ ] 3. Overview (backend, frontend)
  Covers: AC-1
  - [ ] 3.1 Failing tests: `/ai` renders `Lab/Overview` with props `textProvider`, `textModel`, `embeddingProvider`, `embeddingModel`, `ollama` (`reachable` true/false via a faked HTTP call, 1 s timeout), `capabilities` (five entries with route, title, status `ready` or `coming`, slice id), `recentRuns` (20 newest, documented fields); five capability entries
  - [ ] 3.2 Implement controller, page and component
  - [ ] 3.3 **Verify**: tests green

- [ ] 4. Describe page with streaming (backend, frontend)
  Covers: AC-2, AC-3, AC-4
  - [ ] 4.1 Failing tests: `/ai/describe` renders `Lab/Describe` with a listing picker prop (10 listing ids and titles plus free id input); `GET /ai/runs/{invocationId}` returns the run JSON or 404
  - [ ] 4.2 Implement `useEventStream`, the page (output with `aria-live="polite"`, event log, metrics, Stop, run row after `[DONE]`, error, 429 and connection messages)
  - [ ] 4.3 **Verify**: tests, typecheck and build pass; in a browser on nginx-fpm with the fake provider and with Ollama: text grows, Stop works, a new run starts; screenshots saved in the report

- [ ] 5. Semantic and docs pages, `Server-Timing` (backend, frontend)
  Covers: AC-5, AC-6, MNC-2
  - [ ] 5.1 Failing tests: both search endpoints send `Server-Timing` with `embed` and `db` durations (text mode: `db` only) and unchanged bodies; `/ai/semantic` and `/ai/docs` render their pages
  - [ ] 5.2 Implement the header and both pages (similarity bars; three columns with shared-chunk highlighting)
  - [ ] 5.3 **Verify**: tests, typecheck and build pass; browser check with real queries

- [ ] 6. Placeholders and guide section 13 (frontend, docs)
  Covers: AC-7, AC-8
  - [ ] 6.1 Failing tests: `/ai/chat` and `/ai/photos` render their placeholder pages with the slice ids; doc test for `## 13. The AI lab UI: Inertia, Vue and streams in the browser` with links to the code, the spec, https://inertiajs.com, https://vuejs.org, MDN `ReadableStream` and `AbortController` and `Server-Timing`
  - [ ] 6.2 Implement and write the section; update the status line and Planned list

## Phase 3: Check

- [ ] 7. Browser and gates (all)
  Covers: AC-9, MNC-1, MNC-2, MNC-3
  - [ ] 7.1 **Verify**: every lab page at 1440×900 and 390×844: no horizontal scroll, no console errors, visible focus, contrast checked; Pint, PHPStan, Pest, `npm run typecheck`, `npm run build` green; asset fingerprint unchanged; parity identical on six servers; after committing, `bench/run.sh ping --quick` passes

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | overview page | `tests/Feature/AiLabOverviewTest.php::AC-1 the overview shows models, Ollama status, capabilities and recent runs` | test | 3 |
| AC-2 | streamed describe | manual-test.md#step-2 | manual | 4 |
| AC-3 | stop | manual-test.md#step-2 | manual | 4 |
| AC-4 | error messages | manual-test.md#step-3 | manual | 4 |
| AC-5 | semantic page | `tests/Feature/AiLabSearchPagesTest.php::AC-5 search endpoints send Server-Timing` + manual-test.md#step-4 | test + manual | 5 |
| AC-6 | docs page, three modes | manual-test.md#step-4 | manual | 5 |
| AC-7 | placeholders | `tests/Feature/AiLabPlaceholderTest.php::AC-7 chat and photos show what is coming` | test | 6 |
| AC-8 | guide section 13 | `tests/Feature/LearningGuideTest.php::AC-8 learning guide explains the AI lab UI` | test | 6 |
| AC-9 | responsive and accessible | manual-test.md#step-5 | manual | 2, 7 |
| MNC-1 | old assets byte-identical | `tests/Feature/AiLabEntryTest.php::MNC-1 the lab uses its own entry and the old pages do not load it` + manual-test.md#step-1 | test + manual | 1, 7 |
| MNC-2 | endpoint bodies unchanged | `tests/Feature/AiLabSearchPagesTest.php::MNC-2 search bodies are unchanged by Server-Timing` | test | 5 |
| MNC-3 | suite and bench | manual-test.md#step-6 | manual | 7 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`
- frontend: `ddev npm run typecheck`, `ddev npm run build`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
- [ ] Project on nginx-fpm; `npm run build` output present; old asset fingerprint unchanged
