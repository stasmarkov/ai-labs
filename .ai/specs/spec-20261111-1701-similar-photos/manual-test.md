# Manual test plan: similar car photos (SL-36)

Base URL: https://ai-lab.ddev.site (all lab pages live under `/ai`).

## Preconditions

- `ddev start` has run and the app answers at https://ai-lab.ddev.site. The settings page only exists in the `local` environment.
- The 156 imported photos from SL-27 are in the database and `/storage/photos/...` files open (`ddev artisan storage:link` ran during `ddev setup`).
- Docker has room for a 2 GB container. The first encoder start downloads about 600 MB of weights, so allow a few minutes.
- For steps that embed, keep a second terminal open with a worker for the `ai-vision` queue: `ddev artisan queue:work --queue=ai-vision`. Without it `photos:embed --wait` waits until it gives up.
- Example data used below: photo 25 belongs to listing 675 (a BMW X3). Photo 141 and photo 100 are other photos. `/listings/1` is a listing without a photo.
- Browser: any, with the dev tools console open.

## Step 1

The encoder starts, answers `/health` and keeps its weights (AC-1).

```sh
ddev restart
ddev exec curl -s -o /dev/null -w '%{http_code}\n' http://photo-encoder:7997/health
ddev exec curl -s http://photo-encoder:7997/models
docker volume ls | grep photo-encoder-models
docker inspect ddev-ai-lab-photo-encoder --format '{{.HostConfig.Memory}} {{.HostConfig.RestartPolicy.Name}} {{.State.Health.Status}}'
ddev restart
time ddev exec curl -s -o /dev/null -w '%{http_code}\n' http://photo-encoder:7997/health
docker logs ddev-ai-lab-photo-encoder 2>&1 | grep -i -c download
```

Expected:
- The first health call prints `200` (on a very first start repeat it until it does; the model loads for up to two minutes).
- `/models` lists `openai/clip-vit-base-patch32` and `laion/CLIP-ViT-B-32-laion2B-s34B-b79K`.
- A volume named `ddev-ai-lab_photo-encoder-models` is listed.
- The inspect line reads `2147483648 unless-stopped healthy`.
- After the second `ddev restart` health answers `200` again in about a minute, and the log shows no new weights download.

## Step 2

Embed all photos, and refuse when the encoder is down (AC-2).

```sh
ddev artisan photos:embed --fresh --wait
ddev artisan tinker --execute 'echo DB::table("listing_photos")->whereNotNull("embedding")->count()." of ".DB::table("listing_photos")->count()."\n"; echo DB::table("listing_photos")->distinct()->pluck("embedding_model")->implode(",")."\n"; echo DB::table("ai_runs")->where("agent","PhotoEncoder")->count()."\n";'
ddev exec 'PHOTO_ENCODER_URL=http://127.0.0.1:1 php artisan photos:embed; echo "exit=$?"'
```

Expected:
- First command prints `Queued 10 jobs for 156 photos on ai-vision (photo-encoder/openai/clip-vit-base-patch32).` (10 jobs of up to 16 photos), then `Embedded 156 photos in ... s (...)`, and exits 0.
- The tinker line prints `156 of 156`, the label `photo-encoder/openai/clip-vit-base-patch32`, and a count of `PhotoEncoder` rows that is at least 10 (one per batch; older runs add to it).
- The unreachable run prints `The image encoder "photo-encoder" did not answer.` and `exit=1`, with no jobs queued.
- Wrong-dimension refusal needs a mis-sized model, so it is covered only by `PhotoEmbedTest` (`ddev artisan test --compact tests/Feature/PhotoEmbedTest.php`).
- Run `ddev artisan photos:embed --wait` once more: it queues 0 jobs and exits 0 because everything is current.

## Step 3

The similar strip on the lab page, the happy path (AC-3, AC-6).

Open https://ai-lab.ddev.site/ai/photos?photo=25

Expected:
- A "Similar photos" heading with tags `model photo-encoder/openai/clip-vit-base-patch32`, `cutoffs 0.75 to below 0.98` and `source database` or `cache`.
- Eight thumbnails, each with a similarity meter and the text "same make and model" or "other make or model", and a title.
- No thumbnail is a photo of listing 675 (the title of the query photo), values run from high to low, and none shows 0.98 or more or less than 0.75. Most matches are BMW X3 (5 of 8 were seen on the first run).
- No red errors in the console and no failed image requests.

Then open `/ai/photos?photo=` with the id of a photo from a different make (pick one in the list on that page). Expected: its matches differ and still respect the same cutoffs.

## Step 4

The cache, and no stale results after the vectors change (AC-4).

```sh
ddev artisan cache:clear
```

1. Open https://ai-lab.ddev.site/ai/photos?photo=141. Expected: source tag says `database`.
2. Reload the page. Expected: source tag says `cache` and the same 8 photos in the same order.
3. Same-label rebuild: run `ddev artisan photos:embed --fresh --wait`, then reload `/ai/photos?photo=141`. Expected: source `database` again (the cache did not survive the re-embed).
4. Switch-back check (known failing, see the note at the end): do this after step 6 below, once the model has been switched to the laion model and back. With photo 25 first viewed under the openai label (cache filled), then switched to laion with `--fresh`, then reset to openai with no `--fresh`, reload `/ai/photos?photo=25`.
   Expected: "No similar photos: index built with another model; run photos:embed --fresh."
   Observed on 2026-11-11: the strip still shows `source cache` with matches from the old label for up to an hour. This is an open defect, not a pass.

## Step 5

The listing page block (AC-5).

1. Open https://ai-lab.ddev.site/listings/675. Expected: a "Similar cars" section with 8 items; each is an image plus a title, linked to `/listings/<id>` of a different listing (for example `/listings/15182`, `/listings/3539`). Click one: it opens a normal listing page with HTTP 200. Right-click an image and open it: the URL is `/storage/photos/...` and it loads.
2. Open https://ai-lab.ddev.site/listings/1. Expected: no "Similar cars" text and no `/storage/` image markup (view source and search for `similar` and `storage/`).

## Step 6

Switching the photo model (AC-7).

1. Make sure no result for the photo is cached: `ddev artisan cache:clear`.
2. Open https://ai-lab.ddev.site/ai/settings. Expected: a row `embeddings-photos` of kind image embeddings, offering only the provider `photo-encoder` with two models, and an index line `Indexed rows in listing_photos: 156 of 156; 156 made with the effective model.`
3. In that row choose model `laion/CLIP-ViT-B-32-laion2B-s34B-b79K` and press Save without ticking anything. Expected: the checkbox "I will re-index after changing the model; search answers 409 until then." appears, and Save without it shows `Confirm that you will re-index: search answers 409 until you do.` (HTTP 422); nothing is saved.
4. Tick the checkbox and press Save. Expected: `embeddings-photos now uses photo-encoder / laion/CLIP-ViT-B-32-laion2B-s34B-b79K.` and a warning that 156 vectors were made with another model.
5. Open https://ai-lab.ddev.site/ai/photos?photo=100. Expected: no thumbnails, the text "No similar photos: index built with another model; run photos:embed --fresh."
6. Run `ddev artisan photos:embed --fresh --wait` (worker running). Reload the page. Expected: the label tag now reads `photo-encoder/laion/CLIP-ViT-B-32-laion2B-s34B-b79K`. This model scores lower, so a message such as "no photo of another listing is similar enough" at the 0.75 cutoff is normal.
7. Rollback: press "Reset to configuration" on the row, then `ddev artisan photos:embed --fresh --wait`. Expected: `/ai/photos?photo=25` shows 8 matches again under the openai label.

## Step 7

Eval and benchmark numbers (AC-8).

```sh
ddev artisan photos:similar-eval --force
cat bench/results/photo-similar/$(date -u +%F)/run.json | head -60
```

Then open `BENCHMARKS.md` and find the section "Similar car photos (2026-11-11)".

Expected:
- The command prints its summary and writes `run.json`. `--force` is needed because the command refuses to overwrite the same UTC day's file. Revert with `git checkout bench/results` if the numbers moved and you do not want to keep them.
- `run.json` has precision for make and model (about 0.68), precision for make (about 0.79), coverage (about 0.99), p50 and p95 latency cached and uncached, throughput in photos per second, and the threshold curve from 0.6 upward.
- The `BENCHMARKS.md` section has the threshold table, the same figures, and the headings "What the numbers say" and "Interview story".

## Step 8

The guide (AC-9).

Open https://ai-lab.ddev.site/ai/guides/similar-photos.

Expected: six tabs, in this order: How it works, Implementation, Modify, Support, Numbers, Experiments; each has text. No console errors. `/ai/guides/photos` links to it. Then run `ddev artisan test --compact tests/Feature/GuideContentTest.php` (the no-references check on the guide and on `/ai/photos`); it passes.

## Step 9

Pages without a photo are byte-identical (MNC-1).

```sh
PARITY_INSECURE=1 bench/parity.sh capture
git diff -U0 bench/parity
git checkout bench/parity
```

Expected: the diff shows only the `captured=` header line; the hash for `/listings/1` stays `f840ff18...`. Repeat after `ddev server frankenphp-worker` and `ddev server frankenphp-octane` (switch back with `ddev server frankenphp`); the result is the same in each mode.

## Step 10

Nothing else moved (MNC-2, MNC-3, MNC-5).

```sh
git diff HEAD -- .ddev/config.yaml
git diff HEAD -- app/Models/ListingPhoto.php app/Services/PhotoLabService.php app/Repositories/EloquentPhotoLabRepository.php | grep '^-' | grep -v '^---'
```

Then open https://ai-lab.ddev.site/ai/settings and look at the other seven rows.

Expected:
- The first command prints nothing.
- The second prints nothing (additions only, no removed lines).
- The seven earlier rows still show their old kinds and providers, and their index lines are unchanged. On `/ai/photos` the existing extraction button for a photo still works as before.

## Step 11

Latency (NFR-1).

Use the `run.json` from step 7.

Expected: uncached p95 is under 50 ms (1.26 ms was recorded) and cached p95 is under 5 ms (0.07 ms recorded), over 156 samples. In the browser network tab, `/ai/photos?photo=25` loads in well under a second both times.

## Step 12

Resources and queue (NFR-2).

```sh
docker stats --no-stream ddev-ai-lab-photo-encoder
```

Expected: the memory limit column shows 2 GiB. While `photos:embed` runs, the page `/ai/photos?photo=25` still opens at normal speed. Stop the worker, run `ddev artisan photos:embed --fresh` (no `--wait`): it prints `Queued ... on ai-vision` and returns at once, and the vectors stay empty until the worker runs. Start the worker and finish with `ddev artisan photos:embed --wait`.

## Step 13

Visitors never see encoder problems (NFR-3).

```sh
docker stop ddev-ai-lab-photo-encoder
```

Open https://ai-lab.ddev.site/listings/675 and https://ai-lab.ddev.site/ai/photos?photo=25. Then `docker start ddev-ai-lab-photo-encoder`.

Expected: both pages answer 200 from the stored vectors with no error text (the web request never calls the encoder). The block links use only `/listings/<id>` and `/storage/photos/...` paths from the database. Only `photos:embed` fails while the container is stopped (step 2 message).

## Run record

2026-11-11: steps 1 to 3 and 5 to 13 passed as written. Step 4 part 4 and the "reported only on a cache miss" part of step 6 do not pass yet: after the model moves from one label to another and back without `--fresh`, a cached result is served with no "index built with another model" reason. Logged in `tasks.md` under 9.2.
