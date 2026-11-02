# Manual test plan: AI lab UI (SL-46)

Run from the project root, `/Users/stasmarkov/work/interview`. The lab is at https://interview.ddev.site/ai. Use a desktop browser with its developer tools open (Console and Network).

## Preconditions

- `ddev server --current` prints `nginx-fpm`; `ddev npm run build` has run; Ollama is running with `qwen3.5:9b` and `bge-m3`; `.env` has `AI_PROVIDER=ollama`.
- Docs are indexed (SL-32): `ddev artisan docs:search flushpackets --k=1` prints a row.

## Step 1: old pages untouched (MNC-1)

```sh
bench/asset-fingerprint.sh
curl -s https://interview.ddev.site/listings/1 | grep -o 'build/assets/[^"]*'
```

Expected: the `app.css` and `app.js` file names and hashes are the ones recorded before the slice (in the build report); the detail page links only those two files, never the `ai` entry.

## Step 2: streamed description and Stop (AC-2, AC-3)

1. Open `/ai`, check the top bar shows the text model `qwen3.5:9b`, the embedding model `bge-m3` and a green Ollama dot, and the recent runs table has rows.
2. Open `/ai/describe`, pick a listing, press Start. Expected: the text grows word by word; the rail shows events arriving with times, time to first token, tokens and tokens/s; after the end the run row (provider `ollama`, tokens, latency) appears.
3. Start again and press Stop after a second. Expected: the text stops growing at once and the page says "stopped after N tokens"; Start works again immediately.

4. Switch to the fake provider (`AI_PROVIDER=fake-stream` in `.env`, `ddev artisan config:clear`), start a run: 200 words arrive over about 10 s, first token within 1 s; switch back to `ollama`.
5. Count runs (`ddev artisan tinker --execute 'echo App\Models\AiRun::count();'`), start a run and press Stop after a second, count again: the count usually does not change, and the page says the server may not have logged the run.
6. `ddev server frankenphp-octane`, repeat 2 and 3 on `/ai/describe`, then `ddev server nginx-fpm`.

## Step 3: errors (AC-4)

1. Press Start more than 20 times within a minute (or set `AI_DESCRIBE_PER_MINUTE=2`, `ddev artisan config:clear`). Expected: a readable "too many requests" message with seconds to wait. Restore the setting.
2. Quit Ollama (menu bar) and press Start. Expected: the masked "The description could not be generated." message, no stack trace; the top bar shows Ollama unreachable after a reload. Start Ollama again (`open -a Ollama`).

## Step 4: search pages (AC-5, AC-6)

1. `/ai/semantic`, query "family car with a big boot". Expected: 10 listings with body type, price, city, similarity bars; the rail shows embedding ms and database ms. An empty query shows the 422 message.
2. `/ai/docs`, query `flushpackets`, then "why does apache hold back a streamed response until the end". Expected: three columns (vector, text, hybrid), each with timings; chunks found by more than one mode are highlighted; for `flushpackets` the text and hybrid columns start with the learning guide chunk.

## Step 5: responsive and accessible (AC-7, AC-9)

For `/ai`, `/ai/describe`, `/ai/semantic`, `/ai/docs`, `/ai/chat`, `/ai/photos`, at 1440×900 and at 390×844 (device toolbar):
- no horizontal scrollbar; no console errors;
- Tab through every control: focus is always visible;
- chat and photos show what is coming and link to the backlog and guide;
- with a screen reader (VoiceOver: Cmd+F5) on `/ai/describe`, the streamed text is read out politely while the event log is not.

## Step 6: parity, suite and bench (MNC-2, MNC-3)

```sh
mkdir -p /tmp/sl46
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl46 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl46/$s.txt
done
ddev server nginx-fpm
ddev artisan test --compact
ddev npm run typecheck && ddev npm run build
bench/run.sh ping --quick; echo "exit=$?"   # commit first
```

Expected: `identical` six times; all green; `exit=0`.
