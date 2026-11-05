# HTTP/3 — Tasks

**Spec**: spec-20261105-1109-http3 | **Size**: M | **Tasks**: 6

## Phase 1: Bench

- [x] 1. Verify the unknowns (bench)
  Covers: AC-1, AC-2
  - [x] 1.1 Pick a pinned curl image with HTTP3 (`curl -V`); check `ifb` and `tc` availability in a NET_ADMIN sidecar; check Chromium accepts the SPKI-pinned cert over QUIC on frankenphp
  - [x] 1.2 **Verify**: findings recorded in this task with the commands and outputs
    Findings (2026-11-05, arm64 Docker host on OrbStack, kernel 7.x, frankenphp active, web container reached on `ddev_default`):
    - **curl image**: `curlimages/curl:8.11.1` has no HTTP3 (`curl --http3-only` -> "the installed libcurl version does not support this"). Alpine `curl` 8.14.1 and edge 8.23.0 have no HTTP3 in Features either. `ymuski/curl-http3` and `badouralix/curl-http3` have no linux/arm64 manifest. `alpine/curl-http3` (curl 8.11.0-DEV, quiche 0.22.0, Features `HTTP3`) works on arm64: pin `alpine/curl-http3@sha256:b0cb55696adcaf5714958926a28ce78a5fa72f6a4aa8df2ec5b72f1cde8038e3`, run with `--entrypoint curl`.
    - **h3 request**: `curl -sS -k --http3-only --resolve interview.ddev.site:443:<web ip> -w '%{http_code} %{http_version}'` returns `200 3` for `/up`, `/` and `/listings`. On `/listings/1` it fails with `curl: (8) Header without colon` right after the `HTTP/3 103` interim response with its `link:` headers: this quiche-based curl cannot parse an Early Hints interim response over h3. http3.sh must therefore either send the h3 request to a path without hints or run with `EARLY_HINTS=false`, and record which. `curl --http2` to the same URL shows `HTTP/2 103`, `alt-svc: h3=":443"; ma=2592000`, then `HTTP/2 200`.
    - **tc and ifb**: a sidecar `alpine:3.22` + `apk add iproute2` (tc iproute2-6.15.0) with only `--cap-add NET_ADMIN` and `--network container:<client>` can: add `netem delay 75ms rate 750kbit loss 2%` on `eth0`, create and raise `ifb0` (`ip link add ifb0 type ifb`), add the `ingress` qdisc on `eth0` with a `u32 match u32 0 0 action mirred egress redirect dev ifb0` filter, and add `netem delay 75ms rate 1600kbit loss 2%` on `ifb0`. No kernel module had to be loaded. The qdisc state lives in the shared netns, so later clients in it are shaped, and `tc -s qdisc` from another sidecar shows drop counters (eth0 dropped 37 of 1621 pkt, ifb0 dropped 31 of 5570 pkt after one h2 and one h3 request).
    - **RTT under the profile**: 5 TCP connects (`curl -w %{time_connect}`) gave 0.1538, 0.1547, 1.1616 (SYN lost and retransmitted), 0.1524, 0.1528 s: median 0.1538 s, inside 140-170 ms. An h3 request in the same netns returned `200 3`.
    - **Chromium, SPKI-pinned cert over QUIC**: in `mcr.microsoft.com/playwright:v1.49.1-noble` with `playwright-core@1.49.1`, args `--host-resolver-rules=MAP <host> <ip> --ignore-certificate-errors-spki-list=<spki> --enable-quic --origin-to-force-quic-on=<host>:443` loaded `/listings/1` with status 200 and `nextHopProtocol` `h3`; with `--disable-quic` the same page reports `h2`. The certificate is accepted over QUIC with the SPKI pin alone.
    - **Cleanup**: the server was switched back to nginx-fpm; nothing under `.ddev` changed; the probe script lived in `/tmp` and is removed.

- [x] 2. http3.sh (bench)
  Covers: AC-1, NFR-2
  - [x] 2.1 h3-only request, Alt-Svc on h2, ALPN, sha256 equality, redacted trace, JSON; nginx-fpm reports h3_ok false
  - [x] 2.2 **Verify**: `BenchHttp3Test` with stubbed docker/curl; live run on frankenphp and nginx-fpm

- [x] 3. netem sidecar (bench)
  Covers: AC-2, NFR-1
  - [x] 3.1 `bench/lib/netem.sh`: sidecar with `tc`, sharing the client's netns; egress and ifb ingress shaping; RTT/loss verification; fallback with reason
  - [x] 3.2 **Verify**: `BenchNetemTest` with stubs; live RTT sample 140–170 ms

- [x] 4. lcp.mjs protocol and netem modes (bench)
  Covers: AC-3, MNC-2
  - [x] 4.1 `--protocol h2|h3`, `--net netem --loss`, nextHopProtocol validity, load time; toxiproxy default unchanged
  - [x] 4.2 **Verify**: `BenchLcpTest` extended (dry-run plans for both modes; default unchanged)

## Phase 2: Measure and document

- [x] 5. Committed run and docs (measurement, docs)
  Covers: AC-4, AC-5
  - [x] 5.1 4 variants × 5 cold runs on frankenphp; http3.sh results; end on nginx-fpm
  - [x] 5.2 BENCHMARKS section and guide section 22
  - [x] 5.3 **Verify**: `BenchmarksDocTest`, `LearningGuideTest` read numbers from the result files; `BenchCommittedRunsTest` privacy grep green

- [x] 6. Parity, fingerprint and bench (checks)
  Covers: MNC-1, MNC-2, MNC-3
  - [x] 6.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `git diff HEAD -- .ddev` empty; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 6.2 **Verify**: outputs recorded here
    - After the slice commit c427ebd: `bench/run.sh ping --quick` exit 0 on a clean tree; web container offloads read back as before (tso on, gso on, gro off, udp_gso on); privacy grep over bench/results/http3 clean; `git diff HEAD -- .ddev` empty.
    - Pint passed; PHPStan `No errors` (253 files); `ddev npm run typecheck` clean; `ddev npm run build` ok; full suite `1152 passed (9909 assertions)`; after the review fix pass: `1190 passed (11925 assertions)`.
    - Fingerprint before and after the build identical: `app-CjRfEL-0.css 82042c94...ce71`, `app-BvRk9kiK.js e3b0c44298...b855`.
    - Parity: `identical` on nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane, frankenphp-worker; server back on nginx-fpm.
    - `git diff HEAD --stat -- .ddev` empty.
    - Pending: `bench/run.sh ping --quick` refuses on this dirty tree ("git working tree has changes outside bench/results/ and .ai/session/"); run it after the slice commit and record its exit code here, then tick 6.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | one h3 request, Alt-Svc | `tests/Feature/BenchHttp3Test.php` | test | 1, 2 |
| AC-2 | netem applied and verified | `tests/Feature/BenchNetemTest.php` | test | 1, 3 |
| AC-3 | lcp.mjs protocol/netem modes | `tests/Feature/BenchLcpTest.php` | test | 4 |
| AC-4 | committed 4 variants, BENCHMARKS | `tests/Feature/BenchmarksDocTest.php` | test | 5 |
| AC-5 | guide section 22 | `tests/Feature/LearningGuideTest.php` | test | 5 |
| MNC-1 | no .ddev or Caddyfile change | `manual-test.md#step-5` | manual | 6 |
| MNC-2 | toxiproxy default unchanged | `tests/Feature/BenchLcpTest.php` | test | 4 |
| MNC-3 | fingerprint, parity, suite | `manual-test.md#step-5` | manual | 6 |
| NFR-1 | same netem profile recorded | `tests/Feature/BenchmarksDocTest.php` | test | 5 |
| NFR-2 | no personal data or IPs | `tests/Feature/BenchCommittedRunsTest.php` | test | 2, 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 7. netem loss verification from qdisc counters, 20 RTT samples and the Q9 network block (bench)
  Covers: AC-2, NFR-1
  - Gap: `bench/lib/netem.sh` never reads `tc -s qdisc` drop counters, so the Q3 check "qdisc drop rate within 1-3.5 % per direction when loss is set; a 2 % profile with no measured drop fails the run" is missing; `NETEM_SAMPLES` defaults to 5, not the 20 TCP connects Q3 requires; the committed `h*-loss*.json` `net` block has no `mode`, `limit` or `qdisc: {egress_drop_rate, ingress_drop_rate}` (Q9).
  - Approach: add a `netem_read_drops` step (eth0 and ifb0 `dropped/sent`) run by `lcp.mjs` after the variant's runs; fail the variant when loss > 0 and a direction is outside 1-3.5 %; default `NETEM_SAMPLES=20`; add `mode`, `limit: 1000` and `qdisc` to `netem_profile_json`; re-run the 4 variants and update the docs if numbers change.
  - [x] 7.1 **Verify**: new `BenchNetemTest` cases (stubbed `tc -s` output: 2 % passes, 0 drops with loss 2 fails, 5 % fails; 20 samples taken); `BenchLcpTest` asserts `net.qdisc` and `net.mode` in the result; `BenchCommittedRunsTest` asserts loss2 files have both drop rates within 1-3.5 % and 20 RTT samples

- [x] 8. ifb-missing behaviour and the egress-only fallback per Clarifications Q2 (bench)
  Covers: AC-2
  - Gap: in `NETEM_APPLY_SCRIPT` `ip link add ifb0 type ifb 2>/dev/null || true` swallows the failure, and every failure ends in `applied=false` with exit 0; Q2 requires exit 2 with a message when ifb is missing, and an opt-in `--netem-fallback egress-only` (egress `delay 150ms`, loss on egress only, no rate, `network.mode: "egress-only"`). Neither exists, and `manual-test.md` step 2 runs `bench/lib/netem.sh --verify --loss 0`, which the CLI rejects (usage is `verify <client-container>`).
  - Approach: detect the ifb failure explicitly and exit 2 with a message; add the egress-only fallback flag (also accepted by `lcp.mjs --net netem`) that records `mode: "egress-only"`; fix the manual-test step 2 commands to the real CLI.
  - [x] 8.1 **Verify**: `BenchNetemTest` cases with a stubbed sidecar where `ip link add ifb0` fails: exit 2 without the flag; with `--netem-fallback egress-only` the profile JSON has `mode: "egress-only"`, delay 150 and no rate; manual-test step 2 commands run as written

- [x] 9. lcp.mjs netem mode needs 5 valid runs with up to 3 retries (Q8) (bench)
  Covers: AC-3
  - Gap: `bench/lcp.mjs` uses `MIN_VALID_RUNS = 3` and has no retry, so a netem variant with only 3 runs on the requested protocol is written (`BenchLcpTest` "marks a run invalid..." expects exit 0 with `valid_runs: 3`). Q8 requires up to 3 extra attempts per variant and a failure when fewer than 5 valid runs remain after 8 attempts.
  - Approach: in netem mode only (MNC-2 keeps the toxiproxy rule), loop the inside runs until 5 valid or 8 attempts, record `attempts`, fail with exit 1 and write nothing otherwise.
  - [x] 9.1 **Verify**: `BenchLcpTest`: 2 mismatched runs then 5 h3 runs gives `valid_runs: 5` after 7 attempts; 4 mismatches in 8 attempts exits 1 with no file; the existing toxiproxy-mode tests stay green

- [x] 10. Pin the netem sidecar image by digest (bench)
  Covers: NFR-1, AC-2
  - Gap: `bench/lib/netem.sh` defaults `NETEM_IMAGE=alpine:3.22` (a tag, not a digest) and runs `apk add iproute2 curl` at every sidecar start, so the shaping tool versions are unpinned; Boundaries say "pin images by digest" and Q1 names a digest-pinned image with `tc`. The result files do not record the sidecar image digest.
  - Approach: default `NETEM_IMAGE` to a digest-pinned image that ships `tc`, `ip` and `curl` (e.g. `nicolaka/netshoot@sha256:...`, arm64 manifest checked) and drop the runtime `apk add`; add `sidecar_image_digest` to `netem_profile_json`.
  - [x] 10.1 **Verify**: `BenchNetemTest` asserts the stubbed `docker run` uses an `@sha256:` image and no `apk add`; the profile JSON carries `sidecar_image_digest`

## Phase: Gaps (converge round 2)

- [x] 11. http3.sh JSON lacks the `alpn_h2` field AC-1 names (bench)
  Covers: AC-1
  - Gap: AC-1 requires the JSON to carry `h3_ok`, `alt_svc`, `alpn_h2` and `curl_image_digest`. `bench/http3.sh` writes only `h2_alpn` (the jq filter at the end of the script), and the committed `bench/results/http3/2026-11-05/frankenphp.json` and `nginx-fpm.json` have no `alpn_h2`. `manual-test.md` step 1 runs `jq '{h3_ok, alt_svc, alpn_h2}'` and expects `alpn_h2` `h2`, which prints `null` with the current output. `BenchHttp3Test` and `BenchCommittedRunsTest` never assert `alpn_h2`.
  - Approach: add `alpn_h2` (the same value as `h2_alpn`; keep `h2_alpn` for Q6) to the jq object in `bench/http3.sh`; re-run `bench/http3.sh` on frankenphp and nginx-fpm, or add the field to the two committed JSONs from the existing `h2_alpn`; assert it in both tests.
  - [x] 11.1 **Verify**: `BenchHttp3Test` AC-1 case asserts `alpn_h2 => 'h2'`; `BenchCommittedRunsTest` AC-1 case asserts `frankenphp.json` `alpn_h2` is `h2`; manual-test step 1 `jq '{h3_ok, alt_svc, alpn_h2}'` prints `"h2"`

- [x] 12. No guard that the default toxiproxy result keeps the same schema (bench)
  Covers: MNC-2, AC-3
  - Gap: Q8 defines "same output" for the default mode as the same schema and fields with the new fields absent. The only MNC-2 guard, `BenchLcpTest` "AC-3 MNC-2 lcp dry-run without the new flags keeps the toxiproxy plan", checks the dry-run plan keys. The real toxiproxy-mode run tests ("AC-4 lcp runs the pinned image..." and "AC-4 NFR-1 lcp throttles at the network layer...") use `toMatchArray`, which allows extra keys. Nothing fails if `protocol`, `net`, `attempts` or `load_ms`, or `next_hop_protocol`/`load_ms` in `samples`, leak into a `bench/results/lcp/` file, or if `MIN_VALID_RUNS = 3` stops applying there.
  - Approach: in `BenchLcpTest`, run the stubbed default (toxiproxy) mode and assert the exact top-level key list of the written file, the same list as before SL-12. Assert that `protocol`, `net`, `attempts` and `load_ms` are absent, and that each sample lacks `next_hop_protocol` and `load_ms`. Keep the existing "fewer than 3 valid" test as the validity-rule guard.
  - [x] 12.1 **Verify**: the new `BenchLcpTest` case passes; it fails when a netem-only field is added to the toxiproxy result

- [x] 13. Committed loss-0 variants were not produced by the shipped scripts (measurement)
  Covers: NFR-1, AC-4
  - Gap: `bench/results/http3/2026-11-05/h2-loss0.json` and `h3-loss0.json` have no `attempts` (task 9) and no `net.sidecar_image_digest` (task 10), but `h2-loss2.json` and `h3-loss2.json` have both. The loss-0 pair was therefore shaped by the old unpinned `alpine:3.22` + `apk add iproute2` sidecar, and the loss-2 pair by the digest-pinned netshoot sidecar. Boundaries say "pin images by digest", and the docs compare the loss-0 gap with the loss-2 gap ("2 % loss did not widen the gap"), so the four variants do not share one recorded client setup. `BenchCommittedRunsTest` does not assert `attempts` or `sidecar_image_digest`.
  - Approach: on frankenphp, re-run `h2-loss0` and `h3-loss0` with the current `bench/lcp.mjs --net netem`, or all 4 in one session. End on nginx-fpm. Update the BENCHMARKS.md and guide section 22 numbers if they change, and extend the `BenchCommittedRunsTest` AC-4 case to require `attempts` and one shared, non-null `net.sidecar_image_digest` across all 4 files.
  - [x] 13.1 **Verify**: `BenchCommittedRunsTest` (all 4 files carry `attempts` and the same `sidecar_image_digest` matching `^sha256:[0-9a-f]{64}$`), `BenchmarksDocTest` and `LearningGuideTest` pass; `git diff HEAD -- .ddev` empty

## Phase: Review fix pass

Every confirmed finding of the ship report was checked against the code first; all were real. Task 6 stays open for one item only: `bench/run.sh ping --quick` needs a clean tree, which exists only after the slice commit (not made here).

- [x] R1. Per-packet loss (major). Fixed. `bench/lib/netem.sh` turns `tso`, `gso`, `gro` and `tx-udp-segmentation` (UDP GSO, which quic-go uses) off on the client `eth0` and `ifb0` (inside `apply`) and on the web container's `eth0` for the run (`netem.sh web-offloads off|restore`, a sidecar with `--network container:ddev-interview-web --cap-add NET_ADMIN`, the netshoot image pinned by digest). Before values on this host: tso on, gso on, gro off, udp_gso on (client and web); after: all off; restored: equal to before, checked (`matches_before`). The original values live in a state file, so a hard-killed run still restores the true originals on the next restore; `lcp.mjs` restores from its exit handler and from SIGINT/SIGTERM. `apply` refuses to shape when tso/gso/gro stay on, and `lcp.mjs` refuses to run when the web offloads cannot be turned off (no byte-counter fallback was needed). Verified with `tc -s`: counts are now packets (avg 365 bytes per packet in a probe, MTU-bounded) and the measured per-direction rate tracks the configured 2 % (committed: h2 2.01 % egress and 2.13 % ingress, h3 1.67 % and 1.86 %).
- [x] R2. Probe traffic. Fixed. The RTT probe is `curl -I` (TCP and TLS connect plus a HEAD, no page). The netem qdiscs are re-created after the RTT verification (`tc` has no counter reset), so the drop check covers only the variant's own traffic. The accepted window is derived from the loss, 0.5 x to 1.75 x `NETEM_LOSS` (1 to 3.5 % at 2 %), tested for losses 1, 2 and 5; the counters are recorded in packets with the window.
- [x] R3. Re-run of all 4 variants with the final scripts. Done with `bench/http3-matrix.sh` in one session (5 attempts each, 5 valid, one shared sidecar digest, offload state in every file). `bench/http3.sh` re-run for frankenphp and nginx-fpm. The old `h2-loss0.json`-style files are replaced by `lcp-<variant>.json`. Tasks 13 and 7 closed by this.
- [x] R4. AC-1 field. `h2_alpn` renamed to `alpn_h2` (nothing read `h2_alpn`); h2 status must be 200 or the script exits 1 without a result; bodies on a mismatch go to `bench/results/raw/` (gitignored), never into the results tree. Spec-lite Q6 updated. Tasks 11 closed.
- [x] R5. Early Hints vs quiche curl. `http3.sh` keeps the real page `/listings/1` and runs with `EARLY_HINTS=false` through `bench/lib/env-switch.sh` (restored on exit); the JSON records `early_hints_during_h3: false` and `early_hints_reason`. The lcp variants keep the hints on (Chromium parses the 103 over QUIC).
- [x] R6. `bench/lcp.mjs`: netem mode forces and records path `direct` and rejects `--path router` (`--path` and `--path-reason` are optional there); `--variant` must match `[A-Za-z0-9._-]{1,40}` in both modes; netem results are `bench/results/http3/<date>/lcp-<variant>.json`; SIGINT and SIGTERM handlers exit through the cleanup handler (client container, sidecar shaping, web offloads), with the long container commands run asynchronously so a signal is handled while they go; a thrown navigation is an invalid attempt inside the 8-attempt cap (netem mode only, the toxiproxy mode still aborts as in SL-10); the throttle block is built from the applied netem profile (the dry run reads `netem.sh defaults`).
- [x] R7. Q7. `http3.sh` switches to frankenphp itself (`--server <name>|current`) and `bench/http3-matrix.sh` does the same for the 4 variants; both restore nginx-fpm from an EXIT trap.
- [x] R8. Guard tests. The toxiproxy result has the exact SL-10 key lists (top level, throttle, samples) and none of `protocol`, `net`, `attempts`, `load_ms`; committed files must all carry `attempts`, one shared sidecar digest, the offload state, 20 RTT samples and per-packet counters; `alpn_h2` is asserted in `BenchHttp3Test` and `BenchCommittedRunsTest`.
- [x] R9. `manual-test.md` steps 1 to 3 and 7 now use commands that run as written (netem verify with traffic and drops, matrix wrapper, single `lcp.mjs` invocation with `--variant`).
- [x] R10. Docs. BENCHMARKS.md section and guide section 22 carry the new numbers (read from the files by the doc tests); loss rates are per variant; "most runs lose nothing" removed; the forced-QUIC caveat (repeat visitor, not a first visit), the offload change and why per-packet loss matters, the quiche 103 limitation and the alpine/curl-http3 choice are written down.
- Skipped: nothing. Left for a human: commit the slice, then `bench/run.sh ping --quick` (task 6.2).
- Gates after the pass: full suite 1190 passed (11925 assertions), Pint passed, PHPStan no errors, typecheck and build ok, parity `identical` on 6 servers, fingerprint unchanged, `.ddev` diff empty, server on nginx-fpm.
