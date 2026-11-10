# Manual test plan: car photos and vision extraction (SL-27)

Base URL: https://ai-lab.ddev.site (DDEV, PostgreSQL, FrankenPHP).

## Preconditions

- Run everything from the repo root. `ddev start` is up.
- Ollama runs on the host with `gemma4:12b` and `qwen3.5:9b` pulled (`ollama list`), and `.env` has `AI_PROVIDER=ollama`.
- About 2 GB of free disk for the dataset (four parquet shards, roughly 2 GB) plus the converted images.
- Docker is running (the download script converts the shards in a pinned `python:3.12-slim` container).
- Start from a clean tree apart from this feature: `git status --short`.

Rollback: nothing here touches listings. To undo, `ddev artisan photos:import --fresh` is not needed; run `ddev artisan migrate:rollback --step=2` to drop `listing_photos` and `photo_extractions`, and `rm -rf storage/app/datasets storage/app/public/photos`. Delete `bench/results/photo-vision/<date>` if you do not want to keep an eval result.

## Step 0: the reported failure, /ai/photos returned HTTP 500

The page failed with `SQLSTATE[42P01] relation "listing_photos" does not exist` because the two photo migrations had not run.

```sh
ddev artisan migrate:status | grep -i photo
ddev artisan migrate --no-interaction
ddev artisan migrate:status | grep -i photo
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/ai/photos
curl -s -w '\n%{http_code}\n' https://ai-lab.ddev.site/ai/photos/1/status
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/ai/guides/photos
```

Expected:
- The first `migrate:status` shows `create_listing_photos_table` and `create_photo_extractions_table` as Pending; the second shows both as Ran.
- `/ai/photos` returns `200` (before the migrations: `500`).
- `/ai/photos/1/status` returns a JSON body and a 200 status code (no 500). With no photos yet it may show an empty or not-found shape, but it must not be a server error.
- `/ai/guides/photos` returns `200`.
- In a browser, open https://ai-lab.ddev.site/ai/photos. With no photos imported it shows the empty state naming the download and import commands.

## Step 1: terms gate and download (AC-1, NFR-1, MNC-5)

```sh
bench/datasets/stanford-cars.sh; echo "exit=$?"
ls storage/app/datasets 2>&1
bench/datasets/stanford-cars.sh --accept-terms; echo "exit=$?"
bench/datasets/stanford-cars.sh --accept-terms; echo "exit=$?"
ls storage/app/datasets/stanford-cars
git status --short | grep -v '^.. .ai/session'
```

Expected:
- Run 1 prints "Stanford Cars dataset - terms of use" with the source URL `https://ai.stanford.edu/~jkrause/cars/car_dataset.html`, ends with "Run again with --accept-terms", and prints `exit=1`. `storage/app/datasets` does not exist yet.
- Run 2 downloads the four parquet shards, verifies each sha256, converts them, and prints `exit=0`. It takes a while.
- Run 3 downloads nothing (files reported as already present and verified, no conversion) and prints `exit=0`.
- The folder holds `classes.csv`, `images/` and the parquet files.
- `git status` shows no path under `storage/app/datasets` (dataset is gitignored). Also check `docs/datasets/stanford-cars.md` lists the source, terms URL, mirror commit and checksums (NFR-1).

## Step 2: import (AC-2, MNC-5)

```sh
ddev artisan photos:import
ddev artisan tinker --execute 'echo App\Models\ListingPhoto::count().PHP_EOL;'
ddev artisan photos:import
ddev artisan tinker --execute 'echo App\Models\ListingPhoto::count().PHP_EOL; $p = App\Models\ListingPhoto::with("listing")->first(); echo json_encode($p->only(["id","path","sha256"])).PHP_EOL;'
ls storage/app/public/photos | head -3
git status --short | grep -v '^.. .ai/session'
```

Expected:
- First run prints a report with matched and skipped counts and the number of stored photos, at most 600 (and at most 12 per model).
- The count is greater than 0 and equal to the stored number reported.
- The second run stores 0 new photos (same sha256 never stored twice); the count is unchanged.
- Pick one photo in the lab (step 3) and confirm the listing it belongs to has the same make and model as the car class in its title, with the nearest year available.
- Files in `storage/app/public/photos` are JPEGs; check one is at most 1024 px on its longest side: `sips -g pixelWidth -g pixelHeight storage/app/public/photos/$(ls storage/app/public/photos | head -1)`.
- `git status` still shows nothing from the dataset or the photos (MNC-5).

## Step 3: extraction and the lab page (AC-3, AC-4, AC-5, NFR-2)

Terminal 1 (worker on its own queue):

```sh
ddev artisan queue:work --queue=ai-vision
```

Terminal 2:

```sh
ddev artisan photos:extract --limit=20
```

Then open https://ai-lab.ddev.site/ai/photos in a browser (desktop width, then resize to 390 px wide).

1. The grid shows photos with status badges that move from pending to completed (or failed) without reloading.
2. Click a completed photo. The detail shows the photo, the extracted JSON (caption, tags, colours, body type, damage, people count), the attempts count, the model (`gemma4:12b`), the prompt version (`v1`), the latency and a link to the AI run.
3. Click the run link: the run page opens.
4. Click the button on a photo that has no result yet. Note: the button is currently labelled "Extract" (or "Extract again" for an existing result), not "Run extraction" as the spec says. Record this as a known gap.
5. After clicking, the status updates on its own (the page polls about every 2 seconds). Click it again while it runs: nothing second is queued.
6. Open the browser console: no errors.

Expected on the command line: `photos:extract` reports queued photos; the worker log shows jobs on `ai-vision` only. Retry check (AC-4): stop Ollama (`ollama stop gemma4:12b`, or quit the app) and run `ddev artisan photos:extract --limit=1 --fresh`; the photo ends `failed` with an error shown, and `ddev artisan tinker --execute 'echo App\Models\AiRun::latest()->take(3)->get(["id","status"])->toJson();'` shows a run row per attempt. Restart Ollama afterwards.

## Step 4: safety of the lab endpoints (NFR-3)

```sh
curl -s -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/ai/photos/abc/status'
curl -s -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/ai/photos/..%2F..%2F.env/status'
curl -s -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/ai/photos?photo=../../.env'
curl -s -o /dev/null -w '%{http_code}\n' -X POST https://ai-lab.ddev.site/ai/photos/1/extract
```

Expected:
- First two: `404` (only numeric ids route).
- Third: `200`, the page shows the grid and ignores the non-numeric value; no file content appears.
- Fourth: `419` (no CSRF token), proving the endpoint only takes the id and is protected.

## Step 5: eval (AC-6)

```sh
ddev artisan photos:eval --photos=50
ls bench/results/photo-vision/
git status --short bench BENCHMARKS.md
```

Expected:
- The command prints a per-model table for `gemma4:12b` and `qwen3.5:9b`.
- `bench/results/photo-vision/<today>/run.json` exists and holds both models with every metric (valid-first-attempt rate, field accuracy, latency and so on).
- Running again the same day without `--force` refuses to overwrite.
- `BENCHMARKS.md` has a photo-vision section in the same format as the other sections, with a measured number in the story. This section must be added from the run.json numbers by hand; it is not generated, so if it is missing the step fails.

## Step 6: capability guide (AC-7)

Open https://ai-lab.ddev.site/ai/guides/photos.

Expected:
- Six tabs, each with content: Works, Failures, Recipes, Support, Numbers, Experiments.
- The Numbers tab shows measured values from step 5. If it still shows one row marked pending, the guide is not filled from the eval yet.
- No leftover reference to an unfinished slice or a ticket id on `/ai/photos` or the guide.

## Step 7: listings and parity unchanged (MNC-1)

```sh
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/api/listings
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
git checkout bench/parity
```

Open one listing page in the browser; it shows no photo.

Expected: `200` for the API; the parity diff has only the `captured=` line changed.

## Step 8: asset fingerprint (MNC-4)

```sh
ddev npm run build
bench/asset-fingerprint.sh
```

Expected: the printed `app-*.css` and `app-*.js` hashes equal the ones recorded in `tasks.md` section 9.2 (`176bae5b...` and `7fbc3860...`). Photos.vue changed again after that measurement, so a difference here must be explained before sign-off.

## Step 9: existing AI features unchanged (MNC-2)

Open https://ai-lab.ddev.site/ai/chat and send "hello"; open /ai/overview. Both work as before, and `photo-vision` shows only as a new row among the feature keys.
