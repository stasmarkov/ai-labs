#!/usr/bin/env bash
## The server names the lab knows. Source it; it defines variables and functions and runs nothing.
## SUPPORTED_SERVERS are the modes the lab runs; REMOVED_SERVERS are names that no longer run and get the removal message.
## Provides:
##   is_removed_server <name>        true when the name (or the first word of `ddev server --current` output) is a removed server
##   server_removed_message <name>   prints the removal message for a removed name
##   current_server [dir]            prints the active supported server, or explains on stderr and returns 2
##   dev_watch_overlay [dir]         prints the path of the `ddev dev-watch` overlay (DEV_WATCH_OVERLAY_NAME)
##   refuse_when_dev_watch [dir]     explains on stderr and returns 2 when that overlay exists

SUPPORTED_SERVERS=(frankenphp frankenphp-worker frankenphp-octane)
REMOVED_SERVERS=(nginx-fpm apache-fpm openlitespeed)

is_removed_server() {
  local server name="${1%% *}"
  for server in "${REMOVED_SERVERS[@]}"; do
    [[ "$server" == "$name" ]] && return 0
  done
  return 1
}

server_removed_message() {
  local list
  printf -v list '%s, ' "${SUPPORTED_SERVERS[@]}"
  echo "${1%% *} was removed in SL-50; the lab runs on the FrankenPHP family: ${list%, }"
}

# Prints the server that is up now when it is one of SUPPORTED_SERVERS. When the project is not set up or still runs
# a removed server, prints why on stderr and returns 2, so a bench script never labels a result with that state.
# Usage: server="$(current_server "$APPROOT")" || exit 2
current_server() {
  local output name candidate
  output="$(cd "${1:-.}" && ddev server --current)"
  name="${output%% *}"
  for candidate in "${SUPPORTED_SERVERS[@]}"; do
    if [[ "$candidate" == "$name" && "$output" == "$name" ]]; then
      echo "$name"
      return 0
    fi
  done
  if is_removed_server "$name"; then
    server_removed_message "$name" >&2
  else
    echo "The project is not on a FrankenPHP mode ($output); run ddev setup or ddev server default." >&2
  fi
  return 2
}

# The gitignored overlay `ddev dev-watch on` writes in .ddev/ (removed by `ddev dev-watch off` and by `ddev server`).
# It is not a bench overlay: on Octane it replaces the daemon command, as bench/concurrency.sh and bench/leaks.sh do,
# so the two must never be active together. The name must sort after config.zz-server.yaml: its override_config then
# replaces the daemon the server overlay declared, instead of the server overlay adding the same daemon a second time
# (DDEV: "duplicate 'name: frankenphp-octane' in web_extra_exposed_ports").
DEV_WATCH_OVERLAY_NAME=config.zzz-dev-watch.yaml

# Prints the path of the dev-watch overlay of the project in $1 (default: the current directory).
dev_watch_overlay() {
  printf '%s/.ddev/%s\n' "${1:-.}" "$DEV_WATCH_OVERLAY_NAME"
}

# For bench scripts that write their own overlay: prints why and returns 2 when dev-watch is on.
# Usage: refuse_when_dev_watch "$APPROOT" || exit 2
refuse_when_dev_watch() {
  if [[ -e "$(dev_watch_overlay "${1:-.}")" ]]; then
    echo "dev-watch is on ($DEV_WATCH_OVERLAY_NAME): it and this bench overlay would both set the server command; run ddev dev-watch off first." >&2
    return 2
  fi
  return 0
}
