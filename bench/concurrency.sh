#!/usr/bin/env bash
## Usage: bench/concurrency.sh <n> [--max-requests <m>]
##        bench/concurrency.sh default
##        bench/concurrency.sh --show
## Sets the PHP worker limit of the active server to n and restarts DDEV; default restores today's values
## (FPM dynamic, 8 children, recycling after 200 requests; OpenLiteSpeed 10; FrankenPHP 2 x CPUs).
## n means: FPM pm=static, pm.max_children=n, pm.max_requests=0 (web-entrypoint.d script that edits the pool);
## OpenLiteSpeed PHP_LSAPI_CHILDREN=n and maxConns n (generated httpd_config.conf mounted by a compose overlay);
## FrankenPHP num_threads n and max_threads n (FRANKENPHP_CONFIG in a config.zz-*.yaml overlay).
## frankenphp-octane: the daemon command of the stash, copied to config.zzz-bench-concurrency.yaml with
## --workers=n and --max-requests=m and override_config: true, so it replaces the active daemon. m defaults to
## 1000000, "no recycling": Octane's worker script treats 0 as "stop at once". frankenphp-worker: the stash
## Caddyfile reads `num {$WORKER_NUM:4}` and `MAX_REQUESTS {$WORKER_MAX_REQUESTS:500}`, so this script only writes
## those two variables to config.zzz-bench-concurrency.yaml (web_environment); the activated Caddyfile is never edited.
## `ddev server` removes every bench overlay when it switches, so settings never leak to another server.
## --max-requests <m> is accepted for these two servers only.
## All overlays are gitignored and written under .ddev/; .ddev/config.yaml is never touched.
## --show prints "<n> workers" or "default"; on frankenphp-octane and frankenphp-worker it prints
## "<n> workers, max-requests <m>" read from the active configuration. Env: BENCH_APPROOT.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
DDEV_DIR="$APPROOT/.ddev"
FPM_SCRIPT="$DDEV_DIR/web-entrypoint.d/zz-bench-fpm.sh"
FRANKEN_OVERLAY="$DDEV_DIR/config.zz-bench-concurrency.yaml"
OLS_COMPOSE="$DDEV_DIR/docker-compose.bench-concurrency.yaml"
OLS_DIR="$DDEV_DIR/bench-concurrency"
OLS_CONF="$OLS_DIR/httpd_config.conf"
OLS_SOURCE="$DDEV_DIR/servers/openlitespeed/conf/httpd_config.conf"
OCTANE_OVERLAY="$DDEV_DIR/config.zzz-bench-concurrency.yaml"
OCTANE_ACTIVE="$DDEV_DIR/config.zz-server.yaml"
OCTANE_SOURCE="$DDEV_DIR/servers/frankenphp-octane/config.frankenphp-octane.yaml"
CADDYFILE="$DDEV_DIR/web-build/Caddyfile.frankenphp"
CADDYFILE_SOURCE="$DDEV_DIR/servers/frankenphp-worker/web-build/Caddyfile.frankenphp"
NO_RECYCLING=1000000

usage() {
  echo "Usage: $0 <n> [--max-requests <m>]|default|--show (n and m are positive integers; --max-requests only on frankenphp-octane and frankenphp-worker)" >&2
  exit 2
}

remove_overlays() {
  rm -rf "$FPM_SCRIPT" "$FRANKEN_OVERLAY" "$OLS_COMPOSE" "$OLS_DIR" "$OCTANE_OVERLAY"
}

write_octane() {
  {
    echo "override_config: true"
    sed -E "s/--workers=[0-9]+/--workers=$1/; s/--max-requests=[0-9]+/--max-requests=$2/" "$OCTANE_SOURCE"
  } > "$OCTANE_OVERLAY"
}

write_worker() {
  printf '# bench: %s workers, max-requests %s\nweb_environment:\n  - WORKER_NUM=%s\n  - WORKER_MAX_REQUESTS=%s\n' "$1" "$2" "$1" "$2" > "$OCTANE_OVERLAY"
}

show_worker_server() {
  local file
  case "$1" in
    frankenphp-octane)
      file="$OCTANE_OVERLAY"
      [[ -f "$file" ]] || file="$OCTANE_ACTIVE"
      echo "$(sed -n 's/.*--workers=\([0-9]*\).*/\1/p' "$file" | head -n 1) workers, max-requests $(sed -n 's/.*--max-requests=\([0-9]*\).*/\1/p' "$file" | head -n 1)"
      ;;
    frankenphp-worker)
      if [[ -f "$OCTANE_OVERLAY" ]]; then
        echo "$(sed -n 's/^  - WORKER_NUM=//p' "$OCTANE_OVERLAY") workers, max-requests $(sed -n 's/^  - WORKER_MAX_REQUESTS=//p' "$OCTANE_OVERLAY")"
      else
        # Defaults written in the Caddyfile placeholders, e.g. {$WORKER_NUM:4}.
        echo "$(sed -n 's/.*{\$WORKER_NUM:\([0-9]*\)}.*/\1/p' "$CADDYFILE_SOURCE" | head -n 1) workers, max-requests $(sed -n 's/.*{\$WORKER_MAX_REQUESTS:\([0-9]*\)}.*/\1/p' "$CADDYFILE_SOURCE" | head -n 1)"
      fi
      ;;
  esac
}

write_fpm() {
  mkdir -p "$(dirname "$FPM_SCRIPT")"
  cat > "$FPM_SCRIPT" <<SCRIPT
#!/usr/bin/env bash
# bench: $1 workers
for pool in /etc/php/*/fpm/pool.d/www.conf; do
  sed -i -E \\
    -e 's/^;?pm = .*/pm = static/' \\
    -e 's/^;?pm\\.max_children = .*/pm.max_children = $1/' \\
    -e 's/^;?pm\\.max_requests = .*/pm.max_requests = 0/' "\$pool"
done
SCRIPT
  chmod +x "$FPM_SCRIPT"
}

write_frankenphp() {
  printf '# bench: %s workers\nweb_environment:\n  - "FRANKENPHP_CONFIG=num_threads %s\\nmax_threads %s"\n' "$1" "$1" "$1" > "$FRANKEN_OVERLAY"
}

write_openlitespeed() {
  mkdir -p "$OLS_DIR"
  sed -E -e "s/^( *maxConns +)[0-9]+/\1$1/" -e "s/PHP_LSAPI_CHILDREN=[0-9]+/PHP_LSAPI_CHILDREN=$1/" "$OLS_SOURCE" > "$OLS_CONF"
  cat > "$OLS_COMPOSE" <<COMPOSE
# bench: $1 workers
services:
  openlitespeed:
    volumes:
      - \${DDEV_APPROOT}/.ddev/bench-concurrency/httpd_config.conf:/usr/local/lsws/conf/httpd_config.conf:ro
      - \${DDEV_APPROOT}/.ddev/bench-concurrency/httpd_config.conf:/usr/local/lsws/.conf/httpd_config.conf:ro
COMPOSE
}

show() {
  local file server
  server="$(cd "$APPROOT" && ddev server --current)"
  if [[ "$server" == "frankenphp-octane" || "$server" == "frankenphp-worker" ]]; then
    show_worker_server "$server"
    return
  fi
  for file in "$FPM_SCRIPT" "$FRANKEN_OVERLAY" "$OLS_COMPOSE"; do
    if [[ -f "$file" ]]; then
      sed -n 's/^# bench: //p' "$file" | head -n 1
      return
    fi
  done
  echo default
}

max_requests=""
case "${1:-}" in
  --show) show; exit 0 ;;
  default) value="" ;;
  *)
    [[ "${1:-}" =~ ^[1-9][0-9]*$ ]] || usage
    value="$1"
    ;;
esac
if [[ "${2:-}" == "--max-requests" ]]; then
  [[ -n "$value" && "${3:-}" =~ ^[1-9][0-9]*$ && $# -eq 3 ]] || usage
  max_requests="$3"
elif [[ $# -gt 1 ]]; then
  usage
fi

server="$(cd "$APPROOT" && ddev server --current)"
if [[ -n "$max_requests" && "$server" != "frankenphp-octane" && "$server" != "frankenphp-worker" ]]; then
  usage
fi
remove_overlays
# Older harness versions edited the activated Caddyfile in place; put the stash copy back on default.
if [[ "$server" == "frankenphp-worker" && -z "$value" ]]; then
  mkdir -p "$(dirname "$CADDYFILE")"
  cp "$CADDYFILE_SOURCE" "$CADDYFILE"
fi
if [[ -n "$value" ]]; then
  case "$server" in
    nginx-fpm | apache-fpm) write_fpm "$value" ;;
    frankenphp) write_frankenphp "$value" ;;
    frankenphp-octane) write_octane "$value" "${max_requests:-$NO_RECYCLING}" ;;
    frankenphp-worker) write_worker "$value" "${max_requests:-$NO_RECYCLING}" ;;
    openlitespeed) write_openlitespeed "$value" ;;
    *) echo "Unknown server '$server'" >&2; exit 1 ;;
  esac
fi
(cd "$APPROOT" && ddev restart >/dev/null)
echo "$server: ${value:-default}"
