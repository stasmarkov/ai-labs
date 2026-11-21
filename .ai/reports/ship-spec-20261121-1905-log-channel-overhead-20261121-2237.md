# Ship report: spec-20261121-1905-log-channel-overhead (SL-60)

## Summary

SL-60 wants the opt-in `frankenphp` log channel to add less than 5 % to p95. Two changes are in: the output-neutral optimisations in `app/Logging/FrankenphpJsonFormatter.php` and `app/Logging/FrankenphpLogHandler.php`, which add a capped sensitive-key memo, a one-pass flatten and a default writer that is resolved once. Characterization tests now pin every entry byte for byte. Pint, PHPStan and the debug scan pass. The test suite fails on two `DocsLinksTest` checks because SL-59 is not named in any guide section. That failure comes from the previous slice, not this one. The bench has not been run, so the main claim (below 5 %, or a recorded miss) is still unproven. Tasks 4 and 10 depend on that run and are still open.

**Recommendation: FIX-FIRST.**

## Stack & repos

| Repo | Role | Stack | Tracked | Branch | Left from |
|------|------|-------|---------|--------|-----------|
| `.` | backend | laravel | yes | `feature/log-channel-overhead` (created) | `master` |

- No untracked or WIP repos. No repo warnings.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Present: `plugin:sdd-kit-laravel@sdd-kit`.

## Spec

- Id: `spec-20261121-1905-log-channel-overhead`
- Folder: `.ai/specs/spec-20261121-1905-log-channel-overhead` (`spec-lite.md`, `tasks.md`, `decisions.md`, `manual-test.md`)

## Investigation highlights

- The investigation phase returned no relevant files, no risks and no existing implementation.
- The files that matter, from the build: `app/Logging/FrankenphpJsonFormatter.php`, `app/Logging/FrankenphpLogHandler.php`, `tests/Feature/FrankenphpLogEntryCharacterizationTest.php` (new), `tests/Feature/FrankenphpLogDefaultWriterTest.php` (new), `tests/Feature/StructuredLogTest.php`, `tests/Feature/BenchLogOverheadTest.php`, `bench/log-overhead.sh`.

## Tasks completed

Build: 6 of 8 buildable tasks done.

- [x] 1. Characterization test of the full entry, then profile
- [x] 2. Output-neutral optimisations chosen by the profile
- [ ] 3. Manual: run `bench/log-overhead.sh` on a clean tree (manual, owner's step)
- [ ] **4. Point BENCHMARKS, the guide, the lesson and the SL-16 note at the new file: incomplete.** It needs the result file from task 3.
- [ ] 5. Manual: walk manual-test.md (manual, owner's step)
- [x] 6. Cap the `isSensitive()` memo at 1 024 keys (gap, round 1)
- [x] 7. Pin the exact stderr line, the full slog attribute array and the exception trace (gap, round 1)
- [x] 8. Complete the profile in decisions.md, including `frankenphp_log()` cost in worker mode (gap, round 1)
- [x] 9. Resolve the default writer once (gap, round 1)
- [ ] **10. Record the bench verdict in decisions.md and BENCHMARKS: incomplete (build failed).** It needs the bench numbers from task 3.

## Converge

- Rounds: 2. Stop reason: **cap**.
- Round 1 added gap tasks 6, 7, 8 and 9. All four were built and re-audited in round 2.
- Round 2 added gap task 10, the bench verdict plus the C-11 `frankenphp_log()` share and the NFR-2 channel-off check. Its build **failed** because no bench result exists yet. No round-2 gaps were built and left un-audited.
- No gaps went to the manual test plan in either round.
- Gaps left unbuilt: task 10 (failed build). The `remainingGaps` list is otherwise empty.

## Quality gates

| Repo | Gate | Status | Details |
|------|------|--------|---------|
| `.` | Pint | pass | 1100 files, no style violations (`ddev exec`) |
| `.` | PHPStan | pass | No errors (`ddev exec`) |
| `.` | Pest/PHPUnit | **fail** | 3781 passed, 3 skipped, 2 failed. Both failures are in `tests/Feature/DocsLinksTest.php`: "AC-7 every shipped AI slice in the backlog is named in a guide section" (line 631) and "AC-7 SL-44 is named once the ship commit marks it shipped" (line 643). `docsLinksUnnamedSlices()` returns `[59]`, so the shipped slice SL-59 is not named in any numbered guide section of `docs/learning-guide.md`. |
| `.` | Debug scan | pass | No `dd(`, `dump(` or `ray(` in `app/`, `routes/` or `database/` |

## Visual tests

Not run. This slice has no UI change.

## Acceptance

**Not resolved end to end.** Of the 15 steps checked, these failed:

- Hop 4, the end state: no new bench result file shows overhead below 5 %, and no miss is recorded.
- AC-2: there is no new `log-overhead.json` with `difference_percent < 5`, and no miss is recorded per Scope 5.
- AC-4: BENCHMARKS.md, the logs-restart guide and the lesson do not read a new file yet. The old file stays.
- NFR-1: `difference_percent < 5` is not shown.
- NFR-2: the channel-off p95 within 8.55 to 9.28 ms (C-10) is not shown.

Broken links: none. Every failed step depends on the bench run (task 3), which has not happened. AC-3 and MNC-1 (identical entries) are covered by the characterization tests, which pass.

E2E regression test: none was written. This is a backend-only slice with no browser E2E config.

## Confirmed review findings

All findings are minor. Nothing critical or major was confirmed.

### Minor

- F1 `app/Logging/FrankenphpLogHandler.php:112`: One-pass flatten is not output-identical to `Arr::dot` + `array_filter` when dotted keys collide. **fixed**
- F2 `tests/Feature/FrankenphpLogEntryCharacterizationTest.php:229`: Memo cap test passes even if the memo is never populated. **fixed**
- F3 `tests/Feature/FrankenphpLogDefaultWriterTest.php:7`: Default-writer test is traced to AC-1, which is the profiling-documentation criterion. **fixed**
- F4 `app/Logging/FrankenphpJsonFormatter.php:133`: Sensitive-key memo is capped by key count but not key length, and holds raw key strings for the life of a worker. **fixed**
- F5 `app/Logging/FrankenphpJsonFormatter.php:115`: Redaction checks each key segment alone, so a secret nested under split keys reaches slog unredacted. This is existing behavior, not a regression. **remaining**
- F6 `app/Logging/FrankenphpJsonFormatter.php:133`: Sensitive-key memo fills first-come and never evicts, so a long-lived worker can lock out its hot keys. **fixed**
- F7 `app/Logging/FrankenphpLogHandler.php:112`: Exception traces become 20+ separate slog attributes on the dominant `frankenphp_log()` path, and that cost was never measured. **remaining**
- F8 `tests/Feature/FrankenphpLogDefaultWriterTest.php:30`: The new default-writer test reads the handler's private `$writer` property through reflection. **fixed**
- F9 `.ai/specs/spec-20261121-1905-log-channel-overhead/tasks.md:39`: The tasks.md traceability row for AC-1 restates the criterion differently from the spec. **fixed**
- F10 `tests/Feature/FrankenphpLogDefaultWriterTest.php:20`: An arrow function in the child script has no return type. **fixed**

F5 and F7 are minor and do not block SHIP on their own.

## Fix

- Rounds: 1. Stop reason: **clean**.
- Fixed: F1, F2, F3, F4, F6, F8, F9, F10.
- Remaining: F5, F7.

## Documentation updated

None in this run. The docs phase returned nothing. The BENCHMARKS, guide, lesson and SL-16 note updates are task 4, which waits on the bench result. `decisions.md` holds the profile from tasks 1, 2, 8 and 9.

## Manual test plan

- Written to `.ai/specs/spec-20261121-1905-log-channel-overhead/manual-test.md` (6 steps).
- How to run: `ddev start`, then follow Steps 1 to 6 in order. Step 2 runs `bench/log-overhead.sh` on a clean tree and commits the result file it prints after "Wrote".
- After the steps pass, tick tasks **3** and **5** in `.ai/specs/spec-20261121-1905-log-channel-overhead/tasks.md`.

## Cost

| Phase | Agents | Tokens |
| --- | --- | --- |
| Detect | 1 | tokens: n/a |
| Spec | 1 | tokens: n/a |
| Prepare | 1 | tokens: n/a |
| Build | 8 | tokens: n/a |
| Gates | 28 | tokens: n/a |
| Converge | 2 | tokens: n/a |
| Review | 31 | tokens: n/a |
| Fix | 14 | tokens: n/a |
| Acceptance | 1 | tokens: n/a |
| TestPlan | 1 | tokens: n/a |

`tokens: n/a` means the run had no token budget. The Report agent is not in this table. Failed agent-name lookups: 0.

## Recommendation

**FIX-FIRST**, for these reasons:

1. The Pest/PHPUnit gate fails: two `DocsLinksTest` AC-7 checks fail because SL-59 is not named in any guide section. The cause predates this slice, but the suite must be green before the PR.
2. Acceptance is not resolved. The slice's main claim (p95 overhead below 5 %, or a recorded miss with the C-11 share, plus the NFR-2 channel-off check) has no evidence because `bench/log-overhead.sh` has not been run.
3. Tasks 4 and 10 are incomplete (task 10's build failed). Both wait on the bench result.
4. F5 and F7 remain. Both are minor and do not block on their own.

Next: name SL-59 in its numbered section of `docs/learning-guide.md` so `tests/Feature/DocsLinksTest.php` passes. Then run `bench/log-overhead.sh` on a clean tree (manual-test.md Step 2, task 3) and build tasks 10 and 4 from the result file.
