# Manual test plan: duplicate photos and the best-photo pick (SL-39)

Run these in order. Each step says what to type and what you should see.

## Before you start

- Base URL: https://ai-lab.ddev.site (DDEV is running: `ddev start`).
- The image encoder is up, Ollama is up with `gemma4:12b`, and the imported photos are embedded (`ddev artisan photos:embed --wait` reports nothing left to do).
- A queue worker for vision jobs is running in a second terminal: `ddev artisan queue:work --queue=ai-vision`.
- There is no login. The lab pages are open.
- Take a baseline and keep the numbers:

```sh
ddev exec 'ls -R storage/app/public/photos | wc -l'
ddev artisan tinker --execute 'echo DB::table("listing_photos")->count()." total / ".DB::table("listing_photos")->where("source","augmented")->count()." augmented";'
```

- Rollback: everything this plan creates is a generated copy. `ddev artisan photos:make-duplicates --fresh --photos=1` removes all augmented rows and files first. Reviews are cleared with the Undo button. Imported photos are never touched.

## Step 1: make the degraded copies (AC-1)

```sh
ddev artisan photos:make-duplicates --photos=20 --fresh
ddev artisan photos:make-duplicates --photos=20
ddev artisan tinker --execute '$r=DB::table("listing_photos")->where("source","augmented"); echo $r->count()." rows, ".(clone $r)->whereNull("duplicate_of")->count()." without duplicate_of, ".(clone $r)->whereNull("variant")->count()." without variant\n"; print_r(DB::table("listing_photos")->where("source","augmented")->selectRaw("variant, count(*) c")->groupBy("variant")->pluck("c","variant")->all());'
```

Expected:
- First run: prints `Originals used: 20` and `Variants stored:` a number between 40 and 80 (2 to 4 per original; 56 on the dev data). It ends with the line `Next: ddev artisan photos:embed --wait`.
- Second run (same seed 7): `Variants stored: 0` and `Already stored:` equal to the first number. Same seed gives the same copies.
- The tinker line shows `0 without duplicate_of, 0 without variant`, and a count per variant kind (for example mirrored, recompressed).
- Check the same listing and a new hash: pick one row, `ddev artisan tinker --execute '$v=App\Models\ListingPhoto::where("source","augmented")->first(); $o=App\Models\ListingPhoto::find($v->duplicate_of); var_dump($v->listing_id===$o->listing_id, $v->sha256!==$o->sha256);'` prints `bool(true)` twice.
- Scope of `--fresh`: before it, note one copy's file: `ddev artisan tinker --execute 'echo App\Models\ListingPhoto::where("source","augmented")->value("path");'` (call it PATH) and the imported-photo count: `ddev artisan tinker --execute 'echo DB::table("listing_photos")->where("source","!=","augmented")->count();'`. Run `ddev artisan photos:make-duplicates --photos=20 --fresh --seed=8`. It prints `Removed first:` with the old variant count. The imported count is unchanged. With a different seed the copies are new, so `ddev exec ls storage/app/public/PATH` should say "No such file" (old files are removed, not just their rows). Then run `--seed=7 --fresh` to return to the first set.
- Bad input: `ddev artisan photos:make-duplicates --photos=0` prints `--photos must be a positive whole number and --seed a whole number.` and exits non-zero.

Then embed the new copies: `ddev artisan photos:embed --wait`.

## Step 2: copies never leak into earlier features (AC-2, MNC-3)

1. Open https://ai-lab.ddev.site/ai/photos and go to the last page. Expected: the generated copies show a tag `augmented · mirrored` (or the other kind). Page 1, with imported photos, has no such tag, and the extraction counts at the top match what they were before you generated copies.
2. Open the similar view of any imported photo and run a photo search (for example https://ai-lab.ddev.site/ai/photos/similar and https://ai-lab.ddev.site/ai/photos/search). Expected: no result carries an `augmented` tag, and none of the file names end in a variant kind; the results are the same as before step 1 (to compare, run the same query after `photos:make-duplicates --fresh --photos=1` removes the copies, then regenerate).
3. Same check in the console: `ddev artisan tinker --execute 'print_r(DB::select("select count(*) c from listing_photos where source = ? ", ["augmented"]));'` is greater than 0 while the pages above show none.

## Step 3: group the copies (AC-3, NFR-3)

```sh
ddev artisan photos:duplicates
```

Expected:
- Lines `Label:`, `Cutoff: 0.92`, `Photos read:` (around 212 on the dev data), `Groups: 23 (...)`, `Older groups replaced:`, and `Clustered in N ms`.
- N is far below 5000 (185 on the dev data). That is the NFR-3 check for 300 photos.
- No group has more than 6 photos: `ddev artisan tinker --execute 'echo DB::table("photo_duplicate_members")->selectRaw("group_id, count(*) c")->groupBy("group_id")->orderByDesc("c")->value("c");'` prints 6 or less.
- Running it without embedded photos prints `No photo is embedded with <label>. Run photos:embed first.` (only if you wiped vectors; skip otherwise).

## Step 4: judge one call per group (AC-4, NFR-2)

```sh
ddev artisan photos:duplicates --judge --wait
```

Expected:
- `Queued 23 groups on ai-vision for gemma4:12b (v1).` (the count is your number of groups), then after a while `Judged: 23 completed, 0 failed.`
- If nothing moves for 60 seconds you see `No progress; start a worker: ddev artisan queue:work --queue=ai-vision`. Start the worker from Before you start.
- `photos:duplicates --wait` without `--judge` prints `--wait needs --judge.`
- Open https://ai-lab.ddev.site/ai/photos/duplicates, click the link `Logged run of the last attempt` on any card. Expected: https://ai-lab.ddev.site/ai/runs/<id> opens with status 200 and shows the vision call with the group's images. Each attempt is its own run, up to 3 per group.
- The group's pick is one of the photos on that card, never a photo from another group.
- Images sent per group is at most 6 (the cap in step 3).

## Step 5: review page and review actions (AC-5, AC-6, MNC-5, NFR-1)

Count files and rows first (commands from Before you start).

1. Open https://ai-lab.ddev.site/ai/photos/duplicates. The title is `Duplicate photos`; the nav has a `Duplicate photos` link to this page. The summary bar shows `Groups 23`, `Judged 23`, `Reviewed 0`, `Marked for removal 0`.
2. On the first card check: a similarity bar for each photo (for example 0.9921, 0.9921, 0.9608), variant tags (`original`, `augmented · mirrored`, `augmented · recompressed`), a `model's pick` label on one photo, the model's reasons, any issues, and the run link.
3. Click `Keep this one, remove 2`. Expected: the summary shows `Reviewed 1`, `Marked for removal 2`; the card tag reads `model pick kept`; the pick shows `kept`, the others `marked for removal`; the text `2 marked for removal; no file or row was deleted.` and an `Undo` button appear.
4. Click `Undo`. Expected: `Reviewed 0`, `Marked for removal 0`, marks and tag gone.
5. Click `Pick another` on the original (the photo that was not the model's pick). Expected: tag `your pick kept`; the original shows `kept`; the others `marked for removal`; the model's pick label is still visible next to your choice.
6. Click `Undo` again. Expected: the card is back to its first state.
7. Repeat the counting commands. Expected: the file count and the `listing_photos` count equal the baseline taken at the start of this step. No photo was deleted.
8. Safety check with a bad id (NFR-1). In the browser console on the page run:

```js
fetch('/ai/photos/duplicates/1/keep', {method:'POST', headers:{'Content-Type':'application/json','Accept':'application/json','X-XSRF-TOKEN':decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1])}, body: JSON.stringify({photo_id: 999999})}).then(r => r.status)
```

Expected: a 4xx status (422 or 404), not 200, and the summary stays `Reviewed 0` after a reload.

## Step 6: the reported problem, re-judging after a review (AC-5, AC-6)

This is the case that failed: running `--judge` again wiped the model's pick on a group you had already reviewed.

1. On https://ai-lab.ddev.site/ai/photos/duplicates click `Keep this one, remove 2` on the first card. Note the `model's pick` photo and its reasons.
2. Run `ddev artisan photos:duplicates --judge --wait`. If you rebuilt groups only (`photos:duplicates`), reviewed groups are kept.
3. Reload the page.

Expected: the reviewed card still shows the same `model's pick`, the same reasons, and the tag `model pick kept`. The summary still shows `Reviewed 1`, `Marked for removal 2`. The reviewed group is not queued again, so the `Queued N groups` number is one lower than the group count. Unreviewed cards are judged as before.

Clean up: click `Undo` on that card.

## Step 7: eval and benchmarks (AC-7, NFR-3)

```sh
ddev artisan photos:duplicates-eval --force
```

Expected:
- A table with the cutoffs (10 rows), then `Chosen cutoff 0.92 (...)`, then `Clustered N photos in M ms` with M under 5000.
- A model table with `gemma4:12b` and `qwen3.5:9b` rows (a model that is not installed is skipped with a warning, not an error).
- The last line is `Results: .../photo-duplicates/<today UTC>/run.json`. Open that file: it has `curve` with `recall_by_kind` and precision per cutoff, `cutoff`, `judging.models` with pick accuracy per model, and `clustering`.
- Open BENCHMARKS.md, section `Duplicate photos and the best pick (2026-11-12)`. It must show the cutoff table with recall per variant kind and precision, the chosen cutoff, and the pick accuracy per model. Expected rows for 0.95 and 0.97 and the per-kind recall columns are present; if they are missing, AC-7 is not met.
- Without a model: `ddev artisan photos:duplicates-eval --no-judge --force` finishes with a cutoff table and no model table.

## Step 8: the guide (AC-8)

Open https://ai-lab.ddev.site/ai/guides/photo-duplicates. Expected: six tabs, `How it works`, `Implementation`, `Modify`, `Support`, `Numbers`, `Experiments`, each with text. The Numbers tab mentions the cutoff 0.92. The browser console has no errors. Open the review page again: neither page mentions a book, a course, or any outside reference.

## Step 9: nothing else changed (MNC-1, MNC-2)

```sh
ddev artisan photos:similar-eval --force
ddev artisan photos:search-eval --force
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The similar-photos and photo-search numbers equal the committed ones in BENCHMARKS.md, with the generated copies still in the database.
- The fingerprint lines show `app-DNob7CFE.css 176bae5b...` and `app-PTzu1QaN.js 7fbc3860...` (the baseline names and hashes).
- The parity diff changes only the `captured=` line.
- Restore the snapshot afterwards: `git checkout bench/parity` and any result files the evals overwrote.
