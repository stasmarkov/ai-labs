# Benchmarks

## Versions

Rows come from `bench/versions.sh` (needs `/debug/runtime` enabled with `DEBUG_ROUTES=true`), one per server.

- **nginx-fpm and apache-fpm** are pinned: PHP 8.4 in `.ddev/config.yaml`, and the web server and PHP patch versions come from the DDEV web image, so the DDEV version pins them.
- **frankenphp is not pinned.** The add-on is pinned at v1.3.0, but it installs the `frankenphp` and `php-zts-*` packages without versions from pkg.henderkes.com. Any web-image rebuild that misses the Docker cache (a DDEV upgrade, `ddev utility rebuild`, another machine) can install newer FrankenPHP, Caddy and PHP patch releases. The frankenphp row is a point-in-time record: re-run `bench/versions.sh` after any rebuild and before every benchmark, and update the row.

| Active server | Web server version | DDEV version | PHP | SAPI | OPcache | Date |
|---------------|--------------------|--------------|-----|------|---------|------|
| nginx-fpm | 1.26.3 | 1.25.4 | 8.4.24 | fpm-fcgi | true | 2026-10-29 |
| apache-fpm | 2.4.68 | 1.25.4 | 8.4.24 | fpm-fcgi | true | 2026-10-29 |
| frankenphp | 1.13.4 | 1.25.4 | 8.4.27 | frankenphp | true | 2026-10-29 |

FrankenPHP row: ddev/ddev-frankenphp v1.3.0, Caddy v2.11.7

The FrankenPHP row runs classic mode (no worker) on thread-safe PHP, so its PHP patch version differs from the FPM rows.

## Response parity

`bench/parity/nginx-fpm.txt`, `bench/parity/apache-fpm.txt` and `bench/parity/frankenphp.txt` hold the status, media type and body SHA-256 of a fixed request set of 7 requests, captured on 2026-10-29 from the same seeded database on stock DDEV server configs (FrankenPHP from the add-on stash). `bench/parity.sh compare nginx-fpm apache-fpm`, `bench/parity.sh compare nginx-fpm frankenphp` and `bench/parity.sh compare apache-fpm frankenphp` all report `identical`.

Known differences that the comparison deliberately ignores:

- **Content-Type charset.** nginx serves JSON as `application/json; charset=utf-8`; Apache serves `application/json`. The app sends the same body; the parameter is added by nginx's `charset_types`. Parity compares the media type only.
- **`/up` render time.** Laravel's health page prints `rendered in <n>ms`, which changes on every request; it is masked before hashing, for `/up` only.
- **Headers** such as `Server` and `Date` differ by design and are not compared.
