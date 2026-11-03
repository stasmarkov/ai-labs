#!/usr/bin/env bash
## Usage: bench/chat-stop.sh [--modes <a,b,...>] [--runs <n>] [--tokens <n>] [--label <name>] [--force]
## Measures what a client Stop does on the server, per server mode. For each mode it asks POST /api/docs/ask (cache off)
## --runs times (default 5), closes the connection after --tokens text events (default 20), then looks up the ai_runs row
## of that request by the invocation_id in the stream's stream_start event (waiting up to CHAT_STOP_SETTLE seconds for it)
## and reads status, tokens_out and stopped_after_ms, then sends a 1-token request straight to Ollama. A row is never
## guessed from "the latest row": a mode that does not detect the abort writes its completed row late, and that row would
## otherwise be credited to the next run.
## The probe starts right after the abort; its median is compared with the median of 5 probes made on an idle model before
## the runs. A mode is reported with its numbers whether or not it detects the abort.
## Per run: tokens_after_stop = tokens_out of the row minus the text events the client received; probe_ms = Ollama latency.
## NFR-1 (reported, not a gate): median probe_ms <= 1.5 x idle median + 1000 and median tokens_after_stop <= 10.
## Writes bench/results/chat-stop/<UTC date>/<mode>.json and prints one table per mode.
## --modes switches with `ddev server <mode>` and ends on nginx-fpm; without it the active server is measured.
## Env: CHAT_STOP_BASE_URL (default: `ddev server --url`), CHAT_STOP_OLLAMA_URL (default http://localhost:11434),
##      OLLAMA_NUM_PARALLEL (recorded as metadata.ollama_num_parallel; 'default' when unset),
##      CHAT_STOP_MODEL (default OLLAMA_MODEL from .env, else qwen3.5:9b), CHAT_STOP_SETTLE (seconds to wait for the row, default 4).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
OLLAMA="${CHAT_STOP_OLLAMA_URL:-http://localhost:11434}"
SETTLE="${CHAT_STOP_SETTLE:-4}"
MODES=""
RUNS=5
TOKENS=20
LABEL=""
FORCE=0
LIMIT=10
QUESTION="Explain in detail how queues, workers and batching fit together"

while [ $# -gt 0 ]; do
  case "$1" in
    --modes) MODES="$2"; shift 2 ;;
    --modes=*) MODES="${1#--modes=}"; shift ;;
    --runs) RUNS="$2"; shift 2 ;;
    --runs=*) RUNS="${1#--runs=}"; shift ;;
    --tokens) TOKENS="$2"; shift 2 ;;
    --tokens=*) TOKENS="${1#--tokens=}"; shift ;;
    --label) LABEL="$2"; shift 2 ;;
    --label=*) LABEL="${1#--label=}"; shift ;;
    --force) FORCE=1; shift ;;
    *) echo "unknown argument: $1" >&2; exit 2 ;;
  esac
done

MODEL="${CHAT_STOP_MODEL:-$(grep -E '^OLLAMA_MODEL=' "$ROOT/.env" 2>/dev/null | head -1 | cut -d= -f2- || true)}"
MODEL="${MODEL:-qwen3.5:9b}"
PARALLEL="${OLLAMA_NUM_PARALLEL:-default}"
DAY="$(date -u +%Y-%m-%d)"
OUT="$ROOT/bench/results/chat-stop/$DAY${LABEL:+/$LABEL}"
mkdir -p "$OUT"

if [ -z "$MODES" ]; then
  MODES="$(cd "$ROOT" && ddev server --current)"
fi

STARTS=()
WORK="$(mktemp -d)"
trap 'rm -rf "$WORK"' EXIT

pace() {
  local now oldest wait
  now="$(date +%s)"
  if [ "${#STARTS[@]}" -ge "$LIMIT" ]; then
    oldest="${STARTS[$((${#STARTS[@]} - LIMIT))]}"
    wait=$((oldest + 61 - now))
    if [ "$wait" -gt 0 ]; then sleep "$wait"; fi
  fi
  STARTS+=("$(date +%s)")
}

probe() {
  local body
  body="$(jq -nc --arg m "$MODEL" '{model: $m, prompt: "Hi", stream: false, think: false, options: {num_predict: 1}}')"
  curl -sk -o /dev/null -w '%{time_total}\n' -H 'Content-Type: application/json' -d "$body" "$OLLAMA/api/generate" |
    awk '{ printf "%d\n", $1 * 1000 + 0.5 }'
}

invocation_id_in() {
  sed -n 's/^data: //p' "$1" 2>/dev/null | jq -Rr 'fromjson? | select(.type == "stream_start") | .invocation_id' 2>/dev/null | head -1
}

row_for() {
  (cd "$ROOT" && ddev artisan tinker --execute "echo json_encode(App\\Models\\AiRun::where(\"invocation_id\", \"$1\")->first()?->only([\"status\", \"tokens_out\", \"stopped_after_ms\"]));" 2>/dev/null) |
    tail -1
}

wait_for_row() {
  local row deadline=$((SECONDS + SETTLE))
  while :; do
    row="$(row_for "$1")"
    if [ -n "$row" ] && [ "$row" != "null" ]; then echo "$row"; return; fi
    if [ "$SECONDS" -ge "$deadline" ]; then echo null; return; fi
    sleep 1
  done
}

events_in() {
  local count
  count="$(grep -c '"type":"text_delta"' "$1" 2>/dev/null || true)"
  echo "${count:-0}"
}

measure_mode() {
  local mode="$1" base idle i pid file seen row invocation probe_ms started now
  base="${CHAT_STOP_BASE_URL:-$(cd "$ROOT" && ddev server --url)}"
  local rows="$WORK/$mode.rows"
  : >"$rows"

  probe >/dev/null
  idle="$(for i in 1 2 3 4 5; do probe; done | jq -sc '.')"

  for i in $(seq 1 "$RUNS"); do
    pace
    file="$WORK/$mode.$i.body"
    : >"$file"
    curl -sk -N -o "$file" -H 'Content-Type: application/json' \
      -d "$(jq -nc --arg q "$QUESTION" '{q: $q, cache: false}')" "$base/api/docs/ask" &
    pid=$!
    started="$(date +%s)"
    while [ "$(events_in "$file")" -lt "$TOKENS" ]; do
      now="$(date +%s)"
      if ! kill -0 "$pid" 2>/dev/null || [ $((now - started)) -gt 120 ]; then break; fi
      sleep 0.05
    done
    kill "$pid" 2>/dev/null || true
    wait "$pid" 2>/dev/null || true
    probe_ms="$(probe)"
    seen="$(events_in "$file")"
    invocation="$(invocation_id_in "$file")"
    row=null
    if [[ "$invocation" =~ ^[A-Za-z0-9_-]+$ ]]; then
      row="$(wait_for_row "$invocation")"
    else
      invocation=""
    fi
    if [ "$row" = "null" ]; then row='{"status":"no_row","tokens_out":null,"stopped_after_ms":null}'; fi
    jq -nc --argjson run "$i" --arg invocation "$invocation" --argjson seen "$seen" --argjson probe "$probe_ms" --argjson row "$row" '
      {run: $run, invocation_id: (if $invocation == "" then null else $invocation end), events_received: $seen, status: $row.status, tokens_out: $row.tokens_out,
       tokens_after_stop: (if $row.tokens_out == null then null else ($row.tokens_out - $seen) end),
       stopped_after_ms: $row.stopped_after_ms, probe_ms: $probe}' >>"$rows"
  done

  jq -s --arg mode "$mode" --arg model "$MODEL" --arg parallel "$PARALLEL" --arg commit "$(git -C "$ROOT" rev-parse HEAD)" \
    --arg at "$(date -u +%Y-%m-%dT%H:%M:%S+00:00)" --argjson idle "$idle" --argjson tokens "$TOKENS" '
    def median: map(select(. != null)) | sort | if length == 0 then null else (length / 2 | floor) as $i | if length % 2 == 1 then .[$i] else (.[$i - 1] + .[$i]) / 2 end end;
    (map(.probe_ms) | median) as $probe | ($idle | median) as $base | (map(.tokens_after_stop) | median) as $lag |
    {
      metadata: {mode: $mode, model: $model, ollama_num_parallel: $parallel, generated_at: $at, git_commit: $commit, runs: length, abort_after_events: $tokens},
      idle_probe_ms: $idle,
      median_idle_probe_ms: $base,
      median_probe_ms_after_stop: $probe,
      median_tokens_after_stop: $lag,
      stopped_rows: (map(select(.status == "stopped")) | length),
      probe_within_budget: ($probe != null and $probe <= $base * 1.5 + 1000),
      lag_within_budget: ($lag != null and $lag <= 10),
      requests: .
    }' "$rows" >"$OUT/$mode.json"

  jq -r '
    "mode \(.metadata.mode)  model \(.metadata.model)  runs \(.metadata.runs)  abort after \(.metadata.abort_after_events) events",
    "  stopped rows              \(.stopped_rows)/\(.metadata.runs)",
    "  median tokens after Stop  \(.median_tokens_after_stop)  (<= 10: \(.lag_within_budget))",
    "  idle probe median ms      \(.median_idle_probe_ms)",
    "  probe after Stop median   \(.median_probe_ms_after_stop)  (<= 1.5x idle + 1000: \(.probe_within_budget))",
    "",
    (["run", "status", "tokens_out", "after_stop", "probe_ms"] | @tsv),
    (.requests[] | [.run, (.status // "-"), (.tokens_out // "-"), (.tokens_after_stop // "-"), .probe_ms] | @tsv)
  ' "$OUT/$mode.json" | awk -F '\t' 'NF > 1 { printf "  %-5s %-12s %-11s %-11s %s\n", $1, $2, $3, $4, $5; next } { print }'
  echo
}

SWITCHED=0
for mode in ${MODES//,/ }; do
  if [ -e "$OUT/$mode.json" ] && [ "$FORCE" -ne 1 ]; then
    echo "$OUT/$mode.json exists; pass --force to overwrite" >&2
    exit 2
  fi
  if [[ "$MODES" == *,* ]] || [ "$mode" != "$(cd "$ROOT" && ddev server --current)" ]; then
    (cd "$ROOT" && ddev server "$mode" >/dev/null)
    SWITCHED=1
  fi
  measure_mode "$mode"
done

if [ "$SWITCHED" -eq 1 ]; then
  (cd "$ROOT" && ddev server nginx-fpm >/dev/null)
fi

echo "Results: $OUT"
