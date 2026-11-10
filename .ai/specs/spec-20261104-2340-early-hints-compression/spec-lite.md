---
id: spec-20261104-2340-early-hints-compression
title: Preload links, a 103 Early Hint on FrankenPHP, and compression measured per encoding
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-04T21:43:37Z
created: 2026-11-04
stacks: [laravel]
slice: SL-10
brs: [BR-50, BR-52, BR-26, BR-27]
---

# Preload links, a 103 Early Hint on FrankenPHP, and compression measured per encoding

## Summary

The listing detail page (`GET /listings/{id}`) gets two additions:
- **Preload links.** Its final response announces the page's Vite CSS and JS in `Link: <…>; rel=preload` headers, using Laravel's `AddLinkHeadersForPreloadedAssets` middleware.
- **An Early Hint on FrankenPHP.** Before the controller does its database work, the page sends a `103 Early Hints` interim response with the same links, through FrankenPHP's `headers_send(103)`. The call is guarded, so every other server returns an identical final response and no error (BR-50).

Compression gets measured, not built (BR-52):
- The FrankenPHP Caddyfiles already have `encode zstd br gzip`.
- The slice verifies negotiation for each `Accept-Encoding` and measures bytes and server CPU per encoding for the detail page, its CSS and JS, and the 50 MiB CSV export, on frankenphp and nginx-fpm.
- It checks that an offloaded file is compressed once at most.

LCP is measured with a pinned, throttled Chromium (Playwright) running in a container, 5 runs per variant, on frankenphp (hints on and off) and nginx-fpm.

## Context

- **Facts checked on 2026-11-04:**
  - **Router:** the DDEV router is Traefik 3.7.12 and serves HTTP/2.
  - **Early Hints in Chromium:** honoured only over HTTP/2 or HTTP/3. Whether Traefik forwards a 103 from the backend is **unverified**.
  - **nginx compression:** `gzip` only. `Accept-Encoding: br` and `zstd` get an uncompressed answer (1 512 bytes vs 628 with gzip for `/listings/1`).
  - **FrankenPHP compression:** `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` and the worker Caddyfile both have `encode zstd br gzip`.
  - **Layout:** the detail page uses `resources/views/components/layouts/app.blade.php` with `@vite`.
- **Why it matters.** The detail page waits on the database before sending any byte. A 103 lets the browser fetch CSS and JS during that wait. Compression trades CPU for bytes, which matters on a slow mobile link.
- **Constraints:**
  - The asset fingerprint (`app-CjRfEL-0.css` / `app-BvRk9kiK.js`) and the parity captures must not change. Parity hashes bodies, and a new `Link` header changes headers only. `bench/parity.sh` must still say `identical`, or its capture must be re-baselined with the reason recorded.

## Scope

### In scope

- **Preload links.** `AddLinkHeadersForPreloadedAssets` is applied to `listings.show` only. Its final response carries `Link` headers for the CSS and JS of the page's `@vite` entries, the same URLs as the HTML tags.
- **The Early Hint.** A small `SendEarlyHints` middleware on `listings.show`:
  - On classic FrankenPHP (see Clarifications Q3), it sets the `Link` headers, calls `headers_send(103)`, removes them again, then calls `$next($request)`.
  - On every other SAPI it does nothing.
  - The switch is `config('performance.early_hints')` (env `EARLY_HINTS`, default true), so the bench can turn it off.
  - The links come from Vite's manifest for the page's entries.
- **Verifying the 103 path.** `bench/early-hints.sh` records whether a 103 arrives with `curl -v --http2`, both through the router (`https://ai-lab.ddev.site`) and straight at the web container (`https://web:443`, from a container on `ddev_default`).
- **LCP.** `bench/lcp.mjs` with a pinned Playwright Chromium image, run as a container on `ddev_default`.
  - It throttles with Chrome DevTools Protocol network emulation: 150 ms RTT, 1.6 Mbit/s down, 750 kbit/s up (Lighthouse "Slow 4G"), and 4× CPU slowdown.
  - It runs 5 cold runs per variant (cache disabled, a new browser context each time).
  - It records LCP, FCP, TTFB, and whether an early hint was used (`performance.getEntriesByType('navigation')[0].initiatorType` / the resource timing `initiatorType` "early-hints").
  - Variants: frankenphp with hints, frankenphp without hints, and nginx-fpm.
  - It goes through the router if the router forwards 103, else straight at `web:443`, and states which.
- **Compression.** `bench/compression.sh` runs on frankenphp and nginx-fpm for `/listings/1`, the CSS, the JS and `/exports/listings?mode=offload` (debug routes on).
  - For each `Accept-Encoding` of `identity`, `gzip`, `br`, `zstd`, and `zstd, br, gzip`, it records the returned `Content-Encoding`, the wire bytes, and the time to the last byte (median of 5).
  - For the export it also records the server CPU used by the web container during one download per encoding (`docker stats` CPU seconds, from cgroup `cpu.stat` before and after).
  - It checks that the offloaded export is compressed exactly once: `Content-Encoding` appears once, and the decompressed sha256 equals the record.
- **nginx and Apache compression** stay as they are (gzip, or Apache's defaults). This slice records them only. Changing them is out of scope.
- **Docs:** a `BENCHMARKS.md` section `## Early Hints and compression (<run date>)` and guide section 21.

### Out of scope

- Adding brotli or zstd to nginx or Apache (needs modules; a later option, noted in the guide).
- Early Hints on frankenphp-octane and frankenphp-worker (worker request lifecycle; noted). The guarded middleware does nothing there unless the SAPI check passes, and it is measured only on classic frankenphp.
- HTTP/3 (SL-12).
- Preloading fonts or images.

## Must NOT change

- **MNC-1**: The detail page's HTML body is byte-identical on all 6 servers with hints on or off. `bench/parity.sh` stays `identical`; if a capture includes headers, re-baseline it with the reason recorded.
- **MNC-2**: The asset fingerprint is unchanged (the Vite build is not touched).
- **MNC-3**: The SL-11 export bytes and sha256 are unchanged, and offload still sends 0 PHP bytes.
- **MNC-4**: `.ddev/config.yaml` is not edited, and server configs keep their compression settings.

## Acceptance criteria

- **AC-1 (BR-50)**: WHEN `GET /listings/1` is requested on any server, THE SYSTEM SHALL answer 200 with `Link` headers that preload the page's CSS and JS (`rel=preload; as=style` and `as=script` or `modulepreload`), matching the URLs in the HTML.
- **AC-2 (BR-50)**: WHEN the server is classic frankenphp and `EARLY_HINTS` is on, THE SYSTEM SHALL send a `103` with the same `Link` headers before the final 200. With `EARLY_HINTS=false`, or on any other server, no 103 is sent and the final response is identical (headers apart from `Date`, plus the body). A test SHALL cover the guard (no call without `headers_send`, no error) using a seam for the function.
- **AC-3**: `bench/early-hints.sh` SHALL report whether a 103 arrives through the router and directly, on frankenphp and nginx-fpm, and save its curl traces to `bench/results/early-hints/<date>/`.
- **AC-4 (BR-26)**: `bench/lcp.mjs` SHALL record LCP, FCP and TTFB (median and range of 5 cold runs) for the three variants under the stated throttle. It SHALL record whether the browser used the early hint, and the path used (router or direct). The JSON goes to `bench/results/lcp/<date>/`.
- **AC-5 (BR-52)**: `bench/compression.sh` SHALL show:
  - frankenphp returning `zstd`, `br`, `gzip` or no encoding according to `Accept-Encoding`, preferring zstd for `zstd, br, gzip`;
  - nginx-fpm returning gzip or nothing;
  - for each resource and encoding: wire bytes and time to the last byte;
  - for the export: CPU seconds per encoding.
  The JSON goes to `bench/results/compression/<date>/`.
- **AC-6**: The offloaded export SHALL be compressed at most once on every encoding: one `Content-Encoding` value, and the decompressed sha256 equals the `exports` record. A test or a bench check SHALL fail otherwise.
- **AC-7 (BR-26, BR-27)**:
  - `BENCHMARKS.md` SHALL have the section with tables for the 103 path, LCP and compression, one Mermaid chart (LCP per variant), plus "What the numbers say", "Surprises" and "Interview story".
  - `docs/learning-guide.md` SHALL get `## 21. Early Hints and compression`, explaining:
    - what a 103 is and why only HTTP/2+ browsers use it;
    - why it must be sent before the slow work;
    - why proxies may drop it;
    - preload vs modulepreload;
    - how content negotiation picks an encoding;
    - the CPU/bytes trade-off with the measured numbers;
    - why compressing an offloaded large file can cost more than it saves.
  - It SHALL have links to the code, configs, scripts, results and official docs.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Reproducibility | The browser and probe images are pinned by digest, the throttle values are recorded in every LCP file, and the runs are cold. | `bench/results/lcp/` |
| **NFR-2** | Safety | The hint middleware never throws on any SAPI. A server without `headers_send` behaves exactly as before. | `tests/Feature/EarlyHintsTest.php` |

## Boundaries

- **Always**:
  - guard every server-specific call;
  - pin images;
  - leave the server on nginx-fpm.
- **Ask first**: editing `.ddev/config.yaml` or the router; adding nginx or Apache compression modules.
- **Never**:
  - change the Vite build or the asset names;
  - commit `.env` or `.ai/session`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `routes/catalog.php` (`listings.show`);
  - `resources/views/components/layouts/app.blade.php` (`@vite`);
  - `vendor/laravel/framework/src/Illuminate/Http/Middleware/AddLinkHeadersForPreloadedAssets.php`;
  - the `Illuminate\Foundation\Vite` manifest helpers;
  - the FrankenPHP Caddyfiles under `.ddev/servers/frankenphp*/web-build/`;
  - `.ddev/nginx_full/nginx-site.conf`;
  - `bench/lib/health-probe.sh` and the docker conventions in `bench/run.sh` (pinned images, `--add-host` to the router);
  - SL-11's `bench/export-load.sh` (exports, debug routes `.env` handling).
- **Approach**:
  - `SendEarlyHints` uses an injectable `EarlyHintSender` (real one calls `headers_send(103)` when it exists).
  - Link values come from `Vite::preloadedAssets()` after a render, or from a manifest lookup for the page's entries. Pick whichever gives the same URLs before the controller runs, and test it.

## Decisions (self-decided, standing approval)

1. **Laravel's own preload middleware for the final `Link` headers.** It already turns `@vite` preloads into headers, so no hand-written list can drift from the HTML.
2. **Manifest-based links for the 103.** The 103 must go out before the controller renders the view, so it cannot use the render's preloads. The page's Vite entries are known, and the manifest gives their built URLs.
3. **Measure the path, then measure LCP on the path that works.** If Traefik drops 103, measuring LCP through it would show "hints do nothing" for the wrong reason. Going straight at `web:443` over HTTP/2 isolates the server's effect, and the doc states which path each number used.
4. **Compression is recorded, not changed on nginx or Apache.** BR-52 is about FrankenPHP. Adding brotli or zstd to nginx needs a third-party module, a separate decision.
5. **Lighthouse's "Slow 4G" throttle with 4× CPU.** It is the standard mobile profile, so the numbers compare with published ones, and a hint only helps when the round trip is long.

## Clarifications (binding; resolve the clarity gate)

**Link headers on FrankenPHP (Q1).** The hint middleware sends the 103 with `headers_send(103)` and then removes its `Link` headers with `header_remove('Link')`. The final 200's `Link` headers therefore come only from `AddLinkHeadersForPreloadedAssets`, the same as with hints off. A test asserts there is exactly one set of `Link` values in the final response.

**"Identical" (Q2).**
- **Hints on vs off on the same server:** the same status, the same body bytes, and the same headers except `Date`.
- **Across servers:** the same body bytes and the same set of `Link` URLs. Server-specific headers (`Server`, `Via`, encodings) may differ.
- **Matching:** "same `Link`" means the same set of URLs with the same `rel` and `as`. Order and quoting are not compared.

**Final 200 headers on FrankenPHP (Q2 deviation).** A live dump on 2026-11-05 (`bench/results/early-hints/2026-11-05/frankenphp-final-headers-hints-{on,off}.txt`, diff in `frankenphp-final-headers.diff`, `Date` excluded) shows one difference: with hints on, `Server: FrankenPHP Caddy` is on the 103 but missing from the final 200. Status, `Link`, `Cache-Control`, `Content-Type` and `Vary` are identical. Cause: Caddy writes its own `Server` header while flushing the interim response, and `headers_send(103)` leaves nothing for the final one. It is not set from PHP, so the sender cannot restore it without hard-coding a server banner; accepted as a recorded deviation. Bodies are unaffected (MNC-1).

**The guard (Q3).** The hint is sent only when all of these hold:
- `function_exists('headers_send')`, which exists only under FrankenPHP;
- `$_SERVER['FRANKENPHP_WORKER']` is not set, so worker mode and Octane on FrankenPHP are skipped;
- `config('performance.early_hints')` is true.
`PHP_SAPI` is recorded in the bench output, not used in the guard.

**Entries and manifest (Q4).**
- **Entries:** `config('performance.early_hint_entries')`, default `['resources/css/app.css', 'resources/js/app.js']`, the same list the layout passes to `@vite`. A test asserts the two agree.
- **Links:** the middleware resolves the entries through the Vite manifest the same way `Vite::preloadedAssets()` would, entries plus their imported chunks and CSS.
- **No manifest:** with the Vite hot file present or no manifest (dev server, `withoutVite()`), it sends no hint and does nothing else.
- **URLs:** relative to the site root (`/build/assets/…`), as `@vite` renders them.

**Order and the database (Q5).** `ShowListingController::__invoke(int $id, …)` has no route-model binding, so no query runs before route middleware. `SendEarlyHints` is the first route middleware on `listings.show`. A 103 followed by a 404 for an unknown id is allowed: the browser simply does not use the preloads, and an unknown listing still answers 404.

**Toggling the hint for the bench (Q6).** `config/performance.php` is new. The bench sets `EARLY_HINTS=false` in `.env` and runs `config:clear`, using the shared bench `.env` backup and restore that SL-09 and SL-11 use, then restores both.

**The direct path (Q7).** Both nginx-fpm and frankenphp listen on 443 with TLS inside the web container (checked: 0.0.0.0:443). The direct client runs in a container on `ddev_default` and maps `ai-lab.ddev.site` to the web container's IP (`--resolve` for curl, `--host-resolver-rules` for Chromium), so SNI and Host are the real ones. Certificates are accepted with `-k` / `ignoreHTTPSErrors`; the bench is local.

**Choosing the LCP path (Q8).** `bench/lcp.mjs --path router|direct`. The matrix uses one path for all three variants:
- `router` if `early-hints.sh` shows the router forwards a 103;
- `direct` if not.
The choice and the reason are written into every result file.

**Detecting the early hint (Q9).**
- **Signal:** `PerformanceResourceTiming.initiatorType === 'early-hints'` on the CSS or JS entry (Chromium's resource-timing value for hinted fetches), not the navigation entry.
- **"Cold":** a new browser context per run, with no `Network.setCacheDisabled`, so hinted preloads can be reused. The HTTP cache is empty because the context is new.

**CPU measurement (Q10).**
- **Source:** cgroup v2 `cpu.stat` `usage_usec` of the web container (`ddev-interview-web`), read with `docker exec` before and after; not `docker stats`.
- **Repeats:** the export is downloaded 5 times per encoding, like everything else, and the median of the CPU deltas is reported.

**"At most once" (Q11).**
- **The rule:** with `identity` there is no `Content-Encoding`; otherwise exactly one coding value. `gzip, gzip`, or two `Content-Encoding` headers, is double compression.
- **Reference:** the decompressed body's sha256 must equal `exports.sha256` for `listings`.
- **nginx:** `text/csv` is not in DDEV's default `gzip_types`, so nginx may send the export uncompressed. That is recorded, not changed.

**The compression client (Q12).** It runs in a container on `ddev_default` through the router, like `bench/run.sh`, using curl over HTTP/2. Time to the last byte is curl's `time_total`. Debug routes for the export are turned on and back off through the shared `.env` handling.

**The six servers (Q13).** nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane and frankenphp-worker. Parity runs once with the default (hints on). Hints off is covered by the AC-2 test and by the LCP variant.

**Testing the bench scripts (Q14).**
- **Shell scripts:** PHPUnit tests in the existing `Bench*Test` style: they put stub `docker`, `curl` and `node` executables first on `PATH` and assert the arguments and the parsed output.
- **`lcp.mjs`:** it also gets a `--dry-run` that prints its plan, which the test asserts.
- **Docs:** `BenchmarksDocTest` covers the BENCHMARKS section and is added to the traceability table.

**Terms (Q15).**
- **BR-50:** the detail page announces CSS/JS with preload links; FrankenPHP sends a 103; elsewhere the response is identical and error-free.
- **BR-52:** FrankenPHP compresses with zstd, br or gzip by `Accept-Encoding`.
- **BR-26:** a measurement slice needs tests, a committed result table and an interview story with a measured number.
- **BR-27:** a guide section.
- **Doc subsections:** "What the numbers say" is the conclusions with numbers. "Interview story" is the problem, what was measured, and at least one measured number.
- **AC-1:** "any server" covers all six.

## Open questions

Clarity gate 2026-11-04: a fresh-context reviewer raised 15 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: The hint's `Link` headers are removed after the 103.
- [x] Q2: What "identical" means.
- [x] Q3: The guard skips worker and Octane modes.
- [x] Q4: Entries from config; manifest resolution; the dev and missing-manifest case.
- [x] Q5: No binding before middleware; a 103 then a 404 is allowed.
- [x] Q6: `.env` toggle through the shared handling; the new config file.
- [x] Q7: Direct TLS with resolve rules.
- [x] Q8: One path for all variants, chosen from the 103 check.
- [x] Q9: Hint detection and what "cold" means.
- [x] Q10: cgroup `cpu.stat`, 5 runs.
- [x] Q11: The double-compression rule and the sha256 reference.
- [x] Q12: The compression client and path.
- [x] Q13: The six servers.
- [x] Q14: Stubbed bench tests and `--dry-run`.
- [x] Q15: Terms.

## Dependencies

- **Requires**: SL-11 (export, debug-routes handling), SL-09 (bench conventions), SL-06.
- **Blocks**: SL-12 (HTTP/3 reuses the LCP script).

---
Next: `/sdd spec-20261104-2340-early-hints-compression` (approval → build)
