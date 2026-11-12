# Listing moderation classifier — Tasks

**Spec**: spec-20261112-2146-moderation | **Size**: M | **Tasks**: 6

## Phase 1: Classifier

- [ ] 1. Facts (backend)
  Covers: AC-2, NFR-2
  - [ ] 1.1 `ModerationFacts` (median for make, model and year ±1, at least 5 listings, cached; ratio; the VIN regex; the reported register `resources/moderation/reported-vins.php`; the injection pattern via the guard).
  - [ ] 1.2 **Verify**: `tests/Feature/ModerationFactsTest.php`.

- [ ] 2. Agent, rules and service (backend)
  Covers: AC-1, AC-3, AC-4
  - [ ] 2.1 `ModerationAgent`, prompt v1, `ModerationRules`, `ModerationService` (one retry, `invalid` status, `ai_runs` per attempt, threshold), the `moderation` feature, config, `.env.example`.
  - [ ] 2.2 **Verify**: `tests/Feature/ModerationServiceTest.php` (valid; invalid then valid; twice invalid → `invalid`; a reason outside the list rejected; injection classified, not refused; the provider from settings).

## Phase 2: Set, eval, lab

- [ ] 3. Eval set (backend)
  Covers: AC-5
  - [ ] 3.1 `moderation:make-set` (seeded, the generator for safe items, templates for risky items, the hand-written hard negatives and mixed items committed in the seed data); `listings.v1.jsonl` committed.
  - [ ] 3.2 **Verify**: `tests/Feature/ModerationSetTest.php` (determinism, counts per label and reason, not in the corpus).

- [ ] 4. Eval command (backend)
  Covers: AC-6
  - [ ] 4.1 `moderation:eval` (JSON-valid rates, the threshold curve and best F1, accuracy, precision, recall, false positives on hard negatives, reason recall, latency).
  - [ ] 4.2 **Verify**: `tests/Feature/ModerationEvalTest.php` (the maths on scripted answers).

- [ ] 5. Endpoint, lab page and guide (backend, frontend, docs)
  Covers: AC-7, AC-8, NFR-1
  - [ ] 5.1 `POST /api/moderation`; `Lab/Moderation.vue`; navigation and capability; settings row; guide; §16; README.
  - [ ] 5.2 **Verify**: `tests/Feature/ModerationEndpointTest.php`, `GuideContentTest`, `NoReferencesInUiTest`, `AiSettingsPageTest`; typecheck; build; browser check.

## Phase 3: Run and checks

- [ ] 6. Run and checks (checks)
  Covers: AC-6, MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 6.1 Orchestrator: `moderation:make-set`, `moderation:eval` live, set the threshold, the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity; ping after the commit; push.
  - [ ] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | shape, retry once, invalid, logged | `tests/Feature/ModerationServiceTest.php` | test | 2 |
| AC-2 | facts computed and passed | `tests/Feature/ModerationFactsTest.php` | test | 1 |
| AC-3 | injection is a fact, not a refusal | `tests/Feature/ModerationServiceTest.php` | test | 2 |
| AC-4 | provider by settings | `tests/Feature/ModerationServiceTest.php` | test | 2 |
| AC-5 | deterministic set outside corpus | `tests/Feature/ModerationSetTest.php` | test | 3 |
| AC-6 | eval metrics and BENCHMARKS | `tests/Feature/ModerationEvalTest.php` | test | 4, 6 |
| AC-7 | lab page result | `tests/Feature/ModerationEndpointTest.php` | test | 5 |
| AC-8 | guide, no references | `tests/Feature/GuideContentTest.php` | test | 5 |
| MNC-1 | other features unchanged | verify existing AI feature and settings tests pass unchanged | test | 5 |
| MNC-2 | guard still refuses elsewhere | verify existing guard refusal tests pass unchanged | test | 2 |
| MNC-3 | offline suite | `tests/Feature/ModerationServiceTest.php` | test | 2 |
| MNC-4 | fingerprint and parity | `manual-test.md#step-3` | manual | 6 |
| NFR-1 | safe input and output | `tests/Feature/ModerationEndpointTest.php` | test | 5 |
| NFR-2 | bounded cost | `tests/Feature/ModerationServiceTest.php` | test | 2 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
