# Manual test plan: optional cloud providers (SL-51)

Steps 2 and 3 need a real key and spend a few cents. Skip them if no key is at hand; the automated tests cover the logic offline.

## Step 1

No keys (MNC-1).

1. Make sure `.env` has no `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` or `GEMINI_API_KEY`.
2. Open https://ai-lab.ddev.site/ai/describe and https://ai-lab.ddev.site/ai/guides/providers.

Expected:
- The selector lists only the local providers.
- The guide shows the capability table and `no key` for each cloud provider.
- Describe works as before.

## Step 2

One cloud key (AC-1, AC-3, AC-4).

1. Put `ANTHROPIC_API_KEY=<your key>` in `.env` and run `ddev restart`.
2. On `/ai/describe` pick "anthropic" and generate a description.
3. Open the run detail page.

Expected:
- The text streams.
- The run row shows provider `anthropic` and the configured Claude model.
- The guide shows `key set` for Anthropic. The key value appears nowhere: view the page source and search for its first 8 characters.

## Step 3

Embeddings switch (AC-5).

1. Set `AI_EMBEDDINGS_DOCS_PROVIDER=openai` with `OPENAI_API_KEY` in `.env`, then run `ddev restart`.
2. Run a docs search.
3. Run `ddev artisan docs:index`, then search again.

Expected:
- The first search answers 409 with the re-index command.
- After the re-index, results come back.
- Rollback: remove the variable and run `ddev artisan docs:index` again for `bge-m3`.

## Step 4

Checks (MNC-5).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The fingerprint is `app-DNob7CFE.css` / `app-PTzu1QaN.js`.
- Only the `captured=` line changes.
