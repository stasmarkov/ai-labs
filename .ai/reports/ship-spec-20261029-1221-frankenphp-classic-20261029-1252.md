# Ship report: spec-20261029-1221-frankenphp-classic

## Summary

Adds FrankenPHP (classic mode) as a third switchable server next to nginx-fpm and apache-fpm. `ddev server frankenphp` activates a committed, unmodified copy of `ddev/ddev-frankenphp` v1.3.0. `bench/parity.sh` and `bench/versions.sh` now handle the Caddy server. A frankenphp parity snapshot is recorded, and BENCHMARKS.md and README.md are updated. All 7 tasks are built (5 from the plan plus 2 converge gap tasks). All 4 gates pass, and the live end-to-end journey on the DDEV project worked. There are three confirmed major review findings: FrankenPHP/ZTS package versions are not pinned (two reviewers found this separately), and the AC-5 test depends on the developer's working tree. Fix these before shipping. All of the work is still uncommitted.

## Stack & repos

| Repo | Role | Stack | Tracked | Notes |
|---|---|---|---|---|
| `.` | backend | laravel | yes | Git repo, but nothing from this feature is committed yet. Modified: `.ddev/commands/host/server`, `.gitignore`, `BENCHMARKS.md`, `README.md`, `backlog.md`, `bench/parity.sh`, `bench/versions.sh`, 4 test files. Untracked: `.ddev/servers/`, `bench/parity/frankenphp.txt`, the spec folder. |

No repo warnings. No WIP repo was built into.

Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `sdd-kit-laravel`, `sdd-kit-drupal`. Discovery noted one error: user stacks unreadable.

## Spec

- id: `spec-20261029-1221-frankenphp-classic`
- folder: `.ai/specs/spec-20261029-1221-frankenphp-classic`

## Investigation highlights

The investigation recorded no key files, risks or already-shipped pieces. The code touched is listed under Stack & repos. Risks surfaced later, in acceptance and review (see below).

## Tasks completed

7/7. Incomplete: none.

## Converge

- Rounds run: 2
- Gap tasks appended (round 1):
  - Task 6: test the frankenphp already-active no-op (activated files and overlay untouched) and the successful frankenphp to nginx-fpm switch (restart call and `Active:` line). Covers AC-2 and AC-3.
  - Task 7: test the parity Server-header fallback for a server missing from the token map. Covers AC-7.
- Round 2: no gaps.
- Gaps left unbuilt: none.

## Quality gates (repo `.`)

| Gate | Status | Details |
|---|---|---|
| Pint | pass | 68 files (via ddev exec) |
| PHPStan | pass | No errors (via ddev exec) |
| Pest/PHPUnit | pass | 115 passed, 569 assertions (via ddev exec) |
| Debug scan | pass | No `dd(`/`dump(`/`ray(` in app/, routes/, database/ |

Contract-sync does not apply (single repo). No gate was unreachable.

## Visual tests

Skipped. This is a CLI and HTTP feature with no UI change.

## Acceptance

**Resolved end-to-end: yes.** Live run on https://interview.ddev.site (DDEV v1.25.4, OrbStack), switching nginx-fpm -> frankenphp -> nginx-fpm -> frankenphp -> apache-fpm -> nginx-fpm.

- Journey 1 (switch to frankenphp): printed `Active: frankenphp`. The overlay and all 4 activated files match the stash byte for byte.
- Journey 2 (runtime): `sapi=frankenphp`, `opcache_enabled=true`, PHP 8.4.27 ZTS, `server: FrankenPHP Caddy`. `pdo_pgsql` and `redis` are loaded.
- Journey 3 (parity): the live frankenphp capture is `identical` to the nginx-fpm, apache-fpm and committed frankenphp snapshots.
- Journey 4 (versions): `| frankenphp | 1.13.4 | 1.25.4 | 8.4.27 | frankenphp | true | 2026-10-29 |`, which matches BENCHMARKS.md.
- Journey 5 (switch back): no `*.frankenphp` files left. PHP is NTS on both FPM servers, and fresh captures match the committed snapshots.
- Stash: all 5 files are byte-identical to upstream v1.3.0. VERSION is correct. There is no addon-metadata entry.
- AC-1 to AC-11 and MNC-1 to MNC-3: all confirmed. AC-4 rollback was confirmed with a stubbed ddev whose restart fails: overlay and files restored, 2 restarts, exit 1. `.ddev/config.yaml` was never modified.
- Broken hops: none.
- Caveat: the "SHALL be committed" parts of AC-5 and AC-8 only hold once the pending commit is made. `.ddev/servers/frankenphp/` and `bench/parity/frankenphp.txt` are untracked but not ignored, so nothing blocks that commit.
- Clean-up: the project was left on nginx-fpm, `.env` was restored (DEBUG_ROUTES removed) and the committed snapshots are unchanged.

**E2E regression test:** none written. The backend-only Laravel stack has no e2e runner configured. AC coverage comes from the Pest feature tests (`DdevServerCommandTest`, `BenchScriptsTest`, `BenchmarksDocTest`, `ReadmeSwitchingServersTest`), which passed.

## Confirmed review findings

### Major

- `.ddev/servers/frankenphp/config.frankenphp.yaml:13-14`: FrankenPHP, Caddy and `php-zts-*` are installed with no version from the moving henderkes apt repo. This breaks BR-25, and BENCHMARKS.md:5 implies the versions are pinned. Drift has already happened (the spec expected 1.12.1, the run got 1.13.4). Two reviewers reported this separately. Fix: pin `frankenphp=<ver>` and `php-zts-*=<ver>` as an approved stash deviation, or state in BENCHMARKS.md that the frankenphp row is recorded at a point in time and not pinned.
- `tests/Feature/DdevServerCommandTest.php:202`: the AC-5 test checks that the activated files are absent from the real repo's `.ddev/`, so the suite fails whenever frankenphp is active. Fix: check only the `.gitignore` entries and the stash contents, or skip the absence check when the overlay says frankenphp.

### Minor

- `.ddev/commands/host/server:117`: the "already active" check looks only at the overlay marker, so missing or outdated activated files are never repaired. Fix: compare the activated files and the overlay against the stash before treating the call as a no-op.
- `.ddev/commands/host/server:24`: a missing stash is reported as "Unknown server 'frankenphp'" while the same message lists frankenphp as supported. Fix: print a separate "stash missing" error, and assert that message in the AC-4 test.
- `.ddev/commands/host/server:131`: if a `cp` fails during activation, `set -e` exits with no rollback, leaving partial files and leaked temp backups. There is also no INT/TERM trap. Fix: check every stash file exists before deactivating, and add a trap that calls `restore_files`.
- `bench/parity.sh:68`: a missing Server header skips validation, so a capture against a stopped project (router 404) overwrites the snapshot. Fix: treat a missing header as a mismatch (exit 4).
- `bench/parity.sh:24`: the `${1%%-*}` fallback guesses wrong for Octane on FrankenPHP (SL-04 answers `Server: Caddy`), and the Task 7 test at `BenchScriptsTest.php:402` locks that in. Fix: fail with "add a token for <server>" for unmapped servers, and update the Task 7 test.
- `bench/versions.sh:41`: the `*)` branch reports the nginx version for any unknown server, and an empty web_version is never checked. Fix: make nginx-fpm an explicit case, exit non-zero for unknown servers, and fail when web_version is empty.
- `tests/Feature/BenchScriptsTest.php:173`: the curl stub always returns `fpm-fcgi`, so the frankenphp versions test asserts an impossible row. Fix: let the stub return the SAPI per server, and assert `frankenphp`.
- `BENCHMARKS.md:13`: the add-on/Caddy line is typed by hand. Fix: have `versions.sh` generate it from `frankenphp -v` and `.ddev/servers/frankenphp/VERSION`.

## Documentation updated

- Updated: `README.md` ("Switching servers": how the stash is activated and rolled back, a frankenphp parity example, the Server-header token rule).
- Checked and left unchanged: `BENCHMARKS.md` (already updated in Task 5 and verified accurate).
- Not affected: `CLAUDE.md`, `AGENTS.md`, `docs/*`, `backlog.md`, `product-brief.md`. Unrelated to this feature, `CLAUDE.md` is stale: it says MariaDB and PHPUnit, but the project uses PostgreSQL and Pest-style tests.

## Manual test plan

Written to `.ai/specs/spec-20261029-1221-frankenphp-classic/manual-test.md`. To run it, start on nginx-fpm with DDEV running and network access, follow steps 1-10 in a host terminal (step 8 is optional), then use the rollback line at the end to restore nginx-fpm and `.env`.

## Recommendation

**FIX-FIRST.** The build is complete, the gates pass, acceptance resolved live, and no converge gaps are left. The three major findings still need fixing first. The unpinned FrankenPHP/ZTS versions make the recorded BENCHMARKS row impossible to reproduce and break BR-25. The AC-5 test fails the suite whenever a developer is on frankenphp. All of the work, including the stash and the frankenphp snapshot, also still needs to be committed before a PR.

Next: make the AC-5 test in `tests/Feature/DdevServerCommandTest.php:202` independent of the working tree (check only the `.gitignore` entries and the stash). Then pin or clearly document the unpinned FrankenPHP/ZTS versions in `config.frankenphp.yaml` and `BENCHMARKS.md`.
