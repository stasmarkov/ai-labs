#!/usr/bin/env bash
## Usage: bench/restart-load.sh [--quick] [--modes <list>]
## Measures what `frankenphp:workers:restart` does to requests in flight: a closed load of 50 connections runs on a route
## while the command restarts the workers, and the seconds inside the restart windows are compared with the rest.
## Modes (default: frankenphp-worker frankenphp-octane); routes: /api/ping and /listings?make=BMW. One cell per mode and route.
## The load is oha in a pinned one-off container on ddev_default, capped at 2 CPUs and reaching the DDEV router through --add-host.
## Default method (BR-21): warm-up 30 s, then 3 repeats of 60 s, with 3 restarts per repeat at 15, 30 and 45 s.
## --quick (development only): warm-up 5 s, measure 30 s, 1 repeat, one restart at 15 s, and no clean-git-tree check.
## Timing: the load starts when the oha container is running (polled with docker inspect; oha writes its db only at the end),
## and the restart offsets count from that moment. Each restart is `ddev artisan frankenphp:workers:restart`, timed in host
## wall-clock ms; its window runs from the trigger to 2 s after the command returns.
## Two sources, because oha's request database (--db-url) stores only the requests that got an answer:
##   - the load (oha's db, aggregated with sqlite, never loaded whole): completed requests, non-2xx and p99 inside against
##     outside the windows, placed by oha's own start column;
##   - the probe (bench/lib/request-probe.pl): GET on the same route at BENCH_PROBE_RPS (default 20) from the host for the
##     whole measure, one curl per request, each logged with its host ms start, status and curl exit. Its failures
##     (non-2xx, connection errors, timeouts) are what `failures` reports inside against outside the windows.
## oha's own whole-run error count (transport errors and timeouts included, not split by window) is kept as oha_errors_whole_run.
## Over the repeats: the median of the load p99 values and the sum of the failures.
## The harness sets .env to APP_ENV=production, APP_DEBUG=false, DEBUG_ROUTES=true through bench/lib/env-switch.sh, runs
## `artisan optimize`, and on exit restores .env and switches back to the project's default server.
## Output: bench/results/restart/<UTC date>/<mode>-<route>.json (--quick: the gitignored bench/results/raw/)
## with the method, every repeat (restarts with duration_ms and exit code, load and probe figures inside and outside, oha's
## summary), the flat restarts list, the median p99 and the summed failures.
## Env: BENCH_WARMUP, BENCH_MEASURE (seconds), BENCH_REPEATS, BENCH_PROBE_RPS, BENCH_APPROOT, BENCH_OHA_IMAGE,
## BENCH_READY_TIMEOUT (default 120), BENCH_DATE, BENCH_OUT_DIR, BENCH_BASE_URL.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"
# shellcheck source=lib/health-probe.sh
source "$SCRIPT_DIR/lib/health-probe.sh"
# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"
OHA_IMAGE="${BENCH_OHA_IMAGE:-ai-lab-oha:1.16.0}"   # built locally from bench/oha/Dockerfile
CONNECTIONS=50
ROUTES=("/api/ping" "/listings?make=BMW")
WINDOW_TAIL_S=2
PROBE_RPS="${BENCH_PROBE_RPS:-20}"
PROBE_TIMEOUT_S=5

usage() {
  echo "Usage: $0 [--quick] [--modes <frankenphp-worker,frankenphp-octane>]" >&2
  exit 2
}

quick=0
modes="frankenphp-worker,frankenphp-octane"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --quick) quick=1; shift ;;
    --modes) modes="${2:-}"; shift 2 || usage ;;
    *) usage ;;
  esac
done
IFS=',' read -r -a MODE_LIST <<< "$modes"
for mode in "${MODE_LIST[@]}"; do
  [[ "$mode" == "frankenphp-worker" || "$mode" == "frankenphp-octane" ]] || usage
done

if [[ "$quick" == "1" ]]; then
  WARMUP="${BENCH_WARMUP:-5}"
  MEASURE="${BENCH_MEASURE:-30}"
  REPEATS="${BENCH_REPEATS:-1}"
  RESTARTS=1
else
  WARMUP="${BENCH_WARMUP:-30}"
  MEASURE="${BENCH_MEASURE:-60}"
  REPEATS="${BENCH_REPEATS:-3}"
  RESTARTS=3
fi
READY_TIMEOUT="${BENCH_READY_TIMEOUT:-120}"
OFFSETS=()
for ((k = 1; k <= RESTARTS; k++)); do
  OFFSETS+=("$((MEASURE * k / (RESTARTS + 1)))")
done

cd "$APPROOT"
WORK="$(mktemp -d)"
LOAD_NAME="restart-load-$$"
PROBE_PID=""
server_switched=0

read_default_server "$APPROOT"

restore_all() {
  [[ -z "$PROBE_PID" ]] || kill "$PROBE_PID" 2>/dev/null || true
  docker rm -f "$LOAD_NAME" >/dev/null 2>&1 || true
  if [[ "$ENV_SWITCHED" == "1" ]]; then
    env_switch_restore
    ddev artisan optimize:clear >/dev/null 2>&1 || true
  fi
  if [[ "$server_switched" == "1" ]]; then
    restore_default_server "$APPROOT" >/dev/null 2>&1 || true
    ddev restart >/dev/null 2>&1 || true
  fi
  rm -rf "$WORK" 2>/dev/null || true
}
trap restore_all EXIT
trap 'exit 130' INT TERM

refuse() {
  echo "Run condition failed: $1" >&2
  exit 2
}

if [[ "$quick" == "0" ]]; then
  # .ai/session/ is the SDD tooling's own log, rewritten by the tooling itself; it is not app code.
  [[ -z "$(git status --porcelain -- . ':!bench/results' ':!.ai/session' 2>/dev/null || true)" ]] \
    || refuse "git working tree has changes outside bench/results/ and .ai/session/"
fi

DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
if [[ "$quick" == "1" ]]; then
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/raw}"
else
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/restart/$DATE}"
fi
mkdir -p "$OUT_DIR"
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"

env_switch_begin
env_set APP_ENV production
env_set APP_DEBUG false
env_set DEBUG_ROUTES true

wait_ready() {
  local deadline=$((SECONDS + READY_TIMEOUT))
  until [[ "$(curl -ksS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/healthz" 2>/dev/null || true)" == "200" ]]; do
    [[ "$SECONDS" -lt "$deadline" ]] || refuse "the server did not answer /healthz with 200 within ${READY_TIMEOUT}s"
    sleep 1
  done
}

lookup_router
OHA_DIGEST="$(image_digest "$OHA_IMAGE")"
[[ -n "$OHA_DIGEST" ]] || { echo "Cannot read the digest or ID of $OHA_IMAGE; refusing to run." >&2; exit 1; }

now_ms() {
  perl -MTime::HiRes=time -e 'printf "%d\n", time * 1000'
}

command -v sqlite3 >/dev/null 2>&1 || { echo "sqlite3 is needed on the host to read oha's per-request data." >&2; exit 1; }
command -v perl >/dev/null 2>&1 || { echo "perl is needed on the host for the request probe." >&2; exit 1; }

# Prints the load figures inside and outside the windows as one JSON object, computed in sqlite so the rows are never
# loaded into the shell or jq. $1 is the db, $2 the windows JSON. p99 is the nearest-rank value: row ceil(0.99 n).
load_figures() {
  local db="$1" windows="$2" values
  values="$(jq -r 'map("(\(.window_from_s), \(.window_to_s))") | join(", ")' <<< "$windows")"
  [[ -n "$values" ]] || values="(-1, -1)"
  sqlite3 -json "$db" "
    create temp table w(f real, t real);
    insert into w values $values;
    create temp table r as
      select duration * 1000.0 as ms, (status < 200 or status >= 300) as bad,
             exists(select 1 from w where oha.start >= w.f and oha.start <= w.t) as inside
      from oha;
    select g.inside as inside, count(r.ms) as requests, coalesce(sum(r.bad), 0) as non_2xx,
           (select ms from (select ms, row_number() over (order by ms) as rn, count(*) over () as n from r r2 where r2.inside = g.inside)
            where rn = (99 * n + 99) / 100) as p99_ms
    from (select 0 as inside union all select 1) g left join r on r.inside = g.inside
    group by g.inside;" | jq -c '
      def pick($i): (map(select(.inside == $i)) | .[0] // {requests: 0, non_2xx: 0, p99_ms: null}) | {requests, non_2xx, p99_ms};
      {inside: pick(1), outside: pick(0)}'
}

# Prints the probe figures inside and outside the windows. $1 is the probe CSV, $2 the windows JSON (host ms).
probe_figures() {
  local csv="$1" windows="$2"
  jq -n -R --argjson windows "$windows" --argjson tail "$WINDOW_TAIL_S" '
    def figures: {requests: length,
      failures: (map(select(.exit != 0 or .code < 200 or .code >= 300)) | length),
      connection_errors: (map(select(.exit != 0 and .exit != 28)) | length),
      timeouts: (map(select(.exit == 28)) | length),
      non_2xx: (map(select(.exit == 0 and (.code < 200 or .code >= 300))) | length)};
    [inputs | split(",") | select(length >= 3)
     | {t: (.[0] | tonumber), code: (.[1] | tonumber), exit: (.[2] | tonumber)}
     | . + {inside: (.t as $t | any($windows[]; $t >= .start_ms and $t <= .end_ms + $tail * 1000))}] as $rows
    | {inside: ($rows | map(select(.inside)) | figures), outside: ($rows | map(select(.inside | not)) | figures)}' < "$csv"
}

# One measured repeat on $route into $WORK/repeat.json: the load and the probe run in the background, the restarts fire
# at the offsets counted from the moment the load container runs.
run_repeat() {
  local repeat="$1" db="$WORK/oha.db" windows="[]" off start_ms end_ms wait_ms restart_status restart_output load_pid load_start_ms deadline load_status
  rm -f "$db" "$WORK/load.json" "$WORK/probe.csv"
  : > "$WORK/probe.csv"
  docker_run "$LOAD_NAME" -v "$WORK:/out" "$OHA_IMAGE" -c "$CONNECTIONS" -z "${MEASURE}s" -w -t 30s --no-tui --insecure \
    --db-url /out/oha.db --output-format json "$BASE_URL$route" > "$WORK/load.json" &
  load_pid=$!
  deadline=$((SECONDS + 60))
  until [[ "$(docker inspect -f '{{.State.Running}}' "$LOAD_NAME" 2>/dev/null || true)" == "true" ]]; do
    kill -0 "$load_pid" 2>/dev/null || { echo "The load container exited before it was seen running." >&2; exit 1; }
    [[ "$SECONDS" -lt "$deadline" ]] || { echo "oha did not start within 60 s." >&2; exit 1; }
    sleep 0.05
  done
  load_start_ms="$(now_ms)"
  perl "$SCRIPT_DIR/lib/request-probe.pl" "$BASE_URL$route" "$MEASURE" "$PROBE_RPS" "$PROBE_TIMEOUT_S" "$WORK/probe.csv" &
  PROBE_PID=$!
  for off in "${OFFSETS[@]}"; do
    wait_ms=$((load_start_ms + off * 1000 - $(now_ms)))
    if [[ "$wait_ms" -gt 0 ]]; then sleep "$(awk -v ms="$wait_ms" 'BEGIN { printf "%.3f", ms / 1000 }')"; fi
    restart_status=0
    start_ms="$(now_ms)"
    restart_output="$(ddev artisan frankenphp:workers:restart 2>&1)" || restart_status=$?
    end_ms="$(now_ms)"
    windows="$(jq -c --argjson off "$off" --argjson s "$start_ms" --argjson e "$end_ms" --argjson st "$restart_status" \
      --arg out "$restart_output" --argjson ls "$load_start_ms" --argjson tail "$WINDOW_TAIL_S" --argjson measure "$MEASURE" '
      . + [{offset_s: $off, start_ms: $s, end_ms: $e, duration_ms: ($e - $s), exit_code: $st, output: $out,
            window_from_s: ([0, ($s - $ls) / 1000] | max), window_to_s: ([$measure, ($e - $ls) / 1000 + $tail] | min)}]' <<< "$windows")"
  done
  load_status=0
  wait "$load_pid" || load_status=$?
  wait "$PROBE_PID" 2>/dev/null || true
  PROBE_PID=""
  [[ "$load_status" == "0" ]] || { echo "The load generator failed (exit $load_status)." >&2; exit 1; }
  wait_ready
  [[ -s "$db" ]] || { echo "oha wrote no request database." >&2; exit 1; }
  load_figures "$db" "$windows" > "$WORK/load-figures.json"
  probe_figures "$WORK/probe.csv" "$windows" > "$WORK/probe-figures.json"
  parse_oha load < "$WORK/load.json" > "$WORK/summary.json"
  jq -n --argjson repeat "$repeat" --argjson windows "$windows" --argjson ls "$load_start_ms" \
    --slurpfile figures "$WORK/load-figures.json" --slurpfile probe "$WORK/probe-figures.json" --slurpfile summary "$WORK/summary.json" '
    {repeat: $repeat, load_start_ms: $ls, restarts: $windows,
     inside: $figures[0].inside, outside: $figures[0].outside,
     probe: $probe[0], load: $summary[0]}' > "$WORK/repeat.json"
}

for mode in "${MODE_LIST[@]}"; do
  server_switched=1
  ddev server "$mode" >/dev/null
  ddev artisan optimize >/dev/null
  ddev restart >/dev/null
  server="$(current_server "$APPROOT")" || exit 2
  [[ "$server" == "$mode" ]] || refuse "expected $mode, the server is $server"
  BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
  HOST="${BASE_URL#*://}"
  HOST="${HOST%%/*}"
  HOST_NAME="${HOST%%:*}"
  wait_ready

  for route in "${ROUTES[@]}"; do
    name="${route#/}"
    name="$(printf '%s' "${name//[^A-Za-z0-9]/-}" | sed 's/--*/-/g; s/-$//')"
    docker_run "$LOAD_NAME" "$OHA_IMAGE" -c "$CONNECTIONS" -z "${WARMUP}s" -w -t 30s --no-tui --insecure \
      --output-format json "$BASE_URL$route" > /dev/null

    rm -f "$WORK"/repeat-*.json
    for ((repeat = 1; repeat <= REPEATS; repeat++)); do
      run_repeat "$repeat"
      cp "$WORK/repeat.json" "$WORK/repeat-$repeat.json"
    done

    file="$OUT_DIR/$mode-$name.json"
    jq -s \
      --arg server "$mode" --arg route "$route" --arg commit "$COMMIT" --arg date "$DATE" --arg base "$BASE_URL" \
      --arg image "$OHA_IMAGE" --arg digest "$OHA_DIGEST" --argjson connections "$CONNECTIONS" --argjson warmup "$WARMUP" \
      --argjson measure "$MEASURE" --argjson repeats "$REPEATS" --argjson offsets "$(printf '%s\n' "${OFFSETS[@]}" | jq -sc .)" \
      --argjson tail "$WINDOW_TAIL_S" --argjson quick "$quick" --argjson probe_rps "$PROBE_RPS" '
      def median: map(select(. != null)) | sort | if length == 0 then null elif length % 2 == 1 then .[length / 2 | floor] else (.[length / 2 - 1] + .[length / 2]) / 2 end;
      {server: $server, route: $route, commit: $commit, date: $date, base_url: $base, quick: ($quick == 1),
       connections: $connections, warmup_s: $warmup, measure_s: $measure, repeats: $repeats,
       restart_offsets_s: $offsets, window_tail_s: $tail, probe_rps: $probe_rps,
       load_image: $image, load_image_digest: $digest,
       runs: .,
       restarts: (map(.restarts) | add),
       median_p99_ms: {inside: (map(.inside.p99_ms) | median), outside: (map(.outside.p99_ms) | median)},
       failures: {inside: (map(.probe.inside.failures) | add), outside: (map(.probe.outside.failures) | add)},
       probe_requests: {inside: (map(.probe.inside.requests) | add), outside: (map(.probe.outside.requests) | add)},
       load_non_2xx: {inside: (map(.inside.non_2xx) | add), outside: (map(.outside.non_2xx) | add)},
       oha_errors_whole_run: (map(.load.errors) | add),
       failures_note: "failures are probe requests (non-2xx, connection errors, timeouts) by window; load_non_2xx and the p99 values come from the load, whose db holds answered requests only; oha_errors_whole_run counts every load error, not split by window"}' \
      "$WORK"/repeat-*.json > "$file"
    jq -r '"\(.server) \(.route): \(.restarts | length) restarts, p99 inside \(.median_p99_ms.inside | . // 0 | . * 10 | round / 10) ms, outside \(.median_p99_ms.outside | . // 0 | . * 10 | round / 10) ms, probe failures inside \(.failures.inside)/\(.probe_requests.inside), outside \(.failures.outside)/\(.probe_requests.outside), load non-2xx inside \(.load_non_2xx.inside), oha errors whole run \(.oha_errors_whole_run)"' "$file"
    echo "Wrote $file"
  done
done
