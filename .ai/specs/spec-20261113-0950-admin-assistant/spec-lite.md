---
id: spec-20261113-0950-admin-assistant
title: Admin assistant with a hand-written agent loop
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-13; standing approval)
approved_at: 2026-11-13T07:54:58Z
created: 2026-11-13
stacks: [laravel]
slice: SL-34
brs: [BR-94, BR-97, BR-90, BR-91, BR-100, BR-27]
---

# Admin assistant with a hand-written agent loop

## Summary

The buyer assistant (SL-25) lets the Laravel AI SDK run its tool loop, so the loop itself is hidden. This slice writes that loop by hand. The developer chats with an admin assistant at `/ai/admin-assistant`, on the local machine only. It talks to Ollama's OpenAI-compatible `/v1/chat/completions` API with a plain HTTP client and five listing tools:

| Tool | Kind |
|---|---|
| `searchListings` | read |
| `getListing` | read |
| `countByTag` | read |
| `updateTitle` | write |
| `addTag` | write |

Each turn of the loop runs as follows:
1. Send the messages and the tool definitions.
2. Read the `tool_calls` from the answer.
3. Check each call in PHP and run the read tools.
4. Stop at every write and show the developer a confirmation card with the exact change.
5. Apply the write only after the developer clicks Confirm.
6. Feed the result back into the conversation.

Every model call and every tool step is logged, and a question takes at most 4 tool steps.

## Context

- **Problem:** the SDK's agent loop (`BuyerAssistantAgent`, `#[MaxSteps]`, the `InvokingTool` events) works, but a learner cannot see what is sent at each turn, how `tool_calls` come back, where a step limit or a confirmation fits, or how a write is kept out of the model's hands. ADR 0001 reserves Ollama's `/v1` API for exactly this lesson.
- **Data:** listings have no title or tags today.
  - The listing page heading is make and model (`resources/views/listings/show.blade.php`).
  - This slice adds a nullable `listings.title` override and a `listing_tags` table.
- **Access:** the app has no login. Like `/ai/settings` (BR-91), the admin assistant exists only when the app runs locally with its switch on, and answers 404 otherwise.

## Scope

### In scope

1. **Schema:**
   - The migration `add_title_to_listings`: `title` varchar(120), nullable.
   - The migration `create_listing_tags`:
     - columns `id`, `listing_id` (foreign key, cascade on delete) and `tag` varchar(30);
     - a unique key on `(listing_id, tag)` and an index on `tag`;
     - `created_at`.
   - The migration `extend_ai_tool_steps_outcomes`: the outcome check gains `pending`, `confirmed` and `rejected`.
   - All three migrations are reversible.
   - The `Listing` model gets `title` and a `tags()` relation, and a `ListingTag` model plus a factory are added.
2. **Listing page:** the `<h1>` and the page title show `title` when it is set, and make and model otherwise (unchanged). Tags are shown as small chips under the heading when there are any. A listing with no title and no tags renders byte-identically to today.
3. **Tools** (`app/Ai/AdminTools/*`):
   - Each tool is a class with a `name`, a `description`, a JSON-schema `parameters` and `isWrite()`, plus two methods:
     - `validate(array $args): list<string>` checks the arguments in PHP before anything runs.
     - `run(array $args): array` returns a capped result.
   - `searchListings`:
     - takes the SL-25 filters, checked by `ListingFilterRules`;
     - `limit` is 1..10, with a default of 5;
     - returns id, make, model, year, price, city, title and tags per row.
   - `getListing`:
     - takes `id`;
     - returns the listing fields, title, tags and the description from `listing_documents`, truncated to 500 characters with an ellipsis and `truncated: true`;
     - an unknown id is an error.
   - `countByTag`:
     - takes `tag`, 2 to 30 characters matching `^[a-z0-9-]+$`;
     - returns `{tag, count}`.
   - `updateTitle` (write):
     - takes `id` and `title`, 3 to 120 characters after trimming, with no control characters;
     - the result holds the old and new title.
   - `addTag` (write):
     - takes `id` and `tag` (the same rule as above);
     - it is idempotent: an existing tag returns `already_tagged: true`.
   - Every result is capped at 4000 characters of JSON. Larger results are cut with `truncated: true`.
4. **The loop** (`AdminAssistantLoop`, no SDK):
   - **Request:** a `POST` to `{ollama url}/v1/chat/completions`, sent through Laravel's `Http` client with:
     - `model`, `messages` and `tools` (OpenAI function format);
     - `tool_choice: auto` and `stream: false`;
     - `temperature: 0`.
   - **System prompt:** a versioned file, `resources/prompts/admin-assistant.v1.md`.
   - **Per answer:** each `tool_calls` entry is parsed: its `arguments` are a JSON string, and invalid JSON is a tool error.
     - A read tool that passes `validate` runs, and its result goes back as a `role: tool` message whose content is wrapped in `<tool_result>…</tool_result>` and marked as data (BR-100).
     - A tool that fails `validate` returns `{error: "invalid_arguments", details}` to the model (outcome `error`).
     - A write tool that passes `validate` stops the loop. It stores a **pending action** and returns it to the client, and nothing has been written yet.
   - **Step cap:** at most 4 tool steps per question (BR-94), counted across confirmation pauses. A fifth call is not run. The loop then sends one last request with `tool_choice: none` and returns `limit_reached: true`.
   - **Turn cap:** the loop also stops after 6 model calls per question as a safety net.
   - **Model:** the feature key `admin-assistant` in `AiProviders` resolves the model (BR-91: settings page row, config, default). The provider must be `ollama`, and any other provider is refused with 422 `provider_not_supported`. The timeout is the provider's.
5. **Conversation and confirmation state** live on the server:
   - **Conversation:** the conversation is a cache entry (`admin-assistant:conv:{uuid}`, TTL 60 min) holding the messages and the step count.
   - **Pending action:** at most one at a time, stored in the conversation as tool, arguments, `tool_call_id`, a preview (for a title: the old and new title; for a tag: the listing and the tag), a random 32-character `action_id` and `expires_at` (10 minutes).
   - **Confirm or reject:** `POST /api/admin-assistant/actions/{action_id}` with `{conversation_id, decision: confirm|reject}`.
     - **Confirm:** the write runs once in PHP, inside a DB transaction, after `validate` runs again, and the step is logged `confirmed`.
     - **Reject:** nothing is written, and the step is logged `rejected`.
     - Either way the tool message goes back to the model (`{"status":"applied", …}` or `{"status":"rejected_by_developer"}`), and the loop continues.
   - **Refused:**
     - an unknown, expired or already-used action, or one from another conversation, gets 409 `action_not_pending` and writes nothing;
     - a new question while an action is pending gets 409 `action_pending`.
   - **What the model cannot do:** it cannot confirm. Only this endpoint can, and a "yes" typed in the chat is just a question.
6. **Endpoints**, which are 404 unless local and `AI_ADMIN_ASSISTANT` is on (default on locally):
   - `POST /api/admin-assistant/ask`:
     - takes JSON `{question, conversation_id?}`, with the question at most 500 characters;
     - replies with JSON `{conversation_id, answer, steps[], pending_action|null, limit_reached, run_ids[]}`.
   - `POST /api/admin-assistant/actions/{action_id}`: the same reply shape.
   - Both:
     - are JSON-only;
     - use the cross-site refusal (`RefuseCrossSiteRequests`);
     - have a rate limit (`AI_ADMIN_ASSISTANT_PER_MINUTE`, default 20).
   - The question passes `PromptInjectionGuard` first: a match is refused before any model call.
7. **Logging:**
   - **Model calls (BR-90):** one `ai_runs` row per model call, with feature `admin-assistant`, provider, model, prompt version, tokens in and out from `usage`, latency and status.
   - **Tool steps:** one `ai_tool_steps` row per tool step, with position, tool, arguments, outcome (`ok`, `error`, `failed`, `pending`, `confirmed` or `rejected`), error, result summary and duration.
   - **Writes:** a write logs its `pending` step at the proposal and a second row, `confirmed` or `rejected`, at the decision.
8. **Lab page** `Lab/AdminAssistant.vue` at `/ai/admin-assistant`:
   - chat, example questions, a New chat button;
   - a step timeline showing the tool, the pretty-printed arguments, the outcome and the capped result;
   - a confirmation card with a before-and-after preview and Confirm / Reject buttons;
   - "limit reached" and "refused" states;
   - a link to each logged run.
   The page also needs a navigation entry, a capability line, a settings row (the feature key) and a local-only badge.
9. **Docs:**
   - the guide `resources/guides/admin-assistant.php` (six tabs: how the loop works turn by turn, the raw request and response shapes, permissions in PHP, how to add a tool, how to support it);
   - a learning-guide §16 section;
   - README lines;
   - `.env.example` entries.

### Out of scope

- Streaming the answer.
- Cloud providers for this feature.
- Deleting or editing anything other than the title and tags.
- Removing a tag.
- An eval set: the behaviour is proven by scripted tests; no model comparison here.
- Authentication: the guard is local-only.
- MCP (SL-35).

## Must NOT change

- **MNC-1:** the buyer assistant (`/ai/assistant`, `/api/assistant/ask`, its tool and its steps) behaves and logs exactly as today.
- **MNC-2:** the listing page for a listing with no title and no tags is byte-identical. The parity capture differs only in `captured=`.
- **MNC-3:** existing `ai_tool_steps` rows and outcomes stay valid. The migration only widens the check.
- **MNC-4:** outside the local environment, or with `AI_ADMIN_ASSISTANT=false`, there are no admin-assistant routes in practice (404) and no settings row.
- **MNC-5:** the default test suite makes no network call.
- **MNC-6:** the asset fingerprint of the public site is unchanged. Only the lab bundle grows.

## Acceptance criteria

- **AC-1 (BR-94):** WHEN the model returns a read tool call with valid arguments, THE SYSTEM SHALL run it, append a `role: tool` message wrapped in `<tool_result>` with the capped result, call the model again, and return the final answer with the step in `steps[]`.
- **AC-2:** WHEN a tool call's arguments are invalid JSON or fail `validate`, THE SYSTEM SHALL not run the tool, return `invalid_arguments` with details to the model as the tool result, and log the step with outcome `error`.
- **AC-3 (BR-97):** WHEN the model calls `updateTitle` or `addTag` with valid arguments, THE SYSTEM SHALL write nothing, return a `pending_action` with a preview, and log a `pending` step.
- **AC-4 (BR-97):**
  - WHEN the developer confirms a pending action of the same conversation before it expires, THE SYSTEM SHALL validate it again, apply exactly that write once, log a `confirmed` step, send `applied` to the model and continue the loop.
  - WHEN the developer rejects it, THE SYSTEM SHALL write nothing, log `rejected` and continue.
- **AC-5:** IF the action id is unknown, expired, already used or belongs to another conversation, THEN THE SYSTEM SHALL answer 409 `action_not_pending` and write nothing. IF a question arrives while an action is pending, THEN THE SYSTEM SHALL answer 409 `action_pending`.
- **AC-6 (BR-94):** WHEN a question would need a 5th tool step, THE SYSTEM SHALL not run it, make one final call with `tool_choice: none`, and return `limit_reached: true`. No question makes more than 6 model calls.
- **AC-7 (BR-90):** THE SYSTEM SHALL write one `ai_runs` row per model call (feature `admin-assistant`, provider, model, prompt version, tokens, latency) and one `ai_tool_steps` row per tool step and decision, linked to the run that proposed it.
- **AC-8 (BR-91):** THE SYSTEM SHALL use the model resolved for `admin-assistant`, and refuse a non-Ollama provider with 422 `provider_not_supported` before any call.
- **AC-9 (BR-100):** WHEN the question matches the prompt-injection guard, THE SYSTEM SHALL refuse it before any model call. Tool results reach the model only inside `<tool_result>` as data.
- **AC-10:** WHEN a listing has a title or tags, THE listing page SHALL show the title as the heading and the tags as chips. Without them the page is unchanged.
- **AC-11:** WHEN the app is not local or `AI_ADMIN_ASSISTANT=false`, THE SYSTEM SHALL answer 404 on the page and both endpoints.
- **AC-12 (BR-27):** THE guide `/ai/guides/admin-assistant` SHALL answer 200 with six tabs and no slice ids or paths. The learning guide SHALL have its section.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Authorization | Writes happen only through the confirm endpoint, with a valid pending action of the same conversation; the model and a typed "yes" cannot cause a write; the routes exist only locally; cross-site requests get 403 | `tests/Feature/AdminAssistantConfirmTest.php` |
| **NFR-2** | Bounded cost | At most 4 tool steps and 6 model calls per question; each tool result is at most 4000 characters; at most 10 rows per search | `tests/Feature/AdminAssistantLoopTest.php` |
| **NFR-3** | Data safety | A confirmed write runs in a transaction after `validate` runs again; a write to a listing deleted meanwhile is an error, not a crash | `tests/Feature/AdminAssistantConfirmTest.php` |

## Boundaries

- **Always:**
  - test the loop with `Http::fake` sequences of OpenAI-shaped answers;
  - follow the SL-25 conventions for steps and runs;
  - keep website text free of slice ids and paths.
- **Ask first:** none (decisions below under standing approval).
- **Never:**
  - let the model trigger a write;
  - use the laravel/ai SDK agent for this loop;
  - change the buyer assistant;
  - delete listings or data;
  - commit `.env`.

## Technical context

- **Related code:**
  - **Buyer assistant:** `app/Ai/BuyerAssistantAgent.php`, `app/Ai/Tools/SearchListings.php` and `app/Rules/ListingFilterRules.php`.
  - **Logging and the guard:** `app/Ai/AiRunRecorder.php`, `app/Ai/RecordToolStep.php` (`ai_tool_steps`) and `PromptInjectionGuard`.
  - **Providers and access:** `app/Ai/AiProviders.php` (features, `settingsAvailable()`), `EnsureLocalSettings` and `RefuseCrossSiteRequests`.
  - **Listings:** `resources/views/listings/show.blade.php` and `listing_documents` (descriptions).
  - **Design notes:** the reference design `.ai/research/ai-chatbot-reference.md` (Tools and MCP) and ADR 0001 (Ollama `/v1` for this slice).
- **Already implemented:**
  - run and step logging;
  - the filter validation rules;
  - the guard;
  - the settings resolver;
  - the guide engine;
  - the lab shell.

## Decisions

- **D-1:** the conversation state lives on the server, in the cache, not in a client-sent history. A client-sent history could carry a forged "confirmed" tool message. (self-decided, standing approval)
- **D-2:** non-streaming JSON per turn. The lesson is the loop: each model call and tool step shows as one timeline row. SL-25 and SL-41 already teach streaming. (self-decided, standing approval)
- **D-3:** Ollama only. The hand-written client speaks the OpenAI-compatible API, and ADR 0001 reserves `/v1` for this slice. Cloud is out of scope. (self-decided, standing approval)
- **D-4:** the title is a nullable override shown on the listing page, so a confirmed write has a visible effect. Tags get their own table with a unique key, so `addTag` is idempotent. (self-decided, standing approval)
- **D-5:** local-only like the settings page (no login in this lab). The switch is `AI_ADMIN_ASSISTANT`, default true, and has effect only when `APP_ENV=local`. (self-decided, standing approval)
- **D-6:** one pending action at a time, so the developer confirms writes one by one, in the order the model proposed them. If the model proposes two writes in one answer, the second waits for the next turn and gets `{"status":"deferred","reason":"one write at a time"}`, which counts as a step. (self-decided, standing approval)

## Open questions

All raised by the clarity gate on 2026-11-13; answered below as C-1..C-20 (self-decided, standing approval).

- [x] Step counting, several calls per answer, reads next to a write → C-1, C-2
- [x] Call cap across pauses, the final call → C-3
- [x] Expired action, unknown conversation, races → C-4, C-5
- [x] Failure at confirm, outcomes `error` vs `failed`, Ollama failures, time limit → C-6, C-7
- [x] Listing page CSS and caching → C-8
- [x] Tool-result wrapping, guard on arguments, refused reply → C-9
- [x] `steps[]`/`run_ids[]` scope, linking, positions, prompt version → C-10
- [x] Caps and truncation, the description, search totals, tag filter → C-11
- [x] Title and tag rules, previews → C-12
- [x] Gate, routes, tests, rate limit, JSON-only, settings row → C-13, C-14
- [x] Conversation lifetime, history size, New chat → C-15
- [x] Answer text and thinking → C-16
- [x] Down migration, snapshot, `listing_tags` timestamps → C-17
- [x] Typed "yes" vs 409, manual-test data, action id format → C-18
- [x] Guide tabs, run links, capability line, learning guide → C-19
- [x] Model support for tools → C-20

## Clarifications

- **C-1 Step:** one tool step is one entry in `tool_calls` that the loop handles. Every handled call counts against the 4-step cap: a run, an `invalid_arguments` error, a proposal and a `deferred` reply alike.
  - The confirm or reject decision is not a new step. It completes the proposal's step, but it still gets its own `ai_tool_steps` row with the next position.
  - Calls that are not handled because the cap is reached get the tool message `{"status":"not_run","reason":"step_limit"}`. They are logged with outcome `error` and error `step_limit`, but they do not count, because the cap is already reached.
- **C-2 Order within one answer:** the calls are processed in the order the model gave them.
  - Reads before the first write run.
  - The first valid write becomes the pending action.
  - Every call after that write, reads included, gets `{"status":"deferred","reason":"one change at a time; ask again after the decision"}`, outcome `error` with error `deferred`. Each still counts as a step.
  - Every `tool_call_id` therefore always gets exactly one `role: tool` reply before the next model call. The write's reply is added at the decision.
- **C-3 Call cap:** the 6 model calls are counted per question and span confirmation pauses. They include the final `tool_choice: none` call.
  - When the step cap is reached, the next call is that final call. When call 6 would still be a normal call, it is sent as the final call instead.
  - If the final call returns `tool_calls` anyway, they are ignored (logged `not_run`). The `answer` is its content, or "I reached the step limit; ask a narrower question." when the content is empty.
- **C-4 Expiry:**
  - **When it is noticed:** a pending action past `expires_at` is treated as expired on the next request. The loop adds the tool message `{"status":"expired"}`, logs a step row with outcome `rejected` and error `expired`, and clears the pending action.
  - **A new question:** it is then allowed, so an expired action never blocks. While the action is still valid, a question gets 409 `action_pending`.
  - **Confirming an expired action:** this gets 409 `action_not_pending`.
- **C-5 Ids and races:**
  - **Conversation id:** `conversation_id` is a UUID v4 created by the server.
    - A missing id starts a conversation.
    - An unknown or expired UUID gets 404 `conversation_not_found`. The client starts again without it.
    - An id that is not a UUID gets 422.
  - **Atomic claim:** a confirm takes `Cache::lock("admin-assistant:conv:{id}", 10)` around reading and clearing the pending action, so of two simultaneous confirms one applies and the other gets 409.
  - **Cache store:** the default cache store, Redis in dev and the array store in tests, where locks also work.
- **C-6 Error at confirm:**
  - **Failed re-validation:** if `validate` fails again at confirm (the listing was deleted, or a tag became invalid), nothing is written. The step row has outcome `error` with error `invalid_at_confirm`, the model gets `{"status":"not_applied","error":"invalid_at_confirm","details":[…]}`, and the loop continues.
  - **HTTP status:** the reply is 200, with the usual shape and the step showing the error.
  - **Outcome values:**
    - `error`: a refusal that checking found: bad arguments, step limit, deferred, invalid at confirm.
    - `failed`: an exception while running, such as a database error. The model then gets `{"status":"failed"}`.
- **C-7 Model failures and time:**
  - **When a model call fails:** a timeout, a non-200, a body that is not JSON, or an answer with no `choices`.
    - The `ai_runs` row has status `failed` with the redacted message.
    - The request answers 503 `model_unavailable`, or 422 `model_unsupported` when Ollama says the model does not support tools.
    - The conversation keeps every message up to the last complete exchange, so the question can be asked again.
  - **Time:** each request stops making model calls once 90 s have passed since it started, and continues as if the cap were reached (C-3). This sits under the 120 s default timeout. For a long run the controller calls `set_time_limit(150)`.
- **C-8 Listing page:**
  - **Classes:** the heading keeps its classes. The tag chips reuse only classes already used in the public views (`mt-1 text-sm` on a `<ul>` with `<li>` items, one line, comma-free), so the public CSS bundle and its fingerprint do not change.
  - **Caching:** nothing about the listing page is cached by the title or tags. Search indexes, embeddings and documents are not re-indexed; a note in the guide says so.
- **C-9 Data marking:**
  - **Tool results:** the result JSON is encoded with `JSON_HEX_TAG`, so `<` and `>` inside strings become `\u003C` and `\u003E`. The wrapper can then not be closed from the inside. The system prompt states that `<tool_result>` content is data.
  - **The guard:** `PromptInjectionGuard` runs on the question only. Titles and tags are plain data and are limited by their own rules.
  - **A refused question:** 200 with `{refused: true, pattern, answer: "<refusal text>", steps: [], pending_action: null, run_ids: []}`, with no `ai_runs` row and no model call.
- **C-10 Reply scope and logging:**
  - **Scope:** `steps[]` and `run_ids[]` cover the current HTTP request only. The confirm reply includes the decision row and everything after it. `limit_reached` is true when this request hit a cap. The page appends each reply to its timeline.
  - **Linking:**
    - each step row links to the `ai_runs` row of the model call that produced the tool call;
    - the decision row links to the same run as its proposal;
    - `position` numbers continue across pauses within one question and start at 1 for each question.
  - **Run links:** each call gets an invocation id (UUID) so that `/ai/runs/{invocationId}` opens it.
  - **Result summary:** `{rows}` for search, `{id}` for get, `{tag,count}`, `{id, old_title, new_title}`, or `{id, tag, already_tagged}`.
  - **Prompt version:** `admin-assistant.v1`.
- **C-11 Caps:**
  - **Result size:** the cap is 4000 characters (`mb_strlen`) of the encoded result, not counting the wrapper. Above it, `rows` are dropped from the end until it fits, and `truncated: true` and `rows_dropped: n` are added. A single `getListing` result fits by design.
  - **Description:** `getListing` uses the listing's one `listing_documents` row (one per listing, keyed by listing id), or `description: null` when there is none. It keeps the first 497 characters plus `...` when the text is longer than 500.
  - **Search:**
    - `searchListings` returns `{total, rows}`, where `total` is the full match count;
    - its arguments are the `ListingFilterRules` keys plus `limit` (validated by the tool, 1..10, default 5) and an optional `tag` (the same tag rule), which filters to listings with that tag.
- **C-12 Title and tag rules:**
  - **Title:**
    - **Trimming:** the stored title is the trimmed value.
    - **Rejected:** any Unicode `Cc` or `Cf` character, newlines and tabs included.
    - **Same title:** the same title as now is a valid write that changes nothing, and `result.changed` is false.
    - **Clearing:** clearing a title is not a tool, so it is out of scope.
  - **Tag:**
    - the tag is lowercased and trimmed, then must match `^[a-z0-9]+(-[a-z0-9]+)*$`, 2 to 30 characters;
    - "Family-Car" becomes "family-car", and "-x" is rejected.
  - **Tag preview:** listing id, make, model, year, the current title or null, the current tags, and the tag to add.
- **C-13 Gate and routes:**
  - **When the feature is on:** `APP_ENV=local` and `config('ai.admin_assistant.enabled')` (env `AI_ADMIN_ASSISTANT`, default true).
  - **The middleware:** `EnsureAdminAssistant` answers 404 otherwise. The routes are always registered, so the route cache is safe.
  - **Tests:** they set `app()->detectEnvironment(fn () => 'local')` or the config the gate reads, the same way the settings tests do.
  - **Where the routes live:** the page goes in `routes/web.php` under the `/ai` group, and the two endpoints go in `routes/catalog.php` next to `/api/assistant/ask`, with the same middleware style plus `RefuseCrossSiteRequests`.
  - **Rate limit:** `throttle:admin-assistant`, keyed by IP and shared by both endpoints.
  - **JSON only:** a non-JSON body gets 415, as on the moderation endpoint. `Accept` is not checked.
  - **Provider check:** the 422 `provider_not_supported` check applies on both endpoints.
- **C-14 Settings row:** it uses the standard row. The settings page does not filter providers per feature. A saved non-Ollama provider is allowed to be saved, then refused by the endpoints with 422 (AC-8), and the row's help text says "Ollama only". No change to the settings page code beyond adding the feature key.
- **C-15 Conversation:**
  - **Lifetime:** the TTL slides, renewed on every request to 60 minutes.
  - **History:** at most the last 40 messages besides the system prompt are sent. Older ones are dropped whole, never in the middle of an assistant–tool pair.
  - **Step count:** it resets per question.
  - **New chat:** it starts a new UUID. An old conversation with a pending action just expires, and nothing is written.
- **C-16 Answer text:** requests send `think: false` where the model accepts it. Any `<think>…</think>` block is stripped from the content. When an answer has both content and `tool_calls`, the content is kept in the history and only the final answer is returned as `answer`.
- **C-17 Migrations:**
  - **Down migration:** the outcome-check migration's down step fails with a clear message if rows with the new outcomes exist; it does not delete data. The implementer reads the existing constraint name (`ai_tool_steps_outcome_check`) from its migration.
  - **Snapshot (MNC-2):** the snapshot test renders `/listings/{id}` before and after the change for a factory listing. `data-price-rendered-ms` is normalised as the parity script does, and the bodies are compared.
  - **`ListingTag`:** it sets `const UPDATED_AT = null`.
- **C-18 A typed "yes" and test data:**
  - **A typed "yes":** it is a question. While an action is pending, any question gets 409 `action_pending`, and the page shows "Confirm or reject the pending change first." Once the action is decided or expired, "yes" is an ordinary question and can never confirm.
  - **Test data:** the manual test uses listings 12 and 13 from the 50 000 seed, which have descriptions. In the "ask again" step, the question is asked again in the same conversation.
  - **`action_id`:** `Str::random(32)`, alphanumeric.
- **C-19 Docs:**
  - **The six tabs:** the guide engine's six: works, failures, recipes, support, numbers and experiments.
    - **Numbers:** shows counts from `ai_tool_steps` for this feature, or the pending note.
    - **Experiments:** a raw request and response walk-through and "add a tool".
  - **Run links:** they use the existing `/ai/runs/{invocationId}` page.
  - **Capability line:** the line on the lab overview that lists each feature (`LabOverviewService`).
  - **Learning guide:** a new subsection in the existing §16.
- **C-20 Model support:** qwen3.5:9b supports tools in Ollama (used by SL-25). Other models are the user's choice, and an unsupported one gets 422 `model_unsupported` (C-7).

