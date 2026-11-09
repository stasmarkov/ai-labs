#!/usr/bin/env bash
## Usage: bench/http3-matrix.sh [--runs 5] [--only h2-loss0,h3-loss0,h2-loss2,h3-loss2] [--dry-run]
## Runs the four netem variants of the HTTP/3 comparison ({h2, h3} x {loss 0, loss 2 %}) on frankenphp with bench/lcp.mjs --net netem.
## It switches to frankenphp itself (`ddev server frankenphp`) and an EXIT trap always puts the default server back, also after a failure or a
## Ctrl-C. Each variant writes bench/results/http3/<UTC date>/lcp-<variant>.json; the date is computed once, so all four files share a folder
## (BENCH_DATE overrides it). A variant that fails (loss check outside its window, fewer than --runs valid runs) stops the matrix with its
## exit code and writes nothing; rerun that variant with --only. --dry-run prints the commands and runs none.
## The curl proof is a separate script: bench/http3.sh (and `bench/http3.sh --server nginx-fpm`).

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/default-server.sh
source "$SCRIPT_DIR/lib/default-server.sh"

runs=5
only="h2-loss0,h3-loss0,h2-loss2,h3-loss2"
dry=0
while [[ $# -gt 0 ]]; do
  case "$1" in
    --runs) runs="${2:-}"; shift 2 || { echo "missing value for --runs" >&2; exit 2; } ;;
    --only) only="${2:-}"; shift 2 || { echo "missing value for --only" >&2; exit 2; } ;;
    --dry-run) dry=1; shift ;;
    *) echo "Usage: $0 [--runs 5] [--only h2-loss0,h3-loss0,h2-loss2,h3-loss2] [--dry-run]" >&2; exit 2 ;;
  esac
done
[[ "$runs" =~ ^[0-9]+$ && "$runs" -ge 1 ]] || { echo "--runs must be a positive integer" >&2; exit 2; }

variants=()
IFS=',' read -r -a requested <<< "$only"
for variant in "${requested[@]}"; do
  case "$variant" in
    h2-loss0|h3-loss0|h2-loss2|h3-loss2) variants+=("$variant") ;;
    *) echo "unknown variant '$variant' (use h2-loss0, h3-loss0, h2-loss2, h3-loss2)" >&2; exit 2 ;;
  esac
done

export BENCH_DATE="${BENCH_DATE:-$(date -u +%Y-%m-%d)}"
export BENCH_APPROOT="$APPROOT"

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
trap 'exit 130' INT TERM

(cd "$APPROOT" && step ddev server frankenphp)
for variant in "${variants[@]}"; do
  protocol="${variant%%-*}"
  loss="${variant##*loss}"
  step node "$SCRIPT_DIR/lcp.mjs" --variant "$variant" --net netem --protocol "$protocol" --loss "$loss" --runs "$runs" \
    --path direct --path-reason "netem needs the direct path: the router has no HTTP/3"
done
