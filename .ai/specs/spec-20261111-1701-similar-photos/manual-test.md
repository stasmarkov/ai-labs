# Manual test plan: similar car photos (SL-36)

Base URL: https://ai-lab.ddev.site. Needs the 156 imported photos from SL-27.

## Step 1

The encoder (AC-1).

```sh
ddev restart
ddev exec curl -s http://photo-encoder:7997/health; echo
docker volume ls | grep -i encoder
ddev restart && ddev exec curl -s http://photo-encoder:7997/health; echo
```

Expected:
- The health check answers.
- A named weights volume exists.
- The second start does not download the model again (fast health).

## Step 2

Embed and look (AC-2, AC-3, AC-6).

```sh
ddev artisan photos:embed --wait
```

Then open `/ai/photos` and pick a BMW X3.

Expected:
- The summary shows 156 embedded and one run per batch.
- The strip shows up to 8 photos, none from the same listing, each with a similarity below 0.98.
- Most matches are BMW X3; the model label and cutoffs are shown.

## Step 3

Cache and speed (AC-4, NFR-1).

Reload the same photo twice.

Expected:
- The second load says "from cache".
- The eval's run.json shows p95 under 50 ms uncached and under 5 ms cached.

## Step 4

The listing page (AC-5, MNC-1).

Open the listing of a photo (link on `/ai/photos`), then `/listings/1`.

Expected:
- The photo's listing shows "Similar cars" with linked photos.
- `/listings/1` shows no block.
- `PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity` changes only the `captured=` line.

## Step 5

Model switch (AC-7).

On `/ai/settings`, switch `embeddings-photos` to another available model (if one is listed) with the re-index tick.

Expected:
- The strip says the index was built with another model until `ddev artisan photos:embed --fresh` runs.
- Reset afterwards.
