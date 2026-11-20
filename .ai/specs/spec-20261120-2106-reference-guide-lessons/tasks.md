# Scene lessons for the reference-only guides — Tasks

**Spec**: spec-20261120-2106-reference-guide-lessons | **Size**: L | **Tasks**: 7

## Phase 1: Lessons

- [ ] 1. Running-models and answers lessons: `describing-a-car`, `prompts-you-can-version`, `guards-on-both-sides` (content)
  Covers: AC-2, AC-3, AC-4
  - [ ] 1.1 Write the three lesson files; add `lessons` keys to `describe`, `prompts`, `playground`, `guards`; remove them from `references`.
  - [ ] 1.2 **Verify**: `LessonContentTest` covers the three lessons.

- [ ] 2. Search-and-photos lessons: `search-by-meaning`, `searching-photos-by-text-and-likeness`, `finding-duplicate-photos` (content)
  Covers: AC-2, AC-3, AC-4
  - [ ] 2.1 Write the three lesson files; add `lessons` keys to `semantic`, `photo-search`, `similar-photos`, `photo-duplicates`; remove them from `references`.
  - [ ] 2.2 **Verify**: `LessonContentTest` covers the three lessons.

- [ ] 3. Running-the-server lessons: `the-run-log`, `logs-and-a-safe-worker-restart`, `seeing-your-change-in-worker-mode` (content)
  Covers: AC-2, AC-3, AC-4
  - [ ] 3.1 Write the three lesson files; add `lessons` keys to `run-log`, `logs-restart`, `worker-dx`; remove them from `references`.
  - [ ] 3.2 **Verify**: `LessonContentTest` covers the three lessons.

- [ ] 4. Course and page tests move to 17 lessons and no reference topics (laravel)
  Covers: AC-1, AC-6, MNC-1, MNC-5, NFR-3
  - [ ] 4.1 Update `LessonPagesTest`, `LessonRepositoryTest`, `GuidePageTest` expectations; keep a fixture-based test for reference topics so that code path stays covered.
  - [ ] 4.2 **Verify**: tests green.

## Phase 2: Docs and checks

- [ ] 5. Docs: `lessons` guide, README, learning guide (docs)
  Covers: AC-7, MNC-3
  - [ ] 5.1 List the 17 lessons; no slice ids or paths in guide text.
  - [ ] 5.2 **Verify**: `NoReferencesInUiTest`, `DocsLinksTest` green.

- [ ] 6. Accuracy review of the nine lessons against code and result files (review)
  Covers: NFR-1, MNC-2
  - [ ] 6.1 Fresh reviewer checks every claim and number; record the table in this file.
  - [ ] 6.2 **Verify**: every row resolved.

- [ ] 7. Manual: walk manual-test.md
  Covers: AC-5, AC-6, NFR-2, MNC-3, MNC-4
  - [ ] 7.1 **Verify**: every step passes.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | 17 lessons, no reference topics | `tests/Feature/LessonPagesTest.php` | test | 4 |
| AC-2 | numbers from tracked files | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-3 | steps, outcomes, summary, deeper | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-4 | guides link their lesson | `tests/Feature/GuidePageTest.php` | test | 1, 2, 3 |
| AC-5 | scenes fit, audit exit 0 | `manual-test.md#step-1` | manual | 7 |
| AC-6 | keyboard, motion, progress 17 | `manual-test.md#step-2` | manual | 7 |
| AC-7 | docs list 17 lessons | `manual-test.md#step-4` | manual | 5 |
| MNC-1 | existing lessons unchanged | `tests/Feature/LessonRepositoryTest.php` | test | 4 |
| MNC-2 | no numeric literals | `tests/Feature/LessonContentTest.php` | test | 6 |
| MNC-3 | no refs, fingerprint, parity | `tests/Feature/NoReferencesInUiTest.php` | test | 5, 7 |
| MNC-4 | no deps, tables, routes | `manual-test.md#step-5` | manual | 7 |
| MNC-5 | SL-57 pages as shipped | `tests/Feature/GuidePageTest.php` | test | 4 |
| NFR-1 | accuracy review | `manual-test.md#step-3` | manual | 6 |
| NFR-2 | frame time ≤ 20 ms | `manual-test.md#step-2` | manual | 7 |
| NFR-3 | payload < 60 000 | `tests/Feature/LessonPagesTest.php` | test | 4 |

## Verification gates

- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev artisan test --compact`
- `ddev npm run typecheck`, `ddev npm run build`
- `node bench/ui-audit.mjs`, `bench/asset-fingerprint.sh`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
