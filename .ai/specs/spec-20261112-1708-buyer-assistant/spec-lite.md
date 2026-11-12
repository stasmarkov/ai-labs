---
id: spec-20261112-1708-buyer-assistant
title: Buyer assistant with a SearchListings tool
type: feature
size: L
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-12; standing approval)
approved_at: 2026-11-12T15:10:20Z
created: 2026-11-12
stacks: [laravel]
slice: SL-25
brs: [BR-26, BR-27, BR-90, BR-91, BR-94, BR-97]
---

# Buyer assistant with a SearchListings tool

## Summary

`/ai/assistant` is a chat where a buyer asks car-buying questions, such as "Do you have a diesel BMW under 20000 EUR in Lviv?". An agent answers by calling one tool, `SearchListings`. Its argument schema is the NL-search filter shape (SL-23), and every call is validated by the same `ListingFilterRules` before the existing listings search runs. The tool never builds a query itself.

- **Step limit:** a question takes at most 4 tool steps (BR-94).
- **Repair:** when the model sends invalid arguments, the tool answers with the validation errors so the model can retry once.
- **Logging and timeline:** every step is logged (BR-90) and shown as a step timeline.
- **Settings:** the feature key `assistant` sits in `AiProviders` and appears on `/ai/settings`.
- **Eval:** a labelled set of 30 questions measures tool-call success, steps per question and latency for qwen3.5:9b and gemma4:12b.
- **Guide:** a capability guide explains it.

## Context

- **Problem**: the lab has structured output (SL-23) but no agent that decides on its own when and how to call a tool. Tool calling, step limits, argument repair and logging are the core of every assistant.
- **SDK support** (laravel/ai, installed):
  - the `Laravel\Ai\Contracts\Tool` contract (`description`, `schema`, `handle`);
  - the `#[MaxSteps]` attribute driving `TextGenerationLoop`;
  - the `InvokingTool`, `ToolInvoked` and `ToolFailed` events with the invocation id, tool invocation id, arguments and result.
- **BR-97** (no write action without confirmation) is satisfied by design: the assistant has **only read-only tools**. The guide says so, and a test pins that every registered tool is read-only.

## Scope

### In scope

- **Agent.** `App\Ai\BuyerAssistantAgent` implements `HasTools`, carries `#[MaxSteps(4)]` and uses the versioned prompt `resources/prompts/buyer-assistant.v1.md`. It uses the feature key `assistant` (`AI_ASSISTANT_PROVIDER` / `_MODEL`; default: the text default). Its streamed answer reuses the SL-45 SSE protocol and the Stop handling.
  - The prompt tells the model to use the tool for any question about available cars, to answer only from tool results, to say when nothing matched, and never to invent listings.
- **Tool.** `App\Ai\Tools\SearchListings`, read-only.
  - **Schema:** the SL-23 filter fields (make, model, city, fuel, `min_year`, `max_price_eur`), all nullable, plus `limit` (1–10, default 5).
  - **`handle()`:**
    1. validate the arguments with `ListingFilterRules`;
    2. on success, call the existing listings search service and return compact JSON: the total and up to `limit` listings (id, year, make, model, price, city, fuel, plus a URL path);
    3. on failure, return `{"error":"invalid_arguments","errors":{…}}` without querying.
- **Repair.** At most one repair per question: after the first invalid call, the tool result carries the errors and the model may call again. A second invalid call returns `{"error":"invalid_arguments","final":true}`, the prompt tells the model to answer without searching, and the step is marked `gave_up`. Repairs count toward the 4-step limit.
- **Step log.**
  - **Table.** A listener on `InvokingTool` / `ToolInvoked` / `ToolFailed` writes `ai_tool_steps`:
    - `invocation_id` (the `ai_runs` row of the turn), `tool_invocation_id`, `step` (1–4), `tool`;
    - `arguments` (json), `status` (`ok`, `invalid_arguments`, `gave_up` or `failed`);
    - `result_summary`: the total and the returned ids, never the full payload;
    - `latency_ms`, `created_at`.
  - **Turn rows.** The `ai_runs` row of the turn records the whole turn as today (provider, model, prompt version, tokens, latency). New counters: `tool_steps`, plus `tool_errors` on the row.
- **Conversation.** Up to 6 earlier turns are sent as history, the same rule as SL-45 chat. History is kept in the page (client-side), with no server session.
- **Lab page `/ai/assistant`.**
  - A chat column with a provider selector (the SL-51 lab selector) and a Stop button.
  - The SL-48 "How it works" panel, driven by new events (`tool_call`, `tool_result`, `tool_error`) emitted on the SSE stream.
  - A step timeline beside the answer. Each tool step shows its number, the arguments (pretty JSON), the status badge, the result summary (total, top ids as links to `/listings/{id}`) and latency.
  - The answer text, the run link, and an empty state.
  - Example question chips.
  - A navigation entry and a capability on the overview and home page.
- **Input guard** first (SL-45): a refused question makes no model call.
- **Eval.** `assistant:eval {--models=qwen3.5:9b,gemma4:12b}` runs the 30 questions in `bench/assistant/questions.v1.jsonl`, each labelled with:
  - `expects_tool` (bool);
  - `expected_filters` (the subset of fields a correct first valid call must contain, compared exactly);
  - `max_steps_ok` (the steps a good answer needs).

  The mix is 18 search questions, 6 follow-ups that need two searches (for example "and in Kyiv?"), and 6 questions that need no tool (general buying advice).

  Per model it records:
  - **tool-call precision:** a tool was called only when expected;
  - **tool-call recall;**
  - **argument validity on the first try;**
  - **repair success rate:** an invalid call followed by a valid one;
  - **filter accuracy:** the first valid call matches `expected_filters`;
  - **steps per question:** mean and maximum, with a hard check that the maximum is at most 4;
  - **answers citing only returned ids:** listing ids in the answer text that the tool did not return count as hallucinated;
  - **latency p50 and p95 per question.**

  Results go to `bench/results/assistant/<date>/run.json`, and `BENCHMARKS.md` gets a section in the existing format (BR-26).
- **Guide and docs.**
  - `resources/guides/assistant.php` (capability, six tabs), including why only read-only tools exist (BR-97) and how to add a write tool with confirmation.
  - §16 lists the files, and the README gets a paragraph.

### Out of scope

- Write tools and confirmation flows.
- More tools than `SearchListings`.
- Server-stored conversations.
- MCP (SL-35).
- A hand-written agent loop (SL-34).

## Must NOT change

- **MNC-1**: The `/listings` and `/api/listings/nl` endpoints, `ListingFilterRules` and the listings search service behave as before; the tool calls them unchanged.
- **MNC-2**: The SL-45 chat, its SSE events and its Stop handling are unchanged; the new events are added only on the assistant stream.
- **MNC-3**: Existing features on `/ai/settings` are unchanged; `assistant` is a new row.
- **MNC-4**: The suite stays offline (SDK fakes for the agent and its tool calls).
- **MNC-5**: The fingerprint is unchanged and parity is identical.

## Acceptance criteria

- **AC-1**: WHEN a buyer asks a search question THE SYSTEM SHALL let the agent call `SearchListings` with filter arguments, validate them with `ListingFilterRules`, run the existing listings search only for valid arguments, and answer from the returned listings.
- **AC-2** (BR-94): WHEN a question is answered THE SYSTEM SHALL stop after at most 4 tool steps, repairs included.
- **AC-3**: IF the model sends invalid arguments THEN THE SYSTEM SHALL return the validation errors as the tool result and allow one more call. IF that call is invalid too THEN it SHALL return a final error, and the step SHALL be recorded as `gave_up`, without querying.
- **AC-4** (BR-90): WHEN a turn completes, fails or stops THE SYSTEM SHALL have written one `ai_runs` row with `tool_steps` and `tool_errors`, plus one `ai_tool_steps` row per tool step with arguments, status, result summary and latency.
- **AC-5**: WHEN the buyer asks on `/ai/assistant` THE SYSTEM SHALL stream the answer and show each tool step in the timeline as it happens, with status, arguments, result summary and links to the listings.
- **AC-6** (BR-97): WHEN the agent's tools are listed THE SYSTEM SHALL contain only read-only tools, and a test SHALL fail if a tool that writes is registered.
- **AC-7** (BR-91): WHEN `assistant` is switched on `/ai/settings` or by env THE SYSTEM SHALL run the agent on that provider and model.
- **AC-8** (BR-26): WHEN `assistant:eval` runs THE SYSTEM SHALL write the per-model metrics listed in scope, and `BENCHMARKS.md` SHALL present them.
- **AC-9** (BR-27): WHEN `/ai/guides/assistant` renders THE SYSTEM SHALL show six non-empty tabs, and the no-references test SHALL pass on it and on `/ai/assistant`.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Safety | Tool arguments never reach SQL except through `ListingFilterRules`-validated values passed to the existing search service; the input guard runs before the model | `BuyerAssistantToolTest` |
| **NFR-2** | Bounded cost | At most 4 steps, `limit` at most 10 listings per call, results summarised to at most 10 listings in the tool result | `BuyerAssistantAgentTest` |
| **NFR-3** | Honest answers | The eval reports the share of answers that mention listing ids the tool did not return | eval run.json |

## Rollout

- **Flag**: none.
- **Deploy order**: migration (`ai_tool_steps`, the two `ai_runs` columns), then code.
- **Rollback**: revert and `migrate:rollback`.

## Boundaries

- **Always**:
  - Validate tool arguments with the shared rules.
  - Read-only tools only.
  - Log every step.
  - Fake the agent in tests.
  - Update the guide, §16, the README and `BENCHMARKS.md`.
- **Ask first**:
  - Any write tool.
  - A new package or model.
  - Storing conversations on the server.
- **Never**:
  - Let the model build SQL or a URL query directly.
  - Exceed 4 steps.
  - Store full tool payloads in logs.

## Technical context

- **Related code**:
  - `app/Ai/ListingFilterAgent.php`, `app/Rules/ListingFilterRules.php` and `app/Services/NlSearchService.php` (SL-23);
  - the listings search service behind `/listings`;
  - `app/Ai/DocsAnswerAgent.php`, `DocsAnswerProtocol` and the chat page (SL-45: SSE, history, Stop);
  - `app/Ai/AiProviders.php` (features);
  - `app/Ai/RecordAiRun.php` (`ai_runs` listener);
  - `resources/js/ai/composables/useEventStream.ts`;
  - `resources/js/ai/components/LabHowItWorks.vue`;
  - `vendor/laravel/ai/src/Attributes/MaxSteps.php`, `Events/ToolInvoked.php`, `InvokingTool.php`, `ToolFailed.php`, `Gateway/TextGenerationLoop.php`.
- **Already implemented**: the provider resolver and settings, the input guard, the SSE protocol, the guide engine, the run detail page.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **Repair runs through the tool result**, not a separate retry around the model. The model sees its own error and corrects itself, which is the standard tool-calling pattern and what the guide teaches. The single-repair limit is enforced in the tool by counting invalid calls per turn.
- **Read-only tools satisfy BR-97.** Adding a write tool needs the owner's approval and a confirmation flow (a later slice).
- **Answer grading is not an LLM judge.** The eval checks tool behaviour and id honesty, which are deterministic. Answer quality is shown for reading in the lab, not scored.

## Clarity gate answers (binding, self-decided 2026-11-12)

1. **Filters the tool doesn't have.**
   - The schema stays the SL-23 filters exactly (make, model, city, fuel, `min_year`, `max_price_eur`) plus `limit`. There is no body type and no radius.
   - The prompt tells the model to say plainly which wishes it cannot filter on ("estate", "near") instead of guessing. The eval has 4 questions that test this; their label lists `unsupported` words that the answer must mention.
   - Prices are converted to whole EUR by the model ("20k" → 20000). Other currencies are unsupported and must be said so.
   - The summary and manual examples are corrected to "a diesel BMW under 20000 EUR in Lviv".
2. **Step definition.** A tool step is one `SearchListings` call, parallel calls counted one by one.
   - The tool counts calls per turn. A 5th call returns `{"error":"step_limit"}` without querying, and is logged with status `step_limit`.
   - `#[MaxSteps(6)]` bounds the model round-trips so that 4 tool calls plus the final answer always fit.
   - `ai_tool_steps.step` numbers calls 1 to 4 in the order they were invoked.
3. **Repair rule.**
   - **Where the state lives:** a scoped per-turn `ToolTurn` object (bound per request or job), never static. It holds the call count and the invalid count.
   - **Statuses:**
     - the first invalid call is `invalid_arguments`;
     - a second invalid call in the same turn is `gave_up` and returns `final:true`;
     - any later call after `final` is `gave_up` again, without querying;
     - a valid call does **not** reset the invalid count, so at most one repair per turn even with two searches.
   - `failed` is an exception inside the search.
   - `tool_errors` counts `invalid_arguments`, `gave_up`, `step_limit` and `failed`.
4. **Link to `ai_runs`.**
   - `ai_tool_steps.invocation_id` stores the SDK invocation id, which is the same id `RecordAiRun` writes to `ai_runs.invocation_id` (no foreign key; joined by value, as the run detail page already does).
   - Steps are written when each tool event fires, so they can exist before the run row.
   - On Stop, the steps already written stay, a step in progress gets no row, and the run row is `stopped` with the counters of the steps written.
5. **Eval scoring.**
   - **Filter match:** after normalisation (strings trimmed and lowercased, city aliases `kiev`→`kyiv`, numbers as integers), every key in `expected_filters` must equal the call's value. Keys not in the label are ignored.
   - **Follow-ups:** each line carries `history`, a list of `{question, answer}` pairs scripted in the file, sent as history.
   - **Precision and recall:** per question (did it call the tool at least once). The step count is checked against `max_steps_ok` as "steps within the expected" (a rate).
   - **Latency:** per question, from request to final answer, reported as p50 and p95.
6. **Made-up listing ids.**
   - The prompt requires every listing mentioned to be written as a link `/listings/{id}`.
   - Ids are extracted from that pattern only; an id not returned by any tool call in the turn counts as made up.
   - The rate is reported, together with answers that mention listings without links.
7. **History.**
   - A turn is one question with its final answer.
   - Up to 6 earlier turns go as plain text; each earlier answer also carries the arguments of its last valid call as a compact line `(searched: {json})`, so follow-ups like "and in Kyiv?" keep the filters.
8. **Contracts.**
   - **Search:** the tool calls `ListingSearchService::search(ListingFilter::fromValidated($valid, perPage: $limit))`. `limit` is validated by the tool itself (`integer|between:1,10`), next to `ListingFilterRules`.
   - **All-null filters:** a call with every filter null is valid (it returns the newest listings, limit applies).
   - **SSE events, in order per call:**
     - `tool_call {step, arguments}`;
     - then `tool_result {step, status, total, ids}` for an ok call, or `tool_error {step, status, errors}` for `invalid_arguments`, `gave_up`, `step_limit` or `failed`;
     - then the SL-45 `text_delta` / `stream_end`.
   - **Limits:** the `assistant` throttle is 20 per minute per IP, plus the SL-51 `cloud-ai` limiter when the provider is a cloud one.
   - **AC-6:** a tool is read-only when it implements `App\Ai\Tools\ReadOnlyTool` (a marker interface). The test fails when any tool in `tools()` lacks it.
   - **Guide:** six tabs (How it works, Implementation, Modify, Support, Numbers, Experiments). §16 is section 16 of `docs/learning-guide.md`.

## Open questions

- [x] Clarity gate (8 items) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90, BR-91, BR-94 and BR-97 come from a draft brief applied in every slice (standing approval).
