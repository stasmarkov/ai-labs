# Manual test plan: local LLM comparison and limits (SL-28)

Base URL: https://ai-lab.ddev.site. Run every command from the project root.

## Before you start

- DDEV is running and the dev database holds the 50 000 demo listings.
- Ollama runs on the host with `qwen3.5:9b` and `gemma4:12b` pulled.
- Close other heavy apps: memory and tokens/s depend on free RAM.
- Note the active server: `bash -c 'source bench/lib/servers.sh; current_server'`.
- `cp .env /tmp/env.before`

## Step 1

The model bench (AC-1, AC-2, AC-3, AC-4).

```sh
ddev artisan llm:bench
cat bench/results/llm/$(date -u +%F)/run.json | head -120
```

Expected:
- One summary row per model, with load time, TTFT, tokens/s and memory.
- `run.json` holds:
  - the speed block per model;
  - the context block, where `truncated` is true at the configured `num_ctx` and false at 2×;
  - the failover matrix: 12 rows, each with a status, an error, a time to fail and the `ai_runs` flag;
  - the model table with source paths and dates.
- No API key or `Authorization` string: `grep -iE 'authorization|api[_-]?key|sk-' bench/results/llm/*/run.json` prints nothing.
- After the run, Ollama works normally again. For example, `/ai/describe` streams for listing 1.

## Step 2

Concurrency (AC-5).

```sh
bench/llm-load.sh concurrency
cat bench/results/llm-load/$(date -u +%F)/concurrency.json | head -80
```

Expected:
- Levels 1, 4 and 16 for both models.
- TTFT p95 grows with the level.
- The non-200 or incomplete share is 0. There is no 429.
- `ollama_num_parallel` is recorded.

## Step 3

Search under AI load (AC-6).

```sh
bench/llm-load.sh search-under-load
cat bench/results/llm-load/$(date -u +%F)/search-under-load.json
```

Expected:
- frankenphp, frankenphp-worker and frankenphp-octane each have a run without load and a run with 4 describe streams, plus the p95 delta.
- The describe count during the window is above 0.
- At the end, the active server is the one noted before the start.

## Step 4

Restore on interrupt (MNC-4, NFR-1).

```sh
bench/llm-load.sh concurrency --quick   # press Ctrl-C after ~10 s
cmp .env /tmp/env.before && echo env-ok
git diff --quiet .ddev/config.yaml && echo ddev-ok
ls .env.bench-backup 2>/dev/null || echo no-backup
```

Then the stale-backup check:

```sh
cp .env .env.bench-backup && bench/llm-load.sh concurrency --quick; echo "exit=$?"; rm .env.bench-backup
```

Expected:
- `env-ok`, `ddev-ok` and `no-backup` are printed.
- The active server is unchanged.
- No `k6` container is left running (`docker ps`).
- The stale-backup run prints `exit=2` before any load.

## Step 5

Smoke group and other checks (AC-7, MNC-2, MNC-3, MNC-5).

```sh
ddev artisan test --compact                           # smoke tests skipped, suite green
ollama pull qwen3:0.6b
ddev exec env LOCAL_LLM_SMOKE=1 php artisan test --group=local-llm
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
bench/run.sh ping --quick
```

Expected:
- The default suite passes and lists the smoke tests as skipped. The smoke group gives 3 passed.
- The fingerprint is unchanged, and the parity diff shows only the `captured=` line.
- `run.sh ping --quick` exits 0 with its usual output.

## Step 6

The guide (AC-8).

1. Open https://ai-lab.ddev.site/ai/guides/local-models and click through all six tabs.
2. Open `BENCHMARKS.md`, section "Local models and limits".

Expected:
- Status 200 and six tabs. The Numbers tab shows the figures from steps 1 to 3.
- The page has no slice ids or file paths.
- The `BENCHMARKS.md` section has the five tables, "What the numbers say", "Surprises" and an interview story with a measured number.
