# RAG evaluation harness — Tasks

**Spec**: spec-20261103-1204-rag-eval-harness | **Size**: M | **Tasks**: 9

## Phase 1: Backend

- [x] 1. Chunk size as config (backend)
  Covers: AC-7, MNC-2
  - [x] 1.1 `config('ai.docs.chunk.max_tokens')` (500) and `merge_below_tokens` (300) with env overrides and `.env.example`; `MarkdownChunker` takes them (constructor or method arguments), the defaults reproduce today's chunks
  - [x] 1.2 The document hash includes the chunk settings; `docs_documents` records the settings used (nullable columns `chunk_max_tokens`, `chunk_merge_below_tokens`) so `rag:eval` can read them
  - [x] 1.3 **Verify**: chunker tests unchanged and green; new test: max 300 gives no paragraph chunk over 300 estimated tokens; changing the setting changes the hash

- [x] 2. Shared answer pipeline and `answer()` (backend)
  Covers: AC-8, MNC-1
  - [x] 2.1 Extract the guard → retrieval → gate → budget → agent steps so `ask()` (SSE) and `answer(question, model override)` share them; output guard and citation parsing shared with `DocsAnswerProtocol`
  - [x] 2.2 `DocsAnswerResult` DTO (outcome, text, sources, cited ids, invocation id)
  - [x] 2.3 **Verify**: `DocsAskTest` green unedited; new `DocsAnswerResultTest` for each outcome with the fake provider, including the model override and the `ai_runs` row

- [x] 3. Eval set and its checks (data, tests)
  Covers: AC-1, AC-2
  - [x] 3.1 Write `bench/rag-eval/questions.v1.jsonl` (24 on-topic: ≥ 16 Laravel, ≥ 6 repo; 6 off-topic; tags paraphrase ≥ 4, identifier ≥ 3), evidence copied from the files
  - [x] 3.2 `RagEvalSet` loader with line-numbered validation errors
  - [x] 3.3 **Verify**: `RagEvalSetTest` (format, counts, evidence present in the docs on disk, the Laravel docs skipped with a reason when `storage/app/docs/laravel` is absent) and `RagEvalContaminationTest` (no question or answer in any `DocsCorpus` file)

- [x] 4. Recall@k, MRR and calibration (backend)
  Covers: AC-3, AC-4, NFR-2
  - [x] 4.1 `RagRetrievalEvaluator`: embed each question once, search each mode once at the largest k and cut (exact for k ≤ rrf_depth; larger k rejected), hit rule from Decision 1, recall and MRR, missed ids
  - [x] 4.2 `ThresholdCalibrator`: on/off-topic stats, Youden's J over observed values (ties → higher t), TPR/FPR at the configured threshold
  - [x] 4.3 **Verify**: unit tests with hand-made similarities and hits (J, ties, MRR 1/rank, recall with mixed hits)

- [x] 5. Grader agent (backend)
  Covers: AC-5, AC-6
  - [x] 5.1 `resources/prompts/rag-grader.v1.md`, `RagGraderAgent` with `HasStructuredOutput`, the model from `--grader`, one retry, `ungraded` on shape failure; logged in `ai_runs`
  - [x] 5.2 **Verify**: tests with the SDK fake for valid output, invalid twice → ungraded, retry then valid

- [x] 6. `rag:eval` command and result files (backend)
  Covers: AC-3, AC-4, AC-5, AC-7, AC-11, NFR-1, NFR-2
  - [x] 6.1 Signature from the spec; stages recall → calibration → answers; tables in the console; `recall.json`, `calibration.json`, `answers.json` written atomically to `bench/results/rag-eval/<UTC date>/<label>/` (label exists → exit unless `--force`) with metadata (chunk settings from the index, embedding model, models, prompt versions, eval-set version, git commit)
  - [x] 6.2 Off-topic and no_context scoring, citation rate, medians from `ai_runs`, repeat median/range; chunk-settings mismatch warning with `--force`; error exits
  - [x] 6.3 **Verify**: `RagEvalCommandTest` with fakes: recall-only run, answer run with a fake grader, invalid file line, no chunks, embedding down, mismatch refusal, reproducible recall output

## Phase 2: Measure and document

- [x] 7. Committed run and calibrated default (measurement)
  Covers: AC-9, NFR-1
  - [x] 7.1 On nginx-fpm with Ollama warm: recall + calibration at 500/300; answers qwen (graded by gemma) and gemma (graded by qwen), `--repeat=3`
  - [x] 7.2 Re-index at 300/180, recall + calibration + qwen answers `--repeat=1`; restore 500/300 and re-index
  - [x] 7.3 Apply Decision 4 to the threshold; update `config/ai.php`, `.env.example` and any test that pins 0.5 with the reason
  - [x] 7.4 **Verify**: result JSON committed; numbers in the next task match the files

- [x] 8. BENCHMARKS.md and guide section 15 (docs)
  Covers: AC-9, AC-10, AC-2
  - [x] 8.1 `## RAG evaluation (2026-11-03)` with tables, What the numbers say, Surprises, Interview story; question ids only
  - [x] 8.2 Guide section 15 per AC-10
  - [x] 8.3 **Verify**: `LearningGuideTest` and `BenchmarksDocTest` needles; `RagEvalContaminationTest` still green with the new text

- [ ] 9. Parity, fingerprint and bench (checks)
  Covers: MNC-3, MNC-4
  - [ ] 9.1 Full suite, Pint, PHPStan, typecheck, build green; fingerprint equals BENCHMARKS.md; parity identical on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [ ] 9.2 **Verify**: outputs recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | 30 valid lines, evidence present | `tests/Feature/RagEvalSetTest.php` | test | 3 |
| AC-2 | no eval text in indexed files | `tests/Feature/RagEvalContaminationTest.php` | test | 3, 8 |
| AC-3 | recall@k and MRR per k × mode | `tests/Unit/RagRetrievalEvaluatorTest.php`, `manual-test.md#step-2` | test | 4, 6 |
| AC-4 | calibration with Youden's J | `tests/Unit/ThresholdCalibratorTest.php` | test | 4, 6 |
| AC-5 | answers graded, scores and medians | `tests/Feature/RagEvalCommandTest.php`, `manual-test.md#step-3` | test | 5, 6 |
| AC-6 | grader shape, retry, ungraded | `tests/Feature/RagGraderAgentTest.php` | test | 5 |
| AC-7 | chunk size config and mismatch guard | `tests/Unit/MarkdownChunkerTest.php`, `manual-test.md#step-4` | test | 1, 6 |
| AC-8 | shared non-streaming answer path | `tests/Feature/DocsAnswerResultTest.php`, `manual-test.md#step-8` | test | 2 |
| AC-9 | committed run, BENCHMARKS section, default | `manual-test.md#step-5` | manual | 7, 8 |
| AC-10 | guide section 15 | `tests/Feature/LearningGuideTest.php` | test | 8 |
| AC-11 | error exits | `tests/Feature/RagEvalCommandTest.php`, `manual-test.md#step-9` | test | 6 |
| MNC-1 | ask endpoint unchanged | `tests/Feature/DocsAskTest.php`, `manual-test.md#step-7` | test | 2 |
| MNC-2 | default chunks unchanged | `tests/Unit/MarkdownChunkerTest.php` | test | 1 |
| MNC-3 | search commands and pages unchanged | `tests/Feature/DocsSearchTest.php`, `manual-test.md#step-7` | test | 9 |
| MNC-4 | fingerprint, parity, offline suite | `manual-test.md#step-6` | manual | 9 |
| NFR-1 | recall stage < 30 s | `manual-test.md#step-2` | manual | 7 |
| NFR-2 | reproducible recall with metadata | `tests/Feature/RagEvalCommandTest.php` | test | 6 |

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

- [x] 10. answer() drains the SDK stream through the shared protocol steps (backend)
  Covers: AC-8, AC-5
  - [x] 10.1 `DocsAnswerService::answer()` calls `$agent->prompt()`, against Clarification Q3 ("drains the SDK stream (`stream()`), never `prompt()`"). Its row is written by the new `AiRunRecorder::recordRun()` with `ttftMs: null`, so the row is not "identical in kind" to the streamed one, and `median_ttft_ms` is always null. Also, `answer()` has its own copy of the output-guard leak check, the `output:` refusal reason and the citation-to-id mapping, which `DocsAnswerProtocol::parts()` already has. Change `answer()` to drain `$agent->stream(...)` with the model override. The `StreamingAgent`/`AgentStreamed` listeners (`RecordAiRun`) then write the row with TTFT. Take the leak check, the refusal and the cited ids from one step that the protocol and `answer()` both call, so nothing is duplicated
  - [x] 10.2 **Verify**: `DocsAnswerResultTest` asserts that the `DocsAnswerAgent` row from `answer()` has a non-null `ttft_ms` and the same agent, prompt version, status values and id columns as a row from `ask()` for the same question. A `RagEvalCommandTest` answer run asserts that `median_ttft_ms` is not null. `DocsAskTest` stays green and unedited

- [x] 11. `failed` outcome and spec outcome names in DocsAnswerResult (backend)
  Covers: AC-8, AC-5
  - [x] 11.1 AC-8 lists the outcomes `completed`, `no_context`, `refused` and `failed`. `app/Enums/DocsAnswerOutcome.php` has `answered`, `refused_input`, `refused_output` and `no_context`, and has no `failed`: `answer()` throws `DocsAnswerUnavailableException` on any model error. As a result the Q2 row "any `failed` → excluded from both means, counted 'failed'" is not implemented: `RagModelReport::summarise()` has no failed count, and one transient error aborts the whole run. Fix: return a `Failed` result that carries the invocation id of the failed row. Expose the spec's outcome values in `answers.json`; the refusal stage can stay as a separate field. Count `failed` in the run summary and the console table. Keep the AC-11 stop for a model that is actually unavailable, for example when every question of the first run fails or a pre-flight call fails, with no files written
  - [x] 11.2 **Verify**: `DocsAnswerResultTest`: a failing model gives outcome `failed` with the invocation id of the `failed` row. `RagEvalCommandTest`: one failing answer among others is counted `failed` and left out of both means, the run still writes its files, and the existing "answer model unavailable" test still exits 1 with no files

- [x] 12. Stop when the grader model is unavailable (backend)
  Covers: AC-11
  - [x] 12.1 `RagAnswerGrader::grade()` catches every `Throwable` as `provider_error` and returns an ungraded grade, and `RagEvalCommand` never stops for it. If the `--grader` model is not pulled or the provider is down, the run exits 0 and writes `answers.json` with every answer ungraded, which breaks AC-11 ("IF ... a model is unavailable, stop with a clear message and no partial results file"). No test covers a grader that is down. Fix: when the grader is unavailable (for example, a pre-flight grader call fails, or the first graded answer fails with `provider_error` on both attempts), raise an exception that the command maps to exit 1 with a message naming the grader model, and write no files. A single bad reply stays `ungraded` as AC-6 requires
  - [x] 12.2 **Verify**: a new `RagEvalCommandTest` case makes `RagGraderAgent::fake` always throw. It asserts exit 1, a message that names the grader, and no output directory or `.tmp-*` directory. The existing AC-6 provider-error test in `RagGraderAgentTest` stays green

- [x] 13. Re-run the committed answer runs with TTFT and update the docs (measurement, docs)
  Covers: AC-5, AC-9, AC-10
  - [x] 13.1 Every committed `answers.json` under `bench/results/rag-eval/2026-11-03/` has `"median_ttft_ms": null`. `BENCHMARKS.md` calls TTFT "empty", with the cause not investigated, so AC-5's "median latency and TTFT from ai_runs" is not met by the committed results. After tasks 10 and 11, re-run `answers-qwen-500-300` and `answers-gemma-500-300` (`--repeat=3`) and `answers-qwen-300-180` (`--repeat=1`) with `--force`. Then replace the TTFT `null` and the "TTFT is empty" surprise in `BENCHMARKS.md` and guide section 15 with the measured medians, and update any figures that change
  - [x] 13.2 **Verify**: `RagEvalCommittedResultsTest` asserts that `median_ttft_ms` is a positive number for every committed model report. `BenchmarksDocTest` and `LearningGuideTest` match the TTFT figures against the JSON. `RagEvalContaminationTest` stays green

## Phase: Gaps (converge round 2)

- [x] 14. answer() writes a failed run row when the stream yields an Error event (backend)
  Covers: AC-8, AC-5
  - [x] 14.1 In `app/Services/DocsAnswerService.php::answer()`, an SDK `Error` event makes the loop throw its own `RuntimeException` inside `foreach ($stream ...)`. That abandons the SDK generator, so neither `AgentStreamed` nor `AgentFailed` fires (`vendor/laravel/ai/src/Providers/Concerns/StreamsText.php` only dispatches `AgentFailed` for exceptions thrown by the gateway). The Ollama gateway yields such an `Error` and returns without throwing on an in-stream `{"error": ...}` line (`vendor/laravel/ai/src/Gateway/Ollama/Concerns/HandlesTextStreaming.php:48-60`). The run `AiRunRecorder::started()` opened is never finished, so the `Failed` result and `answers.json` carry an invocation id with no `ai_runs` row, and `RagEvalRepositoryInterface::timings()` has nothing for it. The only failed-outcome test (`DocsAnswerResultTest` "returns a failed outcome with the invocation id of the failed run row") uses a thrown exception, which the SDK records itself. Fix: in the `catch (Throwable $e)` of `answer()`, call `$recorder->failed($stream->invocationId, $e)` (`finish()` is a no-op when the SDK already finished the run), so every `Failed` result has its `failed` row
  - [x] 14.2 **Verify**: a new `DocsAnswerResultTest` case makes the stream yield an `Error` event without throwing, for example through a `fake-stream` option or a bound test gateway. It asserts outcome `failed`, exactly one `DocsAnswerAgent` row with `status` `failed` whose `invocation_id` equals the result's, and `retrieved_chunk_ids` set. The existing thrown-exception test still passes, and `DocsAskTest` stays green and unedited

- [x] 15. AC-11 stop messages name the unavailable embedding or answer model (backend)
  Covers: AC-11
  - [x] 15.1 AC-11 requires "a clear message" when the embedding or a model is unavailable. `RagEvalCommand` prints only `$e->getMessage()`. For a down answer model that is `DocsAnswerUnavailableException`'s fixed "Docs answering is unavailable right now." (`app/Exceptions/DocsAnswerUnavailableException.php`). The "Every answer of {model} failed in the first run" text built in `RagAnswerEvaluator::assertModelReachable()` is only the previous exception and is never shown, so the user cannot tell which `--models` entry failed or whether the cause was the embedding. A down embedding prints "Docs search is unavailable.", which does not say the embedding model is the cause. The tests "AC-11 stops with a message and no files when the embedding is unavailable" and "AC-11 stops without files when the answer model is unavailable" assert only the exit code and the missing directory, not a message. The grader case already names its model. Fix: have the answer stage raise an exception that names the answer model, and have the command print a message that names the embedding model (`DocsEmbeddingService::modelLabel()`) when the embedding fails, in either stage
  - [x] 15.2 **Verify**: the two `RagEvalCommandTest` cases add `expectsOutputToContain` for the answer model name (`fake-other-1`) and for the embedding model label or the word "embedding". They still assert exit 1 and that neither the output directory nor any `.tmp-*` directory exists

- [x] 16. Test the refusal scoring of the answer stage (tests)
  Covers: AC-5
  - [x] 16.1 AC-5 says an off-topic question that is refused scores correct = 2 and counts as "declined correctly". Clarification Q2 says an on-topic refusal scores 0 and counts as "false refusal". `RagAnswerEvaluator::score()` implements both, and `answers.json` writes `stage`. But no `RagEvalCommandTest` case produces a refused outcome: every test question ends `completed`, `no_context` or `failed`, so the `false_refusal` count, the off-topic refused → `declined_correctly` path and the `stage` value (`refused_input` / `refused_output`) are untested. Add command tests with an injection-worded off-topic question (input guard), and an on-topic question whose answer repeats the canary (output guard, fixed with `CanaryGenerator`)
  - [x] 16.2 **Verify**: `RagEvalCommandTest` asserts that the off-topic refused row has `outcome` `refused`, `stage` `refused_input`, `category` `declined_correctly` and `correctness` 2, and that the grader is not prompted for it. It also asserts that the on-topic refused row has `outcome` `refused`, `stage` `refused_output`, `category` `false_refusal` and `correctness` 0, and that the run summary has `false_refusal` 1 and `groundedness` that excludes both rows

- [x] 17. Tick task 2 once its Verify is re-run (checks)
  Covers: AC-8, MNC-1
  - [x] 17.1 Task 2 (shared answer pipeline and `answer()`) and items 2.1–2.3 are still `[ ]` in this file. It was built, and tasks 10 and 11 extended it, but "Done when: All tasks checked" cannot pass while it stays unticked. Re-run 2.3: `DocsAskTest` green with no diff against `HEAD`, and `DocsAnswerResultTest` covering every outcome (completed, no_context, refused input and output, failed) with the model override and the `ai_runs` row. Then tick 2, 2.1, 2.2 and 2.3
  - [x] 17.2 **Verify**: `git diff HEAD -- tests/Feature/DocsAskTest.php` is empty; `ddev artisan test --compact tests/Feature/DocsAskTest.php tests/Feature/DocsAnswerResultTest.php` passes; task 2 and 2.1–2.3 are `[x]`

## Phase: Review fix pass

- [x] 18. Review fix pass (backend, tests, docs)
  Covers: AC-5, AC-8, AC-11, AC-6, AC-1, AC-2
  - [x] 18.1 Latency median: `RagAnswerEvaluator` collects invocation ids only for results that reached the model (completed, refused_output, failed), so latency and TTFT medians use one population. Committed `median_latency_ms` recomputed from `ai_runs` without re-running a model (qwen 500/300 8641 to 8997, gemma 500/300 9928.5 to 10690, qwen 300/180 5094 to 5398 ms; TTFT unchanged); BENCHMARKS.md and guide section 15 numbers updated, with a note. Test: an all-`no_context` run has null medians
  - [x] 18.2 `answer()`: the catch finishes the run through `AiRunRecorder::failed()`, so an in-stream SDK Error event writes a `failed` row and cleans `AiRunRecorder::$runs`. Test with a provider whose stream yields an Error event and ends without throwing (gap task 14)
  - [x] 18.3 Output path safety: `--label` must match `[A-Za-z0-9._-]{1,60}` and not be `.` or `..`; `--output-dir` must resolve (realpath of the nearest existing ancestor, no `..` segment) strictly inside `ai.docs.eval_results_root` (default `bench/results`); `--force` replaces only an empty directory or one holding nothing but `recall.json`, `calibration.json` and `answers.json`; the old directory is renamed to a sibling backup, the new one moved in, the backup deleted, and the backup restored if the move fails. Tests: `.`, `..`, `../../app`, outside, symlink, foreign files, nested directory, restore on a failed move
  - [x] 18.4 Contamination test: short answers are compared as whole raw lines, each normalized on its own
  - [x] 18.5 `RagModelReport`: a mean over nothing and an empty citation rate are null and skipped in `acrossRuns()`; JSON keeps null and the console shows an em dash
  - [x] 18.6 `RagEvalSet` rejects an evidence phrase whose normalized form is empty
  - [x] 18.7 With `--repeat` > 1 the console counts are summed over the runs with the run count in the header, and `answers.json` gains `totals` per model (new runs only; the three committed files were not re-run and have no `totals`)
  - [x] 18.8 Grader prompt `rag-grader.v2.md` (v1 kept for the committed results): the prompt carries `<question_type>` and `<expected_behaviour>` (answer or decline) and the two rules are mutually exclusive; config points at v2. Committed runs used `rag-grader@v1`; v2 applies to future runs and none of the committed models was re-run (noted in BENCHMARKS.md)
  - [x] 18.9 AC-11 messages: `RagEvalModelException::embeddingUnavailable()` ("Embedding model ollama/bge-m3 is unavailable: <cause>", retrieval and answer stage) and `answerModelFailed()` ("Answer model <name> failed for every question ..."); tests assert both (gap task 15)
  - [x] 18.10 `RagEvalDataException` (exit FAILURE) for the calibrator's empty group; `RagEvalOptionException` stays for bad options; an empty mode list has its own message
  - [x] 18.11 Tests for refused outcomes in the answer stage: off-topic refused_input scores 2 as declined correctly, on-topic refused_output is a false refusal with 0, the grader is not prompted, groundedness excludes both (gap task 16)
  - [x] 18.12 Task 2 and 2.1-2.3 ticked after `DocsAskTest` (no diff against HEAD) and `DocsAnswerResultTest` passed (gap task 17)
  - Skipped: none. All findings were verified against the code first and were correct

## Phase: Owner request (2026-11-03)

- [x] 19. AI implementation map in the learning guide (docs)
  Covers: AC-10
  - [x] 19.1 Guide section 16 "How the AI features are built: a map of the code": layers, every AI file with purpose and slice, four requests end to end, common feature requests and where they land, testing patterns (owner: "the guide should show how we implemented all AI stuff")
  - [x] 19.2 **Verify**: `tests/Feature/LearningGuideTest.php::the learning guide maps every AI file, request flow and feature recipe, and every linked file exists` (all 89 links resolve; fails when a new `app/Ai/*.php` is missing from the map)
