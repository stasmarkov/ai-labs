# Manual test plan: local model wiring (SL-22)

Run from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://interview.ddev.site.

## Preconditions

- Ollama is running on the Mac (`curl -s localhost:11434/api/version` prints a version) and `ollama list` shows `qwen3.5:9b`.
- `ddev start`; `ddev server --current` prints `nginx-fpm`.
- `.env` has `AI_PROVIDER=ollama`; `OLLAMA_MODEL`, `OLLAMA_URL`, `OLLAMA_NUM_CTX`, `OLLAMA_KEEP_ALIVE`, `AI_THINK`, `AI_TIMEOUT`, `AI_MAX_TOKENS` are unset or at their documented defaults. Run `ddev artisan config:clear`.

## Step 1: one real stream by hand (AC-1, AC-3)

```sh
ollama stop qwen3.5:9b
curl -N https://interview.ddev.site/listings/1/describe
ollama ps
ddev artisan tinker --execute 'dump(App\Models\AiRun::latest("id")->first()->only(["provider","model","tokens_in","tokens_out","ttft_ms","latency_ms","status"]));'
```

Expected: `data:` lines with `text_delta` events arrive over a few seconds and form an English description of the car; the last line is `data: [DONE]`. `ollama ps` shows `qwen3.5:9b` with context 8192. The row shows provider `ollama`, model `qwen3.5:9b`, non-zero tokens, status `completed`. The first request after the model was unloaded is slower (model load).

## Step 2: the container reaches the host on every server (AC-5, MNC-3)

```sh
mkdir -p /tmp/sl22
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null && echo "== $s"
  ddev exec getent hosts host.docker.internal
  ddev exec curl -s http://host.docker.internal:11434/api/version; echo
  BENCH_PARITY_DIR=/tmp/sl22 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl22/$s.txt
done
ddev server nginx-fpm
```

Expected per server: an address line for `host.docker.internal`, a JSON version, `identical`.

## Step 3: real streams on all six servers (AC-1, AC-6, AC-7)

```sh
curl -s -o /dev/null https://interview.ddev.site/listings/1/describe   # warm the model
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null && echo "== $s" && bench/sse.sh --real; echo "exit=$?"
done
ddev server nginx-fpm
ddev artisan tinker --execute 'dump(App\Models\AiRun::where("provider","ollama")->latest("id")->take(6)->get(["model","tokens_in","tokens_out","ttft_ms","latency_ms","status"])->toArray());'
```

Expected: `exit=0` six times; six `completed` rows with provider `ollama`. On worker servers, `ddev server <name>` restarts the workers, so `.env` is picked up.

## Step 4: back to the fake without code changes (AC-1, MNC-1)

```sh
sed -i '' 's/^AI_PROVIDER=.*/AI_PROVIDER=fake-stream/' .env && ddev artisan config:clear
bench/sse.sh; echo "exit=$?"
sed -i '' 's/^AI_PROVIDER=.*/AI_PROVIDER=ollama/' .env && ddev artisan config:clear
```

Expected: the fake thresholds pass (200 events, last ≥ 9.5 s), `exit=0`; the newest row has provider `fake-stream`.

## Step 5: suite and harness (MNC-2, MNC-4)

```sh
# Quit Ollama from its menu bar icon (Quit Ollama), then:
curl -s --max-time 2 localhost:11434/api/version || echo "ollama stopped"
ddev artisan test --compact   # passes with Ollama stopped
open -a Ollama
bench/run.sh ping --quick; echo "exit=$?"
```

Expected: all tests pass with Ollama not running (the suite needs no network); the quick ping run passes its run conditions. Commit the slice before this step: `bench/run.sh` refuses a dirty tree.
