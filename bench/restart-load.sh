#!/usr/bin/env bash
## Usage: bench/restart-load.sh [--quick] [--modes <list>]
## Measures what `frankenphp:workers:restart` does to requests in flight: a closed load of 50 connections runs on a route
## while the command restarts the workers once, half way through the window.
## Modes (default: frankenphp-worker frankenphp-octane); routes: /api/ping and /listings/1. One cell per mode and route.
## The load is oha in a pinned one-off container on ddev_default, capped at 2 CPUs and reaching the DDEV router through --add-host.
## Default method: warm-up 15 s, measure 30 s, restart at 15 s. --quick (development only): warm-up 3 s, measure 10 s,
## and no clean-git-tree check.
## The harness sets .env to APP_ENV=production, APP_DEBUG=false, DEBUG_ROUTES=true, runs `artisan optimize`, and on exit
## restores .env and switches back to the project's default server.
## Output: bench/results/restart/<UTC date>/<mode>-<route>.json (--quick: the gitignored bench/results/raw/)
## with the restart command's output and exit code, the load figures, the failures and their share.
## Env: BENCH_WARMUP, BENCH_MEASURE (seconds), BENCH_APPROOT, BENCH_OHA_IMAGE, BENCH_READY_TIMEOUT (default 120), BENCH_DATE.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"
# shellcheck source=lib/health-probe.sh
source "$SCRIPT_DIR/lib/health-probe.sh"
OHA_IMAGE="${BENCH_OHA_IMAGE:-ai-lab-oha:1.16.0}"   # built locally from bench/oha/Dockerfile
CONNECTIONS=50
ROUTES=(/api/ping /listings/1)

usage() {
  echo "Usage: $0 [--quick] [--modes <frankenphp-worker,frankenphp-octane>]" >&2
  exit 2
}

quick=0
modes="frankenphp-worker,frankenphp-octane"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --quick) quick=1; shift ;;
    --modes) modes="${2:-}"; shift 2 || usage ;;
    *) usage ;;
  esac
done
IFS=',' read -r -a MODE_LIST <<< "$modes"
for mode in "${MODE_LIST[@]}"; do
  [[ "$mode" == "frankenphp-worker" || "$mode" == "frankenphp-octane" ]] || usage
done

if [[ "$quick" == "1" ]]; then
  WARMUP="${BENCH_WARMUP:-3}"
  MEASURE="${BENCH_MEASURE:-10}"
else
  WARMUP="${BENCH_WARMUP:-15}"
  MEASURE="${BENCH_MEASURE:-30}"
fi
READY_TIMEOUT="${BENCH_READY_TIMEOUT:-120}"
RESTART_AT=$((MEASURE / 2))

cd "$APPROOT"
ENV_FILE="$APPROOT/.env"
BACKUP="$APPROOT/.env.bench-backup"
WORK="$(mktemp -d)"
LOAD_NAME="restart-load-$$"
env_switched=0

read_default_server "$APPROOT"

restore_all() {
  docker rm -f "$LOAD_NAME" >/dev/null 2>&1 || true
  if [[ "$env_switched" == "1" && -f "$BACKUP" ]]; then
    cp -p "$BACKUP" "$ENV_FILE"
    rm -f "$BACKUP"
    ddev artisan optimize:clear >/dev/null 2>&1 || true
    restore_default_server "$APPROOT" >/dev/null 2>&1 || true
    ddev restart >/dev/null 2>&1 || true
  fi
  rm -rf "$WORK"
}
trap restore_all EXIT
trap 'exit 130' INT TERM

refuse() {
  echo "Run condition failed: $1" >&2
  exit 2
}

set_env_value() {
  local key="$1" value="$2"
  { grep -v "^${key}=" "$ENV_FILE" || true; echo "${key}=${value}"; } > "$WORK/env.new"
  cat "$WORK/env.new" > "$ENV_FILE"
}

if [[ -e "$BACKUP" ]]; then
  echo "Stale $BACKUP exists: a previous run did not restore .env. Restore it by hand first." >&2
  exit 2
fi
[[ -f "$ENV_FILE" ]] || { echo "No .env at $ENV_FILE" >&2; exit 2; }
if [[ "$quick" == "0" ]]; then
  # .ai/session/ is the SDD tooling's own log, rewritten by the tooling itself; it is not app code.
  [[ -z "$(git status --porcelain -- . ':!bench/results' ':!.ai/session' 2>/dev/null || true)" ]] \
    || refuse "git working tree has changes outside bench/results/ and .ai/session/"
fi

DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
if [[ "$quick" == "1" ]]; then
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/raw}"
else
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/restart/$DATE}"
fi
mkdir -p "$OUT_DIR"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

cp -p "$ENV_FILE" "$BACKUP"
env_switched=1
set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value DEBUG_ROUTES true

wait_ready() {
  local deadline=$((SECONDS + READY_TIMEOUT))
  until [[ "$(curl -ksS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/healthz" 2>/dev/null || true)" == "200" ]]; do
    [[ "$SECONDS" -lt "$deadline" ]] || refuse "the server did not answer /healthz with 200 within ${READY_TIMEOUT}s"
    sleep 1
  done
}

lookup_router
OHA_DIGEST="$(image_digest "$OHA_IMAGE")"
[[ -n "$OHA_DIGEST" ]] || { echo "Cannot read the digest or ID of $OHA_IMAGE; refusing to run." >&2; exit 1; }

for mode in "${MODE_LIST[@]}"; do
  ddev server "$mode" >/dev/null
  ddev artisan optimize >/dev/null
  ddev restart >/dev/null
  server="$(current_server "$APPROOT")" || exit 2
  [[ "$server" == "$mode" ]] || refuse "expected $mode, the server is $server"
  BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
  HOST="${BASE_URL#*://}"
  HOST="${HOST%%/*}"
  HOST_NAME="${HOST%%:*}"
  wait_ready

  for route in "${ROUTES[@]}"; do
    name="${route#/}"
    name="${name//\//-}"
    docker_run "$LOAD_NAME" "$OHA_IMAGE" -c "$CONNECTIONS" -z "${WARMUP}s" -w -t 30s --no-tui --insecure \
      --output-format json "$BASE_URL$route" > /dev/null

    docker_run "$LOAD_NAME" "$OHA_IMAGE" -c "$CONNECTIONS" -z "${MEASURE}s" -w -t 30s --no-tui --insecure \
      --output-format json "$BASE_URL$route" > "$WORK/load.json" &
    load_pid=$!
    sleep "$RESTART_AT"
    restart_status=0
    restart_output="$(ddev artisan frankenphp:workers:restart 2>&1)" || restart_status=$?
    load_status=0
    wait "$load_pid" || load_status=$?
    [[ "$load_status" == "0" ]] || { echo "The load generator failed (exit $load_status)." >&2; exit 1; }
    wait_ready

    file="$OUT_DIR/$mode-$name.json"
    parse_oha load < "$WORK/load.json" | jq \
      --arg server "$mode" --arg route "$route" --arg commit "$COMMIT" --arg date "$DATE" --arg base "$BASE_URL" \
      --arg image "$OHA_IMAGE" --arg digest "$OHA_DIGEST" --arg output "$restart_output" \
      --argjson status "$restart_status" --argjson connections "$CONNECTIONS" --argjson warmup "$WARMUP" \
      --argjson measure "$MEASURE" --argjson restart_at_s "$RESTART_AT" --argjson quick "$quick" '
      {server: $server, route: $route, commit: $commit, date: $date, base_url: $base, quick: ($quick == 1),
       connections: $connections, warmup_s: $warmup, measure_s: $measure, restart_at_s: $restart_at_s,
       load_image: $image, load_image_digest: $digest,
       restart: {exit_code: $status, output: $output},
       load: .}' > "$file"
    jq -r '"\(.server) \(.route): restart exit \(.restart.exit_code), \(.load.requests) requests, errors \(.load.errors) (\(.load.error_percent | . * 100 | round / 100)%), p95 \(.load.p95_ms | . * 10 | round / 10) ms, p99 \(.load.p99_ms | . * 10 | round / 10) ms"' "$file"
    echo "Wrote $file"
  done
done
