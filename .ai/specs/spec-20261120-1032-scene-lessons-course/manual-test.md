# Manual test plan: remaining scene lessons (SL-56)

Base URL: https://ai-lab.ddev.site

## Preconditions

- DDEV is running: `ddev start`. The site opens at https://ai-lab.ddev.site/ai/learn.
- Frontend is built: `ddev npm run build` (or `ddev npm run dev` running).
- No login and no seeded data are needed. The lessons only read tracked files.
- Progress is stored in the browser (localStorage key `ai-lab:learn:v1`). Start clean: in the DevTools console run `localStorage.removeItem('ai-lab:learn:v1')` and reload.
- Rollback: nothing is written to the database or the server. To reset, run the same `localStorage.removeItem` line. To undo the change itself, revert the feature commit with git.

The eight lessons:

- https://ai-lab.ddev.site/ai/learn/running-models/one-model-many-users
- https://ai-lab.ddev.site/ai/learn/running-models/streaming-an-answer
- https://ai-lab.ddev.site/ai/learn/running-models/choosing-a-model-and-provider
- https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/structured-output-and-validation
- https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/retrieval-with-citations
- https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/caching-answers
- https://ai-lab.ddev.site/ai/learn/agents/a-tool-loop-with-caps-and-confirmation
- https://ai-lab.ddev.site/ai/learn/agents/mcp-and-a-research-agent

## Step 1

Course and layout (AC-1, AC-4). This is also the originally reported problem: seven lessons showed "coming soon" and could not be opened.

1. Open https://ai-lab.ddev.site/ai/learn.
2. Look at the list of lessons.
3. Open each of the eight lessons above at a window width of 1440 px, then again at 1024 px (DevTools device toolbar).
4. Run the audit from the project folder:

   ```sh
   node bench/ui-audit.mjs --only learn,learn/running-models/one-model-many-users,learn/running-models/streaming-an-answer,learn/running-models/choosing-a-model-and-provider,learn/answers-you-can-trust/structured-output-and-validation,learn/answers-you-can-trust/retrieval-with-citations,learn/answers-you-can-trust/caching-answers,learn/agents/a-tool-loop-with-caps-and-confirmation,learn/agents/mcp-and-a-research-agent
   ```

Expected:
- `/ai/learn` returns 200 and lists three modules (Running models, Answers you can trust, Agents) with eight lessons.
- Every lesson link shows its level, minutes and step count (for example "advanced, 12 min, 12 steps"). No lesson has a "coming soon" tag.
- The progress line reads "0 of 8 lessons done".
- Each lesson URL returns 200 and shows the scene with its first step. Boxes do not overlap and no label is cut off, at both widths. The only text outside a box is the small count bubble in a corner, as in the first lesson.
- The audit reports 0 failing pages and exits 0 (`echo $?` prints 0).

## Step 2

Interaction and smoothness (AC-5, NFR-2).

1. Open https://ai-lab.ddev.site/ai/learn/running-models/streaming-an-answer. Without the mouse, press ArrowRight until the last step, then ArrowLeft once. Repeat for the other seven lessons.
2. In one lesson press R, then Space.
3. Turn on reduced motion: DevTools, Rendering tab, "Emulate CSS media feature prefers-reduced-motion" set to "reduce". Reload https://ai-lab.ddev.site/ai/learn/agents/mcp-and-a-research-agent and walk its steps again.
4. Switch reduced motion off. Walk all eight lessons to their last step, then go back to https://ai-lab.ddev.site/ai/learn.
5. Frame timing: in DevTools, Performance tab, open the busiest step of a lesson (the one with the most moving dots, for example step 8 of 12 in `mcp-and-a-research-agent`), record 5 seconds and read the median frame time. Repeat for each lesson.

Expected:
- Each ArrowRight changes the address to the next step (`.../2`, `.../3`, up to the last step) and the text read by screen readers changes (for example "Step 2 of 10: ..."). ArrowLeft goes back one step.
- R and Space work. Space switches the "Pause" button to "Resume".
- With reduced motion, no dot moves. Dots are drawn as dashed static markers and the keys still work.
- `/ai/learn` now reads "8 of 8 lessons done".
- The median frame time is 20 ms or less (about 16.7 ms at 60 Hz) in every lesson.

## Step 3

Accuracy (NFR-1).

1. Open the review record in `.ai/specs/spec-20261120-1032-scene-lessons-course/tasks.md` (the table of claims checked against code).
2. In each of the seven new lessons, pick one number shown in a KPI and find it in its result file under `bench/results/` or in `BENCHMARKS.md`.
3. Pick one claim per lesson and check it in the code. Examples:
   - caching: `DocsAnswerCache::normalize` removes trailing `?`, `!`, `.` and spaces.
   - tool loop: `AdminAssistantLoop::MAX_STEPS` matches the step cap in the lesson.
   - MCP and research: the research caps in `ai.research.max_model_calls`.

Expected: every number you check matches its result file exactly, and every claim matches the code.

## Step 4

Numbers come from files, and "not measured" is honest (AC-2, MNC-2).

1. Open the caching lesson step about old answers going away (https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/caching-answers, then step "Old answers go away") and read the KPI "Entries gone stale per docs change" and the text.
2. Repeat on the confirmation steps of `a-tool-loop-with-caps-and-confirmation` and `mcp-and-a-research-agent`, and on the step "A replay costs nothing".
3. In any lesson, look for digits written inside sentences.

Expected:
- Steps with no measurement show "not measured" in the KPI and say so in the text. No invented number appears.
- Every number in a KPI or sentence can be found in a file under `bench/results/` or in `BENCHMARKS.md`.

## Step 5

Deeper links and guide backlinks (AC-3, AC-6).

1. In each new lesson find a step with the link "Go deeper in the guide" and click it.
2. Open https://ai-lab.ddev.site/ai/guides/lessons and read "The eight lessons".
3. Open these guides and find the link to their lesson, then click it: `/ai/guides/local-models`, `/ai/guides/streaming`, `/ai/guides/chat`, `/ai/guides/providers`, `/ai/guides/nl-search`, `/ai/guides/moderation`, `/ai/guides/photos`, `/ai/guides/docs`, `/ai/guides/assistant`, `/ai/guides/admin-assistant`, `/ai/guides/mcp`, `/ai/guides/research`.
4. Open the "Lessons" section of `README.md` and "Scene lessons" in `docs/learning-guide.md`.

Expected:
- Each "Go deeper" link opens an existing guide and shows the named tab (the address ends with `#<tab>`).
- Each lesson has 8 to 13 steps and ends on a summary step.
- The lessons guide lists all eight titles. Each of the twelve guides links its lesson and the link opens it. README and the learning guide list the eight lessons.

## Step 6

New icons (AC-7).

1. Open https://ai-lab.ddev.site/ai/learn/agents/a-tool-loop-with-caps-and-confirmation/2 and find the tools box and the person box.
2. Open https://ai-lab.ddev.site/ai/learn/agents/mcp-and-a-research-agent/2 and find the same two.

Expected: the tools box and the person box each have their own icon, different from the other boxes, and their text is easy to read against the fill.

## Step 7

First lesson and engine unchanged (MNC-1).

1. Open https://ai-lab.ddev.site/ai/learn/running-models/one-model-many-users and walk it to the last step.
2. Run `ddev artisan test --compact tests/Feature/LessonPagesTest.php tests/Feature/LessonRepositoryTest.php`.

Expected: the first lesson plays as before (10 steps) and the tests pass.

## Step 8

No internal references, assets or dependencies changed (MNC-3, MNC-4).

1. On `/ai/learn` and each lesson page, select all text and look for slice ids (such as "SL-56"), `.md` file names and repo paths.
2. Run `bench/asset-fingerprint.sh`.
3. Run `git diff --stat 1d5b7a6 -- composer.json composer.lock package.json package-lock.json database routes config`.

Expected:
- No slice id, `.md` name or repo path is visible on these pages.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The git diff prints nothing: no new dependencies, tables, routes or config.
