# Local LLM comparison and limits — Tasks

**Spec**: spec-20261113-0038-local-llm-limits | **Size**: M | **Tasks**: 6

## Phase 1: Model bench command

- [ ] 1. Speed and model table (backend)
  Covers: AC-1, AC-4, MNC-6, NFR-3
  - [ ] 1.1 `llm:bench` with `--models`, `--runs`, `--only`, `--force`:
    - speed: unload with `keep_alive: 0`, cold load time, warm runs, TTFT measured by the client, prompt-eval and generation tokens/s, `/api/ps` memory;
    - the model table copied from the latest committed eval runs, with source and date;
    - output `bench/results/llm/<date>/run.json` and a summary table.
  - [ ] 1.2 **Verify**: `tests/Feature/LlmBenchCommandTest.php`:
    - medians, minimum and maximum, and tokens/s from scripted NDJSON streams;
    - memory read from `/api/ps`;
    - eval figures copied with source and date, `null` with a reason for a missing model;
    - an existing day refused without `--force`;
    - the committed eval files unchanged.

- [ ] 2. Context and failover (backend)
  Covers: AC-2, AC-3, NFR-2
  - [ ] 2.1 Context check:
    - a prompt of about 1.5 × `num_ctx` with a marker code;
    - sent at the configured and at 2× `num_ctx`;
    - records `truncated` and `recalled`.
  - [ ] 2.2 Failover matrix: unreachable, missing model and 1 s timeout × describe, natural-language search, moderation and assistant. It records the status, the client-visible error or event, the time to fail and the failed `ai_runs` row, and runs in-process with config overrides that are restored afterwards.
  - [ ] 2.3 **Verify**: `tests/Feature/LlmBenchCommandTest.php`:
    - truncation detection;
    - recall matching;
    - a failover row for each fault from `Http::fake` (connection exception, 404 "model not found", and a timeout);
    - no key or `Authorization` value in `run.json` or the output.

## Phase 2: Load script

- [ ] 3. `bench/llm-load.sh concurrency` (checks)
  Covers: AC-5, MNC-3, MNC-4, NFR-1
  - [ ] 3.1 Levels 1, 4 and 16 with `max(8, 2 × level)` streams per model:
    - env switched through `env-switch.sh` (`AI_DESCRIBE_PROVIDER`, `AI_DESCRIBE_MODEL`, `AI_DESCRIBE_PER_MINUTE=1000`);
    - k6 in a one-off container running `k6-sse.js` in real mode;
    - TTFT and done percentiles, events/s, failure share and `OLLAMA_NUM_PARALLEL`.
    - `--quick` runs levels 1 and 4 with 4 streams each (C-11).
    - `bench/k6-sse.js` gains an optional `ITERATIONS` env; its default duration behaviour is unchanged (C-12).
  - [ ] 3.2 **Verify**: `bash -n bench/llm-load.sh`, `shellcheck` if present, and `--help`. A `--quick` run on qwen3.5:9b writes the JSON. `.env` is identical after the run and after a Ctrl-C (manual-test step 4).

- [ ] 4. `bench/llm-load.sh search-under-load` (checks)
  Covers: AC-6, MNC-3, MNC-4, NFR-1
  - [ ] 4.1 Per server mode:
    - `run.sh search` without load;
    - `run.sh search` again while a k6 SSE loop holds 4 real describe streams, started before the warm-up and stopped after the repeats;
    - records the median figures, the p95 delta and the completed describes;
    - restores the starting server.
  - [ ] 4.2 **Verify**: a `--quick` run on the active server writes the JSON. The server is restored, and `git diff --quiet .ddev/config.yaml` passes.

## Phase 3: Smoke group, docs, run

- [ ] 5. Smoke group and guide (backend, docs)
  Covers: AC-7, AC-8, MNC-1, MNC-2
  - [ ] 5.1 Smoke tests:
    - `tests/Feature/LocalLlmSmokeTest.php` in group `local-llm`, with `LOCAL_LLM_SMOKE` gating and skip messages that give the `ollama pull` command;
    - skipped unless `LOCAL_LLM_SMOKE=1`, with `phpunit.xml` unchanged (C-1);
    - the 3 smoke tests (describe, natural-language search, moderation) on `OLLAMA_SMOKE_MODEL`, default `qwen3:0.6b`;
    - `.env.example` entries.
  - [ ] 5.2 Guide and docs:
    - `resources/guides/local-models.php` (six tabs; how to read tokens/s and TTFT, `num_ctx` and truncation, `OLLAMA_NUM_PARALLEL`, `keep_alive` and cold load, failover and how a fallback chain would be added, and why the site slows);
    - guide index registration;
    - the learning-guide §16 section;
    - README commands.
  - [ ] 5.3 **Verify**:
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

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | speed metrics per model | `tests/Feature/LlmBenchCommandTest.php` | test | 1 |
| AC-2 | context truncation check | `tests/Feature/LlmBenchCommandTest.php` | test | 2 |
| AC-3 | failover matrix | `tests/Feature/LlmBenchCommandTest.php` | test | 2 |
| AC-4 | eval figures copied with source | `tests/Feature/LlmBenchCommandTest.php` | test | 1 |
| AC-5 | concurrency 1/4/16 | `manual-test.md#step-2` | manual | 3 |
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
