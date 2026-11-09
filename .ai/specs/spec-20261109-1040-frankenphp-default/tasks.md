# FrankenPHP default — Tasks

**Spec**: spec-20261109-1040-frankenphp-default | **Size**: M | **Tasks**: 5

## Phase 1: Commands

- [x] 1. Default in the server command and ddev setup (devops)
  Covers: AC-1, AC-2, MNC-1, MNC-3
  - [x] 1.1 `DEFAULT_SERVER`, `--default`, `default` target, no-args output and not-set-up hint in `.ddev/commands/host/server`
  - [x] 1.2 `.ddev/commands/host/setup`: start, switch to default, keys if missing, migrate, idempotent
  - [x] 1.3 **Verify**: `ServerDefaultCommandTest` with stubbed ddev; live: move overlay aside, `ddev setup`, hub answers; second run says already set up; restore

## Phase 2: Scripts and tests

- [x] 2. Bench restore targets (bench)
  Covers: AC-3, MNC-3
  - [x] 2.1 `bench/lib/default-server.sh` (`restore_default_server`, read once, exit 2 if empty); every restore in bench/*.sh and bench/lcp.mjs uses it; comments and usage updated; measurement lists unchanged; list the tests that pinned nginx-fpm restores (grep) and update them
  - [x] 2.2 **Verify**: existing Bench*Test updated for the restore target; grep check in a test

## Phase 3: Docs

- [x] 3. README, CLAUDE.md, guide, BENCHMARKS, manual tests (docs)
  Covers: AC-4, MNC-4
  - [x] 3.1 Default-server text and `ddev setup`; guide section 2 note; BENCHMARKS top note; manual-test plans' resting server lines
  - [x] 3.2 **Verify**: doc test (README and CLAUDE.md never call nginx-fpm the default; mention ddev setup)

## Phase 4: Checks

- [x] 4. Switch this machine and run the checks (checks)
  Covers: AC-5, MNC-1, MNC-2, MNC-5
  - [x] 4.1 `ddev server default`; full suite, Pint, PHPStan, typecheck, build; fingerprint; parity on 6 servers; `git diff HEAD -- .ddev/config.yaml` empty
  - [x] 4.2 **Verify**: outputs recorded here: default switched (Active: frankenphp); suite 1270 passed; Pint, PHPStan, typecheck, build clean; fingerprint unchanged (app-DNob7CFE.css, app-PTzu1QaN.js); parity identical x6; config.yaml diff empty

- [x] 5. Bench on a clean tree (checks)
  Covers: AC-5
  - [x] 5.1 After the commit, `bench/run.sh ping --quick` on the default server, exit 0
  - [x] 5.2 **Verify**: recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | --default, default, hint | `tests/Feature/ServerDefaultCommandTest.php`, `manual-test.md#step-1` | test | 1 |
| AC-2 | ddev setup idempotent, hub answers | `tests/Feature/ServerDefaultCommandTest.php`, `manual-test.md#step-1` | test | 1 |
| AC-3 | scripts restore the default | `tests/Feature/BenchScriptsTest.php`, `manual-test.md#step-4` | test | 2 |
| AC-4 | docs name the default | `tests/Feature/DocsDefaultServerTest.php`, `manual-test.md#step-5` | test | 3 |
| AC-5 | end state and checks | `manual-test.md#step-3` | manual | 4, 5 |
| MNC-1 | config.yaml unchanged | `manual-test.md#step-3` | manual | 4 |
| MNC-2 | parity and fingerprint | `manual-test.md#step-3` | manual | 4 |
| MNC-3 | every server reachable | `manual-test.md#step-2` | manual | 1, 2 |
| MNC-4 | history unchanged | `tests/Feature/BenchmarksDocTest.php`, `manual-test.md#step-6` | test | 3 |
| MNC-5 | suite offline | `verify full suite green`, `manual-test.md#step-6` | test | 4 |

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

- [x] 6. ddev setup aborts when the Mercure keys are only partly present (devops)
  Covers: AC-2
  - Evidence: `.ddev/commands/host/setup` `keys_present` counts a key as missing when any one key is absent or empty in either file (Q2), then runs `ddev artisan mercure:keys` without `--force`. `app/Console/Commands/MercureKeysCommand.php` `handle()` returns FAILURE ("already holds Mercure keys. Use --force") as soon as either file has any non-empty key, so with `set -Eeuo pipefail` setup exits 1 in exactly the partial case Q2 says it repairs (e.g. `.env` with `MERCURE_PUBLISHER_JWT_KEY=` and `MERCURE_SUBSCRIBER_JWT_KEY=b`). The test `AC-2 setup treats an empty key as missing and never forces` passes only because its stub `ddev artisan mercure:keys` always succeeds.
  - Approach: make setup handle the partial state without `--force` (e.g. copy a complete key pair from the file that has one into the file that lacks it, else stop with exit 2 and a message naming the missing keys and `ddev artisan mercure:keys --force`), and make the stub in `ServerDefaultCommandTest` refuse like the real command when a key is already present.
  - [x] 6.1 **Verify**: `ddev artisan test --compact tests/Feature/ServerDefaultCommandTest.php` with a stub that mirrors the real refusal; a partial-key case (one key empty in `.env`, complete pair in `.ddev/.env.web`) ends with exit 0 and both keys non-empty in both files, or exit 2 with the named message, and never calls `mercure:keys --force`.

- [x] 7. Run the live AC-2 check (manual-test step 1) and record it (checks)
  Covers: AC-2
  - Evidence: tasks 1 to 4 all report "I did not run the live `ddev setup` check"; subtask 1.3 is ticked although its live part (move the overlay aside, `ddev setup`, hub answers, second run says already set up) was never run. The Traceability row for AC-2 names `manual-test.md#step-1`, which has no recorded result.
  - Approach: after task 6, run `.ai/specs/spec-20261109-1040-frankenphp-default/manual-test.md` step 1 as written (backup under `/tmp/sl47-<pid>/`), then record the outputs here.
  - [x] 7.1 **Verify**: recorded here: `ddev server --default` prints `frankenphp`; `ddev server` with the overlay moved aside prints `Not set up: run ddev setup`; first `ddev setup` prints `Set up: frankenphp`; curl to `/.well-known/mercure?topic=x` prints `200 text/event-stream`; second `ddev setup` prints `Already set up: frankenphp`; `ddev server --current` is `frankenphp`. Recorded (live run): `ddev server --default` -> `frankenphp`; `ddev server` with every activated file moved aside -> `Active: nginx-fpm (default: frankenphp)` and `Not set up: run ddev setup`; first `ddev setup` -> `Set up: frankenphp`; curl -> `200 text/event-stream`; second `ddev setup` -> `Already set up: frankenphp`; `ddev server --current` -> `frankenphp`; `.ddev/config.yaml` diff empty. Finding: the plan moved only 3 files aside and left `.ddev/web-build/Dockerfile.frankenphp`, so `ddev start` failed on the missing Caddyfile; the plan now moves all five activated files, which is what a fresh clone looks like.

- [x] 8. Learning guide: default-server note in section 2 and no "nginx-fpm by default" line (docs)
  Covers: AC-4
  - Evidence: AC-4 requires the note in the guide's section 2 ("Switching servers and proving they answer the same", `docs/learning-guide.md:33`), but the note was added under section 1 (`docs/learning-guide.md:13`). Two lines below it, `docs/learning-guide.md:15` still says DDEV starts "a `web` container (nginx + PHP-FPM by default)". `DocsDefaultServerTest` only checks that the note string exists somewhere in the guide.
  - Approach: put the "Default server" note (frankenphp, `ddev setup` on a fresh clone, `ddev server default`, other servers one command away) in section 2; reword the section 1 line so nginx + PHP-FPM is what `config.yaml` starts before `ddev setup`, not the default; extend the doc test to assert the note sits between the `## 2.` and `## 3.` headings and that the guide has no "nginx + PHP-FPM by default".
  - [x] 8.1 **Verify**: `ddev artisan test --compact tests/Feature/DocsDefaultServerTest.php` passes, and the new assertion fails when the note is moved back to section 1.

- [x] 9. Manual-test plans still end on, or call, nginx-fpm the default server (docs)
  Covers: AC-4
  - Evidence: Q7 covers every `.ai/specs/*/manual-test.md` whose rollback or ending sends the reader back to nginx-fpm, but only 4 plans changed, and only their precondition lines. Still unchanged: `spec-20261101-1822-octane-leak-lab/manual-test.md:8` "You start on the default server, nginx-fpm", `:16` "ddev server nginx-fpm  # back to the default server", `:259` "The project should end on nginx-fpm"; `spec-20261104-1944-export-offload/manual-test.md:12` "Always run `ddev server nginx-fpm` when you finish a server loop"; `spec-20261029-1013-apache-fpm-parity/manual-test.md:206` "Step 11: switch back to nginx-fpm"; `spec-20261029-1221-frankenphp-classic/manual-test.md:88` "Then `ddev server nginx-fpm` to go back"; plus the rollback/ending `ddev server nginx-fpm` lines in the classic-vs-worker, octane-worker-mode, bench-harness, local-model-wiring, streamed-car-description, listing-embeddings, docs-chat-answer, docs-index-retrieval and ai-lab-ui plans. The edited apache-fpm-parity precondition also contradicts itself ("on the default server" and "no `.ddev/config.zz-server.yaml`", but the default needs the overlay). The doc test matches only `- (Project running on|You are on|Start on) nginx-fpm` bullets, so none of these fail it.
  - Approach: change every rollback, "go back", "end on" and "back to the default server" line that targets nginx-fpm as a resting state to `ddev server default`, and leave steps that measure nginx-fpm on purpose. Fix the apache-fpm-parity precondition. Widen the doc test to fail on `end on nginx-fpm`, `back to nginx-fpm`, `default server, nginx-fpm` and `ddev server nginx-fpm` followed by a "back"/"default" comment.
  - [x] 9.1 **Verify**: `grep -rniE 'end on (`)?nginx-fpm|back to (the default server|nginx-fpm)|default server, nginx-fpm|switch back to nginx-fpm' .ai/specs/*/manual-test.md` finds no resting-state line, and `ddev artisan test --compact tests/Feature/DocsDefaultServerTest.php` passes and fails when one such line is put back.

- [x] 10. The doc test misses "nginx-fpm is the default" (tests)
  Covers: AC-4
  - Evidence: Q6 fixes the pattern `/(default|by default)[^.\n]{0,40}nginx-fpm|nginx-fpm[^.\n]{0,20}(is|by) (the )?default/i`. `tests/Feature/DocsDefaultServerTest.php` uses `/nginx-fpm[^\n.]{0,40}\bby default/i` and `/default[^\n.]{0,20}nginx-fpm/i`, so a README or `CLAUDE.md` sentence like "nginx-fpm is the default server" passes the test, and so does "default" more than 20 characters before nginx-fpm. AC-4 says the test SHALL fail when either file again calls nginx-fpm the default.
  - Approach: use the Q6 pattern as written (it does not match the current README or `CLAUDE.md`), and add a data-provider case that feeds the known bad sentences ("nginx-fpm is the default", "runs on nginx-fpm by default", "the default server is nginx-fpm") to the same matcher.
  - [x] 10.1 **Verify**: `ddev artisan test --compact tests/Feature/DocsDefaultServerTest.php` passes on the current docs, and each bad sentence is matched by the pattern.

- [x] 11. README parity examples and CLAUDE.md config.yaml note (docs)
  Covers: AC-4
  - Evidence: Q10 and the spec scope say the README shows `compare frankenphp <x>` with frankenphp as the reference. `README.md:73-89` still captures nginx-fpm first and runs `bench/parity.sh compare nginx-fpm <x>` five times, and the block ends on `frankenphp-worker` with no `ddev server default`. The scope also asks `CLAUDE.md` to say that `config.yaml` still says nginx-fpm on purpose; the new `CLAUDE.md` environment line drops that fact, so a reader of `.ddev/config.yaml` sees nginx-fpm with no explanation.
  - Approach: reorder the README parity block to capture frankenphp first, compare each other server with `compare frankenphp <x>`, and end with `ddev server default`; add "`config.yaml` still says `webserver_type: nginx-fpm` on purpose" to the `CLAUDE.md` environment sentence (the Q6 wording, which the doc test pattern does not match); assert both in `DocsDefaultServerTest`.
  - [x] 11.1 **Verify**: `grep -c 'compare frankenphp' README.md` is 5, `grep -c 'compare nginx-fpm' README.md` is 0 outside the nginx-fpm line, `CLAUDE.md` contains "still says `webserver_type: nginx-fpm` on purpose", and `ddev artisan test --compact tests/Feature/DocsDefaultServerTest.php` passes.

## Phase: Gaps (converge round 2)

- [x] 12. http3-matrix.sh has no test for its restore target or measured server (tests)
  Covers: AC-3, MNC-3
  - Evidence: AC-3 says tests SHALL cover the restore target of each changed script. `bench/http3-matrix.sh` now restores through `restore_default_server step` (line 51) after `read_default_server`, but no test runs it: `grep -rn http3-matrix tests/` finds only the static list in `tests/Feature/BenchScriptsTest.php:609`, which checks that the file mentions `lib/default-server.sh` and `restore_default_server`. The other five restoring scripts each have a behaviour test (`BenchSlowLoadTest`, `BenchExportLoadTest`, `BenchHttp3Test`, `BenchSuiteServersTest`, `BenchChatStopTest`); a wrong restore target or a dropped `ddev server frankenphp` measurement step in the HTTP/3 matrix would pass the suite.
  - Approach: add a dry-run test in `tests/Feature/BenchHttp3Test.php` (same pattern as the slow-load and export-load matrix tests): a stub `ddev` on `PATH` that answers `server --default` with `apache-fpm`; run `bench/http3-matrix.sh --dry-run --runs 1`; assert the first server line is `ddev server frankenphp`, four `lcp.mjs` variant lines follow, and the last line is `ddev server apache-fpm`. Add a case where the stub answers nothing: exit 2 and no `ddev server frankenphp` line.
  - [x] 12.1 **Verify**: `ddev artisan test --compact tests/Feature/BenchHttp3Test.php` passes, and the new test fails when `restore_default_server step` in `bench/http3-matrix.sh` is replaced by `step ddev server nginx-fpm`.

- [x] 13. Manual-test plans still restore nginx-fpm at the end of blocks and in rollbacks (docs)
  Covers: AC-4
  - Evidence: Q7 says plans whose rollback or ending sends the reader back to nginx-fpm switch to `ddev server default`. Task 9's grep only looks for phrases, so bare closing `ddev server nginx-fpm` lines remain. These include `spec-20261030-1806-bench-harness/manual-test.md:20` (Rollback block), `spec-20261102-1552-streamed-car-description/manual-test.md:125` (Rollback: "run `ddev artisan config:clear` and `ddev server nginx-fpm`"), `spec-20261105-1109-http3/manual-test.md:43` and `:103` (end of the netem cleanup and the parity loop, although the plan's rollback now says "Always finish with `ddev server default`"), `spec-20261104-2340-early-hints-compression/manual-test.md:39`, `:80`, `:132`, `:247` (after each server loop, although its precondition now starts on the default), `spec-20261104-0001-slow-io-healthz/manual-test.md:131` and `:149` (end-of-check restores), `spec-20261102-2352-docs-index-retrieval/manual-test.md:246` and `spec-20261102-1812-local-model-wiring/manual-test.md:37`. Some edited plans now contradict themselves. `spec-20261030-1806-bench-harness/manual-test.md` step 5.1 runs `ddev server default`, then step 5.2 expects `ddev server --current` to print `nginx-fpm`. `spec-20261104-0001-slow-io-healthz/manual-test.md:7-8` still says the active server must be nginx-fpm, but its rollback (line 13) restores the default.
  - Approach: in each listed plan, change the rollback and closing `ddev server nginx-fpm` lines (a switch after a loop or cleanup, not followed by a measurement on nginx-fpm) to `ddev server default`. Make bench-harness 5.2 expect the name `ddev server --default` prints, and make the slow-io-healthz precondition match its rollback, or keep nginx-fpm on purpose and say so. Keep steps that measure nginx-fpm on purpose. Widen the resting-state check in `tests/Feature/DocsDefaultServerTest.php` to fail when a fenced block's last line is `ddev server nginx-fpm`, and when a `## Rollback` section contains `ddev server nginx-fpm`.
  - [x] 13.1 **Verify**: `ddev artisan test --compact tests/Feature/DocsDefaultServerTest.php` passes. The test fails when `ddev server nginx-fpm` is put back as the last line of the early-hints step 3 block, or into the bench-harness Rollback block. No plan has a fenced block whose last line is `ddev server nginx-fpm`.

- [x] 14. BENCHMARKS.md top note does not mention ddev setup (docs)
  Covers: AC-4
  - Evidence: AC-4 says `BENCHMARKS.md` (top note) SHALL name FrankenPHP as the default and `ddev setup` for fresh clones. `BENCHMARKS.md:3` reads "The default server is now frankenphp. Results below were measured on the server named in each section; ..." and has no `ddev setup`. `tests/Feature/DocsDefaultServerTest.php` (`AC-4 the guide and BENCHMARKS.md carry a default-server note`) only checks for the "The default server is now frankenphp" string, so it does not catch this.
  - Approach: extend the top note with one clause, for example "(run `ddev setup` once on a fresh clone; `ddev server default` returns to it)", and leave every historical section unchanged (MNC-4). Make the doc test assert that the BENCHMARKS top note (text before the first `## ` heading) and the guide's section 2 note both contain `ddev setup`.
  - [x] 14.1 **Verify**: `ddev artisan test --compact tests/Feature/DocsDefaultServerTest.php tests/Feature/BenchmarksDocTest.php` passes. `git diff HEAD -- BENCHMARKS.md` changes only lines above the first `## ` heading.

## Phase: Review fix pass

Verified against the ship report and fixed on 2026-11-09. Task 5 (clean-tree bench) stays open: it needs the commit.

- [x] R1. `ddev setup` brings up a real fresh clone: copies `.env.example` when `.env` is missing; `ddev composer install` when `vendor/autoload.php` is missing; `ddev artisan key:generate` when `APP_KEY` is empty; then the keys, the server, migrate; `ddev npm install && ddev npm run build` when `public/build/manifest.json` is missing. "Already set up" only when nothing was needed. Stub tests cover the call order and each conditional.
- [x] R2. Readiness is the server command's: new `ddev server --check <name|default>` (exit 0 when the overlay, the activated files and no bench overlay match, via `is_current`); setup uses it and no longer carries the overlay path.
- [x] R3. Secret writes go through a 0600 temp file in the same directory and `mv` (atomic, mode forced on an existing 0644 file); the EXIT trap removes the temp; nothing is copied to `$TMPDIR`. Tests: mode after updating a 0644 file, no temp left when `mv` fails, `TMPDIR` stays empty.
- [x] R4. The three matrix scripts read the default before the restore trap: a failed read exits 2 with one message, no `ddev restart`, no switch. `--dry-run` without `ddev` reads `DEFAULT_SERVER` from `.ddev/commands/host/server`. Tests for all three, dry and live.
- [x] R5. `bench/lib/default-server.sh` takes the root as an argument or from its own path; an exported `APPROOT` is ignored. Callers pass their root; test with a stray `APPROOT`.
- [x] R6. AC-3 guard: `(?![\w-])` regex, scoped to trap lines, cleanup/restore function bodies and the last 10 lines; `$restoring` is derived; regex self-tests; behaviour test for `http3-matrix.sh` (frankenphp measurement first, default restore last, exit 2 when unreadable).
- [x] R7. `ServerDefaultCommandTest` asserts `not->toContain('mercure:keys --force')`; the stub refuses like the real command.
- [x] R8. `BENCHMARKS.md` top note names `ddev setup`; every `.ai/specs/*/manual-test.md` rollback and closing switch is `ddev server default`; plans that need nginx-fpm now switch to it explicitly; `DocsDefaultServerTest` fails on a fenced block that ends on `ddev server nginx-fpm` and on a Rollback/Cleanup section that restores it.

- Owner-side notes: committed as 18e6846; `bench/run.sh ping --quick` exit 0 on a clean tree on the default server (frankenphp, spread 0 %); 1315 tests; `ddev server --check default` exit 0.
