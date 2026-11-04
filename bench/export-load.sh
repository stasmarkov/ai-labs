#!/usr/bin/env bash
## Usage: bench/export-load.sh --mode <offload|stream> [--clients <n>] [--rate <curl rate>] [--quick] [--label <text>]
## Measures what slow export downloads do to a light request on the active server.
## <n> clients (default 20) each download GET /exports/listings?mode=<mode> over and over in a closed loop, every
## download limited by curl --limit-rate (default 2M per second, so one 50 MiB file takes about 25 s and holds its slot that long).
## A download that started before the end of the window is allowed to finish. The clients run in one container.
## Every download is checked by size against the export file; the first download of each client is also checked by
## sha256. Any mismatch, or a status other than 200, counts as an error (integrity_errors counts the mismatches).
## At the same time the shared probe (bench/lib/health-probe.sh) asks GET /healthz at 5 requests per second.
## After each repeat the PHP side is read from storage/logs/exports.jsonl, keeping the lines of that repeat's X-Bench-Run tag:
## downloads PHP handled, PHP hold time (median, p95), peak memory, CPU and the bytes PHP itself wrote.
## CPU is per request where one PHP process serves one request at a time. FrankenPHP runs requests as threads of one process
## and PHP has no per-thread getrusage, so its log lines carry cpu_ms null and the table prints "n/a (shared process)".
## Default method: measure 60 s, 3 repeats. --quick (development only): measure 15 s, 1 repeat, rate 10M, no restart.
## The harness sets .env to APP_ENV=production, APP_DEBUG=false, DEBUG_ROUTES=true and a high EXPORTS_PER_MINUTE,
## runs `artisan optimize`, needs the export file (ddev artisan exports:generate) and restores .env on exit.
## Output: bench/results/export-load/<UTC date>/<mode>-<label>.json (--quick: the gitignored bench/results/raw/)
## and a table on stdout. Exit 2 names a failed run condition.
## Env: BENCH_MEASURE (seconds), BENCH_REPEATS, BENCH_SETTLE, BENCH_RATE, BENCH_APPROOT, BENCH_BASE_URL, BENCH_OUT_DIR,
##      BENCH_READY_TIMEOUT, BENCH_MAX_TIME (seconds per download, default 300), BENCH_EXPORT_ID (default listings),
##      BENCH_OHA_IMAGE, BENCH_CLIENT_IMAGE.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
OHA_IMAGE="${BENCH_OHA_IMAGE:-autopulse-oha:1.16.0}"   # built locally from bench/oha/Dockerfile
CLIENT_IMAGE="${BENCH_CLIENT_IMAGE:-curlimages/curl:8.11.1}"
EXPORT_ID="${BENCH_EXPORT_ID:-listings}"
source "$SCRIPT_DIR/lib/health-probe.sh"
SPREAD_LIMIT=20

usage() {
  echo "Usage: $0 --mode <offload|stream> [--clients <n>] [--rate <curl rate>] [--quick] [--label <text>]" >&2
  exit 2
}

mode=""
clients=20
rate=""
quick=0
label="cell"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --mode) mode="${2:-}"; shift 2 || usage ;;
    --clients) clients="${2:-}"; shift 2 || usage ;;
    --rate) rate="${2:-}"; shift 2 || usage ;;
    --quick) quick=1; shift ;;
    --label) label="${2:?--label needs a value}"; shift 2 ;;
    *) usage ;;
  esac
done
[[ "$mode" == "offload" || "$mode" == "stream" ]] || usage
[[ "$clients" =~ ^[1-9][0-9]*$ ]] || usage
[[ "$label" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid --label '$label': use a-z, 0-9 and -." >&2; exit 2; }

if [[ "$quick" == "1" ]]; then
  MEASURE="${BENCH_MEASURE:-15}"
  REPEATS="${BENCH_REPEATS:-1}"
  SETTLE="${BENCH_SETTLE:-0}"
  [[ -n "$rate" ]] || rate="${BENCH_RATE:-10M}"
else
  MEASURE="${BENCH_MEASURE:-60}"
  REPEATS="${BENCH_REPEATS:-3}"
  SETTLE="${BENCH_SETTLE:-10}"
  [[ -n "$rate" ]] || rate="${BENCH_RATE:-2M}"
fi
MAX_TIME="${BENCH_MAX_TIME:-300}"
[[ "$REPEATS" =~ ^[1-9][0-9]*$ ]] || { echo "BENCH_REPEATS must be a positive integer (got '$REPEATS')." >&2; exit 2; }
[[ "$rate" =~ ^[1-9][0-9]*[kKmMgG]?$ ]] || { echo "Invalid --rate '$rate': use curl's form, for example 2M." >&2; exit 2; }

cd "$APPROOT"
ENV_FILE="$APPROOT/.env"
BACKUP="$APPROOT/.env.bench-backup"
WORK="$(mktemp -d)"
RUN_ID="$$"
CLIENT_NAME="export-load-clients-$RUN_ID"
PROBE_NAME="export-load-probe-$RUN_ID"
env_switched=0
worker_restarted=0

restore_env() {
  docker rm -f "$CLIENT_NAME" "$PROBE_NAME" >/dev/null 2>&1 || true
  if [[ "$env_switched" == "1" && -f "$BACKUP" ]]; then
    cp -p "$BACKUP" "$ENV_FILE"
    rm -f "$BACKUP"
    ddev artisan optimize:clear >/dev/null 2>&1 || true
    if [[ "$worker_restarted" == "1" ]]; then
      ddev restart >/dev/null 2>&1 || true
    fi
  fi
  rm -rf "$WORK"
}
trap restore_env EXIT
trap 'exit 130' INT TERM

refuse() {
  echo "Run condition failed: $1" >&2
  exit 2
}

set_env_value() {
  local key="$1" value="$2"
  { grep -v "^${key}=" "$ENV_FILE" || true; echo "${key}=${value}"; } > "$WORK/env.new"
  cat "$WORK/env.new" > "$ENV_FILE"
}

if [[ -e "$BACKUP" ]]; then
  echo "Stale $BACKUP exists: a previous run did not restore .env. Restore it by hand first." >&2
  exit 2
fi
[[ -f "$ENV_FILE" ]] || { echo "No .env at $ENV_FILE" >&2; exit 2; }

server="$(ddev server --current)"
BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
HOST="${BASE_URL#*://}"
HOST="${HOST%%/*}"
HOST_NAME="${HOST%%:*}"
EXPORT_URL="$BASE_URL/exports/$EXPORT_ID?mode=$mode"
EXPORT_FILE="storage/app/exports/$EXPORT_ID.csv"

cp -p "$ENV_FILE" "$BACKUP"
env_switched=1
set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value DEBUG_ROUTES true
set_env_value EXPORTS_PER_MINUTE 1000000
ddev artisan optimize >/dev/null

READY_TIMEOUT="${BENCH_READY_TIMEOUT:-120}"
if [[ "$server" == "frankenphp-octane" || "$server" == "frankenphp-worker" ]]; then
  ddev restart >/dev/null
  worker_restarted=1
  ready_deadline=$((SECONDS + READY_TIMEOUT))
  until [[ "$(curl -ksS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/healthz" 2>/dev/null || true)" == "200" ]]; do
    [[ "$SECONDS" -lt "$ready_deadline" ]] || refuse "$server did not answer /healthz with 200 within ${READY_TIMEOUT}s after the restart"
    sleep 1
  done
fi

expected_bytes=""
expected_sha256=""
check_conditions() {
  local status
  [[ "$(ddev artisan config:show app.env | awk 'NF {print $NF}')" == "production" ]] || refuse "APP_ENV is not production"
  [[ "$(ddev artisan config:show app.debug | awk 'NF {print $NF}')" == "false" ]] || refuse "APP_DEBUG is not false"
  ddev exec ls bootstrap/cache/config.php >/dev/null 2>&1 || refuse "config cache is not built"
  ddev exec sh -c 'ls bootstrap/cache/routes*.php' >/dev/null 2>&1 || refuse "route cache is not built"
  status="$(curl -ksS --max-time 30 -o "$WORK/check.json" -w '%{http_code}' "$BASE_URL/healthz")" || status=000
  [[ "$status" == "200" ]] || refuse "cannot read /healthz (status $status)"
  ddev exec test -f "$EXPORT_FILE" >/dev/null 2>&1 || refuse "no export file $EXPORT_FILE: run ddev artisan exports:generate"
  expected_bytes="$(ddev exec stat -c %s "$EXPORT_FILE" | tr -d '[:space:]')"
  expected_sha256="$(ddev exec sha256sum "$EXPORT_FILE" | awk '{print $1}')"
  [[ "$expected_bytes" =~ ^[0-9]+$ && "$expected_sha256" =~ ^[0-9a-f]{64}$ ]] || refuse "cannot read the size and sha256 of $EXPORT_FILE"
}
check_conditions

OHA_DIGEST="$(image_digest "$OHA_IMAGE")"
[[ -n "$OHA_DIGEST" ]] || { echo "Cannot read the digest or ID of $OHA_IMAGE; refusing to run." >&2; exit 1; }
CLIENT_DIGEST="$(image_digest "$CLIENT_IMAGE")"
[[ -n "$CLIENT_DIGEST" ]] || { echo "Cannot read the digest or ID of $CLIENT_IMAGE; refusing to run." >&2; exit 1; }

COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
CONCURRENCY_SETTING="$(bash "$SCRIPT_DIR/concurrency.sh" --show 2>/dev/null | tr '\n' ' ' | sed 's/ *$//' || true)"
[[ -n "$CONCURRENCY_SETTING" ]] || CONCURRENCY_SETTING="default"
DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
if [[ "$quick" == "1" ]]; then
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/raw}"
else
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/export-load/$DATE}"
fi
mkdir -p "$OUT_DIR"

# The clients for $1 seconds with the X-Bench-Run tag $2; one JSON line per download lands in $3.
generate_clients() {
  local seconds="$1" tag="$2" out="$3"
  rm -rf "$out"
  mkdir -p "$out"
  chmod 777 "$out"
  docker_run "$CLIENT_NAME" --entrypoint sh -v "$SCRIPT_DIR/lib:/bench-lib:ro" -v "$out:/out" \
    -e "URL=$EXPORT_URL" -e "TAG=$tag" -e "CLIENTS=$clients" -e "DURATION=$seconds" -e "RATE=$rate" -e "MAX_TIME=$MAX_TIME" \
    -e "EXPECT_BYTES=$expected_bytes" -e "EXPECT_SHA256=$expected_sha256" -e "OUT=/out" \
    "$CLIENT_IMAGE" /bench-lib/export-client.sh >/dev/null
}

# Reads the client JSON lines on stdin: downloads, errors, integrity errors and the download times.
parse_clients() {
  jq -s --argjson expected "$expected_bytes" '
    def pct($q): sort | if length == 0 then null else .[(length * $q | ceil) - 1 | if . < 0 then 0 else . end] end;
    (map(.seconds)) as $t
    | length as $n
    | (map(select(.ok | not)) | length) as $errors
    | {
        downloads: $n,
        errors: $errors,
        integrity_errors: (map(select((.status == 200) and ((.size_ok | not) or (.sha_ok | not)))) | length),
        sha_checked: (map(select(.sha_checked)) | length),
        error_percent: (if $n == 0 then 0 else $errors / $n * 100 end),
        bytes_total: (map(.bytes) | add // 0),
        download_p50_s: ($t | pct(0.5)),
        download_p95_s: ($t | pct(0.95)),
        mbit_per_s_per_client_median: ([.[] | select(.ok and .seconds > 0) | .bytes * 8 / 1000000 / .seconds] | pct(0.5))
      }'
}

# Reads storage/logs/exports.jsonl and keeps the lines of the tag $1.
parse_php_log() {
  local tag="$1"
  ddev exec sh -c 'cat storage/logs/exports.jsonl 2>/dev/null || true' \
    | jq -Rn --arg tag "$tag" '
      def pct($q): sort | if length == 0 then null else .[(length * $q | ceil) - 1 | if . < 0 then 0 else . end] end;
      [inputs | fromjson? | select(type == "object" and .bench_run == $tag)] as $l
      | {
          downloads: ($l | length),
          php_held_ms_p50: ($l | map(.php_held_ms) | pct(0.5)),
          php_held_ms_p95: ($l | map(.php_held_ms) | pct(0.95)),
          peak_memory_bytes_max: ($l | map(.peak_memory_bytes) | max),
          cpu_ms_total: (if ($l | length) == 0 then 0 elif ($l | any(.cpu_ms == null)) then null else ($l | map(.cpu_ms) | add) end),
          cpu_scope: (if ($l | any(.cpu_scope == "process")) then "process" else "request" end),
          bytes_sent_by_php_total: ($l | map(.bytes_sent_by_php) | add // 0),
          aborted: ($l | map(select(.aborted)) | length)
        }'
}

if [[ "$quick" == "0" && "$SETTLE" != "0" && "$worker_restarted" == "0" ]]; then
  ddev restart >/dev/null
fi
sleep "$SETTLE"
lookup_router
check_conditions

repeat_files=()
for repeat in $(seq 1 "$REPEATS"); do
  tag="export-load-$server-$mode-$label-$RUN_ID-r$repeat"
  generate_clients "$MEASURE" "$tag" "$WORK/clients-$repeat" &
  clients_pid=$!
  probe_status=0
  generate_probe "$MEASURE" "$WORK/probe.json" || probe_status=$?
  clients_status=0
  wait "$clients_pid" || clients_status=$?
  [[ "$probe_status" == "0" ]] || { echo "The health probe failed (exit $probe_status)." >&2; exit 1; }
  [[ "$clients_status" == "0" ]] || { echo "The export clients failed (exit $clients_status)." >&2; exit 1; }
  file="$WORK/repeat-$repeat.json"
  cat "$WORK"/clients-"$repeat"/client-*.jsonl 2>/dev/null > "$WORK/clients.jsonl" || true
  jq -n --argjson load "$(parse_clients < "$WORK/clients.jsonl")" --argjson health "$(parse_oha probe < "$WORK/probe.json")" \
    --argjson php "$(parse_php_log "$tag")" --arg tag "$tag" --argjson repeat "$repeat" \
    '{repeat: $repeat, bench_run: $tag, load: $load, health: $health, php: $php}' > "$file"
  repeat_files+=("$file")
done

FILE="$OUT_DIR/$mode-$label.json"
jq -s \
  --arg server "$server" --arg mode "$mode" --arg label "$label" --arg commit "$COMMIT" \
  --arg concurrency "$CONCURRENCY_SETTING" --arg base "$BASE_URL" --arg date "$DATE" --arg rate "$rate" \
  --arg clientImage "$CLIENT_IMAGE" --arg clientDigest "$CLIENT_DIGEST" --arg probeImage "$OHA_IMAGE" --arg probeDigest "$OHA_DIGEST" \
  --argjson clients "$clients" --argjson bytes "$expected_bytes" --arg sha "$expected_sha256" \
  --argjson probeQps "$PROBE_QPS" --argjson probeTimeout "$PROBE_TIMEOUT" \
  --argjson measure "$MEASURE" --argjson quick "$quick" --argjson limit "$SPREAD_LIMIT" '
  def nums: map(select(. != null));
  def median: nums | sort | if length == 0 then null elif length % 2 == 1 then .[length / 2 | floor] else (.[length / 2 - 1] + .[length / 2]) / 2 end;
  def spread: nums as $v | ($v | median) as $m
    | if $m == null then null
      elif $m == 0 then (if ($v | max) > 0 then null else 0 end)
      else (($v | max) - ($v | min)) / $m * 100 end;
  def figures: [
    ["load", "downloads", (.load.downloads)], ["load", "errors", (.load.errors)], ["load", "integrity_errors", (.load.integrity_errors)],
    ["load", "error_percent", (.load.error_percent)], ["load", "download_p50_s", (.load.download_p50_s)],
    ["load", "download_p95_s", (.load.download_p95_s)], ["load", "mbit_per_s_per_client", (.load.mbit_per_s_per_client_median)],
    ["health", "p50_ms", (.health.p50_ms)], ["health", "p95_ms", (.health.p95_ms)], ["health", "p95_answered_ms", (.health.p95_answered_ms)],
    ["health", "p99_ms", (.health.p99_ms)], ["health", "errors", (.health.errors)], ["health", "error_percent", (.health.error_percent)],
    ["php", "downloads", (.php.downloads)], ["php", "held_ms_p50", (.php.php_held_ms_p50)], ["php", "held_ms_p95", (.php.php_held_ms_p95)],
    ["php", "peak_memory_bytes_max", (.php.peak_memory_bytes_max)], ["php", "cpu_ms_total", (.php.cpu_ms_total)],
    ["php", "bytes_sent_by_php_total", (.php.bytes_sent_by_php_total)], ["php", "aborted", (.php.aborted)]
  ] | map({key: "\(.[0])_\(.[1])", value: .[2]});
  . as $repeats
  | ([$repeats[] | figures[]] | group_by(.key) | map({key: .[0].key, values: map(.value)})) as $series
  | ($repeats | map(.health.p95_ms) | spread) as $healthSpread
  | ($repeats | map(select(.health.starved == true)) | length) as $starvedRepeats
  | {
      server: $server, mode: $mode, label: $label, commit: $commit, concurrency: $concurrency,
      base_url: $base, date: $date, quick: ($quick == 1),
      clients: $clients, rate: $rate, export_bytes: $bytes, export_sha256: $sha,
      probe_qps: $probeQps, probe_timeout_s: $probeTimeout, measure_s: $measure, repeats: ($repeats | length),
      client_image: $clientImage, client_image_digest: $clientDigest, probe_image: $probeImage, probe_image_digest: $probeDigest,
      health_starved_repeats: $starvedRepeats,
      median: (($series | map({key, value: (.values | median)}) | from_entries) + {health_starved: ($starvedRepeats > 0)}),
      spread_percent: (($series | map({key, value: (.values | spread)}) | from_entries) + {health_p95: $healthSpread}),
      spread_limit_percent: $limit,
      flagged: ($healthSpread != null and $healthSpread > $limit),
      runs: $repeats
    }' "${repeat_files[@]}" > "$FILE"

echo "mode=$mode server=$server setting=\"$CONCURRENCY_SETTING\" clients=$clients rate=$rate measure=${MEASURE}s export=${expected_bytes}B"
{
  printf 'repeat\tdownloads\terrors\tintegrity\thealth_p95_ms\thealth_err_%%\tphp_held_p95_ms\tphp_peak_MiB\tphp_cpu_ms\tphp_bytes\n'
  jq -r 'def r: if . == null then "n/a" else (. * 10 | round / 10 | tostring) end;
    .runs[] | [.repeat, .load.downloads, .load.errors, .load.integrity_errors,
    ((.health.p95_ms | r) + (if .health.starved then " starved" else "" end)), (.health.error_percent | r),
    (.php.php_held_ms_p95 | r), (if .php.peak_memory_bytes_max == null then "n/a" else (.php.peak_memory_bytes_max / 1048576 | r) end),
    (if .php.cpu_scope == "process" then "n/a (shared process)" else (.php.cpu_ms_total | r) end), .php.bytes_sent_by_php_total] | @tsv' "$FILE"
  jq -r 'def r: if . == null then "n/a" else (. * 10 | round / 10 | tostring) end;
    .median | ["median", .load_downloads, .load_errors, .load_integrity_errors,
    ((.health_p95_ms | r) + (if .health_starved then " starved" else "" end)), (.health_error_percent | r),
    (.php_held_ms_p95 | r), (if .php_peak_memory_bytes_max == null then "n/a" else (.php_peak_memory_bytes_max / 1048576 | r) end),
    (if .php_cpu_ms_total == null then "n/a (shared process)" else (.php_cpu_ms_total | r) end), .php_bytes_sent_by_php_total] | @tsv' "$FILE"
} | awk -F'\t' '{ printf "%-8s %-10s %-7s %-10s %-18s %-13s %-16s %-13s %s\n", $1, $2, $3, $4, $5, $6, $7, $8, $9 }'
jq -r 'if .median.load_integrity_errors > 0 or .median.load_errors > 0 then "INTEGRITY: downloads with a wrong size or sha256 or a non-200 status are counted as errors" else empty end' "$FILE"
jq -r '"health p95 spread " + (if .spread_percent.health_p95 == null then "n/a (median 0)" else "\(.spread_percent.health_p95 | . * 10 | round / 10)%" end) + (if .flagged then " FLAGGED: above \(.spread_limit_percent)%" else " (within \(.spread_limit_percent)%)" end)' "$FILE"
echo "Wrote $FILE"
