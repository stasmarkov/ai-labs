# Five interactive guide formats, trialled on the photos guide — Tasks

**Spec**: spec-20261117-2243-guide-format-variants | **Size**: L | **Tasks**: 8

## Phase 1: Engine and compare page

- [ ] 1. Format loading, routes and the compare page (laravel)
  Covers: AC-1, AC-3, AC-4, AC-11, MNC-1, MNC-2, NFR-3
  - [ ] 1.1 Failing tests:
    - `/ai/guides/photos/formats` answers 200 with six entries and LO-1 to LO-5;
    - an unknown key or format answers 404;
    - a broken format file (missing field, unknown LO id, unresolvable snippet) raises `InvalidGuideException` naming file and field;
    - `/ai/guides` shows the trial card and keeps every existing entry in order;
    - `/ai/guides/photos` props are unchanged.
  - [ ] 1.2 `GuideFormatRepository` (per-format schema, `SnippetResolver`), `GuideFormatPageService`, two invokable controllers, routes next to `ai.guides.show`, `Lab/GuideFormats.vue`, the trial card in `Guides.vue`.
  - [ ] 1.3 **Verify**: `tests/Feature/GuideFormatPagesTest.php`, `GuidePageTest`, `GuideContentTest` green.

## Phase 2: The five formats (content + page each)

- [ ] 2. Build-along format (laravel)
  Covers: AC-2, AC-5, AC-6, NFR-1
  - [ ] 2.1 Failing tests: the page answers 200; every LO is covered by a step; every step has goal, code, command and expected result.
  - [ ] 2.2 `resources/guides/formats/photos/build-along.php`, `Lab/GuideFormat/BuildAlong.vue` with `localStorage` progress and reset.
  - [ ] 2.3 **Verify**: tests green; manual-test step 2 (progress survives reload).

- [ ] 3. Follow one photo (trace) format (laravel)
  Covers: AC-2, AC-5, AC-8, NFR-1
  - [ ] 3.1 Failing tests: 200; the stages in pipeline order; every stage has a panel with sample data and code; LO coverage.
  - [ ] 3.2 `trace.php`, `Lab/GuideFormat/Trace.vue` (SVG/CSS flow, Play/Pause, keyboard).
  - [ ] 3.3 **Verify**: tests green; manual-test step 2.

- [ ] 4. Predict and check (quiz) format (laravel)
  Covers: AC-2, AC-5, AC-7, NFR-1
  - [ ] 4.1 Failing tests: 200; 8 to 10 questions, each with exactly one correct option, an explanation and code; LO coverage.
  - [ ] 4.2 `quiz.php`, `Lab/GuideFormat/Quiz.vue` (locked answers, score, `aria-live` feedback).
  - [ ] 4.3 **Verify**: tests green; manual-test step 2.

- [ ] 5. Break and fix (debug) format (laravel)
  Covers: AC-2, AC-5, AC-9, NFR-1
  - [ ] 5.1 Failing tests: 200; 4 or 5 scenarios, each with symptom, at least two clues, cause options with one correct, fix and preventing test reference; LO coverage.
  - [ ] 5.2 `debug.php`, `Lab/GuideFormat/Debug.vue`.
  - [ ] 5.3 **Verify**: tests green; manual-test step 2.

- [ ] 6. Code tour format (laravel)
  Covers: AC-2, AC-5, AC-10, NFR-1
  - [ ] 6.1 Failing tests: 200; every stop resolves to real source lines; LO coverage; no stop shows a repository path.
  - [ ] 6.2 `tour.php`, `Lab/GuideFormat/Tour.vue` (code panel via `LabCode`, annotations, previous/next, map).
  - [ ] 6.3 **Verify**: tests green; manual-test step 2.

## Phase 3: Checks, docs and the pick

- [ ] 7. UI rules, audit, docs and gates (laravel)
  Covers: MNC-3, MNC-4, MNC-5, NFR-2
  - [ ] 7.1 Extend `NoReferencesInUiTest` (and `labUiPaths`) with the compare page and the five format pages; add them to the ui-audit page list.
  - [ ] 7.2 Short note in `docs/learning-guide.md` (in-app guides section) on the trial and how the formats are built; `DocsLinksTest` green.
  - [ ] 7.3 **Verify**: full suite, Pint, PHPStan, `npm run typecheck`, `npm run build`, `node bench/ui-audit.mjs --only guides,guides/photos/formats,guides/photos/formats/build-along,guides/photos/formats/trace,guides/photos/formats/quiz,guides/photos/formats/debug,guides/photos/formats/tour` exit 0, `bench/asset-fingerprint.sh` unchanged, parity capture on the three modes differs only in `captured=`.

- [ ] 8. Gate: the owner tries the six formats and records the pick
  Covers: none (decision). The pick, with the rubric reasons, goes into `decisions.md`; the follow-up rollout slice is added to `backlog.md`.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | compare page, six entries, LOs | `tests/Feature/GuideFormatPagesTest.php::compare page lists the baseline and five formats with the outcomes` | test | 1 |
| AC-2 | five format pages answer 200 | `tests/Feature/GuideFormatPagesTest.php::each format page answers 200` | test | 2–6 |
| AC-3 | 404 for unknown key/format | `tests/Feature/GuideFormatPagesTest.php::unknown key or format is 404` | test | 1 |
| AC-4 | format files validated | `tests/Feature/GuideFormatContentTest.php::a broken format file names the file and field` | test | 1 |
| AC-5 | every format covers LO-1..LO-5 | `tests/Feature/GuideFormatContentTest.php::every format covers every learning outcome` | test | 2–6 |
| AC-6 | build-along progress persists | `manual-test.md#step-2` | manual | 2 |
| AC-7 | quiz reveal and score | `manual-test.md#step-2` | manual | 4 |
| AC-8 | trace Play/Pause and stages | `manual-test.md#step-2` | manual | 3 |
| AC-9 | debug scenario flow | `manual-test.md#step-2` | manual | 5 |
| AC-10 | tour stops and navigation | `tests/Feature/GuideFormatContentTest.php::every tour stop resolves to source`, `manual-test.md#step-2` | test + manual | 6 |
| AC-11 | trial card on /ai/guides | `tests/Feature/GuideFormatPagesTest.php::guides index shows the trial card` | test | 1 |
| MNC-1 | existing guide pages unchanged | `tests/Feature/GuidePageTest.php` | test | 1 |
| MNC-2 | guides index keeps entries and order | `tests/Feature/GuideFormatPagesTest.php::guides index keeps every guide in order` | test | 1 |
| MNC-3 | no slice ids, .md or paths in UI | `tests/Feature/NoReferencesInUiTest.php` | test | 7 |
| MNC-4 | fingerprint and parity unchanged | `manual-test.md#step-4` | manual | 7 |
| MNC-5 | suite offline | `manual-test.md#step-4` | manual | 7 |
| NFR-1 | keyboard and aria-live | `manual-test.md#step-3` | manual | 2–6 |
| NFR-2 | layout at 1440/1024 | `manual-test.md#step-4` | manual | 7 |
| NFR-3 | offline, no writes | `tests/Feature/GuideFormatPagesTest.php::format pages write nothing and call no model` | test | 1 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck` and `ddev npm run build`
- `node bench/ui-audit.mjs --only <the new pages>`

## Done when

- [ ] Tasks 1–7 checked (ship); task 8 checked when the owner records the pick (spec then `done`)
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
