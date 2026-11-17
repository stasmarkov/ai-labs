# Manual test plan: five interactive guide formats (SL-54)

## Step 1

The compare page and the entry card (AC-1, AC-11).

Open https://ai-lab.ddev.site/ai/guides and follow the "Guide format trial" card.

Expected:
- `/ai/guides` still lists every guide in the same order; the trial card is the only addition.
- The compare page shows six entries: the current guide (baseline), Build-along, Follow one photo, Predict and check, Break and fix and Code tour. Each entry has its teaching idea, expected time and a link.
- The five learning outcomes LO-1 to LO-5 are listed once at the top, the rubric's four criteria are shown, and each entry shows where it covers each outcome.

## Step 2

Each format works (AC-6 to AC-10). Open each format from the compare page.

Expected:
- **Build-along:** tick two steps, reload: both are still ticked. "Reset progress" clears them.
- **Follow one photo:** Play highlights the stages in order (command, queue, job, agent, model server, schema, PHP rules, stored row) and shows each panel; Pause stops it; clicking any stage opens its panel.
- **Predict and check:** answering a question shows right or wrong, the explanation and the code, and the answer cannot be changed; after the last question the score shows as `n / total`.
- **Break and fix:** a scenario shows the symptom first, clues appear one at a time, a cause can be chosen, then the cause, the fix and the preventing test appear.
- **Code tour:** each stop shows real source lines labelled by class and method (no file path) with its note; previous and next move one stop; the map shows the current stop.

## Step 3

Keyboard and screen reader basics (NFR-1). Repeat step 2 using only Tab, Shift-Tab, Enter and Space.

Expected:
- Every control can be reached and used, and the focus is visible.
- Quiz and debug feedback is announced (a screen reader, or the `aria-live="polite"` region in the inspector, shows the text).

## Step 4

Rules and gates (MNC-3, MNC-4, MNC-5, NFR-2).

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
- The audit ends with `14 page(s) checked, 0 failing` (7 pages at 2 widths).
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- Each parity capture differs only in the `captured=` line.
- The suite is green with no model server running.

## Step 5

The pick (task 8). Try all six entries for the same goal, "add a `mileage_visible` field to the photo extraction", and score each against the rubric on the compare page. Record the winner and the reasons in `decisions.md`.
