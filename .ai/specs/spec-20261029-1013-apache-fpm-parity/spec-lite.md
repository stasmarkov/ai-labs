---
id: spec-20261029-1013-apache-fpm-parity
title: Apache-fpm server switch, response parity check and versions table
type: feature
size: S
priority: P2
status: done
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov
approved_at: 2026-10-29T08:40:48Z
created: 2026-10-29
stacks: [laravel]
slice: SL-02
brs: [BR-02, BR-25]
---

# Apache-fpm server switch, response parity check and versions table

## Summary
The developer can switch the DDEV web server between nginx-fpm and apache-fpm with one command, capture the responses of a fixed request set on each server, and prove they are identical. A guarded runtime endpoint shows which SAPI and web server answered and that OPcache is on, and BENCHMARKS.md gets a versions table. This is the first of the five server configs and sets the pattern later slices (FrankenPHP, Octane, OpenLiteSpeed) extend.

## Context
- **Problem**: Only nginx-fpm exists (`.ddev/config.yaml`, `webserver_type: nginx-fpm`). There is no way to switch servers without editing the committed config, no proof that two servers return the same responses, and no record of versions or OPcache state. `bench/` and `BENCHMARKS.md` do not exist; `README.md` is the stock Laravel README.
- **User impact**: The developer gets a repeatable switch and a parity check before any benchmark, so later numbers compare the same app.
- **Business rules (product-brief.md, draft)**:
  - **BR-02** For the same request and seed, every endpoint returns the same status and the same JSON body on all five server configs. *(This spec checks it for nginx-fpm and apache-fpm; it compares the HTML detail page body too.)*
  - **BR-25** Server, PHP, DDEV and add-on versions are pinned in config and recorded in BENCHMARKS.md for every result table. *(PHP is pinned in `.ddev/config.yaml`; nginx and Apache versions come from the DDEV web image, so they are pinned by the DDEV version recorded in the table.)*

## Scope
### In scope
- A custom DDEV host command `ddev server [nginx-fpm|apache-fpm]`.
- `bench/parity.sh` with `capture` and `compare` subcommands, and committed snapshots for both servers.
- `GET /debug/runtime`, off unless `DEBUG_ROUTES=true`.
- `bench/versions.sh` that prints one versions-table row for the current server.
- `BENCHMARKS.md` with the versions table filled for nginx-fpm and apache-fpm.
- A "Switching servers" section in `README.md`.
### Out of scope
- FrankenPHP, Octane and OpenLiteSpeed (SL-03, SL-04, SL-05). "Extendable" here means only: supported servers are one list in the command, each mapped to its overlay content, so a later slice adds an entry.
- Load testing and any RPS/latency numbers (SL-06).
- Custom Apache or nginx config; DDEV's generated configs are used as is.
- A CI check of the snapshots; they are point-in-time evidence, re-captured whenever endpoints or seed data change.
- Rewriting the rest of the stock README.

## Must NOT change
- **MNC-1**: `.ddev/config.yaml` is never modified by switching; its `webserver_type` stays `nginx-fpm`.
- **MNC-2**: Responses of `/up`, `/api/ping`, `/listings` and `/listings/{id}` on nginx-fpm are unchanged (existing Pest suite stays green without edits).
- **MNC-3**: CI (`.github/workflows/ci.yml`) keeps passing; `/debug/runtime` returns 404 in the test environment unless a test enables it.

## Acceptance criteria

**Active server** (used by AC-1–AC-4, AC-8, AC-11): the `webserver_type` in `.ddev/config.zz-server.yaml` if that file exists, otherwise the `webserver_type` in `.ddev/config.yaml`. `ddev server --current` prints only that value, for scripts.

- **AC-1**: WHEN the developer runs `ddev server apache-fpm` and the active server differs THE SYSTEM SHALL overwrite `.ddev/config.zz-server.yaml` with `webserver_type: apache-fpm`, run `ddev restart`, and print the active server.
- **AC-2**: WHEN the developer runs `ddev server nginx-fpm` and the active server differs THE SYSTEM SHALL overwrite the overlay with `webserver_type: nginx-fpm`, run `ddev restart`, and print the active server.
- **AC-3**: WHEN the developer runs `ddev server` with no argument THE SYSTEM SHALL print the active server and the supported values, and change nothing. WHEN the requested server is already active THE SYSTEM SHALL print `already active` and skip the restart.
- **AC-4**: IF the argument is not a supported server THEN THE SYSTEM SHALL exit 1, print the supported values, and leave the overlay and project untouched. IF `ddev restart` fails THEN THE SYSTEM SHALL restore the previous overlay (or remove it if there was none) and exit 1.
- **AC-5**: `.ddev/config.zz-server.yaml` SHALL be listed in `.gitignore`.
- **AC-6**: WHEN `config('app.debug_routes')` is true and the developer requests `GET /debug/runtime` THE SYSTEM SHALL return 200 in the `ApiResponse::success` envelope with `data` = `{ sapi: php_sapi_name(), php_version: PHP_VERSION, opcache_enabled: bool (the opcache_enabled key of opcache_get_status(false); false when the function is missing or returns false), server_software: SERVER_SOFTWARE server variable or null }`. Tests assert the keys and types only, not values (CLI and FPM differ).
- **AC-7**: WHILE `config('app.debug_routes')` is false THE SYSTEM SHALL answer `GET /debug/runtime` with the same status and body as `GET /debug/not-a-route` sent with the same `Accept` header (checked with `Accept: application/json` and with no `Accept` header). Headers are not compared.
- **AC-8 (BR-02)**: WHEN the developer runs `bench/parity.sh capture` THE SYSTEM SHALL request each path of the fixed request set against `PARITY_BASE_URL` (default `https://interview.ddev.site`) with `curl`, no redirect following, and write `bench/parity/<active server>.txt` in the snapshot format below.
- **AC-9 (BR-02)**: WHEN the developer runs `bench/parity.sh compare <a> <b>` (each argument a server name mapped to `bench/parity/<name>.txt`, or a path if it contains `/`) and every data line matches by position THE SYSTEM SHALL print `identical` and exit 0. IF any line or the line count differs THEN it SHALL print each differing path with both full lines and exit 1. IF a snapshot file is missing THEN it SHALL print which one and exit 2.
- **AC-10 (BR-02)**: `bench/parity/nginx-fpm.txt` and `bench/parity/apache-fpm.txt` SHALL be committed, captured from the same seeded database state (`ddev artisan migrate:fresh --seed` once before the first capture; switching web servers does not touch the database), and `compare nginx-fpm apache-fpm` on them SHALL print `identical`.
- **AC-11 (BR-25)**: WHEN the developer runs `bench/versions.sh` while `/debug/runtime` is enabled THE SYSTEM SHALL print one markdown table row: active server, web server version (from `ddev exec nginx -v` or `ddev exec apache2 -v`, version number only), DDEV version (from host `ddev version`), PHP version, SAPI, OPcache enabled (from `/debug/runtime`), date (UTC, `YYYY-MM-DD`). IF `/debug/runtime` returns 404 THEN it SHALL exit 1 with a message explaining how to enable it.
- **AC-12 (BR-25)**: `BENCHMARKS.md` SHALL contain a "Versions" table (header row matching AC-11's columns) with one row each for nginx-fpm and apache-fpm produced by `bench/versions.sh`, both with OPcache enabled `true` and SAPI `fpm-fcgi`.
- **AC-13**: `README.md` SHALL contain a "Switching servers" section with the `ddev server` command and supported values, the parity workflow (seed once, capture on each server, compare), how to enable `/debug/runtime` (below), and a note that Apache reads `public/.htaccess` on every request.

**Fixed request set** (in this order): `/up`, `/api/ping`, `/listings`, `/listings?make=BMW&fuel=diesel&per_page=5`, `/listings?per_page=99` (422), `/listings/1`, `/listings/999999` (404). All requests send no `Accept` header.

**Snapshot format**: first line `# server=<name> base=<url> captured=<ISO-8601 UTC>`; then one tab-separated line per request: `path` (as requested, with query string), `status`, `media type` (Content-Type without parameters such as charset, lowercased, trimmed), `sha256` of the body. Lines starting with `#` are ignored by `compare`. `capture` writes the file only after every request succeeds (else exit 3) and the `Server` header matches the active server (else exit 4).

**Enabling `/debug/runtime`**: set `DEBUG_ROUTES=true` in the project `.env` (Laravel reads it inside the container), then `ddev artisan optimize:clear` if config is cached. Scripts never change `.env`. It stays `false` in `.env.example` and for benchmarks.

**HTTPS**: curl trusts DDEV's mkcert certificate by default; `PARITY_INSECURE=1` adds `-k` for machines where mkcert is not trusted. Scripts run on the macOS host and on Linux (`shasum -a 256` or `sha256sum`, whichever exists).

## Boundaries
- **Always**: Pest tests for `/debug/runtime` (enabled and disabled); run gates through DDEV; follow existing conventions (final invokable controllers, `ApiResponse` envelope); leave the project on nginx-fpm with `DEBUG_ROUTES` back to `false` when done.
- **Ask first**: any new Composer or npm dependency; custom Apache or nginx config; changing the request set or adding headers to the comparison.
- **Never**: edit `.ddev/config.yaml` to switch servers; commit `.ddev/config.zz-server.yaml`; change existing endpoint behavior; leave `DEBUG_ROUTES=true` in `.env.example`.

## Technical context
- **Related code**: `.ddev/config.yaml`, `.ddev/commands/host/` (custom host commands), `bootstrap/app.php` (routing `then:` registers `routes/catalog.php` under the `api` middleware group; JSON error rendering), `app/Http/Controllers/PingController.php`, `app/Http/Responses/ApiResponse.php`, `public/.htaccess`.
- **Already implemented**: SL-01 endpoints and Pest suite (Pest 5.3 on PHPUnit 13, 56 tests; Larastan level 5 installed). The listing seed is deterministic (fixed seed), so listing 1 always exists after seeding. `.ddev/apache/apache-site.conf` is DDEV-generated and works with `public/.htaccess`.
- **Approach**: DDEV merges every `.ddev/config.*.yaml` over `config.yaml` in name order, so a gitignored `config.zz-server.yaml` overrides `webserver_type` last. `/debug/runtime` lives in a new `routes/debug.php`, registered in `bootstrap/app.php` next to `catalog.php` under the same `api` middleware group; it is always registered (so `route:cache` works) and its controller aborts 404 when `config('app.debug_routes')` is false. The config key `app.debug_routes` reads `env('DEBUG_ROUTES', false)`.
- **Repos**: single repo `/Users/stasmarkov/work/interview`, dev URL https://interview.ddev.site.

## Decisions
1. Switch via a custom `ddev server` host command writing a gitignored overlay — keeps `config.yaml` stable and extends to later servers (user choice, 2026-10-29).
2. Parity by snapshot + compare, because only one web server runs per DDEV project at a time (user choice).
3. OPcache proof via a guarded runtime route, because CLI and web SAPI differ and FrankenPHP/OLS have no `php-fpm` binary to inspect (user choice).
4. Compare status, content type and body hash; other headers (`Server`, `Date`, cookies) differ by design and are excluded.
5. Test framework is Pest 5.3 (SL-01 decision, 2026-10-29); the project `CLAUDE.md` still says PHPUnit and is out of date.
6. `/up` is hashed after masking `rendered in <n>ms` to `rendered in Nms`, for `/up` only, because Laravel prints the render time in the page. Confirmed by Stanislav Markov, 2026-10-29.
7. Parity compares the media type, not the full Content-Type: nginx adds `; charset=utf-8` to JSON via `charset_types`, Apache does not. Stock DDEV server configs stay unmodified (no custom Apache config). Chosen by Stanislav Markov, 2026-10-29; the difference is recorded in BENCHMARKS.md.

## Open questions
- [x] Assumption: BR-02 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-29).
- [x] Assumption: BR-25 is an unvalidated draft rule (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-29).
- [x] Clarity-gate answers folded in above (active-server rule, `.env` toggle, envelope shape, 404 reference request, version sources, seeded state, snapshot format, compare semantics, HTTPS, extendability, restart failure handling, route file, restore state). — **Accepted** (Stanislav Markov, 2026-10-29).

## Dependencies
- **Requires**: SL-01 (shipped).
- **Blocks**: SL-03, SL-05 (reuse `ddev server`, parity and versions scripts).

---
Next: `/sdd spec-20261029-1013-apache-fpm-parity` (approval → build) or `/execute-task --spec=spec-20261029-1013-apache-fpm-parity`
