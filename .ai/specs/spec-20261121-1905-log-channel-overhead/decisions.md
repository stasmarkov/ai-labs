# Decisions: log channel overhead

## Task 1: profile of one entry (before any change)

Method: a throwaway CLI script (deleted after the run) booted the app, set a hidden `request_id` in `Context`, bound a
`GET /debug/log-ping` request and timed each stage on the same info record with an empty context. Each stage ran 2 000
warm-up calls, then 5 rounds of 20 000 calls with `hrtime()`; the figure is the best round per call. PHP 8.4.27 ZTS in
DDEV (aarch64), no opcache JIT tuning. The writer was a no-op closure, so `frankenphp_log()` itself (the Go-side slog
call) is not in these numbers; it only exists inside a FrankenPHP request and is covered by `bench/log-overhead.sh`.

| Part | Cost per entry |
|------|----------------|
| Building the `LogRecord` (datetime included) | 0.29 us |
| `RequestContextProcessor` (Context lookup, `config()`, `request()`, route, path) | 2.48 us |
| `FrankenphpJsonFormatter::format` (normalize, redact, date) | 3.40 us |
| `FrankenphpLogHandler::slogAttributes` (`Arr::dot` + filter) | 0.97 us |
| `toJsonLine` (stderr form only) | 0.44 us |
| `FrankenphpLogHandler::handle` with a no-op writer (formatter + level mapping) | 3.63 us |
| `Logger::info` end to end with a no-op writer | 7.00 us |

Reading: the PHP side of one entry is about 7 us, of which the processor is about 35 % and the formatter about 50 %.
`slogAttributes` adds about 1 us on the `frankenphp_log` path. Against the 5 % budget of a ping that takes roughly 9 ms
at p95 (about 450 us), 7 us of PHP work is about 1.5 %, so the remainder of the measured overhead sits in the
`frankenphp_log()` call and its Go-side slog handling, which a CLI profile cannot see. Task 2 therefore targets only
output-neutral savings in the measured PHP parts (processor lookups, formatter redaction key checks, `Arr::dot`), and
the bench in task 3 decides whether the target is met.

Guard: `tests/Feature/FrankenphpLogEntryCharacterizationTest.php` pins the formatted entry, the stderr line, the
`frankenphp_log` call with its slog attributes and the level mapping, green on the unchanged code.

## Task 2: output-neutral changes and after-numbers

Changes: `FrankenphpJsonFormatter::isSensitive` memoizes the verdict per raw key (the regex, `str_replace` and suffix loop
run once per distinct key instead of once per key per entry); `FrankenphpLogHandler::slogAttributes` flattens with a
single recursive pass instead of `Arr::dot` plus `array_filter` (same keys, same values, empty arrays left out).
`RequestContextProcessor` is unchanged: its cost is the `Context`, `config()` and `request()` lookups, and caching any of
them would change behaviour when they change within a process (worker mode, tests).

Same method as task 1 (2 000 warm-up calls, best of 5 rounds of 20 000, same host):

| Part | Before | After |
|------|--------|-------|
| `FrankenphpJsonFormatter::format` | 3.40 us | 1.61 us |
| `slogAttributes` | 0.97 us | 0.47 us |

The characterization test, `StructuredLogTest` and `BenchLogOverheadTest` pass unchanged. About 2.3 us saved per entry
of the 7 us PHP side; whether the 5 % target is met is decided by the bench in task 3.

## Task 8: complete profile, before and after

Setup: PHP 8.4.27 ZTS, DDEV on aarch64. CLI rows: 3 runs per column, each run 2 000 warm-up calls and 5 rounds of 20 000
calls with `hrtime()` (N = 100 000 per run); the figure is the mean of the three run means. "Before" is the `master`
formatter and handler (loaded under another namespace), "after" is the working tree. `frankenphp_log` row: server mode
`frankenphp-worker` (4 worker threads), `LOG_STACK=single,frankenphp`, `/debug/log-ping` under `oha` (50 connections,
10 s warm-up, then 30 s, twice, 4 200 to 4 400 requests/s). A short-lived, uncommitted patch put `hrtime()` around the
`frankenphp_log()` call in `FrankenphpLogHandler::write` and appended 1 sample in 50 to a file (N = 2 678 and 2 562
samples); the patch, the timing files and the `.env` change were reverted afterwards, and `laravel.log` was cut back.

| Part | Before | After | N | Mode |
|------|--------|-------|---|------|
| `RequestContextProcessor` | 2.06 us | 2.38 us (unchanged code; run means 2.09 to 2.71) | 3 x 100 000 | CLI |
| Normalize (`normalizeArray` of the extra) | 0.40 us | 0.42 us | 3 x 100 000 | CLI |
| Redact (key check + replace) | 2.74 us | 0.56 us | 3 x 100 000 | CLI |
| Formatter `format` as a whole | 4.19 us | 1.58 us | 3 x 100 000 | CLI |
| Attribute flattening (`slogAttributes`) | 1.07 us | 0.46 us | 3 x 100 000 | CLI |
| `frankenphp_log()` call (worker mode) | 106 us and 120 us mean (run 1, run 2); median 36 and 35 us; p95 311 and 455 us | same: the call is not changed | 2 678 and 2 562 | frankenphp-worker, under load |
| End to end, PHP side (`Logger::info`, no-op writer) | 8.00 us | 5.46 us | 3 x 100 000 | CLI |
| End to end with `frankenphp_log` (PHP side + flattening + call, mean) | about 122 us | about 119 us | - | worker |

Reading: the PHP work of one entry dropped by about 2.5 us (processor, normalize and redact, flattening included
about 3.1 us), mostly from the redaction memo (2.74 to 0.56 us) and the one-pass flattening. The processor figure moved
within the noise of the host (2.01 to 2.71 us for the same code), so no gain is claimed there. The `frankenphp_log()`
call is the dominant cost: about 110 us mean per entry under load (the median is 36 us, the tail comes from contention
on Go's slog output with 4 threads), roughly 12 % of the ~930 us of thread time one request takes at this rate (4 threads
at about 4 300 requests/s), and about 20 times the whole PHP side. Savings in PHP code cannot bring that share
down; what is left for the 5 % target is how often and how large the call is, which task 3's bench decides, and the
share above is the figure C-11 needs for the recorded-miss path.

## Task 9: default writer resolved once

`FrankenphpLogHandler::write()` built a static closure and called `call_user_func('frankenphp_log', ...)` on every
entry. The default writer is now resolved on the first write without an injected writer (`??=` into `$this->writer`)
and calls `frankenphp_log()` directly. Outside FrankenPHP (no function) the resolution returns null and stays a
`function_exists()` check per write before the stderr line. Injected writers are untouched. Output is identical: the
closure still flattens through `slogAttributes()`. Guarded by `tests/Feature/FrankenphpLogDefaultWriterTest.php`
(child PHP process that defines a global `frankenphp_log`), plus the characterization and `StructuredLogTest` tests
unchanged. The saving is one closure allocation and one `call_user_func` indirection per entry, well under 1 us; the
bench in task 3 decides the 5 % target.

## Bench run 2 (2026-11-21, same commit): also under load

Started after the 1-minute load average stayed below 4 for two minutes (3.74 at start), but an unrelated Node test
process (over 7 hours at about 96 % CPU) kept running and the load was back at 14 by the end; another project's install
script and the OrbStack VM manager were busy too. Result (`log-overhead-run2.json`): channel off p95 9.52 ms (repeats
9.48, 9.52, 9.58; RPS about 8 000 to 8 140), channel on 13.65 ms (repeats 12.40, 14.59, 13.65; RPS about 5 300 to
6 000), difference 43.5 %. The channel-off p95 is just above the 2026-11-17 range (8.55 to 9.28 ms).

Reading of runs 1 and 2 together: the off side moves a little with host load, the on side moves a lot (+8 % calm on
2026-11-17, +43.5 % and +83.5 % under contention). That fits the profile: the cost sits in `frankenphp_log()` and Go's
slog output, whose tail grows when threads contend for CPU, while the PHP side is about 5 us per entry. Neither run
decides AC-2; the verdict needs a run on an idle host. Status: the code change is output-neutral and merged, the
measurement is pending.

## Bench run 3 and an A/B against the old code (2026-11-22): the change is reverted

- Run 3, SL-60 code (commit 0b1b569), `bench/results/restart/2026-11-22/log-overhead.json`: channel off p95 9.94 ms,
  on 20.88 ms, difference 109.9 %. The unrelated Node test process was still at about 99 % CPU.
- Right after, the same bench on a temporary local branch with the logging code from before SL-60 (commit efa909c's
  `app/Logging`; branch deleted after the run), `log-overhead-ab-before.json`: channel off p95 11.09 ms, on 11.06 ms,
  difference -0.3 %, under the same kind of load.

Three runs of the new code (+83.5 %, +43.5 %, +109.9 %) against one run of the old code (-0.3 %) on the same host on
the same days point at the SL-60 change, not the host, even though the profile could not show why (the PHP side got
cheaper; both versions call the same `frankenphp_log()` with the same attributes). The old run's -0.3 % is also far
from SL-16's +8 %, so the host's state matters a lot and no single run is conclusive.

Decision (self-decided, standing approval): revert `app/Logging` to the code before SL-60 on master, because the only
same-conditions comparison favours it and the PHP-side saving (about 2.5 us per entry) is too small to be worth a
risk of this size. The characterization tests stay (they were green on the old code and still are); the memo-cap test
is removed with the memo. Still open: a clean A/B on an idle host (old and new code alternated, two runs each) to find
the cause, and the AC-2 verdict. NFR-1 is not met by any run of this slice.

## A/B on 2026-11-23: no regression; the revert is undone; verdict

Alternated runs, two each, files in `bench/results/restart/2026-11-23/` (`ab-summary.txt` has the load before and after
each run and the busiest processes): old-1 +91.4 %, new-1 +53.6 %, old-2 +12.5 %, new-2 +20.3 %. The host was busy in
all four (load 3.9 rising to 11 in the first, 12 to 18 in the others; the IDE peaked at about 1 000 % CPU and the Docker
VM manager at about 260 %). The old code is as noisy as the new one, so the -0.3 % of 2026-11-22 was a fluke and the
revert of 2026-11-22 was premature: it is undone (`app/Logging` and the memo-cap test are back to commit 0b1b569).

Verdict for AC-2 (miss path, Scope 5): no run of this slice is below 5 %, and none was taken on a calm host. The share
of `frankenphp_log()` in one entry is about 110 of about 115 us (estimate from the profile: CLI PHP side plus the
sampled call in worker mode), so PHP-side work cannot bring the channel under 5 %. The threshold stays at 5 %, the
channel stays opt-in, and the calm-host figure stays +8 % (2026-11-17).

Deviation from AC-4: the guide and the lesson keep reading the 2026-11-17 file, because every SL-60 file was taken on a
busy host and would show a contention figure as if it were the channel's cost. BENCHMARKS lists every SL-60 run with the
load next to it.

## Task 3: bench verdict

Per C-1 the first clean-tree run decides: `bench/results/restart/2026-11-21/log-overhead.json` (printed after "Wrote"),
`channel_off.p95_ms` 10.34273, `channel_on.p95_ms` 18.975288, `difference_percent` 83.5, `within_threshold` false. The
channel-off p95 is outside 8.55 to 9.28 ms because the host was busy (load 15 to 19, see run 1 above); every later run
was busy too, and the A/B shows old and new code alike. C-11 estimate: `frankenphp_log()` is about 110 of about 115 us
of one entry (share about 0.96), so about 8.3 ms of the 8.63 ms (about 80 of the 83.5 %) difference in that run is the
call itself; an estimate, and one that reflects contention, not the calm-host cost. **AC-2 takes the miss path: the
5 % threshold stays, the channel stays opt-in.**
