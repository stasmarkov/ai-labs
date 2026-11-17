# Ship report: structured logs and worker restart (SL-16)

## Summary

The code half of SL-16 is built: the `frankenphp` structured log channel (`FrankenphpLogHandler`, `RequestContextProcessor`, `AssignRequestId`), the `frankenphp:workers:restart` command, queue-context logging, the `LOG_PING` entry and the bench scripts `bench/restart-load.sh` and `bench/log-overhead.sh`. Pint, PHPStan, the full test suite and the debug scan all pass. The measurement half is not done. Nobody has run the restart-under-load and channel-overhead benchmarks, so BENCHMARKS.md and the guide still show placeholder numbers. AC-7 and NFR-2 therefore fail acceptance. One converge gap (task 13, restart timing in `bench/restart-load.sh`) failed to build, and the benchmark depends on it. Recommendation: **FIX-FIRST**.

## Stack & repos

| Repo | Role | Stack | Tracked | Branch | Left from |
| --- | --- | --- | --- | --- | --- |
| `/Users/stasmarkov/work/interview` | backend (+ Inertia/Vue frontend in the same repo) | laravel | yes | `feature/logs-worker-restart` (created by Prepare) | `master` |

- **WIP:** the repo is tracked, but the converge and fix work is not committed. At report time there were 18 modified files (738+/85-) and several untracked files that belong to this feature: `bench/log-overhead.sh`, `tests/Feature/BenchLogOverheadTest.php`, `tests/Feature/BenchRestartLoadTest.php` and `tests/Feature/UiAuditGuidePagesTest.php`. The last commit is `3e69da6 feat(logs): SL-16 structured logs and worker restart command`.
- Specialist agent: laravel-backend-dev.
- The Laravel manifest is untrusted because there is no trust store, so the DDEV running check was not applied to the wrapper.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Plugins present: `sdd-kit-laravel@sdd-kit`, `sdd-kit-drupal@sdd-kit`. Discovery could not read the manifests for `sdd-kit-laravel-nextjs`, `sdd-kit-devenv` and `sdd-kit`.

## Spec

- Id: `spec-20261117-1052-logs-worker-restart`
- Folder: `.ai/specs/spec-20261117-1052-logs-worker-restart` (`spec-lite.md`, `tasks.md`, `manual-test.md`)

## Investigation highlights

The Investigation phase returned no data: no key files, no risks and no existing pieces. From the build and review, the key files are:

- `app/Logging/FrankenphpLogHandler.php`, `app/Logging/RequestContextProcessor.php`, `config/logging.php`
- `app/Console/Commands/FrankenphpWorkersRestartCommand.php`, `config/ai.php` (server section)
- `app/Providers/AppServiceProvider.php` (queue context flag), `app/Http/Controllers/PingController.php` (`LOG_PING`)
- `bench/restart-load.sh`, `bench/log-overhead.sh`, `bench/lib/env-switch.sh`, `bench/ui-audit.mjs`
- `tests/Feature/StructuredLogTest.php`, `tests/Feature/WorkersRestartCommandTest.php`, `tests/Feature/BenchRestartLoadTest.php`, `tests/Feature/BenchLogOverheadTest.php`

Already shipped before this run: the spec was approved in `0411351`, and the first build of the channel and command landed in `3e69da6`.

## Tasks completed

Build: 10 of 14 complete.

Incomplete: **3, 5, 10, 13**

- **3:** run restart-under-load on worker and Octane with the full method, plus the channel overhead ping. Not done, so there are no committed result files.
- **5:** gates and ship. The build recorded it as incomplete, but `tasks.md` has it ticked. The gates pass in this run, but the commit and push have not happened.
- **10:** fill the BENCHMARKS section and the guide numbers from the result files. It is blocked on 3.
- **13:** time the restarts from the load start, not from oha's database file. The build failed. `tasks.md` has it ticked, but Converge recorded it as failed, so treat it as open until `BenchRestartLoadTest` and a live `--quick` run prove it.

Manual task 12 is not counted as built or incomplete. It is covered under Manual test plan.

## Converge

- Rounds run: 2. Stop reason: **cap**.
- **Round 1:** gap tasks 6, 7, 8, 9, 10 and 11 were appended.
  - 6: `bench/restart-load.sh` follows the spec's restart method (C-7, BR-21).
  - 7: `LOG_PING` ping entry and the channel overhead run for NFR-2.
  - 8: queue jobs log context `queue`, not `http`.
  - 9: the AC-4 test proves the per-request reset and the worker flag.
  - 10: BENCHMARKS and guide numbers come from the result files. Still open.
  - 11: the UI audit runs on `/ai/guides/logs-restart`.
  - Task 12 (live log entry and restart command on the running servers) went to the manual test plan.
- **Round 2:** gap tasks 13, 14 and 15 were appended.
  - 13: restart timing from the load start. **Failed to build.**
  - 14: the command probes the admin API before it refuses outside the web container. Built, not re-audited.
  - 15: the overhead run is named in the README, the learning guide, the manual test and the NFR-2 row. Built, not re-audited.
- Gaps left unbuilt: none listed as remaining, but task 10 is still open and task 13 failed (see above).

## Quality gates

| Repo | Gate | Status | Details |
| --- | --- | --- | --- |
| interview | Pint | pass | 1025 files (via `ddev exec`) |
| interview | PHPStan | pass | No errors (via `ddev exec`) |
| interview | PHPUnit | pass | 3322 passed, 3 skipped, 42558 assertions (via `ddev exec`) |
| interview | Debug scan | pass | No `dd(`/`dump(`/`ray(` in `app/`, `routes/`, `database/` |

## Visual tests

None ran. The feature's UI surface is the `/ai/guides/logs-restart` guide page, which gap task 11 audited with `bench/ui-audit.mjs`.

## Acceptance

- Original scenario resolved end-to-end: **no**. 17 of 19 steps passed and there are no broken links.
- Failed steps:
  - **AC-7:** `bench/restart-load.sh` records per mode and route, and BENCHMARKS reports the results with the story. No measured run exists, BENCHMARKS still says "Not measured yet", and task 13's timing bug blocks the full method.
  - **NFR-2:** overhead below 5 % on `/api/ping` p95 in worker mode (`manual-test.md#step-4`, `bench/log-overhead.sh`). Not measured.
- E2E regression test: none was written. The frontend stack declares no E2E for this feature, and the acceptance steps are backend and bench checks.

## Confirmed review findings

**Major**
- F1 `app/Providers/AppServiceProvider.php:169`: A failed queue job's exception is logged after the queue flag is cleared, so it shows context `cli`. **Fixed**
- F2 `bench/restart-load.sh:181`: Restart-window failures miss connection errors because oha's `--db-url` stores only successful requests. **Fixed**
- F3 `bench/log-overhead.sh:65`: The log-overhead baseline writes no log, so the measured channel cost also includes the `Log::info` call and the single-file write. **Fixed**
- F4 `app/Logging/FrankenphpLogHandler.php:42`: Exception and other object values in the log context are lost because they encode to `{}`. **Fixed**
- F10 `bench/log-overhead.sh:19`: The bench `.env` backup (`.env.bench-log-backup`) holds every secret and is not gitignored. **Fixed**
- F14 `bench/log-overhead.sh:65`: The log overhead bench measures the whole logging path, not the frankenphp channel. **Fixed**
- F17 `app/Logging/FrankenphpLogHandler.php:59`: The handler serializes JSON itself and discards Monolog's formatted output. **Fixed**

**Minor**
- F5 `app/Logging/FrankenphpLogHandler.php:107`: Matching sensitive keys by substring redacts token-count fields. **Fixed**
- F6 `app/Providers/AppServiceProvider.php:167`: A nested sync job inside an async job clears the queue flag for the rest of the outer job. **Fixed**
- F7 `app/Logging/RequestContextProcessor.php:35`: HTTP log entries written before `AssignRequestId` runs are labelled context `cli`. **Remaining**
- F8 `app/Console/Commands/FrankenphpWorkersRestartCommand.php:48`: The admin-API probe skips the inside-container guard whenever anything answers on `localhost:2019`. **Fixed**
- F9 `BENCHMARKS.md:1517`: BENCHMARKS.md and the guide describe the old restart-load and log-overhead method. **Remaining**
- F11 `app/Console/Commands/FrankenphpWorkersRestartCommand.php:48`: The host-side admin API probe bypasses the in-container guard and restarts whatever answers on host `localhost:2019`. **Remaining**
- F12 `app/Logging/FrankenphpLogHandler.php:15`: Log redaction misses common secret key spellings and does not look inside objects. **Remaining**
- F13 `app/Http/Controllers/PingController.php:15`: `LOG_PING` turns the unauthenticated, unthrottled `/api/ping` into a log-write amplifier. **Fixed**
- F15 `bench/restart-load.sh:181`: restart-load loads every request row into jq memory and scans it several times. **Remaining**
- F16 `bench/log-overhead.sh:66`: The log-on overhead run writes an unbounded number of lines to `laravel.log` and never truncates it. **Remaining**
- F18 `bench/restart-load.sh:75`: `restart-load.sh` re-implements the `.env` backup and restore that `bench/lib/env-switch.sh` provides. **Remaining**
- F19 `app/Console/Commands/FrankenphpWorkersRestartCommand.php:73`: The command calls the admin API over HTTP directly instead of through a Support client class. **Remaining**
- F20 `app/Console/Commands/FrankenphpWorkersRestartCommand.php:94`: The sub-command runs through the Artisan facade with its output copied by hand instead of `$this->call()`. **Fixed**
- F21 `app/Console/Commands/FrankenphpWorkersRestartCommand.php:46`: `insideContainer()` returns `?int` as a "continue" sentinel and has side effects. **Remaining**
- F22 `config/ai.php:5`: Server and infra settings were added to `config/ai.php` without the section banner every sibling key has. **Fixed**
- F23 `app/Http/Controllers/PingController.php:15`: A bench-only log toggle was added to a public API controller. **Remaining**
- F24 `bench/log-overhead.sh:33`: A dead EXIT trap is overwritten seven lines later. **Fixed**

All remaining findings are minor and do not block SHIP on their own. F9 overlaps with open task 10, which does block.

## Fix

- Rounds run: 1. Stop reason: **clean**.
- Fixed: F1, F2, F3, F4, F5, F6, F8, F10, F13, F14, F17, F20, F22, F24
- Remaining: F7, F9, F11, F12, F15, F16, F18, F19, F21, F23

## Documentation updated

The Docs phase recorded no updates. The working tree has doc edits made during build and converge: `README.md`, `docs/learning-guide.md` (I-12), `.env.example` and `manual-test.md` (gap 15), and the `resources/guides/logs-restart.php` guide (task 4). `BENCHMARKS.md` "Worker restart under load" and the guide `numbers` are still placeholders (task 10, F9).

## Manual test plan

- Written to `/Users/stasmarkov/work/interview/.ai/specs/spec-20261117-1052-logs-worker-restart/manual-test.md`. The run counted 5 steps, but the file has Steps 1 to 8 plus a rollback block.
- How to run: with DDEV started and `.env` copied to `/tmp/env.before`, follow the steps in order from the project root, switching servers as each step says, and restore with the rollback block (`cp /tmp/env.before .env && ddev server default && ddev restart`).
- Tick **task 12** in `tasks.md` once steps 1 and 2 pass: the live `frankenphp_log` entry shows in `ddev logs -s web` at info level, and the restart command works against the real servers. Steps 3 and 4 (restart-load and log overhead) can only pass after tasks 13, 3 and 10 are done.

## Cost

| Phase | Agents | Tokens |
| --- | --- | --- |
| Detect | 1 | tokens: n/a |
| Spec | 1 | tokens: n/a |
| Prepare | 2 | tokens: n/a |
| Build | 14 | tokens: n/a |
| Gates | 4 | tokens: n/a |
| Converge | 2 | tokens: n/a |
| Review | 56 | tokens: n/a |
| Fix | 26 | tokens: n/a |
| Acceptance | 1 | tokens: n/a |
| TestPlan | 1 | tokens: n/a |

"tokens: n/a" means this run had no token budget. The Report agent is not in the table.

## Recommendation

**FIX-FIRST**

- Acceptance did not resolve. AC-7 (restart-under-load results in BENCHMARKS) and NFR-2 (channel overhead below 5 %) have not been measured.
- Converge stopped at the round cap. Gap 13 failed to build, and without it the full restart-load method stops with "oha did not start within 60 s", while `--quick` places its restart windows after the load has ended.
- Tasks 3 and 10 are open, BENCHMARKS.md and the guide still hold placeholder numbers (F9), and gaps 14 and 15 were built but not re-audited.
- The converge and fix work is not committed: the working tree has modified and untracked files for this feature.
- On the positive side, all gates are green, all major review findings are fixed, and the 10 remaining findings are minor.

Next: fix task 13 in `bench/restart-load.sh` so `run_repeat` takes the load start from the running oha container (`docker inspect` `State.Running`/`State.StartedAt`) instead of waiting for `oha.db`, and make the `BenchRestartLoadTest` docker stub write `oha.db` only when the load ends. Then run tasks 3 and 10 (full restart-load on worker and Octane, `bench/log-overhead.sh`, and BENCHMARKS and guide numbers from the result files).
