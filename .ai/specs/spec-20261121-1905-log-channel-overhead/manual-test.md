# Manual test plan: log channel overhead (SL-60)

Base URL: https://ai-lab.ddev.site

The reported problem: with the `frankenphp` log channel on, one logged entry per request cost +8 % p95 (8.96 to 9.67 ms on 2026-11-17), above the 5 % target. This slice makes the PHP side cheaper without changing any entry, then measures again.

## Preconditions

- `ddev start` is running and `ddev setup` was done once.
- Work on branch `feature/log-channel-overhead` (or master after merge), with the code committed. For step 2 the tree must be clean: `git status --short` shows nothing outside `.ai/session`.
- `jq` and `shasum` are installed on the host.
- Steps 4 to 6 need the debug route: set `DEBUG_ROUTES=true` in `.env`, then `ddev restart`. Set it back to `false` at the end (see rollback).
- Step 2 switches the server to `frankenphp-worker` and edits `.env` (`LOG_STACK`) with a backup; it restores both on exit, even on Ctrl-C. It takes about 10 minutes (two variants, each 30 s warm-up plus 3 x 60 s).
- Rollback: revert the feature commit; delete the new `bench/results/restart/<date>/` folder if the run is discarded; set `DEBUG_ROUTES=false` in `.env` and `ddev restart`; if a bench run was killed hard, run `ddev server frankenphp` and check `.env` has `LOG_STACK=single`.

## Step 1

Profile record (AC-1).

1. Open `.ai/specs/spec-20261121-1905-log-channel-overhead/decisions.md`, section "Task 8".

Expected: one table with before and after cost per entry for processor, normalize, redact, attribute flattening, `frankenphp_log` (measured in worker mode) and end to end, plus N, mean in microseconds, PHP version (8.4.27) and server mode, and the method used.

## Step 2

Overhead run (AC-2, NFR-1, NFR-2, MNC-3). This is the originally reported miss: +8 % on 2026-11-17. This is task 3.

```sh
git status --short
bench/log-overhead.sh
```

Then, with the path the script printed after "Wrote":

```sh
jq '{off: .channel_off.p95_ms, on: .channel_on.p95_ms, difference_percent, within_threshold}' bench/results/restart/<run date>/log-overhead.json
jq '[.channel_off.runs[].p95_ms] | {min: min, max: max}' bench/results/restart/2026-11-17/log-overhead.json
jq '[.channel_off.runs[].p95_ms]' bench/results/restart/<run date>/log-overhead.json
git status --short
```

Expected:
- The script prints `p95 off ... ms, on ... ms, difference ... %` and `Wrote .../log-overhead.json`. The JSON has the same keys as the 2026-11-17 file (the method and shape are unchanged).
- `within_threshold` is `true` (`difference_percent` below 5). If it is `false`, that is the recorded-miss path: `decisions.md` gets a "Task 3: bench verdict" section and BENCHMARKS.md states the miss with the estimated share taken by `frankenphp_log()` (about 110 us per entry), the threshold stays 5 % and the channel stays opt-in. The profile suggests a miss is likely, since `frankenphp_log()` itself cannot be made cheaper from PHP.
- The new channel-off p95 values lie within 8.55 to 9.28 ms (the three 2026-11-17 repeats), or the difference is explained in `decisions.md`.
- The last `git status --short` shows only the new result folder (the script restored `.env`, the server and `storage/logs/laravel.log`). The 2026-11-17 file is still there and unchanged.
- Commit the new result file. An extra run keeps its own name, for example `log-overhead-run2.json`.

## Step 3

Docs, dependencies and the asset fingerprint (AC-4, MNC-4). The docs part needs task 4 done.

1. Open https://ai-lab.ddev.site/ai/guides/logs-restart#numbers and https://ai-lab.ddev.site/ai/learn/running-the-server/logs-and-a-safe-worker-restart (the overhead step).
2. Read the channel overhead part of `BENCHMARKS.md`.
3. Run:

```sh
ddev npm run build
bench/asset-fingerprint.sh
git diff --stat master -- composer.json composer.lock package.json package-lock.json database routes
ls bench/results/restart/
```

Expected:
- The guide and the lesson show the difference from the new file (same number as `difference_percent`), and say "met" or "missed" matching the result.
- BENCHMARKS.md has one table with rows 2026-11-17 and the new run (off p95, on p95, difference %, RPS off/on).
- Fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- The `git diff --stat` prints nothing (no new dependencies, tables or routes).
- `ls` lists both `2026-11-17` and the new date.

## Step 4

Happy path: the route answers and the entry is written (MNC-2). Needs `DEBUG_ROUTES=true`.

```sh
curl -si https://ai-lab.ddev.site/debug/log-ping -H 'X-Request-Id: manual-test-0001'
```

Expected: `HTTP/2 200`, header `x-request-id: manual-test-0001`, body with `"ping":"pong"` and message `pong`.

Then confirm the plain channel (default `LOG_STACK=single`) logged it:

```sh
ddev exec tail -n 3 storage/logs/laravel.log
```

Expected: a line ending with `ping` for the same request. Without `LOG_STACK=single,frankenphp` the frankenphp channel writes nothing (opt-in).

## Step 5

Entry shape with the channel on (MNC-1, MNC-2).

1. In `.env` set `LOG_STACK=single,frankenphp`, then `ddev restart`.
2. Run:

```sh
curl -s https://ai-lab.ddev.site/debug/log-ping -H 'X-Request-Id: manual-test-0002' > /dev/null
ddev logs -s web 2>&1 | grep manual-test-0002 | tail -n 1
```

Expected: one JSON entry with message `ping`, level `INFO`, and `request.request_id` equal to `manual-test-0002` (as nested `request` data, plus method `GET`, path `debug/log-ping` or similar, route `debug.log-ping`, `server_mode`, `worker`). Compared to the same request on `master` (`git stash` or check out master, restart, repeat), the fields, their order and values are the same apart from time and elapsed values. The automated characterization test pins this; this step is the eyeball check on the real server.

3. Put `LOG_STACK=single` back and `ddev restart` when done.

## Step 6

Redaction still works (MNC-1). Run the automated pin first, then the eyeball check:

```sh
ddev artisan test --compact tests/Feature/FrankenphpLogEntryCharacterizationTest.php tests/Feature/FrankenphpLogDefaultWriterTest.php tests/Feature/StructuredLogTest.php tests/Feature/BenchLogOverheadTest.php
ddev artisan tinker --execute 'Log::channel("frankenphp")->info("redact check", ["password" => "hunter2", "apiKey" => "k-123", "access-token" => "t-456", "tokens_in" => 12, "plain" => "visible"]);'
ddev exec tail -n 1 storage/logs/laravel.log
```

Expected: tests pass (about 38 tests, 282 assertions). The tinker run writes a JSON line (on stderr in the CLI) where `password`, `apiKey`, `access-token` and `tokens_in` show `[redacted]` and `plain` shows `visible`. Only keys that were redacted before are redacted now.

## Tick after the manual steps pass

Tick tasks 3 and 5 (their `- [ ] N.` lines in `.ai/specs/spec-20261121-1905-log-channel-overhead/tasks.md`).
