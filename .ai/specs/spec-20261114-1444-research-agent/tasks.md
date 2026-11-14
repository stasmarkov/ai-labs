# Multi-step research agent — Tasks

**Spec**: spec-20261114-1444-research-agent | **Size**: M | **Tasks**: 6

## Phase 1: Engine

- [ ] 1. Storage (backend)
  Covers: AC-1, AC-7
  - [ ] 1.1 Add the `research_runs` and `research_steps` migrations, models and factories, and the repository contracts in the repo's layering style.
  - [ ] 1.2 **Verify**: `tests/Feature/ResearchRunnerTest.php` (schema and relations).

- [ ] 2. Agents and runner (backend)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, AC-7, AC-8, AC-9, NFR-1
  - [ ] 2.1 Add:
    - the three agents and their v1 prompts;
    - the `research` feature key, config and `.env.example` entries;
    - `ResearchRunner`: the phases, the guard, the plan retry, search via `DocsSearchService`, notes in Redis, chunk-id filtering, report citations, the caps and the step logging.
  - [ ] 2.2 **Verify**: `tests/Feature/ResearchRunnerTest.php`, with faked agents and embeddings:
    - the happy path;
    - the planner invalid twice;
    - a forged chunk id dropped;
    - no facts gives the fixed report with no call;
    - unknown citations removed;
    - the Redis hash during the run and the steps after;
    - an `ai_runs` row per call;
    - an injection gives `refused`;
    - the caps.

## Phase 2: Surfaces

- [ ] 3. Command and replay (backend)
  Covers: AC-6, NFR-3
  - [ ] 3.1 Add `agent:research {topic?}` with `--provider`, `--json` and `--replay` (C-8).
  - [ ] 3.2 **Verify**: `tests/Feature/ResearchReplayTest.php`:
    - a replay prints the stored steps;
    - `Http::assertNothingSent` and no agent prompt during the replay;
    - the exit codes.

- [ ] 4. Endpoints, job, page (backend, frontend)
  Covers: AC-10, NFR-2
  - [ ] 4.1 Add:
    - the job;
    - `POST /api/research` (202) and `GET /api/research/{id}`;
    - the rate limit, the cross-site refusal and `LimitCloudAi`;
    - `Lab/Research.vue`, with the timeline, the escaped markdown report, the sources, client-side replay and the recent runs;
    - the navigation and capability entries.
  - [ ] 4.2 **Verify**: `tests/Feature/ResearchEndpointTest.php`:
    - 202 and the run, the shape and 404;
    - 429;
    - 403 cross-site;
    - the escaped report in the page props;
    - the settings row.

## Phase 3: Docs and checks

- [ ] 5. Guide and docs (docs)
  Covers: AC-11
  - [ ] 5.1 Add the `research` guide, the learning-guide section and the README lines.
  - [ ] 5.2 **Verify**: `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, typecheck and build.

- [ ] 6. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 6.1 Orchestrator:
    - a live `agent:research` on qwen3.5:9b with 2 topics (one covered by the docs, one not);
    - a replay;
    - the page run in the browser;
    - the gates, the fingerprint and parity;
    - commit, the ping and push.
  - [ ] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | phases stored, run done | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-2 | planner fails twice | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-3 | forged chunk ids, no-facts report | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-4 | citations to sources only | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-5 | notes in Redis then in steps | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-6 | replay without calls | `tests/Feature/ResearchReplayTest.php` | test | 3 |
| AC-7 | ai_runs per call, provider resolution | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-8 | guard refusal | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-9 | caps | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| AC-10 | endpoints and page | `tests/Feature/ResearchEndpointTest.php` | test | 4 |
| AC-11 | guide | `tests/Feature/GuideContentTest.php` | test | 5 |
| MNC-1 | docs search and ask unchanged | verify existing docs search and ask tests pass unchanged | test | 6 |
| MNC-2 | other features unchanged | verify existing AI feature and settings tests pass unchanged | test | 6 |
| MNC-3 | fingerprint and parity | `manual-test.md#step-4` | manual | 6 |
| MNC-4 | offline suite | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| NFR-1 | bounded cost | `tests/Feature/ResearchRunnerTest.php` | test | 2 |
| NFR-2 | safety | `tests/Feature/ResearchEndpointTest.php` | test | 4 |
| NFR-3 | replay fidelity | `tests/Feature/ResearchReplayTest.php` | test | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
