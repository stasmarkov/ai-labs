# Manual test plan: in-app guides (SL-48)

## Step 1

The guide index and one guide (AC-2, AC-4).

1. Open https://ai-lab.ddev.site/ai/guides.
2. Open "Describe".
3. Go through all six tabs and reload on the Support tab.

Expected:
- Capability and foundation guides are listed, and Photos is labelled "planned".
- Each tab has content.
- The reload keeps the Support tab (hash).
- Snippets show a file caption and line numbers.
- The numbers match `bench/results` JSON.

## Step 2

Live sync (AC-5, MNC-2).

1. Open `/ai/describe` and expand "How it works".
2. Generate a description, then press Stop on a second run.
3. Repeat on `/ai/chat` with a question the cache already holds.

Expected:
- Steps highlight as the event log grows, and the final step is marked done.
- Stop still cancels.
- On chat the cache-hit step lights up.
- The event log, citations and run detail work as before.

## Step 3

Experiments (AC-6).

1. On a guide's Experiments tab, pick a prediction.
2. Click "Run it".
3. Run the preset, then come back.

Expected:
- The live page opens with the preset filled in.
- Back on the guide, the stored prediction is shown next to the measured result.

## Step 4

Performance (NFR-1).

```sh
for i in $(seq 5); do curl -s -o /dev/null https://ai-lab.ddev.site/ai/guides/chat; done
for i in $(seq 20); do curl -s -o /dev/null -w '%{time_total}\n' https://ai-lab.ddev.site/ai/guides/chat; done | sort -n | sed -n 19p
```

Expected: the 19th of 20 sorted values is under 0.150 s.

## Step 5

Checks (MNC-3, MNC-4, MNC-6).

```sh
bench/parity.sh
bench/asset-fingerprint.sh
ddev artisan test --compact
```

Expected:
- Parity is identical.
- The main entry fingerprint is unchanged.
- The suite passes with networking to Ollama stopped (`ddev exec curl -m1 http://host.docker.internal:11434` is not needed by any test).

At 390 px, `/ai/guides/chat` has no horizontal page scroll.
