#!/usr/bin/env bash
## Usage: bench/compression.sh [--listing <id>] [--label <name>]
## Records what the server that is up now (`ddev server --current`) does with Accept-Encoding. For each resource
## (the listing page, its CSS, its own JS "page-js", the largest built JS "lab-bundle", and the CSV export) and each Accept-Encoding value (identity, gzip, br, zstd and
## "zstd, br, gzip") it records the status, the Content-Encoding the server answered, the bytes on the wire and the time to the
## last byte (median of BENCH_REPEATS requests, default 5; a true median, the mean of the middle two for an even count). The CSS and JS paths
## are read from the page, so the Vite fingerprint is not hard-coded. The page's own script is the "page-js" row (it can be an empty file).
## "lab-bundle" is the largest built JS under /build/assets, which the detail page does not load: it only gives the JS rows some bytes to compress.
## The export (GET /exports/listings?mode=offload) is also checked: an identity request must carry no Content-Encoding, any other
## request exactly one value (never compressed twice), and the body decoded by curl must have the sha256 stored in the `exports` record
## for the export id. It also records the CPU seconds the web container spent per download, from the cgroup counter (cpu.stat usage_usec;
## the whole container, so keep it otherwise idle): one sample per repeat and their median as cpu_seconds.
## The script only reads the exports record and the file size; it never regenerates the export. Needs the export file (ddev artisan exports:generate)
## and a rate limit that allows the downloads (EXPORTS_PER_MINUTE).
## Writes bench/results/compression/<UTC date>/<label>.json (label defaults to the server name).
## Exit 1 when the export fails the single-encoding or sha256 check (the file is still written), 2 on bad arguments,
## and non-zero without a file when a request fails, so a failure is not recorded as "no compression".
## Every request runs in a curl container, run by digest, on ddev_default (the host curl may lack brotli or zstd), over HTTP/2 through the DDEV router.
## The script refuses to run when the digest of the curl image cannot be read. It turns DEBUG_ROUTES on and lifts the export rate limit in .env for the
## run through the shared bench .env backup and restore (bench/lib/env-switch.sh) and restores .env and clears the config cache on exit.
## Env: DECODE_IMAGE (default curlimages/curl:8.11.1), BENCH_REPEATS, BENCH_DATE, BENCH_APPROOT, BENCH_MAX_TIME (seconds per request, default 300), BENCH_EXPORT_ID (default listings).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
REPEATS="${BENCH_REPEATS:-5}"
MAX_TIME="${BENCH_MAX_TIME:-300}"
EXPORT_ID="${BENCH_EXPORT_ID:-listings}"
DECODE_IMAGE="${DECODE_IMAGE:-curlimages/curl:8.11.1}"
EXPORT_FILE="storage/app/exports/$EXPORT_ID.csv"
LISTING=1
LABEL=""

usage() {
  echo "Usage: bench/compression.sh [--listing <id>] [--label <name>]"
}

while [ $# -gt 0 ]; do
  case "$1" in
    --listing) LISTING="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
    --listing=*) LISTING="${1#--listing=}"; shift ;;
    --label) LABEL="${2:-}"; shift 2 || { usage >&2; exit 2; } ;;
    --label=*) LABEL="${1#--label=}"; shift ;;
    -h|--help) usage >&1; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done
[[ "$LISTING" =~ ^[1-9][0-9]*$ ]] || { echo "Invalid --listing '$LISTING'." >&2; exit 2; }
[[ "$REPEATS" =~ ^[1-9][0-9]*$ ]] || { echo "BENCH_REPEATS must be a positive integer (got '$REPEATS')." >&2; exit 2; }
[[ -z "$LABEL" || "$LABEL" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid --label '$LABEL': use a-z, 0-9 and -." >&2; exit 2; }

SERVER="$(cd "$APPROOT" && ddev server --current | tr -d '[:space:]')"
BASE_URL="$(cd "$APPROOT" && ddev server --url | tr -d '[:space:]')"
[ -n "$LABEL" ] || LABEL="$SERVER"
DAY="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT="$APPROOT/bench/results/compression/$DAY"
WORK="$(mktemp -d)"
# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"
cleanup() {
  env_switch_restore
  rm -rf "$WORK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

HOST_NAME="${BASE_URL#*://}"
HOST_NAME="${HOST_NAME%%/*}"
ROUTER_IP="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ddev-router)"
[ -n "$ROUTER_IP" ] || { echo "Cannot find the ddev-router IP." >&2; exit 1; }
docker pull "$DECODE_IMAGE" >/dev/null 2>&1 || true
IMAGE_ID="$(docker image inspect -f '{{index .RepoDigests 0}}' "$DECODE_IMAGE" 2>/dev/null || true)"
[ -n "$IMAGE_ID" ] || { echo "Cannot read the digest of $DECODE_IMAGE; refusing to run." >&2; exit 1; }

env_switch_begin
env_set DEBUG_ROUTES true
env_set EXPORTS_PER_MINUTE 1000000
env_apply

# Runs curl in the pinned container on ddev_default, reaching the site through the router.
client() {
  docker run --rm --network ddev_default --add-host "$HOST_NAME:$ROUTER_IP" "$IMAGE_ID" -ksS --http2 --max-time "$MAX_TIME" "$@"
}

ENCODINGS=("identity" "gzip" "br" "zstd" "zstd, br, gzip")

# Prints "<status> <wire bytes> <seconds>" and leaves the response headers in $WORK/headers.
fetch() {
  local out
  out="$(client -H "Accept-Encoding: $2" -D - -o /dev/null -w '\n%{http_code} %{size_download} %{time_total}\n' "$BASE_URL$1")" || return 1
  printf '%s\n' "$out" | sed '$d' > "$WORK/headers"
  printf '%s\n' "$out" | tail -n1
}

# True median of the numbers on stdin: the middle value, or the mean of the middle two for an even count.
median() {
  sort -n | awk '{ v[NR] = $1 } END { if (NR % 2) { print v[(NR + 1) / 2] } else { print (v[NR / 2] + v[NR / 2 + 1]) / 2 } }'
}

# Prints every Content-Encoding value of the last response joined by commas (empty when there is none).
content_encoding() {
  { grep -i '^content-encoding:' "$WORK/headers" || true; } | sed 's/^[^:]*: *//' | tr -d '\r' | paste -sd, - | sed 's/ *, */, /g'
}

sha_of_stdin() {
  if command -v sha256sum >/dev/null 2>&1; then sha256sum | cut -d' ' -f1; else shasum -a 256 | cut -d' ' -f1; fi
}

cpu_usec() {
  (cd "$APPROOT" && ddev exec cat /sys/fs/cgroup/cpu.stat) | awk '/^usage_usec/ {print $2}'
}

# Page CSS and JS paths, taken from the listing HTML.
client -H "Accept-Encoding: identity" -o - "$BASE_URL/listings/$LISTING" > "$WORK/page.html"
CSS_PATH="$(grep -Eo '/build/assets/[^"'"'"' >]+\.css' "$WORK/page.html" | head -n1 || true)"
PAGE_JS_PATH="$(grep -Eo '/build/assets/[^"'"'"' >]+\.js' "$WORK/page.html" | head -n1 || true)"
LAB_JS_PATH=""
if [ -d "$APPROOT/public/build/assets" ] && ls "$APPROOT"/public/build/assets/*.js >/dev/null 2>&1; then
  LAB_JS_PATH="/build/assets/$(basename "$(ls -S "$APPROOT"/public/build/assets/*.js | head -n1)")"
fi
[ -n "$LAB_JS_PATH" ] || LAB_JS_PATH="$PAGE_JS_PATH"
[ -n "$CSS_PATH" ] && [ -n "$PAGE_JS_PATH" ] || { echo "Cannot find the CSS and JS assets in /listings/$LISTING." >&2; exit 1; }

EXPORT_PATH="/exports/$EXPORT_ID?mode=offload"
RECORD="$(cd "$APPROOT" && ddev artisan tinker --execute 'echo implode(" ", [DB::table("exports")->where("id", "'"$EXPORT_ID"'")->value("bytes"), DB::table("exports")->where("id", "'"$EXPORT_ID"'")->value("sha256")]);' | tail -n1 | tr -d '\r')"
EXPECTED_BYTES="${RECORD%% *}"
EXPECTED_SHA="${RECORD##* }"
[[ "$EXPECTED_BYTES" =~ ^[0-9]+$ && "$EXPECTED_SHA" =~ ^[0-9a-f]{64}$ ]] \
  || { echo "Cannot read the exports record for '$EXPORT_ID': run ddev artisan exports:generate." >&2; exit 1; }
ACTUAL_BYTES="$(cd "$APPROOT" && ddev exec stat -c %s "$EXPORT_FILE" | tr -d '[:space:]')"
[ "$ACTUAL_BYTES" = "$EXPECTED_BYTES" ] || { echo "$EXPORT_FILE has $ACTUAL_BYTES bytes, the exports record says $EXPECTED_BYTES." >&2; exit 1; }

RESOURCES="$WORK/resources.jsonl"
EXPORT_ROWS="$WORK/export.jsonl"
: > "$RESOURCES"
: > "$EXPORT_ROWS"

# Appends one resource row; the remaining arguments are the per-repeat seconds. The median is a true median.
record_row() {
  local name="$1" path="$2" enc="$3" status="$4" ce="$5" bytes="$6"
  shift 6
  jq -cn --arg resource "$name" --arg path "$path" --arg enc "$enc" --argjson status "$status" \
    --arg ce "$ce" --argjson bytes "$bytes" --args '
      def med: sort | if length % 2 == 1 then .[(length - 1) / 2] else (.[length / 2 - 1] + .[length / 2]) / 2 end;
      {resource: $resource, path: $path, accept_encoding: $enc, status: $status,
       content_encoding: $ce, wire_bytes: $bytes, seconds: ($ARGS.positional | map(tonumber)),
       seconds_median: ($ARGS.positional | map(tonumber) | med)}' "$@" >> "$RESOURCES"
}

measure_resource() {
  local name="$1" path="$2" enc="$3" status bytes seconds ce
  local -a times=()
  for _ in $(seq "$REPEATS"); do
    read -r status bytes seconds < <(fetch "$path" "$enc")
    [ "$status" = "200" ] || { echo "$name with Accept-Encoding '$enc' answered $status." >&2; exit 1; }
    ce="$(content_encoding)"
    times+=("$seconds")
  done
  record_row "$name" "${path%%\?*}" "$enc" "$status" "$ce" "$bytes" "${times[@]}"
}

for resource in "html:/listings/$LISTING" "css:$CSS_PATH" "page-js:$PAGE_JS_PATH" "lab-bundle:$LAB_JS_PATH"; do
  name="${resource%%:*}"
  path="${resource#*:}"
  for enc in "${ENCODINGS[@]}"; do
    measure_resource "$name" "$path" "$enc"
  done
done

EXPORT_OK=true
for enc in "${ENCODINGS[@]}"; do
  cpu_samples=()
  time_samples=()
  single=true
  for _ in $(seq "$REPEATS"); do
    before="$(cpu_usec)"
    read -r status bytes seconds < <(fetch "$EXPORT_PATH" "$enc")
    after="$(cpu_usec)"
    [ "$status" = "200" ] || { echo "export with Accept-Encoding '$enc' answered $status." >&2; exit 1; }
    cpu_samples+=("$(awk -v a="$before" -v b="$after" 'BEGIN { printf "%.3f", (b - a) / 1000000 }')")
    time_samples+=("$seconds")
    ce="$(content_encoding)"
    if [ "$enc" = identity ] && [ -n "$ce" ]; then
      single=false
      echo "export with Accept-Encoding 'identity' answered with Content-Encoding '$ce'." >&2
    elif [[ "$ce" == *,* ]]; then
      single=false
      echo "export with Accept-Encoding '$enc' was compressed more than once: Content-Encoding '$ce'." >&2
    fi
  done
  cpu_median="$(printf '%s\n' "${cpu_samples[@]}" | median)"
  # The same downloads feed the export row of the resource table, so the export is fetched once per repeat.
  record_row export "${EXPORT_PATH%%\?*}" "$enc" "$status" "$ce" "$bytes" "${time_samples[@]}"
  decoded_sha="$(client --compressed -H "Accept-Encoding: $enc" -o - "$BASE_URL$EXPORT_PATH" | sha_of_stdin)"
  sha_ok=false
  [ "$decoded_sha" = "$EXPECTED_SHA" ] && sha_ok=true
  [ "$single" = true ] || EXPORT_OK=false
  [ "$sha_ok" = true ] || { echo "export with Accept-Encoding '$enc' decoded to sha256 $decoded_sha, expected $EXPECTED_SHA." >&2; EXPORT_OK=false; }
  jq -cn --arg enc "$enc" --arg ce "$ce" --argjson bytes "$bytes" --argjson cpu "$cpu_median" \
    --argjson cpus "$(printf '%s\n' "${cpu_samples[@]}" | jq -s .)" \
    --argjson times "$(printf '%s\n' "${time_samples[@]}" | jq -s .)" \
    --argjson single "$single" --argjson sha_ok "$sha_ok" --arg sha "$decoded_sha" \
    'def med: sort | if length % 2 == 1 then .[(length - 1) / 2] else (.[length / 2 - 1] + .[length / 2]) / 2 end;
     {accept_encoding: $enc, content_encoding: $ce, wire_bytes: $bytes, seconds: $times,
      seconds_median: ($times | med),
      cpu_samples: $cpus, cpu_seconds: $cpu, single_encoding: $single, decoded_sha256: $sha, sha_ok: $sha_ok}' >> "$EXPORT_ROWS"
done

mkdir -p "$OUT"
COMMIT="$(cd "$APPROOT" && git rev-parse --short HEAD 2>/dev/null || echo unknown)"
jq -n --arg server "$SERVER" --arg label "$LABEL" --arg commit "$COMMIT" --arg date "$DAY" --argjson repeats "$REPEATS" \
  --argjson listing "$LISTING" --arg sha "$EXPECTED_SHA" --argjson bytes "$EXPECTED_BYTES" --argjson ok "$EXPORT_OK" --arg image "$DECODE_IMAGE" --arg image_id "$IMAGE_ID" \
  --slurpfile resources "$RESOURCES" --slurpfile export "$EXPORT_ROWS" \
  '{server: $server, label: $label, date: $date, commit: $commit, listing: $listing, repeats: $repeats, client: {image: $image, image_id: $image_id, protocol: "h2"},
    sha256_source: "exports record",
    resources: $resources, export: {expected_sha256: $sha, expected_bytes: $bytes, ok: $ok, rows: $export}}' > "$OUT/$LABEL.json"

echo "server=$SERVER repeats=$REPEATS export_ok=$EXPORT_OK result=$OUT/$LABEL.json"
jq -r '.resources[] | [.resource, .accept_encoding, .content_encoding, .wire_bytes, .seconds_median] | @tsv' "$OUT/$LABEL.json"
[ "$EXPORT_OK" = true ] || exit 1
