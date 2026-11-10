#!/usr/bin/env bash
## Usage: bench/concurrency.sh <n> [--max-requests <m>]
##        bench/concurrency.sh default
##        bench/concurrency.sh autoscale --threads <n> --max-threads <m> [--max-wait <seconds>]
##        bench/concurrency.sh --show
## Sets the PHP worker limit of the active server to n and restarts DDEV; default restores today's values
## (FrankenPHP 2 x CPUs).
## n means: FrankenPHP num_threads n and max_threads n (FRANKENPHP_CONFIG in a config.zz-*.yaml overlay).
## frankenphp-octane: the daemon command of the stash, copied to config.zzz-bench-concurrency.yaml with
## --workers=n and --max-requests=m and override_config: true, so it replaces the active daemon. m defaults to
## 1000000, "no recycling": Octane's worker script treats 0 as "stop at once". frankenphp-worker: the stash
## Caddyfile reads `num {$WORKER_NUM:4}` and `MAX_REQUESTS {$WORKER_MAX_REQUESTS:500}`, so this script only writes
## those two variables to config.zzz-bench-concurrency.yaml (web_environment); the activated Caddyfile is never edited.
## `ddev server` removes every bench overlay when it switches, so settings never leak to another server.
## --max-requests <m> is accepted for these two servers only.
## All overlays are gitignored and written under .ddev/; .ddev/config.yaml is never touched.
## autoscale (frankenphp only): num_threads n, max_threads m (m > n) and max_wait_time s seconds (default 5) in the
## same FRANKENPHP_CONFIG overlay, so FrankenPHP starts n threads and adds up to m under load; other servers get an error.
## A removed server name in the active overlay is refused with the removal message (exit 2).
## --show prints "<n> workers" or "default" ("autoscale <n> to <m> threads, max-wait <s>s" after autoscale); on frankenphp-octane and frankenphp-worker it prints
## "<n> workers, max-requests <m>" read from the active configuration. Env: BENCH_APPROOT.

set -Eeuo pipefail

SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
APPROOT="${BENCH_APPROOT:-$(cd "$SCRIPT_DIR/.." && pwd)}"
DDEV_DIR="$APPROOT/.ddev"
# shellcheck source=lib/servers.sh
source "$SCRIPT_DIR/lib/servers.sh"
FRANKEN_OVERLAY="$DDEV_DIR/config.zz-bench-concurrency.yaml"
OCTANE_OVERLAY="$DDEV_DIR/config.zzz-bench-concurrency.yaml"
OCTANE_ACTIVE="$DDEV_DIR/config.zz-server.yaml"
OCTANE_SOURCE="$DDEV_DIR/servers/frankenphp-octane/config.frankenphp-octane.yaml"
CADDYFILE="$DDEV_DIR/web-build/Caddyfile.frankenphp"
CADDYFILE_SOURCE="$DDEV_DIR/servers/frankenphp-worker/web-build/Caddyfile.frankenphp"
NO_RECYCLING=1000000

die2() {
  echo "$1" >&2
  exit 2
}

refuse_removed_server() {
  if is_removed_server "$1"; then
    die2 "$(server_removed_message "$1")"
  fi
}

usage() {
  die2 "Usage: $0 <n> [--max-requests <m>]
       $0 default
       $0 autoscale --threads <n> --max-threads <m> [--max-wait <seconds>]
       $0 --show
n, m and seconds are positive integers; --max-requests only on frankenphp-octane and frankenphp-worker;
autoscale only on frankenphp, with m greater than n."
}

remove_overlays() {
  rm -rf "$FRANKEN_OVERLAY" "$OCTANE_OVERLAY"
}

write_octane() {
  {
    echo "override_config: true"
    sed -E "s/--workers=[0-9]+/--workers=$1/; s/--max-requests=[0-9]+/--max-requests=$2/" "$OCTANE_SOURCE"
  } > "$OCTANE_OVERLAY"
}

write_worker() {
  printf '# bench: %s workers, max-requests %s\nweb_environment:\n  - WORKER_NUM=%s\n  - WORKER_MAX_REQUESTS=%s\n' "$1" "$2" "$1" "$2" > "$OCTANE_OVERLAY"
}

show_worker_server() {
  local file
  case "$1" in
    frankenphp-octane)
      file="$OCTANE_OVERLAY"
      [[ -f "$file" ]] || file="$OCTANE_ACTIVE"
      echo "$(sed -n 's/.*--workers=\([0-9]*\).*/\1/p' "$file" | head -n 1) workers, max-requests $(sed -n 's/.*--max-requests=\([0-9]*\).*/\1/p' "$file" | head -n 1)"
      ;;
    frankenphp-worker)
      if [[ -f "$OCTANE_OVERLAY" ]]; then
        echo "$(sed -n 's/^  - WORKER_NUM=//p' "$OCTANE_OVERLAY") workers, max-requests $(sed -n 's/^  - WORKER_MAX_REQUESTS=//p' "$OCTANE_OVERLAY")"
      else
        # Defaults written in the Caddyfile placeholders, e.g. {$WORKER_NUM:4}.
        echo "$(sed -n 's/.*{\$WORKER_NUM:\([0-9]*\)}.*/\1/p' "$CADDYFILE_SOURCE" | head -n 1) workers, max-requests $(sed -n 's/.*{\$WORKER_MAX_REQUESTS:\([0-9]*\)}.*/\1/p' "$CADDYFILE_SOURCE" | head -n 1)"
      fi
      ;;
  esac
}

write_frankenphp() {
  printf '# bench: %s workers\nweb_environment:\n  - "FRANKENPHP_CONFIG=num_threads %s\\nmax_threads %s"\n' "$1" "$1" "$1" > "$FRANKEN_OVERLAY"
}

show() {
  local file server
  server="$(current_server "$APPROOT")" || exit 2
  if [[ "$server" == "frankenphp-octane" || "$server" == "frankenphp-worker" ]]; then
    show_worker_server "$server"
    return
  fi
  for file in "$FRANKEN_OVERLAY"; do
    if [[ -f "$file" ]]; then
      sed -n 's/^# bench: //p' "$file" | head -n 1
      return
    fi
  done
  echo default
}

write_autoscale() {
  printf '# bench: autoscale %s to %s threads, max-wait %ss\nweb_environment:\n  - "FRANKENPHP_CONFIG=num_threads %s\\nmax_threads %s\\nmax_wait_time %ss"\n' "$1" "$2" "$3" "$1" "$2" "$3" > "$FRANKEN_OVERLAY"
}

if [[ "${1:-}" == "autoscale" ]]; then
  shift
  threads="" max_threads="" max_wait=5
  while [[ $# -gt 0 ]]; do
    [[ $# -ge 2 ]] || usage
    case "$1" in
      --threads) threads="$2" ;;
      --max-threads) max_threads="$2" ;;
      --max-wait) max_wait="$2" ;;
      *) usage ;;
    esac
    shift 2
  done
  for number in "$threads" "$max_threads" "$max_wait"; do
    [[ "$number" =~ ^[1-9][0-9]*$ ]] || usage
  done
  (( max_threads > threads )) || usage
  server="$(current_server "$APPROOT")" || exit 2
  refuse_removed_server "$server"
  if [[ "$server" != "frankenphp" ]]; then
    die2 "autoscale is available on frankenphp only (active server: $server)"
  fi
  remove_overlays
  write_autoscale "$threads" "$max_threads" "$max_wait"
  (cd "$APPROOT" && ddev restart >/dev/null)
  echo "$server: autoscale $threads to $max_threads threads, max-wait ${max_wait}s"
  exit 0
fi

max_requests=""
case "${1:-}" in
  --show) show; exit 0 ;;
  default) value="" ;;
  *)
    [[ "${1:-}" =~ ^[1-9][0-9]*$ ]] || usage
    value="$1"
    ;;
esac
if [[ "${2:-}" == "--max-requests" ]]; then
  [[ -n "$value" && "${3:-}" =~ ^[1-9][0-9]*$ && $# -eq 3 ]] || usage
  max_requests="$3"
elif [[ $# -gt 1 ]]; then
  usage
fi

server="$(current_server "$APPROOT")" || exit 2
refuse_removed_server "$server"
if [[ -n "$max_requests" && "$server" != "frankenphp-octane" && "$server" != "frankenphp-worker" ]]; then
  usage
fi
remove_overlays
# Older harness versions edited the activated Caddyfile in place; put the stash copy back on default.
if [[ "$server" == "frankenphp-worker" && -z "$value" ]]; then
  mkdir -p "$(dirname "$CADDYFILE")"
  cp "$CADDYFILE_SOURCE" "$CADDYFILE"
fi
if [[ -n "$value" ]]; then
  case "$server" in
    frankenphp) write_frankenphp "$value" ;;
    frankenphp-octane) write_octane "$value" "${max_requests:-$NO_RECYCLING}" ;;
    frankenphp-worker) write_worker "$value" "${max_requests:-$NO_RECYCLING}" ;;
    *) echo "Unknown server '$server'" >&2; exit 1 ;;
  esac
fi
(cd "$APPROOT" && ddev restart >/dev/null)
echo "$server: ${value:-default}"
