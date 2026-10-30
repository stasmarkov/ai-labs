# OpenLiteSpeed + LSAPI as a switchable DDEV service — Tasks

**Spec**: spec-20261029-2027-openlitespeed | **Size**: M | **Tasks**: 5

## Phase 1: Server and switching

- [x] 1. OLS stash: Dockerfile, config, compose file (devenv)
  Covers: AC-4, AC-5, AC-6
  - [x] 1.1 `Dockerfile` from `litespeedtech/openlitespeed:1.9.4-lsphp84`, installing `lsphp84-pgsql`, copying the committed OLS config
  - [x] 1.2 OLS config: HTTP listener 8088, LSAPI external app for lsphp84, vhost with docroot `/var/www/html/public`, `.htaccess` rewrites, `index.php` fallback; storage writable by the lsphp process
  - [x] 1.3 `docker-compose.openlitespeed.yaml` (service `openlitespeed`, bind mount at `/var/www/html`, `HTTPS_EXPOSE: 8091:8088`, UID/GID build args), `VERSION`
  - [x] 1.4 **Verify**: manual-test.md steps 1–3

- [x] 2. `ddev server openlitespeed` (devenv)
  Covers: AC-1, AC-2, AC-3, MNC-1
  - [x] 2.1 Failing tests in `tests/Feature/DdevServerCommandTest.php` with a stub openlitespeed stash: activation, deactivation, supported list, already-active, incomplete stash
  - [x] 2.2 Add the server to the command; add the activated compose path to `.gitignore`
  - [x] 2.3 **Verify**: `DdevServerCommandTest` green; `git diff --exit-code .ddev/config.yaml`

## Phase 2: Bench tooling

- [x] 3. Per-server base URL, `litespeed` token, OLS version (bench)
  Covers: AC-7, AC-9
  - [x] 3.1 Failing tests in `tests/Feature/BenchScriptsTest.php`: openlitespeed default URL `:8091`, override by `PARITY_BASE_URL`, `LiteSpeed` header accepted, versions parses `lshttpd -v`
  - [x] 3.2 Implement in `bench/parity.sh` and `bench/versions.sh`
  - [x] 3.3 **Verify**: `BenchScriptsTest` green

## Phase 3: Live run and docs

- [x] 4. Live switch, runtime check, capture (devenv/bench)
  Covers: AC-4, AC-6, AC-8, MNC-2
  - [x] 4.1 `ddev server openlitespeed`; `/up` on both URLs; `/debug/runtime` and `php -m` with `DEBUG_ROUTES=true`
  - [x] 4.2 `bench/parity.sh capture`; compare with nginx-fpm → `identical`; `bench/versions.sh` row
  - [x] 4.3 Switch to nginx-fpm: no OLS container, no activated file, fresh capture `identical`
  - [x] 4.4 **Verify**: manual-test.md steps 4–6; project on nginx-fpm, `DEBUG_ROUTES` off

- [x] 5. BENCHMARKS.md and README (docs)
  Covers: AC-10, AC-11, MNC-3
  - [x] 5.1 Versions row and pinning note; Response parity entry; idle-web note
  - [x] 5.2 README: value, URL, first build, `.htaccess` restart note
  - [x] 5.3 **Verify**: doc tests updated/added and green; full suite, Pint, Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | switch activates compose file + overlay | `tests/Feature/DdevServerCommandTest.php::AC-1 openlitespeed activates its compose file and keeps web on nginx-fpm` | test | 2 |
| AC-2 | switching away removes the compose file | `tests/Feature/DdevServerCommandTest.php::AC-2 switching away from openlitespeed removes its compose file` | test | 2 |
| AC-3 | supported list, no-op, stash check, rollback | `tests/Feature/DdevServerCommandTest.php::AC-3 openlitespeed is a supported server` | test | 2 |
| AC-4 | OLS answers on :8091 with LiteSpeed header; :443 still up | manual-test.md#step-2 | manual | 1, 4 |
| AC-5 | docroot, rewrites, LSAPI, writable storage | manual-test.md#step-3 | manual | 1 |
| AC-6 | SAPI litespeed, OPcache on, pdo_pgsql + redis | manual-test.md#step-4 | manual | 1, 4 |
| AC-7 | per-server default URL and litespeed token | `tests/Feature/BenchScriptsTest.php::AC-7 openlitespeed capture defaults to port 8091 and expects a LiteSpeed header` | test | 3 |
| AC-8 | OLS snapshot identical to nginx-fpm | `tests/Feature/BenchScriptsTest.php::AC-8 committed openlitespeed snapshot matches nginx-fpm` | test | 4 |
| AC-9 | versions parses lshttpd -v | `tests/Feature/BenchScriptsTest.php::AC-9 versions parses the OpenLiteSpeed version` | test | 3 |
| AC-10 | BENCHMARKS.md row + notes | `tests/Feature/BenchmarksDocTest.php::AC-10 BENCHMARKS.md has an openlitespeed row and pinning note` | test | 5 |
| AC-11 | README OLS notes | `tests/Feature/ReadmeSwitchingServersTest.php::AC-11 README documents openlitespeed URL and htaccess restart` | test | 5 |
| MNC-1 | config.yaml untouched | `tests/Feature/DdevServerCommandTest.php::MNC-1 never modifies config.yaml` | test | 2 |
| MNC-2 | no OLS container after switching away | manual-test.md#step-5 | manual | 4 |
| MNC-3 | app unchanged; suite + CI green | verify existing Pest suite green with only server-list assertions edited | test | 5 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project left on nginx-fpm with `DEBUG_ROUTES` off

## Phase: Gaps (converge round 1)

- [x] 6. Stash completeness check covers VERSION; assert `Active: openlitespeed` output (devenv)
  Covers: AC-1, AC-3
  - [x] 6.1 Failing tests in `tests/Feature/DdevServerCommandTest.php` (openlitespeed describe): stub stash includes `VERSION`; deleting `VERSION` makes `ddev server openlitespeed` exit 1 naming `VERSION is missing` with no overlay, no compose file and no restart; the AC-1 test asserts stdout contains `Active: openlitespeed`
  - [x] 6.2 Add `VERSION` to the openlitespeed case of `stash_files` in `.ddev/commands/host/server`
  - [x] 6.3 **Verify**: `ddev artisan test --compact tests/Feature/DdevServerCommandTest.php` green; `git diff --exit-code .ddev/config.yaml`

- [x] 7. Run manual-test.md step 3 live through DDEV and record the result (devenv)
  Covers: AC-5
  - [x] 7.1 `ddev server openlitespeed`; `/listings/1` → 200, `/no-such-page` → 404 from Laravel, `/api/ping` → ping JSON on `https://interview.ddev.site:8091`
  - [x] 7.2 `ddev logs -s openlitespeed` and `storage/logs` show no permission-denied errors; after `ddev artisan view:clear`, a request to `:8091/listings/1` writes compiled views under `storage/framework/views`
  - [x] 7.3 **Verify**: manual-test.md steps 1–3 run end-to-end with the expected output; project switched back to nginx-fpm with `DEBUG_ROUTES` off
