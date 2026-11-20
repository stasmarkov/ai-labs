# Manual test plan: lessons for the reference-only guides (SL-58)

Base URL: https://ai-lab.ddev.site

## Preconditions

- `ddev start`; `ddev npm run build`.
- No login or seeding. Progress is browser-only: run `localStorage.removeItem('ai-lab:learn:v1')` in the console and reload to start clean.
- Rollback: revert the feature commit; nothing is written to the database.

New lessons:

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

Course and layout (AC-1, AC-4, AC-5). The reported problem: 11 guides said "lesson coming".

1. Open https://ai-lab.ddev.site/ai/learn.
2. Open each new lesson at 1440 px and at 1024 px.
3. Open https://ai-lab.ddev.site/ai/guides/semantic and https://ai-lab.ddev.site/ai/guides/worker-dx.
4. Run `node bench/ui-audit.mjs; echo "exit=$?"`.

Expected:
- `/ai/learn` shows five modules, 17 lessons, "0 of 17 lessons done", no "reference" tag and no "lesson coming".
- Each lesson answers 200 with its scene; boxes do not overlap and no label is cut off at either width.
- Both guides show "Walk through" as a link to their lesson.
- The audit reports `lessons 17 from /ai/learn`, 0 failing, `exit=0`.

## Step 2

Interaction and smoothness (AC-6, NFR-2).

1. In each new lesson press ArrowRight to the last step, then ArrowLeft once; press R and Space in one lesson.
2. With DevTools Rendering "prefers-reduced-motion: reduce", walk one lesson again.
3. On the busiest step of each new lesson (most packets moving at once), wait 2 s and paste in the console:

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
4. Walk all 17 lessons to the last step and return to `/ai/learn`.

Expected: each arrow changes the URL step; Space toggles Pause/Resume; with reduced motion no dot moves; median frame ≤ 20 ms; `/ai/learn` reads "17 of 17 lessons done".

## Step 3

Accuracy (NFR-1, AC-2).

1. Read the review table in `.ai/specs/spec-20261120-2106-reference-guide-lessons/tasks.md`.
2. In each new lesson pick one KPI number and find it in its file under `bench/results/`.
3. Open a step that says "not measured" (for example in the run log or guards lesson).

Expected: every checked number matches its file; unmeasured steps say "not measured" in the KPI and the text.

## Step 4

Docs (AC-7).

1. Open https://ai-lab.ddev.site/ai/guides/lessons, README "Lessons" and `docs/learning-guide.md` "Scene lessons".

Expected: all three list 17 lessons in five modules.

## Step 5

No references, assets or dependencies changed (MNC-3, MNC-4).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity; git checkout -- bench/parity
git diff --stat master -- composer.json composer.lock package.json package-lock.json database routes
```

Expected: fingerprint `app-DNob7CFE.css` and `app-PTzu1QaN.js`; parity diff shows only `captured=`; the last git diff prints nothing.
