#!/usr/bin/env bash
## Usage: bench/leaks.sh [--max-requests <n>]
## Counts state leaks on the active server. For each lab scenario and mode (/debug/leaks/{scenario}) it
## restarts the workers so the row starts clean, sends request A (dealer 1, locale uk) 20 times so every
## worker in the pool has served A, then sends request B (dealer 2, locale de; no locale for number-locale)
## 20 times, and prints "leaked n/20" (n = B responses with data.leaked true).
## The script sets DEBUG_ROUTES=true in .env (backed up and restored on exit, like bench/run.sh) and
## refuses (exit 2) when the lab routes still answer anything but 200.
## --max-requests <n> (frankenphp-octane only) restarts the daemon with --max-requests=<n> through the
## gitignored overlay .ddev/config.zzz-bench-leaks.yaml, a copy of the stash's daemon config with the
## value changed; the overlay is removed and DDEV restarted again on exit, restoring the stash's value.
## Env: BENCH_APPROOT (project root), BENCH_BASE_URL (default: `ddev server --url`),
##      BENCH_SETTLE (seconds to wait for the server after a restart, default 60).

set -Eeuo pipefail
# shellcheck source=lib/servers.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/servers.sh"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
ENV_FILE="$APPROOT/.env"
BACKUP="$APPROOT/.env.bench-backup"
OVERLAY="$APPROOT/.ddev/config.zzz-bench-leaks.yaml"
STASH_DAEMON="$APPROOT/.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml"
SETTLE="${BENCH_SETTLE:-60}"
REQUESTS=20
PARALLEL=8
SCENARIOS=(dealer-scope price-cache number-locale request-at-boot)
MODES=(leak fixed)

usage() {
  echo "Usage: $0 [--max-requests <n>] (n is a positive integer)" >&2
  exit 2
}

refuse() {
  echo "Cannot run: $1" >&2
  exit 2
}

max_requests=""
while [[ $# -gt 0 ]]; do
  case "$1" in
    --max-requests)
      [[ "${2:-}" =~ ^[1-9][0-9]*$ ]] || usage
      max_requests="$2"
      shift 2
      ;;
    *) usage ;;
  esac
done

cd "$APPROOT"
command -v jq >/dev/null 2>&1 || { echo "jq is required on the host (brew install jq)." >&2; exit 2; }
WORK=""
env_switched=0
overlay_written=0

# After a .env change: frankenphp-octane must restart its daemon, because `octane:frankenphp` copies the
# .env values into the FrankenPHP process environment at start and a reload keeps them.
apply_env_change() {
  if [[ "$server" == "frankenphp-octane" ]]; then
    ddev restart >/dev/null 2>&1 || true
  else
    restart_workers
  fi
}

restart_workers() {
  case "$server" in
    frankenphp-octane) ddev artisan octane:reload --server=frankenphp >/dev/null 2>&1 || true ;;
    frankenphp | frankenphp-worker) ddev exec curl -sf -X POST localhost:2019/frankenphp/workers/restart >/dev/null 2>&1 || true ;;
  esac
}

wait_ready() {
  local i status
  for ((i = 0; i < SETTLE; i++)); do
    status="$(curl -ksS --max-time 10 -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$BASE_URL/debug/worker" 2>/dev/null)" || status=000
    [[ "$status" == "200" ]] && return 0
    sleep 1
  done
  return 1
}

cleanup() {
  local code=$?
  trap - EXIT
  if [[ "$env_switched" == "1" && -f "$BACKUP" ]]; then
    cp -p "$BACKUP" "$ENV_FILE"
    rm -f "$BACKUP"
    ddev artisan optimize:clear >/dev/null 2>&1 || true
  fi
  if [[ "$overlay_written" == "1" ]]; then
    rm -f "$OVERLAY"
    ddev restart >/dev/null 2>&1 || true
  elif [[ "$env_switched" == "1" ]]; then
    apply_env_change
  fi
  if [[ "$env_switched" == "1" && -n "${BASE_URL:-}" ]]; then
    local status
    status="$(curl -ksS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/debug/worker" 2>/dev/null)" || status=000
    [[ "$status" == "404" ]] || echo "Warning: /debug/worker answers $status after restoring .env (expected 404)." >&2
  fi
  [[ -n "$WORK" ]] && rm -rf "$WORK"
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

if [[ -e "$BACKUP" ]]; then
  echo "Stale $BACKUP exists: a previous run did not restore .env. Restore it by hand first." >&2
  exit 2
fi
[[ -f "$ENV_FILE" ]] || refuse "no .env at $ENV_FILE"

server="$(current_server .)" || exit 2
BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
if [[ -n "$max_requests" && "$server" != "frankenphp-octane" ]]; then
  refuse "--max-requests needs the frankenphp-octane server (active: $server)"
fi
WORK="$(mktemp -d)"

cp -p "$ENV_FILE" "$BACKUP"
env_switched=1
{ grep -v '^DEBUG_ROUTES=' "$ENV_FILE" || true; echo "DEBUG_ROUTES=true"; } > "$WORK/env.new"
cat "$WORK/env.new" > "$ENV_FILE"
ddev artisan optimize:clear >/dev/null 2>&1 || true

if [[ -n "$max_requests" ]]; then
  [[ -f "$STASH_DAEMON" ]] || refuse "no daemon config at $STASH_DAEMON"
  {
    echo "override_config: true"
    sed -E "s/--max-requests=[0-9]+/--max-requests=$max_requests/" "$STASH_DAEMON"
  } > "$OVERLAY"
  overlay_written=1
  ddev restart >/dev/null
else
  apply_env_change
fi

wait_ready || refuse "$BASE_URL/debug/worker did not answer 200; DEBUG_ROUTES is not enabled for the serving app"

# Sends the request in $1 $REQUESTS times, $PARALLEL at a time, bodies to $WORK/out/<n>.json.
fire() {
  rm -rf "$WORK/out"
  mkdir -p "$WORK/out"
  seq 1 "$REQUESTS" | xargs -P "$PARALLEL" -I{} \
    curl -ksS --max-time 30 -H 'Accept: application/json' -o "$WORK/out/{}.json" -w '%{http_code}\n' "$1" > "$WORK/out/codes" || true
}

# Prints the number of B responses with data.leaked true. Each body is parsed on its own, so one HTML
# error page cannot zero the count; non-200 answers in the A or B phase are named as errors.
count_leaks() {
  local leaked=0 bad file note=""
  for file in "$WORK/out"/*.json; do
    [[ -f "$file" ]] || continue
    [[ "$(jq -r '.data.leaked // empty' "$file" 2>/dev/null || true)" == "true" ]] && leaked=$((leaked + 1))
  done
  bad="$(grep -vc '^200$' "$WORK/out/codes" || true)"
  [[ "$bad" -gt 0 ]] && note="$bad errors"
  if [[ "${a_bad:-0}" -gt 0 ]]; then
    note="${note:+$note, }A phase: $a_bad errors"
  fi
  echo "$leaked/$REQUESTS${note:+ ($note)}"
}

echo "server: $server, max-requests ${max_requests:-default}, A=$REQUESTS requests then B=$REQUESTS requests per row"
for scenario in "${SCENARIOS[@]}"; do
  for mode in "${MODES[@]}"; do
    restart_workers
    wait_ready || refuse "server did not come back after restarting the workers"
    fire "$BASE_URL/debug/leaks/$scenario?dealer=1&locale=uk&mode=$mode"
    a_bad="$(grep -vc '^200$' "$WORK/out/codes" || true)"
    if [[ "$scenario" == "number-locale" ]]; then
      fire "$BASE_URL/debug/leaks/$scenario?dealer=2&mode=$mode"
    else
      fire "$BASE_URL/debug/leaks/$scenario?dealer=2&locale=de&mode=$mode"
    fi
    printf '%-16s %-6s leaked %s\n' "$scenario" "$mode" "$(count_leaks)"
  done
done
