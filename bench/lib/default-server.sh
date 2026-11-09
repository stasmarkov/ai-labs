#!/usr/bin/env bash
## Shared restore target for the bench scripts that switch servers. Source it; it defines functions and runs nothing.
## The target is the project's default server, asked once from `ddev server --default` and kept in DEFAULT_SERVER_NAME. When the
## command answers nothing, the script exits 2 before it switches anything.
## The project root is an explicit argument (the caller's resolved root); without one it is computed from this file's own path.
## An exported APPROOT is never read.
## Provides:
##   read_default_server [root] [--dry-run]    call it BEFORE installing the restore trap and before the first switch. With --dry-run,
##                                             and only when `ddev` is not installed, the name is read from the server command file.
##   restore_default_server [root] [prefix command...]
##                                             runs `<prefix> ddev server <default>` in the root (a dry-run caller passes its `step`).
## `root` is recognised by being an existing directory; everything after it is the prefix command.

DEFAULT_SERVER_NAME=""
_DEFAULT_SERVER_LIB_ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/../.." && pwd)"

read_default_server() {
  local root="$_DEFAULT_SERVER_LIB_ROOT" dry=0 arg
  for arg in "$@"; do
    if [[ "$arg" == "--dry-run" ]]; then dry=1; elif [[ -d "$arg" ]]; then root="$arg"; fi
  done
  [[ -z "$DEFAULT_SERVER_NAME" ]] || return 0
  if [[ "$dry" == "1" ]] && ! command -v ddev >/dev/null 2>&1; then
    DEFAULT_SERVER_NAME="$(sed -n 's/^DEFAULT_SERVER=//p' "$root/.ddev/commands/host/server" 2>/dev/null | head -n 1 | tr -d '[:space:]"')"
  else
    DEFAULT_SERVER_NAME="$(cd "$root" && ddev server --default 2>/dev/null | tr -d '[:space:]')" || true
  fi
  [[ -n "$DEFAULT_SERVER_NAME" ]] || { echo "Cannot read the default server: 'ddev server --default' answered nothing." >&2; exit 2; }
}

restore_default_server() {
  local root="$_DEFAULT_SERVER_LIB_ROOT"
  if [[ $# -gt 0 && -d "$1" ]]; then root="$1"; shift; fi
  read_default_server "$root"
  (cd "$root" && "$@" ddev server "$DEFAULT_SERVER_NAME")
}
