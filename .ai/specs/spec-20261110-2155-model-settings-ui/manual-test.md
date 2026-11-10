# Manual test plan: model settings UI (SL-52)

Base URL: https://ai-lab.ddev.site. Needs Ollama running on the host with `qwen3.5:9b`, `gemma4:12b` and `bge-m3` pulled, and `AI_PROVIDER=ollama` in `.env`.

## Step 1

The page (AC-2, AC-3, AC-4).

1. Open `/ai/settings`.
2. For Describe, pick Ollama, then `gemma4:12b`, then Save.
3. Reload the page.
4. Click "Reset to configuration".

Expected:
- Every feature row shows its provider, model and source.
- The Ollama model list holds only installed models: `bge-m3` appears only for the embeddings rows.
- After the save, Describe shows `gemma4:12b · settings`.
- After the reset, Describe shows `qwen3.5:9b · configuration`.

## Step 2

Worker mode sees the change (AC-1, NFR-1).

```sh
ddev server frankenphp-worker
```

1. Save `gemma4:12b` for Describe.
2. Generate a description at once (no restart).
3. Open its run detail.
4. Reset, generate again, and run `ddev server default`.

Expected:
- The run shows `gemma4:12b`, and the next run after the reset shows `qwen3.5:9b`.
- Repeat on `frankenphp-octane` with the same result.

## Step 3

Embeddings switch (AC-5).

1. For Docs embeddings, choose another installed embedding model.

Expected:
- The page warns before saving and shows `ddev artisan docs:index` after saving.
- The indexed-row count drops to 0 for the new model.
- Docs search answers 409 until the re-index. Reset afterwards.

## Step 4

Speed and exposure (NFR-2, NFR-3, AC-6).

```sh
for i in $(seq 5); do curl -sk -o /dev/null https://ai-lab.ddev.site/ai/settings; done
for i in $(seq 20); do curl -sk -o /dev/null -w '%{time_total}\n' https://ai-lab.ddev.site/ai/settings; done | sort -n | sed -n 19p
```

Then set `AI_SETTINGS_UI=false`, run `ddev restart`, and open `/ai/settings`.

Expected:
- The timing is under 0.200.
- With the flag off the page is a 404, and "Settings" is gone from the navigation. Rollback: remove the line and run `ddev restart`.

## Step 5

Checks (MNC-6).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The fingerprint is unchanged.
- Only the `captured=` line changes.
