# Living learning guide, final pass — Tasks

**Spec**: spec-20261115-1539-learning-guide-final | **Size**: M | **Tasks**: 12

## Phase 1: Checks first

- [x] 1. Link and structure tests (tests)
  Covers: AC-3, AC-1, AC-4, AC-6, AC-7
  - [x] 1.1 Add `tests/Feature/DocsLinksTest.php`:
    - relative links and anchors with GitHub slugs across the guide, README, BENCHMARKS and `docs/*.md`;
    - a ToC-vs-headings check;
    - a "no removed server as current" check;
    - a closing-summary check;
    - every shipped AI slice named.
  - [x] 1.2 **Verify**: the new checks fail on today's guide where expected (ToC, summary, coverage, stale servers), and the link check reports any existing broken links.

## Phase 2: Rewrite

- [x] 2. Restructure and renumber (docs)
  Covers: AC-1, AC-4
  - [x] 2.1 Do the following:
    - the intro, the ToC, and Part I and Part II in the Scope 1 order;
    - move the existing sections without losing content;
    - rewrite the removed-server text as history;
    - put in the reserved places;
    - update the `LearningGuideTest` section numbers.
  - [x] 2.2 **Verify**: `LearningGuideTest` and `BenchmarksDocTest` pass.
    - Recorded: both pass, and so does the full suite apart from the AC-6 closing-summary check (Task 4). The old section bodies keep 99.7 % of their words in the new homes (31 631 of 31 739); the 108 words that differ are rewritten server wording, renamed section references and moved `<!-- history -->` markers.

- [x] 3. New and promoted sections (docs)
  Covers: AC-2, AC-5, AC-7
  - [x] 3.1 Write the sections listed in AC-2, each in the Scope 2 pattern, with numbers from `BENCHMARKS.md` or `bench/results`.
  - [x] 3.2 **Verify**: the coverage check passes, and every number spot-checked against its source (recorded here).
    - Recorded: the coverage check passes and so do the AC-2 and AC-5 tests in `LearningGuideTest`. Spot checks: II-8 gemma4:12b p50 3697 ms and qwen3.5:9b 41/50 body agreement (`BENCHMARKS.md`, photo vision); II-15 0.785 / 0.677 precision at cutoff 0.75 and text search P@8 0.506, MRR 0.569 (similar photos and text search sections); II-16 hybrid w 0.9 at 0.693 against 0.677 and duplicate cutoff 0.92 with F1 0.894 (hybrid and duplicates sections); II-20 15 runs, 3.9 model calls, 7.8 steps, 20 s (`bench/results/research/2026-11-14/stats.json`); II-21 9 runs, 7 two-model runs at 10.3 s (`bench/results/playground/2026-11-14/stats.json`). II-18 and II-19 say "Not measured" with the reason.

- [x] 4. Closing summary and inbound links (docs)
  Covers: AC-3, AC-6
  - [x] 4.1 Write the closing summary, and update the guide links in README, BENCHMARKS, `docs/` and the backlog to the new anchors.
  - [x] 4.2 **Verify**: `DocsLinksTest` is fully green.

- [x] 5. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 5.1 Orchestrator:
    - [x] `git diff --stat` shows docs and tests only;
    - [x] the full suite, Pint and PHPStan;
    - [x] fingerprint and parity (see 10.1);
    - [x] commit, ping and push: not done yet; the orchestrator does them in the ship commit.
  - [x] 5.2 **Verify**: outputs recorded here.
    - Full suite before the review fix pass: 2915 passed, 3 skipped, 0 failed (30668 assertions); after it, see 12.2.
    - Pint: passed. PHPStan: no errors.
    - No changes under `app/`, `routes/`, `resources/`, `config/`, `database/` or `bench/`.
    - Fingerprint: `app-DNob7CFE.css` and `app-PTzu1QaN.js` still present in `public/build/assets`; `bench/parity` has no diff.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | ToC and two parts | `tests/Feature/DocsLinksTest.php` | test | 1, 2 |
| AC-2 | full sections for the gaps | `tests/Feature/LearningGuideTest.php` | test | 3 |
| AC-3 | links and anchors resolve | `tests/Feature/DocsLinksTest.php` | test | 1, 4 |
| AC-4 | no removed server as current | `tests/Feature/DocsLinksTest.php` | test | 1, 2, 8, 12 |
| AC-5 | numbers match sources | `tests/Feature/LearningGuideTest.php`, `tests/Feature/DocsLinksTest.php`, `manual-test.md#step-2` | test, manual | 3, 11 |
| AC-6 | closing summary | `tests/Feature/DocsLinksTest.php` | test | 4, 6, 11, 12 |
| AC-7 | every shipped AI slice named | `tests/Feature/DocsLinksTest.php` | test | 1, 3, 12 |
| MNC-1 | no app code changed | `manual-test.md#step-3` | manual | 5 |
| MNC-2 | existing guide checks still pass | `tests/Feature/LearningGuideTest.php` | test | 2 |
| MNC-3 | numbers unchanged | `manual-test.md#step-2` | manual | 3 |
| MNC-4 | fingerprint and parity | `manual-test.md#step-3` | manual | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green

## Phase: Gaps (converge round 1)

- [x] 6. Headline numbers in the closing summary (docs)
  Covers: AC-6
  - Gap: the `## Closing summary` section of `docs/learning-guide.md` has the five ideas, the section links and "Read next", but it quotes no number at all. AC-6 and Scope 1 require "the headline numbers". The AC-6 test in `tests/Feature/DocsLinksTest.php` checks only the idea needles, five or more section links and the status line, so it passes without numbers.
  - Approach: add a short "Headline numbers" list to the summary. Use 4 to 6 numbers that are already quoted in the guide, for example classic vs worker search p95 (I-5), the AI-stream health-check p95 (I-6), RAG recall (II-12), the II-15/II-16 photo precision and the II-5 search p95 under 4 streams. Link each number to its section and to its `BENCHMARKS.md` source. Extend the AC-6 test so the summary must hold a numbers marker and at least 3 numeric values, each of which also appears in `BENCHMARKS.md` or the cited result file.
  - [x] 6.1 **Verify**: `ddev artisan test --compact tests/Feature/DocsLinksTest.php` is green, and the extended AC-6 test fails if the numbers list is removed.

- [x] 7. Rewrite leftover old section numbers (docs, tests)
  Covers: AC-3
  - Gap: two plain-text references to the old numbering survived the renumbering.
    - `docs/learning-guide.md:719` (II-6) says "Section [II-2](…) and 14 stream free text". Old §14 is now II-11.
    - `docs/learning-guide.md:759` (II-7) says "Section [II-6](…) and 25 turn words into filters". Old §25 is now II-17.
  - C-9 requires every "§n" or "section n" reference to be rewritten to the new ids. The bare-reference test removes link targets first and only matches `sections? \d+`, so "Section [II-2] and 14" slips through.
  - Approach: replace "and 14" with a link to II-11 and "and 25" with a link to II-17. In `DocsLinksTest`, also fail on a bare number that is joined to a section link by "and", "or", "," or "to", for example `\]\(#[^)]*\)\s*(,|and|or|to)\s+\d+\b`.
  - [x] 7.1 **Verify**: `grep -nE '\]\(#[^)]*\)\s*(,|and|or|to)\s+[0-9]+\b' docs/learning-guide.md README.md BENCHMARKS.md docs/*.md` prints nothing, and `DocsLinksTest` is green. The new pattern fails when either old line is restored.

- [x] 8. Stop empty history markers from exempting current-tense server text (docs, tests)
  Covers: AC-4
  - Gap: the AC-4 test in `tests/Feature/DocsLinksTest.php` skips any line that contains `<!-- history -->`, even when the closing `<!-- /history -->` follows straight after it with nothing in between. The guide uses that empty pair as a bypass on lines that still describe a removed server as current, in the present tense:
    - `docs/learning-guide.md:314` (I-6 "One slow request, step by step"): "On **nginx-fpm** nginx passes the request to the FPM pool…";
    - `docs/learning-guide.md:378` (I-7 "One offload per server"): "**openlitespeed**: the LSAPI PHP process returns `X-LiteSpeed-Location` and is free at once…".
  - The same empty pair also sits on lines 124, 139, 157, 231, 604, 606 and 1058.
  - Approach: rewrite lines 314 and 378 in the past tense as history ("On nginx-fpm, before it was removed, …"). Wrap every removed-server sentence in a real `<!-- history -->` … `<!-- /history -->` span that encloses the text. Keep the `*History: …*` label lines first in their sections, because `LearningGuideTest` checks that. In the AC-4 test, do not exempt a line whose only history span is empty, meaning `<!-- history -->` is directly followed by `<!-- /history -->`.
  - [x] 8.1 **Verify**: `grep -n '<!-- history --><!-- /history -->' docs/learning-guide.md README.md BENCHMARKS.md` prints nothing. `DocsLinksTest` and `LearningGuideTest` are green, and the AC-4 test fails when an empty marker pair is appended to a line that names `nginx-fpm`.

- [x] 9. Walkthrough for the promoted local-models section (docs, tests)
  Covers: AC-2
  - Gap: II-5 "Local models and their limits" is a section AC-2 requires in the Scope 2 pattern, but it has no walkthrough of one request or job. It has no "step by step" heading and no numbered list, only a bullet list of topics plus "Run it". Every other AC-2 section except the one-paragraph I-2 history has a numbered "… step by step" walkthrough. The AC-2 dataset test in `tests/Feature/LearningGuideTest.php` checks code and spec links, "Read next" and "Numbers"/"Not measured", but not the walkthrough, so the gap passes.
  - Approach: add a "One bench run, step by step (`ddev artisan llm:bench`)" numbered walkthrough to II-5. Use what BENCHMARKS "Local models and limits (2026-11-13)" already describes: unload, cold request, 5 warm requests with a marker line, the context check through `/api/ps`, then failover. Add an assertion to the AC-2 test that each section, except I-2 which Scope 1 makes one paragraph, has a "step by step" line followed by a numbered list.
  - [x] 9.1 **Verify**: `ddev artisan test --compact tests/Feature/LearningGuideTest.php` is green, and the new walkthrough assertion fails on the pre-fix II-5.

- [x] 10. Run the fingerprint, parity and diff checks from the manual test (checks)
  Covers: MNC-1, MNC-4
  - Gap: task 5.2 records that `bench/asset-fingerprint.sh` and the parity capture were not run. MNC-4 was inferred from the build files still being present in `public/build/assets` and from the untouched directories. C-12 and `manual-test.md#step-3` require the actual commands:
    - `git diff --stat origin/master -- app routes resources config database bench`;
    - `bench/asset-fingerprint.sh`;
    - `PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity`, where only `captured=` may differ.
  - Approach: run the three commands on the final tree and record their output under 10.1. If the parity capture rewrites files in `bench/parity`, restore them so MNC-1 still holds.
  - [x] 10.1 **Verify**: the recorded outputs show the following:
    - the diff command prints nothing;
    - the fingerprint script reports `app-DNob7CFE.css` / `app-PTzu1QaN.js` unchanged;
    - `git diff -U0 bench/parity` shows only `captured=` lines, or nothing.
    - Recorded outputs (2026-11-15):
      - `git diff --stat origin/master -- app routes resources config database bench` printed nothing.
      - `bench/asset-fingerprint.sh` printed `resources/css/app.css assets/app-DNob7CFE.css 176bae5b...f1d444` and `resources/js/app.js assets/app-PTzu1QaN.js 7fbc3860...735256`, unchanged.
      - `PARITY_INSECURE=1 bench/parity.sh capture` wrote `bench/parity/frankenphp.txt`; `git diff -U0 bench/parity` showed only the `captured=` line (2026-11-13T00:49:49Z to 2026-11-15T14:33:32Z). The file was restored with `git checkout`, so `git status --short bench` is empty.

## Phase: Gaps (converge round 2)

- [x] 11. Number checks for II-5 and the closing summary (tests)
  Covers: AC-5, MNC-3
  - Gap: the AC-5 test in `tests/Feature/LearningGuideTest.php` (`AC-5 guide numbers in the new and promoted sections match BENCHMARKS and the result files`) checks II-8, II-15, II-16, II-20 and II-21 only. II-5 "Local models and their limits" is a promoted section that quotes 15 numbers, and none of them is tested: 0.2 s, 6.6 s and 31 s first byte, 52 chunks per second, 5-134 ms, 8-32 ms and 1013-1063 ms failover, search p95 64 to 2724 ms and 63 to 2793 ms, RPS 950 to 23, and 132 to 237 ms. The task 3.2 spot-check record does not list II-5 either. The closing summary is also a new section. Its AC-6 check in `tests/Feature/DocsLinksTest.php` only asserts that each backticked number appears somewhere in `BENCHMARKS.md`, so `64` passes trivially. The unbackticked `4003 ms`, `21 of 24`, `0.75` and `4 describe streams` are not checked at all. Today every one of these numbers matches `BENCHMARKS.md`, so this gap is a missing guard, not a wrong number.
  - Approach: add an `'II-5. '` entry to `$fromBenchmarks` in the AC-5 test. Its needles are `0.2 s`, `6.6 s`, `31 s`, `5-134 ms`, `8-32 ms`, `1013-1063 ms`, `64 to 2724 ms`, `63 to 2793 ms`, `about 950 to 23` and `132 to 237 ms`. Check each one against the `## Local models and limits (2026-11-13)` section of `BENCHMARKS.md`, not the whole file, and allow the source's own wording, for example `5-134 ms` from the failover table and `64 to 2724 ms` from the notes. In the AC-6 test, match each headline number against the BENCHMARKS section its bullet links to (the `#anchor` on the same line), not the whole file. Record the II-5 spot checks under 11.1.
  - [x] 11.1 **Verify**: `ddev artisan test --compact tests/Feature/LearningGuideTest.php tests/Feature/DocsLinksTest.php` is green. Changing `2724` to `2725` in II-5 fails the AC-5 test, and changing `276` to `277` in the closing summary fails the AC-6 test. `git diff --stat -- app routes resources config database bench` prints nothing.
    - Recorded (review fix pass, 2026-11-15): done in `tests/Feature/DocsLinksTest.php` instead of `$fromBenchmarks`, so the II-5 check covers every number the section quotes, not a needle list. `AC-5 every number in the local models section is in its BENCHMARKS source` takes every decimal and every number of 3 or more digits in II-5 (dates, slice ids and model tags such as `qwen3.5:9b` skipped) and requires each in the `## Local models and limits (2026-11-13)` section: 1.5, 0.2, 6.6, 134, 1013, 1063, 2724, 2793, 950, 132 and 237 all match. The AC-6 test now takes every such number of each headline bullet, backticked or not, and requires it both in the guide section and in the `BENCHMARKS.md` section that bullet links. Changing `2724` to `2725` in II-5 failed AC-5, and `2369` to `2368` in the summary failed AC-6 (checked, then restored).

## Phase: Review fix pass

- [x] 12. Fix the confirmed review findings (docs, tests)
  Covers: AC-4, AC-5, AC-6, AC-7
  - [x] 12.1 Do the following:
    - AC-4 test: only the text between `<!-- history -->` and `<!-- /history -->` is exempt, tracked across lines (`docsLinksOutsideHistory`); text before an opening marker and after a closing one is checked, and an unclosed span fails. The one other exemption is the italic `*History: …*` label at the start of a line, which `LearningGuideTest` (MNC-2) requires as the first line of I-1 to I-5 and which is itself a history marker. This replaces the Task 8 rule. The lines it then flagged (I-1, I-2, I-5, I-6, I-7, I-8, I-9, I-10, II-1, II-3, II-13, II-14) were rewritten in the past tense inside real spans.
    - Stale text the regex does not catch: the I-1 walkthrough now follows the default server, "all six servers" became three in I-10, the ZTS Dockerfile note and the "queued Mercure or Reverb variant" (SL-14 and SL-21 were dropped) were rewritten, and the six-server notes in II-2, II-11 and II-14 say three remain.
    - Prerequisites: Ollama 0.40.13 (embeddings and docs index, 2026-11-02) and 0.40.16 (local models, 2026-11-13), as `BENCHMARKS.md` records.
    - II-11: the CORS paragraph no longer claims a cross-site page cannot call `/api/docs/ask`. Laravel's default CORS answers `api/*` preflights for any origin and the route has no `RefuseCrossSiteRequests`; the rate limit bounds it, and `LimitCloudAi` refuses cross-site requests on a cloud provider.
    - Closing summary: the noisy 365/1461 RPS rows and the removed nginx-fpm 4003 ms row were replaced by the worker-memory figures (11.3 MB, 6.3 MB, 501 sessions, I-5) and FrankenPHP autoscaled 276 ms against fixed 14 threads 2369 ms (I-6). SL-44 is named in a closing line, and a test with SL-44 set to `shipped` in a copy of the backlog proves AC-7 holds after the ship commit.
    - `BENCHMARKS.md`: the history span at the end now wraps only the removed-server snapshots and prose; the current snapshot list, the asset fingerprint and the PHP settings table are outside it, and the OpenLiteSpeed, `.htaccess`, `X-Forwarded-Proto` and charset notes are past tense.
  - [x] 12.2 **Verify**: full suite, Pint, PHPStan, `git status --short -- app routes resources config database bench` empty.
    - Recorded (2026-11-15): full suite 2919 passed, 3 skipped, 0 failed (30713 assertions); Pint passed; PHPStan no errors; the `git status` command printed nothing.

- Clean-tree bench: after commit ece3d20, `bench/run.sh ping --quick` exited 0 on frankenphp.
