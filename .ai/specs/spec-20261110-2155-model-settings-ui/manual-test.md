# Manual test plan: model settings UI (SL-52)

Base URL: https://ai-lab.ddev.site (local DDEV, `APP_ENV=local`).

## Preconditions

- `ddev start`, then `ddev artisan migrate` (creates the `ai_settings` table).
- Ollama runs on the host with `qwen3.5:9b`, `gemma4:12b` and `bge-m3` pulled. `.env` has `AI_PROVIDER=ollama`, `CACHE_STORE=redis` and no `AI_SETTINGS_UI=false` line.
- Listing 1 exists (`/listings/1/describe` works) and the docs and listings indexes are built, so the counts in step 3 are not 0 before you start.
- Start from a clean settings table. Run this first and expect `0`:

```sh
ddev psql -c "select count(*) from ai_settings"
```

Rollback for every step: `ddev psql -c "delete from ai_settings"; ddev redis-cli DEL ai-settings:v1` (or `ddev artisan cache:clear`), then `ddev server default` if you switched servers.

Shortcut to read the newest run: `ddev psql -c "select id,provider,model,status from ai_runs order by id desc limit 1"`.

## Step 1

Save and reset from the page, and the originally reported failure (AC-1, AC-2, AC-3, AC-6 part).

The reported bug: after one save, every page answered 500 ("tried to access a property on an incomplete object ... AiSettingChoice") because the cached settings could not be read back by the next request. This step must now pass.

1. Open `/ai/settings` (viewport 1440px wide). Open the browser console.
2. Find the Describe row. Provider list: pick `ollama`. Model: pick `gemma4:12b`. Click Save.
3. Hard-reload the page (Cmd+Shift+R). Do this twice. Every reload is a new request that reads the saved settings from Redis.
4. Open these in turn: `/`, `/ai`, `/ai/describe`, `/ai/guides/providers`, `/ai/nl-search`.
5. Check the stored row:

```sh
ddev psql -c "select feature,provider,model from ai_settings"
ddev redis-cli KEYS '*ai-settings*'
```

6. Back on `/ai/settings`, click "Reset to configuration" on the Describe row.
7. Reload once more.

Expected:
- Step 1: status 200, a "Settings" link in the navigation, no red console errors. Each feature row (describe, nl-search, docs-answer, rag-grader, photo-vision, embeddings-listings, embeddings-docs) shows a source tag and a "Using <provider> / <model>" line. Source is `default` or `configuration` here, never `request`.
- Step 2: the green status line reads "describe now uses ollama / gemma4:12b." The Describe row shows source `settings` and "Using ollama / gemma4:12b".
- Step 3: both reloads answer 200 and still show `settings` and `gemma4:12b`.
- Step 4: all five pages answer 200, none shows a server error.
- Step 5: one row `describe  ollama  gemma4:12b`. The Redis key listing may show the `ai-settings:v1` key (with the cache prefix).
- Step 6: the status line reads "describe reset to configuration" or similar, and the row returns to `qwen3.5:9b` with source `configuration`. The provider and model selects return to `ollama` and `qwen3.5:9b` too (Task 13: if they keep `gemma4:12b`, note it as a failure).
- Step 7: 200. `ddev psql -c "select count(*) from ai_settings"` gives `0`.

Also check the option lists (AC-2): the Provider list holds only enabled providers (here `fake-stream`, `fake-instant`, `ollama`; a cloud provider appears only if its key is set). The Ollama model list on text rows holds only installed text models (`gemma4:12b`, `qwen3.5:9b`); `bge-m3` appears only on the two embeddings rows. The photo-vision row shows "(provider default)" next to its model.

## Step 2

Worker mode sees the change with no restart (AC-1, NFR-1).

```sh
ddev server frankenphp-worker
```

1. On `/ai/settings` save `ollama` / `gemma4:12b` for Describe.
2. At once, with no restart, run:

```sh
curl -sk -N "https://ai-lab.ddev.site/listings/1/describe" | head -c 600
ddev psql -c "select id,provider,model,status from ai_runs order by id desc limit 1"
```

3. Run the curl a second time (this hits the cached setting), then the `ai_runs` query again.
4. Click "Reset to configuration" for Describe, repeat the curl and the query.
5. Repeat steps 1 to 4 after `ddev server frankenphp-octane`.
6. Finish with `ddev server default`.

Expected:
- The curl streams text (HTTP 200, not `{"success":false,"status":500,...}`).
- The newest `ai_runs` row after the save and after the second curl: `ollama` / `gemma4:12b`.
- After the reset: `ollama` / `qwen3.5:9b`.
- The same results on Octane. `ddev server --current` names the server in use.

## Step 3

Embeddings switch (AC-5).

1. Open `/ai/settings`. Find the `embeddings-docs` row. Note the text "Indexed rows in ...: N of M; N made with the effective model."
2. In the Provider list keep `ollama`; in the Model list choose another installed embedding model (anything other than the configured `bge-m3`). If only one embedding model is installed, run `ollama pull nomic-embed-text` first.
3. Before clicking Save, read the row. Then click Save without ticking the checkbox.
4. Tick "I will re-index after changing the model; search answers 409 until then." and click Save.
5. Read the row again, then run:

```sh
curl -sk -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/docs/search?q=routing&mode=vector'
```

6. Run the command the page shows (`ddev artisan docs:index`), wait for the queue to finish, reload the page and run the curl again (optional). Click "Reset to configuration" to finish.

Expected:
- Step 3: a note reads "Changing the embedding model leaves the stored vectors stale until you re-index." and the checkbox is shown. Saving without the tick shows the red error "Confirm that you will re-index: search answers 409 until you do." and stores nothing.
- Step 4: status line "embeddings-docs now uses ollama / <model>.".
- Step 5: the row now says "0 made with the effective model", a red alert "<N> vectors were made with another model, so search over them is stale." and the code line `ddev artisan docs:index`. The curl prints `409`.
- Step 6: after the re-index the alert is gone and the curl prints `200`. After the reset the checkbox and the alert do not appear on their own (Task 13, no change made by you means no warning).
- Repeat once on `embeddings-listings`: the command shown is `ddev artisan listings:embed --fresh --wait`.

## Step 4

Speed and exposure (NFR-2, NFR-3, AC-6, MNC-4).

```sh
for i in $(seq 5); do curl -sk -o /dev/null https://ai-lab.ddev.site/ai/settings; done
for i in $(seq 20); do curl -sk -o /dev/null -w '%{time_total}\n' https://ai-lab.ddev.site/ai/settings; done | sort -n | sed -n 19p
```

Run it twice: once with no saved setting, once after saving Describe `ollama` / `gemma4:12b` (reset afterwards).

Then flag off: add `AI_SETTINGS_UI=false` to `.env`, run `ddev restart`, and run:

```sh
curl -sk -o /dev/null -w 'GET %{http_code}\n' https://ai-lab.ddev.site/ai/settings
curl -sk -o /dev/null -w 'DELETE %{http_code}\n' -X DELETE https://ai-lab.ddev.site/ai/settings/describe
```

With a Describe setting stored (insert one first: `ddev psql -c "insert into ai_settings (feature,provider,model,created_at,updated_at) values ('describe','ollama','gemma4:12b',now(),now())"`), run the describe curl from step 2 and read the newest `ai_runs` row. Open `/ai` and check the navigation.

Expected:
- Both timings print a value under 0.200 (seconds), with Ollama reachable.
- With the flag off: GET prints `404`, DELETE prints `404` (a CSRF 419 would also prove no write reaches the controller, but 404 is what you should see because the route gate runs first).
- The describe run uses the configured `qwen3.5:9b`, not the stored `gemma4:12b` (stored settings are ignored).
- The "Settings" link is gone from the navigation.
- The page shows no API key values at any time (only provider names).
- Rollback: delete the `AI_SETTINGS_UI=false` line, `ddev restart`, then `ddev psql -c "delete from ai_settings"`.
- Non-local check (optional): with `APP_ENV=production` in `.env` and `ddev restart`, `/ai/settings` answers 404. Restore `APP_ENV=local` after.

## Step 5

Checks (MNC-6).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Also confirm non-lab routes still answer after a saved setting: save Describe, then `curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/` and `https://ai-lab.ddev.site/up`.

Expected:
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`, unchanged.
- Only the `captured=` line changes in the parity diff (revert it with `git checkout bench/parity`).
- Both curls print `200`.

## Step 6

Rejected saves store nothing (AC-4).

On `/ai/settings` open the browser console and paste:

```js
const t = decodeURIComponent(document.cookie.match(/XSRF-TOKEN=([^;]+)/)[1]);
const put = (feature, body) => fetch('/ai/settings/' + feature, {method: 'PUT', headers: {'Content-Type': 'application/json', 'Accept': 'application/json', 'X-XSRF-TOKEN': t}, body: JSON.stringify(body)}).then(async r => r.status + ' ' + (await r.text()).slice(0, 300));
await put('describe', {provider: 'ollama', model: 'no-such-model:1b'});
await put('describe', {provider: 'not-a-provider', model: 'x'});
await put('describe', {provider: 'ollama', model: 'bge-m3'});
await put('bogus-feature', {provider: 'ollama', model: 'qwen3.5:9b'});
```

Expected:
- First three: `422` with a JSON `errors` object naming `model` or `provider`.
- Fourth: `404`.
- `ddev psql -c "select count(*) from ai_settings"` still prints `0`.

## Step 7

The lab selector wins over a saved setting (AC-7, MNC-2).

1. Save Describe `ollama` / `gemma4:12b` on the settings page.
2. Run `curl -sk -N "https://ai-lab.ddev.site/listings/1/describe?provider=fake-instant" | head -c 300` and read the newest `ai_runs` row.
3. Run the same curl without `?provider=` and read the newest row.
4. Open `/ai/describe`, pick the `fake-instant` provider in the selector and generate; then open `/ai/settings`.
5. Reset Describe.

Expected:
- Step 2: the run shows `fake-instant`. Step 3: `ollama` / `gemma4:12b`.
- Step 4: the page generates with `fake-instant`; `/ai/settings` still shows Describe as source `settings` (the override applied to that request only, nothing was saved, and the page never shows source `request`).

## Step 8

Providers guide (AC-8).

1. Open `/ai/guides/providers` with no saved setting, then again after saving Describe `ollama` / `gemma4:12b`.

Expected:
- Status 200 both times. The page lists every feature with its effective provider, model and source (after the save: describe `ollama`, `gemma4:12b`, `settings`).
- A section "Switch a model from the Settings page" with the recipe: open `/ai/settings`, pick provider and model, Save, Reset to configuration.

Reset Describe afterwards.

## Step 9

Endpoint contracts and no-settings behaviour (MNC-1, MNC-3).

With an empty `ai_settings` table, run:

```sh
curl -sk -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/ping'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/listings/1/describe'
curl -sk -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/docs/search?q=routing&mode=text'
curl -sk 'https://ai-lab.ddev.site/api/docs/search?q=routing&mode=vector' | head -c 300
ddev artisan test --compact
```

Expected:
- `200` for ping, describe and the text docs search.
- The vector search answers `200` with data and the same JSON envelope as before this feature.
- The describe run row shows `ollama` / `qwen3.5:9b` (configuration, then default, exactly as before).
- The suite passes with no failures and no test file from SL-51 changed (`git status` shows none under the SL-51 tests).
