#!/usr/bin/env bash
## Usage: bench/rename-project.sh
## One-off, kept as documentation: renames the DDEV project from `interview` to `ai-lab` and carries the database over.
## 1. preflight: the project is running and the tree is clean (changes under .ai/session are allowed)
## 2. ddev export-db to .ai/tmp/pre-rename.sql.gz (must be non-empty), row counts of the key tables and the JSON of
##    /listings?per_page=5&page=1 and /listings/1 saved under .ai/tmp/pre-rename/, hashes of the Mercure keys
## 3. ddev stop, change only the `name:` line of .ddev/config.yaml, ddev start, ddev import-db
## 4. counts compared per table, samples compared after replacing the old host with the new one, keys compared
## 5. ddev server default, ddev setup
## 6. prints the command that deletes the old project; it is never run here
## If the name is already ai-lab and .ai/tmp/pre-rename/counts.json exists, it only verifies (steps 4 and 6).
## Env: RENAME_FROM (default interview), RENAME_TO (default ai-lab).

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
cd "$ROOT"

FROM="${RENAME_FROM:-interview}"
TO="${RENAME_TO:-ai-lab}"
CONFIG=".ddev/config.yaml"
TMP=".ai/tmp"
PRE="$TMP/pre-rename"
DUMP="$TMP/pre-rename.sql.gz"
TABLES="listings listing_documents docs_documents docs_chunks ai_runs docs_answer_cache exports"
LIST_PATH="/listings?per_page=5&page=1"
DETAIL_PATH="/listings/1"

fail() {
  echo "rename-project: $*" >&2
  exit 1
}

current_name() {
  sed -n 's/^name: *//p' "$CONFIG" | head -1
}

collect_counts() {
  local php="echo json_encode(["
  local table
  for table in $TABLES; do
    php+="'$table'=>DB::table('$table')->count(),"
  done
  php+="]);"
  ddev artisan tinker --execute "$php" | tail -1 | tr -d ' \r'
}

count_of() {
  local json="$1" table="$2"
  echo "$json" | tr -d '{}"' | tr ',' '\n' | sed -n "s/^$table://p"
}

key_hashes() {
  {
    [ -f .ddev/.env.web ] && shasum .ddev/.env.web
    [ -f .env ] && grep -E '^MERCURE' .env | shasum
  } || true
}

compare_counts() {
  local before after table
  before="$(cat "$PRE/counts.json")"
  after="$(collect_counts)"
  for table in $TABLES; do
    if [ "$(count_of "$before" "$table")" != "$(count_of "$after" "$table")" ]; then
      fail "count mismatch for $table: before $(count_of "$before" "$table"), after $(count_of "$after" "$table")"
    fi
  done
  echo "counts equal: $TABLES"
}

compare_samples() {
  local old_host="$1" new_host="$2" name path
  for name in list detail; do
    path="$LIST_PATH"
    [ "$name" = "detail" ] && path="$DETAIL_PATH"
    # data-price-rendered-ms is the render time of the detail page (SL-13 live price), different on every request.
    curl -sk "https://$new_host$path" | sed 's/data-price-rendered-ms="[0-9]*"/data-price-rendered-ms=""/' > "$PRE/$name.after.json"
    sed -e "s/$old_host/$new_host/g" -e 's/data-price-rendered-ms="[0-9]*"/data-price-rendered-ms=""/' "$PRE/$name.before.json" > "$PRE/$name.before.normalized.json"
    cmp -s "$PRE/$name.before.normalized.json" "$PRE/$name.after.json" || fail "sample $name differs after the rename ($path)"
  done
  echo "samples equal: $LIST_PATH and $DETAIL_PATH"
}

compare_keys() {
  [ "$(key_hashes)" = "$(cat "$PRE/keys.sha")" ] || fail "the Mercure keys or .ddev/.env.web changed"
  echo "keys unchanged"
}

print_delete() {
  echo "The old project's volumes are untouched (the name is only unlisted). When you are sure, delete them yourself:"
  echo "  docker volume ls --format '{{.Name}}' | grep -E '(^|ddev-)${FROM}[-_]'"
  echo "  docker volume rm ${FROM}-postgres ddev-${FROM}_redis ${FROM}_project_mutagen"
}

if [ "$(current_name)" = "$TO" ] && [ -f "$PRE/counts.json" ]; then
  FROM="$(cat "$PRE/old-name")"
  echo "already renamed to $TO, verifying only"
  compare_counts
  compare_samples "$FROM.ddev.site" "$TO.ddev.site"
  compare_keys
  print_delete
  exit 0
fi

[ "$(current_name)" = "$FROM" ] || fail "$CONFIG has name '$(current_name)', expected '$FROM'"
ddev describe -j 2>/dev/null | grep -q '"status": *"running"' || fail "the project is not running; run ddev start first"
if [ -n "$(git status --porcelain -- . ':!.ai/session')" ]; then
  fail "the tree must be clean (only .ai/session may change); commit or stash first"
fi

mkdir -p "$PRE"
ddev export-db --gzip --file="$DUMP"
[ -s "$DUMP" ] || fail "the export $DUMP is empty"
echo "exported the database to $DUMP"

collect_counts > "$PRE/counts.json"
echo "$FROM" > "$PRE/old-name"
curl -sk "https://$FROM.ddev.site$LIST_PATH" > "$PRE/list.before.json"
curl -sk "https://$FROM.ddev.site$DETAIL_PATH" > "$PRE/detail.before.json"
key_hashes > "$PRE/keys.sha"
echo "recorded counts and samples in $PRE"

rollback() {
  echo "rename-project: failed after the name change." >&2
  echo "Rollback: set 'name: $FROM' in $CONFIG and run ddev start; the old volumes are intact." >&2
}
trap 'status=$?; [ $status -ne 0 ] && rollback; exit $status' EXIT

# --unlist frees the folder from the old name; DDEV refuses to start a renamed project otherwise. Volumes stay.
ddev stop --unlist "$FROM"
sed -i.bak "s/^name: *$FROM\$/name: $TO/" "$CONFIG" && rm -f "$CONFIG.bak"
ddev start
ddev import-db --file="$DUMP"

compare_counts
compare_samples "$FROM.ddev.site" "$TO.ddev.site"
compare_keys

trap - EXIT
ddev server default
ddev setup
print_delete
