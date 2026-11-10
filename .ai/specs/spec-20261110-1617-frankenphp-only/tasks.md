# FrankenPHP family only — Tasks

**Spec**: spec-20261110-1617-frankenphp-only | **Size**: M | **Tasks**: 7

## Phase 1: Server command and DDEV files

- [x] 1. `ddev server` narrowed (devops)
  Covers: AC-1, MNC-1, MNC-6
  - [x] 1.1 Set `SUPPORTED` to the three modes, remove the removed servers' cases, add the removal message, make `--current` say "not set up" with no overlay, and update the help text.
  - [x] 1.2 **Verify**: `DdevServerCommandTest`, `ServerDefaultCommandTest` and `ServerSwitchChecksTest` updated; a removed name exits 1 and leaves the overlay untouched.

- [x] 2. DDEV leftovers (devops)
  Covers: AC-6, MNC-3
  - [x] 2.1 Delete `.ddev/servers/openlitespeed/`; strip apache lines from `.ddev/web-build/Dockerfile` (delete the file if empty); trim `.gitignore` lines nothing writes any more.
  - [x] 2.2 **Verify**: the `FrankenphpOnlyTest` web-build case; `git diff` shows no change under `.ddev/servers/frankenphp*`.

## Phase 2: Bench and parity

Order: 1, 2, 3, 5, 6, then 4, then 7 (parity is captured last; clarity answer 4). Before task 1, record `git rev-parse HEAD` here as the last commit with the old servers.

Last commit with the old servers: `719c0a5`.

- [x] 3. Bench scripts (bench)
  Covers: AC-2, MNC-4
  - [x] 3.1 Narrow matrices, suites and loops to the three modes; remove the server-specific branches; `summary.php` still reads historical results.
  - [x] 3.2 **Verify**: the `Bench*Test` files updated; a removed server passed to a script exits non-zero; `BenchSummaryTest` renders a committed historical result.

- [x] 4. Parity (checks)
  Covers: AC-3, MNC-2
  - [x] 4.1 Delete the three removed baselines; capture frankenphp, frankenphp-worker and frankenphp-octane after the code change.
  - [x] 4.2 **Verify**: `compare frankenphp frankenphp-worker` and `compare frankenphp frankenphp-octane` print identical; route lines equal today's frankenphp snapshot.

## Phase 3: Tests, docs and guides

- [x] 5. Guard test and test cleanup (backend)
  Covers: AC-4
  - [x] 5.1 Add `tests/Feature/FrankenphpOnlyTest.php` with the allowlist; update or delete tests whose subject was a removed server.
  - [x] 5.2 **Verify**: the guard test is green; planting `nginx-fpm` in `bench/run.sh` makes it red (revert after).

- [x] 6. Docs and guides (docs)
  Covers: AC-5, MNC-4
  - [x] 6.1 README, `CLAUDE.md`, getting-started, architecture, the `BENCHMARKS.md` top note (naming the last commit with the old servers), learning guide history lines and section 2, the streaming guide.
  - [x] 6.2 **Verify**: `ReadmeSwitchingServersTest`, `LearningGuideTest`, `GuideContentTest` and `HomeLandingTest` (server modes pinned) are green.

- [x] 7. Checks (checks)
  Covers: MNC-1, MNC-5
  - [x] 7.1 Full suite, Pint, PHPStan, typecheck, build; fingerprint unchanged; `ddev server --check default` exit 0; `bench/run.sh ping --quick` exit 0 after the commit.
  - [x] 7.2 **Verify**: outputs recorded here.

### Touched test files (`git diff --name-status HEAD -- tests/`)

Rewritten = removed-server fixtures moved to the three modes or a neutral label; deleted = only removed-server cases dropped; kept = existing cases untouched, new cases added.

- M tests/Feature/BenchChatStopTest.php: rewritten
- M tests/Feature/BenchCompressionTest.php: rewritten
- M tests/Feature/BenchConcurrencyTest.php: rewritten
- M tests/Feature/BenchEarlyHintsTest.php: rewritten
- M tests/Feature/BenchExportLoadTest.php: rewritten
- M tests/Feature/BenchHttp3Test.php: rewritten
- M tests/Feature/BenchLcpTest.php: rewritten
- M tests/Feature/BenchLeaksTest.php: rewritten
- M tests/Feature/BenchMemoryTest.php: rewritten
- M tests/Feature/BenchRunConditionsTest.php: rewritten
- M tests/Feature/BenchScriptsTest.php: rewritten
- M tests/Feature/BenchSlowLoadTest.php: rewritten
- M tests/Feature/BenchSuiteServersTest.php: rewritten
- M tests/Feature/BenchSummaryTest.php: kept (new cases added)
- M tests/Feature/BenchSweepTest.php: deleted (removed-server cases dropped)
- M tests/Feature/BenchTimeoutsTest.php: rewritten
- M tests/Feature/DdevHostGatewayTest.php: deleted (removed-server cases dropped)
- M tests/Feature/DdevServerCommandTest.php: rewritten
- M tests/Feature/DocsDefaultServerTest.php: rewritten
- M tests/Feature/ExportOffloadChecksTest.php: rewritten
- M tests/Feature/ExportOffloadConfigTest.php: deleted (removed-server cases dropped)
- M tests/Feature/LearningGuideTest.php: kept (new cases added)
- M tests/Feature/ReadmeSwitchingServersTest.php: rewritten
- M tests/Feature/ServeExportTest.php: rewritten
- M tests/Feature/ServerDefaultCommandTest.php: rewritten
- M tests/Feature/ServerSwitchChecksTest.php: rewritten
- A tests/Feature/FrankenphpOnlyTest.php: new (guard test)

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | server menu has three modes; removed names refused | `tests/Feature/DdevServerCommandTest.php` | test | 1 |
| AC-2 | bench uses only the three modes | `tests/Feature/BenchSuiteServersTest.php` | test | 3 |
| AC-3 | parity on three modes, identical | `manual-test.md#step-2` | manual | 4 |
| AC-4 | no removed identifiers outside history | `tests/Feature/FrankenphpOnlyTest.php` | test | 5 |
| AC-5 | docs and guides show three modes | `tests/Feature/ReadmeSwitchingServersTest.php` | test | 6 |
| AC-6 | web image has no apache modules | `tests/Feature/FrankenphpOnlyTest.php` | test | 2 |
| MNC-1 | default server behaviour | `tests/Feature/ServerDefaultCommandTest.php` | test | 1, 7 |
| MNC-2 | routes identical on three modes | `manual-test.md#step-2` | manual | 4 |
| MNC-3 | worker and Octane configs untouched | `manual-test.md#step-3` | manual | 2 |
| MNC-4 | history kept | `tests/Feature/BenchmarksDocTest.php` | test | 3, 6 |
| MNC-5 | main fingerprint | `manual-test.md#step-3` | manual | 7 |
| MNC-6 | config.yaml unchanged | `manual-test.md#step-3` | manual | 1 |

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

- [x] 8. Getting-started and streaming guide describe the three modes and how to switch (docs)
  Covers: AC-5
  - [x] 8.1 `docs/getting-started.md` names FrankenPHP only once (line 73, a versions list) and has no word on worker, Octane or `ddev server`; `resources/guides/streaming.php` only says "each of the three FrankenPHP modes" in a support note, without naming classic, worker and Octane, what each means for a stream, or how to switch. Add a short "Server modes" part to getting-started (classic default with Mercure, `ddev server frankenphp-worker`, `ddev server frankenphp-octane`, `ddev server default`) and a `works` or `recipes` entry to the streaming guide that names the three modes and the `ddev server <mode>` switch (PHP-FPM only as "measured earlier on PHP-FPM").
  - [x] 8.2 **Verify**: a new `ReadmeSwitchingServersTest` (or `GuideContentTest`) case asserts getting-started and `resources/guides/streaming.php` each contain `frankenphp`, `frankenphp-worker`, `frankenphp-octane` (or Classic/Worker/Octane) and `ddev server`; it fails before the edit and passes after; manual step 4 shows the three modes on `/ai/guides/streaming`.

- [x] 9. Learning-guide history lines for every section 1-8 that measured a removed server (docs)
  Covers: AC-4, clarity 3 and 10
  - [x] 9.1 Sections 5 ("Measuring fairly", line 121 "ratios against nginx-fpm", line 129 OpenLiteSpeed) and 6 ("What was measured", line 145 nginx-fpm RPS) describe removed servers but have no history line; sections 1-3 use free-form blockquotes instead of the clarity-10 line. Add the clarity-10 history line (`*History: measured on nginx-fpm, apache-fpm and OpenLiteSpeed, which the lab dropped on 2026-11-10; the FrankenPHP rows still apply.*`) at the top of every section 1-8 that names a removed server, keeping the existing section-2 note about the three current modes.
  - [x] 9.2 **Verify**: a `LearningGuideTest` case loops sections 1-8 and asserts that each section containing `nginx-fpm|apache-fpm|openlitespeed` starts with the history line, and that section 2 names `frankenphp`, `frankenphp-worker` and `frankenphp-octane` as the current modes; it fails before the edit (sections 5 and 6) and passes after.

- [x] 10. Remove the nginx timeout-swap leftovers from `.gitignore` and `ddev server` (devops)
  Covers: AC-6 (task 2), clarity 9
  - [x] 10.1 `.gitignore` still has `.ddev/nginx/bench-timeouts.conf` (line 42) and `.ddev/nginx_full/nginx-site.conf.bench-orig` (line 51), though clarity 9 says remove exactly these with the openlitespeed line and no script writes them any more (`bench/timeouts.sh` only writes the php ini and entrypoint). `.ddev/commands/host/server` `bench_overlays`/`clear_bench_overlays` (lines 143-155) still restore `nginx-site.conf.bench-orig`, a path of the removed nginx timeout swap, and `DdevServerCommandTest` line 558 pins it. Remove both `.gitignore` lines, drop the bench-orig path and branch from the server command, and remove or rewrite that test case.
  - [x] 10.2 **Verify**: the `FrankenphpOnlyTest` web-build case also asserts `.gitignore` contains neither `bench-timeouts.conf` nor `nginx-site.conf.bench-orig`, and `grep -n bench-orig .ddev/commands/host/server` is empty; `DdevServerCommandTest` and `FrankenphpOnlyTest` are green.

- [x] 11. Remove the apache-fpm site config and its test (devops)
  Covers: scope "their stashes and configs", clarity 8
  - [x] 11.1 `.ddev/apache/apache-site.conf` (apache-fpm only; `XSendFile On` needs the mod_xsendfile that task 2 removed from the image) is still tracked, and `ExportOffloadConfigTest` "AC-5 apache limits mod_xsendfile to the exports directory" asserts only on it, which clarity 8 says to delete. Delete the file and that test; change the `docs/learning-guide.md` links to it (line 1006, and the table cell at line 240 if it is a link) to plain text naming commit `719c0a5`, so the link checker in `LearningGuideTest` stays green. Keep `.ddev/nginx_full/nginx-site.conf` (the bootstrap `config.yaml` still uses nginx-fpm, MNC-6).
  - [x] 11.2 **Verify**: `git ls-files .ddev/apache` is empty; `ExportOffloadConfigTest`, `LearningGuideTest` and `FrankenphpOnlyTest` are green.

- [x] 12. Pin `ddev server --help` and `ddev setup` recovery from a removed-server overlay (devops)
  Covers: AC-1, MNC-1
  - [x] 12.1 DDEV builds `ddev server --help` from the `## Description` and `## Usage` annotations of `.ddev/commands/host/server`, but no test reads them (no `## Usage` assertion in `tests/Feature`). Clarity 5 says `ddev setup` switches to the default when the overlay names a removed server; `ServerDefaultCommandTest` has no setup case for that overlay. Add a `DdevServerCommandTest` case that parses the two annotation lines and asserts they list exactly `frankenphp`, `frankenphp-worker`, `frankenphp-octane` (plus `default`) and no removed name, and a `ServerDefaultCommandTest` case that writes `# server: openlitespeed` to the overlay, runs `setup`, and asserts it calls `server default`, does not print "Already set up", and ends with `# server: frankenphp`.
  - [x] 12.2 **Verify**: both new cases are green; changing `## Usage` to include `nginx-fpm` makes the first red (revert after).

- [x] 13. Record the spec bookkeeping in tasks.md (checks)
  Covers: clarity 6 and 8
  - [x] 13.1 Task 1 and its subitems are still unticked although its code and tests are done; the "last commit with the old servers" (`719c0a5`, already named in `BENCHMARKS.md`) is not recorded here as Phase 2 asks; and clarity 8's list of every touched test file with its outcome (deleted, rewritten or kept) is missing. Tick task 1 after re-running its Verify, add the commit line under Phase 2, and add the touched-test list (from `git diff --name-status HEAD -- tests/`).
  - [x] 13.2 **Verify**: tasks.md shows task 1 ticked, `719c0a5` under Phase 2, and one line per changed file in `git diff --name-status HEAD -- tests/` with its outcome.

## Phase: Gaps (converge round 2)

- [x] 14. Learning-guide section 2 names the three modes in backticks so the full suite is green (docs)
  Covers: AC-4, AC-5
  - [x] 14.1 `ddev artisan test --compact` fails 1 of 1560: `LearningGuideTest` "AC-4 learning guide sections 1-8 that name a removed server start with the history line" (line 683) expects section 2 to contain `` `frankenphp` ``, but the section 2 note in `docs/learning-guide.md` says "The default server is now frankenphp (" without backticks (the task 9 edit is no longer there). Put backticks around `frankenphp` in that note so section 2 names `frankenphp`, `frankenphp-worker` and `frankenphp-octane` as the current modes. Do not touch the history line or the test.
  - [x] 14.2 **Verify**: `ddev artisan test --compact tests/Feature/LearningGuideTest.php` is green, and the full suite `ddev artisan test --compact` reports 0 failed.

- [x] 15. Switching away from a removed-server overlay also removes that server's stale activated compose file (devops)
  Covers: MNC-1, AC-1 (clarity 5)
  - [x] 15.1 At `719c0a5`, `activated_files openlitespeed` copied `docker-compose.openlitespeed.yaml` into `.ddev/` (gitignored then). Its `build.context: ./servers/openlitespeed` is now deleted. The new `.ddev/commands/host/server` only removes the activated files of `SUPPORTED` servers (`deactivate_others`, `restore_files`), and the `.gitignore` line is gone. So on an old checkout where openlitespeed was active, `ddev setup` → `ddev server default` leaves `.ddev/docker-compose.openlitespeed.yaml` in place. DDEV still loads it, `ddev restart` fails on the missing build context, and the switch aborts and restores the removed-server overlay. Clarity 5 recovery fails, and the file also shows up as untracked. The `ServerDefaultCommandTest` recovery case (line 361) writes only the overlay, so it does not catch this. In the switch path (next to `clear_bench_overlays`), remove `.ddev/docker-compose.<name>.yaml` for every name in `REMOVED_SERVERS`. Build the path from the list so the guard test stays green without a new literal.
  - [x] 15.2 **Verify**: a new `DdevServerCommandTest` case writes `# server: openlitespeed` to the overlay and creates `.ddev/docker-compose.openlitespeed.yaml`, runs `server default`, and asserts exit 0, `# server: frankenphp` in the overlay, and that the compose file no longer exists. The case fails before the change and passes after. `FrankenphpOnlyTest`, `DdevServerCommandTest` and `ServerDefaultCommandTest` are green.

- [x] 16. `bench/chat-stop.sh --modes` refuses a removed server before measuring anything (bench)
  Covers: AC-2 (clarity 7)
  - [x] 16.1 `bench/chat-stop.sh` takes an explicit server list (`--modes a,b`) but does not source `bench/lib/servers.sh`. It switches with `ddev server "$mode"` (line 185) inside the measuring loop. So `--modes frankenphp,nginx-fpm` first measures frankenphp (Ollama calls, a result JSON written), then dies with exit 1 from `ddev server`. Clarity 7 asks for exit 2 with `server_removed_message` before anything runs. `BenchChatStopTest` has no removed-server case, and its `ddev` stub accepts any name. Source `lib/servers.sh` and, after argument parsing and before `mkdir -p "$OUT"`, check every entry of `MODES`: a removed name prints `server_removed_message` to stderr and exits 2.
  - [x] 16.2 **Verify**: a new `BenchChatStopTest` case runs `--modes frankenphp,nginx-fpm` (dataset: nginx-fpm, apache-fpm, openlitespeed). It asserts exit 2, stderr containing `<name> was removed in SL-50` and `frankenphp-octane`, no `bench/results/chat-stop/*/*.json` written, and no `ddev server` line in `stub.log`. The case fails before the change and passes after. Add `tests/Feature/BenchChatStopTest.php` to the `FrankenphpOnlyTest` allowlist with the reason "asserts the removal message", and both are green.

- [x] 17. Guard allowlist covers only the `CLAUDE.md` sentence about `config.yaml`, not the whole file (backend)
  Covers: AC-4 (clarity 1)
  - [x] 17.1 Clarity 1 allows "the `CLAUDE.md` sentence about" `.ddev/config.yaml`, but `tests/Feature/FrankenphpOnlyTest.php` allowlists `'CLAUDE.md'` whole. A removed server described as a mode anywhere else in `CLAUDE.md` (the file every agent session loads) would pass the guard. Drop `CLAUDE.md` from the path allowlist. Before matching, strip the exact sentence fragment ``still says `webserver_type: nginx-fpm` on purpose`` from the file contents (it is pinned by `DocsDefaultServerTest`), then match what is left.
  - [x] 17.2 **Verify**: `FrankenphpOnlyTest` is green. Adding the line `ddev server apache-fpm` to `CLAUDE.md` turns it red and lists `CLAUDE.md`; revert the line afterwards. `DocsDefaultServerTest` stays green.

## Fix pass and run record (2026-11-10)

Review fixes:
- **Major: stale compose file.** On an old checkout that had OpenLiteSpeed active, its compose file stayed in `.ddev/` and broke `ddev restart`. `ddev server` now deletes `.ddev/docker-compose.<removed>.yaml` for every name in `REMOVED_SERVERS` on each switch (`remove_removed_leftovers`); new `DdevServerCommandTest` case. Running `ddev server` with no arguments now prints the removal hint as well.
- **Major: offload cells.** `bench/export-load-matrix.sh` runs the offload cell only on classic FrankenPHP, the only mode with the X-Sendfile intercept. Worker and Octane run stream only (4 cells; `BenchExportLoadTest` pins it).
- **Shared guard.** `current_server` in `bench/lib/servers.sh` refuses a "not set up" or removed current server with exit 2. `run`, `compression`, `early-hints`, `http3`, `export-load`, `memory`, `leaks`, `mercure-load`, `slow-load`, `concurrency`, `timeouts` and `chat-stop` use it; `lcp.mjs` has the same check. `FrankenphpOnlyTest` covers the three states.
- **chat-stop.** `chat-stop.sh` checks the whole `--modes` list before the first measurement (exit 2; new test).
- **Guard allowlist.** CLAUDE.md is allowed per line (only the `config.yaml` sentence). Test files stay allowed whole, each with its reason. Rewriting their fixtures to build names from `REMOVED_SERVERS` was not worth the churn.
- **Guides and docs.** The describe guide's buffering failure now names FrankenPHP and puts Apache `flushpackets` as "measured earlier on PHP-FPM". The learning guide and the `BENCHMARKS.md` note put `frankenphp` in backticks (gap 14).
- **Kept on purpose.** `App\Enums\OffloadHeader` (X-Accel-Redirect, X-LiteSpeed-Location) and `config/exports.php` stay. They are an app-level, configurable choice that matters when the app is deployed behind nginx or LiteSpeed in production, and the lab does not run those servers any more. A learning point, not a server mode.

Checks:
- Full suite: 1561 passed before the two added tests; all green after them.
- Pint and PHPStan clean, typecheck and build pass.
- Fingerprint: `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Parity captured last on frankenphp, frankenphp-worker and frankenphp-octane. Both `compare frankenphp <mode>` print identical, and `git diff` shows only the three `captured=` header lines change.
- `ddev server`: lists the three modes. `ddev server nginx-fpm` prints the removal message and exits 1. `--current` prints `frankenphp`, and `--check default` exits 0.
- `git diff --stat 719c0a5 -- .ddev/config.yaml .ddev/servers/frankenphp*` is empty (MNC-3, MNC-6).
- Last commit with the old servers: `719c0a5`.
- Clean-tree bench: after commit ff77fb7, `bench/run.sh ping --quick` exited 0 on frankenphp (`bench/results/raw/2026-11-10/frankenphp/ping-fair-160247-s1r1.json`).
