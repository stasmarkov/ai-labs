# Manual test plan: streamed car description (SL-20)

Run from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://interview.ddev.site.

## Preconditions

- `ddev start`; `ddev server --current` prints `nginx-fpm`.
- `.env` has `AI_PROVIDER=fake-stream` (or no `AI_PROVIDER`, which defaults to it) and no `AI_FAKE_*` overrides.
- `ddev artisan migrate` has run (the `ai_runs` table exists).

## Step 1: one stream by hand (AC-1, AC-5)

```sh
curl -N https://interview.ddev.site/listings/1/describe
ddev artisan tinker --execute 'dump(App\Models\AiRun::latest("id")->first()->only(["provider","model","prompt_version","tokens_in","tokens_out","ttft_ms","latency_ms","status"]));'
```

Also check the headers: `curl -s -D - -o /dev/null --max-time 2 https://interview.ddev.site/listings/1/describe` shows `HTTP/2 200`, `content-type: text/event-stream; charset=utf-8` and `x-accel-buffering: no`.

Expected: lines `data: {...}` appear one by one for about 10 seconds (watch them arrive, not all at once; this is the originally reported problem, a stream that arrived in one lump), the first event is `stream_start`, text deltas (`"type":"text_delta"`) carry one word each and start `2019 Ford Fiesta`, there is a `text_end` and a `stream_end`, the last line is `data: [DONE]`. The row shows provider `fake-stream`, prompt version `car-description@v1`, tokens out 200, ttft_ms under 1000, latency_ms about 10 000, status `completed`.

## Step 2: timings on nginx-fpm (AC-3)

```sh
bench/sse.sh
```

Expected: first text event within 1 000 ms, last at 9 500 ms or later, 200 text events, at least 150 distinct arrival times, exit 0.

## Step 3: every server streams (AC-8)

```sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null && echo "== $s" && bench/sse.sh; echo "exit=$?"
done
ddev server nginx-fpm
```

Expected: `exit=0` for all six with the AC-8 numbers. A buffering server shows the first event at about 10 000 ms (everything arrives at the end) and exit 1.

## Step 4: existing endpoints unchanged (MNC-1)

```sh
mkdir -p /tmp/sl20
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl20 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl20/$s.txt
done
ddev server nginx-fpm
```

Expected: `identical` six times.

## Step 5: suite and harness (MNC-2, AC-4)

```sh
ddev artisan test --compact
bench/run.sh ping --quick; echo "exit=$?"
```

Expected: all tests pass; the quick ping run passes its run conditions and exits 0 (commit or stash first: it refuses a dirty tree). For AC-4 by hand, on each of nginx-fpm, frankenphp-octane and frankenphp-worker:

```sh
printf '\nAI_PROVIDER=fake-instant\nAI_MODEL=fake-slow-2\n' >> .env
for s in nginx-fpm frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null && ddev artisan config:clear >/dev/null   # switching restarts the worker
  echo "== $s"; curl -sN https://interview.ddev.site/listings/1/describe | tail -2
  ddev artisan tinker --execute 'dump(App\Models\AiRun::latest("id")->first()->only(["id","provider","model","tokens_out","status"]));'
done
```

Expected: on all three servers the newest row shows provider `fake-instant`, model `fake-slow-2`, tokens out 20 and status `completed`. Then remove the two `AI_` lines from `.env`, run `ddev server nginx-fpm` and `ddev artisan config:clear`; `ddev server --current` prints `nginx-fpm`.

## Step 6: unknown listing (AC-2)

```sh
ddev artisan tinker --execute 'echo App\Models\AiRun::count();'
curl -si https://interview.ddev.site/listings/999999/describe
curl -si -H 'Accept: text/event-stream' https://interview.ddev.site/listings/999999/describe
ddev artisan tinker --execute 'echo App\Models\AiRun::count();'
```

Expected: both requests answer `HTTP/2 404` with `content-type: application/json` and the body `{"success":false,"status":404,"message":"Not found","errors":{}}`, no `data:` lines. The two counts are equal (no `ai_runs` row written).

## Step 7: provider and model from `.env` (AC-4)

Use the loop at the end of step 5 (it sets `AI_PROVIDER=fake-instant` and `AI_MODEL=fake-slow-2`, runs on nginx-fpm, frankenphp-octane and frankenphp-worker, and clears config). Expected: the newest row on each server shows provider `fake-instant`, model `fake-slow-2`, tokens out 20, status `completed`, and the stream finishes at once instead of after 10 seconds. Afterwards `.env` is restored and `ddev server --current` prints `nginx-fpm`.

## Step 8: the provider fails mid-run (AC-6)

```sh
for n in 0 5; do
  printf '\nAI_FAKE_FAIL_AFTER=%s\n' $n >> .env
  ddev artisan config:clear >/dev/null
  echo "== fail_after=$n"
  curl -siN https://interview.ddev.site/listings/1/describe | sed -n '1p'
  curl -sN https://interview.ddev.site/listings/1/describe | tail -n 2
  ddev artisan tinker --execute 'dump(App\Models\AiRun::latest("id")->first()->only(["status","error_class","error_message","ttft_ms","latency_ms","tokens_in","tokens_out"]));'
  sed -i '' '/^AI_FAKE_FAIL_AFTER=/d' .env
done
ddev artisan config:clear
```

Expected for both runs: `HTTP/2 200` (first line); the last two lines of the body are a `data: {"type":"error",...}` event with a generic message (no stack trace, no class name) and `data: [DONE]`. The newest row has status `failed`, error class `RuntimeException`, a short error message, tokens in and out null, a latency of a few ms (0) or about 250 to 300 ms (5 tokens at 50 ms). `ttft_ms` is null for 0 and a number for 5. The 1 000-character cut is covered by the automated test. Check `.env` has no `AI_FAKE_FAIL_AFTER` left.

## Step 9: new prompt version (AC-7)

```sh
cp resources/prompts/car-description.v1.md resources/prompts/car-description.v2.md
printf '\nAI_PROMPT_CAR_DESCRIPTION=car-description.v2\n' >> .env
ddev artisan config:clear >/dev/null
curl -sN https://interview.ddev.site/listings/1/describe | tail -1
ddev artisan tinker --execute 'echo App\Models\AiRun::latest("id")->first()->prompt_version;'
```

Expected: the stream ends with `data: [DONE]` and tinker prints `car-description@v2`. Rollback: `rm resources/prompts/car-description.v2.md`, delete the `AI_PROMPT_CAR_DESCRIPTION` line from `.env`, run `ddev artisan config:clear`; a new run records `car-description@v1` again.

## Step 10: learning guide (AC-9)

Open `docs/learning-guide.md` and find "## 9. Streaming an AI answer". Expected: it walks through one call from route to client, lists what each of the six servers needed to stop buffering, explains how `ai_runs` is filled, says what the fake provider hides (tokenizer, real latency), and has links to the code, this spec, the `bench/sse.sh` results, the Laravel AI SDK docs and the MDN server-sent events page. Click each relative link; each opens an existing file.

## Rollback

All steps leave the project as found if you finish them. If a step is interrupted: delete any `AI_*` lines you added to `.env`, remove `resources/prompts/car-description.v2.md`, run `ddev artisan config:clear` and `ddev server nginx-fpm`. Test rows in `ai_runs` can stay.
