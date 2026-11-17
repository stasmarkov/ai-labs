# Manual test plan: structured logs and worker restart (SL-16)

Run everything from the project root, `/Users/stasmarkov/work/interview`. The app is at https://ai-lab.ddev.site.

## Before you start

- DDEV is running (`ddev start`) and `ddev describe` shows the project as OK.
- The working tree is clean enough for the benchmark scripts (step 3 refuses to run on a dirty tree unless you use `--quick`).
- Remember which server you start on: `ddev server --current`. The default is `frankenphp`; each step below tells you when to switch back.
- `.env` is never edited by hand. Step 1 switches it with the shared bench helper, which keeps a backup in `.env.bench-backup` (gitignored) and refuses to start over a stale one.

Rollback for every step that switches `.env` or the server:

```sh
APPROOT=$PWD bash -c 'source bench/lib/env-switch.sh; ENV_SWITCHED=1; env_switch_restore'
ddev server frankenphp
ddev restart
```

## Step 1

Live log entry in worker mode (task 12; AC-1, AC-3, AC-4, AC-5). The reported problem was that logs in a FrankenPHP worker had no request id and no structure.

1. Switch to worker mode and open the log stream in terminal A:

   ```sh
   ddev server frankenphp-worker
   ddev logs -f -s web
   ```

2. In terminal B, turn the channel on with the bench helper. `/debug/log-ping` (a debug-only copy of `/api/ping` that writes one info entry) answers only while `DEBUG_ROUTES` is true:

   ```sh
   APPROOT=$PWD bash -c 'source bench/lib/env-switch.sh; env_switch_begin; env_set LOG_STACK single,frankenphp; env_set DEBUG_ROUTES true; env_apply'
   ddev restart
   ```

3. Send two requests with no id and read the headers:

   ```sh
   curl -sk -D - -o /dev/null https://ai-lab.ddev.site/debug/log-ping | grep -i x-request-id
   curl -sk -D - -o /dev/null https://ai-lab.ddev.site/debug/log-ping | grep -i x-request-id
   ```

4. Send a request with your own id, and check the function the handler calls inside a request:

   ```sh
   curl -sk -D - -o /dev/null -H 'X-Request-Id: abcdefgh-1234' https://ai-lab.ddev.site/debug/log-ping | grep -i x-request-id
   curl -sk https://ai-lab.ddev.site/debug/runtime | jq .data.frankenphp_log
   ```

Expected:
- Terminal A shows one JSON entry per request with the message `ping`, level `INFO`, and a `request` object holding `request_id`, `method` `GET`, `path` `/debug/log-ping`, `route` `debug.log-ping`, `server_mode` `frankenphp-worker`, `worker` `true`, `elapsed_ms` and `context` `http`.
- `/debug/runtime` reports `frankenphp_log` as `frankenphp_log(string $message, int $level = 0, array $context = []): void` (the C-2 signature, read with `ReflectionFunction` inside the request).
- Each response header `X-Request-Id` equals the `request_id` in the matching log entry (AC-1, AC-3).
- The two ids from the first two requests are different ULIDs, and the log shows no field from request 1 inside request 2's entry (AC-4).
- The third request returns `x-request-id: abcdefgh-1234`, and the log entry for it carries the same id.

Rollback: `APPROOT=$PWD bash -c 'source bench/lib/env-switch.sh; ENV_SWITCHED=1; env_switch_restore' && ddev restart`

## Step 2

Restart command in each server mode (task 12; AC-6).

1. Worker mode (still on `frankenphp-worker` after step 1, with `.env` restored):

   ```sh
   ddev artisan frankenphp:workers:restart; echo "exit=$?"
   curl -sk https://ai-lab.ddev.site/api/ping
   ```

2. Octane mode:

   ```sh
   ddev server frankenphp-octane && ddev artisan frankenphp:workers:restart; echo "exit=$?"
   ```

3. Classic mode:

   ```sh
   ddev server frankenphp && ddev artisan frankenphp:workers:restart; echo "exit=$?"
   ```

4. Admin API failure. The address is fixed in `config/ai.php`, so point it at a closed port for one call only:

   ```sh
   ddev artisan tinker --execute 'config(["ai.server.admin_url" => "http://localhost:9"]); $code = Artisan::call("frankenphp:workers:restart"); echo Artisan::output(), "exit=", $code, PHP_EOL;'
   ```

5. Outside the container (the host PHP, not ddev):

   ```sh
   php artisan frankenphp:workers:restart; echo "exit=$?"
   ```

Expected:
- Worker: a line like `workers restarted in 120 ms`, `exit=0`, and the ping still answers `{"success":true,"status":200,"message":"pong","data":{"ping":"pong"}}`.
- Octane: the normal `octane:reload` output, `exit=0`.
- Classic: `classic mode has no workers to restart`, `exit=0`.
- Failure: `admin API: ...` with the connection error, then `exit=1`.
- Host run: the message "Run this command inside the web container (ddev artisan frankenphp:workers:restart)..." with `exit=1` (or, when the host PHP cannot boot the app, a failure from the missing database host). It sends nothing to `localhost:2019` on the host and never claims a restart happened.

Rollback: `ddev server default`

## Step 3

Restart under load (AC-7). This one takes a long time, so start it before lunch.

1. A short development run first, on one mode:

   ```sh
   bench/restart-load.sh --quick --modes frankenphp-worker
   ls bench/results/raw/
   ```

2. The full run (30 s warm-up, 3 repeats of 60 s, 3 restarts per repeat at 15, 30 and 45 s, both modes, both routes). The git tree must be clean:

   ```sh
   bench/restart-load.sh
   ls bench/results/restart/*/
   jq '{server, route, median_p99_ms, failures, probe_requests, load_non_2xx}' bench/results/restart/*/frankenphp-worker-*.json
   ```

3. Open `BENCHMARKS.md` and find the section "Worker restart under load".

Expected:
- The quick run writes a JSON with `restarts[0].window_from_s` near 15, `runs[0].inside.requests` above 0 and `runs[0].probe.inside.requests` above 0.
- The full run writes four files in `bench/results/restart/<date>/`: `frankenphp-worker` and `frankenphp-octane`, each for `/api/ping` and `/listings?make=BMW`. Each has per-repeat probe failures (with connection errors and timeouts) and load p99 inside and outside the restart windows, plus `restarts[].duration_ms`.
- `BENCHMARKS.md` "Worker restart under load" has a table whose numbers match those files, plus "What the numbers say", "Surprises" and the story.
- The guide at https://ai-lab.ddev.site/ai/guides/logs-restart shows the same numbers on its numbers tab.
- `.env` and the server are back as they were: `git diff --stat .env` shows nothing and `ddev server --current` prints your starting server.

Rollback: the script restores `.env` and the server on exit. If you interrupted it hard, run the rollback block at the top.

## Step 4

Unchanged behaviour and channel overhead (MNC-2, NFR-2).

1. Asset fingerprint and parity:

   ```sh
   bench/asset-fingerprint.sh
   PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
   ```

2. Response shape of a normal page and the API:

   ```sh
   curl -sk -D - https://ai-lab.ddev.site/api/ping
   ```

3. Channel overhead in worker mode (long run on a clean tree; the script switches `.env` and the server, restores them, and cuts `storage/logs/laravel.log` back to its starting size). It loads `/debug/log-ping`, which writes one entry per request, with `LOG_STACK=single` and then `single,frankenphp`:

   ```sh
   bench/log-overhead.sh
   jq '{route, difference_percent, within_threshold}' bench/results/restart/*/log-overhead.json
   ```

Expected:
- The fingerprint matches the BENCHMARKS baseline: `app-DNob7CFE.css` with `176bae5b...` and `app-PTzu1QaN.js` with `7fbc3860...`.
- Parity differs only in the `captured=` line.
- The ping answers `200`, `content-type: application/json`, the same body as before, and the only new header is `x-request-id`.
- `route` is `/debug/log-ping`, and `difference_percent` is below 5 with `within_threshold` `true`, or the difference is within the spread of the repeats (BENCHMARKS reports which).

Rollback: the script restores `.env` and the server; otherwise use the block at the top.

## Step 5

Request id rules (AC-3), no restart needed, classic mode is fine.

```sh
curl -sk -D - -o /dev/null https://ai-lab.ddev.site/api/ping | grep -i x-request-id
curl -sk -D - -o /dev/null -H 'X-Request-Id: abcdefgh-1234' https://ai-lab.ddev.site/api/ping | grep -i x-request-id
curl -sk -D - -o /dev/null -H 'X-Request-Id: bad id!!' https://ai-lab.ddev.site/api/ping | grep -i x-request-id
curl -sk -D - -o /dev/null -H 'X-Request-Id: short' https://ai-lab.ddev.site/api/ping | grep -i x-request-id
```

Expected:
- Line 1: a fresh 26-character id such as `01M7MJKQD25XHDJ3YT9H5YSVD2`.
- Line 2: `abcdefgh-1234`, reused.
- Lines 3 and 4: new 26-character ids, not the text you sent.

## Step 6

Fallback to stderr and redaction (AC-2, AC-5). The command line has no `frankenphp_log()`, so the entry goes to stderr as one JSON line:

```sh
ddev artisan tinker --execute 'Log::channel("frankenphp")->info("manual check", ["password" => "hunter2", "api_token" => "abc", "X-Api-Key" => "k", "tokens_in" => 12, "user" => ["cookie" => "sid=1"], "exception" => new RuntimeException("boom")]);' 2>&1 >/dev/null | tail -1
```

Expected:
- One line of JSON with `"message":"manual check"`, `"level":"info"`, a `channel`, a `request` object whose fields are null and `"context":"cli"`.
- `password`, `api_token`, `X-Api-Key` and the nested `cookie` show `[redacted]`. `tokens_in` keeps `12`. The text `hunter2` appears nowhere.
- `exception` is an object with `class` `RuntimeException`, `message` `boom`, `code` and `file` ending in `:<line>`.

## Step 7

Guide page (AC-8):

1. Open https://ai-lab.ddev.site/ai/guides/logs-restart.
2. Click through the six tabs.
3. Search the page for `SL-`, `BR-` and `app/`.
4. Run `ddev artisan test --compact --filter='DocsLinksTest|GuideContentTest|NoReferencesInUiTest'`.
5. Open `docs/learning-guide.md` and find `I-12`.

Expected:
- The page loads with status 200, six tabs, and the explanation sections.
- No slice ids and no file paths on the page.
- The three tests pass, and I-12 exists.

## Step 8

Default logging and admin port (MNC-1, MNC-3), with `.env` back to `LOG_STACK=single`:

```sh
grep -n '^LOG_STACK' .env
ddev artisan tinker --execute 'Log::info("single only");'
tail -1 storage/logs/laravel.log
ddev describe | grep -i -E '2019|admin'
```

Expected:
- `LOG_STACK=single`, and the last line of `storage/logs/laravel.log` is the usual `[...] local.INFO: single only` line with no JSON request object.
- No new exposed port in `ddev describe`, and nothing listening on 2019 from the host: `curl -s -m 2 http://localhost:2019/config/` fails.

## Tick after the manual steps pass

Tick after the manual steps pass: task 12 (the `- [ ] 12.` line in `.ai/specs/spec-20261117-1052-logs-worker-restart/tasks.md`), once steps 1 and 2 pass. Steps 3 and 4 pass once the committed results under `bench/results/restart/` and the BENCHMARKS section match.
