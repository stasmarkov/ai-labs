# Changes from ddev/ddev-frankenphp v1.3.0

`web-build/Caddyfile.frankenphp` is the add-on Caddyfile with these changes. The other three activated files come unchanged from `.ddev/servers/frankenphp/`.

- Removed the `#ddev-generated` first line, so a DDEV add-on update does not treat this edited copy as its own.
- Removed the `@secure` matcher and the `php_server @secure` block in `:80`, and instead added `map {header.X-Forwarded-Proto} {forwarded_https}` plus `env HTTPS {forwarded_https}` on the single `php_server`. The router ends TLS and forwards plain HTTP with `X-Forwarded-Proto: https`; the map turns that into `HTTPS=on` per request (else `off`), so Laravel generates `https://` URLs without trusting proxy headers in app code. Worker-level `env` is fixed when the worker boots, but `php_server` `env` is set per request.
- Merged the two `php_server` blocks of `:80` into one, so there is a single worker pool.
- Added to that `php_server`: `index frankenphp-worker.php`, `try_files {path} frankenphp-worker.php` and `resolve_root_symlink` (the same as Octane's own Caddyfile), so existing files such as `/build/*` assets are served by Caddy and every other request goes to the worker script.
- Added a `worker` block to that `php_server`: file `public/frankenphp-worker.php`, `num 4`, and the environment Octane passes that the worker script reads (`FRANKENPHP_WORKER`, `APP_BASE_PATH`, `APP_PUBLIC_PATH`, `LARAVEL_OCTANE`, `MAX_REQUESTS 500`, `REQUEST_MAX_EXECUTION_TIME 30`, the same 30 s per-request limit Octane passes from `octane.max_execution_time`).
- Replaced the `php_server` of `:443` with `reverse_proxy localhost:80`, so direct HTTPS access reaches the same single worker pool. `tls` is unchanged.
