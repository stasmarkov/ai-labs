# Manual test plan: learning guide final pass (SL-44)

This change only touches documentation and tests: `docs/learning-guide.md`, `README.md`, `BENCHMARKS.md` and three test files. Nothing in the running app changes, so most steps are done by reading the files in a markdown preview (GitHub or your editor) and running a few commands in the project root.

Preconditions:
- You are in the project root, on the branch with the change.
- DDEV is running (`ddev start`) and the app answers at https://ai-lab.ddev.site.
- `public/build/manifest.json` exists (run `ddev npm run build` if it does not).

Rollback: nothing to undo; this plan only reads. If you want to throw the change away, `git checkout -- docs README.md BENCHMARKS.md tests`.

## Step 1

Structure of the guide (AC-1, AC-6).

1. Open `docs/learning-guide.md` in a markdown preview.
2. Read the top: the intro, "How to read this guide", "Prerequisites" and the dated status line.
3. In "Contents", click these entries one by one: `I-1`, `I-7`, `I-11`, `II-4`, `II-5`, `II-20`, `II-24`, "Closing summary".
4. Scroll to the very end of the file.

Expected:
- The status line starts with `**Status (2026-11-15):**`.
- Contents is in learning order: "Part I - The server" (I-1 to I-11), then "Part II - AI" (II-1 to II-24), then the closing summary.
- Each click you made lands on the section with the same title.
- The guide ends with "Closing summary": five recurring ideas, each linking to its section, a list of headline numbers, and a "Read next" paragraph.

## Step 2

Numbers match their sources (AC-5, MNC-3).

1. In `docs/learning-guide.md`, open section II-5 ("Local models and their limits"). Note the figures: 0.2 s, 6.6 s, 31 s, and the pair 64 to 2724 ms.
2. Open `BENCHMARKS.md` and find the section "Local models and limits (2026-11-13)". Compare the same rows.
3. Open II-20 (research agent). Note: 15 runs, 11 done, 4 refused, 3.9 calls, 7.8 steps, 20 s. Compare with `bench/results/` for the research run (`stats.json`).
4. Open II-21 (prompt playground). Note: 9 runs, 3 prompts, 7 versions, 7 runs at 10.3 s. Compare with the playground `stats.json` in `bench/results/`.
5. Open the closing summary. Note 365 / 1461 RPS, 276 vs 4003 ms, 0.875 at 21/24, 0.677. Find each in the BENCHMARKS.md section the summary links to.
6. Check that the change did not edit numbers in BENCHMARKS.md:
   ```sh
   git diff --word-diff HEAD -- BENCHMARKS.md
   ```

Expected:
- Every figure from steps 1 to 5 is identical in its source.
- The word diff of BENCHMARKS.md shows only link and wording changes (for example "(section 9)" became a link to II-2), no changed numbers.

## Step 3

No code changed, fingerprint and parity unchanged (MNC-1, MNC-4).

```sh
git diff --stat HEAD -- app routes resources config database bench
git status --short -- app routes resources config database bench
bench/asset-fingerprint.sh
git diff --stat HEAD -- bench/parity
ddev artisan test --compact
```

Expected:
- The first two commands print nothing.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`, with the same hashes as in `.ai/specs/spec-20261115-1539-learning-guide-final/tasks.md` task 10 (they were `176bae5b...f1d444` and `7fbc3860...735256`).
- The parity diff is empty. To re-capture and compare: `bench/parity.sh capture`, then `git diff bench/parity`; only the `captured=` line may differ.
- The test run ends green (about 2915 passed, 3 skipped, 0 failed).

## Step 4

Removed servers are described in the past tense (AC-4). This is the case that was reported as still wrong.

1. Open `docs/learning-guide.md`, section I-7, the paragraph "One offload per server" (around line 375).
2. Read the nginx-fpm bullet.
3. Search the file for the words `nginx-fpm`, `apache`, `OpenLiteSpeed` and `FPM`:
   ```sh
   grep -n -i "nginx-fpm\|apache\|openlitespeed\|php-fpm" docs/learning-guide.md
   ```
4. For every hit outside a table or block between `<!-- history -->` and `<!-- /history -->`, read the sentence. Look especially at line 123 ("must never stay active on the FPM servers") and line 306 ("measures that ... on PHP-FPM").
5. Open section I-11 and check the places for SL-15, SL-16 and SL-17. Search for the text "Planned sections".

Expected:
- The nginx-fpm bullet in I-7 is past tense or sits inside a history span, the same way the apache-fpm and openlitespeed bullets do. Today the line starts "nginx-fpm: PHP-FPM returns the empty response ..." in the present tense and the `<!-- history -->` marker at its end covers only the next bullet. If you see that, this step fails and the guide needs a fix (and the check in `DocsLinksTest` should stop exempting a line just because it contains an opening marker).
- No sentence says nginx-fpm, Apache or OpenLiteSpeed is the current server; only FrankenPHP (three modes) is current.
- Lines 123 and 306 read as history or are rephrased.
- I-11 lists SL-15, SL-16 and SL-17 as reserved places. "Planned sections" does not appear.

## Step 5

Full sections for the gaps (AC-2).

1. In the guide, open these sections: II-4 (providers and settings), II-5 (local limits), II-8 (vision), II-15 (similar photos, text-to-photo), II-16 (hybrid and duplicates), II-18 (hand-written tool loop), II-19 (MCP), II-20 (research agent), II-21 (prompt playground), II-23 (in-app guides and homescreen), and the history paragraph in I-2 (FrankenPHP-only).
2. In each one, look for: what it is, a step-by-step walk through one request, links to code and to the spec folder, numbers (or the words "Not measured"), and a "Read next" line.
3. Click one spec link per section, for example the SL-28 link in II-5.

Expected:
- All eleven have every part from step 2.
- Spec links open a folder under `.ai/specs/` that exists.

## Step 6

Every shipped AI slice is named (AC-7).

1. Open `backlog.md` and list the shipped AI slices from SL-20 on.
2. For each, search the guide, for example:
   ```sh
   for n in 20 22 23 24 25 26 27 28 32 33 34 35 36 37 38 39 40 41 42 43 45 46 48 51 52; do
     printf "SL-%s: " "$n"; grep -c "SL-$n\b" docs/learning-guide.md
   done
   ```

Expected: every line prints a count of 1 or more. A 0 means a shipped slice is missing from the guide.

## Step 7

Links work in the real UI too (AC-3, a quick human check beyond the test).

1. Open https://ai-lab.ddev.site/ai and then https://ai-lab.ddev.site/ai/docs.
2. In README.md preview, click "learning-guide" and the "II-5 of the learning guide" link.

Expected:
- Both pages load with status 200 and no broken layout.
- The README links open the guide at the right heading.
