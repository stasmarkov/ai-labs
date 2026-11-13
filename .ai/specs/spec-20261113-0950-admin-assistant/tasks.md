# Admin assistant with a hand-written agent loop — Tasks

**Spec**: spec-20261113-0950-admin-assistant | **Size**: M | **Tasks**: 7

## Phase 1: Data and tools

- [ ] 1. Schema, models, listing page (backend)
  Covers: AC-10, MNC-2, MNC-3
  - [ ] 1.1 The three reversible migrations; `Listing::title` and `tags()`, plus `ListingTag` with its factory; the listing page shows the title and tag chips.
  - [ ] 1.2 **Verify**: `tests/Feature/ListingTitleTagsTest.php`:
    - with no title and no tags the HTML is identical to a snapshot of today's render;
    - with a title or tags they are shown;
    - the widened outcome check accepts `pending`, `confirmed` and `rejected` and keeps the old values;
    - the migrations roll back.

- [ ] 2. Tools (backend)
  Covers: AC-2, NFR-2
  - [ ] 2.1 `app/Ai/AdminTools/*`: the five tools with their schemas, `validate`, `run`, `isWrite`, the 4000-character cap, the 10-row limit and the 500-character description cut.
  - [ ] 2.2 **Verify**: `tests/Feature/AdminToolsTest.php`:
    - argument rules for each tool;
    - capped results;
    - `addTag` is idempotent;
    - an unknown id is an error;
    - `run` of a write tool is never called by the loop path (only by the confirmation service).

## Phase 2: Loop and confirmation

- [ ] 3. Hand-written loop (backend)
  Covers: AC-1, AC-2, AC-6, AC-7, AC-8, AC-9, NFR-2
  - [ ] 3.1 `AdminAssistantLoop`:
    - the OpenAI-shaped request;
    - `tool_calls` parsing;
    - `<tool_result>` wrapping;
    - the cap of 4 tool steps and 6 calls, with a final `tool_choice: none` call;
    - one `ai_runs` row per call and one `ai_tool_steps` row per step;
    - prompt v1;
    - the `admin-assistant` feature key, Ollama only;
    - the guard.
  - [ ] 3.2 **Verify**: `tests/Feature/AdminAssistantLoopTest.php`, using `Http::fake` sequences:
    - a read step and then the answer;
    - invalid JSON arguments;
    - a failed `validate`;
    - the 5th step blocked with `limit_reached`;
    - at most 6 calls;
    - an `ai_runs` row per call with tokens;
    - steps logged;
    - a non-Ollama provider gives 422;
    - an injection is refused with no request sent;
    - the request body has `tools`, `tool_choice` and `temperature 0`.

- [ ] 4. Pending actions and confirm endpoint (backend)
  Covers: AC-3, AC-4, AC-5, NFR-1, NFR-3
  - [ ] 4.1 Conversation state in the cache, the pending action (random id, 10 min expiry, one at a time, the deferred second write), the confirm/reject service (validate again, transaction, `confirmed`/`rejected` steps, continue the loop), and the 409 rules.
  - [ ] 4.2 **Verify**: `tests/Feature/AdminAssistantConfirmTest.php`:
    - a write proposal writes nothing;
    - confirm applies it once;
    - a second confirm gives 409;
    - reject writes nothing;
    - expired, unknown and other-conversation actions give 409;
    - a question while an action is pending gives 409;
    - a "yes" typed in the chat does not write;
    - a listing deleted before confirm gives an error and no crash;
    - the deferred second write.

## Phase 3: HTTP, UI, docs, checks

- [ ] 5. Endpoints and gate (backend)
  Covers: AC-8, AC-11, MNC-4, NFR-1
  - [ ] 5.1 The routes, the local-only middleware (reusing the settings gate's checks with `AI_ADMIN_ASSISTANT`), the JSON-only requests, the cross-site refusal, the rate limiter, the settings row and `.env.example`.
  - [ ] 5.2 **Verify**: `tests/Feature/AdminAssistantEndpointTest.php`:
    - 404 when not local or switched off;
    - 403 cross-site;
    - 415 for a form body;
    - 429 over the limit;
    - the reply shape;
    - the settings row only when available.
    `AiSettingsPageTest` is updated.

- [ ] 6. Lab page and guide (frontend, docs)
  Covers: AC-12
  - [ ] 6.1 `Lab/AdminAssistant.vue`, with the navigation and capability entries, plus the guide (six tabs), the learning-guide section and README.
  - [ ] 6.2 **Verify**:
    - `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest` and an Inertia page test;
    - typecheck and build;
    - a browser check of manual-test steps 2 to 4.

- [ ] 7. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-5, MNC-6
  - [ ] 7.1 Orchestrator:
    - a live walk of manual-test.md on qwen3.5:9b;
    - full suite, Pint, PHPStan, typecheck and build;
    - fingerprint and parity;
    - commit; bench ping on the clean tree; push.
  - [ ] 7.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | read tool step and answer | `tests/Feature/AdminAssistantLoopTest.php` | test | 3 |
| AC-2 | invalid arguments not run | `tests/Feature/AdminAssistantLoopTest.php` | test | 2, 3 |
| AC-3 | write becomes pending | `tests/Feature/AdminAssistantConfirmTest.php` | test | 4 |
| AC-4 | confirm applies once, reject writes nothing | `tests/Feature/AdminAssistantConfirmTest.php` | test | 4 |
| AC-5 | 409 rules | `tests/Feature/AdminAssistantConfirmTest.php` | test | 4 |
| AC-6 | step and call cap | `tests/Feature/AdminAssistantLoopTest.php` | test | 3 |
| AC-7 | runs and steps logged | `tests/Feature/AdminAssistantLoopTest.php` | test | 3 |
| AC-8 | provider from settings, Ollama only | `tests/Feature/AdminAssistantLoopTest.php` | test | 3, 5 |
| AC-9 | guard and tool results as data | `tests/Feature/AdminAssistantLoopTest.php` | test | 3 |
| AC-10 | title and tags on listing page | `tests/Feature/ListingTitleTagsTest.php` | test | 1 |
| AC-11 | local-only 404 | `tests/Feature/AdminAssistantEndpointTest.php` | test | 5 |
| AC-12 | guide and learning guide | `tests/Feature/GuideContentTest.php` | test | 6 |
| MNC-1 | buyer assistant unchanged | verify existing buyer assistant tests pass unchanged | test | 7 |
| MNC-2 | listing page unchanged without title/tags | `tests/Feature/ListingTitleTagsTest.php` | test | 1 |
| MNC-3 | old tool-step outcomes valid | `tests/Feature/ListingTitleTagsTest.php` | test | 1 |
| MNC-4 | no routes outside local | `tests/Feature/AdminAssistantEndpointTest.php` | test | 5 |
| MNC-5 | offline suite | `tests/Feature/AdminAssistantLoopTest.php` | test | 3 |
| MNC-6 | fingerprint and parity | `manual-test.md#step-6` | manual | 7 |
| NFR-1 | writes only through confirm | `tests/Feature/AdminAssistantConfirmTest.php` | test | 4, 5 |
| NFR-2 | bounded cost | `tests/Feature/AdminAssistantLoopTest.php` | test | 2, 3 |
| NFR-3 | transactional, safe write | `tests/Feature/AdminAssistantConfirmTest.php` | test | 4 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
