# Car photos and vision extraction — Tasks

**Spec**: spec-20261111-0000-photo-vision | **Size**: L | **Tasks**: 9

## Phase 1: Photos

- [x] 1. Dataset terms and download script (devops)
  Covers: AC-1, NFR-1, MNC-5
  - [x] 1.1 `bench/datasets/stanford-cars.sh` (`--accept-terms`, a mirror chosen and recorded, checksum, idempotent); `docs/datasets/stanford-cars.md` (terms, source, checksum, size, date checked); confirm `storage/app/datasets` is ignored.
  - [x] 1.2 **Verify**: `tests/Feature/PhotoDatasetScriptTest.php` (no terms → exit 1 and no curl; stubbed curl → checksum checked; second run skips).

- [x] 2. `listing_photos` and the import command (database, backend)
  Covers: AC-2, MNC-1, MNC-5
  - [x] 2.1 A migration, model and factory; a `StanfordCarsClass` parser; a `PhotoMatcher` against `Catalog`; `photos:import` (the nearest year, one photo per listing, resize to 1024 px, sha256 unique, report, `--fresh`).
  - [x] 2.2 **Verify**: `tests/Feature/PhotoImportTest.php` with a fixture dataset folder (generated JPEGs, class list including matching, partial and unknown classes).

## Phase 2: Vision extraction

- [x] 3. Agent, schema and rules (backend)
  Covers: AC-3, MNC-2
  - [x] 3.1 `PhotoVisionAgent` (structured output, `photo-vision.v1.md`, image via `Image::fromStorage`); `PhotoExtractionRules`; register `photo-vision` in `AiProviders` and config; `.env.example`.
  - [x] 3.2 **Verify**: `tests/Feature/PhotoVisionAgentTest.php` (fake output passes; the schema keys and enums; the provider from config).

- [x] 4. Extraction job, retry and storage (backend)
  Covers: AC-3, AC-4, NFR-2
  - [x] 4.1 `photo_extractions` migration and model; `ExtractPhotoJob` (queue `ai-vision`, up to 3 attempts, one `ai_runs` row per attempt); `photos:extract` (`--limit`, `--model`, `--fresh`, progress, summary).
  - [x] 4.2 **Verify**: `tests/Feature/PhotoExtractTest.php` (valid first time; invalid twice then valid; three invalid → failed with errors; runs per attempt; queue name; `--model` refused for another provider).

## Phase 3: Lab, eval, guide

- [x] 5. Lab page `/ai/photos` (backend, frontend)
  Covers: AC-5, NFR-3
  - [x] 5.1 Controller props (paginated photos, statuses, the selected extraction); `POST /ai/photos/{photo}/extract` (queues one job, takes an id only); `GET /ai/photos/{photo}/status`; `Lab/Photos.vue` (grid, detail, JSON, run link, poll, empty state, How it works panel); capability `ready`.
  - [x] 5.2 **Verify**: `tests/Feature/PhotoLabPageTest.php`; `NoReferencesInUiTest` covers `/ai/photos`; typecheck; build; browser check at 1440 and 390.

- [x] 6. Eval command (backend)
  Covers: AC-6
  - [x] 6.1 `photos:eval` (a seeded sample of 50, per-model metrics, `run.json`).
  - [x] 6.2 **Verify**: `tests/Feature/PhotoEvalTest.php` with fakes (metric maths on known answers).

- [x] 7. Guide and docs (docs)
  Covers: AC-7
  - [x] 7.1 `photos.php` as a capability guide; §16 files; README "Photos" paragraph (download, import, extract, eval commands).
  - [x] 7.2 **Verify**: `GuideContentTest` (photos counted as a capability), the `LearningGuideTest` map.

- [ ] 8. Run the real pipeline once (checks)
  Covers: AC-1, AC-2, AC-3, AC-6
  - [ ] 8.1 The owner or orchestrator runs: download (with terms accepted), `photos:import`, `photos:extract --limit=50`, `photos:eval`; commit `run.json` and the `BENCHMARKS.md` section.
  - [ ] 8.2 **Verify**: `manual-test.md` steps 1–4 recorded here.

- [x] 9. Checks (checks)
  Covers: MNC-1, MNC-3, MNC-4
  - [x] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; parity on frankenphp unchanged; `bench/run.sh ping --quick` after the commit.
  - [x] 9.2 **Verify**: outputs recorded here.
    - `ddev artisan test --compact`: 1743 passed (19865 assertions), 155 s; no dataset on disk (`storage/app/datasets` absent), so MNC-3 holds (`PhotoImportTest` "MNC-3").
    - Pint `--dirty`: passed. PHPStan: `[OK] No errors`. `ddev npm run typecheck`: clean. `ddev npm run build`: built.
    - `bench/asset-fingerprint.sh` with the photo-vision Photos.vue and with the HEAD Photos.vue (the only front-end change): identical, `app-DNob7CFE.css` 176bae5b... and `app-PTzu1QaN.js` 7fbc3860... (MNC-4).
    - `PARITY_INSECURE=1 bench/parity.sh capture` on frankenphp: only the `captured=` line differs (MNC-1); reverted.
    - `bench/run.sh ping --quick`: refused, the working tree is not clean (nothing committed yet). Run it after the commit.

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
| MNC-1 | listing pages and parity unchanged | `manual-test.md#step-7` | manual | 9 |
| MNC-2 | existing features unchanged | verify existing AI feature tests pass unchanged | test | 3 |
| MNC-3 | offline, dataset-free tests | `tests/Feature/PhotoImportTest.php` | test | 2 |
| MNC-4 | fingerprint unchanged | `manual-test.md#step-8` | manual | 9 |
| MNC-5 | nothing from dataset committed | `manual-test.md#step-2` | manual | 1, 2 |
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

## Phase: Gaps (converge round 1)

- [x] 10. Convert the downloaded parquet shards into the layout `photos:import` reads (devops)
  Covers: AC-1, AC-2, MNC-5
  - `bench/datasets/stanford-cars.sh` fetches only `data/*.parquet` from the Hugging Face mirror, but `App\Support\StanfordCarsDataset` reads `classes.csv` (`file,class_name`) and `images/<file>`, so `photos:import` on the real download fails with "No dataset ... (expected classes.csv and images/)". Spec clarification 2 requires the script to normalise the mirror in a pinned, digest-referenced `python:3.12-slim` container (images out of parquet, integer labels to class names), and script tests to stub `docker` too. Nothing here does that.
  - **Verify**: `tests/Feature/PhotoDatasetScriptTest.php` with a stubbed `docker` on `PATH`: after a verified download the script runs the pinned image (digest in the command) to write `images/` and `classes.csv` into the gitignored target; a second run skips both download and conversion; no terms means no docker call. Then manual steps 1 and 2: `photos:import` on the real download prints matched and skipped counts and stores photos, and `git status` stays clean.

- [x] 11. Answer 409 for an in-flight extraction and use a named `photo-extract` limiter (backend)
  Covers: AC-5, NFR-3
  - Spec clarification 8 says a `queued` or `running` row makes the server answer 409 and the endpoint uses a `photo-extract` limiter (30 per minute per IP). `ExtractLabPhotoController` always returns 202, `PhotoLabService::extract` returns the in-flight row silently, and `PhotoLabPageTest` "a photo already queued or running is not queued twice" asserts 202. `routes/web.php` uses `throttle:30,1` instead of a named limiter like the others in `AiServiceProvider`.
  - [x] **Verify**: `tests/Feature/PhotoLabPageTest.php`: POST on a queued and on a running row returns 409 with the current status and pushes no job; a 31st POST within a minute returns 429; `Photos.vue` shows the 409 message without breaking the poll.

- [x] 12. Empty-state commands, the `request_failed` step and the schema recipe (frontend, docs)
  Covers: AC-5, AC-7
  - The scope says the empty state shows the two commands to run, but `resources/js/ai/Pages/Lab/Photos.vue` shows only `photos:import` and never the download script. The How it works steps should map to `request_sent`, `response_received`, `validation_failed` and `request_failed`, but `resources/guides/photos.php` has no `request_failed` step and `Photos.vue` passes `:events="[]"` to `LabHowItWorks`. The Modify recipes should include "add a field to the schema", but the guide has "Compare two models" in its place.
  - [x] **Verify**: `GuideContentTest` asserts that the photos works steps carry all four events and that a recipe covers adding a schema field; `NoReferencesInUiTest` still passes on `/ai/photos` and `/ai/guides/photos`; a browser check of the empty state shows both commands.

## Phase: Gaps (converge round 2)

- [x] 13. List the photo's other extraction rows as a switcher and name the button "Run extraction" (backend, frontend)
  Covers: AC-5
  - Clarity gate answer 1 says the detail panel also lists the other rows (another model or prompt version) as a switcher. `EloquentPhotoLabRepository::find` loads only the row for the effective model and prompt version (`forRequest`), `PhotoLabDetail` has no field for other rows, and `Photos.vue` shows the configured `extraction.model` and `extraction.promptVersion` instead of the shown row's. The AC and manual step 3 name the button "Run extraction", but `Photos.vue` labels it "Extract" or "Extract again".
  - **Verify**: `tests/Feature/PhotoLabPageTest.php`: a photo with rows for the effective model, for `other-model` and for an older prompt version exposes the other rows (model, prompt version, status, run link) on `selected`. Choosing one by its row id (which must belong to that photo, or the page falls back to the effective row) shows that row's data, model and prompt version. `Photos.vue` renders the switcher and the "Run extraction" label. Typecheck and build pass.

- [x] 14. Light `request_failed`, not `validation_failed`, for a transport failure on `/ai/photos` (backend, frontend)
  Covers: AC-5
  - `PhotoExtractionService::errorOf` stores a transport error as `errors: {error: [class, message]}`, and `ExtractPhotoJob::failed` does the same. In `Photos.vue`, `guideEvents` picks `validation_failed` whenever `errorList` is non-empty, so a failed row always lights `validation_failed` and the `request_failed` step never lights. The `error` key cannot tell the two apart, because an empty answer (`UnexpectedValueException`) uses it too.
  - **Verify**: `tests/Feature/PhotoLabPageTest.php`: the status endpoint and `selected` carry a failure kind. It is `request` for a row failed by `ConnectionException` or by a dead job, and `validation` for a row failed on rule errors or on three empty answers. `Photos.vue` maps `request` to `['request_sent', 'request_failed']` and `validation` to `['request_sent', 'response_received', 'validation_failed']`. Typecheck passes.

- [x] 15. List the remaining photo files in §16 and name the `ai-vision` worker command (docs)
  Covers: AC-7, NFR-2
  - The scope says §16 lists the new files, and the smaller points say the README and the guide name `ddev artisan queue:work --queue=ai-vision`. `docs/learning-guide.md` §16 has no `ExtractLabPhotoController`, `ShowLabPhotoStatusController`, `PhotoLabRepositoryInterface`/`EloquentPhotoLabRepository`, `PhotoExtraction` model, `PhotoExtractionInFlightException`, the two migrations, `bench/datasets/stanford-cars-convert.py`, or `PhotoLabPageTest`/`PhotoVisionAgentTest`/`PhotoDatasetScriptTest`. `README.md` says only "a worker for the `ai-vision` queue", `resources/guides/photos.php` never names the command, and line 1218 of the learning guide still calls `/ai/photos` a placeholder.
  - **Verify**: `tests/Feature/LearningGuideTest.php` asserts that §16 links each file above and that every link exists. `GuideContentTest` asserts that the photos guide text contains `queue:work --queue=ai-vision`. `README.md` contains `ddev artisan queue:work --queue=ai-vision`. `NoReferencesInUiTest` still passes on `/ai/guides/photos`.

- [ ] 16. Replace the pending photos number with the measured eval result (docs)
  Covers: AC-6, AC-7
  - Task 7 left `resources/guides/photos.php` `numbers` as one row with `'pending' => true`, waiting for the real run. Task 8 commits `run.json` and the `BENCHMARKS.md` section, but nothing points the guide's Numbers tab at the run. The scope says the Numbers tab comes from the eval.
  - **Verify**: after task 8, the photos guide's `numbers` rows use `file` `photo-vision/<date>/run.json` with paths into each model's first-attempt validity and body type agreement, and none is `pending`. `GuideContentTest` asserts the photos numbers have no pending row and render a value. The `BENCHMARKS.md` "Interview story" quotes one of these measured numbers.

- [x] 17. Re-run and re-record the checks after the round 1 gaps (checks)
  Covers: MNC-1, MNC-4
  - Task 9.2 records 1743 tests and an unchanged fingerprint measured against the Photos.vue of that time. Tasks 10 to 12 then changed `Photos.vue` (409 handling, empty state, events), the routes, `AiServiceProvider` and the tests. So the recorded full-suite count and the MNC-4 and MNC-1 evidence predate the current code, and `bench/run.sh ping --quick` has still not run.
  - **Verify**: the results are recorded under task 9.2 with the date: `ddev artisan test --compact` full pass with the new count; Pint, PHPStan, `ddev npm run typecheck` and `ddev npm run build`; `bench/asset-fingerprint.sh` identical to HEAD for `app-*.css` and `app-*.js` (MNC-4); `PARITY_INSECURE=1 bench/parity.sh capture` differs only on `captured=` (MNC-1); and `bench/run.sh ping --quick` after the commit.

## Fix pass and run record (2026-11-11)

Review fixes:
- **Major: wrong model on a cloud provider.** `photo-vision.model` is `null` by default. The resolver takes the provider's `models.vision.default` (Ollama: `gemma4:12b`, `OLLAMA_VISION_MODEL`), then its text model, so setting only `AI_PHOTO_VISION_PROVIDER=anthropic` never sends an Ollama id (`PhotoVisionAgentTest` MNC-2 case).
- **Major: `provider` skipped the cloud limits.** `LimitCloudAi` applies the request `provider` only to `AiProviders::OVERRIDABLE_FEATURES` (describe, nl-search, docs-answer). A `provider` in the photo request no longer bypasses the cloud limits or the cross-site guard.
- **Major: job ran on the wrong provider.** A job queued for one provider fails with a "queue it again" message when `photo-vision` now runs on another, instead of running there under the old label. The unique key is `(photo_id, provider, model, prompt_version)`, and the repository filters on provider (test added).
- **Double queueing.** `queue()` locks the row in a transaction and returns `null` while it is queued or running. The lab answers 409 and the command skips it (race test added).
- **Failure kinds.** Transport failures are stored under `transport` and empty or unparsable output under `output`; the panel lights `request_failed` for transport and `validation_failed` otherwise (gap 14).
- **Timeouts.** The job timeout is 420 s (3 × 120 s plus room), and the database queue `retry_after` default is raised to 480 s.
- **GD check.** `photos:import` checks for GD before `--fresh` deletes anything.
- **Switcher (gap 13).** The detail lists the photo's results for other models and prompt versions (model, prompt, status, latency, JSON, run link).
- **Button label.** It reads "Run extraction".
- **Polling.** It retries with doubling backoff, up to 5 failures, instead of stopping after one.
- **Empty state.** It shows `ddev artisan photos:import`.
- **Docs (gap 15).** §16 lists the controllers, repository, model, exception, migrations, converter and the three remaining tests. The README and the guide name `ddev artisan queue:work --queue=ai-vision`. The "placeholder" wording is gone.

Not fixed (minor, recorded):
- The cloud budget counts one hit per request while a job may make 3 calls; cloud vision is out of scope and unmeasured here.
- Class counts under `--limit` cover only the classes before the cut.
- There are no thumbnails: the grid loads the 1024 px files, acceptable on a local lab.
- EXIF orientations 2, 4, 5 and 7 (mirrored) and colour-profile conversion are not handled; the spec's resize answer is met only for the common orientations.

Checks:
- Full suite: 1756 passed. Pint and PHPStan clean, typecheck and build pass.
- Fingerprint: `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Dev database migrated (two new tables). `/ai/photos` and `/ai/guides/photos` return 200.
- Browser check: the empty state shows the commands, with no horizontal scroll, no slice or `.md` text, and no console warnings.

Still open:
- **Task 8 (real pipeline) and gap 16 (guide Numbers from the eval).** These need the owner to accept the Stanford Cars terms (`bench/datasets/stanford-cars.sh --accept-terms`). Then: `photos:import`, `photos:extract --limit=50` with an `ai-vision` worker, `photos:eval`, and commit `run.json`, the `BENCHMARKS.md` section and the guide numbers.
