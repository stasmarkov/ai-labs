---
id: spec-20261110-1014-rename-ai-lab
title: Rename the project to ai-lab and make the homescreen a study-lab landing page
type: improvement
size: M
priority: P1
status: draft
ticket:
stakeholders: [Stanislav Markov]
approved_by:
approved_at:
created: 2026-11-10
stacks: [laravel]
slice: SL-49
brs: [BR-27]
---

# Rename the project to ai-lab and make the homescreen a study-lab landing page

## Summary

The project becomes **ai-lab**: a study lab for implementing AI features in Laravel, with local and cloud model providers, on FrankenPHP. The pivot was decided by the owner on 2026-11-10; the product brief is already revised.

This slice renames everything:
- the DDEV project (`name: ai-lab` in `.ddev/config.yaml`, owner-approved), so the URL becomes https://ai-lab.ddev.site. The database is carried over with a verified export and import;
- `APP_NAME` and `APP_URL`;
- every current reference in code, scripts, tests, docs and specs' run and command lines.

It also replaces Laravel's default welcome page at `/` with a landing page for learners:
- what the lab teaches;
- how to run it (`ddev setup`, Ollama models, optional cloud keys);
- a capability map linking into `/ai`, the guide sections and the code;
- the providers and server modes;
- where the numbers live (`BENCHMARKS.md`).

Historical result files and past reports keep the URLs they were recorded with.

## Context

- **Facts checked on 2026-11-10:**
  - **DDEV:** `.ddev/config.yaml:1` is `name: interview`. The server command defaults to `https://${DDEV_HOSTNAME:-interview.ddev.site}`, and the OpenLiteSpeed stash has a vhost folder named `interview` (that server is removed in SL-50, so it is only renamed if it stays reachable in this slice).
  - **References:** 76 tracked files outside `bench/results`, `.ai/reports` and `.ai/session` contain `interview.ddev.site`, mostly tests, bench scripts, docs and spec manual tests.
  - **Home route:** `routes/web.php` maps `/` to `Route::view('/', 'welcome')`, Laravel's default page.
  - **App name:** `APP_NAME=Laravel` in `.env.example`.
  - **Remote:** there is no git remote, so nothing outward-facing is affected.
- **Data at risk.** A DDEV rename creates new containers and volumes. The database holds 50 000 listings, their documents and embeddings, the docs index, `ai_runs`, the answer cache, exports and so on. It must be exported before and imported after, then verified.
- **Project instruction.** The previous rule was never to edit `.ddev/config.yaml` without asking. The owner approved this edit on 2026-11-10, for the `name:` line only.

## Scope

### In scope

- **Rename with data.** `bench/rename-project.sh` (one-off, kept for documentation) runs:
  1. `ddev export-db --gzip --file=.ai/tmp/pre-rename.sql.gz` (gitignored path);
  2. row counts of key tables (`listings`, `listing_documents`, `docs_documents`, `docs_chunks`, `ai_runs`, `docs_answer_cache`, `exports`), recorded to a JSON;
  3. `ddev stop`;
  4. edit `name: ai-lab`;
  5. `ddev start`, `ddev import-db`, then the same counts compared; a mismatch stops with an error;
  6. `ddev server default` and `ddev setup`;
  7. the old project's containers and volumes are **not** deleted automatically. The script prints the `ddev delete interview --omit-snapshot` command for the owner to run later.
- **Names.**
  - `APP_NAME=ai-lab` in `.env.example`, plus `.env` through the setup command, which never overwrites other values.
  - `APP_URL=https://ai-lab.ddev.site` in `.env.example`.
  - The server command's default host becomes `ai-lab.ddev.site`.
  - Every `interview.ddev.site` and every "AutoPulse" or "interview" project name in tracked code, scripts, tests, README, `CLAUDE.md`, the guide, `BENCHMARKS.md` prose and set-up lines, the product brief, the backlog, and specs' run, command and manual-test lines.
  - **Not changed:** `bench/results/**`, `.ai/reports/**`, and URLs quoted inside recorded measurements. Those are history, and a short note at the top of `BENCHMARKS.md` says the old URL was the same project.
- **The landing page `/`.**
  - **Front end:** an Inertia page `Home` rendered through the lab's root view and design system, so it does not touch the Blade assets or their fingerprint.
  - **Content:**
    - a one-paragraph "what this is";
    - "Run it": `ddev setup`, the `ollama pull` commands, and the optional cloud keys (marked "coming in SL-51" until that ships);
    - a capability map listing each shipped AI capability with its lab link, guide section and main code file (generated from `LabOverviewService` capabilities plus a guide-section map, not hard-coded twice);
    - server modes (classic, worker, Octane) with links to their guide sections;
    - "Where the numbers are" (`BENCHMARKS.md`);
    - a link to the learning guide.
  - **Accessibility:** it meets the SL-46 bar.
- **README.** Rewritten for learners: the purpose, requirements (DDEV, Ollama and the models, about 20 GB disk), `ddev setup`, a tour (`/`, `/ai`, the guide), how to run tests, benches and evals, and the history note.
- **Parity and fingerprint.**
  - **Parity:** the `/` body changes, so the parity baseline is re-captured once, on the servers that exist at the time, with the reason recorded in `BENCHMARKS.md` "Response parity".
  - **Blade assets:** they must keep their fingerprint.
- **Tests.**
  - The URLs used in tests come from `config('app.url')` or one constant where possible, not literals.
  - A test asserts that no tracked file outside the history folders contains `interview.ddev.site`.
  - A landing page test checks its props and links.

### Out of scope

- Removing the other servers (SL-50) and adding cloud providers (SL-51). The landing page says what is coming.
- Renaming the folder on disk (`~/work/interview`) and the Claude Code memory path. The README tells the owner that the folder can be renamed at any time and how.
- Rewriting historical result files or reports.

## Must NOT change

- **MNC-1**: database contents are identical after the rename (the counts match; a sample of 5 listings has equal JSON from `/api/listings`).
- **MNC-2**: every route other than `/` answers as before on the new URL (parity on the other paths is identical). The Blade asset fingerprint is unchanged.
- **MNC-3**: the Mercure keys, `.env` secrets and `.ddev/.env.web` are kept. The full suite stays green offline.
- **MNC-4**: `.ddev/config.yaml` changes only on the `name:` line.

## Acceptance criteria

- **AC-1**: After the rename, `ddev describe` SHALL show project `ai-lab` at https://ai-lab.ddev.site. The key table counts SHALL equal the pre-rename counts, and `ddev server --check default` SHALL exit 0.
- **AC-2**: `git grep -n "interview.ddev.site"` SHALL find matches only under `bench/results/`, `.ai/reports/` and quoted history lines that a test allows by path. `git grep -in autopulse` SHALL find only the brief's rename note and history.
- **AC-3**: `GET /` SHALL render the landing page with:
  - the what-it-is text;
  - the run steps;
  - a capability map with a lab link, a guide anchor and a code path for each capability;
  - the server modes;
  - links to the guide and `BENCHMARKS.md`.
  Every internal link SHALL resolve: a test checks routes, and that guide anchors and file paths exist.
- **AC-4**: The README SHALL describe the lab for a learner, with the setup steps that work on a fresh clone. A doc test checks for `ddev setup`, the model pulls and the URL.
- **AC-5**: The checks SHALL hold: full suite, Pint, PHPStan, typecheck, build; the Blade fingerprint unchanged; parity identical on all current servers after the one re-baseline for `/`; `bench/run.sh ping --quick` exit 0 on a clean tree.

## Boundaries

- **Always**:
  - export and verify the DB before any DDEV change;
  - never delete the old project's volumes automatically;
  - keep history files as they are.
- **Ask first**: any `.ddev/config.yaml` change beyond `name:`; deleting the old DDEV project.
- **Never**:
  - commit the DB export, `.env` or keys;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `.ddev/config.yaml`, `.ddev/commands/host/server` and `setup`;
  - `routes/web.php`;
  - `resources/views/welcome.blade.php`;
  - `resources/js/ai/` (layout, design system, `LabOverviewService`);
  - `bench/parity.sh`, `bench/asset-fingerprint.sh`;
  - `tests/` (URL literals);
  - README, `CLAUDE.md`, `docs/learning-guide.md`, `BENCHMARKS.md`, `product-brief.md`, `backlog.md`, `.ai/specs/*`.

## Decisions (self-decided, standing approval, within the owner's choices)

1. **Export and import, not snapshot restore.** A gzip SQL dump is portable across project names and easy to verify by row counts. Snapshots are tied to the project name.
2. **Keep the old project's volumes until the owner deletes them.** Deletion cannot be undone, so the script prints the command instead of running it.
3. **The landing page lives on the lab's Vue entry.** It keeps the Blade assets' fingerprint stable and reuses one design system for `/` and `/ai`.
4. **One generated capability map.** The capabilities, their guide anchors and code paths come from one place, the lab service plus a small map, so the landing page, `/ai` and the guide's section 16 cannot disagree.
5. **History stays as recorded.** Past measurements cite the URL they ran against. Rewriting them would make them look like new results.

## Open questions

<!-- Filled by the clarity gate. -->

## Dependencies

- **Requires**: SL-47.
- **Blocks**: SL-50, SL-51 and the remaining AI slices (they use the new name and URL).

---
Next: `/sdd spec-20261110-1014-rename-ai-lab` (approval → build)
