---
id: spec-20261101-2126-classic-vs-worker
title: Classic vs worker mode benchmark and worker memory growth
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-01T19:30:56Z
created: 2026-11-01
stacks: [laravel]
slice: SL-08
brs: [BR-20, BR-21, BR-23, BR-24, BR-26, BR-27]
---

# Classic vs worker mode benchmark and worker memory growth

## Summary
The SL-06 harness is extended to the two worker-mode servers. BENCHMARKS.md then compares FrankenPHP classic with frankenphp-octane and frankenphp-worker on `/api/ping` and `/listings?make=BMW`, fair (8 PHP workers everywhere) and tuned (best of 8/16/32), and explains why the bootstrap-bound endpoint gains more than the database-bound one. A memory run sends 10 000 requests to one worker and plots its memory with and without a leaking static cache, and with and without worker recycling (`max-requests`). The guide gets a section explaining both results.

## Context
- **Problem**:
  - SL-06 measured four classic servers only. `bench/suite.sh` refuses worker servers.
  - `bench/concurrency.sh` sends frankenphp-octane and frankenphp-worker down the classic FrankenPHP thread setting (`num_threads`/`max_threads`). That changes neither Octane's `--workers` nor the worker Caddyfile's `num`, so it cannot set a worker count today.
  - Both worker servers currently run 4 workers with max-requests 500.
- **Facts**:
  - Octane's worker script (`vendor/laravel/octane/bin/frankenphp-worker.php`) loops `while ($requestCount < $maxRequests …)` with `MAX_REQUESTS` defaulting to 1000. So `0` stops a worker at once and does not mean "unlimited"; "no recycling" needs a very large value.
  - SL-07's price-cache scenario keeps a static array keyed by amount that grows with every new amount. `/debug/worker` reports `memory_bytes`, `peak_memory_bytes`, `pid` and `worker_hits`.
- **Business rules (product-brief.md, draft)**:
  - **BR-20** Every benchmark run uses the same commit and `.env`, `APP_DEBUG=false`, `APP_ENV=production`, optimized caches, OPcache on and Xdebug off; a run that violates any of these is discarded.
  - **BR-21** Each scenario is warmed up 30 s, measured 60 s, repeated 3 times; the median run is reported with RPS, p50, p95, p99, error %, peak memory and CPU.
  - **BR-23** Each comparison has a fair run (comparable concurrency limits across servers) and a best-tuned run per server, both recorded with their settings.
  - **BR-24** Results are reported as ratios against a baseline plus trade-offs; the expected outcome is written down before each run and any surprise explained after.
  - **BR-26** A slice that produces measurements is done only with passing tests, a committed result table following BR-21, and a short interview story containing at least one measured number.
  - **BR-27** Every shipped slice includes a "How it works" note and adds its section to `docs/learning-guide.md`.
  - **Q-02 (answered)**: spread > 5 % → rerun once, then mark `noisy`.

## Scope
### In scope
- `bench/concurrency.sh <n>` for frankenphp-octane (`--workers=<n>` and no recycling in the daemon command) and frankenphp-worker (`num <n>` in the worker block), plus `default` restore (4 workers, max-requests 500); `--show` prints the real values.
- `bench/suite.sh --servers <list>` so the matrix can run on `frankenphp,frankenphp-octane,frankenphp-worker` (the default list stays the four SL-06 classic servers; the timeout step is skipped for worker servers).
- Fair runs with 8 PHP workers and no recycling, a sweep of 8/16/32 and tuned runs for the three FrankenPHP servers on ping and search, with the SL-06 method, using frankenphp classic as the ratio baseline.
- `bench/memory.sh`: one-worker memory run, 10 000 requests per case, memory sampled every 500 requests.
- BENCHMARKS.md: Expectations committed before any run, the classic-vs-worker tables, the memory table, Surprises, an interview story; `docs/learning-guide.md` section.
### Out of scope
- Re-running nginx-fpm, apache-fpm and openlitespeed (their SL-06 tables stay as they are). frankenphp classic **is** re-measured in the same session as the baseline, so classic and worker numbers share one machine state; the timeout step is not repeated.
- Thread autoscaling and slow I/O (SL-09), worker restart via admin API command (SL-16).

## Must NOT change
- **MNC-1**: The SL-06 tables and summaries in `bench/results/2026-11-01/` are not modified; `bench/suite.sh` without `--servers` behaves as before.
- **MNC-2**: After the runs every server is back to its committed defaults (frankenphp threads auto; both worker servers 4 workers, max-requests 500), the project is on nginx-fpm with `.env` restored, and parity captures on frankenphp-octane and frankenphp-worker compare `identical` with their committed snapshots.
- **MNC-3**: The Pest suite and CI stay green.

## Acceptance criteria
- **AC-1 (BR-23)**: `bench/concurrency.sh <n>` on frankenphp-octane SHALL restart the daemon with `--workers=<n> --max-requests=1000000`, and on frankenphp-worker SHALL run the edited Caddyfile with `num <n>` and `MAX_REQUESTS 1000000`, through gitignored overlays. `default` SHALL restore 4 workers and 500. `--show` SHALL print `<n> workers, max-requests <m>` read from the active configuration, and 4 live worker threads SHALL be visible via the admin API `GET /frankenphp/threads` after `default`.
- **AC-2 (BR-21, BR-23)**: `bench/suite.sh --servers frankenphp,frankenphp-octane,frankenphp-worker` SHALL run fair (8 workers, no recycling), sweep (8/16/32) and tuned runs on ping and search with the SL-06 method and rules (warm-up 30 s, measure 60 s, 3 repeats, median, Q-02 rerun), and write summaries to a new `bench/results/<date>/` folder with frankenphp classic as the ratio baseline (fair vs fair, tuned vs tuned).
- **AC-3**: `bench/memory.sh` SHALL run these cases, each as 10 000 sequential requests (one at a time with `curl`, since there is one worker) to `/debug/leaks/price-cache?dealer=1&locale=en&mode=<mode>&amount=<1000000 + i>` (a new amount every request), after setting 1 worker through `bench/concurrency.sh 1 --max-requests <m>`:
  - **A, leak, no recycling** (worker servers, max-requests 1 000 000): the static cache grows;
  - **B, fixed, no recycling** (worker servers, max-requests 1 000 000): the flat baseline;
  - **C, leak, max-requests 500** (worker servers): the sawtooth;
  - **A and B on frankenphp classic** with `num_threads 1` and `max_threads 1`, where nothing survives a request, so both should be flat.

  Before the first request and after every 100 requests it SHALL read `/debug/worker` and record `memory_bytes`, `worker_hits` and `first_seen_at`. A worker restart shows as `worker_hits` going down and `first_seen_at` changing. The process id does not help here: FrankenPHP workers are threads in one process, so `pid` stays the same. The `/debug/worker` reads are requests too and count toward the worker's max-requests; the 10 000 counts only lab requests. The run is a single pass with no warm-up or repeats (BR-21 covers throughput runs, not this one), with the BR-20 run conditions and `DEBUG_ROUTES` switched in `.env` and restored on exit, as in `bench/leaks.sh`.

  Results go to `bench/results/<run-dir>/memory-<server>.json` with fields: `server`, `commit`, `date`, and per case `case`, `mode`, `workers`, `max_requests`, `samples` (list of `{requests, memory_bytes, worker_hits, first_seen_at}`), `restarts`, `growth_kib_per_1000`.
- **AC-4 (BR-24)**: BENCHMARKS.md SHALL contain "Expectations (classic vs worker)" committed before any SL-08 run: ping at least 2x the RPS of frankenphp classic in worker mode because bootstrap disappears; search at most 1.3x because the database dominates; case A memory grows roughly linearly; case B and both classic cases stay within 5 KiB per 1 000 requests; case C drops at every restart (about every 500 requests).
- **AC-5 (BR-21, BR-24)**: BENCHMARKS.md SHALL contain ping and search tables for the three FrankenPHP servers (fair and tuned rows, BR-21 columns, spread, ratio vs frankenphp classic), rendered by `bench/summary.php --tables` from the new results folder.
- **AC-6**: BENCHMARKS.md SHALL contain a memory table: per server and case, `memory_bytes` at requests 0, 1 000, 5 000 and 10 000, the number of restarts seen, the highest `memory_bytes` reached, and `growth_kib_per_1000` = the least-squares slope of `memory_bytes` over requests, computed per stretch between restarts and averaged (for case A, one stretch: the whole run).
- **AC-7 (BR-24)**: BENCHMARKS.md SHALL explain the classic-vs-worker gap with the measured numbers. The estimated bootstrap share of a request is derived as 1 − (worker p50 ÷ classic p50) at the fair setting, per endpoint, and reported for ping and search under "Surprises (classic vs worker)" or an explanation paragraph, and add an interview story of 3–6 sentences with at least one number from these tables (BR-26).
- **AC-8 (BR-27)**: `docs/learning-guide.md` SHALL contain a "Classic vs worker mode: what the numbers say" section that covers:
  - why bootstrap matters more for a tiny request;
  - how to read the memory curve;
  - why `MAX_REQUESTS=0` does not mean unlimited in Octane's worker script;
  - when recycling is a fix and when it only hides a leak;
  - links to the scripts, tables and Octane/FrankenPHP docs.

**Clarifications from the clarity gate**
- **Results folder.** Every SL-08 run goes to `bench/results/<date>-classic-vs-worker/`, with the name fixed once at the start through `BENCH_DATE`, so it never writes into the SL-06 folder `bench/results/2026-11-01/` and does not split across midnight.
- **Methods reused from SL-06 as they are** (see `.ai/specs/spec-20261030-1806-bench-harness/`):
  - **Load:** 50 concurrent connections in a closed model; oha for ping, k6 for search.
  - **Validity:** spread = (max − min) / median; above 5 %, rerun once.
  - **Resources:** container-level memory and CPU from `docker stats`.
  - **Tuned:** the highest RPS with error ≤ 1 %; within 3 %, the lower setting wins; then a full run with that setting.

  Classic frankenphp's "8 workers" means `num_threads 8` and `max_threads 8`, as in SL-06.
- **Baseline** for ratios: frankenphp if it is in `--servers`, otherwise nginx-fpm if present, otherwise the first listed server.
- **Commit.** The harness changes and the Expectations are committed before any run; every SL-08 run then happens at that commit (recorded in every result file); results and docs are committed after.
- **`bench/concurrency.sh`.** Gains `--max-requests <m>` (worker servers only). `--show` prints `<n> workers, max-requests <m>` on worker servers and `threads <num>/<max>` (or `auto`) on frankenphp classic, as before elsewhere. Overlays stay gitignored next to the existing ones, and the implementer lists their paths in the script header.
- **Tests and docs.** Tests are Pest 5.3 (project `CLAUDE.md` is out of date). The guide links at least the Laravel Octane docs (https://laravel.com/docs/13.x/octane) and FrankenPHP worker docs (https://frankenphp.dev/docs/worker/).

## Boundaries
- **Always**: test first for every script change (stub pattern from SL-06/SL-07); one commit for every run of the matrix (record it); restore defaults on exit even on failure or Ctrl-C; leave the project on nginx-fpm.
- **Ask first**: more than 32 workers; changing the SL-06 method; editing the committed stashes' default worker count.
- **Never**: modify SL-06 results; leave a worker server with changed worker count or max-requests.

## Technical context
- **Related code**:
  - harness: `bench/run.sh`, `bench/suite.sh`, `bench/concurrency.sh`, `bench/summary.php` (`--tables`, server list), `bench/leaks.sh` (`.env` switch, `--max-requests` overlay, daemon restart);
  - stashes: `.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml` (daemon command), `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp` (`num 4`, `MAX_REQUESTS 500`);
  - endpoints and tests: `app/Http/Controllers/DebugWorkerController.php`, `tests/Feature/Bench*Test.php`.
- **Environment**: OrbStack VM with 7 CPUs and 16 GB; oha 1.16.0 (local image) and k6 1.0.0.
- **Repos**: single repo `/Users/stasmarkov/work/interview`.

## Decisions
1. Compare frankenphp classic with both frankenphp-octane and frankenphp-worker (user choice, 2026-11-01).
2. Fair = 8 PHP workers everywhere, no recycling; tuned = best of 8/16/32 (user choice).
3. "No recycling" = `max-requests` 1 000 000, because `0` stops Octane's worker loop at once.
4. Memory runs use 1 worker so every request and every `/debug/worker` read hit the same worker thread.
5. Self-review (2026-11-01): re-measuring classic in the same session, sequential memory load (~80 000 requests, 20–30 min), sampling every 100 against restarts every ~500, and no recycling in fair runs were checked and kept; `memory_get_usage()` in a ZTS build reports the worker thread's own PHP heap, which is the right measure and is explained in the guide (self-decided, standing approval).
6. Dry run (2026-11-02): uncollected garbage cycles make `memory_get_usage()` climb ~4.8 KB per request in every mode and drop every ~750 requests when PHP's cycle collector runs (Octane worker, 1 thread, 4 000 lab requests). That sawtooth (~3 MB swing) is larger than case A's real leak (~0.25 KB per request), so a slope over raw readings cannot separate A from B. `bench/memory.sh` now samples `/debug/worker?gc=1`, which runs `gc_collect_cycles()` before reading memory; `memory_bytes` is retained memory and `memory_bytes_before_gc` keeps the raw value for the guide (self-decided, standing approval).
7. The same check showed a one-off warm-up step between request 0 and 100 (the lab's classes load) that gave case B a 218 KiB per 1 000 slope over 1 000 requests while samples 100…1 000 were identical. The slope now leaves out the first sample of each stretch with three or more samples (self-decided, standing approval).

## Open questions
- [x] Assumption: BR-20, BR-21, BR-23, BR-24, BR-26 and BR-27 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, standing approval 2026-11-01).
- [x] Clarity-gate answers folded in above (separate results folder, frankenphp re-measured as baseline, restarts detected by `worker_hits`/`first_seen_at` not `pid`, three memory cases plus two classic, sequential curl with new amounts and samples every 100, memory runs single-pass, SL-06 methods reused, bootstrap-share formula, slope per stretch, baseline rule, commit order, `--max-requests` flag, numeric expectations). — **Accepted** (Stanislav Markov, standing approval 2026-11-01).

## Dependencies
- **Requires**: SL-06, SL-07 (shipped).
- **Blocks**: SL-09.

---
Next: `/sdd spec-20261101-2126-classic-vs-worker` (approval → build)
