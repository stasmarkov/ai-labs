# Manual test plan: buyer assistant (SL-25)

Base URL: https://ai-lab.ddev.site. Needs Ollama with qwen3.5:9b and gemma4:12b and `AI_PROVIDER=ollama`.

## Step 1

Ask and watch the steps (AC-1, AC-5).

1. Open `/ai/assistant`.
2. Ask "Do you have a diesel BMW under 20000 EUR in Lviv?", then "Is it an estate?" (expect the assistant to say it cannot filter by body type).
3. Then ask the follow-up "and only from 2018 or newer?".

Expected:
- The timeline shows step 1 with the arguments, status ok, a total and listing links.
- The answer names only listings from the step's result.
- The follow-up makes a new call that includes `min_year` 2018.
- The run link opens.

## Step 2

Repair, limit and eval (AC-2, AC-3, AC-8).

```sh
ddev artisan assistant:eval
```

Expected:
- `run.json` shows, for both models, tool precision and recall, first-try validity, repair rate, filter accuracy, steps (maximum at most 4), hallucinated ids and latency.
- No question exceeds 4 steps.

## Step 3

Checks (MNC-5).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The fingerprint is unchanged.
- Only the `captured=` line changes.
