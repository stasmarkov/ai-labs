# Search car photos by text — Tasks

**Spec**: spec-20261111-2206-photo-text-search | **Size**: M | **Tasks**: 6

## Phase 1: Service

- [ ] 1. Text encoding and prompt template (backend)
  Covers: AC-1, MNC-3
  - [ ] 1.1 `PhotoEncoderClient::embedText` (`modality` text, 512 check, normalise); `photo-search.v1.md`; config `ai.photo_search.*` (prompt, `min_score`, limit 12, candidates 40); a live check that the encoder accepts text, recorded in `decisions.md`.
  - [ ] 1.2 **Verify**: `tests/Feature/PhotoEncoderTextTest.php` (`Http::fake`: request body has modality text and the model; wrong dimensions raise).

- [ ] 2. Search service, cache and run rows (backend)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, NFR-2
  - [ ] 2.1 `PhotoTextSearchService` (validate, guard, index check, encode, the 40 nearest for the label, filter, at most 12, cache with generation and prompt version, `ai_runs` row); a repository method for the text-vector nearest search.
  - [ ] 2.2 **Verify**: `tests/Feature/PhotoTextSearchTest.php` (order and one per listing; cutoff; a refusal makes no encoder call; 409 without vectors; cache hit with no run row; a generation bump invalidates; the query never reaches SQL as text).

## Phase 2: HTTP and lab

- [ ] 3. API endpoint (backend)
  Covers: AC-1, AC-2, AC-3
  - [ ] 3.1 `GET /api/photos/search` (form request, throttle, JSON shape, 422/409/503).
  - [ ] 3.2 **Verify**: `tests/Feature/PhotoTextSearchApiTest.php`.

- [ ] 4. Lab page (frontend)
  Covers: AC-6
  - [ ] 4.1 `/ai/photo-search` controller and `Lab/PhotoSearch.vue` (field with `?q=`, chips, grid, tags, How it works panel, empty states), navigation, overview capability.
  - [ ] 4.2 **Verify**: `tests/Feature/PhotoSearchPageTest.php`; `NoReferencesInUiTest`; typecheck; build; browser check.

## Phase 3: Eval, guide, checks

- [ ] 5. Query set, eval and guide (backend, docs)
  Covers: AC-7, AC-8
  - [ ] 5.1 `queries.v1.jsonl` (30 queries with rules); `photos:search-eval` (metrics, curve, both prompt variants); `photo-search.php` guide; `related` links; §16; README.
  - [ ] 5.2 **Verify**: `tests/Feature/PhotoSearchEvalTest.php` (the maths on fixed vectors; the colour pool restricted to extracted photos; queries not found in any indexed text), `GuideContentTest`, `LearningGuideTest`.

- [ ] 6. Run and checks (checks)
  Covers: AC-7, MNC-1, MNC-2, MNC-4, NFR-1
  - [ ] 6.1 Run `photos:search-eval` live, set the calibrated `min_score`, write the `BENCHMARKS.md` section; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on frankenphp; `bench/run.sh ping --quick` after the commit.
  - [ ] 6.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | templated text search, one per listing, cutoff | `tests/Feature/PhotoTextSearchTest.php` | test | 1, 2 |
| AC-2 | guard refuses, no encoder call | `tests/Feature/PhotoTextSearchTest.php` | test | 2 |
| AC-3 | 409 without vectors | `tests/Feature/PhotoTextSearchApiTest.php` | test | 2, 3 |
| AC-4 | run row per encode, none on cache hit | `tests/Feature/PhotoTextSearchTest.php` | test | 2 |
| AC-5 | embed invalidates searches | `tests/Feature/PhotoTextSearchTest.php` | test | 2 |
| AC-6 | lab page shows query, prompt, results | `tests/Feature/PhotoSearchPageTest.php` | test | 4 |
| AC-7 | eval metrics, curve, both prompts | `tests/Feature/PhotoSearchEvalTest.php` | test | 5, 6 |
| AC-8 | guide, no references | `tests/Feature/GuideContentTest.php` | test | 5 |
| MNC-1 | SL-36 unchanged | verify existing SimilarPhotos and PhotoEmbed tests pass unchanged | test | 2 |
| MNC-2 | other pages and parity | `manual-test.md#step-3` | manual | 6 |
| MNC-3 | offline suite | `tests/Feature/PhotoEncoderTextTest.php` | test | 1 |
| MNC-4 | fingerprint unchanged | `manual-test.md#step-3` | manual | 6 |
| NFR-1 | latency | `manual-test.md#step-2` | manual | 6 |
| NFR-2 | query safety | `tests/Feature/PhotoTextSearchTest.php` | test | 2 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
