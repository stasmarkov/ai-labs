# Manual test plan: five interactive guide formats (SL-54)

Follow the steps in order. Steps 2 and 3 are the manual task 13 in `tasks.md`; step 5 is task 8.

## Preconditions

- DDEV is running and the app answers at https://ai-lab.ddev.site: `ddev start`, then `ddev server frankenphp`.
- The assets are built: `ddev npm run build`.
- No login and no seeded data are needed. The pages are static and need no model server.
- Use a desktop browser (Chrome or Firefox) with DevTools available. Start from a clean state: in DevTools, Application, Local storage, delete any `ai-lab.guide-format.*` keys.
- Rollback: nothing is written to the database, so there is nothing to undo. To clear browser progress, run `Object.keys(localStorage).filter(k => k.startsWith('ai-lab.guide-format.')).forEach(k => localStorage.removeItem(k))` in the console.

## Step 1

The compare page, the entry card and the 404 cases (AC-1, AC-3, AC-11).

1. Open https://ai-lab.ddev.site/ai/guides. Expect 200 and a card named "Guide format trial". Every guide card that was there before is still there in the same order; the trial card is the only addition.
2. Click "Guide format trial". Expect the address to become `https://ai-lab.ddev.site/ai/guides/photos/formats`.
3. On that page check:
   - The five learning outcomes LO-1 to LO-5 are listed once, at the top.
   - The rubric shows four criteria: Clarity, Time to first working change, Retention, Effort to author.
   - Six entries: "Reference tabs" (the baseline) and the five formats. Each has a name, the teaching idea, an expected time (for example "10–15 min"), a link, and a per-outcome coverage for LO-1 to LO-5.
4. Click each of the six links. Expect 200 each: `/ai/guides/photos`, and `/ai/guides/photos/formats/build-along`, `/trace`, `/quiz`, `/debug`, `/tour`.
5. 404 checks, in a terminal (each must print `404`):

```sh
for p in photos/formats/nope describe/formats describe/formats/quiz nope/formats photos/formats/_compare; do
  curl -sk -o /dev/null -w "$p %{http_code}\n" https://ai-lab.ddev.site/ai/guides/$p
done
```

   Expected: five lines, each ending in `404`.

## Step 2

Each format works in the browser (AC-6 to AC-10). Open each page from the compare page.

### 2a Build-along (AC-6)

Open https://ai-lab.ddev.site/ai/guides/photos/formats/build-along. There are six steps, starting with "Start from one command".

1. Tick "Step 1 done: Start from one command" and "Step 3 done: Ask for a schema, not prose". Expect the status to read "2 of 6 steps done".
2. Reload the page (Cmd-R). Expect steps 1 and 3 still ticked and the status still "2 of 6 steps done". In DevTools, `localStorage['ai-lab.guide-format.photos.build-along']` holds `["command","schema"]`.
3. Click "Reset progress". Expect all boxes unticked and the status "0 of 6". Reload: still all unticked.
4. Spot check that the commands are real: in a terminal, run one command shown on the page, for example `ddev artisan test --compact --filter="rejects output that breaks a rule in PHP"`. Expect passing tests, not "No tests found".

### 2b Follow one photo (AC-8)

Open https://ai-lab.ddev.site/ai/guides/photos/formats/trace. There are eight stages: The command, The vision queue, The job, The agent, The model server, The schema, The PHP rules, The stored row.

1. Click "Play". Expect the button to read "Pause" and the highlighted stage to move on about every 2.5 seconds, in the order above, with the panel below showing "Stage n of 8" for the current stage.
2. Click "Pause" while on stage 3 or later. Wait 5 seconds. Expect the highlight to stay put and the button to read "Play" again.
3. Click "The PHP rules" (stage 7). Expect its panel with a sample labelled as an example, and code.
4. With focus on a stage, press the Left arrow. Expect stage 6 to be selected and focused, and its panel shown.

### 2c Predict and check (AC-7)

Open https://ai-lab.ddev.site/ai/guides/photos/formats/quiz. There are nine questions, shown one at a time, starting with "The command queues one job per photo. Where do those jobs run?".

1. Before answering, "Next" is disabled.
2. Pick an option with the mouse or the arrow keys (picking alone commits nothing) and click "Check answer". Expect "Correct" or "Not quite", then an explanation and a code block. The options are now disabled, marked "(your answer)" and "(correct answer)". Click the other options: nothing changes.
3. Click "Next" and answer all nine, choosing the first option each time.
4. After the ninth answer expect "Score: n / 9", where n equals the number of questions where you saw "Correct". "Next" is not shown on the last question.

### 2d Break and fix (AC-9)

Open https://ai-lab.ddev.site/ai/guides/photos/formats/debug. The first scenario is "An answer keeps failing" with the symptom `status: failed`, `attempts: 3`, `errors.dominant_colours.0: The selected dominant_colours.0 is invalid.`

1. Expect the symptom first. The cause options are disabled with the text "Show a clue first to unlock the causes."
2. Click "Show clue 1 of 3 for scenario 1". Expect only clue 1. Click "Show clue 2 of 3 for scenario 1": clue 2 appears below it.
3. Choose a wrong cause and click "Check cause for scenario 1". Expect "Not this one" and that you can choose again.
4. Choose the right cause and click "Check cause for scenario 1". Expect "Correct", the right option marked "(the cause)", then the cause, the fix, and "Test that prevents it" followed by a real test title, and code. "Try another cause" is no longer shown.
5. On scenario 2 ("Photos fail at once"), show one clue, then click "Show answer for scenario 2" without choosing. Expect the right option checked and marked "(the cause)", the cause text, the fix and the test to appear, and no "Try another cause".

### 2e Code tour (AC-10)

Open https://ai-lab.ddev.site/ai/guides/photos/formats/tour. There are six stops, starting with "The command queues the work".

1. Expect stop 1 to show source lines of the extract command with a note, and "Previous stop" disabled. The code is labelled by class and method, and no file path is shown on the page body.
2. Click "Next stop". Expect stop 2, "The job on its own queue", with different lines and its note. The map marks stop 2 as current.
3. Click "Previous stop". Expect stop 1 again.
4. Open the real file `app/Console/Commands/ExtractPhotosCommand.php` and compare the first lines on screen with it. They must match the file.

## Step 3

Keyboard-only walk and announcements (NFR-1). Unplug the mouse or do not touch it. Use only Tab, Shift-Tab, Enter, Space and the arrow keys. Repeat 2a to 2e.

1. Build-along: Tab to a step checkbox, press Space. Expect it ticked. Tab to "Reset progress", press Enter. Expect all cleared.
2. Follow one photo: Tab to "Play", press Enter, then Enter again for "Pause". Tab to a stage and use the Left and Right arrows to move between stages; the focus ring is visible and follows.
3. Predict and check: Tab to an option, press Space, Tab to "Check answer", press Enter, Tab to "Next", press Enter.
4. Break and fix: Tab to "Show clue 1 of 3 for scenario 1", press Enter. Tab to a cause radio, press Space, Tab to "Check cause for scenario 1", press Enter.
5. Code tour: Tab to "Next stop", press Enter. Tab to "Previous stop", press Enter.
6. Throughout, a visible focus ring shows where you are and every control has a readable name.
7. In DevTools, Elements: find the element with `aria-live="polite"` on the quiz and debug pages. Expect its text to change to the feedback (for example "Score: n / 9." on the quiz's last question). Optional: confirm the same with VoiceOver (Cmd-F5).

## Step 4

Rules and gates (MNC-3, MNC-4, MNC-5, NFR-2). Run from the project folder on the host.

```sh
node bench/ui-audit.mjs --only guides,guides/photos/formats,guides/photos/formats/build-along,guides/photos/formats/trace,guides/photos/formats/quiz,guides/photos/formats/debug,guides/photos/formats/tour
bench/asset-fingerprint.sh
for mode in frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $mode && PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
done
git checkout -- bench/parity && ddev server frankenphp
ddev artisan test --compact
```

Expected:
- The audit ends with `14 page(s) checked, 0 failing` (7 pages at 1440 and 1024 px). To see it by eye, resize the browser to 1024 px wide on the compare page and the five format pages: nothing overlaps or scrolls sideways.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- Each parity capture differs only in the `captured=` line.
- The suite ends green with no model server running (about 3450 passed, 3 skipped).
- On every new page, search the visible text for "SL-", ".md" and "app/" in the body copy: none appear. The code caption under a code block may show a snippet path, as on every existing guide.

## Step 5

The pick (task 8). Try all six entries for the same goal, "add a `mileage_visible` field to the photo extraction", and score each against the rubric on the compare page. Record the winner and the reasons in `decisions.md`.

## Tick after the manual steps pass

Tick task 13 in `tasks.md` (the line starting `- [ ] 13.`). Task 8 is ticked when step 5 is recorded in `decisions.md`.
