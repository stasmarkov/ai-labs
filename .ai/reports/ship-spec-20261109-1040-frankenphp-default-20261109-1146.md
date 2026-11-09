# Ship report: spec-20261109-1040-frankenphp-default (SL-47)

## Summary

SL-47 makes FrankenPHP classic, with the Mercure hub, the project's default server. It adds `ddev server --default` and `ddev server default`, a new `ddev setup` host command for fresh clones, and a shared `bench/lib/default-server.sh` so bench scripts restore the default instead of nginx-fpm. It also updates the docs. On the live DDEV project the user journey works: setup from a no-overlay checkout reaches frankenphp, the hub answers 200 `text/event-stream`, and a second run reports "Already set up". All gates are green.

The slice is not finished:
- Task 5 (bench on a clean tree) is open.
- Three converge gaps are unbuilt.
- AC-3, AC-4 and AC-5 are only partly met.
- Review confirmed three major findings, the most serious being that `ddev setup` cannot bring up a real fresh clone.
- Nothing is committed.

**Recommendation: FIX-FIRST.**

## Stack & repos

| Repo | Role | Stack | Tracked | Notes |
|---|---|---|---|---|
| `.` | backend | laravel | yes | WIP: every change is uncommitted on `master`. That is 36 modified tracked files, plus the untracked `.ddev/commands/host/setup`, `bench/lib/default-server.sh` and three new tests. |

Stack overlay: discovery installed-plugins (plugin); added: none; ignored: 0; rejected: 0; untrusted: none. Discovery errors: trustStore unreadable, user stacks unreadable. Present: `plugin:sdd-kit-laravel@sdd-kit`.

## Spec

- Id: `spec-20261109-1040-frankenphp-default`
- Folder: `.ai/specs/spec-20261109-1040-frankenphp-default`

## Investigation highlights

The investigation step returned no files, risks or prior work. The points below come from the build, converge and acceptance phases:

- **Key files:** `.ddev/commands/host/server` (default target, `--default`, no-args hint) and `.ddev/commands/host/setup` (new). Also `bench/lib/default-server.sh` (new), `bench/{suite,chat-stop,http3,http3-matrix,slow-load-matrix,export-load-matrix}.sh`, `tests/Feature/{ServerDefaultCommandTest,DocsDefaultServerTest,BenchScriptsTest}.php`, README.md, CLAUDE.md, BENCHMARKS.md, `docs/learning-guide.md`, and the earlier slices' manual-test plans.
- **Risks:**
  - `vendor/` is gitignored, so a fresh-clone bring-up depends on Composer.
  - Gitignored activated files can drift away from the overlay marker.
  - Mercure JWT secrets are handled in a shell script.
  - `bench/run.sh` refuses a dirty tree, so AC-5 can only be checked after a commit.
- **Already shipped:** SL-13 (Mercure live price on FrankenPHP), the `ddev server` switcher, `MercureKeysCommand` (owner-only key writes), and the bench harness and parity tooling. `.ddev/config.yaml` deliberately still says nginx-fpm (MNC-1, diff empty).

## Tasks completed

- Build: 10/14. **Incomplete: 5** (bench on a clean tree: `bench/run.sh ping --quick` after the commit, exit 0).
- Checked: 1, 2, 3, 4, 6, 7, 8, 9, 10.
- Task 11 (README parity examples and the CLAUDE.md config.yaml note) is still unchecked in `tasks.md`. Acceptance checked the tree and found it done: README uses `compare frankenphp <x>` five times, and the "on purpose" note is in CLAUDE.md. Tick it once someone confirms.
- Tasks 12, 13 and 14 are open converge gaps (see below).

## Converge

- Rounds run: 2.
- Gap tasks appended: 9.
  - Round 1: #6 to #11 (partial Mercure keys abort setup, the live AC-2 check, guide section 2 note, nginx-fpm-as-default wording in the manual-test plans, doc-test regex, README/CLAUDE.md notes).
  - Round 2: #12 to #14.
- Gaps left unbuilt:
  - **#12** http3-matrix.sh has no test for its restore target or measured server.
  - **#13** Manual-test plans still restore nginx-fpm at the end of blocks and in rollbacks.
  - **#14** BENCHMARKS.md top note does not mention `ddev setup`.

## Quality gates

| Repo | Gate | Status | Details |
|---|---|---|---|
| `.` | Pint | pass | 437 files (via ddev exec) |
| `.` | PHPStan | pass | No errors (via ddev exec) |
| `.` | PHPUnit | pass | 1281 passed, 14305 assertions (via ddev exec) |
| `.` | Debug scan | pass | No `dd(` / `dump(` / `ray(` in app/, routes/, database/ |

No gate was unreachable. Contract-sync does not apply (single repo).

## Visual tests

Not run (no visual stage; this slice has no UI change).

## Acceptance

**Did the original scenario resolve end-to-end? No.** The live journey works, but the slice's own acceptance criteria are not all met. The trace found no broken hop.

Hops verified against the real DDEV project:
1. `ddev server --default` printed `frankenphp`.
2. `ddev server` with no arguments printed "Active: frankenphp (default: frankenphp)" and no hint.
3. `default` and `frankenphp` behaved the same, and an unknown server name exited 1.
4. With the overlay moved aside, the command fell back to "Active: nginx-fpm ... Not set up: run ddev setup".
5. The first `ddev setup` switched to frankenphp, kept the existing keys (md5 unchanged) and migrated.
6. The hub answered 200 `text/event-stream`.
7. The second `ddev setup` printed "Already set up: frankenphp".
8. The bench dry runs restore `ddev server frankenphp`.

181 targeted tests pass.

| AC | Status | Note |
|---|---|---|
| AC-1 | met | Verified live and in ServerDefaultCommandTest |
| AC-2 | met | Verified live, but only on a checkout that already has `vendor/` and APP_KEY (see the major finding) |
| AC-3 | partly met | Code is correct, but `bench/http3-matrix.sh` only has a static string check (gap 12) |
| AC-4 | partly met | BENCHMARKS.md top note lacks `ddev setup` (gap 14). Manual-test plans still end on `ddev server nginx-fpm` (gap 13): bench-harness:20, early-hints 39/80/132/247, http3 43/103, slow-io-healthz 131/149, local-model-wiring 37/50, streamed-car-description, docs-index-retrieval. bench-harness step 2 still expects nginx-fpm right after `ddev server default`. |
| AC-5 | not met | Nothing is committed, and `bench/run.sh:148-149` refuses a dirty tree, so the clean-tree ping run never happened (task 5). |
| MNC-1 to MNC-5 | held | config.yaml diff empty. Fingerprint unchanged (app-DNob7CFE.css, app-PTzu1QaN.js). Live parity was re-run on frankenphp only; the other five rely on tasks.md 4.2. History is unchanged apart from the BENCHMARKS top note. |

E2E regression test: none written. This stack declares no browser e2e, and E2E is null. Acceptance relied on the feature tests and the live hops above.

## Confirmed review findings

### Major

- **`.ddev/commands/host/setup:80`**: setup never runs `ddev composer install` or `key:generate`. On a real fresh clone `vendor/` is missing, so the first `ddev artisan mercure:keys` call fails under `set -e`. The site would also return 500 with an empty APP_KEY. Fix: run `ddev composer install` (and `ddev artisan key:generate` when APP_KEY is empty) before the artisan steps, or narrow the "run `ddev setup`" claim in README.md:46 and CLAUDE.md.
- **`bench/slow-load-matrix.sh:38`** (also `bench/export-load-matrix.sh:35`, `bench/http3-matrix.sh`): `trap restore EXIT` is installed before `read_default_server`. A failed read then fires `restore`, which in slow-load runs `concurrency.sh default` and so a full `ddev restart`. It also re-queries ddev, prints the error twice and exits 2 inside the trap. `--dry-run` also needs a working ddev. Fix: call `read_default_server` before the trap, as `suite.sh` does, and skip or stub the lookup under `--dry-run`.
- **`tests/Feature/BenchScriptsTest.php:614`**: the guard `/ddev server (nginx-fpm|frankenphp)(\s|$)/` misses `ddev server nginx-fpm)`, which is the exact restore it replaced. It is also not scoped to trap or cleanup bodies or the last 10 lines (Q4), and `$restoring` is a hard-coded list. Fix: use `(?![\w-])`, scope the check to trap, cleanup and tail blocks, and derive `$restoring` from scripts that call `ddev server "$..."`.

### Minor

- **`.ddev/commands/host/setup:90-91`**: `server_ready` only checks that the overlay exists and that `--current` matches the default. It reports "Already set up" even when the activated files are missing or stale or a bench overlay is left over. Fix: always call `ddev server default`, which already re-applies stale files and no-ops otherwise.
- **`.ddev/commands/host/setup:42`**: `umask 077` does not tighten an existing file, so Mercure keys can land in a 0644 `.env`. Fix: write a 0600 temp file in the same directory and `mv` it over the target, or `chmod 600` after writing.
- **`.ddev/commands/host/setup:39`**: the key write truncates `.env` in place, and the copy holding its secrets stays in `$TMPDIR` if the write is interrupted. Fix: rename atomically from a temp file next to the target, and add `trap 'rm -f "$temp"' EXIT`.
- **`tests/Feature/ServerDefaultCommandTest.php:96`**: `not->toContain('--force mercure')` can never fail. Fix: assert `not->toContain('mercure:keys --force')`.
- **`bench/lib/default-server.sh:13`**: the library reads an undeclared `APPROOT` global, so an exported `APPROOT` can redirect restores. Fix: pass the root explicitly, or honour only `BENCH_APPROOT`.
- **`.ai/specs/spec-20261029-1013-apache-fpm-parity/manual-test.md:8`**: the new precondition (start on frankenphp) contradicts Steps 1-3, which expect nginx-fpm and no overlay. The rollback alternative `rm -f .ddev/config.zz-server.yaml` lands on nginx-fpm. Fix: add an explicit `ddev server nginx-fpm` step first, and drop the `rm` alternative.
- **`.ai/specs/spec-20261029-1221-frankenphp-classic/manual-test.md:8`**: Step 1 expects a real switch to frankenphp, but you are already on it. The rollback at line 13 still runs `ddev server nginx-fpm`. Fix: start from nginx-fpm explicitly, and roll back with `ddev server default`.
- **`.ai/specs/spec-20261103-1204-rag-eval-harness/manual-test.md:153`**: the line "Leave the server on nginx-fpm: `ddev server default`" contradicts its own command. Fix: say "on the default server".
- **`.ai/specs/spec-20261104-1944-export-offload/manual-test.md:162`**: the rollback still runs `ddev server nginx-fpm`. Fix: change it to `ddev server default`.

## Documentation updated

These are uncommitted:
- README.md: default-server and `ddev setup` section, `compare frankenphp <x>` examples.
- CLAUDE.md: environment line, plus the note that config.yaml says nginx-fpm on purpose.
- BENCHMARKS.md: top note only.
- `docs/learning-guide.md`: section 2 note, and the "nginx-fpm by default" line removed.
- Preconditions and part of the rollbacks in the earlier manual-test plans under `.ai/specs/*/manual-test.md`.
- This slice's `spec-lite.md` and `tasks.md`.

Gaps 13 and 14 remain in the docs.

## Manual test plan

- Written to `.ai/specs/spec-20261109-1040-frankenphp-default/manual-test.md`.
- How to run: from the repo root on the host, with DDEV running on frankenphp and the Mercure keys present, follow Steps 1-6 in order. Step 1 backs up the overlay to `/tmp/sl47-<pid>`, and Step 3 needs the changes committed. To roll back, restore the backup into `.ddev/`, run `ddev restart`, then `ddev server default` (or re-run `ddev setup`).

## Recommendation

**FIX-FIRST.**
- The build is partial: task 5 is open.
- Converge gaps 12, 13 and 14 are unbuilt.
- The acceptance trace is unresolved (AC-3, AC-4 and AC-5 are partly met).
- All work is uncommitted WIP on `master`.
- Three major findings are confirmed, including a fresh-clone `ddev setup` that cannot run without `vendor/`.

Next: make `.ddev/commands/host/setup` run `ddev composer install` (and `ddev artisan key:generate` when APP_KEY is empty) before its first `ddev artisan` call, and add a test for it.
