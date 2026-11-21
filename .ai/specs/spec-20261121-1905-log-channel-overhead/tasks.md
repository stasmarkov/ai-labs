# Bring the frankenphp log channel under 5 % p95 overhead — Tasks

**Spec**: spec-20261121-1905-log-channel-overhead | **Size**: M | **Tasks**: 5

## Phase 1: Guard and profile

- [x] 1. Characterization test of the full entry, then profile (laravel)
  Covers: AC-1, AC-3, MNC-1
  - [x] 1.1 Add `tests/Feature/FrankenphpLogEntryCharacterizationTest.php`: a test that pins the full formatted entry and the slog attributes for records with nested context, an object, an exception (with previous) and sensitive keys (`password`, `apiKey`, `access-token`, `tokens_in`), on both the `frankenphp_log` writer and the stderr line. Green on today's code.
  - [x] 1.2 Profile one entry part by part; write the split and the method in `decisions.md`.
  - [x] 1.3 **Verify**: new test green; `StructuredLogTest` green.

## Phase 2: Optimise

- [x] 2. Output-neutral optimisations chosen by the profile (laravel)
  Covers: AC-3, MNC-1, MNC-2
  - [x] 2.1 Apply the changes; re-profile; record after-numbers in `decisions.md`.
  - [x] 2.2 **Verify**: task 1 test, `StructuredLogTest`, `BenchLogOverheadTest` green; pint, phpstan green.

## Phase 3: Measure and document

- [ ] 3. Manual: run `bench/log-overhead.sh` on a clean tree
  Covers: AC-2, NFR-1, NFR-2, MNC-3
  - [ ] 3.1 **Verify**: `manual-test.md#step-2` passes; new result file committed.

- [ ] 4. Point BENCHMARKS, the guide, the lesson and the SL-16 note at the new file (docs)
  Covers: AC-4
  - [ ] 4.1 Before/after in BENCHMARKS; guide numbers and lesson `$overhead` read the new file; text matches the result (met or missed).
  - [ ] 4.2 **Verify**: `LessonContentTest`, `GuidePageTest` green.

- [ ] 5. Manual: walk manual-test.md
  Covers: MNC-4
  - [ ] 5.1 **Verify**: every step passes.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | per-part cost recorded in decisions.md | `manual-test.md#step-1` | manual | 1, 2 |
| AC-2 | new result < 5 % or miss recorded | `manual-test.md#step-2` | manual | 3 |
| AC-3 | identical entries | `tests/Feature/FrankenphpLogEntryCharacterizationTest.php` | test | 1, 2 |
| AC-4 | consumers read the new file | `tests/Feature/LessonContentTest.php`, `manual-test.md#step-3` | test | 4 |
| MNC-1 | entry shape unchanged | `tests/Feature/FrankenphpLogEntryCharacterizationTest.php`, `tests/Feature/FrankenphpLogDefaultWriterTest.php` (C-5), `manual-test.md#step-5`, `manual-test.md#step-6` | test | 1, 2, 9 |
| MNC-2 | opt-in, request id unchanged | `tests/Feature/StructuredLogTest.php`, `manual-test.md#step-4` | test | 2 |
| MNC-3 | bench method unchanged | `tests/Feature/BenchLogOverheadTest.php`, `manual-test.md#step-2` | test | 3 |
| MNC-4 | no deps, fingerprint | `manual-test.md#step-3` | manual | 5 |
| NFR-1 | difference < 5 % | `manual-test.md#step-2` | manual | 3 |
| NFR-2 | channel-off p95 not slower | `manual-test.md#step-2` | manual | 3 |

## Verification gates

- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev artisan test --compact`
- `bench/log-overhead.sh` (task 3), `bench/asset-fingerprint.sh`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green

## Phase: Gaps (converge round 1)

- [x] 6. Cap the `isSensitive()` memo at 1 024 keys (laravel)
  Covers: AC-3, MNC-1
  - `FrankenphpJsonFormatter::$sensitiveKeys` grows without limit (`??=` on every distinct key), but scope 2 asks for a bounded memo and C-9 says it caps at 1 024 keys, after which new keys are computed and not stored. A worker that logs per-entry keys (ids as keys) would grow it for its whole life.
  - [x] **Verify**: a characterization test formats a record with more than 1 024 distinct keys (sensitive and not) on one formatter instance, then the fixed record, and gets the same redaction as a fresh formatter; the memo holds at most 1 024 entries; `StructuredLogTest` and the characterization test pass unchanged.
- [x] 7. Pin the exact stderr line, the full slog attribute array and the exception trace against fixed output (laravel)
  Covers: AC-3, MNC-1
  - C-4 asks for the exact JSON line and the exact attribute array, and for the trace compared with relative paths and line numbers kept. Today the stderr test compares `$raw` with `toJsonLine()` of the same code (self-referential), the slog test pins only the first 14 keys plus spot checks, and the trace is only checked as non-empty and at most 20 frames.
  - [x] **Verify**: `FrankenphpLogEntryCharacterizationTest` compares a literal expected JSON line (paths made relative) and a literal full `slogAttributes()` array, key order included, green on the current code and on the pre-change code (`git stash` of `app/Logging`).
- [x] 8. Complete the profile in decisions.md: `frankenphp_log()` cost in worker mode and after-numbers for every part (laravel)
  Covers: AC-1, AC-2
  - Scope 1 and C-6 ask for `hrtime` around the `frankenphp_log` call in worker mode, and for N, mean us per part, PHP version and server mode. decisions.md times everything with a no-op writer (no `frankenphp_log` row, best round instead of mean), and the task 2 table has after-numbers only for `format` and `slogAttributes`. Without the `frankenphp_log` figure the share C-11 needs for the Scope 5 miss path cannot be computed. The manual-test step 1 expects a `frankenphp_log` row.
  - [x] **Verify**: decisions.md has one before/after table with processor, normalize, redact, attribute flattening, `frankenphp_log` (worker mode) and end-to-end rows, plus N, mean, PHP version and server mode; no timing code is committed (`git status` clean outside `.ai`).
- [x] 9. Resolve the default writer once, or record why the per-write closure stays (laravel)
  Covers: C-5, MNC-1
  - `FrankenphpLogHandler::write()` still builds a new static closure and calls `call_user_func('frankenphp_log', ...)` on every entry. C-5 says the default writer is resolved once (constructor or first write) and calls `frankenphp_log` directly. The task 1 profile used an injected writer, so this cost was never measured, and decisions.md does not say why it was left out.
  - [x] **Verify**: either `write()` reuses a writer resolved once (the injected-writer tests and `StructuredLogTest` pass unchanged), or decisions.md states the measured cost and why it is under the C-7 bar.

## Phase: Gaps (converge round 2)

- [ ] 10. Record the bench verdict in decisions.md and BENCHMARKS: met or missed, the C-11 `frankenphp_log()` share and the NFR-2 channel-off check (docs)
  Covers: AC-2, NFR-1, NFR-2
  - Scope 5 and AC-2 say a miss is written in `decisions.md` and BENCHMARKS with the share taken by `frankenphp_log()`, and C-11 defines that share as its us per entry / total us per entry from the profile, applied to the measured p95 difference and labelled an estimate. NFR-2 (C-10) asks for the new channel-off p95 against 8.55 to 9.28 ms, or an explanation. `decisions.md` stops at task 9 with the per-entry ratio only (about 110 us vs 5.46 us, "the bench in task 3 decides"), and no buildable task writes the verdict: task 3 is the owner's run and only checks it (`manual-test.md#step-2`), and task 4 covers AC-4 (consumers read the new file), not the decisions record or the share figure.
  - [ ] **Verify**: after task 3, `decisions.md` has a "Task 3: bench verdict" section that names the file printed after "Wrote", quotes `channel_off.p95_ms`, `channel_on.p95_ms`, `difference_percent` and `within_threshold` from it, states whether the channel-off p95 lies within 8.55 to 9.28 ms (or explains why not), and, when `within_threshold` is false, gives the C-11 estimate (share x difference in ms and %) labelled an estimate, keeps the 5 % threshold and the opt-in; BENCHMARKS.md states the same share next to the before/after table; every number matches the committed JSON.
