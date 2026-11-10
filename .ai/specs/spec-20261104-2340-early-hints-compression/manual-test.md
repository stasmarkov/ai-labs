# Manual test plan: Early Hints and compression (SL-10)

The problem this slice addresses: the listing detail page made the browser wait for the whole HTML before it started fetching the CSS and JS, and nobody had measured what compression costs or saves on each server. After the change the page announces its assets early (a `103` on classic FrankenPHP, `Link` headers everywhere), and the benchmarks show the effect.

## Before you start

- DDEV is running: `cd /Users/stasmarkov/work/interview && ddev start`.
- The site answers at https://ai-lab.ddev.site and listing 1 exists (it does in the seeded database).
- Start on the default server: `ddev server default`, then `ddev server --current` prints the name `ddev server --default` prints.
- Tools used: `curl` 8.x with HTTP/2 on the host (`curl --version` lists `HTTP2`), Docker running (the bench scripts use pinned containers), `jq`.
- Steps 3, 4, 5, 7 and 8 switch servers and edit `.env`. Rollback for all of them is in the last section.
- Known open item: on `frankenphp-octane` a `103` is still sent (converge tasks 9.2 and 16 are open). Step 4 expects no `103` there, which is what the spec requires. If you see one, record it as a failure of AC-2, not a mistake in the test.

## Step 1

Preload links on nginx-fpm (AC-1, happy path).

```sh
ddev server nginx-fpm
curl -sI https://ai-lab.ddev.site/listings/1 | grep -iE '^(HTTP|link)'
curl -s https://ai-lab.ddev.site/listings/1 | grep -oE '/build/assets/app-[A-Za-z0-9_-]+\.(css|js)' | sort -u
```

Expected:
- `HTTP/2 200`.
- A `link:` header with two values: `<https://ai-lab.ddev.site/build/assets/app-CjRfEL-0.css>; rel="preload"; as="style"` and `<https://ai-lab.ddev.site/build/assets/app-BvRk9kiK.js>; rel="modulepreload"; as="script"`.
- The third command prints the same two file names (`app-CjRfEL-0.css`, `app-BvRk9kiK.js`).

## Step 2

Same check on every server (AC-1).

```sh
for s in nginx-fpm apache-fpm frankenphp frankenphp-octane frankenphp-worker openlitespeed; do
  ddev server $s
  echo "== $s"
  curl -skI "$(ddev server --url)/listings/1" | grep -iE '^(HTTP|link)'
done
ddev server default
```

Expected: for each of the six servers, status `200` and the same two preload values as in step 1 (openlitespeed is served on its own port, `ddev server --url` supplies it; the host in the URLs may differ, the file names must not). No server shows a different fingerprint.

## Step 3

Early Hints on classic FrankenPHP, and the guard when turned off (AC-2, the reported case).

```sh
ddev server frankenphp
curl -sk -o /dev/null -v --http2 https://ai-lab.ddev.site/listings/1 2>&1 | grep -E '^< (HTTP|link)'
```

Expected:
- `< HTTP/2 103`, followed by one or more `< link:` lines (CSS and JS values as in step 1).
- Then `< HTTP/2 200` with the `link:` values once (the final response may show them as one combined header).

Now turn the hints off:

```sh
cp .env /tmp/interview.env.bak
grep -q '^EARLY_HINTS=' .env && sed -i.bak 's/^EARLY_HINTS=.*/EARLY_HINTS=false/' .env || echo 'EARLY_HINTS=false' >> .env
ddev artisan config:clear
curl -sk -o /dev/null -v --http2 https://ai-lab.ddev.site/listings/1 2>&1 | grep -E '^< (HTTP|link)'
```

Expected: no `103` line. Only `< HTTP/2 200` and the `link:` header. Known and accepted difference: with hints on, the final 200 lacks the `Server: FrankenPHP Caddy` header (see `bench/results/early-hints/2026-11-05/frankenphp-final-headers.diff`); apart from that and `Date` the headers are the same.

Restore: `mv /tmp/interview.env.bak .env && ddev artisan config:clear`.

## Step 4

No 103 on other servers (AC-2).

```sh
for s in nginx-fpm apache-fpm frankenphp-worker frankenphp-octane; do
  ddev server $s
  echo "== $s"
  curl -sk -o /dev/null -v --http2 https://ai-lab.ddev.site/listings/1 2>&1 | grep -cE '^< HTTP/2 103'
done
ddev server default
```

Expected: `0` for every server. Today `frankenphp-octane` prints a non-zero count (open defect, see "Before you start"); the other three print `0`.

Also run the automated guard test, which covers the missing-`headers_send` case: `ddev artisan test --compact tests/Feature/EarlyHintsTest.php` passes.

## Step 5

The 103 path, recorded by the script (AC-3).

```sh
ddev server frankenphp && bench/early-hints.sh
ddev server nginx-fpm && bench/early-hints.sh
ls bench/results/early-hints/$(date -u +%Y-%m-%d)/
cat bench/results/early-hints/$(date -u +%Y-%m-%d)/frankenphp.json | jq '{server, router_103, direct_103}'
```

Expected:
- Each run exits 0 and prints its summary.
- The folder holds `frankenphp-router.trace`, `frankenphp-direct.trace`, `frankenphp.json`, `nginx-fpm-router.trace`, `nginx-fpm-direct.trace`, `nginx-fpm.json`.
- frankenphp: `router_103: true`, `direct_103: true` (matches the committed run in `bench/results/early-hints/2026-11-04/`). nginx-fpm: both `false`.
- `grep ' 103' bench/results/early-hints/*/frankenphp-direct.trace` shows `< HTTP/2 103`.

## Step 6

LCP, FCP and TTFB (AC-4). The full run takes several minutes.

```sh
node bench/lcp.mjs --help
node bench/lcp.mjs --variant nginx-fpm --path router --path-reason "baseline without hints" --dry-run
jq '{variant, server, path, path_reason, runs, cold, lcp_ms, fcp_ms, ttfb_ms, early_hint_used}' bench/results/lcp/2026-11-05/*.json
```

Expected:
- `--help` and `--dry-run` print the usage and plan, nothing is requested.
- The three committed files (`frankenphp-hints`, `frankenphp-no-hints`, `nginx-fpm`) each have `runs: 5`, `cold: true`, and `median`, `min`, `max` for `lcp_ms`, `fcp_ms`, `ttfb_ms` (TTFB median at or above 150).
- `early_hint_used` is `true` only in `frankenphp-hints.json`; `path` is `router`, with a `path_reason`.
- Optional live rerun: `ddev server frankenphp && node bench/lcp.mjs --variant frankenphp-hints --path router --path-reason "router forwards the 103"` writes `bench/results/lcp/<today>/frankenphp-hints.json` with the same fields.

## Step 7

Content negotiation for compression (AC-5).

```sh
CSS=/build/assets/app-CjRfEL-0.css
for s in frankenphp nginx-fpm; do
  ddev server $s; echo "== $s"
  for e in identity gzip br zstd 'zstd, br, gzip'; do
    curl -sk -o /dev/null -H "Accept-Encoding: $e" -w "$e -> %{size_download} bytes, encoding=%header{content-encoding}\n" https://ai-lab.ddev.site$CSS
  done
done
ddev server default
```

Expected:
- frankenphp: `identity` has no encoding (empty), `gzip` gives `gzip`, `br` gives `br`, `zstd` gives `zstd`, and `zstd, br, gzip` gives `zstd`. Compressed byte counts are smaller than identity.
- nginx-fpm: `gzip` gives `gzip`, `identity` has no encoding. (`br`/`zstd` requests fall back to gzip or nothing, never `br` or `zstd`.)

Then the recorded numbers: `ddev server frankenphp && bench/compression.sh`, and `jq '.resources[] | {resource, accept_encoding, content_encoding, wire_bytes, seconds_median}' bench/results/compression/$(date -u +%Y-%m-%d)/frankenphp.json | head -60` shows rows for `html`, `css`, `js` and `export` at each of the five encodings. Note: the `js` row measures the largest built bundle (`app-BFvfizUE.js`), because the detail page's own `app-BvRk9kiK.js` is 0 bytes. This is not yet stated in BENCHMARKS.md (open task 17). Run `ddev server nginx-fpm && bench/compression.sh` too; the committed examples are in `bench/results/compression/2026-11-05/`. The script needs the export file `storage/app/exports/listings.csv` (52428832 bytes); if it stops with "Cannot read the exports record", run `ddev artisan exports:generate`.

## Step 8

Export is compressed at most once (AC-6).

```sh
jq '.export | {ok, expected_sha256, expected_bytes, rows: [.rows[] | {accept_encoding, content_encoding, wire_bytes, cpu_seconds, single_encoding, sha_ok}]}' bench/results/compression/2026-11-05/frankenphp.json
jq '.export.rows[] | {accept_encoding, single_encoding, sha_ok}' bench/results/compression/2026-11-05/nginx-fpm.json
```

Expected:
- `expected_sha256` is `74366ebfa0f6812c1eafd4c3c09ecfd5434e0aa762e7c24f8a8b324a8d87fa11`, `expected_bytes` is `52428832`.
- Every row has `single_encoding: true` and `sha_ok: true`; `content_encoding` is one value (or empty for identity). On frankenphp the CPU seconds rise from about 0.14 (identity) to about 0.29 (gzip) and 0.76 (br).
- The failure path: `ddev artisan test --compact tests/Feature/BenchCompressionTest.php` passes, which shows the script exits non-zero when an encoding is doubled or the hash differs.

## Step 9

Documentation (AC-7).

```sh
grep -n '^## Early Hints and compression' BENCHMARKS.md
sed -n '/^## Early Hints and compression/,/^## [^E]/p' BENCHMARKS.md | grep -nE '^(###|```mermaid|\|)' | head -40
grep -n '^## 21\. Early Hints and compression' docs/learning-guide.md
```

Expected:
- One heading `## Early Hints and compression (2026-11-05)` in BENCHMARKS.md, with tables for the 103 path, LCP and compression, one `mermaid` chart (LCP per variant), and the subsections `What the numbers say`, `Surprises`, `Interview story`.
- `## 21. Early Hints and compression` in the guide. Read it and check it explains: what a 103 is and why only HTTP/2+ browsers use it; why it is sent before the slow work; why proxies may drop it; preload vs modulepreload; how `Accept-Encoding` negotiation works; the CPU versus bytes trade-off with the measured numbers; why compressing an offloaded large file can cost more than it saves.
- It links to code (`app/Http/Middleware/SendEarlyHints.php`), scripts (`bench/*.sh`, `bench/lcp.mjs`), results and official docs. Known gaps: the Caddyfiles and nginx-site.conf are not linked (open task 18).
- `ddev artisan test --compact tests/Feature/BenchmarksDocTest.php tests/Feature/LearningGuideTest.php` passes.

## Step 10

HTML unchanged on all servers, hints on or off (MNC-1).

```sh
git status --short bench/parity/
for s in apache-fpm frankenphp frankenphp-octane frankenphp-worker openlitespeed; do bench/parity.sh compare nginx-fpm $s; done
```

Then, as a live cross-check, on each of nginx-fpm, frankenphp, frankenphp-octane, frankenphp-worker:

```sh
ddev server frankenphp
curl -sk https://ai-lab.ddev.site/listings/1 | shasum -a 256
```

Expected:
- The snapshot folder has no changes (nothing to re-baseline), and each compare line says `identical`.
- The hash is `98e943eb...` (the same first characters on every server, and with `EARLY_HINTS=false` too).
- Finish with `ddev server default`.

## Step 11

Asset fingerprint unchanged (MNC-2).

```sh
bench/asset-fingerprint.sh
git diff --stat HEAD -- resources vite.config.js package.json
```

Expected: the script prints `app-CjRfEL-0.css` and `app-BvRk9kiK.js` with their SHA-256; the git diff is empty.

## Step 12

Export bytes and offload unchanged (MNC-3).

```sh
ddev server nginx-fpm
curl -sk -o /tmp/listings.csv 'https://ai-lab.ddev.site/exports/listings?mode=offload' -H 'Accept-Encoding: identity' -w '%{http_code} %{size_download}\n'
shasum -a 256 /tmp/listings.csv
jq '.php_bytes_sent_by_php_total' bench/results/export-load/2026-11-04/offload-nginx-fpm.json
```

Expected: `200 52428832`, SHA-256 `74366ebfa0f6812c1eafd4c3c09ecfd5434e0aa762e7c24f8a8b324a8d87fa11`, and `0` for the PHP bytes in the SL-11 offload result (`ddev artisan test --compact tests/Feature/ServeExportTest.php` also passes). If the endpoint answers 429, wait a minute (rate limit) and retry. Remove the file after: `rm /tmp/listings.csv`.

## Step 13

DDEV and server configs untouched (MNC-4).

```sh
git diff --stat HEAD -- .ddev/config.yaml
git status --short .ddev
grep -rn 'encode' .ddev/ | grep -iE 'zstd|gzip'
```

Expected: the first two print nothing; the third still shows `encode zstd br gzip` in the FrankenPHP Caddyfiles and the gzip settings in the nginx site config.

## Step 14

Reproducibility of the LCP runs (NFR-1).

```sh
jq '{browser_image_digest, throttle, cold, runs}' bench/results/lcp/2026-11-05/*.json
jq '.probe_image_digest' bench/results/early-hints/2026-11-04/*.json
```

Expected: every LCP file has a `browser_image_digest` starting with `mcr.microsoft.com/playwright@sha256:`, `throttle` of `rtt_ms 150`, `down_kbps 1600`, `up_kbps 750`, `cpu_slowdown 4`, `cold: true`, `runs: 5`. The probe digest is a `curlimages/curl@sha256:...` value. `ddev artisan test --compact tests/Feature/BenchLcpTest.php` passes.

## Step 15

The hint code never breaks a request (NFR-2).

```sh
ddev artisan test --compact tests/Feature/EarlyHintsTest.php tests/Feature/PreloadLinksTest.php
: > storage/logs/laravel.log
for s in nginx-fpm frankenphp; do ddev server $s; for i in 1 2 3; do curl -sk -o /dev/null -w "$s %{http_code}\n" https://ai-lab.ddev.site/listings/1; done; done
ddev server default
grep -ci 'early\|headers_send' storage/logs/laravel.log
```

Expected: tests pass; six lines ending in `200`; the final count is `0` (no exception was reported by the hint middleware on either server).

## Cleanup and rollback

```sh
ddev server default
ddev server --current                     # frankenphp, the name `ddev server --default` prints
grep -nE '^(EARLY_HINTS|DEBUG_ROUTES)' .env   # prints nothing; if EARLY_HINTS is there, delete the line
ddev artisan config:clear
git status --short .ddev bench/parity     # empty
```

To switch the feature off without touching code, set `EARLY_HINTS=false` in `.env` and run `ddev artisan config:clear`; the `Link` preload headers stay, only the `103` goes away. Result files written by your own reruns live in dated folders under `bench/results/`; delete the ones you do not want to commit.
