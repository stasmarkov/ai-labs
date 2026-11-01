#!/usr/bin/env bash
## Usage: bench/pick-tuned.sh <sweep result file>...
## Prints the setting with the best sweep result: highest RPS with error % <= 1, the lower setting
## when RPS is within 3 %, the fewest errors when every setting exceeds 1 %.

set -Eeuo pipefail

jq -s -r '
  map({setting: (.label | ltrimstr("sweep-") | tonumber), rps, error_percent}) as $runs
  | ($runs | map(select(.error_percent <= 1))) as $valid
  | if ($valid | length) == 0 then ($runs | min_by(.error_percent) | .setting)
    else ($valid | map(.rps) | max) as $best
      | ($valid | map(select(.rps >= $best * 0.97)) | min_by(.setting) | .setting)
    end' "$@"
