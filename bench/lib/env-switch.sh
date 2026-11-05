#!/usr/bin/env bash
## Shared .env backup and restore for the bench scripts that need a temporary setting (compression.sh, lcp.sh), the same
## convention as slow-load.sh and export-load.sh: copy .env to .env.bench-backup, change keys, and on exit copy it back and
## clear the config cache. Source it; it defines functions and runs nothing.
## A caller sets before use: APPROOT. Call `env_switch_begin` once, `env_set KEY VALUE` for each key, and
## `env_switch_restore` from the EXIT trap (it does nothing when env_switch_begin was never reached).

ENV_FILE="${APPROOT:?}/.env"
ENV_BACKUP="$APPROOT/.env.bench-backup"
ENV_SWITCHED=0

env_switch_begin() {
  if [[ -e "$ENV_BACKUP" ]]; then
    echo "Stale $ENV_BACKUP exists: a previous run did not restore .env. Restore it by hand first." >&2
    exit 2
  fi
  [[ -f "$ENV_FILE" ]] || { echo "No .env at $ENV_FILE" >&2; exit 2; }
  cp -p "$ENV_FILE" "$ENV_BACKUP"
  ENV_SWITCHED=1
}

env_set() {
  local key="$1" value="$2" tmp
  tmp="$(mktemp)"
  { grep -v "^${key}=" "$ENV_FILE" || true; echo "${key}=${value}"; } > "$tmp"
  cat "$tmp" > "$ENV_FILE"
  rm -f "$tmp"
}

env_apply() {
  (cd "$APPROOT" && ddev artisan config:clear >/dev/null)
}

env_switch_restore() {
  if [[ "$ENV_SWITCHED" == "1" && -f "$ENV_BACKUP" ]]; then
    cp -p "$ENV_BACKUP" "$ENV_FILE"
    rm -f "$ENV_BACKUP"
    (cd "$APPROOT" && ddev artisan config:clear >/dev/null 2>&1) || true
    ENV_SWITCHED=0
  fi
}
