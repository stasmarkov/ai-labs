---
id: spec-20261120-1558-one-learning-area
title: One learning area for guides and scene lessons, and a grouped lab sidebar
type: feature
size: L
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-20; standing approval)
approved_at: 2026-11-20T14:01:19Z
created: 2026-11-20
stacks: [laravel]
slice: SL-57
brs: [BR-27]
---

# One learning area for guides and scene lessons, and a grouped lab sidebar

## Summary

The lab has two separate ways to learn:

- **Guides** at `/ai/guides`: 25 tabbed reference pages, listed in a flat index by kind (capability / foundation).
- **Scene lessons** at `/ai/learn`: 8 animated lessons in 3 modules, with a course sidebar and progress.

They look different, sit under different index pages, and the lab sidebar is a flat list of 17 links. The owner asked (2026-11-20): "turn all guides into the learning format, but keep the tabbed version; all guides/learning should be similar; restructure the sidebar menu."

This slice joins them into **one learning area**:

1. `/ai/learn` becomes the only index. It is a course map: modules → topics. Every guide is reachable from it, either through a lesson that links it or as a reference-only topic. `/ai/guides` answers a permanent redirect to `/ai/learn`.
2. Guide pages and lesson pages share **one topic header** (breadcrumb, title, summary, meta line, and a "Walk through | Reference" switch) and the **same course sidebar** and page width. The tabbed guide is the "Reference" view; the scene lesson is the "Walk through" view.
3. The lab sidebar is regrouped into labelled sections.

New lessons for the guides that have none yet are **out of scope**: that is SL-58. Until then those topics show "Reference" only.

## Context

- Guides: `app/Guides/GuideRepository.php`, `app/Services/GuidePageService.php`, `resources/js/ai/Pages/Lab/Guide.vue`, `Guides.vue`, `ShowLabGuidesController`. Each guide file `resources/guides/<key>.php` may hold `lessons => ['<module>/<lesson>', ...]` (validated shape; titles resolved by `LessonService::titles`). 14 guides link at least one lesson today; 11 content guides link none: `describe, guards, logs-restart, photo-duplicates, photo-search, playground, prompts, run-log, semantic, similar-photos, worker-dx`. The meta guide `lessons` explains the lesson format.
- Lessons: `resources/lessons/course.php` (modules → lessons), `app/Lessons/LessonRepository.php` (`summary()`), `LessonService`, `LessonPageService`, pages `Learn.vue`, `Lesson.vue`, `components/lesson/CourseSidebar.vue`, `useLessonProgress.ts` (localStorage `ai-lab:learn:v1`).
- Lab sidebar: `LabOverviewService::navigation()` returns a flat `list<{href,label}>`, shared as `lab.navigation`, rendered in `resources/js/ai/Layouts/LabLayout.vue`. The lesson page widens the layout to 1440 px (`page.component === 'Lab/Lesson'`).
- Format trial (SL-54, closed): `/ai/guides/{key}/formats[/{format}]`, today linked from the guides index (`trial` prop) and its pages link back to `/ai/guides`.
- Home page: `LabHomeService` passes `guidesHref` (`/ai/guides`).
- Rules: product-brief.md BR-27 (how-it-works guide duty); project memory "Guides and UI duty": pages render without layout breaks at 1440 and 1024 px; no slice ids, `.md` names or repo paths in the UI (`NoReferencesInUiTest`).

## Scope

1. **Course map data** (`resources/lessons/course.php`): each module keeps `lessons` and gains an optional `references` list of guide keys (reference-only topics). Add two modules for guides not linked by any lesson. Target placement (the implementer may adjust titles/summaries, not the rule in AC-2):
   - Running models: lessons as today; references `describe`.
   - Answers you can trust: lessons as today; references `prompts`, `guards`, `playground`.
   - Agents: lessons as today; no references.
   - **Search and photos** (new, slug `search-and-photos`): references `semantic`, `photo-search`, `similar-photos`, `photo-duplicates`.
   - **Running the server** (new, slug `running-the-server`): references `run-log`, `logs-restart`, `worker-dx`.
   - The meta guide `lessons` is not a topic; the course page links it as "How these lessons work".
   The guide files' `lessons` keys stay the single source for lesson ↔ guide links; the course only adds reference-only topics.
2. **Validation** (`LessonRepository`): a module's `references` must be a list of existing guide keys; a key may appear in at most one module's `references`; a key listed in `references` must not also be linked by any lesson (that would make it appear twice). A failure throws `InvalidLessonException` naming the course file and the key.
3. **Course page** `/ai/learn` (`Learn.vue`, `LessonPageService`/`LessonRepository::summary()`):
   - Heading "Learn", intro, progress line (counts lessons only), "How these lessons work" link to `/ai/guides/lessons`.
   - Per module: lesson topics first, then reference topics.
   - Lesson topic: title, summary, "level, N min, N steps", done tag, a **Walk through** link (lesson) and **Reference** link(s): every guide whose `lessons` key names this lesson, by guide title.
   - Reference topic: guide title, guide summary, a "reference" tag and a **Reference** link only.
   - Keep the course page payload light (no step data, as SL-56 made it).
4. **Redirect**: `GET /ai/guides` → 301 to `/ai/learn`. Route name `ai.guides.index` stays (pointing at the redirect) so `route()` callers keep working. `Guides.vue` and its controller's page are removed. `LabHomeService::guidesHref` points at `/ai/learn`. The format-trial pages' "Guides" breadcrumb links to `/ai/learn`; the photos guide shows a small "Format trial" link to `/ai/guides/photos/formats` (the only entry point once the guides index is gone).
5. **Shared topic header** (new `components/learn/TopicHeader.vue`), used by `Guide.vue` and `Lesson.vue`:
   - Breadcrumb: `Learn / <module title> / <topic title>` (Learn and module link to `/ai/learn#<module slug>`).
   - Title, summary, meta line (lesson: "level, N min, N steps"; guide: "reference").
   - Switch: two segments "Walk through" and "Reference", the current one marked `aria-current="page"`. On a lesson, Reference links its first guide and, when there are more, lists the others next to the switch ("Also: …"). On a guide with lessons, Walk through links the first lesson and lists others the same way. On a guide with no lesson, Walk through is shown disabled with the text "lesson coming" (no link).
   - Replaces the lesson's current "Learn / title" breadcrumb + "Read the guide" link, and the guide's bottom "Lessons" nav.
   - A guide's module: the module of its first linked lesson, otherwise the module whose `references` hold it. The meta guide `lessons` has no module: breadcrumb `Learn / How these lessons work`.
6. **Same course sidebar** on guide pages: `CourseSidebar.vue` lists every module with its lesson topics and reference topics (reference topics link to the guide, marked "ref"); current topic highlighted with `aria-current`. Ticks and the "x/y done" counts stay for lessons only. Guide pages use the same 1440 px container and the same collapsed-by-default rail as lessons (same localStorage key).
7. **Grouped lab sidebar**: `navigation()` returns groups `list<{label, items: list<{href,label}>}>`:
   - **Learn**: Overview, Course (`/ai/learn`).
   - **Text**: Describe, Chat, Moderation, Playground*.
   - **Search**: Semantic search, NL search, Docs search.
   - **Photos**: Photos, Photo search, Duplicate photos.
   - **Agents**: Assistant, Research, Admin assistant*, Changes*.
   - **Setup**: Settings*.
   (* keeps today's availability rule; an empty group is omitted.) `LabLayout.vue` renders a small uppercase group label above each list. "Course" is current on `/ai/learn/**` and `/ai/guides/**`. Below `md` the groups wrap as compact labelled rows (no layout break at 1024 or below).
8. **Docs and guide**: update the `lessons` guide (one learning area, Walk through vs Reference, reference topics), README "Lessons" section and `docs/learning-guide.md` "Scene lessons" (BR-27). Add SL-57 and SL-58 to `backlog.md`.
9. **Audit**: `bench/ui-audit.mjs` discovers guide pages from `/ai/learn` links (it used the guides index) and still checks every guide and lesson page at 1440 and 1024 px.

## Out of scope

- New scene lessons for the eleven reference-only guides (SL-58).
- Changing any guide tab content or lesson step content.
- Regrouping the overview page cards or the home page.
- Removing the format-trial pages.

## Must NOT change

- MNC-1: Lesson URLs `/ai/learn/{module}/{lesson}/{step?}`, the step engine, lesson content, keyboard controls and progress storage (`ai-lab:learn:v1`).
- MNC-2: Guide URLs `/ai/guides/{key}`, every guide's tabs (works, implementation, modify, support, numbers, experiments, build, watch, elsewhere), their content and `#tab` anchors.
- MNC-3: Format trial URLs `/ai/guides/{key}/formats` and `/ai/guides/{key}/formats/{format}` answer as today.
- MNC-4: Lab feature pages (describe … settings) and their availability rules (playground, admin assistant, changes, settings conditional as today).
- MNC-5: No slice ids, `.md` names or repo paths visible in the UI (`NoReferencesInUiTest`), no new dependencies, no database changes.
- MNC-6: The asset fingerprint printed by `bench/asset-fingerprint.sh` (`app-DNob7CFE.css`, `app-PTzu1QaN.js`) and the parity capture (only `captured=` may differ) — these measure the landing/catalog bench pages, which this slice does not touch.

## Acceptance criteria

- AC-1: WHEN a visitor requests `GET /ai/guides` THE SYSTEM SHALL answer 301 with `Location` `/ai/learn`.
- AC-2: WHEN the course is loaded THE SYSTEM SHALL make every guide in `resources/guides` except `lessons` reachable exactly once as a topic owner: either linked by at least one lesson through its `lessons` key, or listed in exactly one module's `references`, never both; a course that breaks this throws `InvalidLessonException` naming the key.
- AC-3: WHEN a visitor opens `/ai/learn` THE SYSTEM SHALL list five modules; each lesson topic shows Walk through and Reference links, each reference topic shows a Reference link and a "reference" tag, and the page links "How these lessons work".
- AC-4: WHEN a visitor opens any lesson page THE SYSTEM SHALL show the topic header with breadcrumb `Learn / <module> / <lesson>`, meta line, and the switch with "Walk through" current and "Reference" linking a guide that names this lesson.
- AC-5: WHEN a visitor opens a guide page that a lesson links THE SYSTEM SHALL show the same topic header with "Reference" current and "Walk through" linking that lesson; for a guide with no lesson the Walk through segment is not a link and reads "lesson coming".
- AC-6: WHEN a visitor opens a guide page THE SYSTEM SHALL show the course sidebar listing all modules, lesson and reference topics, with this guide's topic marked current.
- AC-7: WHEN any lab page renders THE SYSTEM SHALL share `lab.navigation` as labelled groups Learn, Text, Search, Photos, Agents, Setup (empty groups omitted), and the layout shows each group's label.
- AC-8: WHEN the home page renders THE SYSTEM SHALL point its guides link at `/ai/learn`; the photos guide shows a "Format trial" link to `/ai/guides/photos/formats`.
- AC-9: WHEN `node bench/ui-audit.mjs` runs THE SYSTEM SHALL check `/ai/learn`, every guide page and every lesson page at 1440 and 1024 px and report 0 failing pages.
- AC-10 (BR-27): The `lessons` guide, README "Lessons" and `docs/learning-guide.md` "Scene lessons" describe the one learning area (Walk through vs Reference, reference topics), and `backlog.md` holds SL-57 and SL-58.

## Quality attributes

- NFR-1: Course page `data-page` payload stays under 60 000 characters (SL-55 budget), measured in `LessonPagesTest`.
- NFR-2: Accessibility: the switch and both sidebars are keyboard reachable with a visible focus outline; current items carry `aria-current="page"`; group labels are headings or labelled lists so screen readers announce the group (WCAG 2.2 AA).

## Boundaries

- **Always:** run PHP/npm through `ddev`; keep tests offline; check pages at 1440 and 1024 px.
- **Ask first:** none (owner standing approval for recommended choices).
- **Never:** edit `.ddev/config.yaml`; change lesson or guide content beyond the photos "Format trial" link and the `lessons` guide; add dependencies; show slice ids or repo paths in the UI.

## Technical context

- Approach: course data stays in PHP files read by `LessonRepository`; `GuideRepository` is injected (or a small `CourseMap` service built from both) to resolve reference keys, titles and summaries, and to build the guide → module and lesson → guides maps once per request (scoped, memoized like `LessonService`). Shared props: `course` (as today, extended with `references` per module and `guides` per lesson) used by Learn, Lesson and Guide pages.
- `LabLayout` widens to 1440 px for `Lab/Lesson` and `Lab/Guide`.
- Tests to update: `GuidePageTest` (index → redirect, navigation shape), `GuideContentTest` (guide titles now asserted on `/ai/learn`), `GuideFormatPagesTest` (trial no longer on the index; on the photos guide), `HomeLandingTest` (`guidesHref`), `NoReferencesInUiTest` and `CloudKeysNeverLeakTest` (`/ai/guides` → `/ai/learn`, follow redirect), `LessonRepositoryTest`, `LessonPagesTest`, `UiAuditGuidePagesTest`.

## Decisions

- D-1: One index at `/ai/learn`, `/ai/guides` redirects — two index pages for the same set of topics is what made the area feel split. 301 because the move is permanent.
- D-2: Lesson ↔ guide links stay in guide files (`lessons` key); course.php only adds reference-only topics. One source per fact; no duplicated mapping to drift.
- D-3: The guide kind tag (capability / foundation) is dropped from the index: modules group topics now, and the kind still reads on the guide itself if needed.
- D-4: Lab sidebar grouped by what a page does (Text, Search, Photos, Agents) rather than by course module: a lab page's guide can sit in a module that does not match its function (Photos is taught by the structured-output lesson), and readers look for pages by function.
- D-5: Guide pages get the same 1440 px width and collapsed course rail as lessons, so moving between Walk through and Reference does not jump the layout.

## Open questions

- [x] 1–24 raised by the fresh-context clarity gate on 2026-11-20; all answered below as C-1 to C-24 (owner standing approval: recommended answers).

## Clarifications

- C-1: "First guide" of a lesson = guides ordered by `GuideRepository` order (alphabetical by key); the same order everywhere (header, "Also:", course page).
- C-2: A guide's "first linked lesson" = first entry of the guide's own `lessons` array.
- C-3: On a lesson-linked guide, the course sidebar marks that guide's first linked lesson row with `aria-current="page"` (no done tick change). On a reference guide, its reference row is current.
- C-4: Validation adds: every lesson in course.php is named by at least one guide's `lessons` key, else `InvalidLessonException`. All 8 lessons satisfy this today.
- C-5: Scope 2 rules are four: unknown key; key in two modules; key both referenced and lesson-linked; a content guide owned by neither. `lessons` (meta guide) is not allowed in any `references` list.
- C-6: Fail-fast is accepted: an unplaced new guide breaks `/ai/learn` and lessons, caught by `LessonRepositoryTest`; the `lessons` guide's authoring notes say so.
- C-7: `Route::permanentRedirect('/ai/guides', '/ai/learn')->name('ai.guides.index')`; query dropped; tests assert status 301 and redirect to `/ai/learn`.
- C-8: Delete `ShowLabGuidesController`, `Guides.vue` and logic only they used (the index `trial` prop).
- C-9: A generic `formatsHref` prop on guides that have a format trial (only photos today), rendered as a small "Format trial" link in the topic header meta line.
- C-10: Breadcrumb "Learn" → `/ai/learn`; module → `/ai/learn#<module slug>`; `Learn.vue` sets `id="<slug>"` on each module section.
- C-11: Update every consumer of `lab.navigation` (PHP tests, TS types, LabLayout); no flat alias kept.
- C-12: Today's "Guides" and "Learn" entries merge into "Course". Every other current item goes into its group; the test asserts the full href set so nothing disappears.
- C-13: "Course" is current on every `/ai/learn*` and `/ai/guides*` URL, format trial pages included.
- C-14: Setup uses today's `settingsAvailable()` rule; tests cover on and off, and an empty group is omitted.
- C-15: Guide pages reuse the lesson rail's exact localStorage key `ai-lab:learn:sidebar`; widening applies to `Lab/Lesson` and `Lab/Guide` only (not format trial pages).
- C-16: Disabled segment: `<span aria-disabled="true">` "Walk through · lesson coming", muted, not focusable.
- C-17: Guide and lesson pages carry the same slim course summary (no step data); add a payload assertion (< 60 000) on one guide page.
- C-18: D-3 means: the guide page does not add a kind tag; the topic header meta line on a guide reads "reference".
- C-19: Guide summaries come from the existing required `summary` field.
- C-20: `ui-audit.mjs` collects every `/ai/guides/*` href on `/ai/learn` except `/formats` URLs; `UiAuditGuidePagesTest` asserts the discovered set equals all guide keys (the meta guide via "How these lessons work").
- C-21: AC-10 is verified manually only; AC-6 also gets a `GuidePageTest` assertion (course prop holds reference topics and the current topic id).
- C-22: Checked: the lab is a separate Vite entry (`resources/js/ai/app.ts`, `resources/css/ai.css`); the fingerprint covers `app.js`/`app.css`, so MNC-6 holds. If it changes anyway, stop and record why.
- C-23: "Also:" is an inline comma-separated list of links that wraps under the switch.
- C-24: Lab sidebar: one `<nav aria-label="Lab pages">` with, per group, a small uppercase visible heading and a `<ul aria-labelledby>`.
