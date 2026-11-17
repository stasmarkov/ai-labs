#!/usr/bin/env bash
## Usage: bench/reload-latency.sh
## Edit to reload: the time from saving a PHP file to the first response that serves the new code, in five ways of working:
## classic (frankenphp), octane-nowatch, octane-watch, worker-nowatch and worker-watch (frankenphp-octane and
## frankenphp-worker, with `ddev dev-watch` off or on). FrankenPHP's browser hot reload is not in v1.13.5, so it has no row.
## One trial: overwrite MARKER in app/Support/ReloadProbe.php with a unique value, record the host time, poll
## /debug/reload-probe every 50 ms until the answer holds the marker, record the elapsed ms (capped at BENCH_CAP_S).
## The Mutagen delay is a separate pass after each timed trial, so it adds no load to the timing: one `ddev exec` starts a
## 10 ms poll of the file inside the container and prints "ready"; then the host writes a new marker and the ms count until
## that poll prints "seen" ("n/a" when `ddev describe` shows no Mutagen). The docker exec start-up is not in the number.
## Rows with watching (and classic) run 10 trials. The no-watch rows run 3 trials that are expected to end as "never"
## after BENCH_NEVER_CAP_S (counted, left out of the statistics) and then 10 timed restarts: the marker is written and
## synced, then `ddev artisan frankenphp:workers:restart` runs. restart_ms counts from the start of that command to the
## new answer, so it includes the docker exec and the Artisan boot; restart_call_ms is the admin API call (worker) or
## octane:reload (Octane) alone, as the command prints it ("workers restarted in N ms").
## The no-watch rows run with the request limit lifted (bench/concurrency.sh, same worker count), so a worker recycling
## itself cannot load the new file and end a "never" early. Every row starts with `ddev server`, which clears that overlay,
## and the watch rows then run `ddev dev-watch on` (the two overlays are never active together).
## Median, p90 (nearest rank) and max cover the numeric trials only.
## OPcache checks a file's timestamp at most every opcache.revalidate_freq (2 s in DDEV), so the wait depends on when in
## that window the edit lands. A fixed rhythm (serve, settle, edit) would land every edit at the same point of the window;
## the random pause before each edit spreads them over it, as a developer's saves are.
## The harness sets DEBUG_ROUTES=true in .env through bench/lib/env-switch.sh. It switches servers with `ddev server` and
## watching with `ddev dev-watch`, and never edits the .ddev project config. On any exit, Ctrl-C included, it puts back the probe
## file, .env, the server it started on and its dev-watch state.
## Output: bench/results/reload/<UTC date>/<row>.json with {mode, watch, row, trials: [{ms, mutagen_ms}], median_ms, p90_ms,
## max_ms, never, mutagen_median_ms, restart_ms: {median, p90, max}|null, restart_call_ms: {median, p90, max}|null, commit,
## frankenphp_version}.
## Env: BENCH_APPROOT, BENCH_DATE, BENCH_OUT_DIR, BENCH_BASE_URL, BENCH_ROWS (comma list of row names, default all five),
## BENCH_TRIALS (10), BENCH_NEVER_TRIALS (3), BENCH_CAP_S (30, the longest wait for a trial that should reload),
## BENCH_NEVER_CAP_S (30, the wait before a no-watch trial counts as never), BENCH_SETTLE_S (1, the pause after a trial),
## BENCH_JITTER_S (2, an extra random pause of 0 to this many seconds before each edit),
## BENCH_READY_TIMEOUT (120). The two caps accept decimals.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"

PROBE_FILE="$APPROOT/app/Support/ReloadProbe.php"
CONTAINER_PROBE_FILE=/var/www/html/app/Support/ReloadProbe.php
PROBE_PATH=/debug/reload-probe
TRIALS="${BENCH_TRIALS:-10}"
NEVER_TRIALS="${BENCH_NEVER_TRIALS:-3}"
SETTLE_S="${BENCH_SETTLE_S:-1}"
READY_TIMEOUT="${BENCH_READY_TIMEOUT:-120}"
# row name|server|watching
ROWS=(
  "classic|frankenphp|off"
  "octane-nowatch|frankenphp-octane|off"
  "octane-watch|frankenphp-octane|on"
  "worker-nowatch|frankenphp-worker|off"
  "worker-watch|frankenphp-worker|on"
)

if [[ $# -gt 0 ]]; then
  echo "Usage: $0" >&2
  exit 2
fi

# Seconds (decimals allowed) to whole ms.
seconds_to_ms() {
  [[ "$1" =~ ^[0-9]+([.][0-9]+)?$ ]] || { echo "Not a number of seconds: $1" >&2; exit 2; }
  awk -v s="$1" 'BEGIN { printf "%d\n", s * 1000 }'
}
CAP_MS="$(seconds_to_ms "${BENCH_CAP_S:-30}")"
JITTER_MS="$(seconds_to_ms "${BENCH_JITTER_S:-2}")"
NEVER_CAP_MS="$(seconds_to_ms "${BENCH_NEVER_CAP_S:-30}")"

if [[ -n "${BENCH_ROWS:-}" ]]; then
  selected=()
  for row in "${ROWS[@]}"; do
    [[ ",$BENCH_ROWS," == *",${row%%|*},"* ]] && selected+=("$row")
  done
  [[ ${#selected[@]} -gt 0 ]] || { echo "BENCH_ROWS names no known row (classic, octane-nowatch, octane-watch, worker-nowatch, worker-watch)." >&2; exit 2; }
  ROWS=("${selected[@]}")
fi

cd "$APPROOT"
WORK="$(mktemp -d)"
BACKUP="$WORK/ReloadProbe.php"
SYNC_PID=""
START_SERVER=""
START_WATCH=""
server_switched=0
probe_saved=0

restore_all() {
  local status=$?
  [[ -z "$SYNC_PID" ]] || kill "$SYNC_PID" 2>/dev/null || true
  if [[ "$probe_saved" == "1" ]]; then
    cat "$BACKUP" > "$PROBE_FILE"
  fi
  env_switch_restore
  if [[ "$server_switched" == "1" ]]; then
    if [[ "$START_SERVER" == "$DEFAULT_SERVER_NAME" ]]; then
      restore_default_server "$APPROOT" >/dev/null 2>&1 || true
    else
      ddev server "$START_SERVER" >/dev/null 2>&1 || true
    fi
    if [[ "$START_WATCH" == "on" ]]; then
      ddev dev-watch on >/dev/null 2>&1 || true
    fi
  fi
  rm -rf "$WORK" 2>/dev/null || true
  if [[ "$probe_saved" == "1" ]] && git -C "$APPROOT" rev-parse --is-inside-work-tree >/dev/null 2>&1 \
    && ! git -C "$APPROOT" diff --quiet -- app/Support/ReloadProbe.php; then
    echo "app/Support/ReloadProbe.php differs from git after the restore; check it before committing." >&2
    [[ "$status" -ne 0 ]] || status=3
  fi
  exit "$status"
}
trap restore_all EXIT
trap 'exit 130' INT TERM

now_ms() {
  perl -MTime::HiRes=time -e 'printf "%d\n", time * 1000'
}

watch_state() {
  ddev dev-watch status | sed -n 's/^dev-watch: \([a-z]*\).*/\1/p'
}

read_default_server "$APPROOT"
START_SERVER="$(current_server "$APPROOT")" || exit 2
START_WATCH="$(watch_state)"
[[ "$START_WATCH" == "on" || "$START_WATCH" == "off" ]] || { echo "Cannot read the dev-watch state from 'ddev dev-watch status'." >&2; exit 2; }
BASE_URL="${BENCH_BASE_URL:-$(ddev server --url)}"
DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
OUT_DIR="${BENCH_OUT_DIR:-$APPROOT/bench/results/reload/$DATE}"
COMMIT="$(git -C "$APPROOT" rev-parse --short HEAD 2>/dev/null || echo unknown)"
FRANKENPHP_VERSION="$(ddev exec frankenphp version 2>/dev/null | head -n 1 | sed -n 's/^FrankenPHP \(v[0-9.]*\).*/\1/p' || true)"
FRANKENPHP_VERSION="${FRANKENPHP_VERSION:-unknown}"
if ddev describe 2>/dev/null | grep -qi 'mutagen'; then MUTAGEN=1; else MUTAGEN=0; fi
mkdir -p "$OUT_DIR"

cp -p "$PROBE_FILE" "$BACKUP"
probe_saved=1
env_switch_begin
env_set DEBUG_ROUTES true
env_apply

# Overwrites MARKER with $1 in place, so a watcher sees one write.
write_marker() {
  M="$1" perl -0777 -pe 's/(MARKER = \x27)[^\x27]*(\x27)/$1$ENV{M}$2/' "$PROBE_FILE" > "$WORK/probe.new"
  cat "$WORK/probe.new" > "$PROBE_FILE"
}

# Sleeps a random 0 to JITTER_MS ms.
jitter() {
  local ms=$((RANDOM % (JITTER_MS + 1)))
  sleep "$(awk -v m="$ms" 'BEGIN { printf "%.3f\n", m / 1000 }')"
}

new_marker() {
  echo "m$(now_ms)x$RANDOM"
}

# Polls the probe route until it answers with $1; prints the ms since $2, or returns 1 after $3 ms (default CAP_MS).
poll_marker() {
  local marker="$1" t0="$2" cap="${3:-$CAP_MS}" body
  while true; do
    body="$(curl -ksS --max-time 5 "$BASE_URL$PROBE_PATH" 2>/dev/null || true)"
    if [[ "$body" == *"$marker"* ]]; then
      echo $(( $(now_ms) - t0 ))
      return 0
    fi
    (( $(now_ms) - t0 < cap )) || return 1
    sleep 0.05
  done
}

# Writes marker $1 and prints the ms until the container sees it, or null. One `ddev exec` polls the file every 10 ms
# inside the container and says "ready" before the write, so its start-up is not counted, then "seen".
sync_pass() {
  local marker="$1" fifo="$WORK/sync.fifo" line t0 ms=null polls=$((CAP_MS / 10))
  rm -f "$fifo"
  mkfifo "$fifo"
  ddev exec "m=$marker; echo ready; i=0; while [ \$i -lt $polls ]; do grep -qF \"\$m\" $CONTAINER_PROBE_FILE && { echo seen; exit 0; }; sleep 0.01; i=\$((i + 1)); done; echo timeout" > "$fifo" 2>/dev/null &
  SYNC_PID=$!
  exec 3< "$fifo"
  if read -r -t "$READY_TIMEOUT" -u 3 line && [[ "$line" == "ready" ]]; then
    write_marker "$marker"
    t0="$(now_ms)"
    if read -r -t $((CAP_MS / 1000 + 5)) -u 3 line && [[ "$line" == "seen" ]]; then
      ms=$(( $(now_ms) - t0 ))
    fi
  else
    write_marker "$marker"
  fi
  exec 3<&-
  kill "$SYNC_PID" 2>/dev/null || true
  wait "$SYNC_PID" 2>/dev/null || true
  SYNC_PID=""
  echo "$ms"
}

wait_ready() {
  local deadline=$((SECONDS + READY_TIMEOUT))
  until curl -ksS --max-time 5 "$BASE_URL$PROBE_PATH" 2>/dev/null | grep -q marker; do
    [[ "$SECONDS" -lt "$deadline" ]] || { echo "The server did not answer $PROBE_PATH within ${READY_TIMEOUT}s (is DEBUG_ROUTES on?)." >&2; exit 2; }
    sleep 1
  done
}

# One edit-to-reload trial: appends {ms, mutagen_ms} to $1; ms is null when the cap ($2 ms) ran out. $3 is "reloads"
# when the row serves new code by itself; then, after the Mutagen pass, the script waits until that marker is served too,
# so the next trial starts from a settled worker.
trial() {
  local out="$1" cap="$2" reloads="$3" marker t0 ms mutagen_ms="\"n/a\""
  jitter
  marker="$(new_marker)"
  write_marker "$marker"
  t0="$(now_ms)"
  ms="$(poll_marker "$marker" "$t0" "$cap" || echo null)"
  if [[ "$MUTAGEN" == "1" ]]; then
    marker="$(new_marker)"
    mutagen_ms="$(sync_pass "$marker")"
    [[ "$reloads" != "reloads" ]] || poll_marker "$marker" "$(now_ms)" >/dev/null || true
  fi
  jq -nc --argjson ms "$ms" --argjson mutagen_ms "$mutagen_ms" '{ms: $ms, mutagen_ms: $mutagen_ms}' >> "$out"
  sleep "$SETTLE_S"
}

# One timed restart: the marker is written and synced first. Appends {ms, call_ms}: ms from the start of the command to
# the new answer, call_ms the reload time the command prints itself.
restart_trial() {
  local out="$1" marker t0 ms call_ms output
  jitter
  marker="$(new_marker)"
  if [[ "$MUTAGEN" == "1" ]]; then
    sync_pass "$marker" >/dev/null
  else
    write_marker "$marker"
  fi
  t0="$(now_ms)"
  output="$(ddev artisan frankenphp:workers:restart 2>&1 || true)"
  ms="$(poll_marker "$marker" "$t0" || echo null)"
  call_ms="$(printf '%s\n' "$output" | sed -n 's/.*workers restarted in \([0-9][0-9]*\) ms.*/\1/p' | head -n 1)"
  jq -nc --argjson ms "$ms" --argjson call_ms "${call_ms:-null}" '{ms: $ms, call_ms: $call_ms}' >> "$out"
  sleep "$SETTLE_S"
}

# A worker that reaches its request limit restarts and would load the new file by itself, which a "never" row must not
# do (the 50 ms poll alone reaches 500 requests in 25 s). bench/concurrency.sh keeps the worker count and lifts the limit;
# the next `ddev server` removes its overlay.
no_recycling() {
  local workers
  workers="$(BENCH_APPROOT="$APPROOT" bash "$SCRIPT_DIR/concurrency.sh" --show | sed -n 's/^\([0-9][0-9]*\) workers.*/\1/p')"
  BENCH_APPROOT="$APPROOT" bash "$SCRIPT_DIR/concurrency.sh" "${workers:-4}" >/dev/null
}

run_row() {
  local name="$1" server="$2" watching="$3" trials="$TRIALS" i
  local trials_file="$WORK/$name.trials" restart_file="$WORK/$name.restart"
  : > "$trials_file"
  : > "$restart_file"
  echo "Row $name: server $server, watch $watching" >&2
  server_switched=1
  ddev server "$server" >/dev/null
  if [[ "$watching" == "on" ]]; then
    ddev dev-watch on >/dev/null
  elif [[ "$server" != "frankenphp" ]]; then
    no_recycling
  fi
  wait_ready
  local cap="$CAP_MS" reloads=reloads
  if [[ "$watching" == "off" && "$server" != "frankenphp" ]]; then
    trials="$NEVER_TRIALS"
    cap="$NEVER_CAP_MS"
    reloads=never
  fi
  for ((i = 1; i <= trials; i++)); do
    trial "$trials_file" "$cap" "$reloads"
  done
  if [[ "$watching" == "off" && "$server" != "frankenphp" ]]; then
    for ((i = 1; i <= TRIALS; i++)); do
      restart_trial "$restart_file"
    done
  fi
  jq -n --arg name "$name" --arg server "$server" --arg watching "$watching" --arg commit "$COMMIT" \
    --arg version "$FRANKENPHP_VERSION" --slurpfile t "$trials_file" --slurpfile r "$restart_file" '
    def nums: map(select(type == "number")) | sort;
    def median: nums as $s | ($s | length) as $n
      | if $n == 0 then null elif $n % 2 == 1 then $s[($n - 1) / 2] else ($s[$n / 2 - 1] + $s[$n / 2]) / 2 end;
    def p90: nums as $s | ($s | length) as $n | if $n == 0 then null else $s[(0.9 * $n | ceil) - 1] end;
    def max: nums | if length == 0 then null else .[-1] end;
    ($t | map(.ms)) as $ms
    | ($t | map(.mutagen_ms)) as $mg
    | ($r | map(.ms)) as $rs
    | ($r | map(.call_ms)) as $rc
    | {mode: $server, watch: ($watching == "on"), row: $name, trials: $t,
       median_ms: ($ms | median), p90_ms: ($ms | p90), max_ms: ($ms | max),
       never: ($ms | map(select(. == null)) | length),
       mutagen_median_ms: (if ($mg | nums | length) == 0 then "n/a" else ($mg | median) end),
       restart_ms: (if ($r | length) == 0 then null else {median: ($rs | median), p90: ($rs | p90), max: ($rs | max)} end),
       restart_call_ms: (if ($rc | nums | length) == 0 then null else {median: ($rc | median), p90: ($rc | p90), max: ($rc | max)} end),
       commit: $commit, frankenphp_version: $version}' > "$OUT_DIR/$name.json"
}

for row in "${ROWS[@]}"; do
  IFS='|' read -r row_name row_server row_watch <<< "$row"
  run_row "$row_name" "$row_server" "$row_watch"
done

echo "Hot reload: not available in FrankenPHP $FRANKENPHP_VERSION (no module), so it has no row." >&2
echo "$OUT_DIR"
