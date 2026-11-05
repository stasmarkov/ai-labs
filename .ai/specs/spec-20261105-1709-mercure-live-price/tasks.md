# Mercure live price — Tasks

**Spec**: spec-20261105-1709-mercure-live-price | **Size**: M | **Tasks**: 8

## Phase 1: Hub and backend

- [ ] 1. Hub and keys (devops, backend)
  Covers: AC-3, NFR-1, MNC-3
  - [ ] 1.1 `mercure` directive in the frankenphp stash Caddyfile `:80` block; `mercure:keys` command writing `.env` and `.ddev/.env.web`; `/.ddev/.env.web` gitignored; `.env.example` names
  - [ ] 1.2 **Verify**: on frankenphp, `curl -N .../.well-known/mercure?topic=…` connects (200, `text/event-stream`); a test asserts the gitignore entry and that no key value appears in tracked files

- [ ] 2. Tokens and publisher (backend)
  Covers: AC-3, AC-4, MNC-4, MNC-5
  - [ ] 2.1 `config/mercure.php`, `MercureTokens` (HS256 sign/verify), `MercurePublisher` (POST, 2 s, report on failure)
  - [ ] 2.2 **Verify**: `MercureTokensTest` (claims, expiry, signature), `MercurePublisherTest` with `Http::fake` (body, auth header, failure reported not thrown)

- [ ] 3. Price change event and commands (backend)
  Covers: AC-1, AC-2, MNC-4
  - [ ] 3.1 `ListingPriceChanged` after commit, listener publishing public (and private with `--private`), `listings:reprice`
  - [ ] 3.2 **Verify**: feature tests (publish only on change, private flag, failure does not fail the command)

## Phase 2: Frontend

- [ ] 4. Detail page subscription (frontend)
  Covers: AC-1, AC-2, AC-5, NFR-2, MNC-1, MNC-2
  - [ ] 4.1 Meta hub + `data-price-topic`, `aria-live` region, `app.js` EventSource with give-up rule; `?watch=private` cookie behind the debug gate
  - [ ] 4.2 **Verify**: build; browser check on frankenphp (price updates within 1 s; listing 2 unchanged) and nginx-fpm (one failed request, no retries); parity re-baselined once with the reason; fingerprint re-recorded

## Phase 3: Measure and document

- [ ] 5. mercure-load.sh (bench)
  Covers: AC-6, AC-2
  - [ ] 5.1 Pinned Node subscribers (no deps), probe, busy-thread sampling, 20 publishes, latency per subscriber, private checks, JSON
  - [ ] 5.2 **Verify**: `BenchMercureLoadTest` with stubs; `--quick` run

- [ ] 6. Committed runs (measurement)
  Covers: AC-6
  - [ ] 6.1 N=100 and N=1000 on frankenphp; end on nginx-fpm
  - [ ] 6.2 **Verify**: result files present, private checks pass

- [ ] 7. BENCHMARKS.md, guide 23, code map (docs)
  Covers: AC-6, AC-7
  - [ ] 7.1 Section, chart with SL-09 contrast, three subsections; guide 23; section 16 pointer
  - [ ] 7.2 **Verify**: `BenchmarksDocTest`, `LearningGuideTest` read numbers from the files

- [ ] 8. Checks
  Covers: MNC-1, MNC-2, MNC-3, MNC-5
  - [ ] 8.1 Full suite, Pint, PHPStan, typecheck, build; new fingerprint recorded; parity identical on 6 servers against the new baseline; `git diff HEAD -- .ddev/config.yaml` empty; key grep clean; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [ ] 8.2 **Verify**: outputs recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | public update shown live | `manual-test.md#step-2` | manual | 3, 4 |
| AC-2 | private topic only to its token | `tests/Feature/BenchMercureLoadTest.php` | test | 3, 5, 6 |
| AC-3 | token shapes and expiry | `tests/Feature/MercureTokensTest.php` | test | 1, 2 |
| AC-4 | publish failure reported, not thrown | `tests/Feature/MercurePublisherTest.php` | test | 2 |
| AC-5 | no retry storm without a hub | `manual-test.md#step-4` | manual | 4 |
| AC-6 | load bench and BENCHMARKS | `tests/Feature/BenchmarksDocTest.php` | test | 5, 6, 7 |
| AC-7 | guide section 23 | `tests/Feature/LearningGuideTest.php` | test | 7 |
| MNC-1 | parity re-baselined once | `manual-test.md#step-5` | manual | 4, 8 |
| MNC-2 | fingerprint re-recorded | `manual-test.md#step-5` | manual | 4, 8 |
| MNC-3 | no keys committed, config.yaml untouched | `tests/Feature/MercureTokensTest.php` | test | 1, 8 |
| MNC-4 | price change works without a hub | `tests/Feature/ListingRepriceTest.php` | test | 3 |
| MNC-5 | suite offline | `tests/Feature/MercurePublisherTest.php` | test | 2 |
| NFR-1 | key and cookie security | `tests/Feature/MercureTokensTest.php` | test | 1, 4 |
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
