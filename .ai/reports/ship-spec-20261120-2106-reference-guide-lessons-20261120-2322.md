# Ship report: spec-20261120-2106-reference-guide-lessons (SL-58)

## Summary

SL-58 adds nine scene lessons for the eleven guides that were reference-only and showed "Walk through · lesson coming". The course now has 17 lessons in five modules, and no topic in the real course is reference-only. The lessons are `describing-a-car`, `prompts-you-can-version`, `guards-on-both-sides`, `search-by-meaning`, `searching-photos-by-text-and-likeness`, `finding-duplicate-photos`, `the-run-log`, `logs-and-a-safe-worker-restart` and `seeing-your-change-in-worker-mode`. The guides, the README and the learning guide all link the new lessons, and a test fixture still covers the reference-topic path.

10 of 11 tasks are built. All four gates pass, and acceptance resolved all 21 steps. Converge hit its round cap, and three minor review findings are still open. Recommendation: **FIX-FIRST**.

## Stack & repos

| Repo | Role | Stack | Tracked | Branch | Left branch |
| --- | --- | --- | --- | --- | --- |
| `.` | backend | laravel | yes | `feature/reference-guide-lessons` (created by Prepare) | `master` |

- Repo warnings: none.
- WIP: the work is not committed yet. The nine new lesson files are untracked (`resources/lessons/answers-you-can-trust/{guards-on-both-sides,prompts-you-can-version}.php`, `resources/lessons/running-models/describing-a-car.php`, and the new folders `resources/lessons/running-the-server/` and `resources/lessons/search-and-photos/`). The guide, test and doc edits are modified but unstaged.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `plugin:sdd-kit-laravel@sdd-kit`. Discovery reported one error: `trustStore unreadable`.

## Spec

- Id: `spec-20261120-2106-reference-guide-lessons`
- Folder: `.ai/specs/spec-20261120-2106-reference-guide-lessons`

## Investigation highlights

- Key files: Investigation returned none. The work touched `resources/lessons/course.php`, the nine new files under `resources/lessons/**`, eleven guides under `resources/guides/*.php` (each gains a `lessons` key and leaves `references`), `resources/guides/lessons.php`, `README.md`, `docs/learning-guide.md`, and the tests `GuidePageTest`, `LessonContentTest`, `LessonPagesTest` and `LessonRepositoryTest`.
- Risks: Investigation recorded none. Two came up later: the accuracy of the lesson claims (handled by the NFR-1 review and findings F1 to F10), and losing test coverage of the SL-57 reference-topic path (gaps 10 and 11).
- Already shipped: Investigation recorded nothing. This slice builds on SL-57, which added the single learning area, the grouped lab sidebar, `LessonRepository` and the reference-topic state in `TopicHeader.vue`.

## Tasks completed

10 of 11 built. **Incomplete: 4.**

| # | Task | State |
| --- | --- | --- |
| 1 | Lessons: running models and trustworthy answers (`describing-a-car`, `prompts-you-can-version`, `guards-on-both-sides`) | done |
| 2 | Lessons: search and photos (`search-by-meaning`, `searching-photos-by-text-and-likeness`, `finding-duplicate-photos`) | done |
| 3 | Lessons: running the server (`the-run-log`, `logs-and-a-safe-worker-restart`, `seeing-your-change-in-worker-mode`) | done |
| 4 | Move the course and page tests to 17 lessons and no reference topics | **incomplete**: 4.1 done, but 4.2 "Verify: tests green" is not ticked |
| 5 | Docs: lessons guide, README, learning guide | done |
| 6 | Accuracy review of the nine lessons (NFR-1 table) | done |
| 7 | Manual: walk manual-test.md | manual, not run (see Manual test plan) |
| 8 to 10 | Converge round 1 gaps | done |
| 11 to 12 | Converge round 2 gaps | done (not re-audited) |

## Converge

Ran 2 rounds and stopped at the **round cap**. No gaps are left unbuilt, no gap failed to build, and no gap was sent to the manual test plan.

- **Round 1** added 3 gap tasks, all built:
  - 8: test that each of the 11 guides links its own new lesson in the topic header. Before this, only `describe` was checked, and `playground` and `similar-photos` were never checked.
  - 9: list the 17 lessons in the "Scene lessons" paragraph of `docs/learning-guide.md` and test it (AC-7).
  - 10: assert the reference-topic guide page shape (`topic.module`, `topic.lessons = []`) in the `ref-ok` fixture test (C-5, MNC-5).
- **Round 2** added 2 gap tasks. Both were built and **not re-audited**:
  - 11: put the parse-error guide case (`ref-parse`) back into the broken-guide fixture test.
  - 12: record the C-7 `bench/results/**` run-count search and the C-8 dropped story beat ("answer guard checks citations") in the NFR-1 table, and make the row pattern accept `dropped`.

## Quality gates

| Repo | Gate | Status | Details |
| --- | --- | --- | --- |
| `.` | Pint | pass | 1098 files pass (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | PHPUnit | pass | 3775 passed, 3 skipped (56122 assertions) |
| `.` | Debug scan | pass | No `dd(`/`dump(`/`ray(` in app/, routes/, database/ |

## Visual tests

Not run (no visual result for this run).

## Acceptance

- Original scenario: **resolved end-to-end.** The eleven guides that said "lesson coming" now link a playable lesson, and `/ai/learn` lists 17 lessons with no reference topics. 21 steps, 0 failed.
- Broken hops: none. Broken links: none.
- E2E regression test: none written. The backend-only Laravel stack declares no browser e2e, so there are no AC ids and nothing to run.

## Confirmed review findings

### Major

- F1 `resources/lessons/running-the-server/the-run-log.php:289`: the run-log summary says the log leaves out calls that never reached the model, which contradicts step 4. **fixed**
- F2 `resources/lessons/running-models/describing-a-car.php:246`: the stop figures come from the docs-answer stream but are presented as a description measurement. **fixed**

### Minor

- F3 `resources/lessons/running-the-server/seeing-your-change-in-worker-mode.php:316`: five rows are called "three modes, each with and without the watcher", which would be six. **fixed**
- F4 `resources/lessons/running-the-server/seeing-your-change-in-worker-mode.php:120`: the manual-restart step describes both server modes but quotes only the worker-mode figure. **fixed**
- F5 `resources/lessons/search-and-photos/searching-photos-by-text-and-likeness.php:194`: text-search precision is described as a share of returned photos, but the metric is a mean per query with empty queries counted as zero. **fixed**
- F6 `resources/lessons/running-the-server/logs-and-a-safe-worker-restart.php:98`: the lesson says two requests never share an id, but a client-supplied id is reused as is. **fixed**
- F7 `docs/learning-guide.md:1410`: the learning guide still named "MCP and a research agent" as the last lesson and left out this slice's spec. **fixed**
- F8 `resources/lessons/search-and-photos/search-by-meaning.php:52`: the recall KPIs use count format, so a fraction reads as a count of neighbours. **fixed**
- F9 `resources/lessons/running-the-server/the-run-log.php:176` (security): the lesson says the page is never told why a call failed, but the public run page shows the message. It also overstates key masking. **remaining**
- F10 `resources/lessons/running-the-server/logs-and-a-safe-worker-restart.php:171` (security): the lesson says "put values in the context", but only values under sensitive key names are redacted. **fixed**
- F11 `app/Services/LessonPageService.php:103` (performance): every guide and lesson sidebar now loads all 17 lesson files just to decide whether each sidebar link gets an href. **remaining**
- F12 `resources/guides/lessons.php:16` (design): the lessons guide hard-codes the lesson count that `course.php` already owns. **remaining**
- F13 `tests/Feature/LessonContentTest.php:742` (design): the NFR-1 test repeated the no-digit check that AC-2 MNC-2 already runs. **fixed**
- F14 `tests/Feature/LessonPagesTest.php:554` (design): the new real-course AC-1 test duplicated existing course assertions. **fixed**
- F15 `docs/learning-guide.md:1418` (design): the learning-guide section did not link this slice's spec. **fixed**

The open minor findings (F9, F11, F12) are listed here but do not block SHIP on their own.

## Fix

- Rounds: 1. Stop reason: clean.
- Fixed: F1, F2, F3, F4, F5, F6, F7, F8, F10, F13, F14, F15.
- Remaining: F9, F11, F12.

## Documentation updated

The Docs phase gave no separate result. This slice's own doc tasks (5, 9, F7, F15) updated:

- `README.md`: lists the 17 lessons.
- `docs/learning-guide.md`: the "Scene lessons" paragraph now names every lesson by module and links this slice's spec.
- `resources/guides/lessons.php` and the eleven guides that used to be reference-only now link their lessons.

## Manual test plan

- Written to `.ai/specs/spec-20261120-2106-reference-guide-lessons/manual-test.md`. TestPlan recorded 9 steps, but the file now has 15 step headings.
- How to run: `ddev start && ddev npm run build`, clear the `ai-lab:learn:v1` localStorage key, then work through each step against https://ai-lab.ddev.site in desktop Chrome at 1440 and 1024 widths.
- When every step passes, tick **7.1 and 7** in `tasks.md`, plus the "Done when" items that depend on them.

## Cost

| Phase | Agents | Tokens |
| --- | --- | --- |
| Detect | 1 | tokens: n/a |
| Spec | 1 | tokens: n/a |
| Prepare | 1 | tokens: n/a |
| Build | 11 | tokens: n/a |
| Gates | 4 | tokens: n/a |
| Converge | 2 | tokens: n/a |
| Review | 40 | tokens: n/a |
| Fix | 22 | tokens: n/a |
| Acceptance | 1 | tokens: n/a |
| TestPlan | 1 | tokens: n/a |

"tokens: n/a" means the run had no token budget. The Report agent is not included in this table. Failed agent-name lookups: 0.

## Recommendation

**FIX-FIRST.** Reasons:

- Task 4 is incomplete: its verify step (4.2, tests green) is not ticked. The full PHPUnit gate passed, so this is probably only a matter of confirming the result and ticking the box, but the task list does not show it as done.
- Converge stopped at the round cap, not on a clean audit. Round 2's gaps 11 and 12 were built but not re-audited.
- Manual task 7 (walk `manual-test.md`) has not been run.
- Three minor findings are still open: F9 (the run-log lesson misstates what the public run page shows and overstates key masking), F11 and F12.
- Nothing is committed yet, and the nine new lesson files are untracked.

Next: close task 4. Run `ddev artisan test --compact tests/Feature/LessonPagesTest.php tests/Feature/LessonRepositoryTest.php tests/Feature/GuidePageTest.php`, then tick 4.2 and 4 in `tasks.md`. After that, re-audit gaps 11 and 12 and fix F9 in `resources/lessons/running-the-server/the-run-log.php:176`.
