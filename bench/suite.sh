#!/usr/bin/env bash
## Usage: bench/suite.sh [--quick] [--servers <a,b,c>]
## Runs the whole matrix on the three modes frankenphp, frankenphp-worker and frankenphp-octane:
## ping and search, each as fair (8 workers), quick sweep (8/16/32), tuned (best sweep setting), then the
## timeout comparison.
## --servers runs only the listed modes. The ratio baseline is frankenphp when it is listed, otherwise the
## first listed mode; the baseline server runs first. A removed server name exits 2 with the removal
## message. The timeout comparison runs only without --servers. On exit it restores default
## concurrency, default timeouts and the default server (`ddev server --default`).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
SERVERS=("${SUPPORTED_SERVERS[@]}")
SCENARIOS=(ping search)
SWEEP=(8 16 32)
quick_args=()
servers_given=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --quick) quick_args=(--quick) ;;
    --servers)
      if [[ $# -lt 2 || -z "${2:-}" ]]; then
        echo "--servers needs a comma separated list, e.g. --servers frankenphp,frankenphp-octane" >&2
        exit 2
      fi
      servers_given=1
      IFS=',' read -r -a SERVERS <<< "$2"
      shift
      ;;
    *)
      echo "Unknown argument: $1" >&2
      exit 2
      ;;
  esac
  shift
done

in_list() {
  local item="$1" entry
  shift
  for entry in "$@"; do
    [[ "$entry" == "$item" ]] && return 0
  done
  return 1
}

# The baseline of the ratio column, and the server order with the baseline first.
BASELINE_SERVER=frankenphp
if [[ $servers_given -eq 1 ]]; then
  if [[ ${#SERVERS[@]} -eq 0 ]]; then
    echo "--servers needs a comma separated list." >&2
    exit 2
  fi
  for entry in "${SERVERS[@]}"; do
    if is_removed_server "$entry"; then
      server_removed_message "$entry" >&2
      exit 2
    fi
    if ! in_list "$entry" "${SUPPORTED_SERVERS[@]}"; then
      echo "Unknown server: $entry" >&2
      exit 2
    fi
  done
  # Baseline for ratios: frankenphp if listed, otherwise the first server.
  if ! in_list frankenphp "${SERVERS[@]}"; then
    BASELINE_SERVER="${SERVERS[0]}"
  fi
  ordered=("$BASELINE_SERVER")
  for entry in "${SERVERS[@]}"; do
    [[ "$entry" != "$BASELINE_SERVER" ]] && ordered+=("$entry")
  done
  SERVERS=("${ordered[@]}")
fi
# One date for the whole suite, so raw files, summaries and the baseline path agree even when the
# runs cross midnight UTC. run.sh reads BENCH_DATE.
BENCH_DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
export BENCH_DATE

# Never write into a results folder that already holds committed results (e.g. SL-06's dated folder).
if [[ -n "$(cd "$APPROOT" && git ls-files -- "bench/results/$BENCH_DATE" 2>/dev/null)" ]]; then
  echo "bench/results/$BENCH_DATE already holds committed results. Set BENCH_DATE to a new folder name, e.g. BENCH_DATE=$(date -u +%Y-%m-%d)-classic-vs-worker." >&2
  exit 2
fi

read_default_server "$APPROOT"
marker="$(mktemp)"

cleanup() {
  rm -f "$marker"
  bash "$SCRIPT_DIR/concurrency.sh" default || true
  bash "$SCRIPT_DIR/timeouts.sh" apply defaults || true
  restore_default_server "$APPROOT" || true
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# Writes the committed summary JSON bench/results/<date>/<server>-<scenario>-<label>.json from the raw runs
# newer than marker $1, with the baseline server's summary of the same table as the baseline.
write_summary() {
  local marker="$1" label="$2" out file
  # Arrays are expanded with ${a[@]+"${a[@]}"} so an empty array is safe under set -u on bash 3.2 (macOS).
  local raw_files=() baseline_args=()
  out="$APPROOT/bench/results/$BENCH_DATE/$server-$scenario-$label.json"
  while IFS= read -r file; do
    raw_files+=("$file")
  done < <(find "$APPROOT/bench/results/raw/$BENCH_DATE/$server" -name "$scenario-$label-*.json" -newer "$marker" | sort)
  if [[ ${#raw_files[@]} -eq 0 ]]; then
    echo "No raw results for $server $scenario $label; run.sh failed?" >&2
    return 1
  fi
  if [[ "$server" != "$BASELINE_SERVER" ]]; then
    baseline_args=("--baseline=$APPROOT/bench/results/$BENCH_DATE/$BASELINE_SERVER-$scenario-$label.json")
  else
    baseline_args=(--self-baseline)
  fi
  php "$SCRIPT_DIR/summary.php" "${raw_files[@]}" ${baseline_args[@]+"${baseline_args[@]}"} --write="$out" || [[ $? -eq 3 ]]
}

for server in "${SERVERS[@]}"; do
  ddev server "$server"
  for scenario in "${SCENARIOS[@]}"; do
    bash "$SCRIPT_DIR/concurrency.sh" 8
    touch "$marker"
    bash "$SCRIPT_DIR/run.sh" "$scenario" --label fair ${quick_args[@]+"${quick_args[@]}"}
    write_summary "$marker" fair

    sweep_files=()
    for setting in "${SWEEP[@]}"; do
      bash "$SCRIPT_DIR/concurrency.sh" "$setting"
      BENCH_WARMUP=10 BENCH_MEASURE=30 BENCH_REPEATS=1 BENCH_SETTLE=10 \
        bash "$SCRIPT_DIR/run.sh" "$scenario" --label "sweep-$setting"
      sweep_files+=("$(ls -t "$APPROOT/bench/results/raw/$BENCH_DATE/$server/$scenario-sweep-$setting-"*.json | head -1)")
    done
    best="$(bash "$SCRIPT_DIR/pick-tuned.sh" "${sweep_files[@]}")"
    bash "$SCRIPT_DIR/concurrency.sh" "$best"
    touch "$marker"
    bash "$SCRIPT_DIR/run.sh" "$scenario" --label "tuned" ${quick_args[@]+"${quick_args[@]}"}
    write_summary "$marker" tuned
  done
  bash "$SCRIPT_DIR/concurrency.sh" default
  [[ $servers_given -eq 1 ]] || bash "$SCRIPT_DIR/timeouts.sh" run
done
