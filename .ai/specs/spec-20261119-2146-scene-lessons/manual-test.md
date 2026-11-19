# Manual test plan: AI lab scene lessons (SL-55)

Base URL: https://ai-lab.ddev.site
Pilot lesson: https://ai-lab.ddev.site/ai/learn/running-models/one-model-many-users

## Before you start

- Run `ddev start` and check that https://ai-lab.ddev.site/ai opens.
- Build the front end if the lesson pages fail with a manifest error: `ddev npm run build`.
- Use Chrome. Open DevTools (Console tab) and keep it open the whole time.
- Start from clean progress: in the Console run `localStorage.removeItem('ai-lab:learn:v1')`, then reload.
- No login or seeding is needed. The lesson reads committed files under `bench/results`.
- Rollback: nothing here changes the database or any file. The only state is the `ai-lab:learn:v1` key in your browser. Clear it with the line above.

The problem that was reported: the lab had no scene lessons, so SL-54 could not be closed. Steps 1 to 4 show that the lessons now work end to end.

## Step 1

Course and pilot (AC-1, AC-2, AC-3, AC-6).

1. Open `/ai/learn`.
2. Click "One model, many users".
3. Walk all 10 steps with Next, then walk back with the left arrow, then forward with the right arrow.
4. On step 4 press R, then press Space twice (click on an empty part of the page first, so no button has focus).
5. Click the step 8 button, then use the browser Back button.
6. Go to step 6 and reload the page.
7. Visit step 10, return to `/ai/learn`, then press "reset progress".

Expected:
- `/ai/learn` shows the heading "Learn", 3 modules and 8 lessons. Only "One model, many users" is a link. The other 7 say "coming soon".
- The lesson opens on `/ai/learn/running-models/one-model-many-users` showing step 1 "One user". The KPI strip reads 1 user, 0.3 s, 51.6 tok/s, 64 ms.
- Each step changes the URL (`/2`, `/3`, `/4`, back to `/3`), the scene (queue badges, node loads, packet rates), the KPI strip and the panel at once. Step 3 shows 4 users and 6.6 s. Step 4 shows 16 users and 31.2 s with a long queue at the model. Step 8 shows "no answer" and an "unreachable" badge.
- Step 5 ("The rest of the site") shows search p95 jumping on worker mode.
- The panel has a role, a title, a body, a "Key idea" callout and "What changed".
- R replays the step animation (the URL stays). The first Space freezes the packets and the button reads "Resume". The second Space resumes them.
- The step 8 button goes to `/8`. Browser Back returns to the step you were on before, and the Network tab shows no request for either.
- Step 5 shows its first-token time in plain ink (not red or green): it goes back from sixteen users to four. Steps 9 and 10 colour no number.
- After the reload on step 6 the learn list keeps its ticks.
- Visiting step 10 shows "Running models 1/1 done" in the sidebar.
- After "reset progress" nothing is ticked and the sidebar shows 0/1.
- Console: no errors.

## Step 2

Accessibility (AC-7, NFR-3).

1. On step 1 press Tab repeatedly through the whole page. Check every control for a visible outline.
2. In DevTools open Rendering, set "Emulate CSS media feature prefers-reduced-motion" to "reduce", and reload.
3. Press the arrow keys on a few steps.
4. Turn on VoiceOver (Cmd+F5) or, in the Console, run `document.querySelector('[aria-live]').textContent` after each step change.

Expected:
- Tab order is sensible (skip link, nav, sidebar, step buttons 1 to 10, Next, Replay, Pause, guide link). Every stop has a visible outline.
- With reduced motion the packets do not move and dashed static markers are drawn. The arrow keys still change steps.
- The live text reads "Step 1 of 10: One user. ..." and then "Step 2 of 10: ..." after you move.
- Body text is readable (contrast at least 4.5:1). Check the orange "Learn" link and the white text on the node fills with a contrast checker if in doubt.

## Step 3

Smoothness (NFR-1). Open step 7 (`/ai/learn/running-models/one-model-many-users/7`, the busiest, about 20 packets per second) and wait 2 s. Paste this in the Console:

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

Expected: median at most 20 ms (about 60 fps is normal, for example `median ms 16.7 p95 ms 16.8`).

## Step 4

Closure and checks (AC-9, MNC-3). Run in the repo root:

```sh
grep -n "scene" .ai/specs/spec-20261117-2243-guide-format-variants/decisions.md
grep -n "SL-54" backlog.md
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
git checkout -- bench/parity
node bench/ui-audit.mjs --only learn
```

Expected:
- The first command shows the "Owner pick (task 8)" entry with "Chosen format: scene lessons". The second shows SL-54 as "shipped (pick: scene lessons, follow-up SL-55)". Task 8 in the SL-54 tasks.md is `- [x] 8.`.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The parity diff shows only the `captured=` line changing (the checkout puts the snapshot back).
- The audit exits 0 and reports 0 failing pages. It needs Playwright 1.55 in the npx cache (`npx playwright@1.55 --version`).

## Step 5

Unknown lessons and steps (AC-8).

```sh
for p in running-models/one-model-many-users/1 running-models/one-model-many-users/10 \
  running-models/one-model-many-users/11 running-models/one-model-many-users/0 \
  running-models/nope running-models/streaming-an-answer nomodule/one-model-many-users; do
  printf '%s ' "$p"; curl -sk -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site/ai/learn/$p"
done
```

Expected: steps 1 and 10 print 200. Everything else prints 404 (step 11, step 0, unknown lesson, the announced but unbuilt lesson, unknown module).

## Step 6

Numbers come from committed files (AC-4, BR-26).

1. Run `ddev artisan test --compact tests/Feature/LessonContentTest.php tests/Feature/LessonRepositoryTest.php`.
2. Check that the pilot only uses tracked files: `grep -o "bench/results/[A-Za-z0-9_./-]*json" resources/lessons/running-models/one-model-many-users.php | sort -u | xargs git ls-files --error-unmatch`.
3. Break it on purpose: in `resources/lessons/running-models/one-model-many-users.php` change one `bench/results/...json` file name to `bench/results/missing.json`, then open `/ai/learn/running-models/one-model-many-users`.
4. Undo your edit by hand (the file may hold uncommitted work, so do not use `git checkout` on it).

Expected:
- Tests pass. The `git ls-files` command prints each file name and exits 0.
- With the missing file the lesson page answers 500 and `/ai/learn` still answers 200 (the lesson is listed as unavailable). The log names the file.
- After the undo the page answers 200 again.

## Step 7

Broken lesson file is rejected with a clear error (AC-5).

1. Run `ddev artisan test --compact tests/Feature/LessonRepositoryTest.php`.
2. Optional by hand: in the pilot file change one node `kind` to `banana` and open the lesson page. Undo the edit afterwards.

Expected:
- All tests pass (they cover node kinds, edges, roles, outcomes, step count 8 to 13, learn ids, and raw/ paths).
- By hand: the page answers 500 and the log message names the file and the rule that failed (for example an unknown node kind). Undo it and the page answers 200.

## Step 8

Lessons guide, docs and linked guides (AC-10, BR-27).

1. Open `/ai/guides/lessons`.
2. Click each tab.
3. Open `/ai/guides/local-models` and `/ai/guides/metrics`.
4. Open `README.md` near the line about `/ai/learn` and the "Scene lessons" paragraph in `docs/learning-guide.md`.

Expected:
- "Scene lessons" opens with the tabs How it works, Implementation, Modify, Support, Numbers, Experiments, Build it yourself, Watch out for, Elsewhere. No console errors.
- The local-models and metrics guides each show a link "Learn it step by step" to `/ai/learn/running-models/one-model-many-users`, and the link opens the lesson.
- The README and the learning guide describe how to author a lesson.
- The pilot's guide links open: `/ai/guides/local-models`, `/ai/guides/streaming`, `/ai/guides/metrics` (all 200).

## Step 9

Existing pages unchanged (MNC-1).

1. Open `/ai`, `/ai/guides`, `/ai/guides/photos`, `/ai/guides/formats`.
2. Open the five format pages from `/ai/guides/formats` (build-along, trace, quiz, debug, tour).
3. Run `ddev artisan test --compact`.
4. Run `git diff --stat HEAD -- app/Guides tests/Feature | cat` and check the existing tests only gained lines.

Expected:
- All pages load with no console errors and look as before.
- The full suite passes.
- Existing test changes are additions only (new data set rows, new tests, one extra constructor argument).

## Step 10

No references in the lesson UI (MNC-2).

1. Open the pilot, step 1 and step 7. Select all text (Cmd+A) and read it.
2. In the Console run `/SL-\d+|\.md\b|bench\/|resources\/|app\//.test(document.body.innerText)`.

Expected: `false`. No slice ids, `.md` names or repo paths show on `/ai/learn`, the pilot or the course sidebar.

## Step 11

No new dependencies, tables or calls (MNC-4).

1. Run `git status --short package.json package-lock.json composer.json composer.lock database`.
2. In DevTools open the Network tab, reload a lesson step and click Next twice.

Expected:
- The git command prints nothing.
- The only requests are the page and its assets. Next changes the URL with no request: the lesson came with the page (D-9). No POST, no model or other outside call.

## Step 12

Payload size (NFR-2).

1. Open the pilot, view the page source (Cmd+Opt+U) and find the `data-page` attribute, or in the Console run `document.getElementById('app').dataset.page.length`.
2. Run `ddev artisan test --compact tests/Feature/LessonPagesTest.php`.

Expected: the length is about 24,000 characters, well below 60,000. The tests pass.
