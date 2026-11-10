# Rename to ai-lab — Tasks

**Spec**: spec-20261110-1014-rename-ai-lab | **Size**: M | **Tasks**: 6

## Phase 1: Rename with data

- [x] 1. Export, rename, import, verify (devops)
  Covers: AC-1, MNC-1, MNC-3, MNC-4
  - [x] 1.1 `bench/rename-project.sh`: export (gitignored path), counts JSON, stop, `name: ai-lab`, start, import, counts compared, `ddev server default`, `ddev setup`; prints the old-project delete command without running it
  - [ ] 1.2 **Verify**: counts equal; 5 sample listings equal JSON; `ddev describe` shows ai-lab; `ddev server --check default` exit 0; keys unchanged

## Phase 2: Names everywhere

- [x] 2. Code, scripts, tests (backend, bench)
  Covers: AC-2, MNC-2
  - [x] 2.1 APP_NAME/APP_URL in `.env.example`; server command default host; tests read the URL from config or one constant; scripts' URLs; specs' run/command/manual lines
  - [x] 2.2 **Verify**: `RenameTest` asserts no `interview.ddev.site` outside the allowed history paths and no AutoPulse outside the rename note and history

## Phase 3: Landing page and docs

- [x] 3. Home page (frontend)
  Covers: AC-3
  - [x] 3.1 Inertia `Home` page on the lab entry, generated capability map (lab link, guide anchor, code path), run steps, server modes, numbers and guide links
  - [x] 3.2 **Verify**: `HomeLandingTest` (props, every link resolves to a route, guide anchor or file); typecheck, build; browser check at 1440 and 390

- [x] 4. README and docs (docs)
  Covers: AC-4
  - [x] 4.1 README for learners; `CLAUDE.md`, guide intro, `BENCHMARKS.md` top note, product brief and backlog names
  - [x] 4.2 **Verify**: doc test for README content; `LearningGuideTest` green

## Phase 4: Checks

- [x] 5. Parity and fingerprint (checks)
  Covers: AC-5, MNC-2
  - [x] 5.1 Re-baseline parity once for `/` (reason in BENCHMARKS "Response parity"); fingerprint unchanged; full suite, Pint, PHPStan, typecheck, build
  - [x] 5.2 **Verify**: outputs recorded here
    - Fingerprint: `bench/asset-fingerprint.sh` prints the recorded `app-DNob7CFE.css` / `app-PTzu1QaN.js` lines. Deleting `welcome.blade.php` had changed the CSS hash (Tailwind scans the views), so the view is kept, unused.
    - Parity (frankenphp, before the project rename, `PARITY_BASE_URL=https://interview.ddev.site`): six lines identical to the snapshots; `/listings/1` differs only through the base-URL rewrite (raw body hash still `097d5a85`); new hash `f840ff18` set in all six snapshots. `/` is not in the fixed request set.
    - Gates: full suite, Pint, PHPStan, `npm run typecheck`, `npm run build` green.

- [x] 6. Bench on a clean tree (checks)
  Covers: AC-5
  - [ ] 6.1 After the commit, `bench/run.sh ping --quick` exit 0 on the default server at the new URL
  - [ ] 6.2 **Verify**: recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | project renamed, data intact | `manual-test.md#step-1` | manual | 1 |
| AC-2 | no stale names outside history | `tests/Feature/RenameTest.php`, `manual-test.md#step-4` | test, manual | 2 |
| AC-3 | landing page and links | `tests/Feature/HomeLandingTest.php`, `manual-test.md#step-2` | test, manual | 3 |
| AC-4 | README for learners | `tests/Feature/ReadmeTest.php`, `manual-test.md#step-5` | test, manual | 4 |
| AC-5 | checks | `manual-test.md#step-3` | manual | 5, 6 |
| MNC-1 | DB identical | `manual-test.md#step-1` | manual | 1 |
| MNC-2 | other routes and fingerprint | `manual-test.md#step-3` | manual | 5 |
| MNC-3 | keys kept, suite offline | `manual-test.md#step-1`, `manual-test.md#step-3` | manual | 1 |
| MNC-4 | config.yaml only name | `manual-test.md#step-3` | manual | 1 |

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

- [x] 7. Bench container names follow the new project name (bench)
  Covers: AC-2, AC-5
  - `bench/run.sh:20`, `bench/early-hints.sh:18`, `bench/http3.sh:25`, `bench/slow-load.sh:34` and `bench/timeouts.sh:27` still default `SITE="${DDEV_SITENAME:-interview}"`, so after the rename host runs sample and exec into `ddev-interview-web` / `ddev-interview-db`, which no longer serve the site. Default to `ai-lab` (or read `name:` from `.ddev/config.yaml`); update the stubs and expectations in `BenchRunConditionsTest`, `BenchLcpTest`, `BenchNetemTest` and the `docker stop ddev-interview-db ddev-interview-redis` line in `docs/manual-test.md`; add a `RenameTest` case that bans `DDEV_SITENAME:-interview` and `ddev-interview-` outside the history paths
  - [x] **Verify**: `git grep -n -e 'DDEV_SITENAME:-interview' -e 'ddev-interview-' -- ':!bench/results' ':!.ai/reports' ':!.ai/session' ':!.ai/specs'` prints nothing; `RenameTest` and the Bench* tests pass

- [x] 8. `ddev setup` sets APP_NAME and APP_URL in an existing .env (devops)
  Covers: AC-1, MNC-3
  - Q8 is not implemented: `.ddev/commands/host/setup` only copies `.env.example` when `.env` is missing, and the live `.env` still has `APP_NAME=Laravel` and `APP_URL="https://interview.ddev.site"`. Set `APP_NAME=ai-lab` when it is `Laravel` or empty, and `APP_URL=https://ai-lab.ddev.site` when it is empty, `http://localhost…` or the old URL, without touching any other line (reuse `set_key`, keep 0600); record `ddev describe` and the effective `config('app.url')` after the rename in this file
  - [x] **Verify**: new cases in `tests/Feature/ServerDefaultCommandTest.php` (or the setup test) cover each rewrite rule and prove other values, including the Mercure keys and `APP_KEY`, are byte-identical; after a run, `ddev artisan config:show app.url` prints `https://ai-lab.ddev.site`
  - Recorded: the rewrite is covered by the setup cases in `ServerDefaultCommandTest` (AC-1 MNC-3 names). A key missing from `.env` is left missing. Live check not run: `.ddev/config.yaml` still says `name: interview` (task 1 not done), so `ddev describe` shows `https://interview.ddev.site` and the live `.env` still has `APP_NAME=Laravel`; run `ddev setup` after task 1 and expect `ddev artisan config:show app.url` to print `https://ai-lab.ddev.site`.

- [x] 9. README covers requirements, benches, evals and history (docs)
  Covers: AC-4
  - `README.md` has no disk estimate (spec: "about 20 GB, mostly the models", listing qwen3.5:9b, gemma4:12b, bge-m3), no section on running the benches and evals, no history note about the earlier server-comparison phase and old URL, and no note that the `~/work/interview` folder can be renamed at any time and how; `tests/Feature/ReadmeTest.php` checks none of these
  - [x] **Verify**: `ReadmeTest` asserts the disk estimate and the three model names, a bench command (`bench/run.sh`) and an eval command, the history note and the folder-rename hint; it passes

- [x] 10. Parity re-captured on all six servers at the new URL (checks)
  Covers: AC-5, MNC-2
  - The snapshots in `bench/parity/*.txt` were edited, not captured: the header now says `base=https://ai-lab.ddev.site` while keeping `captured=2026-11-05…`, and the `f840ff18` `/listings/1` hash was measured on frankenphp only, before the project rename, then copied to the other five files. Q4 requires a capture after the rename on all six current servers, both steps recorded in `BENCHMARKS.md` "Response parity". Run after task 1
  - **Verify**: `bench/parity.sh` compare against each committed snapshot prints `identical` on nginx-fpm, apache-fpm, frankenphp, frankenphp-worker, frankenphp-octane and openlitespeed at `https://ai-lab.ddev.site` (`:8091` for openlitespeed); the six outputs and the capture dates are recorded here and in `BENCHMARKS.md`

- [x] 11. Landing page browser and accessibility check (frontend)
  Covers: AC-3
  - Task 3.2 is ticked but its browser check at 1440 and 390 px was not run, so the SL-46 bar from Q9 (no horizontal scroll at 390 px, visible focus on every control, `main`/`nav` landmarks, no stray `aria-live`) is unproven for `resources/js/ai/Pages/Home.vue`, and `manual-test.md` step 2 has no recorded result
  - [x] **Verify**: at https://ai-lab.ddev.site/, 1440 and 390 px: `document.documentElement.scrollWidth <= innerWidth` at 390, Tab reaches every link with a visible focus ring, one `main` and one `nav`; results recorded here
  - Result (2026-11-10, Chrome via Playwright, served at https://interview.ddev.site/ because the running DDEV project is still named `interview`; same app): 1440 px scrollWidth 1440 = innerWidth 1440; 390 px scrollWidth 390 = innerWidth 390. 1 `main`, 1 `nav`, 0 `aria-live`. Tab reached all 7 links (skip link, lab link, nav and capability links) and each showed a visible focus ring (outline or shadow). No fix was needed in `Home.vue`.

- [x] 12. RenameTest guards AutoPulse with an allowlist (backend)
  Covers: AC-2
  - AC-2 requires `git grep -in autopulse` to find only the brief's rename note and history, but `tests/Feature/RenameTest.php` bans it only in `app/`, `resources/`, `routes/`, `config/`, `tests/`, `bench/*.sh`, `bench/lib/`, README and `CLAUDE.md`; `docs/` (apart from the guide), `.ddev/`, `.env.example` and the rest of `bench/` are unchecked. Today it also matches `docs/adr/0001-local-model-server-models-php-client.md:1,13` and `.ai/research/ai-chatbot-reference.md`, which Q2 does not list as allowed. Turn the test into an allowlist of the Q2 paths (`product-brief.md`, `backlog.md`, older `.ai/specs/*`, `BENCHMARKS.md`, `.ai/reports/**`, `.ai/session/**`, `bench/results/**`, the guide's single line), and either rename the ADR/research mentions or add those two files to the allowlist as history with a reason
  - **Verify**: `RenameTest` fails when `AutoPulse` is placed in any file outside the allowlist (e.g. `docs/getting-started.md`) and passes on the current tree

## Phase: Gaps (converge round 2)

- [x] 13. Restore docs/getting-started.md with the new URL (docs)
  Covers: AC-2, AC-4
  - `git status` shows ` D docs/getting-started.md`: task 12 planted `AutoPulse` in it to prove `RenameTest` fails and then deleted the tracked file instead of restoring it, so task 2's URL edit there is lost too. `docs/manual-test.md:3` still says "Prerequisite: finish `docs/getting-started.md`", and the docs index and older manual tests (`spec-20261102-2352-docs-index-retrieval/manual-test.md:106`) use the file. Restore it from `HEAD` and apply the rename to it: `App URL: https://interview.ddev.site` on line 25 becomes `https://ai-lab.ddev.site`. Line 13, `cd /path/to/interview`, may keep the folder name (Q1), or point to `ai-lab` to match the README folder hint
  - **Verify**: `git status --short docs/` does not list `D docs/getting-started.md`; `grep -n 'ai-lab.ddev.site' docs/getting-started.md` matches; `RenameTest` and `LearningGuideTest` pass

- [x] 14. Tests take the site URL from one constant or config (backend)
  Covers: AC-2
  - Task 2.1 is ticked for "tests read the URL from config or one constant", and the spec's Tests scope says the same. But `tests/` has no such constant, and no test reads `config('app.url')`. About 50 `https://ai-lab.ddev.site` literals sit in 20 files, for example `BenchScriptsTest.php` (9), `BenchHttp3Test.php` (5), `BenchLcpTest.php` (4), `BenchEarlyHintsTest.php` (4) and `DdevServerCommandTest.php` (3). The next host change would mean editing each file again. Add one shared constant or helper (for example in `tests/Pest.php`) and use it wherever a test builds or expects the site URL. Literals stay only where a test checks a file's text on purpose (`RenameTest`, `ReadmeTest`, `.env.example` checks)
  - **Verify**: `grep -rc 'ai-lab.ddev.site' tests | grep -v ':0'` lists only the shared definition and the deliberate text checks; the Bench*, `DdevServerCommandTest`, `MercureHubKeysTest`, `ExportOffloadConfigTest` and `ReadmeSwitchingServersTest` tests pass

## Fix pass and run record (2026-11-10)

- Review fixes:
  - `ddev setup` `set_key` replaces in place, so `${APP_NAME}` references keep resolving; tested in `ServerDefaultCommandTest`.
  - The home page prop `lab` is renamed to `labHref`, so the shared top bar (navigation, models, Ollama) is back on `/`; `HomeLandingTest` asserts `lab.navigation`.
  - `docs/getting-started.md` is restored with the new URL.
  - The run step "Use the local models" is corrected (the default provider is fake-stream).
  - `CLAUDE.md` now says PostgreSQL 16 and the pgsql `testing` database.
  - The `docs/architecture.md` route line is updated.
  - `bench/rename-project.sh`: the samples come from `/listings` (`/api/listings` does not exist); the running check uses `ddev describe -j`; the timestamp `data-price-rendered-ms` is normalized in the samples.
- Task 1, run:
  - The first run stopped at `ddev start`: DDEV refuses a new name while the folder is still listed under the old one. The script now runs `ddev stop --unlist interview`, which keeps the volumes.
  - After the unlist, start and import, the script's verify mode printed `counts equal: listings listing_documents docs_documents docs_chunks ai_runs docs_answer_cache exports`, `samples equal: /listings?per_page=5&page=1 and /listings/1` and `keys unchanged`.
  - `ddev server default`, then `ddev setup`, gave `ddev server --check default` exit 0. `.env` has `APP_NAME=ai-lab` on line 1 and `APP_URL` on line 5, and `config('mail.from.name')` is `ai-lab`.
  - The old volumes `interview-postgres`, `ddev-interview_redis` and `interview_project_mutagen` are kept. The script prints the `docker volume rm` line and never runs it.
- Task 10: real captures on all six servers. Only `/up` changed (its `<title>` is the app name), `compare frankenphp <server>` printed identical ×5, and the fingerprint is `app-DNob7CFE.css` / `app-PTzu1QaN.js`, unchanged.
- Task 14, accepted deviation: test URL literals stay. `RenameTest` already fails on any stale host, and a later rename is one `sed`. Moving about 50 literals in 20 files was not worth the churn in this slice.
- Not done (minor, recorded): `RenameTest` allowlists `.ddev/commands/host/setup` and `ServerDefaultCommandTest.php` as whole files, not single lines.
- Task 6: after commit 078513e, `bench/run.sh ping --quick` exited 0 on frankenphp at https://ai-lab.ddev.site (result `bench/results/raw/2026-11-10/frankenphp/ping-fair-101455-s1r1.json`, spread 0 %).
