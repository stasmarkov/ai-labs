---
id: spec-20261117-1422-worker-dx
title: Worker-mode developer experience
type: feature
size: M
priority: P2
status: approved
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-17; standing approval)
approved_at: 2026-11-17T12:25:40Z
created: 2026-11-17
stacks: [laravel]
slice: SL-17
brs: [BR-26, BR-27]
---

# Worker-mode developer experience

## Summary

Worker modes keep the app in memory, so a code edit does not show up until the workers restart. This slice makes an edit take effect quickly in worker mode and makes Xdebug stop inside an Octane request. It then measures, for each way of working, the time from saving a file to the first response that serves the new code.

The five ways of working:
1. Classic `frankenphp`, which needs nothing.
2. `frankenphp-octane` without watching.
3. `frankenphp-octane --watch`, using FrankenPHP's native watcher, which Octane uses for FrankenPHP.
4. `frankenphp-worker` with the native `watch` directive in its worker block.
5. FrankenPHP's browser hot reload, where the installed v1.13.5 provides it.

The backlog depends on SL-04, which was replaced by the FrankenPHP worker and Octane modes. This slice builds on those.

## Context

- **Octane:** `vendor/laravel/octane/src/Commands/StartFrankenPhpCommand.php` handles `--watch` by writing FrankenPHP's native `watch` snippet into the Caddyfile (lines 348–376: "FrankenPHP has native watcher support"). No Node watcher (chokidar) is needed, and none is installed.
- **Worker mode:** the Caddyfile `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp` has a `worker { … }` block with no `watch`.
- **Xdebug:** the extension is installed (`php-zts-xdebug`) and switched off (`ddev xdebug status` reports disabled). DDEV toggles it with `ddev xdebug on|off`. In worker modes the request runs inside the long-lived worker, so the debugger must be triggered per request.
- **File sync on macOS:** DDEV syncs files with Mutagen, which adds its own delay before the container sees a change. That delay is part of the honest number and is measured apart.

## Scope

### In scope

1. **Watch in worker mode:**
   - **The directive:** the `worker` block of the worker-mode stash Caddyfile gets a `watch` directive driven by `{$WORKER_WATCH}`. When it is empty, nothing is watched (today's behaviour).
   - **Patterns:**
     - `./app/**/*.php`, `./config/**/*.php`, `./routes/**/*.php`, `./resources/**/*.php`, `./resources/views/**/*.blade.php` and `./.env`;
     - not `vendor`, `storage`, `node_modules` or `public/build`.
   - **Switching:** `ddev dev-watch on|off|status`, a host command. It sets the env var through a gitignored overlay, as `bench/concurrency.sh` does, and restarts the web container. It never touches `.ddev/config.yaml` or `.env`.
2. **Watch in Octane:** `config/octane.php` `watch` holds the same paths. `ddev dev-watch on` also switches the Octane daemon command to `--watch` through the same overlay mechanism. Off restores it.
3. **Hot reload:**
   - **If the installed FrankenPHP exposes a hot-reload feature** (a directive or a Mercure-based browser refresh in v1.13.x, checked against `frankenphp` docs and `caddy list-modules` in the container):
     - it is switched on together with watch in worker mode;
     - a small script in the lab layout subscribes to its topic, but only when the app runs locally and dev-watch is on;
     - the measurement includes it.
   - **If it does not exist:** the guide says so, and the hot-reload row is reported as "not available in v1.13.5".
4. **Xdebug in Octane:**
   - **Setup:** `ddev xdebug on` together with `XDEBUG_MODE=debug` and `xdebug.start_with_request=trigger` for the worker modes, so only requests with the `XDEBUG_TRIGGER` cookie or query stop. Without that, every worker request would try to connect.
   - **Documented:**
     - how to set a breakpoint in a controller;
     - send `?XDEBUG_TRIGGER=1`;
     - see it stop in PhpStorm (path mapping `/var/www/html` → project root);
     - turn it off again.
   - **Verified:** a dry check with Xdebug's `xdebug_info()` in a debug route, plus a scripted DBGp listener (`bench/xdebug-check.php`, a tiny socket server on the host on port 9003) that confirms an Octane request connects and sends `init`. No IDE is needed.
5. **Edit-to-reload measurement** (`bench/reload-latency.sh`):
   - **One trial, per way of working:**
     1. append a unique marker to a file that a debug route returns (`routes/debug.php` → `/debug/reload-probe` reads a marker from a small included PHP file);
     2. record the host time;
     3. poll the route every 50 ms until the response contains the marker;
     4. record the elapsed ms, capped at 30 s, with "never" for modes that need a manual restart.
   - **Repeats:** 10 trials per way of working, reporting the median, p90 and max. The probe file is restored afterwards.
   - **Mutagen delay:** reported separately, as the time until `ddev exec cat` sees the marker.
   - **Octane without watch:** measured both as "never" (no restart) and as the time to reload with `frankenphp:workers:restart`, the SL-16 command.
   - **Output:** `bench/results/reload/<date>/<mode>.json`.
6. **Docs:**
   - **BENCHMARKS:** a "Edit to reload in worker modes" section with the table, What the numbers say, Surprises and an interview story.
   - **Learning guide:** I-13, replacing the reserved SL-17 bullet. The reserved section is removed if empty.
   - **In-app guide:** a new foundation guide `worker-dx` on `/ai/guides`, with the six tabs plus build, watch and elsewhere.
   - **README:** the commands.

### Out of scope

- Production deploys.
- Changing the default server.
- IDE configuration files committed to the repo.
- Watch for front-end assets (Vite already does that).

## Must NOT change

- **MNC-1:** with dev-watch off, all three modes start and behave as today. The parity capture differs only in `captured=`, and the fingerprint is unchanged.
- **MNC-2:** `.ddev/config.yaml` and `.env` are never modified. Overlays are gitignored.
- **MNC-3:** with Xdebug off, which is the default, no request tries to connect to a debugger.
- **MNC-4:** the default test suite needs no running server or debugger.

## Acceptance criteria

- **AC-1:** WHEN dev-watch is on in `frankenphp-worker` and a watched PHP file is saved, THE next request SHALL serve the new code within 2 s at the median, measured on this machine.
- **AC-2:** WHEN dev-watch is on in `frankenphp-octane` (`--watch`), THE SYSTEM SHALL serve the new code without a manual restart, and the median edit-to-reload time SHALL be recorded.
- **AC-3:** WHEN dev-watch is off, an edit SHALL not be served in worker modes until a restart. The measurement SHALL record "never" for that row, and the restart-command time separately.
- **AC-4:** WHEN Xdebug is on with the trigger and an Octane request carries `XDEBUG_TRIGGER`, THE request SHALL open a DBGp connection to the host listener. Requests without the trigger SHALL not.
- **AC-5:** `ddev dev-watch on|off|status` SHALL switch watching for both worker modes, restore the previous state, and never touch `.ddev/config.yaml` or `.env`.
- **AC-6 (BR-26):** `bench/reload-latency.sh` SHALL record the five rows in C-5, with median, p90 and max over the numeric trials, `never` counts, restart times for the no-watch rows, and the Mutagen delay. Hot reload is reported as not available (C-1). BENCHMARKS SHALL report them with a story.
- **AC-7 (BR-27):** `/ai/guides/worker-dx` SHALL answer 200 with the six tabs and the teach sections, with no slice ids or paths. The learning guide SHALL have I-13. The UI audit on `/ai/guides` SHALL pass.

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Safety | The probe file and all overlays are restored on any exit, including Ctrl-C; the debug probe route exists only where `routes/debug.php` is enabled | `tests/Feature/BenchReloadLatencyTest.php` |
| **NFR-2** | Overhead | With dev-watch on, idle CPU of the web container stays below 5 % (watcher cost), measured once | `manual-test.md#step-3` |

## Boundaries

- **Always:**
  - use FrankenPHP's native watcher;
  - test the scripts with stubs;
  - restore the state after every run;
  - update `/ai/guides` (owner rule).
- **Ask first:** adding any npm or Composer dependency. The plan needs none. If the build finds one is needed, it stops and reports.
- **Never:**
  - modify `.ddev/config.yaml` or `.env`;
  - commit IDE files;
  - leave Xdebug on after a run.

## Technical context

- **Related code:**
  - **Servers:** `.ddev/servers/frankenphp-worker/web-build/Caddyfile.frankenphp`, `.ddev/servers/frankenphp-octane/` (config and Caddyfile) and `config/octane.php`.
  - **Bench patterns:** `bench/concurrency.sh` (overlay pattern), `bench/lib/*` and `routes/debug.php`.
  - **Guides:** the guide format with teach sections.
- **Already implemented:**
  - the native watcher in Octane's FrankenPHP command;
  - the Xdebug extension;
  - the restart command (SL-16);
  - the debug routes;
  - the overlay helpers.

## Decisions

- **D-1:** the native FrankenPHP watcher is used for both worker modes, so no Node or chokidar dependency is needed. (self-decided, standing approval)
- **D-2:** Xdebug uses trigger mode in worker modes, so a long-lived worker does not try to connect on every request. (self-decided, standing approval)
- **D-3:** a DBGp socket listener proves the breakpoint path without an IDE, and the guide covers PhpStorm. (self-decided, standing approval)
- **D-4:** hot reload is not available in v1.13.5 (C-1), so it is reported as unavailable and not built. (self-decided, standing approval)

## Open questions

All 27 items from the clarity gate on 2026-11-17 are answered below in C-1 to C-13 (self-decided, standing approval).

- [x] Hot reload: decided, not available in v1.13.5 → C-1
- [x] Octane overlay, the two watch mechanisms, globs, cached config → C-2, C-3
- [x] Probe file, bench state, rows, "never", the 2 s rule, Mutagen → C-4, C-5
- [x] Xdebug config home, scope, listener, MNC-3 check, debug route → C-6
- [x] `dev-watch` behaviour, persistence, status → C-7
- [x] Parity, NFR-2 method, traceability, stubs → C-8, C-9
- [x] Guide terms, learning-guide location, result format, manual steps, ping → C-10 to C-13

## Clarifications

- **C-1 Hot reload:** checked in the container on 2026-11-17. `frankenphp list-modules` in v1.13.5 has `frankenphp`, `admin.api.frankenphp` and the Mercure modules, but no hot-reload module. `php-server` offers only `--watch`.
  - **What this means:** hot reload is out of scope. The hot-reload row is reported as "not available in FrankenPHP v1.13.5", and the guide explains what browser hot reload would add.
  - **Rows:** AC-6 now lists four measured ways of working plus that note.
  - **Scope:** Scope 3 and D-4 are settled this way. No script and no Mercure wiring are added.
- **C-2 Overlays:**
  - **One file:** `ddev dev-watch on` writes `.ddev/config.zzz-dev-watch.yaml`, gitignored, the same pattern as `config.zzz-bench-concurrency.yaml`.
  - **Worker mode:** the overlay sets `web_environment: [WORKER_WATCH=<patterns>]`.
  - **Octane:** the overlay holds a copy of the stash's `web_extra_daemons` Octane command with `--watch` added. The stash command lives in `.ddev/servers/frankenphp-octane/config.frankenphp-octane.yaml`. The overlay sets `override_config: true` only for that key, the same way `bench/concurrency.sh` does.
  - **Turning it off:** `ddev dev-watch off` deletes the overlay and runs `ddev restart`.
  - **Server switches:** `ddev server` already removes `config.zzz-*` overlays when it switches, so dev-watch is off after a server switch. `status` says so, and `on` must be run again.
- **C-3 Patterns:**
  - **Worker mode:** the `watch` directive takes globs; the patterns are written as `./app/**/*.php`, `./config/**/*.php`, `./routes/**/*.php`, `./resources/**/*.php` and `./.env`. The separate Blade pattern is dropped, because `resources/**/*.php` covers `*.blade.php`.
  - **Octane:** `config/octane.php` `watch` uses Octane's format (`app`, `config`, `routes`, `resources/**/*.php`, `.env`). Octane turns it into the FrankenPHP snippet only when `--watch` is passed, so the config change has no effect while dev-watch is off.
  - **Caching:** the measurement and dev-watch need uncached config and routes (the dev default). If the app is cached, `dev-watch on` prints a warning, and `.env` edits then need `config:clear`.
- **C-4 Probe:**
  - **The file:** `app/Support/ReloadProbe.php`, committed, a small class whose constant `MARKER` is `'initial'`. It is in a watched path.
  - **The route:** `/debug/reload-probe` (in `routes/debug.php`, which is enabled only when `DEBUG_ROUTES=true`, as the bench harness sets it) returns that constant.
  - **One trial:** the script overwrites the constant with a unique value, then polls.
  - **Restore:** the original file is copied back on any exit (trap), and `git diff --quiet app/Support/ReloadProbe.php` is checked at the end.
- **C-5 Bench run:**
  - **Server and watch state:** `bench/reload-latency.sh` switches servers itself with `ddev server`, turns dev-watch on or off per row, and at the end restores the starting server and the starting dev-watch state (NFR-1 is extended to these).
  - **Rows:**
    1. classic;
    2. Octane with no watch;
    3. Octane with watch;
    4. worker with no watch;
    5. worker with watch.
  - **No-watch rows:** a trial polls for at most 30 s and records `never`. Only 3 trials are run, not 10, to save time. The row reports `never` and is excluded from median, p90 and max.
  - **Restart time:** each no-watch row also records 10 trials of the time from `ddev artisan frankenphp:workers:restart` to the new code. On Octane that command runs `octane:reload` (SL-16 C-6).
  - **Statistics:** computed over the numeric trials only.
  - **AC-1:** the 2 s median is end to end, from save to the new response, so it includes the Mutagen delay. If it fails, the result is reported honestly and the guide explains which part, Mutagen or the watcher, dominates.
  - **Mutagen:** the delay is measured per trial, as the time until `ddev exec cat` sees the marker. If `ddev describe` does not show `Perf mode: mutagen`, which it does today, the delay is recorded as `n/a`.
- **C-6 Xdebug:**
  - **Settings:** `.ddev/php/xdebug-trigger.ini`, committed, with `xdebug.start_with_request=trigger` and `xdebug.client_host=host.docker.internal`. DDEV loads it in every mode, which is acceptable: trigger mode only changes when a session starts. MNC-2 is about `config.yaml` and `.env` only.
  - **Turning it on:** `ddev xdebug on|off` enables the extension, then the web container is restarted, so the long-lived workers start with or without it. `dev-watch` does not touch Xdebug.
  - **ZTS:** the build first checks that `ddev xdebug on` works on the php-zts build. If it does not, it records why and stops for this part.
  - **The listener:** `bench/xdebug-check.php` runs in the host or the container PHP, since it needs only sockets. It listens on 9003 and exits 1 at once if the port is taken ("port 9003 in use, close the IDE listener"). It waits 10 s for a connection and then exits with a summary.
  - **The check:**
    1. a triggered request;
    2. an untriggered request, with a 3 s wait, expecting no connection;
    3. `ddev xdebug off` and a restart;
    4. a triggered request, expecting no connection (MNC-3).
  - **The `xdebug_info()` dry check:** dropped. The DBGp check is enough.
- **C-7 `dev-watch`:**
  - **`status`:** prints `dev-watch: on|off (server: <mode>)`.
  - **`on` on classic:** prints "classic mode reloads on every request; nothing to watch" and exits 0 without writing an overlay.
  - **Restore:** "restore the previous state" means `off` returns to no overlay.
  - **Restarts:** the overlay survives `ddev restart`, but not `ddev server` (C-2).
- **C-8 Parity and fingerprint:**
  - **Parity:** `PARITY_INSECURE=1 bench/parity.sh capture` for each of the three modes with dev-watch off, where only `captured=` may differ.
  - **Fingerprint:** `bench/asset-fingerprint.sh` shows `app-DNob7CFE.css` / `app-PTzu1QaN.js`.
  - **Manual step 1:** loops over the three modes.
- **C-9 NFR-2 and traceability:**
  - **NFR-2 method:** idle CPU is the mean of 30 one-second `docker stats` samples of `ddev-${DDEV_SITENAME}-web`, with watch off and then on, in worker mode. The limit is a difference under 5 percentage points of one core.
  - **AC-6:** split. The bench test checks the script and its JSON (stubs: PATH shims for `ddev`, `curl` and `docker`, with Ctrl-C simulated by `kill -INT`, the SL-28 pattern). `BenchmarksDocTest` checks that the table rows match the result files.
  - **MNC-4:** proved by the full suite passing offline.
  - **AC-1 to AC-3:** proved by the script's results (task 3), not by eye.
- **C-10 Guide terms:**
  - **The tabs:** the six engine tabs are works, failures, recipes, support, numbers and experiments, plus the three SL-53 teach tabs (build, watch, elsewhere). That makes nine tabs when all are present.
  - **Foundation guide:** a guide not tied to one lab page.
  - **UI audit:** `bench/ui-audit.mjs`, exit 0.
  - **Allowed on the site:** commands and env names. Not allowed: slice ids, `.md` names and repo paths.
- **C-11 Learning guide:** `docs/learning-guide.md` has `## I-13. Coming next…`, a reserved section left after SL-15 and SL-16. It is replaced by `## I-13. Worker-mode developer experience`, and the reserved section is removed, because no reserved items remain. `DocsLinksTest` and the order list are updated.
- **C-12 Results:**
  - **Files:** `bench/results/reload/<UTC YYYY-MM-DD>/<row>.json`, named `classic`, `octane-nowatch`, `octane-watch`, `worker-nowatch` and `worker-watch`.
  - **Fields:** `{mode, watch, trials: [{ms|null, mutagen_ms}], median_ms, p90_ms, max_ms, never: n, restart_ms: {median, p90, max}|null, commit, frankenphp_version}`.
  - **BENCHMARKS columns:** way of working, median, p90, max, never, restart median, Mutagen median.
- **C-13 Manual steps and shipping:**
  - **Manual step 2:** edits `app/Support/ReloadProbe.php` through the script, not routes. It restores the file with `git checkout -- app/Support/ReloadProbe.php`.
  - **Manual step 4:** uses `/api/ping`, which exists (parity lists it), on the project URL from `ddev describe`.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
  - **Pushing:** covered by the owner's standing instruction to push every slice.
  - **PHPStan:** configured, and it has run in every slice.

