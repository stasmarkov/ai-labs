# Manual test plan: HTTP/3 (SL-12)

Run everything from `/Users/stasmarkov/work/interview` on the host, with DDEV running (`ddev start`) and Docker up. The app is at https://interview.ddev.site. Needs `jq`, `node` and `docker` on the host. Nothing here needs a login or seeded data beyond the default seeders (listing 1 must exist).

Rollback: the steps switch the web server and, in step 2 and 3, the segmentation offloads of the web container's eth0. Always finish with `ddev server nginx-fpm`; if a netem run was interrupted hard (kill -9), also run `bench/lib/netem.sh web-offloads restore ddev-interview-web` and `docker rm -f netem-client`. The scripts do this themselves on exit, Ctrl-C and SIGTERM. No step changes tracked files except result files under `bench/results/http3/<date>/`; use a throwaway date (`BENCH_DATE=manual-check`) to keep the committed results untouched, and delete that folder afterwards.

## Step 1

One request over HTTP/3 (AC-1), including the case that used to fail: a server with no UDP listener. The script switches servers itself and puts nginx-fpm back.

```sh
BENCH_DATE=manual-check bench/http3.sh; echo "exit=$?"
jq '{h3_ok, h3_status, h3_alpn, alpn_h2, h2_status, alt_svc, path, early_hints_during_h3, curl_image_digest, same_body: (.body_sha256_h2 == .body_sha256_h3)}' bench/results/http3/manual-check/frankenphp.json
head -40 bench/results/http3/manual-check/frankenphp.trace

BENCH_DATE=manual-check bench/http3.sh --server nginx-fpm; echo "exit=$?"
jq '{h3_ok, h2_status, reason}' bench/results/http3/manual-check/nginx-fpm.json
ddev server --current
```

Expected:
- frankenphp: exit 0, `h3_ok: true`, `h3_status: 200`, `h3_alpn: "h3"`, `alpn_h2: "h2"`, `h2_status: 200`, `alt_svc` containing `h3=":443"`, `path: "/listings/1"`, `early_hints_during_h3: false`, `curl_image_digest` starting `alpine/curl-http3@sha256:`, `same_body: true`.
- `EARLY_HINTS=false` is set in `.env` only while the requests run (the quiche-based curl cannot parse the 103 that `/listings/1` sends over h3); `git status` shows no change and `ls .env.bench-backup` finds nothing afterwards.
- The trace has no certificate subject or issuer lines and shows `<ip>` instead of addresses.
- nginx-fpm: exit 0, `h3_ok: false`, `h2_status: 200`, `reason` like `no HTTP/3: curl exit 7`.
- `ddev server --current` prints `nginx-fpm`. No `.html` body files appear under `bench/results/http3/`; a body mismatch would write them to `bench/results/raw/` (gitignored).

## Step 2

Network conditions (AC-2). Start an idle client, turn the web container's offloads off, apply the profile with each loss value, verify, generate real traffic, read the drops, then restore everything.

```sh
ddev server frankenphp
docker run -d --name netem-client --network ddev_default nicolaka/netshoot@sha256:b09d9b21381f47a79b3cbcb30da25266dc17186ea00ae65e99fdc51396f48e70 sleep infinity
export NETEM_PROBE_URL=$(ddev server --url) NETEM_PROBE_RESOLVE=interview.ddev.site:443:$(docker inspect -f '{{.NetworkSettings.Networks.ddev_default.IPAddress}}' ddev-interview-web)
bench/lib/netem.sh web-offloads off ddev-interview-web
for l in 0 2; do NETEM_LOSS=$l bench/lib/netem.sh apply netem-client | jq '{applied, rtt_ms, mode, offloads}'; NETEM_LOSS=$l bench/lib/netem.sh verify netem-client | jq '{applied, rtt_ms}'; done
docker exec netem-client sh -c "for r in 1 2 3; do for i in \$(seq 1 25); do curl -sk --http2 -o /dev/null --resolve $NETEM_PROBE_RESOLVE https://interview.ddev.site/listings/1 & done; wait; done"
NETEM_LOSS=2 bench/lib/netem.sh drops netem-client | jq .qdisc
bench/lib/netem.sh clear netem-client
docker rm -f netem-client
bench/lib/netem.sh web-offloads restore ddev-interview-web
ddev server nginx-fpm
```

Expected:
- `web-offloads off` prints `before` (committed run: tso on, gso on, gro off, udp_gso on) and `after` (all off); `web-offloads restore` prints `matches_before: true`.
- Both loss values: `applied` true, `rtt_ms` between 140 and 170 (about 154), mode `bidirectional`, `offloads.client.after.eth0` with tso, gso, gro and udp_gso all `off`.
- `drops` after the traffic (at loss 2): `egress_drop_rate` and `ingress_drop_rate` each within 1 and 3.5 (the window is 0.5 to 1.75 times the loss), counted in packets (`egress_packets`, `ingress_packets`). The counters were reset after the RTT check, so the probe is not in them. With only a few hundred packets a single run can fall outside the window; repeat the traffic loop.
- To see the ifb fallback, run on a host where `ifb` cannot load: apply exits 2 with a message. Opt in with `bench/lib/netem.sh --netem-fallback egress-only apply netem-client`; the profile then says mode `egress-only`, delay 150 ms, no rate. (Covered by `BenchNetemTest`; only try this by hand if your Docker VM lacks ifb.)

## Step 3

Page timings and committed variants (AC-3, AC-4). The matrix wrapper switches to frankenphp, runs the four variants (each a few minutes) and always puts nginx-fpm back.

```sh
node bench/lcp.mjs --dry-run --variant h3-loss2 --path direct --path-reason "netem needs the direct path: the router has no HTTP/3" --net netem --protocol h3 --loss 2
BENCH_DATE=manual-check bench/http3-matrix.sh --dry-run
BENCH_DATE=manual-check bench/http3-matrix.sh
ddev server --current
```

One variant on its own (the same command the matrix runs; start with `ddev server frankenphp` and finish with `ddev server nginx-fpm`):

```sh
BENCH_DATE=manual-check node bench/lcp.mjs --variant h3-loss2 --path direct --path-reason "netem needs the direct path: the router has no HTTP/3" --net netem --protocol h3 --loss 2
```

Then inspect a result file (netem results are `lcp-<variant>.json`; committed ones are in `bench/results/http3/2026-11-05/`):

```sh
jq '{protocol, path, attempts, valid_runs, rtt: .net.rtt_ms, drops: .net.qdisc, offloads_web: .net.offloads.web, load_ms, lcp_ms}' bench/results/http3/2026-11-05/lcp-h3-loss2.json
```

Expected:
- The dry run prints the plan (path `direct`, throttle from the netem.sh defaults) and exits 0 without launching a browser; `--path router` with `--net netem` exits 2.
- Each variant ends with 5 valid runs; every valid run's `next_hop_protocol` is `h2` in the h2 variants and `h3` in the h3 variants. A run that fell back to another protocol, or whose navigation failed, is an invalid attempt (up to 8 attempts), not a recorded sample.
- Each file has `lcp_ms`, `fcp_ms`, `ttfb_ms` and `load_ms` with `min`, `median`, `max`, plus `net` with the profile, `rtt_ms`, `qdisc` rates and counts, `sidecar_image_digest` and `offloads` (client before/after, web before/after/restored).
- A loss 2 variant can fail with "drop rate ... outside 1-3.5%": that is the check working and nothing is written; rerun it with `bench/http3-matrix.sh --only h3-loss2`.
- Committed values: see the table in `BENCHMARKS.md` under the HTTP/3 section and compare its four rows with `jq '.load_ms.median' bench/results/http3/2026-11-05/lcp-*-loss*.json`. The table, the Mermaid chart, "What the numbers say", "Surprises" and "Interview story" (with at least one measured number) are all present.
- `ddev server --current` prints `nginx-fpm` and `docker ps` shows no `lcp-netem-*` container.

## Step 4

Learning guide (AC-5).

```sh
grep -n "^## 22\. HTTP/3 and QUIC" docs/learning-guide.md
```

Open `docs/learning-guide.md` at that heading and read it.

Expected: the heading is found once. The section covers QUIC over UDP, why the router path has no h3 (TLS ends at Traefik), what `Alt-Svc` advertises and how a browser upgrades, head-of-line blocking in TCP versus QUIC's per-stream recovery, why the throttle sits on the network layer, the measured numbers, and working links to `bench/http3.sh`, `bench/lib/netem.sh`, `bench/lcp.mjs`, `bench/results/http3/` and the official docs (RFC 9000, RFC 9114, MDN). Click each link in your editor or on GitHub; none is a 404. The link to section 21 (`#21-early-hints-and-compression`) lands on that section.

## Step 5

Nothing else changed (MNC-1, MNC-3).

```sh
git diff --stat HEAD -- .ddev
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do ddev server $s >/dev/null; BENCH_PARITY_DIR=/tmp/sl12 bench/parity.sh capture >/dev/null; bench/parity.sh compare bench/parity/$s.txt /tmp/sl12/$s.txt; done
ddev server nginx-fpm
ddev artisan test --compact
bench/run.sh ping --quick; echo "exit=$?"
```

Run the last command after the slice's commit, on a clean tree (it refuses on a dirty one).

Expected:
- The `.ddev` diff is empty (no router, host network or Caddyfile change, also after the server switches).
- The fingerprint is unchanged: `app-CjRfEL-0.css` sha256 `82042c94...ce71`, `app-BvRk9kiK.js` sha256 `e3b0c442...b855`.
- `identical` six times.
- Full suite green (1166 passed at the last run).
- `bench/run.sh ping --quick` exits 0 (still pending until the commit).

## Step 6

Old behaviour kept (MNC-2).

```sh
git status --short bench/results/lcp
node bench/lcp.mjs --dry-run --variant check --path router --path-reason "default mode check" | tee /tmp/lcp-now.txt
```

Expected: `bench/results/lcp` shows no changes, and the default (toxiproxy) dry run plan shows the toxiproxy network mode with no netem fields. No `--protocol`, `--net` or `--loss` flag is needed for the old mode.

## Step 7

Fairness (NFR-1). For each pair compare the network block of the h2 and h3 files.

```sh
cd bench/results/http3/2026-11-05
for l in 0 2; do diff <(jq -S '.net | del(.rtt_samples_ms, .qdisc, .rtt_ms)' lcp-h2-loss$l.json) <(jq -S '.net | del(.rtt_samples_ms, .qdisc, .rtt_ms)' lcp-h3-loss$l.json) && echo "loss$l same profile"; done
jq '[.variant, .net.rtt_ms, .net.sidecar_image_digest, .attempts]' lcp-*-loss*.json
grep -n -i "emulateNetworkConditions\|Network.emulate" /Users/stasmarkov/work/interview/bench/lcp.mjs || echo "no DevTools network emulation"
```

Expected: "same profile" for both loss values (delay 75, rates, mode, loss, offload state), RTT 154-155 ms in all four files, the same `sidecar_image_digest` and an `attempts` count in each, and no DevTools network emulation. All four files come from one session with the same scripts and sidecar.

## Step 8

Privacy (NFR-2).

```sh
grep -rEn "[0-9]{1,3}(\.[0-9]{1,3}){3}|subject:|issuer:|stasmarkov|stanislav|markov|drupfan" bench/results/http3 || echo clean
```

Expected: `clean`. (A match on the curl message "unable to get local issuer certificate" would not be a certificate field; none is expected in the case-sensitive pattern above.)
