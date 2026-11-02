#!/usr/bin/env bash
## Usage: bench/asset-fingerprint.sh [manifest]
## Prints the built file name and SHA-256 of the entries the old Blade pages load
## (resources/css/app.css and resources/js/app.js), read from the Vite manifest
## (default public/build/manifest.json). Two builds of the same sources must print
## the same lines; the AI lab entries are not listed, so adding them changes nothing.

set -euo pipefail

ROOT="$(cd "$(dirname "${BASH_SOURCE[0]}")/.." && pwd)"
MANIFEST="${1:-$ROOT/public/build/manifest.json}"

if [[ ! -f "$MANIFEST" ]]; then
  echo "No Vite manifest at $MANIFEST; run ddev npm run build first." >&2
  exit 1
fi

for entry in resources/css/app.css resources/js/app.js; do
  file="$(php -r '$m = json_decode(file_get_contents($argv[1]), true); echo $m[$argv[2]]["file"] ?? "";' "$MANIFEST" "$entry")"
  if [[ -z "$file" ]]; then
    echo "$entry is not in $MANIFEST" >&2
    exit 1
  fi
  printf '%s %s %s\n' "$entry" "$file" "$(shasum -a 256 "$(dirname "$MANIFEST")/$file" | cut -d' ' -f1)"
done
