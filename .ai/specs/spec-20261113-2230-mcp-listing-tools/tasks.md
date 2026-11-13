# MCP server for the listing tools — Tasks

**Spec**: spec-20261113-2230-mcp-listing-tools | **Size**: M | **Tasks**: 6

## Phase 1: Data and server

- [ ] 1. Tables and models (backend)
  Covers: AC-3, AC-5
  - [ ] 1.1 Add `pending_changes` and `mcp_tool_calls`: migrations, models and factories, the repository contracts (C-12), and `AdminWriteApplier` taken out of `AdminAssistantConfirmation::apply` with the SL-34 tests still green (C-2).
  - [ ] 1.2 **Verify**: `tests/Feature/McpChangesTest.php` (schema, expiry marking, the conditional claim).

- [ ] 2. Server and read tools (backend)
  Covers: AC-1, AC-2, NFR-2, MNC-4
  - [ ] 2.1 Add:
    - `ListingsServer`;
    - `routes/ai.php` with `Mcp::local('listings', …)`;
    - the read tool wrappers `search_listings`, `get_listing`, `count_by_tag` and `change_status`;
    - the schemas from the SL-34 rules;
    - the `mcp_tool_calls` logging;
    - the gate in `mcp:start`.
  - [ ] 2.2 **Verify**: `tests/Feature/McpListingsServerTest.php`, using laravel/mcp testing:
    - the tool list and its annotations;
    - read results equal to the SL-34 tool results;
    - invalid arguments give an error;
    - an `ok`/`error` row is logged;
    - with the gate off, `mcp:start` exits 1.

## Phase 2: Proposals and confirmation

- [ ] 3. Propose tools (backend)
  Covers: AC-3, AC-4
  - [ ] 3.1 Add `propose_title_change` and `propose_tag`: validate, store the pending change and return the id, preview and URL, with no listing write.
  - [ ] 3.2 **Verify**: `tests/Feature/McpListingsServerTest.php`:
    - a proposal leaves the listing unchanged and stores `pending`;
    - the URL is in the text;
    - the server has no confirm or apply tool.

- [ ] 4. Confirmation page and endpoints (backend, frontend)
  Covers: AC-4, AC-5, AC-6, AC-7, NFR-1, NFR-3
  - [ ] 4.1 Add:
    - `/ai/changes` and `/ai/changes/{id}` (Inertia `Lab/Changes.vue`);
    - the confirm and reject endpoints (JSON, CSRF, cross-site refusal, rate limit, local gate);
    - re-validation and the transactional write through the SL-34 write code;
    - the 409 `change_not_pending` rule;
    - `failed` on an invalid change;
    - the navigation entry.
  - [ ] 4.2 **Verify**: `tests/Feature/McpChangesTest.php`:
    - confirm applies once, and a second confirm gives 409;
    - reject writes nothing;
    - an expired change gives 409;
    - a deleted listing gives `failed`;
    - cross-site gives 403;
    - not local gives 404;
    - `change_status` returns each status.

## Phase 3: Docs and checks

- [ ] 5. Guide and docs (docs)
  Covers: AC-8
  - [ ] 5.1 Write the guide `mcp` (six tabs), the learning-guide section, README (the Claude Code command checked through DDEV, the Claude Desktop snippet, the inspector) and `.env.example`.
  - [ ] 5.2 **Verify**: `GuideContentTest`, `GuidePageTest` and `NoReferencesInUiTest`, plus typecheck and build.

- [ ] 6. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 6.1 Orchestrator:
    - a live check that `ddev exec -T php artisan mcp:start listings` answers an MCP `initialize` and `tools/list` over stdio (a JSON-RPC lines script), plus one proposal confirmed in the browser page;
    - the full suite, Pint, PHPStan, typecheck and build;
    - fingerprint and parity;
    - commit, the ping on a clean tree, and push.
  - [ ] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | tool list and annotations | `tests/Feature/McpListingsServerTest.php` | test | 2 |
| AC-2 | read tools and logging | `tests/Feature/McpListingsServerTest.php` | test | 2 |
| AC-3 | proposal stores pending, no write | `tests/Feature/McpListingsServerTest.php` | test | 3 |
| AC-4 | no tool confirms, only the endpoint applies | `tests/Feature/McpChangesTest.php` | test | 3, 4 |
| AC-5 | confirm, reject, 409, failed | `tests/Feature/McpChangesTest.php` | test | 4 |
| AC-6 | change_status | `tests/Feature/McpListingsServerTest.php` | test | 2, 4 |
| AC-7 | gate | `tests/Feature/McpListingsServerTest.php` | test | 2, 4 |
| AC-8 | guide and docs | `tests/Feature/GuideContentTest.php` | test | 5 |
| MNC-1 | admin assistant unchanged | verify existing AdminAssistant and AdminTools tests pass unchanged | test | 6 |
| MNC-2 | other lab pages unchanged | verify existing lab page tests pass unchanged | test | 6 |
| MNC-3 | public pages, fingerprint, parity | `manual-test.md#step-4` | manual | 6 |
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

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
