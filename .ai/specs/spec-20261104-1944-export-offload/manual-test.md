# Manual test plan: export offload (SL-11)

What this checks: a 50 MiB CSV download no longer ties up a PHP worker for the whole transfer. PHP can hand the file to the web server (offload) and be free at once, and the old way (PHP reads and sends the file, `mode=stream`) still gives the same bytes.

## Preconditions

- DDEV is running and you are in `/Users/stasmarkov/work/interview`. App URL: https://ai-lab.ddev.site (the other servers get their own URL; `ddev server --url` prints the current one).
- The route is a lab endpoint behind the debug gate. In `.env` set `DEBUG_ROUTES=true`, then run `ddev artisan config:clear`.
- Start on the default server: `ddev server default`.
- Use a clean working tree for step 5 (the fingerprint and parity checks compare against committed snapshots).
- You need about 60 MiB free in `storage/app/exports/` and curl, shasum and jq on the host.
- Switching servers restarts DDEV and takes about a minute each time. Always run `ddev server default` when you finish a server loop.

Helper used below (paste once per terminal):

```sh
cd /Users/stasmarkov/work/interview
export SHA=$(ddev exec sha256sum storage/app/exports/listings.csv | cut -d' ' -f1)   # run after step 1
```

## Step 1

Generate the file (AC-1).

```sh
ddev artisan exports:generate
ls -l storage/app/exports/listings.csv
head -2 storage/app/exports/listings.csv
ddev artisan tinker --execute 'echo json_encode(DB::table("exports")->get());'
ddev artisan exports:generate    # second run
```

Expected:
- The command prints bytes (at least 52428800), rows (about 206000) and a sha256.
- The header line is `snapshot,listing_id,make,model,year,price,currency,city,fuel,mileage_km,description`.
- The `exports` table has one row for `listings` with the same bytes, rows and sha256.
- After the second run the row's `generated_at` is newer, there is still one row, and no `*.tmp` file is left in `storage/app/exports/`.
- Run `export SHA=...` from the helper above now.

## Step 2

Offload on each server (AC-2). This is the originally reported problem: before this change, PHP held a worker for the whole download.

```sh
: > storage/logs/exports.jsonl
for s in nginx-fpm apache-fpm frankenphp openlitespeed; do
  ddev server $s >/dev/null
  u=$(ddev server --url)
  curl -sk -D /tmp/h.$s -o /tmp/e.$s.csv "$u/exports/listings?mode=offload"
  echo "$s | $(head -1 /tmp/h.$s | tr -d '\r') | $(grep -i '^x-export-mode' /tmp/h.$s | tr -d '\r') | $(shasum -a 256 /tmp/e.$s.csv | cut -d' ' -f1)"
done
ddev server default
echo "expected sha: $SHA"
jq -c '{server,mode,php_held_ms,bytes_sent_by_php}' storage/logs/exports.jsonl
```

Expected:
- Four lines, each `HTTP/... 200`, `X-Export-Mode: offload`, and a hash equal to `$SHA`.
- Four log lines with `mode` `offload` and `bytes_sent_by_php` 0. `php_held_ms` is small (tens of ms, the file does not pass through PHP).
- On openlitespeed the `Content-Type` is `application/octet-stream` instead of `text/csv` (known: OLS uses the file's MIME type). The bytes are still identical.
- If a server answers `X-Export-Mode: stream-fallback` instead, the bytes must still match and the gap must be written down in the guide.

## Step 3

The exports folder is not reachable by URL, and crafted paths are refused (NFR-2, AC-5).

Run on each server (`ddev server nginx-fpm|apache-fpm|frankenphp|openlitespeed`):

```sh
u=$(ddev server --url)
curl -sk --path-as-is -o /dev/null -w 'internal: %{http_code}\n' "$u/_protected_exports/listings.csv"
curl -sk --path-as-is -o /dev/null -w 'storage:  %{http_code}\n' "$u/storage/app/exports/listings.csv"
curl -sk --path-as-is -o /dev/null -w 'traverse: %{http_code}\n' "$u/_protected_exports/../../.env"
curl -sk --path-as-is -o /dev/null -w 'encoded:  %{http_code}\n' "$u/%5Fprotected_exports/listings.csv"
curl -sk --path-as-is -o /dev/null -w 'doubled:  %{http_code}\n' "$u//_protected_exports/listings.csv"
```

On openlitespeed also go around the router, inside the container (absolute-form and dot segments included):

```sh
docker exec ddev-interview-openlitespeed sh -c 'for p in /%5Fprotected_exports/listings.csv //_protected_exports/listings.csv /%2e/_protected_exports/listings.csv; do curl -s --path-as-is -o /dev/null -w "$p %{http_code}\n" "http://127.0.0.1:8088$p"; done'
```

Expected on every server (`encoded` and `doubled` too): never 200 and never any CSV or `.env` content.
- `internal`: 404 on nginx-fpm, apache-fpm and frankenphp; 403 on openlitespeed.
- `storage`: 403 on all four.
- `traverse`: 403 or 404.

Crafted offload header (optional, apache-fpm): temporarily make a route return `X-Sendfile: /var/www/html/.env` and request it; expected 404 and no `.env` content. Remove the probe afterwards. The same refusal for nginx is covered by `tests/Feature/ExportOffloadConfigTest.php` (internal location plus alias), run it with `ddev artisan test --compact tests/Feature/ExportOffloadConfigTest.php`: all pass.

## Step 4

Stream mode, memory bound and client disconnect (AC-3, AC-4).

1. Same bytes in stream mode, on nginx-fpm:

```sh
ddev server nginx-fpm
: > storage/logs/exports.jsonl
curl -sk -D /tmp/hs -o /tmp/s.csv "$(ddev server --url)/exports/listings?mode=stream"
grep -i '^x-export-mode' /tmp/hs; shasum -a 256 /tmp/s.csv; echo "expected $SHA"
jq -c '{mode,peak_memory_bytes,bytes_sent_by_php,aborted}' storage/logs/exports.jsonl
```

Expected: `X-Export-Mode: stream`, same hash, `bytes_sent_by_php` 52428832, `peak_memory_bytes` well under 33554432 (typically 2-6 MiB), `aborted` false. Repeat on the other three servers for a full check.

2. Client leaves part-way (slow client, gives up after 3 seconds), on each server:

```sh
: > storage/logs/exports.jsonl
curl -sk --limit-rate 3M --max-time 3 -o /dev/null "$(ddev server --url)/exports/listings?mode=stream"
sleep 3
jq -c '{server,mode,aborted,bytes_sent_by_php,php_held_ms}' storage/logs/exports.jsonl
```

Expected: one log line with `aborted` true and `bytes_sent_by_php` clearly below 52428832, on nginx-fpm, apache-fpm and frankenphp. Known gap: on openlitespeed `aborted` is false and all 52428832 bytes are read (OLS buffers the response, so PHP does not see the disconnect). Record the result for openlitespeed as "known gap", not as a pass.

## Step 5

Existing behaviour is unchanged (MNC-1, MNC-2, MNC-3).

1. `git diff --stat HEAD -- .ddev/config.yaml` is empty (MNC-2). There is no `concurrency.sh` overlay: `ls .ddev/config.*.yaml` shows only `config.zz-server.yaml`.
2. `bench/asset-fingerprint.sh` prints the `app.css` hash that BENCHMARKS.md lists (it starts `82042c94`) (MNC-3).
3. `bench/parity.sh` on a clean tree: `identical` for all 6 servers (MNC-1). Existing pages still answer on every server: `for p in / /listings /healthz; do curl -sk -o /dev/null -w "$p %{http_code}\n" "$(ddev server --url)$p"; done` gives 200 three times.
4. `ddev artisan test --compact`: all pass, and it needs no network (MNC-3).
5. `git diff HEAD -- .ddev/nginx_full .ddev/apache` shows only added lines for the new location and directives (MNC-1).
6. `git diff HEAD -- bench/slow-load.sh`: the removed lines match `bench/lib/health-probe.sh`, which both `slow-load.sh` and `export-load.sh` source (MNC-4); `bash -n bench/slow-load.sh bench/export-load.sh` prints nothing.

## Step 6

Errors, throttle and the gate (AC-5). On nginx-fpm:

```sh
u=$(ddev server --url)
curl -sk -w '\n%{http_code}\n' "$u/exports/nope"
curl -sk -w '\n%{http_code}\n' "$u/exports/listings?mode=zip"
```

Expected: first is `404` with `{"message":"Export \"nope\" not found."}`; second is `422` with a validation message about `mode`.

Throttle (30 per minute per IP):

```sh
for i in $(seq 1 40); do curl -sk -o /dev/null -w '%{http_code}\n' "$u/exports/nope"; done | sort | uniq -c
```

Expected: some `404` lines and, once past 30, `429` lines (JSON `Too Many Attempts.`). Wait one minute before continuing.

Gate: set `DEBUG_ROUTES=false` in `.env`, run `ddev artisan config:clear`, then `curl -sk -w '\n%{http_code}\n' "$u/exports/listings?mode=offload"`. Expected: `404` with `{"message":"Not found"}`. Turn it back on only if you still need it.

## Step 7

Benchmark results and docs (AC-6, AC-7, NFR-1).

1. `ls bench/results/export-load/2026-11-04` lists 8 files (`offload-` and `stream-` for nginx-fpm, apache-fpm, frankenphp, openlitespeed). Check: `jq -c '{server,mode,repeats,load_integrity_errors}' bench/results/export-load/2026-11-04/*.json`. Expected: repeats 3, `load_integrity_errors` 0 in every file (NFR-1).
2. Open BENCHMARKS.md and find `## File downloads: offload vs PHP streaming (2026-11-04)`. Expected: the table with server, mode, completed downloads, download p95, php_held_ms, peak memory, CPU per download, health p95 and health timeouts; one Mermaid chart; and the subsections "What the numbers say", "Surprises", "Interview story".
3. Open docs/learning-guide.md and find `## 20. Letting the web server send the file`. Expected: a walkthrough per server, the four header spellings (X-Accel-Redirect, X-Sendfile, X-LiteSpeed-Location, Caddy intercept), why the path is restricted, the cost of PHP streaming, the measured numbers, and links to code, configs, scripts and official docs. Click a few links; none is broken.
4. Optional re-run of one cell (about 10 minutes): `ddev server nginx-fpm && bench/export-load.sh --mode stream --quick`, then `ddev server default`. Expected: a results table, no integrity errors.

## Rollback and cleanup

- Set `DEBUG_ROUTES=false` (or remove the line) in `.env`, run `ddev artisan config:clear`.
- `ddev server default`.
- Remove test files: `rm -f /tmp/h.* /tmp/e.*.csv /tmp/s.csv /tmp/hs`. The generated CSV can stay or go: `rm storage/app/exports/listings.csv` (the next `exports:generate` recreates it).
- If a crafted-header probe route was added in step 3, delete it. Run `git status` and confirm no unexpected changes.
