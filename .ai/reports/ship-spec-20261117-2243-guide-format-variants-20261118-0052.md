# Ship report: spec-20261117-2243-guide-format-variants

## Summary

SL-54 adds five interactive guide formats (build-along, trace, quiz, debug, code tour) and a compare page, trialled on the photos guide at `/ai/guides/photos/formats`. 11 of 13 build tasks completed. Tasks 6 and 10 are incomplete. All four quality gates pass. The acceptance walk (22 steps) resolved end to end. Converge stopped at its round cap, and the round-2 gaps (14, 15) were built but not re-audited. One fix round closed 11 of 20 confirmed review findings. The 9 that remain are all minor. The manual browser and keyboard walk (task 13) and the owner's format pick (task 8) are still open.

**Recommendation: FIX-FIRST.**

## Stack & repos

| Repo | Role | Stack | Tracked | Branch | Left branch |
| --- | --- | --- | --- | --- | --- |
| `/Users/stasmarkov/work/interview` | backend | laravel | yes | `feature/guide-format-variants` (created by Prepare) | `master` |

- No untracked or WIP repos.
- Repo warning: the Claude Code Glob tool was unavailable because the user stacks directory could not be listed.
- Stack overlay: discovery installed-plugins (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `plugin:sdd-kit-laravel@sdd-kit`. Discovery errors: user stacks unreadable, trustStore unreadable.

## Spec

- Id: `spec-20261117-2243-guide-format-variants`
- Folder: `.ai/specs/spec-20261117-2243-guide-format-variants`

## Investigation highlights

Investigation returned no key files, risks or existing implementation (empty result). The feature is new code:

- `app/Guides/GuideFormatRepository.php`
- `app/Services/GuideFormatPageService.php`
- `app/DTOs/GuideFormat{Compare,File,Meta}.php`
- two invokable controllers
- `resources/guides/formats/photos/*.php`
- `resources/js/ai/Pages/Lab/GuideFormats.vue`
- `resources/js/ai/Pages/Lab/GuideFormat/*.vue`

## Tasks completed

Build: **11/13**.

- **Incomplete: 6** (Code tour format) **and 10** (Quiz shows one question at a time, Next after answering, score after the last). `tasks.md` has both ticked, but the build phase marked them incomplete. Re-run their Verify steps before ticking them.
- **Not built: task 8.** Gate: the owner tries the six formats and records the pick (in `decisions.md`, with the rollout slice added to `backlog.md`). This waits for the owner. To resume after the pick: `Workflow({ name: "sdd-kit:ship-feature", args: { spec: "spec-20261117-2243-guide-format-variants" } })` (file-copy install: name `"ship-feature"`).
- **Manual: task 13.** Run manual-test steps 2 and 3 (browser behaviour and keyboard-only walk of the five formats) and record the result. This is neither built nor incomplete; see Manual test plan.
- Completed: 1, 2, 3, 4, 5, 7, 9, 11, 12, 14, 15.

## Converge

Two rounds ran. **Stop reason: cap.**

- **Round 1, gap tasks appended:**
  - 9: NFR-3 test also requests the five format pages
  - 10: Quiz shows one question at a time, Next after answering, score after the last
  - 11: Trace has a 2.5 s Play step, Left/Right arrow keys between stages, and a sample labelled "example"
  - 12: Debug locks the cause until a clue is shown, adds a "Show answer" control, and labels the symptom "example"
- **Round 1, manual gap:** 13 (manual-test steps 2 and 3), sent to the manual test plan.
- **Round 2, gap tasks appended:**
  - 14: Build-along test commands run real tests. `--filter=PhotoExtractionRules` and `--filter=PhotoExtractionService` matched no tests.
  - 15: Debug "preventing test" names a real test title.
- **Built but not re-audited (last round):** 14, 15.
- **Failed to build:** none.
- **Gaps left unbuilt:** none.

## Quality gates

Repo: `/Users/stasmarkov/work/interview`

| Gate | Status | Details |
| --- | --- | --- |
| Pint | pass | PASS 1054 files (via ddev exec) |
| PHPStan | pass | [OK] No errors (via ddev exec) |
| Pest/PHPUnit | pass | 3451 passed, 3 skipped (44402 assertions) (via ddev exec) |
| Debug scan | pass | No `dd(` `dump(` `ray(` in app/ routes/ database/ |

## Visual tests

Not run (no visual phase result).

## Acceptance

- **Resolved end to end: yes.** 22 steps, no failed steps, no broken links or hops.
- **E2E regression test:** none written. The phase returned no result, so there are no AC ids and no pass or fail to report. The browser-only criteria (AC-6 to AC-10, NFR-1) still depend on manual-test steps 2 and 3.

## Confirmed review findings

### Major

- F1 `resources/js/ai/Pages/Lab/GuideFormat/Quiz.vue:99`: the quiz answer locks on the first arrow-key press, so keyboard users cannot pick freely. **fixed**
- F2 `resources/js/ai/Pages/Lab/GuideFormat/Debug.vue:158`: Debug "Show answer" never shows which cause is correct and keeps saying "Try another cause". **fixed**
- F3 `resources/guides/formats/photos/debug.php:60`: the debug scenario "old-model" teaches a false rule about how the command skips photos. **fixed**
- F4 `resources/guides/formats/photos/tour.php:22`: most tour annotations point at docblock or brace lines, not the code they describe. **fixed**

### Minor

- F5 `app/Guides/GuideFormatRepository.php:48`: a format folder with missing files keeps the trial card and turns its pages into 500s instead of 404s. **fixed**
- F6 `resources/js/ai/Pages/Lab/GuideFormat/Trace.vue:78`: trace arrow keys move from the current stage, not the focused one. **fixed**
- F7 `resources/guides/formats/photos/quiz.php:90`: the quiz content tags a question with an outcome it does not teach, and the trace sample shows the wrong prompt-version format. **fixed**
- F8 `tests/Feature/GuideFormatPagesTest.php:79`: the MNC-1 test compares the photos guide props to themselves. **remaining**
- F9 `.ai/specs/spec-20261117-2243-guide-format-variants/tasks.md:31`: `tasks.md` marks task 4 (quiz) open although it shipped, and a stray sed backup file is left. **fixed**
- F10 `app/Services/GuideFormatPageService.php:55`: the compare page resolves all ~32 code snippets in five format files just to get item titles for the coverage table. **remaining**
- F11 `app/Guides/GuideFormatRepository.php:93`: `assertComplete` repeats 7 filesystem stats on every compare or format load in a request. **fixed**
- F12 `resources/guides/formats/photos/quiz.php:55`: duplicate and overlapping snippets inflate the Inertia payload on Quiz and Trace. **remaining**
- F13 `resources/js/ai/Pages/Lab/GuideFormat/Trace.vue:123`: an inline function ref in the Trace stage buttons is re-created and re-run on every render, including each 2.5 s autoplay tick. **fixed**
- F14 `app/Services/GuideFormatPageService.php:89`: format pages receive an `outcomes` prop that no format page reads. **remaining**
- F15 `app/DTOs/GuideFormatFile.php:14`: `GuideFormatFile::$key` and `::$format` are never read. **fixed**
- F16 `app/DTOs/GuideFormatFile.php:24`: domain logic (outcome coverage) lives inside a DTO. **remaining**
- F17 `app/Guides/GuideFormatRepository.php:142`: format items cross the repository/service boundary as untyped arrays, not DTOs. **remaining**
- F18 `app/Services/GuideFormatPageService.php:85`: the service picks the Inertia component, which is a response concern that belongs to the controller. **remaining**
- F19 `app/Services/GuideFormatPageService.php:27`: `trial()` hardcodes the `'photos'` key instead of discovering format sets as its docblock claims. **remaining**
- F20 `app/Services/GuideFormatPageService.php:88`: mappings and validation are duplicated between `GuideFormatPageService` and `GuideFormatRepository`. **remaining**

The remaining minor findings do not block SHIP on their own.

## Fix

- Rounds: 1. **Stop reason: clean.**
- Fixed: F1, F2, F3, F4, F5, F6, F7, F9, F11, F13, F15
- Remaining: F8, F10, F12, F14, F16, F17, F18, F19, F20

## Documentation updated

The docs phase returned no result. Task 7 added a short note to `docs/learning-guide.md` (in-app guides section) on the trial and how the formats are built (+2 lines in the working tree).

## Manual test plan

- Written to `/Users/stasmarkov/work/interview/.ai/specs/spec-20261117-2243-guide-format-variants/manual-test.md` (10 steps).
- How to run: `ddev start && ddev server frankenphp && ddev npm run build`, then follow the steps in order at https://ai-lab.ddev.site/ai/guides.
- After steps 2 and 3 pass (browser behaviour and keyboard-only walk of the five formats), tick **task 13** in `tasks.md`. That covers AC-6, AC-7, AC-8, AC-9, AC-10 and NFR-1.
- Step 5 is the owner pick (task 8).

## Cost

| Phase | Agents | Tokens |
| --- | --- | --- |
| Detect | 1 | tokens: n/a |
| Spec | 1 | tokens: n/a |
| Prepare | 2 | tokens: n/a |
| Build | 13 | tokens: n/a |
| Gates | 4 | tokens: n/a |
| Converge | 2 | tokens: n/a |
| Review | 50 | tokens: n/a |
| Fix | 22 | tokens: n/a |
| Acceptance | 1 | tokens: n/a |
| TestPlan | 1 | tokens: n/a |

`tokens: n/a` means the run had no token budget. The Report agent is not counted. Failed agent-name lookups: 0.

## Recommendation

**FIX-FIRST.** Reasons:

1. Build tasks 6 (code tour) and 10 (quiz one question at a time) are incomplete.
2. Converge hit its round cap, so gaps 14 and 15 were built but never re-audited against the spec.
3. Manual task 13 has not been run. The browser and keyboard criteria AC-6 to AC-10 and NFR-1 have only a manual check, and no E2E test was written.
4. F8 remains: the MNC-1 test compares the photos guide props to themselves, so "existing guide pages unchanged" is not really checked. Like the other 8 remaining findings it is minor and does not block on its own.

Task 8 (owner pick) is a decision gate after ship and does not block.

Next: finish build tasks 6 (code tour) and 10 (quiz one question at a time) and re-run their Verify steps, then re-audit gaps 14 and 15 against the spec.
