# Benchmarks

## Versions

Rows come from `bench/versions.sh` (needs `/debug/runtime` enabled with `DEBUG_ROUTES=true`), one per server. PHP is pinned in `.ddev/config.yaml`; nginx and Apache versions come from the DDEV web image, so they are pinned by the DDEV version.

| Active server | Web server version | DDEV version | PHP | SAPI | OPcache | Date |
|---------------|--------------------|--------------|-----|------|---------|------|
| nginx-fpm | 1.26.3 | 1.25.4 | 8.4.24 | fpm-fcgi | true | 2026-10-29 |
| apache-fpm | 2.4.68 | 1.25.4 | 8.4.24 | fpm-fcgi | true | 2026-10-29 |

## Response parity

`bench/parity/nginx-fpm.txt` and `bench/parity/apache-fpm.txt` hold the status, media type and body SHA-256 of a fixed request set of 7 requests, captured on 2026-10-29 from the same seeded database on stock DDEV server configs. `bench/parity.sh compare nginx-fpm apache-fpm` reports `identical`.

Known differences that the comparison deliberately ignores:

- **Content-Type charset.** nginx serves JSON as `application/json; charset=utf-8`; Apache serves `application/json`. The app sends the same body; the parameter is added by nginx's `charset_types`. Parity compares the media type only.
- **`/up` render time.** Laravel's health page prints `rendered in <n>ms`, which changes on every request; it is masked before hashing, for `/up` only.
- **Headers** such as `Server` and `Date` differ by design and are not compared.
