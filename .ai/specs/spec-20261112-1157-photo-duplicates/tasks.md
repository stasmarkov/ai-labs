# Duplicate photos and the best-photo pick — Tasks

**Spec**: spec-20261112-1157-photo-duplicates | **Size**: L | **Tasks**: 8

## Phase 1: Data

- [x] 1. Columns, generator and isolation (database, backend)
  Covers: AC-1, AC-2, MNC-1, MNC-3
  - [x] 1.1 Migration: `duplicate_of`, `variant`, `review_status` on `listing_photos`; `photos:make-duplicates` (seeded, five variant kinds with GD, `--fresh`); `excludeAugmented` on `VectorQuery`, default on; the grid tag.
  - [x] 1.2 **Verify**: `tests/Feature/PhotoDuplicateGeneratorTest.php` (determinism, kinds, sha256, files, `--fresh` scope); the SL-36, SL-37 and SL-38 tests unchanged plus a test that an augmented photo never appears in them.

## Phase 2: Groups and judgement

- [x] 2. Clustering (backend)
  Covers: AC-3, NFR-3
  - [x] 2.1 Tables `photo_duplicate_groups` and `photo_duplicate_members`; `DuplicateClusterService` (shared repository with augmented rows and the same listing, cutoff, union-find, cap at 6 with strongest-edge split); `photos:duplicates`.
  - [x] 2.2 **Verify**: `tests/Feature/PhotoDuplicateClusterTest.php` (crafted vectors: components, the cutoff edge, the cap and split, a rebuild replaces older groups).

- [x] 3. Best-photo agent and job (backend)
  Covers: AC-4, NFR-2
  - [x] 3.1 `PhotoBestPickAgent`, prompt v1, rules, retry, table `photo_duplicate_judgements`, `JudgeDuplicateGroupJob` on `ai-vision`, `photos:duplicates --judge --wait`.
  - [x] 3.2 **Verify**: `tests/Feature/PhotoBestPickTest.php` (images and ids sent; a pick outside the group rejected and retried; three bad answers lead to failed; a transport error is one attempt; `ai_runs` per attempt; the queue name).

## Phase 3: Review UI

- [x] 4. Review page and actions (backend, frontend)
  Covers: AC-5, AC-6, NFR-1, MNC-5
  - [x] 4.1 Routes (index, keep, undo), form requests, the service (marks only); `Lab/PhotoDuplicates.vue` (cards, pick, reasons, issues, run link, actions, summary, How it works, empty states); navigation and capability.
  - [x] 4.2 **Verify**: `tests/Feature/PhotoDuplicateReviewTest.php` (marks, undo, override, a photo outside the group refused, no `Storage::delete`, no row deleted); `NoReferencesInUiTest`; typecheck; build; browser check.

## Phase 4: Eval, guide, run

- [x] 5. Eval (backend)
  Covers: AC-7, NFR-3
  - [x] 5.1 `photos:duplicates-eval` (recall per kind, precision, the chosen cutoff rule, pick accuracy per model, valid on the first attempt, latency, clustering time).
  - [x] 5.2 **Verify**: `tests/Feature/PhotoDuplicateEvalTest.php` (the maths on crafted groups; the cutoff rule including the F1 fallback).

- [x] 6. Guide and docs (docs)
  Covers: AC-8
  - [x] 6.1 `photo-duplicates.php` guide; links from the photos and similar-photos guides; §16; README.
  - [x] 6.2 **Verify**: `GuideContentTest`, `LearningGuideTest`.

- [x] 7. Run once (checks)
  Covers: AC-1, AC-3, AC-7
  - [x] 7.1 Orchestrator: `photos:make-duplicates --photos=20`, `photos:embed --wait`, `photos:duplicates-eval`, set the cutoff, `photos:duplicates --judge --wait`, the `BENCHMARKS.md` section.
  - [x] 7.2 **Verify**: outputs recorded here.
    - `photos:make-duplicates --photos=20`: 20 originals, 56 variants stored.
    - `photos:embed --wait`: 56 photos embedded.
    - `photos:duplicates-eval`: cutoff 0.92 (F1 fallback; recall 0.886, precision 0.902); 212 photos clustered in 185 ms; gemma4:12b picked the original in 5 of 19 groups, qwen3.5:9b in 8 of 19.
    - `photos:duplicates --judge --wait`: 23 groups, 23 judged, 0 failed.
    - `BENCHMARKS.md` section "Duplicate photos and the best pick"; raw `bench/results/photo-duplicates/2026-11-12/run.json`.

- [x] 8. Checks (checks)
  Covers: MNC-1, MNC-2, MNC-4
  - [x] 8.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on frankenphp; re-run `photos:similar-eval` and `photos:search-eval` to confirm the earlier numbers are unchanged; `bench/run.sh ping --quick` after the commit.
  - [x] 8.2 **Verify**: outputs recorded here.
    - Full suite: 2115 passed (23366 assertions). Pint: passed. PHPStan: no errors. `npm run typecheck`: clean. `npm run build`: built.
    - `bench/asset-fingerprint.sh` before and after the build is identical: `app-DNob7CFE.css` (176bae5b...) and `app-PTzu1QaN.js` (7fbc3860...).
    - `PARITY_INSECURE=1 bench/parity.sh capture` on frankenphp: only the `captured=` line differs; restored the file, so the committed snapshot stays.
    - `photos:similar-eval --force` on the dev database (156 imported plus 56 augmented photos): P@8 make+model 0.677, make 0.785, coverage 0.987, matching the committed SL-38 baseline. Re-running inside a rolled-back transaction with the augmented rows deleted gives the same metrics (only latencies differ), so augmented rows change nothing.
    - `photos:search-eval --force`: P@8 0.533, MRR 0.608, coverage 1.000 (template). It differs from the 2026-11-11 file (0.506, 0.569, 0.967) because the photo extractions grew from 50 to 156 colour photos since that run; the same run with the augmented rows deleted is identical, and no augmented photo has an extraction. The new run files were removed so the committed results stay as they were.
    - `bench/run.sh ping --quick` refuses on this tree ("git working tree has changes outside bench/results/ and .ai/session/"); run it after the commit.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | generator deterministic, fresh scope | `tests/Feature/PhotoDuplicateGeneratorTest.php`; `manual-test.md#step-1` | test | 1 |
| AC-2 | augmented never in earlier features | `tests/Feature/PhotoDuplicateGeneratorTest.php`; `manual-test.md#step-2` | test | 1 |
| AC-3 | components, cutoff, cap | `tests/Feature/PhotoDuplicateClusterTest.php`; `manual-test.md#step-3` | test | 2 |
| AC-4 | one call, checked pick, retry, runs | `tests/Feature/PhotoBestPickTest.php`; `manual-test.md#step-4` | test | 3 |
| AC-5 | marks, undo, override, no delete | `tests/Feature/PhotoDuplicateReviewTest.php`; `manual-test.md#step-5`, `manual-test.md#step-6` | test | 4 |
| AC-6 | review page content | `tests/Feature/PhotoDuplicateReviewTest.php`; `manual-test.md#step-5` | test | 4 |
| AC-7 | curve, cutoff, pick accuracy | `tests/Feature/PhotoDuplicateEvalTest.php`; `manual-test.md#step-7` | test | 5, 7 |
| AC-8 | guide, no references | `tests/Feature/GuideContentTest.php`; `manual-test.md#step-8` | test | 6 |
| MNC-1 | earlier numbers unchanged | `manual-test.md#step-9` | manual | 8 |
| MNC-2 | parity and fingerprint | `manual-test.md#step-9` | manual | 8 |
| MNC-3 | extraction and grid unchanged | verify existing PhotoLab and PhotoExtract tests pass unchanged; `manual-test.md#step-2` | test | 1 |
| MNC-4 | offline suite | `tests/Feature/PhotoBestPickTest.php` | test | 3 |
| MNC-5 | never deletes | `tests/Feature/PhotoDuplicateReviewTest.php`; `manual-test.md#step-5` | test | 4 |
| NFR-1 | review safety | `tests/Feature/PhotoDuplicateReviewTest.php`; `manual-test.md#step-5` | test | 4 |
| NFR-2 | queue and image bounds | `tests/Feature/PhotoBestPickTest.php`; `manual-test.md#step-4` | test | 3 |
| NFR-3 | clustering time | `manual-test.md#step-3`, `manual-test.md#step-7` | manual | 5, 7 |

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

- [x] 9. Make the clustering tests deterministic (backend)
  Covers: AC-3, MNC-4
  - `tests/Feature/PhotoDuplicateClusterTest.php:78` ("AC-3 stores the connected components ... chaining through a middle photo") fails intermittently: 3 of 5 runs of the six `PhotoDuplicate*`/`PhotoBestPick`/`HybridSimilarPhotos` test files together dropped the axis-pair group (e.g. photos 191, 192). It passes alone. The test feeds sparse, mutually orthogonal axis vectors through the real HNSW index, which still holds dead entries from earlier rolled-back tests. The nearest query is approximate, so a neighbour is sometimes not returned. Task 5 also reported this failure. Fix the tests only, not the production clustering. Run the component, cutoff, cap and split cases through a fixed-neighbour `DuplicateGroupRepositoryInterface` fake, as `reviewFakeNeighbours` does. Alternatively, use dense, non-orthogonal vectors unique to each test. Keep real vectors only where the index itself is under test (the NFR-3 one-query-per-photo case and the label filter).
  - [x] 9.1 **Verify**: `ddev artisan test --compact tests/Feature/PhotoDuplicateGeneratorTest.php tests/Feature/PhotoDuplicateEvalTest.php tests/Feature/HybridSimilarPhotosTest.php tests/Feature/PhotoDuplicateClusterTest.php tests/Feature/PhotoDuplicateReviewTest.php tests/Feature/PhotoBestPickTest.php` passes 10 runs in a row, and the full suite passes twice.

- [x] 10. Browser check of the review page (frontend)
  Covers: AC-5, AC-6, MNC-5
  - Task 4.2's Verify lists a "browser check", and manual-test.md step 2 asks for clicks on "Keep this one, remove N", "Undo" and "Pick another". The task 4 report says no interactive browser check was done; only the page's 200 status and a source-string check (`PhotoDuplicates.vue` contains the labels) were verified. None of tasks 7 or 8 recorded one. Open https://ai-lab.ddev.site/ai/photos/duplicates against the judged groups from task 7, using Playwright or Chrome. On one card, check the similarities, the variant tags, the model's pick, its reasons and issues, the run link and the summary bar. Click Keep, then Undo, then "Pick another", and record that the marks, the review tag and the summary change. Also confirm that `ls storage/app/public/photos -R | wc -l` and the `listing_photos` count are unchanged.
  - [x] 10.1 **Verify**: the outputs are recorded here: the summary before and after each action, the file and row counts before and after, and no console errors.
    - Headless Chrome (playwright-core) on https://ai-lab.ddev.site/ai/photos/duplicates, dev database, group 1 (3 photos: original 0.9921, mirrored 0.9921, recompressed 0.9608), judged by gemma4:12b, status completed, 1 attempt, 9678 ms, 2 reasons, a "Logged run of the last attempt" link to /ai/runs/<id>. The card shows similarity bars, variant tags (original, augmented · mirrored, augmented · recompressed), the "model's pick" label on the mirrored copy, and 3 images. This group has no issues list.
    - Summary before: Groups 23, Judged 23, Reviewed 0, Marked for removal 0.
    - Keep this one, remove 2: Reviewed 1, Marked for removal 2; card tag "review: model pick kept"; the pick shows "kept", the other two "marked for removal"; Undo and "2 marked for removal; no file or row was deleted." appear.
    - Undo: Reviewed 0, Marked for removal 0; review tag, marks and Undo gone.
    - Pick another (on the original): Reviewed 1, Marked for removal 2; tag "review: your pick kept"; the original shows "kept", the model's pick and the recompressed copy "marked for removal".
    - Undo again: Reviewed 0, Marked for removal 0, card back to its first state.
    - Counts before and after: `ls -R storage/app/public/photos | wc -l` 681 and 681; `listing_photos` rows 212 and 212, 0 with a review_status after; `photo_duplicate_reviews` 0 after.
    - Console: no errors or warnings, no page errors, no HTTP responses of 400 or above.

- [x] 11. Isolation guards for the remaining earlier-feature queries (backend)
  Covers: AC-2, MNC-1, MNC-3
  - Task 1 added `source <> 'augmented'` filters to queries that no test exercises:
    - `EloquentPhotoEvalRepository::countPhotos()` and `sample()` (SL-27 `photos:eval`);
    - `EloquentSimilarEvalRepository::embeddedTitles()` (SL-36/38 eval);
    - `EloquentListingPhotoRepository::captionRows()` and `captionRowsFor()` (the private `captionQuery`, which feeds `photos:embed --text`, SL-38).
  - The "AC-2 the extraction, eval and coverage queries ..." test in `tests/Feature/PhotoDuplicateGeneratorTest.php` covers only `pendingPhotoIds`, the extraction `countPhotos`, `PhotoSearchEvalRepository::embeddedPhotoIds` and `SimilarEvalRepository::embeddedPhotos`. Clarification 7 requires "a test checks each eval's photo count against imported photos only". Extend that test, or add one next to it, with an augmented row present (with a vector and a completed extraction, so it would otherwise qualify). Assert that it is absent from the photo-eval count and sample, from `embeddedTitles` and from the caption rows. Change no existing SL-27, SL-36, SL-37 or SL-38 test.
  - [x] 11.1 **Verify**: `ddev artisan test --compact tests/Feature/PhotoDuplicateGeneratorTest.php` passes. Removing any one of those four filters by hand makes the new assertions fail.

## Phase: Gaps (converge round 2)

- [x] 12. Keep the judgement of a reviewed group when `photos:duplicates --judge` runs again (backend)
  Covers: AC-5, AC-6
  - Clarity gate 3 says a rebuild "keeps reviewed groups, their members, judgement and marks", and the scope says a rebuild deletes only "unreviewed judgements". `DuplicateClusterService::rebuild()` does keep reviewed groups, but `PhotoDuplicatesCommand::judge()` then queues every id from `EloquentDuplicateJudgementRepository::groupIds($label)`, which has no review filter. For a reviewed group, `EloquentDuplicateJudgementRepository::queue()` finds the existing completed row (same provider, model and prompt version), and `updateOrCreate` resets it to `queued` with `best_photo_id`, `reasons` and `issues` set to null. The reviewed card then loses the model's pick, and a fresh judgement can contradict the stored review `source = 'model'`. No test runs `--judge` after a review: "AC-5 a rebuild keeps a reviewed group ..." in `tests/Feature/PhotoDuplicateReviewTest.php` calls `rebuild()` only. Fix: leave groups that have a `photo_duplicate_reviews` row out of `groupIds()`, for example with `whereNotExists` on `photo_duplicate_reviews`. Then add a test.
  - [x] 12.1 **Verify**: a new test in `tests/Feature/PhotoDuplicateReviewTest.php`:
    - Setup: `Queue::fake()`, `reviewFakeNeighbours`, a reviewed group with a completed judgement, and one unreviewed component.
    - Run `photos:duplicates --judge`.
    - Assert the reviewed group's judgement row is unchanged (status `completed`, same `best_photo_id`, `reasons` and `invocation_ids`).
    - Assert exactly one `JudgeDuplicateGroupJob` is pushed, for the unreviewed group.
    - Run `ddev artisan test --compact tests/Feature/PhotoDuplicateReviewTest.php tests/Feature/PhotoBestPickTest.php`: it passes, and the new test fails when the filter is removed.

- [x] 13. Prove that `--fresh` deletes the old augmented files (backend)
  Covers: AC-1
  - AC-1 says `--fresh` "SHALL remove only augmented rows and their files". In `tests/Feature/PhotoDuplicateGeneratorTest.php`, "AC-1 --fresh removes only augmented rows and their files" collects `$augmentedPaths` but asserts only that they are non-empty. It never checks that those files are gone after `--fresh`, and the determinism test compares row signatures only. Deleting `$disk->delete($path)` from `PhotoDuplicateGeneratorService::deleteAugmented()` would leave the suite green. Add `Storage::disk('public')->assertMissing($path)` for every earlier augmented path. Also assert that none of the earlier augmented row ids still exist.
  - [x] 13.1 **Verify**: `ddev artisan test --compact tests/Feature/PhotoDuplicateGeneratorTest.php` passes. Removing the `$disk->delete($path)` loop in `deleteAugmented()` by hand makes that test fail; restore it afterwards.

- [x] 14. Show the full cutoff curve, with recall per kind, in `BENCHMARKS.md` (docs)
  Covers: AC-7
  - AC-7 says `BENCHMARKS.md` SHALL present the cutoff curve with recall per variant kind and precision. The "Duplicate photos and the best pick" table lists 8 of the 10 thresholds in `bench/results/photo-duplicates/2026-11-12/run.json`: the rows for 0.95 (69 pairs, recall 0.605) and 0.97 (39 pairs, recall 0.342) are missing. Recall per kind appears only in prose, for 0.92 and 0.98. Add the 0.95 and 0.97 rows and add recall-per-kind columns (cropped, darkened, downscaled, mirrored, recompressed), taking every value from `run.json`. Do not re-run the eval.
  - [x] 14.1 **Verify**: the table has 10 rows (0.90 to 0.99), and the pairs, true, false, precision, recall, F1 and five per-kind recall values of each row match `curve[]` in `run.json` to 3 decimals. `ddev artisan test --compact tests/Feature/LearningGuideTest.php tests/Feature/GuideContentTest.php` still passes.

## Fix pass and run record (2026-11-12)

Review fixes:
- **Major: `--judge` overwrote judgements.** It re-queued reviewed groups and reset their completed judgement. `groupIds($label, $request)` now leaves out reviewed groups and groups already judged by the same provider, model and prompt (test).
- **Major: a slow judgement could be run twice.** The job timeout was 600 s, above the database queue `retry_after` of 480 s, so a slow judgement could be reserved twice and fail while still running. The timeout is now 420 s.
- **Major: `--fresh` left stale review state.** `photos:make-duplicates --fresh` left orphaned review marks and groups stuck as reviewed. Before deleting the copies, the groups that hold a copy are now deleted, with their reviews and judgements, and the marks they wrote are cleared. This lives in the repository (`deleteGroupsHolding`) to respect the architecture rule (test).
- **Re-imported copies.** `photos:import --fresh` deletes the generated copies' files before the cascade removes their rows, so no file is orphaned.
- **Cutoff.** The config comment and the two 0.98 fallbacks now match the calibrated 0.92. The group cap is clamped to the 6 images a judgement sends.
- **Keep and undo.** They refuse groups of another vector label (404), and an overlong group id is a 404, not a 500 (tests).
- **Gap 13.** The `--fresh` test now checks that the old copy files are gone from disk.
- **Gap 14.** `BENCHMARKS.md` shows the full curve, 0.90 to 0.99, with recall per kind.
- **Guide.** The hard cases on this data are cropped (6/8 at 0.92) and recompressed (11/12), not mirrored (12/12). The guide's failure mode and experiment wording are corrected.

Not fixed (minor, recorded):
- `review_status` is per photo, not per label, so marks from an old label can show in new-label groups after a model switch.
- The page shows the configured cutoff, not the cutoff each group was built with.
- A rebuild recreates unreviewed groups, so their judgements are redone.
- Unused DTO fields.
- No index on `kept_photo_id` / `best_photo_id`.

Run (from the build, kept):
- 20 originals, 56 copies, 212 photos, 114 true pairs.
- Cutoff 0.92 by the F1 fallback: no threshold reached recall 0.9 with precision 1.0. At 0.92, P 0.902, R 0.886, F1 0.894. Precision is 1.0 only from 0.94, at recall 0.693 or less.
- Clustering takes 185 ms.
- Picked the original:
  - gemma4:12b: 5 of 19 groups (0.263), about chance for groups of about 3–4 photos;
  - qwen3.5:9b: 8 of 19 (0.421).

  Both answered validly on the first attempt every time.

Checks:
- Full suite: 2121 passed. PHPStan clean, typecheck and build pass.
- Fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Parity on frankenphp identical.
- `/ai/photos/duplicates`, `/ai/guides/photo-duplicates`, `/ai/photos` and `/listings/1` answer 200.
- Clean-tree bench: after commit b065e2f, `bench/run.sh ping --quick` exited 0 on frankenphp.
