---
id: spec-20261121-1905-log-channel-overhead
title: Bring the frankenphp log channel under 5 % p95 overhead
type: improvement
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-21; standing approval)
approved_at: 2026-11-21T17:09:13Z
created: 2026-11-21
stacks: [laravel]
slice: SL-60
brs: [BR-21, BR-26, BR-27]
---

# Bring the frankenphp log channel under 5 % p95 overhead

## Summary

SL-16 added the opt-in `frankenphp` log channel: every entry is built by `FrankenphpJsonFormatter`, enriched by `RequestContextProcessor`, and written through `frankenphp_log()` (Caddy's structured log) or as a JSON line on stderr. Its NFR-2 asked for < 5 % extra p95 on a request that logs one entry, on worker mode. The measured result (`bench/results/restart/2026-11-17/log-overhead.json`) was **+8 %** (p95 8.96 → 9.67 ms; RPS about 8 600 → 7 100). NFR-2 was recorded as missed and the decision was left to the owner.

This slice finds where the cost goes, removes what can be removed **without changing any entry**, and measures again with the same script and method. If the target still cannot be met, the slice says so with numbers and does not move the threshold.

## Context

- Code: `app/Logging/FrankenphpLogHandler.php`, `FrankenphpJsonFormatter.php`, `RequestContextProcessor.php`; `config/logging.php` channel `frankenphp`; `app/Http/Middleware/AssignRequestId.php`; route `/debug/log-ping` (`routes/debug.php`, `DebugLogPingController`).
- Bench: `bench/log-overhead.sh` (full BR-21 ping via `bench/run.sh ping`: warm-up 30 s, measure 60 s, 3 repeats, median; `LOG_STACK=single` vs `single,frankenphp`, worker mode, `/debug/log-ping`), output `bench/results/restart/<date>/log-overhead.json`.
- Tests: `tests/Feature/StructuredLogTest.php`, `tests/Feature/BenchLogOverheadTest.php`.
- Consumers of the 2026-11-17 file: `BENCHMARKS.md` (around line 1526–1545), guide `resources/guides/logs-restart.php` numbers tab, lesson `resources/lessons/running-the-server/logs-and-a-safe-worker-restart.php` (`$overhead`).
- SL-16 spec: `.ai/specs/spec-20261117-1052-logs-worker-restart` (NFR-2, C-8 result note).
- Rules: BR-21 (bench method), BR-26 (numbers come from committed result files), BR-27 (guide duty).

Observed per-entry work today (candidates; the profile in task 1 decides):
- `RequestContextProcessor`: `config()` lookup, `request()`, route name, `Context::getHidden`, per entry.
- Formatter: Monolog `normalize()` of context and extra, then `redact()` walking every key with a `preg_replace` + `strtolower` + suffix loop per key.
- Handler: a new closure per write; `Arr::dot()` + `array_filter` on the attributes; `call_user_func('frankenphp_log', …)`.
- `frankenphp_log()` itself (Go slog → Caddy log → container stderr), which PHP code cannot make cheaper.

## Scope

### In scope

1. **Profile** one `/debug/log-ping` entry: time each part (processor, normalize, redact, attribute flattening, the `frankenphp_log` call) in worker mode, e.g. with repeated calls in a debug-only artisan run or `hrtime` around each part under load. Record the split in `decisions.md`.
2. **Optimise the PHP side without changing output**, for example:
   - memoise `isSensitive()` per key (static array, bounded size);
   - fast path in `redact`/normalize when context holds only scalars;
   - read `ai.server.mode` and the worker flag once per worker (they do not change between requests), not per entry;
   - call `frankenphp_log` directly instead of building a closure per write;
   - flatten attributes in one pass instead of `Arr::dot` + `array_filter`.
   Only changes the profile shows to matter; each keeps the entry byte-for-byte equal.
3. **Measure again** with `bench/log-overhead.sh` (unchanged method), on a clean tree, writing `bench/results/restart/<run date>/log-overhead.json`. The 2026-11-17 file stays as history.
4. **Point the consumers at the new file**: `BENCHMARKS.md` (add the new run next to the old one, before/after), the `logs-restart` guide numbers, the lesson `logs-and-a-safe-worker-restart` (`$overhead`), and the SL-16 spec C-8 note (one line pointing at SL-60).
5. **If the result is still ≥ 5 %**: write in `decisions.md` and BENCHMARKS how much of the remaining cost is `frankenphp_log()` itself, keep the 5 % threshold, keep the channel opt-in, and state the miss in the guide and lesson text (numbers from the file).

### Out of scope

- Changing the threshold, the bench method or the route under test.
- Dropping fields from the entry, sampling, or async/buffered writing that changes when an entry appears.
- Changing FrankenPHP, Caddy or Docker logging configuration (`.ddev/config.yaml` is never edited).
- Other channels.

## Must NOT change

- MNC-1: Every entry is identical: same fields, order, values, redaction (`[redacted]` for the same keys), slog level mapping and slog attribute names (`request.request_id`, `context.…`), for `frankenphp_log()` and for the stderr JSON line. `StructuredLogTest` passes unchanged.
- MNC-2: The channel stays opt-in (`LOG_STACK`), and request id handling in `AssignRequestId` is unchanged.
- MNC-3: `bench/log-overhead.sh` method and output shape are unchanged (`BenchLogOverheadTest` passes unchanged).
- MNC-4: No new dependencies, tables or routes; asset fingerprint unchanged.

## Acceptance criteria

- AC-1: WHEN the profile is done THE SYSTEM's spec folder SHALL hold, in `decisions.md`, the per-part cost of one entry before and after the change, with how it was measured.
- AC-2: WHEN `bench/log-overhead.sh` runs on a clean tree after the change THE SYSTEM SHALL write a new `log-overhead.json` (BR-21 method), and its `difference_percent` SHALL be below 5 (`within_threshold: true`) — or, if it is not, the miss is recorded per Scope 5 with the share taken by `frankenphp_log()`.
- AC-3: WHEN the optimised code logs the same records as before THE SYSTEM SHALL produce identical entries; a test compares the full formatted entry and the slog attributes for records with nested context, objects, exceptions and sensitive keys against fixed expected output.
- AC-4 (BR-26, BR-27): `BENCHMARKS.md`, the `logs-restart` guide numbers tab and the lesson `logs-and-a-safe-worker-restart` read the new result file, and the old one stays committed.

## Quality attributes

- NFR-1: Overhead — `difference_percent` < 5 in the new `log-overhead.json` (target; see AC-2 for the recorded-miss path).
- NFR-2: The channel-off path (`LOG_STACK=single`) does not get slower: its p95 in the new file is within the spread of the 2026-11-17 run, or the difference is explained.

## Boundaries

- **Always:** run PHP through `ddev`; keep tests offline; run the bench on a clean tree (commit code first); keep the old result file.
- **Ask first:** none (owner standing approval).
- **Never:** change the threshold or method; drop entry fields; edit `.ddev/config.yaml`; hand-write numbers.

## Technical context

- Worker mode keeps the handler, formatter and processor objects alive across requests (Laravel resolves the log manager once per worker unless flushed); per-worker caches must hold only values that cannot change between requests (config is read at boot; request data must stay per entry).
- `frankenphp_log()` exists only during a served request; the stderr path is used in CLI and tests.

## Decisions

- D-1: Fix the cost, keep the target. Moving the threshold would hide the miss (self-decided, standing approval).
- D-2: Optimisations must be output-neutral; anything that changes an entry is out of scope.

## Open questions

- [x] 1–14 raised by the fresh-context clarity gate on 2026-11-21; all answered below as C-1 to C-14 (owner standing approval: recommended answers).

## Clarifications

- C-1: The first full `bench/log-overhead.sh` run on a clean tree after the change is the verdict. Any extra run is committed and reported too (rename extra same-day files with a suffix such as `log-overhead-run2.json`); no run replaces another.
- C-2: "Clean tree" = no tracked or untracked changes outside `.ai/session`; the spec, `decisions.md` and the code are committed before the run. The bench run is done by the owner's session after the build merges, not inside the build workflow.
- C-3: AC-3's characterization test lives in a new file `tests/Feature/FrankenphpLogEntryCharacterizationTest.php`; `StructuredLogTest` stays unchanged (MNC-1).
- C-4: Fixed output: freeze the clock (`Carbon::setTestNow` / a fixed `DateTimeImmutable` on the record), set a fixed request id in hidden Context, build exceptions in the test at a known line; compare the exact JSON line and the exact attribute array. If a trace is emitted, compare it with file paths made relative and line numbers kept.
- C-5: The handler keeps its injectable writer callable; the default writer is resolved once (constructor or first write) and calls `frankenphp_log` directly. Tests keep using a recording writer.
- C-6: Profiling: a throwaway loop (uncommitted) of at least 100 000 entries per part in the container, plus `hrtime` around the `frankenphp_log` call in worker mode in a short-lived uncommitted patch. `decisions.md` records N, mean µs per part, PHP version and server mode. No timing code is committed.
- C-7: A part is worth optimising when it is ≥ 10 % of the PHP-side cost of one entry or ≥ 1 µs.
- C-8: Caches live on the processor and formatter instances, never in statics, so a new app or log manager starts fresh (tests that change config stay correct).
- C-9: The `isSensitive()` memo caps at 1 024 keys; when full, new keys are computed and not stored.
- C-10: NFR-2 spread = min–max of the three per-repeat p95 values in the 2026-11-17 file's `channel_off.runs` (8.55–9.28 ms).
- C-11: The share of `frankenphp_log()` = its µs per entry ÷ total µs per entry from the profile, applied to the measured p95 difference, labelled an estimate.
- C-12: Manual checks use the path the script prints after "Wrote", not a computed date.
- C-13: BENCHMARKS gets one table with rows 2026-11-17 and the new run (off p95, on p95, difference %, RPS off/on). If nothing is worth optimising, decisions.md says why and "after" equals "before". The SL-16 C-8 note gains one line: "Re-measured in SL-60: <file>".
- C-14: The fingerprint is compared with the value before this slice (`app-DNob7CFE.css`, `app-PTzu1QaN.js` on master today); phpstan is installed and configured (`ddev exec vendor/bin/phpstan analyse`).
