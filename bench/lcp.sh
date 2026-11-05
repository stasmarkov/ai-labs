#!/usr/bin/env bash
## Usage: bench/lcp.sh --variant <name> --path <router|direct> --path-reason <text> [--hints on|off] [lcp.mjs options]
## Runs bench/lcp.mjs for the server that is up now. With `--hints off` it sets EARLY_HINTS=false in .env for the run (the no-hints
## variant) using the shared bench .env backup and restore (bench/lib/env-switch.sh), clears the config cache, and restores both on exit.
## Every other option is passed to lcp.mjs, which also records the hints setting in the result file.
## Env: BENCH_APPROOT and the lcp.mjs variables (BENCH_DATE, PLAYWRIGHT_IMAGE, TOXIPROXY_IMAGE).

set -euo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
# shellcheck source=lib/env-switch.sh
source "$SCRIPT_DIR/lib/env-switch.sh"
trap env_switch_restore EXIT
trap 'exit 130' INT TERM

HINTS=on
ARGS=()
while [ $# -gt 0 ]; do
  case "$1" in
    --hints) HINTS="${2:-}"; shift 2 || { echo "missing value for --hints" >&2; exit 2; } ;;
    --hints=*) HINTS="${1#--hints=}"; shift ;;
    *) ARGS+=("$1"); shift ;;
  esac
done
[[ "$HINTS" == on || "$HINTS" == off ]] || { echo "--hints must be on or off (got '$HINTS')." >&2; exit 2; }

if [[ "$HINTS" == off ]]; then
  env_switch_begin
  env_set EARLY_HINTS false
  env_apply
fi

BENCH_APPROOT="$APPROOT" node "$SCRIPT_DIR/lcp.mjs" --hints "$HINTS" ${ARGS[@]+"${ARGS[@]}"}
