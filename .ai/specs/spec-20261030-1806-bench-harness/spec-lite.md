---
id: spec-20261030-1806-bench-harness
title: Benchmark harness and process-vs-thread comparison on four servers
type: feature
size: M
priority: P2
status: done
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov
approved_at: 2026-10-30T16:21:49Z
created: 2026-10-30
stacks: [laravel]
slice: SL-06
brs: [BR-20, BR-21, BR-22, BR-23, BR-24, BR-26, BR-27]
---

# Benchmark harness and process-vs-thread comparison on four servers

## Summary
The developer runs one command per scenario: it warms up, measures and repeats per the rules, and records RPS, latency percentiles, errors, spread and memory for the active server. BENCHMARKS.md gets `/api/ping` and `/listings` tables for nginx-fpm, apache-fpm, OpenLiteSpeed and FrankenPHP classic. Each table has a fair run (8 PHP workers everywhere) and a tuned run (best of 8/16/32 per server), idle and 50-user memory, and a long-request timeout comparison. A "How it works" note explains what each number means and where requests wait in each model.

## Context
- **Problem**: Four servers serve identical responses (SL-02…SL-05), but nothing is measured yet. Concurrency differs per server today: FPM `pm.max_children=8` (nginx-fpm and apache-fpm), OpenLiteSpeed `PHP_LSAPI_CHILDREN=10`, FrankenPHP default threads = 2 × CPUs = 14. No load tool is installed on the Mac. Docker (OrbStack) has 7 CPUs and 16 GB.
- **User impact**: The first defensible numbers; SL-08, SL-09, SL-15 and SL-18 reuse the harness.
- **Business rules (product-brief.md, draft)**:
  - **BR-20** Every benchmark run uses the same commit and `.env`, `APP_DEBUG=false`, `APP_ENV=production`, optimized caches, OPcache on and Xdebug off; a run that violates any of these is discarded.
  - **BR-21** Each scenario is warmed up 30 s, measured 60 s, repeated 3 times; the median run is reported with RPS, p50, p95, p99, error %, peak memory and CPU.
  - **BR-22** The load generator runs outside the web container under test.
  - **BR-23** Each comparison has a fair run (comparable concurrency limits across servers) and a best-tuned run per server, both recorded with their settings.
  - **BR-24** Results are reported as ratios against the nginx-fpm baseline plus trade-offs; the expected outcome is written down before each run and any surprise explained after.
  - **BR-26** A slice that produces measurements is done only with passing tests, a committed result table following BR-21, and a short interview story containing at least one measured number.
  - **BR-27** Every shipped slice includes a short "How it works" note.
  - **Q-02 (answered)**: spread = (max − min) / median of the 3 runs' RPS. Above 5 %, rerun the scenario once; still above, record the median marked `noisy` with its spread.

## Scope
### In scope
- `bench/run.sh <scenario>` (`ping`, `search`) for the active server, with a `--quick` mode (5 s warm-up, 15 s measure, 1 repeat) for development only.
- Load generators as pinned Docker images run as one-off containers on the `ddev_default` network: `oha` for `ping`, `k6` for `search` (random page 1–100 of `/listings?make=BMW`, as in the roadmap's example script).
- `bench/concurrency.sh <n>`: sets the PHP worker limit of the active server to `n` (FPM `pm.max_children`, OLS `PHP_LSAPI_CHILDREN`, FrankenPHP `num_threads`/`max_threads`), and `bench/concurrency.sh default` restores today's values.
- Result files under `bench/results/<date>/<server>/`, plus a summary step that picks the median run, computes spread and the `noisy` flag, and prints a BENCHMARKS.md row.
- Guarded `GET /debug/sleep/{seconds}` (1–120) for the timeout comparison.
- BENCHMARKS.md: an expectations section committed before any results; ping and search tables (fair + tuned); a memory table; a timeout table; a "How the numbers work" note; an interview story.
### Out of scope
- Worker mode (SL-08), slow I/O and healthz (SL-09), dashboards (SL-15).
- Changing app code beyond the debug sleep route.
- Production-like hardware; numbers are compared as ratios (BR-24).

## Must NOT change
- **MNC-1**: `.ddev/config.yaml` is never modified; switching still goes through `ddev server`.
- **MNC-2**: Responses do not change. After the runs, with `bench/concurrency.sh default`, fresh parity captures on nginx-fpm and frankenphp compare `identical` with their committed snapshots.
- **MNC-3**: The Pest suite and CI stay green; `/debug/sleep` returns 404 unless `DEBUG_ROUTES=true`.
- **MNC-4**: The project ends on nginx-fpm with default concurrency, `DEBUG_ROUTES` off, and `.env` back to its pre-run values.

## Acceptance criteria

**Run conditions (BR-20)**: before measuring, `bench/run.sh` checks and refuses to run (exit 2, naming the failed check) unless:
- `APP_ENV=production` and `APP_DEBUG=false` are in effect;
- config, route and view caches are built;
- OPcache is on and Xdebug is off for the serving SAPI, read from `/debug/runtime`;
- the git working tree has no changes outside `bench/results/`.

During runs the harness itself sets `.env` to `APP_ENV=production`, `APP_DEBUG=false`, `DEBUG_ROUTES=true` and restores the original `.env` afterwards, even on failure or Ctrl-C.

**Load model**: closed model, 50 concurrent connections (oha `-c 50`; k6 50 VUs looping with no think time) for every ping and search run. Generator request timeout 30 s. **Error %** = (responses outside 2xx/3xx + transport errors + generator timeouts) / all requests. k6 is configured with `summaryTrendStats` including `p(99)`.

**Traffic path (BR-22)**: load-generator containers join `ddev_default`, are capped with `--cpus=2`, and reach the DDEV router by `--add-host interview.ddev.site:<router IP>` (from `docker inspect ddev-router`), using the base URL from `ddev server --url`; TLS verification is skipped because the container does not trust mkcert. The router IP is looked up before every run. All containers share the OrbStack VM's 7 CPUs; the 2-CPU cap limits what the generator can take, and BENCHMARKS.md states this. Images are pinned by tag and their digests recorded in each result file.

- **AC-1 (BR-21)**: WHEN the developer runs `bench/run.sh ping` (or `search`) THE SYSTEM SHALL record idle memory, warm up 30 s, measure 60 s, repeat 3 times, and write one result file per run with RPS, p50, p95, p99, error %, peak memory and average CPU of the serving container(s), plus the server, concurrency setting, commit, tool versions and image tags.
- **AC-2 (Q-02)**: The summary SHALL compute spread = (max − min) / median RPS. Above 5 % THE SYSTEM SHALL rerun the 3 repeats once automatically; if still above, it SHALL mark the row `noisy` and show the spread. It SHALL report the median run (by RPS) and print a BENCHMARKS.md table row.
- **AC-3 (BR-20)**: IF any run condition fails THEN `bench/run.sh` SHALL exit 2 naming the check, without starting load.
- **AC-4 (BR-23)**: `bench/concurrency.sh <n>` SHALL set the active server's PHP worker limit to `n` and restart, and `default` SHALL restore today's values (FPM 8, OLS 10, FrankenPHP 2 × CPUs). Each result file SHALL record the setting it ran with.
- **AC-5 (BR-23)**: Fair runs SHALL use 8 PHP workers on every server. Tuned runs SHALL first run a quick sweep of 8, 16 and 32 per server and scenario, pick the setting with the highest RPS (error % ≤ 1), then run the full method with it.
- **AC-6 (BR-21, BR-24)**: BENCHMARKS.md SHALL have ping and search tables with one fair and one tuned row per server. Each row has the BR-21 columns plus spread, setting, and RPS ratio vs the nginx-fpm row of the same table.
- **AC-7 (BR-21)**: BENCHMARKS.md SHALL have a memory table: idle memory and peak memory at 50 concurrent users per server (fair setting), for the serving container(s). For OpenLiteSpeed that is the OLS container; the idle `web` container is listed separately.
- **AC-8**: `GET /debug/sleep/{seconds}` SHALL, when `DEBUG_ROUTES=true`, sleep that many seconds (1–120, else 422) and return 200 JSON with the slept seconds. WHILE `DEBUG_ROUTES` is off it SHALL return the same 404 as an unknown path.
- **AC-9**: BENCHMARKS.md SHALL have a timeout table for `/debug/sleep/30`:
  - **Rows:** each server (nginx-fpm, apache-fpm, openlitespeed, frankenphp), each tested with its defaults and with every timeout it has set to 10 s (PHP `max_execution_time`, FPM `request_terminate_timeout`, OLS external-app timeout, FrankenPHP `max_execution_time`);
  - **Columns:** HTTP status, time until the response, and which layer ended the request;
  - **Explanation:** a short note on why `max_execution_time` does not stop `sleep()` under PHP-FPM on Linux (it counts CPU time) but does under thread-safe PHP (wall-clock timers), as observed.
- **AC-10 (BR-24)**: BENCHMARKS.md SHALL contain "Expectations", written and committed before any result. It states what is expected per scenario (e.g. ping: all four close at fair settings, as DDEV's benchmark found; search: DB-bound, so server differences shrink). After the results, "Surprises" explains every result that differs from the expectation.
- **AC-11 (BR-27)**: BENCHMARKS.md SHALL contain "How the numbers work":
  - what RPS, p50/p95/p99, error % and spread mean;
  - the traffic path (load container → router → serving container → PHP worker);
  - where a request waits when all workers are busy in each model (FPM listen backlog, LSAPI connection queue, FrankenPHP request queue);
  - why only ratios are compared.
- **AC-12 (BR-26)**: BENCHMARKS.md SHALL contain an interview story of 3–6 sentences with at least one measured number from these tables.

**Measurement details (clarity gate, accepted 2026-10-30)**
- **Repeats.** Each of the 3 repeats is warm-up 30 s then measure 60 s, with no restart between repeats. If the spread exceeds 5 %, `bench/run.sh` (not the summary) runs a second set of 3; the first set is discarded and the second reported, marked `noisy` if its spread also exceeds 5 %.
- **Fair setup (8 workers, no recycling).** FPM `pm = static`, `pm.max_children = 8`, `pm.max_requests = 0`; OLS `PHP_LSAPI_CHILDREN=8` and `maxConns 8`; FrankenPHP `num_threads 8` and `max_threads 8`. `default` restores today's values: FPM dynamic/8/200, OLS 10/10, FrankenPHP unset (auto = 2 × CPUs). The tuned sweep varies the same settings, keeping the no-recycling mode.
- **Tuned sweep.** One sweep run per setting: warm-up 10 s, measure 30 s. The setting with the highest RPS among those with error % ≤ 1 wins; when RPS is within 3 %, the lower setting wins; if all exceed 1 % errors, the one with the fewest errors wins. The tuned row always gets its own full run, even when the winner is 8.
- **Containers measured.** Memory and CPU are reported separately for the serving container (`web`, or `openlitespeed` for OLS, with the idle `web` listed too) and for `db`. The router is not counted. Memory is `docker stats` MEM USAGE, sampled every 1 s; peak = max sample during measure; CPU = average of samples, in % of one CPU. Idle memory = sample after a restart and 10 s of settling, before warm-up.
- **Memory table source.** Taken from the fair ping run of each server.
- **Ratios.** Only RPS gets a ratio: fair rows vs the fair nginx-fpm row, tuned rows vs the tuned nginx-fpm row. If that baseline is `noisy`, the ratio is marked `~`.
- **Search data.** The SL-01 seed (500 dealers, 50 000 listings, fixed seed). The k6 script requests `/listings?make=BMW&page=N` with N = (iteration mod 100) + 1, the same sequence on every server; the build checks that `make=BMW` has at least 100 pages at the default page size.
- **Timeouts (AC-9).** `bench/timeouts.sh` applies, through gitignored overlays, either the defaults or every layer timeout set to 10 s at once: PHP `max_execution_time`, FPM `request_terminate_timeout`, nginx `fastcgi_read_timeout`, Apache `ProxyTimeout`, OLS external-app timeout, FrankenPHP `max_execution_time`. The DDEV router timeout stays default and is recorded. It sends `/debug/sleep/30` three times in sequence and records status, time, and which layer ended the request (from status, headers, body and that server's logs). The explanation is written from what is observed; a result that contradicts the expected CPU-time vs wall-clock behaviour goes to Surprises.
- **Caches and `.env`.** `bench/run.sh` backs up `.env` to gitignored `.env.bench-backup`, writes `APP_ENV=production`, `APP_DEBUG=false` and `DEBUG_ROUTES=true`, runs `artisan optimize` (config, events, routes, views), then checks the run conditions. On exit (also on failure or Ctrl-C) it restores `.env` and runs `optimize:clear`. `/debug/*` routes are guarded only by `DEBUG_ROUTES`, so they work in production mode.
- **Clean tree and commit.** `.env` and every overlay the scripts write are gitignored, so the clean-tree check passes. Order: commit the harness and the Expectations, then do every run at that commit (recorded in each result file), then commit results and docs.
- **Orchestrator.** `bench/suite.sh` runs the whole matrix: servers nginx-fpm, apache-fpm, openlitespeed and frankenphp (classic only; it refuses worker servers) × ping/search × fair/sweep/tuned, plus memory and timeouts. On exit it restores default concurrency, default timeouts and nginx-fpm.
- **Result files.** Raw per-run output goes to gitignored `bench/results/raw/`; one committed summary JSON per server, scenario and setting goes to `bench/results/<date>/` (fields: server, scenario, setting, commit, images and digests, per-run RPS/p50/p95/p99/error %/memory/CPU, median run, spread, noisy).
- **Concurrency overlays.** `bench/concurrency.sh` writes gitignored overlays, then restarts with `ddev restart` (FPM pool, FrankenPHP) or `ddev server <active>` re-apply (OLS stash config). The implementer uses DDEV-supported override points and documents them. `--show` prints the active server's current values.
- **Tooling.** The summary math is `bench/summary.php` (plain PHP, no Laravel), tested by Pest inside DDEV; shell scripts are tested with the stub pattern from SL-02. Tests are Pest 5.3 (the project `CLAUDE.md` is out of date). The BENCHMARKS.md doc tests are added in task 7, after the tables exist.
- **`/debug/sleep`.** Route constraint `[0-9]+` (non-numeric → 404), 1–120 (else 422 envelope), response `data.slept_seconds`, using Laravel's `Sleep` so tests can fake it.
- **Trade-offs and naming.** Each table row has a notes column for trade-offs (BR-24). "How the numbers work" is this slice's BR-27 "How it works" note.

## Boundaries
- **Always**: Pest tests for the summary math (median, spread, noisy, ratio) with fixture result files, for `/debug/sleep`, and for the run-condition checks; restore `.env`, concurrency and server on exit; record tool and image versions.
- **Ask first**: installing anything on the Mac; changing app code beyond `/debug/sleep`; more than 32 workers; a load level other than 50 users.
- **Never**: run the load generator inside the container under test; commit raw result files larger than 1 MB (summaries only, raw files gitignored); leave the project in production mode or with changed concurrency.

## Technical context
- **Related code**: `.ddev/commands/host/server` (`--current`, `--url`), `bench/parity.sh`, `bench/versions.sh`, `.ddev/servers/openlitespeed/conf/httpd_config.conf` (`PHP_LSAPI_CHILDREN=10`, `maxConns 10`), `.ddev/servers/frankenphp/` (Caddyfile, `FRANKENPHP_CONFIG` hook), `routes/debug.php` and `DebugRuntimeController` (guard pattern), `BENCHMARKS.md`.
- **Environment facts**: OrbStack VM with 7 CPUs and 16 GB; `jq` and `python3` exist on the Mac; FPM pool `pm = dynamic`, `max_children 8`, `max_requests 200`.
- **Approach**: bash orchestration on the host (`bench/run.sh`, `bench/concurrency.sh`) plus small, tested parsers; concurrency changes through committed per-server config overlays that the scripts swap in, never by editing `.ddev/config.yaml`.
- **Repos**: single repo `/Users/stasmarkov/work/interview`.

## Decisions
1. Load generators as pinned Docker images on `ddev_default`, not Homebrew (user choice, 2026-10-30).
2. Fair = 8 PHP workers everywhere; tuned = best of 8/16/32 by a quick sweep (user choice).
3. Add guarded `/debug/sleep/{seconds}` for the timeout comparison (user choice).
4. Full method in one background run, about 1.5–2 hours of load (user choice).
5. Q-02 validity rule: spread > 5 % → rerun once, then mark `noisy` (user choice).
6. oha has no public Docker image (the build's pinned `ghcr.io/hatoo/oha` tag was never pullable), so `bench/oha/Dockerfile` builds oha 1.16.0 from the release binary with SHA-256 values computed on 2026-11-01 (oha publishes none); the image ID is recorded instead of a registry digest (fix pass, 2026-11-01).
7. The nginx 10 s timeout replaces DDEV's `nginx_full/nginx-site.conf` for the short mode, because DDEV sets `fastcgi_read_timeout 10m` inside the PHP location, where a server-level snippet cannot override it; `defaults` restores DDEV's file (fix pass).
8. The clean-tree check ignores `.ai/session/`, the SDD tooling's own log, which the tooling rewrites; app code is still required to be committed (fix pass).
9. Timeout attribution reads only the request's own log lines: container output since the request started, Laravel's `storage/logs/laravel.log` and OpenLiteSpeed's `error.log`, both read inside the serving container (fix pass).

## Open questions
- [x] Assumption: BR-20, BR-21, BR-22, BR-23, BR-24, BR-26 and BR-27 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, 2026-10-30).
- [x] Clarity-gate answers folded in above (load model, CPU cap, fair setup, containers measured, plus defaults for repeats, sweep, ratios, search data, timeouts, caches/.env, commit order, orchestrator, result files, overlays, tooling, `/debug/sleep`). — **Accepted** (Stanislav Markov, 2026-10-30).

## Dependencies
- **Requires**: SL-02, SL-03, SL-05 (shipped).
- **Blocks**: SL-08, SL-09, SL-10, SL-11, SL-14, SL-15, SL-18.

---
Next: `/sdd spec-20261030-1806-bench-harness` (approval → build)
