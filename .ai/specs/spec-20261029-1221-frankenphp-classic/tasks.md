# FrankenPHP classic mode as a switchable DDEV server — Tasks

**Spec**: spec-20261029-1221-frankenphp-classic | **Size**: M | **Tasks**: 5

## Phase 1: Switching

- [x] 1. Stash the add-on v1.3.0 files (devenv)
  Covers: AC-5
  - [x] 1.1 Fetch the five files from the `v1.3.0` tag into `.ddev/servers/frankenphp/` byte-identical; add `VERSION`
  - [x] 1.2 Ensure no `.ddev/addon-metadata/frankenphp` and no activated files exist; add the four activated paths to `.gitignore`
  - [x] 1.3 **Verify** (one-time, needs network): `diff` each stash file against the tag's raw file is empty

- [x] 2. `ddev server frankenphp` with activation, deactivation and rollback (devenv)
  Covers: AC-1, AC-2, AC-3, AC-4, MNC-1
  - [x] 2.1 Failing tests in `tests/Feature/DdevServerCommandTest.php` with a stub stash and stub `ddev`: activation copies files and writes the overlay; switching away deletes them; failed restart restores the previous file set; supported list includes frankenphp
  - [x] 2.2 Generalise the command: per-server overlay content + optional activated-file list; keep SL-02 behaviour
  - [x] 2.3 **Verify**: new and existing `DdevServerCommandTest` tests green; `git diff --exit-code .ddev/config.yaml`

## Phase 2: Bench tooling

- [x] 3. Parity token map and FrankenPHP version parsing (bench)
  Covers: AC-7, AC-9
  - [x] 3.1 Failing tests in `tests/Feature/BenchScriptsTest.php`: frankenphp capture accepts `Server: Caddy`, rejects `nginx`; versions parses `frankenphp -v`
  - [x] 3.2 Implement the token map in `bench/parity.sh` and the frankenphp case in `bench/versions.sh`
  - [x] 3.3 **Verify**: `ddev artisan test --compact tests/Feature/BenchScriptsTest.php` green

## Phase 3: Live run and docs

- [x] 4. Live switch, OPcache/extension check, captures (devenv/bench)
  Covers: AC-6, AC-8, MNC-2
  - [x] 4.1 `ddev server frankenphp`; with `DEBUG_ROUTES=true` check `/debug/runtime` and `ddev exec php -m` (add `php-zts-opcache` to the stash only if OPcache is off, and note it in `VERSION`)
  - [x] 4.2 `bench/parity.sh capture`; compare against both FPM snapshots → `identical`; `bench/versions.sh` row
  - [x] 4.3 Switch to apache-fpm and nginx-fpm: `ddev exec php -v` non-ZTS, no activated files, fresh capture compares `identical` with each committed snapshot (to a temp dir via `BENCH_PARITY_DIR`)
  - [x] 4.4 **Verify**: manual-test.md steps 1–6 pass; project left on nginx-fpm, `DEBUG_ROUTES` off

- [x] 5. BENCHMARKS.md and README (docs)
  Covers: AC-10, AC-11, MNC-3
  - [x] 5.1 Versions row, add-on and Caddy version line, three snapshots in Response parity
  - [x] 5.2 README: frankenphp value, first-build note, ZTS explanation, `ddev logs -f`
  - [x] 5.3 **Verify**: doc tests updated/added and green; full suite, Pint and Larastan green

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | switch to frankenphp activates files + overlay | `tests/Feature/DdevServerCommandTest.php::AC-1 frankenphp activates the stash and writes its overlay` | test | 2 |
| AC-2 | switching away deletes activated files | `tests/Feature/DdevServerCommandTest.php::AC-2 switching away from frankenphp removes the activated files` | test | 2 |
| AC-3 | frankenphp listed; --current prints it | `tests/Feature/DdevServerCommandTest.php::AC-3 frankenphp is a supported server` | test | 2 |
| AC-4 | failed restart restores overlay + file set | `tests/Feature/DdevServerCommandTest.php::AC-4 failed switch to frankenphp restores the previous file set` | test | 2 |
| AC-5 | activated paths gitignored; stash committed | `tests/Feature/DdevServerCommandTest.php::AC-5 frankenphp activated files are gitignored` | test | 1 |
| AC-6 | SAPI frankenphp, OPcache on, pdo_pgsql + redis | manual-test.md#step-3 | manual | 4 |
| AC-7 | parity checks Caddy header, writes snapshot | `tests/Feature/BenchScriptsTest.php::AC-7 frankenphp capture expects a Caddy server header` | test | 3 |
| AC-8 | frankenphp snapshot identical to both FPM | `tests/Feature/BenchScriptsTest.php::AC-8 committed frankenphp snapshot matches both FPM snapshots` | test | 4 |
| AC-9 | versions row uses frankenphp -v | `tests/Feature/BenchScriptsTest.php::AC-9 versions parses the FrankenPHP version` | test | 3 |
| AC-10 | BENCHMARKS.md frankenphp row + add-on line | `tests/Feature/BenchmarksDocTest.php::AC-10 BENCHMARKS.md has a frankenphp row and the add-on version` | test | 5 |
| AC-11 | README frankenphp notes | `tests/Feature/ReadmeSwitchingServersTest.php::AC-11 README documents frankenphp, ZTS and logs` | test | 5 |
| MNC-1 | config.yaml untouched | `tests/Feature/DdevServerCommandTest.php::MNC-1 never modifies config.yaml` | test | 2 |
| MNC-2 | FPM servers clean after switching back | manual-test.md#step-5 | manual | 4 |
| MNC-3 | app unchanged; suite + CI green | verify existing Pest suite green without edits to existing tests | test | 5 |

## Verification gates

- backend: `ddev exec vendor/bin/pint --dirty --format agent`, `ddev exec vendor/bin/phpstan analyse`, `ddev artisan test --compact`

## Done when
- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- [x] Project left on nginx-fpm with `DEBUG_ROUTES` off

## Phase: Gaps (converge round 1)

- [x] 6. Test the frankenphp already-active no-op and the successful switch back to nginx-fpm (devenv)
  Covers: AC-2, AC-3
  - In `tests/Feature/DdevServerCommandTest.php`, after `ddev server frankenphp`: delete `ddev-calls.log`, run `ddev server frankenphp` again and assert exit 0, output `frankenphp already active`, no `ddev` call, overlay unchanged and the four activated files unchanged. Then run `ddev server nginx-fpm` and assert output `Active: nginx-fpm`, a `restart` call, overlay `# server: nginx-fpm`, and none of the four activated files present.
  - **Verify**: `ddev artisan test --compact tests/Feature/DdevServerCommandTest.php` green with the new test
- [x] 7. Test the parity Server-header fallback for a server missing from the token map (bench)
  Covers: AC-7
  - In `tests/Feature/BenchScriptsTest.php`, set the active server to an unmapped name (e.g. `octane-frankenphp`) and assert `bench/parity.sh capture` accepts a `Server` header containing `octane` (exit 0, snapshot written) and rejects one without it (exit 4, `Snapshot not written`, no file).
  - **Verify**: `ddev artisan test --compact tests/Feature/BenchScriptsTest.php` green with the new test
