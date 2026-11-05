# Mercure live price — Tasks

**Spec**: spec-20261105-1709-mercure-live-price | **Size**: M | **Tasks**: 15

## Phase 1: Hub and backend

- [x] 1. Hub and keys (devops, backend)
  Covers: AC-3, NFR-1, MNC-3
  - [x] 1.1 `mercure` directive in the frankenphp stash Caddyfile `:80` block; `mercure:keys` command writing `.env` and `.ddev/.env.web`; `/.ddev/.env.web` gitignored; `.env.example` names
  - [x] 1.2 **Verify**: on frankenphp, `curl -N .../.well-known/mercure?topic=…` connects (200, `text/event-stream`); a test asserts the gitignore entry and that no key value appears in tracked files

- [x] 2. Tokens and publisher (backend)
  Covers: AC-3, AC-4, MNC-4, MNC-5
  - [x] 2.1 `config/mercure.php`, `MercureTokens` (HS256 sign/verify), `MercurePublisher` (POST, 2 s, report on failure)
  - [x] 2.2 **Verify**: `MercureTokensTest` (claims, expiry, signature), `MercurePublisherTest` with `Http::fake` (body, auth header, failure reported not thrown)

- [x] 3. Price change event and commands (backend)
  Covers: AC-1, AC-2, MNC-4
  - [x] 3.1 `ListingPriceChanged` after commit, listener publishing public (and private with `--private`), `listings:reprice`
  - [x] 3.2 **Verify**: feature tests (publish only on change, private flag, failure does not fail the command)

## Phase 2: Frontend

- [x] 4. Detail page subscription (frontend)
  Covers: AC-1, AC-2, AC-5, NFR-2, MNC-1, MNC-2
  - [x] 4.1 Meta hub + `data-price-topic`, `aria-live` region, `app.js` EventSource with give-up rule; `?watch=private` cookie behind the debug gate
  - [x] 4.2 **Verify**: build; browser check on frankenphp (price updates within 1 s; listing 2 unchanged) and nginx-fpm (one failed request, no retries); parity re-baselined once with the reason; fingerprint re-recorded

## Phase 3: Measure and document

- [x] 5. mercure-load.sh (bench)
  Covers: AC-6, AC-2
  - [x] 5.1 Pinned Node subscribers (no deps), probe, busy-thread sampling, 20 publishes, latency per subscriber, private checks, JSON
  - [x] 5.2 **Verify**: `BenchMercureLoadTest` with stubs; `--quick` run

- [ ] 6. Committed runs (measurement)
  Covers: AC-6
  - [ ] 6.1 N=100 and N=1000 on frankenphp; end on nginx-fpm
  - [ ] 6.2 **Verify**: result files present, private checks pass

- [x] 7. BENCHMARKS.md, guide 23, code map (docs)
  Covers: AC-6, AC-7
  - [x] 7.1 Section, chart with SL-09 contrast, three subsections; guide 23; section 16 pointer
  - [x] 7.2 **Verify**: `BenchmarksDocTest`, `LearningGuideTest` read numbers from the files

- [ ] 8. Checks
  Covers: MNC-1, MNC-2, MNC-3, MNC-5
  - [ ] 8.1 Full suite, Pint, PHPStan, typecheck, build; new fingerprint recorded; parity identical on 6 servers against the new baseline; `git diff HEAD -- .ddev/config.yaml` empty; key grep clean; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [ ] 8.2 **Verify**: outputs recorded here
    - Suite 1241 passed (13351 assertions); Pint passed; PHPStan no errors; `npm run typecheck` and `npm run build` clean.
    - Fingerprint `app-DNob7CFE.css` / `app-BbIOVcpD.js` matches the BENCHMARKS.md record. Parity re-captured on all 6 servers: only the timestamp line differs from `bench/parity/*.txt`; `compare nginx-fpm <server>` identical for the other 5.
    - `git diff HEAD -- .ddev/config.yaml` empty. The two Mercure key values appear in no tracked or untracked file outside `.env` and `.ddev/.env.web` (both gitignored, untracked).
    - Open: `bench/run.sh ping --quick` exits 2 ("git working tree has changes outside bench/results/ and .ai/session/"), the last run condition; every earlier condition passed. It needs the slice committed first, then a rerun.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | public update shown live | `manual-test.md#step-2` | manual | 3, 4 |
| AC-2 | private topic only to its token | `tests/Feature/BenchMercureLoadTest.php`, `manual-test.md#step-3` | test | 3, 5, 6 |
| AC-3 | token shapes and expiry | `tests/Feature/MercureTokensTest.php` | test | 1, 2 |
| AC-4 | publish failure reported, not thrown | `tests/Feature/MercurePublisherTest.php`, `manual-test.md#step-4` | test | 2 |
| AC-5 | no retry storm without a hub | `manual-test.md#step-4` | manual | 4 |
| AC-6 | load bench and BENCHMARKS | `tests/Feature/BenchmarksDocTest.php`, `manual-test.md#step-6` | test | 5, 6, 7 |
| AC-7 | guide section 23 | `tests/Feature/LearningGuideTest.php`, `manual-test.md#step-7` | test | 7 |
| MNC-1 | parity re-baselined once | `manual-test.md#step-5` | manual | 4, 8 |
| MNC-2 | fingerprint re-recorded | `manual-test.md#step-5` | manual | 4, 8 |
| MNC-3 | no keys committed, config.yaml untouched | `tests/Feature/MercureTokensTest.php`, `manual-test.md#step-5` | test | 1, 8 |
| MNC-4 | price change works without a hub | `tests/Feature/ListingRepriceTest.php` | test | 3 |
| MNC-5 | suite offline | `tests/Feature/MercurePublisherTest.php` | test | 2 |
| NFR-1 | key and cookie security | `tests/Feature/MercureTokensTest.php`, `manual-test.md#step-3` | test | 1, 4 |
| NFR-2 | polite live region | `manual-test.md#step-2` | manual | 4 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green

## Phase: Gaps (converge round 1)

- [ ] 9. Committed full-method Mercure runs replace the --quick files (measurement, docs)
  Covers: AC-6, AC-1, AC-2
  - Both committed files in `bench/results/mercure-load/2026-11-05/` have `"quick": true`, `"publishes": 5`, `"measure_s": 20` and `"commit": "cb01359"` from a dirty tree. Clarification Q12 says `--quick` output goes to `raw/` only, and Task 6 is still open. `BENCHMARKS.md` ("Method limits", "Interview story") and guide section 23 both quote these numbers with a quick-run caveat. After the slice is committed, run `bench/mercure-load.sh --subscribers 100 --label frankenphp` and `--subscribers 1000` with the full method (clean tree, restart, 90 s, 20 publishes) on frankenphp, end on nginx-fpm, and replace the quick files. Update both doc tables, the chart, the prose and the result paths in `BenchmarksDocTest` and `LearningGuideTest` if the date folder changes. Then drop the quick-run caveats.
  - [ ] 9.1 **Verify**: both result files have `"quick": false`, `"publishes": 20`, `private.passed` true, `delivered.percent` 100, zero health timeouts and `latency_ms.max` under 1000 (AC-1's "within 1 s" through the router). `ddev artisan test --compact tests/Feature/BenchmarksDocTest.php tests/Feature/LearningGuideTest.php` passes. `ddev server` reports nginx-fpm at the end.

- [ ] 10. Finish Task 8 on a clean tree (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-5
  - Task 8 is unticked. Its own 8.2 notes say `bench/run.sh ping --quick` exits 2 because the tree is dirty, so the last run condition was never met. Rerun it after the commit and record the output under 8.2, then tick Task 8.
  - [ ] 10.1 **Verify**: `bench/run.sh ping --quick` exits 0 on a clean tree. `git diff HEAD -- .ddev/config.yaml` is empty. The full suite, Pint, PHPStan, typecheck and build are green. Task 8 and 8.1/8.2 are ticked with the outputs recorded.

- [x] 11. Guide 23: results link, key delivery, and the section 16 request row (docs)
  Covers: AC-7
  - AC-7 says section 23 links the results. `docs/learning-guide.md` section 23 links `BENCHMARKS.md` but not `bench/results/mercure-load/…`. The keys paragraph says "The hub reads them from the environment" but never says why: Caddy reads process environment variables only, DDEV loads `.ddev/.env.web` into the web container's environment, and Laravel reads `.env`. Section 16 has only a "Live updates (SL-13)" paragraph. Its "Common feature requests and where they land" table has no "Notify the browser when something changes" row pointing to section 23. `LearningGuideTest` (AC-7 test) checks for none of these. Add the results link, the two-path key explanation and the table row, and extend the test.
  - [x] 11.1 **Verify**: the AC-7 test in `tests/Feature/LearningGuideTest.php` also asserts `](../bench/results/mercure-load/` and `.ddev/.env.web` in section 23, plus a section 16 table row containing "Notify the browser when something changes" and "section 23". `ddev artisan test --compact tests/Feature/LearningGuideTest.php` passes.

- [x] 12. Follow the binding Q9 connect timeout and the Q11 shared formatter (backend)
  Covers: AC-4, AC-1
  - Q9 sets a 2 s total timeout with 1 s to connect. `app/Support/MercurePublisher.php` passes `config('mercure.timeout')` (2) to both `timeout()` and `connectTimeout()`. Q11 says `formatted` comes from the same helper that renders the price in the Blade view. `resources/views/listings/show.blade.php` still inlines `number_format(...)` and does not call `App\Support\PriceFormatter::format`, so the live text and a reload can drift apart. Add a `connect_timeout` (1) to `config/mercure.php` and use it. Render the Blade price with `PriceFormatter::format` so the markup bytes stay the same.
  - [x] 12.1 **Verify**: a `MercurePublisherTest` case asserts the configured connect timeout is 1 and the total timeout is 2. A `ListingLivePriceTest` case asserts the rendered price text equals `PriceFormatter::format($cents, $currency)`. The `/listings/1` parity hash is unchanged (`bench/parity.sh compare` prints `identical` against `bench/parity/nginx-fpm.txt`). `ddev artisan test --compact` is green.

## Phase: Gaps (converge round 2)

- [x] 13. Run manual-test step 3: the private update in a browser through the cookie (frontend, devops)
  Covers: AC-2, NFR-1
  - The browser path of the private variant was never run live. Task 4's summary records only the public check (listing 1 updated, listing 2 unchanged) and the nginx-fpm check. `bench/mercure-load.sh` proves the hub's authorization with an `Authorization: Bearer` header (`mercure-subscribers.mjs`), not with the `mercureAuthorization` cookie that `ShowListingController` sets and `resources/js/app.js` relies on (`withCredentials: Boolean(privateTopic)`). `ListingLivePriceTest` checks the cookie attributes in-process only, so nothing shows that the cookie (path `/.well-known/mercure`, SameSite=Strict, Secure) actually reaches the hub through the DDEV router and that the page then updates. The Traceability table names `manual-test.md#step-3` as NFR-1's live check, and no output of it is recorded. Run step 3 on frankenphp with `DEBUG_ROUTES=true` in the Playwright container used for Task 4, then restore `.env`, listing prices and nginx-fpm.
  - [x] 13.1 **Verify**: on frankenphp, `GET /listings/1?watch=private` through the router returns a `Set-Cookie: mercureAuthorization=…; path=/.well-known/mercure; secure; httponly; samesite=strict`. The browser's hub request carries that cookie. After `listings:reprice 1 <price> --private`, the page shows the new price. After `listings:reprice 2 <price> --private`, the listing 1 tab does not change. The outputs are recorded under 13.1. `.env` has no `DEBUG_ROUTES=true` at the end, both prices are restored with `--restore`, and `ddev server` reports nginx-fpm.
  - Recorded outputs (review fix pass, 2026-11-05, frankenphp, `DEBUG_ROUTES=true` in `.env` for the run, removed afterwards): `GET /listings/1?watch=private` returned `Set-Cookie: mercureAuthorization=<jwt>; Max-Age=3600; path=/.well-known/mercure; secure; httponly; samesite=strict`. The browser kept the cookie (HttpOnly, Secure, SameSite Strict, path `/.well-known/mercure`). The hub request carried both topics plus `lastEventID=earliest`. The private bench checks (`authorized=1 wrong-token=0 no-token=0`) passed on the new transport. The acceptance run earlier showed the page updating to 189.90 EUR after `--private` for listing 1 and no change after listing 2's. Prices restored with `--restore`, `.env` byte-identical to its backup, nginx-fpm active.

## Phase: Review fix pass

Source: the ship report `.ai/reports/ship-spec-20261105-1709-mercure-live-price-20261105-1942.md`. Each finding was verified before it was fixed. Open: tasks 6, 9 and 10 need a commit first (see 15).

- [x] 14. Review findings fixed (backend, frontend, devops, docs)
  - [x] 14.1 Lost updates: the hub uses `transport bolt { path /tmp/mercure.db  size 100 }` (a `transport_url` line was tried first and is silently ignored by the Caddyfile parser, so the block form is required); every publish carries an `id` (`urn:uuid:…`); the page renders `data-price-rendered-ms` (read before the listing is loaded), `app.js` subscribes with `lastEventID=earliest` and applies a message only through `shouldApply` in `resources/js/price-updates.js` (not older than render, not older than the last applied; equal applies). `bench/parity.sh` masks the attribute. Live (Playwright container, frankenphp): hub request delayed 4 s, `listings:reprice 1 7100000` run after the page rendered and before the EventSource connected: price 70,000.00 -> 71,000.00 EUR after 4010 ms, live region "Price updated to 71,000.00 EUR". A history update older than the render (7,000,000, published before the load) left the live region empty and the price unchanged.
  - [x] 14.2 Stale cookie: a plain detail page (and `?watch=private` without the gate or keys) sends an expired `mercureAuthorization` Set-Cookie with the same path and flags; the private page's Max-Age is exp minus now from one `time()` read (live: `Max-Age=3600`, plain page next: `Max-Age=0`, browser cookie list empty). Tests assert both headers. Guide explains same-origin EventSource sends cookies whatever `withCredentials` says.
  - [x] 14.3 `MercureTokens` reads the clock once; test asserts exp - iat is exactly 60 and 3600 over 300 tokens.
  - [x] 14.4 Layering: model hook, `ListingPriceChanged` and `PublishListingPrice` removed. `ListingPriceService::reprice(id, cents, private)` updates through the repository (one DTO) and publishes public (+ private) after the commit, returning `ListingRepriceResult` (public/private published flags, null = not attempted). The command warns on a failed public or private publish (live on nginx-fpm: both warnings shown, exit 0). Synchronous on purpose; the docblock and guide say a web path should queue the publish.
  - [x] 14.5 `MercurePublisher` encodes inside the try and catches `Throwable`; test with non-UTF-8 data (reported, false, nothing sent).
  - [x] 14.6 `mercure:keys` writes 0600 (temp file created 0600 in the same directory, renamed over the target); test checks the mode with umask 0 and a pre-existing 0644 file.
  - [x] 14.7 `mercure:token` has no `--private` option; description says it issues only private-topic tokens; `bench/mercure-load.sh` and its test updated.
  - [x] 14.8 Caddy metrics: `frankenphp_busy_threads` is absent without `servers { metrics }` (sampler read `na na`), and an env toggle would need `.ddev/.env.web` edits plus a restart around every run. Decision: keep `metrics`, recorded in BENCHMARKS.md as a condition difference for frankenphp cells from SL-13 on.
  - [x] 14.9 The extra hub request on servers without a hub is kept for parity and its cost is documented in BENCHMARKS.md and guide 23.
  - [x] 14.11 BENCHMARKS.md busy-thread wording fixed (`listings:reprice` is a CLI process outside the pool; the only PHP-thread work is `/healthz`) and the latency-tail causes reworded; guide section 23 and the section 16 row updated; bolt quick re-checks recorded.
  - [x] 14.12 **Verify**: full suite, Pint, PHPStan, typecheck and build green; parity re-captured on all 6 servers (reason: the page gained `data-price-rendered-ms`, masked in the hash) and `compare nginx-fpm <server>` is `identical` for the other five; fingerprint `app-DNob7CFE.css` unchanged, JS now `app-PTzu1QaN.js` (`7fbc38601b4f59a1c78dcec8cb4ceb16cf7a64abf4dfafff60069c5f97735256`); `git diff HEAD -- .ddev/config.yaml` empty; `git grep -F` for both key values finds nothing; `ddev server` reports nginx-fpm.

- [ ] 15. Full-method runs on a clean tree (tasks 6, 9, 10 stay open)
  - `bench/mercure-load.sh` refuses a dirty tree (`git working tree has changes outside bench/results/ and .ai/session/`), and the whole slice is uncommitted, so the N=100 and N=1000 full runs and `bench/run.sh ping --quick` were not run. After the commit: `bench/mercure-load.sh --subscribers 100 --label frankenphp` and `--subscribers 1000 --label frankenphp`, replace the `--quick` files in `bench/results/mercure-load/2026-11-05/` (move the old ones to `bench/results/raw/`), update the BENCHMARKS.md and guide tables and the "quick" caveats, then `bench/run.sh ping --quick`.

