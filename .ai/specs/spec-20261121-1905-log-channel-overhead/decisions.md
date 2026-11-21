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
