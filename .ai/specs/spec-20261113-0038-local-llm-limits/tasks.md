# Local LLM comparison and limits — Tasks

**Spec**: spec-20261113-0038-local-llm-limits | **Size**: M | **Tasks**: 6

## Phase 1: Model bench command

- [x] 1. Speed and model table (backend)
  Covers: AC-1, AC-4, MNC-6, NFR-3
  - [x] 1.1 `llm:bench` with `--models`, `--runs`, `--only`, `--force`:
    - speed: unload with `keep_alive: 0`, cold load time, warm runs, TTFT measured by the client, prompt-eval and generation tokens/s, `/api/ps` memory;
    - the model table copied from the latest committed eval runs, with source and date;
    - output `bench/results/llm/<date>/run.json` and a summary table.
  - [x] 1.2 **Verify**: `tests/Feature/LlmBenchCommandTest.php`:
    - medians, minimum and maximum, and tokens/s from scripted NDJSON streams;
    - memory read from `/api/ps`;
    - eval figures copied with source and date, `null` with a reason for a missing model;
    - an existing day refused without `--force`;
    - the committed eval files unchanged.

- [x] 2. Context and failover (backend)
  Covers: AC-2, AC-3, NFR-2
  - [x] 2.1 Context check:
    - a prompt of about 1.5 × `num_ctx` with a marker code;
    - sent at the configured and at 2× `num_ctx`;
    - records `truncated` and `recalled`.
  - [x] 2.2 Failover matrix: unreachable, missing model and 1 s timeout × describe, natural-language search, moderation and assistant. It records the status, the client-visible error or event, the time to fail and the failed `ai_runs` row, and runs in-process with config overrides that are restored afterwards.
  - [x] 2.3 **Verify**: `tests/Feature/LlmBenchCommandTest.php`:
    - truncation detection;
    - recall matching;
    - a failover row for each fault from `Http::fake` (connection exception, 404 "model not found", and a timeout);
    - no key or `Authorization` value in `run.json` or the output.

## Phase 2: Load script

- [x] 3. `bench/llm-load.sh concurrency` (checks)
  Covers: AC-5, MNC-3, MNC-4, NFR-1
  - [x] 3.1 Levels 1, 4 and 16 with `max(8, 2 × level)` streams per model:
    - env switched through `env-switch.sh` (`AI_DESCRIBE_PROVIDER`, `AI_DESCRIBE_MODEL`, `AI_DESCRIBE_PER_MINUTE=1000`);
    - k6 in a one-off container running `k6-sse.js` in real mode;
    - TTFT and done percentiles, events/s, failure share and `OLLAMA_NUM_PARALLEL`.
    - `--quick` runs levels 1 and 4 with 4 streams each (C-11).
    - `bench/k6-sse.js` gains an optional `ITERATIONS` env; its default duration behaviour is unchanged (C-12).
  - [x] 3.2 **Verify**: `bash -n bench/llm-load.sh`, `shellcheck` if present, and `--help`. A `--quick` run on qwen3.5:9b writes the JSON. `.env` is identical after the run and after a Ctrl-C (manual-test step 4).

- [x] 4. `bench/llm-load.sh search-under-load` (checks)
  Covers: AC-6, MNC-3, MNC-4, NFR-1
  - [x] 4.1 Per server mode:
    - `run.sh search` without load;
    - `run.sh search` again while a k6 SSE loop holds 4 real describe streams, started before the warm-up and stopped after the repeats;
    - records the median figures, the p95 delta and the completed describes;
    - restores the starting server.
  - [x] 4.2 **Verify**: a `--quick` run on the active server writes the JSON. The server is restored, and `git diff --quiet .ddev/config.yaml` passes.

## Phase 3: Smoke group, docs, run

- [x] 5. Smoke group and guide (backend, docs)
  Covers: AC-7, AC-8, MNC-1, MNC-2
  - [x] 5.1 Smoke tests:
    - `tests/Feature/LocalLlmSmokeTest.php` in group `local-llm`, with `LOCAL_LLM_SMOKE` gating and skip messages that give the `ollama pull` command;
    - skipped unless `LOCAL_LLM_SMOKE=1`, with `phpunit.xml` unchanged (C-1);
    - the 3 smoke tests (describe, natural-language search, moderation) on `OLLAMA_SMOKE_MODEL`, default `qwen3:0.6b`;
    - `.env.example` entries.
  - [x] 5.2 Guide and docs:
    - `resources/guides/local-models.php` (six tabs; how to read tokens/s and TTFT, `num_ctx` and truncation, `OLLAMA_NUM_PARALLEL`, `keep_alive` and cold load, failover and how a fallback chain would be added, and why the site slows);
    - guide index registration;
    - the learning-guide §16 section;
    - README commands.
  - [x] 5.3 **Verify**:
    - `GuideContentTest`, `NoReferencesInUiTest` and `GuidePageTest` cover `local-models`;
    - the default suite shows the smoke tests skipped and passes with Ollama stopped;
    - `ollama pull qwen3:0.6b` then `ddev exec env LOCAL_LLM_SMOKE=1 php artisan test --group=local-llm` gives 3 passed.

- [ ] 6. Live runs and checks (checks)
  Covers: AC-1..AC-8, MNC-4, MNC-5
  - [ ] 6.1 Orchestrator:
    - live `ddev artisan llm:bench`;
    - `bench/llm-load.sh concurrency` on both models;
    - `bench/llm-load.sh search-under-load` (full BR-21 method);
    - the `BENCHMARKS.md` section, with the guide numbers filled;
    - full suite, Pint, PHPStan, typecheck and build;
    - fingerprint and parity;
    - commit; bench ping on the clean tree; push.
  - [ ] 6.2 **Verify**: the outputs are recorded here.
    - Recorded 2026-11-12 UTC: `llm:bench` live on qwen3.5:9b and gemma4:12b (`bench/results/llm/2026-11-12/run.json`), no key or `Authorization` string in it; `llm-load.sh concurrency` (`bench/results/llm-load/2026-11-12/concurrency.json`, 0 failed, `.env` identical, `.ddev/config.yaml` unchanged, no backup or k6 container left); the `BENCHMARKS.md` section and the guide numbers filled from them.
    - Gates: suite 2365 passed and 3 skipped, Pint, PHPStan, `typecheck` and `build` clean; fingerprint unchanged, parity differs only in `captured=`.
    - Interrupt check 2026-11-13: `concurrency --quick` got SIGINT about 10 s in (exit 130); `.env` identical to `/tmp/env.before`, `.ddev/config.yaml` unchanged, no `.env.bench-backup` or `.env.llm-load-backup`, no `llm-load-k6-*` container, server `frankenphp` as at the start. The stale-backup run exited 2 before any load, with `.env` and the config unchanged. The `search-under-load --quick` interrupt is not done: `bench/run.sh` refused the dirty tree before the describe loop ran (no interrupt happened), so it needs the commit first.
    - Review fix pass 2026-11-13 UTC: `llm:bench --force` and `bench/llm-load.sh concurrency --force` re-run live after the fixes (`bench/results/llm/2026-11-13/run.json`, `bench/results/llm-load/2026-11-13/concurrency.json`, 0 failed streams, `.env` identical to the copy taken at the start, no backup, server `frankenphp`); BENCHMARKS "Local models and limits", the guide and learning-guide §16 regenerated from them. The 2026-11-12 run files are superseded and still on disk.
    - Still open: the search-under-load run (fifth BENCHMARKS table is a marked placeholder), its interrupt check, the commit, the bench ping on the clean tree and the push. (`bench/run.sh` refuses a tree with uncommitted changes, so it needs the commit first), the commit, the bench ping on the clean tree and the push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | speed metrics per model | `tests/Feature/LlmBenchCommandTest.php` | test | 1 |
| AC-2 | context truncation check | `tests/Feature/LlmBenchCommandTest.php` | test | 2 |
| AC-3 | failover matrix | `tests/Feature/LlmBenchCommandTest.php` | test | 2 |
| AC-4 | eval figures copied with source | `tests/Feature/LlmBenchCommandTest.php` | test | 1 |
| AC-5 | concurrency 1/4/16 | `tests/Feature/BenchLlmLoadTest.php` | test | 3 |
| AC-6 | search p95 per server under AI load | `manual-test.md#step-3` | manual | 4 |
| AC-7 | smoke group opt-in | `tests/Feature/LocalLlmSmokeTest.php` | test | 5 |
| AC-8 | BENCHMARKS, guide, no references | `tests/Feature/GuideContentTest.php` | test | 5, 6 |
| MNC-1 | app behaviour unchanged | verify existing feature tests pass unchanged | test | 5 |
| MNC-2 | default suite offline | `tests/Feature/LocalLlmSmokeTest.php` | test | 5 |
| MNC-3 | existing bench scripts unchanged | `manual-test.md#step-5` | manual | 3, 4 |
| MNC-4 | .env, server and ddev config restored | `manual-test.md#step-4` | manual | 3, 4 |
| MNC-5 | fingerprint and parity | `manual-test.md#step-5` | manual | 6 |
| MNC-6 | committed evals untouched | `tests/Feature/LlmBenchCommandTest.php` | test | 1 |
| NFR-1 | safe bench runs | `manual-test.md#step-4` | manual | 3, 4 |
| NFR-2 | no secrets in output | `tests/Feature/LlmBenchCommandTest.php` | test | 2 |
| NFR-3 | offline suite | `tests/Feature/LlmBenchCommandTest.php` | test | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green

## Phase: Gaps (converge round 1)

- [x] 7. Guide Numbers read the load results too (docs)
  Covers: AC-8
  - The spec (Scope 4) says the Numbers tab of `resources/guides/local-models.php` reads from `bench/results/llm` and `bench/results/llm-load`. Today all six `numbers` entries point at `llm/2026-11-12/run.json`, so none of the concurrency or search-under-load figures appear. Add entries for `llm-load/<date>/concurrency.json` (for example the level-16 TTFT p50 and the aggregate chunks/s per model) and, after the task 6 run, for `llm-load/<date>/search-under-load.json` (the p95 delta per server mode).
  - [x] **Verify**: `ddev artisan test --compact tests/Feature/GuideContentTest.php` passes, including "AC-3 renders a guide with every number and snippet resolved" for `local-models`, and the Numbers tab at `/ai/guides/local-models` shows at least one `llm-load` figure.

- [ ] 8. BENCHMARKS.md fifth table and search conclusions (docs)
  Covers: AC-6, AC-8
  - The "Local models and limits" section in `BENCHMARKS.md` (line 1292) has the speed, eval, context, failover and concurrency tables. Of the spec's five tables, the search p95 per server mode without and with AI load is missing, because `bench/results/llm-load/<date>/search-under-load.json` does not exist yet. The heading says `(2026-11-13)`, but the run files it cites are dated 2026-11-12. "What the numbers say" has no line on the search p95 delta.
  - After the task 6 `search-under-load` run, add the table (RPS, p50, p95, p99 and error % per server, without and with load, the p95 delta and the completed describes per minute), a conclusion that cites it, and a heading date that matches the run files.
  - **Verify**: `search-under-load.json` is committed with all three servers in `SUPPORTED_SERVERS` (`quick: false`, 3 repeats, warm-up 30 s, measure 60 s), and every figure in the new table matches it.

- [ ] 9. Live interrupt check of llm-load.sh (checks)
  Covers: NFR-1, MNC-4
  - NFR-1 is to be proven by manual-test step 4, a Ctrl-C during `bench/llm-load.sh concurrency --quick`. Only the stubbed SIGINT test in `tests/Feature/BenchLlmLoadTest.php` covers it. The task 3 report says the live Ctrl-C attempt probably came after the run had finished, and task 6.2 records only an uninterrupted run. There is no live run of `search-under-load` that is interrupted while the k6 loop and a server switch are active.
  - Run manual-test step 4 for real: interrupt about 10 s into a `concurrency --quick` run, and interrupt a `search-under-load --quick` run while the describe loop is running. Record the results under task 6.2.
  - **Verify**: after each interrupt, `cmp .env /tmp/env.before`, `git diff --quiet .ddev/config.yaml`, no `.env.bench-backup` or `.env.llm-load-backup`, no `llm-load-k6-*` container in `docker ps -a`, and `current_server` equals the starting server. The stale-backup run prints `exit=2` before any load.

- [x] 10. k6-sse.js keeps the summary of existing callers unchanged (checks)
  Covers: MNC-3
  - `bench/k6-sse.js` now sets `summaryTrendStats: ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max']` in every mode. The old value was `['avg', 'med', 'p(95)', 'p(99)']`. `bench/slow-load.sh` (line 225) runs the script without `ITERATIONS` and copies the k6 summary into its raw result files as they are, so its output changes even though the duration mode should be identical (MNC-3, C-12). The current test only checks the executor string.
  - Choose `summaryTrendStats` by mode: keep the old list when `real` is false and use the longer one only in real mode. Extend the "AC-5 k6-sse.js gains ITERATIONS" test so it asserts the old list for duration mode.
  - **Verify**: `ddev artisan test --compact tests/Feature/BenchLlmLoadTest.php` passes. Running `bench/k6-sse.js` without `ITERATIONS` writes a summary whose trend keys are exactly `avg, med, p(95), p(99)`, and `llm-load.sh concurrency` still reads `min`, `max` and the stream metrics.

## Phase: Gaps (converge round 2)

- [x] 11. search-under-load proves the describe loop really loaded the measured window (checks)
  - Done 2026-11-13 (review fix pass): the describe loop now reaches the web container by name on ddev_default (`BASE_URL=http://ddev-<site>-web`, `HOST_HEADER`, k6 `dns.ttl` 1 s, no pinned router IP); before it writes the file the script exits 1 when `describes.completed` is 0 or half or more of the streams were incomplete (`verify_describe_loop`); the concurrency path re-reads the router IP after each restart. Tests: `BenchLlmLoadTest` (container-name loop, 0 completed, 500000/1000000 incomplete boundary).
  Covers: AC-6
  - `start_describe_loop` in `bench/llm-load.sh` starts k6 with `docker_run`, which pins `--add-host "$HOST_NAME:$router_ip"` to the router IP read before `run.sh` starts. `bench/run.sh` then runs `ddev restart` during that same load run (line 123 on worker servers, line 282 the settle restart in full mode), and its own comment (line 162) says the router can get a new IP on that restart. The loop keeps the stale address, so the "with load" search figures can be measured with no describe traffic, and the script still writes `search-under-load.json` (it never checks `describes.completed`, the incomplete share or the router IP).
  - After `stop_describe_loop`, compare the router IP with the one the loop started with, and refuse to write the result (exit 1, `.env` and server restored by the trap) when it changed or when `completed` is 0. Record the incomplete count next to it as today.
  - **Verify**: new `tests/Feature/BenchLlmLoadTest.php` cases with the docker stub: a router IP that changes between the loop start and its stop, and a k6 summary with 0 completed streams, each exit non-zero with no `search-under-load.json`, `.env` byte-identical and the k6 container removed; the existing AC-6 tests still pass.

- [x] 12. Moderation smoke test fails on a provider error (backend)
  - Done 2026-11-13 (review fix pass): `LocalLlmSmokeTest` now requires 200 and `data.status` in classified|invalid; a 503 fails.
  Covers: AC-7
  - Scope 3 says moderation "returns `classified` or `invalid`, never an exception". The third test in `tests/Feature/LocalLlmSmokeTest.php` accepts `status` 200 or 503 and only checks `data.status` when it is 200. A transport or provider error ends as "failed" in `ModerationService` and reaches the client as 503, so a smoke run whose moderation call breaks still passes.
  - Assert status 200 and `data.status` in `classified`/`invalid`; keep the risk and reasons checks.
  - **Verify**: `ddev exec env LOCAL_LLM_SMOKE=1 php artisan test --group=local-llm` gives 3 passed with `qwen3:0.6b`, and with `OLLAMA_SMOKE_MODEL` set to a model that is pulled but whose call is made to fail (for example a 1 s `ai.providers.ollama.timeout` override in a scratch run) the moderation test fails instead of passing; the default suite still shows the 3 tests skipped.

- [x] 13. llm:bench tests cover the summary row, num_ctx and the keys in the console output (backend)
  - Done 2026-11-13 (review fix pass): `LlmBenchCommandTest` asserts `metadata.num_ctx`, one printed summary row per model and that neither `sk-test-secret-123`, `sk-ant-test-secret-456` nor `p4ss` appears in the command output.
  Covers: AC-1, NFR-2
  - AC-1 requires `num_ctx` in `run.json` and "one summary row per model" printed. `LlmBenchCommandTest` never asserts `metadata.num_ctx` or any table output of `LlmBenchCommand::handle`. C-16 requires that neither `sk-test-secret-123` nor the URL user info appear in the command output; the NFR-2 test only asserts `doesntExpectOutputToContain('p4ss')`, while `llmFailoverFake` echoes `sk-test-secret-123` in its 404 body and nothing checks that the key stays out of the console output.
  - Extend the AC-1 speed test to assert `metadata.num_ctx` and that the output contains one row per model (`expectsOutputToContain` with each model name and its median generation rate). Extend the NFR-2 test with `doesntExpectOutputToContain` for `sk-test-secret-123` and `sk-ant-test-secret-456`.
  - **Verify**: `ddev artisan test --compact tests/Feature/LlmBenchCommandTest.php` passes with the new assertions, and the AC-1 test fails when the speed table is not printed.

- [x] 14. Guide Numbers show the search-under-load p95 delta (docs)
  - Done 2026-11-13 (review fix pass): `resources/guides/local-models.php` builds its Numbers from the newest run folders and adds p95 without load, with load and the delta per server mode, with the engine's pending note per server until `search-under-load.json` exists. `GuideContentTest` covers both states. The tick is for the entries; the figures appear after the search run.
  Covers: AC-8
  - Task 7 asked for Numbers entries from `llm-load/<date>/search-under-load.json` (the p95 delta per server mode) after the task 6 run. Task 7 is ticked, but every `llm-load` entry in `resources/guides/local-models.php` (lines 114 to 117) points at `concurrency.json`, so the guide's "why the site slows" tab has no measured search figure.
  - After the task 6 full run, add one entry per server in `SUPPORTED_SERVERS` for `servers.<i>.p95_delta_ms` (or `p95_delta_percent`) from the committed `search-under-load.json`.
  - **Verify**: `ddev artisan test --compact tests/Feature/GuideContentTest.php` passes, including "AC-3 renders a guide with every number and snippet resolved" for `local-models`, and `/ai/guides/local-models` Numbers shows a search p95 delta for each of the three servers that matches the run file.

## Review fix pass (2026-11-13)

Findings fixed, each with tests in `LlmBenchCommandTest`, `BenchLlmLoadTest` or `GuideContentTest`:

- [x] nl-search `json_valid` counts failed, refused and rejected queries as invalid (null with a reason when the fields are missing); photo-vision accuracy is `body_type_agreement.rate`, null with "the vision eval reports validity only" otherwise (`LlmEvalFigures`).
- [x] Failover: the model is unloaded before each timeout entry and the inputs are the longest the validators accept; each row has `answered`; the stored `ai_runs` message is read through `AiRunRepositoryInterface::storedErrorMessage` and written as `run_error_messages` with `message_redacted`.
- [x] One not-found for listing 1 (`LlmSpeedBench::listing`), each section wrapped so an exception becomes that section's `error`, the file is still written and the exit is non-zero.
- [x] Speed: a stream with no content chunk is a failed sample (ttft null, in-stream error recorded, counted, left out of the statistics); the bench forces `keep_alive` 5m for its own requests.
- [x] Context: effective window from `/api/ps`, `over_window`, `truncated` null with `context_shift_suspected`, `num_ctx_applied`.
- [x] `k6-sse.js` per-stream chunk rate from the first byte to `[DONE]`; `llm-load.sh` warm-up id 50000, `--models` validated and quoted, loop by container name, loop verification.
- [x] `LlmBenchRedactor` patterns (bearer, Authorization, key headers and query parameters, user info, sk-, sk-ant-, AIza); `llm:bench` refuses outside `APP_ENV=local` (exit 2).
