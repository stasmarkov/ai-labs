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

Expected: lines `data: {...}` appear one by one for about 10 seconds (watch them arrive, not all at once), text deltas carry one word each, the last line is `data: [DONE]`. The row shows provider `fake-stream`, prompt version `car-description@v1`, tokens out 200, ttft_ms under 1000, latency_ms about 10 000, status `completed`.

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

Expected: all tests pass; the quick ping run passes its run conditions and exits 0 (commit or stash first: it refuses a dirty tree). For AC-4 by hand (on nginx-fpm): set `AI_MODEL=fake-slow-2` in `.env`, run `ddev artisan config:clear`, repeat step 1, and check the new row's model is `fake-slow-2`; then remove the line again.
