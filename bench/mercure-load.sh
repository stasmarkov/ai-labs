#!/usr/bin/env bash
## Usage: bench/mercure-load.sh --subscribers <n> [--quick] [--label <text>]
## Measures what <n> open Mercure subscribers do to a light request, and how fast a published price change reaches them.
## The subscribers are held by one pinned node container (bench/lib/mercure-subscribers.mjs, built-in fetch, no dependencies)
## that opens <n> EventSource-style connections to GET /.well-known/mercure?topic=<public price topic of listing 1> through the DDEV
## router, plus three check subscribers on the private topic: one with listing 1's token, one with listing 2's token, one without.
## At the same time the shared probe (bench/lib/health-probe.sh) asks GET /healthz at 5 requests per second with a 10 s timeout, and
## bench/lib/busy-threads.sh samples FrankenPHP's busy and total PHP threads from Caddy's metrics once a second inside the web container.
## While they run, the script changes listing 1's price PUBLISHES times with `listings:reprice` (public updates), then once with
## --private. That last command also sends a public update, so every subscriber should receive PUBLISHES + 1 messages.
## Latency is the receive time in the node container minus published_at_ms, which PHP takes right before the POST; the containers
## share the host kernel's clock. Delivery is received / (subscribers x (PUBLISHES + 1)). The private checks pass when only the
## subscriber with listing 1's token received the private update.
## Listing 1's price is read first and put back on exit, also on failure. The harness sets .env to APP_ENV=production,
## APP_DEBUG=false (the Mercure keys stay as they are), runs `artisan optimize`, checks the run conditions (exit 2 naming the failed
## check, before any load) and restores .env on exit. A server without a Mercure hub answering the hub path is refused (exit 2).
## The subscribers and the probe are two one-off containers on ddev_default, each capped at 2 CPUs and reaching the router through --add-host.
## Default method: 90 s window, 20 publishes. --quick (development only): 20 s window, 5 publishes, no restart, no clean-git-tree check.
## Output: bench/results/mercure-load/<UTC date>/n<subscribers>-<label>.json (--quick: the gitignored bench/results/raw/) and a summary
## on stdout. Exit 1 when the private checks fail.
## Env: BENCH_MEASURE (seconds), BENCH_PUBLISHES, BENCH_PUBLISH_GAP (seconds between publishes, default 1), BENCH_DRAIN (seconds to wait after
##      the last publish, default 3), BENCH_SETTLE (seconds after restart), BENCH_APPROOT, BENCH_BASE_URL, BENCH_OUT_DIR, BENCH_DATE,
##      BENCH_SUBS_SETTLE (seconds between the subscribers being ready and the first publish, default 2),
##      BENCH_READY_TIMEOUT (seconds to wait for every subscriber to be answered, default 180), BENCH_OHA_IMAGE, BENCH_NODE_IMAGE.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
OHA_IMAGE="${BENCH_OHA_IMAGE:-autopulse-oha:1.16.0}"   # built locally from bench/oha/Dockerfile
NODE_IMAGE="${BENCH_NODE_IMAGE:-node:22.12.0-alpine}"
source "$SCRIPT_DIR/lib/health-probe.sh"
source "$SCRIPT_DIR/lib/env-switch.sh"
LISTING_ID=1
OTHER_LISTING_ID=2
PRICE_STEP=100

usage() {
  echo "Usage: $0 --subscribers <n> [--quick] [--label <text>]" >&2
  exit 2
}

subscribers=""
quick=0
label="cell"
while [[ $# -gt 0 ]]; do
  case "$1" in
    --subscribers) subscribers="${2:-}"; shift 2 || usage ;;
    --quick) quick=1; shift ;;
    --label) label="${2:?--label needs a value}"; shift 2 ;;
    *) usage ;;
  esac
done
[[ "$subscribers" =~ ^[1-9][0-9]*$ ]] || usage
[[ "$label" =~ ^[a-z0-9-]+$ ]] || { echo "Invalid --label '$label': use a-z, 0-9 and -." >&2; exit 2; }

if [[ "$quick" == "1" ]]; then
  MEASURE="${BENCH_MEASURE:-20}"
  PUBLISHES="${BENCH_PUBLISHES:-5}"
  SETTLE="${BENCH_SETTLE:-0}"
else
  MEASURE="${BENCH_MEASURE:-90}"
  PUBLISHES="${BENCH_PUBLISHES:-20}"
  SETTLE="${BENCH_SETTLE:-10}"
fi
PUBLISH_GAP="${BENCH_PUBLISH_GAP:-1}"
DRAIN="${BENCH_DRAIN:-3}"
READY_TIMEOUT="${BENCH_READY_TIMEOUT:-180}"
[[ "$MEASURE" =~ ^[1-9][0-9]*$ && "$PUBLISHES" =~ ^[1-9][0-9]*$ ]] || { echo "BENCH_MEASURE and BENCH_PUBLISHES must be positive integers." >&2; exit 2; }

cd "$APPROOT"
WORK="$(mktemp -d)"
RUN_ID="$$"
SUBS_NAME="mercure-load-subs-$RUN_ID"
PROBE_NAME="mercure-load-probe-$RUN_ID"
background_pids=()
price_touched=0
original_price=""

cleanup() {
  local pid
  docker rm -f "$SUBS_NAME" "$PROBE_NAME" >/dev/null 2>&1 || true
  for pid in ${background_pids[@]+"${background_pids[@]}"}; do
    kill "$pid" >/dev/null 2>&1 || true
  done
  if [[ "$price_touched" == "1" ]]; then
    ddev artisan listings:reprice "$LISTING_ID" --restore="$original_price" >/dev/null 2>&1 || true
  fi
  if [[ "$ENV_SWITCHED" == "1" ]]; then
    env_switch_restore
    ddev artisan optimize:clear >/dev/null 2>&1 || true
  fi
  rm -rf "$WORK"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

refuse() {
  echo "Run condition failed: $1" >&2
  exit 2
}

[[ -f "$ENV_FILE" ]] || refuse "no .env at $ENV_FILE"
grep -q '^MERCURE_PUBLISHER_JWT_KEY=.' "$ENV_FILE" && grep -q '^MERCURE_SUBSCRIBER_JWT_KEY=.' "$ENV_FILE" \
  || refuse "the Mercure keys are not in .env: run ddev artisan mercure:keys and ddev restart"

server="$(ddev server --current)"
BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
HOST="${BASE_URL#*://}"
HOST="${HOST%%/*}"
HOST_NAME="${HOST%%:*}"
HUB_URL="$BASE_URL/.well-known/mercure"

# A hub answers 200 text/event-stream and keeps the connection open, so curl stops at its own timeout (exit 28).
hub_answer="$(curl -ksS -N --max-time 3 -o /dev/null -w '%{http_code} %{content_type}' "$HUB_URL?topic=bench-probe" 2>/dev/null || true)"
[[ "$hub_answer" == "200 text/event-stream"* ]] || refuse "no Mercure hub answers $HUB_URL on $server (got '${hub_answer:-nothing}'): use ddev server frankenphp"

env_switch_begin
env_set APP_ENV production
env_set APP_DEBUG false
ddev artisan optimize >/dev/null

check_conditions() {
  local status
  [[ "$(ddev artisan config:show app.env | awk 'NF {print $NF}')" == "production" ]] || refuse "APP_ENV is not production"
  [[ "$(ddev artisan config:show app.debug | awk 'NF {print $NF}')" == "false" ]] || refuse "APP_DEBUG is not false"
  ddev exec ls bootstrap/cache/config.php >/dev/null 2>&1 || refuse "config cache is not built"
  ddev exec sh -c 'ls bootstrap/cache/routes*.php' >/dev/null 2>&1 || refuse "route cache is not built"
  status="$(curl -ksS --max-time 30 -o "$WORK/check.json" -w '%{http_code}' "$BASE_URL/healthz")" || status=000
  [[ "$status" == "200" ]] || refuse "cannot read /healthz (status $status)"
  if [[ "$quick" == "0" ]]; then
    # .ai/session/ is the SDD tooling's own log, rewritten by the tooling itself; it is not app code.
    status="$(git status --porcelain -- . ':!bench/results' ':!.ai/session' 2>/dev/null || true)"
    [[ -z "$status" ]] || refuse "git working tree has changes outside bench/results/ and .ai/session/"
  fi
}
check_conditions

OHA_DIGEST="$(image_digest "$OHA_IMAGE")"
[[ -n "$OHA_DIGEST" ]] || { echo "Cannot read the digest or ID of $OHA_IMAGE; refusing to run." >&2; exit 1; }
NODE_DIGEST="$(image_digest "$NODE_IMAGE")"
[[ -n "$NODE_DIGEST" ]] || { echo "Cannot read the digest or ID of $NODE_IMAGE; refusing to run." >&2; exit 1; }

COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
CONCURRENCY_SETTING="$(bash "$SCRIPT_DIR/concurrency.sh" --show 2>/dev/null | tr '\n' ' ' | sed 's/ *$//' || true)"
[[ -n "$CONCURRENCY_SETTING" ]] || CONCURRENCY_SETTING="default"
DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
if [[ "$quick" == "1" ]]; then
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/raw}"
else
  OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/mercure-load/$DATE}"
fi
mkdir -p "$OUT_DIR"

if [[ "$quick" == "0" && "$SETTLE" != "0" ]]; then
  ddev restart >/dev/null
fi
sleep "$SETTLE"
lookup_router
check_conditions

topic_base="$(ddev artisan config:show mercure.topic_base | awk 'NF {print $NF}')"
[[ -n "$topic_base" ]] || refuse "cannot read mercure.topic_base"
TOPIC="${topic_base%/}/listings/$LISTING_ID/price"
PRIVATE_TOPIC="$TOPIC/private"
token_for() {
  ddev artisan mercure:token "$1" | tail -n 1 | tr -d '[:space:]'
}
token_right="$(token_for "$LISTING_ID")"
token_wrong="$(token_for "$OTHER_LISTING_ID")"
[[ -n "$token_right" && -n "$token_wrong" ]] || refuse "mercure:token printed no token"
checks="$(jq -nc --arg topic "$PRIVATE_TOPIC" --arg right "$token_right" --arg wrong "$token_wrong" \
  '[{name: "authorized", topic: $topic, token: $right}, {name: "wrong-token", topic: $topic, token: $wrong}, {name: "no-token", topic: $topic, token: ""}]')"

original_price="$(ddev artisan tinker --execute "echo \App\Models\Listing::query()->whereKey($LISTING_ID)->value('price_cents');" | tail -n 1 | tr -d '[:space:]')"
[[ "$original_price" =~ ^[1-9][0-9]*$ ]] || refuse "cannot read the price of listing $LISTING_ID"

mkdir -p "$WORK/subs"
chmod 777 "$WORK/subs"
docker_run "$SUBS_NAME" --ulimit nofile=65536:65536 -v "$SCRIPT_DIR/lib:/bench-lib:ro" -v "$WORK/subs:/out" \
  -e "HUB=$HUB_URL" -e "TOPIC=$TOPIC" -e "COUNT=$subscribers" -e "CHECKS=$checks" -e "OUT=/out" -e NODE_TLS_REJECT_UNAUTHORIZED=0 -e NODE_NO_WARNINGS=1 \
  "$NODE_IMAGE" node /bench-lib/mercure-subscribers.mjs >/dev/null &
subs_pid=$!
background_pids+=("$subs_pid")

ready_deadline=$((SECONDS + READY_TIMEOUT))
until [[ -s "$WORK/subs/ready.json" ]]; do
  kill -0 "$subs_pid" 2>/dev/null || { echo "The subscriber container stopped before it was ready." >&2; exit 1; }
  [[ "$SECONDS" -lt "$ready_deadline" ]] || { echo "The subscribers were not ready within ${READY_TIMEOUT}s." >&2; exit 1; }
  sleep 0.5
done
sleep "${BENCH_SUBS_SETTLE:-2}"

ddev exec sh bench/lib/busy-threads.sh "$MEASURE" > "$WORK/busy.txt" &
sampler_pid=$!
background_pids+=("$sampler_pid")
generate_probe "$MEASURE" "$WORK/probe.json" &
probe_pid=$!
background_pids+=("$probe_pid")

price_touched=1
for k in $(seq 1 "$PUBLISHES"); do
  ddev artisan listings:reprice "$LISTING_ID" "$((original_price + k * PRICE_STEP))" >/dev/null
  sleep "$PUBLISH_GAP"
done
ddev artisan listings:reprice "$LISTING_ID" "$((original_price + (PUBLISHES + 1) * PRICE_STEP))" --private >/dev/null
sleep "$DRAIN"

probe_status=0
wait "$probe_pid" || probe_status=$?
wait "$sampler_pid" || true
[[ "$probe_status" == "0" ]] || { echo "The health probe failed (exit $probe_status)." >&2; exit 1; }
touch "$WORK/subs/stop"
subs_status=0
wait "$subs_pid" || subs_status=$?
[[ "$subs_status" == "0" && -s "$WORK/subs/result.json" ]] || { echo "The subscriber container failed (exit $subs_status)." >&2; exit 1; }

busy="$(jq -Rn '
  def median: sort | if length == 0 then null elif length % 2 == 1 then .[length / 2 | floor] else (.[length / 2 - 1] + .[length / 2]) / 2 end;
  [inputs | split(" ") | select(length == 2 and .[0] != "na")] as $rows
  | ($rows | map(.[0] | tonumber)) as $busy
  | {metric_found: ($rows | length > 0), samples: ($rows | length), max: ($busy | max), median: ($busy | median),
     total_threads: ($rows | map(.[1] | tonumber) | max)}' < "$WORK/busy.txt")"

FILE="$OUT_DIR/n$subscribers-$label.json"
jq -n \
  --slurpfile subs "$WORK/subs/result.json" --argjson health "$(parse_oha probe < "$WORK/probe.json")" --argjson busy "$busy" \
  --arg server "$server" --arg label "$label" --arg commit "$COMMIT" --arg concurrency "$CONCURRENCY_SETTING" \
  --arg base "$BASE_URL" --arg date "$DATE" --arg topic "$TOPIC" \
  --arg nodeImage "$NODE_IMAGE" --arg nodeDigest "$NODE_DIGEST" --arg probeImage "$OHA_IMAGE" --arg probeDigest "$OHA_DIGEST" \
  --argjson subscribers "$subscribers" --argjson publishes "$PUBLISHES" --argjson measure "$MEASURE" --argjson quick "$quick" \
  --argjson probeQps "$PROBE_QPS" --argjson probeTimeout "$PROBE_TIMEOUT" '
  def pct($q): map(select(. != null)) | sort | if length == 0 then null else .[(length * $q | ceil) - 1 | if . < 0 then 0 else . end] end;
  def received($name): ($subs[0].checks | map(select(.name == $name)) | .[0].received) // 0;
  def open($name): ($subs[0].checks | map(select(.name == $name)) | .[0].open) // false;
  ($subs[0]) as $s
  | ($subscribers * ($publishes + 1)) as $expected
  | (received("authorized")) as $right | (received("wrong-token")) as $wrong | (received("no-token")) as $none
  | {
      server: $server, label: $label, commit: $commit, concurrency: $concurrency, base_url: $base, date: $date, quick: ($quick == 1),
      topic: $topic, subscribers: $subscribers, publishes: $publishes, public_messages_per_subscriber: ($publishes + 1),
      measure_s: $measure, probe_qps: $probeQps, probe_timeout_s: $probeTimeout,
      subscriber_image: $nodeImage, subscriber_image_digest: $nodeDigest, probe_image: $probeImage, probe_image_digest: $probeDigest,
      connected: $s.connected, connect_failed: ($subscribers - $s.connected), connect_failures: $s.failures,
      health: $health,
      busy_threads: $busy,
      latency_ms: {p50: ($s.latencies_ms | pct(0.5)), p95: ($s.latencies_ms | pct(0.95)), max: ($s.latencies_ms | map(select(. != null)) | max),
                   samples: ($s.latencies_ms | map(select(. != null)) | length)},
      delivered: {received: $s.messages, expected: $expected, percent: (($s.messages / $expected * 1000 | round) / 10)},
      private: {
        authorized_received: $right, wrong_token_received: $wrong, no_token_received: $none,
        passed: (open("authorized") and open("wrong-token") and open("no-token") and $right == 1 and $wrong == 0 and $none == 0)
      }
    }' > "$FILE"

echo "subscribers=$subscribers server=$server setting=\"$CONCURRENCY_SETTING\" measure=${MEASURE}s publishes=$PUBLISHES (+1 private)"
jq -r 'def r: if . == null then "n/a" else (. * 10 | round / 10 | tostring) end;
  "connected \(.connected)/\(.subscribers)" + (if .connect_failed > 0 then " (failed: \(.connect_failures | tostring))" else "" end),
  "delivered \(.delivered.received)/\(.delivered.expected) (\(.delivered.percent)%)",
  "latency_ms p50=\(.latency_ms.p50 | r) p95=\(.latency_ms.p95 | r) max=\(.latency_ms.max | r) over \(.latency_ms.samples) messages",
  "health_p95_ms=\(.health.p95_ms | r)" + (if .health.starved then " starved" else "" end) + " p50=\(.health.p50_ms | r) timeouts=\(.health.breakdown.timeouts) errors=\(.health.errors) (\(.health.error_percent | r)%)",
  (if .busy_threads.metric_found then "busy threads: max=\(.busy_threads.max) median=\(.busy_threads.median | r) of \(.busy_threads.total_threads) (\(.busy_threads.samples) samples)"
   else "busy threads: metric not found in Caddy metrics" end)' "$FILE"
private_passed="$(jq -r '.private.passed' "$FILE")"
jq -r '"private checks: " + (if .private.passed then "PASS" else "FAIL" end) + " (authorized=\(.private.authorized_received) wrong-token=\(.private.wrong_token_received) no-token=\(.private.no_token_received))"' "$FILE"
echo "Wrote $FILE"
[[ "$private_passed" == "true" ]] || exit 1
