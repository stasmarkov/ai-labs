# Manual test plan: optional cloud providers (SL-51)

App: https://ai-lab.ddev.site (DDEV, PostgreSQL). Run every command from the project root.

## Before you start

- `ddev start`, then `ddev artisan migrate` (nothing new to migrate; this just proves the DB is up).
- Back up your env file: `cp .env .env.manual-backup`. Steps that change `.env` say so, and each is followed by `ddev restart`.
- Start with `.env` holding `AI_PROVIDER=fake-stream`, and `ANTHROPIC_API_KEY`, `OPENAI_API_KEY`, `GEMINI_API_KEY` all empty. No cloud account is needed: wherever a key is required the plan uses a made-up one (`sk-ant-manual-0001`). With a made-up key the cloud provider answers 401, which is the point of those steps.
- A helper to show the last runs (used in several steps):

```sh
ddev artisan tinker --execute 'foreach (DB::table("ai_runs")->orderByDesc("id")->limit(3)->get(["agent","provider","model","status"]) as $r) { echo "$r->agent | $r->provider | $r->model | $r->status\n"; }'
```

- Listings and the docs index must exist (`/listings/1` opens, `ddev artisan docs:index` was run once with Ollama bge-m3). Steps 4 and 13 need Ollama running on the host with `qwen3.5:9b` and `bge-m3`; skip the parts that say so if it is not.
- Rollback for the whole plan: `cp .env.manual-backup .env && ddev restart`, and `rm -f app/Ai/ZzManualProbe.php` (step 12).

## Step 1

Defaults with no keys (MNC-1, and the selector part of AC-3).

1. Open https://ai-lab.ddev.site/ai/describe.
2. Open the Provider select.
3. Open https://ai-lab.ddev.site/ai/guides/providers.

Expected:
- The select lists `fake-stream`, `fake-instant` and the configured provider marked `(configured)`. There is no `anthropic`, `openai` or `gemini` option, because they have no key.
- The guide shows a capability table with rows fake-stream, fake-instant, ollama, anthropic, openai, gemini. The key column for the cloud rows reads `not set (ANTHROPIC_API_KEY)`, `not set (OPENAI_API_KEY)`, `not set (GEMINI_API_KEY)`.
- No browser console errors.

## Step 2

Describe with a provider override, the main happy path (AC-3, AC-4).

1. On https://ai-lab.ddev.site/ai/describe choose listing 1 if asked, pick `fake-instant` in the Provider select, press Start.
2. In the browser network tab, find the stream request.
3. Click through to the run page, then run the helper command from "Before you start".

Expected:
- The request is `GET /listings/1/describe?provider=fake-instant`, status 200, an event stream.
- The text arrives at once (the instant fake) and the stream ends with `[DONE]`.
- The run page `/ai/runs/<id>` opens with 200.
- The newest row reads `CarDescriptionAgent | fake-instant | fake-instant-1 | completed`.
- Pick `fake-stream` and run again: the row now says `fake-stream`, model `fake-slow-1`.

## Step 3

Docs chat with an override (AC-3).

1. Open https://ai-lab.ddev.site/ai/chat, pick `fake-instant`, type `How do I define a route?`, press Ask.
2. Look at the request in the network tab, then run the helper.

Expected:
- `POST /api/docs/ask`, status 200, the JSON body contains `"provider":"fake-instant"`.
- The newest row is `DocsAnswerAgent | fake-instant | fake-instant-1 | completed`.
- Needs the docs index and Ollama embeddings (bge-m3). If search fails with 503 because Ollama is down, that is not this feature; start Ollama and retry.

## Step 4

NL search: override sends provider, the configured option sends none (AC-3, MNC-2).

Needs `AI_PROVIDER=ollama` in `.env` (then `ddev restart`) and Ollama running.

1. Open https://ai-lab.ddev.site/ai/nl-search, type `red bmw`, choose `fake-instant`, press Search.
2. Choose the `(configured)` option, press Search again.

Expected:
- First request: `/api/listings/nl?q=red+bmw&model=...&provider=fake-instant`. It answers 503. This is a known limit: the fake driver cannot return structured output. The run row still names `fake-instant`.
- Second request: `/api/listings/nl?q=red+bmw&model=qwen3.5:9b` with no `provider` parameter, status 200, the same JSON shape as before this feature, and a new `ListingFilterAgent | ollama | qwen3.5:9b` row.

## Step 5

The reported failing case: a provider that is not enabled is refused with 422, never silently used (AC-2, AC-3).

```sh
curl -s -i 'https://ai-lab.ddev.site/listings/1/describe?provider=anthropic' | head -20
curl -s -i 'https://ai-lab.ddev.site/api/listings/nl?q=red+bmw&provider=openai' | head -20
curl -s -i -X POST https://ai-lab.ddev.site/api/docs/ask -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"q":"What is a route?","provider":"gemini"}' | head -20
curl -s -i 'https://ai-lab.ddev.site/listings/1/describe?provider=bogus' | head -20
curl -s -i 'https://ai-lab.ddev.site/api/listings/nl?q=a&provider[]=x' | head -20
```

(Add `-k` if the local certificate is not trusted.)

Expected:
- The first four answer `422` with JSON containing `"code":"provider_not_enabled"` and a message naming the provider. No event stream starts.
- The fifth (array instead of a name) answers `422` "Validation failed", not 500.
- `ai_runs` gets no new row for any of them (run the helper before and after; the top row is unchanged).

## Step 6

Configured cloud provider with no key fails clearly (AC-2).

1. In `.env` set `AI_DESCRIBE_PROVIDER=anthropic`, `AI_NL_SEARCH_PROVIDER=anthropic`, `AI_DOCS_ANSWER_PROVIDER=anthropic`, leave `ANTHROPIC_API_KEY=` empty. Run `ddev restart`.
2. Run:

```sh
curl -s -i -k 'https://ai-lab.ddev.site/listings/1/describe' | head -20
curl -s -i -k 'https://ai-lab.ddev.site/api/listings/nl?q=red+bmw' | head -20
curl -s -i -k -X POST https://ai-lab.ddev.site/api/docs/ask -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"q":"What is a route?"}' | head -20
```

3. Set `AI_EMBEDDINGS_LISTINGS_PROVIDER=openai` and `AI_EMBEDDINGS_DOCS_PROVIDER=openai` (key still empty), `ddev restart`, then:

```sh
ddev artisan listings:embed; echo "exit=$?"
ddev artisan docs:index; echo "exit=$?"
ddev artisan nl:eval; echo "exit=$?"
curl -s -i -k 'https://ai-lab.ddev.site/api/listings/semantic?q=red+suv' | head -20
curl -s -i -k 'https://ai-lab.ddev.site/api/docs/search?q=routing' | head -20
```

Expected:
- The three curls answer `503` with JSON `"code":"provider_not_configured"`, before any stream. No request leaves for api.anthropic.com. No new `ai_runs` row.
- The three artisan commands print a message that names the provider and its missing key, and print `exit=1`.
- The two embeddings searches answer `503` with `"code":"provider_not_configured"`.
- Rollback: restore `.env` from the backup and `ddev restart`.

## Step 7

Guide shows key status, never a key value (AC-6, NFR-1).

1. In `.env` set `ANTHROPIC_API_KEY=sk-ant-manual-0001`, `ddev restart`.
2. Open https://ai-lab.ddev.site/ai/guides/providers and view the page source.
3. Run:

```sh
curl -s -k https://ai-lab.ddev.site/ai/guides/providers | grep -c 'sk-ant-manual-0001'
curl -s -k https://ai-lab.ddev.site/ai/describe | grep -c 'sk-ant-manual-0001'
```

Expected:
- The Anthropic row says the key is set; OpenAI and Gemini still say `not set (...)`.
- Both greps print `0`. The string is also absent from the browser's page source (Ctrl+F finds nothing).
- `/ai/describe` now offers `anthropic` in the select.

## Step 8

Per-feature cloud provider, and the run row on a failed call (AC-1, AC-4).

1. Keep the made-up key from step 7. In `.env` add `AI_DESCRIBE_PROVIDER=anthropic` and `AI_DESCRIBE_MODEL=claude-manual-model`. `ddev restart`.
2. Open https://ai-lab.ddev.site/ai/describe, leave the select on `(configured)`, press Start.
3. Run the helper.
4. Open https://ai-lab.ddev.site/ai/docs, search `routing`, and ask in `/ai/chat` (no override).

Expected:
- The describe stream ends with an error event (Anthropic rejects the made-up key with 401). The page does not show the key.
- The newest row is `CarDescriptionAgent | anthropic | claude-manual-model | failed`.
- Chat and docs search still work on their own providers (`DocsAnswerAgent | ollama | qwen3.5:9b`, or the fake when `AI_PROVIDER` is the default). Only describe moved to Anthropic.
- With a real key and no model override you would see `claude-haiku-5-5` and a streamed text instead; optional, a few cents.
- Stopping a run (press Stop on a long `fake-stream` describe) leaves a row with status `stopped` and provider `fake-stream`.

## Step 9

Cloud rate limit (AC-7). Uses the setup of step 8 plus `AI_CLOUD_PER_MINUTE=2` in `.env`; `ddev restart`.

```sh
for i in 1 2 3; do curl -s -o /dev/null -w '%{http_code}\n' -k 'https://ai-lab.ddev.site/listings/1/describe'; done
for i in 1 2 3; do curl -s -o /dev/null -w '%{http_code}\n' -k 'https://ai-lab.ddev.site/listings/1/describe?provider=fake-instant'; done
```

Expected:
- First loop prints a non-429 status twice (the faked 401 shows up as an error, so 200 with an error event or 503), then `429` on the third.
- Second loop (a local provider) prints `200` three times. No `429`.
- The limit is per IP and per minute; wait a minute before repeating.
- Rollback: remove `AI_CLOUD_PER_MINUTE`, `AI_DESCRIBE_PROVIDER`, `AI_DESCRIBE_MODEL`, and the key; `ddev restart`.

## Step 10

Embedding index guard (AC-5).

1. Make sure the index was built with Ollama bge-m3 (default). In `.env` set `AI_EMBEDDINGS_LISTINGS_PROVIDER=openai`, `AI_EMBEDDINGS_DOCS_PROVIDER=openai`, `OPENAI_API_KEY=sk-manual-0002`. `ddev restart`.
2. Run:

```sh
curl -s -i -k 'https://ai-lab.ddev.site/api/listings/semantic?q=red+suv' | head -20
curl -s -i -k 'https://ai-lab.ddev.site/api/docs/search?q=routing' | head -20
curl -s -i -k -X POST https://ai-lab.ddev.site/api/docs/ask -H 'Content-Type: application/json' -H 'Accept: application/json' -d '{"q":"What is a route?"}' | head -20
```

3. Dimension check: in `.env` also set `AI_EMBEDDINGS_DOCS_PROVIDER=gemini`, `GEMINI_API_KEY=x-manual`, `ddev restart`, then `ddev artisan docs:index; echo "exit=$?"` (the Gemini entry is not 1024 dimensions; if your build of the config gives it 1024, use `openai` for `listings:embed` instead).

Expected:
- Step 2: each answers `409` with `"code":"index_not_built"` and a message that names `php artisan listings:embed --fresh` (semantic) or `php artisan docs:index` (docs). Docs ask answers 409 as plain JSON, no stream. Nothing is sent to OpenAI.
- Step 3: the command prints a dimension error naming the provider and exits non-zero, before any embedding call.
- Rollback: restore `.env` from the backup, `ddev restart`. The index is untouched because nothing was re-embedded.

## Step 11

Test suite stays offline and covers the limiter (MNC-4, NFR-2, NFR-3).

```sh
ddev artisan test --compact tests/Feature/CloudProvidersTest.php tests/Feature/CloudRateLimitTest.php tests/Feature/CloudKeysNeverLeakTest.php tests/Feature/EmbeddingsProviderSwitchTest.php tests/Feature/AiLabProviderSelectTest.php
```

Disconnect from the network (Wi-Fi off) and run it again.

Expected:
- All pass both times. A test that tried to reach a provider would fail with a stray-request error rather than hang.

## Step 12

The map test notices a new file under app/Ai (AC-8).

```sh
printf '<?php\n\nnamespace App\\Ai;\n\nfinal class ZzManualProbe {}\n' > app/Ai/ZzManualProbe.php
ddev artisan test --compact tests/Feature/LearningGuideTest.php
rm app/Ai/ZzManualProbe.php
ddev artisan test --compact tests/Feature/LearningGuideTest.php
```

Expected:
- First run fails and names `app/Ai/ZzManualProbe.php` as missing from `docs/learning-guide.md`.
- Second run passes. Also check `grep -n 'AiProviders.php\|ProviderChoice.php' docs/learning-guide.md` prints a line for each file.

## Step 13

Existing contracts and search results unchanged without `provider` (MNC-2, MNC-3). Use the default `.env` (backup restored), `AI_PROVIDER=ollama`.

```sh
curl -s -k -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/listings/semantic?q=red+suv'
curl -s -k -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/docs/search?q=routing'
curl -s -k 'https://ai-lab.ddev.site/api/listings/nl?q=red+bmw' | head -c 400
curl -s -k -N 'https://ai-lab.ddev.site/listings/1/describe' | head -20
ddev artisan tinker --execute 'echo DB::table("listing_documents")->where("embedding_model","!=","ollama/bge-m3")->count(), " ", DB::table("docs_chunks")->where("embedding_model","!=","ollama/bge-m3")->count(), "\n";'
```

Expected:
- 200, 200, a JSON body with the same keys as before (filters, results), and an event stream with `stream_start`, `text_delta` events and a final `[DONE]`.
- The last command prints `0 0`: every indexed row carries `ollama/bge-m3`, so the new model filter drops nothing and search results are the same as before.

## Step 14

Main asset fingerprint and non-lab routes (MNC-5).

```sh
bench/asset-fingerprint.sh
git diff --stat HEAD -- resources/js/app.js resources/css/app.css routes/web.php resources/views
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- The fingerprint lines name `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The `git diff --stat` prints nothing.
- Only the `captured=` line changes in the parity diff.
- Undo the parity capture with `git checkout bench/parity` if you do not want to keep it.

## Step 15

No key in logs or run rows (NFR-1).

1. In `.env` set `ANTHROPIC_API_KEY=sk-ant-canary-7777` and `AI_DESCRIBE_PROVIDER=anthropic`, `ddev restart`.
2. Open `/ai/describe`, press Start (it fails with a 401).
3. Run:

```sh
grep -c 'sk-ant-canary-7777' storage/logs/laravel.log
ddev artisan tinker --execute 'echo DB::table("ai_runs")->where("error_message","like","%canary%")->count(), "\n";'
curl -s -k -N 'https://ai-lab.ddev.site/listings/1/describe' | grep -c canary
```

Expected:
- All three print `0`. Make sure the log file exists and has content from the failed run (`tail storage/logs/laravel.log`); a missing log proves nothing.
- Rollback: restore `.env` from the backup, `ddev restart`.
