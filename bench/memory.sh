#!/usr/bin/env bash
## Usage: bench/memory.sh [--requests <n>]
## Memory of one PHP worker over n sequential requests (default 10000) on the active server, which must be
## frankenphp-octane, frankenphp-worker or frankenphp (classic). Each case first sets 1 worker through
## bench/concurrency.sh 1 [--max-requests <m>], then sends n requests, one at a time, to
## /debug/leaks/price-cache?dealer=1&locale=en&mode=<mode>&amount=<1000000 + i> (a new amount every request).
## Cases: A leak, no recycling (max-requests 1000000); B fixed, no recycling; C leak, max-requests 500 (worker
## servers only). Classic frankenphp runs A and B on one thread (num_threads 1, max_threads 1), where nothing
## survives a request. n must be a positive multiple of 100.
## Before the first request and after every 100 requests it reads /debug/worker and records memory_bytes,
## worker_hits and first_seen_at. A restart shows as worker_hits going down or first_seen_at changing (workers
## are threads in one process, so pid does not change). The reads count toward the worker's max-requests; the n
## counts lab requests only. Classic frankenphp has no long-lived worker, so its restarts are reported as 0.
## growth_kib_per_1000 is the least-squares slope of memory_bytes over requests, per stretch between restarts
## (stretches with one sample are skipped), averaged, in KiB per 1000 requests.
## The run is a single pass with the BR-20 conditions (APP_ENV=production, APP_DEBUG=false, DEBUG_ROUTES=true,
## optimized caches, OPcache on, Xdebug off, clean git tree). .env is backed up and restored on exit, and
## `concurrency.sh default` runs on exit, so the server returns to its committed worker settings.
## Any lab request that does not answer 200 aborts the run with exit 2 (naming the case and the count).
## Writes bench/results/<BENCH_DATE>/memory-<server>.json (BENCH_DATE default: today, UTC) with server, commit,
## date and per case: case, mode, workers, max_requests, samples, restarts, growth_kib_per_1000.
## Env: BENCH_APPROOT (project root), BENCH_BASE_URL (default: `ddev server --url`), BENCH_DATE,
##      BENCH_SETTLE (seconds to wait for the server after a restart, default 60).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
ENV_FILE="$APPROOT/.env"
BACKUP="$APPROOT/.env.bench-backup"
SETTLE="${BENCH_SETTLE:-60}"
SAMPLE_EVERY=100
NO_RECYCLING=1000000
RECYCLING=500
REQUESTS=10000

usage() {
  echo "Usage: $0 [--requests <n>] (n is a positive multiple of $SAMPLE_EVERY)" >&2
  exit 2
}

refuse() {
  echo "Cannot run: $1" >&2
  exit 2
}

while [[ $# -gt 0 ]]; do
  case "$1" in
    --requests)
      [[ "${2:-}" =~ ^[1-9][0-9]*$ ]] && (( $2 % SAMPLE_EVERY == 0 )) || usage
      REQUESTS="$2"
      shift 2
      ;;
    *) usage ;;
  esac
done

cd "$APPROOT"
command -v jq >/dev/null 2>&1 || refuse "jq is required on the host (brew install jq)"
server="$(ddev server --current)"
case "$server" in
  frankenphp-octane | frankenphp-worker | frankenphp) ;;
  *) refuse "the active server is $server; use frankenphp-octane, frankenphp-worker or frankenphp" ;;
esac
[[ -e "$BACKUP" ]] && refuse "stale $BACKUP exists: a previous run did not restore .env. Restore it by hand first"
[[ -f "$ENV_FILE" ]] || refuse "no .env at $ENV_FILE"

BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
RESULT="$APPROOT/bench/results/$DATE/memory-$server.json"
if [[ -n "$(git -C "$APPROOT" ls-files -- "bench/results/$DATE" 2>/dev/null)" ]]; then
  echo "bench/results/$DATE already holds committed results. Set BENCH_DATE to a new folder name, e.g. BENCH_DATE=$(date -u +%Y-%m-%d)-classic-vs-worker." >&2
  exit 2
fi
COMMIT="$(git rev-parse --short HEAD 2>/dev/null || echo unknown)"
WORK="$(mktemp -d)"
env_switched=0
workers_changed=0

cleanup() {
  local code=$?
  trap - EXIT
  if [[ "$env_switched" == "1" && -f "$BACKUP" ]]; then
    cp -p "$BACKUP" "$ENV_FILE"
    rm -f "$BACKUP"
    ddev artisan optimize:clear >/dev/null 2>&1 || true
  fi
  if [[ "$workers_changed" == "1" ]]; then
    bash "$SCRIPT_DIR/concurrency.sh" default >/dev/null 2>&1 || echo "Warning: concurrency.sh default failed; restore the worker settings by hand." >&2
  fi
  rm -rf "$WORK"
  exit "$code"
}
trap cleanup EXIT
trap 'exit 130' INT TERM

set_env_value() {
  { grep -v "^${1}=" "$ENV_FILE" || true; echo "${1}=${2}"; } > "$WORK/env.new"
  cat "$WORK/env.new" > "$ENV_FILE"
}

# Waits up to SETTLE seconds of wall-clock time (SECONDS), however long each curl attempt takes.
wait_ready() {
  local status deadline=$((SECONDS + SETTLE))
  while true; do
    status="$(curl -ksS --max-time 5 -H 'Accept: application/json' -o /dev/null -w '%{http_code}' "$BASE_URL/debug/worker" 2>/dev/null)" || status=000
    [[ "$status" == "200" ]] && return 0
    [[ "$SECONDS" -lt "$deadline" ]] || return 1
    sleep 1
  done
}

check_conditions() {
  local body="$WORK/runtime.json" status value
  [[ "$(ddev artisan config:show app.env | awk 'NF {print $NF}')" == "production" ]] || refuse "APP_ENV is not production"
  [[ "$(ddev artisan config:show app.debug | awk 'NF {print $NF}')" == "false" ]] || refuse "APP_DEBUG is not false"
  ddev exec ls bootstrap/cache/config.php >/dev/null 2>&1 || refuse "config cache is not built"
  ddev exec sh -c 'ls bootstrap/cache/routes*.php' >/dev/null 2>&1 || refuse "route cache is not built"
  status="$(curl -ksS --max-time 30 -H 'Accept: application/json' -o "$body" -w '%{http_code}' "$BASE_URL/debug/runtime")" || status=000
  [[ "$status" == "200" ]] || refuse "cannot read /debug/runtime (status $status)"
  ddev exec sh -c 'ls storage/framework/views/*.php' >/dev/null 2>&1 || refuse "view cache is not built"
  value="$(jq -r '.data.opcache_enabled' "$body")"
  [[ "$value" == "true" ]] || refuse "OPcache is not on for the serving SAPI"
  value="$(jq -r '.data.xdebug_loaded' "$body")"
  [[ "$value" == "false" ]] || refuse "Xdebug is not off for the serving SAPI"
  value="$(git status --porcelain -- . ':!bench/results' ':!.ai/session' 2>/dev/null || true)"
  [[ -z "$value" ]] || refuse "git working tree has changes outside bench/results/ and .ai/session/"
}

# Appends {requests, memory_bytes, worker_hits, first_seen_at} read from /debug/worker to the case's samples.
sample() {
  local attempt status
  for attempt in 1 2 3; do
    status="$(curl -ksS --max-time 30 -H 'Accept: application/json' -o "$WORK/sample.json" -w '%{http_code}' "$BASE_URL/debug/worker")" || status=000
    if [[ "$status" == "200" ]] && jq -ce --argjson requests "$1" \
      '{requests: $requests, memory_bytes: .data.memory_bytes, worker_hits: .data.worker_hits, first_seen_at: .data.first_seen_at}' \
      "$WORK/sample.json" >> "$WORK/samples.ndjson" 2>/dev/null; then
      return 0
    fi
    sleep 1
  done
  refuse "/debug/worker did not answer with a sample after $1 requests (last status $status)"
}

JQ_CASE='
def restarted($a; $b): $b.worker_hits < $a.worker_hits or $b.first_seen_at != $a.first_seen_at;
def stretches($classic):
  . as $s
  | if $classic then [$s] else
      reduce range(1; $s | length) as $i ([[$s[0]]];
        if restarted($s[$i - 1]; $s[$i]) then . + [[$s[$i]]] else .[-1] += [$s[$i]] end)
    end;
def slope:
  if length < 2 then null else
    (map(.requests) | add / length) as $mx
    | (map(.memory_bytes) | add / length) as $my
    | (map(pow(.requests - $mx; 2)) | add) as $d
    | if $d == 0 then null else (map((.requests - $mx) * (.memory_bytes - $my)) | add) / $d end
  end;
. as $samples
| ($samples | stretches($classic)) as $parts
| ($parts | map(slope) | map(select(. != null))) as $slopes
| {
    case: $case, mode: $mode, workers: 1, max_requests: $max,
    samples: $samples,
    restarts: ($parts | length - 1),
    growth_kib_per_1000: (if $slopes == [] then 0 else (($slopes | add / length) * 1000 / 1024 * 100 | round) / 100 end)
  }'

run_case() {
  local case_id="$1" mode="$2" max="$3" i status errors=0 args=(1)
  [[ -n "$max" ]] && args+=(--max-requests "$max")
  echo "case $case_id ($mode, max-requests ${max:-n/a}): $REQUESTS requests"
  workers_changed=1
  bash "$SCRIPT_DIR/concurrency.sh" "${args[@]}" >/dev/null
  wait_ready || refuse "$BASE_URL/debug/worker did not answer 200 after setting 1 worker"
  if [[ "$env_checked" != "1" ]]; then
    check_conditions
    env_checked=1
  fi

  : > "$WORK/samples.ndjson"
  sample 0
  for ((i = 0; i < REQUESTS; i++)); do
    status="$(curl -ksS --max-time 30 -H 'Accept: application/json' -o /dev/null -w '%{http_code}' \
      "$BASE_URL/debug/leaks/price-cache?dealer=1&locale=en&mode=$mode&amount=$((1000000 + i))")" || status=000
    [[ "$status" == "200" ]] || errors=$((errors + 1))
    (( (i + 1) % SAMPLE_EVERY == 0 )) && sample "$((i + 1))"
  done
  [[ "$errors" -eq 0 ]] || refuse "case $case_id: $errors of $REQUESTS lab requests did not answer 200; the curve would be invalid"

  jq -s -c --arg case "$case_id" --arg mode "$mode" --argjson max "${max:-null}" \
    --argjson classic "$classic" "$JQ_CASE" "$WORK/samples.ndjson" >> "$WORK/cases.ndjson"
  return 0
}

cp -p "$ENV_FILE" "$BACKUP"
env_switched=1
set_env_value APP_ENV production
set_env_value APP_DEBUG false
set_env_value DEBUG_ROUTES true
ddev artisan optimize >/dev/null

env_checked=0
: > "$WORK/cases.ndjson"
if [[ "$server" == "frankenphp" ]]; then
  classic=true
  run_case A leak ""
  run_case B fixed ""
else
  classic=false
  run_case A leak "$NO_RECYCLING"
  run_case B fixed "$NO_RECYCLING"
  run_case C leak "$RECYCLING"
fi
# Written only once every case has succeeded, so a failed later case never replaces a complete file.
mkdir -p "$(dirname "$RESULT")"
jq -s --arg server "$server" --arg commit "$COMMIT" --arg date "$DATE" \
  '{server: $server, commit: $commit, date: $date, cases: .}' "$WORK/cases.ndjson" > "$WORK/result.json"
mv "$WORK/result.json" "$RESULT"
echo "wrote $RESULT"
