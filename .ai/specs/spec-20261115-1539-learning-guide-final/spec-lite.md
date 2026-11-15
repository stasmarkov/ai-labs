---
id: spec-20261115-1539-learning-guide-final
title: Living learning guide, final pass
type: improvement
size: M
priority: P2
status: done
ticket:
stakeholders: [Stas Markov]
approved_by: Stas Markov (owner request 2026-11-15; standing approval)
approved_at: 2026-11-15T13:42:58Z
created: 2026-11-15
stacks: [laravel]
slice: SL-44
brs: [BR-27]
---

# Living learning guide, final pass

## Summary

`docs/learning-guide.md` (1424 lines, 26 numbered sections) grew one slice at a time, in build order. This pass turns it into a guide a learner can read from top to bottom:
1. **Order:** the sections are put in learning order, in two parts, server and AI.
2. **Gaps:** the missing sections are added.
3. **Stale text:** text about servers that were removed is rewritten.
4. **Links:** every link and anchor in the repo docs is checked by a test.
5. **Summary:** a closing summary is added.

No application code changes.

## Context

- **Order:** the sections run in build order, so streaming an AI answer (§9) sits between worker mode and slow I/O (§19), and the server and AI topics alternate.
- **Gaps:** these shipped slices have no full section of their own:
  - **Photos:** SL-27 (vision extraction) and SL-36…SL-39 (similar photos, text-to-photo search, hybrid similarity, duplicates).
  - **Server and providers:** SL-47 and SL-50 (FrankenPHP with Mercure as the default, then FrankenPHP only), SL-51 (cloud providers) and SL-52 (the settings page).
  - **Lab and project:** SL-48 (in-app guides) and SL-49 (the rename and the homescreen).
  - **Short subsections only:** SL-28, SL-34, SL-35, SL-42 and SL-43 have only short subsections inside §16, the code map.
- **Stale text:**
  - The status line is dated 2026-11-03.
  - "Planned sections" lists slices that have shipped, and SL-14, which was dropped.
  - About 66 lines still describe nginx-fpm, Apache or OpenLiteSpeed as current. The servers were narrowed to the FrankenPHP family in SL-50.
- **Links:** `LearningGuideTest` checks some links per section. Nothing checks every relative link and `#anchor` in the guide, README and BENCHMARKS.
- **Still to come:** SL-15, SL-16 and SL-17 (metrics, structured logs and worker restart, worker-mode developer experience). Each adds its own section when it ships (BR-27), so this pass leaves marked places for them.

## Scope

### In scope

1. **Structure**:
   - **Intro:**
     - how to read the guide;
     - a learning-order table of contents;
     - the prerequisites (DDEV, `ddev setup`, Ollama models);
     - a current status line dated by the commit.
   - **Part I, the server**, in this order:
     - the app and one request;
     - how PHP runs behind a web server (classic, worker, Octane) and why only the FrankenPHP family remains (SL-50 history in one paragraph);
     - switching servers and proving parity;
     - worker mode and state leaks;
     - measuring fairly, then classic vs worker numbers;
     - slow I/O and the health check;
     - offloading downloads;
     - Early Hints and compression;
     - HTTP/3;
     - live updates with Mercure;
     - the reserved places for metrics, logs and developer experience (SL-15 to SL-17), each one line saying what will be covered there.
   - **Part II, AI**, in this order:
     - **Basics:**
       - how an LLM call works end to end;
       - streaming an answer;
       - running models locally;
       - providers, the cloud option and the settings page;
       - local model comparison and limits (SL-28, now a full section).
     - **Structured output:**
       - natural-language search;
       - moderation;
       - vision extraction.
     - **Retrieval:**
       - embeddings and vector search;
       - chunking and hybrid retrieval;
       - answering with citations and guards;
       - measuring RAG;
       - caching answers;
       - Stop and conversations.
     - **Images:** similar photos, text-to-photo search, hybrid similarity, duplicates and quality.
     - **Agents and tools:**
       - tool calling with the SDK;
       - the hand-written tool loop;
       - MCP and confirmations made outside the client;
       - the research agent;
       - the prompt playground.
     - **The lab:**
       - the lab UI;
       - in-app guides;
       - the homescreen;
       - the code map (former §16, kept as a reference chapter).
   - **Closing summary:**
     - the five ideas that recur, each with the sections that show it:
       - measure before claiming;
       - a validation boundary in PHP;
       - marked data and guards;
       - caps on every loop;
       - logs for every call;
     - the headline numbers;
     - what to read next.
2. **Each new or promoted section** follows the existing pattern:
   - what it is;
   - a walkthrough of one request or job;
   - links to the code and the spec;
   - the measured numbers, taken from `BENCHMARKS.md` or the committed `bench/results` files, never invented;
   - official docs to read next.
3. **Accuracy:**
   - Every sentence that describes nginx-fpm, Apache or OpenLiteSpeed as current is rewritten as history or removed.
   - Every number quoted must match its cited source.
   - "Planned sections" is replaced by the reserved places.
4. **Anchors:** the section numbers change, so every inbound link to the guide is updated to the new anchors:
   - the README;
   - `BENCHMARKS.md`;
   - the `docs/` files;
   - the backlog and the specs' text where they link the guide.

   The in-app guides never link the guide (BR-48), so they are not affected.
5. **Tests**:
   - **New `tests/Feature/DocsLinksTest.php`:** for `docs/learning-guide.md`, `README.md`, `BENCHMARKS.md` and `docs/*.md`, every relative link resolves to an existing file, and every `#anchor` (in the same file or across files) matches a heading slug, using the GitHub slug rule. External links are not fetched.
   - **`LearningGuideTest`:** its section-number assertions are updated to the new ids (C-6). Its content checks keep their meaning, and none is deleted or weakened.
   - **New checks:**
     - the table of contents lists every `##` section in order;
     - every shipped AI slice in `backlog.md` (status `shipped`, AI track) is named in at least one section;
     - the guide does not present nginx-fpm, Apache or OpenLiteSpeed as current servers;
     - the closing summary exists.
6. **Backlog:** SL-44 status goes from `proposed` to `specced`, then to shipped.

### Out of scope

- Application code, routes, in-app guides and bench scripts.
- SL-15, SL-16 and SL-17 content.
- Translating the guide.
- New measurements: the guide only quotes what is committed.

## Must NOT change

- **MNC-1:** no file under `app/`, `routes/`, `resources/`, `config/`, `database/` or `bench/` changes.
- **MNC-2:** every existing `LearningGuideTest`, `BenchmarksDocTest` and `GuideContentTest` content check still passes, with only its section numbers updated.
- **MNC-3:** the numbers quoted stay identical to `BENCHMARKS.md` and the result files.
- **MNC-4:** the fingerprint and parity are unchanged.

## Acceptance criteria

- **AC-1:** THE guide SHALL open with a learning-order table of contents listing every `##` section in its order, and SHALL be split into Part I (server) and Part II (AI) in the order given in Scope 1.
- **AC-2:** THE guide SHALL have a full section, in the Scope 2 pattern, for:
  - vision extraction;
  - similar photos and text-to-photo search;
  - hybrid similarity and duplicates;
  - providers and settings;
  - the FrankenPHP-only server history;
  - local model limits;
  - the hand-written tool loop;
  - MCP;
  - the research agent;
  - the prompt playground;
  - the in-app guides and homescreen.
- **AC-3:** WHEN `DocsLinksTest` runs, every relative link and every anchor in the guide, README, `BENCHMARKS.md` and `docs/*.md` SHALL resolve.
- **AC-4:** No sentence in the guide SHALL present nginx-fpm, Apache or OpenLiteSpeed as a current server. "Planned sections" SHALL be replaced by the reserved places for SL-15 to SL-17.
- **AC-5:** Every number quoted in a new or promoted section SHALL match its cited source in `BENCHMARKS.md` or `bench/results`.
- **AC-6:** THE guide SHALL end with a closing summary: the recurring ideas with the section links, the headline numbers, and what to read next.
- **AC-7:** Every shipped AI slice in `backlog.md` SHALL be named in at least one section.

## Boundaries

- **Always:**
  - quote numbers only from committed sources, with the source linked;
  - keep the existing pattern and tone;
  - update the tests' section numbers instead of loosening their checks.
- **Ask first:** none.
- **Never:**
  - change application code;
  - invent numbers;
  - delete an existing test check.

## Technical context

- **Related files:**
  - `docs/learning-guide.md`, `README.md`, `BENCHMARKS.md`, `docs/adr/*` and `backlog.md`;
  - `tests/Feature/LearningGuideTest.php` and `BenchmarksDocTest.php`;
  - the specs under `.ai/specs/*` for the slice links;
  - `bench/results/**`.
- **Already implemented:**
  - the 26 sections;
  - the §16 code map and its file-existence test.

## Decisions

- **D-1:** the guide is renumbered into learning order, and the tests and inbound links are updated. The build order stays visible through the slice id in each section's spec link. (self-decided, standing approval)
- **D-2:** SL-15 to SL-17 get reserved places now. Each fills its own section when it ships. (self-decided, standing approval)
- **D-3:** this is type `improvement` with no code change. A size M fits, because it rewrites and adds about 10 sections. (self-decided, standing approval)

## Open questions

All 32 items the clarity gate raised on 2026-11-15 are answered below in C-1 to C-14 (self-decided, standing approval).

- [x] What counts as AI track, "named" and "shipped" → C-1
- [x] Stale-server detection → C-2
- [x] The section mapping and its granularity; the AC-2 slices; promoted subsections → C-3, C-4
- [x] Testing AC-1 order and AC-2 → C-5
- [x] Headings, numbering, reserved places, status line → C-6
- [x] Sections without numbers, the summary's numbers → C-7
- [x] Slug rule, link parsing, files covered, spec links, plain-text references → C-8, C-9
- [x] Other tests, losing content, number matching → C-10, C-11
- [x] Manual-test base, baselines, terms, red phase, backlog timing → C-12, C-13
- [x] Prerequisites, approval → C-14

## Clarifications

- **C-1 AI slices:**
  - **AI track:** the slices from SL-20 up, apart from the server-track slices SL-47, SL-49 and SL-50, which are named too but are not part of the AI check.
  - **Named:** the slice's `SL-nn` id appears in a section, usually in its spec link.
  - **Which slices count:** only rows whose `Status:` is exactly `shipped`. `dropped`, `approved`, `specced` and `proposed` rows are ignored.
- **C-2 Stale servers:**
  - **The check:** the test fails when a line of the guide outside the history paragraphs names `nginx-fpm`, `apache-fpm`, `Apache` or `OpenLiteSpeed`.
  - **History paragraphs:** marked by the HTML comments `<!-- history -->` … `<!-- /history -->`. The guide renders them as normal text.
  - **Other files:** the README and BENCHMARKS are checked the same way, so their old results tables must sit in history blocks.
- **C-3 The new order, and where today's sections go:**

  | New section | Taken from |
  |---|---|
  | **Part I** | |
  | I-1 | §1 |
  | I-2 | §3, plus an SL-47/SL-50 history paragraph |
  | I-3 | §2 |
  | I-4 | §4 and §7 |
  | I-5 | §5, §6 and §8 |
  | I-6 | §19 |
  | I-7 | §20 |
  | I-8 | §21 |
  | I-9 | §22 |
  | I-10 | §23 |
  | I-11 | reserved |
  | **Part II** | |
  | II-1 | new: an LLM call end to end |
  | II-2 | §9 |
  | II-3 | §10 |
  | II-4 | new: SL-51 and SL-52 |
  | II-5 | SL-28, promoted |
  | II-6 | §24 |
  | II-7 | §26 |
  | II-8 | new: SL-27 vision |
  | II-9 | §11 |
  | II-10 | §12 |
  | II-11 | §14 |
  | II-12 | §15 |
  | II-13 | §17 |
  | II-14 | §18 |
  | II-15 | new: SL-36 and SL-37 |
  | II-16 | new: SL-38 and SL-39 |
  | II-17 | §25 |
  | II-18 | SL-34, promoted |
  | II-19 | SL-35, promoted |
  | II-20 | SL-42, promoted |
  | II-21 | SL-43, promoted |
  | II-22 | §13 |
  | II-23 | new: SL-48 and SL-49 |
  | II-24 | the §16 code map |
  | Closing summary | new |

  **Granularity:** each table row is one `##` section, which settles the Scope 1 and AC-2 wording. The AC-2 items map to rows II-4, II-5, II-8, II-15, II-16, II-18, II-19, II-20, II-21 and II-23, plus the history paragraph in I-2.
- **C-4 Promoted subsections:** the old code-map subsections for SL-28, SL-34, SL-35, SL-42 and SL-43 become a one-line pointer to their section. The rows of the file table stay in the code map.
- **C-5 Testing AC-1 and AC-2:**
  - **Order:** a list in `DocsLinksTest` holds the expected `##` titles in order (the C-3 table, matched as substrings). The test asserts that the guide's `##` headings follow that order and that the two part headings exist.
  - **Full sections:** `LearningGuideTest` gets one test per AC-2 section. It asserts the heading, at least one code link, one spec link, a "Read next" line, and a number or the "not measured" line (C-7).
- **C-6 Format:**
  - **Parts:** `# Part I — The server` and `# Part II — AI`.
  - **Section headings:** `## I-n. Title` and `## II-n. Title`.
  - **Which headings the contents list:** the intro, the contents itself and the closing summary are `##` sections without a number. The contents lists every `##` heading, itself excluded.
  - **Reserved places:** one `##` section, "I-11. Coming next: metrics, logs and developer experience", with one bullet per slice. Each later slice replaces its bullet with its own numbered section.
  - **Status line:** `**Status (YYYY-MM-DD):**`, with the date the guide was last updated. The test checks only that the line exists.
- **C-7 Sections without numbers:** a section with no committed measurement says "Not measured: …" with the reason, instead of numbers.
- **C-8 Links:**
  - **Slugs:** GitHub's rule, implemented in the test:
    - lowercase;
    - strip every character that is not a letter, digit, space or hyphen (this covers `§`, `…`, em dashes and backticks);
    - spaces become hyphens;
    - duplicates get `-1`, `-2` and so on.
  - **What is parsed:** inline links `[t](u)` and images, outside fenced and inline code. Reference-style links and raw HTML are not used in these files and are not parsed. `<a id>` anchors are not allowed.
  - **Not checked:** `http:`, `https:` and `mailto:` links.
  - **Relative links:** resolved from the file's folder. A trailing `/` must be an existing directory. `#L10` anchors on non-markdown files are accepted when the file exists.
  - **Files covered:** `docs/**/*.md` (ADRs included), `README.md`, `BENCHMARKS.md` and `backlog.md`.
  - **Specs:** the `.ai/specs` files are not rewritten and not checked. They are a historical record, and their old anchors may stay.
- **C-9 References:**
  - **Spec links:** every spec folder linked from the guide must be tracked by git. The test checks that each linked path exists, and this slice's folder is committed with the slice.
  - **Plain-text references:** "§n" and "section n" in the covered files are rewritten to the new ids. The test fails on a bare `§\d` or `section \d+` in those files.
- **C-10 Other tests:**
  - **`GuideContentTest`:** `tests/Feature/GuideContentTest.php` tests the in-app guides. It has no guide numbers and is run unchanged.
  - **`BenchmarksDocTest`:** its numbers are BENCHMARKS sections. They are updated only if a BENCHMARKS heading moves into a history block.
  - **The code-map test:** `LearningGuideTest`'s file-existence test finds the code map by title, not by number, so it is renamed safely.
- **C-11 Content and numbers:**
  - **Losing content:** a moved section keeps every paragraph. Wording changes only where C-2 needs history. A check in Task 2 compares the word count of the old §1–26 bodies with their new homes: at least 95 % must remain, and the difference is listed in tasks.md.
  - **Number matching:** a quoted number must equal its source after rounding to the precision the source shows. Task 3.2 records at least one number for each new section, with its source.
- **C-12 Manual test and baselines:**
  - **Diff base:** the manual test diffs against the commit before the slice, `git diff --stat <commit before SL-44>..HEAD -- app routes resources config database bench`.
  - **Fingerprint:** compared with `app-DNob7CFE.css` / `app-PTzu1QaN.js`, as recorded in `BENCHMARKS.md`.
  - **Parity:** `PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity`, where only `captured=` may differ.
  - **Ping:** `bench/run.sh ping --quick` after the commit.
  - **Orchestrator:** the session that commits.
- **C-13 Red phase and backlog:**
  - **Red phase:** the red tests from Task 1 stay local until Task 4 is green. Only one green commit is made.
  - **Backlog:** the change to `specced` is made in the spec commit, and the change to `shipped` in the ship commit, by the orchestrator.
- **C-14 Prerequisites and approval:**
  - **Prerequisites:**
    - DDEV and `ddev setup`;
    - Ollama 0.40 or newer with `qwen3.5:9b` (about 7 GB), `gemma4:12b` (about 7 GB) and `bge-m3` (about 1 GB);
    - optional: `qwen3:0.6b` for the smoke group;
    - the photo encoder sidecar starts with DDEV.
  - **Approval:** the spec is approved by the owner's standing approval when this section is written. Work starts only after `status: approved` is set.

