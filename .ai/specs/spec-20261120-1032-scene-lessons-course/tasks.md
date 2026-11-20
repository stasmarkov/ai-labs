# Scene lessons for the remaining course outline — Tasks

**Spec**: spec-20261120-1032-scene-lessons-course | **Size**: L | **Tasks**: 6

## Phase 1: Lessons

- [ ] 1. Running-models lessons (content)
  Covers: AC-2, AC-3
  - [ ] 1.1 Write `streaming-an-answer` and `choosing-a-model-and-provider`.
  - [ ] 1.2 **Verify**: `LessonContentTest` covers both lessons.

- [ ] 2. Answers-you-can-trust lessons (content)
  Covers: AC-2, AC-3
  - [ ] 2.1 Write `structured-output-and-validation`, `retrieval-with-citations` and `caching-answers`.
  - [ ] 2.2 **Verify**: `LessonContentTest` covers all three lessons.

- [ ] 3. Agents lessons, plus node kinds if needed (content, vue)
  Covers: AC-2, AC-3, AC-7
  - [ ] 3.1 Write `a-tool-loop-with-caps-and-confirmation` and `mcp-and-a-research-agent`, and add up to 3 node kinds with icons and validation.
  - [ ] 3.2 **Verify**: `LessonContentTest`, `LessonRepositoryTest` (new kinds), contrast test.

## Phase 2: Wiring and checks

- [ ] 4. Course, guides, docs (content, docs)
  Covers: AC-1, AC-6
  - [ ] 4.1 In `course.php`, turn on all eight lessons with real minutes, level and step count. Add `lessons` keys to the related guides. Update the `lessons` guide, the learning guide and the README.
  - [ ] 4.2 **Verify**: `LessonPagesTest`, `GuidePageTest`, `GuideContentTest`, `DocsLinksTest`, `NoReferencesInUiTest`.

- [ ] 5. Accuracy review and browser walk (checks)
  Covers: AC-4, AC-5, NFR-1, NFR-2
  - [ ] 5.1 A fresh reviewer checks each lesson's text against the code and the result files. Then a Playwright walk of all eight lessons covers the keyboard, reduced motion and frame timing on the busiest step. The UI audit runs on `/ai/learn` and all eight lessons.
  - [ ] 5.2 **Verify**: the results are recorded here.

- [ ] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [ ] 6.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | all eight playable | `tests/Feature/LessonPagesTest.php` | test | 4 |
| AC-2 | numbers from tracked files | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-3 | steps, outcomes, summary, deeper | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| AC-4 | renders without overlap | `manual-test.md#step-1` | manual | 5 |
| AC-5 | keyboard, motion, progress | `manual-test.md#step-2` | manual | 5 |
| AC-6 | guides link lessons, docs | `tests/Feature/GuidePageTest.php` | test | 4 |
| AC-7 | new node kinds | `tests/Feature/LessonRepositoryTest.php` | test | 3 |
| MNC-1 | pilot and engine unchanged | verify existing SL-55 lesson tests pass unchanged | test | 6 |
| MNC-2 | no numeric literals | `tests/Feature/LessonContentTest.php` | test | 1, 2, 3 |
| MNC-3 | no references, fingerprint, parity | `tests/Feature/NoReferencesInUiTest.php` | test | 4, 6 |
| MNC-4 | no deps, tables, calls | `tests/Feature/LessonPagesTest.php` | test | 6 |
| NFR-1 | accuracy | `manual-test.md#step-3` | manual | 5 |
| NFR-2 | smoothness | `manual-test.md#step-2` | manual | 5 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
