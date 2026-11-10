#!/usr/bin/env bash
## The server names the lab knows. Source it; it defines variables and functions and runs nothing.
## SUPPORTED_SERVERS are the modes the lab runs; REMOVED_SERVERS are names that no longer run and get the removal message.
## Provides:
##   is_removed_server <name>        true when the name (or the first word of `ddev server --current` output) is a removed server
##   server_removed_message <name>   prints the removal message for a removed name
##   current_server [dir]            prints the active supported server, or explains on stderr and returns 2

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
