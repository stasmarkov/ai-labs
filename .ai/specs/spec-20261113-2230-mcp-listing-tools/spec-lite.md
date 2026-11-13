---
id: spec-20261113-2230-mcp-listing-tools
title: MCP server for the listing tools
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-13; standing approval)
approved_at: 2026-11-13T20:34:49Z
created: 2026-11-13
stacks: [laravel]
slice: SL-35
brs: [BR-97, BR-27]
---

# MCP server for the listing tools

## Summary

The admin assistant (SL-34) has five listing tools, but only its own loop can use them. This slice publishes the same tools through a Laravel MCP server (`laravel/mcp` 1.1.2, already installed). The developer can then connect Claude Code or Claude Desktop over the local stdio transport and search, read and count listings from that client.

Writes stay behind a human confirmation the client's model cannot give itself (BR-97):
1. A write tool only **proposes** a change. It stores the change as pending and returns a link.
2. The developer opens the link in the browser on a local-only page and clicks Confirm or Reject.
3. The model can then ask for the change's status with a read tool.

The MCP server only groups and describes the tools. The tools reuse the SL-34 tool classes, so validation, caps and the write code are the same.

## Context

- **Problem:** the reference design (`.ai/research/ai-chatbot-reference.md`, Tools and MCP) wants one MCP server per data domain that outside clients connect to, with writes behind confirmation.
  - `laravel/mcp` 1.1.2 offers `Server`, `Tool`, `Mcp::local()` (`artisan mcp:start <handle>`), `Mcp::web()` and tool annotations (`IsReadOnly`, `IsDestructive`, `IsIdempotent`).
  - It has no elicitation, so the server cannot ask the human through the client.
  - A client's own permission prompt for tool calls is client policy: a user may have allowed every call. Neither can stand in for the confirmation BR-97 asks for.
- **Existing code:**
  - `app/Ai/AdminTools/*`, with `AdminTool` (`name`, `description`, `parameters`, `isWrite`, `validate`, `run`), `AdminWriteTool::preview()` and `AdminToolRegistry`;
  - `AdminAssistantConfirmation::apply()`, which validates again and writes in a transaction;
  - the local-only gate pattern (`EnsureAdminAssistant`, `AiProviders::settingsAvailable()`).

## Scope

### In scope

1. **The server:** `app/Mcp/Servers/ListingsServer.php`.
   - It has a `#[Name('ai-lab listings')]`, a `#[Version]` and `#[Instructions]` that explain the propose-then-confirm rule.
   - It is registered in `routes/ai.php` as `Mcp::local('listings', ListingsServer::class)` and started with `php artisan mcp:start listings`.
2. **Tools** (`app/Mcp/Tools/*`): each is a thin `Laravel\Mcp\Server\Tool` that wraps the matching SL-34 `AdminTool`. Its schema is built from the same rules, it validates with `validate()`, and the result has the same caps.

   | Tool | Wraps | Kind |
   |---|---|---|
   | `search_listings` | `SearchListingsTool` | `IsReadOnly` |
   | `get_listing` | `GetListingTool` | `IsReadOnly` |
   | `count_by_tag` | `CountByTagTool` | `IsReadOnly` |
   | `propose_title_change` | `UpdateTitleTool` | none (C-5) |
   | `propose_tag` | `AddTagTool` | none (C-5) |
   | `change_status` | | `IsReadOnly` |

   - **Proposing:** the two `propose_*` tools validate the change, never write the listing, and create a pending change.
     - They return text with the change id, the preview (old and new title; or the listing and the tag), the status `pending`, and the confirm URL `{APP_URL}/ai/changes/{id}`.
     - The text tells the model: "The developer must confirm this in the browser; you cannot confirm it."
   - **`change_status`:** takes `id` and returns `pending`, `confirmed` (with the applied result), `rejected` or `expired`.
   - **No confirm tool:** no tool can confirm, reject or apply anything.
   - **Errors:** invalid arguments return an MCP error response (`Response::error`) with the validation messages, and nothing is stored.
3. **Pending changes** live in the new table `pending_changes`:
   - **Columns:**
     - `id` (uuid, primary key);
     - `source` (`mcp`), `tool`, `arguments` (jsonb), `preview` (jsonb);
     - `status`: `pending`, `applying` (internal, C-1), `confirmed`, `rejected`, `expired` or `failed`;
     - `result` (jsonb, nullable) and `error` (nullable);
     - `expires_at`, `decided_at`, and the timestamps.
   - **Indexes:** on `status` and on `expires_at`.
   - **Model:** `PendingChange` with a factory.
   - **Expiry:** a pending change expires 30 minutes after it is created. It is marked `expired` when it is read or decided after that time.
4. **Confirmation page:** `/ai/changes`, local-only (404 otherwise), an Inertia page `Lab/Changes.vue`.
   - **The list:** pending changes, newest first, each with its preview and Confirm and Reject buttons. Below that, the last 20 decided changes with their outcome.
   - **One change:** `/ai/changes/{id}` shows that change on its own; the link the tool returns points here.
   - **Decision endpoints:** `POST /ai/changes/{id}/confirm` and `POST /ai/changes/{id}/reject`.
     - They take JSON, use the cross-site refusal and a rate limit, and need a CSRF token: they are web routes and the page sends it.
     - **Confirm:**
       - re-checks the change with the tool's `validate()`;
       - applies it once in a transaction, through the same write code SL-34 uses;
       - sets `confirmed` with the result, or `failed` with the error when it is no longer valid.
     - **Reject** sets `rejected`.
     - **Refused:** a change that is not pending (decided, expired or unknown) gets 409 `change_not_pending`, and nothing is written.
     - **Races:** a decision claims the row with a conditional update (`where status = pending`), so two clicks apply it once.
5. **Logging (BR-97 "every tool step is logged"):**
   - **`mcp_tool_calls` table:** one row per MCP tool call, with:
     - `id`, `tool`, `arguments` (jsonb), the result summary (jsonb);
     - `outcome`: `ok`, `error`, `proposed` or `failed`;
     - `error`, `duration_ms`, `pending_change_id` (nullable), `client` (the client name from the MCP initialize, when known) and `created_at`.
   - **Decisions:** confirm and reject are recorded on `pending_changes` (`decided_at`, `status`).
   - **On the page:** each pending change shows the tool call that proposed it.
6. **Gate:**
   - **When the server is on:** `config('ai.mcp.enabled')` (env `AI_MCP_SERVER`, default true) and `APP_ENV=local`.
   - **Server off:** `mcp:start listings` stops with a clear message and exit code 1, and the change routes answer 404.
   - **Settings:** the MCP server makes no model call, so it has no settings row.
7. **Docs:**
   - a guide `resources/guides/mcp.php` with the six tabs:
     - what MCP is and what a server, a tool and an annotation are;
     - how a client starts the server over stdio;
     - why the confirmation is out of band;
     - how to add a tool;
     - how to connect Claude Code (`claude mcp add ai-lab -- ddev exec -T php artisan mcp:start listings`, checked at build time to work through DDEV over stdio, else the working command) and Claude Desktop (a JSON config snippet);
     - the MCP Inspector (`php artisan mcp:inspector listings`);
   - a learning-guide §16 subsection;
   - README lines;
   - `.env.example`;
   - the lab navigation entry ("Changes").

### Out of scope

- An HTTP (`Mcp::web`) transport or OAuth.
- MCP resources and prompts.
- Elicitation.
- Committing a `.mcp.json` to the repo: the guide shows the command instead.
- New tools beyond the SL-34 five plus `change_status`.
- Changing the admin assistant's own confirmation flow.

## Must NOT change

- **MNC-1:** the SL-34 admin assistant (`/ai/admin-assistant`, its endpoints, tools, caps and logging) behaves exactly as today. Shared tool classes keep their behaviour and tests.
- **MNC-2:** the buyer assistant and every other lab page are unchanged.
- **MNC-3:** the public listing pages are unchanged. The asset fingerprint is unchanged, and the parity capture differs only in `captured=`.
- **MNC-4:** the default test suite makes no network call and starts no process.

## Acceptance criteria

- **AC-1:** WHEN a client lists the tools of the `listings` server, THE SYSTEM SHALL return the six tools with descriptions, input schemas and annotations: `IsReadOnly` on the four read tools and none on the two `propose_*` tools (C-5).
- **AC-2:** WHEN `search_listings`, `get_listing` or `count_by_tag` is called with valid arguments, THE SYSTEM SHALL return the same capped result the SL-34 tool returns and log an `ok` row. With invalid arguments it SHALL return an MCP error with the messages and log an `error` row.
- **AC-3 (BR-97):** WHEN `propose_title_change` or `propose_tag` is called with valid arguments, THE SYSTEM SHALL write nothing to the listing, store a pending change, return its id, preview and confirm URL, and log a `proposed` row.
- **AC-4 (BR-97):** No MCP tool SHALL confirm, reject or apply a change. Only the confirm endpoint, reached from the local page, SHALL apply a pending change, once, after validating it again, in a transaction.
- **AC-5:**
  - WHEN the developer confirms a pending change, THE SYSTEM SHALL apply it and set `confirmed` with the result.
  - WHEN they reject it, THE SYSTEM SHALL write nothing and set `rejected`.
  - IF the change is not pending or has expired, THEN THE SYSTEM SHALL answer 409 `change_not_pending`.
  - IF it is no longer valid, THEN THE SYSTEM SHALL set `failed` without writing.
- **AC-6:** WHEN `change_status` is called, THE SYSTEM SHALL return the current status, and the result for a confirmed change.
- **AC-7:** WHEN the app is not local or `AI_MCP_SERVER=false`, `mcp:start listings` SHALL exit 1 with a message, and the `/ai/changes` routes SHALL answer 404.
- **AC-8 (BR-27):** `/ai/guides/mcp` SHALL answer 200 with six tabs and no slice ids or paths. The learning guide SHALL have its section, and README SHALL show how to connect Claude Code and Claude Desktop.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Authorization | A listing changes only through the confirm endpoint, on a local app, with a CSRF token and same-site request; no MCP tool and no client permission setting can cause a write | `tests/Feature/McpChangesTest.php` |
| **NFR-2** | Bounded output | Tool results keep the SL-34 caps (10 rows, 4000 characters, 500-character description, 20 tags) | `tests/Feature/McpListingsServerTest.php` |
| **NFR-3** | Data safety | Two confirms of one change apply it once; a change to a deleted listing ends `failed`, not in an exception | `tests/Feature/McpChangesTest.php` |

## Boundaries

- **Always:**
  - test the server with `laravel/mcp`'s testing helpers (`ListingsServer::tool(...)` style assertions), offline;
  - reuse the SL-34 tool classes rather than copying their rules;
  - keep website text free of slice ids and paths.
- **Ask first:** none (decisions below under standing approval).
- **Never:**
  - add a tool that confirms or applies a change;
  - write a listing from an MCP tool;
  - commit `.env` or a `.mcp.json`;
  - change the SL-34 confirmation flow.

## Technical context

- **Related code:**
  - **SL-34 tools and writes:** `app/Ai/AdminTools/*` (`AdminTool`, `AdminWriteTool::preview`, `AdminToolRegistry`) and `app/Services/AdminAssistantConfirmation.php` (re-validation and the transactional write).
  - **Gate pattern:** `app/Http/Middleware/EnsureAdminAssistant.php` and `RefuseCrossSiteRequests`.
  - **MCP package:** `vendor/laravel/mcp` (Server, Tool, Registrar `local()`, annotations, `StartCommand`, `InspectorCommand`, Testing).
- **Already implemented:**
  - the tools' validation and caps;
  - the write code;
  - the guide engine;
  - the lab shell;
  - the `/ai` route group.

## Decisions

- **D-1:** the confirmation is out of band, in the browser. `laravel/mcp` 1.1.2 has no elicitation. A client's own approval prompt can be switched to "always allow", and a separate confirm tool could be called by the client's model itself. Only a human click on a local page meets BR-97. (self-decided, standing approval)
- **D-2:** pending changes go in a database table, not the cache. They must outlive an MCP session and be listed on a page. (self-decided, standing approval)
- **D-3:** MCP calls are logged in their own table `mcp_tool_calls`. `ai_tool_steps` needs an `ai_runs` row, and here no model runs on our side. (self-decided, standing approval)
- **D-4:** stdio only (`Mcp::local`). The backlog asks for the local transport, and an HTTP transport would need auth. (self-decided, standing approval)
- **D-5:** the write tools are named `propose_*`, so the name itself tells the client's model that calling one does not change anything. (self-decided, standing approval)

## Open questions

All 30 items raised by the clarity gate on 2026-11-13 are answered below as C-1..C-16 (self-decided, standing approval).

- [x] `failed` in `change_status`, the confirm sequence, reusing the SL-34 write code → C-1, C-2
- [x] Gate mechanism, `routes/ai.php`, the manual gate test → C-3
- [x] Expiry marking, read-only status, the "decided" list → C-4
- [x] Annotations → C-5
- [x] Endpoint responses, CSRF, the rate limit, page refresh → C-6
- [x] Duplicate and conflicting proposals → C-7
- [x] The MCP result format, schemas and names → C-8
- [x] `mcp_tool_calls` fields, the client name, logging scope → C-9
- [x] `change_status` edge inputs, the URL base → C-10
- [x] Page details: decided or unknown id, list limits, proposer details, errors → C-11
- [x] Layering → C-12
- [x] Previews, `#[Version]`, `#[Instructions]`, `source` → C-13
- [x] Retention, cross-site semantics, fingerprint scope → C-14
- [x] The Claude Code command over DDEV → C-15
- [x] Traceability placement → C-16

## Clarifications

- **C-1 Statuses:**
  - **Stored values:** `pending_changes.status` is one of `pending`, `applying`, `confirmed`, `rejected`, `expired` or `failed`.
  - **What `change_status` returns:**
    - every status except the internal `applying`, which it reports as `pending`;
    - for a `failed` change, the `error`;
    - for a `confirmed` change, the `result`.

  AC-6 covers all of these.
- **C-2 Confirm sequence:**
  1. A conditional update claims the row: `status = applying` where `status = pending` and `expires_at > now`. If no row is updated, the reply is 409 `change_not_pending`.
  2. The change is applied through a new shared `AdminWriteApplier`, taken out of `AdminAssistantConfirmation::apply` with the same steps: find the write tool, `validate()` again, then `tool->run()` inside the listing repository's transaction.
  3. The applier returns an outcome (`applied`, `invalid_at_confirm`, `tool_failed` or `failed`) and the reply.
  4. The row is then set to `confirmed` with the result, or to `failed` with the error.

  `AdminAssistantConfirmation::apply` calls the same applier. Its behaviour, replies and logged steps stay identical (MNC-1, guarded by the existing SL-34 tests), and the SL-34 flow itself does not change.
- **C-3 Gate and registration:**
  - **Registration:** `routes/ai.php` is new, and `laravel/mcp`'s service provider loads it when it exists. It always registers `Mcp::local('listings', ListingsServer::class)`.
  - **The gate:** a `CommandStarting` listener for `mcp:start` with the handle `listings` throws a `RuntimeException`, "The ai-lab MCP server runs only locally with AI_MCP_SERVER on.", when the gate is off. Artisan prints it and exits 1.
  - **Config:** `config('ai.mcp.enabled')` reads `env('AI_MCP_SERVER', true)`. The manual gate test passes the env to a fresh process, and the config cache is not used in dev.
  - **Pages:** the `/ai/changes` routes and the nav entry are gated on the same two conditions. When the gate is off, the routes answer 404 and the nav entry is hidden.
- **C-4 Expiry:**
  - **Lifetime:** 30 minutes, configurable as `ai.mcp.change_ttl_minutes`.
  - **Effective status:** expiry is computed, not swept. A `pending` row past `expires_at` is shown as `expired` by the page and by `change_status`, without a write, so `change_status` stays read-only.
  - **Storage:** the row is stored as `expired` only when a decision on it is attempted (that request answers 409), and lazily by the page list query.
  - **The page:** it shows pending changes that have not expired, at most 50, newest first. The "recent" list shows the last 20 rows that are confirmed, rejected, failed or expired.
- **C-5 Annotations:**
  - **Read tools:** `IsReadOnly`.
  - **Propose tools:** no destructive or read-only annotation, because they only add a pending row. Their description says "proposes; nothing changes until the developer confirms in the browser". This replaces the `IsDestructive` and `IsIdempotent` annotations in Scope 2 and AC-1.
  - **AC-1 now:** read tools are `IsReadOnly`, propose tools carry no annotation.
- **C-6 Endpoints:**
  - **Confirm:** 200 with `{data: {id, status, result|null, error|null}}`. A confirm that ends `failed` is still 200, with status `failed`.
  - **Not pending or expired:** 409 `change_not_pending`, the same for an unknown id that is a valid UUID.
  - **Bad id:** an id that is not a UUID gets 404 from a route `whereUuid`.
  - **CSRF:** a missing token gets the framework's 419, which is fine and needs no special handling.
  - **The page:** it posts with the shared lab fetch helper (JSON, CSRF header) and reloads the props with an Inertia partial reload.
  - **Rate limit:** `throttle:changes`, 30 per minute per IP, shared by both endpoints.
- **C-7 Proposals:**
  - **Duplicates:** several pending changes for one listing are allowed. Each is confirmed on its own, and the later confirm wins for a title.
  - **Tag already on the listing:** proposing a tag the listing already has stores a pending change whose preview says `already_tagged: true`. Confirming it is then a no-op, which is the SL-34 behaviour.
  - **Tags are free-form:** "a tag that does not exist" is not a case.
  - **Unknown listing:** a listing that does not exist fails `validate()` at propose time, so nothing is stored.
- **C-8 MCP format:**
  - **Results:** each tool returns `Response::json($result)`, or `Response::text` of the JSON-encoded result if the package has no json helper.
  - **AC-2 equality:** the decoded content equals the SL-34 tool's `run()` array, after the same caps.
  - **Schemas:** each tool's schema maps `AdminTool::parameters()` (JSON Schema) to the `JsonSchema` builder, with the same properties, types, limits and required fields.
  - **Names and descriptions:** the names are the MCP ones in the Scope table. The descriptions are rewritten for MCP clients, and the propose tools state the confirmation rule.
- **C-9 Logging:**
  - **Every call is logged**, including `change_status`.
  - **Outcomes:** `ok` for a read that returned; `error` for a validation refusal or an unknown change id; `proposed` for a stored proposal; `failed` for an exception.
  - **Result summary:** the same shape as SL-34's summaries, plus `{id, status}` for `change_status` and `{change_id}` for proposals.
  - **Duration:** `duration_ms` is the time spent in the tool's handle.
  - **Arguments:** stored as given, cut to 2000 characters of JSON.
  - **No `client` column:** `laravel/mcp` 1.1.2 does not expose the client's initialize info to tools, so it is dropped from Scope 5.
- **C-10 Edge inputs and the URL:**
  - **`change_status` ids:** an id that is not a UUID, or an unknown one, returns an MCP error "No change with this id." and is logged as `error`.
  - **Confirm URL:** built with `url('/ai/changes/'.$id)`, so `APP_URL` without a trailing slash, which is `https://ai-lab.ddev.site` in `.env.example` and in the container.
- **C-11 Page details:**
  - **A decided id:** `/ai/changes/{id}` shows the change read-only, with its outcome.
  - **An unknown id** gets 404.
  - **Proposer details:** each change shows its proposing call's time, tool and arguments, found through `mcp_tool_calls.pending_change_id`.
  - **Errors:** a `failed` change shows its error text, the validation messages joined, or "the change could not be applied".
  - **What `error` holds:** a string of at most 500 characters.
- **C-12 Layering:**
  - `PendingChangeRepositoryInterface` and `McpToolCallRepositoryInterface`, with Eloquent implementations bound in a provider.
  - Services use the contracts, following the repo's `ArchTest`.
- **C-13 Metadata:**
  - **Previews:**
    - **Title:** `{listing_id, make, model, year, old_title (null when no title is set), new_title}`. The page shows "make model" when `old_title` is null.
    - **Tag:** `{listing_id, make, model, year, title, tags, tag, already_tagged}`.
  - **Server attributes:**
    - `#[Version('1.0.0')]`;
    - `#[Instructions]`: "Listing tools of the ai-lab car marketplace. Read tools return capped results. Changes are only proposed: give the developer the link to confirm in the browser; you cannot confirm or apply a change."
  - **`source`:** always `mcp` for now. The column exists so the admin assistant could share the table later.
- **C-14 Retention, cross-site and fingerprint:**
  - **Retention:** no pruning in this slice (local lab data). The guide mentions it.
  - **Cross-site:** `RefuseCrossSiteRequests` refuses only requests a browser marks `Sec-Fetch-Site: cross-site`. A request with no such header, such as curl, passes, which matches the other lab endpoints. The test sends the header to prove the 403.
  - **Fingerprint:** it covers the public `app.css` and `app.js` only, and the lab bundle is separate (as in SL-34). A new lab page does not change it.
- **C-15 Claude Code command:**
  - **What the build checks:** that `ddev exec -T php artisan mcp:start listings` answers a JSON-RPC `initialize` and `tools/list` over stdin and stdout, with nothing but JSON on stdout.
  - **If DDEV adds noise:** use `docker exec -i ddev-ai-lab-web php /var/www/html/artisan mcp:start listings` and document it.
  - **If neither works:** record it, and document only the MCP Inspector and the in-process tests.
- **C-16 Test placement:** tool behaviour, including `change_status` (AC-6) and the `mcp:start` gate (AC-7), goes in `tests/Feature/McpListingsServerTest.php`. Page, endpoint and applier behaviour go in `tests/Feature/McpChangesTest.php`.

