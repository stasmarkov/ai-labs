# MCP server for the listing tools — Tasks

**Spec**: spec-20261113-2230-mcp-listing-tools | **Size**: M | **Tasks**: 6

## Phase 1: Data and server

- [x] 1. Tables and models (backend)
  Covers: AC-3, AC-5
  - [x] 1.1 Add `pending_changes` and `mcp_tool_calls`: migrations, models and factories, the repository contracts (C-12), and `AdminWriteApplier` taken out of `AdminAssistantConfirmation::apply` with the SL-34 tests still green (C-2).
  - [x] 1.2 **Verify**: `tests/Feature/McpChangesTest.php` (schema, expiry marking, the conditional claim).

- [x] 2. Server and read tools (backend)
  Covers: AC-1, AC-2, NFR-2, MNC-4
  - [x] 2.1 Add:
    - `ListingsServer`;
    - `routes/ai.php` with `Mcp::local('listings', …)`;
    - the read tool wrappers `search_listings`, `get_listing`, `count_by_tag` and `change_status`;
    - the schemas from the SL-34 rules;
    - the `mcp_tool_calls` logging;
    - the gate in `mcp:start`.
  - [x] 2.2 **Verify**: `tests/Feature/McpListingsServerTest.php`, using laravel/mcp testing:
    - the tool list and its annotations;
    - read results equal to the SL-34 tool results;
    - invalid arguments give an error;
    - an `ok`/`error` row is logged;
    - with the gate off, `mcp:start` exits 1.

## Phase 2: Proposals and confirmation

- [x] 3. Propose tools (backend)
  Covers: AC-3, AC-4
  - [x] 3.1 Add `propose_title_change` and `propose_tag`: validate, store the pending change and return the id, preview and URL, with no listing write.
  - [x] 3.2 **Verify**: `tests/Feature/McpListingsServerTest.php`:
    - a proposal leaves the listing unchanged and stores `pending`;
    - the URL is in the text;
    - the server has no confirm or apply tool.

- [x] 4. Confirmation page and endpoints (backend, frontend)
  Covers: AC-4, AC-5, AC-6, AC-7, NFR-1, NFR-3
  - [x] 4.1 Add:
    - `/ai/changes` and `/ai/changes/{id}` (Inertia `Lab/Changes.vue`);
    - the confirm and reject endpoints (JSON, CSRF, cross-site refusal, rate limit, local gate);
    - re-validation and the transactional write through the SL-34 write code;
    - the 409 `change_not_pending` rule;
    - `failed` on an invalid change;
    - the navigation entry.
  - [x] 4.2 **Verify**: `tests/Feature/McpChangesTest.php`:
    - confirm applies once, and a second confirm gives 409;
    - reject writes nothing;
    - an expired change gives 409;
    - a deleted listing gives `failed`;
    - cross-site gives 403;
    - not local gives 404;
    - `change_status` returns each status.

## Phase 3: Docs and checks

- [x] 5. Guide and docs (docs)
  Covers: AC-8
  - [x] 5.1 Write the guide `mcp` (six tabs), the learning-guide section, README (the Claude Code command checked through DDEV, the Claude Desktop snippet, the inspector) and `.env.example`.
  - [x] 5.2 **Verify**: `GuideContentTest`, `GuidePageTest` and `NoReferencesInUiTest`, plus typecheck and build.

- [x] 6. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Orchestrator:
    - a live check that `ddev exec php artisan mcp:start listings` answers an MCP `initialize` and `tools/list` over stdio (a JSON-RPC lines script), plus one proposal confirmed in the browser page;
    - the full suite, Pint, PHPStan, typecheck and build;
    - fingerprint and parity;
    - commit, the ping on a clean tree, and push.
  - [x] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | tool list and annotations | `tests/Feature/McpListingsServerTest.php` | test | 2, 13 |
| AC-2 | read tools and logging | `tests/Feature/McpListingsServerTest.php` | test | 2 |
| AC-3 | proposal stores pending, no write | `tests/Feature/McpListingsServerTest.php` | test | 3 |
| AC-4 | no tool confirms, only the endpoint applies | `tests/Feature/McpChangesTest.php` | test | 3, 4 |
| AC-5 | confirm, reject, 409, failed | `tests/Feature/McpChangesTest.php` | test | 4 |
| AC-6 | change_status | `tests/Feature/McpListingsServerTest.php` | test | 2, 4 |
| AC-7 | gate | `tests/Feature/McpListingsServerTest.php` | test | 2, 4, 13 |
| AC-8 | guide and docs | `tests/Feature/GuideContentTest.php` | test | 5 |
| MNC-1 | admin assistant unchanged | verify existing AdminAssistant and AdminTools tests pass unchanged | test | 6 |
| MNC-2 | other lab pages unchanged | verify existing lab page tests pass unchanged | test | 6 |
| MNC-3 | public pages, fingerprint, parity | `manual-test.md#step-13` | manual | 6 |
| MNC-4 | offline suite | `tests/Feature/McpListingsServerTest.php` | test | 2 |
| NFR-1 | writes only via confirm endpoint | `tests/Feature/McpChangesTest.php` | test | 4 |
| NFR-2 | bounded output | `tests/Feature/McpListingsServerTest.php` | test | 2 |
| NFR-3 | applied once, safe failure | `tests/Feature/McpChangesTest.php` | test | 4 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 7. Put the confirm and reject endpoints behind the CSRF token (backend, frontend)
  Covers: NFR-1, AC-4
  - The spec (Scope 4, C-6) puts them at `POST /ai/changes/{id}/confirm|reject` as web routes that need a CSRF token (419 without one). They are registered in `routes/catalog.php` under the `api` group as `/api/changes/{id}/...`, and `route:list` shows no `web` or `PreventRequestForgery`. `Changes.vue` posts with a bare `fetch` and no CSRF header. Any local process without a `Sec-Fetch-Site` header, such as a client model running curl, can confirm a change.
  - Fix: move both routes into the `/ai` web group in `routes/web.php`, keeping `EnsureMcpServer`, `RefuseCrossSiteRequests`, `throttle:mcp-changes` and `whereUuid`. Post from `Changes.vue` with the shared lab fetch helper that sends the CSRF header, and update `confirmUrl` and the README and learning-guide endpoint paths.
  - [x] 7.1 **Verify**: in `tests/Feature/McpChangesTest.php`, an NFR-1 test checks that the gathered middleware of `changes.confirm` and `changes.reject` includes `web`/`PreventRequestForgery`, and that a session request with the token confirms. The existing AC-5/NFR-1/NFR-3 tests pass on the new paths.

- [x] 8. Answer 409 change_not_pending for an unknown change id (backend)
  Covers: AC-5
  - C-6 and Scope 4 say an unknown id that is a valid UUID gets 409 `change_not_pending`. `PendingChangeDecisionService::existing()` throws `PendingChangeConflictException::notFound()`, which gives 404 `change_not_found`, and the test `AC-5 answers 404 for an unknown change` asserts that.
  - Fix: map an unknown UUID on confirm and reject to 409 `change_not_pending`. A non-UUID id keeps its 404 from `whereUuid`.
  - [x] 8.1 **Verify**: `tests/Feature/McpChangesTest.php` asserts 409 `change_not_pending` for a random UUID on confirm and reject, and 404 for `not-a-uuid`.

- [x] 9. Log a `failed` row when an MCP tool throws (backend)
  Covers: AC-2
  - C-9 says the outcome `failed` is logged for an exception. `AdminToolWrapper::handle` catches only `AdminToolFailed`, `ProposeChangeTool::handle` catches only `AdminToolFailed`, and `ChangeStatusTool::handle` catches nothing. An unexpected exception, such as a database error, leaves no `mcp_tool_calls` row. No test covers `failed`.
  - Fix: catch `Throwable` in the three handles, `report()` it, log outcome `failed` with the exception class as `error`, and return `Response::error`.
  - [x] 9.1 **Verify**: `tests/Feature/McpListingsServerTest.php` binds a throwing repository or tool and asserts an MCP error and one `failed` row, for a read tool, a propose tool and `change_status`.

- [x] 10. Complete the proposal previews and the proposal text (backend)
  Covers: AC-3
  - C-13 says a title preview is `{listing_id, make, model, year, old_title, new_title}`. `UpdateTitleTool::preview()` returns only `{id, old_title, new_title}`. C-7/C-13 say a tag preview carries `already_tagged`, which `AddTagTool::preview()` lacks.
  - Scope 2 says the returned text tells the model "The developer must confirm this in the browser; you cannot confirm it." `ChangeProposal::toArray()` has no "you cannot confirm it".
  - None of this is tested in `McpListingsServerTest.php`.
  - Fix: enrich the preview in `ChangeProposalService`, leaving the SL-34 `preview()` unchanged for MNC-1, and add the sentence to the note.
  - [x] 10.1 **Verify**: `tests/Feature/McpListingsServerTest.php`: the AC-3 title test asserts `listing_id`, `make`, `model` and `year` in the stored preview. A tag proposal for a tag the listing already has stores `already_tagged: true`. The response contains "you cannot confirm it".

- [x] 11. Show the proposing tool call and the failure messages on the changes page (backend, frontend)
  Covers: AC-4
  - Scope 5 and C-11 say each change shows its proposing call's time, tool and arguments, found through `mcp_tool_calls.pending_change_id`. `PendingChangePageService::present()` never calls `McpToolCallRepositoryInterface::findByPendingChange`, and `Changes.vue` has no proposer section.
  - C-11 says a `failed` change shows the validation messages joined, or "the change could not be applied". The page renders `Not applied: {{ shown.error }}`, which is the code `invalid_at_confirm`, and does not use `result.details`.
  - Fix: add a `proposed_by` (time, tool, arguments) to the presented change. Render it, and render the joined `result.details`, or the fallback text, for a failed change.
  - [x] 11.1 **Verify**: the `tests/Feature/McpChangesTest.php` AC-4 page test asserts `proposed_by.tool` equals `propose_title_change` for a change proposed through the server. A failed change's props or rendered text carry the validation message, such as "No listing with id ...".

- [x] 12. Test the change pages and the nav entry with AI_MCP_SERVER off (backend)
  Covers: AC-7
  - AC-7 and C-3 require the `/ai/changes` routes to answer 404 and the nav entry to be hidden when `ai.mcp.enabled` is false.
  - `NFR-1 answers 404 outside the local environment...` checks the GET pages only for `production`. With the switch off it checks only the POST endpoints.
  - No test asserts the "Changes" nav entry is absent when the gate is off.
  - [x] 12.1 **Verify**: `tests/Feature/McpChangesTest.php` adds an AC-7 test. With `APP_ENV=local` and `ai.mcp.enabled=false`, `GET /ai/changes` and `GET /ai/changes/{id}` answer 404, and another lab page's `lab.navigation` has no `/ai/changes` href. With the switch on, the href is present.

- [x] 13. Fix the manual test commands that use the unsupported `ddev exec -T` (docs)
  Covers: AC-1, AC-7
  - Task 5 found this DDEV (v1.25.4) has no `-T` flag, and README and the guide use `ddev exec php artisan mcp:start listings`. `manual-test.md` Step 1 still says `claude mcp add ai-lab -- ddev exec -T php artisan mcp:start listings`. Step 3 runs `ddev exec -T env AI_MCP_SERVER=false php artisan mcp:start listings`, which fails on the flag, not on the gate, so it cannot prove AC-7's exit 1.
  - Fix: drop `-T` in both steps, matching README.
  - [x] 13.1 **Verify**: `ddev exec env AI_MCP_SERVER=false php artisan mcp:start listings; echo "exit=$?"` prints the gate message and `exit=1`, and Step 1's command matches README line 153.

## Phase: Gaps (converge round 2)

- [x] 14. Show each pending change's preview and Confirm and Reject buttons in the `/ai/changes` list, and reload the lists after a decision (frontend)
  Covers: AC-4, AC-5
  - Scope 4 says the list shows pending changes "each with its preview and Confirm and Reject buttons", and C-6 says the page "reloads the props with an Inertia partial reload". In `resources/js/ai/Pages/Lab/Changes.vue` the "Waiting for a decision" list renders only a link with `item.tool` and `expires_at`. The Confirm and Reject buttons exist only in the `shown` section, which is `null` on `/ai/changes`. After `decide()` only `shown` is replaced, so `pending` and `recent` stay stale until a full reload.
  - `manual-test.md` Step 2 says "Open the link it returns, or `/ai/changes`. Click Reject". On `/ai/changes` there is no Reject button.
  - Fix: render each pending item's preview, plus Confirm and Reject buttons that post to its `changes.confirm` and `changes.reject` URLs with the same `decide()` helper. After a decision, call `router.reload({ only: ['pending', 'recent', 'change'] })`. `PendingChangePageService::lists()` already sends the preview in the props.
  - [x] 14.1 **Verify**: `ddev npm run typecheck && ddev npm run build` are clean. In `tests/Feature/McpChangesTest.php`, the AC-4 list test asserts that the list props carry `preview` and per-item confirm and reject URLs, or the URL template the page uses. In the browser on `/ai/changes`, a pending change shows its preview, and Confirm and Reject on it apply or refuse it. Afterwards the change moves from "Waiting for a decision" to "Decided" without a manual reload. This is recorded in `manual-test.md` Step 6 (the Step 2 reference was a numbering slip: Step 2 lists the tools).

- [x] 15. Assert that the propose tools have no annotations and publish their schemas (backend)
  Covers: AC-1
  - AC-1 (C-5) requires no annotation on `propose_title_change` and `propose_tag`, and a description and input schema on all six tools. In `tests/Feature/McpListingsServerTest.php`, the only check on the propose tools is `annotations.readOnlyHint ?? false` being false, in the AC-4 test. An `IsDestructive` or `IsIdempotent` annotation added to them would still pass. The AC-1 schema test checks only `search_listings`, `get_listing` and `count_by_tag`, and no test checks the propose tools' description or `inputSchema`.
  - Fix: extend the AC-1 test. Assert `ListingsServer::tools()->assertRegistered(...)` with all six classes. Assert that the propose tools' `annotations` are empty, with no `readOnlyHint`, `destructiveHint` or `idempotentHint`. Assert a non-empty description, and an `inputSchema` whose `required` is `['id', 'title']` and `['id', 'tag']`, matching `UpdateTitleTool::parameters()` and `AddTagTool::parameters()`.
  - [x] 15.1 **Verify**: `ddev artisan test --compact tests/Feature/McpListingsServerTest.php --filter=AC-1` passes. Adding `#[IsDestructive]` to `ProposeTagTool` temporarily makes it fail.

## Phase: Review fix pass (SL-35 review findings)

- [x] 16. Harden the confirmation (C-17), failures and the page (backend, frontend, docs)
  - [x] 16.1 Security: confirm and reject need `Sec-Fetch-Site: same-origin` (`RequireSameOriginDecision`, also checks `Origin` against `APP_URL`) and the one-time per-session nonce (`PendingChangeNonces`, 403 wrong, 422 missing). Tool and note text now say the confirmation needs the developer in the browser. Residual risk documented (spec C-17, guide, README, manual test).
  - [x] 16.2 `failed` instead of stuck `applying`: the write is wrapped in try/catch (generic error, reported), and a change `applying` over 2 minutes is stored `failed` when read or listed. Unexpected exceptions show "the change could not be applied", never a class name.
  - [x] 16.3 Proposal reply: preview tags capped with `tagsShown`, change id and url survive the 4000 character cap, url built with `route('ai.changes.show')`.
  - [x] 16.4 Page: the pending change shows the listing's current title and tags next to the preview; list rows are slim (one `findMany` query, constant query count) and carry Confirm and Reject; lists reload after a decision or a 409; one `csrfToken` helper in `resources/js/ai/composables/csrfToken.ts` used by Changes, Photos and PhotoDuplicates.
  - [x] 16.5 The `mcp:start` gate moved to `AiServiceProvider`; the exports rate-limit comment is back in place.
  - [x] 16.6 Tests: `McpChangesTest`, `McpListingsServerTest`, `tests/Unit/ChangeProposalTest.php`, `tests/Unit/PendingChangeNoncesTest.php`.

## Live results (task 6, review fix pass, 2026-11-13 22:20 to 22:35 UTC, DDEV v1.25.4, Playwright for the browser steps)

- Gates: full suite `ddev artisan test --compact` 2677 passed, 3 skipped (28531 assertions); Pint passed; PHPStan "No errors"; `ddev npm run typecheck` and `ddev npm run build` clean; `bench/asset-fingerprint.sh` prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`; parity capture differs from HEAD only in `captured=` (`git checkout -- bench/parity` run afterwards).
- Step 1 (stdio, `mcp()` helper with `ddev exec php artisan mcp:start listings`, no `-T`): every output line is JSON, `initialize` replies with `serverInfo` `ai-lab listings` 1.0.0 and the instructions text, ping answers `{}`; stderr empty.
- Step 2: six tools; the four read tools have `readOnlyHint` true; the propose tools carry only the auto `title` annotation; every tool has a description and an `inputSchema`.
- Steps 3 and 4: `count_by_tag` returned `{"tag":"family-car","count":0}`, `get_listing` listing 1 (Ford Fiesta), `search_listings` total 50000 with 5 rows in 676 characters; three `ok` rows with `duration_ms`; `get_listing` with `"abc"` returned `isError` true "The id argument must be a positive integer." and an `error` row.
- Step 5: two proposals returned pending ids, previews with make, model and year, and a `route()` URL on `https://ai-lab.ddev.site`; the unknown listing returned an error and stored nothing; listing 1 stayed `{"title":null,"tags":[]}`.
- Steps 6 and 7 (curl with a cookie jar, then Playwright): the change page returns 200 with the preview, the current state, the proposer and a nonce; Confirm applied the title once (listing 1 page shows it), Reject left the tags empty.
- Step 8: `change_status` gave `confirmed` with the result for A and `rejected` for B; `confirm_change` returned "Tool [confirm_change] not found."
- Step 9: `not-a-uuid` 404 and unknown uuid 404; POST with no header or token 419; with `Sec-Fetch-Site: same-origin` and no nonce 422; with the cookie jar and CSRF token but no `Sec-Fetch-Site` 403; wrong nonce 403; no session 403; correct flow 200. The same-origin and nonce flow was also done with forged headers from curl and it succeeds: this is the documented residual risk of C-17.
- Browser (Playwright, headless Chromium): `/ai/changes` listed three pending changes each with tool name, listing, `"old" to "new"` preview, current title and tags, Confirm and Reject. Reject on one and Confirm on another moved them to Decided without a manual reload. Two tabs: Confirm in tab 1 applied, Confirm in tab 2 returned 409 `change_not_pending`, showed the message and refreshed to `confirmed` with no buttons.
- Step 10: `ddev exec env AI_MCP_SERVER=false php artisan mcp:start listings` and the same with `APP_ENV=production` print the gate message and `exit=1`. The 404 of the routes with the switch off is covered by `AC-7 hides the change pages ...` (the `.env` was not touched).
- Rollback: listing 1 ends with `title` null and no `listing_tags` rows. The `pending_changes` and `mcp_tool_calls` rows from the checks stay in the local database.

## Orchestrator record (2026-11-14)

- Residual risk (C-17): a client with a shell can read the page and forge the browser headers, and that is accepted (self-decided, standing approval). A local process with a shell can already write the database through `artisan tinker`, so no in-app check can stop it. BR-97 protects against the model writing through its tools: no MCP tool can confirm, and an MCP-only client cannot.
- Review fix pass: the 12 findings are fixed. Gates: suite 2677 passed and 3 skipped; Pint, PHPStan, typecheck and build clean; the fingerprint is unchanged; parity differs only in `captured=`. Live steps 1 to 10 were walked, and listing 1 ends clean.
- Clean-tree bench: after commit c5f9c80, `bench/run.sh ping --quick` exited 0 on frankenphp.
