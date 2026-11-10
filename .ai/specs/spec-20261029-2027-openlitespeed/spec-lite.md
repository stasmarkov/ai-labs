---
id: spec-20261029-2027-openlitespeed
title: OpenLiteSpeed + LSAPI as a switchable DDEV service with parity and versions
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov
approved_at: 2026-10-30T08:55:16Z
created: 2026-10-29
stacks: [laravel]
slice: SL-05
brs: [BR-02, BR-25]
---

# OpenLiteSpeed + LSAPI as a switchable DDEV service with parity and versions

## Summary
The developer runs `ddev server openlitespeed` and an OpenLiteSpeed (OLS) container serves the unchanged app through LSAPI with lsphp 8.4, the `pgsql` and `redis` extensions and OPcache, at `https://ai-lab.ddev.site:8091`. Its responses match the other three servers in the parity check, and its versions are in BENCHMARKS.md. Switching to any other server removes the OLS container.

## Context
- **Problem**: OLS is not a DDEV `webserver_type`, so it cannot replace the `web` container. It has to run as an extra Docker Compose service next to `web`, with its own port, and reach the same code, PostgreSQL and Redis.
- **User impact**: The fourth of five server configs; SL-06 benchmarks it against nginx-fpm, apache-fpm and FrankenPHP classic.
- **Business rules (product-brief.md, draft)**:
  - **BR-02** For the same request and seed, every endpoint returns the same status and the same JSON body on all five server configs.
  - **BR-25** Server, PHP, DDEV and add-on versions are pinned in config and recorded in BENCHMARKS.md for every result table.

## Scope
### In scope
- A stash `.ddev/servers/openlitespeed/` (committed) with: a Dockerfile `FROM litespeedtech/openlitespeed:1.9.4-lsphp84` that installs `lsphp84-pgsql` and copies the OLS config; the OLS config files (server config with an HTTP listener on 8088 and the LSAPI external app, plus one vhost); and `docker-compose.openlitespeed.yaml`.
- `ddev server openlitespeed`: activates the compose file and writes an overlay that keeps `web` on nginx-fpm.
- Per-server default base URL in `bench/parity.sh` and `bench/versions.sh`, the `litespeed` header token, and an OLS version command.
- A committed `bench/parity/openlitespeed.txt` that compares `identical` with the other three snapshots.
- An openlitespeed row in BENCHMARKS.md and README updates.
### Out of scope
- WebAdmin (port 7080) — not exposed; configuration lives only in committed files.
- LSAPI tuning (`PHP_LSAPI_CHILDREN`, instances, max connections) beyond what is needed to serve; tuning is SL-06.
- LSCache, HTTP/3, compression settings.
- Making OLS answer on the main `https://ai-lab.ddev.site` URL.

## Must NOT change
- **MNC-1**: `.ddev/config.yaml` is never modified.
- **MNC-2**: nginx-fpm, apache-fpm and frankenphp behave as in SL-02/SL-03: after switching away from openlitespeed, no OLS container runs (`docker ps` shows none for this project), `.ddev/docker-compose.openlitespeed.yaml` does not exist, and a fresh capture on nginx-fpm compares `identical` with its committed snapshot.
- **MNC-3**: App code and existing tests are unchanged except where a test enumerates supported servers; the Pest suite and CI stay green.

## Acceptance criteria

**Stash** (`.ddev/servers/openlitespeed/`, committed): `Dockerfile`, `docker-compose.openlitespeed.yaml`, `conf/httpd_config.conf`, `conf/vhosts/interview/vhconf.conf`, and `VERSION` containing `litespeedtech/openlitespeed:1.9.4-lsphp84`. **Activated file** (copy, listed in the repo-root `.gitignore`): `.ddev/docker-compose.openlitespeed.yaml`. The compose service is named `openlitespeed`, builds from `.ddev/servers/openlitespeed`, mounts the project at `/var/www/html` (the same path as the `web` container, so cached Laravel config and compiled-view paths match), joins the DDEV network so `db` and `redis` resolve, and is routed by the DDEV router with `HTTPS_EXPOSE: 8091:8088` on the project hostname (`VIRTUAL_HOST: $DDEV_HOSTNAME`; TLS ends at the router, OLS receives plain HTTP). It follows DDEV's extra-service conventions: `container_name: ddev-${DDEV_SITENAME}-openlitespeed` and the `com.ddev.site-name` / `com.ddev.approot` labels. No HTTP-only port is exposed.

- **AC-1**: WHEN the developer runs `ddev server openlitespeed` THE SYSTEM SHALL copy the stash compose file into `.ddev/`, write `.ddev/config.zz-server.yaml` as `# server: openlitespeed` followed by `webserver_type: nginx-fpm`, run `ddev restart`, and print `Active: openlitespeed`.
- **AC-2**: WHEN the developer switches from openlitespeed to any other server THE SYSTEM SHALL delete `.ddev/docker-compose.openlitespeed.yaml`, write that server's overlay and restart, so no OLS container is left running.
- **AC-3**: `ddev server` with no argument SHALL print `Supported: nginx-fpm apache-fpm frankenphp openlitespeed`; `--current`, the already-active check, stash completeness check and rollback SHALL behave for openlitespeed as they do for frankenphp.
- **AC-4**: WHILE openlitespeed is active, `https://ai-lab.ddev.site:8091/up` SHALL return 200 with a `Server` header containing `litespeed` (case-insensitive), and `https://ai-lab.ddev.site/up` (the idle nginx-fpm `web` container) SHALL still return 200.
- **AC-5**: The OLS vhost SHALL use `/var/www/html/public` as document root, load `public/.htaccess` rewrite rules, and pass every non-file request to `index.php` through LSAPI, so `/listings`, `/listings/1`, `/api/ping` and unknown paths are answered by Laravel. Laravel SHALL be able to write `storage/` and `bootstrap/cache/` from the lsphp process (no permission errors in `storage/logs` and compiled views written).
- **AC-6 (BR-25)**: WHILE openlitespeed is active and `/debug/runtime` is enabled, `https://ai-lab.ddev.site:8091/debug/runtime` SHALL report `sapi` = `litespeed`, `opcache_enabled` = `true`, and a `php_version` with major.minor 8.4; `ddev exec -s openlitespeed php -m` SHALL list `pdo_pgsql` and `redis`.
- **AC-7 (BR-02)**: `bench/parity.sh capture` and `bench/versions.sh` SHALL read the active server from `ddev server --current` (as today) and default their base URL per active server: `https://ai-lab.ddev.site:8091` for openlitespeed, `https://ai-lab.ddev.site` for the others; `PARITY_BASE_URL` still overrides. The header token map gains `openlitespeed → litespeed`. Before hashing, `capture` replaces every occurrence of its base URL in the body with `https://ai-lab.ddev.site`, so absolute links built from the request host (asset and image URLs on the detail page) compare equal; for the default servers this is a no-op.
- **AC-8 (BR-02)**: `bench/parity/openlitespeed.txt` SHALL be committed, captured from the same seeded database, and `bench/parity.sh compare nginx-fpm openlitespeed` SHALL print `identical` (and therefore matches apache-fpm and frankenphp, which are identical to nginx-fpm).
- **AC-9 (BR-25)**: WHEN `bench/versions.sh` runs while openlitespeed is active THE SYSTEM SHALL print a row whose web-server column is the OLS version number from `ddev exec -s openlitespeed /usr/local/lsws/bin/lshttpd -v` (e.g. `1.9.4`); PHP version, SAPI and OPcache come from `/debug/runtime` on the openlitespeed base URL (so `DEBUG_ROUTES=true` is needed, as for the other servers).
- **AC-10 (BR-25)**: BENCHMARKS.md SHALL have an openlitespeed row pasted from `bench/versions.sh` (SAPI `litespeed`, OPcache `true`), state that the row is pinned by the image tag `1.9.4-lsphp84` except for the `lsphp84-pgsql` package installed at build time (unversioned, so a point-in-time record), name `bench/parity/openlitespeed.txt` in Response parity with its compare result, and note that OLS listens on its own port while `web` stays idle on nginx-fpm.
- **AC-11**: README's "Switching servers" section SHALL list `openlitespeed`, give its URL `https://ai-lab.ddev.site:8091`, say the first switch builds the image, and note that OLS reads `.htaccess` rewrite rules at start-up, so changes to them need a `ddev restart` (unlike Apache).

**Clarifications from the clarity gate**
- **HTTPS seen by Laravel.** The vhost sets `HTTPS=on` for requests the router forwards with `X-Forwarded-Proto: https` (the FrankenPHP add-on's Caddyfile does the same), so generated URLs use `https://`. No app or `.env` change.
- **Base URL in bodies.** Accepted difference, handled by parity normalisation (AC-7, user choice 2026-10-30) and documented in BENCHMARKS.md.
- **lsphp user and storage.** The image creates a user with the host's UID/GID (compose build args from `$DDEV_UID` / `$DDEV_GID`, which DDEV provides) and the LSAPI external app and OLS run PHP as that user, so files written by either container have the same owner. No chmod of `storage/`.
- **Mount and Mutagen.** DDEV uses Mutagen on this Mac: `web` sees a synced volume, OLS bind-mounts the host directory `${DDEV_APPROOT}`. Writes reach the other side after a short sync delay; run `ddev mutagen sync` before capturing on openlitespeed if caches were just rebuilt in `web`.
- **PHP config.** lsphp keeps its own `php.ini` except `date.timezone=UTC` and OPcache settings copied from the `web` container's FPM pool (`conf/zz-opcache.ini`: memory 500 MB, 1 000 000 files, interned strings 16 MB, `validate_timestamps` on, `revalidate_freq` 2). Changed after review, 2026-10-30: the lsphp defaults (128 MB, 10 000 files) would fill up on this app and skew SL-06. Dates in responses come from the app's UTC timezone, not `php.ini`.
- **Environment.** Laravel reads only `.env`; no container environment needs to reach the LSAPI workers.
- **OLS config install.** The Dockerfile copies the two committed files over the image defaults in `/usr/local/lsws/conf/` (the entrypoint only restores defaults when `conf/` is empty). The admin listener stays as in the image but is not published.
- **`.htaccess`.** OLS applies only its rewrite rules (with auto-load on). The other directives (Authorization header passing, trailing-slash redirect, MultiViews) are not replicated: the parity request set uses none of them. Any difference found later is a SL-06 note.
- **Extensions.** `lsphp84-pgsql` provides both `pgsql` and `pdo_pgsql`. The CLI `php -m` check plus the DB-backed endpoints answering through LSAPI prove the extension works in the server SAPI.
- **Stash completeness and rollback.** The stash completeness check covers all five stash files; rollback is the existing code path (restore overlay and activated files, retry one restart).
- **Seed.** Same `db` container and deterministic seed (SL-01); no reseed before capturing.
- **Idle `web` container.** Acceptable here; SL-06 decides how to account for it.
- **Tests allowed to change.** `DdevServerCommandTest` (the supported-list assertion) and `BenchmarksDocTest` (row-count assertions). Tests are Pest 5.3; the project `CLAUDE.md` is out of date on PHPUnit and MariaDB.

## Boundaries
- **Always**: Pest tests for the new `ddev server`, parity and versions behaviour using the existing stub patterns; gates through DDEV; leave the project on nginx-fpm with `DEBUG_ROUTES` off.
- **Ask first**: exposing WebAdmin; a different image tag; LSAPI tuning; changing app code or `.htaccess`.
- **Never**: modify `.ddev/config.yaml`; commit the activated compose file; leave an OLS container running after switching away.

## Technical context
- **Related code**: `.ddev/commands/host/server` (`SUPPORTED`, `overlay_for`, `activated_files`, `stash_files`, rollback), `.ddev/servers/frankenphp/` (stash pattern), `bench/parity.sh` (`header_token`, `PARITY_BASE_URL`), `bench/versions.sh` (per-server `case`), `tests/Feature/DdevServerCommandTest.php`, `tests/Feature/BenchScriptsTest.php`, `tests/Feature/BenchmarksDocTest.php`, `tests/Feature/ReadmeSwitchingServersTest.php`.
- **Image facts** (upstream `litespeedtech/ols-dockerfiles` template): Ubuntu base; OLS installed in `/usr/local/lsws`; the LiteSpeed apt repo is configured; `lsphp84`, `-common`, `-mysql`, `-curl`, `-imagick`, `-redis`, `-memcached`, `-intl` and `-opcache` are preinstalled; `/usr/bin/php` links to lsphp's CLI; the entrypoint starts OLS with `lswsctrl start` and copies `/usr/local/lsws/.conf` to `conf/` only when `conf/` is empty; the template's HTTP listener is on 8088.
- **Approach**: generalise the per-server base URL in the bench scripts; add openlitespeed to `ddev server` with an overlay that keeps `web` on nginx-fpm and one activated compose file.
- **Repos**: single repo `/Users/stasmarkov/work/interview`.

## Decisions
1. Activated by `ddev server openlitespeed`, not always on, so the OLS container never competes with the other servers for CPU and memory (user choice, 2026-10-29).
2. Configuration only in committed files; WebAdmin not exposed (user choice).
3. Pin image `litespeedtech/openlitespeed:1.9.4-lsphp84` (latest, 2026-10-29) (user choice).
4. Own port (8090/8091) on the project hostname instead of a sub-subdomain, because DDEV's certificate covers `ai-lab.ddev.site` but `*.ddev.site` does not cover `ols.ai-lab.ddev.site`.
5. Mount at `/var/www/html` so paths in Laravel's cached config and compiled views are valid in both containers.
6. Routing is left entirely to `public/.htaccess`; the vhost only sets `HTTPS=on` from `X-Forwarded-Proto`. A vhost-level index.php rule ran before `.htaccess` and suppressed its trailing-slash redirect (review fix, 2026-10-30).
7. WebAdmin is disabled (`disableWebAdmin 1`), not only unpublished, so the config lives only in the committed files (review fix, 2026-10-30).
8. The server → base URL mapping lives in one place, `ddev server --url`, read by `bench/parity.sh` and `bench/versions.sh` (review fix, 2026-10-30).

## Open questions
- [x] Assumption: BR-02 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-30).
- [x] Assumption: BR-25 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-30).
- [x] Clarity-gate answers folded in above (HTTPS in vhost, base-URL normalisation, UID/GID user, bind mount + Mutagen, php.ini, env, config install, `.htaccess`, extensions, stash/rollback, seed, idle web, tests allowed to change). — **Accepted** (Stanislav Markov, 2026-10-30).

## Dependencies
- **Requires**: SL-02, SL-03 (shipped).
- **Blocks**: SL-06 (four-server benchmark).

---
Next: `/sdd spec-20261029-2027-openlitespeed` (approval → build)
