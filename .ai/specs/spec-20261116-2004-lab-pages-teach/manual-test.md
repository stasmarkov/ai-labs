# Manual test plan: lab pages that teach (SL-53)

## Preconditions

- DDEV is running (`ddev start`) and the site answers at https://ai-lab.ddev.site. The local dev database is seeded (it has listings 1, 2 and 3).
- The lab bundle is built: `ddev npm run build`.
- You use Chrome. Open DevTools (Cmd+Option+I) on the Console tab to watch for errors.
- For step 1 you need Node and Playwright 1.55.x in the npx cache (the script loads no other version). If none is there, run `npx playwright@1.55 --version` once, or point `PW` at a playwright package.
- Some lab pages answer 404 when their feature is off (Admin assistant, Changes, Playground). That is not a failure. The audit skips them with a note.
- Nothing in this plan changes data, so there is no rollback. Do not submit any form on the pages below.

## Step 1

The reported problem: the listing select on Moderation and Describe was squeezed to a sliver. Layout (AC-1, AC-2, AC-4).

1. Run the audit from the repo root:

```sh
node bench/ui-audit.mjs; echo EXIT=$?
```

Expected:
- The last lines read `44 page(s) checked, 0 failing` and `EXIT=0`.
- There is no line starting with `FAIL` and no finding of the kinds overlap, narrow, scroll, no-teach, no-guide, no-hint or chips.
- `id` lines name the change, research run, playground run and run used for the detail pages. A `SKIP` note instead is fine when the dev database has no such row.

2. Open https://ai-lab.ddev.site/ai/moderation in a window about 1440 px wide.

Expected:
- The "Choose one of the first listings" select is wide (at least 160 px, in practice a full-width row), with no label or button on top of it.
- The example buttons sit on their own row below the select.
- The Console shows no errors.

3. Narrow the window to about 1024 px (DevTools device toolbar, width 1024) and reload.

Expected: same as above, and there is no horizontal scrollbar on the page.

4. Repeat steps 2 and 3 on https://ai-lab.ddev.site/ai/describe.

Expected:
- The listing select is stacked above the free listing id input, then the provider select, then the example buttons. Nothing overlaps.
- Click the second example button. The select shows listing 2, the free id input stays empty and Start is enabled. (Do not click Start.)

5. Open https://ai-lab.ddev.site/ai/research and click the first of the three buttons in the "Example topics" group.

Expected: the topic field is filled with the example text, for instance `How does hybrid search combine vector and text ranking?`.

## Step 2

Teaching content and accessibility (AC-3, AC-4, AC-5, NFR-1). This is task 9.

1. Open https://ai-lab.ddev.site/ai/playground, https://ai-lab.ddev.site/ai/research and https://ai-lab.ddev.site/ai/moderation one after another. Under the form, find the block with four collapsible parts. Open each part: How it works, Build it yourself, Watch out for, Same pattern elsewhere.

Expected:
- All four parts exist on each page, and each has text. How it works has 3 to 6 numbered steps.
- Each field has a placeholder, an example button or a hint line.
- The text contains no ticket ids like `SL-53`, no `.md` file names and no repo paths like `app/Services/...`.

2. On Moderation, open Watch out for and click "Open the warnings in the guide" (or the "Guide: what goes wrong" link on a row).

Expected:
- The URL becomes `https://ai-lab.ddev.site/ai/guides/moderation#watch`.
- The "Watch out for" tab is selected and its panel shows content.
- The same guide also has the tabs "Build it yourself" (`#build`) and "Same pattern elsewhere" (`#elsewhere`). Their text matches what the page showed.

3. Keyboard focus. Reload https://ai-lab.ddev.site/ai/playground, click an empty part of the page, then press Tab repeatedly.

Expected:
- Focus reaches each of the four part headings (a visible focus ring on each).
- Space or Enter on a focused heading opens or closes that part.
- Focus reaches each example button, with a visible ring. Enter on a button fills the text field.
- Focus order follows the page top to bottom, and no control is skipped.

4. Chip names. With a screen reader off, check in DevTools: Elements, select an example button, Accessibility pane, "Name".

Expected:
- Playground, Research and Moderation chips are named by their text (for example "Summarise a listing"). The group is named "Example topics" or similar.
- On Describe the chips are named "1", "2", "3". This is known and weak, so record it, and do not treat it as a failure unless the names are empty.

5. Contrast. In DevTools, inspect the small grey text under a part heading and the chip text on Playground. Look at the Contrast ratio in the color picker.

Expected: body text 4.5:1 or better, and the colours are the same as on other lab pages (the existing ink and muted colours; nothing new).

6. Record the result of steps 3 to 5 in `tasks.md`, under task 9.

## Step 3

Nothing else changed (MNC-1, MNC-2, MNC-4).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
ddev artisan test --compact
git checkout bench/parity
```

Expected:
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The parity diff has no changed line except `captured=`.
- The suite passes (about 3180 tests, a few skipped).

Then spot-check by hand:
- Open https://ai-lab.ddev.site/ and the public car pages. They look as before.
- Open https://ai-lab.ddev.site/ai/guides/moderation. The original tabs (How it works, Implementation, Modify, Support, Numbers, Experiments) are still there, and the three new ones follow them.

Rollback note: `git checkout bench/parity` restores the parity file after the capture. Nothing else is written.

## Tick after the manual steps pass

Tick task 9 in `.ai/specs/spec-20261116-2004-lab-pages-teach/tasks.md` (the line starting `- [ ] 9.`).
