---
id: spec-20261029-1221-frankenphp-classic
title: FrankenPHP classic mode as a switchable DDEV server with parity and versions
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov
approved_at: 2026-10-29T10:25:24Z
created: 2026-10-29
stacks: [laravel]
slice: SL-03
brs: [BR-02, BR-25]
---

# FrankenPHP classic mode as a switchable DDEV server with parity and versions

## Summary
The developer runs `ddev server frankenphp` and the unchanged app is served by FrankenPHP in classic mode (no worker), with thread-safe (ZTS) PHP and the pgsql and redis extensions. Responses match nginx-fpm and apache-fpm in the parity check, OPcache is on, and versions are recorded in BENCHMARKS.md. Switching back to nginx-fpm or apache-fpm leaves no FrankenPHP or ZTS traces behind.

## Context
- **Problem**: SL-02 added `ddev server` for nginx-fpm and apache-fpm only. FrankenPHP is not a DDEV `webserver_type`. It comes from the `ddev/ddev-frankenphp` add-on, whose files take effect whenever they exist in `.ddev/`. Its overlay sets `webserver_type: generic` and starts a `frankenphp` daemon, and its Dockerfiles switch the container's `php` to the ZTS build. Installing it the normal way would make FrankenPHP permanent and change PHP for the other servers.
- **User impact**: The third of five server configs; FrankenPHP classic vs FPM numbers (SL-06) and worker mode (SL-04) build on it.
- **Business rules (product-brief.md, draft)**:
  - **BR-02** For the same request and seed, every endpoint returns the same status and the same JSON body on all five server configs.
  - **BR-25** Server, PHP, DDEV and add-on versions are pinned in config and recorded in BENCHMARKS.md for every result table.

## Scope
### In scope
- The `ddev/ddev-frankenphp` add-on **v1.3.0** files, kept in a committed stash `.ddev/servers/frankenphp/`.
- `ddev server frankenphp`: activates the stash and writes the overlay; switching to another server deactivates it.
- `bench/parity.sh` and `bench/versions.sh` support for FrankenPHP.
- A committed `bench/parity/frankenphp.txt` that compares `identical` with both FPM snapshots.
- A frankenphp row in the BENCHMARKS.md Versions table, plus the add-on version.
- README updates: supported value, first-switch build time, why FrankenPHP needs ZTS PHP, where its logs are.
### Out of scope
- Worker mode, Octane, `FRANKENPHP_CONFIG` (SL-04).
- Thread tuning (`num_threads`, `max_threads`); add-on defaults are used (SL-06, SL-09).
- Compression, Early Hints, Caddyfile edits (SL-10); the add-on's Caddyfile is used unchanged.
- OpenLiteSpeed (SL-05) and load tests (SL-06).

## Must NOT change
- **MNC-1**: `.ddev/config.yaml` is never modified.
- **MNC-2**: nginx-fpm and apache-fpm behave as in SL-02: after switching back from frankenphp, `ddev exec php -v` shows a non-ZTS PHP, none of the activated FrankenPHP files exist in `.ddev/`, and a fresh capture on each server compares `identical` with its committed snapshot.
- **MNC-3**: App code and endpoint responses are unchanged; the existing Pest suite stays green without edits to existing tests, and CI keeps passing.

## Acceptance criteria

**Stash** (`.ddev/servers/frankenphp/`): `config.frankenphp.yaml`, `docker-compose.frankenphp.yaml`, `web-build/Caddyfile.frankenphp`, `web-build/Dockerfile.frankenphp`, `web-build/pre.Dockerfile.frankenphp`, all byte-identical to the raw files at the `v1.3.0` tag of `github.com/ddev/ddev-frankenphp` (fetched from `https://raw.githubusercontent.com/ddev/ddev-frankenphp/v1.3.0/<path>`). `ddev add-on get` copies these `project_files` verbatim; its `install.yaml` actions are only checks (PHP ≥ 8.2, legacy files, old `.env.web` keys) and a PHP 8.6 package tweak, none of which apply here, so the five files are a complete install, plus a `VERSION` file containing `ddev/ddev-frankenphp v1.3.0`. The only allowed deviation is adding `php-zts-opcache` to `webimage_extra_packages` if AC-6 fails without it; that deviation is then noted in `VERSION`, and the byte-identity check skips that one file. No `.ddev/addon-metadata/frankenphp` entry is left behind.

**Activated files** (copies of the stash, listed in the repo-root `.gitignore`): `.ddev/docker-compose.frankenphp.yaml`, `.ddev/web-build/Caddyfile.frankenphp`, `.ddev/web-build/Dockerfile.frankenphp`, `.ddev/web-build/pre.Dockerfile.frankenphp`.

- **AC-1**: WHEN the developer runs `ddev server frankenphp` from another server THE SYSTEM SHALL copy the stash's activated files into place, write `.ddev/config.zz-server.yaml` as the line `# server: frankenphp` followed by the stash's whole `config.frankenphp.yaml` (its `#ddev-generated` line included), run `ddev restart`, and print `Active: frankenphp`.
- **AC-2**: WHEN the developer runs `ddev server nginx-fpm` or `ddev server apache-fpm` while frankenphp is active THE SYSTEM SHALL delete every activated file, write that server's overlay, run `ddev restart`, and print the active server.
- **AC-3**: `ddev server` with no argument SHALL print `Supported: nginx-fpm apache-fpm frankenphp` (this order); `ddev server --current` SHALL print `frankenphp` while it is active; `ddev server frankenphp` while it is already active SHALL print `frankenphp already active` and change nothing.
- **AC-4**: IF `ddev restart` fails during a switch THEN THE SYSTEM SHALL restore the previous overlay and the previous set of activated files (present or absent) from backups taken before the switch, attempt one more `ddev restart`, and exit 1 (same recovery messages as SL-02).
- **AC-5**: The four activated file paths SHALL be listed in `.gitignore`; the stash SHALL be committed.
- **AC-6 (BR-25)**: WHILE frankenphp is active and `/debug/runtime` is enabled THE SYSTEM SHALL report `sapi` = `frankenphp`, `opcache_enabled` = `true`, and a `php_version` with the same major.minor as the nginx-fpm row (8.4; the ZTS package's patch release may differ and is recorded as is); `ddev exec php -m` SHALL list `pdo_pgsql` and `redis`.
- **AC-7 (BR-02)**: WHEN `bench/parity.sh capture` runs while frankenphp is active THE SYSTEM SHALL check the `Server` header of every response for the token `caddy` (case-insensitive) and write `bench/parity/frankenphp.txt` in the SL-02 snapshot format (path, status, media type, body SHA-256; no other headers; no `Accept-Encoding` sent, so bodies are uncompressed). The token map is: nginx-fpm → `nginx`, apache-fpm → `apache`, frankenphp → `caddy`; a server missing from the map makes `capture` exit 2 with a message to add it (changed from a name-prefix fallback after review, 2026-10-29). A mismatch or a missing `Server` header exits 4 without writing.
- **AC-8 (BR-02)**: `bench/parity/frankenphp.txt` SHALL be committed, captured from the same seeded database as the FPM snapshots, and `bench/parity.sh compare nginx-fpm frankenphp` and `compare apache-fpm frankenphp` SHALL both print `identical`.
- **AC-9 (BR-25)**: WHEN `bench/versions.sh` runs while frankenphp is active THE SYSTEM SHALL print a row whose web-server column is the FrankenPHP version number from `ddev exec frankenphp -v` with the leading `v` stripped (output looks like `FrankenPHP v1.12.1 PHP 8.4.x Caddy v2.10.x h1:…`, so the column is `1.12.1`). `versions.sh` does not print the add-on or Caddy version.
- **AC-10 (BR-25)**: BENCHMARKS.md SHALL have a frankenphp row in the Versions table pasted from `bench/versions.sh` output (SAPI `frankenphp`, OPcache `true`), one line under the table written by hand: `FrankenPHP row: ddev/ddev-frankenphp v1.3.0, Caddy <version from frankenphp -v>`, and the Response parity section SHALL name all three snapshot files and state that `compare nginx-fpm frankenphp` and `compare apache-fpm frankenphp` print `identical`.
- **AC-11**: README's "Switching servers" section (tested in the existing `tests/Feature/ReadmeSwitchingServersTest.php`) SHALL list `frankenphp`, say the first switch to it builds the web image and can take several minutes, explain in 2–3 sentences why FrankenPHP needs ZTS PHP (PHP runs in threads inside one process) while FPM uses NTS processes, and point to `ddev logs -f` for FrankenPHP logs.

**Clarifications from the clarity gate**
- **Overlay lists.** `.ddev/config.yaml` sets no `webimage_extra_packages`, `web_extra_daemons` or `web_extra_exposed_ports`, so whether DDEV merges or replaces those lists does not change the result.
- **Image rebuild.** The switch runs `ddev restart`, which rebuilds the web image when the `.ddev/web-build` files or `webimage_extra_packages` change. If manual-test step 5 still shows ZTS PHP on an FPM server after switching back, the command adds an image rebuild step for that switch (allowed without asking) and the step is re-run.
- **Stash in tests.** The command reads the stash from `$DDEV_APPROOT/.ddev/servers/<server>/`, the same `DDEV_APPROOT` convention SL-02's tests use; tests create a stub stash under a temp `DDEV_APPROOT`.
- **Byte-identity check.** A one-time manual check against the tag (needs network), not a CI test.
- **Same seeded database.** The seed is deterministic (SL-01, fixed seed). Manual-test step 5 proves the database did not change: fresh FPM captures compare `identical` with the committed snapshots.
- **PHP config under ZTS.** The add-on copies the FPM `php.ini` and points DDEV's `start.sh` at `/etc/php-zts/conf.d/`, so DDEV's PHP settings apply. This project has no custom `.ddev/php/*.ini`. Only "OPcache on" is required here; OPcache settings are compared in SL-06.
- **Tooling.** Tests are Pest 5.3 (SL-01 decision); Larastan level 5 is installed. The project `CLAUDE.md` still says PHPUnit and is out of date.

## Boundaries
- **Always**: Pest tests for the new `ddev server` behaviour (activation, deactivation, rollback) using stubbed `ddev`, as in SL-02; run gates through DDEV; leave the project on nginx-fpm with `DEBUG_ROUTES` unset or `false` when done.
- **Ask first**: any stash deviation other than `php-zts-opcache`; Caddyfile or thread changes; a different add-on version; new Composer or npm dependencies.
- **Never**: modify `.ddev/config.yaml`; commit activated files; leave FrankenPHP files active on nginx-fpm or apache-fpm; change app code to make parity pass.

## Technical context
- **Related code**: `.ddev/commands/host/server` (`SUPPORTED`, `overlay_for`, `active_server` reading the `# server:` marker, rollback), `bench/parity.sh` (Server-header family check now uses `${server%%-*}`; it must become the per-server token map in AC-7), `bench/versions.sh` (web-server version `case`), `BENCHMARKS.md`, `README.md`, `tests/Feature/DdevServerCommandTest.php`, `tests/Feature/BenchScriptsTest.php`, `tests/Feature/BenchmarksDocTest.php`.
- **Already implemented**: SL-02 switching, parity and versions tooling; `/debug/runtime` (`DEBUG_ROUTES=true` in `.env`, then `ddev artisan optimize:clear`).
- **Add-on facts (v1.3.0)**: `config.frankenphp.yaml` sets `webserver_type: generic`, a `frankenphp` web extra daemon, port 80/443 exposure, and `webimage_extra_packages` including `frankenphp`, `php-zts-pdo-pgsql`, `php-zts-redis`. `Dockerfile.frankenphp` copies the FPM `php.ini` to `/etc/php-zts/php.ini` and points `/usr/bin/php` at `php-zts`. The Caddyfile serves `{$DDEV_APPROOT}/{$DDEV_DOCROOT}` with `php_server` and `encode zstd br gzip`; requests without `Accept-Encoding` get uncompressed bodies.
- **Approach**: generalise `ddev server` so each server declares its overlay content and an optional list of stash files to activate; the switch removes the previous server's activated files and copies the next server's.
- **Repos**: single repo `/Users/stasmarkov/work/interview`, dev URL https://interview.ddev.site.

## Decisions
1. Stash + activate instead of a normal add-on install, so FrankenPHP's overlay and ZTS Dockerfiles never affect the FPM servers (user choice, 2026-10-29).
2. Pin add-on v1.3.0 (latest release, 2026-10-09) and record it in BENCHMARKS.md (user choice).
3. Add-on default threads; tuning belongs to SL-06/SL-09 (user choice).
4. Parity's Server-header check uses an explicit token per server, because Caddy answers `Server: FrankenPHP Caddy`, not the server name.
5. FrankenPHP and ZTS PHP packages stay unpinned (the add-on installs them without versions); BENCHMARKS.md marks the frankenphp row as a point-in-time record to refresh after any image rebuild (user choice after review, 2026-10-29).
6. `ddev server <active server>` re-applies the overlay and activated files when they differ from the stash, instead of always printing `already active` (review fix, 2026-10-29).

## Open questions
- [x] Assumption: BR-02 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-29).
- [x] Assumption: BR-25 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-29).
- [x] Clarity-gate answers folded in above (raw tag files = complete install, overlay content, root `.gitignore`, snapshot format and `Accept-Encoding`, per-response header check and fallback, PHP major.minor match, image rebuild, stash location in tests, already-active no-op, backups for rollback, fetch method, seed determinism, version formats, PHP config under ZTS, Pest/Larastan). — **Accepted** (Stanislav Markov, 2026-10-29).

## Dependencies
- **Requires**: SL-02 (shipped).
- **Blocks**: SL-04 (Octane worker on FrankenPHP), SL-06 (benchmarks), SL-12, SL-13, SL-19.

---
Next: `/sdd spec-20261029-1221-frankenphp-classic` (approval → build)
