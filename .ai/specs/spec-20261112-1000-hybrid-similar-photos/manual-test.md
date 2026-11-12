# Manual test plan: hybrid similar photos (SL-38)

Base URL: https://ai-lab.ddev.site. The encoder is running and all photos have image vectors (SL-36).

## Step 1

Captions and caption vectors (AC-1).

```sh
ddev artisan photos:extract
ddev artisan queue:work --queue=ai-vision --stop-when-empty
ddev artisan photos:embed --text --wait
```

Expected:
- The extraction completes for most photos.
- The embed summary counts photos with a caption vector and those skipped for having no caption.

## Step 2

Slider and eval (AC-4, AC-5, NFR-1).

Open `/ai/photos?photo=25` and move the slider from 1.0 to 0.5.

Expected:
- At 1.0 the hybrid column equals the image-only column.
- At 0.5 the order changes, and each row shows its image and caption parts.

Then run:

```sh
ddev artisan photos:similar-eval --hybrid
```

Expected: `hybrid.json` holds the per-`w` metrics, the baseline, the title run marked as leaking, and a hybrid p95.

## Step 3

Checks (MNC-3, MNC-4).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The fingerprint is unchanged.
- Only the `captured=` line changes.
