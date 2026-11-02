# Manual test plan: local model wiring (SL-22)

Run from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://interview.ddev.site.

## Preconditions

- Ollama is running on the Mac (`curl -s localhost:11434/api/version` prints a version) and `ollama list` shows `qwen3.5:9b`.
- `ddev start`; `ddev server --current` prints `nginx-fpm`.
- `.env` has `AI_PROVIDER=ollama`; `OLLAMA_MODEL`, `OLLAMA_URL`, `OLLAMA_NUM_CTX`, `OLLAMA_KEEP_ALIVE`, `AI_THINK`, `AI_TIMEOUT`, `AI_MAX_TOKENS` are unset or at their documented defaults. Run `ddev artisan config:clear`.

<a id="step-1"></a>
## Step 1: one real stream by hand, the reported failure (AC-1, AC-3)

Before this change the describe request on a real model failed because the web container could not reach Ollama on the Mac (`host.docker.internal` did not resolve, so the stream ended with the masked error). It must now stream real text.

```sh
ollama stop qwen3.5:9b
curl -N https://interview.ddev.site/listings/1/describe
ollama ps
ddev artisan tinker --execute 'dump(App\Models\AiRun::latest("id")->first()->only(["provider","model","tokens_in","tokens_out","ttft_ms","latency_ms","status"]));'
```

Expected: `data:` lines with `text_delta` events arrive over a few seconds and form an English description of the car; the last line is `data: [DONE]`. `ollama ps` shows `qwen3.5:9b` with context 8192. The row shows provider `ollama`, model `qwen3.5:9b`, non-zero tokens, status `completed`. The first request after the model was unloaded is slower (model load).

<a id="step-2"></a>
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

<a id="step-3"></a>
## Step 3: real streams on all six servers (AC-1, AC-6, AC-7)

```sh
curl -s -o /dev/null https://interview.ddev.site/listings/1/describe   # warm the model
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null && echo "== $s" && bench/sse.sh --real; echo "exit=$?"
done
ddev server nginx-fpm
ddev artisan tinker --execute 'dump(App\Models\AiRun::where("provider","ollama")->latest("id")->take(6)->get(["model","tokens_in","tokens_out","ttft_ms","latency_ms","status"])->toArray());'
```

Expected: `exit=0` six times; each run prints `status=200`, `first_text_ms` of 5000 or less, `text_events` of 20 or more and a span (`last_text_ms` minus `first_text_ms`) of 1000 or more. `distinct_ms` must be at least 40 % of `text_events` (about 55 % is normal: Ollama itself delivers several tokens per millisecond). Six newest rows are `completed` with provider `ollama`, non-zero `tokens_in` and `tokens_out`, and a non-null `ttft_ms` (check the two worker servers in particular). On worker servers, `ddev server <name>` restarts the workers, so `.env` is picked up.

<a id="step-4"></a>
## Step 4: back to the fake without code changes (AC-1, MNC-1)

```sh
sed -i '' 's/^AI_PROVIDER=.*/AI_PROVIDER=fake-stream/' .env && ddev artisan config:clear
bench/sse.sh; echo "exit=$?"
sed -i '' 's/^AI_PROVIDER=.*/AI_PROVIDER=ollama/' .env && ddev artisan config:clear
```

Expected: the fake thresholds pass (200 events, last ≥ 9.5 s), `exit=0`; the newest row has provider `fake-stream`.

<a id="step-5"></a>
## Step 5: suite and harness (MNC-2, MNC-4)

```sh
# Quit Ollama from its menu bar icon (Quit Ollama), then:
curl -s --max-time 2 localhost:11434/api/version || echo "ollama stopped"
ddev artisan test --compact   # passes with Ollama stopped
open -a Ollama
bench/run.sh ping --quick; echo "exit=$?"
```

Expected: all tests pass with Ollama not running (the suite needs no network); the quick ping run passes its run conditions. Commit the slice before this step: `bench/run.sh` refuses a dirty tree.

<a id="step-6"></a>
## Step 6: Ollama down and unknown listing (AC-4)

```sh
# Quit Ollama from its menu bar icon, then:
curl -sN https://interview.ddev.site/listings/1/describe
ddev artisan tinker --execute 'dump(App\Models\AiRun::latest("id")->first()->only(["provider","status","error_class"]));'
curl -s -i https://interview.ddev.site/listings/999999/describe
open -a Ollama
```

Expected: the stream contains `data: {"type":"error","message":"The description could not be generated."}` followed by `data: [DONE]`; no host name or connection detail appears in the output. The newest row has provider `ollama`, status `failed` and an error class naming the exception. The unknown listing answers `HTTP/2 404` with `content-type: application/json` and a body starting `{"success":false,"status":404`, immediately, with no model call.

<a id="step-7"></a>
## Step 7: request options and the guide (AC-2, AC-8)

```sh
ddev artisan config:show ai.providers.ollama
grep -n "^## 10. Running the model locally" docs/learning-guide.md
```

Expected: `config:show` lists model `qwen3.5:9b`, `max_tokens` 400, `timeout` 120 and `request_options` with `think` false, `keep_alive` `10m`, `num_ctx` 8192. Then in `.env` set `OLLAMA_NUM_CTX=4096`, run `ddev artisan config:clear`, repeat `config:show`: `num_ctx` is 4096 (the override works); remove the line and clear the config again. The grep finds the section; open `docs/learning-guide.md` at that line and check the six-row table (tokens in, tokens out, ttft, latency, tokens/s) and that the links to ADR 0001, `app/Ai/`, the spec, the Ollama docs and the Laravel AI SDK docs open.

## Rollback

`.env` must end with `AI_PROVIDER=ollama` and the project on nginx-fpm (`ddev server --current`). To go back to the fake at any point: `sed -i '' 's/^AI_PROVIDER=.*/AI_PROVIDER=fake-stream/' .env && ddev artisan config:clear`. Test rows added to `ai_runs` are harmless and can stay.
