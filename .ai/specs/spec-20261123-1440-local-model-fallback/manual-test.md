# Manual test plan: local model fallback (SL-61)

Base URL: https://ai-lab.ddev.site

## Preconditions

- `ddev start`; Ollama running with `qwen3.5:9b` and `qwen3:0.6b` (`ollama list`).
- `.env` has no `AI_*_FALLBACK_MODEL` overrides (defaults apply); on https://ai-lab.ddev.site/ai/settings reset describe and NL search to configuration (saved choices outrank `.env`); check the model env names in `config/ai.php`.
- Rollback: revert the feature commit and roll back its migration by name (`ddev artisan migrate:rollback --path=database/migrations/<the SL-61 migration file>`).

## Step 1

Fallback on a fault (AC-1, AC-4). The originally reported gap: with the main model gone, describe and NL search fail.

1. Set `AI_DESCRIBE_MODEL=llm-missing:0` and `AI_NL_SEARCH_MODEL=llm-missing:0` in `.env`, then `ddev artisan config:clear`.
2. Open https://ai-lab.ddev.site/ai/describe and describe a car; open https://ai-lab.ddev.site/ai/nl-search and search "cheap diesel estate".
3. Set `AI_DESCRIBE_FALLBACK_MODEL=llm-missing-too:0`, clear config, describe again.
4. Remove all three lines and clear config.

Expected:
- Step 2: both answer (text streams; filters appear). The run rows show model `qwen3:0.6b`, fallback from `llm-missing:0`, reason `model_missing`.
- Step 3: describe shows the usual error event; one failed row names both models.

## Step 2

Lab display (AC-6).

1. Open the run page of a step 2 run (link from the feature page or `/ai/runs/<id>`).
2. Open https://ai-lab.ddev.site/ai/settings at 1440 and 1024 px.

Expected: the run says "answered by the fallback model qwen3:0.6b (model missing)"; the settings page lists each feature's fallback model and budget (read-only); no layout break.

## Step 3

Measurement (AC-7, NFR-1, NFR-2).

```sh
sysctl -n vm.loadavg            # below 4 before starting
ddev artisan llm:bench --only=fallback
```

Expected: the command prints the three tables and the path of `bench/results/llm-fallback/<date>/run.json`; the file records host load at start and end; fault rows answer within 2 s plus the fallback's first token; describe first-token p50 at 1 parallel without faults lies within 196–296 ms or the difference is explained.

## Step 4

Docs and nothing else changed (AC-8, MNC-4).

1. Open https://ai-lab.ddev.site/ai/guides/local-models#numbers and /ai/guides/providers.
2. Run `bench/asset-fingerprint.sh` and `git diff --stat master -- composer.json composer.lock package.json package-lock.json`.

Expected: guides show the fallback numbers and watch-outs; fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`; the git diff prints nothing.
