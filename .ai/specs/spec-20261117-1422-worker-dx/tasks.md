# Worker-mode developer experience — Tasks

**Spec**: spec-20261117-1422-worker-dx | **Size**: M | **Tasks**: 10

## Phase 1: Watch, hot reload, Xdebug

- [x] 1. Watch for both worker modes (devops)
  Covers: AC-1, AC-2, AC-3, AC-5, MNC-1, MNC-2
  - [x] 1.1 Add:
    - the `watch` directive with `{$WORKER_WATCH}`;
    - `config/octane.php` watch paths;
    - `ddev dev-watch on|off|status` through a gitignored overlay;
    - the hot-reload check and wiring if available.
  - [x] 1.2 **Verify**: `tests/Feature/DevWatchCommandTest.php`, a static check of the overlays, the Caddyfile and the command. Then live: an edit is served without a restart on both modes, and parity is unchanged with watch off.

- [x] 2. Xdebug in Octane (devops)
  Covers: AC-4, MNC-3
  - [x] 2.1 Add the trigger-mode settings for the worker modes, `bench/xdebug-check.php` (a DBGp listener), and the guide steps.
  - [x] 2.2 **Verify**: live, a request with the trigger opens a connection and one without it does not; `ddev xdebug off` afterwards.

## Phase 2: Measure and document

- [x] 3. Reload latency (checks)
  Covers: AC-6, NFR-1, NFR-2
  - [x] 3.1 Add `bench/reload-latency.sh` (5 ways of working, 10 trials each, the Mutagen delay) and the `/debug/reload-probe` route. Run it.
  - [x] 3.2 **Verify**: `tests/Feature/BenchReloadLatencyTest.php` (with stubs, the restore on interrupt), and the result files.

- [x] 4. Docs and guide (docs)
  Covers: AC-7
  - [x] 4.1 Add the BENCHMARKS section, the learning guide I-13, the `resources/guides/worker-dx.php` guide and README.
  - [x] 4.2 **Verify**: `DocsLinksTest`, `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, and ui-audit on `/ai/guides` and `/ai/guides/worker-dx`.

- [x] 5. Gates and ship (checks)
  Covers: MNC-1, MNC-4
  - [x] 5.1 Gates after the review fix pass (2026-11-17): full suite 3412 passed, 3 skipped; Pint passed; PHPStan no errors; `npm run typecheck` and `npm run build` pass; `bench/asset-fingerprint.sh` prints `app-DNob7CFE.css` / `app-PTzu1QaN.js`; parity capture on frankenphp, frankenphp-octane and frankenphp-worker with dev-watch off differs only in `captured=` (snapshots restored with `git checkout -- bench/parity`); `node bench/ui-audit.mjs --only guides,guides/worker-dx` 4 pages, 0 failing; `bench/reload-latency.sh` full rerun exit 0 (results in `bench/results/reload/2026-11-17/`, commit field b2b453a because the fix pass is uncommitted).
  - [x] 5.2 Commit, `bench/run.sh ping --quick` after the commit, push (owner).

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | worker watch < 2 s median | `manual-test.md#step-2` | manual | 1, 3 |
| AC-2 | octane --watch recorded | `manual-test.md#step-2` | manual | 1, 3 |
| AC-3 | no watch = never, restart timed | `manual-test.md#step-2` | manual | 3 |
| AC-4 | Xdebug trigger in Octane | `tests/Feature/XdebugTriggerTest.php`, `tests/Feature/XdebugTriggerMiddlewareTest.php`, `manual-test.md#step-4` | test | 2 |
| AC-5 | dev-watch command | `tests/Feature/DevWatchCommandTest.php`, `manual-test.md#step-5` | test | 1 |
| AC-6 | reload table and story | `tests/Feature/BenchReloadLatencyTest.php`, `manual-test.md#step-3`, `manual-test.md#step-9` | test | 3 |
| AC-7 | guide and docs | `tests/Feature/GuideContentTest.php`, `manual-test.md#step-7` | test | 4 |
| MNC-1 | watch off unchanged | `manual-test.md#step-1` | manual | 1, 5 |
| MNC-2 | config and .env untouched | `tests/Feature/DevWatchCommandTest.php`, `manual-test.md#step-6` | test | 1 |
| MNC-3 | no debugger connect when off | `tests/Feature/XdebugTriggerMiddlewareTest.php`, `manual-test.md#step-4` | test | 2 |
| MNC-4 | suite offline | `tests/Feature/BenchReloadLatencyTest.php`, `manual-test.md#step-8` | test | 3 |
| NFR-1 | restore on exit | `tests/Feature/BenchReloadLatencyTest.php`, `manual-test.md#step-9` | test | 3 |
| NFR-2 | watcher idle CPU | `manual-test.md#step-3`, `tests/Feature/BenchmarksDocTest.php` (NFR-2 case) | manual + feature | 3, 6 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run (steps 2, 4 and 5 were run live in the fix pass; the full manual plan was not re-run)
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 6. Measure and record the watcher idle CPU, watch off vs on in worker mode (checks)
  Covers: NFR-2
  - [x] 6.1 **Verify**: BENCHMARKS "Edit to reload in worker modes" records the mean of 30 one-second `docker stats` samples of the web container with dev-watch off and on in `frankenphp-worker`, and the difference is under 5 percentage points; a `BenchmarksDocTest` case asserts both numbers are present; manual-test step 3 uses the same 30-sample off/on method.

- [x] 7. Check `git diff --quiet` on the probe file at the end of reload-latency.sh (checks)
  Covers: NFR-1
  - [x] 7.1 **Verify**: `bench/reload-latency.sh` runs `git diff --quiet -- app/Support/ReloadProbe.php` after the restore and exits non-zero with a message when the file differs; a new `BenchReloadLatencyTest` case (stub repo with a dirty probe) proves it, and the existing NFR-1 cases still pass.

- [x] 8. Remove the repository path `.ddev/config.yaml` from the worker-dx guide and guard `.ddev/` paths (docs)
  Covers: AC-7
  - [x] 8.1 **Verify**: `resources/guides/worker-dx.php` has no `.ddev/` path; `NoReferencesInUiTest` also rejects `\.ddev/` in lab page props and HTML and passes for every guide, including `/ai/guides/worker-dx`.

- [x] 9. Align manual-test.md steps 1, 2 and 4 with C-8, C-13 and C-6 (docs)
  Covers: MNC-1, MNC-3, AC-1, AC-2, AC-3
  - [x] 9.1 **Verify**: step 1 loops parity capture over the three modes with dev-watch off and runs `bench/asset-fingerprint.sh` expecting `app-DNob7CFE.css` / `app-PTzu1QaN.js`; step 2 edits `app/Support/ReloadProbe.php` through the script and restores it with `git checkout -- app/Support/ReloadProbe.php`; step 4 starts the listener again after `ddev xdebug off` plus restart and expects `connections=0` for a triggered request.

## Phase: Gaps (converge round 2)

- [x] 10. Check the untriggered Xdebug request with its own listener in manual step 4 and the guide recipe (docs)
  Covers: AC-4
  - [x] 10.1 **Verify**: manual-test.md step 4 and the worker-dx guide recipe "Check Xdebug without an IDE" run a separate listener with `--wait=3` (the guide names it without a repo path) for the request without `XDEBUG_TRIGGER` and expect `connections=0` from it (the triggered listener still expects `connections=1 init=1`); a `ManualTestPlanTest` case asserts step 4 has at least three listener runs and a `--wait=3` run before the untriggered `curl`, and `GuideContentTest` / `NoReferencesInUiTest` still pass.

## Phase: Review fixes (2026-11-17, ship report F1–F15)

- [x] 11. End the debugger session with the worker (F2, F7, F9, F13)
  Covers: AC-4, MNC-3
  - [x] 11.1 `App\Support\WorkerRecycle` (flag, `loop()`, `isInWorkerLoop()`); `public/frankenphp-worker.php` is Octane's worker script with its loop replaced by `WorkerRecycle::loop()` (used by both worker modes); the middleware requests a recycle after a successful connect and acts only inside the worker loop (Octane's `LARAVEL_OCTANE` is process env, invisible to a request, which is why AC-4 failed on Octane in acceptance).
  - [x] 11.2 **Verify**: `XdebugTriggerMiddlewareTest` (recycle on connect, none on failed connect / classic / no trigger, loop stops after the flagged request, loop flag). Live with one worker, `bench/xdebug-check.php --follow`: frankenphp-worker `connections=1 init=1 ended=1 after=4ms`, then untriggered `connections=0`; frankenphp-octane `ended=1 after=4ms` / `after=5ms`, then `connections=0`; Xdebug off: triggered `connections=0` on both. Control on frankenphp-worker with the recycle call disabled: `ended=0`, worker kept running.
- [x] 12. Triggers like native Xdebug (F4, F6): query, POST, cookie and request environment; `xdebug.trigger_value` via `hash_equals`. **Verify**: `XdebugTriggerMiddlewareTest`.
- [x] 13. Listener on 127.0.0.1 (F8). **Verify**: live on OrbStack, a connection from the container to `host.docker.internal:9003` reached the 127.0.0.1 listener (`connections=1 init=1`); `--any` not needed. Docker Desktop not available on this machine.
- [x] 14. dev-watch and bench overlays exclude each other (F1, F14). **Verify**: `DevWatchCommandTest` (Octane `on` exits 2 with a bench overlay; one shared `DEV_WATCH_OVERLAY_NAME`; `bench_overlays` lists bench overlays only), `BenchConcurrencyTest`, `BenchLeaksTest` (exit 2 while dev-watch is on), `DdevServerCommandTest` (a switch still removes the dev-watch overlay). Found live: the overlay was renamed to `config.zzz-dev-watch.yaml` so it sorts after `config.zz-server.yaml` (the old name made `ddev dev-watch on` fail on Octane with a duplicate `web_extra_exposed_ports` name, the acceptance hop-3 failure); `DevWatchCommandTest` checks the order; live `ddev dev-watch on` on Octane runs `octane:frankenphp --watch`.
- [x] 15. Watch `bootstrap/**/*.php`, `composer.lock`, `.env` in both modes (F3). **Verify**: `DevWatchCommandTest`; spec C-3 updated.
- [x] 16. Reload bench measurement (F10, F11, F12): `BENCH_NEVER_CAP_S`, `BENCH_ROWS`, `BENCH_JITTER_S` (random 0–2 s before each edit, against phase lock with `opcache.revalidate_freq=2`), Mutagen as a separate pass, `restart_ms` end to end plus `restart_call_ms`; the restart command prints its time on Octane too. **Verify**: `BenchReloadLatencyTest` (< 5 s for the file), `WorkersRestartCommandTest`; full rerun of `bench/reload-latency.sh`.
- [x] 17. BENCHMARKS conclusion from the data (F5). **Verify**: `BenchmarksDocTest` (table incl. reload-call column, sync sentence quotes per-row medians).
- [x] 18. F15: the controller has no own `debug_routes` guard (route group only). **Verify**: `BenchReloadLatencyTest` 404 case.
- Clean-tree bench: after commit 4602000, `bench/run.sh ping --quick` exited 0 on frankenphp.
