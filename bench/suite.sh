#!/usr/bin/env bash
## Usage: bench/suite.sh [--quick]
## Runs the whole matrix on the classic servers nginx-fpm, apache-fpm, openlitespeed and frankenphp:
## ping and search, each as fair (8 workers), quick sweep (8/16/32), tuned (best sweep setting), then the
## timeout comparison. Refuses worker servers. On exit it restores default concurrency, default timeouts
## and nginx-fpm.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
SERVERS=(nginx-fpm apache-fpm openlitespeed frankenphp)
SCENARIOS=(ping search)
SWEEP=(8 16 32)
quick_args=()
[[ "${1:-}" == "--quick" ]] && quick_args=(--quick)
# One date for the whole suite, so raw files, summaries and the baseline path agree even when the
# runs cross midnight UTC. run.sh reads BENCH_DATE.
BENCH_DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
export BENCH_DATE

for worker in frankenphp-octane frankenphp-worker; do
  if [[ "$(ddev server --current)" == "$worker" ]]; then
    echo "Active server is $worker. The suite runs classic servers only." >&2
    exit 2
  fi
done

marker="$(mktemp)"

cleanup() {
  rm -f "$marker"
  bash "$SCRIPT_DIR/concurrency.sh" default || true
  bash "$SCRIPT_DIR/timeouts.sh" apply defaults || true
  ddev server nginx-fpm || true
}
trap cleanup EXIT
trap 'exit 130' INT TERM

# Writes the committed summary JSON bench/results/<date>/<server>-<scenario>-<label>.json from the raw runs
# newer than marker $1, with the nginx-fpm summary of the same table as the baseline.
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
  [[ "$server" != "nginx-fpm" ]] && baseline_args=("--baseline=$APPROOT/bench/results/$BENCH_DATE/nginx-fpm-$scenario-$label.json")
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
  bash "$SCRIPT_DIR/timeouts.sh" run
done
