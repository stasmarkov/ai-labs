#!/usr/bin/env bash
## Usage: bench/metrics-snapshot.sh <cell> <from-ms> <to-ms> [--load-window <from-ms>-<to-ms>] [--no-screenshot] [--force]
## Reads one window of the metrics stack (`ddev metrics on`) and saves it for a slow-load cell: a JSON with the maximum busy
## threads, busy workers, total workers, queue depth and resident memory and the p95 request latency, and a PNG of the
## whole Grafana dashboard for <from-ms> to <to-ms>. <cell> is `<workload>-<server>-<threads>` (a-z, 0-9, -); the times are
## epoch milliseconds, which bench/metrics-matrix.sh takes just before and after the slow-load call.
## --load-window narrows the numbers (not the PNG) to the time the load generator ran: bench/metrics-matrix.sh watches for the
## slow-load load container and passes its first start and last stop, so restarts and .env switching are left out. The window
## still holds the warm-ups (the same load) and the health probe's 5 requests per second, which share the handler with the load.
## Without it the numbers cover the whole window.
## Prometheus is asked from inside its container (its port is not published). The p95 is histogram_quantile over the increase
## across the window, one value; the maxima are max_over_time. One Caddy handler per mode is counted (vars, php, subroute),
## so a request counts once and the scrape's own requests (handler "metrics") are left out.
## Caddy's latency histogram has no bucket above 10 s: a p95 at or above 10 s is written as the string ">10 (bucket cap)"
## with p95_capped true; read long SSE stream times from slow-load.sh instead.
## In worker modes busy_threads counts idle worker threads and queue_depth counts only regular requests, so read
## busy_workers against total_workers there.
## The scrape coverage is the successful (up = 1) scrapes of job frankenphp in the window divided by the window in seconds
## (scrape interval 1 s). Below 0.8, or with no busy-threads sample, it exits 1 and writes nothing.
## The PNG comes from bench/metrics-screenshot.mjs in the pinned Playwright image on ddev_default (Grafana over plain HTTP,
## kiosk mode, auto-refresh off). With PLAYWRIGHT_CONTAINER set it runs in that already started container (the matrix
## starts one per run and installs playwright-core once); otherwise it starts a one-off container.
## --no-screenshot writes the JSON only. A second run for the same cell refuses to overwrite unless --force is given.
## Output: bench/results/metrics/<UTC date>/<cell>.json and <cell>.png.
## Env: BENCH_APPROOT, BENCH_DATE, BENCH_OUT_DIR, DDEV_SITENAME, PLAYWRIGHT_IMAGE, PLAYWRIGHT_CONTAINER.

set -Eeuo pipefail
# shellcheck source=lib/servers.sh
source "$(dirname "${BASH_SOURCE[0]}")/lib/servers.sh"

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
SITE="${DDEV_SITENAME:-ai-lab}"
PROMETHEUS="ddev-${SITE}-prometheus"
GRAFANA="ddev-${SITE}-grafana"
# shellcheck source=lib/playwright.sh
source "$SCRIPT_DIR/lib/playwright.sh"
DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/metrics/$DATE}"
# One handler per mode counts a request once: vars (classic), php (worker; map and rewrite repeat it), subroute (Octane). The scrape's own handler (metrics) is left out.
HANDLERS='handler=~"php|vars|subroute"'

usage() {
  echo "Usage: bench/metrics-snapshot.sh <cell> <from-ms> <to-ms> [--load-window <from-ms>-<to-ms>] [--no-screenshot] [--force]" >&2
  exit 2
}

screenshot=1
force=0
load_window=""
load_window_given=0
positional=()
while [[ $# -gt 0 ]]; do
  case "$1" in
    --help|-h) sed -n '2,/^$/p' "${BASH_SOURCE[0]}" | sed -n 's/^## \{0,1\}//p'; exit 0 ;;
    --no-screenshot) screenshot=0 ;;
    --force) force=1 ;;
    --load-window) load_window="${2:-}"; load_window_given=1; [[ $# -ge 2 ]] && shift ;;
    --*) usage ;;
    *) positional+=("$1") ;;
  esac
  shift
done
[[ ${#positional[@]} -eq 3 ]] || usage
cell="${positional[0]}"
from_ms="${positional[1]}"
to_ms="${positional[2]}"
[[ "$cell" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid cell '$cell': use a-z, 0-9 and -." >&2; exit 2; }
[[ "$from_ms" =~ ^[0-9]+$ && "$to_ms" =~ ^[0-9]+$ ]] || { echo "<from-ms> and <to-ms> must be epoch milliseconds." >&2; exit 2; }
(( to_ms > from_ms )) || { echo "<to-ms> must be after <from-ms>." >&2; exit 2; }

json_file="$OUT_DIR/$cell.json"
png_file="$OUT_DIR/$cell.png"
if [[ "$force" == "0" && ( -e "$json_file" || -e "$png_file" ) ]]; then
  echo "$json_file or the PNG exists; pass --force to overwrite." >&2
  exit 2
fi

load_from_ms="$from_ms"
load_to_ms="$to_ms"
if [[ "$load_window_given" == "1" ]]; then
  [[ "$load_window" =~ ^([0-9]+)-([0-9]+)$ ]] || { echo "--load-window must be <from-ms>-<to-ms>." >&2; exit 2; }
  load_from_ms="${BASH_REMATCH[1]}"
  load_to_ms="${BASH_REMATCH[2]}"
  (( load_to_ms > load_from_ms )) || { echo "The load window must end after it starts." >&2; exit 2; }
fi

window_s=$(( (to_ms - from_ms + 999) / 1000 ))
load_s=$(( (load_to_ms - load_from_ms + 999) / 1000 ))
eval_time="$(printf '%d.%03d' $(( load_to_ms / 1000 )) $(( load_to_ms % 1000 )))"

urlencode() {
  local s="$1" i c out=""
  for (( i = 0; i < ${#s}; i++ )); do
    c="${s:i:1}"
    case "$c" in
      [a-zA-Z0-9.~_-]) out+="$c" ;;
      *) out+="$(printf '%%%02X' "'$c")" ;;
    esac
  done
  printf '%s' "$out"
}

# Prints the first sample value of an instant query, or "null" when there is none or it is not a finite number.
query_value() {
  local response value
  response="$(docker exec "$PROMETHEUS" wget -qO- "http://localhost:9090/api/v1/query?query=$(urlencode "$1")&time=$eval_time")" \
    || { echo "Prometheus is not answering in $PROMETHEUS; run ddev metrics on." >&2; exit 1; }
  value="$(printf '%s' "$response" | sed -n 's/.*"value":\[[^,]*,"\([^"]*\)"\].*/\1/p')"
  if [[ "$value" =~ ^-?[0-9]+(\.[0-9]+)?([eE][+-]?[0-9]+)?$ ]]; then
    printf '%s' "$value"
  else
    printf 'null'
  fi
}

w="${load_s}s"
max_busy="$(query_value "max(max_over_time(frankenphp_busy_threads[$w]))")"
max_queue="$(query_value "max(max_over_time(frankenphp_queue_depth[$w]))")"
max_busy_workers="$(query_value "sum(max_over_time(frankenphp_busy_workers[$w]))")"
total_workers="$(query_value "sum(max_over_time(frankenphp_total_workers[$w]))")"
max_memory="$(query_value "max(max_over_time(process_resident_memory_bytes[$w]))")"
p95="$(query_value "histogram_quantile(0.95, sum by (le) (increase(caddy_http_request_duration_seconds_bucket{$HANDLERS}[$w])))")"

samples="$(query_value "sum_over_time(up{job=\"frankenphp\"}[$w])")"
coverage="null"
[[ "$samples" != "null" ]] && coverage="$(awk -v n="$samples" -v w="$load_s" 'BEGIN { printf "%.4g", n / w }')"
if [[ "$coverage" == "null" ]] || awk -v c="$coverage" 'BEGIN { exit !(c < 0.8) }'; then
  echo "Window of $cell was not scraped: scrape coverage $coverage is below 0.8 (Prometheus was probably stopped during the run)." >&2
  exit 1
fi
if [[ "$max_busy" == "null" ]]; then
  echo "max_busy_threads is null for $cell: no frankenphp samples in the window." >&2
  exit 1
fi

p95_capped=false
if [[ "$p95" != "null" ]] && awk -v p="$p95" 'BEGIN { exit !(p >= 10) }'; then
  echo "p95 of $cell is at or above 10 s, the top Caddy histogram bucket: writing \">10 (bucket cap)\"." >&2
  p95='">10 (bucket cap)"'
  p95_capped=true
fi

server="$(current_server "$APPROOT" 2>/dev/null)" || server="unknown"
commit="$(cd "$APPROOT" && git rev-parse --short HEAD 2>/dev/null)" || commit="unknown"

mkdir -p "$OUT_DIR"
printf '{\n  "cell": "%s",\n  "server": "%s",\n  "commit": "%s",\n  "from_ms": %s,\n  "to_ms": %s,\n  "window_s": %s,\n  "load_from_ms": %s,\n  "load_to_ms": %s,\n  "load_s": %s,\n  "scrape_coverage": %s,\n  "metrics": {\n    "max_busy_threads": %s,\n    "max_busy_workers": %s,\n    "total_workers": %s,\n    "max_queue_depth": %s,\n    "max_memory_bytes": %s,\n    "p95_seconds": %s,\n    "p95_capped": %s\n  },\n  "counted": "%s"\n}\n' \
  "$cell" "$server" "$commit" "$from_ms" "$to_ms" "$window_s" "$load_from_ms" "$load_to_ms" "$load_s" "$coverage" \
  "$max_busy" "$max_busy_workers" "$total_workers" "$max_queue" "$max_memory" "$p95" "$p95_capped" \
  "one handler per mode (vars, php, subroute): load, warm-up and health probe; not the scrape" > "$json_file"
echo "cell=$cell load_s=$load_s max_busy_threads=$max_busy max_busy_workers=$max_busy_workers total_workers=$total_workers max_queue_depth=$max_queue max_memory_bytes=$max_memory p95_seconds=$p95"

if [[ "$screenshot" == "1" ]]; then
  url="$(playwright_dashboard_url "$GRAFANA" "$from_ms" "$to_ms")"
  if [[ -n "${PLAYWRIGHT_CONTAINER:-}" ]]; then
    docker exec "$PLAYWRIGHT_CONTAINER" bash -c "$(playwright_shot_command "$url" "$cell")"
  else
    docker run --rm --network ddev_default \
      -v "$SCRIPT_DIR:/bench:ro" -v "$OUT_DIR:/out" \
      -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" \
      "$PLAYWRIGHT_IMAGE_PINNED" bash -c "$PLAYWRIGHT_INSTALL && $(playwright_shot_command "$url" "$cell")"
  fi
  echo "screenshot=$png_file"
fi
