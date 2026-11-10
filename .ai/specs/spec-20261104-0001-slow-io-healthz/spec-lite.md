---
id: spec-20261104-0001-slow-io-healthz
title: Slow I/O, worker exhaustion and a health check that must stay fast
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-03T22:04:04Z
created: 2026-11-04
stacks: [laravel]
slice: SL-09
brs: [BR-40, BR-41, BR-26, BR-21, BR-27]
---

# Slow I/O, worker exhaustion and a health check that must stay fast

## Summary

Two new endpoints:
- `GET /listings/{id}/slow` reads the listing and then waits on a stub VIN API that answers after 300 ms (BR-40).
- `GET /healthz` returns 200 with no database, session or external work (BR-41).

A new bench scenario drives 100 concurrent users at the slow endpoint. Alongside, a low-rate probe hits `/healthz` and records its p95 latency and its share of errors (502, 504, timeouts). It runs on four configurations:
- **nginx-fpm** with `max_children` 8 and 32 (both static, through bench overlays);
- **frankenphp** with a fixed thread pool (today's default, 2 × CPU = 14), and with autoscaling (`max_threads` 64, `max_wait_time` 5 s).

A second workload replaces the slow HTTP call with open AI answer streams: the describe endpoint on the fake-stream provider, about 6 s each, no GPU. It shows that an SSE answer holds a PHP worker exactly like slow I/O does. Results go to `BENCHMARKS.md` as tables plus a chart, and the guide gets a section.

## Context

- **Problem**:
  - A PHP request holds its worker for as long as it waits on I/O.
  - With 8 FPM children, 100 users waiting 300 ms each queue behind each other. The health check, which needs no work at all, waits in the same queue, so a load balancer may mark a healthy server dead.
  - FrankenPHP can add threads under load (`max_threads`) and give up on a request waiting too long (`max_wait_time`).
  - Nothing in the repo shows these effects with numbers yet.
- **Link to the AI track**:
  - The owner asked why answers stream over SSE rather than Reverb or Mercure. The answer was that SSE holds a worker per open answer.
  - SL-41 measured a fresh answer at about 6 s.
  - This slice puts a number on that cost.
- **What exists**:
  - `bench/concurrency.sh` sets FPM `pm.max_children` (`pm=static`) and FrankenPHP `num_threads`/`max_threads` through gitignored overlay files. It never touches `.ddev/config.yaml`.
  - `bench/run.sh` implements the BR-21 method (warm-up 30 s, measure 60 s, 3 repeats, median), with oha and k6 as pinned containers.
  - The web container has 7 CPUs.
  - Laravel's `/up` exists, but it runs the full web stack.

## Scope

### In scope

- **The stub VIN API** is a separate DDEV service, `vinstub`, added by a new `.ddev/docker-compose.vinstub.yaml`.
  - It is a small `node:22-alpine` container serving `GET /vin/{vin}`. It answers after `VINSTUB_DELAY_MS` (default 300) with fixed JSON.
  - It stays separate so that the wait does not itself use a PHP worker.
  - It is reachable from every server mode at `http://vinstub:3000`.
- **`GET /listings/{id}/slow`** (route in `routes/catalog.php`, the same id constraint as `listings.show`):
  - loads the listing;
  - calls the stub with Laravel's HTTP client, with `ai`-style config `services.vinstub.url` and a timeout of 2 s;
  - returns JSON `{listing_id, vin, vin_report, waited_ms}`.
  - An unknown id is a 404. A stub failure or timeout is a 503 with the standard JSON error body.
  - **Gated (revised in review fix pass):** the route holds a worker on purpose, so it sits behind the `EnsureDebugRoutes` middleware (`DEBUG_ROUTES`; a plain 404 while it is off) and a `throttle:slow` limiter (`services.vinstub.per_minute`, env `VINSTUB_PER_MINUTE`, default 60 per IP). `bench/slow-load.sh` sets `DEBUG_ROUTES=true` and a raised limit for the run and restores `.env`.
- **`GET /healthz`**: registered without the `web` middleware group (no session, no cookies, no CSRF) and with no database access. It returns `200 {"status":"ok"}`. A test proves that no query runs and no session is started.
- **`bench/slow-load.sh`** (BR-21 method):
  - **Load:** `--workload http|sse` and `--users 100` (default). For `sse` the default is 30 streams, so FPM saturates without hitting the per-IP rate limit; the harness raises `AI_DESCRIBE_PER_MINUTE` for the run.
  - **Probe:** an oha container at 5 requests per second on `/healthz` for the same window, with a 10 s client timeout.
  - **Recorded:** the load's RPS, p50/p95/p99 and error share; the probe's p50/p95/p99, its 502 and 504 counts and timeouts, and its error share.
  - **Output:** one JSON per configuration and workload under `bench/results/slow-load/<UTC date>/`.
  - **`.env` handling:** like `run.sh`, it sets `APP_ENV=production` and `APP_DEBUG=false`, plus `AI_PROVIDER=fake-stream`, `AI_FAKE_TOKENS=120` and `AI_FAKE_TOKEN_DELAY_MS=50` for `sse`. It restores `.env` on exit.
- **`bench/concurrency.sh` gains an autoscale form for frankenphp:** `bench/concurrency.sh autoscale --threads <n> --max-threads <m> --max-wait <s>`. It writes `num_threads`, `max_threads` and `max_wait_time` to the same kind of overlay, `default` restores, and `--show` reports them.
- **Measured matrix** (run by `bench/slow-load-matrix.sh`): `http` and `sse` × {nginx-fpm static 8, nginx-fpm static 32, frankenphp fixed 14, frankenphp autoscale 14→64 with `max_wait_time` 5 s}.
- **Docs:**
  - a `BENCHMARKS.md` section `## Slow I/O and the health check (<run date>)`, with the tables, a Mermaid `xychart-beta` chart of health p95 per configuration and workload, "What the numbers say", "Surprises" and "Interview story";
  - guide section 19;
  - the section 16 code map, for the AI-stream workload only: a row pointing to the bench script and a "SSE holds a worker" note.

### Out of scope

- openlitespeed, apache-fpm, frankenphp-octane and frankenphp-worker in the matrix. The scripts work on them; they are not part of the committed run. The guide names them as a follow-up.
- Queued or async alternatives (Octane concurrent tasks, queues).
- Real load balancers or health-check-driven restarts.
- Changing production server defaults. Every setting is a bench overlay, restored after.

## Must NOT change

- **MNC-1**: every existing route, response and parity capture (`bench/parity.sh` identical on 6 servers). New routes are additions, and the parity capture does not include them.
- **MNC-2**:
  - **No file edits:** `.ddev/config.yaml`, the server stashes under `.ddev/servers/` and the default PHP settings stay unedited.
  - **Settings restored:** `bench/concurrency.sh default` brings back today's limits: FPM dynamic with 8 children and recycling at 200; FrankenPHP 2 × CPU.
- **MNC-3**: the asset fingerprint is unchanged, and the full suite runs offline (the stub is faked with `Http::fake` in tests).
- **MNC-4**: `bench/run.sh ping|search` behaves as before.

## Acceptance criteria

- **AC-1 (BR-40)**:
  - (Revised in review fix pass: WHILE `DEBUG_ROUTES` is on and the caller is within the `slow` limit; with `DEBUG_ROUTES` off the route answers 404, over the limit 429.)
  - WHEN `GET /listings/1/slow` is requested, THE SYSTEM SHALL answer 200 JSON with the listing id, the stub's VIN report, and `waited_ms` ≥ 300 (measured around the HTTP call), within 2 s.
  - An unknown id SHALL be a 404 before any stub call.
  - IF the stub fails or exceeds 2 s, THEN THE SYSTEM SHALL answer 503 with the standard JSON error body.
- **AC-2 (BR-41)**: WHEN `GET /healthz` is requested, THE SYSTEM SHALL answer `200 {"status":"ok"}` with no database query, no session start, no `Set-Cookie` and no outbound HTTP call.
- **AC-3**: The `vinstub` service SHALL:
  - start with `ddev start` on every server mode;
  - answer `GET /vin/{vin}` after `VINSTUB_DELAY_MS` ms (± 20 ms at no load);
  - use no PHP.
- **AC-4**: `bench/slow-load.sh --workload http` SHALL:
  - run the BR-21 method against the active server and configuration;
  - write JSON with the load and probe figures listed in Scope, the configuration (`bench/concurrency.sh --show`), the server, the commit and the method;
  - restore `.env`.
  With `--quick` it SHALL use 5 s / 15 s / 1 repeat (development only, never committed).
- **AC-5**: `bench/slow-load.sh --workload sse` SHALL do the same with `--users` concurrent `GET /listings/{id}/describe` streams. Each stream is read to `[DONE]`, and the load figures are time to `[DONE]` and the share of streams that ended with a non-200 or an incomplete stream.
- **AC-6**: `bench/concurrency.sh autoscale --threads 14 --max-threads 64 --max-wait 5` SHALL configure frankenphp with those values through an overlay (`FRANKENPHP_CONFIG`). `--show` SHALL print them and `default` SHALL remove them. On any other server it SHALL exit non-zero with a message.
- **AC-7 (BR-26, BR-21)**: The committed run SHALL cover the 8-cell matrix: 2 workloads × 4 configurations, 3 repeats each, median reported. `BENCHMARKS.md` SHALL:
  - give a table per workload (configuration, load RPS and p95, health p95, health error share with 502/504/timeout counts);
  - include one Mermaid `xychart-beta` chart of health p95;
  - include "What the numbers say", "Surprises" and "Interview story" with at least one measured number.
- **AC-8 (BR-27)**: `docs/learning-guide.md` SHALL get section `## 19. Slow I/O, worker pools and a health check that stays fast`. It SHALL:
  - walk through one slow request on FPM and on FrankenPHP;
  - explain `pm.max_children` vs threads, what `max_threads` and `max_wait_time` do, why `/healthz` skips the web middleware, and why an SSE answer is the same as slow I/O for the pool (with the measured numbers);
  - say when to choose a queued or broadcast variant;
  - link the code, scripts, results and official docs.
  The section 16 code map SHALL point to the SSE workload.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Reproducibility | Each cell's 3 repeats have a health-p95 spread recorded. A spread above 20 % is flagged in the table, not hidden. | `bench/results/slow-load/` |
| **NFR-2** | Isolation | The probe and the load run as separate containers capped at 2 CPUs each (the `run.sh` convention), and the stub as its own service, so neither takes PHP workers. | `manual-test.md#step-2` |

## Boundaries

- **Always**:
  - make every server setting through the gitignored bench overlays and restore it;
  - test with `Http::fake`;
  - leave the server on nginx-fpm with default settings at the end of every script.
- **Ask first**: editing `.ddev/config.yaml` or a server stash; adding services beyond `vinstub`; changing default worker limits.
- **Never**:
  - commit `.env` or overlays;
  - let the probe share the load container;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `bench/concurrency.sh` (overlay mechanics, `--show`, `default`);
  - `bench/run.sh` (BR-21 method, oha/k6 containers, `.env` handling, `--quick`);
  - `bench/sse.sh` (stream reading);
  - `.ddev/commands/host/server` (`ddev server` removes bench overlays on switch);
  - `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` (`frankenphp { {$FRANKENPHP_CONFIG} }`);
  - `routes/catalog.php`, `bootstrap/app.php` (where `/up` is wired);
  - `app/Services/ListingDetailService.php`;
  - `config/services.php`.
- **Approach**:
  - A `VinReportService` with an interface, so tests fake it.
  - A controller per endpoint.
  - The `/healthz` route in its own route file loaded without middleware groups in `bootstrap/app.php`.
  - Scripts in bash, following the existing bench conventions.

## Decisions (self-decided, standing approval)

1. **The stub is a separate Node container, not a Laravel route.** A stub inside the app would take a PHP worker for its own 300 ms and double the effect being measured. Node's single event loop serves many sleeping requests at once, like a real external API.
2. **Use the describe stream for the SSE workload, not docs ask.** Describe needs no embedding or retrieval, so the fake provider makes it a pure "worker held for 6 s" load with no GPU and no rate-limit-by-design (the docs throttle is 10 per minute).
3. **The 8-cell matrix uses nginx-fpm and frankenphp only.** They are the two models the backlog asks about (process pool vs thread pool). The other four servers would quadruple a ~40-minute run without a new lesson; the scripts support them later.
4. **Autoscale 14 → 64 with `max_wait_time` 5 s.** 14 is today's fixed default (2 × 7 CPUs), so the only change is the ability to grow. 64 is above 100 × 300 ms ÷ 1 s ≈ 30 needed for the HTTP workload. 5 s is below the probe's 10 s client timeout, so a request that waited too long comes back as an error the probe sees, not a silent hang.
5. **`/healthz` skips the web middleware.** A health check that starts a session writes to Redis and sends a cookie on every probe. The lesson is to answer from the cheapest path that still proves PHP runs.
6. **30 concurrent streams for the SSE workload.** With 8 FPM children that is nearly 4 times the pool, enough to show queueing, and well below what makes the fake provider itself the bottleneck.

## Clarifications (binding; resolve the clarity gate)

**The FPM cells (Q1).** Both FPM cells are `bench/concurrency.sh` overlays, so they are comparable: `pm=static` with 8 and with 32 children, and no recycling. Today's dynamic default is restored afterwards and is not a measured cell. The table labels them "static 8" and "static 32".

**Who switches servers (Q2).** `bench/slow-load.sh` measures the active server and configuration only. It never switches servers or overlays, and it leaves both as it found them. A wrapper, `bench/slow-load-matrix.sh`, runs the 8 cells in order:
1. switch the server and apply the overlay;
2. run both workloads;
3. after the last cell, restore with `concurrency.sh default` and end on nginx-fpm.

**Caching (Q3).** The describe stream has no cache (only docs ask has the SL-40 cache), so every stream holds a worker for the full fake answer. Each request uses a different listing id in rotation (1–50 000, which exist in the bench database), so no layer can serve a repeat.

**Route (Q4).** The load and the probe go through the DDEV router, exactly as `bench/run.sh` does (`--add-host` to the router IP, `https://ai-lab.ddev.site`), because a real health check also comes through a proxy. 502 and 504 can therefore come from the router (Traefik) or the server; the JSON records which server responded when the header shows it. The guide explains the difference.

**Probe definitions (Q5) (revised in review fix pass).**
- **Window:** the probe runs only in the 60 s measure window, once per repeat, at 5 requests per second with a 10 s client timeout. It opens 50 connections (5 per second x 10 s) and oha runs with `--latency-correction`, so a stuck request never pauses the schedule (coordinated omission).
- **Two p95 figures:**
  - `health_p95_answered_ms`: over requests that got any HTTP response (oha's own p95).
  - `health_p95_ms`: over **all sent probes**, an unanswered probe (timeout or transport error) counting as the 10 s timeout value. The table and the chart use this one. It is read off oha's percentile points (p10 ... p99.99) by linear interpolation; when more than 5 % of the probes were unanswered it is the timeout value.
- **p50 and p99:** over answered requests only.
- **Starved:** when no probe was answered, `health_p95_answered_ms`, p50 and p99 are null and `starved: true`. The flag is carried into the median block (`median.health_starved`, true when any repeat was starved) and the printed table marks the cell "starved". No made-up number is shown as a measurement.
- **Error share:** (non-2xx + timeouts) / requests sent, unchanged.
- **Breakdown:** 502, 504, other 5xx, other non-2xx and timeouts are counted separately.
- **Spread:** (max - min) / true median; when the median is 0 but the max is not, the spread is null and printed "n/a (median 0)".

**The 503 body (Q6).** The slow endpoint always answers JSON: the route has the `api`-style JSON error rendering by forcing `Accept: application/json` in a small route middleware. Its error body is the app's standard one: `{"success":false,"status":503,"message":"VIN service unavailable.","errors":{}}`.

**The VIN and the stub (Q7).**
- **The VIN:** listings have no VIN column, so it is derived deterministically, `'AP' . str_pad((string) $id, 15, '0', STR_PAD_LEFT)` (17 characters).
- **The stub's reply:** `{"vin":"…","make_check":"ok","recalls":0,"source":"vinstub"}`, returned under `vin_report`.
- **Config:** `services.vinstub.url` (env `VINSTUB_URL`, default `http://vinstub:3000`) and `services.vinstub.timeout` (2 s total, connect 1 s), no retries.

**Testing `waited_ms` (Q8).** Tests use `Http::fake` with a sequence that sleeps 300 ms via a callback in one test, which asserts `waited_ms` ≥ 300 and < 2 000. The other tests use instant fakes. "Within 2 s" means the stub call's total timeout.

**`/healthz` details (Q9).**
- **Wiring:** registered in `bootstrap/app.php` through `withRouting(then: …)` loading `routes/health.php` with no middleware group, so `/up` and route caching are unaffected.
- **No throttling.**
- **Maintenance mode:** it answers 503, like everything else, because the global maintenance middleware still runs. That is correct, since a server in maintenance is not healthy for traffic, and the guide says so.
- **No session:** the test asserts no `Set-Cookie` and that `session()->isStarted()` is false.

**SSE load (Q10).**
- **Concurrency:** a closed loop of `--users` concurrent workers. Each opens a stream, reads to `[DONE]`, then immediately opens the next one, for the window.
- **Reader:** a k6 script, `bench/k6-sse.js`, pinned like `k6-search.js`, which reads each response body to the end.
- **Rate limit:** `AI_DESCRIBE_PER_MINUTE` is raised to 100000 for the run.
- **Figures:** time to `[DONE]` p50, p95 and p99; streams per second; incomplete or non-200 share.

**Spread (Q11).** (max − min) ÷ median of the 3 repeats, per metric, recorded for every figure. The table flags health p95 with "⚠" above 20 %.

**The stub on every server (Q12).** Task 1.2 checks the stub from all 6 servers with one `curl` per server. "± 20 ms" means the median of 10 idle calls is between 280 and 320 ms. "No PHP" means it runs in its own container (`docker ps` shows `node`).

**Overlay mechanics (Q13).**
- **Restart:** `concurrency.sh autoscale` restarts DDEV itself, as its other forms do.
- **Overlay content:** `FRANKENPHP_CONFIG` gets `num_threads 14`, `max_threads 64` and `max_wait_time 5s` (Caddy duration syntax).
- **`--show`:** prints `autoscale: 14 threads, max 64, max_wait 5s`.

**Committed results (Q14).**
- **`--quick` runs** write to `bench/results/raw/` only (gitignored), never to `bench/results/slow-load/`.
- **Committed runs** are the matrix wrapper's output.
- **Tests:** `BenchmarksDocTest` reads the numbers from the committed JSON files (whichever dated directory the section links) and checks that the section quotes them.

**Data and guide numbering (Q15).**
- **Listings:** ids 1–50 000 exist in the dev database used by the bench (`APP_ENV=production` only changes the environment flags, not the database).
- **Guide numbering:** sections 17 and 18 exist (SL-40, SL-41), so this is section 19.

## Open questions

Clarity gate 2026-11-04: a fresh-context reviewer raised 15 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: Both FPM cells are static overlays.
- [x] Q2: A matrix wrapper switches servers; the measuring script does not.
- [x] Q3: No cache on describe; ids rotate.
- [x] Q4: Everything goes through the DDEV router.
- [x] Q5: Probe definitions.
- [x] Q6: The slow endpoint always answers JSON.
- [x] Q7: VIN derivation, stub JSON and timeouts.
- [x] Q8: The `waited_ms` test.
- [x] Q9: `/healthz` wiring, maintenance mode, the session check.
- [x] Q10: A closed-loop k6 SSE reader.
- [x] Q11: The spread formula and flag.
- [x] Q12: The stub checked on 6 servers.
- [x] Q13: Overlay restart and syntax.
- [x] Q14: Where results are written.
- [x] Q15: Data and section numbering.

## Dependencies

- **Requires**: SL-06 (bench harness, `concurrency.sh`), SL-20/SL-22 (describe stream, fake provider).
- **Blocks**: SL-10 onward (server track).

---
Next: `/sdd spec-20261104-0001-slow-io-healthz` (approval → build)
