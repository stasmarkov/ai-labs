# Car photos and vision extraction — Tasks

**Spec**: spec-20261111-0000-photo-vision | **Size**: L | **Tasks**: 9

## Phase 1: Photos

- [ ] 1. Dataset terms and download script (devops)
  Covers: AC-1, NFR-1, MNC-5
  - [ ] 1.1 `bench/datasets/stanford-cars.sh` (`--accept-terms`, a mirror chosen and recorded, checksum, idempotent); `docs/datasets/stanford-cars.md` (terms, source, checksum, size, date checked); confirm `storage/app/datasets` is ignored.
  - [ ] 1.2 **Verify**: `tests/Feature/PhotoDatasetScriptTest.php` (no terms → exit 1 and no curl; stubbed curl → checksum checked; second run skips).

- [ ] 2. `listing_photos` and the import command (database, backend)
  Covers: AC-2, MNC-1, MNC-5
  - [ ] 2.1 A migration, model and factory; a `StanfordCarsClass` parser; a `PhotoMatcher` against `Catalog`; `photos:import` (the nearest year, one photo per listing, resize to 1024 px, sha256 unique, report, `--fresh`).
  - [ ] 2.2 **Verify**: `tests/Feature/PhotoImportTest.php` with a fixture dataset folder (generated JPEGs, class list including matching, partial and unknown classes).

## Phase 2: Vision extraction

- [ ] 3. Agent, schema and rules (backend)
  Covers: AC-3, MNC-2
  - [ ] 3.1 `PhotoVisionAgent` (structured output, `photo-vision.v1.md`, image via `Image::fromStorage`); `PhotoExtractionRules`; register `photo-vision` in `AiProviders` and config; `.env.example`.
  - [ ] 3.2 **Verify**: `tests/Feature/PhotoVisionAgentTest.php` (fake output passes; the schema keys and enums; the provider from config).

- [ ] 4. Extraction job, retry and storage (backend)
  Covers: AC-3, AC-4, NFR-2
  - [ ] 4.1 `photo_extractions` migration and model; `ExtractPhotoJob` (queue `ai-vision`, up to 3 attempts, one `ai_runs` row per attempt); `photos:extract` (`--limit`, `--model`, `--fresh`, progress, summary).
  - [ ] 4.2 **Verify**: `tests/Feature/PhotoExtractTest.php` (valid first time; invalid twice then valid; three invalid → failed with errors; runs per attempt; queue name; `--model` refused for another provider).

## Phase 3: Lab, eval, guide

- [ ] 5. Lab page `/ai/photos` (backend, frontend)
  Covers: AC-5, NFR-3
  - [ ] 5.1 Controller props (paginated photos, statuses, the selected extraction); `POST /ai/photos/{photo}/extract` (queues one job, takes an id only); `GET /ai/photos/{photo}/status`; `Lab/Photos.vue` (grid, detail, JSON, run link, poll, empty state, How it works panel); capability `ready`.
  - [ ] 5.2 **Verify**: `tests/Feature/PhotoLabPageTest.php`; `NoReferencesInUiTest` covers `/ai/photos`; typecheck; build; browser check at 1440 and 390.

- [ ] 6. Eval command (backend)
  Covers: AC-6
  - [ ] 6.1 `photos:eval` (a seeded sample of 50, per-model metrics, `run.json`).
  - [ ] 6.2 **Verify**: `tests/Feature/PhotoEvalTest.php` with fakes (metric maths on known answers).

- [ ] 7. Guide and docs (docs)
  Covers: AC-7
  - [ ] 7.1 `photos.php` as a capability guide; §16 files; README "Photos" paragraph (download, import, extract, eval commands).
  - [ ] 7.2 **Verify**: `GuideContentTest` (photos counted as a capability), the `LearningGuideTest` map.

- [ ] 8. Run the real pipeline once (checks)
  Covers: AC-1, AC-2, AC-3, AC-6
  - [ ] 8.1 The owner or orchestrator runs: download (with terms accepted), `photos:import`, `photos:extract --limit=50`, `photos:eval`; commit `run.json` and the `BENCHMARKS.md` section.
  - [ ] 8.2 **Verify**: `manual-test.md` steps 1–4 recorded here.

- [ ] 9. Checks (checks)
  Covers: MNC-1, MNC-3, MNC-4
  - [ ] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; parity on frankenphp unchanged; `bench/run.sh ping --quick` after the commit.
  - [ ] 9.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | terms gate, download, checksum, skip | `tests/Feature/PhotoDatasetScriptTest.php` | test | 1 |
| AC-2 | exact matching, nearest year, resize, dedupe | `tests/Feature/PhotoImportTest.php` | test | 2 |
| AC-3 | photo sent with image to photo-vision model | `tests/Feature/PhotoVisionAgentTest.php` | test | 3, 4 |
| AC-4 | retry up to 3, failed with errors, runs per attempt | `tests/Feature/PhotoExtractTest.php` | test | 4 |
| AC-5 | lab page grid, detail, run extraction | `tests/Feature/PhotoLabPageTest.php` | test | 5 |
| AC-6 | eval metrics and BENCHMARKS section | `tests/Feature/PhotoEvalTest.php` | test | 6, 8 |
| AC-7 | capability guide, no references | `tests/Feature/GuideContentTest.php` | test | 7 |
| MNC-1 | listing pages and parity unchanged | `manual-test.md#step-5` | manual | 9 |
| MNC-2 | existing features unchanged | verify existing AI feature tests pass unchanged | test | 3 |
| MNC-3 | offline, dataset-free tests | `tests/Feature/PhotoImportTest.php` | test | 2 |
| MNC-4 | fingerprint unchanged | `manual-test.md#step-5` | manual | 9 |
| MNC-5 | nothing from dataset committed | `manual-test.md#step-1` | manual | 1, 2 |
| NFR-1 | terms shown and recorded | `tests/Feature/PhotoDatasetScriptTest.php` | test | 1 |
| NFR-2 | own queue, bounded size | `tests/Feature/PhotoExtractTest.php` | test | 4 |
| NFR-3 | no path from requests | `tests/Feature/PhotoLabPageTest.php` | test | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
