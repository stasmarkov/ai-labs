#!/bin/sh
## Runs inside the web container (ddev exec sh bench/lib/busy-threads.sh <seconds>): once a second reads Caddy's admin
## metrics and prints "<busy> <total>" for FrankenPHP's busy and total PHP threads, or "na na" when the metric is absent.
seconds="${1:-10}"
i=0
while [ "$i" -lt "$seconds" ]; do
  curl -s --max-time 2 http://localhost:2019/metrics 2>/dev/null | awk '
    /^frankenphp_busy_threads / { busy = $2 }
    /^frankenphp_total_threads / { total = $2 }
    END { print (busy == "" ? "na" : busy), (total == "" ? "na" : total) }'
  sleep 1
  i=$((i + 1))
done
