#!/usr/bin/env bash
## Usage: bench/parity.sh capture
##        bench/parity.sh compare <server-or-path-a> <server-or-path-b>
## Captures status, media type and body SHA-256 for a fixed request set into
## bench/parity/<active server>.txt. Only the /up body has its render time
## ("rendered in Nms") masked, because Laravel prints it and it differs per request.
## The snapshot is written only when every request succeeds and the Server header
## matches the active server.
## Env: PARITY_BASE_URL (default https://interview.ddev.site), PARITY_INSECURE=1 adds -k.

set -euo pipefail

BASE_URL="${PARITY_BASE_URL:-https://interview.ddev.site}"
SERVER_COMMAND="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)/.ddev/commands/host/server"
DIR="${BENCH_PARITY_DIR:-$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)/parity}"
REQUESTS=(/up /api/ping /listings "/listings?make=BMW&fuel=diesel&per_page=5" "/listings?per_page=99" /listings/1 /listings/999999)

sha256() {
  if command -v sha256sum >/dev/null 2>&1; then
    sha256sum | cut -d' ' -f1
  else
    shasum -a 256 | cut -d' ' -f1
  fi
}

usage() {
  echo "Usage: $0 capture | compare <server-or-path-a> <server-or-path-b>" >&2
  exit 2
}

# Media type only: lowercased, parameters such as charset dropped.
media_type() {
  local type="${1%%;*}"
  printf '%s' "$type" | tr '[:upper:]' '[:lower:]' | sed -E 's/^[[:space:]]+|[[:space:]]+$//g'
}

capture() {
  local server family meta rest path status type server_header hash curl_flags=(-sS --max-time 30)
  server="$(bash "$SERVER_COMMAND" --current)"
  family="${server%%-*}"
  [[ "${PARITY_INSECURE:-0}" == "1" ]] && curl_flags+=(-k)
  mkdir -p "$DIR"
  CAPTURE_BODY="$(mktemp)"
  CAPTURE_TMP="$(mktemp)"
  trap 'rm -f "${CAPTURE_BODY:-}" "${CAPTURE_TMP:-}"' EXIT
  local body="$CAPTURE_BODY" tmp="$CAPTURE_TMP"
  printf '# server=%s base=%s captured=%s\n' "$server" "$BASE_URL" "$(date -u +%Y-%m-%dT%H:%M:%SZ)" > "$tmp"
  for path in "${REQUESTS[@]}"; do
    if ! meta="$(curl "${curl_flags[@]}" -o "$body" -w '%{http_code}\t%{content_type}\t%header{server}' "$BASE_URL$path")" \
      || [[ "${meta%%$'\t'*}" == "000" ]]; then
      echo "Request failed: $BASE_URL$path. Snapshot not written. Is the project running? Set PARITY_INSECURE=1 if the certificate is not trusted." >&2
      exit 3
    fi
    status="${meta%%$'\t'*}"
    rest="${meta#*$'\t'}"
    type="$(media_type "${rest%%$'\t'*}")"
    server_header="$(printf '%s' "${rest#*$'\t'}" | tr '[:upper:]' '[:lower:]')"
    if [[ -n "$server_header" && "$server_header" != *"$family"* ]]; then
      echo "Server header '$server_header' does not match the active server '$server'. Snapshot not written. Run 'ddev restart' or 'ddev server $server'." >&2
      exit 4
    fi
    if [[ "$path" == "/up" ]]; then
      hash="$(sed -E 's/rendered in [0-9]+ms/rendered in Nms/' "$body" | sha256)"
    else
      hash="$(sha256 < "$body")"
    fi
    printf '%s\t%s\t%s\t%s\n' "$path" "$status" "$type" "$hash" >> "$tmp"
  done
  mv "$tmp" "$DIR/$server.txt"
  echo "Wrote $DIR/$server.txt"
}

resolve_snapshot() {
  if [[ "$1" == */* ]]; then
    printf '%s' "$1"
  else
    printf '%s/%s.txt' "$DIR" "$1"
  fi
}

compare() {
  local a b file i count_a count_b max differing=0
  a="$(resolve_snapshot "$1")"
  b="$(resolve_snapshot "$2")"
  for file in "$a" "$b"; do
    if [[ ! -f "$file" ]]; then
      echo "Missing snapshot: $file" >&2
      exit 2
    fi
  done
  local lines_a=() lines_b=()
  while IFS= read -r line || [[ -n "$line" ]]; do lines_a+=("$line"); done < <(grep -v '^#' "$a" || true)
  while IFS= read -r line || [[ -n "$line" ]]; do lines_b+=("$line"); done < <(grep -v '^#' "$b" || true)
  count_a=${#lines_a[@]}
  count_b=${#lines_b[@]}
  max=$(( count_a > count_b ? count_a : count_b ))
  for (( i = 0; i < max; i++ )); do
    if [[ "${lines_a[i]-}" != "${lines_b[i]-}" ]]; then
      differing=1
      local source="${lines_a[i]-${lines_b[i]-}}"
      echo "Differs: ${source%%$'\t'*}"
      echo "  a ($a): ${lines_a[i]-<missing>}"
      echo "  b ($b): ${lines_b[i]-<missing>}"
    fi
  done
  if (( count_a != count_b )); then
    differing=1
    echo "Line count differs: $count_a in $a, $count_b in $b"
  fi
  if (( differing )); then
    echo "Snapshots differ: $a $b" >&2
    exit 1
  fi
  echo "identical"
}

case "${1:-}" in
  capture) [[ $# -eq 1 ]] || usage; capture ;;
  compare) [[ $# -eq 3 ]] || usage; compare "$2" "$3" ;;
  *) usage ;;
esac
