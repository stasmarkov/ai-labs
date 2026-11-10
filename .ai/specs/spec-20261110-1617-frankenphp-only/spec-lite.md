---
id: spec-20261110-1617-frankenphp-only
title: FrankenPHP family only
type: improvement
size: M
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-10; standing approval)
approved_at: 2026-11-10T14:20:10Z
created: 2026-11-10
stacks: [laravel]
slice: SL-50
brs: [BR-26, BR-27]
---

# FrankenPHP family only

## Summary

The owner narrowed the lab to the FrankenPHP family on 2026-11-10: "Keep only frankenphp, and drop not needed. I also interested how to use frankenphp and octane in laravel." Three modes stay:
- **classic FrankenPHP** (default, with the built-in Mercure hub);
- **FrankenPHP worker mode**;
- **Laravel Octane on FrankenPHP**.

nginx-fpm, apache-fpm and OpenLiteSpeed leave every runnable part of the repo: the `ddev server` choices, their stashes and configs, the bench cells, the parity baselines, the tests and the current docs. Results measured on them stay as history in `bench/results/`, `BENCHMARKS.md` and the learning guide.

## Context

- **Problem**: 60 tracked files outside `bench/results` and `.ai` name the three servers.
  - **Server command:** `ddev server` offers six servers (`.ddev/commands/host/server:14`).
  - **Stash:** `.ddev/servers/openlitespeed/` holds OpenLiteSpeed's stash.
  - **Web image:** `.ddev/web-build/Dockerfile` installs `mod_xsendfile` for apache-fpm into the shared web image.
  - **Bench:** 12 bench scripts and 3 parity baselines cover the three servers.
  - **Tests:** 31 test files refer to them.
- **User impact**: a learner sees only the modes the lab is about. A fresh clone builds a smaller web image, and the server menu and bench matrices are shorter.

## Scope

### In scope

- **`ddev server`**:
  - `SUPPORTED=(frankenphp frankenphp-worker frankenphp-octane)`.
  - Remove the nginx-fpm, apache-fpm and openlitespeed cases (overlay, URL, compose file and stash files). Update the description, usage line and help.
  - `ddev server nginx-fpm` (or another removed name) exits 1 with: `<name> was removed in SL-50; the lab runs on the FrankenPHP family: frankenphp, frankenphp-worker, frankenphp-octane`.
  - `--current` with no overlay prints `not set up` (exit 0) instead of reporting the base `nginx-fpm`, and points at `ddev setup`.
- **DDEV files**:
  - Delete `.ddev/servers/openlitespeed/`.
  - Drop the apache `mod_xsendfile` lines from `.ddev/web-build/Dockerfile`; delete the file if nothing else is left in it.
  - Remove `.gitignore` lines that only serve the removed servers (`.ddev/docker-compose.openlitespeed.yaml`, nginx bench conf leftovers) once no script writes those paths.
- **Bench**:
  - Every matrix and loop (`suite.sh`, `run.sh`, `concurrency.sh`, `export-load-matrix.sh`, `http3*.sh`, `slow-load-matrix.sh`, `timeouts.sh`, `versions.sh`, `lcp.mjs`, `summary.php`) iterates only the three modes.
  - Server-specific code paths are removed: nginx timeout conf swaps, apache/nginx X-Sendfile cells, and the OLS port 8091.
  - `parity.sh` server tokens list only the three modes.
  - Delete `bench/parity/{nginx-fpm,apache-fpm,openlitespeed}.txt`.
  - `summary.php` still renders committed historical results that name removed servers (read-only history).
- **Parity**: capture `bench/parity/{frankenphp,frankenphp-worker,frankenphp-octane}.txt` fresh. `compare frankenphp frankenphp-worker` and `compare frankenphp frankenphp-octane` print `identical`.
- **Tests**:
  - Update tests that iterate servers or use nginx-fpm as the baseline (for example `DdevServerCommandTest`, `ServerSwitchChecksTest`, `BenchSuiteServersTest`, `BenchTimeoutsTest`, `ReadmeSwitchingServersTest`) to the three modes, with frankenphp as the baseline.
  - Delete tests whose only subject is a removed server.
  - Doc tests that read historical numbers from `BENCHMARKS.md` stay.
- **Docs**:
  - `README.md`, `CLAUDE.md` and `docs/getting-started.md` describe the three modes.
  - `BENCHMARKS.md` gets a top note: the server comparison phase (nginx-fpm, apache-fpm, OpenLiteSpeed) is history, reproducible from the last commit that had them. Its sections stay.
  - `docs/learning-guide.md` sections 1–8 get one history line each where they describe a removed server. Section 2 ("Switching servers") describes the three modes.
  - `docs/architecture.md` is updated where it lists servers.
- **In-app guides**:
  - `resources/guides/streaming.php` (and any other guide that compares servers) explains the three FrankenPHP modes for AI streaming: classic, worker and Octane.
  - Where a guide quotes a PHP-FPM result as the reason, it says "measured earlier on PHP-FPM".
  - The home page server modes are already the three (`LabHomeService::serverModes()`); the test pins them.
- **Guard test**: `tests/Feature/FrankenphpOnlyTest.php` fails if `nginx-fpm`, `apache-fpm` or `openlitespeed` appears in a tracked file outside the history allowlist.

### Out of scope

- Changing `.ddev/config.yaml` (see Clarifications).
- Deleting the old Docker images or volumes of removed servers. Nothing is deleted on the host; the report prints what the owner may prune.
- Rewriting historical result files or `BENCHMARKS.md` sections.
- New FrankenPHP features (SL-15–17).

## Must NOT change

- **MNC-1**: The default server stays classic FrankenPHP with Mercure. `ddev server default`, `ddev setup` and `ddev server --check default` behave as today.
- **MNC-2**: Every app route answers the same on the three modes. The parity snapshots of the three modes are identical to each other, and their per-route hashes equal today's committed `bench/parity/frankenphp.txt` lines.
- **MNC-3**: The worker and Octane modes keep their current configs (`.ddev/servers/frankenphp-worker`, `frankenphp-octane`) byte-identical.
- **MNC-4**: Historical data stays: `bench/results/**` and the measured sections of `BENCHMARKS.md` are unchanged, and the doc tests that read them stay green.
- **MNC-5**: The main asset fingerprint stays `app-DNob7CFE.css` / `app-PTzu1QaN.js`.
- **MNC-6**: `.ddev/config.yaml` is unchanged.

## Acceptance criteria

- **AC-1**: WHEN `ddev server` is run without arguments or with `--help` THE SYSTEM SHALL list exactly `frankenphp`, `frankenphp-worker` and `frankenphp-octane`. WHEN it is run with `nginx-fpm`, `apache-fpm` or `openlitespeed` THE SYSTEM SHALL exit 1 with the removal message and leave the active server unchanged.
- **AC-2**: WHEN a bench matrix or suite runs without an explicit server list THE SYSTEM SHALL use only the three FrankenPHP modes. IF a removed server is passed THEN the script SHALL exit non-zero with the same removal wording.
- **AC-3**: WHEN parity is captured on each of the three modes THE SYSTEM SHALL write three snapshots that compare `identical` pairwise, and no snapshot of a removed server remains.
- **AC-4**: WHEN `FrankenphpOnlyTest` runs THE SYSTEM SHALL find no `nginx-fpm`, `apache-fpm` or `openlitespeed` in tracked files outside the allowlist:
  - `bench/results/**`, `.ai/**`, `BENCHMARKS.md`, `product-brief.md` and `backlog.md`;
  - `docs/learning-guide.md` (history lines only);
  - `docs/adr/**`;
  - the test itself;
  - the `ddev server` removal message.
- **AC-5**: WHEN a learner reads the README, the getting-started doc, the streaming guide or the home page THE SYSTEM SHALL describe exactly the three FrankenPHP modes and how to switch between them.
- **AC-6**: WHEN the web image is built THE SYSTEM SHALL not install apache modules for a removed server.

## Boundaries

- **Always**:
  - Keep the history.
  - Run the full suite, Pint, PHPStan, typecheck and build.
  - Capture parity on the three modes after the code change.
- **Ask first**:
  - Any `.ddev/config.yaml` edit.
  - Pruning Docker images or volumes.
  - Deleting a historical result file.
- **Never**:
  - Delete `bench/results/**`.
  - Edit measured numbers in `BENCHMARKS.md`.
  - Change the worker or Octane configs.
  - Weaken a doc test that guards a historical number.

## Technical context

- **Related code**: `.ddev/commands/host/server`, `.ddev/servers/*`, `.ddev/web-build/Dockerfile`, `bench/*.sh`, `bench/lcp.mjs`, `bench/summary.php`, `bench/parity/*`, `bench/lib/default-server.sh`, `tests/Feature/Bench*Test.php`, `tests/Feature/DdevServerCommandTest.php`, `ServerSwitchChecksTest.php`, `ReadmeSwitchingServersTest.php`, `resources/guides/streaming.php`, `app/Services/LabHomeService.php`.
- **Already implemented**:
  - frankenphp is the default through the overlay `.ddev/config.zz-server.yaml` (SL-47).
  - The home page already lists the three modes (SL-49).
  - SL-49 captured parity on all six servers today.

## Clarifications (binding)

Self-decided under the owner's standing approval.

- **`.ddev/config.yaml` stays as is (`webserver_type: nginx-fpm`).** It is only DDEV's bootstrap before `ddev setup` writes the overlay, and changing it needs the owner's approval. `ddev server --current` and the README say "not set up" in that state, so nginx-fpm is never presented as a mode. Moving the FrankenPHP overlay into the base config is offered to the owner as a follow-up.
- **Removed means removed from runnable code.** Past runs stay readable: `summary.php` still renders committed results that name removed servers, and `BENCHMARKS.md` keeps its tables. A learner who wants to re-run them checks out the last commit before SL-50, named in the `BENCHMARKS.md` note.
- **"PHP-FPM" as a concept may stay in guide prose and in the learning guide**, as the reason FrankenPHP's model differs. Only the server identifiers are banned outside history.

## Clarity gate answers (binding, self-decided 2026-11-10)

1. **Allowlist.** The guard test holds a PHP array of allowed paths, each entry with a one-line reason. Besides the history paths in AC-4 it allows:
   - `.ddev/config.yaml` (MNC-6) and the `CLAUDE.md` sentence about it;
   - `bench/lib/servers.sh` (the removed-name list, point 2);
   - the doc tests that assert historical numbers or wording (`BenchmarksDocTest` and any test reading `BENCHMARKS.md` or `bench/results`);
   - `BenchSummaryTest` (historical fixtures);
   - `docs/learning-guide.md` and `docs/adr/**`.

   Every other tracked text file fails.
2. **Removed names** live in one list, `REMOVED_SERVERS=(nginx-fpm apache-fpm openlitespeed)` in a new `bench/lib/servers.sh`, next to `SUPPORTED_SERVERS` (the three modes). The server command and the bench scripts source it.
   - A removed name gets the removal message and exit 1. That is exit 2 for bench scripts, which already use 2 for bad input.
   - Any other unknown name keeps today's "unknown server" message.
   - `default` stays accepted as an alias. The help shows the three modes plus `default`.
3. **Guard matching.**
   - Case-insensitive regex `nginx-fpm|apache-fpm|openlitespeed` over `git ls-files` (the same helper `RenameTest` uses; git works in the web container).
   - Binary files are skipped. `.gitignore` is included.
   - `nginx`, `apache` and `8091` alone are not matched; task 2 removes their code paths.
   - `docs/learning-guide.md` is allowed whole, because it is history narrative. `LearningGuideTest` pins that section 2 names exactly the three modes as the current ones.
4. **Parity is captured last**, after the doc and guide edits (task order: 1, 2, 3, 5, 6, then 4, then 7).
   - The parity request set has 7 non-lab routes (`/up`, `/api/ping`, listings, detail and so on), which no guide or home edit touches, so MNC-2's equal-hash rule holds.
   - Guide edits do not touch the main entry, so the MNC-5 fingerprint holds.
5. **`--current`**:
   - **Overlay names a supported mode:** prints that mode (normally `frankenphp`; manual step 1).
   - **No overlay:** prints `not set up (run ddev setup)` and exits 0.
   - **Overlay names a removed server** (an old checkout): prints `<name> (removed; run ddev server default)`. `--check default` then exits 1, and `ddev setup` switches to the default instead of printing "Already set up".

   "Leave the active server unchanged" in AC-1 means the overlay file is byte-identical after a refused switch.
6. **Last commit with the old servers.** The build records `git rev-parse HEAD` in tasks.md before its first code change; that is the approved-spec commit. `BENCHMARKS.md` names it, and manual step 3 uses it. The orchestrator makes the commits after the fix pass, and task 7.1's ping runs after them.
7. **Bench contract.** Scripts keep their current way of taking servers (the `SERVERS` env var or arguments). Default lists come from `SUPPORTED_SERVERS`, and removed names exit 2 with `server_removed_message` from `servers.sh`. `summary.php` takes no server argument and renders whatever committed results exist, so it is outside AC-2.
8. **Test rule.**
   - Delete a test only if every assertion targets a removed server's config or script.
   - Rewrite mixed tests to the three modes, with frankenphp as the baseline.
   - Keep assertions on historical result files or `BENCHMARKS.md` numbers unchanged.
   - The build lists every touched test file with its outcome (deleted, rewritten or kept) in tasks.md.
9. **Dockerfile.** Remove both apache lines. If the file then has no `RUN`, `COPY` or `ADD` instruction, delete it. AC-6 asserts that no file under `.ddev/web-build/` contains `apache` or `xsendfile`.

   **`.gitignore`.** Remove exactly `.ddev/docker-compose.openlitespeed.yaml`, `.ddev/nginx/bench-timeouts.conf` and `.ddev/nginx_full/nginx-site.conf.bench-orig`, after a grep shows no script writes them.
10. **Terms and outputs.**
    - **History line:** one italic line at the top of each learning-guide section from 1 to 8 that measured a removed server: `*History: measured on nginx-fpm, apache-fpm and OpenLiteSpeed, which the lab dropped on 2026-11-10; the FrankenPHP rows still apply.*`
    - **Prune list:** the orchestrator's final chat report lists the local Docker images and volumes of the removed servers (`docker images` and `docker volume ls` filtered by `litespeed` and the unused PHP-FPM web image tags). Nothing is deleted.
    - **`PARITY_INSECURE=1`** makes curl accept DDEV's local certificate (`-k`).
    - **Other guides:** any guide whose text compares servers by identifier gets the same wording. A concept mention ("works under php-fpm") stays.

## Open questions

- [x] Clarity gate (10 items) answered above.
- [x] Accepted assumption: BR-26 and BR-27 come from a draft brief. The owner applies them in every slice (standing approval).
