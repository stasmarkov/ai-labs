#!/usr/bin/env bash
## Usage: bench/timeouts.sh apply <defaults|short>
##        bench/timeouts.sh measure
##        bench/timeouts.sh run
## apply short writes gitignored overlays that set every layer timeout of the active server to 10 s and
## restarts DDEV; apply defaults removes them. Layer on frankenphp, frankenphp-worker and frankenphp-octane:
## PHP max_execution_time. A removed server in the active overlay is refused with the removal message.
## The DDEV router timeout is never changed; it is recorded in the result file.
## measure sends GET /debug/sleep/30 three times in sequence, prints status, seconds, Server header and the
## layer that ended the request (from status, headers, body and the server log).
## run switches DEBUG_ROUTES=true in .env (restored on exit), measures with the defaults, then with short
## timeouts, restores the defaults and writes bench/results/<date>/timeouts-<server>.json.
## Env: BENCH_BASE_URL (default: `ddev server --url`), BENCH_APPROOT, BENCH_RESULTS_DIR.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
DDEV_DIR="$APPROOT/.ddev"
INI="$DDEV_DIR/php/zz-bench-timeouts.ini"
SITE="${DDEV_SITENAME:-ai-lab}"
ENTRY="$DDEV_DIR/web-entrypoint.d/zz-bench-timeouts.sh"
RESULTS_DIR="${BENCH_RESULTS_DIR:-$APPROOT/bench/results}"
ENV_FILE="$APPROOT/.env"
BACKUP="$APPROOT/.env.bench-backup"
ROUTER_TIMEOUT="${BENCH_ROUTER_TIMEOUT:-default (ddev-router, not changed)}"
SLEEP_SECONDS=30
WORK=""
env_switched=0

usage() {
  echo "Usage: $0 apply <defaults|short> | measure | run" >&2
  exit 2
}

remove_overlays() {
  rm -rf "$INI" "$ENTRY"
}

write_entry() {
  mkdir -p "$(dirname "$ENTRY")"
  {
    echo '#!/usr/bin/env bash'
    echo '# bench: all timeouts 10 s'
    cat
  } > "$ENTRY"
  chmod +x "$ENTRY"
}

write_frankenphp() {
  write_entry <<'SCRIPT'
for dir in /etc/php-zts/conf.d /usr/local/etc/php/conf.d; do
  if [[ -d "$dir" ]]; then
    printf 'max_execution_time = 10\n' > "$dir/99-bench-timeouts.ini"
  fi
done
SCRIPT
}

apply() {
  local mode="${1:-}" server
  case "$mode" in
    defaults | short) ;;
    *) usage ;;
  esac
  remove_overlays
  if [[ "$mode" == "short" ]]; then
    server="$(current_server "$APPROOT")" || exit 2
    if is_removed_server "$server"; then
      server_removed_message "$server" >&2
      exit 2
    fi
    case "$server" in
      frankenphp | frankenphp-octane | frankenphp-worker) write_frankenphp ;;
      *) echo "Unknown server '$server'" >&2; exit 1 ;;
    esac
  fi
  (cd "$APPROOT" && ddev restart >/dev/null)
}

classify() {
  local status="$1" header="$2" body="$3" logs="$4"
  if [[ "$status" == "200" ]]; then echo "none (request completed)"; return; fi
  if grep -qiE 'Maximum execution time' <<< "$body$logs"; then echo "PHP max_execution_time"; return; fi
  if grep -qi 'caddy' <<< "$header" && [[ "$status" =~ ^50[0234]$ ]]; then echo "FrankenPHP max_execution_time"; return; fi
  if [[ "$status" == "404" ]]; then echo "route not found (DEBUG_ROUTES off)"; return; fi
  if [[ "$status" == "504" ]]; then echo "DDEV router timeout"; return; fi
  echo "unknown"
}

## measure <label> appends one JSON line per request to $WORK/runs.jsonl.
measure() {
  local label="${1:-adhoc}" base server service status seconds header body logs layer i started
  [[ -n "$WORK" ]] || { WORK="$(mktemp -d)"; }
  server="$(current_server "$APPROOT")" || exit 2
  service=web
  base="${BENCH_BASE_URL:-$(cd "$APPROOT" && ddev server --url)}"
  local app_log="/var/www/html/storage/logs/laravel.log" log_size app_lines
  for i in 1 2 3; do
    started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    # Laravel's own log is read inside the serving container: the Mac's copy lags behind (Mutagen).
    log_size="$(docker exec "ddev-$SITE-$service" sh -c "wc -c < $app_log 2>/dev/null || echo 0" 2>/dev/null | tr -dc '0-9')"
    log_size="${log_size:-0}"
    read -r status seconds header < <(curl -ksS -o "$WORK/body" --max-time $((SLEEP_SECONDS + 30)) \
      -w '%{http_code} %{time_total} %header{server}\n' "$base/debug/sleep/$SLEEP_SECONDS" || echo "000 0 none")
    body="$(head -c 400 "$WORK/body" 2>/dev/null | tr '\n' ' ' || true)"
    # Only this request's log lines (since it started), without the entrypoint's shell trace lines (`+ ...`).
    logs="$(docker logs --since "$started" "ddev-$SITE-$service" 2>&1 | grep -vE '^\++ ' | grep -iE 'timed out|terminat|signal|Maximum execution' | tail -n 5 || true)"
    # Lines Laravel appended during this request, e.g. "Maximum execution time of 10 seconds exceeded".
    app_lines="$(docker exec "ddev-$SITE-$service" sh -c "tail -c +$((log_size + 1)) $app_log 2>/dev/null" 2>/dev/null | grep -iE 'Maximum execution' | cut -c1-200 | tail -n 2 || true)"
    [[ -z "$app_lines" ]] || logs="${logs:+$logs
}$app_lines"
    layer="$(classify "$status" "$header" "$body" "$logs")"
    printf '%s\t%s\trequest %s\tstatus=%s\tseconds=%s\tserver=%s\tlayer=%s\n' "$server" "$label" "$i" "$status" "$seconds" "$header" "$layer"
    jq -nc --arg label "$label" --argjson request "$i" --arg status "$status" --arg seconds "$seconds" \
      --arg header "$header" --arg layer "$layer" --arg log "$logs" \
      '{label: $label, request: $request, status: ($status | tonumber), seconds: ($seconds | tonumber), server_header: $header, ending_layer: $layer, log_excerpt: $log}' >> "$WORK/runs.jsonl"
  done
}

restore_env() {
  apply defaults >/dev/null 2>&1 || true
  if [[ "$env_switched" == "1" && -f "$BACKUP" ]]; then
    cp -p "$BACKUP" "$ENV_FILE"
    rm -f "$BACKUP"
    (cd "$APPROOT" && ddev artisan optimize:clear >/dev/null 2>&1) || true
  fi
  [[ -z "$WORK" ]] || rm -rf "$WORK"
}

run_all() {
  local server out
  WORK="$(mktemp -d)"
  trap restore_env EXIT
  trap 'exit 130' INT TERM
  if [[ -e "$BACKUP" ]]; then
    echo "Stale $BACKUP exists: a previous run did not restore .env. Restore it by hand first." >&2
    exit 2
  fi
  [[ -f "$ENV_FILE" ]] || { echo "No .env at $ENV_FILE" >&2; exit 2; }
  cp -p "$ENV_FILE" "$BACKUP"
  env_switched=1
  { grep -v '^DEBUG_ROUTES=' "$ENV_FILE" || true; echo 'DEBUG_ROUTES=true'; } > "$WORK/env.new"
  cat "$WORK/env.new" > "$ENV_FILE"
  (cd "$APPROOT" && ddev artisan optimize:clear >/dev/null 2>&1) || true

  server="$(current_server "$APPROOT")" || exit 2
  apply defaults
  echo "# defaults"
  measure defaults
  apply short
  echo "# all timeouts 10 s"
  measure short
  apply defaults

  out="$RESULTS_DIR/${BENCH_DATE:-$(date -u +%F)}/timeouts-$server.json"
  mkdir -p "$(dirname "$out")"
  jq -s --arg server "$server" --arg date "$(date -u +%FT%TZ)" --arg router "$ROUTER_TIMEOUT" --argjson seconds "$SLEEP_SECONDS" \
    '{server: $server, date: $date, path: "/debug/sleep/\($seconds)", router_timeout: $router, runs: .}' "$WORK/runs.jsonl" > "$out"
  echo "Wrote $out"
}

case "${1:-}" in
  apply) apply "${2:-}" ;;
  measure) measure adhoc ;;
  run) run_all ;;
  *) usage ;;
esac
