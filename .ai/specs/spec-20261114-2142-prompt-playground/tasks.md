# Prompt playground with versions — Tasks

**Spec**: spec-20261114-2142-prompt-playground | **Size**: M | **Tasks**: 5

## Phase 1: Data and runner

- [ ] 1. Storage (backend)
  Covers: AC-1, AC-7
  - [ ] 1.1 Add the four migrations, the models and factories, and the repository contracts.
  - [ ] 1.2 **Verify**: `tests/Feature/PlaygroundPromptTest.php`:
    - version numbering;
    - the immutability (no update path);
    - 422 `unchanged`;
    - name rules;
    - delete keeps runs.

- [ ] 2. Runner (backend)
  Covers: AC-2, AC-3, AC-4, AC-5, AC-6, NFR-1
  - [ ] 2.1 Add `PlaygroundAgent`, `PlaygroundRunner` (target validation, sequential calls, results, `ai_runs` logging with the version label, failure isolation, the guard flag), config and `.env.example`.
  - [ ] 2.2 **Verify**: `tests/Feature/PlaygroundRunTest.php`, with the agent faked:
    - 2 and 3 targets;
    - a disabled provider or an unknown model gives 422 and no call;
    - one failing target;
    - the injection flag;
    - an `ai_runs` row per call;
    - the caps.

## Phase 2: Surfaces and checks

- [ ] 3. Endpoints and page (backend, frontend)
  Covers: AC-7, AC-8, NFR-2
  - [ ] 3.1 Add:
    - the routes and controllers;
    - the gate on the prompt writes;
    - the cross-site refusal, the rate limit and `LimitCloudAi` weighting;
    - `Lab/Playground.vue`, with the editor, the versions, the client line diff, the targets, the columns and the history;
    - the navigation and capability entries.
  - [ ] 3.2 **Verify**: `tests/Feature/PlaygroundEndpointTest.php`:
    - the shapes;
    - 404 for writes when not local;
    - 403 cross-site;
    - 429;
    - a stored run opened with no call;
    - escaped output in the props.
    Also add `NoReferencesInUiTest` for `/ai/playground`.

- [ ] 4. Guide and docs (docs)
  Covers: AC-9
  - [ ] 4.1 Add the guide `playground`, the learning-guide section and README.
  - [ ] 4.2 **Verify**: `GuideContentTest`, `GuidePageTest`, typecheck and build.

- [ ] 5. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 5.1 Orchestrator:
    - a live comparison on qwen3.5:9b against gemma4:12b with two versions of one prompt, in the browser;
    - the gates, the fingerprint and parity;
    - commit, the ping and push.
  - [ ] 5.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | versions immutable, numbered | `tests/Feature/PlaygroundPromptTest.php` | test | 1 |
| AC-2 | targets run sequentially, results stored | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| AC-3 | ai_runs per call | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| AC-4 | invalid target 422 | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| AC-5 | failure isolation | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| AC-6 | guard flag | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| AC-7 | stored run reopened | `tests/Feature/PlaygroundEndpointTest.php` | test | 3 |
| AC-8 | writes local-only | `tests/Feature/PlaygroundEndpointTest.php` | test | 3 |
| AC-9 | guide | `tests/Feature/GuideContentTest.php` | test | 4 |
| MNC-1 | feature prompts unchanged | verify existing prompt-file and AI feature tests pass unchanged | test | 5 |
| MNC-2 | settings unchanged | verify existing AiSettingsPageTest passes unchanged | test | 5 |
| MNC-3 | fingerprint and parity | `manual-test.md#step-3` | manual | 5 |
| MNC-4 | offline suite | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| NFR-1 | bounded cost | `tests/Feature/PlaygroundRunTest.php` | test | 2 |
| NFR-2 | safety | `tests/Feature/PlaygroundEndpointTest.php` | test | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
