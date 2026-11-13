# Manual test plan: local LLM comparison and limits

Base URL: https://ai-lab.ddev.site. Run every command from the project root.

## Before you start

- DDEV is running (`ddev start`) and the dev database holds the 50 000 demo listings.
- Ollama runs on the host with `qwen3.5:9b` and `gemma4:12b` pulled (`ollama list`).
- Close other heavy apps: memory and tokens/s depend on free RAM.
- The project default server must be the active one (`ddev server --default` and `ddev server --current` print the same name). The full `search-under-load` run refuses to start otherwise.
- Commit or stash your work first. `bench/run.sh`, which `search-under-load` calls, refuses a tree with uncommitted changes.
- Record the starting state:

```sh
bash -c 'source bench/lib/servers.sh; current_server' | tee /tmp/server.before
cp .env /tmp/env.before
git diff --quiet .ddev/config.yaml && echo ddev-clean
```

Rollback if a run is killed hard (kill -9) and leaves things behind: `mv .env.bench-backup .env` or `mv .env.llm-load-backup .env`, then `ddev artisan optimize:clear`, `ddev server <name from /tmp/server.before>`, and `docker rm -f $(docker ps -aq --filter name=llm-load-k6)`.

## Step 1

The model bench (AC-1, AC-2, AC-3, AC-4, NFR-2).

```sh
ddev artisan llm:bench
cat bench/results/llm/$(date -u +%F)/run.json | head -120
```

A second run on the same day must be refused: `ddev artisan llm:bench --only=speed` prints that the file exists and exits non-zero; with `--force` it overwrites.

Expected:
- Three tables are printed: speed, context and failover. The speed table has one row per model (`qwen3.5:9b`, `gemma4:12b`) with load ms, TTFT median and min-max, prompt tok/s, generation tok/s median and min-max, memory GB and VRAM GB.
- `run.json` metadata has `ollama_version`, `num_ctx` (8192 by default), `runs` (5), `sections` and `models`.
- Each model has:
  - `speed`: `load_ms`, `ttft_ms` and `generation_tokens_per_s` each with median, min and max, `prompt_eval_tokens_per_s`, `size` and `size_vram` in bytes;
  - `context`: `configured` and `doubled` blocks (num_ctx, `prompt_eval_count`, `truncated`, `recalled`) plus `estimated_prompt_tokens`;
  - `failover`: 3 faults (`unreachable`, missing model, 1 s timeout) x 4 entry points (`describe`, `nl-search`, moderation, assistant), each with `status`, `client`, `time_to_fail_ms` and `failed_run_written` (true);
  - `evals`: per feature `json_valid`, `accuracy`, `source` and `date`, or `null` with a reason when the model is absent from that run.
- No secrets: `grep -iE 'authorization|api[_-]?key|sk-' bench/results/llm/*/run.json` prints nothing, and none of the console output shows a key or a URL with a password.
- Committed eval files are untouched (MNC-6): `git status --short bench/results/nl-search bench/results/assistant bench/results/moderation bench/results/photo-vision` prints nothing.
- Afterwards Ollama still answers normally: open https://ai-lab.ddev.site/ai/describe and stream a description for listing 1.

## Step 2

Concurrency (AC-5).

```sh
bench/llm-load.sh concurrency
cat bench/results/llm-load/$(date -u +%F)/concurrency.json | head -80
```

Expected:
- A table on stdout and a JSON file with `server`, `ollama_num_parallel` ("default" when unset) and, for both models, levels 1, 4 and 16 with 8, 8 and 32 streams.
- Each level has `ttft_p50_ms`, `ttft_p95_ms`, `done_p50_ms`, `done_p95_ms`, `events_per_s_per_stream_median`, `events_per_s_aggregate`, `failed_share` and `rate_limited`.
- TTFT p95 grows with the level. `failed_share` is 0 and `rate_limited` is 0 at every level.
- Afterwards `cmp .env /tmp/env.before` is silent.

## Step 3

Search under AI load (AC-6). This takes a long time (3 servers, 3 repeats of 30 s warm-up and 60 s measure, twice each). It is the step that was not yet run when the spec was checked.

```sh
bench/llm-load.sh search-under-load
f=bench/results/llm-load/$(date -u +%F)/search-under-load.json
jq '.servers[] | {server, without_load, with_load, p95_delta_ms, p95_delta_percent, describes}' $f
```

Expected:
- `quick` is false, and `servers` has `frankenphp`, `frankenphp-worker` and `frankenphp-octane`.
- Each server has `without_load` and `with_load` with rps, p50, p95, p99 and error percent (median of 3 repeats), a `p95_delta_ms`, and `describes.completed` above 0 with `completed_per_minute`.
- Check the originally suspected gap: `jq '.servers[].describes.completed' $f` must show no 0. If the router address changes during a restart, the with-load figures would be measured with no describe traffic; a 0 or a refused write means the run is invalid, repeat it.
- At the end `ddev server --current` equals `cat /tmp/server.before`, `cmp .env /tmp/env.before` is silent, and `git diff --quiet .ddev/config.yaml && echo ddev-ok` prints `ddev-ok`.
- Interrupt variant: run `bench/llm-load.sh search-under-load --quick --force`, press Ctrl-C about 40 s in (while the describe loop runs) and run the checks of step 4.

## Step 4

Restore on interrupt (MNC-4, NFR-1).

```sh
bench/llm-load.sh concurrency --quick --force   # press Ctrl-C after ~10 s
echo "exit=$?"
cmp .env /tmp/env.before && echo env-ok
git diff --quiet .ddev/config.yaml && echo ddev-ok
ls .env.bench-backup .env.llm-load-backup 2>/dev/null || echo no-backup
docker ps -a --filter name=llm-load-k6 --format '{{.Names}}'
bash -c 'source bench/lib/servers.sh; current_server' | diff - /tmp/server.before && echo server-ok
```

Then the stale-backup check:

```sh
cp .env .env.bench-backup && bench/llm-load.sh concurrency --quick --force; echo "exit=$?"; rm .env.bench-backup
```

Expected:
- After Ctrl-C the exit code is 130, and `env-ok`, `ddev-ok`, `no-backup` and `server-ok` are printed. The `docker ps -a` line prints nothing.
- The stale-backup run prints `exit=2` right away, before any load, and `.env` is unchanged.

## Step 5

Existing scripts and fingerprint (MNC-3, MNC-5).

```sh
git diff --quiet HEAD -- bench/run.sh bench/slow-load.sh bench/sse.sh bench/chat-stop.sh bench/suite.sh && echo scripts-unchanged
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
bench/run.sh ping --quick
```

Expected:
- `scripts-unchanged` is printed. `bench/k6-sse.js` keeps the old summary list when no `ITERATIONS` is set: `grep -n summaryTrendStats bench/k6-sse.js` shows `['avg', 'med', 'p(95)', 'p(99)']` for the non-real branch.
- The fingerprint lines equal the ones from before the change (compare with `git stash`-free output from an earlier build, or the lines recorded in BENCHMARKS.md).
- The parity diff shows only the `captured=` line.
- `bench/run.sh ping --quick` exits 0 with its usual output. Restore the parity file afterwards: `git checkout bench/parity`.

## Step 6

The guide and benchmark notes (AC-8).

1. Open https://ai-lab.ddev.site/ai/guides/local-models and click through the six tabs: How it works, Implementation, Modify, Support, Numbers, Experiments.
2. Open `BENCHMARKS.md`, section "Local models and limits".

Expected:
- Status 200 and six tabs. The Numbers tab shows figures from the run files, including a concurrency figure and, once step 3 is done, a search p95 delta for each of the three servers matching `search-under-load.json`.
- The page shows no slice ids (no `SL-` text).
- The `BENCHMARKS.md` section has five tables (speed and evals, context, failover, concurrency, search under load), "What the numbers say", "Surprises" and an interview story with a measured number. The heading date matches the run files, and every figure in the search table matches `search-under-load.json`.

## Step 7

Smoke group (AC-7, MNC-2).

```sh
ddev artisan test --compact
ollama pull qwen3:0.6b
ddev exec env LOCAL_LLM_SMOKE=1 php artisan test --group=local-llm
```

Expected:
- The default run is green with 3 tests shown as skipped, and makes no call to Ollama (it also passes with Ollama stopped).
- The smoke run gives 3 passed (describe, natural-language search, moderation).
- Failing case that must now fail: make the model call fail with a 1 s timeout (`AI_TIMEOUT` is the Ollama timeout in `config/ai.php`), for example `ddev exec env LOCAL_LLM_SMOKE=1 OLLAMA_SMOKE_MODEL=gemma4:12b AI_TIMEOUT=1 php artisan test --group=local-llm --filter=moderation`. The moderation test fails; it must not pass on a 503.
- With Ollama stopped and `LOCAL_LLM_SMOKE=1`, the tests are skipped with a message that contains `ollama pull qwen3:0.6b`.

## Step 8

Application behaviour unchanged (MNC-1).

```sh
git diff --stat HEAD -- app/Http routes config resources/js phpunit.xml resources/views
```

Expected: no output. New app code is only the `llm:bench` command and its support classes.
