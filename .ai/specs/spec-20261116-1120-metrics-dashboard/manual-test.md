# Manual test plan: metrics dashboard (SL-15)

Run every command from the project root.

## Step 1

Opt-in stack (AC-2, MNC-1).

```sh
ddev restart && docker ps --format '{{.Names}}' | grep -E 'prometheus|grafana' || echo none
ddev metrics on
ddev metrics status
```

Expected:
- `none` after the plain restart.
- `on` prints `https://ai-lab.ddev.site:3000`, and `status` shows the target `web:9180` as up.
- Open the URL: the "FrankenPHP" dashboard loads with no login, and its panels show data.

## Step 2

Endpoint and exposure (AC-1, NFR-1).

```sh
for s in frankenphp frankenphp-worker frankenphp-octane; do
  ddev server $s >/dev/null
  docker run --rm --network ddev_default curlimages/curl -s http://ddev-ai-lab-web:9180/metrics | grep -c frankenphp_total_threads
  docker run --rm --network ddev_default curlimages/curl -s -m 3 http://ddev-ai-lab-web:2019/config/ || echo admin-closed
done
ddev server frankenphp
```

Expected: for each mode, a count of 1 or more and `admin-closed`.

## Step 3

Results (AC-4).

```sh
ls bench/results/metrics/*/
```

Expected:
- One `.json` and one `.png` per slow-load cell.
- Open two of the PNGs: the busy-threads panel rises during the load.

## Step 4

Nothing else changed (MNC-2, MNC-4).

```sh
git diff --quiet .ddev/config.yaml && echo cfg-ok
bench/asset-fingerprint.sh
PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
ddev metrics off
```

Expected:
- `cfg-ok` is printed.
- The fingerprint is unchanged.
- Only `captured=` differs.
- After `off`, no metrics container runs.
