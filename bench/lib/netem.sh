#!/usr/bin/env bash
## Network shaping for the bench clients: a NET_ADMIN sidecar with `tc` joins the client container's network namespace
## (`--network container:<client>`), so every process in that container, whatever the protocol, sees the same link.
## Egress is shaped with netem on eth0; ingress is redirected to an ifb device and shaped there with the same delay and loss.
## Source it (defines functions, runs nothing) or run it: bench/lib/netem.sh apply|verify|drops|clear|profile <client-container>,
## web-offloads off|restore <web-container>, or defaults (the profile with the default values, no docker call).
## Provides: netem_apply, netem_verify, netem_clear, netem_profile_json and the NETEM_* result variables.
## netem_apply shapes and then verifies the measured RTT. When shaping is impossible (no NET_ADMIN, no tc, no ifb) or the RTT is
## off, shaping is removed again and NETEM_APPLIED=false with NETEM_REASON set; that is a fallback, not an error (exit 0).
## When ifb cannot be created, apply stops with exit 2 and a message; --netem-fallback egress-only (before the command, or NETEM_FALLBACK)
## opts in to egress shaping only: delay 150ms, loss on egress only, no rate, mode egress-only.
## Per-packet loss: netem drops one skb at a time, and with TSO/GSO/GRO (and UDP GSO for QUIC) a skb can carry many wire packets, so a
## "2 %" drop would hit whole bursts and differ between TCP and QUIC. apply therefore turns tso, gso, gro and tx-udp-segmentation off on
## the client eth0 (and ifb0 when it accepts it), and `web-offloads off|restore <web-container>` does the same on the web container's eth0
## from a sidecar in its netns. That is runtime state of a shared container: the previous values are kept in a state file and
## `web-offloads restore` puts them back (callers run it from an EXIT trap). Before/after values are recorded in the profile.
## The RTT probe is a HEAD request (TCP and TLS connect, no page body) and the qdisc counters are reset after verification, so the drop
## check covers only the traffic that follows. The accepted drop window is derived from the loss: NETEM_LOSS x 0.5 to x 1.75 percent.
## Env: NETEM_DELAY_MS (one way, default 75, so RTT ~150), NETEM_LOSS (percent per direction, default 2), NETEM_RATE_UP,
##      NETEM_RATE_DOWN (default 750kbit, 1600kbit), NETEM_RTT_MIN, NETEM_RTT_MAX (ms, default 140 and 170), NETEM_SAMPLES (default 20),
##      NETEM_IMAGE (sidecar image with tc, ip and curl, default nicolaka/netshoot pinned by its multi-arch index digest, amd64 and arm64), NETEM_DEV (default eth0), NETEM_PROBE_URL and NETEM_PROBE_RESOLVE
##      (host:port:address for curl --resolve) for the RTT sample: TCP connect time of NETEM_PROBE_URL measured inside the client netns.

NETEM_DELAY_MS="${NETEM_DELAY_MS:-75}"
NETEM_LOSS="${NETEM_LOSS:-2}"
NETEM_RATE_UP="${NETEM_RATE_UP:-750kbit}"
NETEM_RATE_DOWN="${NETEM_RATE_DOWN:-1600kbit}"
NETEM_RTT_MIN="${NETEM_RTT_MIN:-140}"
NETEM_RTT_MAX="${NETEM_RTT_MAX:-170}"
NETEM_SAMPLES="${NETEM_SAMPLES:-20}"
NETEM_LIMIT=1000
NETEM_FALLBACK="${NETEM_FALLBACK:-}"
NETEM_MODE=bidirectional
NETEM_IFB_MISSING=false
NETEM_DROP_MIN="${NETEM_DROP_MIN:-}"
NETEM_DROP_MAX="${NETEM_DROP_MAX:-}"
NETEM_DEVS=""
NETEM_RESTORE=""
NETEM_OFFLOADS_JSON=""
NETEM_IMAGE="${NETEM_IMAGE:-nicolaka/netshoot@sha256:b09d9b21381f47a79b3cbcb30da25266dc17186ea00ae65e99fdc51396f48e70}"
NETEM_DEV="${NETEM_DEV:-eth0}"
NETEM_PROBE_URL="${NETEM_PROBE_URL:-}"
NETEM_PROBE_RESOLVE="${NETEM_PROBE_RESOLVE:-}"

NETEM_APPLIED=false
NETEM_REASON=""
NETEM_RTT_MS=""
NETEM_RTT_SAMPLES=""
NETEM_EGRESS_DROP_RATE=""
NETEM_INGRESS_DROP_RATE=""
NETEM_EGRESS_SENT=""
NETEM_EGRESS_DROPPED=""
NETEM_EGRESS_BYTES=""
NETEM_INGRESS_SENT=""
NETEM_INGRESS_DROPPED=""
NETEM_INGRESS_BYTES=""
NETEM_DROP_LO=""
NETEM_DROP_HI=""

# Runs the shell script $2 in a sidecar sharing the netns of container $1. Output on stdout, exit code of the script.
netem_sidecar() {
  local client="$1" script="$2"
  docker run --rm --cap-add NET_ADMIN --network "container:$client" \
    -e DEV="$NETEM_DEV" -e DELAY="${NETEM_DELAY_MS}ms" -e LOSS="${NETEM_LOSS}%" -e RATE_UP="$NETEM_RATE_UP" -e RATE_DOWN="$NETEM_RATE_DOWN" \
    -e SAMPLES="$NETEM_SAMPLES" -e DEVS="$NETEM_DEVS" -e RESTORE="$NETEM_RESTORE" -e PROBE_URL="$NETEM_PROBE_URL" -e PROBE_RESOLVE="$NETEM_PROBE_RESOLVE" \
    --entrypoint sh "$NETEM_IMAGE" -c "$script"
}

# A one-line reason: last line of the error output, IPv4 addresses removed.
netem_reason_line() {
  tail -n1 "$1" | sed -E 's/[0-9]{1,3}(\.[0-9]{1,3}){3}/<ip>/g' | cut -c1-160
}

# Turns the segmentation offloads of a device off so that a netem drop hits one wire packet. Unsupported features are ignored here;
# netem_apply reads the state back and refuses to go on when tso, gso or gro are still on.
NETEM_OFFLOADS_OFF_FN='
offloads_off() { ethtool -K "$1" tso off gso off gro off tx-udp-segmentation off >/dev/null 2>&1 || true; }
'

NETEM_APPLY_SCRIPT="$NETEM_OFFLOADS_OFF_FN"'
set -e
offloads_off "$DEV"
tc qdisc del dev "$DEV" ingress 2>/dev/null || true
tc qdisc del dev "$DEV" root 2>/dev/null || true
tc qdisc add dev "$DEV" root netem delay "$DELAY" loss "$LOSS" rate "$RATE_UP"
ip link show ifb0 >/dev/null 2>&1 || ip link add ifb0 type ifb 2>/dev/null || { echo "ifb unavailable: cannot create ifb0" >&2; exit 42; }
ip link set ifb0 up
offloads_off ifb0
tc qdisc replace dev ifb0 root netem delay "$DELAY" loss "$LOSS" rate "$RATE_DOWN"
tc qdisc add dev "$DEV" handle ffff: ingress
tc filter add dev "$DEV" parent ffff: protocol all u32 match u32 0 0 action mirred egress redirect dev ifb0
'

NETEM_APPLY_EGRESS_SCRIPT="$NETEM_OFFLOADS_OFF_FN"'
set -e
offloads_off "$DEV"
tc qdisc del dev "$DEV" ingress 2>/dev/null || true
tc qdisc del dev "$DEV" root 2>/dev/null || true
tc qdisc add dev "$DEV" root netem delay "$DELAY" loss "$LOSS"
'

NETEM_SHOW_EGRESS_SCRIPT='
tc qdisc show dev "$DEV"
'

NETEM_DROPS_EGRESS_SCRIPT='
tc -s qdisc show dev "$DEV"
'

NETEM_SHOW_SCRIPT='
tc qdisc show dev "$DEV"
tc qdisc show dev ifb0
'

NETEM_DROPS_SCRIPT='
tc -s qdisc show dev "$DEV"
echo "--ifb0--"
tc -s qdisc show dev ifb0
'

NETEM_RTT_SCRIPT='
i=0
while [ "$i" -lt "$SAMPLES" ]; do
  i=$((i + 1))
  if [ -n "$PROBE_RESOLVE" ]; then
    curl -ksI -o /dev/null --max-time 10 --resolve "$PROBE_RESOLVE" -w "%{time_connect}\n" "$PROBE_URL" || echo 0
  else
    curl -ksI -o /dev/null --max-time 10 -w "%{time_connect}\n" "$PROBE_URL" || echo 0
  fi
done
'

# Re-creates the netem qdiscs so their counters start at zero (tc has no reset). The ingress qdisc and its redirect stay.
NETEM_RESET_SCRIPT='
tc qdisc del dev "$DEV" root 2>/dev/null || true
tc qdisc add dev "$DEV" root netem delay "$DELAY" loss "$LOSS" rate "$RATE_UP"
tc qdisc del dev ifb0 root 2>/dev/null || true
tc qdisc add dev ifb0 root netem delay "$DELAY" loss "$LOSS" rate "$RATE_DOWN"
'

NETEM_RESET_EGRESS_SCRIPT='
tc qdisc del dev "$DEV" root 2>/dev/null || true
tc qdisc add dev "$DEV" root netem delay "$DELAY" loss "$LOSS"
'

# Prints one line per device in $DEVS: "<dev> tso=<on|off|na> gso=.. gro=.. udp_gso=..".
NETEM_OFFLOAD_READ_SCRIPT='
for dev in $DEVS; do
  ethtool -k "$dev" 2>/dev/null | {
    tso=na; gso=na; gro=na; udp=na
    while read -r name value rest; do
      case "$name" in
        tcp-segmentation-offload:) tso=$value ;;
        generic-segmentation-offload:) gso=$value ;;
        generic-receive-offload:) gro=$value ;;
        tx-udp-segmentation:) udp=$value ;;
      esac
    done
    echo "$dev tso=$tso gso=$gso gro=$gro udp_gso=$udp"
  }
done
'

# Sets tso, gso, gro and tx-udp-segmentation of $DEVS off.
NETEM_OFFLOAD_OFF_SCRIPT="$NETEM_OFFLOADS_OFF_FN"'
for dev in $DEVS; do offloads_off "$dev"; done
'

# Sets the features of $DEV back to the "tso=on gso=on gro=off udp_gso=on" words in $RESTORE.
NETEM_OFFLOAD_RESTORE_SCRIPT='
args=""
for kv in $RESTORE; do
  k=${kv%%=*}; v=${kv#*=}
  case "$v" in on|off) ;; *) continue ;; esac
  [ "$k" = udp_gso ] && k=tx-udp-segmentation
  args="$args $k $v"
done
[ -z "$args" ] || ethtool -K "$DEV" $args >/dev/null 2>&1 || true
'

NETEM_CLEAR_SCRIPT='
tc qdisc del dev "$DEV" ingress 2>/dev/null || true
tc qdisc del dev "$DEV" root 2>/dev/null || true
ip link del ifb0 2>/dev/null || true
'

# Removes the shaping from the netns of container $1.
netem_clear() {
  netem_sidecar "$1" "$NETEM_CLEAR_SCRIPT" >/dev/null 2>&1 || true
}

# Checks that netem with the wanted delay and loss is installed on both devices. Sets NETEM_REASON when not.
netem_check_qdiscs() {
  local out show="$NETEM_SHOW_SCRIPT" wanted=2 where="$NETEM_DEV and ifb0"
  if [ "$NETEM_MODE" = egress-only ]; then show="$NETEM_SHOW_EGRESS_SCRIPT"; wanted=1; where="$NETEM_DEV"; fi
  out="$(netem_sidecar "$1" "$show" 2>/dev/null)" || { NETEM_REASON="cannot read the qdiscs"; return 1; }
  local pattern="qdisc netem.*delay ${NETEM_DELAY_MS}ms"
  if awk -v loss="$NETEM_LOSS" 'BEGIN {exit !(loss + 0 > 0)}'; then pattern="$pattern.*loss ${NETEM_LOSS}%"; fi
  if [ "$(printf '%s\n' "$out" | grep -c "$pattern")" -lt "$wanted" ]; then
    NETEM_REASON="netem delay/loss not found on $where"
    return 1
  fi
}

# Samples the TCP connect time of NETEM_PROBE_URL in the client netns. Sets NETEM_RTT_SAMPLES (ms, space separated) and NETEM_RTT_MS (median).
netem_measure_rtt() {
  local raw
  [ -n "$NETEM_PROBE_URL" ] || { NETEM_REASON="no NETEM_PROBE_URL to measure the RTT"; return 1; }
  raw="$(netem_sidecar "$1" "$NETEM_RTT_SCRIPT" 2>/dev/null)" || { NETEM_REASON="RTT sample failed"; return 1; }
  NETEM_RTT_SAMPLES="$(printf '%s\n' "$raw" | awk 'NF && $1 > 0 {printf "%s%d", (n++ ? " " : ""), $1 * 1000 + 0.5}')"
  [ -n "$NETEM_RTT_SAMPLES" ] || { NETEM_REASON="RTT sample returned no connect time"; return 1; }
  NETEM_RTT_MS="$(printf '%s\n' $NETEM_RTT_SAMPLES | sort -n | awk '{v[NR]=$1} END {print v[int((NR + 1) / 2)]}')"
}

# "<packets> <dropped> <bytes>" of the netem qdisc from the tc -s output on stdin; empty when not found.
netem_drop_counts() {
  awk '/^qdisc netem/ {want = 1; next} want && /Sent .* pkt \(dropped/ {
    bytes = $2; sent = $4; dropped = $7; gsub(/,/, "", dropped)
    print sent, dropped, bytes
    exit
  }'
}

# Percent with 2 decimals of dropped/sent; empty when nothing was sent.
netem_rate_of() {
  awk -v s="$1" -v d="$2" 'BEGIN {if (s > 0) printf "%.2f", d / s * 100}'
}

# Reads the netem drop counters of both devices of container $1 (in packets: the offloads are off) into NETEM_EGRESS_* and NETEM_INGRESS_*
# (sent, dropped, bytes, drop rate in percent). With loss above 0 each direction must land inside NETEM_LOSS x 0.5 .. x 1.75 percent
# (NETEM_DROP_MIN and NETEM_DROP_MAX override); otherwise returns 1 with NETEM_REASON set.
netem_read_drops() {
  local raw egress ingress drops="$NETEM_DROPS_SCRIPT" directions="egress ingress"
  if [ "$NETEM_MODE" = egress-only ]; then drops="$NETEM_DROPS_EGRESS_SCRIPT"; directions=egress; fi
  raw="$(netem_sidecar "$1" "$drops" 2>/dev/null)" || { NETEM_REASON="cannot read the qdisc counters"; return 1; }
  egress="$(printf '%s\n' "$raw" | sed '/^--ifb0--$/,$d' | netem_drop_counts)"
  ingress=""
  if [ "$NETEM_MODE" != egress-only ]; then ingress="$(printf '%s\n' "$raw" | sed '1,/^--ifb0--$/d' | netem_drop_counts)"; fi
  read -r NETEM_EGRESS_SENT NETEM_EGRESS_DROPPED NETEM_EGRESS_BYTES <<< "$egress"
  read -r NETEM_INGRESS_SENT NETEM_INGRESS_DROPPED NETEM_INGRESS_BYTES <<< "$ingress"
  NETEM_EGRESS_DROP_RATE=""
  NETEM_INGRESS_DROP_RATE=""
  [ -z "$NETEM_EGRESS_SENT" ] || NETEM_EGRESS_DROP_RATE="$(netem_rate_of "$NETEM_EGRESS_SENT" "$NETEM_EGRESS_DROPPED")"
  [ -z "$NETEM_INGRESS_SENT" ] || NETEM_INGRESS_DROP_RATE="$(netem_rate_of "$NETEM_INGRESS_SENT" "$NETEM_INGRESS_DROPPED")"
  NETEM_DROP_LO="${NETEM_DROP_MIN:-$(awk -v l="$NETEM_LOSS" 'BEGIN {printf "%g", l * 0.5}')}"
  NETEM_DROP_HI="${NETEM_DROP_MAX:-$(awk -v l="$NETEM_LOSS" 'BEGIN {printf "%g", l * 1.75}')}"
  awk -v loss="$NETEM_LOSS" 'BEGIN {exit !(loss + 0 > 0)}' || return 0
  local direction rate
  for direction in $directions; do
    if [ "$direction" = egress ]; then rate="$NETEM_EGRESS_DROP_RATE"; else rate="$NETEM_INGRESS_DROP_RATE"; fi
    if [ -z "$rate" ]; then
      NETEM_REASON="no $direction drop counters while loss is ${NETEM_LOSS}%"
      return 1
    fi
    if ! awk -v r="$rate" -v lo="$NETEM_DROP_LO" -v hi="$NETEM_DROP_HI" 'BEGIN {exit !(r >= lo && r <= hi)}'; then
      NETEM_REASON="$direction drop rate ${rate}% outside ${NETEM_DROP_LO}-${NETEM_DROP_HI}% with loss ${NETEM_LOSS}%"
      return 1
    fi
  done
}

# Offload state of the devices $2 (space separated) in container $1 as JSON {"eth0": {"tso": "on", ...}} on stdout.
netem_offload_state() {
  local raw
  NETEM_DEVS="$2"
  raw="$(netem_sidecar "$1" "$NETEM_OFFLOAD_READ_SCRIPT" 2>/dev/null)" || return 1
  printf '%s\n' "$raw" | jq -Rn '[inputs | select(length > 0) | split(" ") | {(.[0]): (.[1:] | map(split("=") | {(.[0]): .[1]}) | add)}] | add // {}'
}

# True when tso, gso and gro of device $2 in the state JSON $1 are all off (or not present on the device).
netem_offloads_are_off() {
  printf '%s\n' "$1" | jq -e --arg dev "$2" '.[$dev] | (.tso != "on" and .gso != "on" and .gro != "on")' >/dev/null
}

# Verifies shaping on container $1: qdiscs present, median RTT inside NETEM_RTT_MIN..NETEM_RTT_MAX. Returns 1 with NETEM_REASON set otherwise.
netem_verify() {
  netem_check_qdiscs "$1" || return 1
  netem_measure_rtt "$1" || return 1
  if [ "$NETEM_RTT_MS" -lt "$NETEM_RTT_MIN" ] || [ "$NETEM_RTT_MS" -gt "$NETEM_RTT_MAX" ]; then
    NETEM_REASON="measured RTT ${NETEM_RTT_MS} ms outside ${NETEM_RTT_MIN}-${NETEM_RTT_MAX} ms"
    return 1
  fi
}

# Shapes container $1 and verifies it; on any failure removes the shaping and falls back with a reason. The client offloads are read
# before and after (NETEM_OFFLOADS_JSON), and the counters are reset after the RTT verification.
netem_apply() {
  local client="$1" err status=0 script="$NETEM_APPLY_SCRIPT" reset="$NETEM_RESET_SCRIPT" devs="$NETEM_DEV ifb0" before after
  if [ "$NETEM_MODE" = egress-only ]; then script="$NETEM_APPLY_EGRESS_SCRIPT"; reset="$NETEM_RESET_EGRESS_SCRIPT"; devs="$NETEM_DEV"; fi
  NETEM_APPLIED=false
  NETEM_IFB_MISSING=false
  NETEM_REASON=""
  NETEM_RTT_MS=""
  NETEM_RTT_SAMPLES=""
  before="$(netem_offload_state "$client" "$NETEM_DEV" || echo null)"
  [ -n "$before" ] || before=null
  err="$(mktemp)"
  netem_sidecar "$client" "$script" >/dev/null 2>"$err" || status=$?
  if [ "$status" -ne 0 ]; then
    if [ "$status" -eq 42 ]; then
      NETEM_IFB_MISSING=true
      NETEM_REASON="ifb unavailable: cannot create ifb0; rerun with --netem-fallback egress-only for egress-only shaping"
      rm -f "$err"
      netem_clear "$client"
      return 0
    fi
    NETEM_REASON="netem unavailable: $(netem_reason_line "$err")"
    rm -f "$err"
    netem_clear "$client"
    return 0
  fi
  rm -f "$err"
  after="$(netem_offload_state "$client" "$devs" || echo '{}')"
  [ -n "$after" ] || after='{}'
  NETEM_OFFLOADS_JSON="$(jq -cn --argjson b "$before" --argjson a "$after" '{client: {before: $b, after: $a}}')"
  if ! netem_offloads_are_off "$after" "$NETEM_DEV"; then
    NETEM_REASON="cannot turn tso, gso and gro off on $NETEM_DEV"
    netem_clear "$client"
    return 0
  fi
  if netem_verify "$client"; then
    if netem_sidecar "$client" "$reset" >/dev/null 2>&1; then
      NETEM_APPLIED=true
    else
      NETEM_REASON="cannot reset the qdisc counters"
      netem_clear "$client"
    fi
  else
    netem_clear "$client"
  fi
  return 0
}

# State file with the web container's offloads from before they were turned off ("web-offloads off"), removed by "restore".
netem_web_state_file() {
  printf '%s/netem-offloads-%s.state' "${NETEM_STATE_DIR:-${TMPDIR:-/tmp}}" "$1"
}

# Turns tso, gso, gro and tx-udp-segmentation off on eth0 of web container $1 and prints {"before": .., "after": ..}. A leftover state
# file from an interrupted run keeps the original values (they are the ones to restore), so before is always the untouched state.
netem_web_offloads_off() {
  local web="$1" state before after
  state="$(netem_web_state_file "$web")"
  if [ -s "$state" ]; then
    before="$(cat "$state")"
  else
    before="$(netem_offload_state "$web" "$NETEM_DEV")" || { echo "cannot read the offloads of $web" >&2; return 1; }
    printf '%s\n' "$before" > "$state"
  fi
  NETEM_DEVS="$NETEM_DEV"
  netem_sidecar "$web" "$NETEM_OFFLOAD_OFF_SCRIPT" >/dev/null 2>&1 || true
  after="$(netem_offload_state "$web" "$NETEM_DEV")" || { echo "cannot read the offloads of $web" >&2; return 1; }
  jq -cn --argjson b "$before" --argjson a "$after" '{before: $b, after: $a}'
  netem_offloads_are_off "$after" "$NETEM_DEV" || { echo "cannot turn tso, gso and gro off on $web" >&2; return 1; }
}

# Puts the offloads recorded by netem_web_offloads_off back on web container $1 and prints {"restored": .., "matches_before": bool}.
# Does nothing (prints {"restored": null}) when no state file exists, so it can run from an EXIT trap more than once.
netem_web_offloads_restore() {
  local web="$1" state before words restored
  state="$(netem_web_state_file "$web")"
  if [ ! -s "$state" ]; then echo '{"restored":null,"matches_before":null}'; return 0; fi
  before="$(cat "$state")"
  words="$(printf '%s\n' "$before" | jq -r --arg dev "$NETEM_DEV" '.[$dev] | to_entries | map("\(.key)=\(.value)") | join(" ")')"
  NETEM_RESTORE="$words"
  netem_sidecar "$web" "$NETEM_OFFLOAD_RESTORE_SCRIPT" >/dev/null 2>&1 || true
  restored="$(netem_offload_state "$web" "$NETEM_DEV")" || { echo "cannot read the offloads of $web; state kept in $state" >&2; return 1; }
  jq -cn --argjson b "$before" --argjson r "$restored" '{restored: $r, matches_before: ($b == $r)}'
  if [ "$before" = "$restored" ] || [ "$(printf '%s' "$before" | jq -cS .)" = "$(printf '%s' "$restored" | jq -cS .)" ]; then
    rm -f "$state"
  else
    echo "offloads of $web differ from before; state kept in $state" >&2
    return 1
  fi
}

# The profile and the measurement as one JSON object; no addresses, names or hosts.
netem_profile_json() {
  local NETEM_RATE_EFFECTIVE_UP="$NETEM_RATE_UP" NETEM_RATE_EFFECTIVE_DOWN="$NETEM_RATE_DOWN"
  if [ "$NETEM_MODE" = egress-only ]; then NETEM_RATE_EFFECTIVE_UP=""; NETEM_RATE_EFFECTIVE_DOWN=""; fi
  jq -n --argjson applied "$NETEM_APPLIED" --arg reason "$NETEM_REASON" --argjson delay "$NETEM_DELAY_MS" --argjson loss "$NETEM_LOSS" \
    --arg up "$NETEM_RATE_EFFECTIVE_UP" --arg down "$NETEM_RATE_EFFECTIVE_DOWN" --arg rtt "$NETEM_RTT_MS" --arg samples "$NETEM_RTT_SAMPLES" \
    --arg mode "$NETEM_MODE" --arg image "$NETEM_IMAGE" --argjson limit "$NETEM_LIMIT" --arg egress "$NETEM_EGRESS_DROP_RATE" --arg ingress "$NETEM_INGRESS_DROP_RATE" \
    --arg es "$NETEM_EGRESS_SENT" --arg ed "$NETEM_EGRESS_DROPPED" --arg eb "$NETEM_EGRESS_BYTES" \
    --arg is "$NETEM_INGRESS_SENT" --arg id "$NETEM_INGRESS_DROPPED" --arg ib "$NETEM_INGRESS_BYTES" \
    --arg lo "$NETEM_DROP_LO" --arg hi "$NETEM_DROP_HI" --argjson offloads "${NETEM_OFFLOADS_JSON:-null}" \
    --argjson rttmin "$NETEM_RTT_MIN" --argjson rttmax "$NETEM_RTT_MAX" '
    def nn: if . == "" then null else . end;
    def num: nn | if . == null then null else tonumber end;
    {sidecar_image_digest: ($image | split("@") | if length == 2 and (.[1] | test("^sha256:[0-9a-f]{64}$")) then .[1] else null end), applied: $applied, reason: ($reason | nn), delay_ms: $delay, loss_pct: $loss, rate_up: ($up | nn), rate_down: ($down | nn),
     rtt_ms: ($rtt | num),
     rtt_samples_ms: ($samples | if . == "" then [] else split(" ") | map(tonumber) end),
     rtt_target_ms: [$rttmin, $rttmax], mode: $mode, limit: $limit, offloads: $offloads,
     qdisc: {unit: "packets (segmentation offloads off, counters reset after the RTT check)",
             egress_drop_rate: ($egress | num), ingress_drop_rate: ($ingress | num),
             egress_packets: ($es | num), egress_dropped: ($ed | num), egress_bytes: ($eb | num),
             ingress_packets: ($is | num), ingress_dropped: ($id | num), ingress_bytes: ($ib | num),
             accepted_drop_pct: (if $lo == "" then null else [($lo | tonumber), ($hi | tonumber)] end)}}'
}

netem_main() {
  if [ "${1:-}" = --netem-fallback ]; then
    NETEM_FALLBACK="${2:-}"
    shift 2 || true
  fi
  case "$NETEM_FALLBACK" in
    "") ;;
    egress-only)
      NETEM_MODE=egress-only
      NETEM_DELAY_MS=150
      ;;
    *)
      echo "Unknown --netem-fallback value: $NETEM_FALLBACK (only egress-only)" >&2
      return 2
      ;;
  esac
  local cmd="${1:-}" client="${2:-}"
  if [ "$cmd" = defaults ]; then
    netem_profile_json
    return 0
  fi
  if [ "$cmd" = web-offloads ]; then
    local action="${2:-}" web="${3:-}"
    case "$action" in
      off) netem_web_offloads_off "$web" ;;
      restore) netem_web_offloads_restore "$web" ;;
      *) echo "Usage: bench/lib/netem.sh web-offloads off|restore <web-container>" >&2; return 2 ;;
    esac
    return
  fi
  if [ -z "$client" ] || [[ "$cmd" != apply && "$cmd" != verify && "$cmd" != drops && "$cmd" != clear && "$cmd" != profile ]]; then
    echo "Usage: bench/lib/netem.sh [--netem-fallback egress-only] apply|verify|drops|clear|profile <client-container> | web-offloads off|restore <web-container> | defaults" >&2
    return 2
  fi
  case "$cmd" in
    clear) netem_clear "$client" ;;
    apply)
      netem_apply "$client"
      netem_profile_json
      if [ "$NETEM_IFB_MISSING" = true ]; then echo "$NETEM_REASON" >&2; return 2; fi
      ;;
    drops)
      NETEM_APPLIED=true
      netem_read_drops "$client" || { netem_profile_json; return 1; }
      netem_profile_json
      ;;
    verify | profile)
      if netem_verify "$client"; then NETEM_APPLIED=true; fi
      netem_profile_json
      ;;
  esac
}

if [ "${BASH_SOURCE[0]}" = "$0" ]; then
  set -euo pipefail
  netem_main "$@"
fi
