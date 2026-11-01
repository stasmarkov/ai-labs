#!/usr/bin/env bash
## Usage: bench/timeouts.sh apply <defaults|short>
##        bench/timeouts.sh measure
##        bench/timeouts.sh run
## apply short writes gitignored overlays that set every layer timeout of the active server to 10 s and
## restarts DDEV; apply defaults removes them. Layers per server:
##   nginx-fpm      PHP max_execution_time, FPM request_terminate_timeout, nginx fastcgi_read_timeout
##   apache-fpm     PHP max_execution_time, FPM request_terminate_timeout, Apache ProxyTimeout
##   openlitespeed  PHP max_execution_time, external app (initTimeout) and connection (connTimeout) timeouts
##   frankenphp     PHP max_execution_time (all three variants)
## The DDEV router timeout is never changed; it is recorded in the result file.
## measure sends GET /debug/sleep/30 three times in sequence, prints status, seconds, Server header and the
## layer that ended the request (from status, headers, body and the server log).
## run switches DEBUG_ROUTES=true in .env (restored on exit), measures with the defaults, then with short
## timeouts, restores the defaults and writes bench/results/<date>/timeouts-<server>.json.
## Env: BENCH_BASE_URL (default: `ddev server --url`), BENCH_APPROOT, BENCH_RESULTS_DIR.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
DDEV_DIR="$APPROOT/.ddev"
INI="$DDEV_DIR/php/zz-bench-timeouts.ini"
NGINX="$DDEV_DIR/nginx/bench-timeouts.conf"   # legacy path, only removed
NGINX_SITE="$DDEV_DIR/nginx_full/nginx-site.conf"
NGINX_SITE_ORIG="$DDEV_DIR/nginx_full/nginx-site.conf.bench-orig"
SITE="${DDEV_SITENAME:-interview}"
ENTRY="$DDEV_DIR/web-entrypoint.d/zz-bench-timeouts.sh"
OLS_COMPOSE="$DDEV_DIR/docker-compose.bench-timeouts.yaml"
OLS_DIR="$DDEV_DIR/bench-timeouts"
OLS_SOURCE="$DDEV_DIR/servers/openlitespeed/conf/httpd_config.conf"
CONCURRENCY_OLS_COMPOSE="$DDEV_DIR/docker-compose.bench-concurrency.yaml"
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
  rm -rf "$INI" "$NGINX" "$ENTRY" "$OLS_COMPOSE" "$OLS_DIR"
  # Put DDEV's own nginx site config back (it keeps its #ddev-generated marker, so DDEV manages it again).
  if [[ -f "$NGINX_SITE_ORIG" ]]; then
    mv "$NGINX_SITE_ORIG" "$NGINX_SITE"
  fi
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

write_fpm_layers() {
  mkdir -p "$(dirname "$INI")"
  printf 'max_execution_time = 10\n' > "$INI"
  write_entry <<'SCRIPT'
for pool in /etc/php/*/fpm/pool.d/www.conf; do
  sed -i -E '/^;?request_terminate_timeout/d' "$pool"
  echo 'request_terminate_timeout = 10s' >> "$pool"
done
SCRIPT
}

# DDEV's nginx site config sets `fastcgi_read_timeout 10m` inside the PHP location, which overrides any
# server-level snippet in .ddev/nginx/. So the short mode swaps in a copy of the generated site config
# with 10 s there and without the #ddev-generated marker (DDEV then leaves it alone); `defaults` puts the
# original back. Both paths are gitignored by DDEV / the project.
write_nginx() {
  write_fpm_layers
  [[ -f "$NGINX_SITE" ]] || { echo "No $NGINX_SITE to copy." >&2; exit 1; }
  cp -p "$NGINX_SITE" "$NGINX_SITE_ORIG"
  sed -E -e '/#ddev-generated/d' -e 's/fastcgi_read_timeout [0-9]+[a-z]*;/fastcgi_read_timeout 10s;/' "$NGINX_SITE_ORIG" > "$NGINX_SITE"
  grep -q 'fastcgi_read_timeout 10s;' "$NGINX_SITE" || { echo "Could not set fastcgi_read_timeout in $NGINX_SITE." >&2; exit 1; }
}

write_apache() {
  write_fpm_layers
  cat >> "$ENTRY" <<'SCRIPT'
printf 'ProxyTimeout 10\n' > /etc/apache2/conf-available/zz-bench-timeouts.conf
a2enconf zz-bench-timeouts >/dev/null
SCRIPT
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

write_openlitespeed() {
  if [[ -f "$CONCURRENCY_OLS_COMPOSE" ]]; then
    echo "bench/concurrency.sh overlay is active and mounts the same OpenLiteSpeed config; run 'bench/concurrency.sh default' first." >&2
    exit 1
  fi
  mkdir -p "$OLS_DIR"
  sed -E -e 's/^( *connTimeout +)[0-9]+/\110/' -e 's/^( *initTimeout +)[0-9]+/\110/' "$OLS_SOURCE" > "$OLS_DIR/httpd_config.conf"
  printf 'date.timezone=UTC\nmax_execution_time=10\n' > "$OLS_DIR/zz-timezone.ini"
  cat > "$OLS_COMPOSE" <<'COMPOSE'
# bench: all timeouts 10 s
services:
  openlitespeed:
    volumes:
      - ${DDEV_APPROOT}/.ddev/bench-timeouts/httpd_config.conf:/usr/local/lsws/conf/httpd_config.conf:ro
      - ${DDEV_APPROOT}/.ddev/bench-timeouts/httpd_config.conf:/usr/local/lsws/.conf/httpd_config.conf:ro
      - ${DDEV_APPROOT}/.ddev/bench-timeouts/zz-timezone.ini:/usr/local/lsws/lsphp84/etc/php/8.4/mods-available/zz-timezone.ini:ro
COMPOSE
}

apply() {
  local mode="${1:-}" server
  case "$mode" in
    defaults | short) ;;
    *) usage ;;
  esac
  remove_overlays
  if [[ "$mode" == "short" ]]; then
    server="$(cd "$APPROOT" && ddev server --current)"
    case "$server" in
      nginx-fpm) write_nginx ;;
      apache-fpm) write_apache ;;
      openlitespeed) write_openlitespeed ;;
      frankenphp | frankenphp-octane | frankenphp-worker) write_frankenphp ;;
      *) echo "Unknown server '$server'" >&2; exit 1 ;;
    esac
  fi
  (cd "$APPROOT" && ddev restart >/dev/null)
}

service_name() {
  if [[ "$1" == "openlitespeed" ]]; then echo openlitespeed; else echo web; fi
}

classify() {
  local status="$1" header="$2" body="$3" logs="$4"
  if [[ "$status" == "200" ]]; then echo "none (request completed)"; return; fi
  if grep -qiE 'Maximum execution time' <<< "$body$logs"; then echo "PHP max_execution_time"; return; fi
  # FPM's own warning when request_terminate_timeout fires: "... execution timed out (10.0 sec), terminating".
  if grep -qiE 'execution timed out|exited on signal 15' <<< "$logs"; then echo "PHP-FPM request_terminate_timeout"; return; fi
  if grep -qiE 'upstream timed out' <<< "$logs" || { [[ "$status" == "504" ]] && grep -qi '^nginx' <<< "$header"; }; then echo "nginx fastcgi_read_timeout"; return; fi
  if grep -qiE 'AH01102|AH00957|proxy.*timeout' <<< "$logs" || { grep -qi '^apache' <<< "$header" && [[ "$status" =~ ^50[234]$ ]]; }; then echo "Apache ProxyTimeout"; return; fi
  # OLS answers 500 itself when the lsphp external app does not respond in time (no PHP fatal is logged).
  if grep -qi 'litespeed' <<< "$header" && [[ "$status" =~ ^50[0234]$ ]]; then echo "OpenLiteSpeed external app timeout"; return; fi
  if grep -qi 'caddy' <<< "$header" && [[ "$status" =~ ^50[0234]$ ]]; then echo "FrankenPHP max_execution_time"; return; fi
  if [[ "$status" == "404" ]]; then echo "route not found (DEBUG_ROUTES off)"; return; fi
  if [[ "$status" == "504" ]]; then echo "DDEV router timeout"; return; fi
  echo "unknown"
}

## measure <label> appends one JSON line per request to $WORK/runs.jsonl.
measure() {
  local label="${1:-adhoc}" base server service status seconds header body logs layer i started ols_size
  [[ -n "$WORK" ]] || { WORK="$(mktemp -d)"; }
  server="$(cd "$APPROOT" && ddev server --current)"
  service="$(service_name "$server")"
  base="${BENCH_BASE_URL:-$(cd "$APPROOT" && ddev server --url)}"
  local app_log="/var/www/html/storage/logs/laravel.log" log_size app_lines
  for i in 1 2 3; do
    started="$(date -u +%Y-%m-%dT%H:%M:%SZ)"
    # Laravel's own log is read inside the serving container: the Mac's copy lags behind (Mutagen).
    log_size="$(docker exec "ddev-$SITE-$service" sh -c "wc -c < $app_log 2>/dev/null || echo 0" 2>/dev/null | tr -dc '0-9')"
    log_size="${log_size:-0}"
    # OpenLiteSpeed writes its own error log to a file inside its container, not to stdout.
    ols_size=0
    if [[ "$service" == "openlitespeed" ]]; then
      ols_size="$(docker exec "ddev-$SITE-$service" sh -c "wc -c < /usr/local/lsws/logs/error.log 2>/dev/null || echo 0" 2>/dev/null | tr -dc '0-9')"
      ols_size="${ols_size:-0}"
    fi
    read -r status seconds header < <(curl -ksS -o "$WORK/body" --max-time $((SLEEP_SECONDS + 30)) \
      -w '%{http_code} %{time_total} %header{server}\n' "$base/debug/sleep/$SLEEP_SECONDS" || echo "000 0 none")
    body="$(head -c 400 "$WORK/body" 2>/dev/null | tr '\n' ' ' || true)"
    # Only this request's log lines (since it started), without the entrypoint's shell trace lines (`+ ...`).
    logs="$(docker logs --since "$started" "ddev-$SITE-$service" 2>&1 | grep -vE '^\++ ' | grep -iE 'timed out|terminat|signal|Maximum execution|AH0|upstream' | tail -n 5 || true)"
    # Lines Laravel appended during this request, e.g. "Maximum execution time of 10 seconds exceeded".
    app_lines="$(docker exec "ddev-$SITE-$service" sh -c "tail -c +$((log_size + 1)) $app_log 2>/dev/null" 2>/dev/null | grep -iE 'Maximum execution' | cut -c1-200 | tail -n 2 || true)"
    [[ -z "$app_lines" ]] || logs="${logs:+$logs
}$app_lines"
    if [[ "$service" == "openlitespeed" ]]; then
      app_lines="$(docker exec "ddev-$SITE-$service" sh -c "tail -c +$((ols_size + 1)) /usr/local/lsws/logs/error.log 2>/dev/null" 2>/dev/null | grep -iE 'timeout|timed out|abort|lsphp|ExtConn|retry' | cut -c1-200 | tail -n 3 || true)"
      [[ -z "$app_lines" ]] || logs="${logs:+$logs
}$app_lines"
    fi
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

  server="$(cd "$APPROOT" && ddev server --current)"
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
