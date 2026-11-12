# ai-lab

A study lab for building AI features in Laravel. It is a Laravel 13 app (PHP 8.4) with a 50 000-listing car marketplace as the demo domain. Each AI capability is implemented the Laravel way, runs live in the lab at `/ai` with a "how it works" note next to the result, is measured in [BENCHMARKS.md](BENCHMARKS.md), and is explained step by step in [docs/learning-guide.md](docs/learning-guide.md) with links to the code.

What you can study: streaming an answer and stopping it, embeddings and vector search, retrieval with citations and prompt-injection guards, answer caching, evaluation harnesses, structured output (a plain-language search turned into validated filters), and live updates. It runs on FrankenPHP (classic, worker and Octane modes), so you can also see how the server mode changes the way AI features are served.

## Requirements

- [DDEV](https://ddev.readthedocs.io) with Docker, about 8 GB of memory for Docker.
- Free disk: about 20 GB, mostly the models: `qwen3.5:9b` (text), `gemma4:12b` (second text model, used by the model comparisons) and `bge-m3` (embeddings).
- [Ollama](https://ollama.com) on the host for real models. Without it everything still runs on the `fake-stream` provider.

## Quick start

```sh
git clone <this repository> ai-lab
cd ai-lab
ddev setup                      # .env, Composer and npm dependencies, app key, server, Mercure keys, migrations, front-end build
```

Open https://ai-lab.ddev.site for the landing page and https://ai-lab.ddev.site/ai for the lab. The first `ddev setup` builds the FrankenPHP image and can take several minutes. A second run prints `Already set up: frankenphp`.

The default provider is `fake-stream`, which needs no model, so every page works right away. To use local models, pull them with Ollama and switch the provider in `.env`:

```sh
ollama pull qwen3.5:9b          # text model (OLLAMA_MODEL)
ollama pull bge-m3              # embedding model (OLLAMA_EMBED_MODEL)
ollama pull gemma4:12b          # second text model for comparisons
```

Then set `AI_PROVIDER=ollama` in `.env`. The container reaches Ollama at `http://host.docker.internal:11434` (`OLLAMA_URL`). Cloud providers are optional: set `ANTHROPIC_API_KEY`, `OPENAI_API_KEY` or `GEMINI_API_KEY` in `.env` and the provider appears in the lab's selector (or set `AI_PROVIDER` to use it for every text feature). With no key nothing changes and nothing leaves the machine. Cloud calls are billed and limited per IP (`AI_CLOUD_PER_MINUTE`); embeddings stay on Ollama bge-m3 unless you re-index. Details: the providers guide at `/ai/guides/providers`.

Seed the demo data and run the tests:

```sh
ddev artisan migrate:fresh --seed
ddev artisan test --compact     # runs offline with fakes
```

## Where to look

| I want to | Go to |
|-----------|-------|
| try the features | https://ai-lab.ddev.site/ai |
| understand how each one works | [docs/learning-guide.md](docs/learning-guide.md) (section 16 is a map of all the AI code) |
| see the numbers | [BENCHMARKS.md](BENCHMARKS.md) |
| see what is planned | [backlog.md](backlog.md) and [product-brief.md](product-brief.md) |

## Benches and evals

Measurements are commands, not tests; the tests run offline with fakes. Results are committed under `bench/results/` and quoted in [BENCHMARKS.md](BENCHMARKS.md).

```sh
bench/run.sh ping --quick                   # server benchmark on the active server (drop --quick for a full run)
ddev artisan rag:eval                       # retrieval and answer quality on 30 labelled questions
ddev artisan nl:eval                        # natural-language search turned into filters
```

Both evals need Ollama running with the models above.

## Photos

The photos lab sends a car photo to a vision model and stores a checked caption, tags, colours, body type, damage and people count. The photos come from the Stanford Cars dataset, which you download yourself and never commit; terms and source are in [docs/datasets/stanford-cars.md](docs/datasets/stanford-cars.md).

```sh
bench/datasets/stanford-cars.sh --accept-terms   # download the dataset (about 1.9 GB)
ddev artisan photos:import                       # match photos to listings, resize, store
ddev artisan photos:extract --limit=50           # queue the vision jobs (queue ai-vision)
ddev artisan photos:eval                         # measure models on a sample, writes run.json
```

Extraction needs a vision model on Ollama (default `gemma4:12b`) and a worker for the `ai-vision` queue: `ddev artisan queue:work --queue=ai-vision`. The tests run offline with fixture images.

### Image encoder

Similar-photo search uses a local image encoder, the Infinity CPU server with `openai/clip-vit-base-patch32` (512 dimensions), as a DDEV sidecar. Its compose file is `.ddev/docker-compose.photo-encoder.yaml`; `.ddev/config.yaml` is not touched. The image is pinned by digest and runs as `linux/amd64` (emulated on Apple silicon). It is capped at 2 GB, and its model weights live in the named volume `photo-encoder-models`, so a restart does not download them again.

```sh
ddev restart
ddev exec curl -s http://photo-encoder:7997/health   # the first start downloads about 600 MB of weights
```

Embedding and search, once the encoder answers, with a worker on `ai-vision`:

```sh
ddev artisan photos:embed --wait                 # embed every listing photo in queued batches
ddev artisan photos:embed --fresh --wait         # rebuild after switching the encoder model
ddev artisan photos:similar-eval                 # precision, coverage and latency, writes run.json
```

The listing page shows a "Similar cars" block and `/ai/photos` a "Similar photos" strip. How it works: `/ai/guides/similar-photos`.

### Search photos by text

The same encoder has a text side, so words can find photos: `/ai/photo-search?q=red%20convertible`, or `GET /api/photos/search?q=`. The query is wrapped in `a photo of {query}` (`resources/prompts/photo-search.v1.md`), encoded and matched against the photo vectors built by `photos:embed`. Text-to-photo scores are far lower than photo-to-photo ones, so the cutoff `AI_PHOTO_SEARCH_MIN_SCORE` is calibrated on 30 labelled queries:

```sh
ddev artisan photos:search-eval                  # precision, MRR, threshold curve, template vs bare query, writes run.json
```

How it works: `/ai/guides/photo-search`.

### Find duplicate photos and pick the best one

Real photos hold no duplicates, so the lab generates some, groups close vectors, and asks the vision model which photo of a group is best. The review page only marks photos: no row and no file is deleted.

```sh
ddev artisan photos:make-duplicates --photos=20   # degraded copies on the same listing, source augmented
ddev artisan photos:embed --wait                  # embed the copies
ddev artisan photos:duplicates-eval               # threshold curve, chosen cutoff, pick accuracy, writes run.json
ddev artisan photos:duplicates --judge --wait     # group by the cutoff, judge each group on the ai-vision queue
```

The review page is `/ai/photos/duplicates`. The cutoff is `AI_PHOTO_DUPLICATE_CUTOFF`. How it works: `/ai/guides/photo-duplicates`.

## Buyer assistant

`/ai/assistant` is a chat where the model decides when to search the listings. It has one read-only tool, `SearchListings`, whose arguments go through the same rules as `/listings` and then into the same search service. A bad call gets one repair, a question may use at most 4 searches, and every call is logged as a step. The stream is `POST /api/assistant/ask`; the lab shows each call and result in a timeline.

```sh
ddev artisan assistant:eval --models=qwen3.5:9b   # 30 questions: tool precision and recall, argument validity, steps, unknown ids
```

The provider and model come from the `assistant` feature on `/ai/settings`. How it works: `/ai/guides/assistant`.

## Listing moderation

`/ai/moderation` rates how risky a listing is for a buyer: a risk from 0 to 1, reasons from a closed list of seven, and a short explanation. The app computes what the model cannot know (the market median for the make, model and year, the price ratio, the VINs in the text and whether one is reported) and passes it as facts. PHP checks the answer; a rejected or unparsable one is asked for once more, then ends as `invalid`. A description that matches the input guard is classified, not refused. The endpoint is `POST /api/moderation` (JSON only, 30 requests a minute per IP, `AI_MODERATION_PER_MINUTE`).

```sh
ddev artisan moderation:make-set                  # the 100 labelled listings, same seed, same file
ddev artisan moderation:eval                      # once per model (--models, default qwen3.5:9b,gemma4:12b): accuracy, precision, recall, reason recall, threshold curve
```

The provider and model come from the `moderation` feature on `/ai/settings`; the risky cut-off is `AI_MODERATION_THRESHOLD`, or a per-model value in `ai.moderation.thresholds`. How it works: `/ai/guides/moderation`.

## History

The project began as a PHP server comparison (five web servers and worker mode; only the FrankenPHP family is kept) on a car marketplace, and was renamed ai-lab on 2026-11-10 when it became a study lab for AI features in Laravel. The server work stays below and in [BENCHMARKS.md](BENCHMARKS.md). The old DDEV URL is gone; use https://ai-lab.ddev.site.

The DDEV project name comes from `name:` in `.ddev/config.yaml`, not from the folder, so the folder (for example `~/work/interview`) can be renamed at any time:

```sh
ddev stop
mv ~/work/interview ~/work/ai-lab
cd ~/work/ai-lab && ddev start
```

## Switching servers

The default server is frankenphp. Only the FrankenPHP family is supported: `frankenphp` (classic), `frankenphp-worker` and `frankenphp-octane`. `.ddev/config.yaml` is never modified; the default lives in the `ddev server` command. On a fresh clone, run `ddev setup`: it copies `.env.example` to `.env` if `.env` is missing, starts DDEV, runs `composer install` and `key:generate` when they are needed, switches to the default server, creates the Mercure keys if they are missing, migrates, and builds the front end (`npm install && npm run build`) when `public/build` is missing. A second run prints `Already set up: frankenphp`. `ddev server --default` prints the default name, and `ddev server default` switches to it. Switch the web server with the DDEV host command:

```sh
ddev server                 # show the active and supported servers
ddev server frankenphp      # FrankenPHP in classic mode (no worker), Caddy in front
ddev server frankenphp-octane  # FrankenPHP worker mode, started by Laravel Octane
ddev server frankenphp-worker  # FrankenPHP worker mode, started by the add-on's Caddyfile
ddev server default         # back to the default server
```

The command writes the gitignored overlay `.ddev/config.zz-server.yaml` and restarts the project. The first switch to a FrankenPHP mode builds the web image and can take several minutes; later switches reuse it. FrankenPHP runs PHP inside its own process as threads, so it needs the thread-safe (ZTS) build of PHP, unlike the non-thread-safe (NTS) build of a PHP-FPM setup. FrankenPHP logs are in `ddev logs -f`.

FrankenPHP is not a DDEV `webserver_type`, so its files come from the `ddev/ddev-frankenphp` add-on (version in `.ddev/servers/frankenphp/VERSION`), committed unchanged under `.ddev/servers/frankenphp/` rather than installed with `ddev add-on get`. `ddev server frankenphp` copies `docker-compose.frankenphp.yaml` and `web-build/{Caddyfile,Dockerfile,pre.Dockerfile}.frankenphp` from there into `.ddev/` (all four are gitignored) and writes the add-on's `config.frankenphp.yaml` into the overlay. If `ddev restart` fails, the command puts back the previous overlay and add-on files and restarts the previous server.

The other servers this project once compared were removed; asking for one by its old name exits 1 with a message. Their measurements stay in [BENCHMARKS.md](BENCHMARKS.md).

Check that the three modes answer identically. Seed once so every capture sees the same data, then capture on each mode and compare:

```sh
ddev artisan migrate:fresh --seed
ddev server frankenphp
bench/parity.sh capture                       # reference: writes bench/parity/frankenphp.txt
ddev server frankenphp-octane
bench/parity.sh capture                       # writes bench/parity/frankenphp-octane.txt
bench/parity.sh compare frankenphp frankenphp-octane  # prints "identical" (exit 0) or the differing requests (exit 1)
ddev server frankenphp-worker
bench/parity.sh capture                       # writes bench/parity/frankenphp-worker.txt
bench/parity.sh compare frankenphp frankenphp-worker
ddev server default                           # back to the default server
```

Each snapshot line holds the path, status, media type (charset and other parameters dropped) and body SHA-256. Only the `/up` page has its render time masked before hashing. `capture` writes nothing if a request fails (exit 3) or the `Server` header does not match the active server (exit 4); set `PARITY_INSECURE=1` if the DDEV certificate is not trusted. The header must contain `caddy` on all three modes (case-insensitive); a server missing from that list makes `capture` stop with exit 2. `capture` and `bench/versions.sh` take their base URL from `ddev server --url` (`https://ai-lab.ddev.site`); set `PARITY_BASE_URL` to override it.

To record versions in `BENCHMARKS.md`, enable `/debug/runtime` and run `bench/versions.sh` on each server:

```sh
# set DEBUG_ROUTES=true in .env, then
ddev artisan optimize:clear
bench/versions.sh                             # one markdown row for BENCHMARKS.md
```

These two scripts never change `.env` (`bench/leaks.sh`, below, does, and restores it). Set `DEBUG_ROUTES` back to `false` afterwards; it stays `false` in `.env.example` and for benchmarks.

## How worker mode works

Classic PHP boots the framework on every request: autoload, service providers, config, routes, then the response, then everything is thrown away. In worker mode FrankenPHP starts a fixed number of PHP threads (workers) that each run `public/frankenphp-worker.php` once. That script boots Laravel a single time and then loops, waiting for requests. Both worker servers here use 4 workers and restart a worker after 500 requests.

One request, step by step:

1. The browser reaches the DDEV router, which ends TLS and forwards plain HTTP to the container with `X-Forwarded-Proto: https`. Each server turns that header into `HTTPS=on` for PHP in its own place: frankenphp-worker in its Caddyfile (`map` + `env HTTPS` on `php_server`), frankenphp-octane through `OCTANE_HTTPS=true` on the daemon, which makes Octane's `EnforceRequestScheme` listener set `HTTPS=on` and force `https://` URLs. The app itself does not trust proxy headers.
2. Caddy (inside FrankenPHP) checks whether the path is an existing file under `public/`, such as `/build/*` assets, and serves it directly.
3. Otherwise `try_files` rewrites the request to `frankenphp-worker.php` and hands it to an idle worker thread.
4. The worker, which already holds a booted application, builds a request from the handed-over data and runs it through the kernel. Routing, middleware, controller and view run, with no autoload or provider boot.
5. The response goes back to Caddy and the client, and Octane resets request-scoped state (sessions, resolved request instances and similar) so the next request starts clean. State kept in static properties or singletons is not reset, which is the main risk of worker mode.
6. After 500 requests the worker script leaves its loop and exits, and FrankenPHP starts a fresh worker, which limits memory growth. FrankenPHP's own worker `max_requests` option is not used; the counter lives in Octane's worker script.

The limit is the `MAX_REQUESTS` environment variable, which the worker script `vendor/laravel/octane/bin/frankenphp-worker.php` (the file `public/frankenphp-worker.php` is built on) reads and compares with its request counter; without it the script defaults to 1000. On frankenphp-octane it comes from `--max-requests=500` in the daemon command (the option's own default is also 500; `config/octane.php` has no `max_requests` key). On frankenphp-worker it is `env MAX_REQUESTS 500` in the Caddyfile `worker` block.

Octane does not rebuild the application for each request. Each request runs on a fresh clone of the container made from the booted application, so bindings resolved during the request do not leak into the next one. Bindings listed under `warm` in `config/octane.php` are resolved once at boot, and those under `flush` are dropped after every request, when the `OperationTerminated` event runs `FlushTemporaryContainerInstances` (the list is empty by default); the `listeners` list there holds these reset steps.

The two ways differ in who starts and owns the server:

| | frankenphp-octane | frankenphp-worker |
|---|---|---|
| Switch with | `ddev server frankenphp-octane` | `ddev server frankenphp-worker` |
| Process that serves | Octane daemon: `php artisan octane:frankenphp` with its own `frankenphp run` child, on port 8000 behind the router | one `frankenphp run --config /etc/frankenphp/Caddyfile` from the add-on |
| Octane daemon | yes | no |
| Who owns the config | Octane writes its Caddyfile at start-up (generated by `vendor/laravel/octane`); worker count and max requests come from the daemon command in the overlay | `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp` (`worker` block, `num 4`), edited from the add-on file as listed in its `CHANGES.md` |
| Restart workers | `ddev artisan octane:reload --server=frankenphp` (prints `Reloading workers...`), or `ddev restart` | `ddev restart`, or the admin API directly: `ddev exec curl -X POST localhost:2019/frankenphp/workers/restart`. `octane:reload` does not work here: it first checks Octane's state file (`storage/logs/octane-server-state.json`), which only the Octane daemon writes |
| Logs | `ddev logs -f`: Caddy JSON lines at warning level and above (`--log-level=warn`), plus start/stop messages | `ddev logs -f`: Caddy JSON lines, no per-request access log |

Both use the same thread-safe PHP and web image as `ddev server frankenphp`. Neither writes a log line per request, so both do the same work per request (that matters for the SL-08 benchmark). To watch every request while exploring, change the daemon's `--log-level=warn` to `info` in `.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml`, or add a `log` directive to the worker Caddyfile, then run `ddev server <name>` again.

One side effect to know: `/up` prints "Response rendered in Nms" from the `LARAVEL_START` constant. `public/frankenphp-worker.php` defines it so `/up` looks the same as on the other servers, but a constant is set once per worker, so in worker mode the number is the time since the worker booted, not the request time. The parity check masks it.

Octane features that only work on Swoole (checked against the Octane docs and source) do not work on either of these servers: concurrent tasks (`Octane::concurrently`), interval callbacks (`Octane::tick`), Octane tables (`Octane::table`) and the `octane` cache driver. Calling them on FrankenPHP fails or has no effect. Use the normal queue, scheduler and cache drivers instead.

A worker reads `.env` once when it boots. After changing `.env`, such as setting `DEBUG_ROUTES=true` for `/debug/runtime`, run `ddev artisan optimize:clear` and then `ddev restart` so the workers boot again. On frankenphp-octane `ddev artisan octane:reload --server=frankenphp` is quicker; on frankenphp-worker use the admin API call from the table above. After either, `/debug/runtime` answers 200. Without a restart or reload the running workers keep the old values and `/debug/runtime` still answers 404. The same goes for code: a code change needs a restart too, because the booted application and its loaded classes stay in memory until the worker exits.

To watch a worker persist and see state leak between requests, enable `DEBUG_ROUTES=true` (and restart the workers as above), then call `/debug/worker` and `/debug/leaks/{scenario}?dealer=<id>&locale=<uk|de|en>&mode=<leak|fixed>`. The scenarios are `dealer-scope`, `price-cache`, `number-locale` and `request-at-boot`; `dealer` is required, `mode` defaults to `leak`, and `amount` (in cents) is optional. With `DEBUG_ROUTES` off both routes answer 404 and the lab registers no container bindings. To count the leaks on the active server, run:

```sh
bench/leaks.sh                    # per scenario and mode: "leaked n/20"
bench/leaks.sh --max-requests 1   # frankenphp-octane only: rerun with workers restarting after every request
```

The script sets `DEBUG_ROUTES=true` in `.env` itself (backed up to `.env.bench-backup` and restored on exit) and restarts the workers before every row. It sends request A (dealer 1, locale uk) 20 times, then request B (dealer 2, locale de) 20 times, and counts the B responses with `leaked: true`. It exits 2 if `/debug/worker` does not answer 200, if a stale `.env.bench-backup` exists, or if `--max-requests` is given on another server. `--max-requests` writes the gitignored overlay `.ddev/config.zzz-bench-leaks.yaml`, a copy of the daemon config with the new value, and runs `ddev restart`; on exit it removes the overlay and restarts again. Each leak and its fix are explained in [section 7 of the learning guide](docs/learning-guide.md#7-state-leaks-in-worker-mode), and the measured counts are in [BENCHMARKS.md](BENCHMARKS.md#state-leaks).

## License

The app is built on the Laravel framework, which is open-sourced software licensed under the [MIT license](https://opensource.org/licenses/MIT).
