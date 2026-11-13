#!/usr/bin/env bash
## Usage: bench/llm-load.sh concurrency [--models=<a,b>] [--quick] [--force]
##        bench/llm-load.sh search-under-load [--quick] [--force]
## Measures what the app does when several real describe streams run at once against a local Ollama model, on the
## server that is up now (`ddev server --current`, recorded in the result).
## concurrency: for each model and each level in 1, 4 and 16 it runs max(8, 2 x level) real GET /listings/{id}/describe
## streams with `level` held open at once (ids rotate over 1..50000, so no cache helps; the warm-up describe uses id 50000). bench/k6-sse.js runs in a one-off
## k6 container on ddev_default in real mode (ITERATIONS) with a 300 s request timeout. One describe is sent before level 1
## and left out, so the cold model load does not count. Per level it records the stream count, the time to the first byte
## of the stream (TTFT: the stream opens with Ollama's first chunk, so this is prompt evaluation plus the first token) and
## the time to [DONE] as p50/p95, text events per second per stream (median, from the first chunk to [DONE]) and aggregate ("chunks/s": SSE chunks, not
## tokens; tokens/s lives in `ddev artisan llm:bench`), the non-200 or incomplete share (a non-200 status, no [DONE], an
## error or refused event, or a timeout) and the 429 count. It also records OLLAMA_NUM_PARALLEL as the app sees it
## ('default' when unset; the host Ollama's own value is not readable from the app).
## --models defaults to qwen3.5:9b,gemma4:12b; each name must match ^[A-Za-z0-9._:/-]+$ (exit 2 otherwise). --quick (development only): levels 1 and 4 with 4 streams each.
## The model is chosen through .env (AI_DESCRIBE_PROVIDER=ollama, AI_DESCRIBE_MODEL, AI_DESCRIBE_PER_MINUTE=1000) with the
## shared bench backup (bench/lib/env-switch.sh), `ddev artisan optimize`, and a restart on worker servers. On any exit,
## including Ctrl-C, the k6 container is removed, .env is restored and the caches are cleared; the DDEV project config is never
## touched. A stale .env.bench-backup stops the run with exit 2 before any load.
## Output: bench/results/llm-load/<UTC date>/concurrency.json and a table on stdout. An existing day is refused (exit 2)
## without --force.
##
## search-under-load: for each server in SUPPORTED_SERVERS it runs `bench/run.sh search` (BR-21: warm-up 30 s, measure 60 s,
## 3 repeats) twice, labelled llm-noload and llm-load. The second run has a k6 loop of 4 real describe streams (closed loop, 300 s
## request timeout) on qwen3.5:9b started right before run.sh, so it covers the warm-up, and stopped after the last repeat. The loop
## reaches the web container by name on ddev_default (HTTP port 80 with the site Host header), so a ddev restart cannot strand it.
## A server whose loop completed no describe, or had half or more of its streams incomplete, stops the run with exit 1 before the file is written.
## Per server it records the median RPS, p50, p95, p99 and error % of both runs, the p95 delta, and the describes that completed
## during the loop with the elapsed seconds and the rate per minute. Servers are switched with `ddev server <mode>` and the one
## active at the start is restored on any exit, through the default-server library: the full run therefore needs the project
## default (`ddev server --default`) to be the active server at the start, and refuses (exit 2) otherwise. --quick: the active server only, with `run.sh search --quick` (5 s, 15 s, 1 repeat).
## run.sh keeps its own .env backup, so this mode keeps its own (.env.llm-load-backup) and refuses a stale .env.bench-backup (exit 2).
## Output: bench/results/llm-load/<UTC date>/search-under-load.json and a table on stdout.
## Env: BENCH_RUN_SCRIPT (default bench/run.sh), BENCH_APPROOT, BENCH_DATE, BENCH_BASE_URL, BENCH_K6_IMAGE, BENCH_READY_TIMEOUT (seconds, default 120).

set -Eeuo pipefail
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
# shellcheck source=lib/health-probe.sh
source "$SCRIPT_DIR/lib/health-probe.sh"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"
# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"

K6_IMAGE="${BENCH_K6_IMAGE:-grafana/k6:1.0.0}"
DEFAULT_MODELS="qwen3.5:9b,gemma4:12b"
REQUEST_TIMEOUT=300s
ID_STEP=10007
# Outside the ids the measured streams start from (they run from 1 up and from the offsets), so the warm-up cannot warm the
# prefix cache of the first measured stream.
WARMUP_ID=50000
MODEL_PATTERN='^[A-Za-z0-9._:/-]+$'

usage() {
  echo "Usage: bench/llm-load.sh concurrency [--models=<a,b>] [--quick] [--force]"
  echo "       bench/llm-load.sh search-under-load [--quick] [--force]"
}

mode=""
models="$DEFAULT_MODELS"
models_given=0
quick=0
force=0
if [[ $# -gt 0 ]]; then
  case "$1" in
    -h|--help) usage; exit 0 ;;
    concurrency|search-under-load) mode="$1"; shift ;;
    *) echo "unknown mode: $1" >&2; usage >&2; exit 2 ;;
  esac
else
  usage >&2
  exit 2
fi
while [[ $# -gt 0 ]]; do
  case "$1" in
    --models=*) models="${1#--models=}"; models_given=1; shift ;;
    --models) models="${2:-}"; models_given=1; shift 2 || { usage >&2; exit 2; } ;;
    --quick) quick=1; shift ;;
    --force) force=1; shift ;;
    -h|--help) usage; exit 0 ;;
    *) echo "unknown argument: $1" >&2; usage >&2; exit 2 ;;
  esac
done
if [[ "$mode" == "search-under-load" && "$models_given" == "1" ]]; then
  echo "search-under-load always uses the default text model; --models is for concurrency." >&2
  exit 2
fi
# The whole value first: `read` below stops at a newline, which would hide whatever follows it.
[[ "$models" =~ ^[A-Za-z0-9._:/,-]+$ ]] || { echo "Invalid model name in '--models': use letters, digits and . _ : / - only, separated by commas." >&2; exit 2; }
IFS=',' read -r -a model_list <<< "$models"
[[ ${#model_list[@]} -gt 0 && -n "${model_list[0]}" ]] || { echo "--models needs at least one model." >&2; usage >&2; exit 2; }
# The names go into .env, so only the characters of an Ollama model tag are accepted.
for candidate in "${model_list[@]}"; do
  [[ "$candidate" =~ $MODEL_PATTERN ]] || { echo "Invalid model name '$candidate': use letters, digits and . _ : / - only." >&2; exit 2; }
done

if [[ "$quick" == "1" ]]; then
  LEVELS=(1 4)
else
  LEVELS=(1 4 16)
fi
streams_for() {
  local level="$1" streams
  if [[ "$quick" == "1" ]]; then
    echo 4
    return
  fi
  streams=$((level * 2))
  if [[ "$streams" -lt 8 ]]; then streams=8; fi
  echo "$streams"
}

DAY="${BENCH_DATE:-$(date -u +%F)}"
OUT_DIR="$APPROOT/bench/results/llm-load/$DAY"
OUT_FILE="$OUT_DIR/$mode.json"
if [[ -e "$OUT_FILE" && "$force" == "0" ]]; then
  echo "$OUT_FILE exists; pass --force to replace it." >&2
  exit 2
fi

WORK="$(mktemp -d)"
K6_NAME="llm-load-k6-$$"
worker_restarted=0
server_changed=0
cleanup() {
  docker rm -f "$K6_NAME" >/dev/null 2>&1 || true
  if [[ "$ENV_SWITCHED" == "1" ]]; then
    env_switch_restore
    (cd "$APPROOT" && ddev artisan optimize:clear >/dev/null 2>&1) || true
    # Worker servers keep the benchmark config in memory until they boot again.
    if [[ "$server_changed" == "1" ]]; then
      restore_default_server "$APPROOT" >/dev/null 2>&1 || true
    elif [[ "$worker_restarted" == "1" ]]; then
      (cd "$APPROOT" && ddev restart >/dev/null 2>&1) || true
    fi
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

server="$(current_server "$APPROOT")" || exit 2
BASE_URL="${BENCH_BASE_URL:-$(cd "$APPROOT" && ddev server --url | tr -d '[:space:]')}"
HOST_NAME="${BASE_URL#*://}"
HOST_NAME="${HOST_NAME%%/*}"
HOST_NAME="${HOST_NAME%%:*}"
# The describe loop of search-under-load talks to the web container by its name on ddev_default (HTTP, Host header), not to
# a router address pinned at the start: `bench/run.sh` restarts DDEV and the router and the container can get new addresses.
WEB_HOST="ddev-${DDEV_SITENAME:-ai-lab}-web"

run_concurrency() {
  # The app's value, read before .env changes: the ddev artisan line ends in the value ("null" when unset).
  num_parallel="$(cd "$APPROOT" && ddev artisan config:show ai.providers.ollama.num_parallel | awk 'NF {print $NF}' | tail -n1)"
  if [[ "$num_parallel" =~ ^[0-9]+$ ]]; then
    num_parallel_json="$num_parallel"
  else
    num_parallel_json='"default"'
  fi

  env_switch_begin
  lookup_router
  K6_DIGEST="$(image_digest "$K6_IMAGE")"
  [[ -n "$K6_DIGEST" ]] || { echo "Cannot read the digest of $K6_IMAGE; refusing to run." >&2; exit 1; }

  apply_env() {
    (cd "$APPROOT" && ddev artisan optimize >/dev/null)
    if [[ "$server" == "frankenphp-octane" || "$server" == "frankenphp-worker" ]]; then
      (cd "$APPROOT" && ddev restart >/dev/null)
      worker_restarted=1
      local deadline=$((SECONDS + ${BENCH_READY_TIMEOUT:-120}))
      until [[ "$(curl -ksS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/healthz" 2>/dev/null || true)" == "200" ]]; do
        [[ "$SECONDS" -lt "$deadline" ]] || { echo "$server did not answer /healthz within the ready timeout after the restart." >&2; exit 1; }
        sleep 1
      done
    fi
  }

  # Runs one level in k6 and prints its figures as a JSON object on stdout.
  run_level() {
    local level="$1" streams="$2" offset="$3"
    rm -f "$WORK/k6.json"
    docker_run "$K6_NAME" -v "$SCRIPT_DIR:/bench:ro" -v "$WORK:/out" -e "BASE_URL=$BASE_URL" -e "VUS=$level" \
      -e "ITERATIONS=$streams" -e "ID_OFFSET=$offset" -e "REQUEST_TIMEOUT=$REQUEST_TIMEOUT" \
      "$K6_IMAGE" run --quiet --summary-export /out/k6.json /bench/k6-sse.js >/dev/null || true
    [[ -s "$WORK/k6.json" ]] || { echo "k6 wrote no summary." >&2; return 1; }
    jq --argjson level "$level" --argjson streams "$streams" '
      (.metrics.stream_failed // {}) as $f
      | (.metrics.stream_text_events_total.count // 0) as $events
      | ((.metrics.stream_ended_at_ms.max // 0) - (.metrics.stream_started_at_ms.min // 0)) as $span
      | {
          level: $level,
          streams: $streams,
          ttft_p50_ms: (.metrics.stream_ttfb_ms.med // null),
          ttft_p95_ms: (.metrics.stream_ttfb_ms["p(95)"] // null),
          done_p50_ms: (.metrics.stream_done_ms.med // null),
          done_p95_ms: (.metrics.stream_done_ms["p(95)"] // null),
          events_per_s_per_stream_median: (.metrics.stream_events_per_s.med // null),
          generation_p50_ms: (.metrics.stream_generation_ms.med // null),
          events_per_s_aggregate: (if $span > 0 then (($events / ($span / 1000)) * 100 | round / 100) else null end),
          text_events_total: $events,
          incomplete_streams: ($f.passes // 0),
          failed_share: (if (($f.passes // 0) + ($f.fails // 0)) > 0 then ($f.passes // 0) / (($f.passes // 0) + ($f.fails // 0)) else 0 end),
          rate_limited: (.metrics.stream_rate_limited.count // 0)
        }' "$WORK/k6.json"
  }

  offset=0
  model_files=()
  index=0
  for model in "${model_list[@]}"; do
    env_set AI_DESCRIBE_PROVIDER ollama
    env_set AI_DESCRIBE_MODEL "\"$model\""
    env_set AI_DESCRIBE_PER_MINUTE 1000
    apply_env
    # A DDEV restart in apply_env can give the router a new address.
    lookup_router
    # The cold load is not measured: one describe, result ignored.
    curl -ksS --max-time 300 -H 'Accept: text/event-stream' -o /dev/null -w '%{http_code}\n' "$BASE_URL/listings/$WARMUP_ID/describe" >/dev/null || true
    level_files=()
    for level in "${LEVELS[@]}"; do
      streams="$(streams_for "$level")"
      echo "model=$model level=$level streams=$streams" >&2
      run_level "$level" "$streams" "$offset" > "$WORK/level-$index-$level.json"
      level_files+=("$WORK/level-$index-$level.json")
      offset=$(((offset + ID_STEP) % 50000))
    done
    jq -s --arg model "$model" '{model: $model, levels: .}' "${level_files[@]}" > "$WORK/model-$index.json"
    model_files+=("$WORK/model-$index.json")
    index=$((index + 1))
  done

  jq -s --arg date "$DAY" --arg server "$server" --argjson quick "$([[ "$quick" == "1" ]] && echo true || echo false)" \
    --argjson parallel "$num_parallel_json" --arg digest "$K6_DIGEST" --arg image "$K6_IMAGE" --arg timeout "$REQUEST_TIMEOUT" '{
      mode: "concurrency",
      date: $date,
      server: $server,
      quick: $quick,
      ollama_num_parallel: $parallel,
      ollama_num_parallel_note: "the app config value; the Ollama host server own OLLAMA_NUM_PARALLEL is not readable from the app",
      k6_image: $image,
      k6_image_digest: $digest,
      request_timeout: $timeout,
      ttft_note: "time to the first byte of the stream; it opens with the first Ollama chunk, so it includes prompt evaluation",
      events_note: "SSE text chunks, not tokens; the per-stream rate counts from the first chunk to [DONE], the wait before it is the TTFT",
      models: .
    }' "${model_files[@]}" > "$WORK/result.json"

  mkdir -p "$OUT_DIR"
  cat "$WORK/result.json" > "$OUT_FILE"

  echo "server=$server ollama_num_parallel=$(jq -r '.ollama_num_parallel' "$OUT_FILE")"
  {
    echo "model level streams ttft_p50_ms ttft_p95_ms done_p50_ms done_p95_ms chunks/s/stream chunks/s_total failed_share"
    jq -r '.models[] | .model as $m | .levels[] | [$m, .level, .streams, .ttft_p50_ms, .ttft_p95_ms, .done_p50_ms, .done_p95_ms, .events_per_s_per_stream_median, .events_per_s_aggregate, .failed_share] | map(. // "-") | join(" ")' "$OUT_FILE"
  } | awk '{ for (i = 1; i <= NF; i++) { w[NR, i] = $i; if (length($i) > m[i]) m[i] = length($i) } n = NF; r = NR }
    END { for (j = 1; j <= r; j++) { line = ""; for (i = 1; i <= n; i++) line = line sprintf("%-" m[i] "s  ", w[j, i]); print line } }'
  echo "wrote $OUT_FILE"
}

SEARCH_MODEL="qwen3.5:9b"
LOAD_STREAMS=4
RUN_SCRIPT="${BENCH_RUN_SCRIPT:-$SCRIPT_DIR/run.sh}"

# Median of each figure over the repeat files given as arguments, as one JSON object.
median_of_runs() {
  jq -s '
    def med: sort | length as $n | if $n == 0 then null elif $n % 2 == 1 then .[($n - 1) / 2] else (.[$n / 2 - 1] + .[$n / 2]) / 2 end;
    def round2: if . == null then null else (. * 100 | round) / 100 end;
    {
      rps: (map(.rps) | med | round2),
      p50_ms: (map(.p50_ms) | med | round2),
      p95_ms: (map(.p95_ms) | med | round2),
      p99_ms: (map(.p99_ms) | med | round2),
      error_percent: (map(.error_percent) | med | round2),
      repeats: length,
      warmup_s: .[0].warmup_s,
      measure_s: .[0].measure_s
    }' "$@"
}

wait_until_ready() {
  local deadline=$((SECONDS + ${BENCH_READY_TIMEOUT:-120}))
  until [[ "$(curl -ksS --max-time 10 -o /dev/null -w '%{http_code}' "$BASE_URL/healthz" 2>/dev/null || true)" == "200" ]]; do
    [[ "$SECONDS" -lt "$deadline" ]] || { echo "The server did not answer /healthz within the ready timeout." >&2; exit 1; }
    sleep 1
  done
}

switch_server() {
  local target="$1"
  if [[ "$target" != "$active_server" ]]; then
    server_changed=1
    (cd "$APPROOT" && ddev server "$target" >/dev/null)
    active_server="$target"
    [[ -n "${BENCH_BASE_URL:-}" ]] || BASE_URL="$(cd "$APPROOT" && ddev server --url | tr -d '[:space:]')"
    wait_until_ready
  fi
  if [[ "$target" == "frankenphp-octane" || "$target" == "frankenphp-worker" ]]; then
    worker_restarted=1
  fi
}

# Starts the describe loop: 4 virtual users, real mode, far more streams than a run can finish. The loop reaches the web
# container by name (k6 looks the name up again every second), so a restart of DDEV does not strand it.
start_describe_loop() {
  load_dir="$WORK/load-$1"
  mkdir -p "$load_dir"
  docker run -d --rm --name "$K6_NAME" --network ddev_default --cpus=2 -v "$SCRIPT_DIR:/bench:ro" -v "$load_dir:/out" \
    -e "BASE_URL=http://$WEB_HOST" -e "HOST_HEADER=$HOST_NAME" -e "VUS=$LOAD_STREAMS" \
    -e "ITERATIONS=1000000" -e "ID_OFFSET=$2" -e "REQUEST_TIMEOUT=$REQUEST_TIMEOUT" \
    "$K6_IMAGE" run --quiet --summary-export /out/k6.json /bench/k6-sse.js >/dev/null
  loop_started_at=$SECONDS
}

# Refuses to go on when the loop did not really load the measured window: no describe completed, or half or more of the
# streams were incomplete. $1 is the server, $2 the k6 summary of the loop.
verify_describe_loop() {
  local completed incomplete
  completed="$(jq -r '(.metrics.stream_failed.fails // 0)' "$2")"
  incomplete="$(jq -r '(.metrics.stream_failed.passes // 0)' "$2")"
  if [[ "$completed" -le 0 ]] || [[ $((incomplete * 2)) -ge $((completed + incomplete)) ]]; then
    echo "The describe loop did not load $1: $completed describes completed, $incomplete incomplete. Refusing to write search-under-load.json." >&2
    exit 1
  fi
}

# Interrupts k6 so it writes its summary, waits for the container to go, and sets loop_elapsed.
stop_describe_loop() {
  loop_elapsed=$((SECONDS - loop_started_at))
  docker kill -s INT "$K6_NAME" >/dev/null 2>&1 || true
  local deadline=$((SECONDS + 90))
  while [[ -n "$(docker ps -q -f "name=^${K6_NAME}\$" 2>/dev/null || true)" && "$SECONDS" -lt "$deadline" ]]; do
    sleep 1
  done
  docker rm -f "$K6_NAME" >/dev/null 2>&1 || true
}

# Runs `run.sh search` with the label and prints the repeat files it kept, one per line.
run_search() {
  local label="$1" output
  output="$(cd "$APPROOT" && bash "$RUN_SCRIPT" search ${quick_flag[@]+"${quick_flag[@]}"} --label "$label")"
  grep '^/' <<< "$output" || { echo "run.sh search wrote no result files." >&2; return 1; }
}

# Fills the global `files` from run_search; a failed run stops the script here (the macOS bash has no mapfile).
collect_files() {
  local listing line
  listing="$(run_search "$1")"
  files=()
  while IFS= read -r line; do
    [[ -z "$line" ]] || files+=("$line")
  done <<< "$listing"
}

run_search_under_load() {
  if [[ -e "$APPROOT/.env.bench-backup" ]]; then
    echo "Stale $APPROOT/.env.bench-backup exists: a previous run did not restore .env. Restore it by hand first." >&2
    exit 2
  fi
  # shellcheck disable=SC2034 # read by the env-switch.sh functions
  ENV_BACKUP="$APPROOT/.env.llm-load-backup"
  active_server="$server"
  if [[ "$quick" == "0" ]]; then
    read_default_server "$APPROOT"
    if [[ "$DEFAULT_SERVER_NAME" != "$server" ]]; then
      echo "The full run restores the default server ($DEFAULT_SERVER_NAME) at the end, but $server is active. Run ddev server --default first, or use --quick." >&2
      exit 2
    fi
  fi
  quick_flag=()
  if [[ "$quick" == "1" ]]; then
    quick_flag=(--quick)
    servers=("$server")
  else
    servers=("${SUPPORTED_SERVERS[@]}")
  fi

  env_switch_begin
  env_set AI_DESCRIBE_PROVIDER ollama
  env_set AI_DESCRIBE_MODEL "\"$SEARCH_MODEL\""
  env_set AI_DESCRIBE_PER_MINUTE 1000
  K6_DIGEST="$(image_digest "$K6_IMAGE")"
  [[ -n "$K6_DIGEST" ]] || { echo "Cannot read the digest of $K6_IMAGE; refusing to run." >&2; exit 1; }

  local mode_server index=0 offset=0
  server_files=()
  for mode_server in "${servers[@]}"; do
    switch_server "$mode_server"
    echo "server=$mode_server without describe load" >&2
    collect_files llm-noload
    [[ ${#files[@]} -gt 0 ]] || exit 1
    median_of_runs "${files[@]}" > "$WORK/noload-$index.json"

    echo "server=$mode_server with $LOAD_STREAMS describe streams" >&2
    start_describe_loop "$index" "$offset"
    collect_files llm-load
    stop_describe_loop
    [[ ${#files[@]} -gt 0 ]] || exit 1
    [[ -s "$load_dir/k6.json" ]] || { echo "k6 wrote no summary." >&2; exit 1; }
    verify_describe_loop "$mode_server" "$load_dir/k6.json"
    median_of_runs "${files[@]}" > "$WORK/load-$index.json"

    jq -n --arg server "$mode_server" --argjson elapsed "$loop_elapsed" --argjson streams "$LOAD_STREAMS" \
      --slurpfile without "$WORK/noload-$index.json" --slurpfile with "$WORK/load-$index.json" --slurpfile k6 "$load_dir/k6.json" '
      def round2: if . == null then null else (. * 100 | round) / 100 end;
      ($k6[0].metrics.stream_failed // {}) as $f
      | ($f.fails // 0) as $completed
      | {
          server: $server,
          without_load: $without[0],
          with_load: $with[0],
          p95_delta_ms: (($with[0].p95_ms - $without[0].p95_ms) | round2),
          p95_delta_percent: (if $without[0].p95_ms > 0 then ((($with[0].p95_ms - $without[0].p95_ms) / $without[0].p95_ms * 100) | round2) else null end),
          describes: {
            streams_held: $streams,
            completed: $completed,
            incomplete: ($f.passes // 0),
            rate_limited: ($k6[0].metrics.stream_rate_limited.count // 0),
            elapsed_s: $elapsed,
            completed_per_minute: (if $elapsed > 0 then ($completed / ($elapsed / 60) | round2) else null end)
          }
        }' > "$WORK/server-$index.json"
    server_files+=("$WORK/server-$index.json")
    index=$((index + 1))
    offset=$(((offset + ID_STEP) % 50000))
  done

  jq -s --arg date "$DAY" --arg server "$server" --arg model "$SEARCH_MODEL" --arg digest "$K6_DIGEST" --arg image "$K6_IMAGE" \
    --argjson quick "$([[ "$quick" == "1" ]] && echo true || echo false)" '{
      mode: "search-under-load",
      date: $date,
      starting_server: $server,
      quick: $quick,
      describe_model: $model,
      k6_image: $image,
      k6_image_digest: $digest,
      method: "run.sh search without and with a k6 loop of real describe streams started before the warm-up and stopped after the last repeat; median of the repeats",
      describes_note: "completed describes cover the whole loop period, warm-up and repeats; a stream cut by a server restart or the final stop counts as incomplete",
      servers: .
    }' "${server_files[@]}" > "$WORK/result.json"

  mkdir -p "$OUT_DIR"
  cat "$WORK/result.json" > "$OUT_FILE"

  echo "starting_server=$server describe_model=$SEARCH_MODEL"
  {
    echo "server rps_noload rps_load p95_noload_ms p95_load_ms p95_delta_ms errors_load% describes_completed per_min"
    jq -r '.servers[] | [.server, .without_load.rps, .with_load.rps, .without_load.p95_ms, .with_load.p95_ms, .p95_delta_ms, .with_load.error_percent, .describes.completed, .describes.completed_per_minute] | map(. // "-") | join(" ")' "$OUT_FILE"
  } | awk '{ for (i = 1; i <= NF; i++) { w[NR, i] = $i; if (length($i) > m[i]) m[i] = length($i) } n = NF; r = NR }
    END { for (j = 1; j <= r; j++) { line = ""; for (i = 1; i <= n; i++) line = line sprintf("%-" m[i] "s  ", w[j, i]); print line } }'
  echo "wrote $OUT_FILE"
}

if [[ "$mode" == "search-under-load" ]]; then
  run_search_under_load
else
  run_concurrency
fi
