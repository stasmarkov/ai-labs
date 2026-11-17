#!/usr/bin/env bash
## Usage: bench/log-overhead.sh
## NFR-2: what the frankenphp log channel costs per request. Runs the full BR-21 ping (bench/run.sh ping: warm-up 30 s,
## measure 60 s, 3 repeats, spread check) on frankenphp-worker against /debug/log-ping, a debug-only copy of /api/ping that
## writes one info entry, twice: once with LOG_STACK=single, once with LOG_STACK=single,frankenphp. Both runs log the entry
## to the file, so the difference is the frankenphp channel alone. The setting is written to .env through the shared
## bench/lib/env-switch.sh, with its own backup file (in the system temp directory, mode 600) so bench/run.sh can keep the
## usual .env.bench-backup. On exit .env is restored and the project's default server is switched back.
## The single channel grows storage/logs/laravel.log by one line per request (millions in a full run); on exit the file is
## cut back to the size it had before, so earlier entries stay and nothing from the run does. The frankenphp entries go to
## the web container's log stream (`ddev logs -s web`), which docker rotates.
## Output: bench/results/restart/<UTC date>/log-overhead.json with the median p95 of each run, every repeat's p95, and the
## difference in %. Under 5 % (or within the spread of the runs) reads as "no measurable cost".
## Env: BENCH_APPROOT, BENCH_DATE, BENCH_OUT_DIR, BENCH_RUN_SH (the ping runner, default bench/run.sh); BENCH_WARMUP, BENCH_MEASURE,
## BENCH_REPEATS and the rest are read by bench/run.sh.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
RUN_SH="${BENCH_RUN_SH:-$SCRIPT_DIR/run.sh}"
MODE="frankenphp-worker"
PING_PATH="/debug/log-ping"
THRESHOLD_PERCENT=5
# The backup holds every secret of .env, so it lives outside the repository (nothing to commit by accident, nothing for the clean-tree check to see).
ENV_SWITCH_BACKUP="${TMPDIR:-/tmp}/ai-lab-bench-log-env-backup-$(printf %s "$APPROOT" | cksum | cut -d" " -f1)"
# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"

server_switched=0
LARAVEL_LOG="$APPROOT/storage/logs/laravel.log"
log_size_before=""
# Cuts laravel.log back to its size before the run (removes it when the run created it); never touches a file that shrank.
trim_laravel_log() {
  [[ -n "$log_size_before" && -f "$LARAVEL_LOG" ]] || return 0
  if [[ "$log_size_before" == "absent" ]]; then
    rm -f "$LARAVEL_LOG"
  elif [[ "$(wc -c < "$LARAVEL_LOG" | tr -d ' ')" -gt "$log_size_before" ]]; then
    perl -e 'truncate($ARGV[0], $ARGV[1]) or die "truncate: $!\n"' "$LARAVEL_LOG" "$log_size_before"
  fi
}
cleanup() {
  trim_laravel_log
  env_switch_restore
  if [[ "$server_switched" == "1" ]]; then
    restore_default_server "$APPROOT" >/dev/null 2>&1 || true
  fi
}
trap 'exit 130' INT TERM

DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/restart/$DATE}"
COMMIT="$(git -C "$APPROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
WORK="$(mktemp -d)"
trap 'cleanup; rm -rf "$WORK"' EXIT

read_default_server "$APPROOT"
if [[ -f "$LARAVEL_LOG" ]]; then log_size_before="$(wc -c < "$LARAVEL_LOG" | tr -d ' ')"; else log_size_before="absent"; fi
env_switch_begin
cd "$APPROOT"
ddev server "$MODE" >/dev/null
server_switched=1

# One run: sets the stack, lets bench/run.sh do the full ping and keeps the result files it lists.
run_variant() {
  local label="$1" stack="$2" output
  env_set LOG_STACK "$stack"
  env_apply
  output="$(BENCH_PING_PATH="$PING_PATH" bash "$RUN_SH" ping --label "$label")"
  printf '%s\n' "$output" | grep '\.json$' > "$WORK/$label.files" || { echo "bench/run.sh listed no result files." >&2; exit 1; }
  # shellcheck disable=SC2046
  jq -s --arg stack "$stack" '
    map(select(.discarded | not)) as $runs
    | ($runs | map(.p95_ms) | sort) as $p
    | {log_stack: $stack,
       p95_ms: (if ($p | length) == 0 then null elif ($p | length) % 2 == 1 then $p[($p | length) / 2 | floor] else (($p[($p | length) / 2 - 1] + $p[($p | length) / 2]) / 2) end),
       runs: ($runs | map({p95_ms, rps, p99_ms, error_percent}))}' $(cat "$WORK/$label.files") > "$WORK/$label.json"
}

run_variant log-off single
run_variant log-on single,frankenphp

mkdir -p "$OUT_DIR"
file="$OUT_DIR/log-overhead.json"
jq -n --slurpfile off "$WORK/log-off.json" --slurpfile on "$WORK/log-on.json" \
  --arg server "$MODE" --arg route "$PING_PATH" --arg commit "$COMMIT" --arg date "$DATE" --argjson threshold "$THRESHOLD_PERCENT" '
  ($off[0].p95_ms) as $a | ($on[0].p95_ms) as $b
  | (if $a == null or $b == null or $a == 0 then null else ((($b - $a) / $a * 1000 | round) / 10) end) as $diff
  | {server: $server, route: $route, commit: $commit, date: $date, method: "BR-21: warm-up 30 s, measure 60 s, 3 repeats, median",
     channel_off: $off[0], channel_on: $on[0], difference_percent: $diff, threshold_percent: $threshold,
     within_threshold: ($diff != null and $diff < $threshold)}' > "$file"
jq -r '"p95 off \(.channel_off.p95_ms) ms, on \(.channel_on.p95_ms) ms, difference \(.difference_percent) %"' "$file"
echo "Wrote $file"
