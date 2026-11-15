# Manual test plan: learning guide final pass (SL-44)

## Step 1

Read the structure (AC-1, AC-6).

1. Open `docs/learning-guide.md` on GitHub, or in a markdown preview.
2. Click five random entries in the table of contents.

Expected:
- The table of contents is in learning order: Part I server, then Part II AI.
- Each link you click jumps to its section.
- The guide ends with the closing summary.

## Step 2

Numbers (AC-5, MNC-3).

1. Pick three numbers from the new sections, for example a photo precision, a model's tokens/s and a playground latency.
2. Find each one in `BENCHMARKS.md` or the linked `bench/results` file.

Expected: each number is identical to its source.

## Step 3

No code changed (MNC-1, MNC-4).

```sh
git diff --stat origin/master -- app routes resources config database bench
bench/asset-fingerprint.sh
ddev artisan test --compact
```

Expected:
- The first command prints nothing.
- The fingerprint is unchanged.
- The test suite passes.
