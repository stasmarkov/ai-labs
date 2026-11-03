# AI lab UI — Tasks

**Spec**: spec-20261103-0012-ai-lab-ui | **Size**: M | **Tasks**: 17

## Phase 1: Foundation

- [x] 0. Make the old CSS build reproducible and re-baseline parity (frontend, bench)
  Covers: MNC-1
  - [x] 0.1 `app.css`: `@import 'tailwindcss' source(none);` plus explicit `@source` for the Blade views (excluding `views/ai`), `js/app.js` and the two existing lines; add `bench/asset-fingerprint.sh`
  - [x] 0.2 Build twice with an unrelated markdown change in between: the fingerprint must not change; check `/listings/1` renders the same Tailwind classes it uses (visual check of the detail page)
  - Result (2026-11-03): `storage/framework/views/*.php` dropped from the sources as well: it was empty here, and once the lab's Blade view is compiled it would leak lab classes into `app.css`. Build with a markdown change holding new class names in between: fingerprint unchanged, `app-CjRfEL-0.css` sha256 `82042c94…ce71`, `app-BvRk9kiK.js` sha256 `e3b0c442…b855` (empty file). 40 class names generated only from markdown are gone; every class used on `/` (143) and `/listings/1` (20) has a rule (checked in the browser). Parity: only the `/listings/1` hash changed, six servers identical to each other
  - [x] 0.3 Re-capture `bench/parity/<server>.txt` on all six servers and commit it alone with the reason (non-reproducible CSS from automatic source detection); record the fingerprint

- [x] 1. Inertia, Vue and the separate entry (frontend, backend)
  Covers: MNC-1, MNC-2
  - [x] 1.1 Failing tests: before installing, record the manifest entries and sha256 of the built `app.css` and `app.js` (script `bench/asset-fingerprint.sh` prints them); a Pest test asserts `/ai` renders the root view `ai.app` with the `ai` Vite entry and that `/listings/1` does not include it; Inertia middleware is not in the global `web` group
  - [x] 1.2 Install the dependencies in Scope; add `resources/views/ai/app.blade.php`, `resources/js/ai/app.ts`, `resources/css/ai.css` (tokens from the Design section, `source(none)`), `@source not` lines in `app.css`; Vite config gets the Vue plugin and the two new inputs; `tsconfig.json`; `npm run typecheck` (`vue-tsc --noEmit`)
  - [x] 1.3 **Verify**: `npm run build` and `npm run typecheck` pass; `bench/asset-fingerprint.sh` prints the same `app.css` and `app.js` names and hashes as before; `bench/parity.sh compare` identical on nginx-fpm

- [x] 2. Layout and design system (frontend)
  Covers: AC-9
  - [x] 2.1 Lab layout (top bar with model tags and Ollama status, left navigation, work area plus rail) and the components named in the Design section, with keyboard focus styles and `prefers-reduced-motion`
  - [x] 2.2 **Verify**: typecheck and build pass; a placeholder page renders with the layout at 1440×900 and 390×844 without horizontal scroll

## Phase 2: Pages

- [x] 3. Overview (backend, frontend)
  Covers: AC-1
  - [x] 3.1 Failing tests: `/ai` renders `Lab/Overview` with props `textProvider`, `textModel`, `embeddingProvider`, `embeddingModel`, `ollama` (`reachable` true/false via a faked HTTP call, 1 s timeout), `capabilities` (five entries with route, title, status `ready` or `coming`, slice id), `recentRuns` (20 newest, documented fields); five capability entries
  - [x] 3.2 Implement controller, page and component
  - [x] 3.3 **Verify**: tests green

- [x] 4. Describe page with streaming (backend, frontend)
  Covers: AC-2, AC-3, AC-4
  - [x] 4.1 Failing tests: `/ai/describe` renders `Lab/Describe` with a listing picker prop (10 listing ids and titles plus free id input); `GET /ai/runs/{invocationId}` returns the run JSON or 404
  - [x] 4.2 Implement `useEventStream`, the page (output with `aria-live="polite"`, event log, metrics, Stop, run row after `[DONE]`, error, 429 and connection messages)
  - [x] 4.3 **Verify**: tests, typecheck and build pass; in a browser on nginx-fpm with the fake provider and with Ollama: text grows, Stop works, a new run starts; screenshots saved in the report

- [x] 5. Semantic and docs pages, `Server-Timing` (backend, frontend)
  Covers: AC-5, AC-6, MNC-2
  - [x] 5.1 Failing tests: both search endpoints send `Server-Timing` with `embed` and `db` durations (text mode: `db` only) and unchanged bodies; `/ai/semantic` and `/ai/docs` render their pages
  - [x] 5.2 Implement the header and both pages (similarity bars; three columns with shared-chunk highlighting)
  - [x] 5.3 **Verify**: tests, typecheck and build pass; browser check with real queries

- [x] 6. Placeholders and guide section 13 (frontend, docs)
  Covers: AC-7, AC-8
  - [x] 6.1 Failing tests: `/ai/chat` and `/ai/photos` render their placeholder pages with the slice ids; doc test for `## 13. The AI lab UI: Inertia, Vue and streams in the browser` with links to the code, the spec, https://inertiajs.com, https://vuejs.org, MDN `ReadableStream` and `AbortController` and `Server-Timing`
  - [x] 6.2 Implement and write the section; update the status line and Planned list

## Phase 3: Check

- [x] 7. Browser and gates (all)
  Covers: AC-9, MNC-1, MNC-2, MNC-3
  - [x] 7.1 **Verify**: every lab page at 1440×900 and 390×844: no horizontal scroll, no console errors, visible focus, contrast checked; Pint, PHPStan, Pest, `npm run typecheck`, `npm run build` green; asset fingerprint unchanged; parity identical on six servers; after committing, `bench/run.sh ping --quick` passes

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | overview page | `tests/Feature/AiLabOverviewTest.php::AC-1 the overview shows models, Ollama status, capabilities and recent runs` + manual-test.md#step-2 | test + manual | 3 |
| AC-2 | streamed describe | manual-test.md#step-3 | manual | 4 |
| AC-3 | stop | manual-test.md#step-4 | manual | 4 |
| AC-4 | error messages | manual-test.md#step-5 | manual | 4 |
| AC-5 | semantic page | `tests/Feature/AiLabSearchPagesTest.php::AC-5 search endpoints send Server-Timing` + manual-test.md#step-6 | test + manual | 5 |
| AC-6 | docs page, three modes | manual-test.md#step-7 | manual | 5 |
| AC-7 | placeholders | `tests/Feature/AiLabPlaceholderTest.php::AC-7 chat and photos show what is coming` + manual-test.md#step-8 | test + manual | 6 |
| AC-8 | guide section 13 | `tests/Feature/LearningGuideTest.php::AC-8 learning guide explains the AI lab UI` + manual-test.md#step-9 | test + manual | 6 |
| AC-9 | responsive and accessible | `tests/Feature/AiLabDesignSystemTest.php::AC-9 the lab text colours reach a contrast of 4.5:1 on the paper` + manual-test.md#step-10 | test + manual | 2, 7 |
| MNC-1 | old assets byte-identical | `tests/Feature/AiLabEntryTest.php::MNC-1 the lab uses its own entry and the old pages do not load it` + manual-test.md#step-1 | test + manual | 1, 7 |
| MNC-2 | endpoint bodies unchanged | `tests/Feature/AiLabSearchPagesTest.php::MNC-2 search bodies are unchanged by Server-Timing` + manual-test.md#step-11 | test + manual | 5 |
| MNC-3 | suite and bench | manual-test.md#step-12 | manual | 7 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`
- frontend: `ddev npm run typecheck`, `ddev npm run build`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project on nginx-fpm; `npm run build` output present; old asset fingerprint unchanged

## Phase: Gaps (converge round 1)

- [x] 8. Share the `lab` props so the top bar works on every lab page (backend)
  Covers: AC-1
  - Gap: `app/Http/Middleware/HandleInertiaRequests.php::share()` returns only `parent::share()`; the Clarification "Shared props" (`lab.textProvider`, `lab.textModel`, `lab.embeddingProvider`, `lab.embeddingModel`, `lab.ollama`) is not implemented. `resources/js/ai/Layouts/LabLayout.vue` falls back to `page.props`, which only `Lab/Overview` fills, so on `/ai/describe`, `/ai/semantic`, `/ai/docs`, `/ai/chat` and `/ai/photos` the top bar shows no model tags and "Ollama not used" even when Ollama is the provider (manual step 3.2 "top bar shows Ollama unreachable" cannot pass)
  - Approach: share a lazy `lab` prop from `HandleInertiaRequests` built from `LabOverviewService` (models plus `OllamaServerInfo::reachable()` or `not-used`); make `LabLayout` read `page.props.lab` only
  - [x] 8.1 **Verify**: a failing Pest test first: `GET /ai/describe` (and `/ai/chat`) `assertInertia` has `lab.textModel`, `lab.embeddingModel` and `lab.ollama.reachable` (true with a faked `/api/version`, `not-used` when no provider uses Ollama); then green; typecheck and build pass; in a browser the top bar on every lab page shows both model tags and the Ollama dot

- [x] 9. Send `Retry-After` on the describe 429 so the page can say "try again in N s" (backend)
  Covers: AC-4
  - Gap: `app/Http/Responses/JsonErrorRenderer.php` turns every `HttpExceptionInterface` (including `ThrottleRequestsException`) into `ApiResponse::error(...)` without the exception's headers, so `/listings/{id}/describe` answers 429 with no `Retry-After`; `useEventStream.ts::failureMessage` therefore always shows the fallback "try again in a minute" (seen in task 4's browser check), never AC-4's "try again in N s" from `Retry-After`. No test asserts the header (`tests/Feature/OllamaProviderTest.php` only checks the 429 status)
  - Approach: in `JsonErrorRenderer::render`, carry `$e->getHeaders()` onto the JSON response for `HttpExceptionInterface` (body unchanged, so MNC-2's body rule holds; note the restored throttle headers in the build report as the only other header change)
  - [x] 9.1 **Verify**: a failing Pest test first: with `ai.describe_per_minute` = 1, the second `GET /listings/{id}/describe` is 429 with a numeric `Retry-After` and the same JSON body as before; then green; existing 429 tests unchanged and green; in a browser the describe page shows "Too many requests, try again in N s."

- [x] 10. Link the "coming" capability cards to their placeholder pages (frontend)
  Covers: AC-1
  - Gap: AC-1 asks for one card per capability linking to its page, with chat and photos marked "coming"; `resources/js/ai/Pages/Lab/Overview.vue` renders `coming` capabilities as a plain `<span>` (no `Link`), so the overview cards for `/ai/chat` and `/ai/photos` link nowhere
  - Approach: render every card title as an Inertia `Link` to `capability.route`, keeping the "coming · SL-xx" mark for chat and photos
  - [x] 10.1 **Verify**: typecheck and build pass; on `/ai` in a browser all five cards are links, the chat and photos cards still read "coming" with their slice, and clicking them opens the placeholder pages; keyboard focus visible on them

- [x] 11. Run and record the manual steps that were skipped (all)
  Covers: AC-2, AC-3, AC-4, AC-5, AC-6, AC-9
  - Gap: task 4 states the masked `error` event and "connection lost" were not checked in a browser, and neither the `frankenphp-octane` describe check (manual step 2.6, Clarification "Other servers") nor the Stop row count (step 2.5) was run; task 5.3's "browser check with real queries" was done with curl only and task 7 reports only layout checks (scroll, console, focus), so manual step 4 (semantic results with bars and timings, docs three columns with shared-chunk highlighting, 422/503 messages) and the screen-reader check in step 5 have no record. There is no SL-46 build report under `.ai/reports/`. Manual step 4.1 also cannot run as written: `Semantic.vue` disables Search for an empty query, so the 422 message is unreachable that way
  - Approach: run manual-test.md steps 2.4 to 2.6, 3, 4 and the step 5 screen-reader check after tasks 8 to 10; trigger 422 another way (for example a query over 200 characters via the free request, or adjust step 4.1 to what the UI allows) and 503 with Ollama stopped; record results, screenshots and the contrast pairs in an SL-46 report
  - Result: the acceptance run covered the masked error, connection loss, Start/Stop on frankenphp-octane, the run count around Stop and both search pages with real queries (report `ship-spec-20261103-0012-ai-lab-ui-20261103-0259.md`). VoiceOver was not run (needs a person); checked instead that only the description `<output>` has `aria-live="polite"` and the event log has none.
  - [x] 11.1 **Verify**: the report records, per step, the observed result: masked error text and "Connection lost." shown in the page with no console-only failure; describe Start/Stop/new run on `frankenphp-octane`; run count before and after Stop; semantic and docs pages with real queries showing results, `Server-Timing` embed and db values, shared-chunk highlighting, and the 422 and 503 messages; VoiceOver reads the output politely and not the event log

- [x] 12. Record the post-task-0 asset fingerprint and parity re-baseline in BENCHMARKS.md (docs)
  Covers: MNC-1
  - Gap: the spec (Clarifications "Fingerprint") requires the `bench/asset-fingerprint.sh` output after task 0 to be recorded in the build report and in BENCHMARKS.md's parity section; `BENCHMARKS.md` "Response parity" still describes the 2026-10-29/30 captures and has no fingerprint or mention of the task 0 re-baseline (commit 580fbac); the only record is the truncated hashes in this file, which manual step 1 cannot compare against
  - Approach: add the full `bench/asset-fingerprint.sh` output (names and full sha256) and a note on the 2026-11-03 re-baseline (reason: automatic source detection) to BENCHMARKS.md's parity section and the SL-46 report
  - [x] 12.1 **Verify**: `bench/asset-fingerprint.sh` output after `ddev npm run build` matches the lines recorded in BENCHMARKS.md byte for byte; `bench/parity.sh compare` per manual step 6 prints `identical` six times

## Phase: Gaps (converge round 2)

- [x] 13. Browser-check the round-1 changes and record the AC-9 pass at both sizes (frontend)
  Covers: AC-1, AC-9
  - Gap: tasks 8 and 10 changed what every lab page renders (`LabLayout.vue` now shows both model tags from `page.props.lab` on all six pages, and `Overview.vue` turns all five capability titles into `Link`s), but both summaries say no browser check was run, so the browser parts of 8.1 (top bar on every page) and 10.1 (cards are links, chat and photos open, focus visible) were never done. The only AC-9 layout record is task 7's summary, made before tasks 8 and 10. `.ai/reports/sl-46-manual-checks.md` was run at 1440x900 only and records no per-page horizontal-scroll, console or Tab-order results and no 390x844 screenshots (`.ai/reports/sl-46-screenshots/` has none). The spec's AC-9 method asks for screenshots at both sizes, captured console messages and the keyboard tab order
  - Approach: with the round-1 code built (`ddev npm run build`) on nginx-fpm, open `/ai`, `/ai/describe`, `/ai/semantic`, `/ai/docs`, `/ai/chat` and `/ai/photos` at 1440x900 and 390x844 (Playwright viewport or device emulation, not a cropped window). For each, record scrollWidth against clientWidth, the console messages, and Tab through every control. Click each capability card on `/ai`. Add a step 5 section with the screenshots to the SL-46 report
  - Result (after the fix pass): all 12 page and size pairs: no horizontal scroll, 0 console errors, focus outline solid on Tab, Instrument Sans loaded, field borders `#8a8478`, header aligned with the navigation (x=120 at 1440), navigation full height; screenshots `.ai/reports/sl-46-screenshots/fix-<size>-<page>.png`. A live Ollama describe run finished with the logged-run panel shown.
  - [x] 13.1 **Verify**: the report has a row for each of the 12 page and size pairs showing no horizontal scroll, 0 console errors and visible focus on every control (top-bar tags wrap at 390 px without overflow), with screenshots named by page and size; on `/ai` all five cards are links, the chat and photos cards read "coming · SL-45" and "coming · SL-36" and open their placeholders; the top bar on every page shows both model tags and the Ollama dot

- [x] 14. Scope or record the error-header change from `JsonErrorRenderer` (backend)
  Covers: MNC-2
  - Gap: MNC-2 allows only one response change to existing endpoints, the `Server-Timing` header on the two search endpoints. Task 9 added `->withHeaders($e->getHeaders())` to the `HttpExceptionInterface` branch of `app/Http/Responses/JsonErrorRenderer.php:39`, which covers every JSON error. So 429s from `throttle:semantic-search` and `throttle:docs-search` (`routes/catalog.php:20,23`) now also carry `Retry-After`, `X-RateLimit-Limit`, `X-RateLimit-Remaining` and `X-RateLimit-Reset`, and a JSON 405 now carries `Allow`. Task 9's approach asked to note this in the build report as the only other header change, but no report, spec or BENCHMARKS.md text mentions it. No test pins which headers the error responses may carry; `tests/Feature/OllamaProviderTest.php` checks only the describe 429
  - Approach: decide and record the change. Either keep it (the throttle headers are Laravel's normal behaviour, which the old renderer dropped) and note in the SL-46 report and in the spec's MNC-2 that 429 and 405 JSON errors on existing endpoints now carry their exception headers, or narrow the copy to `ThrottleRequestsException`. In both cases, add a Pest test named after MNC-2 that pins the header set on a semantic-search 429 and keeps the body equal to the old one
  - Result: kept, recorded as a decision in spec-lite.md and pinned by `tests/Feature/JsonErrorHeadersTest.php`. JSON errors from HTTP exceptions now carry the exception's headers: `Retry-After` and `X-RateLimit-*` on 429s (describe, semantic search, docs search), `Allow` on 405s. Bodies unchanged.
  - [x] 14.1 **Verify**: the new MNC-2 test fails first if the header set differs from the recorded decision, then passes; the semantic-search 429 JSON body is unchanged (`success`, `status`, `message`, `errors`); the SL-46 report and the spec's MNC-2 line name every header change to existing endpoints; full suite, Pint and PHPStan green

- [x] 15. Correct the re-baseline reason in BENCHMARKS.md (docs)
  Covers: MNC-1
  - Gap: the paragraph task 12 added to `BENCHMARKS.md` under "Response parity" says the snapshots were re-baselined because "`app.css` gained automatic source detection, which changed its hashed file name". That is backwards. Per the spec's Clarifications and task 0, `app.css` always used automatic source detection, which made its build non-reproducible (any markdown change altered it). Task 0 removed that detection with `@import 'tailwindcss' source(none);` and explicit `@source` lines, and the new build got a new name (`app-CjRfEL-0.css`). In this learning project the parity record misstates the mechanism
  - Approach: rewrite that sentence: automatic source detection scanned markdown and specs, so rebuilds were not reproducible. Task 0 switched to `source(none)` with explicit sources, which changed the hashed name once. Keep the fingerprint block and the six-server `identical` line as they are
  - [x] 15.1 **Verify**: the paragraph says detection was removed (`source(none)` with explicit `@source`), not gained; `tests/Feature/BenchmarksDocTest.php` "MNC-1 BENCHMARKS.md parity section records the asset fingerprint and the re-baseline" still passes; `bench/asset-fingerprint.sh` output still matches the two recorded lines

- [x] 16. Re-run the MNC-3 bench gate on the committed round-1 code (bench)
  Covers: MNC-3
  - Gap: MNC-3's `bench/run.sh ping --quick` passed only in task 7, before tasks 8, 9, 10 and 12. Those tasks changed code that runs on every JSON error and every lab request (`JsonErrorRenderer.php`, `HandleInertiaRequests.php`, `LabOverviewService.php`), and their changes are still uncommitted (git status shows them modified). The bench refuses a dirty tree, so manual step 6's last line has not run on the final code. Task 10 also skipped the full suite
  - Approach: after tasks 13 to 15, commit the round-1 and round-2 work, then run manual-test.md step 6 in full on nginx-fpm: suite, typecheck, build, parity on six servers, and `bench/run.sh ping --quick`. Record the exit code and the test count in the SL-46 report
  - Result (commit d23bb68, clean tree): suite 520 green, `identical` six times, `bench/run.sh ping --quick` exit 0, server back on nginx-fpm, fingerprint unchanged.
  - [x] 16.1 **Verify**: on a clean tree, `ddev artisan test --compact` is green, `bench/parity.sh compare` prints `identical` six times, and `bench/run.sh ping --quick; echo "exit=$?"` prints `exit=0`, all recorded in the report; the server is back on nginx-fpm

## Phase: Review fix pass

- [x] 17. Fix the confirmed review and visual findings (backend, frontend)
  - [x] 17.1 Correctness: tokens/s is (tokens − 1) over first-to-last token time; a failed stream looks up its logged run; the similarity value prints the real cosine (bar clamped only); query length counted in code points after trim; listing id pattern matches the route; relative time rounds before choosing the unit
  - [x] 17.2 Design: field border token `field` #8a8478 (3.32:1 on paper, 3.72:1 on white); `@fonts` in the lab layout; search clear button hidden; runs table `whitespace-nowrap` in a focusable labelled region; header in the same 1200 px column as the content; navigation full height
  - [x] 17.3 Wiring: capabilities, navigation and placeholder slices come from one list in `LabOverviewService`, with `route()` URLs shared to the layout as `lab.navigation`; the current page chunk is listed in `@vite` so it loads with `app.ts`; index `ai_runs_recent_idx (created_at DESC, id DESC)` for the recent-runs query
  - [x] 17.4 Security: the run lookup reports the exception class only, not the stored message (it can name the internal model URL)
  - [x] 17.5 Not changed, with reason: the docs page still sends one request per mode, so the query is embedded twice (vector and hybrid). The page exists to show the public endpoint in each mode with its own `Server-Timing`; a combined lab endpoint would measure something else. The cost is one extra bge-m3 call of about 60 ms per search
  - [x] 17.6 **Verify**: suite (520), Pint, PHPStan, typecheck and build green; old asset fingerprint unchanged
