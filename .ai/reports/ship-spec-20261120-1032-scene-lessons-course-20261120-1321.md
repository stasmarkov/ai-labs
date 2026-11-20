# Ship report: spec-20261120-1032-scene-lessons-course

## Summary

SL-56 adds the seven remaining scene lessons, so all eight lessons of the /ai/learn course can be opened (before this, seven showed "coming soon"). It also links the related guides to their lessons and lists the course in the lessons guide and the README. All 16 build tasks are done. Converge ran 2 rounds and stopped at the round cap with no gaps left unbuilt. All four quality gates pass. Acceptance resolved the original scenario end-to-end (21 steps, no failures, no broken links). Review confirmed 21 findings: both majors and 7 minors are fixed, and 12 minors remain. None of the remaining findings block shipping.

## Stack & repos

| Repo | Role | Stack | Tracked | Branch | Left when Prepare switched |
| --- | --- | --- | --- | --- | --- |
| `.` | backend | laravel | yes | `feature/scene-lessons-course` (created) | `master` |

- Untracked or WIP repos: none. Repo warnings: none.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Plugin present: `plugin:sdd-kit-laravel@sdd-kit`. Discovery warning: "user stacks unreadable".

## Spec

- Id: `spec-20261120-1032-scene-lessons-course`
- Folder: `.ai/specs/spec-20261120-1032-scene-lessons-course`

## Investigation highlights

The Investigate phase recorded no key files, risks or already-shipped pieces. The files below come from the Converge and Review records:

- Lesson content: `resources/lessons/running-models/*`, `resources/lessons/answers-you-can-trust/*`, `resources/lessons/agents/*`
- Lesson plumbing: `app/Lessons/LessonService.php`, `app/Lessons/LessonRepository.php` (`NODE_KINDS` gained `tools` and `person`)
- Guides: `resources/guides/{chat,docs,assistant,local-models,moderation,photos,lessons}.php`, and the photos baseline in `tests/Fixtures/guides/photos-guide-props.json`
- Tests: `tests/Feature/{GuidePageTest,LessonContentTest,LessonRepositoryTest,NoReferencesInUiTest}.php`
- Already shipped and reused: the SL-55 scene-first lesson layout and pilot lesson (`running-models/one-model-many-users`)

## Tasks completed

16/16. Incomplete: none.

## Converge

- Rounds run: 2. Stop reason: cap.
- Round 1 appended 6 gap tasks:
  - 7: link the remaining related guides to their lessons, per the C-6 mapping
  - 8: list the eight course lessons in the lessons guide and the README
  - 9: say "not measured" on the C-11 concept steps, and test the rule
  - 10: tie the course spot-checks to the result value and to BENCHMARKS.md
  - 11: cover the seven new lesson pages in NoReferencesInUiTest
  - 12: restore the coming-soon case in the SL-55 guide-link test
- Round 2 appended 4 gap tasks:
  - 13: link the photos guide to its lesson, and make the AC-6 test walk the whole C-6 table
  - 14: fix the caching "not measured" sentence that contradicted the code, and review the sentences added after the accuracy review
  - 15: re-run the UI audit and the browser walk after the content edits, with aria-live and the C-8 busiest-step timing
  - 16: record the two new node kinds in the spec's Decisions
- Built in the last round but not re-audited: 13, 14, 15, 16. Failed to build: none.
- Manual gaps sent to the manual test plan: none.
- Gaps left unbuilt: none.

## Quality gates

| Repo | Gate | Status | Details |
| --- | --- | --- | --- |
| `.` | Pint | pass | PASS 1087 files (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | Pest/PHPUnit | pass | 3635 passed, 3 skipped (50207 assertions) (via ddev exec) |
| `.` | Debug scan | pass | No `dd(`, `dump(` or `ray(` in app/, routes/ or database/ |

## Visual tests

Not run (no visual result was recorded for this run).

## Acceptance

- Original scenario resolved end-to-end: yes. Seven lessons showed "coming soon" and could not be opened; now all eight open from /ai/learn.
- Steps: 21. Failed steps: none. Broken hops or links: none.
- E2E regression test: none written, so no AC ids are traced and nothing ran. The run recorded no E2E result.

## Confirmed review findings

### Major

- F1 `resources/lessons/answers-you-can-trust/structured-output-and-validation.php:193`: the retry step said retries added 1.28 tries per photo, but 1.28 is the mean of all tries, the first one included. **fixed**
- F2 `resources/lessons/running-models/choosing-a-model-and-provider.php:183`: "The order flips" on the assistant was wrong, because both features show the same pattern. **fixed**

### Minor

- F3 `resources/guides/chat.php:168`: the retrieval and caching lessons name `chat` as their guide, but the chat guide no longer links them. **remaining**
- F4 `resources/lessons/answers-you-can-trust/structured-output-and-validation.php:22`: the structured-output lesson has 10 steps but `minutes` was 9. **fixed**
- F5 `tests/Feature/LessonContentTest.php:417`: a spot-check passes by accident, because '86' is found inside '286 ms' and not in a shown 86 %. **remaining**
- F6 `resources/lessons/agents/mcp-and-a-research-agent.php:363`: the replay step replaced the measured "Run time (average)" with "not measured". **fixed**
- F7 `tests/Feature/LessonContentTest.php:481`: the C-11 test for the MCP confirmation step passes on an inherited KPI, so the added `mcp` override does nothing. **remaining**
- F8 `resources/lessons/answers-you-can-trust/structured-output-and-validation.php:304`: the summary said its figures were the second model's photo run, but the strip mixed in first-model search figures. **fixed**
- F9 `resources/lessons/running-models/streaming-an-answer.php:227`: streaming step 7 called the probe timeout cap the "slowest answers". **fixed**
- F10 `resources/lessons/running-models/streaming-an-answer.php:277`: streaming step 8 marks a good outcome with '-', which screen readers announce as "Removed:". **remaining**
- F11 `resources/lessons/agents/a-tool-loop-with-caps-and-confirmation.php:94`: the problem steps show the solution's measured results before the solution exists. **remaining**
- F12 `app/Lessons/LessonService.php:35`: every /ai/learn and lesson page now fully resolves all 8 lessons (12 guides, 21 result JSONs) just to show sidebar metadata. **remaining**
- F13 `tests/Feature/LessonContentTest.php`: the new AC-2 dataset test starts a git subprocess per file. **remaining**
- F14 `resources/lessons/answers-you-can-trust/retrieval-with-citations.php:17`: the retrieval lesson had an unused `$figure` closure. **fixed**
- F15 `resources/lessons/agents/mcp-and-a-research-agent.php:216`: a no-op KPI override was added only to satisfy the 'not measured' test. **fixed**
- F16 `resources/lessons/agents/mcp-and-a-research-agent.php:363`: the measured 'Run time (average)' KPI is overwritten with 'not measured' for a different quantity. **remaining**
- F17 `resources/lessons/answers-you-can-trust/caching-answers.php:49`: a new never-measured KPI used format 'count', unlike the sibling text-only KPIs. **fixed**
- F18 `tests/Feature/LessonRepositoryTest.php:407`: a contrast assertion compares two hard-coded literals and can never fail. **remaining**
- F19 `resources/guides/lessons.php:28`: the course lesson list is copied by hand into the lessons guide and the README instead of being derived from course.php. **remaining**
- F20 `bench/ui-audit.mjs:44`: the UI audit script hard-codes the eight lesson URLs, while the sibling test derives them from course.php. **remaining**
- F21 `resources/lessons/running-models/streaming-an-answer.php:17`: the same KPI-source helper closures are copy-pasted into every new lesson file. **remaining**

The remaining minor findings are listed for follow-up. They do not block SHIP.

## Fix

- Rounds run: 1. Stop reason: clean.
- Fixed: F1, F2, F4, F6, F8, F9, F14, F15, F17.
- Remaining: F3, F5, F7, F10, F11, F12, F13, F16, F18, F19, F20, F21 (all minor).

## Documentation updated

- `docs/learning-guide.md`
- `backlog.md`
- `resources/guides/lessons.php`
- Created: none.

## Manual test plan

- Written to `.ai/specs/spec-20261120-1032-scene-lessons-course/manual-test.md` (4 steps).
- How to run: `ddev start` and `ddev npm run build`, clear `localStorage` key `ai-lab:learn:v1`, then follow the steps from https://ai-lab.ddev.site/ai/learn.

## Cost

| Phase | Agents | Tokens |
| --- | --- | --- |
| Detect | 1 | tokens: n/a |
| Spec | 1 | tokens: n/a |
| Prepare | 1 | tokens: n/a |
| Build | 16 | tokens: n/a |
| Gates | 6 | tokens: n/a |
| Converge | 2 | tokens: n/a |
| Review | 38 | tokens: n/a |
| Fix | 16 | tokens: n/a |
| Acceptance | 1 | tokens: n/a |
| Docs | 1 | tokens: n/a |
| TestPlan | 1 | tokens: n/a |

"tokens: n/a" means the run had no token budget. The Report agent is not counted. Failed agent-name lookups: 0.

## Recommendation

**SHIP**

- 16/16 tasks are built, and no converge gap is left unbuilt or failed to build.
- All gates pass: Pint, PHPStan, PHPUnit (3635 passed, 3 skipped) and the debug scan.
- Acceptance resolved the original scenario end-to-end, with no failed steps or broken links.
- Both major findings (F1, F2) are fixed. The 12 remaining findings are minor.
- Caveats: gaps 13 to 16 were built in the last converge round but not re-audited, and no visual run or E2E regression test was recorded.

Next: /open-pr --spec=spec-20261120-1032-scene-lessons-course --report=.ai/reports/ship-spec-20261120-1032-scene-lessons-course-20261120-1321.md
