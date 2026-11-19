---
id: spec-20261117-2243-guide-format-variants
title: Five interactive guide formats, trialled on the photos guide
type: feature
size: L
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-17; standing approval)
approved_at: 2026-11-17T20:49:44Z
created: 2026-11-17
stacks: [laravel]
slice: SL-54
brs: [BR-27]
---

# Five interactive guide formats, trialled on the photos guide

## Summary

Today every in-app guide uses one format: a tabbed reference page (How it works, Implementation, Modify, Support, Numbers, Experiments, plus Build it yourself, Watch out for and Elsewhere). This slice builds **five different teaching formats** for one topic, the photos guide ("Read a car photo", `/ai/guides/photos`), so the owner can try them side by side and pick the format that best teaches someone to **implement** the feature. The existing guide stays unchanged as the baseline. Only the winning format is later rolled out to the other guides, in a separate slice.

The five formats:

1. **Build-along** (`build-along`): a step-by-step tutorial that rebuilds the feature from zero. Each step has a goal, the real code, a command to run, the expected result and a "done" checkbox.
2. **Follow one photo** (`trace`): a clickable flow of the stages one photo passes through, from the command through the queue, the job, the agent, the model server, the schema and the PHP rules to the stored row. Each stage shows what happens, sample data at that point and the code. "Play" steps through the stages in order.
3. **Predict and check** (`quiz`): 8 to 10 questions in build order. The learner answers first; the answer reveals right or wrong, a short explanation and the code.
4. **Break and fix** (`debug`): four or five failure scenarios. Each starts from a symptom (a sample run row, an error or a log line), reveals clues one at a time, asks for the cause, then shows the fix and the test that prevents it.
5. **Code tour** (`tour`): the real source files in reading order. Annotations are tied to line ranges, with previous and next buttons and a small map of where the tour is.

A **compare page** lists the baseline and the five formats. For each it gives the teaching idea, the expected time, and the same five learning outcomes, so the formats are judged against the same goal.

## Context

- **Problem:** the owner wants to know which guide format teaches implementation best before investing in all guides. Today there is only one format to judge.
- **User impact:** the learner (any developer who clones the repo) gets a better-teaching guide format across the lab once the winner is rolled out.
- **Read:** `product-brief.md` (BR-27; learner persona), `backlog.md` (SL-48 in-app guides, SL-53 lab pages that teach). There is no `docs/context/` (business-rules, architecture, api-contracts). Without it the rules below come from the brief, the SL-48/SL-53 specs and the code. To create those files, run `/write-docs --type=all`.
- **BR-27** (brief): every shipped slice explains how it works step by step and links to code, numbers and docs. Here it is the subject itself: each format must teach the photo feature step by step.
- **SL-48/SL-53 UI rules** (enforced by `tests/Feature/NoReferencesInUiTest.php` and `bench/ui-audit.mjs`):
  - no slice ids, `.md` names or repository paths (`app/`, `resources/`, `config/`, `routes/`, `database/`, `bench/`, `.ddev/`) anywhere in the rendered UI or props;
  - every lab page renders without broken layouts at 1440 and 1024 px.

## Learning outcomes (shared by every format)

After any format, the learner can:

- **LO-1** trace one photo from the extraction command to the stored extraction and the run row;
- **LO-2** add a field: the agent schema, its PHP rule and a new prompt version, together;
- **LO-3** explain why the answer is validated again in PHP and what happens after three failed attempts;
- **LO-4** explain the separate `ai-vision` queue and what happens when the model server is down (one attempt, no retry);
- **LO-5** change the vision model and confirm it from the run rows.

## Format rules (fixed for the build)

- **Where code goes (build-along, tour):**
  - A step names its target by class and method (`App\Ai\PhotoVisionAgent::schema`) or by its Laravel role ("a config key in the AI config", "a new Artisan command"), never by path.
  - Commands are `ddev artisan …` lines, which carry no repository path.
  - Snippets render exactly as in today's guides: code plus a class/method label. The file path stays only in the snippet's `file` prop field, which the no-references check already exempts.
- **References:**
  - The format files use the forms `SnippetResolver` supports today: `App\Class`, `App\Class::method`, a small file, or a `file#region` marker region under app, config, resources, routes or database. That covers the queue and model config (LO-4, LO-5) and the extraction command.
  - A tour stop is one reference. Its annotation targets lines relative to the snippet (1 = first shown line).
  - A "preventing test" in the debug format is named by test title and described in words. Test files are not in the resolver's folders and are not shown as code.
- **Baseline entry:** its idea, its expected time and its outcome mapping (which tab teaches which LO) live in `resources/guides/formats/photos/_compare.php`, next to the per-format metadata. `photos.php` is not touched. The baseline is not tested for LO coverage, because it is the reference as it is.
- **Learning outcomes on the compare page:** listed once at the top. Each entry then shows, per LO, the step, question, scenario, stage or stop that covers it (for the baseline, the tab). The props hold the LO list once, plus an `outcomes` map per entry.
- **Rubric:** static text on the compare page. There are four criteria (clarity, time to first working change, retention, effort to author), each with a one-line meaning. Nothing is scored or stored.
- **Expected time:** set by the author in `_compare.php` as a range in minutes ("15–20 min"). It is an estimate, not a measurement.
- **Build-along progress:** stored under the `localStorage` key `ai-lab.guide-format.<key>.build-along`, as a list of done step ids. Step ids are stable slugs in the content file (`schema`, `php-rules`, …); an unknown stored id is ignored. Renaming a step id resets that step.
- **Quiz:** questions are shown one at a time in build order. An answer locks. Next is enabled after answering. The score shows after the last question. State is in memory only, so a reload restarts the quiz.
- **Trace:** Play advances one stage every 2.5 s from the selected stage (the first by default) and stops on the last stage. Pause keeps the current stage. Every stage is a button, so Tab or Shift-Tab plus Enter or Space work. Left and Right arrows move between stages when the flow has focus.
- **Debug:** clues are revealed one at a time; at least one must be revealed before the cause can be chosen. A wrong cause says why it is wrong and can be retried. The fix and the preventing test show once the right cause is chosen, or after "Show answer".
- **Sample data:** written by hand in the format files, in the shape of real `ai_runs` rows, error messages and log lines. It is labelled "example" in the UI and contains no file or storage paths. Numbers are read from the committed photo-vision results through the existing numbers mechanism, never invented.
- **Validation:**
  - When it runs: format files are validated on every load, like guides. A test also loads every file.
  - An invalid file is a 500 in local, with an `InvalidGuideException` naming the file and the dotted field path (`steps.3.snippet`).
  - A key has formats only when all five files and `_compare.php` exist. Fewer than that is an invalid set: a load error, not a partial page.
  - LO ids are `LO-1` to `LO-5`, upper case.

## Scope

### In scope

1. **Content files:** one data file per format under `resources/guides/formats/photos/<format>.php`, validated at load like `resources/guides/*.php`. Code references use the existing `Class::method` snippet form resolved from the real source. Every file declares which learning outcomes each step covers.
2. **Pages:**
   - `GET /ai/guides/{key}/formats`: the compare page;
   - `GET /ai/guides/{key}/formats/{format}`: one Inertia page per format.

   Both answer 404 for a key without format files (today, every key except `photos`) and for an unknown format.
3. **Entry point:** a "Guide format trial" card on `/ai/guides` that links to the compare page. The photos guide page itself is not changed.
4. **Interactivity:**
   - Client-side only, no database writes and no model calls.
   - Build-along progress is saved in `localStorage`, per format and guide.
   - The quiz state lives in memory only (a reload restarts it).
   - Trace "Play" and the tour's previous/next work with the keyboard.
5. **Tests and checks:** feature tests for the routes, the content validation and LO coverage; the existing no-references and guide tests extended to the new pages; ui-audit run on the new pages.
6. **Learning guide:** a short note in `docs/learning-guide.md` (the in-app guides section) on the trial and how the formats are built.

### Out of scope

- Converting any other guide, and changing the photos guide or the shared guide engine output for existing guides.
- Storing ratings or feedback. The owner records the pick in `decisions.md` (task 8).
- New npm or Composer dependencies, such as a diagram, quiz or code-tour library.
- Live data from the database in the formats. Sample data is static and taken from the committed results and tests, so the pages work offline.

## Must NOT change

- **MNC-1:** `/ai/guides/photos`, and every other `/ai/guides/{key}`, renders the same props as before. `GuideContentTest`, `GuidePageTest` and the guide snapshot checks pass unchanged.
- **MNC-2:** `/ai/guides` still lists every existing guide in the same order; the only addition is the trial card.
- **MNC-3:** no slice id, `.md` name or repository path appears in any rendered lab page, new pages included (SL-48/SL-53 rule).
- **MNC-4:** the public-site asset fingerprint stays `app-DNob7CFE.css` / `app-PTzu1QaN.js` (`bench/asset-fingerprint.sh`), and parity snapshots differ only in `captured=`.
- **MNC-5:** the default test suite stays offline: no model server, no running web server.

## Acceptance criteria

- **AC-1:** WHEN a learner opens `/ai/guides/photos/formats` THE SYSTEM SHALL answer 200 with:
  - the five learning outcomes, listed once;
  - the four-criterion rubric;
  - six entries (the baseline and the five formats), each with a name, the teaching idea, the expected time, a link, and where it covers each learning outcome.
- **AC-2:** WHEN a learner opens `/ai/guides/photos/formats/{format}` for each of `build-along`, `trace`, `quiz`, `debug` and `tour` THE SYSTEM SHALL answer 200 with that format's page.
- **AC-3:** WHEN the key has no format files or the format is unknown THE SYSTEM SHALL answer 404.
- **AC-4:** WHEN a format file is loaded THE SYSTEM SHALL validate it. A missing required field, an unknown learning-outcome id, or a snippet reference that does not resolve to real source raises an error that names the file and the field (the guide engine's `InvalidGuideException`).
- **AC-5:** every format SHALL cover all five learning outcomes, LO-1 to LO-5, in at least one step, question, scenario, stage or tour stop, and a test SHALL prove it per format.
- **AC-6:** WHEN a learner ticks a build-along step and reloads the page THE SYSTEM SHALL show that step as done. A "reset progress" control clears all steps.
- **AC-7:** WHEN a learner answers a quiz question THE SYSTEM SHALL reveal right or wrong, the explanation and the code, and lock the answer. After the last question it shows the score out of the total.
- **AC-8:** WHEN a learner presses Play on the trace THE SYSTEM SHALL highlight each stage in order and show its panel, and Pause stops it. Clicking a stage shows its panel at any time.
- **AC-9:** WHEN a learner opens a debug scenario THE SYSTEM SHALL show the symptom first, reveal clues one at a time, accept a choice of cause, and then show the cause, the fix and the preventing test.
- **AC-10:** WHEN a learner moves through the tour THE SYSTEM SHALL show the real source lines for the current stop with its annotation, and previous and next move one stop.
- **AC-11:** `/ai/guides` SHALL show a "Guide format trial" card linking to `/ai/guides/photos/formats`.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Accessibility | Every interactive control (step checkbox, quiz option, Play/Pause, stage, clue, previous/next) works with the keyboard and has an accessible name. Quiz and debug feedback is announced through an `aria-live="polite"` region. | `manual-test.md#step-3` (keyboard-only walk), ui-audit |
| **NFR-2** | Layout | The compare page and the five format pages render without broken layouts at 1440 and 1024 px | `node bench/ui-audit.mjs --only guides,guides/photos/formats,...` exit 0 |
| **NFR-3** | Offline | The pages need no model server and make no application writes (no row in any app table; the framework's own session row is not counted) | `tests/Feature/GuideFormatPagesTest.php` (a `DB::listen` collector sees no INSERT, UPDATE or DELETE; `Http::preventStrayRequests()`) |

## Boundaries

- **Always:**
  - reuse the guide engine (`GuideRepository` validation style, `SnippetResolver`, `LabLayout`, the existing `Lab*` components);
  - keep code references as `Class::method`, so the UI shows class names, never paths;
  - add a feature test for every AC that has server-side behaviour (AC-1 to AC-5, AC-10 content, AC-11); the browser interactions of AC-6 to AC-10 are checked in manual-test step 2, and no JS test runner is added;
  - run ui-audit on the new pages.
- **Ask first:**
  - any new npm or Composer dependency;
  - changing the output of the existing guide engine for existing guides;
  - any database table.
- **Never:**
  - change `resources/guides/photos.php` or the photos guide page;
  - show slice ids, `.md` names or repository paths in the UI;
  - call a model or write to the database from these pages.

## Technical context

- **Related code:**
  - **Guides:** `app/Guides/GuideRepository.php` (loading and validation), `app/Guides/SnippetResolver.php` (`Class::method` → file, lines, code), `app/Services/GuidePageService.php`, `app/Http/Controllers/ShowLabGuideController.php`, `ShowLabGuidesController.php`, `routes/web.php` (the `/ai/guides` routes);
  - **Pages:** `resources/js/ai/Pages/Lab/Guide.vue` and `Guides.vue`, the `resources/js/ai/components/Lab*.vue` components;
  - **Content source:** `resources/guides/photos.php`, and the photo feature itself (`App\Ai\PhotoVisionAgent`, `App\Services\PhotoExtractionService`, `App\Rules\PhotoExtractionRules`, the extraction command and job);
  - **Numbers:** the committed results in `bench/results/photo-vision/2026-11-11/run.json`.
- **Already implemented:** the guide engine, snippet resolution from real source, the lab layout and components, the ui-audit script, and the no-references test.
- **Approach:**
  - **Loading:** a `GuideFormatRepository` loads and validates `resources/guides/formats/{key}/{format}.php`, one schema per format, and resolves snippets with `SnippetResolver`.
  - **Pages:** a `GuideFormatPageService` builds the props. Two invokable controllers render `Lab/GuideFormats` and `Lab/GuideFormat/<Format>`. Markdown in bodies goes through the existing `GuideRenderer`.
  - **Front end:** Vue 3 with TypeScript and Tailwind 4, with the trace drawn in plain SVG/CSS.
- **Repos:** single repo `/Users/stasmarkov/work/interview`.

## Rollout

- **Flag:** none. The pages are additive and sit under the lab, which runs locally.
- **Deploy order:** one deploy: routes, content and pages together.
- **Rollback:** remove the routes and the trial card. Nothing persists, apart from `localStorage` keys in the learner's browser.

## Decisions

See `decisions.md` (D-1 to D-6).

## Open questions

- [x] Q1: Which topic for the trial? **Answered:** photos (owner's example), because it has a pipeline, a queue, a schema, validation and failure modes, which exercises all five formats.
- [x] Q2: How does the owner pick? **Answered:** the shared learning outcomes plus a short rubric on the compare page (clarity, time to first working change, retention, effort to author). The pick is recorded by hand in `decisions.md` in Gate task 8, with no stored ratings.
- [x] Q3 to Q16 (clarity gate, 2026-11-17, 15 items). All answered in **Format rules** above, self-decided under the owner's standing preference:
  - paths in the build-along;
  - references for config, tests and line ranges;
  - baseline data;
  - the LO display shape;
  - the rubric;
  - session writes (NFR-3 reworded);
  - quiz state;
  - tests vs manual checks (Boundaries reworded);
  - trace behaviour;
  - debug and quiz rules;
  - `localStorage` keys;
  - expected time;
  - validation timing, partial sets and the field path format;
  - sample data.

  Q16, MNC-4 and shipping before the pick: the lab has its own Vite entry (`resources/js/ai/app.ts`, `resources/css/ai.css`) and `app.css` excludes the lab sources (`@source not '../js/ai'`), so the public fingerprint cannot change. The slice ships when tasks 1–7 are done; Gate task 8 (the pick) follows the ship and keeps the spec `in-progress` until the owner records it.

## Dependencies

- **Requires:** SL-48 and SL-53 (guide engine, lab pages, ui-audit), both shipped.
- **Blocks:** a follow-up slice that rolls the chosen format out to the other guides.

---
Next: `/sdd spec-20261117-2243-guide-format-variants` (approval → build) or `/execute-task --spec=20261117-2243`
