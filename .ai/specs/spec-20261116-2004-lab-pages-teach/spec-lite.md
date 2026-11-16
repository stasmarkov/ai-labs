---
id: spec-20261116-2004-lab-pages-teach
title: Lab pages that teach, with no broken layouts
type: improvement
size: M
priority: P1
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-16; standing approval)
approved_at: 2026-11-16T18:07:41Z
created: 2026-11-16
stacks: [laravel]
slice: SL-53
brs: [BR-27]
---

# Lab pages that teach, with no broken layouts

## Summary

The owner found the Moderation page broken (2026-11-16). The "Pick a listing" select shrank to 26 px, and its label wrapped under the example buttons. He asked that every lab page:
1. renders cleanly;
2. gives an example for every field;
3. explains how it works;
4. links to the guide pages that explain the feature;
5. tells the learner how to build the same thing themselves, and what to take into account;
6. shows short examples of the same AI pattern in other domains.

The in-app guides (`/ai/guides`) get the same three teaching parts, so the page and its guide agree. A browser layout check guards every page from now on.

## Context

**Audit, 2026-11-16, run with Playwright on Chrome at 1440 px and 1024 px:**

| Page | Problem |
|---|---|
| Moderation | `#moderation-pick` is 26 px wide. The select column (`flex-1 min-w-0`) shares a flex row with the example buttons, which do not shrink. |
| Describe | `#describe-listing` is 26–29 px wide and overlaps the listing-id input at 1024 px. Same cause. |
| Playground, Research | No "How it works", no guide link, no examples. Playground has 5 fields without a hint. |
| Changes, Settings | No "How it works", no guide link. |
| Moderation form | 8 fields without an example value. |
| Docs (`docs-k`), Photos (`photos-weight`) | No hint. |
| Describe, Chat, NL search, Semantic, Photo search | A guide link and "How it works", but no example inputs to click. |
| Every page | No "Build it yourself", no "Watch out for", no examples from other domains. |

Every other page renders without overlap or horizontal scroll.

**Guides:** the guide engine (`app/Guides/GuideRepository.php`, `resources/guides/*.php`) has six tabs: works, failures, recipes, support, numbers and experiments. It has nothing yet for building a feature yourself or for other domains.

## Scope

### In scope

1. **Layout fixes:**
   - **Moderation and Describe:** the picker and the example buttons go on separate rows, with the select taking the full row and the examples wrapping below it. No control may render narrower than 160 px.
   - **Every page:** checked at 1440 and 1024 px.
   - **Widths:** the 160 px minimum applies to the two listing selects only. The audit's general rule is 120 px for text, number and search inputs, selects and textareas (C-6).
2. **A shared `LabTeach` Vue component**, rendered on every lab page below the form. It has four parts, each one collapsible:
   - **How it works:** 3 to 6 short steps for this page's request, linking to `/ai/guides/<key>#works`. Pages that already have a "How it works" block move it into this component, so there is a single place.
   - **Build it yourself:** 4 to 8 numbered steps a developer would follow to build the same feature in their own Laravel app, naming the building blocks used here (for example an agent with structured output, a validation rule, a repository, a rate limit). Each step links to the guide's recipes tab, or names a public doc.
   - **Watch out for:** 3 to 6 points, such as cost, latency, injection, validation, privacy and evaluation. They link to the guide's failures and support tabs.
   - **Same pattern elsewhere:** 2 or 3 short examples from other domains where the same AI pattern solves a problem. For example:
     - moderation → support-ticket triage or invoice fraud flags;
     - research agent → due-diligence notes;
     - photo duplicates → insurance claim photo reuse.
3. **Examples and hints for every field:**
   - every text, number and textarea field on every lab page gets a realistic placeholder or an example line below it;
   - every page with a main input gets 2 to 4 clickable example chips that fill the form, where it has none today;
   - selects get a first option that explains the choice.
4. **Guide links:**
   - every lab page links its own guide;
   - Changes links the MCP guide, Settings links the providers guide, and Playground and Research link theirs;
   - Overview cards stay as they are.
5. **Guide content:** each feature guide `resources/guides/<key>.php` gains three optional sections, which the engine renders:
   - `build`: the steps;
   - `watch`: the points;
   - `elsewhere`: the examples.

   The page's `LabTeach` reads the same data through its Inertia props, so the text is written once. Foundation guides (`providers`, `streaming`, `guards`, `prompts`, `run-log`, `local-models`, `metrics`) get them where relevant.
6. **Teach data:** a single source per feature, `app/Guides/GuideTeach` loaded from the guide file. Pages read it through a small `GuideRepository::teach($key)`.
7. **Layout check:** `tests/Browser/`-style Playwright scripts are not part of this repo, so the check is `bench/ui-audit.mjs`, run with the Playwright library through `npx`, against `https://ai-lab.ddev.site`. For each lab page, at 1440 and 1024 px, it fails on:
   - overlapping interactive controls or labels (an empty live region does not count);
   - a select or input narrower than 120 px;
   - horizontal scroll;
   - a missing `LabTeach` block, a missing guide link, or a field without a placeholder or hint.

   It writes screenshots to a gitignored folder. The script is run in this slice and documented for future slices.
8. **Feature test:** `tests/Feature/LabTeachTest.php` checks:
   - every lab page's Inertia props carry a `teach` block with non-empty `works`, `build`, `watch` and `elsewhere`, and a guide key;
   - every guide with `build`, `watch` or `elsewhere` renders it on `/ai/guides/<key>`.
9. **Playground history:** no code change. The manual test lists the tinker command that clears local test prompts and runs, because their long labels make the page look broken.

### Out of scope

- A visual redesign.
- New features.
- Mobile layouts below 1024 px.
- Translating the lab.
- Changing how any feature behaves.

## Must NOT change

- **MNC-1:** endpoints, request and response shapes, and feature behaviour.
- **MNC-2:** the public pages, the fingerprint (`app-DNob7CFE.css` / `app-PTzu1QaN.js`) and parity. The lab bundle is separate.
- **MNC-3:** no slice ids, `.md` paths or repo paths on the site (`NoReferencesInUiTest`).
- **MNC-4:** existing guide tabs and their tests.

## Acceptance criteria

- **AC-1:** WHEN Moderation and Describe render at 1440 or 1024 px, THE listing select SHALL be at least 160 px wide and SHALL not overlap any label or button.
- **AC-2:** WHEN `bench/ui-audit.mjs` runs against every lab page at 1440 and 1024 px, THE SYSTEM SHALL report no overlap, no control narrower than 120 px, no horizontal scroll, no missing teach block, no missing guide link and no field without a placeholder or hint, and the script SHALL exit 0.
- **AC-3:** Every lab page SHALL show the `LabTeach` block with How it works, Build it yourself, Watch out for and Same pattern elsewhere. Each part SHALL link to the matching guide tab.
- **AC-4:** Every field SHALL have an example or a hint. Pages with a main input SHALL offer 2 to 4 example chips.
- **AC-5:** Every feature guide on `/ai/guides/<key>` SHALL show the Build it yourself, Watch out for and Same pattern elsewhere sections, with the same text as its page.
- **AC-6:** `NoReferencesInUiTest`, `GuideContentTest` and `GuidePageTest` SHALL pass with the new content.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Accessibility | Collapsible parts are `<details>`/`<summary>` or buttons with `aria-expanded`; example chips are buttons with an accessible name; contrast unchanged | `tests/Feature/LabTeachTest.php` + `manual-test.md#step-2` |

## Boundaries

- **Always:**
  - write the teach text once, in the guide files;
  - keep the tone and length of the existing guides;
  - run the UI audit on every page before shipping.
- **Ask first:** none (standing approval).
- **Never:**
  - change feature behaviour or endpoints;
  - put slice ids or paths on the site;
  - add dependencies (Playwright runs through `npx` from the cache).

## Technical context

- **Related code:**
  - **Pages:** `resources/js/ai/Pages/Lab/*.vue` and `resources/js/ai/Pages/Lab/Guide.vue`.
  - **Guides:** `app/Guides/*` and `resources/guides/*.php`.
  - **Lab controllers:** `app/Http/Controllers/ShowLab*Controller.php`.
  - **Tests:** `tests/Feature/{GuideContentTest,GuidePageTest,NoReferencesInUiTest}.php`.
- **Already implemented:**
  - the guide engine and its tabs;
  - the "How it works" blocks on 11 pages;
  - example chips on Moderation, Assistant, Admin assistant and Photo search.

## Decisions

- **D-1:** the teach text lives in the guide files, and the pages read it, so a page and its guide never drift apart. (self-decided, standing approval)
- **D-2:** the layout check is a Playwright script under `bench/`, not a PHPUnit test. It needs a running browser and the live site, like the other bench scripts. A static feature test covers the teach data. (self-decided, standing approval)
- **D-3:** a single `LabTeach` component, so all pages look the same and future slices only add data. (self-decided, standing approval)

## Open questions

All 23 items from the clarity gate on 2026-11-16 are answered in C-1 to C-12 below (self-decided, standing approval).

- [x] Page list, page-to-guide mapping, feature vs foundation, Settings/Overview → C-1
- [x] Sources of how-it-works text, data shape, where sections appear, tab anchors → C-2, C-3
- [x] Field rules, select hints, chips, main input → C-4
- [x] Audit detection, overlap rule, widths, scroll, preconditions, Playwright loading → C-5, C-6
- [x] Test reach (props vs markup), how the test finds pages → C-7
- [x] Content limits (MNC-3), fingerprint, placement, cleanup, terms, gates, quality bar → C-8 to C-12

## Clarifications

- **C-1 Pages and guides:**

  | Page | Guide key |
  |---|---|
  | `/ai/describe` | `describe` |
  | `/ai/semantic` | `semantic` |
  | `/ai/nl-search` | `nl-search` |
  | `/ai/docs` | `docs` |
  | `/ai/chat` | `chat` |
  | `/ai/photos` | `photos` |
  | `/ai/photo-search` | `photo-search` |
  | `/ai/photos/duplicates` | `photo-duplicates` |
  | `/ai/assistant` | `assistant` |
  | `/ai/moderation` | `moderation` |
  | `/ai/research` | `research` |
  | `/ai/playground` | `playground` |
  | `/ai/admin-assistant` | `admin-assistant` |
  | `/ai/changes` | `mcp` |
  | `/ai/settings` | `providers` |

  - **Feature guides:** the 13 keys from `describe` to `admin-assistant`. All four teach parts are required for them.
  - **Foundation guides:** `providers`, `streaming`, `guards`, `prompts`, `run-log`, `local-models`, `metrics`, `mcp` and `similar-photos`.
    - Every foundation guide gets `build` and `watch`.
    - `elsewhere` is required for `mcp`, `providers`, `local-models` and `metrics`, and optional for the others.
    - Changes and Settings use the `mcp` and `providers` guides, which have all three sections, so their pages show all four parts too.
  - **Excluded:** Overview, `/ai/guides`, the guide page, the run page, `/ai/changes/{id}`, `/ai/research/{id}` and `/ai/playground/{id}`. They are navigation or detail views. They are excluded from `LabTeach` and the teach checks, but the layout checks still apply to them.
- **C-2 Sources:**
  - **How it works:** the existing `works` tab (its step titles, at most 6) is the source for the page's How it works part. The page shows the step titles with the first sentence of each body, and links to `#works` for the rest.
  - **Old blocks:** the hand-written "How it works" blocks in the Vue pages are removed. Any fact in them that is missing from the guide's `works` is moved into the guide first.
  - **The other sections:** `build`, `watch` and `elsewhere` are new keys in the guide file.
  - **AC-5:** means the text is identical, because both the page and the guide render from the same rows.
- **C-3 Shape and rendering:**
  - **Guide file rows:**
    - `build`: `[['step' => string, 'detail' => string, 'tab' => 'recipes'|'support'|null, 'doc' => url|null]]`, 4 to 8 rows.
    - `watch`: `[['point' => string, 'detail' => string, 'tab' => 'failures'|'support']]`, 3 to 6 rows.
    - `elsewhere`: `[['domain' => string, 'problem' => string, 'how' => string]]`, 2 or 3 rows.
  - **`GuideTeach`:** a readonly DTO with `key`, `title`, `works` (list of `{title, summary}`), `build`, `watch` and `elsewhere`, made by `GuideRepository::teach($key)`.
  - **Prop:** controllers pass `teach` (the DTO as an array) and `guideKey`.
  - **On the guide page:** the three sections render as three more tabs, "Build it yourself", "Watch out for" and "Elsewhere", after the existing six. They show only when the guide has the data.
  - **Tab links:** a URL hash selects the tab. If `Guide.vue` does not already open the tab named in the hash, this slice adds that, and `GuidePageTest` checks it.
  - **Links from LabTeach:** each part links to a tab: How it works → `#works`, Build → `#build`, Watch → `#watch`, Elsewhere → `#elsewhere`. Each row of those tabs links on to `recipes`, `failures` or `support` through its `tab` field.
- **C-4 Fields and chips:**
  - **Fields that need a hint:** inputs of type text, search, number, url, email and textarea. Checkboxes, radios, ranges, file and hidden inputs are exempt.
  - **What counts as a hint:** a non-empty `placeholder`, or an `aria-describedby` pointing to visible text.
  - **Selects:** need a visible label. When the select allows no value, an explanatory first option is allowed only if it is the existing empty option, so submitted values are unchanged (MNC-1). Otherwise the select needs a hint line referenced by `aria-describedby`.
  - **Main input:** the first text, search or textarea field in the page's form. Pages with one get 2 to 4 chips. Chips fill the field and never submit. Pages that already have more than 4 chips keep them. Photos (select, weight slider) and Settings get no chips.
- **C-5 Audit contract:**
  - **Markers:**
    - `LabTeach` renders `data-lab-teach` on its root, and `data-teach-part="works|build|watch|elsewhere"` on each part.
    - The guide link carries `data-guide-link`.
    - Example chips carry `data-example`.
  - **Overlap rule:** two visible elements among `label`, `button`, `a`, `input`, `select`, `textarea`, `h1`, `h2` and `h3`, neither containing the other, whose rectangles intersect by more than 4 px on both axes. The following are ignored:
    - elements with no text that are not form controls;
    - elements inside `[role=dialog]`, `[popover]` or `position: fixed` or sticky ancestors.
  - **Collapsed content:** `<details>` parts are opened before checking.
  - **Horizontal scroll:** only the document (`scrollWidth > innerWidth + 2`). Inner containers may scroll.
- **C-6 Widths and running:**
  - **Widths:** at least 120 px for text, search, number, url and email inputs, selects and textareas. Buttons and checkboxes are not measured.
  - **What is checked:** only the first render: no submit and no model call. The viewport height is 1000. HTTPS errors are ignored for DDEV's certificate.
  - **Browser:** the installed Chrome channel, with Playwright Chromium as a fallback.
  - **Loading Playwright:** the script resolves it through `PW` (a path to a playwright package), else `createRequire` from the newest `~/.npm/_npx/*/node_modules/playwright`, else it exits 2 with "run: npx playwright@1.55 --version". The header documents this.
  - **Preconditions:** the seeded dev database and `APP_ENV=local`. Gated pages (admin assistant, changes, playground) are skipped with a note when they answer 404.
- **C-7 Test reach:**
  - **`LabTeachTest`:** checks the props of each page in the C-1 list (a fixed list in the test), plus the static markup in the Vue sources:
    - a regex check that each page renders `<LabTeach`;
    - that `LabTeach.vue` uses `<details>` and `<summary>`;
    - that example chips are `<button type="button"`, have the `data-example` attribute, and have visible text.
  - **Rendered checks:** placeholders and overlap are checked by the audit script (AC-2). The AC-4 and NFR-1 traceability rows include it.
  - **Controllers:** every page in C-1 has a `ShowLab*Controller`.
- **C-8 Content and MNC-3:**
  - **Allowed:** "Build it yourself" may name Laravel and PHP concepts and public packages (`laravel/ai`, `laravel/mcp`, pgvector, Ollama) and link public docs (laravel.com, frankenphp.dev, ollama.com).
  - **Not allowed:** app class names, file paths, slice ids or `.md` names.
  - **Test:** `NoReferencesInUiTest` keeps its rules and is extended to the new sections.
- **C-9 Fingerprint:** the public `app.css` and `app.js` come from separate Vite inputs (`resources/css/app.css`, `resources/js/app.js`). The lab has its own entry. The public CSS source list does not scan `resources/js/ai`; the build agent verifies this first and keeps it so. The fingerprint check proves it.
- **C-10 Placement and state:**
  - **Placement:** `LabTeach` goes after the page's main form and results. On pages with no form (Changes, Settings) it goes after the intro paragraph.
  - **State:** all parts start collapsed except How it works. The state is not persisted.
- **C-11 Cleanup and terms:**
  - **Cleanup:** the playground cleanup in the manual test deletes all local playground runs and prompts on purpose (dev data), and is labelled that way.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
  - **Parity:** `PARITY_INSECURE=1 bench/parity.sh capture`, then `git diff`, where only the `captured=` line may differ.
  - **BR-27:** every slice explains how it works in the guides.
  - **Recorded output:** goes into tasks.md under the task, as a summary of at most 20 lines.
  - **Gitignore:** `/bench/results/raw/` is already ignored, which covers `ui-audit/`.
  - **Gates:** PHPStan, typecheck, the fingerprint and parity all exist and have run in earlier slices.
- **C-12 Quality bar:**
  - **Length:** each teach row is 1 or 2 sentences, at most 240 characters.
  - **Domains:** each `elsewhere` row names a concrete domain and problem.
  - **Review:** the review step reads every guide's new sections for accuracy against the code, and the orchestrator reads a sample of 5 guides before shipping.

