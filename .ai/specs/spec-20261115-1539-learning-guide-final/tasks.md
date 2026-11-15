# Living learning guide, final pass — Tasks

**Spec**: spec-20261115-1539-learning-guide-final | **Size**: M | **Tasks**: 5

## Phase 1: Checks first

- [ ] 1. Link and structure tests (tests)
  Covers: AC-3, AC-1, AC-4, AC-6, AC-7
  - [ ] 1.1 Add `tests/Feature/DocsLinksTest.php`:
    - relative links and anchors with GitHub slugs across the guide, README, BENCHMARKS and `docs/*.md`;
    - a ToC-vs-headings check;
    - a "no removed server as current" check;
    - a closing-summary check;
    - every shipped AI slice named.
  - [ ] 1.2 **Verify**: the new checks fail on today's guide where expected (ToC, summary, coverage, stale servers), and the link check reports any existing broken links.

## Phase 2: Rewrite

- [ ] 2. Restructure and renumber (docs)
  Covers: AC-1, AC-4
  - [ ] 2.1 Do the following:
    - the intro, the ToC, and Part I and Part II in the Scope 1 order;
    - move the existing sections without losing content;
    - rewrite the removed-server text as history;
    - put in the reserved places;
    - update the `LearningGuideTest` section numbers.
  - [ ] 2.2 **Verify**: `LearningGuideTest` and `BenchmarksDocTest` pass.

- [ ] 3. New and promoted sections (docs)
  Covers: AC-2, AC-5, AC-7
  - [ ] 3.1 Write the sections listed in AC-2, each in the Scope 2 pattern, with numbers from `BENCHMARKS.md` or `bench/results`.
  - [ ] 3.2 **Verify**: the coverage check passes, and every number spot-checked against its source (recorded here).

- [ ] 4. Closing summary and inbound links (docs)
  Covers: AC-3, AC-6
  - [ ] 4.1 Write the closing summary, and update the guide links in README, BENCHMARKS, `docs/` and the backlog to the new anchors.
  - [ ] 4.2 **Verify**: `DocsLinksTest` is fully green.

- [ ] 5. Run and checks (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 5.1 Orchestrator:
    - `git diff --stat` shows docs and tests only;
    - the full suite, Pint and PHPStan;
    - fingerprint and parity;
    - commit, ping and push.
  - [ ] 5.2 **Verify**: outputs recorded here.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | ToC and two parts | `tests/Feature/DocsLinksTest.php` | test | 1, 2 |
| AC-2 | full sections for the gaps | `tests/Feature/LearningGuideTest.php` | test | 3 |
| AC-3 | links and anchors resolve | `tests/Feature/DocsLinksTest.php` | test | 1, 4 |
| AC-4 | no removed server as current | `tests/Feature/DocsLinksTest.php` | test | 1, 2 |
| AC-5 | numbers match sources | `manual-test.md#step-2` | manual | 3 |
| AC-6 | closing summary | `tests/Feature/DocsLinksTest.php` | test | 4 |
| AC-7 | every shipped AI slice named | `tests/Feature/DocsLinksTest.php` | test | 1, 3 |
| MNC-1 | no app code changed | `manual-test.md#step-3` | manual | 5 |
| MNC-2 | existing guide checks still pass | `tests/Feature/LearningGuideTest.php` | test | 2 |
| MNC-3 | numbers unchanged | `manual-test.md#step-2` | manual | 3 |
| MNC-4 | fingerprint and parity | `manual-test.md#step-3` | manual | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
