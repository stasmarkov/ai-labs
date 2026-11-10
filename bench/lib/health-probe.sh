#!/usr/bin/env bash
## Shared health probe for the bench scripts that measure a light request next to a heavy load (slow-load.sh, export-load.sh).
## Source it; it defines functions and constants and runs nothing.
## A caller sets before use: SCRIPT_DIR (the bench directory), BASE_URL, HOST_NAME, OHA_IMAGE and, for generate_probe, PROBE_NAME.
## Provides: PROBE_QPS, PROBE_TIMEOUT, PROBE_CONNECTIONS, lookup_router (sets router_ip), image_digest, docker_run,
## generate_probe (GET /healthz at a steady rate) and parse_oha (oha JSON to latency, request rate and failures).

PROBE_QPS=5
PROBE_TIMEOUT=10
PROBE_CONNECTIONS=$((PROBE_QPS * PROBE_TIMEOUT))

router_ip=""
lookup_router() {
  router_ip="$(docker inspect -f '{{range .NetworkSettings.Networks}}{{.IPAddress}}{{end}}' ddev-router)"
  [[ -n "$router_ip" ]] || { echo "Cannot find the ddev-router IP." >&2; exit 1; }
}

image_digest() {
  local image="$1"
  if [[ "$image" == ai-lab-oha:* ]]; then
    docker image inspect "$image" >/dev/null 2>&1 || docker build -q -t "$image" "$SCRIPT_DIR/oha" >/dev/null
    docker image inspect --format '{{.Id}}' "$image" 2>/dev/null || true
  else
    docker pull "$image" >/dev/null 2>&1 || true
    docker image inspect --format '{{index .RepoDigests 0}}' "$image" 2>/dev/null || true
  fi
}

docker_run() {
  local name="$1"
  shift
  docker run --rm --name "$name" --network ddev_default --cpus=2 --add-host "$HOST_NAME:$router_ip" "$@"
}

# The light probe for $1 seconds; raw oha JSON goes to $2.
generate_probe() {
  local seconds="$1" target="$2"
  docker_run "$PROBE_NAME" "$OHA_IMAGE" -c "$PROBE_CONNECTIONS" -q "$PROBE_QPS" --latency-correction -z "${seconds}s" -w -t "${PROBE_TIMEOUT}s" --no-tui --insecure \
    --output-format json "$BASE_URL/healthz" > "$target"
}

# Reads oha JSON on stdin: latency, request rate and the failures. $1 is "probe" or "load".
# oha's percentiles cover answered requests only. For the probe, p95_ms is computed over all sent probes: an unanswered
# one (timeout or transport error) counts as the timeout value, and the answered part is read off oha's percentile
# points by linear interpolation. p95_answered_ms is oha's own p95, null when nothing answered (starved: true);
# p50_ms and p99_ms are over answered requests and null when starved. For the load all three are oha's.
# breakdown: 502, 504, other 5xx, other non-2xx (3xx, 4xx and transport errors that are not timeouts) and timeouts.
# errors = non-2xx + timeouts; requests = sent; error_percent = errors / sent.
parse_oha() {
  jq --argjson timeout "$PROBE_TIMEOUT" --arg kind "${1:-load}" '
    def codes: (.statusCodeDistribution // {}) | to_entries | map({code: (.key | tonumber), n: .value});
    def sum: add // 0;
    (.latencyPercentiles // {}) as $lp
    | def pts: [[0.1, "p10"], [0.25, "p25"], [0.5, "p50"], [0.75, "p75"], [0.9, "p90"], [0.95, "p95"], [0.99, "p99"], [0.999, "p99.9"], [0.9999, "p99.99"]]
        | map({q: .[0], v: $lp[.[1]]}) | map(select(.v != null));
      def at($q): pts as $p
        | if $q <= $p[0].q then $p[0].v
          elif $q >= $p[-1].q then $p[-1].v
          else ([range(0; ($p | length) - 1)] | map(select($p[.].q <= $q and $q < $p[. + 1].q)) | .[0]) as $i
            | $p[$i].v + ($p[$i + 1].v - $p[$i].v) * (($q - $p[$i].q) / ($p[$i + 1].q - $p[$i].q))
          end;
      def ms: if . == null then null else . * 1000 end;
      (codes | map(select(.code == 502) | .n) | sum) as $b502
    | (codes | map(select(.code == 504) | .n) | sum) as $b504
    | (codes | map(select(.code >= 500 and .code != 502 and .code != 504) | .n) | sum) as $other5xx
    | (codes | map(select(.code < 200 or (.code >= 300 and .code < 500)) | .n) | sum) as $nonOkLow
    | ((.errorDistribution // {}) | to_entries) as $errs
    | ($errs | map(select(.key | test("timeout|deadline"; "i")) | .value) | sum) as $timeouts
    | ($errs | map(select(.key | test("timeout|deadline"; "i") | not) | .value) | sum) as $transport
    | (codes | map(.n) | sum) as $answered
    | ($b502 + $b504 + $other5xx + $nonOkLow + $transport + $timeouts) as $errors
    | ($answered + $transport + $timeouts) as $sent
    | ($lp.p95 == null) as $starved
    | (if $sent == 0 then null
       elif $starved or ($sent * 0.95 > $answered) then $timeout
       else at($sent * 0.95 / $answered) end) as $allP95
    | {
        rps: .summary.requestsPerSec,
        p50_ms: ($lp.p50 | ms),
        p95_ms: (if $kind == "probe" then ($allP95 | ms) else ($lp.p95 | ms) end),
        p99_ms: ($lp.p99 | ms),
        starved: $starved,
        breakdown: {"502": $b502, "504": $b504, other_5xx: $other5xx, other_non_2xx: ($nonOkLow + $transport), timeouts: $timeouts},
        errors: $errors,
        requests: $sent,
        error_percent: (if $sent == 0 then 0 else $errors / $sent * 100 end)
      }
      + (if $kind == "probe" then {p95_answered_ms: ($lp.p95 | ms)} else {} end)'
}
