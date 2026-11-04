# Export offload — Tasks

**Spec**: spec-20261104-1944-export-offload | **Size**: M | **Tasks**: 9

## Phase 1: Backend

- [x] 1. Export generation (backend, database)
  Covers: AC-1
  - [x] 1.1 `exports` table migration and model; `ExportFileService::generate()`; `exports:generate` command (atomic write, snapshots to target size, sha256, rows)
  - [x] 1.2 **Verify**: `tests/Feature/ExportGenerateTest.php` with a small target and few listings (header, snapshot repetition, atomic replace, record)

- [x] 2. Serving endpoint and log (backend)
  Covers: AC-2, AC-3, AC-4, AC-5
  - [x] 2.1 `ServeExportController`, route behind debug gate + throttle, mode validation, header choice by server with config override, `X-Export-Mode`, `Content-Length`, attachment headers
  - [x] 2.2 Stream mode with 64 KiB chunks, flush, abort watcher; `ExportDownloadLog` JSON lines with php_held_ms, peak memory, cpu_ms, bytes_sent_by_php, aborted
  - [x] 2.3 **Verify**: `tests/Feature/ExportOffloadConfigTest.php` (static restriction in all four configs) and `tests/Feature/ServeExportTest.php` (each header forced, empty body on offload, same bytes on stream, 404/422/429/debug-off, abort logged)

## Phase 2: Server configs

- [x] 3. nginx internal location (devops)
  Covers: AC-2, AC-5, NFR-2, MNC-1
  - [x] 3.1 Take over `nginx-site.conf` (comment why), add `location /_protected_exports/ { internal; alias …/storage/app/exports/; }`
  - [x] 3.2 **Verify**: live offload download on nginx-fpm matches sha256; direct `/_protected_exports/listings.csv` is 404

- [x] 4. Apache mod_xsendfile (devops)
  Covers: AC-2, AC-5, NFR-2
  - [x] 4.1 `.ddev/web-build/Dockerfile` installs `libapache2-mod-xsendfile`; `apache-site.conf` enables it limited to the exports path
  - [x] 4.2 **Verify**: live offload on apache-fpm matches sha256; `ExportOffloadConfigTest` asserts `XSendFilePath` is limited to the exports directory

- [x] 5. FrankenPHP and OpenLiteSpeed (devops)
  Covers: AC-2, AC-5, NFR-2
  - [x] 5.1 Caddy `intercept` for `X-Sendfile` in the frankenphp stash Caddyfile, root restricted to exports
  - [x] 5.2 OpenLiteSpeed `X-LiteSpeed-Location` context restricted to exports; if unsupported, fallback documented
  - [x] 5.3 **Verify**: live offload on frankenphp and openlitespeed (or `stream-fallback` with the reason)

## Phase 3: Bench

- [x] 6. Shared probe and export-load script (bench)
  Covers: AC-6, NFR-1, MNC-4
  - [x] 6.1 `bench/lib/health-probe.sh` sourced by `slow-load.sh` (no behaviour change; its tests stay green) and by `bench/export-load.sh`
  - [x] 6.2 `bench/export-load.sh` (20 clients, `--limit-rate`, closed loop, integrity checks, log aggregation) and `bench/export-load-matrix.sh`
  - [x] 6.3 **Verify**: `BenchExportLoadTest` with stubs; `--quick` run on nginx-fpm prints a table

## Phase 4: Measure and document

- [x] 7. Committed 8-cell run (measurement)
  Covers: AC-6
  - [x] 7.1 4 servers × 2 modes, 3 repeats, default worker limits; end on nginx-fpm
  - [x] 7.2 **Verify**: 8 JSON files committed

- [x] 8. BENCHMARKS.md and guide section 20 (docs)
  Covers: AC-6, AC-7
  - [x] 8.1 BENCHMARKS section with table, chart and three subsections
  - [x] 8.2 Guide section 20
  - [x] 8.3 **Verify**: `BenchmarksDocTest` and `LearningGuideTest` read the numbers from the result files

- [x] 9. Parity, fingerprint and bench (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 9.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 9.2 **Verify**: outputs recorded here
    - Full suite: 1016 passed (8648 assertions); Pint passed; PHPStan no errors; `vue-tsc --noEmit` and `npm run build` succeeded. `tests/Feature/ExportOffloadChecksTest.php` (MNC-1 to MNC-4) passes.
    - Fingerprint: `bench/asset-fingerprint.sh` printed the same two lines before and after a rebuild (app.css `assets/app-CjRfEL-0.css`, app.js `assets/app-BvRk9kiK.js`); `resources/` is unchanged.
    - Parity: captured on all 6 servers, every hash line equal to the committed snapshot (only the timestamp differed, snapshots restored); `bench/parity.sh compare nginx-fpm <server>` printed `identical` for the other five.
    - `bench/run.sh ping --quick`: exit 0 on a clean tree (changes stashed during the run, restored after), spread 0%.
    - `git diff HEAD -- .ddev/config.yaml` is empty; `.env` restored; active server nginx-fpm.
    - Fix found by the full suite: the AC-3 memory test compared a 5 MiB file against a 2 MiB bound and failed once in the full run (3.0 MiB delta from process peak noise); now a 16 MiB file against an 8 MiB bound, which a full in-memory load would still exceed.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | generate ≥ 50 MiB atomically | `tests/Feature/ExportGenerateTest.php`, `manual-test.md#step-1` | test, manual | 1 |
| AC-2 | offload on 4 servers, same bytes | `manual-test.md#step-2` | manual | 2, 3, 4, 5 |
| AC-3 | stream mode same bytes, memory bound | `tests/Feature/ServeExportTest.php`, `manual-test.md#step-4` | test, manual | 2 |
| AC-4 | download log, abort | `tests/Feature/ServeExportTest.php`, `manual-test.md#step-4` | test, manual | 2 |
| AC-5 | 404/422/429/gate, path restriction | `tests/Feature/ServeExportTest.php`, `manual-test.md#step-6`, `manual-test.md#step-3` | test, manual | 2, 3, 4, 5 |
| AC-6 | committed matrix and BENCHMARKS | `tests/Feature/BenchmarksDocTest.php`, `manual-test.md#step-7` | test, manual | 6, 7, 8 |
| AC-7 | guide section 20 | `tests/Feature/LearningGuideTest.php`, `manual-test.md#step-7` | test, manual | 8 |
| MNC-1 | existing routes and parity | `manual-test.md#step-5` | manual | 9 |
| MNC-2 | config.yaml untouched, default limits | `manual-test.md#step-5` | manual | 7, 9 |
| MNC-3 | fingerprint, offline suite | `manual-test.md#step-5` | manual | 9 |
| MNC-4 | SL-09 scripts unchanged in behaviour | `tests/Feature/BenchSlowLoadTest.php`, `manual-test.md#step-5` | test, manual | 6 |
| NFR-1 | integrity checked | `tests/Feature/BenchExportLoadTest.php`, `manual-test.md#step-7` | test, manual | 6 |
| NFR-2 | exports not public, path restricted | `manual-test.md#step-3` | manual | 3, 4, 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 10. Bench run parameters match the binding run-length clarification (bench, docs)
  Covers: AC-6
  - [x] 10.1 **Verify**: `bench/export-load.sh` defaults and spec-lite.md (Scope "Load", Decision 3, Q4) state the same rate, run length, warm-up and `--quick` settings; BENCHMARKS.md and guide section 20 give the rate in MiB/s; `BenchExportLoadTest` asserts the documented defaults; `BenchmarksDocTest`, `LearningGuideTest` and `ExportLoadResultsTest` pass.

- [x] 11. Offload config guards fail instead of skipping, and cover the OpenLiteSpeed direct-request deny rule (devops, test)
  Covers: AC-5, NFR-2
  - [x] 11.1 **Verify**: `ExportOffloadConfigTest` fails (not skips) when a config lacks its SL-11 change, and asserts the vhconf.conf `THE_REQUEST ... /_protected_exports/` rule with `[F,L]`; `manual-test.md#step-3` expects 404 on nginx and 403 on OpenLiteSpeed (never 200), and its run on all four servers is recorded under this task.
    Step 3 run on 2026-11-04 (direct `/_protected_exports/listings.csv` / `/storage/app/exports/listings.csv`): nginx-fpm 404 / 403; apache-fpm 404 / 403; frankenphp 404 / 403; openlitespeed 403 / 403. `ExportOffloadConfigTest` fails when the OLS `[F,L]` rule is changed or the SL-11 marker is removed, and passes as is (7 tests).

- [x] 12. Guide section 20 walks each server through one offload and notes the out-of-scope modes (docs)
  Covers: AC-7
  - [x] 12.1 **Verify**: section 20 has a per-server walkthrough (nginx-fpm, apache-fpm, frankenphp, openlitespeed: who reads the file, when the PHP worker is free), notes that Octane and the FrankenPHP worker mode are not covered and why, notes the extra web-image build time of `.ddev/web-build/Dockerfile`, and uses `/exports/listings?mode=offload` in the example; `LearningGuideTest` asserts these and passes.

- [x] 13. Tick task 2 in tasks.md (docs)
  Covers: AC-2, AC-3, AC-4, AC-5
  - [x] 13.1 **Verify**: task 2 and 2.1 to 2.3 are `[x]`, matching the passing `ServeExportTest` and `ExportOffloadConfigTest`.

## Phase: Gaps (converge round 2)

- [x] 14. Prove stream mode flushes after every 64 KiB chunk (backend, test)
  Covers: AC-3
  - [x] 14.1 **Verify**: a test in `tests/Feature/ServeExportTest.php` named for AC-3 sends a `mode=stream` response for a file of N full chunks plus a remainder through an output buffer whose callback records each call's write length and `PHP_OUTPUT_HANDLER_FLUSH` phase; it asserts one flush per chunk (N + 1 flushes) and that every flushed write except the last is exactly `ExportStreamer::CHUNK_BYTES`. It fails if the `ob_flush()`/`flush()` calls in `app/Support/ExportStreamer.php` are removed, and `ddev artisan test --compact tests/Feature/ServeExportTest.php` passes.

## Phase: Review fix pass

Each finding was verified before fixing. Doc notes for the guide and BENCHMARKS (not edited here) are marked DOC.

1. CPU baseline (fixed). `public/index.php` defines `LARAVEL_START_RUSAGE = getrusage()` next to `LARAVEL_START`; the log's `cpu_ms` is the delta from it, so boot, middleware and the record lookup are included on FPM, Apache and OLS. FrankenPHP logs `cpu_ms: null`, `cpu_scope: "process"` (threads share one process, no `RUSAGE_THREAD`); `bench/export-load.sh` reports "n/a (shared process)" and stores `php.cpu_ms_total: null`. Spec Q8 revised. DOC: the published FrankenPHP CPU figures (28.6 / 699.9 ms) must go, and every other CPU figure rises after the re-run because boot is now counted, so the offload-vs-stream CPU ratio needs restating. `BenchmarksDocTest` accepts the n/a cell.
2. OLS direct access (fixed, verified live). The old rule matched the raw `THE_REQUEST`; inside the container `/%5Fprotected_exports/...`, `//_protected_exports/...`, `/%2e/_protected_exports/...` and an absolute-form target all answered 200. Now the vhost denies any decoded `REQUEST_URI` containing `_protected_exports` unless the original request line is `GET|HEAD /exports/<id>[?query]` (after the X-LiteSpeed-Location redirect the rules run again and `THE_REQUEST` still holds the client's line). All variants now 403 inside the container and through the router; `?mode=offload` still works (sha256 matches).
3. FrankenPHP worker and Octane (fixed, verified live). The `config.frankenphp.yaml` overlay is shared by `frankenphp` and `frankenphp-worker`, so a `web_environment` flag there would also enable worker mode. The mark is `env EXPORTS_SENDFILE_INTERCEPT 1` on the `php_server` blocks of the classic Caddyfile only; PHP reads it as a server variable. Live: frankenphp offload (sha ok); frankenphp-worker and frankenphp-octane answer `X-Export-Mode: stream-fallback`, same bytes, no `X-Sendfile`. DOC: under worker mode and Octane the shutdown-callback log line is not written (the PHP request never ends), so those modes have no `exports.jsonl` lines; they remain out of scope.
4. OLS abort detection (verified, limitation kept). A stream download cut by the client after 3 s is still logged `aborted: false`, `bytes_sent_by_php` = 52 428 832, `php_held_ms` about 0.3 s: OLS accepts everything PHP writes into its response buffer without waiting for the client, so PHP never sees the disconnect. Lowering `inMemBufSize` to 1M (the only buffer setting; `respBuffer` is already 0) changed nothing, since OLS swaps to disk and keeps reading. No setting applies without touching other servers, so behaviour is kept. DOC: on OLS `aborted: false` in stream mode is a server limitation, and OLS stream `php_held_ms` measures how fast OLS drains PHP, not the client's pace, so it must not be compared with the other servers' stream hold times.
5. OLS Content-Type (fixed, verified live). `forceType` and a `[T=]` rewrite flag did not change it; OLS takes the type from its `mime.properties`, which has no `csv`. The OLS Dockerfile appends `csv = text/csv`; offload now answers `Content-Type: text/csv` (HEAD too).
6. `ExportFileService::generate` (fixed). `fwrite` must return `strlen($line)` and `fclose` must succeed, else `ExportGenerateException`, the temp file is removed and the record is untouched. Tested with a stream wrapper that writes one byte short.
7. HEAD in stream mode (fixed). Headers only, PHP never reads the file, log has `bytes_sent_by_php: 0`.
8. Log robustness (fixed). `X-Bench-Run` must match `[A-Za-z0-9._-]`, cut to 80 characters, else null; the JSON uses `JSON_INVALID_UTF8_SUBSTITUTE`; the write is wrapped so an exception is reported with `report()` and never thrown from the shutdown callback.
9. `--target-mb` (fixed). Must be 1 to 1024, else exit 2; a target that would write no data row throws `ExportGenerateException`. The tests that used sub-MiB targets through the command now call the service.
10. AC-3 memory test (fixed). 5 MiB file, warm-up requests, `memory_reset_peak_usage()` before each request, the fixture freed, delta below 2 MiB on `memory_get_peak_usage()`. Mutation check: adding `file_get_contents($path)` to the streamer made it fail; reverted.
11. Gap 14 (done). The flush test counts `PHP_OUTPUT_HANDLER_FLUSH` calls through an `ob_start` callback: N + 1 flushes, every one but the last exactly 64 KiB. Mutation check: removing `ob_flush()` and `flush()` made it fail; reverted.
12. `ExportDelivery` (fixed). The unused `$mode` and the free-string `$label` are replaced by one `ExportDeliveryMode` enum (`offload`, `stream-fallback`, `stream`) that decides the header value, the log's `mode` and the streaming branch.
13. Slow-read DoS (fixed). `exports.stream_max_seconds` (env `EXPORTS_STREAM_MAX_SECONDS`, default 300); streaming stops after it and logs `aborted: true`, `abort_reason: "max_duration"`. The log also has `abort_reason: "client_disconnect"` for real disconnects.

- Owner-side notes (after the review fix pass):
  - The 8-cell matrix was re-run at commit b00e1f6 (CPU baseline from request start, FrankenPHP CPU n/a); the first run's files were replaced. BENCHMARKS.md and guide section 20 quote the new files.
  - Deviation from Clarification Q4: the harness uses a 60 s closed-loop window at 2 MiB/s per client (about 25 s per download, 3 downloads per client per repeat) instead of 1 MiB/s and a fixed 2 downloads; the hold per download and the BR-21 window are the point, and BENCHMARKS.md states the method used.
  - OpenLiteSpeed: stream mode cells are flagged for spread (44 % health p95) and the offload cell at 31 %; OpenLiteSpeed buffers PHP output, so its stream hold time measures the buffer and disconnects are not seen (documented).
  - Final checks: 1063 tests, Pint, PHPStan, typecheck, build green; fingerprint unchanged; parity identical on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree after the docs commit.
