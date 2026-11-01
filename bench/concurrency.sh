#!/usr/bin/env bash
## Usage: bench/concurrency.sh <n>
##        bench/concurrency.sh default
##        bench/concurrency.sh --show
## Sets the PHP worker limit of the active server to n and restarts DDEV; default restores today's values
## (FPM dynamic, 8 children, recycling after 200 requests; OpenLiteSpeed 10; FrankenPHP 2 x CPUs).
## n means: FPM pm=static, pm.max_children=n, pm.max_requests=0 (web-entrypoint.d script that edits the pool);
## OpenLiteSpeed PHP_LSAPI_CHILDREN=n and maxConns n (generated httpd_config.conf mounted by a compose overlay);
## FrankenPHP num_threads n and max_threads n (FRANKENPHP_CONFIG in a config.zz-*.yaml overlay).
## All overlays are gitignored and written under .ddev/; .ddev/config.yaml is never touched.
## --show prints "<n> workers" or "default". Env: BENCH_APPROOT.

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

usage() {
  echo "Usage: $0 <n>|default|--show (n is a positive integer)" >&2
  exit 2
}

remove_overlays() {
  rm -rf "$FPM_SCRIPT" "$FRANKEN_OVERLAY" "$OLS_COMPOSE" "$OLS_DIR"
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
  local file
  for file in "$FPM_SCRIPT" "$FRANKEN_OVERLAY" "$OLS_COMPOSE"; do
    if [[ -f "$file" ]]; then
      sed -n 's/^# bench: //p' "$file" | head -n 1
      return
    fi
  done
  echo default
}

case "${1:-}" in
  --show) show; exit 0 ;;
  default) value="" ;;
  *)
    [[ "${1:-}" =~ ^[1-9][0-9]*$ ]] || usage
    value="$1"
    ;;
esac

server="$(cd "$APPROOT" && ddev server --current)"
remove_overlays
if [[ -n "$value" ]]; then
  case "$server" in
    nginx-fpm | apache-fpm) write_fpm "$value" ;;
    frankenphp | frankenphp-octane | frankenphp-worker) write_frankenphp "$value" ;;
    openlitespeed) write_openlitespeed "$value" ;;
    *) echo "Unknown server '$server'" >&2; exit 1 ;;
  esac
fi
(cd "$APPROOT" && ddev restart >/dev/null)
echo "$server: ${value:-default}"
