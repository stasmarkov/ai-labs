# Manual test plan: car photos and vision extraction (SL-27)

Base URL: https://ai-lab.ddev.site. Needs Ollama on the host with `gemma4:12b` and `qwen3.5:9b`, and `AI_PROVIDER=ollama`.

## Step 1

Terms and download (AC-1, NFR-1, MNC-5).

```sh
bench/datasets/stanford-cars.sh; echo "exit=$?"
bench/datasets/stanford-cars.sh --accept-terms
bench/datasets/stanford-cars.sh --accept-terms
git status --short | grep -v .ai/session
```

Expected:
- The first run prints the terms and `exit=1`.
- The second run downloads and verifies the checksum.
- The third run says it is already present.
- `git status` shows nothing new.

## Step 2

Import (AC-2).

```sh
ddev artisan photos:import
ddev artisan tinker --execute 'echo App\Models\ListingPhoto::count();'
```

Expected:
- The report lists matched and skipped classes and the number of stored photos (at most 600).
- Opening `/ai/photos` shows the grid.
- Each photo's listing has the same make and model as the photo's class.

## Step 3

Extraction (AC-3, AC-4, AC-5).

```sh
ddev artisan photos:extract --limit=20
ddev artisan queue:work --queue=ai-vision --stop-when-empty
```

Then on `/ai/photos`:
1. Open a completed photo.
2. Click "Run extraction" on a pending one.

Expected:
- The JSON has all six keys.
- The attempts, model, prompt version and run link show; the run page opens.
- The pending photo turns completed or failed without a reload.

## Step 4

Eval (AC-6).

```sh
ddev artisan photos:eval
```

Expected:
- `bench/results/photo-vision/<date>/run.json` has both models with every metric.
- `BENCHMARKS.md` gets its section from these numbers.

## Step 5

Checks (MNC-1, MNC-4).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The fingerprint is unchanged.
- Only the `captured=` line changes.
