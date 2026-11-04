#!/bin/sh
## Runs inside the client container of bench/export-load.sh: N clients, each a closed loop of one rate-limited download after another.
## Env: URL, TAG (X-Bench-Run), CLIENTS, DURATION (seconds to keep starting downloads; started ones finish), RATE (curl --limit-rate),
##      MAX_TIME (seconds per download), EXPECT_BYTES, EXPECT_SHA256, OUT (directory for client-<n>.jsonl).
## Every download is checked by size; the first one of each client is also checked by sha256 of the body.
## One JSON line per download: client, seq, status, bytes, seconds, size_ok, sha_checked, sha_ok, ok.

client() {
  n="$1"
  seq=0
  end=$(( $(date +%s) + DURATION ))
  meta="$OUT/meta-$n"
  while [ "$(date +%s)" -lt "$end" ]; do
    seq=$((seq + 1))
    rm -f "$meta"
    sha_checked=false
    sha_ok=true
    if [ "$seq" -eq 1 ]; then
      sha_checked=true
      got=$(curl -ksS --limit-rate "$RATE" --max-time "$MAX_TIME" -H "X-Bench-Run: $TAG" -o - \
        -w '%output{'"$meta"'}%{http_code} %{size_download} %{time_total}' "$URL" 2>/dev/null | sha256sum | cut -d' ' -f1)
      [ "$got" = "$EXPECT_SHA256" ] || sha_ok=false
    else
      curl -ksS --limit-rate "$RATE" --max-time "$MAX_TIME" -H "X-Bench-Run: $TAG" -o /dev/null \
        -w '%output{'"$meta"'}%{http_code} %{size_download} %{time_total}' "$URL" >/dev/null 2>&1
    fi
    status=0
    bytes=0
    seconds=0
    [ -s "$meta" ] && read -r status bytes seconds < "$meta"
    size_ok=false
    [ "$bytes" = "$EXPECT_BYTES" ] && size_ok=true
    ok=false
    [ "$status" = "200" ] && [ "$size_ok" = true ] && [ "$sha_ok" = true ] && ok=true
    printf '{"client":%s,"seq":%s,"status":%s,"bytes":%s,"seconds":%s,"size_ok":%s,"sha_checked":%s,"sha_ok":%s,"ok":%s}\n' \
      "$n" "$seq" "$status" "$bytes" "$seconds" "$size_ok" "$sha_checked" "$sha_ok" "$ok" >> "$OUT/client-$n.jsonl"
    rm -f "$meta"
  done
}

i=1
while [ "$i" -le "$CLIENTS" ]; do
  client "$i" &
  i=$((i + 1))
done
wait
