# Ship report: spec-20261104-2340-early-hints-compression

## Summary

This slice adds preload `Link` headers to `GET /listings/1` and a 103 Early Hint on classic FrankenPHP (`SendEarlyHints` middleware, the `EarlyHintSender` contract, `PhpEarlyHintSender`, `config/performance.php`). It also adds three bench scripts (`bench/early-hints.sh`, `bench/lcp.mjs`, `bench/compression.sh`) with committed results, a BENCHMARKS section and guide section 21.

All four quality gates pass: 1114 tests, Pint, PHPStan and the debug scan. The build is still partial: 14 of 18 tasks are done. Task 9 is incomplete and converge gaps 16 to 18 were never built. The acceptance trace is unresolved because frankenphp-octane still sends a 103 on every request live, which breaks AC-2 and Q3.

**Recommendation: FIX-FIRST.**

## Stack & repos

| Repo | Role | Stack | Tracked | Notes |
|---|---|---|---|---|
| `.` | backend | laravel | yes | **WIP:** the whole slice is uncommitted work on `master` (default branch is `main`). Branch before opening a PR. |

- Repo warnings: none.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `sdd-kit-laravel`, `sdd-kit-drupal`. Discovery error: `trustStore unreadable`.

## Spec

- Id: `spec-20261104-2340-early-hints-compression`
- Folder: `.ai/specs/spec-20261104-2340-early-hints-compression`

## Investigation highlights

The investigation step returned no key files, risks or prior implementation. Its output was empty. What we know comes from the build and acceptance:

- Key files:
  - `app/Http/Middleware/SendEarlyHints.php`
  - `app/Support/PhpEarlyHintSender.php`
  - `app/Contracts/EarlyHintSender.php`
  - `app/Providers/AppServiceProvider.php` (binding)
  - `routes/catalog.php` (middleware on `listings.show`)
  - `config/performance.php`
  - `bench/{early-hints.sh,lcp.mjs,compression.sh,parity.sh}`
- Already shipped and reused: SL-11 export offload. Its export sha `74366ebf...` and 0 PHP bytes are unchanged. The parity snapshots and the asset fingerprint (`app-CjRfEL-0.css`, `app-BvRk9kiK.js`) are also unchanged.
- Risks found later:
  - The FrankenPHP worker and Octane marker is missing from the per-request `$_SERVER`.
  - The page's own JS bundle is 0 bytes.
  - CDP throttling does not delay the interim 103.

## Tasks completed

- 14 of 18 complete.
- **Incomplete: 9.** Item 9.2 is unticked: its live check on octane and worker was never recorded.
- Also unticked: 16, 17 and 18 (converge gaps, see below), plus the "Done when" items (all tasks checked, traceability, gates).

## Converge

- Rounds run: 2.
- Round 1 appended gap tasks 9 to 15:
  - 9: worker/Octane guard
  - 10: Vite resolution, dev mode and missing manifest
  - 11: identical final headers on frankenphp
  - 12: LCP throttle and TTFB
  - 13: compression.sh per Q10 to Q12
  - 14: live Link check on six servers
  - 15: task 3 verification record
- Round 2 appended gap tasks 16 to 18.
- **Gaps left unbuilt:**
  - 16. Make the worker/Octane guard hold in a live request, not only in the constructor seam
  - 17. Label the compression "js" row as the substitute bundle it is
  - 18. Link the server compression configs from guide section 21

## Quality gates

| Repo | Gate | Status | Details |
|---|---|---|---|
| `.` | Pint | pass | 412 files (ddev exec) |
| `.` | PHPStan | pass | No errors (ddev exec) |
| `.` | PHPUnit | pass | 1114 passed, 9334 assertions (ddev exec) |
| `.` | Debug scan | pass | No `dd(`/`dump(`/`ray(` in app/ routes/ database/ |

No gate was unreachable. Contract-sync does not apply: this is a single repo.

## Visual tests

None ran. The slice changes no UI, and the LCP evidence is the committed Playwright results in `bench/results/lcp/2026-11-05/`.

## Acceptance

**The original scenario is not resolved end-to-end.**

The main journey works on nginx-fpm and classic frankenphp:
- The preload `Link` headers match the HTML.
- The 103 arrives through the router.
- Bodies are byte-identical (`98e943eb...`).
- `Accept-Encoding` negotiation is correct.

Broken hops:

| Check | Result | Why |
|---|---|---|
| Live frankenphp-octane, `curl -v --http2 /listings/1` | **FAIL** | 3 of 3 probes returned a 103 before the 200. `PhpEarlyHintSender::isAvailable()` checks only `$_SERVER['FRANKENPHP_WORKER']`, which is missing from Octane's per-request `$_SERVER`. |
| AC-2 | **FAIL** | Same cause: a 103 is sent on a server other than classic frankenphp. |
| AC-5 | **FAIL** | The "js" compression row measures `/build/assets/app-BFvfizUE.js` (the `resources/js/ai/app.ts` lab bundle), not the page's 0-byte `app-BvRk9kiK.js`. The docs do not say so. |
| AC-7 | **FAIL** | Guide section 21 links no server compression config (Caddyfiles, `nginx-site.conf`). |

Passing:
- AC-1, AC-3, AC-4 and AC-6
- MNC-1 to MNC-4
- NFR-1 and NFR-2
- 130 slice tests

Known accepted deviation: with hints on, the final 200 on frankenphp lacks `Server: FrankenPHP Caddy`. This is recorded in Clarifications and in the 2026-11-05 diff.

Live coverage:
- Checked live just now: nginx-fpm, frankenphp, frankenphp-octane and frankenphp-worker.
- apache-fpm and openlitespeed rely on the run recorded in task 14.2 and on the parity snapshots.
- The server was put back on nginx-fpm, and `.env` is clean.

E2E regression test: none was written. This is a backend-only stack with no e2e config, so no AC ids are traced to an e2e test, and there is no pass or fail result to report.

## Confirmed review findings

### Major

- `app/Support/PhpEarlyHintSender.php:29`: the worker/Octane guard reads only `$_SERVER`, so Octane still sends a 103.
  - Fix: also check `getenv('FRANKENPHP_WORKER')`, `getenv('LARAVEL_OCTANE')` and `$_ENV` behind the seam.
  - Then add tests where the env carries the marker but `$_SERVER` does not, and re-run `bench/early-hints.sh` on octane and worker.
- `app/Http/Middleware/SendEarlyHints.php:62-67`: the middleware has its own manifest walker, which differs from Vite.
  - It drops the entry chunk's own `css`.
  - It ignores `createAssetPathsUsing` and the integrity attributes.
  - It reads a second build-directory config.
  - It re-reads `manifest.json` on every request.
  - Fix: call `app(Vite::class)(config('performance.early_hint_entries'))` and hint `Vite::preloadedAssets()`. Also add a fixture case with entry-level `css`.
- `BENCHMARKS.md:787` and `bench/lcp.mjs:95`: the FCP gain (452 -> 201 ms) and LCP gain are likely an artifact of CDP `emulateNetworkConditions`, which does not delay the 103 or the hinted fetches.
  - Fix: re-measure with packet-level shaping (tc netem or toxiproxy), or add real server think time.
  - Until then, label the deltas as an emulation artifact in BENCHMARKS, the Interview story and guide 21 (around line 979).
- `bench/compression.sh:107` and `bench/lcp.mjs`: the scripts do not back up, toggle and restore `.env` as Q6 and Q12 require.
  - On a default `.env` the offload export route returns non-200, and `--variant frankenphp-no-hints` is only a label.
  - Fix: reuse the sibling scripts' `.env` handling for `EARLY_HINTS` and `DEBUG_ROUTES`, and record the config state in the results.

### Minor

- `bench/compression.sh:103`: the "js" row is the AI lab bundle, not the page's script.
  - Fix: name `app-BFvfizUE.js` / `resources/js/ai/app.ts` in BENCHMARKS and guide 21, or relabel the row (task 17).
- `bench/early-hints.sh:54`: the direct probe uses the wrong SNI and Host, `--add-host` has no effect, and the nginx direct trace fell back to HTTP/1.1.
  - Fix: use `--resolve $HOST_NAME:443:<web ip>` with the real URL.
- `bench/lcp.mjs:208`: a missing FCP becomes 0 ms, and an LCP of 0 is kept, which pulls the min and median down.
  - Fix: reject or flag null and 0 samples.
- `bench/compression.sh:124` and `:133`: the export is downloaded twice per encoding.
  - The awk median takes the lower-middle value, jq takes the upper-middle, and `lcp.mjs` averages the two, so even repeat counts disagree.
  - Fix: use one median helper and one download loop.
- `bench/results/early-hints/2026-11-04/frankenphp-direct.trace:29` (and the other 3 traces): they contain the mkcert subject and issuer (username, hostname, real name) and internal docker IPs.
  - Fix: keep only the `< ` and `> ` header lines when writing traces, and scrub the files before the first commit.
- `bench/compression.sh:63`: there is no "refusing to run" guard when the image digest is empty, and both curl scripts run the image by tag.
  - Fix: fail on an empty digest and run `image@sha256:...`. Pin the `playwright-core` install in `lcp.mjs:176`.

## Documentation updated

The docs stage reported nothing. The build tasks updated:
- `BENCHMARKS.md` (section "Early Hints and compression (2026-11-05)")
- `docs/learning-guide.md` (section 21)

Both still need tasks 17 and 18 and the FCP caveat.

## Manual test plan

- Written to: `/Users/stasmarkov/work/interview/.ai/specs/spec-20261104-2340-early-hints-compression/manual-test.md`
- How to run: with DDEV started on nginx-fpm, follow steps 1 to 15. Use `curl -sI` / `curl -v --http2` on `https://interview.ddev.site/listings/1` per server, and run `bench/early-hints.sh` and `bench/compression.sh`. Roll back with `ddev server nginx-fpm`, remove the `EARLY_HINTS`/`DEBUG_ROUTES` lines from `.env`, then `ddev artisan config:clear`.

## Recommendation

**FIX-FIRST.**

- The build is partial (task 9 is incomplete).
- Converge gaps 16 to 18 are unbuilt.
- The acceptance trace is unresolved: AC-2 fails live on frankenphp-octane, and AC-5 and AC-7 are short.
- There are four major review findings.
- The slice is uncommitted WIP on `master`.

Next: fix `app/Support/PhpEarlyHintSender.php:29` so the guard also reads `getenv('FRANKENPHP_WORKER')` / `getenv('LARAVEL_OCTANE')` behind the seam (task 16). Add the env-only tests, then confirm live that frankenphp-octane and frankenphp-worker send no 103 while classic frankenphp still does.
