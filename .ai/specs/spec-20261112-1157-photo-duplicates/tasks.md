# Duplicate photos and the best-photo pick — Tasks

**Spec**: spec-20261112-1157-photo-duplicates | **Size**: L | **Tasks**: 8

## Phase 1: Data

- [ ] 1. Columns, generator and isolation (database, backend)
  Covers: AC-1, AC-2, MNC-1, MNC-3
  - [ ] 1.1 Migration: `duplicate_of`, `variant`, `review_status` on `listing_photos`; `photos:make-duplicates` (seeded, five variant kinds with GD, `--fresh`); `excludeAugmented` on `VectorQuery`, default on; the grid tag.
  - [ ] 1.2 **Verify**: `tests/Feature/PhotoDuplicateGeneratorTest.php` (determinism, kinds, sha256, files, `--fresh` scope); the SL-36, SL-37 and SL-38 tests unchanged plus a test that an augmented photo never appears in them.

## Phase 2: Groups and judgement

- [ ] 2. Clustering (backend)
  Covers: AC-3, NFR-3
  - [ ] 2.1 Tables `photo_duplicate_groups` and `photo_duplicate_members`; `DuplicateClusterService` (shared repository with augmented rows and the same listing, cutoff, union-find, cap at 6 with strongest-edge split); `photos:duplicates`.
  - [ ] 2.2 **Verify**: `tests/Feature/PhotoDuplicateClusterTest.php` (crafted vectors: components, the cutoff edge, the cap and split, a rebuild replaces older groups).

- [ ] 3. Best-photo agent and job (backend)
  Covers: AC-4, NFR-2
  - [ ] 3.1 `PhotoBestPickAgent`, prompt v1, rules, retry, table `photo_duplicate_judgements`, `JudgeDuplicateGroupJob` on `ai-vision`, `photos:duplicates --judge --wait`.
  - [ ] 3.2 **Verify**: `tests/Feature/PhotoBestPickTest.php` (images and ids sent; a pick outside the group rejected and retried; three bad answers lead to failed; a transport error is one attempt; `ai_runs` per attempt; the queue name).

## Phase 3: Review UI

- [ ] 4. Review page and actions (backend, frontend)
  Covers: AC-5, AC-6, NFR-1, MNC-5
  - [ ] 4.1 Routes (index, keep, undo), form requests, the service (marks only); `Lab/PhotoDuplicates.vue` (cards, pick, reasons, issues, run link, actions, summary, How it works, empty states); navigation and capability.
  - [ ] 4.2 **Verify**: `tests/Feature/PhotoDuplicateReviewTest.php` (marks, undo, override, a photo outside the group refused, no `Storage::delete`, no row deleted); `NoReferencesInUiTest`; typecheck; build; browser check.

## Phase 4: Eval, guide, run

- [ ] 5. Eval (backend)
  Covers: AC-7, NFR-3
  - [ ] 5.1 `photos:duplicates-eval` (recall per kind, precision, the chosen cutoff rule, pick accuracy per model, valid on the first attempt, latency, clustering time).
  - [ ] 5.2 **Verify**: `tests/Feature/PhotoDuplicateEvalTest.php` (the maths on crafted groups; the cutoff rule including the F1 fallback).

- [ ] 6. Guide and docs (docs)
  Covers: AC-8
  - [ ] 6.1 `photo-duplicates.php` guide; links from the photos and similar-photos guides; §16; README.
  - [ ] 6.2 **Verify**: `GuideContentTest`, `LearningGuideTest`.

- [ ] 7. Run once (checks)
  Covers: AC-1, AC-3, AC-7
  - [ ] 7.1 Orchestrator: `photos:make-duplicates --photos=20`, `photos:embed --wait`, `photos:duplicates-eval`, set the cutoff, `photos:duplicates --judge --wait`, the `BENCHMARKS.md` section.
  - [ ] 7.2 **Verify**: outputs recorded here.

- [ ] 8. Checks (checks)
  Covers: MNC-1, MNC-2, MNC-4
  - [ ] 8.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on frankenphp; re-run `photos:similar-eval` and `photos:search-eval` to confirm the earlier numbers are unchanged; `bench/run.sh ping --quick` after the commit.
  - [ ] 8.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | generator deterministic, fresh scope | `tests/Feature/PhotoDuplicateGeneratorTest.php` | test | 1 |
| AC-2 | augmented never in earlier features | `tests/Feature/PhotoDuplicateGeneratorTest.php` | test | 1 |
| AC-3 | components, cutoff, cap | `tests/Feature/PhotoDuplicateClusterTest.php` | test | 2 |
| AC-4 | one call, checked pick, retry, runs | `tests/Feature/PhotoBestPickTest.php` | test | 3 |
| AC-5 | marks, undo, override, no delete | `tests/Feature/PhotoDuplicateReviewTest.php` | test | 4 |
| AC-6 | review page content | `tests/Feature/PhotoDuplicateReviewTest.php` | test | 4 |
| AC-7 | curve, cutoff, pick accuracy | `tests/Feature/PhotoDuplicateEvalTest.php` | test | 5, 7 |
| AC-8 | guide, no references | `tests/Feature/GuideContentTest.php` | test | 6 |
| MNC-1 | earlier numbers unchanged | `manual-test.md#step-4` | manual | 8 |
| MNC-2 | parity and fingerprint | `manual-test.md#step-4` | manual | 8 |
| MNC-3 | extraction and grid unchanged | verify existing PhotoLab and PhotoExtract tests pass unchanged | test | 1 |
| MNC-4 | offline suite | `tests/Feature/PhotoBestPickTest.php` | test | 3 |
| MNC-5 | never deletes | `tests/Feature/PhotoDuplicateReviewTest.php` | test | 4 |
| NFR-1 | review safety | `tests/Feature/PhotoDuplicateReviewTest.php` | test | 4 |
| NFR-2 | queue and image bounds | `tests/Feature/PhotoBestPickTest.php` | test | 3 |
| NFR-3 | clustering time | `manual-test.md#step-3` | manual | 5, 7 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
