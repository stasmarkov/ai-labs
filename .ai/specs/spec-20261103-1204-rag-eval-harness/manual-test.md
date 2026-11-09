# Manual test plan: RAG evaluation harness (SL-33)

What this slice adds: `ddev artisan rag:eval`, which measures docs retrieval (recall@k, MRR), calibrates the relevance threshold, and can run the 30 eval questions through the chatbot and have a second model grade the answers. It also makes the chunk sizes configurable and adds a non-streaming `DocsAnswerService::answer()`.

## Preconditions

- Work from `/Users/stasmarkov/work/interview`; DDEV is running (`ddev start`) and the server is nginx-fpm (`ddev server nginx-fpm`).
- Ollama is running with `qwen3.5:9b`, `gemma4:12b` and `bge-m3` pulled (`ollama list`).
- The Laravel docs are indexed and embedded, and the migration has run: `ddev artisan migrate --force`, then `ddev artisan docs:search flushpackets --k=1` prints a row.
- `.env` has no `AI_DOCS_CHUNK_*` lines (defaults 500/300) and `AI_PROVIDER` points at the real Ollama provider for steps 3, 7 and 8.
- Before starting, note your `.env` so step 4 can be undone: `cp .env /tmp/env.before-sl33`.
- Result folders from these steps use the label `check*` under `bench/results/rag-eval/<today UTC>/`. Delete them when done (see Rollback).

## Step 1: eval set is valid and clean (AC-1, AC-2)

```sh
ddev artisan test --compact --filter=RagEval
head -c 700 bench/rag-eval/questions.v1.jsonl
wc -l bench/rag-eval/questions.v1.jsonl
grep -c '"type":"on_topic"' bench/rag-eval/questions.v1.jsonl
grep -c '"type":"off_topic"' bench/rag-eval/questions.v1.jsonl
```

Expected:
- Tests green, including the evidence test (not skipped, so the docs are on disk) and the contamination test.
- The first line is JSON with `id`, `type`, `question`, `answer`, `expected` (doc plus `evidence` phrases).
- `wc -l` prints 30, the on-topic count 24, the off-topic count 6.
- Spot check one phrase: take the first line's evidence `use the withoutOverlapping method` and run `grep -ril "use the withoutOverlapping method" storage/app 2>/dev/null; ddev artisan docs:search "withoutOverlapping" --k=1`. It must appear in `scheduling.md`.

## Step 2: recall and calibration, twice (AC-3, AC-4, NFR-1, NFR-2)

```sh
time ddev artisan rag:eval --k=3,6,10 --modes=vector,text,hybrid --label=check
cp -r bench/results/rag-eval/$(date -u +%F)/check /tmp/check-run1
ddev artisan rag:eval --k=3,6,10 --modes=vector,text,hybrid --label=check --force
```

Expected:
- A recall table with 9 rows (3 k values x vector, text, hybrid), each with recall, MRR and missed question ids. Reference values from the committed run: vector@6 0.875 and hybrid@6 0.875; text is 0/24 at every k (explained under "Surprises" in `BENCHMARKS.md`).
- A calibration block: min, median, max similarity for on-topic and off-topic; best threshold about 0.581951 with TPR 1 and FPR about 0.167; TPR and FPR at the configured 0.5.
- `recall.json` and `calibration.json` exist in `bench/results/rag-eval/<date>/check/`. `recall.json` metadata lists chunk settings 500/300, embedding model, eval-set version and git commit.
- Real time under 30 s (about 4 s when Ollama is warm; run once before timing if the model is cold).
- No chat model loads during this step (`ollama ps` shows only `bge-m3`).
- Second run: `diff <(jq 'del(.metadata.started_at)' /tmp/check-run1/recall.json) <(jq 'del(.metadata.started_at)' bench/results/rag-eval/$(date -u +%F)/check/recall.json)` prints nothing, same for `calibration.json`. If a field other than the timestamp differs, note its name.
- Running without `--force` on the same label exits 1 and says the label exists.

## Step 3: answers and grading (AC-5, AC-6)

```sh
ddev artisan rag:eval --k=6 --modes=hybrid --answer --models=qwen3.5:9b --grader=gemma4:12b --label=check-answers
jq '.rows[0]' bench/results/rag-eval/$(date -u +%F)/check-answers/answers.json
```

Expected (takes several minutes):
- One report row for `qwen3.5:9b`: mean correctness and groundedness on the 0 to 2 scale, citation rate, declined correctly n/6, false don't-know n/24, failed count, ungraded count, median latency and median TTFT (both positive, milliseconds). Reference from the committed run: correctness about 1.97, groundedness about 2.00, cited about 0.92, declined 5/6, false don't-know 0/24.
- `answers.json` has 30 rows. Each has `invocation_id`, `grader_invocation_id`, outcome, correctness, groundedness and a grader `reason` of at most 300 characters. Off-topic rows that were declined show correctness 2; ungraded rows (if any) carry a failure class and are not in the means.
- Optional repeat: add `--repeat=2` and confirm the report shows the median of run means and a range.

## Step 4: chunk size setting and mismatch guard (AC-7)

1. Edit `.env`: add `AI_DOCS_CHUNK_MAX_TOKENS=300` and `AI_DOCS_CHUNK_MERGE_BELOW_TOKENS=180`. Then `ddev artisan config:clear`.
2. Before re-indexing run `ddev artisan rag:eval --k=6 --modes=hybrid --label=check-mismatch; echo "exit=$?"`.
   Expected: `The index was built with chunk sizes 500/300 but the config says 300/180.`, then `Run docs:index to match the config, or pass --force ...`, `exit=1`, and no `check-mismatch` folder.
3. Same command with `--force`. Expected: the warning, then a normal run with exit 0; the result files still record 500/300 (the index, not the config).
4. Run `ddev artisan docs:index`, wait until embedding finishes (check `ddev artisan queue:work --stop-when-empty` or your worker), then `ddev artisan rag:eval --k=3,6,10 --modes=vector,text,hybrid --label=check-300`.
   Expected: no warning; `recall.json` records chunk settings 300/180; the committed `recall` numbers for 300/180 are in `bench/results/rag-eval/2026-11-03/answers-qwen-300-180/recall.json` for comparison.
5. Restore: `cp /tmp/env.before-sl33 .env`, `ddev artisan config:clear`, `ddev artisan docs:index`, wait for embedding, and repeat step 2's first command. Expected: back to 500/300 with no warning, and the same recall values as before.

## Step 5: committed results and BENCHMARKS section (AC-9)

```sh
ls bench/results/rag-eval/2026-11-03/
grep -n "^## RAG evaluation (2026-11-03)" BENCHMARKS.md
sed -n '/^## RAG evaluation (2026-11-03)/,/^## /p' BENCHMARKS.md | grep -n "^###"
grep -n "min_similarity" -B6 config/ai.php | head -20
git status --short bench/results/rag-eval
```

Expected:
- Four folders: `recall-500-300`, `answers-qwen-500-300`, `answers-gemma-500-300`, `answers-qwen-300-180`.
- The section exists with subsections for tables, What the numbers say, Surprises and Interview story. Tables show question ids only, never question text. The interview story quotes at least one measured number (for example recall@6 0.875 or TPR 1 / FPR 0.167). Pick two table values and confirm them with `jq` in the matching `recall.json`.
- `config/ai.php` keeps the default 0.5 and the comment explains why with the measured TPR/FPR; `.env.example` says the same.
- `git status` shows these result files tracked and clean once the slice is committed. (At review time they were untracked, so this check fails until the commit is made.)

## Step 6: fingerprint, parity, ask stream (MNC-1, MNC-3, MNC-4)

```sh
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do ddev server $s >/dev/null; BENCH_PARITY_DIR=/tmp/sl33 bench/parity.sh capture >/dev/null; bench/parity.sh compare bench/parity/$s.txt /tmp/sl33/$s.txt; done
ddev server default
ddev artisan test --compact
```

Expected:
- The fingerprint lines equal the ones in `BENCHMARKS.md`.
- The word `identical` prints six times, one per server. (Only nginx-fpm was checked at review time; the other five still need running.)
- Full test suite passes offline (about 677 tests).

## Step 7: the endpoint and search still work (MNC-1, MNC-3)

```sh
curl -sN -i -H 'Content-Type: application/json' -d '{"q":"how does rate limiting work"}' https://interview.ddev.site/api/docs/ask | head -20
curl -s -o /dev/null -w '%{http_code}\n' 'https://interview.ddev.site/api/docs/search?q=flushpackets'
ddev artisan docs:search flushpackets --k=1
git diff HEAD -- tests/Feature/DocsAskTest.php | wc -l
```

Expected:
- Ask: `HTTP/2 200`, `content-type: text/event-stream`, `x-ratelimit-limit: 10`, and the first event is `event: sources`, followed by token events and a final done event.
- Search: `200`. The CLI search prints one row.
- The diff line count is `0` (the ask test is unedited).
- Open https://interview.ddev.site/ai/docs in a browser, ask "how does rate limiting work": sources appear, then an answer with citations streams in. Click the run link; https://interview.ddev.site/ai/runs/<id> loads with agent `DocsAnswerAgent`.

## Step 8: invocation ids lead to real run rows (AC-8)

Take an id from step 3 and check both the page and the row:

```sh
ID=$(jq -r '.rows[0].invocation_id' bench/results/rag-eval/$(date -u +%F)/check-answers/answers.json)
curl -s -o /dev/null -w '%{http_code}\n' https://interview.ddev.site/ai/runs/$ID
ddev mysql -e "select agent, model, status, ttft_ms, prompt_version from ai_runs where invocation_id='$ID'"
```

Expected: `200`; one row with agent `DocsAnswerAgent`, model `qwen3.5:9b`, status `completed`, a positive `ttft_ms`, prompt version `docs-answer@v1` (the same shape as a row written by the ask endpoint in step 7).

Failed-run check (known open defect, task 14): when the model stream returns an in-stream error, `answer()` reports outcome `failed` with an invocation id, and a `failed` `ai_runs` row must exist for that id. Reproduce by running step 3 with `--models=no-such-model:1`: the command should stop (exit 1, no files). Then look at `select invocation_id, status from ai_runs order by id desc limit 3`. Expected after the fix: a `failed` row for the attempt. At review time this row was missing; record what you see.

## Step 9: error exits (AC-11)

```sh
ddev artisan rag:eval --questions=bench/rag-eval/nope.jsonl; echo "exit=$?"
head -3 bench/rag-eval/questions.v1.jsonl > /tmp/bad.jsonl; echo '{not json' >> /tmp/bad.jsonl
ddev artisan rag:eval --questions=/tmp/bad.jsonl; echo "exit=$?"
ddev artisan rag:eval --k=6 --modes=hybrid --label=check-nomodel --answer --models=no-such-model:1; echo "exit=$?"
ddev exec env OLLAMA_URL=http://127.0.0.1:1 php artisan rag:eval --k=6 --label=check-down; echo "exit=$?"
ddev artisan rag:eval --k=6 --modes=hybrid --answer --grader=no-such-grader:1 --label=check-nograder; echo "exit=$?"
ls bench/results/rag-eval/$(date -u +%F)/ | grep -E 'nomodel|down|nograder|nope'
```

Expected:
- Missing file: `Eval set not found: ...`, `exit=1`.
- Bad file: `Eval set line 4: invalid JSON (...)`, `exit=1`.
- Unknown answer model: exit 1, a message, no folder. The message should name `no-such-model:1`; today it only says "Docs answering is unavailable right now." (open item, task 15).
- Embedding down: exit 1, no folder. The message should mention the embedding model; today it says "Docs search is unavailable." (task 15).
- Unknown grader: exit 1, the message names `no-such-grader:1`, no folder.
- The final `ls | grep` prints nothing (no partial result folders, no `.tmp-*`).
- No chunks case: skip live; it is covered by `RagEvalCommandTest` and prints "run docs:index first".

## Rollback and cleanup

- Remove check output: `rm -rf bench/results/rag-eval/$(date -u +%F)/check* /tmp/check-run1 /tmp/bad.jsonl /tmp/sl33`.
- Restore settings: `cp /tmp/env.before-sl33 .env && ddev artisan config:clear`, then `ddev artisan docs:index` and wait for the embeddings (step 4.5).
- Leave the server on the default server: `ddev server default`.
- The new migration only adds nullable chunk-setting columns to `docs_documents`; if needed, `ddev artisan migrate:rollback --step=1` removes them.
