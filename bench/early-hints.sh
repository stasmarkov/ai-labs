#!/usr/bin/env bash
## Usage: bench/early-hints.sh [--listing <id>] [--label <name>]
## Records whether a `103 Early Hints` interim response reaches an HTTP/2 client for GET /listings/<id> (default 1) on the
## server that is up now (`ddev server --current`). Two paths: through the DDEV router (curl --http2 against the project
## URL) and straight at the web container (the real URL, with `--resolve <host>:443:<web container IP>` from a curl container on
## ddev_default, so SNI and Host are the real ones, HTTP/2 is negotiated and the router is skipped).
## Comparing them shows whether Traefik forwards a 103 the backend sent.
## Writes bench/results/early-hints/<UTC date>/<label>-router.trace, <label>-direct.trace and <label>.json
## (label defaults to the server name). Traces are redacted before they are written: certificate subject/issuer lines are dropped
## and IP addresses become <ip>. Exits non-zero when a request fails, so a failure is not recorded as "no 103".
## Env: CURL_IMAGE (default curlimages/curl:8.11.1), BENCH_DATE, BENCH_APPROOT.

set -euo pipefail
# shellcheck source=lib/servers.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/servers.sh"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
CURL_IMAGE="${CURL_IMAGE:-curlimages/curl:8.11.1}"
SITE="${DDEV_SITENAME:-ai-lab}"
WEB_CONTAINER="ddev-$SITE-web"
LISTING=1
LABEL=""

while [ $# -gt 0 ]; do
  case "$1" in
    --listing) LISTING="$2"; shift 2 ;;
    --listing=*) LISTING="${1#--listing=}"; shift ;;
    --label) LABEL="$2"; shift 2 ;;
    --label=*) LABEL="${1#--label=}"; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

SERVER="$(current_server "$APPROOT")" || exit 2
BASE_URL="$(cd "$APPROOT" && ddev server --url | tr -d '[:space:]')"
HOST_NAME="${BASE_URL#*://}"
HOST_NAME="${HOST_NAME%%/*}"
[ -n "$LABEL" ] || LABEL="$SERVER"
PATH_PART="/listings/$LISTING"

DAY="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT="$APPROOT/bench/results/early-hints/$DAY"
mkdir -p "$OUT"

docker pull "$CURL_IMAGE" >/dev/null 2>&1 || true
IMAGE_DIGEST="$(docker image inspect --format '{{index .RepoDigests 0}}' "$CURL_IMAGE" 2>/dev/null || true)"
[ -n "$IMAGE_DIGEST" ] || { echo "Cannot read the digest of $CURL_IMAGE; refusing to run." >&2; exit 1; }

web_ip="$(docker inspect -f '{{(index .NetworkSettings.Networks "ddev_default").IPAddress}}' "$WEB_CONTAINER")"
[ -n "$web_ip" ] || { echo "Cannot find the IP of $WEB_CONTAINER." >&2; exit 1; }

# Drops certificate subject/issuer lines (they carry the local CA and machine names) and replaces IPv4 addresses.
redact() {
  grep -Eiv 'subject:|issuer:|mkcert|OU=' | sed -E 's/[0-9]{1,3}(\.[0-9]{1,3}){3}/<ip>/g'
}

# Both paths are cold single requests; the body is discarded, only the verbose header trace matters.
curl -sS -v --http2 -k --max-time 30 -o /dev/null "$BASE_URL$PATH_PART" 2> "$OUT/.router.raw" \
  || { echo "router request failed (trace not kept)" >&2; rm -f "$OUT/.router.raw"; exit 1; }
redact < "$OUT/.router.raw" > "$OUT/$LABEL-router.trace" || true
has_router="$(grep -Ec '^< HTTP/[0-9.]+ 103' "$OUT/.router.raw" || true)"
rm -f "$OUT/.router.raw"

# frankenphp-octane serves plain HTTP on 8000 inside the web container and has nothing on 443: it is probed over HTTP/1.1 there.
if docker run --rm --network ddev_default "$CURL_IMAGE" -sS -k --connect-timeout 5 --max-time 30 -o /dev/null \
  --resolve "$HOST_NAME:443:$web_ip" "https://$HOST_NAME$PATH_PART" >/dev/null 2>&1; then
  DIRECT_SCHEME="https:443"
  direct_args=(-sS -v --http2 -k --resolve "$HOST_NAME:443:$web_ip" "https://$HOST_NAME$PATH_PART")
else
  DIRECT_SCHEME="http:8000"
  direct_args=(-sS -v --http1.1 --resolve "$HOST_NAME:8000:$web_ip" "http://$HOST_NAME:8000$PATH_PART")
fi
docker run --rm --network ddev_default "$CURL_IMAGE" --max-time 30 -o /dev/null "${direct_args[@]}" 2> "$OUT/.direct.raw" \
  || { echo "direct request failed (trace not kept)" >&2; rm -f "$OUT/.direct.raw"; exit 1; }
redact < "$OUT/.direct.raw" > "$OUT/$LABEL-direct.trace" || true
has_direct="$(grep -Ec '^< HTTP/[0-9.]+ 103' "$OUT/.direct.raw" || true)"
# The protocol the final response really used (the 103 needs HTTP/2 in Chromium).
DIRECT_PROTOCOL="${DIRECT_SCHEME} $(grep -Eo '^< HTTP/[0-9.]+' "$OUT/.direct.raw" | tail -n1 | cut -c3-)"
rm -f "$OUT/.direct.raw"

ROUTER_103=$([ "$has_router" -gt 0 ] && echo true || echo false)
DIRECT_103=$([ "$has_direct" -gt 0 ] && echo true || echo false)
COMMIT="$(cd "$APPROOT" && git rev-parse --short HEAD 2>/dev/null || echo unknown)"

jq -n --arg server "$SERVER" --arg label "$LABEL" --arg commit "$COMMIT" --argjson listing "$LISTING" \
  --argjson router "$ROUTER_103" --argjson direct "$DIRECT_103" --arg image "$CURL_IMAGE" --arg digest "$IMAGE_DIGEST" \
  --arg date "$DAY" --arg direct_protocol "$DIRECT_PROTOCOL" '{server: $server, label: $label, date: $date, commit: $commit, listing: $listing, router_103: $router,
    direct_103: $direct, direct_protocol: $direct_protocol, probe_image: $image, probe_image_digest: $digest}' > "$OUT/$LABEL.json"

yn() { [ "$1" = true ] && echo yes || echo no; }
echo "server=$SERVER listing=$LISTING router_103=$(yn "$ROUTER_103") direct_103=$(yn "$DIRECT_103") traces=$OUT"
