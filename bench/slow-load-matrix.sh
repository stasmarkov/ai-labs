#!/usr/bin/env bash
## Usage: bench/slow-load-matrix.sh [--quick] [--dry-run]
## Runs the 4 cells of the slow-load matrix: frankenphp with a fixed 14 threads and
## with autoscale 14 to 64 threads (max-wait 5 s), each with the http and the sse workload through bench/slow-load.sh
## (3 repeats per cell, 100 users for http and 30 for sse). Each setting is applied once and both workloads run under it.
## At the end it restores the default worker limits and the default server (`ddev server --default`), also when a cell fails.
## --quick passes --quick to every cell. --dry-run prints the commands and runs none of them.
## Result files: bench/results/slow-load/<UTC date>/<workload>-<label>.json (--quick: bench/results/raw/) (see bench/slow-load.sh).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"

quick=()
dry=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --quick) quick=(--quick); shift ;;
    --dry-run) dry=1; shift ;;
    *) echo "Usage: $0 [--quick] [--dry-run]" >&2; exit 2 ;;
  esac
done

step() {
  if [[ "$dry" == "1" ]]; then
    echo "$*"
  else
    "$@"
  fi
}

# Read the default before the trap exists: a failed read exits 2 without restarting DDEV or restoring anything.
read_default_server "$APPROOT" $([[ "$dry" == "1" ]] && echo --dry-run)

restore() {
  step bash "$SCRIPT_DIR/concurrency.sh" default || true
  restore_default_server "$APPROOT" step || true
}
trap restore EXIT

# server | label | concurrency.sh arguments
settings=(
  "frankenphp|frankenphp-14|14"
  "frankenphp|frankenphp-autoscale-14-64|autoscale --threads 14 --max-threads 64 --max-wait 5"
)

for setting in "${settings[@]}"; do
  IFS='|' read -r server label args <<< "$setting"
  step ddev server "$server"
  # shellcheck disable=SC2086
  step bash "$SCRIPT_DIR/concurrency.sh" $args
  for workload in http sse; do
    users=100
    [[ "$workload" == "sse" ]] && users=30
    step bash "$SCRIPT_DIR/slow-load.sh" --workload "$workload" --users "$users" --label "$label" ${quick[@]+"${quick[@]}"}
  done
done
