# Manual test plan: worker-mode developer experience (SL-17)

## Step 1

Watch off is unchanged (MNC-1).

```sh
ddev dev-watch status
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
```

Expected:
- `status` prints `off`.
- Only the `captured=` line differs.

## Step 2

Edit to reload (AC-1, AC-2, AC-3).

1. Run:

   ```sh
   ddev server frankenphp-worker && ddev dev-watch on
   ```

2. Change the text returned by any route in `routes/web.php` and reload the page.
3. Repeat on `frankenphp-octane`.
4. Run `ddev dev-watch off` and edit again.

Expected:
- With watch on, both modes show the new text within about 2 s.
- With watch off, the old text stays until `ddev artisan frankenphp:workers:restart`.

## Step 3

Measurement and watcher cost (AC-6, NFR-2).

```sh
bench/reload-latency.sh
ddev dev-watch on && sleep 30 && docker stats --no-stream ddev-ai-lab-web
```

Expected:
- One JSON file per way of working.
- The BENCHMARKS table matches the files.
- Idle CPU stays below 5 %.

## Step 4

Xdebug in Octane (AC-4, MNC-3).

```sh
ddev server frankenphp-octane && ddev xdebug on
php bench/xdebug-check.php &
curl -sk "https://ai-lab.ddev.site/api/ping?XDEBUG_TRIGGER=1" >/dev/null
curl -sk https://ai-lab.ddev.site/api/ping >/dev/null
ddev xdebug off
```

Expected:
- The listener prints one `init` packet, for the request with the trigger only.
- After `off`, no connection is made.
