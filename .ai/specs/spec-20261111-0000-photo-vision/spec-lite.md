---
id: spec-20261111-0000-photo-vision
title: Car photos and vision extraction
type: feature
size: L
priority: P1
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-11; standing approval)
approved_at: 2026-11-10T22:03:41Z
created: 2026-11-11
stacks: [laravel]
slice: SL-27
brs: [BR-26, BR-27, BR-90, BR-91]
---

# Car photos and vision extraction

## Summary

This slice gives the lab real car photos, then reads them with a local vision model.

1. **Import.** A download script fetches the Stanford Cars dataset (Q-06, answered 2026-11-05) into gitignored storage after the learner accepts its terms. An import command matches photos to listings by make, model and year, copies a subset to the public disk, and records them in `listing_photos`.
2. **Extract.** A queued batch sends each photo to the local vision model, either `gemma4:12b` or `qwen3.5:9b`. Both report the vision capability on this machine. The provider and model are chosen per feature (`photo-vision`), as SL-51 does for every feature. Each call returns a schema-validated `{caption, tags, dominant_colours, body_type, visible_damage, people_count}`, retried on invalid output, and every call is logged in `ai_runs`.
3. **Show and measure.** The `/ai/photos` lab page replaces today's placeholder. It shows the photo, the extracted JSON and the run. A small eval compares the two models on 50 photos.
4. **Teach.** The planned photos guide becomes a capability guide.

## Context

- **Problem**:
  - There is no photo storage or import. `/ai/photos` is a "coming" placeholder, and `resources/guides/photos.php` is `kind: planned`.
  - The seed has only static hero images.
  - SL-36–39 (similar photos, text-to-photo search, hybrid similarity, duplicates) all need stored photos and, for SL-38/39, these extractions.
- **User impact**: a learner sees how a Laravel app sends an image to a local multimodal model, forces structured output, retries bad JSON, logs and measures it, and switches the vision model by configuration.

## Scope

### In scope

- **Dataset terms and download** (`bench/datasets/stanford-cars.sh`):
  - **Terms.** The script prints the dataset's terms (research and non-commercial use; images belong to their owners) and refuses to run without `--accept-terms`. The build checks the current terms and the download source, and records both with their URLs in `docs/datasets/stanford-cars.md`.
  - **Source.** The original Stanford URL is no longer served, so a maintained mirror (for example Hugging Face) is chosen at build time. The build records the source, its checksum and the archive size.
  - **Storage and network.** It downloads into `storage/app/datasets/stanford-cars/` (gitignored by `storage/app/.gitignore`) and verifies the checksum. It is idempotent: a verified download is skipped. Nothing in the dataset is ever committed, and the build itself never downloads it (the owner runs the script; tests use fixtures).
- **Matching and import** (`photos:import {--limit=600} {--per-model=12} {--fresh}`):
  - **Parsing.** It parses each Stanford class name (`<Make> <Model…> <Body> <Year>`, for example `Ford Fiesta Sedan 2012`) into make, model, body word and year.
  - **Matching.** It matches against `Catalog::MODELS_BY_MAKE`: an exact make plus catalog model where the class's model starts with the catalog model (`3 Series`, `C-Class`, `Model S`). Classes that match no catalog model are skipped; a make-only match is not used, because a wrong model would teach the wrong thing.
  - **Assignment.** For each matched catalog model it takes up to `--per-model` photos, the class's year deciding which listings get them: listings of that make and model with the nearest year, one photo per listing, deterministic by listing id.
  - **Storage.**
    - Files are copied to the public disk as `photos/{listing_id}/{photo_id}.jpg`, resized to at most 1024 px on the long side with GD or Imagick (whichever the image has; no new dependency).
    - `listing_photos` holds `id`, `listing_id`, `path`, `source` (`stanford-cars`), `source_class`, `source_file`, `width`, `height`, `sha256` (unique) and timestamps.
    - The import report prints the counts: classes matched and skipped, photos stored, listings with a photo.
  - **Fresh run.** `--fresh` deletes this source's rows and files first.
- **Vision extraction**:
  - **Agent.** `App\Ai\PhotoVisionAgent` uses `HasStructuredOutput`, the versioned prompt `resources/prompts/photo-vision.v1.md` and `UsesActiveProvider`, with feature key `photo-vision` registered in `AiProviders` and env `AI_PHOTO_VISION_PROVIDER` / `_MODEL`. The default is Ollama with `gemma4:12b`. The image is attached through the SDK (`Image::fromStorage`).
  - **Schema.** All keys required:
    - `caption`: a string of 1–200 characters;
    - `tags`: 0–8 lowercase strings of 1–24 characters;
    - `dominant_colours`: 1–3 values from a fixed list (`black`, `white`, `grey`, `silver`, `red`, `blue`, `green`, `yellow`, `orange`, `brown`, `beige`, `gold`, `purple`);
    - `body_type`: one of the catalog body types (`hatchback`, `saloon`, `estate`, `SUV`, `MPV`, `coupe`, `convertible`, `pickup`, `van`) or `unknown`;
    - `visible_damage`: `none`, `minor`, `major` or `unknown`;
    - `people_count`: an integer from 0 to 20.
  - **Validation and retry.** The output is validated again in PHP by a rules class, as SL-23 does. An invalid or unparsable answer is retried up to 2 more times with the same prompt (3 attempts in total). After that the photo is marked `failed` with the last validation errors.
  - **Storage.** Results go to `photo_extractions`: `photo_id`, `provider`, `model`, `prompt_version`, `status` (`completed` or `failed`), `attempts`, `data` (json, null on failure), `errors` (json), `invocation_id`, `latency_ms`, and timestamps, unique per photo, model and prompt version.
  - **Batch.**
    - `photos:extract {--limit=} {--model=} {--fresh}` queues one job per pending photo on the queue `ai-vision`, with `tries=1` (retries happen inside the job, attempt by attempt).
    - `--model` is allowed only for models of the feature's provider.
    - Each attempt is an `ai_runs` row (BR-90) through the existing SDK listener.
    - The command prints the progress and summary in the `listings:embed` style.
- **Eval and measurement** (`photos:eval {--photos=50} {--models=gemma4:12b,qwen3.5:9b}`): runs on a fixed, seeded sample of 50 imported photos and writes `bench/results/photo-vision/<date>/run.json`. Per model it records:
  - valid on the first attempt (rate);
  - valid after retries (rate);
  - mean attempts;
  - latency p50 and p95;
  - body type agreement with the listing's catalog body type (rate, `unknown` counted as a miss);
  - colour presence (a sanity rate: at least one colour).

  `BENCHMARKS.md` gets a section in the house format (a table, "What the numbers say", "Interview story", BR-26). The run is done once by the orchestrator after the build; tests use fakes.
- **Lab page `/ai/photos`** (replaces the placeholder; capability status `ready`):
  - a grid of imported photos (paginated, 24 per page) with a status badge for each;
  - a detail panel showing the photo, the extracted JSON (pretty-printed), the attempts, the model, prompt version and latency, and a link to the run;
  - a "Run extraction" button for one photo, which queues one job, plus a live status poll every 2 s while it is queued or running;
  - the "How it works" panel (SL-48), with the steps mapped to the lifecycle events `request_sent`, `response_received`, `validation_failed` and `request_failed`;
  - an empty state when no photos are imported, which shows the two commands to run.
- **Guide**:
  - `resources/guides/photos.php` becomes `kind: capability`, with six tabs: how the image reaches the model, structured output and retry, implementation snippets, Modify recipes (switch the vision model, add a field to the schema, a new prompt version), Support (failure modes: model without vision, invalid JSON loop, timeout, Ollama unreachable, import found no photos), Numbers from the eval, and Experiments.
  - The planned SL-36 part moves to the guide's `related` text, with no slice id.
  - §16 lists the new files.

### Out of scope

- Image embeddings, similar photos, text-to-photo search and duplicates (SL-36–39).
- Cloud vision runs: they work through SL-51 when a key and a vision-capable model are set, but are not measured here.
- Showing photos on the public listing page (SL-36 places them).
- Pulling Ollama models (both are present).

## Must NOT change

- **MNC-1**: The listing pages, `/api/listings` responses and parity snapshots are unchanged; photos appear only in the lab.
- **MNC-2**: Existing AI features, their feature keys and resolver behaviour are unchanged. `photo-vision` is a new key.
- **MNC-3**: The test suite stays offline and dataset-free. It uses fixture images (a few small JPEGs generated in the test) and `PhotoVisionAgent::fake()`.
- **MNC-4**: The main asset fingerprint is unchanged.
- **MNC-5**: Nothing from the dataset, and no imported photo, is committed. `git status` stays clean after an import.

## Acceptance criteria

- **AC-1**: WHEN `stanford-cars.sh` runs without `--accept-terms` THE SYSTEM SHALL print the terms and exit 1 without downloading. WHEN it runs with them THE SYSTEM SHALL download into the gitignored folder and verify the checksum, and a second run SHALL skip the download.
- **AC-2**: WHEN `photos:import` runs on the downloaded dataset THE SYSTEM SHALL store only photos whose class matches a catalog make and model, assign each to a listing of that make and model with the nearest year, write the files to the public disk under the size limit, never store the same file twice (sha256), and print matched and skipped counts.
- **AC-3** (BR-91): WHEN `photos:extract` runs THE SYSTEM SHALL send each pending photo to the `photo-vision` provider and model with the image attached, and store a result that passes the schema and the PHP rules.
- **AC-4**: IF the model answers with invalid or unparsable output THEN THE SYSTEM SHALL retry up to 3 attempts in total, and IF all fail THEN mark the photo `failed` with the last errors; every attempt SHALL be an `ai_runs` row (BR-90).
- **AC-5**: WHEN a learner opens `/ai/photos` THE SYSTEM SHALL show the photo grid with statuses, and for a selected photo the photo, the extracted JSON, the attempts, the model, prompt version, latency and run link. "Run extraction" SHALL queue one job and update the status without a reload.
- **AC-6** (BR-26): WHEN `photos:eval` runs THE SYSTEM SHALL write the per-model metrics listed in scope to `bench/results/photo-vision/<date>/run.json`, and `BENCHMARKS.md` SHALL show them in the house format with a measured number in the story.
- **AC-7** (BR-27): WHEN `/ai/guides/photos` renders THE SYSTEM SHALL show a capability guide with six non-empty tabs, and the AC-1 no-references test of SL-48 SHALL pass on `/ai/photos` and the guide.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Data licence | Terms are shown and accepted before download; the source and terms URL are recorded; no dataset file in git | `tests/Feature/PhotoDatasetScriptTest.php` plus `git status` in manual step 1 |
| **NFR-2** | Resource use | Extraction runs on its own queue (`ai-vision`) with one worker by default, so it never starves the other AI queues; a photo is at most 1024 px, which bounds tokens and time per call | `tests/Feature/PhotoExtractTest.php` (queue name, resized size) |
| **NFR-3** | Safety | A photo path is always from `listing_photos`, never from request input; the lab endpoints take a photo id only | `tests/Feature/PhotoLabPageTest.php` |

## Rollout

- **Flag**: none. The page shows an empty state until the import runs.
- **Deploy order**: migrations, then code; the owner then runs the download, import, extract and eval.
- **Rollback**: revert; `migrate:rollback` drops the two tables, and `photos:import --fresh` (or deleting `storage/app/public/photos`) removes the files.

## Boundaries

- **Always**:
  - Use SDK fakes and generated fixture images in tests.
  - Go through `AiProviders`.
  - Validate output in PHP.
  - Update the guide, §16 and `BENCHMARKS.md`.
- **Ask first**:
  - A new Composer or npm package (image resizing uses GD or Imagick already in the image; if neither is present, ask).
  - Pulling another Ollama model.
  - Using a dataset other than Stanford Cars.
- **Never**:
  - Commit dataset files or imported photos.
  - Download the dataset during the build or the tests.
  - Accept a file path from a request.
  - Show photos on public listing pages in this slice.

## Technical context

- **Related code**:
  - `database/seeders/Catalog.php` (makes, models, body types);
  - the `listings` table (make, model, year);
  - `app/Ai/ListingFilterAgent.php` and `app/Rules/ListingFilterRules.php` (structured output plus PHP rules, SL-23);
  - `app/Ai/AiProviders.php` (features; add `photo-vision`);
  - `app/Ai/RecordAiRun.php` (`ai_runs`);
  - the `listings:embed` command and job (batch pattern);
  - `app/Http/Controllers/ShowLabPhotosController.php` and `resources/js/ai/Pages/Lab/Photos.vue` (placeholder);
  - `resources/guides/photos.php`;
  - `vendor/laravel/ai/src/Files/Image.php` (`fromStorage`, `fromPath`).
- **Already implemented**: SL-51 per-feature resolver; SL-48 guide engine and live panel; the lab design system; `OllamaServerInfo`.
- **Note for SL-52**: its feature list gains `photo-vision`. The SL-52 spec is updated to read features from `AiProviders` rather than a fixed list.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **Matching is exact make and model, or nothing.** A make-only match would put a BMW X5 photo on a 1 Series listing, and the eval's body type agreement would measure the import, not the model.
- **One extraction row per photo, model and prompt version.** Comparing gemma and qwen keeps both results instead of overwriting.
- **The owner runs the download**, after reading the terms. The build never fetches a 1–2 GB archive, and tests use generated images.
- **Defaults:** 600 photos at most, 12 per catalog model. A vision call takes a few seconds locally, so a full batch takes tens of minutes, not hours; both values are options.

## Clarity gate answers (binding, self-decided 2026-11-11)

1. **Status.** `photo_extractions.status` is one of `queued`, `running`, `completed` or `failed`.
   - The row is created as `queued` when the job is dispatched, and set to `running` when the job starts.
   - A photo with no row for the effective model and the current prompt version is `pending`.
   - The grid badge and the detail panel use the row for the effective `photo-vision` model and the current prompt version. The detail panel also lists the other rows (another model or prompt version) as a switcher.
2. **Dataset layout.** The script normalises any mirror into `storage/app/datasets/stanford-cars/images/<file>.jpg` plus `classes.csv` (`file,class_name`), and `photos:import` and its fixture read only that layout.
   - If the chosen mirror ships parquet or `.mat` annotations, the script converts them in a pinned, digest-referenced `python:3.12-slim` container, as the bench uses pinned images. Nothing is installed on the host.
   - The build records the mirror, format and conversion in `docs/datasets/stanford-cars.md`.
3. **Parsing.** Class names are parsed against the catalog, not by a generic grammar.
   - **Make:** the catalog make that the name starts with, case-insensitive and on a word boundary, with an alias map (for example `Mercedes-Benz` / `Mercedes`, `Volkswagen` / `VW`).
   - **Model:** the longest catalog model of that make that follows on a word boundary (`Model S` never matches `Model SX`).
   - **Year:** the trailing four digits.
   - **Body:** the words in between, multi-word allowed (`Crew Cab`, `Cargo Van`).
   - No make, no model or no year means the class is skipped.
4. **Assignment.**
   - **Order:** classes in name order, files in filename order.
   - **`--per-model`:** the cap is shared by every class mapped to the same catalog model.
   - **Listings:** listings of the make and model are ordered by year distance, then id, one photo per listing. If there are fewer listings than photos, the extra photos are skipped and counted.
   - **`--limit`:** a global cap that stops the import in that order.
   - **Re-run without `--fresh`:** a known sha256 is skipped and counted as "already imported", and a listing with a photo gets no second one.
   - **Write order:** each photo is written in a transaction (insert the row, then copy the file to `photos/{listing_id}/{id}.jpg`). A failed copy rolls the row back.
5. **Retry.**
   - Invalid or unparsable output is retried as a new attempt, 3 attempts at most.
   - A transport error (timeout, unreachable, HTTP error) is one attempt with no retry: the row ends `failed` with the error class and the redacted message. It is never left without a row, because the job catches and records the error.
   - The SDK listener writes one `ai_runs` row per prompt, failed ones included.
   - The extraction stores every attempt's invocation id in `invocation_ids` (json), and the page links the last one.
6. **Body mapping for the metric.** Stanford body words map as follows:
   - `Sedan` → saloon, `Hatchback` → hatchback, `Wagon` → estate, `SUV` → SUV, `Minivan` → MPV;
   - `Coupe` → coupe, `Convertible` → convertible;
   - any `Cab` → pickup, `Van` or `Cargo Van` → van;
   - anything else → no mapping.

   Agreement compares the model's `body_type` with the **photo's own class body**, not the listing's, case-insensitive. Photos whose class body has no mapping are left out of the denominator.
7. **Eval mechanics.** `photos:eval` calls the models live and synchronously, outside the queue.
   - It writes no `photo_extractions` rows; it writes `ai_runs` rows with origin `eval`.
   - **Sample:** imported photos ordered by sha256, first N.
   - **Latency:** p50 and p95 per photo including retries, plus the mean per attempt.
   - **Output:** `<date>` is the UTC `Y-m-d`. A same-day rerun needs `--force` (otherwise exit 2).
   - **Models:** they must belong to the `photo-vision` provider.
   - Fewer than `--photos` imported photos means exit 1 with the count.
8. **Re-runs and the endpoint.**
   - "Run extraction" on a `completed` or `failed` row resets it to `queued` (attempts reset) and runs again.
   - On a `queued` or `running` row the button is disabled, and the server answers 409.
   - The endpoint is a normal web route with CSRF, limited by a `photo-extract` limiter (30 per minute per IP), with no login (local lab).
   - `photos:extract --fresh` deletes only the rows for the effective model and the current prompt version.
9. **`--model` validation.**
   - For Ollama, the model must appear in `/api/tags` and report the `vision` capability in `/api/show`. Otherwise it exits 1 with "<model> is not a vision model on this Ollama".
   - For a cloud provider, any id is accepted; the provider's error surfaces on the first call.
10. **Guide and references.**
    - **Tabs:** the guide has the six standard tabs. How it works covers the image path and structured output with retry; then Implementation, Modify, Support, Numbers and Experiments.
    - "The AC-1 no-references test" is `tests/Feature/NoReferencesInUiTest.php`.
    - "§16" is section 16 of `docs/learning-guide.md`.
    - "The `listings:embed` style" means the progress and summary output of `EmbedListingsCommand`.
    - "House format" means the existing `BENCHMARKS.md` sections: a table, "What the numbers say" and "Interview story".
    - **Base URL:** https://ai-lab.ddev.site. Manual step 5's parity request set includes the listings routes.

**Smaller points.**
- **Resize:** the photo is oriented by EXIF, converted to sRGB, and written as JPEG at quality 85; non-JPEG input is converted.
- **Worker:** no supervisor is added. The README and guide name the command `ddev artisan queue:work --queue=ai-vision`.
- **Script tests:** they stub `curl` and `docker` through a `PATH` bin directory, like the other bench tests.

## Open questions

- [x] Clarity gate (10 items and smaller points) answered above.
- [x] Accepted assumption: BR-26, BR-27, BR-90 and BR-91 come from a draft brief applied in every slice (standing approval).
