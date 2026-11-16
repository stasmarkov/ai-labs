# Lab pages that teach, with no broken layouts — Tasks

**Spec**: spec-20261116-2004-lab-pages-teach | **Size**: M | **Tasks**: 11

## Phase 1: Check first

- [x] 1. UI audit script (checks)
  Covers: AC-2
  - [x] 1.1 Add `bench/ui-audit.mjs` and a usage note in its header, with these checks:
    - overlap (ignoring empty live regions);
    - narrow controls;
    - horizontal scroll;
    - a missing teach block;
    - a missing guide link;
    - a field with no placeholder or hint.
    Screenshots go to the gitignored `bench/results/raw/ui-audit/`.
  - [x] 1.2 **Verify**: today it fails, on Moderation and Describe at least. Record the output here.
    Output of `node bench/ui-audit.mjs` (2026-11-16, before any fix; exit 1, 36 page-widths checked, 30 failing):
    ```
    FAIL /ai/moderation @1440: overlap select#moderation-pick x button "reported vin" (10x40 px); narrow select#moderation-pick is 26 px, needs 160
    FAIL /ai/describe @1440: narrow select#describe-listing is 29 px, needs 160
    FAIL /ai/describe @1024: overlap select#describe-listing x input#describe-free-id (10x40 px); narrow select#describe-listing 26 px,
         input#describe-free-id 90 px, select#describe-provider 100 px
    FAIL /ai/docs @1440 and @1024: narrow input#docs-k is 112 px, needs 120
    FAIL every lab page in the C-1 list, both widths: no-teach (no [data-lab-teach]), no-guide (no [data-guide-link])
    FAIL /ai/moderation 13, /ai/playground 11, /ai/settings 26 findings (mostly no-hint)
    ok   /ai, /ai/guides, /ai/guides/describe at both widths
    36 page(s) checked, 30 failing
    ```

## Phase 2: Fix and teach

- [x] 2. Layout fixes (frontend)
  Covers: AC-1
  - [x] 2.1 Put the Moderation and Describe pickers and examples on separate rows, and give the selects a minimum width.
  - [x] 2.2 **Verify**: the audit shows no layout failure on either page.

- [x] 3. Teach data and guide sections (backend, docs)
  Covers: AC-5, AC-6
  - [x] 3.1 Add:
    - the optional `build`, `watch` and `elsewhere` guide sections, with engine support and rendering in `Guide.vue`;
    - `GuideRepository::teach()`;
    - content for every feature guide and the relevant foundation guides.
  - [x] 3.2 **Verify**: `GuideContentTest`, `GuidePageTest` and `NoReferencesInUiTest`, extended for the new sections.

- [x] 4. `LabTeach` on every page (frontend, backend)
  Covers: AC-3, AC-4, NFR-1
  - [x] 4.1 Add:
    - the `LabTeach` component;
    - the controllers pass `teach`;
    - existing "How it works" blocks moved into it;
    - placeholders and hints on every field;
    - example chips where missing;
    - guide links on Changes, Settings, Playground and Research.
  - [x] 4.2 **Verify**: `tests/Feature/LabTeachTest.php`, typecheck and build.

## Phase 3: Run and ship

- [x] 5. Audit run (checks)
  Covers: AC-2
  - [x] 5.1 **Verify**: run `bench/ui-audit.mjs` on all lab pages at 1440 and 1024 px, and record the output here (exit 0).
    Output of `node bench/ui-audit.mjs` (2026-11-16, after tasks 2 to 4; exit 0):
    ```
    ok   every lab page in the C-1 list, /ai, /ai/guides and /ai/guides/describe at 1440
    ok   every lab page in the C-1 list, /ai, /ai/guides and /ai/guides/describe at 1024
    36 page(s) checked, 0 failing
    ```

- [x] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | pickers not collapsed | `tests/Feature/LabLayoutTest.php`; `manual-test.md#step-1` | test | 2 |
| AC-2 | audit clean on all pages | `manual-test.md#step-1` | manual | 1, 5 |
| AC-3 | teach block on every page | `tests/Feature/LabTeachTest.php`; `manual-test.md#step-2` | test | 4 |
| AC-4 | examples and hints | `tests/Feature/LabTeachTest.php`; `manual-test.md#step-1` | test | 4, 5 |
| AC-5 | guides show the new sections | `tests/Feature/GuidePageTest.php`; `manual-test.md#step-2` | test | 3 |
| AC-6 | guide tests pass | `tests/Feature/GuideContentTest.php`; `manual-test.md#step-3` | test | 3 |
| MNC-1 | behaviour unchanged | verify existing feature tests pass unchanged; `manual-test.md#step-3` | test | 6 |
| MNC-2 | public pages, fingerprint, parity | `manual-test.md#step-3` | manual | 6 |
| MNC-3 | no references | `tests/Feature/NoReferencesInUiTest.php` | test | 3, 4 |
| MNC-4 | existing guide tabs | `tests/Feature/GuideContentTest.php`; `manual-test.md#step-3` | test | 3 |
| NFR-1 | accessibility | `tests/Feature/LabTeachTest.php`; `manual-test.md#step-2` | test | 4 |

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

- [x] 7. Layout-check the excluded detail and run pages in the UI audit (checks)
  Covers: AC-2
  - `bench/ui-audit.mjs` `LAYOUT_ONLY_PAGES` holds only `''`, `guides` and `guides/describe`. C-1 says `/ai/changes/{id}`, `/ai/research/{id}`, `/ai/playground/{id}` and the run page `/ai/runs/{invocationId}` skip the teach checks but still get the layout checks. Resolve one id for each from its list page, or skip it with a note when there is none, and check it with `expectTeach: false`.
  - [x] 7.1 **Verify**: `node bench/ui-audit.mjs` lists the detail and run pages at 1440 and 1024 px (or a SKIP note when there is no row) and exits 0. Record the output here.
    Output of `node bench/ui-audit.mjs` (2026-11-16, exit 0, 40 page-widths checked, 0 failing):
    ```
    SKIP /ai/playground/{id}: no row to open from /ai/playground
    SKIP /ai/runs/{id}: no run link on any lab page
    ok   /ai/changes/{id} @1440
    ok   /ai/research/{id} @1440
    ok   /ai/changes/{id} @1024
    ok   /ai/research/{id} @1024
    40 page(s) checked, 0 failing
    ```
- [x] 8. Assert 2 to 4 example chips on pages with a main input (backend tests)
  Covers: AC-4
  - `tests/Feature/LabTeachTest.php` ("AC-4 pages with a main text input offer example chips") only checks that `data-example` or `<LabExamples` appears, and the audit does not count chips. Assert the count: the `EXAMPLES` arrays in Semantic, NlSearch, Docs, Chat and Playground, `slice(0, 3)` in Describe, and the `examples` Inertia prop on Research, Assistant, AdminAssistant, PhotoSearch and Moderation. Each needs at least 2 and at most 4, unless a page already had more than 4 before this slice.
  - [x] 8.1 **Verify**: `ddev artisan test --compact tests/Feature/LabTeachTest.php` passes, and fails when an `EXAMPLES` array is cut to 1 entry.
- [x] 9. Manual: run manual-test step 2 (keyboard focus, chip names, contrast) and record the result
  Covers: NFR-1
  Run 2026-11-16 in Chrome through Playwright 1.55.0 at 1440 px (Tab pressed 160 times from the top of each page):
  ```
  playground  Tab reaches all 4 summaries (works, build, watch, elsewhere) and 3/3 chips; focus ring (:focus-visible + outline) on all
  research    Tab reaches all 4 summaries and 3/3 chips; focus ring on all
  moderation  Tab reaches all 4 summaries and 4/4 chips; focus ring on all
  all three   Enter on a focused summary opens the part, Space closes it again
  chip names  playground "Summarise a listing", "Rewrite a complaint", "Extract the facts" (group "Example inputs");
              research the three topic texts (group "Example topics"); moderation "advance payment", "off platform contact",
              "price far below market", "reported vin" (group "Load an example", via aria-labelledby)
  Enter       on a chip fills #playground-input, #research-topic, and the Moderation form (#moderation-title "2014 Peugeot 308")
  describe    chips named "1", "2", "3" (known, weak, not empty); typed 999 in the free id, clicked chip 2: select=2,
              free id cleared, and Start requested /listings/2/describe (F3)
  contrast    muted teach text 5.10:1, chip text 15.72:1 on white; colours are the existing ink and muted tokens
  ```

## Phase: Gaps (converge round 2)

- [x] 10. Keep the How it works part to 3 to 6 short steps on every lab page (backend, frontend)
  Covers: AC-3
  - In scope item 2 and C-2 say the How it works part shows 3 to 6 short steps: step titles with the first sentence, at most 6. On the 11 pages that had a block, the `works` slot of `LabTeach` renders `<LabHowItWorks bare :steps="howItWorks">`. `howItWorks` comes from `GuideRenderer::steps()`, which returns every guide `works` step with its full rendered body. `teach.works` is capped at 6 and holds first sentences, but the slot overrides it, so those pages never render it. `resources/guides/moderation.php` and `resources/guides/photos.php` have 7 `works` steps, so `/ai/moderation` and `/ai/photos` show 7. `LabTeachTest` checks only the `teach.works` prop, which `GuideRepository::teach()` slices to 6, so no test catches this.
  - Approach: bring the moderation and photos guide `works` down to at most 6 steps. Merge two adjacent steps and keep the `event` markers the live tracking needs. Or cap what the bare `LabHowItWorks` renders so it matches `teach.works`. Then add a `LabTeachTest` case asserting that the `howItWorks` prop holds 3 to 6 steps on each of the 11 pages.
  - [x] 10.1 **Verify**: `ddev artisan test --compact tests/Feature/LabTeachTest.php tests/Feature/GuideContentTest.php tests/Feature/GuidePageTest.php` passes. The new `howItWorks` count case fails on moderation and photos before the fix. `node bench/ui-audit.mjs --only moderation,photos` still exits 0.

## Phase: Review fixes (2026-11-16)

- [x] 11. Fix the review findings F1 to F23 and close gaps 7 to 10 (backend, frontend, checks)
  Covers: AC-2, AC-3, AC-4, AC-5, NFR-1, MNC-3
  - One How it works source (F2, F13, F22, gap 10): the `howItWorks` prop, the `works` slot and the `guideKey` prop (F18) are gone, and `guideHref` is gone from the 9 pages where only the slot used it. Every page renders `teach.works` (title, first sentence, event) through `LabHowItWorks` inside `LabTeach`, which still follows a live run through `:events`/`:state`.
  - No silent cut: `GuideRepository` refuses a guide with teach sections and more than 6 works steps. Playground, research, providers, metrics and similar-photos were merged down to 6 (or fewer) steps; a works step can now list several snippets (`'snippet' => [a, b]`, shown together in the Implementation tab), so no snippet or fact was dropped (`GuideContentTest` lists every kept reference).
  - F1: moderation keeps `ModerationAgent::instructions` on its own step; a test reads both snippets from the Implementation tab.
  - F5: a build or watch row whose tab the guide page does not show is rejected. F6: the first-sentence cut keeps text before the ellipsis (word boundary, else hard cut at 239 + `…`). F8: doc links https on laravel.com, frankenphp.dev, ollama.com or github.com only (the hosts the guides use).
  - F11: the repository is scoped per request and keeps each parsed guide. F12: every `teach` prop is a closure, so a partial reload (Changes) never builds it (test proves it with a broken repository). F14: Build/Watch/Elsewhere are `v-memo="[teach]"`, and no slot remains, so stream updates only touch the How it works list.
  - F15/F16/F17/F23: `GuidePageService::teach()` returns the `App\DTOs\GuideTeach` DTO; `GuideTeach::rows()` is the one row mapping, used by the guide renderer too; renderer closures are typed. F19/F20: the server sends `teach.href` (from `route()`), LabTeach appends the tab hash, with no client tab map.
  - F3: Describe chips set the select and clear the free id (test plus the browser check under task 9). F4: `/ai/changes/{id}` carries `teach`. F10: public docs links are `rel="noopener noreferrer"`. F21: Playground chips use `LabExamples` (which now takes `{label, text}` examples).
  - Audit (F7, F9, gaps 7 and 8): `--only` with no value exits 2; Playwright comes from `PW` or the npx entry pinned to 1.55.x only, else exit 2; TLS errors are ignored only for a `*.ddev.site` base; detail ids come from list links, then the latest dev-database row; the run page is checked as JSON; a page with a main input fails outside 2 to 4 `[data-example]` chips (no page needed grandfathering).
  - [x] 11.1 **Verify**: `node bench/ui-audit.mjs` (2026-11-16, exit 0):
    ```
    id   /ai/changes/{id}: 01a1d704-... (from link)      id   /ai/research/{id}: 01a1da7d-... (from link)
    id   /ai/playground/{id}: 01a1dbe9-... (from database) id  /ai/runs/{id}: 01a1e565-... (from database)
    ok   all 15 C-1 pages, /ai, /ai/guides, /ai/guides/describe, the 3 detail pages @1440 and @1024
    ok   /ai/runs/{id} @1440 and @1024 (JSON endpoint: status and content type only)
    44 page(s) checked, 0 failing
    ```
    `node bench/ui-audit.mjs --only` and `PW=/nonexistent node bench/ui-audit.mjs` both exit 2.
  - [x] 11.2 **Verify**: gates. Full suite, Pint, PHPStan, typecheck and build pass; fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`; parity capture differs only on `captured=` (restored with `git checkout -- bench/parity`).
