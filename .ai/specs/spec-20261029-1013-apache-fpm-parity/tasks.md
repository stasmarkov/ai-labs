# Apache-fpm server switch, response parity check and versions table — Tasks

**Spec**: spec-20261029-1013-apache-fpm-parity | **Size**: S | **Tasks**: 3

## Phase 1: App

- [x] 1. Guarded `/debug/runtime` endpoint (backend)
  Covers: AC-6, AC-7, MNC-2, MNC-3
  - [x] 1.1 Failing tests: enabled returns 200 with `sapi`, `php_version`, `opcache_enabled`, `server_software`; disabled returns the same status and body as an unregistered path
  - [x] 1.2 Add `app.debug_routes` config key (`env('DEBUG_ROUTES', false)`), final invokable controller, `routes/debug.php` registered under the `api` group in `bootstrap/app.php`; `DEBUG_ROUTES=false` in `.env.example`
  - [x] 1.3 **Verify**: new tests green; existing suite green without edits; `ddev artisan route:cache` succeeds

## Phase 2: DDEV and scripts

- [x] 2. `ddev server` host command (devenv)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, MNC-1
  - [x] 2.1 `.ddev/commands/host/server`: no arg → print active server + supported values; valid arg → write `.ddev/config.zz-server.yaml`, `ddev restart`, print active server; invalid arg → exit 1, nothing written
  - [x] 2.2 Add `.ddev/config.zz-server.yaml` to `.gitignore`
  - [x] 2.3 **Verify**: run the Traceability verify checks for AC-1–AC-5; `git diff .ddev/config.yaml` is empty

- [x] 3. Parity and versions scripts, snapshots, BENCHMARKS.md, README (bench/docs)
  Covers: AC-8, AC-9, AC-10, AC-11, AC-12, AC-13
  - [x] 3.1 `bench/parity.sh capture|compare` per the fixed request set; portable SHA-256
  - [x] 3.2 `bench/versions.sh` printing one markdown row; exits 1 with a hint when `/debug/runtime` is 404
  - [x] 3.3 Capture on nginx-fpm, switch, capture on apache-fpm, compare → `identical`; commit both snapshots
  - [x] 3.4 `BENCHMARKS.md` with the Versions table (both rows from `versions.sh`); README "Switching servers" section
  - [x] 3.5 **Verify**: `bench/parity.sh compare nginx-fpm apache-fpm` exits 0; a hand-edited copy of a snapshot makes it exit 1 and print the path; switch back to nginx-fpm

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | `ddev server apache-fpm` writes overlay, restarts | `tests/Feature/DdevServerCommandTest.php::AC-1 apache-fpm writes the overlay and restarts` | test | 2, 4 |
| AC-2 | `ddev server nginx-fpm` switches back | `tests/Feature/DdevServerCommandTest.php::AC-2 nginx-fpm switches back` | test | 2, 4 |
| AC-3 | no arg prints active + supported; already active skipped | `tests/Feature/DdevServerCommandTest.php::AC-3 no argument prints the active and supported servers without restarting` | test | 2, 4 |
| AC-4 | invalid arg exits 1, no change; failed restart restored | `tests/Feature/DdevServerCommandTest.php::AC-4 invalid argument exits non-zero, prints supported values and changes nothing` | test | 2, 4, 14 |
| AC-5 | overlay gitignored | `tests/Feature/DdevServerCommandTest.php::AC-5 overlay is gitignored` | test | 2 |
| AC-6 | runtime JSON when enabled | `tests/Feature/DebugRuntimeTest.php::AC-6 returns runtime info when enabled` | test | 1, 11 |
| AC-7 | 404 like unknown path when disabled | `tests/Feature/DebugRuntimeTest.php::AC-7 MNC-3 is not found when disabled` | test | 1, 11 |
| AC-8 | capture writes snapshot per server | `tests/Feature/BenchScriptsTest.php::AC-8 capture writes the header line and the seven requests in spec order` | test | 3, 5 |
| AC-9 | compare identical → 0, diff → 1, missing → 2 | `tests/Feature/BenchScriptsTest.php::AC-9 compare prints the differing path with both lines and exits 1` | test | 3, 6 |
| AC-10 | committed snapshots compare identical | `tests/Feature/BenchScriptsTest.php::AC-10 committed snapshots use the spec format and cover the seven requests` | test | 3, 7 |
| AC-11 | versions row; 404 → hint + exit 1 | `tests/Feature/BenchScriptsTest.php::AC-11 versions prints one markdown row` | test | 3, 8, 13 |
| AC-12 | BENCHMARKS.md versions rows, OPcache true | `tests/Feature/BenchmarksDocTest.php::AC-12 BENCHMARKS.md versions table has one nginx-fpm and one apache-fpm row` | test | 3, 9 |
| AC-13 | README switching section | `tests/Feature/ReadmeSwitchingServersTest.php::AC-13 README switching section documents the parity workflow` | test | 3, 10 |
| MNC-1 | config.yaml untouched | `tests/Feature/DdevServerCommandTest.php::MNC-1 never modifies config.yaml` | test | 2 |
| MNC-2 | endpoint responses unchanged | verify existing Pest suite (`tests/Feature/*`) green without edits | test | 1 |
| MNC-3 | CI green; route 404 in tests by default | `tests/Feature/DebugRuntimeTest.php::MNC-3 is disabled by default in the test environment` | test | 1 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project left on nginx-fpm

## Phase: Gaps (converge round 1)

- [x] 4. `ddev server`: `--current`, `already active` skip, and restart-failure rollback (devenv)
  Covers: AC-1, AC-2, AC-3, AC-4
  - `.ddev/commands/host/server` has no `--current` branch (it would be rejected as an unknown server), always rewrites the overlay and restarts even when the target is already active (no `already active` output), and under `set -e` a failing `ddev restart` exits with restart's own code and leaves the new overlay in place, with no restore or removal. `tests/Feature/DdevServerCommandTest.php` has no tests for any of these.
  - Add `--current`, which prints only the active value. Before writing, compare the target with `active_server`: if they match, print `already active` and exit 0. Otherwise back up the overlay (or record that there was none), write it, and run `ddev restart`. On failure, restore the backup or delete the overlay, then exit 1. Add Pest tests with a stub `ddev` that can be made to fail.
  - [x] 4.1 **Verify**: new tests pass for `--current` (prints `nginx-fpm` only), already active (no restart call logged, output contains `already active`), and restart failure (exit 1; overlay restored to the previous content, or absent if there was none). `ddev artisan test --compact tests/Feature/DdevServerCommandTest.php` is green.

- [x] 5. `bench/parity.sh capture` matches the spec's request set and snapshot format (bench)
  Covers: AC-8
  - `bench/parity.sh` line 11 uses the request set `/listings?make=BMW` and `/missing-path`. It is missing `/listings?make=BMW&fuel=diesel&per_page=5` and `/listings?per_page=99`.
  - Line 33 sends an `Accept: text/html,application/json` header, but the spec says no Accept header. It always passes `-k`, but `-k` should be added only when `PARITY_INSECURE=1`. It reads `BASE_URL` instead of `PARITY_BASE_URL`.
  - `capture` takes a server argument instead of using the active server.
  - Lines 34-35 write `GET <path> <status> <media-type> <hash>`: space-separated, no `# server=... base=... captured=...` header, and the content type has its parameters stripped and is not lowercased, when it should be the full header value. The body is also altered (render-time masking) before hashing.
  - `tests/Feature/BenchScriptsTest.php` AC-8 test does not check the format or the request order.
  - Rewrite capture to match the spec:
    - Use the exact 7-path set in order, with no Accept header and no `-L`.
    - Use `PARITY_BASE_URL` (default `https://ai-lab.ddev.site`), adding `-k` only when `PARITY_INSECURE=1`.
    - Take the server name from `.ddev/commands/host/server --current` (or the same overlay/config.yaml rule).
    - Write the header line with an ISO-8601 UTC timestamp, then TSV lines `path\tstatus\tcontent-type\tsha256`, where the content type is the lowercased, trimmed full value.
  - If `/up` needs render-time masking to be identical, record it as an approved decision in spec-lite.md (Boundaries: ask first). Otherwise hash the raw body.
  - [x] 5.1 **Verify**: the Pest test with the stub curl asserts a first line matching `^# server=nginx-fpm base=\S+ captured=\d{4}-\d\d-\d\dT\d\d:\d\d:\d\dZ$` and exactly 7 TSV data lines in spec order. It also asserts that no `-H Accept`/`-k` is passed unless `PARITY_INSECURE=1`, and that `PARITY_BASE_URL` is honoured.

- [x] 6. `bench/parity.sh compare`: path arguments, `#` lines ignored, per-path output, exit 2 on a missing file (bench)
  Covers: AC-9
  - `compare` in `bench/parity.sh` (lines 40-54) always maps arguments to `$DIR/<name>.txt`, even when they contain `/`. It exits 1 instead of 2 when a snapshot is missing. It runs `diff -u` on whole files, so `#` lines are not ignored: once the header exists, the `captured=` timestamps differ every time. On a difference it prints the snapshot paths rather than each differing request path with both full lines.
  - The AC-9 test asserts only that the snapshot file path is printed.
  - Treat an argument containing `/` as a file path. Exit 2 naming the missing file. Strip `#` lines and compare the remaining lines by position. If they are identical, print `identical` and exit 0. Otherwise print each differing request path with the line from a and the line from b, report a line-count mismatch, and exit 1.
  - [x] 6.1 **Verify**: Pest tests cover:
    - two snapshots differing only in their `#` header give `identical` and exit 0;
    - one changed data line prints that request path and both full lines, and exits 1;
    - different line counts exit 1;
    - a missing snapshot exits 2 and names the file;
    - a path argument (`./x/nginx-fpm.txt`) is read as a path.

- [x] 7. Re-capture both committed snapshots from seeded state in the spec format (bench)
  Covers: AC-10
  - `bench/parity/nginx-fpm.txt` and `bench/parity/apache-fpm.txt` are in the old format (`GET /up 200 text/html <hash>`). They have no header line, and their request set includes `/missing-path` but lacks `/listings?make=BMW&fuel=diesel&per_page=5` and `/listings?per_page=99` (422). The two files are also untracked (`git status`: `?? bench/`).
  - After tasks 5 and 6:
    1. Run `ddev artisan migrate:fresh --seed` once.
    2. Run `bench/parity.sh capture` on nginx-fpm.
    3. Run `ddev server apache-fpm`, then `bench/parity.sh capture`.
    4. Run `ddev server nginx-fpm`, then `bench/parity.sh compare nginx-fpm apache-fpm`.
  - [x] 7.1 **Verify**: both files start with `# server=<name> base=https://ai-lab.ddev.site captured=...` and hold 7 TSV lines in spec order, including `/listings?per_page=99\t422` and `/listings/999999\t404`. `bench/parity.sh compare nginx-fpm apache-fpm` prints `identical` and exits 0. `ddev server --current` prints `nginx-fpm`.

- [x] 8. `bench/versions.sh` prints the full AC-11 row (bench)
  Covers: AC-11
  - `bench/versions.sh` line 22 prints `| server_software | sapi | php | opcache |`, which is only 4 columns. It is missing:
    - the active server (from `ddev server --current`);
    - the web server version number only (from `ddev exec nginx -v` or `ddev exec apache2 -v`);
    - the DDEV version (host `ddev version`);
    - the date (UTC `YYYY-MM-DD`).
  - The test in `tests/Feature/BenchScriptsTest.php` asserts the 4-column row.
  - Emit `| <active server> | <web server version> | <ddev version> | <php> | <sapi> | <opcache> | <date> |`. Pick `nginx -v` or `apache2 -v` from the active server and extract only the number, for example `1.26.3` or `2.4.68`. Keep the 404 path exiting 1 with the DEBUG_ROUTES/optimize:clear hint.
  - [x] 8.1 **Verify**: the Pest test uses stub `ddev` (`--current`, `exec nginx -v`, `version`) and stub curl, and asserts the exact 7-column row with the date matching `\d{4}-\d\d-\d\d`. The 404 test still exits 1 with a hint mentioning `DEBUG_ROUTES`.

- [x] 9. BENCHMARKS.md "Versions" table with AC-11 columns (docs)
  Covers: AC-12
  - `BENCHMARKS.md` has a `## versions` heading with header `| Server software | SAPI | PHP | OPcache |`. Its rows are not in AC-11's column set: no active server, version number only, DDEV version or date.
  - Rename the heading to "Versions" and use a header row matching AC-11's columns. Fill it with one row from `bench/versions.sh` on nginx-fpm and one on apache-fpm (with `DEBUG_ROUTES=true` temporarily in `.env`, then revert it).
  - [x] 9.1 **Verify**: BENCHMARKS.md has a "Versions" table whose header matches the versions.sh column order. It has exactly one nginx-fpm row and one apache-fpm row, both with SAPI `fpm-fcgi` and OPcache `true`. `.env` no longer sets `DEBUG_ROUTES=true`.

- [x] 10. README "Switching servers" section: missing parity workflow, `/debug/runtime` enablement and `.htaccess` note (docs)
  Covers: AC-13
  - `README.md` lines 44-60 show the commands but have:
    - no "seed once" step (`ddev artisan migrate:fresh --seed` before the first capture);
    - no `ddev artisan optimize:clear` after setting `DEBUG_ROUTES=true`;
    - no statement that scripts never change `.env` and that it stays false in `.env.example` and for benchmarks;
    - no note that Apache reads `public/.htaccess` on every request (`grep -i htaccess README.md` finds nothing).
  - The capture usage line also shows `capture <server>`, which task 5 will make obsolete.
  - Add the missing items, and update the capture usage to match task 5.
  - [x] 10.1 **Verify**: the README "Switching servers" section contains:
    - `ddev server` with `nginx-fpm` and `apache-fpm`;
    - `migrate:fresh --seed`, capture on each server, then `compare`;
    - `DEBUG_ROUTES=true` in `.env` plus `optimize:clear`, with the note that scripts never change `.env` and that it stays false in `.env.example` and for benchmarks;
    - `public/.htaccess` read on every request by Apache.

- [x] 11. DebugRuntimeTest: no-Accept disabled check and response type assertions (backend)
  Covers: AC-6, AC-7
  - In `tests/Feature/DebugRuntimeTest.php`, the AC-7 test compares `/debug/runtime` with an unknown path only via `getJson` (Accept: application/json). The spec also requires the comparison with no `Accept` header.
  - The AC-6 test checks keys (and CLI values) but not types: `opcache_enabled` must be a bool, `sapi`/`php_version` strings, and `server_software` a string or null.
  - Add an AC-7 case using `$this->get(...)` (no Accept) against `/debug/not-a-route` that compares status and body. Add type assertions for the AC-6 data fields.
  - [x] 11.1 **Verify**: `ddev artisan test --compact tests/Feature/DebugRuntimeTest.php` is green, with the new no-Accept case asserting equal status and body, and AC-6 asserting `is_bool(data.opcache_enabled)` and `data.server_software` string|null.

## Phase: Gaps (converge round 2)

- [x] 12. Apache charset: override removed; parity compares media type (decision 7) (docs)
  Covers: AC-10
  - Task 7 added `.ddev/apache/apache-charset.conf` (`Header edit Content-Type "^application/json$" "application/json; charset=utf-8"`), and the committed `bench/parity/apache-fpm.txt` only matches nginx-fpm because of it. `spec-lite.md` lists "Custom Apache or nginx config; DDEV's generated configs are used as is" under Out of scope and "custom Apache or nginx config" under Boundaries > Ask first, but its Decisions list has no entry for this file (only decision 6 for the `/up` masking).
  - Add decision 7 to `spec-lite.md` describing the file, why it exists (nginx `charset utf-8` vs Apache plain `application/json`), and mark it pending confirmation by Stanislav Markov, like decision 6. If it is rejected, remove the file and re-capture apache-fpm (task 7 steps), accepting that compare will report the content-type difference.
  - [x] 12.1 **Verify**: `.ddev/apache/apache-charset.conf` removed; spec decision 7 records the media-type comparison; snapshots re-captured on stock servers and `bench/parity.sh compare nginx-fpm apache-fpm` prints `identical`.

- [x] 13. `bench/versions.sh`: follow the spec's HTTPS and `/debug/runtime` enablement rules (bench)
  Covers: AC-11
  - `bench/versions.sh` line 12 always calls `curl -sk`, while the spec's HTTPS section says curl trusts DDEV's mkcert certificate by default and `-k` is added only with `PARITY_INSECURE=1`. The 404 hint on line 14 (and the header comment on line 4) tells the developer to run `ddev artisan config:clear`, while the spec's "Enabling `/debug/runtime`" rule and README say `ddev artisan optimize:clear`.
  - Drop the unconditional `-k` and add it only when `PARITY_INSECURE=1`. Change the hint and header comment to `DEBUG_ROUTES=true` in `.env` plus `ddev artisan optimize:clear`. Add Pest tests in `tests/Feature/BenchScriptsTest.php` using the stub curl log.
  - [x] 13.1 **Verify**: a new test asserts the stub curl log has no ` -k` by default and has `-k` with `PARITY_INSECURE=1`; the AC-11 404 test asserts stderr contains `DEBUG_ROUTES=true` and `optimize:clear`. `ddev artisan test --compact tests/Feature/BenchScriptsTest.php` is green.

- [x] 14. `ddev server` AC-4 test: assert the supported values are printed and an existing overlay is left untouched (devenv)
  Covers: AC-4
  - `tests/Feature/DdevServerCommandTest.php` "AC-4 invalid argument exits non-zero and changes nothing" checks only the exit code, that no overlay exists and that no `ddev` call was logged. It never asserts that the supported values are printed (the script writes them to stderr), and it only covers the case with no overlay, so "leave the overlay untouched" when one already exists is untested.
  - Extend the test: assert the error output contains `nginx-fpm` and `apache-fpm`; then switch to apache-fpm, clear the call log, run `server foo` again, and assert exit 1, unchanged overlay content and no `ddev` call logged.
  - [x] 14.1 **Verify**: `ddev artisan test --compact tests/Feature/DdevServerCommandTest.php` is green, and the AC-4 test asserts the stderr supported values and the unchanged existing overlay.

- [x] 15. Refresh the Traceability table to point at the actual tests (docs)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, AC-7, AC-8, AC-9, AC-10, AC-11, AC-12, AC-13, MNC-1, MNC-3
  - The Traceability table in `tasks.md` still lists AC-1–AC-5, AC-8–AC-13 and MNC-1 as `manual` from task 2/3, although Pest tests now cover them (`tests/Feature/DdevServerCommandTest.php`, `tests/Feature/BenchScriptsTest.php`, `tests/Feature/BenchmarksDocTest.php`, `tests/Feature/ReadmeSwitchingServersTest.php`). The AC-7 and MNC-3 rows name `is not found when disabled`, but the tests are `AC-7 MNC-3 is not found when disabled`, `AC-7 is not found like an unknown path without an Accept header` and `MNC-3 is disabled by default in the test environment`.
  - Update each row's "Verified by" to the real test file and description (keeping a manual step where real DDEV is required, e.g. AC-1/AC-2 real switch and AC-10 compare on the committed snapshots), set Kind to `test` or `test + manual`, and point the Task column at the gap tasks that added the tests.
  - [x] 15.1 **Verify**: every test named in the Traceability table exists, checked with `grep -F` of each description in `tests/Feature/`; `ddev artisan test --compact` is green.
