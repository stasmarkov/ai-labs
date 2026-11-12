# Ship report: SL-38 hybrid similar photos

## Summary

SL-38 adds a hybrid "similar photos" ranking: `w * image + (1 - w) * caption` over 40 image candidates. It ships with a weight slider on `/ai/photos`, caption vectors (`photos:embed --text`), a `photos:similar-eval --hybrid` sweep, and guide and BENCHMARKS sections. The learner journey works end to end and all gates pass (2020 tests).

It is not ready to push as-is, for three reasons:

- The verified state includes uncommitted converge round-1 fixes: 11 modified files and the untracked `app/DTOs/HybridPool.php`. HEAD (`c5a0919`) alone does not have them. For example, a request with no `w` answers 200 instead of 422, and the slider step is 0.05.
- Converge round-2 tasks 10–13 are still open.
- One confirmed major finding is unfixed: the endpoint reports the wrong `min_score` for a query photo without a caption (open task 11).

## Stack & repos

| Repo | Role | Stack | Tracked |
|------|------|-------|---------|
| `.` | backend (with the Inertia/Vue lab UI) | laravel | yes |

- No repo warnings and no untracked or WIP repo. The working tree does have uncommitted SL-38 work, listed under Summary.
- `.ai/session/*` files are untracked and should stay out of the commit. `.playwright-mcp/` is gitignored.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none

## Spec

- Id: `spec-20261112-1000-hybrid-similar-photos`
- Folder: `.ai/specs/spec-20261112-1000-hybrid-similar-photos`

## Investigation highlights

The investigation phase recorded no files, risks or existing pieces. From the build and acceptance trace:

- **Key files:**
  - `app/Services/SimilarPhotosService.php` (`hybridCandidates()`, `scoreHybrid()`, `blend()`, cache key)
  - `app/Repositories/PhotoVectorRepository.php` (shared `nearest(VectorQuery)`, `captionSimilarities()`)
  - `app/Services/PhotoLabService.php`
  - `app/DTOs/PhotoLabSimilar.php`, `app/DTOs/SimilarPhotos.php`, `app/DTOs/HybridPool.php` (new, untracked)
  - `app/Http/Requests/PhotoSimilarRequest.php`
  - `resources/js/ai/Pages/Lab/Photos.vue`
  - `config/ai.php`
  - `app/Services/SimilarEvalService.php`
- **Already shipped and reused:**
  - SL-36 image-only similar photos: the `rank()` path, `min_score` 0.75 and the cache key format are unchanged for `w` null or 1.0.
  - SL-37 photo text search now delegates to the shared `PhotoVectorRepository::nearest()`, so there is one HNSW query (AC-6).
- **Risks:**
  - Title-source leakage. Hybrid reads only `text_source = 'caption'`, and the title run is reported as leaking.
  - Behaviour drift in SL-36 and SL-37. Their tests are unmodified and pass.
  - `hybrid_min_score` is calibrated for w 0.9, so lower weights can empty the hybrid column.

## Tasks completed

- The build tracker recorded **8/13**, with task **4** flagged incomplete. Task 4's browser check was redone and recorded under converge task 9.
- `tasks.md` has tasks 1–9 checked. Tasks 10–13 (converge round 2) are unchecked.
- The "Done when" checklist in `tasks.md` is unticked.

## Converge

- Rounds run: **2**.
- Round 1 appended gap tasks **7**, **8** and **9**. All three are built and checked, but they are uncommitted:
  - 7: endpoint contract (`w` required and rounded; `query_has_caption`, `candidates`, `candidates_with_caption`; `hybrid_min_score`)
  - 8: query photo without a caption falls back to the image-only result; hybrid reads caption-source rows only
  - 9: slider step 0.1, heading `(w = …)`, neighbour caption line, "No caption for this photo.", browser run
- Round 2 appended gap tasks **10–13**. None are built:
  - 10: the limit of 8 and the photo-id tie order are untested
  - 11: the endpoint `min_score` is wrong for a query photo without a caption
  - 12: no `AI_SIMILAR_HYBRID_WEIGHT` env; `default_weight` 0.9 is hardcoded in `config/ai.php:111`
  - 13: `manual-test.md` step 2 still expects a reorder at w 0.5, where the column is actually empty

## Quality gates per repo

| Repo | Gate | Status | Details |
|------|------|--------|---------|
| `.` | Pint | pass | 664 files (ddev exec) |
| `.` | PHPStan | pass | No errors (ddev exec) |
| `.` | Pest/PHPUnit | pass | 2020 passed, 22471 assertions (ddev exec) |
| `.` | Debug scan | pass | No `dd(`/`dump(`/`ray(` in app, routes, database |

Contract-sync does not apply because this is a single repo.

## Visual tests

No visual test stage ran. The browser checks were done during acceptance (Playwright on `/ai/photos?photo=25` and `?photo=3`) and found 0 console errors.

## Acceptance

**The original scenario resolved end to end on the working tree.** The scenario is a learner on `/ai/photos?photo=N` moving the weight slider. The request path is: route, `ShowLabPhotoSimilarController`, `PhotoSimilarRequest`, `PhotoLabService::similar`, `SimilarPhotosService::for`, `PhotoVectorRepository`, then back as JSON, and the Vue column updates with no reload. There are no broken hops.

- **Slider:** the network log showed only `GET /ai/photos/25/similar?w=0.9|1|0.8|0.5`, all 200, with no reload.
  - At w 1.0 the hybrid column equals image-only.
  - At 0.8 it shows 3 reordered rows with image and caption parts.
  - At 0.5 it is empty and shows the "No similar photos" reason. This is task 13: the manual plan expected a reorder there.
- **Query photo without a caption (photo 3):** the page shows "No caption for this photo."; w 0.5 and w 1 return the same ids, with `query_has_caption: false`.
- **Endpoint contract:**
  - no `w` gives 422
  - `w=2` gives 422
  - `w=1` gives `w` null and no parts
  - `w=0.73` gives `w` 0.7
  - the route has `throttle:photo-similar`
- **Acceptance criteria:**
  - AC-1 to AC-4, AC-6 and AC-7 hold.
  - AC-5 holds. `hybrid.json` has a best w of 0.9 (p@8 0.6934) against a baseline of 0.6769, and the title run is flagged as leaking. The env override from the spec scope is missing (task 12).
  - AC-2's limit of 8 and tie order are correct in code but untested (task 10).
- **Must-not-change and NFRs:**
  - MNC-1 and MNC-2: SL-36 and SL-37 tests are unmodified and pass.
  - MNC-3: parity diff is limited to the `captured=` line, which was restored afterwards.
  - MNC-4: the asset fingerprint is unchanged (`app-DNob7CFE.css`, `app-PTzu1QaN.js`).
  - NFR-1: uncached p95 is 4.32 ms against an 80 ms budget.
  - NFR-2: the title source is never the default and is always reported as leaking.
- **E2E regression test:** none was written, because the stack declares no e2e config. No AC ids are covered by an E2E test, and none ran. Coverage comes from feature tests (`HybridSimilarPhotosTest`, `PhotoSimilarEndpointTest`, `PhotoHybridEvalTest`, `PhotoTextEmbedTest`, `PhotoVectorRepositoryTest`, `GuideContentTest`) plus the recorded browser run.
- **Caveat:** HEAD alone fails the endpoint-contract and UI checks above. The pass holds only once the round-1 fixes are committed.

## Confirmed review findings

### Major

- **`app/Services/PhotoLabService.php:97`**: The endpoint reports `hybrid_min_score` when the query photo has no caption, but the cutoff actually applied is `min_score`. The lab layer works the threshold out again from `w` alone. Fix: have `SimilarPhotosService` put the effective min score (and w) on `SimilarPhotos`/`HybridPool`, and have `PhotoLabService` read it there. This is open task 11, and two reviewers raised it (correctness and design).

### Minor

- **`app/Services/SimilarPhotosService.php:130`**: The hybrid cache key format did not change, so entries cached before this change (no `pool`, title-source scores) are still served for up to 3600 s after deploy. Fix: add a version segment to the hybrid key, or call `invalidateText()` on rollout.
- **`app/Http/Controllers/ShowLabPhotoSimilarController.php:15`**: Every slider request loads the full photo, including both 512-d vectors and every extraction, just to check that the photo exists. Fix: use an existence check, or select only `id`/`listing_id`.
- **`app/Services/SimilarEvalService.php:256`**: `hybridLatency()` calls `invalidate()`, which evicts the live image-only cache, including the listing-page block. Fix: drop `invalidate()` and keep `invalidateText()`.
- **`app/Services/SimilarEvalService.php:120`**: The hybrid eval does not model the production fallback that cuts a captionless query at `min_score`. Fix: move the fallback into a public step the eval reuses, or apply the same rule in the eval.
- **`app/DTOs/PhotoLabSimilar.php:51`**: `coverage` is still computed and returned, but the UI no longer reads it and the spec contract has no such key. Fix: remove it, along with the `PhotoSimilarEndpointTest` asserts and the stale docblock at line 40.

## Documentation updated

The docs stage of this run reported nothing. The SL-38 feature commit `e05f5af` already updates:

- `docs/learning-guide.md` (+12)
- `resources/guides/similar-photos.php` (hybrid step, recipe, two failure modes)
- `BENCHMARKS.md` ("Hybrid similar photos (2026-11-12)" section)
- `bench/results/photo-similar/2026-11-12/hybrid.json`

Still to do:

- Task 12: the guide recipe names only `AI_SIMILAR_HYBRID_MIN_SCORE`.
- Task 13: `manual-test.md` step 2 has not been revised.

## Manual test plan

- Written to `.ai/specs/spec-20261112-1000-hybrid-similar-photos/manual-test.md`. It has 9 steps covering AC-1 to AC-7, MNC-1 to MNC-4, NFR-1 and NFR-2, plus a rollback.
- How to run: with `ddev start` up and assets built, follow steps 1–9 against https://ai-lab.ddev.site, starting at `/ai/photos?photo=25` with the Network tab open. Roll back with `ddev artisan migrate:rollback --step=1` and `git checkout bench/parity`.

## Recommendation

**FIX-FIRST**

The reasons:

- The build is partial (tasks 10–13 open).
- Converge gaps are unbuilt.
- The confirmed major finding (task 11) is unfixed.
- The verified behaviour depends on uncommitted files.

All gates pass and the original scenario resolves on the working tree, so nothing blocks the work. It needs finishing and committing first.

Next: fix `PhotoLabService::similar()` (`app/Services/PhotoLabService.php:97`) so it reports the min score that `SimilarPhotosService` actually applied: carry it on `SimilarPhotos`/`HybridPool` (task 11). Then build tasks 10, 12 and 13, and commit them together with the round-1 fixes and `app/DTOs/HybridPool.php` before pushing.
