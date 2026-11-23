# Manual test plan: local model fallback (SL-61)

Base URL: https://ai-lab.ddev.site. All commands run from the project root on your Mac.

The reported problem: when a feature's main local model is gone, busy or failing, the request failed. Now describe, NL search, docs answer and the assistant answer with the smaller `qwen3:0.6b` and say so in the run log.

## Preconditions

- `ddev start`, and Ollama running on the host with both models: `ollama list` shows `qwen3.5:9b` and `qwen3:0.6b`.
- `.env` has no `AI_*_FALLBACK_MODEL` or `AI_*_FALLBACK_AFTER_MS` lines (the defaults give `qwen3:0.6b`, no budget).
- On https://ai-lab.ddev.site/ai/settings, describe and NL search are on "configuration" (a saved choice outranks `.env`). Use the reset button of a feature if it shows a saved choice.
- Every time you edit `.env`, run `ddev artisan config:clear` afterwards.
- To read run rows: `ddev artisan tinker --execute 'App\Models\AiRun::latest("id")->take(3)->get(["invocation_id","feature","model","status","fallback_from_model","fallback_reason","latency_ms"])->each(fn ($r) => print(json_encode($r->toArray()).PHP_EOL));'`
- Pick a listing id for describe: `ddev artisan tinker --execute 'echo App\Models\Listing::query()->value("id");'`. Below it is written `<id>`.
- Rollback: delete the lines you added to `.env`, run `ddev artisan config:clear`, reset any saved setting on `/ai/settings`. The feature itself: revert its commit and run `ddev artisan migrate:rollback --path=database/migrations/2026_11_23_150000_add_fallback_columns_to_ai_runs_table.php`.

## Step 1

The reported case on NL search, main model missing (AC-1, model_missing).

1. Add to `.env`: `AI_NL_SEARCH_MODEL=missing-model:1b`, then `ddev artisan config:clear`.
2. Run:
   ```sh
   curl -s -w '\nHTTP %{http_code} in %{time_total}s\n' 'https://ai-lab.ddev.site/api/listings/nl?q=cheap+diesel+estate'
   ```
3. Run the tinker command from the preconditions.

Expected:
- Status 200, JSON with listings and `"model":"qwen3:0.6b"` in `data`; under about 2 s plus the small model's time.
- Newest row: `model` `qwen3:0.6b`, `status` `completed`, `fallback_from_model` `missing-model:1b`, `fallback_reason` `model_missing`. One row for the one request.

## Step 2

Same fault on a streamed feature, describe (AC-1).

1. Add `AI_DESCRIBE_MODEL=missing-model:1b` to `.env`, `ddev artisan config:clear`.
2. Run `curl -sN https://ai-lab.ddev.site/listings/<id>/describe`.
3. Run the tinker command.

Expected: text events stream and the stream ends with `stream_end` and `[DONE]`; no error event. One new row: `model` `qwen3:0.6b`, `completed`, `fallback_from_model` `missing-model:1b`, `fallback_reason` `model_missing`.

## Step 3

Other faults: unreachable, 5xx, timeout (AC-1), and no fallback after a first token or tool call (AC-3). These need a broken server mid-answer, so run the offline tests that simulate them:

```sh
ddev artisan test --compact tests/Feature/LocalModelFallbackTest.php
```

Expected: all tests pass, including the unreachable, 500, timeout, token-already-sent and tool-already-called cases. (A refused connection cannot be tried by hand: the fallback sits on the same Ollama, so pointing `OLLAMA_URL` elsewhere breaks both models.)

## Step 4

Busy main model, the first-token budget (AC-2).

1. Stop any loaded model: `ollama stop qwen3.5:9b` (the main model is now cold and slow to load).
2. Replace the describe line in `.env` with: `AI_DESCRIBE_FALLBACK_AFTER_MS=300` (no `AI_DESCRIBE_MODEL` line), `ddev artisan config:clear`.
3. Run `curl -sN https://ai-lab.ddev.site/listings/<id>/describe`.
4. Run the tinker command, then `curl -s http://localhost:11434/api/ps`.

Expected: the stream answers in roughly 1 to 2 s with no error. Row: `model` `qwen3:0.6b`, `fallback_from_model` `qwen3.5:9b`, `fallback_reason` `busy`. `/api/ps` lists `qwen3:0.6b` and not a half-loaded `qwen3.5:9b`.

## Step 5

Both models fail (AC-4).

1. In `.env`: `AI_NL_SEARCH_MODEL=missing-model:1b` and `AI_NL_SEARCH_FALLBACK_MODEL=also-missing:1b`, `ddev artisan config:clear`.
2. Run the curl from Step 1.
3. Run the tinker command.

Expected: status 503 and body `{"success":false,"status":503,"message":"Natural-language search is unavailable right now."}`, the same as a failed main model before this feature. One row only: `status` `failed`, `model` `also-missing:1b`, `fallback_from_model` `missing-model:1b`, `fallback_reason` `model_missing`.

## Step 6

No fallback model, behaviour unchanged (AC-5, MNC-1).

1. In `.env`: `AI_NL_SEARCH_MODEL=missing-model:1b` and `AI_NL_SEARCH_FALLBACK_MODEL=` (empty), `ddev artisan config:clear`.
2. Run the curl from Step 1 and the tinker command.
3. On https://ai-lab.ddev.site/ai/settings, switch NL search to a cloud provider only if you have a key set; otherwise skip this part. A cloud provider must never fall back to the local model.

Expected: status 503 with the same body as Step 5; row `model` `missing-model:1b`, `failed`, `fallback_from_model` and `fallback_reason` both null. Moderation (no fallback by default) behaves as before as well: no fallback fields on its rows.

## Step 7

Lab display and settings (AC-6). First clean up: remove all fallback and model lines you added to `.env` and run `ddev artisan config:clear`; rows from Steps 1 to 4 remain.

1. Run `ddev npm run build` if the page looks old.
2. Open https://ai-lab.ddev.site/ai (recent runs table).
3. Open https://ai-lab.ddev.site/ai/runs/<invocation_id of the Step 4 row> (get it from the tinker command).
4. Open https://ai-lab.ddev.site/ai/settings at 1440 and 1024 px wide.

Expected:
- Recent runs: the fallback rows read `qwen3:0.6b (fallback: the main model was busy)` and `qwen3:0.6b (fallback: the main model is missing)`.
- The run page JSON/view has `fallback_from_model` `qwen3.5:9b` and `fallback_reason` `busy`.
- Settings: describe, NL search, docs answer and assistant each show "Fallback qwen3:0.6b on an error" (read-only), describe shows no budget until one is set; moderation shows no fallback. No layout break, no errors in the browser console.

## Step 8

Original behaviour kept (MNC-2, MNC-3).

```sh
ddev artisan test --compact tests/Unit/AiProvidersTest.php tests/Feature/LocalModelFallbackTest.php
```

Then on https://ai-lab.ddev.site/ai/nl-search, search "cheap diesel estate" with the defaults.

Expected: tests pass (provider precedence request > saved setting > configuration, validation, guards, masked stream error, no cloud/local cross-fallback). The page returns results from `qwen3.5:9b`; the new row has null fallback fields.

## Step 9

Bench on an idle host (task 7; AC-7, NFR-1, NFR-2).

```sh
sysctl -n vm.loadavg          # first number below 4; if not, wait or close other apps
ddev artisan llm:bench --only=fallback --models=qwen3.5:9b
ls bench/results/llm-fallback/
```

Expected:
- The command prints its tables and ends with `Results written to .../bench/results/llm-fallback/<date>/run.json`.
- The file has the fault matrix (unreachable, model missing, 1 s timeout), the busy runs at 1, 4 and 16 parallel with and without the budget (`metadata.budget_ms` 2000), the fallback model's eval figures, and the host load at the start and the end (both low).
- NFR-1: every fault row answered by `qwen3:0.6b` in under 2 s plus its own first-token time.
- NFR-2: `busy.1.without_budget.ttft_ms.p50` is at most 296 ms (SL-28 spread 196 to 296 ms p50 to p95), or the difference is explained in the notes.
- Commit the new `bench/results/llm-fallback/<date>/` folder. A second run on the same day needs `--force`.

## Step 10

Numbers in the docs (task 12; AC-7, AC-8).

1. Open the "Local model fallback (SL-61)" section of `BENCHMARKS.md`: add the three tables (fault matrix, busy describes, fallback evals) from the committed `run.json`.
2. Add one measured number to the interview story in the same section, and apply the C-14 quality rule from `spec-lite.md` to the fallback eval figures.
3. Open https://ai-lab.ddev.site/ai/guides/local-models#numbers and https://ai-lab.ddev.site/ai/guides/providers.
4. Run `ddev artisan test --compact tests/Feature/GuideContentTest.php`.

Expected: the guide shows the fallback numbers (no "not measured yet"), the how-it-works notes, the watch-outs and the recipe; the same numbers appear in `BENCHMARKS.md` and `docs/learning-guide.md`; the test passes.

## Step 11

Nothing else changed (MNC-4, task 16).

```sh
ddev npm run build
bench/asset-fingerprint.sh
git diff --stat master -- composer.json composer.lock package.json package-lock.json
ddev artisan test --compact
```

Expected: fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`; the git diff prints nothing; the full suite passes offline.

Tick after the manual steps pass: tasks 7, 12 (the `- [ ] 7.` and `- [ ] 12.` lines in `.ai/specs/spec-20261123-1440-local-model-fallback/tasks.md`). Task 16 is also open there and Step 11 covers it.
