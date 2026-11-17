# Manual test plan: structured logs and worker restart (SL-16)

## Step 1

Logs (AC-1, AC-3).

1. Switch to worker mode and open the log stream:

   ```sh
   ddev server frankenphp-worker
   ddev logs -f -s web
   ```

2. In a second terminal, switch the channel on, send a request and restore the settings:

   ```sh
   cp .env /tmp/env.before
   sed -i '' -e 's/^LOG_STACK=.*/LOG_STACK=single,frankenphp/' .env
   grep -q '^LOG_PING=' .env || echo 'LOG_PING=true' >> .env
   ddev restart
   curl -sk -D - -o /dev/null https://ai-lab.ddev.site/api/ping | grep -i x-request-id
   cp /tmp/env.before .env && ddev restart
   ```

Expected:
- One JSON entry in the web log stream with `request_id`, `method`, `path`, `route`, `server_mode: frankenphp-worker` and `worker: true`.
- The response header `X-Request-Id` equals that id.

## Step 2

Restart command (AC-6).

```sh
ddev artisan frankenphp:workers:restart
ddev server frankenphp-octane && ddev artisan frankenphp:workers:restart
ddev server frankenphp && ddev artisan frankenphp:workers:restart
```

Expected:
- **Worker mode:** "workers restarted in N ms", exit 0.
- **Octane:** the `octane:reload` output, exit 0.
- **Classic:** "no workers to restart", exit 0.

## Step 3

Restart under load (AC-7).

```sh
bench/restart-load.sh
ls bench/results/restart/*/
```

Expected:
- One JSON per mode and route.
- The BENCHMARKS table matches them.

## Step 4

Other checks (MNC-2, NFR-2).

```sh
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
ddev artisan test --compact
```

Expected:
- The fingerprint is unchanged.
- Parity differs only in `captured=`.
- The suite passes.
- The overhead ping in tasks.md is below 5 %.
