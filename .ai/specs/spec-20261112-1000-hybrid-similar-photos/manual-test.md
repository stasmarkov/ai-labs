# Manual test plan: hybrid similar photos (SL-38)

Hybrid similar photos rank the neighbours of a photo by a mix of image similarity and caption similarity, weighted by `w` (1.0 is image only). This plan checks it by hand.

## Preconditions

- DDEV is running: `ddev start`. The app is at https://ai-lab.ddev.site.
- The image encoder is running and every photo already has an image vector (SL-36). No login is needed.
- The seeded demo data is loaded (photos 25 and 3 exist; photo 25 has a caption, photo 3 has none).
- Built assets are current: `ddev npm run build`.
- Rollback: the change adds text columns to `listing_photos` (migration `2026_11_12_100000`). To undo, `ddev artisan migrate:rollback --step=1` and restore the previous commit. The parity check below rewrites `bench/parity/frankenphp.txt`; run `git checkout bench/parity` after it.

## Step 1: Caption vectors are stored and current ones are skipped (AC-1)

```sh
ddev artisan photos:extract
ddev artisan queue:work --queue=ai-vision --stop-when-empty
ddev artisan photos:embed --text --wait
ddev artisan photos:embed --text --wait
ddev artisan photos:embed --help
```

Expected:
- `photos:extract` finishes without errors (it may say all photos already have an extraction).
- The first `photos:embed --text --wait` prints a summary with about 155 photos embedded and 1 skipped for having no caption.
- The second run embeds 0 photos: everything with a current hash and label is skipped.
- `--help` lists `--text`, `--fresh`, `--wait` and `--limit`, and has no `--text-source` option.

## Step 2: Slider on the lab page, happy path (AC-2, AC-3, AC-4)

Open https://ai-lab.ddev.site/ai/photos?photo=25. Open the browser dev tools, Network tab, and tick "Preserve log".

Expected on load:
- The detail panel shows a slider set to 0.9 (the default weight), an "Image only" column with 8 rows, and a column headed "Image and caption (w = 0.9)".
- The line "39 of 39 neighbours have a caption." appears.
- The console shows no errors.

Now drag the slider (or use the arrow keys) to 1.0, then 0.8, then 0.5.

Expected:
- Each move makes only a `GET /ai/photos/25/similar?w=...` request (`w=1`, `w=0.8`, `w=0.5`), each answering 200. There is no new document request and the page does not reload.
- At 1.0 the hybrid column shows the same 8 photos and scores as the "Image only" column, with no image/caption parts.
- At 0.8 the hybrid column shows 3 rows in a different order (about 0.7943, 0.7716, 0.7636), each with an "image" and a "caption" part.
- At 0.5 the hybrid column shows "No similar photos: no photo of another listing is similar enough." because the blended scores fall under the 0.75 cutoff.
- The "Image only" column never changes.

## Step 3: Photo without a caption (AC-2 fallback)

Open https://ai-lab.ddev.site/ai/photos?photo=3.

Expected:
- The text "No caption for this photo." is shown.
- Rows read like "image 0.8340, no caption, image only".
- Moving the slider to 0.5 and back gives the same 8 photos as the image-only column.

```sh
for w in 0.5 1; do curl -sk "https://ai-lab.ddev.site/ai/photos/3/similar?w=$w" | python3 -c 'import sys,json; d=json.load(sys.stdin); print([m["photo_id"] for m in d["matches"]], d["query_has_caption"], d["candidates_with_caption"])'; done
```

Expected: both lines print the same ids (`[9, 10, 2, 18, 102, 5, 94, 93]`), `False` and `0`. If the key names differ in your output, compare the raw JSON from `curl -sk "https://ai-lab.ddev.site/ai/photos/3/similar?w=0.5"`.

## Step 4: Endpoint contract

```sh
curl -sk -o /dev/null -w "%{http_code}\n" "https://ai-lab.ddev.site/ai/photos/25/similar"
curl -sk -H "Accept: application/json" "https://ai-lab.ddev.site/ai/photos/25/similar"
curl -sk -H "Accept: application/json" "https://ai-lab.ddev.site/ai/photos/25/similar?w=2"
curl -sk "https://ai-lab.ddev.site/ai/photos/25/similar?w=1"
curl -sk "https://ai-lab.ddev.site/ai/photos/25/similar?w=0.73"
ddev artisan route:list --path=ai/photos --name=similar
```

Expected:
- No `w`: status 422, message "The w field is required."
- `w=2`: 422, message that `w` must be between 0 and 1.
- `w=1`: JSON with `"w": null`, 8 matches and no parts (same as image only).
- `w=0.73`: JSON with `"w": 0.7` (rounded to one decimal) and `query_has_caption: true`, `candidates: 39`, `candidates_with_caption: 39`.
- `route:list` shows `GET ai/photos/{photo}/similar` with the `throttle:photo-similar` middleware.

## Step 5: Eval, best weight, title run and latency (AC-5, NFR-1, NFR-2)

```sh
ddev artisan photos:similar-eval --hybrid --force
python3 -c "import json; d=json.load(open('bench/results/photo-similar/'+__import__('os').listdir('bench/results/photo-similar')[-1]+'/hybrid.json')); print(list(d)); print(d['baseline']); print(d['best']); print(d['title']); print(d['latency'])"
grep -n "Hybrid similar photos" BENCHMARKS.md
```

Expected:
- The command finishes and writes `hybrid.json` under `bench/results/photo-similar/<today>/` (the file from 2026-11-12 is the reference).
- The keys are `metadata, baseline, sweep, best, title, latency`; `sweep` has one entry for each `w` from 0 to 1.
- `baseline` has `w: 1` and precision at 8 near 0.677; `best` is `w: 0.9` near 0.693 with `beats_baseline: true`.
- `title` has `leaks_ground_truth: true` and `headline: false` (the title run is shown but never chosen).
- `latency` shows uncached p95 well under the 80 ms budget (about 4.3 ms) and `within_budget: true`. A value over 80 is a recorded miss, not a failure.
- `BENCHMARKS.md` has a section "Hybrid similar photos (2026-11-12)" with the title row marked as leaking.

## Step 6: Guide page (AC-7)

Open https://ai-lab.ddev.site/ai/guides/similar-photos.

Expected: status 200, and the page contains the heading "Hybrid: add what the caption says", the recipe "Change the hybrid weight or the text source", the failure modes "Title leakage makes hybrid look perfect" and "Hybrid changes little for most photos", and the hybrid numbers from the eval.

## Step 7: One shared nearest-by-vector query (AC-6)

```sh
grep -rn "iterative_scan" app/Repositories
grep -n "nearest" app/Repositories/EloquentSimilarPhotosRepository.php app/Repositories/EloquentPhotoTextSearchRepository.php
```

Expected:
- `PhotoVectorRepository.php` holds the photo vector `SET LOCAL hnsw.iterative_scan`. The other matches are the docs, semantic search and docs-answer-cache repositories, which do not touch photos.
- Both the similar-photos and text-search repositories only call `PhotoVectorRepository::nearest(...)`.

## Step 8: Earlier behaviour is unchanged (MNC-1, MNC-2)

```sh
ddev artisan test --compact tests/Feature/PhotoVectorRepositoryTest.php
ddev artisan test --compact --filter='SimilarPhotos|PhotoTextSearch|PhotoSearch|PhotoEncoderText'
```

Then open https://ai-lab.ddev.site/ai/photo-search, type `red convertible` and submit.

Expected:
- Tests pass with no failures.
- The photo search page returns results as before this change.
- Open https://ai-lab.ddev.site/listings/1: the "similar photos" block, if shown, matches the "Image only" column of `/ai/photos` for the same photo.

## Step 9: Offline suite, parity and asset fingerprint (MNC-3, MNC-4)

```sh
ddev artisan test --compact
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
git checkout bench/parity
```

Expected:
- The full suite passes without network access (HTTP is faked).
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js` for the main entries, the same hashes as in `BENCHMARKS.md`.
- The parity diff shows only the `# ... captured=` line changed. Any other changed line means a listing page changed.
