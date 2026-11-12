# Listing moderation classifier — Tasks

**Spec**: spec-20261112-2146-moderation | **Size**: M | **Tasks**: 6

## Phase 1: Classifier

- [x] 1. Facts (backend)
  Covers: AC-2, NFR-2
  - [x] 1.1 `ModerationFacts` (median for make, model and year ±1, at least 5 listings, cached; ratio; the VIN regex; the reported register `resources/moderation/reported-vins.php`; the injection pattern via the guard).
  - [x] 1.2 **Verify**: `tests/Feature/ModerationFactsTest.php`.

- [x] 2. Agent, rules and service (backend)
  Covers: AC-1, AC-3, AC-4
  - [x] 2.1 `ModerationAgent`, prompt v1, `ModerationRules`, `ModerationService` (one retry, `invalid` status, `ai_runs` per attempt, threshold), the `moderation` feature, config, `.env.example`.
  - [x] 2.2 **Verify**: `tests/Feature/ModerationServiceTest.php` (valid; invalid then valid; twice invalid → `invalid`; a reason outside the list rejected; injection classified, not refused; the provider from settings).

## Phase 2: Set, eval, lab

- [x] 3. Eval set (backend)
  Covers: AC-5
  - [x] 3.1 `moderation:make-set` (seeded, the generator for safe items, templates for risky items, the hand-written hard negatives and mixed items committed in the seed data); `listings.v1.jsonl` committed.
  - [x] 3.2 **Verify**: `tests/Feature/ModerationSetTest.php` (determinism, counts per label and reason, not in the corpus).

- [x] 4. Eval command (backend)
  Covers: AC-6
  - [x] 4.1 `moderation:eval` (JSON-valid rates, the threshold curve and best F1, accuracy, precision, recall, false positives on hard negatives, reason recall, latency).
  - [x] 4.2 **Verify**: `tests/Feature/ModerationEvalTest.php` (the maths on scripted answers).

- [x] 5. Endpoint, lab page and guide (backend, frontend, docs)
  Covers: AC-7, AC-8, NFR-1
  - [x] 5.1 `POST /api/moderation`; `Lab/Moderation.vue`; navigation and capability; settings row; guide; §16; README.
  - [x] 5.2 **Verify**: `tests/Feature/ModerationEndpointTest.php`, `GuideContentTest`, `NoReferencesInUiTest`, `AiSettingsPageTest`; typecheck; build; browser check.

## Phase 3: Run and checks

- [x] 6. Run and checks (checks)
  Covers: AC-6, MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Orchestrator: `moderation:make-set`, `moderation:eval` live, set the threshold, the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity; ping after the commit; push.
  - [x] 6.2 **Verify**: outputs recorded here.
    Recorded outputs (task 10, 2026-11-12, headless Chrome driven by playwright-core against https://ai-lab.ddev.site/ai/moderation, qwen3.5:9b via Ollama, threshold 0.2):
    - Step 1.2, first seeded listing: Not risky, risk 0, no reasons ("The model gave no reason."), explanation shown. Facts: market median EUR 43,555, price ratio 1.5489, VINs none, VIN reported no, injection pattern none. Attempts 1, status classified, provider ollama, model qwen3.5:9b, prompt moderation@v1.
    - Step 1.3, "advance payment" example: Risky, risk 0.8 with the threshold 0.2; reasons advance_payment and off_platform_contact, each with its definition; explanation shown; facts median EUR 35,274, ratio 1.0702, injection pattern none; attempts 1.
    - Step 1.4, injection text: Risky, risk 0.95 (at least 0.7); reasons advance_payment, off_platform_contact and other; the facts show injection pattern `ignore-previous`; the text was classified, not refused; attempts 1.
    - The meter (`role="meter"`, "Risk 0.95, threshold 0.2") holds the risk bar and the threshold line. The "Logged run" link opens `/ai/runs/01a1d1b3-42b8-71fc-adc9-520016cc2ee2` with 200 and the run JSON (agent ModerationAgent, status completed, prompt_version moderation@v1). No page or console errors.
    - Finding: the first browser pass showed the run link returning 404 and no `ai_runs` row, because the dev database had two pending migrations from SL-25 (`add_tool_columns_to_ai_runs_table`, `create_ai_tool_steps_table`) and `AiRunRecorder::recordRun` swallows the insert error in `rescue()`. `ddev artisan migrate` fixed it; no code changed. The tests use a freshly migrated database, so they could not show it.
    - Step 3: `bench/asset-fingerprint.sh` printed `resources/css/app.css assets/app-DNob7CFE.css 176bae5b...f1d444` and `resources/js/app.js assets/app-PTzu1QaN.js 7fbc3860...f97735256`, the same as the baseline in BENCHMARKS.md (unchanged). `PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity` changed only the `captured=` line (`2026-11-10T15:57:29Z` to `2026-11-12T21:39:44Z`).

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | shape, retry once, invalid, logged | `tests/Feature/ModerationServiceTest.php`, `manual-test.md#step-4` | test | 2 |
| AC-2 | facts computed and passed | `tests/Feature/ModerationFactsTest.php`, `manual-test.md#step-5` | test | 1 |
| AC-3 | injection is a fact, not a refusal | `tests/Feature/ModerationServiceTest.php` | test | 2 |
| AC-4 | provider by settings | `tests/Feature/ModerationServiceTest.php`, `manual-test.md#step-6` | test | 2 |
| AC-5 | deterministic set outside corpus | `tests/Feature/ModerationSetTest.php`, `manual-test.md#step-2` | test | 3 |
| AC-6 | eval metrics and BENCHMARKS | `tests/Feature/ModerationEvalTest.php`, `manual-test.md#step-2` | test | 4, 6 |
| AC-7 | lab page result | `tests/Feature/ModerationEndpointTest.php`, `manual-test.md#step-1` | test | 5 |
| AC-8 | guide, no references | `tests/Feature/GuideContentTest.php`, `manual-test.md#step-7` | test | 5 |
| MNC-1 | other features unchanged | verify existing AI feature and settings tests pass unchanged, `manual-test.md#step-8` | test | 5 |
| MNC-2 | guard still refuses elsewhere | verify existing guard refusal tests pass unchanged, `manual-test.md#step-8` | test | 2 |
| MNC-3 | offline suite | `tests/Feature/ModerationServiceTest.php`, `manual-test.md#step-9` | test | 2 |
| MNC-4 | fingerprint and parity | `manual-test.md#step-3` | manual | 6 |
| NFR-1 | safe input and output | `tests/Feature/ModerationEndpointTest.php`, `manual-test.md#step-10` | test | 5 |
| NFR-2 | bounded cost | `tests/Feature/ModerationServiceTest.php`, `manual-test.md#step-10` | test | 2 |

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

- [x] 7. Align the eval scorer and the chosen thresholds with the binding clarity gate (backend)
  Covers: AC-6
  - [x] 7.1 `app/Services/ModerationEvalScorer.php` departs from the spec in four places. (a) The curve runs from 0.05 to 0.95 (`CURVE_START = 5`, `CURVE_END = 95`), but the spec says 0.1 to 0.9. (b) `best()` keeps the lowest threshold on a tie, but the spec says the higher one wins. (c) `confusion()` scores only `classified` outcomes, but Clarification 2 says an `invalid` item counts as wrong: a risky one as FN and a safe one as FP. (d) `hardNegatives()` divides by the classified hard negatives, but Clarification 7 says FP ÷ 15 with invalid items counted as FP. Fix all four. Then re-pick the thresholds in `config/ai.php` with the spec rule. The current gemma4:12b value is 0.4, but its F1 ties from 0.35 to 0.5 in `bench/results/moderation/2026-11-12-gemma4/run.json`, so the spec rule gives 0.5. Update the BENCHMARKS.md method text and the threshold rows to match.
  - [x] 7.2 **Verify**: `tests/Feature/ModerationEvalTest.php` has cases for: a curve from 0.1 to 0.9 with 17 points; the highest threshold winning a tie; an invalid risky item counted as FN and an invalid safe item as FP in accuracy, precision and recall; the hard-negative FP rate over 15 with invalid items included. `ai.moderation.thresholds` equals `summary.best_f1.threshold` of each committed run.json.

- [x] 8. Add the `--models` option to `moderation:eval` and write per-model metrics to one run.json (backend)
  Covers: AC-6
  - [x] 8.1 The spec signature is `moderation:eval {--models=qwen3.5:9b,gemma4:12b}`, and the output is one `bench/results/moderation/<date>/run.json` with metrics for each model. `app/Console/Commands/ModerationEvalCommand.php` has only `--provider` and scores one model per run, taken from settings or env. As a result, the two models sit in separate directories (`2026-11-12` and `2026-11-12-gemma4`), and the manual-test step 2 expectation "run.json has, for both models, …" is not met. Add `--models` with that default, run the set once per model (pinning the model for that run), and write `summary` keyed by model id. Re-run live and update the BENCHMARKS.md links and the guide `numbers` paths.
  - [x] 8.2 **Verify**: a `ModerationEvalTest` case runs the command with `--models=a,b` against a faked agent and asserts that run.json has a summary for each model with its own threshold and curve. Then the live `ddev artisan moderation:eval` writes a single run.json that holds both models.

- [x] 9. Log unparsable or empty model output as `validation_failed` and test that path (backend)
  Covers: AC-1
  - [x] 9.1 In `app/Services/ModerationService.php`, an empty structured answer comes back as `[]`, because the SDK's `DecodesStructuredOutput` returns `[]` for unparsable text. That answer throws `UnexpectedValueException`, and the attempt is recorded with `status = failed` and no `validationFailed` flag. It is retried, and two such answers end as `invalid`. Neither `ai_runs` row is flagged `validation_failed`, which contradicts AC-1 ("both attempts logged as `validation_failed`") and the scope line "Invalid or unparsable output is rejected and retried once … flagged `validation_failed`". No test covers this path. Record these attempts with `validationFailed: true`.
  - [x] 9.2 **Verify**: a `ModerationServiceTest` case fakes two empty answers (`[]`) and asserts status `invalid`, 2 attempts, and both `ai_runs` rows flagged `validation_failed`. A second case fakes an empty answer then a valid one and asserts `classified` after 2 attempts, with flags `[true, false]`.

- [x] 10. Run the browser check and manual-test steps 1 and 3, and record the outputs (checks)
  Covers: AC-7
  - [x] 10.1 Task 5.2 lists a "browser check", but the task 5 report says only curl was run, and task 6 does not report one. Task 6.2 says "outputs recorded here", but tasks.md holds no recorded outputs. AC-7 ("SHALL show the risk against the threshold, the reasons, the explanation, the facts, the attempts and the run link") is proven only by endpoint JSON and Inertia props. Nothing has confirmed that `resources/js/ai/Pages/Lab/Moderation.vue` renders them. Run `manual-test.md` step 1 in a real browser (a seeded listing, the advance-payment example, injection text) and step 3 (fingerprint and parity). Record the outcomes under task 6.
  - [x] 10.2 **Verify**: tasks.md records step 1 as observed. The risk meter shows the threshold line, the reason chips, the explanation, the facts panel (median, ratio, VINs, reported, injection pattern), the attempts, and a run link that opens `/ai/runs/{id}`. The injection case shows the reason `other` and a risk of at least 0.7. Step 3 records an unchanged fingerprint and a parity diff on the `captured=` line only.

## Phase: Gaps (converge round 2)

- [x] 11. Report the hard-negative false-positive rate at the best-F1 threshold, not only at the configured one (backend)
  Covers: AC-6
  - [x] 11.1 The spec's eval scope says "choosing the threshold with the best F1 …; at that threshold: accuracy, precision and recall for 'risky', and the false-positive rate on hard negatives". `app/Services/ModerationEvalScorer.php` `hardNegatives()` flags an item by `$o->result->risky`, which the service set against the configured threshold (`ai.moderation.thresholds` / `ai.moderation.threshold`), not against `best_f1.threshold`. The `best_f1` curve point carries accuracy, precision and recall but no hard-negative figure. The committed run only agrees because the config was set to best F1 before the final run. On a calibration run (for example a new prompt, a new model, or the 0.5 default), the reported hard-negative FP belongs to a different threshold than the one chosen. `ModerationEvalTest` shows this: the scripted run's best F1 is at 0.4, but `false_positives_on_hard_negatives` is scored at 0.5. Compute the hard-negative count, `of` and ids at `best_f1.threshold`, with unanswered items still counted as FP. Do this by adding the figure to the `best_f1` entry, for example `best_f1.false_positives_on_hard_negatives`, and keep the configured-threshold figure as it is. Show it in the command's table, and add it to the BENCHMARKS.md method text if the wording changes.
  - [x] 11.2 **Verify**: a new `ModerationEvalTest` case scripts hard negatives whose risk lies between the best-F1 threshold and a different configured threshold. It asserts that the best-F1 hard-negative figure counts them at the best-F1 threshold, with an unanswered hard negative still counted as FP. The existing `ModerationEvalTest` cases and the committed-run threshold test still pass.

- [x] 12. Delete the stray `tests/Feature/AiSettingsPageTest.php-E` backup before the commit (checks)
  Covers: MNC-1 (task 6.1 commit)
  - [x] 12.1 An untracked `tests/Feature/AiSettingsPageTest.php-E` sits next to the edited test. It is the `sed -i -E` backup left on macOS and matches the HEAD version byte for byte, with the old 9-row settings indices. It is not part of the spec and would be committed with the slice. Delete it.
  - [x] 12.2 **Verify**: `git status --short tests/` lists no `*.php-E` file. `ddev artisan test --compact tests/Feature/AiSettingsPageTest.php` passes.

## Fix pass and run record (2026-11-13)

Review findings fixed:
- `--models` with `--provider`: the pinned model is honoured, and the results and thresholds are filed under the model that ran.
- Accuracy, precision, recall and the hard-negative FP are read at the best-F1 threshold. The configured-threshold figures are kept apart, and when nothing has an F1 there is no threshold.
- The guard checks title, make, model, city and description.
- PHP floor: an injection pattern or a reported VIN lifts the risk to at least 0.7, adds the reason, and always flags the listing. The model risk is kept as `model_risk`.
- Cross-site refusal on `POST /api/moderation` reuses the `LimitCloudAi` check.
- The lab examples come from `resources/moderation/lab-examples.php`.
- Set items carry an explicit `kind`.
- The median cache key no longer includes `listing_id`.
- The eval uses the shared percentile, and the VIN register is loaded once.
- The stray `*.php-E` file is deleted.

Gates and checks:
- Full suite: 2319 passed. Pint and PHPStan are clean, and typecheck and build pass.
- The fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js` is unchanged.
- Parity diff: the `captured=` line only.

Live eval re-run, numbers for qwen3.5:9b / gemma4:12b:
| Figure | qwen3.5:9b | gemma4:12b |
|---|---|---|
| JSON-valid | 1.000 | 1.000 |
| Best-F1 threshold | 0.2 | 0.5 |
| Accuracy | 0.960 | 0.990 |
| Precision | 0.926 | 0.980 |
| Recall | 1.000 | 1.000 |
| Hard-negative FP | 3 of 15 | 0 of 15 |
| p50 | 1389 ms | 1870 ms |
- Clean-tree bench: after commit 0dd47a7, `bench/run.sh ping --quick` exited 0 on frankenphp.
