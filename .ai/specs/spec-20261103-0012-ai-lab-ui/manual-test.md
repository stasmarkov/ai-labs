# Manual test plan: AI lab UI (SL-46)

The lab lives at https://ai-lab.ddev.site/ai. Run shell commands from `/Users/stasmarkov/work/interview`. Use a desktop browser with developer tools open (Console and Network tabs).

## Preconditions

- DDEV is up: `ddev start`, then `ddev server nginx-fpm` (this plan starts on nginx-fpm on purpose); `ddev server --current` prints `nginx-fpm`.
- Assets are built: `ddev npm run build`, and `public/hot` does not exist.
- Ollama runs on the Mac with the two models: `ollama list` shows `qwen3.5:9b` and `bge-m3`.
- `.env` has `AI_PROVIDER=ollama`.
- Docs are indexed (SL-32): `ddev artisan docs:search flushpackets --k=1` prints a row.
- Listings exist: `ddev artisan tinker --execute 'echo App\Models\Listing::count();'` prints a number above 0.
- No login is needed.

Rollback for anything that edits `.env`: put the old value back, then `ddev artisan config:clear`.

## Step 1

Old pages untouched (MNC-1).

```sh
bench/asset-fingerprint.sh
curl -s https://ai-lab.ddev.site/listings/1 | grep -o 'build/assets/[^"]*'
```

Expected: the fingerprint lines equal the ones recorded in `BENCHMARKS.md` (`app-CjRfEL-0.css` and `app-BvRk9kiK.js`, same hashes). The listing page links only those two files and never an `ai-` file.

## Step 2

Overview and top bar on every page (AC-1, and the earlier top bar bug).

1. Open https://ai-lab.ddev.site/ai.
2. Expected: the top bar reads "AI lab", text `qwen3.5:9b`, embeddings `bge-m3`, and a green dot with "Ollama reachable". Five capability cards: Describe, Semantic and Docs are ready; Chat shows "coming" with SL-45 and Photos shows "coming" with SL-36. A table lists up to 20 newest runs with agent, provider, model, tokens in and out, TTFT, latency, status and time.
3. Click the Chat card. Expected: the browser goes to `/ai/chat`.
4. Visit `/ai/describe`, `/ai/semantic`, `/ai/docs`, `/ai/chat`, `/ai/photos` (use the top navigation). Expected: on every page the top bar still shows both model tags and the Ollama status, never "Ollama not used".
5. Open https://ai-lab.ddev.site/ai/runs/doesnotexist. Expected: 404.

## Step 3

Streamed description (AC-2).

1. Open `/ai/describe`, choose listing 1, press Start.
2. Expected: the text grows as it arrives. The event log fills with rows (`stream_start`, `text_delta`, ..., `text_end`, `stream_end`) each with a time in ms. The page shows time to first token (a few hundred ms), tokens received, and tokens per second.
3. After the stream ends, a "Logged run" block shows provider `ollama`, model `qwen3.5:9b`, token counts, TTFT, latency, status `completed`.
4. Optional, for a steady stream: set `AI_PROVIDER=fake-stream`, `ddev artisan config:clear`, Start again. Expected: about 200 words over about 10 s, first token under 1 s. Switch back to `ollama` afterwards.

## Step 4

Stop (AC-3).

1. On `/ai/describe` press Start, wait about a second, press Stop.
2. Expected: the text stops growing at once (wait 2 s and compare), the page says "Stopped after N tokens" (N is the real count), and Start is enabled.
3. Press Start immediately. Expected: a new stream begins within a second.
4. Run count check. Note `ddev artisan tinker --execute 'echo App\Models\AiRun::count();'`, Start, Stop after 1 s, count again. Expected on nginx-fpm: unchanged. On a worker server (`ddev server frankenphp-octane`) the server may keep generating and the count rises by one. Repeat 1 to 3 there, then `ddev server nginx-fpm`.

## Step 5

Error messages (AC-4).

1. Rate limit. Run `AI_DESCRIBE_PER_MINUTE=2` in `.env`, `ddev artisan config:clear`, then:

   ```sh
   for i in 1 2 3; do curl -s -o /dev/null -w "%{http_code}\n" https://ai-lab.ddev.site/listings/1/describe; done
   curl -si https://ai-lab.ddev.site/listings/1/describe | head -20
   ```

   Expected: the last responses are 429 with `retry-after: <seconds>` and body `{"success":false,"status":429,"message":"Too Many Attempts.","errors":{}}`. While limited, press Start on `/ai/describe`: the page shows "Too many requests, try again in N s." (N close to the `retry-after`) and state Failed. Restore the setting.
2. Masked error. Set `OLLAMA_URL=http://host.docker.internal:11999` in `.env`, `ddev artisan config:clear`, press Start. Expected: "The description could not be generated.", no stack trace. Reload `/ai`: the top bar shows Ollama unreachable. Restore `.env`.
3. Connection lost. In the browser console on `/ai/describe` run `window.fetch = () => Promise.reject(new TypeError('x'))`, then press Start. Expected: "Connection lost." in the page. Reload to undo.

## Step 6

Semantic search (AC-5).

1. Open `/ai/semantic`, type `cheap family car with low mileage`, press Search.
2. Expected: 10 listings, each with year, make, model, body type, price, city, a bar and a value like `0.53`. The side rail shows Embedding ms and Database ms, matching `server-timing`:

   ```sh
   curl -si 'https://ai-lab.ddev.site/api/listings/semantic?q=family+car' | grep -i server-timing
   ```

   Expected header: `server-timing: embed;dur=..., db;dur=...`.
3. Paste a query of 201 or more characters (for example 250 letters `a`). Expected: "The query is limited to 200 characters."
4. 503: set `OLLAMA_URL=http://host.docker.internal:11999`, `ddev artisan config:clear`, search again. Expected: "Semantic search is unavailable right now." Restore `.env`.

## Step 7

Docs search, three modes (AC-6).

1. Open `/ai/docs`, type `how does rate limiting work`, Search. In the Network tab expect three requests to `/api/docs/search` (modes `vector`, `text`, `hybrid`) starting together.
2. Expected: three columns side by side, each with items showing source, path, heading, snippet, score and ranks like "vector #1 · text #-". Chunks present in more than one list carry an "In several lists" mark. Each column has its own embed and db timings (text mode has no embed time).
3. Search `flushpackets`. Expected: text and hybrid columns start with a repo chunk (BENCHMARKS.md or the learning guide).
4. With the unreachable Ollama URL from step 6.4, search again. Expected: vector and hybrid show "Docs search is unavailable."; the text column still lists results. Restore `.env`.

## Step 8

Placeholders (AC-7).

1. Open `/ai/chat`. Expected: names SL-45 and SL-41 and streamed answers with citations; points to `backlog.md` (the slice entries) and `docs/learning-guide.md`, shown as monospace paths.
2. Open `/ai/photos`. Expected: names SL-36, similar photos, and that it is blocked by Q-06; same pointers.

Known gap: the backlog pointer has no `#sl-45` or `#sl-36` anchor.

## Step 9

Learning guide (AC-8).

1. Open `docs/learning-guide.md` and find `## 13. The AI lab UI: Inertia, Vue and streams in the browser`.
2. Expected: it explains a first load versus later Inertia visits and props as JSON; why the lab has its own Vite entry; reading SSE with `fetch` and a `ReadableStream`; Stop with `AbortController` and what the server sees; and `Server-Timing`. Links open: the spec folder, the controllers, `ServerTiming.php`, `useEventStream.ts`, inertiajs.com, vuejs.org, and the three MDN pages.

## Step 10

Responsive and accessible (AC-9).

For `/ai`, `/ai/describe`, `/ai/semantic`, `/ai/docs`, `/ai/chat`, `/ai/photos`, once at 1440x900 and once at 390x844 (device toolbar), also after running a search or a stream:

- No horizontal scrollbar. In the console: `document.documentElement.scrollWidth === document.documentElement.clientWidth` prints `true`.
- Console has no errors (the browser's own "Failed to load resource: 429" line is fine in step 5).
- Press Tab through the page: every control shows a visible outline.
- Only the description output has `aria-live`: `[...document.querySelectorAll('[aria-live]')].map(e => e.getAttribute('aria-live'))` on a finished `/ai/describe` prints `["polite"]`, and the event log is not in the list.
- Optional: VoiceOver (Cmd+F5) on `/ai/describe` reads the streamed text and not the event log.

## Step 11

Existing endpoints unchanged (MNC-2).

```sh
curl -si 'https://ai-lab.ddev.site/api/listings/semantic?q=family+car' | head -20
curl -si -X POST -H 'Accept: application/json' https://ai-lab.ddev.site/api/listings/semantic | head -12
```

Expected: the semantic and docs search responses have the same JSON body as before plus a `server-timing` header. Other endpoints (for example `/api/listings`) have no new headers.

Known difference to decide on: the uncommitted `app/Http/Responses/JsonErrorRenderer.php` now copies exception headers onto JSON errors, so the POST above answers 405 with `allow: GET, HEAD`, and 429 answers now carry `retry-after` and `x-ratelimit-*`. This is not in the spec; record whether you accept it.

## Step 12

Suite, parity and bench (MNC-3).

```sh
mkdir -p /tmp/sl46
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl46 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl46/$s.txt
done
ddev server default
ddev artisan test --compact
ddev npm run typecheck && ddev npm run build
git status --short
bench/run.sh ping --quick; echo "exit=$?"
```

Expected: `identical` six times; the suite is green (518 tests at last count) with no network calls; typecheck and build are clean. `bench/run.sh` refuses a dirty tree, so commit the work first (`git status --short` must print nothing); then `exit=0`. Finish on nginx-fpm.
