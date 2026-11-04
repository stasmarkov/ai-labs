---
id: spec-20261104-1944-export-offload
title: Large CSV export offloaded to the web server, measured against PHP streaming
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-04T17:48:21Z
created: 2026-11-04
stacks: [laravel]
slice: SL-11
brs: [BR-51, BR-26, BR-21, BR-27]
---

# Large CSV export offloaded to the web server, measured against PHP streaming

## Summary

An Artisan command writes a ~50 MB CSV export to disk once. `GET /exports/{id}` delivers it in one of two ways:
- **offload**: PHP answers at once with an internal-redirect header, and the web server sends the file. The header is `X-Accel-Redirect` on nginx, `X-Sendfile` on Apache (`mod_xsendfile`) and FrankenPHP (Caddy `intercept`), and `X-LiteSpeed-Location` on OpenLiteSpeed.
- **stream**: PHP reads the file and writes it in chunks.

Both return the same bytes (BR-51). A bench runs 20 slow clients at 2 MiB/s each, downloading at once, on all four web servers, in both modes. It records how long PHP holds a worker per download, PHP's peak memory and CPU per download, and the `/healthz` p95 from the SL-09 probe. Results go to `BENCHMARKS.md`, and the guide gets a section.

## Context

- **Problem**:
  - Serving a large file from PHP keeps a worker busy for the whole transfer, and the client's speed sets how long that is.
  - SL-09 measured what a busy pool does to everything else: on 8 FPM children the health check took 4 s at p95, and with 6 s answer streams 299 of 300 probes timed out.
  - A 50 MB file to a 1 MB/s client is a 50 s hold.
  - Web servers can send files themselves after PHP hands them the path, but each server spells it differently and needs its own config.
- **What exists**:
  - The SL-09 probe and BR-21 harness (`bench/slow-load.sh`) and the overlay tooling (`bench/concurrency.sh`).
  - `bench/parity.sh`, and the six-server switch (`ddev server`).
  - 50 000 listings, each with a description of about 191 characters. One pass with descriptions is about 15 MB, so reaching ~50 MB needs repeated snapshots.
- **Server config facts (checked 2026-11-04)**:
  - **nginx:** `.ddev/nginx_full/nginx-site.conf` is still `#ddev-generated` and must be taken over to add an `internal` location.
  - **Apache:** `.ddev/apache/apache-site.conf` is already taken over. `mod_xsendfile` is not installed in the web image.
  - **FrankenPHP:** the Caddyfile lives in `.ddev/servers/frankenphp/web-build/`.
  - **OpenLiteSpeed:** its vhost config lives in `.ddev/servers/openlitespeed/conf/vhosts/`.

## Scope

### In scope

- **`exports:generate {--target-mb=50} {--id=listings}`** writes `storage/app/exports/{id}.csv` atomically (temp file, then rename).
  - Columns: `snapshot, listing_id, make, model, year, price, currency, city, fuel, mileage_km, description`.
  - It loops over all listings in id order, repeating with `snapshot` = 1, 2, … until the file is at least `--target-mb` MiB, finishing the current listing row.
  - It records the size and sha256 in a small `exports` table (`id` string primary key, `path`, `bytes`, `sha256`, `rows`, `generated_at`).
- **`GET /exports/{id}?mode=offload|stream`**, default `offload`:
  - behind the debug-routes gate and its own throttle, like `listings.slow` (each request holds bandwidth and, in `stream` mode, a worker on purpose);
  - `Content-Type: text/csv`, `Content-Disposition: attachment; filename="{id}.csv"`, and `Content-Length`;
  - an unknown id is a 404 JSON.
  - **offload**: PHP sends the header the active server understands, with an empty body, and the web server streams the file.
  - **stream**: PHP sends the file in 64 KiB chunks with a flush after each, through `response()->stream()`.
- **Per-server offload config.** Any server whose offload is not configured falls back to `stream` and says so in an `X-Export-Mode` response header (`offload` | `stream-fallback` | `stream`):
  - **nginx:** an `internal` location `/_protected_exports/` that aliases the exports directory. This takes over `nginx-site.conf`: the `#ddev-generated` marker is removed and the change commented.
  - **Apache:** install `libapache2-mod-xsendfile` through `.ddev/web-build/Dockerfile` (a new file, built into the DDEV web image), and `XSendFile On` / `XSendFilePath` limited to the exports directory in `apache-site.conf`.
  - **FrankenPHP (classic):** a Caddy `intercept` block in the frankenphp stash Caddyfile, which serves `X-Sendfile` paths with `file_server` and is restricted to the exports root. The frankenphp-octane and frankenphp-worker modes are out of scope.
  - **OpenLiteSpeed:** `X-LiteSpeed-Location` pointing at a context restricted to the exports directory. If OpenLiteSpeed cannot serve it, the endpoint falls back and the finding is documented.
- **A download log.** One JSON line per download in `storage/logs/exports.jsonl`:
  - `invocation id`, `server`, `mode`, `php_held_ms` (request start to the end of PHP's work: for offload, response returned; for stream, the last chunk flushed or the client disconnect noticed);
  - `peak_memory_bytes` (`memory_get_peak_usage(true)`) and `cpu_ms` (`getrusage` user + system delta);
  - `bytes_sent_by_php`, and `aborted`.
  It is written in a terminating callback that runs after the stream ends. Under FPM, Laravel's terminate runs after `fastcgi_finish_request`, so the stream case measures itself inside the stream callback.
- **`bench/export-load.sh`** (BR-21 method):
  - **Load:** `--mode offload|stream`, with `--clients 20` concurrent clients at `--rate 2M` (curl `--limit-rate`, 2 MiB/s), in a closed loop, each client downloading the whole file and checking the size and the sha256 of the first download per client.
  - **Probe:** the SL-09 health probe (5 rps, 50 connections, latency correction, 10 s timeout).
  - **Recorded:**
    - download time p50/p95 and completed downloads;
    - the integrity failure count;
    - from the log, median `php_held_ms`, peak memory and CPU per download;
    - the health p95 (with timeouts counted as 10 s) and the timeout share.
  - **Output:** one JSON per server × mode under `bench/results/export-load/<UTC date>/`.
  - **Matrix:** a wrapper, `bench/export-load-matrix.sh`, runs 4 servers × 2 modes with today's default worker limits, and ends on nginx-fpm.
- **Docs:** a `BENCHMARKS.md` section, guide section 20, and the section 16 code map is unchanged (not AI).

### Out of scope

- Octane and the frankenphp worker mode (their offload paths differ). The guide notes them.
- Range requests, resumable downloads, and compression of the CSV.
- Signed or expiring download URLs and authorization (no accounts exist). The endpoint is gated as a lab endpoint instead.
- Generating the export per request (it is generated once by the command).

## Must NOT change

- **MNC-1**:
  - every existing route and response, and `bench/parity.sh` identical on 6 servers;
  - the nginx and Apache config changes add a location or a directive only; existing locations behave as before.
- **MNC-2**: `.ddev/config.yaml` is not edited. Worker limits stay at today's defaults (no `concurrency.sh` overlay in this slice).
- **MNC-3**: the asset fingerprint is unchanged, and the suite runs offline.
- **MNC-4**: the SL-09 scripts and results are unchanged. The probe is reused by calling or sourcing the same functions, not copied.

## Acceptance criteria

- **AC-1 (BR-51)**: `ddev artisan exports:generate` SHALL write a CSV of at least 50 MiB with the stated columns and a header row, atomically, and record its size, sha256 and rows. Running it again SHALL replace the file and the record.
- **AC-2 (BR-51)**: WHEN `GET /exports/listings?mode=offload` is requested on nginx-fpm, apache-fpm, frankenphp and openlitespeed, THE SYSTEM SHALL return 200 with the full file, whose sha256 equals the record's, with `X-Export-Mode: offload`. PHP SHALL write 0 body bytes (the log's `bytes_sent_by_php` = 0). A server where offload cannot work SHALL return `X-Export-Mode: stream-fallback` with the same bytes, and the gap SHALL be documented.
- **AC-3 (BR-51)**: WHEN `mode=stream` is requested, THE SYSTEM SHALL return the identical bytes, write 64 KiB chunks with a flush after each, and keep PHP's peak memory under 32 MiB for the 50 MiB file.
- **AC-4**: Each download SHALL log one JSON line with the fields in Scope. In stream mode a client that disconnects SHALL be logged with `aborted: true`, and PHP SHALL stop reading the file (the SL-41 abort-watcher pattern).
- **AC-5**:
  - An unknown export id SHALL be a JSON 404.
  - A `mode` other than `offload` or `stream` SHALL be a 422.
  - The route SHALL be 404 when debug routes are off, and 429 over its throttle.
  - The offload paths SHALL serve only files under the exports directory: a test or check shows that a crafted path outside it is refused by each server config.
- **AC-6 (BR-26, BR-21)**: `bench/export-load-matrix.sh` SHALL produce 8 JSON files (4 servers × 2 modes, 3 repeats each, median). `BENCHMARKS.md` SHALL have a section `## File downloads: offload vs PHP streaming (<run date>)` with:
  - a table: server, mode, completed downloads, download p95, `php_held_ms`, peak memory, CPU per download, health p95 and health timeouts;
  - one Mermaid chart of `php_held_ms` or health p95;
  - "What the numbers say", "Surprises" and "Interview story".
- **AC-7 (BR-27)**: `docs/learning-guide.md` SHALL get `## 20. Letting the web server send the file`. It SHALL:
  - walk through one offload download on each server (who reads the file, when the PHP worker is free);
  - cover the four header spellings and their configs, why the path must be restricted, and what PHP streaming costs in worker time;
  - give the measured numbers;
  - link the code, configs, scripts and official docs.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Integrity | Every download in the bench is checked by size, and the first per client also by sha256. A mismatch counts as an error in the results. | `bench/results/export-load/` |
| **NFR-2** | Security | The offload configs serve only files under the exports directory, and the exports directory is not reachable directly by URL. | `manual-test.md#step-3` |

## Boundaries

- **Always**:
  - same bytes in both modes;
  - every server config change commented with the slice id;
  - leave the server on nginx-fpm.
- **Ask first**: editing `.ddev/config.yaml`; adding services; changing worker limits.
- **Never**:
  - expose `storage/app/exports` through a public URL;
  - commit generated CSVs (`storage/` is gitignored), `.env` or `.ai/session`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `routes/catalog.php` and the `listings.slow` gate and throttle pattern;
  - `app/Http/Responses/JsonErrorRenderer.php`;
  - `app/Ai/AbortWatcher.php` and `ConnectionAbortWatcher` (from SL-41);
  - `bench/slow-load.sh` (probe functions, BR-21 loop, `.env` handling) and `bench/concurrency.sh`;
  - `.ddev/nginx_full/nginx-site.conf`, `.ddev/apache/apache-site.conf`, `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp`, `.ddev/servers/openlitespeed/conf/vhosts/`;
  - `.ddev/commands/host/server`.
- **Approach**:
  - An `ExportFileService` (generate, locate) and `ServeExportController` choosing the header by the server it detects. Detection uses `$_SERVER['SERVER_SOFTWARE']` and a config override `exports.offload_header`, so tests can force each header.
  - An `ExportDownloadLog` for the JSON lines.
  - A factored probe: `bench/lib/health-probe.sh`, sourced by `slow-load.sh` and `export-load.sh`, with no behaviour change for `slow-load.sh`.

## Decisions (self-decided, standing approval)

1. **Generate once, serve many.** Generating 50 MB per request would measure the generator, not the delivery. The command writes it once, and the endpoint only delivers.
2. **Repeat snapshots to reach the size.** The real data is about 15 MB, and BR-51 asks for ~50 MB. A `snapshot` column makes the repetition explicit rather than padding with filler.
3. **20 clients at 2 MiB/s with today's default worker limits.** That is a hold of about 25 s per stream download (50 MiB at 2 MiB/s), against 8 FPM children or 14 FrankenPHP threads: the SL-09 situation, caused by the client's bandwidth this time and not by an API. No overlays are used, so the comparison is about the delivery mode alone.
4. **Detect the server for the header, with a config override.** One endpoint works on all four servers and tests can force each spelling. A wrong header would send an empty body, so the `X-Export-Mode` header and the integrity check catch it.
5. **Take over `nginx-site.conf` and add a web-build Dockerfile.** These are the only ways to add an internal location and an Apache module in DDEV. `config.yaml` stays untouched, and the takeover is recorded in the file.
6. **The SL-09 probe is factored into a shared file.** It is reused without copying (MNC-4), so both benches measure the health check the same way.

## Clarifications (binding; resolve the clarity gate)

**Choosing the header and the fallback (Q1).**
- **Which header:** `config('exports.offload')` maps the detected server to a header. The server comes from `$_SERVER['SERVER_SOFTWARE']`: `nginx` → `x-accel`, `Apache` → `x-sendfile`, `LiteSpeed` → `x-litespeed`, `Caddy` or `FrankenPHP` → `x-sendfile`, anything else → `none`.
- **Turning one off:** `exports.offload_enabled` (env `EXPORTS_OFFLOAD_ENABLED`, a comma list, default all four headers) can switch a header off. A switched-off header, or `none`, gives `stream-fallback`.
- **If OpenLiteSpeed cannot do it:** the build removes `x-litespeed` from the default list and documents why.
- **Switching servers:** nothing in `.env` needs to change, because detection runs per request.

**Header values (Q2).**
- **nginx:** `X-Accel-Redirect: /_protected_exports/{id}.csv` (a URI, with `alias` to the directory).
- **Apache:** `X-Sendfile: /var/www/html/storage/app/exports/{id}.csv` (an absolute path).
- **FrankenPHP:** `X-Sendfile` with the same absolute path, handled by Caddy:
  - `intercept { @sendfile header X-Sendfile /var/www/html/storage/app/exports/* … handle_response @sendfile { root * / ; rewrite * {resp.header.X-Sendfile} ; file_server } }`;
  - only paths under the exports directory match;
  - any other `X-Sendfile` value is passed through untouched and never served.
- **OpenLiteSpeed:** `X-LiteSpeed-Location: /_protected_exports/{id}.csv` (a URI), with a vhost context `/_protected_exports/` mapped to the directory and marked internal.

**Path restriction (Q3).** PHP only ever sends paths it built from a validated id (Q12), so the server-side restriction is defence in depth. It is checked two ways:
- **Statically:** `tests/Feature/ExportOffloadConfigTest.php` reads the four config files and asserts each one limits offload to the exports directory: nginx `internal` + `alias`, Apache `XSendFilePath`, the Caddy matcher prefix, and the OpenLiteSpeed context path.
- **Live:** a request to `/_protected_exports/listings.csv` (with `curl --path-as-is`) answers 404 on nginx, Apache and FrankenPHP and 403 on OpenLiteSpeed (its vhost rewrite rule on the decoded `REQUEST_URI`), also for `/%5Fprotected_exports/` and `//_protected_exports/`, and `/storage/app/exports/listings.csv` is never 200 on any server.
No debug route that sets arbitrary headers is added.

**Run length (Q4).**
- **Full runs:** each repeat is a closed loop of 60 s: all 20 clients download the file back to back at 2 MiB/s (about 25 s per file), and a download started before the window ends is allowed to finish. The probe covers the 60 s window and stops when it ends. There is no warm-up download. Why: a fixed window gives every cell the same probe length, and the first download of each cell is checked for size and sha256 like the rest, so a cold page cache shows up in the spread, which stayed small.
- **`--quick`:** 15 s, 1 repeat, `--rate 10M`, no server restart. It writes to `bench/results/raw/` only.

**Files and repeats (Q5).** One JSON per server × mode with `runs[]` (one per repeat, all figures) and a `median` block. The median is taken for every reported figure, with a `spread_percent` for each, the same layout as SL-09.

**Mapping log lines (Q6).**
- **Run id:** the bench sends `X-Bench-Run: <server>-<mode>-<repeat>-<pid>` with every download, and the log records it as `bench_run`. The bench aggregates the lines with its own run id.
- **Request id:** `invocation_id` is a UUID v7 per request.
- **Server names:** the log's `server` is the raw `SERVER_SOFTWARE`, and the bench records the DDEV server name itself.

**Throttle and gate (Q7).** `exports.per_minute` (env `EXPORTS_PER_MINUTE`, default 30 per IP). The bench uses the shared `.env` handling from SL-09: it sets `DEBUG_ROUTES=true` and `EXPORTS_PER_MINUTE=1000000`, and restores both on exit.

**Hold time and resources (Q8).** `php_held_ms` runs from `LARAVEL_START` to a `register_shutdown_function` callback, which runs at the very end of the PHP request on every SAPI: after `fastcgi_finish_request`, and after a streamed body ends or aborts. The log line is written in that callback. `peak_memory_bytes` covers the whole request.

**CPU (revised in review fix pass).** `cpu_ms` is user + system `getrusage` CPU for the whole request, boot included: the baseline is read in `public/index.php` next to `LARAVEL_START` (constant `LARAVEL_START_RUSAGE`) and the delta is taken in the shutdown callback. This holds only where one PHP process serves one request at a time (FPM, Apache, OpenLiteSpeed lsphp). FrankenPHP runs requests as threads of one process and PHP has no `RUSAGE_THREAD`, so there the log has `cpu_ms: null` and `cpu_scope: "process"`, and the bench and docs report "n/a (shared process)". Every line carries `cpu_scope` (`request` | `process`) and `abort_reason` (`client_disconnect` | `max_duration` | null).

**Review fix pass additions.** `X-Sendfile` on a FrankenPHP SERVER_SOFTWARE is sent only when the classic Caddyfile marks itself with `env EXPORTS_SENDFILE_INTERCEPT 1` (worker mode and Octane share the add-on `config.frankenphp.yaml`, so the mark lives in the Caddyfile, not in `web_environment`); otherwise `stream-fallback`. Stream mode stops after `exports.stream_max_seconds` (default 300) and logs `aborted: true, abort_reason: "max_duration"`. `--target-mb` must be 1 to 1024. On OpenLiteSpeed the vhost rule denies any decoded `REQUEST_URI` containing `_protected_exports` unless the original request line is `GET|HEAD /exports/<id>`.

**The memory bound (Q9).** The 32 MiB is the absolute `memory_get_peak_usage(true)` for one 50 MiB stream download. It is checked live in the bench log (every stream line ≤ 32 MiB) and in manual step 4. The feature test uses a 5 MiB file and asserts the peak grows by less than 2 MiB over an offload request's peak, so streaming never loads the file. Tests run on the pgsql `testing` database.

**Disconnects (Q10).** The SL-41 `AbortWatcher` interface (`aborted(): bool`, real implementation `connection_aborted()` after a flush) is reused. The test binds a fake that reports an abort after 3 chunks and asserts:
- fewer than all chunks were read (a counting reader);
- the log has `aborted: true`.
`Content-Length` is always the full size; an aborted download is short by definition.

**CSV format (Q11).**
- **Writing:** `fputcsv` (RFC 4180 quoting, `"` doubled), LF line endings, UTF-8, with the header row first.
- **Fields:** `price` is `price_cents / 100` with 2 decimals, and `currency` is the listing's code. All columns come from `listings` plus `listing_documents.description`; there are no other relations.
- **Counting:** `rows` counts data rows, not the header. The size target counts the whole file.

**Validating `{id}` (Q12).** The route constraint is `[a-z0-9-]{1,40}`, and the command option uses the same rule (exit 2 otherwise). A record whose file is missing answers JSON 404 "Export not generated." and reports the inconsistency.

**Directory (Q13).** The plain `storage_path('app/exports')`, not a Laravel disk, at `/var/www/html/storage/app/exports` inside the web container on every server (the same path in all four configs).

**The Dockerfile's reach (Q14).**
- **Which servers:** `.ddev/web-build/Dockerfile` is built into the shared DDEV web image, used by nginx-fpm, apache-fpm and the FrankenPHP variants. The FrankenPHP stash's Dockerfiles are added next to it by `ddev server`; a "stash" is `.ddev/servers/<name>/`, the per-server files that command activates.
- **Effect:** installing one Apache module changes no response on the other servers. The parity check on all 6 servers proves it, and the extra build time is noted in the guide.
- **"Classic" FrankenPHP:** the `frankenphp` server (not Octane or worker mode).

**Units and docs (Q15).**
- **Units:** sizes are MiB, the rate is MiB/s (curl `--limit-rate 2M` means 2 MiB/s), and "~50 MB" means ≥ 50 MiB.
- **Code map:** section 16 maps AI code, so this slice does not add to it.
- **Chart:** health p95 per cell, two bars per server (offload, stream).
- **Interview story:** the problem, what was measured, and at least one measured number.
- **Doc tests:** they read the medians from the result files and assert the sections quote them, rounded to whole ms, MiB to 1 decimal, and CPU to whole ms.

## Open questions

Clarity gate 2026-11-04: a fresh-context reviewer raised 15 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: Header selection and the fallback switch.
- [x] Q2: Header values per server.
- [x] Q3: Static and live checks of the path restriction, with no arbitrary-header route.
- [x] Q4: A 60 s window per repeat at 2 MiB/s, no warm-up; `--quick` is 15 s at 10M.
- [x] Q5: `runs[]` plus a median per file.
- [x] Q6: The `X-Bench-Run` id; UUID v7; server names.
- [x] Q7: Throttle default and the bench's `.env` handling.
- [x] Q8: Timing through the shutdown function.
- [x] Q9: The memory bound checked live and as a test delta.
- [x] Q10: The disconnect test with the SL-41 watcher.
- [x] Q11: The CSV format.
- [x] Q12: `{id}` validation and a missing file.
- [x] Q13: The directory and its absolute path.
- [x] Q14: The Dockerfile's reach; "stash".
- [x] Q15: Units and docs.

## Dependencies

- **Requires**: SL-09 (probe, `/healthz`, slow-load harness), SL-41 (abort watcher), SL-06 (bench harness).
- **Blocks**: none.

---
Next: `/sdd spec-20261104-1944-export-offload` (approval → build)
