# Manual test plan: lessons for the reference-only guides (SL-58)

Base URL: https://ai-lab.ddev.site

The problem being fixed: eleven guides showed "Walk through · lesson coming" and `/ai/learn` listed reference-only topics. Now every one of them has a playable lesson, and the course has 17 lessons.

## Preconditions

- Run `ddev start`, then `ddev npm run build`.
- No login and no seeding. Progress is stored in the browser only. To start clean, run `localStorage.removeItem('ai-lab:learn:v1')` in the browser console on https://ai-lab.ddev.site and reload.
- Use a desktop Chrome window. DevTools device toolbar is handy for the 1440 and 1024 widths.
- Rollback: revert the feature commit. Nothing is written to the database, so there is nothing to clean up. Clear the `ai-lab:learn:v1` key to reset progress.

The nine new lessons:

- https://ai-lab.ddev.site/ai/learn/running-models/describing-a-car
- https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/prompts-you-can-version
- https://ai-lab.ddev.site/ai/learn/answers-you-can-trust/guards-on-both-sides
- https://ai-lab.ddev.site/ai/learn/search-and-photos/search-by-meaning
- https://ai-lab.ddev.site/ai/learn/search-and-photos/searching-photos-by-text-and-likeness
- https://ai-lab.ddev.site/ai/learn/search-and-photos/finding-duplicate-photos
- https://ai-lab.ddev.site/ai/learn/running-the-server/the-run-log
- https://ai-lab.ddev.site/ai/learn/running-the-server/logs-and-a-safe-worker-restart
- https://ai-lab.ddev.site/ai/learn/running-the-server/seeing-your-change-in-worker-mode

## Step 1

AC-1, and the originally reported problem: the course page.

1. Open https://ai-lab.ddev.site/ai/learn.
2. Read the module headings and count the lesson cards. Check each card shows minutes, a level and a step count.
3. Search the page (Ctrl+F) for "lesson coming" and for "Reference".
4. Open https://ai-lab.ddev.site/ai/guides.

Expected:
- Five modules: Running models (4 lessons), Answers you can trust (5), Agents (2), Search and photos (3), Running the server (3). 17 lessons in total.
- The page reads "0 of 17 lessons done". Every card has a "Walk through" link, minutes, level and steps. No card is greyed out.
- "lesson coming" has no match. "Reference" matches only the per-lesson guide links, never a topic tag.
- `/ai/guides` redirects (301) to https://ai-lab.ddev.site/ai/learn.

## Step 2

AC-2 (BR-26): numbers come from tracked result files.

1. Open https://ai-lab.ddev.site/ai/learn/running-models/describing-a-car and step through it with ArrowRight. Note the KPI figures: about 0.3 s first token, 51.6 tokens per second, 94 tokens, and the Stop probe (1 piece after Stop, 75 ms).
2. Open `bench/results/llm/2026-11-13/run.json` and `bench/results/chat-stop/2026-11-03/frankenphp.json` and find the same values.
3. Open https://ai-lab.ddev.site/ai/learn/running-the-server/seeing-your-change-in-worker-mode and note the restart and watcher times (527 ms and 2,468 ms). In `bench/results/reload/2026-11-17` the medians are 526.5 and 2467.5.
4. In each of the other seven new lessons pick one KPI or changed number and find it in a file under `bench/results/`.
5. Open the guards, prompts, run-log and worker-mode lessons and find the step that introduces a KPI showing "not measured".

Expected:
- Every number you checked matches its file (rounded on display).
- Where no measurement exists the KPI says "not measured", and the text of the step that introduces it says "not measured" too.

## Step 3

AC-3: shape of each new lesson.

1. For each of the nine lessons, open it and read the step counter ("Step 1 of N") and the outcomes list.
2. On the first step confirm it poses a problem, and on the last step confirm it is a summary.
3. Click every "deeper" link in each lesson.

Expected:
- Step counts: describing-a-car 10, prompts-you-can-version 11, guards-on-both-sides 10, search-by-meaning 11, searching-photos-by-text-and-likeness 12, finding-duplicate-photos 11, the-run-log 9, logs-and-a-safe-worker-restart 11, seeing-your-change-in-worker-mode 12 (all inside 8 to 13).
- Each lesson lists 3 to 6 outcomes and each one is taught by at least one step.
- Each deeper link opens an existing guide and lands on a tab that exists (#works, #implementation, #modify, #support or #numbers). No 404.

## Step 4

AC-4: the 11 guides link to their lesson.

1. Open each guide and look at the topic header:
   - https://ai-lab.ddev.site/ai/guides/describe
   - https://ai-lab.ddev.site/ai/guides/prompts
   - https://ai-lab.ddev.site/ai/guides/playground
   - https://ai-lab.ddev.site/ai/guides/guards
   - https://ai-lab.ddev.site/ai/guides/semantic
   - https://ai-lab.ddev.site/ai/guides/photo-search
   - https://ai-lab.ddev.site/ai/guides/similar-photos
   - https://ai-lab.ddev.site/ai/guides/photo-duplicates
   - https://ai-lab.ddev.site/ai/guides/run-log
   - https://ai-lab.ddev.site/ai/guides/logs-restart
   - https://ai-lab.ddev.site/ai/guides/worker-dx
2. Click "Walk through" on each.

Expected: all 11 answer 200 and "Walk through" is a link, never "lesson coming". It opens: describe to describing-a-car; prompts and playground to prompts-you-can-version; guards to guards-on-both-sides; semantic to search-by-meaning; photo-search and similar-photos to searching-photos-by-text-and-likeness; photo-duplicates to finding-duplicate-photos; run-log to the-run-log; logs-restart to logs-and-a-safe-worker-restart; worker-dx to seeing-your-change-in-worker-mode.

## Step 5

AC-5: scenes fit at 1440 and 1024 px, and the UI audit passes.

1. Open each new lesson at a 1440 px wide window, press ArrowRight through every step and look at the scene. Repeat at 1024 px.
2. Run:

   ```sh
   node bench/ui-audit.mjs; echo "exit=$?"
   ```

Expected:
- No boxes overlap, no label is cut off, no text sits outside its box, and there is no horizontal scrollbar at either width.
- The audit prints `lessons 17 from /ai/learn`, 0 failing pages, and `exit=0`.

## Step 6

AC-6: keyboard, reduced motion, aria-live, progress.

1. Clear progress: run `localStorage.removeItem('ai-lab:learn:v1')` and reload.
2. In each new lesson press ArrowRight until the last step, then ArrowLeft once.
3. In one lesson press Space, then Space again.
4. In DevTools (Rendering tab) set "Emulate CSS media feature prefers-reduced-motion" to "reduce". Walk one lesson and watch the dots.
5. With a screen reader on, or by inspecting the page, find the element with `aria-live="polite"` and watch it while you press ArrowRight.
6. Walk all 17 lessons (the 8 old ones included) to their last step, then open https://ai-lab.ddev.site/ai/learn.

Expected:
- Each arrow press changes the step number at the end of the URL (for example `/describing-a-car/2`).
- Space switches the button between Pause and Resume.
- With reduced motion, dots do not move.
- The live region reads like "Step 2 of 10: ..." after each move.
- `/ai/learn` reads "17 of 17 lessons done".

## Step 7

AC-7 (BR-27): the docs list 17 lessons.

1. Open https://ai-lab.ddev.site/ai/guides/lessons.
2. Open `README.md` and look for the lessons list.
3. Open `docs/learning-guide.md` and look for "Scene lessons".

Expected: the README and the learning guide say "seventeen lessons"; the guide page states no count (it is built from the course file). All three name all 17 titles in five modules, including the nine new ones (Describing a car, Prompts you can version, Guards on both sides, Search by meaning, Searching photos by text and likeness, Finding duplicate photos, The run log, Logs and a safe worker restart, Seeing your change in worker mode).

## Step 8

MNC-1: the eight existing lessons, the engine, step URLs, keyboard and progress storage are unchanged.

1. Run `git diff master --stat -- app resources/js` and `git diff master --stat -- resources/lessons/running-models/one-model-many-users.php resources/lessons/running-models/streaming-an-answer.php resources/lessons/running-models/choosing-a-model-and-provider.php resources/lessons/answers-you-can-trust/structured-output-and-validation.php resources/lessons/answers-you-can-trust/retrieval-with-citations.php resources/lessons/answers-you-can-trust/caching-answers.php resources/lessons/agents`.
2. Open https://ai-lab.ddev.site/ai/learn/running-models/streaming-an-answer/3 directly, then press ArrowRight and ArrowLeft.
3. In the console run `Object.keys(localStorage)`.

Expected: both diffs are empty. The step URL opens step 3 directly and arrows work as before. The only progress key is `ai-lab:learn:v1`.

## Step 9

MNC-2: no numeric literal in lesson text.

1. Run `grep -nE "[0-9]" resources/lessons/*/describing-a-car.php | head -50` and repeat for the other eight new lesson files.
2. Check where digits appear.

Expected: digits only in layout values (x, y, load, queue, minutes), `lo-N` outcome ids, file paths and placeholder arguments such as `{kpi:...}` and `{num:...}`. No visible sentence carries a bare figure.

## Step 10

MNC-3: no internal references in the UI, assets and parity unchanged.

1. Open two or three new lessons and one guide, and search the page for "SL-", "BR-", ".md", "bench/" and "resources/".
2. Run `bench/asset-fingerprint.sh`.
3. Run `bench/parity.sh capture` and then `git diff bench/parity`. Afterwards run `git checkout -- bench/parity`.

Expected:
- None of those strings appear in lesson or guide text.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The parity diff shows only the `# ... captured=` line.

## Step 11

MNC-4: no new dependencies, tables, routes or model calls.

1. Run `git diff master --stat -- composer.json composer.lock package.json package-lock.json database routes config app`.
2. Open a new lesson and watch the Network tab in DevTools while stepping through.

Expected: the diff is empty. Network shows only the page, assets and Inertia visits, no call to a model or provider.

## Step 12

MNC-5: SL-57 pages still work.

1. Open https://ai-lab.ddev.site/ai/guides and confirm the redirect to `/ai/learn`.
2. Open https://ai-lab.ddev.site/ai/guides/chat and look at the topic header and the course sidebar.
3. Look at the lab sidebar on any `/ai` page.

Expected: the redirect is 301. The guide shows its topic header with "Walk through", and the course sidebar and grouped lab sidebar render as before.

## Step 13

NFR-1: accuracy.

1. Open `.ai/specs/spec-20261120-2106-reference-guide-lessons/tasks.md` and read the "Accuracy review (NFR-1)" table.
2. Pick one row per lesson, open the code or result file in the "Checked against" column, and compare it with the lesson text.

Expected: every row ends in ok, fixed or dropped (none open) and every claim you checked matches the file. Rows for `the-run-log` (run counts) and `guards-on-both-sides` (citation check) are present.

## Step 14

NFR-2: smoothness.

1. On the busiest step of each new lesson (the one with the most dots moving), wait 2 seconds, then paste in the console:

   ```js
   (async () => {
     const t = [];
     let last = performance.now();
     const end = last + 5000;
     await new Promise(done => {
       const tick = now => { t.push(now - last); last = now; now < end ? requestAnimationFrame(tick) : done(); };
       requestAnimationFrame(tick);
     });
     t.sort((a, b) => a - b);
     console.log('median ms', t[Math.floor(t.length / 2)].toFixed(1), 'p95 ms', t[Math.floor(t.length * 0.95)].toFixed(1));
   })();
   ```

Expected: median is 20 ms or less on all nine lessons (about 16.7 ms on a 60 Hz screen).

## Step 15

NFR-3: payload size.

1. Run, for each of the nine lesson URLs:

   ```sh
   curl -sk https://ai-lab.ddev.site/ai/learn/running-models/describing-a-car | sed -n 's:.*<script data-page="app" type="application/json">\(.*\)</script>.*:\1:p' | wc -c
   ```

Expected: every number is below 60000 (the nine lessons measured between about 21,500 and 33,500).

Tick after the manual steps pass: task 7 in `.ai/specs/spec-20261120-2106-reference-guide-lessons/tasks.md`.
