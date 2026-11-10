---
id: spec-20261105-1109-http3
title: One verified HTTP/3 request, and h2 vs h3 under the same network-layer throttle
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-05T09:11:51Z
created: 2026-11-05
stacks: [laravel]
slice: SL-12
brs: [BR-26, BR-27]
---

# One verified HTTP/3 request, and h2 vs h3 under the same network-layer throttle

## Summary

FrankenPHP already serves HTTP/3. Its Caddy listens on UDP 443 inside the web container, but the DDEV router ends TLS and talks to the container over plain HTTP, so no browser has ever used it. This slice reaches the container directly from a bench container on the DDEV Docker network, bypassing the router. It does three things:
- **Proves one HTTP/3 request and the `Alt-Svc` advertisement** with curl.
- **Measures h2 and h3 page timings** with the SL-10 LCP script.
- **Uses identical network conditions** applied with `tc netem` inside the client container: a clean "Slow 4G" profile, and the same profile with 2 % packet loss, where QUIC's per-stream loss recovery should matter most.

No host ports, router changes or `.ddev/config.yaml` edits are needed.

## Context

- **Facts checked on 2026-11-05, on frankenphp:**
  - **Listening:** the web container listens on TCP 443 (h1/h2) and UDP 443 (h3).
  - **Through the router:** `curl -I https://ai-lab.ddev.site/` has no `Alt-Svc`, because Traefik proxies to the container's HTTP port.
- **The SL-10 lesson:** throttle at the network layer, not with DevTools. Toxiproxy is TCP-only, so it cannot carry QUIC (UDP). `tc netem` shapes both TCP and UDP the same way, which makes h2 vs h3 fair.
- **What exists:**
  - `bench/lcp.mjs`: Chromium in a pinned Playwright image, cold runs, `--host-resolver-rules`, the certificate trusted by its SPKI hash, and digest pinning.
  - `bench/early-hints.sh`: the direct-path curl with `--resolve`.
- **What is not known yet, and is verified first:**
  - whether the pinned curl image has HTTP/3 (`curl -V` lists `HTTP3`);
  - whether Chromium accepts the SPKI-pinned certificate for QUIC;
  - whether the Docker VM's kernel has the `ifb` module for shaping incoming traffic.

## Scope

### In scope

- **`bench/http3.sh`** runs from a pinned container on `ddev_default`, with `ai-lab.ddev.site` resolved to the web container's IP. It records:
  - one `--http3-only` request to `/listings/1`: status, `HTTP/3` in the verbose output, and the body sha256 equal to the h2 body's;
  - the `Alt-Svc` header on an h2 request (`h3=":443"`);
  - which ALPN each request negotiated.
  The trace is redacted like the SL-10 traces: no certificate subjects, no IPs.
  If the pinned `curlimages/curl` has no HTTP/3, the script uses another pinned image that has it (for example `ymuski/curl-http3`, or a `cloudflare/quiche` build), recorded by digest.
- **Network shaping:** `bench/lib/netem.sh` applies a profile to the client container's network from a sidecar that shares it (see Clarifications Q1):
  - **Egress:** `tc qdisc add dev eth0 root netem delay 75ms rate 750kbit [loss 2%]`.
  - **Ingress:** redirected through an `ifb0` device, `netem delay 75ms rate 1600kbit [loss 2%]`.
  - **Result:** 150 ms RTT, 1.6 Mbit/s down, 750 kbit/s up, the same values as SL-10's profile.
  - **Without `ifb`:** see Clarifications Q2.
- **`bench/lcp.mjs --protocol h2|h3 --net netem --loss 0|2`**:
  - **h3:** Chromium with `--enable-quic --origin-to-force-quic-on=ai-lab.ddev.site:443` (no Alt-Svc round trip needed).
  - **h2:** Chromium with `--disable-quic`.
  - **Checked:** each run confirms the protocol from `PerformanceNavigationTiming.nextHopProtocol` (`h3` or `h2`); a mismatched run is invalid (retries in Clarifications Q8).
  - **Runs:** 5 cold runs per variant, toxiproxy not used.
  - **Variants:** {h2, h3} × {loss 0 %, loss 2 %} on frankenphp, Early Hints at the default (on).
  - **Recorded:** LCP, FCP and TTFB, plus total load time (`loadEventEnd`), because loss affects the whole transfer, not only first paint.
- **Results:** `bench/results/http3/<UTC date>/` holds the curl trace, the verification JSON, and 4 LCP files.
- **Docs:** `BENCHMARKS.md` section `## HTTP/3 vs HTTP/2 (<date>)` and guide section 22.

### Out of scope

- Exposing UDP 443 on the host, or running HTTP/3 through the DDEV router (Traefik's h3 entry point would need router config, which belongs to the owner).
- HTTP/3 on nginx-fpm, Apache or OpenLiteSpeed (no QUIC in their DDEV builds).
- 0-RTT, connection migration, and multiplexing tests beyond one page load.
- Load or throughput benchmarking over h3.

## Must NOT change

- **MNC-1**: No `.ddev/config.yaml`, router, host network or Caddyfile change. HTTP/3 is already on; only clients are added.
- **MNC-2**: The SL-10 LCP results and the default toxiproxy mode of `bench/lcp.mjs` keep working. The new flags are opt-in and the old defaults stay.
- **MNC-3**: The asset fingerprint, parity on 6 servers and the full suite (offline) are unchanged.

## Acceptance criteria

- **AC-1**: `bench/http3.sh` on frankenphp SHALL record:
  - one request over HTTP/3, with status 200 and a body sha256 equal to the h2 body's;
  - an h2 response carrying `Alt-Svc: h3=":443"…`.
  It saves a redacted trace and a JSON with `h3_ok`, `alt_svc`, `alpn_h2` and `curl_image_digest`. On nginx-fpm it SHALL report `h3_ok: false` with the reason (no UDP listener) and exit 0.
- **AC-2**: `bench/lib/netem.sh` SHALL apply and verify the profile on the client container's network. Verification follows Clarifications Q3: the median of 20 TCP connects is within 140–170 ms, and the qdisc drop rate is within 1–3.5 % per direction when loss is set. The profile values and the measured RTT go into every result file. When `ifb` is unavailable, it follows the documented fallback.
- **AC-3**: `bench/lcp.mjs` SHALL support `--protocol h2|h3` and `--net netem --loss <pct>`:
  - each valid run's `nextHopProtocol` matches the requested protocol;
  - it writes 5 cold runs per variant with the medians and ranges of LCP, FCP, TTFB and load time;
  - the existing toxiproxy mode stays the default, with the same output (MNC-2).
- **AC-4 (BR-26)**: The committed run SHALL cover the 4 variants: h2/h3 × loss 0/2 %. `BENCHMARKS.md` SHALL have:
  - a table of the 4 variants with the medians and ranges, and the measured RTT;
  - one Mermaid chart (load time per variant);
  - "What the numbers say", "Surprises" and "Interview story" with at least one measured number.
- **AC-5 (BR-27)**: `docs/learning-guide.md` SHALL get `## 22. HTTP/3 and QUIC`. It SHALL:
  - explain QUIC over UDP, why the router path has no h3 (TLS ends at Traefik), what `Alt-Svc` advertises, and how a browser upgrades;
  - explain head-of-line blocking in TCP vs QUIC's per-stream recovery, and why the loss variant is where they should differ;
  - explain why the throttle must sit on the network layer for both;
  - give the measured numbers;
  - link the code, scripts, results and official docs.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Fairness | h2 and h3 runs use the same netem profile in the same client container setup, with the profile and the measured RTT recorded per file. No DevTools network emulation is used. | `bench/results/http3/` |
| **NFR-2** | Privacy | No trace or result contains certificate subjects, IP addresses, or the developer's name, username or hostname. | `tests/Feature/BenchCommittedRunsTest.php` |

## Boundaries

- **Always**:
  - pin images by digest;
  - redact traces;
  - leave the server on nginx-fpm;
  - the client containers only get `NET_ADMIN`, nothing more (no `--privileged`).
- **Ask first**: changing `.ddev/config.yaml`, the router or host ports; running privileged containers; loading kernel modules on the Docker VM.
- **Never**:
  - change Caddyfiles to force h3;
  - commit `.env` or `.ai/session`;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `bench/lcp.mjs` and `bench/lcp.sh` (SL-10: Playwright image, SPKI trust, resolver rules, cold runs, validity rules);
  - `bench/early-hints.sh` (direct `--resolve`, trace redaction);
  - `bench/lib/env-switch.sh`;
  - `tests/Feature/BenchLcpTest.php` and `BenchCommittedRunsTest.php` (the privacy grep);
  - `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` (read only).
- **Approach**:
  - Run the Playwright container with `--cap-add NET_ADMIN` and apply netem inside it before launching Chromium. The Playwright image needs `iproute2`, so install it in a tiny derived image pinned in `bench/`, or use a sidecar that shares the network namespace (`--network container:<id>`) and has `tc`.
  - The sidecar approach is preferred: it keeps the Playwright image untouched and gives the same shaping for curl.

## Decisions (self-decided, standing approval)

1. **Direct container-to-container path, not host UDP exposure.** The point is to see h3 work and measure it. A client on the Docker network reaches UDP 443 with no host, router or `config.yaml` change, which keeps the lab's protected settings untouched. The backlog's "outside the DDEV router" is met by bypassing it.
2. **A netem sidecar sharing the client's network namespace.** It is one shaping mechanism for TCP and UDP, so h2 and h3 get identical conditions (the SL-10 lesson), and the browser image stays as pinned.
3. **Force QUIC instead of relying on Alt-Svc discovery.** A cold browser uses h2 on the first visit and switches only later, so a cold-run comparison would never see h3. `--origin-to-force-quic-on` makes the protocol the variable. Alt-Svc is still verified separately (AC-1).
4. **2 % loss as the stress case.** TCP's in-order delivery stalls every HTTP/2 stream on one lost packet, while QUIC stalls only the affected stream. The clean profile shows the baseline, and the lossy one shows where the protocols should differ.
5. **Load time is added to the metrics.** Loss hurts the whole transfer, and LCP may finish before the effect shows.

## Clarifications (binding; resolve the clarity gate)

**The netem sidecar (Q1).**
- **Image:** `nicolaka/netshoot`, pinned by digest, which has `tc`, `ip` and `ping`.
- **Order:** the client container (Playwright or curl) starts first, idle. Then the sidecar starts with `--network container:<client> --cap-add NET_ADMIN`, applies the shaping, verifies it and exits. The client then runs its measurement.
- **Capabilities:** the client gets no extra capabilities. Both containers are removed by a `trap` on exit, success or failure.

**When `ifb` is missing (Q2).**
- **Default:** if `ip link add ifb0 type ifb` fails, `bench/lib/netem.sh` stops with exit 2 and a message.
- **Opt-in fallback:** the developer can pass `--netem-fallback egress-only`. Its profile is egress `delay 150ms`, loss on egress only, and no rate limit. Its results record `"network.mode": "egress-only"`, and the docs say that the h2 vs h3 comparison is still fair (both get the same conditions) but no longer comparable with SL-10's numbers.
- **Kernel modules:** loading one stays "ask first". A missing module ends the measurement run, not the slice; the scripts and tests still ship.

**Verifying the profile (Q3).**
- **RTT:** the median of 20 TCP connects (`curl -w '%{time_connect}'`) to the web container's port 443, within 140–170 ms. Not ICMP.
- **Loss:** read from the qdisc counters after each run (`tc -s qdisc show`), recorded as `qdisc_dropped / qdisc_sent` per direction. The expected value is about 2 % per direction, so about 4 % for a round trip. The check passes when each direction is within 1–3.5 % over the whole variant. A 2 % profile with no measured drop fails the run.

**Plan B for QUIC certificates (Q4).**
1. If Chromium rejects the SPKI-pinned certificate over QUIC, or ignores `--origin-to-force-quic-on` with the resolver rule, the script trusts the mkcert root CA in the browser instead. The read-only `$(mkcert -CAROOT)/rootCA.pem` is imported into the browser profile's NSS database with `certutil`, and the SPKI flag is dropped.
2. If that also fails, the slice ships the curl HTTP/3 proof (AC-1) and h2 page timings only. The h3 variants are recorded as "not measured: <reason>" in the docs. The task 1 findings decide this.

**Equal bodies (Q5).** `/listings/1` is deterministic: the 6-server parity check already compares its body hash, and the page has no CSRF token, nonce or timestamp. Listing 1 exists in the seeded database. If the hashes differ, the script fails and saves both bodies for a diff; there is no normalisation.

**The AC-1 JSON (Q6).**
- **Fields:** `h3_ok` (bool), `h3_status`, `h3_alpn` (`"h3"` or null), `h2_status`, `alpn_h2` (the negotiated value, `"h2"`; renamed from `h2_alpn` in the review fix pass to match AC-1), `alt_svc` (the header string or null), `body_sha256_h2`, `body_sha256_h3`, `reason` (null or a string), and `curl_image_digest`.
- **nginx-fpm:** `curl --http3-only --max-time 5` fails, and `reason` = `"no HTTP/3: curl exit <code>"`.
- **Committed:** both JSON files and the frankenphp trace.

**Switching servers (Q7).** The scripts switch to frankenphp to measure, and a `trap` always restores nginx-fpm, also after a failure. "Leave on nginx-fpm" means the end state.

**Run validity (Q8).**
- **Protocol:** a run whose `nextHopProtocol` is not the requested protocol is invalid. Up to 3 extra attempts per variant; the variant fails if it has fewer than 5 valid runs after 8 attempts.
- **Ranges:** min and max.
- **Load time:** `loadEventEnd` of the navigation entry, which is relative to the navigation start, 0.
- **"Same output" for the default mode:** the same schema and fields, with the new fields absent.

**File layout (Q9).**
- **Folder:** `bench/results/http3/<UTC date of the run start>/`. The scripts compute the date once per run.
- **Files:** `curl-frankenphp.json`, `curl-frankenphp.trace`, `curl-nginx-fpm.json`, `lcp-h2-loss0.json`, `lcp-h3-loss0.json`, `lcp-h2-loss2.json` and `lcp-h3-loss2.json` (the LCP files carry the `lcp-` prefix since the review fix pass, so a `--variant` can never collide with the curl files).
- **Network block:** each LCP file has `network: {mode, profile: {delay_ms_each_way, down_kbit, up_kbit, loss_pct, limit}, rtt_ms_measured, qdisc: {egress_drop_rate, ingress_drop_rate}}`.

**Reaching the web container (Q10).**
- **Lookup:** the container is `ddev-interview-web`, and its IP comes from `docker inspect -f '{{(index .NetworkSettings.Networks "ddev_default").IPAddress}}'`.
- **Redaction:** every file the scripts write goes through the SL-10 redaction (IPv4 → `<ip>`, certificate subject and issuer lines removed), the `tc` and connect output included.
- **NFR-2:** the existing `BenchCommittedRunsTest` privacy patterns, extended to `bench/results/http3/`.

**Manual steps (Q11).** `manual-test.md` gets full commands: `bench/lib/netem.sh --verify`, the exact `bench/lcp.mjs` invocations, and the fingerprint, parity and suite commands. A clean tree means after the slice's commit.

**Docs (Q12).**
- **Chart:** Mermaid `xychart-beta` with load time per variant.
- **"What the numbers say":** h3 vs h2 on the clean and the lossy profile, with the medians.
- **"Interview story":** problem, method, at least one measured number.
- **Doc tests:** they match medians rounded to whole ms against the result files.
- **netem `limit`:** 1000 packets (the default), recorded.

## Open questions

Clarity gate 2026-11-05: a fresh-context reviewer raised 12 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: The sidecar's image, order, capabilities and cleanup.
- [x] Q2: Stop or opt-in fallback when `ifb` is missing.
- [x] Q3: TCP-connect RTT; loss from the qdisc counters.
- [x] Q4: The mkcert CA plan B; ship curl-only if QUIC cannot be trusted.
- [x] Q5: Deterministic body; no normalisation.
- [x] Q6: The full JSON schema; the nginx-fpm reason.
- [x] Q7: Servers switched with a trap restore.
- [x] Q8: Run validity and retries; ranges; load time.
- [x] Q9: File names and the network block.
- [x] Q10: Container lookup and redaction.
- [x] Q11: Manual commands.
- [x] Q12: Chart type and doc matching.

## Dependencies

- **Requires**: SL-10 (LCP script, SPKI trust, redaction, privacy test).
- **Blocks**: none.

---
Next: `/sdd spec-20261105-1109-http3` (approval → build)
