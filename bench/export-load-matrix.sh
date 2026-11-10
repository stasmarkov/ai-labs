#!/usr/bin/env bash
## Usage: bench/export-load-matrix.sh [--quick] [--dry-run]
## Runs the 4 cells of the export matrix: frankenphp with the offload and the stream mode, frankenphp-worker and
## frankenphp-octane with the stream mode only (no X-Sendfile intercept there), through bench/export-load.sh (20 clients,
## 3 repeats per cell, default worker limits).
## It ends on the default server (`ddev server --default`), also when a cell fails. --quick passes --quick to every cell. --dry-run prints the commands and runs none.
## Result files: bench/results/export-load/<UTC date>/<mode>-<server>.json (--quick: bench/results/raw/) (see bench/export-load.sh).

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

# Read the default before the trap exists: a failed read exits 2 without restoring anything.
read_default_server "$APPROOT" $([[ "$dry" == "1" ]] && echo --dry-run)

restore() {
  restore_default_server "$APPROOT" step || true
}
trap restore EXIT

# Only classic FrankenPHP has the X-Sendfile intercept; on worker and Octane the app falls back to streaming, so an
# "offload" cell there would be a stream run under the wrong label. They run the stream mode only.
for server in frankenphp frankenphp-worker frankenphp-octane; do
  step ddev server "$server"
  modes=(stream)
  [[ "$server" == "frankenphp" ]] && modes=(offload stream)
  for mode in "${modes[@]}"; do
    step bash "$SCRIPT_DIR/export-load.sh" --mode "$mode" --label "$server" ${quick[@]+"${quick[@]}"}
  done
done
