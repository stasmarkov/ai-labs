#!/usr/bin/env bash
## Usage: bench/export-load-matrix.sh [--quick] [--dry-run]
## Runs the 8 cells of the export matrix: nginx-fpm, apache-fpm, frankenphp and openlitespeed, each with the offload and
## the stream mode through bench/export-load.sh (20 clients, 3 repeats per cell, default worker limits).
## It ends on nginx-fpm, also when a cell fails. --quick passes --quick to every cell. --dry-run prints the commands and runs none.
## Result files: bench/results/export-load/<UTC date>/<mode>-<server>.json (--quick: bench/results/raw/) (see bench/export-load.sh).

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
  step ddev server nginx-fpm || true
}
trap restore EXIT

for server in apache-fpm frankenphp openlitespeed nginx-fpm; do
  step ddev server "$server"
  for mode in offload stream; do
    step bash "$SCRIPT_DIR/export-load.sh" --mode "$mode" --label "$server" ${quick[@]+"${quick[@]}"}
  done
done
