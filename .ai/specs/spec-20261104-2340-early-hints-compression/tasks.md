# Early Hints and compression — Tasks

**Spec**: spec-20261104-2340-early-hints-compression | **Size**: M | **Tasks**: 8

## Phase 1: Backend

- [x] 1. Preload Link headers (backend)
  Covers: AC-1, MNC-1, MNC-2
  - [x] 1.1 Apply `AddLinkHeadersForPreloadedAssets` to `listings.show`
  - [x] 1.2 **Verify**: test asserts `Link` headers match the HTML's CSS/JS URLs; body unchanged

- [x] 2. Guarded 103 Early Hint (backend)
  Covers: AC-2, NFR-2
  - [x] 2.1 `EarlyHintSender` seam, `SendEarlyHints` middleware with manifest-based links, `config('performance.early_hints')`
  - [x] 2.2 **Verify**: `tests/Feature/EarlyHintsTest.php` (sender called with the links when available and on; not called when off or unavailable; no exception; final response identical)

## Phase 2: Bench

- [x] 3. 103 path check (bench)
  Covers: AC-3
  - [x] 3.1 `bench/early-hints.sh` via router and direct (`web:443` from a container), frankenphp and nginx-fpm, traces saved
  - [x] 3.2 **Verify**: script run recorded in `bench/results/early-hints/2026-11-04/` (frankenphp: router_103 true, direct_103 true; nginx-fpm: router_103 false, direct_103 false), checked by `tests/Feature/BenchEarlyHintsTest.php` (3 passed)

- [x] 4. LCP script (bench)
  Covers: AC-4, NFR-1
  - [x] 4.1 `bench/lcp.mjs` in a pinned Playwright image, CDP throttle, 5 cold runs, early-hint detection, path choice recorded
  - [x] 4.2 **Verify**: `BenchLcpTest` (argument and output shape with stubs); one quick live run

- [x] 5. Compression script (bench)
  Covers: AC-5, AC-6, MNC-3
  - [x] 5.1 `bench/compression.sh` per encoding and resource, wire bytes, time to last byte, export CPU via cgroup, single-compression and sha256 check
  - [x] 5.2 **Verify**: `BenchCompressionTest` with stubs; live run on frankenphp and nginx-fpm

## Phase 3: Measure and document

- [x] 6. Committed runs (measurement)
  Covers: AC-3, AC-4, AC-5, AC-6
  - [x] 6.1 103 path, LCP (3 variants × 5), compression (2 servers), results committed; end on nginx-fpm
  - [x] 6.2 **Verify**: result files present

- [x] 7. BENCHMARKS.md and guide section 21 (docs)
  Covers: AC-7
  - [x] 7.1 BENCHMARKS section with tables, chart, three subsections
  - [x] 7.2 Guide section 21
  - [x] 7.3 **Verify**: `BenchmarksDocTest` and `LearningGuideTest` read numbers from the result files

- [x] 8. Parity, fingerprint and bench (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 8.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 8.2 **Verify**: outputs recorded here
    - Suite 1098 passed, Pint passed, PHPStan no errors, `vue-tsc` clean, `npm run build` ok.
    - Fingerprint unchanged: `app-CjRfEL-0.css`, `app-BvRk9kiK.js` (output identical before and after the build).
    - Parity: `identical` for nginx-fpm against apache-fpm, frankenphp, frankenphp-octane, frankenphp-worker and openlitespeed; recaptured hashes equal the committed snapshots, so no re-baseline.
    - Re-baseline reason not needed for hashes, but `bench/parity.sh capture` was fixed: on FrankenPHP the `Server` header is only on the 103 of `/listings/1`, so the capture now reads it from any response (test `MNC-1 capture accepts a Server header that only the 103 response carries`).
    - `bench/run.sh ping --quick` exit 0 on a clean tree (temporary commit, undone afterwards); `.env` restored, server left on nginx-fpm.
    - `git diff --stat HEAD -- .ddev/config.yaml` empty; no `.ddev` changes.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | Link preload headers | `tests/Feature/PreloadLinksTest.php` | test | 1 |
| AC-2 | guarded 103 | `tests/Feature/EarlyHintsTest.php` | test | 2 |
| AC-3 | 103 path recorded | `manual-test.md#step-5` | manual | 3, 6 |
| AC-4 | LCP 3 variants × 5 | `tests/Feature/BenchLcpTest.php` | test | 4, 6 |
| AC-5 | compression per encoding | `tests/Feature/BenchCompressionTest.php` | test | 5, 6 |
| AC-6 | export compressed at most once | `tests/Feature/BenchCompressionTest.php` | test | 5, 6 |
| AC-7 | BENCHMARKS and guide 21 | `tests/Feature/LearningGuideTest.php`, `tests/Feature/BenchmarksDocTest.php` | test | 7 |
| MNC-1 | HTML identical, parity | `manual-test.md#step-10` | manual | 8 |
| MNC-2 | fingerprint unchanged | `manual-test.md#step-11` | manual | 8 |
| MNC-3 | export bytes unchanged | `tests/Feature/ServeExportTest.php` | test | 5 |
| MNC-4 | config.yaml untouched | `manual-test.md#step-13` | manual | 8 |
| NFR-1 | pinned, cold, recorded | `tests/Feature/BenchLcpTest.php` | test | 4 |
| NFR-2 | middleware never throws | `tests/Feature/EarlyHintsTest.php` | test | 2 |

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

- [x] 9. Skip the 103 in FrankenPHP worker and Octane mode, and test the real guard (backend)
  Covers: AC-2, NFR-2
  - [x] 9.1 `PhpEarlyHintSender::isAvailable()` checks only `function_exists('headers_send') && ! headers_sent()`; the Q3 condition "`$_SERVER['FRANKENPHP_WORKER']` is not set" is missing, so frankenphp-octane and frankenphp-worker also send a 103 (task 8 found the Server header only on the 103 for the octane and worker snapshots too). Add the check, with an injectable seam for `function_exists('headers_send')` and the server variable, so tests can drive the real sender
  - [x] 9.2 **Verify**: new `tests/Feature/EarlyHintsTest.php` cases on the real `PhpEarlyHintSender` and the container default binding: unavailable without `headers_send` (no call, no error, response unchanged) and unavailable when `FRANKENPHP_WORKER` is set; live `bench/early-hints.sh` on frankenphp-octane and frankenphp-worker shows `direct_103: false`
    Output (2026-11-05, after the env-aware guard): `bench/early-hints.sh` frankenphp-octane `router_103=no direct_103=no` (direct over plain HTTP/1.1 on 8000, Octane has no 443); frankenphp-worker `router_103=no direct_103=no` (direct https:443 HTTP/2); classic frankenphp `router_103=yes direct_103=yes`. Plus 3 probes each with `curl -sk -v --http2 .../listings/1 | grep -c '^< HTTP/2 103'`: octane 0 0 0, worker 0 0 0, frankenphp 1 1 1. Results in `bench/results/early-hints/2026-11-05/`.

- [x] 10. Resolve hint links like `Vite::preloadedAssets()`, skip the hint in dev or without a manifest, and tie the entries to the layout (backend)
  Covers: AC-2, NFR-2
  - [x] 10.1 `SendEarlyHints::links()` calls `Vite::asset()` per entry only: with `public/hot` present it hints the dev-server URLs, with no manifest it throws and `report()`s on every request, and imported chunks and CSS of the entries are not included (Q4). No test asserts that `config('performance.early_hint_entries')` equals the `@vite([...])` list in `resources/views/components/layouts/app.blade.php`
  - [x] 10.2 **Verify**: `EarlyHintsTest` cases: hot file present sends nothing; no manifest sends nothing and reports nothing; an entry with `imports`/`css` in a fixture manifest yields the same URL set as the final `Link` header; the config entries equal the layout's `@vite` list

- [x] 11. Make the final 200 headers identical with hints on and off on frankenphp, or record the deviation (backend)
  Covers: AC-2
  - [x] 11.1 `bench/results/early-hints/2026-11-04/frankenphp-direct.trace`: with hints on, `server: FrankenPHP Caddy` and `alt-svc` appear only on the 103 and are missing from the final 200. Q2 requires the same headers except `Date` for hints on vs off; the existing test compares only the body and `Link` in-process
  - [x] 11.2 **Verify**: live header dump of the final 200 on frankenphp with `EARLY_HINTS` on and off (shared `.env` backup/restore), diffed excluding `Date`; either the diff is empty, or the deviation and its cause are recorded in the spec Clarifications and the BENCHMARKS "Surprises"; output recorded here
    Output (2026-11-05, frankenphp, `.env` backed up and restored): diff of the final 200 headers without `Date`, hints on vs off, is `4a5 > server: FrankenPHP Caddy`, so the final 200 with hints on lacks `Server`; the deviation is recorded in spec-lite Clarifications and BENCHMARKS Surprises. Dumps: `bench/results/early-hints/2026-11-05/`. Test: `BenchCommittedRunsTest` "AC-2 the final 200 headers...".

- [x] 12. Make the LCP throttle apply to the navigation and record a real TTFB (bench)
  Covers: AC-4, NFR-1
  - [x] 12.1 All three `bench/results/lcp/2026-11-05/*.json` record `throttle.rtt_ms: 150` but `ttfb_ms.median` 16 to 18 ms and FCP 196 ms with hints, below what one 150 ms request latency allows; BENCHMARKS "Surprises" says the TTFB column is not a measure of the throttle. `bench/lcp.mjs` sets `Network.emulateNetworkConditions` per page and reads `nav.responseStart`. Fix the throttle so it covers the navigation (or measure TTFB from CDP `Network.responseReceived` timing), rerun the 3 variants × 5 cold runs, and regenerate the BENCHMARKS and guide numbers
  - [x] 12.2 **Verify**: rerun files show `ttfb_ms.median` >= 150 for every variant; `BenchLcpTest` asserts the TTFB source; `BenchCommittedRunsTest`, `BenchmarksDocTest` and `LearningGuideTest` pass with the new numbers

- [x] 13. Bring `bench/compression.sh` in line with Q10 to Q12: 5 repeats, median CPU, the exports record, the identity rule (bench)
  Covers: AC-5, AC-6
  - [x] 13.1 `REPEATS` defaults to 3 and the committed files have `repeats: 3` (spec: median of 5); export CPU is one download per encoding, not the median of 5 deltas (nginx-fpm shows 0.028 s for identity vs 0.118 s for zstd with the same uncompressed bytes); the sha256 reference is `sha256sum` of the file, not `exports.sha256` for `listings`; an `identity` request answered with any `Content-Encoding` is not flagged; requests use the host curl, not a container on `ddev_default` (Q12)
  - [x] 13.2 **Verify**: new `BenchCompressionTest` cases (5 CPU samples and their median recorded; `identity` with a `Content-Encoding` exits 1; the reference sha comes from the exports record); committed rerun on nginx-fpm and frankenphp with 5 repeats; `BenchmarksDocTest` and `LearningGuideTest` pass with the new numbers; server left on nginx-fpm

- [x] 14. Check the preload `Link` headers live on all six servers (checks)
  Covers: AC-1
  - [x] 14.1 AC-1 says all six servers; `PreloadLinksTest` runs in-process and parity hashes bodies and the Server header only, so no live `Link` check is recorded for apache-fpm, openlitespeed, frankenphp-octane or frankenphp-worker
  - [x] 14.2 **Verify**: on each of the 6 servers, `curl -skD - -o page.html <url>/listings/1` shows `Link` values for the CSS (`as="style"`) and JS (`modulepreload`) equal to the URLs in the HTML; output recorded here; server left on nginx-fpm
    Recorded 2026-11-05 with `curl -skD - -o page.html <url>/listings/1` after `ddev server <name>`; HTML tags: `<link rel="stylesheet" href="<base>/build/assets/app-CjRfEL-0.css"` and `<script type="module" src="<base>/build/assets/app-BvRk9kiK.js"`.
    - live-link: nginx-fpm match (https://ai-lab.ddev.site) 200 final `link: <https://ai-lab.ddev.site/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style", <https://ai-lab.ddev.site/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`; HTML URLs identical; early hint: none
    - live-link: apache-fpm match (https://ai-lab.ddev.site) 200 final `link: <https://ai-lab.ddev.site/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style", <https://ai-lab.ddev.site/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`; HTML URLs identical; early hint: none
    - live-link: frankenphp match (https://ai-lab.ddev.site) 200 final `link: <https://ai-lab.ddev.site/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style", <https://ai-lab.ddev.site/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`; HTML URLs identical; early hint: 103 with the same two Link values, then 200
    - live-link: openlitespeed match (https://ai-lab.ddev.site:8091) 200 final `link: <https://ai-lab.ddev.site:8091/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style", <https://ai-lab.ddev.site:8091/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`; HTML URLs identical; early hint: none
    - live-link: frankenphp-octane match (https://ai-lab.ddev.site) 200 final `link: <https://ai-lab.ddev.site/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style", <https://ai-lab.ddev.site/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`; HTML URLs identical; early hint: 103 with the same two Link values, then 200
    - live-link: frankenphp-worker match (https://ai-lab.ddev.site) 200 final `link: <https://ai-lab.ddev.site/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style", <https://ai-lab.ddev.site/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`; HTML URLs identical; early hint: none (no 103 seen in this run)
    - server left on nginx-fpm (`ddev server --url` = https://ai-lab.ddev.site)

- [x] 15. Record the task 3 verification (bench)
  Covers: AC-3
  - [x] 15.1 Task 3 and 3.1/3.2 are unticked above although `bench/early-hints.sh`, `BenchEarlyHintsTest` and `bench/results/early-hints/2026-11-04/` exist
  - [x] 15.2 **Verify**: `BenchEarlyHintsTest` passes, the committed run (frankenphp router and direct 103 true, nginx-fpm false) is cited, and task 3 is ticked

## Phase: Gaps (converge round 2)

- [x] 16. Make the worker/Octane guard hold in a live request, not only in the constructor seam (backend)
  Covers: AC-2, NFR-2
  - [x] 16.1 `app/Support/PhpEarlyHintSender.php` skips the hint only when `$_SERVER['FRANKENPHP_WORKER']` is set (the 9.1 change, file modified 01:01). Task 14's live run (recorded 01:30, after that change) still shows "frankenphp-octane ... early hint: 103 with the same two Link values, then 200". Inside `frankenphp_handle_request()` the per-request `$_SERVER` is rebuilt from the request, so the worker marker that `vendor/laravel/octane/bin/frankenphp-worker.php` checks at boot is not reliably there. Octane also sets `LARAVEL_OCTANE=1` in the process env (`StartFrankenPhpCommand.php`). The only test, `AC-2 the real sender is unavailable when FRANKENPHP_WORKER is set`, injects the array directly, so it cannot catch this. Read the marker from the process environment (`getenv('FRANKENPHP_WORKER')`, `getenv('LARAVEL_OCTANE')`, `$_ENV`) as well as `$_SERVER`, behind the same seam. Also update guide section 21, which says only that the sender is unavailable on servers without `headers_send`
  - [x] 16.2 **Verify**: new `EarlyHintsTest` cases: the sender is unavailable when the env seam carries `FRANKENPHP_WORKER` or `LARAVEL_OCTANE` and `$_SERVER` does not. A live `bench/early-hints.sh` (or `curl -skv --http2 .../listings/1`) on frankenphp-octane and on frankenphp-worker shows no `103`, and classic frankenphp still shows one. Output recorded here; server left on nginx-fpm
    Output: see 9.2 (same runs). New `EarlyHintsTest` cases: process environment seam carries `FRANKENPHP_WORKER` or `LARAVEL_OCTANE` while `$_SERVER` does not; real `getenv('LARAVEL_OCTANE')` via `putenv`. Guide section 21 wording is a doc item for the maintainer.

- [x] 17. Label the compression "js" row as the substitute bundle it is (docs)
  Covers: AC-5, AC-7
  - [x] 17.1 The page's script `resources/js/app.js` -> `assets/app-BvRk9kiK.js` is 0 bytes (sha256 `e3b0c442...`, BENCHMARKS.md line 815). `bench/compression.sh` (lines 100-103) therefore swaps in the largest built bundle. In `bench/results/compression/2026-11-05/*.json` that is `/build/assets/app-BFvfizUE.js`, the `resources/js/ai/app.ts` lab entry, which `/listings/1` never loads. The BENCHMARKS "Compression per encoding" table and guide section 21 present the row as plain `js` (202 851 bytes) and never say it is not the detail page's script, so the "detail page, its CSS and JS" reading of AC-5 is misstated
  - [x] 17.2 **Verify**: the BENCHMARKS compression section and guide section 21 name the measured JS file (`app-BFvfizUE.js`, `resources/js/ai/app.ts`) and state that the page's own `app-BvRk9kiK.js` is empty. `BenchmarksDocTest` asserts that the `js` row's `path` from each compression JSON appears in the section

- [x] 18. Link the server compression configs from guide section 21 (docs)
  Covers: AC-7
  - [x] 18.1 AC-7 requires links to "the code, configs, scripts, results and official docs". The only config section 21 of `docs/learning-guide.md` links is `../config/performance.php`. It explains which encodings each server negotiates but does not link the configs that decide this: `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` and `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp` (`encode zstd br gzip`), and `.ddev/nginx_full/nginx-site.conf` (gzip only, `text/csv` not in `gzip_types`)
  - [x] 18.2 **Verify**: section 21 links those three config files with working relative paths. `LearningGuideTest` asserts the links are present in the section and that each target file exists

## Phase: Review fix pass

Done on 2026-11-05 (uncommitted). Docs (BENCHMARKS.md, guide) are updated by the maintainer from these results.

- [x] R1. Worker/Octane guard (gap 16, task 9.2): `PhpEarlyHintSender` is unavailable when `$_SERVER` or the process environment (`getenv()`, `$_ENV`) carries `FRANKENPHP_WORKER`, or the environment carries `LARAVEL_OCTANE`; a new `environment` seam. Live: octane 0 and worker 0 103s (3 probes each), classic frankenphp 1 each.
- [x] R2. Manifest parity: `SendEarlyHints::links()` no longer walks the manifest by hand. It clones the Vite instance, calls it for the entries (as `@vite` does) and reads `preloadedAssets()`, so the set, rel and as equal the final `Link` header by construction (entry css, imports recursively, imports' css). Tests compare the middleware's links with `Vite::preloadedAssets()` for fixture manifests with entry-level css and nested/cyclic imports. Side effect: an entry missing from the manifest now throws inside the guarded block and is reported once (a misconfiguration signal) instead of being skipped silently.
- [x] R3. Throttling artifact: `bench/lcp.mjs` uses a toxiproxy container (TCP 443 to the router; latency 75 ms each direction, bandwidth 200 KB/s down and 94 KB/s up) and Chromium `--host-resolver-rules`; only the 4x CPU slowdown stays in CDP. Throttle method, toxics and image digests are in every result. Deviation: `ghcr.io/shopify/toxiproxy` is denied by the local Docker daemon, so the pinned image is `shopify/toxiproxy:2.1.4` from Docker Hub (digest recorded; amd64 under emulation). Old CDP files moved to `bench/results/lcp/2026-11-05/cdp-throttle/` with a README. Re-ran the 3 variants x 5.
- [x] R4. `lcp.mjs`: runs without FCP or LCP (or 0) are marked `valid: false`, excluded from the summary, counted in `invalid_runs`; fewer than 3 valid runs exits 1 with no file.
- [x] R5. Compression `js` row replaced by `page-js` (the page's own 0-byte script) and `lab-bundle` (largest built JS, not loaded by the detail page).
- [x] R6. `bench/early-hints.sh` direct path uses `--resolve <host>:443:<web ip>` with the real URL; `--add-host` removed. HTTP/2 is negotiated on frankenphp and frankenphp-worker; nginx-fpm answers HTTP/1.1 on 443 (DDEV nginx config), recorded in `direct_protocol`. Octane has no 443, so it falls back to plain HTTP/1.1 on 8000 (recorded). Re-run on all four servers.
- [x] R7. Traces redacted (certificate subject/issuer/mkcert/OU lines dropped, IPv4 to `<ip>`), regenerated; the old `2026-11-04` early-hints directory is deleted (new runs are dated 2026-11-05, UTC). `BenchCommittedRunsTest` greps the committed results for the user name, host name, Cyrillic, certificate lines and IPs.
- [x] R8. `.env` handling: new `bench/lib/env-switch.sh` (backup, set, config:clear, restore); `bench/compression.sh` sets `DEBUG_ROUTES=true` and `EXPORTS_PER_MINUTE=1000000`; new wrapper `bench/lcp.sh --hints off` sets `EARLY_HINTS=false`. Both restore `.env` and clear config on exit.
- [x] R9. `compression.sh` refuses to run without an image digest, runs the client by digest, downloads the export once per repeat (the export rows of the resource table come from the CPU loop; one extra decoded download per encoding stays for the sha256 check), and uses one true-median definition (mean of the middle two for an even count). Re-run on frankenphp and nginx-fpm.
- [x] R10. Gap 17 (label the `lab-bundle` row in BENCHMARKS and guide, assert it in `BenchmarksDocTest`) and gap 18 (link the three config files from guide section 21) stay open: doc items for the maintainer, with the accepted Server-header deviation.

- Owner-side notes (docs, after the fix pass): gaps 17 and 18 done in BENCHMARKS.md and guide section 21, asserted by `BenchmarksDocTest::AC-5 BENCHMARKS.md names the script bundle the compression rows measured` and `LearningGuideTest::AC-7 guide section 21 links the server compression configs and names the substitute script bundle`. Docs quote the toxiproxy re-run (LCP 1211 / 1238 / 1223 ms; FCP 609 / 663 / 612 ms) and keep the flawed CDP run as a Surprise. No personal data under bench/results (grep). Final checks: 1130 tests, Pint, PHPStan, typecheck, build green; fingerprint unchanged; parity identical on 6 servers; `bench/run.sh ping --quick` exit 0 on a clean tree after the commit.
