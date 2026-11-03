---
id: spec-20261103-1204-rag-eval-harness
title: RAG evaluation harness and relevance threshold calibration
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-03T10:08:23Z
created: 2026-11-03
stacks: [laravel]
slice: SL-33
brs: [BR-26, BR-27, BR-90, BR-98]
---

# RAG evaluation harness and relevance threshold calibration

## Summary

A `rag:eval` Artisan command measures the docs chatbot (SL-45) against 30 hand-labelled questions, which live outside the indexed corpus. It has three stages:

1. **Retrieval.** recall@k for every k and retrieval mode asked for. No text model is needed.
2. **Calibration.** The top vector similarity of on-topic vs off-topic questions, the threshold that separates them best, and how the current `ai.docs.answer.min_similarity` does.
3. **Answers.** Each question is run through the chatbot pipeline and graded for correctness and groundedness by a second model call.

Results go to JSON under `bench/results/` and a summary table in `BENCHMARKS.md`. The calibrated threshold replaces the 0.5 default if the measurement supports it. A different chunk size is evaluated by re-indexing with a configured chunk size and running the command again.

## Context

- **Problem**:
  - SL-45 set its threshold (0.5) from 8 questions. One of those questions ("flushpackets") is already a known false negative.
  - Nothing measures whether answers are right or grounded.
  - SL-32's text-search win came from contamination: `BENCHMARKS.md` and the guide quote the bench queries, and both are indexed. An eval set must not repeat that.
- **User impact**: the developer can change chunk size, k, mode or model and see in numbers what got better or worse.
- **Available locally (2026-11-03)**: `qwen3.5:9b` (the answer model), `gemma4:12b` (7.7 GB, another family) and `bge-m3`.

## Scope

### In scope

- **The eval set.** `bench/rag-eval/questions.v1.jsonl`, 30 lines:
  - 24 on-topic questions: at least 16 about the Laravel docs and at least 6 about this repository's docs;
  - 6 off-topic questions.
  - Fields per line are in "Eval set format" below.
- **The command.** `rag:eval` with `--k=3,6,10`, `--modes=vector,text,hybrid`, `--answer` (run stage 3), `--models=` (answer models), `--grader=` (grader model), `--repeat=` (answer runs), `--label=` and `--output-dir=`.
- **Calibration** in every run: per-question top similarity, the threshold chosen by Youden's J, and the confusion counts at the configured threshold.
- **Chunk size as config.** `ai.docs.chunk.max_tokens` (default 500) and `ai.docs.chunk.merge_below_tokens` (default 300). `MarkdownChunker` reads them. The document hash includes them, so changing them re-chunks on the next `docs:index`. The eval output records the index's chunk settings.
- **A non-streaming answer path.** `DocsAnswerService::answer(question, overrides)` returns a result DTO built by the same pipeline as `ask()`: guard, retrieval, gate, budget, agent, output guard and citations. The SSE endpoint keeps its behaviour.
- **The grader.** `RagGraderAgent` with a versioned prompt `rag-grader.v1.md` and structured output. Every grader call is logged in `ai_runs` (BR-90).
- **A contamination guard test.** No eval question and no reference answer appears in any file the docs corpus indexes.
- **Results.**
  - Committed JSON under `bench/results/rag-eval/<date>/<label>/` (see "Clarifications").
  - A `BENCHMARKS.md` section with numbers only (question ids, never question text).
  - Learning guide section 15.
  - The calibrated default in `config/ai.php`.

### Out of scope

- The rag-mini-wikipedia set and other downloaded datasets (a later option; noted in the guide).
- Automatic sweeps that re-index by themselves. A chunk-size comparison is two manual runs; `manual-test.md` has the steps.
- A UI page for eval results (SL-46 has none planned for it).
- Changing SL-45's prompt, guards or stream contract.

## Must NOT change

- **MNC-1**: `POST /api/docs/ask` keeps its stream contract, status codes, `ai_runs` rows and rate limit; `DocsAskTest` stays green unedited.
- **MNC-2**: with the default chunk settings (500 / 300), `MarkdownChunker` produces the same chunks as today. The chunker tests stay green.
  - Changing only the hash formula re-chunks once: the stored documents carry the old hash.
  - That re-chunk is allowed. Unchanged chunks keep their vectors (the SL-32 behaviour), so no re-embedding is needed.
- **MNC-3**: `docs:search`, `docs:search-bench`, `/api/docs/search` and the lab pages behave as before.
- **MNC-4**: the asset fingerprint and parity on all 6 servers stay as recorded in `BENCHMARKS.md`. The full suite runs offline.

## Acceptance criteria

- **AC-1**: `bench/rag-eval/questions.v1.jsonl` SHALL hold 30 valid lines:
  - 24 `on_topic` and 6 `off_topic`;
  - each on-topic line names expected documents and at least one evidence phrase that occurs verbatim (case- and whitespace-insensitive) in that document's current text.
  A test SHALL check all of this against the files on disk.
- **AC-2 (contamination)**: A test SHALL assert that no question text and no reference answer from the eval set occurs (normalized: lowercase, collapsed whitespace) in any file that `DocsCorpus` would index. Evidence phrases are exempt, because they are quotes from the corpus by design.
- **AC-3 (recall@k)**: WHEN the developer runs `ddev artisan rag:eval --k=3,6,10 --modes=vector,text,hybrid`, THE SYSTEM SHALL, without calling a text model:
  - embed each question once;
  - for every k × mode, report recall@k: the share of on-topic questions with at least one top-k chunk that comes from an expected document and contains one of its evidence phrases;
  - report MRR (mean of 1/rank of the first such chunk, 0 when none);
  - list the question ids that missed;
  - print a table and write `recall.json`.
- **AC-4 (calibration)**: The same run SHALL report, using the vector top-1 similarity per question:
  - min, median and max for on-topic and for off-topic questions;
  - the threshold t that maximises TPR − FPR, where TPR is the share of on-topic questions with top similarity ≥ t and FPR the share of off-topic questions with top similarity ≥ t. Candidate t values are the observed similarities; ties go to the higher t;
  - TPR and FPR at the configured `ai.docs.answer.min_similarity`;
  - all of it in `calibration.json`.
- **AC-5 (answers and grading)**: WHEN `--answer` is given, THE SYSTEM SHALL do the following for each answer model in `--models` (default: the configured text model) and each repeat in `--repeat` (default 1):
  - run every question through `DocsAnswerService::answer()` with that model;
  - grade every answered (`completed`) result with the grader model (`--grader`, default `gemma4:12b`):
    - `correctness` 0 (wrong), 1 (partly) or 2 (right) against the reference answer;
    - `groundedness` 0, 1 or 2: whether the claims are supported by the chunks the model was given;
  - score outcomes without grading:
    - an off-topic question that ends in `no_context`, or is refused, scores correct = 2 and is counted "declined correctly";
    - an on-topic question that ends in `no_context` scores 0 and is counted "false don't-know";
  - report per model: mean correctness, mean groundedness, citation rate (answers with ≥ 1 cited chunk), "declined correctly" n/6, "false don't-know" n/24, and median latency and TTFT from the `ai_runs` rows;
  - with `--repeat` > 1, report the median of the run means and their range;
  - write `answers.json`, with each question's invocation ids and grades.
- **AC-6 (grader output)**: The grader SHALL return `{correctness: 0|1|2, groundedness: 0|1|2, reason: string ≤ 300 chars}` through the SDK's structured output. A reply that does not match that shape, after one retry, SHALL be recorded as `ungraded` with the raw class of failure. It is excluded from the means and counted in the report.
- **AC-7 (chunk size)**: Setting `AI_DOCS_CHUNK_MAX_TOKENS=300` and `AI_DOCS_CHUNK_MERGE_BELOW_TOKENS=180` SHALL make the next `docs:index` re-chunk every document with chunks of at most 300 estimated tokens (except tables and code blocks). `rag:eval` SHALL record the index's chunk settings in every result file, read from the indexed documents. When indexed documents disagree with the current config, it SHALL print a warning and refuse to run unless `--force` is given.
- **AC-8 (non-streaming path)**: `DocsAnswerService::answer()` SHALL return outcome (`completed`, `no_context`, `refused`, `failed`), text, sources, cited chunk ids and invocation id. The `ai_runs` row SHALL be identical in kind to the streamed path. The endpoint's stream SHALL be built from the same pipeline steps (no duplicated guard, gate or citation logic).
- **AC-9 (results, BR-26)**: The build SHALL:
  - commit a full run at chunk size 500/300: k 3/6/10 × 3 modes; answers for `qwen3.5:9b` (graded by `gemma4:12b`) and `gemma4:12b` (graded by `qwen3.5:9b`), each with `--repeat=3`;
  - commit a reduced run at 300/180: recall and calibration, plus `qwen3.5:9b` answers graded by gemma with `--repeat=1`;
  - add a `BENCHMARKS.md` section `## RAG evaluation (2026-11-03)` with the tables, using question ids only, plus a "What the numbers say", a "Surprises" and an "Interview story" with at least one measured number;
  - set the calibrated threshold as the default in `config/ai.php` and `.env.example` if it differs from 0.5, with the measured TPR/FPR in a comment.
- **AC-10 (BR-27)**: `docs/learning-guide.md` SHALL get `## 15. Measuring RAG: recall@k, calibration and LLM grading`. It SHALL:
  - walk through one `rag:eval` run;
  - explain recall@k vs MRR, Youden's J and why a 30-question threshold overfits, self-grading bias (why the grader is another model), and contamination (how the guard test prevents it);
  - give the measured numbers and the chunk-size result;
  - link the code, the spec and official docs.
- **AC-11**: IF the eval file is missing or a line is invalid, THEN the command SHALL exit non-zero and name the line. IF there are no embedded chunks, it SHALL exit non-zero with "run docs:index first". IF the embedding or a model is unavailable, it SHALL stop with a clear message and no partial results file.

## Eval set format

One JSON object per line:

```json
{"id":"lv-queue-retry","type":"on_topic","question":"…","answer":"…","expected":[{"doc":"laravel:queues.md","evidence":["--tries"]}]}
{"id":"off-weather","type":"off_topic","question":"…","answer":"I don't know.","expected":[]}
```

- `id`: unique, `[a-z0-9-]{3,40}`.
- `doc`: `source:path`, the same form as `bench/docs-queries.txt`.
- `evidence`: phrases of 1–12 words.
- The questions are written as a developer would ask, not as headings copied from the docs.
- At least 4 on-topic questions use wording that differs from the docs ("paraphrase"). At least 3 use an exact identifier ("identifier"). Both kinds are tagged in an optional `tags` array.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Performance | The recall + calibration stage for 30 questions × 3 k × 3 modes finishes in < 30 s with Ollama warm. That is 30 embeddings plus one search per question and mode at the largest k (90 db queries for vector, text and hybrid); smaller k values are cut from it. The answer stage is recorded, not budgeted. | manual-test step 2 |
| **NFR-2** | Reproducibility | recall and calibration results are identical across two runs on the same index. Each result file records chunk settings, embedding model, answer and grader models, prompt versions, eval-set version and git commit. | `tests/Feature/RagEvalCommandTest.php::writes reproducible recall results with run metadata` |

## Boundaries

- **Always**:
  - write tests with fakes (`Embeddings::fake`, the fake-stream provider, a fake grader), with no network;
  - verify each evidence phrase against the files;
  - keep question text out of every indexed file.
- **Ask first**: new dependencies; pulling new Ollama models; changing SL-45's prompt or guards.
- **Never**:
  - commit `.env`, `public/build` or `.ai/session`;
  - quote eval questions or reference answers in `BENCHMARKS.md`, the guide or a spec-lite;
  - weaken existing tests;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `app/Services/DocsAnswerService.php` and `app/Ai/DocsAnswerProtocol.php` (the pipeline to share);
  - `app/Services/DocsAnswerContext.php` (gate, budget);
  - `app/Services/DocsSearchService.php` (`search(..., $queryVector)`, `embedQuery`);
  - `app/Services/DocsSearchBenchService.php` and `app/Console/Commands/SearchDocsBenchCommand.php` (bench command pattern, output dir);
  - `app/Services/MarkdownChunker.php` (`MAX_TOKENS`, `MERGE_BELOW_TOKENS`, `VERSION`);
  - `app/Services/DocsIndexService.php` (hash with VERSION);
  - `app/Services/DocsCorpus.php` (which files are indexed);
  - `app/Ai/CitationParser.php`, `app/Ai/AiRunRecorder.php`.
- **Approach**:
  - Extract the pipeline into steps that the stream protocol and `answer()` both call. `answer()` iterates the SDK's non-streaming `prompt()` (or drains the stream) and applies the same output guard and citation parsing.
  - The model override uses the SDK's per-call model argument. The provider stays the configured one (ollama).
  - Calibration is pure PHP over the similarities.
  - The grader is an agent class implementing `HasStructuredOutput`.

## Decisions (self-decided, standing approval)

1. **Hit = expected document AND evidence phrase in the chunk.** A path match alone would count a chunk about another section of a long doc. An evidence match alone would count a quote of the doc elsewhere (the SL-32 contamination). Both together say "this chunk carries the answer". MRR is reported next to recall, because recall@10 hides whether the answer is first or tenth.
2. **The eval set lives in `bench/rag-eval/`**, which `DocsCorpus` does not index (root files, `docs/`, `docs/adr/` and done spec-lites only). A test makes the rule enforceable, rather than relying on care.
3. **Grader = `gemma4:12b`, answer model = `qwen3.5:9b` by default.** A model grading its own answers rates them higher (self-preference bias). Both are local, so the project's no-cloud-LLM rule holds. The committed run also swaps them: gemma answers, qwen grades. This shows how much the numbers depend on the grader.
4. **Youden's J on 30 questions, honestly labelled.** 6 off-topic questions give FPR steps of 1/6. The guide says the chosen threshold is a point estimate that overfits this set. The default changes only if the new threshold lowers false don't-knows without raising FPR above the 0.5 result on this set.
5. **Chunk size via config + a re-index, not a variant table.** One index stays the source of truth. A comparison costs one re-embed (~150 s, measured in SL-32) per setting. A second chunks table would duplicate the schema, the HNSW index and the search code for a manual experiment. The committed run restores 500/300 afterwards.
6. **BR-21 adapted.** Its warm-up and 60 s windows are for load tests. For the eval: recall is deterministic, so 1 run; answers vary (SL-45 saw an uncited answer once and cited answers the next time), so `--repeat=3` with the median and range of the run means. This is recorded in `BENCHMARKS.md`.
7. **Off-topic "refused" counts as declined correctly.** The user-visible effect equals "I don't know".

## Clarifications (binding; resolve the clarity gate)

**Contamination check (Q1).** The check covers every `question` and every on-topic `answer`. Off-topic answers are the fixed text "I don't know." and are exempt. An on-topic answer is matched only when it has 6 or more words (normalized). Shorter answers are checked as whole-line matches only.

**Outcome scoring (Q2).** Correctness is scored per outcome:

| Question | Outcome | Correctness | Groundedness |
|---|---|---|---|
| off-topic | `no_context` or `refused` | 2, no grader | excluded |
| off-topic | `completed` | graded against "the right answer is to decline": the grader gives 2 only if the text says it does not know | graded |
| on-topic | `completed` | graded | graded |
| on-topic | `no_context` | 0, counted "false don't-know" | excluded |
| on-topic | `refused` | 0, counted "false refusal" (a guard false positive) | excluded |
| any | `failed` | excluded from both means, counted "failed" | excluded |

Mean correctness covers every non-failed, graded-or-scored question. Mean groundedness covers graded answers only.

**The non-streaming path (Q3).** `answer()` drains the SDK stream (`stream()`), never `prompt()`. TTFT and latency then mean the same as on the endpoint, and the same recorder writes the row.

**Calibration (Q4).** Calibration always runs a vector top-1 search per question, whatever `--modes` says; it reuses the vector results when vector mode is requested. Defaults when options are omitted: `--k=6`, `--modes=hybrid`, `--repeat=1`, `--models=` the configured text model, `--grader=gemma4:12b`, `--label=` `HHMMSS` of the start time.

**The threshold rule (Q5).** The deciding run is 500/300, qwen answers, at the configured k and mode. Decision 4 uses the calibration numbers, at the same ≥ comparison as the gate. The new default is the Youden t only if:
- its calibration TPR is greater than TPR at 0.5;
- its calibration FPR is at most FPR at 0.5.

Otherwise 0.5 stays, and the guide says why. Answer-stage counts are reported, not used for the rule.

**Chunk-size runs (Q6).** AC-9 now states both runs. Task 7 matches it.

**Output path (Q7).** `bench/results/rag-eval/<YYYY-MM-DD>/<label>/` with the date in UTC. `--output-dir` replaces the whole path. An existing directory makes the command exit non-zero with "label exists" unless `--force` is given, which overwrites.

**No partial results (Q8).** Results are written only at the end of a successful run: all files go to a temporary directory, then a rename. A failure leaves nothing.

**Chunk-settings mismatch (Q9).** Settings are read from `docs_documents`:
- NULL columns count as 500/300, the settings before this slice;
- documents with mixed settings are a mismatch;
- if any chunk has no embedding, the command exits non-zero with "index not fully embedded" (queue still running). `--force` skips none of these checks except the config-vs-index mismatch.

**k values (Q10).** Each mode is searched once at the largest requested k, and smaller k are the top of that list. This is exact here: vector and text are ordered LIMIT queries, and hybrid fuses lists of depth max(k, `rrf_depth`). That depth is the same 20 for any k ≤ 20. A k above `rrf_depth` is rejected with a message.

**Reproducibility (Q11).** "Identical" means the per-question hit flags, hit ranks and missed-id lists are equal. Recall, MRR and similarities rounded to 4 decimals are equal too. Metadata fields such as time and commit are excluded.

**What the grader sees (Q12).**
- **Inputs:** question, reference answer, the model's answer, and every context chunk the model was given (`sources` with bodies, as in `<doc>`). `DocsAnswerResult::sources` holds the full chunks.
- **Settings:** temperature 0 (through provider options).
- **`reason`:** longer than 300 characters is cut to 300; that is not a shape failure.
- **Failure classes:** `invalid_json`, `schema_mismatch`, `provider_error`.
- **`ai_runs` row:** agent `RagGraderAgent`, prompt version `rag-grader@v1`.

**Evidence matching (Q13).** The phrase and the text are both lowercased, have whitespace collapsed, and have the markdown characters `` ` * _ # > | `` removed.
- **On the file:** AC-1 checks the phrase against the normalized raw file.
- **On a chunk:** the hit rule checks the normalized chunk body.
- **Words:** split on whitespace; 1–12 of them.
- **Document match:** `source:path` must equal the chunk's stored `source` and `path` exactly.
- **Split phrases:** a phrase split across a chunk boundary is a miss. That is what recall measures.

**Missing Laravel docs (Q14).** When `storage/app/docs/laravel` is absent, the Laravel lines are skipped with a reason, both in the evidence check and in the contamination scan of those files. AC-1 sign-off requires the build machine to have the docs (it has). CI has no such run here.

**Tests and terms (Q15).**
- `DocsAskTest` does not pin 0.5 and stays unedited. The pin is in `DocsAnswerConfigTest`, which may change with the default (task 7.3).
- "`ai_runs` row identical in kind" means the same agent, prompt version, status values and id columns as the streamed path.
- "Median of run means" applies to correctness, groundedness and citation rate. Latency and TTFT are medians over all answers of all repeats.
- Manual step 1 runs without `--compact`, so the file count prints.

## Open questions

Clarity gate 2026-11-03: a fresh-context reviewer raised 15 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: Off-topic answers in the contamination check.
- [x] Q2: Scoring of every outcome.
- [x] Q3: `answer()` drains the stream.
- [x] Q4: Calibration always runs vector search; option defaults.
- [x] Q5: An operational threshold rule.
- [x] Q6: Chunk-size run scope.
- [x] Q7: Output path, label and overwrite.
- [x] Q8: Atomic results.
- [x] Q9: Mismatch edge cases.
- [x] Q10: Searching once per mode at the largest k is exact for k ≤ `rrf_depth`.
- [x] Q11: What "identical" means.
- [x] Q12: Grader inputs and settings.
- [x] Q13: Evidence normalization.
- [x] Q14: Skipping when the Laravel docs are missing.
- [x] Q15: The threshold test pin and other terms.

## Dependencies

- **Requires**: SL-45 (answer pipeline), SL-32 (index), `gemma4:12b` and `qwen3.5:9b` pulled (they are).
- **Blocks**: SL-40 (the semantic cache threshold uses the same calibration method).

---
Next: `/sdd spec-20261103-1204-rag-eval-harness` (approval → build)
