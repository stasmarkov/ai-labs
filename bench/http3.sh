#!/usr/bin/env bash
## Usage: bench/http3.sh [--server <name>|current] [--path <path>] [--label <name>]
## Records whether a server answers over HTTP/3. By default it switches to frankenphp (`ddev server frankenphp`), measures, and an EXIT
## trap always puts the default server back and restores .env; `--server nginx-fpm` measures that server instead, `--server current` measures
## the server that is up now and switches nothing. One GET of <path> (default /listings/1) goes over HTTP/2 and one over HTTP/3 only
## (`curl --http3-only`), both from a curl container on ddev_default straight at the web container (`--resolve <host>:443:<web container IP>`,
## so the DDEV router, which has no UDP 443 published, is skipped). It records the status and negotiated ALPN of each, the sha256 of both
## bodies (they must be equal) and the Alt-Svc header the h2 response carries.
## EARLY_HINTS=false is set in .env for the two requests (the shared bench/lib/env-switch.sh backup and restore): /listings/1 sends a
## 103 Early Hints response and the quiche-based curl cannot parse an interim response over h3 ("Header without colon"). The JSON records
## early_hints_during_h3: false and early_hints_reason; h2 and h3 see the same setting, so the body comparison stays fair.
## Writes bench/results/http3/<UTC date>/<label>.json and <label>.trace (label defaults to the server name). The trace is redacted:
## certificate subject/issuer lines and cookies are dropped and IP addresses become <ip>.
## A server with no UDP listener (nginx-fpm) is not an error: h3_ok is false, `reason` says "no HTTP/3: curl exit <code>" and the exit is 0.
## Exits 1 when the h3 body differs from the h2 body (both bodies go to bench/results/raw/, which is gitignored, never into the results
## tree), non-zero without a result when the h2 request fails or its status is not 200.
## Env: CURL_IMAGE (default alpine/curl-http3, pinned by digest), BENCH_DATE, BENCH_APPROOT, BENCH_H3_MAX_TIME (seconds, default 5).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
CURL_IMAGE="${CURL_IMAGE:-alpine/curl-http3@sha256:b0cb55696adcaf5714958926a28ce78a5fa72f6a4aa8df2ec5b72f1cde8038e3}"
H3_MAX_TIME="${BENCH_H3_MAX_TIME:-5}"
SITE="${DDEV_SITENAME:-ai-lab}"
WEB_CONTAINER="ddev-$SITE-web"
URL_PATH="/listings/1"
LABEL=""
TARGET="frankenphp"
EARLY_HINTS_REASON="the quiche-based curl cannot parse the 103 Early Hints interim response over h3 (Header without colon)"

usage() {
  echo "Usage: bench/http3.sh [--server <name>|current] [--path <path>] [--label <name>]"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --server) TARGET="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
    --server=*) TARGET="${1#--server=}"; shift ;;
    --path) URL_PATH="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
    --path=*) URL_PATH="${1#--path=}"; shift ;;
    --label) LABEL="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
    --label=*) LABEL="${1#--label=}"; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done
[[ "$TARGET" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid --server '$TARGET'." >&2; exit 2; }
[[ "$URL_PATH" == /* ]] || { echo "Invalid --path '$URL_PATH': it must start with /." >&2; exit 2; }
[[ -z "$LABEL" || "$LABEL" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid --label '$LABEL': use a-z, 0-9 and -." >&2; exit 2; }

# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"
SWITCHED_SERVER=0
WORK=""
cleanup() {
  [ -z "$WORK" ] || rm -rf "$WORK"
  env_switch_restore
  if [ "$SWITCHED_SERVER" = 1 ]; then restore_default_server "$APPROOT" >/dev/null 2>&1 || true; fi
}
trap cleanup EXIT
trap 'exit 130' INT TERM

if [ "$TARGET" != current ]; then
  read_default_server "$APPROOT"
  SWITCHED_SERVER=1
  (cd "$APPROOT" && ddev server "$TARGET" >/dev/null)
fi
SERVER="$(cd "$APPROOT" && ddev server --current | tr -d '[:space:]')"
BASE_URL="$(cd "$APPROOT" && ddev server --url | tr -d '[:space:]')"
HOST_NAME="${BASE_URL#*://}"
HOST_NAME="${HOST_NAME%%/*}"
[ -n "$LABEL" ] || LABEL="$SERVER"

DAY="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT="$APPROOT/bench/results/http3/$DAY"
RAW="$APPROOT/bench/results/raw"
WORK="$(mktemp -d)"
mkdir -p "$OUT"

env_switch_begin
env_set EARLY_HINTS false
env_apply

docker pull "$CURL_IMAGE" >/dev/null 2>&1 || true
IMAGE_DIGEST="$(docker image inspect --format '{{index .RepoDigests 0}}' "$CURL_IMAGE" 2>/dev/null || true)"
[ -n "$IMAGE_DIGEST" ] || { echo "Cannot read the digest of $CURL_IMAGE; refusing to run." >&2; exit 1; }

web_ip="$(docker inspect -f '{{(index .NetworkSettings.Networks "ddev_default").IPAddress}}' "$WEB_CONTAINER")"
[ -n "$web_ip" ] || { echo "Cannot find the IP of $WEB_CONTAINER." >&2; exit 1; }

sha256_of() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum "$1" | cut -d' ' -f1; else shasum -a 256 "$1" | cut -d' ' -f1; fi
}

# Drops certificate subject/issuer lines (they carry the local CA and machine names) and cookies, and replaces IPv4 addresses.
redact() {
  grep -Eiv 'subject:|issuer:|mkcert|OU=|set-cookie:|^> cookie:' | sed -E 's/[0-9]{1,3}(\.[0-9]{1,3}){3}/<ip>/g'
}

# Body on stdout, verbose trace on stderr.
curl_in_container() {
  docker run --rm --network ddev_default --entrypoint curl "$CURL_IMAGE" -sS -v -k \
    --resolve "$HOST_NAME:443:$web_ip" "$@" "https://$HOST_NAME$URL_PATH"
}

final_status() {
  grep -E '^< HTTP/[0-9.]+ [0-9]{3}' "$1" | tail -n1 | awk '{print $3}'
}

curl_in_container --http2 --max-time 30 > "$WORK/h2.body" 2> "$WORK/h2.raw" \
  || { echo "h2 request failed (nothing recorded)" >&2; exit 1; }
H2_STATUS="$(final_status "$WORK/h2.raw")"
[ "$H2_STATUS" = 200 ] || { echo "h2 request answered ${H2_STATUS:-no status}, not 200 (nothing recorded)" >&2; exit 1; }
H2_ALPN="$(grep -Eo 'ALPN: server accepted [a-z0-9/.]+' "$WORK/h2.raw" | tail -n1 | awk '{print $4}' || true)"
[ -n "$H2_ALPN" ] || H2_ALPN="$(grep -Eqi '^< HTTP/2 ' "$WORK/h2.raw" && echo h2 || true)"
ALT_SVC="$(grep -Ei '^< alt-svc:' "$WORK/h2.raw" | head -n1 | sed -E 's/^< [Aa]lt-[Ss]vc: *//' | tr -d '\r' || true)"
BODY_H2="$(sha256_of "$WORK/h2.body")"

H3_OK=false
H3_STATUS=""
H3_ALPN=""
BODY_H3=""
REASON=""
EXIT_CODE=0

set +e
curl_in_container --http3-only --max-time "$H3_MAX_TIME" > "$WORK/h3.body" 2> "$WORK/h3.raw"
h3_exit=$?
set -e

if [ "$h3_exit" -ne 0 ]; then
  REASON="no HTTP/3: curl exit $h3_exit"
else
  H3_STATUS="$(final_status "$WORK/h3.raw")"
  grep -Eq '^< HTTP/3 ' "$WORK/h3.raw" && H3_ALPN=h3
  BODY_H3="$(sha256_of "$WORK/h3.body")"
  if [ "$H3_STATUS" != 200 ] || [ "$H3_ALPN" != h3 ]; then
    REASON="unexpected h3 response: status ${H3_STATUS:-none}"
  elif [ "$BODY_H3" != "$BODY_H2" ]; then
    REASON="body sha256 differs"
    mkdir -p "$RAW"
    cp "$WORK/h2.body" "$RAW/http3-$LABEL-body-h2.html"
    cp "$WORK/h3.body" "$RAW/http3-$LABEL-body-h3.html"
    EXIT_CODE=1
  else
    H3_OK=true
  fi
fi

{ echo "# h2"; cat "$WORK/h2.raw"; echo "# h3"; cat "$WORK/h3.raw"; } | redact > "$OUT/$LABEL.trace" || true

COMMIT="$(cd "$APPROOT" && git rev-parse --short HEAD 2>/dev/null || echo unknown)"
jq -n --arg server "$SERVER" --arg label "$LABEL" --arg date "$DAY" --arg commit "$COMMIT" --arg path "$URL_PATH" \
  --argjson h3_ok "$H3_OK" --arg h3_status "$H3_STATUS" --arg h3_alpn "$H3_ALPN" --arg h2_status "$H2_STATUS" \
  --arg alpn_h2 "$H2_ALPN" --arg hints_reason "$EARLY_HINTS_REASON" --arg alt_svc "$ALT_SVC" --arg b2 "$BODY_H2" --arg b3 "$BODY_H3" --arg reason "$REASON" \
  --arg digest "$IMAGE_DIGEST" '
  def nn: if . == "" then null else . end;
  {server: $server, label: $label, date: $date, commit: $commit, path: $path, h3_ok: $h3_ok,
   h3_status: ($h3_status | nn | if . == null then null else tonumber end), h3_alpn: ($h3_alpn | nn),
   h2_status: ($h2_status | nn | if . == null then null else tonumber end), alpn_h2: ($alpn_h2 | nn),
   alt_svc: ($alt_svc | nn), body_sha256_h2: $b2, body_sha256_h3: ($b3 | nn), reason: ($reason | nn),
   early_hints_during_h3: false, early_hints_reason: $hints_reason,
   curl_image_digest: $digest}' > "$OUT/$LABEL.json"

echo "server=$SERVER path=$URL_PATH h3_ok=$([ "$H3_OK" = true ] && echo yes || echo no) alt_svc=$([ -n "$ALT_SVC" ] && echo yes || echo no) result=$OUT/$LABEL.json"
exit "$EXIT_CODE"
