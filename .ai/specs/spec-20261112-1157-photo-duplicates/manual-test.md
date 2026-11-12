# Manual test plan: duplicate photos and the best-photo pick (SL-39)

Base URL: https://ai-lab.ddev.site. The encoder is running, Ollama is up with gemma4:12b, and the 156 photos are embedded.

## Step 1

Generate and group (AC-1, AC-3).

```sh
ddev artisan photos:make-duplicates --photos=20
ddev artisan photos:embed --wait
ddev artisan photos:duplicates
```

Expected:
- 20 originals get 2–4 variants each, and the summary lists counts per kind.
- The groups are mostly an original with its variants. `mirrored` copies may be missing from their groups, and the eval shows how often.

## Step 2

Judge and review (AC-4, AC-5, AC-6).

```sh
ddev artisan queue:work --queue=ai-vision &
ddev artisan photos:duplicates --judge --wait
```

Then open `/ai/photos/duplicates`. Click "Keep this one, remove N", then Undo, then "Pick another".

Expected:
- Each group shows the model's pick with reasons and issues, and a run link.
- The marks change and nothing is deleted: `ls storage/app/public/photos | wc -l` is unchanged.
- The override is shown next to the model's pick.

## Step 3

Eval (AC-7, NFR-3).

```sh
ddev artisan photos:duplicates-eval
```

Expected: `run.json` has recall per kind, precision, the chosen cutoff, the pick accuracy for both models and the clustering time.

## Step 4

Nothing else changed (MNC-1, MNC-2).

```sh
ddev artisan photos:similar-eval --force
ddev artisan photos:search-eval --force
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The similar-photos and photo-search numbers equal the committed ones.
- The fingerprint is unchanged.
- Only the `captured=` line changes.
