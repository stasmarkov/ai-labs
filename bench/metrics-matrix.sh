#!/usr/bin/env bash
## Usage: bench/metrics-matrix.sh [--quick] [--dry-run] [--force]
## Re-runs the slow-I/O scenarios with the metrics stack on and saves what the server showed during each run.
## 8 cells, `<workload>-<server>-<threads>`: the http and the sse workload of bench/slow-load.sh on
##   frankenphp-14     frankenphp with a fixed 14 threads
##   frankenphp-auto   frankenphp autoscale 14 to 64 threads (max-wait 5 s)
##   frankenphp-worker-4, frankenphp-octane-4   four workers
## Per cell the driver takes the epoch milliseconds just before and just after the slow-load call (the PNG shows that whole
## window), and a watcher notes when the slow-load load container first starts and last runs (the load window: warm-ups and
## measured repeats, without the restarts and .env switching). It then calls
## bench/metrics-snapshot.sh <cell> <from-ms> <to-ms> --load-window <load-from>-<load-to>.
## The watcher is also the keep-alive: slow-load.sh restarts the project, which removes the Prometheus and Grafana containers,
## so once the web container is healthy again it recreates them with `docker compose ... up --no-deps prometheus grafana`.
## It does one thing at a time (no concurrent starts), and it is stopped, and waited for, when the call returns or the
## driver exits. `ddev metrics on` also runs before and after each cell.
## Three control cells, http-frankenphp-14-nostack, http-frankenphp-worker-4-nostack and http-frankenphp-octane-4-nostack,
## run last with the stack off; each slow-load file against its mode's cell is the cost of the stack (none has a snapshot).
## One Playwright container renders every screenshot of the run (playwright-core is installed once) and is removed at the end.
## slow-load.sh and concurrency.sh keep their own .env and overlay handling and are not changed. A cell whose slow-load call or
## snapshot fails is reported at the end and the run goes on; the exit code is then 1. At the end it restores the default
## worker limits and the server it started on (the default server when the current one cannot be read), also when it fails.
## A full run refuses to overwrite a cell of the same day unless --force. --quick passes --quick to slow-load.sh and writes
## under the gitignored bench/results/raw/ (overwritten freely). --dry-run prints the commands and runs none of them.
## Result files: bench/results/metrics/<UTC date>/<cell>.{json,png} and bench/results/slow-load/<UTC date>/<cell>.json.
## Env: BENCH_APPROOT, BENCH_DATE, DDEV_SITENAME.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
# shellcheck source=lib/playwright.sh
source "$SCRIPT_DIR/lib/playwright.sh"

quick=()
dry=0
force=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --help|-h) sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed -n 's/^## \{0,1\}//p'; exit 0 ;;
    --quick) quick=(--quick); shift ;;
    --dry-run) dry=1; shift ;;
    --force) force=(--force); shift ;;
    *) echo "Usage: bench/metrics-matrix.sh [--quick] [--dry-run] [--force]" >&2; exit 2 ;;
  esac
done

DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
if [[ ${#quick[@]} -gt 0 ]]; then
  METRICS_OUT_DIR="$APPROOT/bench/results/raw/metrics/$DATE"
  SLOW_LOAD_DIR="$APPROOT/bench/results/raw"
  force=(--force)
else
  METRICS_OUT_DIR="$APPROOT/bench/results/metrics/$DATE"
  SLOW_LOAD_DIR="$APPROOT/bench/results/slow-load/$DATE"
fi
unset BENCH_OUT_DIR

SITE="${DDEV_SITENAME:-ai-lab}"
COMPOSE_FILE="$APPROOT/.ddev/.ddev-docker-compose-full.yaml"
PLAYWRIGHT_NAME="bench-metrics-playwright-$$"
WATCH_DIR=""
WATCH_PID=""
playwright_started=0
failed=()

step() {
  if [[ "$dry" == "1" ]]; then
    echo "$*"
  else
    "$@"
  fi
}

now_ms() {
  if [[ "$dry" == "1" ]]; then
    echo 0
  else
    echo "$(( $(date +%s) * 1000 ))"
  fi
}

running() {
  [[ "$(docker inspect -f '{{.State.Running}}' "$1" 2>/dev/null || true)" == "true" ]]
}

# Once a second: notes the load container's first and last second, and recreates the stack when the healthy web container
# has none. Runs until the flag file is removed; a start in progress finishes before it checks the flag again.
watch_loop() {
  local flag="$1" now
  while [[ -e "$flag" ]]; do
    now="$(( $(date +%s) * 1000 ))"
    if [[ -n "$(docker ps -q --filter 'name=^slow-load-load-' 2>/dev/null)" ]]; then
      [[ -s "$WATCH_DIR/load-from" ]] || echo "$now" > "$WATCH_DIR/load-from"
      echo "$(( now + 1000 ))" > "$WATCH_DIR/load-to"
    fi
    if [[ "$(docker inspect -f '{{.State.Health.Status}}' "ddev-${SITE}-web" 2>/dev/null || true)" == "healthy" ]] \
      && { ! running "ddev-${SITE}-prometheus" || ! running "ddev-${SITE}-grafana"; }; then
      docker compose -f "$COMPOSE_FILE" --profile metrics up -d --no-deps prometheus grafana >/dev/null 2>&1 || true
    fi
    sleep 1
  done
}

watch_start() {
  if [[ "$dry" == "1" ]]; then
    echo "keep-alive start"
    return
  fi
  WATCH_DIR="$(mktemp -d)"
  touch "$WATCH_DIR/run"
  watch_loop "$WATCH_DIR/run" &
  WATCH_PID=$!
}

watch_stop() {
  if [[ "$dry" == "1" ]]; then
    echo "keep-alive stop"
    return
  fi
  if [[ -n "$WATCH_PID" ]]; then
    rm -f "$WATCH_DIR/run"
    wait "$WATCH_PID" 2>/dev/null || true
    WATCH_PID=""
  fi
}

# Prints --load-window <from>-<to> for the last watched call, or nothing when no load container was seen.
load_window_args() {
  if [[ "$dry" == "1" ]]; then
    echo "--load-window LOAD_FROM-LOAD_TO"
    return
  fi
  if [[ -s "$WATCH_DIR/load-from" && -s "$WATCH_DIR/load-to" ]]; then
    echo "--load-window $(cat "$WATCH_DIR/load-from")-$(cat "$WATCH_DIR/load-to")"
  else
    echo "No slow-load load container was seen; the numbers cover the whole window." >&2
  fi
}

# shellcheck disable=SC2046
read_default_server "$APPROOT" $([[ "$dry" == "1" ]] && echo --dry-run)
START_SERVER="$(current_server "$APPROOT" 2>/dev/null)" || START_SERVER=""

# server | label | concurrency.sh arguments
settings=(
  "frankenphp|frankenphp-14|14"
  "frankenphp|frankenphp-auto|autoscale --threads 14 --max-threads 64"
  "frankenphp-worker|frankenphp-worker-4|4"
  "frankenphp-octane|frankenphp-octane-4|4"
)

if [[ ${#force[@]} -eq 0 ]]; then
  for setting in "${settings[@]}"; do
    IFS='|' read -r _ label _ <<< "$setting"
    for existing in "$METRICS_OUT_DIR" "$SLOW_LOAD_DIR"; do
      for workload in http sse; do
        if [[ -e "$existing/$workload-$label.json" ]]; then
          echo "$existing/$workload-$label.json already exists; pass --force to overwrite it." >&2
          exit 2
        fi
      done
    done
  done
fi

restore() {
  watch_stop
  if [[ "$playwright_started" == "1" ]]; then
    step playwright_stop "$PLAYWRIGHT_NAME"
  fi
  step bash "$SCRIPT_DIR/concurrency.sh" default || true
  if [[ -n "$START_SERVER" ]]; then
    (cd "$APPROOT" && step ddev server "$START_SERVER") || true
  else
    restore_default_server "$APPROOT" step || true
  fi
}
trap restore EXIT
trap 'exit 130' INT TERM

step playwright_start "$PLAYWRIGHT_NAME" "$METRICS_OUT_DIR"
playwright_started=1

for setting in "${settings[@]}"; do
  IFS='|' read -r server label args <<< "$setting"
  step ddev server "$server"
  # shellcheck disable=SC2086
  step bash "$SCRIPT_DIR/concurrency.sh" $args
  for workload in http sse; do
    cell="$workload-$label"
    users=100
    [[ "$workload" == "sse" ]] && users=30
    step ddev metrics on
    from_ms="$(now_ms)"
    watch_start
    status=0
    step bash "$SCRIPT_DIR/slow-load.sh" --workload "$workload" --users "$users" --label "$label" ${quick[@]+"${quick[@]}"} || status=$?
    watch_stop
    to_ms="$(now_ms)"
    if [[ "$status" != "0" ]]; then
      echo "slow-load.sh failed for $cell (exit $status); no snapshot." >&2
      failed+=("$cell (slow-load exit $status)")
      continue
    fi
    step ddev metrics on
    # shellcheck disable=SC2046
    step env BENCH_OUT_DIR="$METRICS_OUT_DIR" PLAYWRIGHT_CONTAINER="$PLAYWRIGHT_NAME" bash "$SCRIPT_DIR/metrics-snapshot.sh" \
      "$cell" "$from_ms" "$to_ms" $(load_window_args) ${force[@]+"${force[@]}"} || failed+=("$cell (snapshot)")
  done
done

# Stack-off controls, one per mode: the cost of the stack is each control against its mode's cell.
step ddev metrics off
for control in "frankenphp-worker|4|frankenphp-worker-4-nostack" "frankenphp-octane|4|frankenphp-octane-4-nostack" "frankenphp|14|frankenphp-14-nostack"; do
  IFS='|' read -r server threads label <<< "$control"
  step ddev server "$server"
  step bash "$SCRIPT_DIR/concurrency.sh" "$threads"
  step bash "$SCRIPT_DIR/slow-load.sh" --workload http --users 100 --label "$label" ${quick[@]+"${quick[@]}"} \
    || failed+=("http-$label (slow-load)")
done

if [[ ${#failed[@]} -gt 0 ]]; then
  printf 'Failed cells:\n' >&2
  printf '  %s\n' "${failed[@]}" >&2
  exit 1
fi
