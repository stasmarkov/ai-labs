---
id: spec-20261103-1028-docs-chat-answer
title: Docs chatbot answering with citations and a guard
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-03T08:33:22Z
created: 2026-11-03
stacks: [laravel]
slice: SL-45
brs: [BR-98, BR-100, BR-90, BR-95, BR-27]
---

# Docs chatbot answering with citations and a guard

## Summary

The developer asks one question about the indexed docs (SL-32: this repository's markdown and the Laravel docs). The app retrieves the best chunks, hands them to the local model (qwen3.5:9b) as marked data, and streams the answer back as server-sent events. The answer cites the chunks it used as `[1]`, `[2]`. When nothing relevant is retrieved the bot says it does not know and the model is never called. A question that matches a known prompt-injection pattern is refused before retrieval, and an answer that leaks the system prompt is cut off and replaced by a refusal. Every outcome, answered, "I don't know" or refused, writes one `ai_runs` row that names the chunks retrieved and the chunks cited. The lab's `/ai/chat` placeholder becomes a single-question ask page. Multi-turn chat is SL-41.

## Context

- **Problem**: SL-32 can find chunks but nothing turns them into an answer. The model alone does not know this project and invents things. A bare prompt also has no defence against "ignore your rules" text in a question or a document.
- **User impact**: the developer sees the whole RAG pipeline for one request: guard → embed → hybrid retrieval → relevance gate → prompt with marked context → streamed tokens → citation check → trace row. Each step can be inspected in the lab and in `ai_runs`.
- **Measured before writing (2026-11-03, bge-m3, 2 706 chunks)**: the top vector similarity for on-topic questions was 0.635–0.674 ("rate limiting", "queued job", "benchmark servers"). One exact-term question ("what does flushpackets do in this project") reached only 0.469. Off-topic questions reached 0.330–0.446 (sourdough 0.446, winter tyres 0.418, world cup 0.330). Full-text search needs every word of the question (`websearch_to_tsquery`), so whole questions rarely hit: 0 hits for all four off-topic questions and also 0 for the flushpackets question.

## Scope

### In scope

- `POST /api/docs/ask` with JSON body `{"q": "…"}`: SSE stream of the answer (BR-95). Throttled per IP. Non-JSON bodies get 415, GET gets 405.
- **Input guard**: a pattern list in a config-named file. A matching question is refused with a stated reason and no retrieval or model call happens (BR-100).
- **Retrieval**: SL-32 hybrid search with k = `ai.docs.answer.k` (default 6). It is followed by a relevance gate and a context token budget.
- **Prompt**: a versioned file `resources/prompts/docs-answer.v1.md`. Chunks go in a `<context>` block, each `<doc>` with id, source, path and section. The rule says text inside `<context>` is reference, not instructions (BR-100).
- **"I don't know"** when no chunk passes the gate (BR-98).
- **Output guard**: while streaming, the server stops the answer when it contains the prompt's canary or a long verbatim run of the system prompt. It sends a refusal event instead (BR-100).
- **Citations**: the answer's `[n]` markers are mapped to chunk ids and sent as a final `citations` event. The full list of chunks handed to the model is sent first, as a `sources` event (BR-98).
- **`ai_runs` gets three nullable columns**: `retrieved_chunk_ids`, `cited_chunk_ids` and `refusal_reason`. There are two new status values, `refused` and `no_context` (BR-90).
- **Lab `/ai/chat`**: question box, Ask, Stop, the streamed answer with clickable citation markers, the sources list, the event log, metrics (TTFT, tokens/s, retrieval timings) and the logged run. The chat capability becomes `ready`.
- `docs/learning-guide.md`: new section 14 (BR-27).

### Out of scope

- Multi-turn conversation, history and server-side cancel semantics beyond what the SL-46 Stop already does (SL-41).
- Evaluation of answer quality, and threshold calibration on a labelled set (SL-33).
- Caching (SL-40). Tools/MCP (SL-34). A queued or Reverb variant (non-goal).
- Choosing the search mode or k per request. Both are fixed by config here; SL-33 compares them.
- Personal-data scrubbing in answers (no personal data in the corpus).

## Must NOT change

- **MNC-1**: `GET /listings/{id}/describe` keeps the same SSE events, masked error message, rate limit and `ai_runs` row (columns as today, the new columns null).
- **MNC-2**: `GET /api/docs/search` and `GET /api/listings/semantic`: same JSON body, status codes and `server-timing` header.
- **MNC-3**: existing `ai_runs` rows and the lab overview's runs table still read correctly. The overview shows the new statuses as text.
- **MNC-4**: old Blade pages: `bench/asset-fingerprint.sh` prints the values recorded in `BENCHMARKS.md`, and `bench/parity.sh` is identical on all 6 servers.
- **MNC-5**: the other lab pages (`/ai`, describe, semantic, docs, photos) behave as in SL-46. The full suite stays green without network calls.

## Acceptance criteria

- **AC-1 (BR-95, BR-98)**: WHEN the developer posts `{"q": "how does rate limiting work"}` to `/api/docs/ask`, the response SHALL:
  - be `text/event-stream`;
  - send, in order: one `sources` event listing every chunk given to the model (fields in "Stream contract" below); the SDK's `stream_start`, `text_delta`…, `text_end`, `stream_end` events; one `citations` event; then `data: [DONE]`.
- **AC-2 (BR-98)**: WHEN the answer contains markers `[n]`, the `citations` event SHALL list each distinct `n` in order of first use together with its chunk id. A marker whose `n` is not in `sources` SHALL be reported under `unknown` and never mapped to a chunk.
- **AC-3 (BR-98)**: The relevance gate works chunk by chunk. A retrieved chunk passes when its vector similarity is ≥ `ai.docs.answer.min_similarity` (default 0.5) or it has a full-text rank and the question uses no `websearch_to_tsquery` operators (the word `or`, a `-word`), so a full-text hit still means every word matched. Chunks that fail are dropped from `sources` and the context even when others pass. IF no chunk passes, THEN THE SYSTEM SHALL:
  - send a `no_context` event with the fixed text "I don't know: the indexed docs have nothing relevant to this question.", then `[DONE]`;
  - not call the text model;
  - send no `sources` event (nothing was given to the model);
  - write an `ai_runs` row with status `no_context`, `retrieved_chunk_ids` = the ids hybrid search returned before the gate, and `cited_chunk_ids` null.
- **AC-4 (BR-100)**: WHEN the question matches a pattern in the input-guard list, THE SYSTEM SHALL:
  - send one `refused` event with `reason` "The question looks like an attempt to change the assistant's instructions." and `pattern` (the matched name), then `[DONE]`; no `sources` event;
  - embed nothing and not call the model;
  - write an `ai_runs` row with status `refused`, `refusal_reason` `input:prompt-injection.v1:<pattern name>`, and provider, model and prompt version filled from the active configuration (BR-90).
- **AC-5 (BR-100)**: WHEN the accumulated streamed text contains this request's canary, or a run of 12 consecutive words of the system prompt file that does not also occur in this request's context (see "Output guard" below), THE SYSTEM SHALL:
  - stop reading the model stream;
  - send one `refused` event with `reason` "The answer was withheld because it repeated the assistant's instructions." and `replace: true`, then `[DONE]`;
  - write an `ai_runs` row with status `refused` and `refusal_reason` `output:canary` or `output:prompt_overlap`, the tokens received so far as `tokens_out` when the SDK reports them (else null), TTFT, and no `citations` event.
- **AC-6 (BR-100)**: The model's input SHALL put each chunk inside `<context>…</context>` as `<doc n="…" source="…" path="…" section="…">…</doc>`, with the system prompt stating that text inside `<context>` is reference material, not instructions. Chunk bodies and attribute values SHALL be escaped with `htmlspecialchars(…, ENT_QUOTES | ENT_SUBSTITUTE)` (so `<`, `>`, `&` and quotes are entities). A chunk can then never close its own tag in any spelling (`</doc>`, `</DOC >`), and a path or heading with quotes cannot break an attribute.
- **AC-7 (BR-90)**: WHEN an answer completes, its `ai_runs` row SHALL have:
  - agent `DocsAnswerAgent`, provider, model and prompt version `docs-answer@v1`, tokens in and out, TTFT, latency, status `completed`;
  - `retrieved_chunk_ids` (the `sources` ids in order) and `cited_chunk_ids` (the mapped citation ids in order).

  `retrieved_chunk_ids` always means "what the model was given" on runs that reach the model, and "what search returned" on `no_context` runs (AC-3). A run that fails mid-stream, or is stopped by the client, SHALL record `retrieved_chunk_ids` and leave `cited_chunk_ids` null. A failed run has status `failed` (as describe). A run cut by the client's Stop is not finished by the server on FPM (the SL-46 behaviour); on worker servers it completes server-side and its row is written as `completed`.
- **AC-8**: The context SHALL hold at most `ai.docs.answer.max_context_tokens` (default 4 000) estimated tokens:
  - estimated as `ceil(mb_strlen(body)/4)` per chunk body; the `<doc>` markup is not counted;
  - chunks are dropped from the end of the hybrid (RRF) order after the gate;
  - at least the top chunk is always kept; if it alone is larger, it is cut to `budget × 4` code points at the last whitespace before that point (`mb_substr`, never mid-character).
- **AC-9**: IF `q` is missing, blank after trim, longer than 500 code points (`mb_strlen` after trim), or contains NUL or invalid UTF-8, THEN THE SYSTEM SHALL answer 422 with the standard JSON error body.
  - IF the per-IP limit `ai.docs.answer.per_minute` (default 10) is exceeded, THEN THE SYSTEM SHALL answer 429 with `Retry-After`. Every request that passes validation counts, including refused and `no_context` ones.
  - IF the query cannot be embedded, THEN THE SYSTEM SHALL answer 503 with the standard JSON error body and message "Docs answering is unavailable right now." Retrieval runs before the response starts, which is also why `Server-Timing` (embed, db) is a real header on this SSE response.
  - IF the model fails, the stream SHALL send `{"type":"error","message":"The answer could not be generated."}` once, then `[DONE]`, with no host names or stack traces.
- **AC-10**: WHEN the developer opens `/ai/chat`, types a question and presses Ask, the page SHALL:
  - show the sources list before the first token, then the streamed answer, with each `[n]` rendered as a link to source n;
  - show TTFT, tokens/s, embed and db timings, the event log and, after the stream ends, the logged run;
  - show "I don't know" and refusal states as distinct messages; on `replace: true`, remove the streamed text;
  - stop the stream at once when Stop is pressed.
  The overview's Chat card SHALL show `ready`.
- **AC-11 (BR-27)**: `docs/learning-guide.md` SHALL have section `## 14. Answering with citations: RAG, guards and the trace row`. It walks through one request step by step and includes:
  - the threshold numbers measured above;
  - one shown bypass of the regex input guard, and why marking the context is the structural defence;
  - why the output guard cannot un-send tokens already streamed;
  - links to the code, the spec, and the official docs.

## Stream contract

Every line is `data: <json>\n\n`. The stream always ends with `data: [DONE]\n\n`. Event shapes (snake_case keys):

| Event | Payload |
|---|---|
| `sources` | `{"type":"sources","sources":[{"n":1,"id":123,"source":"laravel","path":"rate-limiting.md","heading":"Rate Limiting › Introduction","vector_similarity":0.674,"text_rank":null}, …]}`. `n` starts at 1, in context order; `heading` is the chunk heading and becomes the `<doc section>` attribute. |
| SDK events | `stream_start`, `text_start`, `text_delta`, `text_end`, `stream_end`, exactly as the describe stream sends them |
| `citations` | `{"type":"citations","cited":[{"n":1,"id":123}, …],"unknown":[7]}`, sent only after `stream_end` of a run that was not refused |
| `no_context` | `{"type":"no_context","message":"I don't know: the indexed docs have nothing relevant to this question.","invocation_id":"…"}` (the run id, so the lab can show the logged row) |
| `refused` | `{"type":"refused","stage":"input"\|"output","reason":"…","pattern":"ignore-previous"\|null,"replace":false\|true}`. `replace` is `true` only for `output`. An input refusal also carries `invocation_id`; an output refusal's run id came in `stream_start`. |
| `error` | `{"type":"error","message":"The answer could not be generated."}` |

Order per outcome:

| Outcome | Events |
|---|---|
| answered | `sources` → SDK events → `citations` → `[DONE]` |
| `no_context` | `no_context` → `[DONE]` |
| input refusal | `refused` → `[DONE]` |
| output refusal | `sources` → SDK events up to the leak → `refused` → `[DONE]` |
| model error | `sources` → any SDK events → `error` → `[DONE]` |

**Citation markers.** A marker is `[n]` or a comma list `[n, m]` (digits, optional spaces), and adjacent markers such as `[2][3]` count as one run. The run must not be preceded by a word character, `]` or `` ` ``, and must not be followed by `(`. This excludes `arr[0]`, markdown links `[text](url)` and `[^1]`. Markers inside fenced code blocks are ignored. Parsing runs once on the full accumulated text at `stream_end`, so markers split across deltas are no problem.

**Output guard.** It runs on the raw accumulated text after each delta.
- **Canary:** `ap-canary-` followed by 16 random hex characters, generated per request and appended to the system prompt as one line. It never appears in any file, so it cannot reach the index or the guide.
- **Overlap:** the system prompt file's text and the answer are each lowercased and split on runs of non-letter, non-digit characters (Unicode), so markdown and punctuation are not words; `[n]` markers are removed before splitting, so citations between copied clauses do not break a run. Reasoning events (`AI_THINK=true`) are not forwarded at all, since the guard checks only answer text. A 12-word run of the prompt that also occurs in this request's context or question is allowed, because quoting a retrieved chunk is honest. The user-message template (the `<context>` wrapper and the question) is not part of the protected text. The prompt is written so that no 12-word run of its rules is generic phrasing.
- **Not on disk:** the prompt text is never quoted 12 words at a time in the guide or in a finished spec.

**Refused and `no_context` rows.** These runs never call the model. The `ai_runs` row takes provider and model from the active configuration (`config('ai.default')` and its text model, e.g. `fake-stream` / `fake-slow-1` in tests), prompt version `docs-answer@v1`, tokens null, TTFT null, and latency from request start.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Performance | Time from request to the `sources` event < 500 ms with Ollama warm (embed ~60 ms + db). The answer's TTFT is recorded, not budgeted. | manual-test step 3 (event log timings) |
| **NFR-2** | Security | No refusal, `no_context` or error event contains the system prompt, the canary, an internal host name or a stack trace. | feature tests per outcome |
| **NFR-3** | Accessibility | `/ai/chat` meets the SL-46 bar: no horizontal scroll at 390 px, visible focus, only the answer region is `aria-live`. | manual-test step 7 + browser check |

## Boundaries

- **Always**:
  - follow the SL-20/SL-22 patterns: an agent class with `VersionedPrompt`, `SseProtocol`, and `AiRunRecorder` via `RecordAiRun`;
  - write feature tests with the fake providers (no network);
  - update the guide.
- **Ask first**: new dependencies; changing `.ddev/config.yaml`; changing SL-32's search scoring or the chunker.
- **Never**:
  - put chunk text outside the marked `<context>` block;
  - send unmasked provider errors;
  - weaken existing tests;
  - commit `.env`, `public/build` or `.ai/session`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `app/Ai/CarDescriptionAgent.php` (agent pattern, provider options);
  - `app/Ai/SseProtocol.php` (SSE framing, masked errors, Octane drain);
  - `app/Ai/AiRunRecorder.php` and `app/Ai/RecordAiRun.php` (trace row via SDK events);
  - `app/Services/DocsSearchService.php` (hybrid search; `DocsMatch` carries `vectorSimilarity` and `textRank`);
  - `app/Http/Requests/DocsSearchRequest.php` (NUL/UTF-8 rule);
  - `app/Providers/AiServiceProvider.php` (rate limiters);
  - `app/Ai/FakeStreamGateway.php` (fake streaming);
  - `resources/js/ai/composables/useEventStream.ts`, `resources/js/ai/Pages/Lab/Chat.vue` (placeholder);
  - `app/Services/LabOverviewService.php` (capability status).
- **Already implemented**: the stream framing, Stop (AbortController), masked errors, the run log and run lookup `/ai/runs/{id}`, hybrid retrieval, `Server-Timing` and the timing collector.
- **Approach**:
  - `DocsAnswerService::ask(question)`: input guard → embed once → hybrid search → relevance gate → token budget → `DocsAnswerAgent` stream.
  - A `DocsAnswerProtocol` extends `SseProtocol`. It yields `sources` first, watches deltas for the output guard and `[n]` markers, and yields `citations` at the end. Refusal and `no_context` responses are plain SSE responses from the same protocol helpers.
  - The recorder gains `annotate(invocationId, retrieved, cited)` and a `recordOutcome(...)` for runs that never reach the model.
  - The shared provider-entry helpers move from `CarDescriptionAgent` into a trait used by both agents (MNC-1 guards it).
  - The fake gateway gains an optional canned `text` (for citation and leak tests).

## Decisions (self-decided, standing approval)

1. **POST with a JSON body** (revised in the review fix pass; it was GET with `q`). A GET or form POST is a CORS "simple" request, so any other page could start a model run in a visitor's browser, and the question would land in access logs and history. A JSON body needs a preflight that other origins do not pass. The endpoint answers 415 to non-JSON bodies and 405 to GET. `curl -N -H 'Content-Type: application/json' -d '{"q":"…"}'` still works.
2. **Relevance gate = vector similarity ≥ 0.5 OR a full-text hit.** The measured numbers show vector similarity alone barely separates exact-term questions (0.469) from off-topic ones (0.446). A full-text hit means every question word occurs in the chunk, a strong signal. 0.5 rejects every measured off-topic question and accepts every measured on-topic one except the flushpackets question, a known false negative recorded in the guide. SL-33 recalibrates on labelled data.
3. **Skip the model on "I don't know"** instead of asking it to decline. This is cheaper, deterministic and testable. The model's own "not in the context" behaviour inside an answered run is left to SL-33's groundedness score.
4. **Output guard: per-request canary + 12-word overlap not found in the context, checked on the accumulated text after each delta.** Tokens already sent cannot be recalled. The `replace: true` flag tells the client to remove them. Holding back a window of tokens would delay every honest answer for a rare attack. This trade-off is written into the guide.
5. **Input guard is a regex list in `resources/guards/prompt-injection.v1.php`** (name → pattern), with the file version and pattern name logged in `refusal_reason` (`input:prompt-injection.v1:<name>`). The guide shows one rephrasing that passes it. That makes the reference design's point that the structural marking is the real defence.
6. **Citations parsed server-side** from `[n]` markers and sent as an event. The client does not have to trust its own parsing, and the trace row gets `cited_chunk_ids`.
7. **Three nullable columns instead of a JSON "extra" column.** They are queryable and self-describing. jsonb for the id lists keeps their order.
8. **Context budget 4 000 estimated tokens** under `num_ctx` 8 192. This leaves room for the prompt, the question and 400 output tokens. The SL-32 chunker allows oversize tables up to ~2 700 tokens.
9. **Throttle 10 per minute per IP**, lower than describe's 20. An answer has a longer prompt and holds the GPU longer.

## Open questions

Clarity gate 2026-11-03: a fresh-context reviewer raised 15 items. All were answered under standing approval and folded into the spec.

- [x] Q1: Is `sources` sent on `no_context`? **No.** AC-3 and the "Stream contract" order table: `no_context` → `[DONE]`.
- [x] Q2: What is `retrieved_chunk_ids`? It is what the model was given; on `no_context` runs it is what search returned before the gate (AC-7 note, AC-3).
- [x] Q3: What is the input `refusal_reason` format? `input:prompt-injection.v1:<name>` (AC-4, Decision 5).
- [x] Q4: What is the canary, and are prompt files indexed? The canary is per request and random, and never on disk. Prompt files are not in the index: the repo corpus is the root files, `docs/`, `docs/adr/` and done spec-lites. Overlap with retrieved context is allowed ("Output guard").
- [x] Q5: How are words split, and what is protected? Unicode letter/digit runs, lowercased; only the system prompt file is protected; runs found in the context or question are allowed ("Output guard").
- [x] Q6: Which database do tests use? PostgreSQL. `phpunit.xml` uses the pgsql `testing` DB (the CLAUDE.md MariaDB/SQLite note is stale), so jsonb and full-text work in tests. Embeddings and the model are faked with the existing fakes (`Embeddings::fake` as in `DocsSearchTest`, the fake-stream provider for text).
- [x] Q7: Is the gate per chunk? **Yes** (AC-3).
- [x] Q8: What counts as a `[n]` marker? Defined in the "Stream contract"; markers are parsed at `stream_end` on the full text; the output guard runs on the raw text.
- [x] Q9: What are the payload shapes? Fixed in the "Stream contract" table. Manual step 1 is aligned with it.
- [x] Q10: What do the non-happy paths send and record? The order table plus the AC-7 note: no `citations` after a refusal or error; Stop is not finished on FPM, as in SL-46; output refusals record TTFT and any tokens.
- [x] Q11: Does Server-Timing work on an SSE response? Yes. Retrieval runs before the response starts, so `AddServerTimingHeader` sets a real header. The page reads it from the response headers (AC-9). An embedding failure is a 503 JSON error, not a stream event.
- [x] Q12: What are the budget details? Body only, code points, RRF order, cut at whitespace with `mb_substr` (AC-8).
- [x] Q13: How is the context escaped? `htmlspecialchars` with `ENT_QUOTES | ENT_SUBSTITUTE` on bodies and attributes (AC-6).
- [x] Q14: How do validation and the throttle count? Length in code points after trim; blank is 422; every validated request counts toward the throttle (AC-9).
- [x] Q15: Where do provider and model come from on refused and `no_context` runs? The active configuration. Input refusals send no `sources` ("Stream contract").

## Dependencies

- **Requires**: SL-32 (index + hybrid search), SL-20/SL-22 (streaming, run log), SL-46 (lab UI).
- **Blocks**: SL-33, SL-40, SL-41.

---
Next: `/sdd spec-20261103-1028-docs-chat-answer` (approval → build)
