#!/usr/bin/env bash
## Usage: bench/versions.sh
## Prints one markdown row: | active server | web server version | ddev version | php | sapi | opcache | date |
## Needs /debug/runtime enabled: DEBUG_ROUTES=true in .env, then `ddev artisan optimize:clear`.
## Env: PARITY_BASE_URL (default https://interview.ddev.site), PARITY_INSECURE=1 adds -k.

set -euo pipefail

BASE_URL="${PARITY_BASE_URL:-https://interview.ddev.site}"
SERVER_COMMAND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.ddev/commands/host/server"
curl_flags=(-sS --max-time 30 -H 'Accept: application/json')
[[ "${PARITY_INSECURE:-0}" == "1" ]] && curl_flags+=(-k)
body="$(mktemp)"
trap 'rm -f "$body"' EXIT

if ! status="$(curl "${curl_flags[@]}" -o "$body" -w '%{http_code}' "$BASE_URL/debug/runtime")" || [[ "$status" == "000" ]]; then
  echo "Cannot reach $BASE_URL/debug/runtime. Is the project running? Set PARITY_INSECURE=1 if the certificate is not trusted." >&2
  exit 1
fi

case "$status" in
  200) ;;
  404)
    echo "GET /debug/runtime returned 404. Set DEBUG_ROUTES=true in .env and run 'ddev artisan optimize:clear'." >&2
    exit 1
    ;;
  *)
    echo "GET /debug/runtime returned $status." >&2
    exit 1
    ;;
esac

php_field() {
  sed -nE "s/.*\"$1\":(\"[^\"]*\"|[a-z]+).*/\1/p" "$body" | tr -d '"\\'
}

server="$(bash "$SERVER_COMMAND" --current)"
case "$server" in
  apache-fpm) web_version="$(ddev exec apache2 -v 2>&1 | sed -nE 's#.*Apache/([0-9]+(\.[0-9]+)*).*#\1#p' | head -n 1)" ;;
  *) web_version="$(ddev exec nginx -v 2>&1 | sed -nE 's#.*nginx/([0-9]+(\.[0-9]+)*).*#\1#p' | head -n 1)" ;;
esac
ddev_version="$(ddev version 2>&1 | sed -nE 's/.*DDEV version[^v0-9]*v?([0-9]+(\.[0-9]+)*).*/\1/p' | head -n 1)"

printf '| %s | %s | %s | %s | %s | %s | %s |\n' \
  "$server" "$web_version" "$ddev_version" \
  "$(php_field php_version)" "$(php_field sapi)" "$(php_field opcache_enabled)" "$(date -u +%Y-%m-%d)"
