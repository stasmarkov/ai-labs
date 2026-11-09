---
id: spec-20261109-1040-frankenphp-default
title: Classic FrankenPHP with the Mercure hub as the project's default server
type: improvement
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-09T08:42:53Z
created: 2026-11-09
stacks: [laravel]
slice: SL-47
brs: [BR-26, BR-27]
---

# Classic FrankenPHP with the Mercure hub as the project's default server

## Summary

The project's resting server becomes classic `frankenphp`, with the Mercure hub from SL-13 on. There is one source of truth for the name: `ddev server --default` prints it, and `ddev server default` switches to it.

A new tracked host command, `ddev setup`, brings a fresh clone onto the default with one call: start DDEV, switch, generate the Mercure keys if missing, and migrate. `.ddev/config.yaml` stays as it is, as the owner decided on 2026-11-09 ("Overlay + one setup step").

Every bench script and check that ends by restoring nginx-fpm restores the default instead. The docs that call nginx-fpm the default say FrankenPHP. The other five servers stay one `ddev server <name>` away, and parity stays identical on all six.

## Context

- **Facts checked on 2026-11-09:**
  - `.ddev/config.yaml` has `webserver_type: nginx-fpm`.
  - The server overlay `.ddev/config.zz-server.yaml` and the activated stash files (`docker-compose.frankenphp.yaml`, `web-build/Caddyfile.frankenphp`) are gitignored and written by `ddev server`.
  - With no overlay, `ddev server --current` falls back to `config.yaml`, which says nginx-fpm.
- **What refers to nginx-fpm as the resting server:**
  - Bench scripts: `chat-stop.sh`, `concurrency.sh`, `export-load-matrix.sh`, `http3.sh`, `http3-matrix.sh`, `slow-load-matrix.sh`, `suite.sh`, `timeouts.sh`, `versions.sh`, `lcp.mjs`, and `parity.sh` (its examples).
  - Documentation: README (switching section), `CLAUDE.md` (environment line), learning guide set-up lines, `BENCHMARKS.md` set-up lines, and the manual-test plans.
  - Tests: about 25 files mention nginx-fpm, and some of them pin a restore target.
- **Owner decision (2026-11-09):** no `config.yaml` edit. The default is the overlay, plus a one-time setup step for fresh clones.

## Scope

### In scope

- **`.ddev/commands/host/server`:**
  - a `DEFAULT_SERVER=frankenphp` constant;
  - `--default` prints it;
  - `default` is accepted as a target;
  - with no arguments the command prints `Active: … (default: frankenphp)`;
  - when the active server comes from the `config.yaml` fallback, i.e. no overlay exists, it also prints `Not set up: run ddev setup` (one line).
- **`.ddev/commands/host/setup`** (new, tracked):
  1. `ddev start`;
  2. `ddev server default`, if it is not already current;
  3. `ddev artisan mercure:keys` when the keys are missing (never `--force`);
  4. `ddev artisan migrate --force`;
  5. print the URL and the active server.
  It is idempotent: a second run changes nothing, and it says so.
- **Bench scripts:**
  - every hard-coded end-of-run `ddev server nginx-fpm` becomes `ddev server "$(ddev server --default)"` (or the default read once at script start);
  - the "ends on nginx-fpm" comments and usage text change to "ends on the default server (frankenphp)";
  - scripts that measure a specific server keep their explicit server arguments: the matrices still cover nginx-fpm and the rest, and only the resting target changes;
  - `lcp.mjs`: the same, wherever it restores.
- **`parity.sh`:** its usage examples compare against frankenphp as the reference.
  - The six baseline files stay as they are, because they are per server.
  - `compare nginx-fpm <x>` remains valid, and the README shows `compare frankenphp <x>`.
- **Docs:**
  - README "Switching servers": FrankenPHP is the default, `ddev setup` on a fresh clone, `ddev server default`, and nginx-fpm and the rest one command away.
  - `CLAUDE.md` environment paragraph: the default server is classic FrankenPHP (overlay), `config.yaml` still says nginx-fpm on purpose, and run `ddev setup` once.
  - Learning guide: a short "Default server" note in section 2, and set-up lines that say where a command must run.
  - `BENCHMARKS.md`: a "Default server" note at the top (from SL-47 on), with the historical sections unchanged.
  - Manual-test plans: their "end on nginx-fpm" lines, in the plans of shipped slices that are still meant to be re-run, become "end on the default server".
- **Tests:**
  - tests that pin the restore target expect the default;
  - a new `ServerDefaultCommandTest` covers `--default`, `default`, the not-set-up message, and `setup`'s idempotency, with a stubbed `ddev`.
- **This machine:** it is switched to the default at the end of the slice.

### Out of scope

- Changing `.ddev/config.yaml` (the owner decided).
- Re-running historical benchmarks on the new default.
- Making Mercure available on other servers.

## Must NOT change

- **MNC-1**: `.ddev/config.yaml` is byte-identical (`git diff HEAD -- .ddev/config.yaml` is empty).
- **MNC-2**: parity is `identical` on all six servers against the current baselines, and the asset fingerprint is unchanged.
- **MNC-3**: every server stays reachable with `ddev server <name>`, and the matrices still measure the servers they measured before.
- **MNC-4**: historical result files and their `BENCHMARKS.md` sections are unchanged, apart from the new top note.
- **MNC-5**: the full suite runs offline.

## Acceptance criteria

- **AC-1**: `ddev server --default` SHALL print `frankenphp`. `ddev server default` SHALL behave exactly as `ddev server frankenphp`. With no arguments, the command SHALL print the active server and the default, plus `Not set up: run ddev setup` when no overlay exists.
- **AC-2**: On a checkout with no overlay (simulated by moving `.ddev/config.zz-server.yaml` and the activated files aside), `ddev setup` SHALL leave the project running on frankenphp with the Mercure hub answering at `/.well-known/mercure` (200, `text/event-stream`). It SHALL create Mercure keys only if they are missing and SHALL run migrations. A second run SHALL report "already set up" and change nothing.
- **AC-3**: Every bench script that restores a server at the end SHALL restore `$(ddev server --default)`. `grep -rn 'ddev server nginx-fpm' bench/` SHALL only find explicit measurement steps, never a restore. Tests SHALL cover the restore target of each changed script.
- **AC-4**: The README, `CLAUDE.md`, the guide (section 2 note), `BENCHMARKS.md` (top note) and the manual-test plans SHALL name FrankenPHP as the default and `ddev setup` for fresh clones. A doc test SHALL fail if README or `CLAUDE.md` again call nginx-fpm the default.
- **AC-5**: The slice SHALL finish with this machine on frankenphp, parity `identical` on all six servers, the fingerprint unchanged, and `bench/run.sh ping --quick` exit 0 on a clean tree, run while on the default.

## Boundaries

- **Always**:
  - read the default from `ddev server --default`, never a literal, in scripts;
  - keep explicit server lists where a script measures many servers.
- **Ask first**: `.ddev/config.yaml`; changing which servers the matrices measure.
- **Never**:
  - rewrite historical measurements;
  - commit `.env`, `.ddev/.env.web` or overlays;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `.ddev/commands/host/server` (`SUPPORTED`, `active_server`, `is_current`, `--current`, `--url`);
  - the bench scripts listed above;
  - `bench/lib/*.sh`;
  - `tests/Feature/Bench*Test.php` (stub `ddev` on `PATH`);
  - README, `CLAUDE.md`, `docs/learning-guide.md` section 2, `BENCHMARKS.md`;
  - `app/Console/Commands/MercureKeysCommand.php`.

## Decisions (self-decided, standing approval)

1. **One name, one place.** `DEFAULT_SERVER` lives in the `server` command and every script asks it, so a later change of default is one line.
2. **`ddev setup` instead of a pre-start hook.** It is explicit and idempotent, and DDEV loads the overlay before hooks run, so a hook would only take effect on the next start.
3. **The "not set up" hint, not an error.** A fresh clone still works on nginx-fpm until setup runs, which keeps `config.yaml`'s truth visible.

## Clarifications (binding; resolve the clarity gate)

**"Set up" (Q1).** Set up means: the overlay exists, its active server equals `ddev server --default`, and both Mercure keys are non-empty in `.env` and in `.ddev/.env.web`. `ddev setup` checks this first. If everything holds, it prints `Already set up: frankenphp` and exits 0 after only `ddev start` (a no-op when running) and `migrate --force` (a no-op with no pending migrations). Otherwise it does the missing steps.

**Keys (Q2).** As in SL-13: both keys live in `.env` and `.ddev/.env.web`, and a key counts as "missing" when either file lacks it or has it empty. Setup then runs `mercure:keys` (never `--force`) followed by `ddev restart`, so Caddy reads them before the hub check.

**Simulating a fresh clone (Q3).** AC-2 and the manual test both use `ddev server`'s own clean state: back up `.ddev/config.zz-server.yaml` and every activated file listed by the server command, delete them, run `ddev setup`, and check. Restoring is not needed, because setup writes the default overlay again. The backup goes under `/tmp/sl47-<pid>/` and is removed at the end.

**Telling a restore from a measurement (Q4).**
- **The marker:** every end-of-run restore calls one shared helper, `bench/lib/default-server.sh` → `restore_default_server`, which runs `ddev server "$(ddev server --default)"`.
- **The test:** it asserts that no script has `ddev server nginx-fpm` inside a `trap`, a cleanup function, or its last 10 lines. It also asserts that each restoring script sources the helper.
- **`lcp.mjs`:** it calls the helper's logic through `ddev server --default` in its cleanup, and the test asserts it.

**Reading the default (Q5).** Scripts read it once, at start, into `DEFAULT_SERVER`. If `ddev server --default` fails or prints nothing, the script exits 2 with a message; there is no literal fallback. Stubbed `ddev` in tests answers `server --default` with `frankenphp`.

**The doc test pattern (Q6).** It fails if README or `CLAUDE.md` match `/(default|by default)[^.\n]{0,40}nginx-fpm|nginx-fpm[^.\n]{0,20}(is|by) (the )?default/i`. The intended `CLAUDE.md` sentence, "`config.yaml` still says `webserver_type: nginx-fpm` on purpose; the default server is FrankenPHP", does not match. The test also requires `ddev setup` in both files.

**Doc scope (Q7).**
- **Manual-test plans:** every plan under `.ai/specs/*/manual-test.md` whose rollback or ending tells the reader to "end on" or "switch back to" nginx-fpm. Those lines become "the default server (`ddev server default`)". Steps that measure nginx-fpm on purpose stay.
- **Learning guide:** a "Default server" note in section 2, plus any guide line that says to run something "on nginx-fpm" as a resting state.
- **Tests:** those that assert a restore to nginx-fpm are found by grep (`ddev server nginx-fpm` in `tests/`) and listed in task 2.

**Traceability (Q8).**
- **AC-2:** the stub test covers the logic, and the live hub check is manual-test step 1, which is added to the table.
- **MNC-5:** covered by the full-suite gate.
- **Test files:** `BenchScriptsTest.php` and `BenchmarksDocTest.php` exist and are extended. `ServerDefaultCommandTest.php` and `DocsDefaultServerTest.php` are new.

**No-args output (Q9).** It is all on stdout. The first line becomes `Active: <server> (default: frankenphp)`, the existing `Supported:` line follows, then `Not set up: run ddev setup` only on the `config.yaml` fallback. On the fallback, `Active:` shows the `config.yaml` value (`nginx-fpm`).

**`parity.sh` and the commit (Q10).**
- **`parity.sh`:** only its usage text and the README examples change, and the reference becomes frankenphp. No default argument is added, and each server is still compared with its own baseline.
- **The commit:** the slice's commit is made by the orchestrator after the build and the review fixes. The clean-tree bench (task 5) runs after it, and approval is recorded before the build starts.

## Open questions

Clarity gate 2026-11-09: 10 items, all answered under standing approval above.

- [x] Q1: What "set up" means.
- [x] Q2: Keys and the restart.
- [x] Q3: How to simulate a fresh clone.
- [x] Q4: The restore helper as the marker.
- [x] Q5: Read the default once and fail closed.
- [x] Q6: The doc test pattern.
- [x] Q7: Doc scope.
- [x] Q8: Traceability.
- [x] Q9: The no-args output.
- [x] Q10: `parity.sh` and the commit.

## Dependencies

- **Requires**: SL-13.
- **Blocks**: the AI slices that follow (they assume the default).

---
Next: `/sdd spec-20261109-1040-frankenphp-default` (approval → build)
