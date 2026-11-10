#!/usr/bin/env bash
## Usage: bench/run.sh <ping|search> [--quick] [--label <text>]
## Runs one benchmark scenario against the active server: 50 concurrent connections, closed model.
## Default method per run: warm-up 30 s, measure 60 s, 3 repeats; a spread above 5 % reruns the set once.
## --quick (development only): warm-up 5 s, measure 15 s, 1 repeat, no restart.
## The harness sets .env to APP_ENV=production, APP_DEBUG=false, DEBUG_ROUTES=true, runs `artisan optimize`,
## checks the run conditions (exit 2 naming the failed check, before any load) and restores .env on exit.
## One raw result file per repeat goes to bench/results/raw/<date>/<server>/ (gitignored).
## ping uses oha, search uses k6, both as pinned one-off containers on ddev_default, capped at 2 CPUs and
## reaching the DDEV router through --add-host.
## Env: BENCH_WARMUP, BENCH_MEASURE (seconds), BENCH_REPEATS, BENCH_SETTLE (seconds after restart),
##      BENCH_APPROOT (project root), BENCH_BASE_URL (default: `ddev server --url`),
##      BENCH_READY_TIMEOUT (seconds to wait for /debug/runtime after a worker-server reload, default 120),
##      BENCH_OHA_IMAGE, BENCH_K6_IMAGE.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
SITE="${DDEV_SITENAME:-ai-lab}"
OHA_IMAGE="${BENCH_OHA_IMAGE:-ai-lab-oha:1.16.0}"   # built locally from bench/oha/Dockerfile
K6_IMAGE="${BENCH_K6_IMAGE:-grafana/k6:1.0.0}"
SPREAD_LIMIT=5
CONNECTIONS=50

usage() {
  echo "Usage: $0 <ping|search> [--quick] [--label <text>]" >&2
  exit 2
}

scenario=""
quick=0
label="fair"
while [[ $# -gt 0 ]]; do
  case "$1" in
    ping | search) scenario="$1"; shift ;;
    --quick) quick=1; shift ;;
    --label) label="${2:?--label needs a value}"; shift 2 ;;
    *) usage ;;
  esac
done
[[ -n "$scenario" ]] || usage
# The label becomes part of file names: keep it to lowercase letters, digits and dashes.
[[ "$label" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid --label '$label': use a-z, 0-9 and -." >&2; exit 2; }

if [[ "$quick" == "1" ]]; then
  WARMUP="${BENCH_WARMUP:-5}"
  MEASURE="${BENCH_MEASURE:-15}"
  REPEATS="${BENCH_REPEATS:-1}"
  SETTLE="${BENCH_SETTLE:-0}"
else
  WARMUP="${BENCH_WARMUP:-30}"
  MEASURE="${BENCH_MEASURE:-60}"
  REPEATS="${BENCH_REPEATS:-3}"
  SETTLE="${BENCH_SETTLE:-10}"
fi

cd "$APPROOT"
ENV_FILE="$APPROOT/.env"
BACKUP="$APPROOT/.env.bench-backup"
WORK="$(mktemp -d)"
SAMPLER_PID=""
env_switched=0

worker_restarted=0

restore_env() {
  [[ -n "$SAMPLER_PID" ]] && kill "$SAMPLER_PID" 2>/dev/null || true
  if [[ "$env_switched" == "1" && -f "$BACKUP" ]]; then
    cp -p "$BACKUP" "$ENV_FILE"
    rm -f "$BACKUP"
    ddev artisan optimize:clear >/dev/null 2>&1 || true
    # Worker servers keep the benchmark config (production, DEBUG_ROUTES=true) in memory until they
    # boot again, so restoring .env alone would leave /debug/* reachable.
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

cp -p "$ENV_FILE" "$BACKUP"
env_switched=1
set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value DEBUG_ROUTES true
ddev artisan optimize >/dev/null

# Worker servers boot the app once: reload them so the switched .env and the caches are what they serve.
READY_TIMEOUT="${BENCH_READY_TIMEOUT:-120}"
if [[ "$server" == "frankenphp-octane" || "$server" == "frankenphp-worker" ]]; then
  ddev restart >/dev/null
  worker_restarted=1
  # SECONDS counts wall-clock time, so a slow curl attempt cannot stretch the limit.
  ready_deadline=$((SECONDS + READY_TIMEOUT))
  until [[ "$(curl -ksS --max-time 10 -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$BASE_URL/debug/runtime" 2>/dev/null || true)" == "200" ]]; do
    [[ "$SECONDS" -lt "$ready_deadline" ]] || refuse "$server did not answer /debug/runtime with 200 within ${READY_TIMEOUT}s after the restart"
    sleep 1
  done
fi

check_conditions() {
  local value body status
  [[ "$(ddev artisan config:show app.env | awk 'NF {print $NF}')" == "production" ]] || refuse "APP_ENV is not production"
  [[ "$(ddev artisan config:show app.debug | awk 'NF {print $NF}')" == "false" ]] || refuse "APP_DEBUG is not false"
  ddev exec ls bootstrap/cache/config.php >/dev/null 2>&1 || refuse "config cache is not built"
  ddev exec sh -c 'ls bootstrap/cache/routes*.php' >/dev/null 2>&1 || refuse "route cache is not built"
  ddev exec sh -c 'ls storage/framework/views/*.php' >/dev/null 2>&1 || refuse "view cache is not built"
  body="$WORK/runtime.json"
  status="$(curl -ksS --max-time 30 -H 'Accept: application/json' -o "$body" -w '%{http_code}' "$BASE_URL/debug/runtime")" || status=000
  [[ "$status" == "200" ]] || refuse "cannot read /debug/runtime (status $status)"
  value="$(jq -r '.data.opcache_enabled' "$body")"
  [[ "$value" == "true" ]] || refuse "OPcache is not on for the serving SAPI"
  value="$(jq -r '.data.xdebug_loaded' "$body")"
  [[ "$value" == "false" ]] || refuse "Xdebug is not off for the serving SAPI"
  if [[ "$scenario" == "search" ]]; then
    status="$(curl -ksS --max-time 30 -H 'Accept: application/json' -o "$body" -w '%{http_code}' "$BASE_URL/listings?make=BMW")" || status=000
    [[ "$status" == "200" ]] || refuse "cannot read /listings?make=BMW (status $status)"
    value="$(jq -r '.meta.last_page // 0' "$body")"
    [[ "$value" =~ ^[0-9]+$ && "$value" -ge 100 ]] || refuse "make=BMW has $value pages, at least 100 are needed"
  fi
  # .ai/session/ is the SDD tooling's own log, rewritten by the tooling itself; it is not app code.
  value="$(git status --porcelain -- . ':!bench/results' ':!.ai/session' 2>/dev/null || true)"
  [[ -z "$value" ]] || refuse "git working tree has changes outside bench/results/ and .ai/session/"
}
check_conditions

serving="web"
[[ "$server" == "openlitespeed" ]] && serving="openlitespeed"
containers=("ddev-$SITE-$serving" "ddev-$SITE-db")
[[ "$serving" != "web" ]] && containers+=("ddev-$SITE-web")

router_ip=""
# The router can get a new IP when `ddev restart` recreates it, so this runs after the restart.
lookup_router() {
  router_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ddev-router)"
  [[ -n "$router_ip" ]] || { echo "Cannot find the ddev-router IP." >&2; exit 1; }
}

image_for() { [[ "$scenario" == "ping" ]] && echo "$OHA_IMAGE" || echo "$K6_IMAGE"; }
IMAGE="$(image_for)"
if [[ "$IMAGE" == ai-lab-oha:* ]]; then
  # oha has no published image: build it from the pinned, checksum-verified release.
  docker image inspect "$IMAGE" >/dev/null 2>&1 || docker build -q -t "$IMAGE" "$SCRIPT_DIR/oha" >/dev/null
  IMAGE_DIGEST="$(docker image inspect --format '{{.Id}}' "$IMAGE" 2>/dev/null || true)"
else
  docker pull "$IMAGE" >/dev/null 2>&1 || true
  IMAGE_DIGEST="$(docker image inspect --format '{{index .RepoDigests 0}}' "$IMAGE" 2>/dev/null || true)"
fi
[[ -n "$IMAGE_DIGEST" ]] || { echo "Cannot read the digest or ID of $IMAGE; refusing to run." >&2; exit 1; }
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
CONCURRENCY_SETTING="$(bash "$SCRIPT_DIR/concurrency.sh" --show 2>/dev/null | tr '\n' ' ' | sed 's/ *$//' || true)"
[[ -n "$CONCURRENCY_SETTING" ]] || CONCURRENCY_SETTING="default"
DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT_DIR="$APPROOT/bench/results/raw/$DATE/$server"
mkdir -p "$OUT_DIR"

docker_load() {
  docker run --rm --network ddev_default --cpus=2 --add-host "$HOST_NAME:$router_ip" "$@"
}

# Runs the generator for $1 seconds and prints its raw JSON on stdout (oha) or writes $WORK/k6.json (k6).
generate() {
  local seconds="$1"
  if [[ "$scenario" == "ping" ]]; then
    # -w waits for requests still in flight at the deadline instead of counting them as errors.
    docker_load "$OHA_IMAGE" -c "$CONNECTIONS" -z "${seconds}s" -w -t 30s --no-tui --insecure --output-format json "$BASE_URL/api/ping"
  else
    rm -f "$WORK/k6.json"
    if ! docker_load -v "$SCRIPT_DIR:/bench:ro" -v "$WORK:/out" -e "BASE_URL=$BASE_URL" -e "DURATION=${seconds}s" \
      "$K6_IMAGE" run --quiet --summary-export /out/k6.json /bench/k6-search.js >/dev/null || [[ ! -s "$WORK/k6.json" ]]; then
      echo "k6 failed or wrote no summary." >&2
      return 1
    fi
    cat "$WORK/k6.json"
  fi
}

sample_once() {
  docker stats --no-stream --format '{{.Name}}	{{.MemUsage}}	{{.CPUPerc}}' "${containers[@]}" 2>/dev/null || true
}

# Streams `docker stats` (one line per container about every second) for the measure window only, so
# peak memory and average CPU are not diluted by idle samples. The terminal control codes docker
# prints between refreshes are stripped when the samples are summarized.
start_sampler() {
  : > "$WORK/samples.tsv"
  docker stats --format '{{.Name}}	{{.MemUsage}}	{{.CPUPerc}}' "${containers[@]}" >> "$WORK/samples.tsv" 2>/dev/null &
  SAMPLER_PID=$!
}

stop_sampler() {
  kill "$SAMPLER_PID" 2>/dev/null || true
  wait "$SAMPLER_PID" 2>/dev/null || true
  SAMPLER_PID=""
  # A very short window can end before the stream wrote its first line; take one reading so the
  # result never has empty memory and CPU maps.
  if ! grep -q '%' "$WORK/samples.tsv" 2>/dev/null; then
    sample_once >> "$WORK/samples.tsv"
  fi
}

# Prints {"peak": {name: MiB}, "cpu": {name: avg %}} from a samples file.
summarize_samples() {
  awk -F'\t' -v prefix="ddev-$SITE-" '
    function mib(s,   n, u) {
      n = s + 0; u = s; sub(/^[0-9.]+/, "", u)
      if (u ~ /^GiB/) return n * 1024
      if (u ~ /^MiB/) return n
      if (u ~ /^KiB/) return n / 1024
      if (u ~ /^GB/) return n * 953.674
      if (u ~ /^MB/) return n * 0.953674
      if (u ~ /^kB/) return n * 0.000953674
      return n / 1048576
    }
    { gsub(/\033\[[0-9;]*[A-Za-z]/, "") }
    NF >= 3 && $3 ~ /%/ {
      name = $1; sub(prefix, "", name)
      split($2, m, " "); v = mib(m[1]); c = $3; sub(/%/, "", c)
      if (!(name in peak) || v > peak[name]) peak[name] = v
      cpu[name] += c; n[name]++
    }
    END { for (k in peak) printf "%s\t%.1f\t%.1f\n", k, peak[k], cpu[k] / n[k] }
  ' "$1" | jq -Rn '[inputs | split("\t")] | {peak: (map({(.[0]): (.[1] | tonumber)}) | add // {}), cpu: (map({(.[0]): (.[2] | tonumber)}) | add // {})}'
}

# Reads the generator output on stdin and prints {rps, p50_ms, p95_ms, p99_ms, error_percent}.
parse_generator() {
  if [[ "$scenario" == "ping" ]]; then
    jq '{
      rps: .summary.requestsPerSec,
      p50_ms: (.latencyPercentiles.p50 * 1000),
      p95_ms: (.latencyPercentiles.p95 * 1000),
      p99_ms: (.latencyPercentiles.p99 * 1000),
      error_percent: (
        ((.statusCodeDistribution // {}) | to_entries | map(select((.key | tonumber) >= 400) | .value) | add // 0) as $bad
        | ((.errorDistribution // {}) | [.[]] | add // 0) as $transport
        | ((.statusCodeDistribution // {}) | [.[]] | add // 0) as $ok
        | (if ($ok + $transport) == 0 then 0 else ($bad + $transport) / ($ok + $transport) * 100 end))
    }'
  else
    jq '{
      rps: .metrics.http_reqs.rate,
      p50_ms: .metrics.http_req_duration.med,
      p95_ms: .metrics.http_req_duration["p(95)"],
      p99_ms: .metrics.http_req_duration["p(99)"],
      error_percent: (.metrics.http_req_failed.value * 100)
    }'
  fi
}

# Fresh containers before measuring; skipped when a worker server was just restarted for the .env switch.
if [[ "$quick" == "0" && "$SETTLE" != "0" && "$worker_restarted" == "0" ]]; then
  ddev restart >/dev/null
fi
sleep "$SETTLE"
lookup_router
check_conditions
sample_once > "$WORK/idle.tsv"
idle_json="$(summarize_samples "$WORK/idle.tsv")"

run_set() {
  local set_number="$1" repeat start generated file
  set_files=()
  for repeat in $(seq 1 "$REPEATS"); do
    if [[ "$WARMUP" != "0" ]]; then
      generate "$WARMUP" >/dev/null
    fi
    start_sampler
    generated="$(generate "$MEASURE")"
    stop_sampler
    file="$OUT_DIR/$scenario-$label-$(date -u +%H%M%S)-s${set_number}r${repeat}.json"
    jq -n \
      --argjson load "$(printf '%s' "$generated" | parse_generator)" \
      --argjson samples "$(summarize_samples "$WORK/samples.tsv")" \
      --argjson idle "$idle_json" \
      --arg server "$server" --arg scenario "$scenario" --arg label "$label" --arg commit "$COMMIT" \
      --arg concurrency "$CONCURRENCY_SETTING" --arg image "$IMAGE" --arg digest "$IMAGE_DIGEST" \
      --arg base "$BASE_URL" --arg date "$DATE" \
      --argjson set "$set_number" --argjson repeat "$repeat" \
      --argjson warmup "$WARMUP" --argjson measure "$MEASURE" --argjson connections "$CONNECTIONS" \
      '$load + {
        server: $server, scenario: $scenario, label: $label, commit: $commit, concurrency: $concurrency,
        image: $image, image_digest: $digest, base_url: $base, date: $date, set: $set, repeat: $repeat,
        warmup_s: $warmup, measure_s: $measure, connections: $connections,
        idle_memory_mib: $idle.peak, peak_memory_mib: $samples.peak, avg_cpu_percent: $samples.cpu
      }' > "$file"
    set_files+=("$file")
  done
}

spread_of() {
  jq -s 'map(.rps) | sort as $r | ($r[length / 2 | floor]) as $median
    | if $median == 0 then 0 else (($r[-1] - $r[0]) / $median * 100) end' "$@"
}

run_set 1
spread="$(spread_of "${set_files[@]}")"
if [[ "$REPEATS" -gt 1 ]] && awk -v s="$spread" -v l="$SPREAD_LIMIT" 'BEGIN { exit !(s > l) }'; then
  echo "Spread ${spread}% is above ${SPREAD_LIMIT}%. Discarding set 1 and rerunning once." >&2
  for file in "${set_files[@]}"; do
    jq '. + {discarded: true}' "$file" > "$WORK/tmp.json" && cat "$WORK/tmp.json" > "$file"
  done
  run_set 2
  spread="$(spread_of "${set_files[@]}")"
fi
echo "Wrote ${#set_files[@]} result file(s) to $OUT_DIR (spread ${spread}%)"
printf '%s\n' "${set_files[@]}"
