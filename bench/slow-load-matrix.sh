#!/usr/bin/env bash
## Usage: bench/slow-load-matrix.sh [--quick] [--dry-run]
## Runs the 8 cells of the slow-load matrix: nginx-fpm with 8 and 32 workers, frankenphp with a fixed 14 threads and
## with autoscale 14 to 64 threads (max-wait 5 s), each with the http and the sse workload through bench/slow-load.sh
## (3 repeats per cell, 100 users for http and 30 for sse). Each setting is applied once and both workloads run under it.
## At the end it restores the default worker limits and the nginx-fpm server, also when a cell fails.
## --quick passes --quick to every cell. --dry-run prints the commands and runs none of them.
## Result files: bench/results/slow-load/<UTC date>/<workload>-<label>.json (--quick: bench/results/raw/) (see bench/slow-load.sh).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"

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

restore() {
  step bash "$SCRIPT_DIR/concurrency.sh" default || true
  step ddev server nginx-fpm || true
}
trap restore EXIT

# server | label | concurrency.sh arguments
settings=(
  "nginx-fpm|nginx-fpm-8|8"
  "nginx-fpm|nginx-fpm-32|32"
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
