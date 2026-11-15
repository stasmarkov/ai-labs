# Ship report: spec-20261115-1539-learning-guide-final (SL-44 learning guide final pass)

## Summary

This change is docs only. It restructures `docs/learning-guide.md` into Part I (server, I-1..I-11) and Part II (AI, II-1..II-24), and closes the guide with a summary. It adds the promoted sections, rewrites the inbound links in README, BENCHMARKS, `docs/` and the backlog, and adds `tests/Feature/DocsLinksTest.php`. All gates are green and every link resolves. The slice is not ready to ship yet:

- AC-4 fails on one present-tense nginx-fpm sentence (`docs/learning-guide.md:375`).
- The AC-4 test has a bypass that hides that sentence.
- The AC-7 test will go red in the ship commit, because SL-44 is never named in the guide.
- Converge task 11 (number guards) is still not built.

## Stack & repos

| Repo | Role | Stack | Tracked |
|------|------|-------|---------|
| `.` | backend | laravel | yes |

- No repo warnings. No untracked or WIP repos.
- All changes are uncommitted on `master`. `tests/Feature/DocsLinksTest.php` is untracked and must be added in the ship commit.
- Stack overlay: discovery plugin-list (plugin); added: none; ignored: 1 (`laravel` from `plugin:sdd-kit-laravel@sdd-kit`, first-party key; update sdd-kit and sdd-kit-laravel to the same version); rejected: 0; untrusted: none. Discovery reported an error: `trustStore unreadable`.

## Spec

- id: `spec-20261115-1539-learning-guide-final`
- folder: `.ai/specs/spec-20261115-1539-learning-guide-final`

## Investigation highlights

- Key files: the investigation step recorded none. From the build: `docs/learning-guide.md`, `BENCHMARKS.md`, `README.md`, `backlog.md`, `tests/Feature/DocsLinksTest.php` (new), `tests/Feature/LearningGuideTest.php`, `tests/Feature/DocsDefaultServerTest.php`, `tests/Feature/ReadmeSwitchingServersTest.php`.
- Risks: none recorded.
- Already shipped: none recorded. The guide already existed and this slice rewrites it. The RAG docs corpus still finds its evidence strings and identifiers in the guide (`bench/rag-eval/questions.v1.jsonl`, `bench/docs-queries.txt`).

## Tasks completed

- Build: 10/11.
- Done: tasks 1 to 5 (original plan) and 6 to 10 (converge round 1).
- Not done: task 11, "Number checks for II-5 and the closing summary". It is still unchecked in `tasks.md:132`. The build summary listed no incomplete tasks, but `tasks.md` shows task 11 open, and the "All tasks checked", "Traceability" and "Gates green" checkboxes are also unchecked.

## Converge

- Rounds run: 2.
- Gap tasks appended:
  - Round 1: tasks 6 (headline numbers in the summary), 7 (old section numbers), 8 (empty history markers), 9 (II-5 walkthrough), 10 (fingerprint, parity and diff checks). All five are built.
  - Round 2: task 11 (AC-5/AC-6 number guards for II-5 and the closing summary).
- Gaps left unbuilt: **11. Number checks for II-5 and the closing summary.** The numbers were checked by hand and match `BENCHMARKS.md`. What is missing is a test guard; no number is wrong.

## Quality gates (repo `.`)

| Gate | Status | Details |
|------|--------|---------|
| Pint | pass | 1005 files pass (via ddev exec) |
| PHPStan | pass | No errors (via ddev exec) |
| Pest/PHPUnit | pass | 2915 passed, 3 skipped (30692 assertions) (via ddev exec) |
| Debug scan | pass | No `dd(`/`dump(`/`ray(` in app/ routes/ database/ |

## Visual tests

Not run. This is a docs-only change and no app route serves these files.

## Acceptance

**Resolved: no.** The scenario is a reader working through the guide: contents, anchors, code links and spec links.

| Check | Result |
|-------|--------|
| Intro, status line (`**Status (2026-11-15):**`), prerequisites, contents | ok |
| Every contents anchor resolves; 1030 relative links checked | ok, no broken file or anchor |
| Spec links into `.ai/specs` | ok |
| AC-1 structure, AC-2 sections, AC-3 links | ok |
| **AC-4: no removed server presented as current** | **broken** |
| AC-5 numbers, AC-6 closing summary, AC-7 slice coverage | ok (AC-5 for II-5 checked by hand only, task 11) |
| MNC-1 (no app/routes/resources/config/database/bench change), MNC-2, MNC-3, MNC-4 (fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js` unchanged, no parity diff) | ok |
| Full suite | ok |

Broken hop (AC-4): `docs/learning-guide.md:375` (I-7 "One offload per server") still reads, in the present tense and outside any history span:

> **nginx-fpm**: PHP-FPM returns the empty response with `X-Accel-Redirect` to nginx, and the FPM worker is free as soon as that response is written.

- The `<!-- history -->` marker at the end of that line encloses only the next bullet (apache-fpm).
- The test passes anyway, because `DocsLinksTest.php:319-333` exempts any line that contains an opening marker.
- Lines 123 and 306 use smaller present-tense wording about FPM.

E2E regression test: none written. The workflow produced no E2E test (E2E: null) because there is no browser journey for a docs-only change. No AC ids are traced to an E2E test. It neither passed nor failed; it was not applicable.

## Confirmed review findings

### Major

- `tests/Feature/DocsLinksTest.php:403`: the AC-7 test will fail as soon as SL-44 is marked `shipped` in the ship commit, because SL-44 is never named in the guide. Fix: name SL-44 in a numbered section or in the Closing summary, or add 44 to the excluded docs-track slices.
- `tests/Feature/DocsLinksTest.php:321` and `docs/learning-guide.md:375` (this was reported twice, once for the test and once for the guide line): the AC-4 check exempts a whole line once `<!-- history -->` appears anywhere on it. The regex is also case-sensitive, so lowercase `openlitespeed` is never matched. The same trailing-marker pattern appears on lines 239, 246, 314, 403, 426, 430, 457 and 469. Fix: strip only `<!-- history -->.*?(<!-- /history -->|$)` before matching, add `/i`, and rewrite line 375 in the past tense.

### Minor

- `docs/learning-guide.md:1723`: the summary says each number "is quoted in its section", but 365 and 1461 RPS appear nowhere in I-5. They also come from rows that `BENCHMARKS.md` marks as noisy. Fix: quote them in I-5 with the noise flag, or pick stable rows.
- `docs/learning-guide.md:1724`: 276 vs 4003 ms compares FrankenPHP with the removed nginx-fpm static-8 row and does not say so. Fix: name the source as history, or compare with the FrankenPHP 14-thread row (2369 ms).
- `docs/learning-guide.md:515`: says "all six servers" when three remain. Lines 123 and 333 describe the FPM servers and the queued Mercure/Reverb variant (both dropped) as current. Fix: rewrite in the past tense or remove.
- `docs/learning-guide.md:20`: says all numbers were measured on Ollama 0.40.13, but the II-5 run used 0.40.16 (`BENCHMARKS.md:1350`). Fix: name both versions.
- `BENCHMARKS.md:1461`: the history span opened before `## Response parity` (line 1438) runs to the end of the file. It hides the current fingerprint and FrankenPHP parity, and present-tense prose about removed servers. Fix: wrap only the old tables in history.
- `docs/learning-guide.md:973`: II-11 claims a cross-site page cannot call `/api/docs/ask` because of the CORS preflight. In fact `api/*` allows any origin, and `RefuseCrossSiteRequests` runs only on cloud providers. The guide contradicts its own line 772. Fix: correct the paragraph. A separate follow-up should add `RefuseCrossSiteRequests` to `routes/catalog.php:64-66,72-74`.
- `tests/Feature/DocsLinksTest.php:386`: the headline-number check does a substring match against the whole of `BENCHMARKS.md`, so it is effectively vacuous. Fix: match with word boundaries inside the linked anchor section. Also add `@return list<string>` to `docsLinksHeadlineNumbers()` and move it to the helpers at the top of the file.
- `.ai/specs/spec-20261115-1539-learning-guide-final/tasks.md:58`: task 5.1 is marked as "commit, ping and push" done, but nothing is committed. The traceability table lists AC-5 as manual, but `LearningGuideTest.php:741` automates it. Fix: correct the record.

## Documentation updated

- `docs/learning-guide.md` (restructure, new and promoted sections, closing summary)
- `README.md`, `BENCHMARKS.md`, and the guide links in `docs/*.md` and the backlog (new anchors)

The docs step itself recorded nothing else (Docs: null).

## Manual test plan

- Written to `.ai/specs/spec-20261115-1539-learning-guide-final/manual-test.md`.
- How to run: with `ddev start` running, follow the 7 steps in order: preview the guide, compare numbers, run the diff/fingerprint/parity/suite commands, check AC-4, AC-2 and AC-7, then do the `/ai` spot check. Step 4 (AC-4, line 375) is expected to fail until the fix lands.

## Recommendation

**FIX-FIRST**

- Acceptance is unresolved: AC-4 fails at `docs/learning-guide.md:375`.
- One converge gap is unbuilt (task 11).
- One major finding will turn the suite red in the ship commit (AC-7 / SL-44).

The gates themselves are green.

Next: rewrite `docs/learning-guide.md:375` (I-7 nginx-fpm bullet) in the past tense or move it inside a history span, then change the AC-4 check in `tests/Feature/DocsLinksTest.php:319-333` to exempt only text between markers (case-insensitive).
