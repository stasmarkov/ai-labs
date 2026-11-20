# Scene lessons for the remaining course outline — Tasks

**Spec**: spec-20261120-1032-scene-lessons-course | **Size**: L | **Tasks**: 6

## Phase 1: Lessons

- [x] 1. Running-models lessons (content)
  Covers: AC-2, AC-3
  - [x] 1.1 Write `streaming-an-answer` and `choosing-a-model-and-provider`.
  - [x] 1.2 **Verify**: `LessonContentTest` covers both lessons.

- [x] 2. Answers-you-can-trust lessons (content)
  Covers: AC-2, AC-3
  - [x] 2.1 Write `structured-output-and-validation`, `retrieval-with-citations` and `caching-answers`.
  - [x] 2.2 **Verify**: `LessonContentTest` covers all three lessons.

- [x] 3. Agents lessons, plus node kinds if needed (content, vue)
  Covers: AC-2, AC-3, AC-7
  - [x] 3.1 Write `a-tool-loop-with-caps-and-confirmation` and `mcp-and-a-research-agent`, and add up to 3 node kinds with icons and validation.
  - [x] 3.2 **Verify**: `LessonContentTest`, `LessonRepositoryTest` (new kinds), contrast test.

## Phase 2: Wiring and checks

- [x] 4. Course, guides, docs (content, docs)
  Covers: AC-1, AC-6
  - [x] 4.1 In `course.php`, turn on all eight lessons with real minutes, level and step count. Add `lessons` keys to the related guides. Update the `lessons` guide, the learning guide and the README.
  - [x] 4.2 **Verify**: `LessonPagesTest`, `GuidePageTest`, `GuideContentTest`, `DocsLinksTest`, `NoReferencesInUiTest`.

- [x] 5. Accuracy review and browser walk (checks)
  Covers: AC-4, AC-5, NFR-1, NFR-2
  - [x] 5.1 A fresh reviewer checks each lesson's text against the code and the result files. Then a Playwright walk of all eight lessons covers the keyboard, reduced motion and frame timing on the busiest step. The UI audit runs on `/ai/learn` and all eight lessons.
  - [x] 5.2 **Verify**: the results are recorded here.

- [x] 6. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 6.1 Run the full suite, Pint, PHPStan, typecheck and build; check the fingerprint and parity; commit, ping and push.

## Review record (task 5)

Test: `UiAuditGuidePagesTest` AC-4 pins the audit page list to the course (written first, failed, then passed after adding the seven lessons to `bench/ui-audit.mjs`).

Accuracy review (NFR-1): each lesson's text checked against the code it describes, and each number against its result file (numbers are `{num:}` or kpi pointers, so the text cannot drift from the files; `LessonContentTest` pins that).

| Lesson | Claims checked against code | Result |
|--------|-----------------------------|--------|
| one-model-many-users | pilot, unchanged (MNC-1) | not re-reviewed |
| streaming-an-answer | `data:` lines, `[DONE]` marker, `X-Accel-Buffering`, `connection_aborted`, run row `stopped` (`SseProtocol`, `ConnectionAbortWatcher`, `AiRunRecorder`) | matches |
| choosing-a-model-and-provider | per-feature resolver and settings, `cloud-ai` limiter (`LimitCloudAi`), three attempts (`PhotoExtractionService::MAX_ATTEMPTS`) | matches |
| structured-output-and-validation | retry cap of three, failed after one try when the server is down, PHP rules after the schema | matches |
| retrieval-with-citations | reciprocal rank fusion, `ai.docs.rrf_depth`, gate before the model call | matches |
| caching-answers | key normalisation (`DocsAnswerCache::normalize`), versions in the key, store only cited answers, prune command | one fix: the text omitted that trailing exclamation marks are also removed; reworded |
| a-tool-loop-with-caps-and-confirmation | `tool_choice` none on the last call (`OllamaChatClient`), `AdminAssistantLoop::MAX_STEPS`, pending action then confirm | matches |
| mcp-and-a-research-agent | `Mcp::local('listings')` (stdio, no port), write tools propose only, research caps (`ai.research.max_model_calls`) | matches |

Browser walk (Playwright 1.55, Chrome, 1440 x 1000, https://ai-lab.ddev.site):

| Check | Result |
|-------|--------|
| Keyboard (ArrowRight through every step, ArrowLeft, R, Space pause and resume) in all eight lessons | works in all eight, steps counted 10, 10, 9, 10, 10, 9, 12, 12 |
| Reduced motion (`prefers-reduced-motion: reduce`) in all eight | steps and keys work, no running animations |
| Frame timing, every step of every lesson, 1.2 s each | median 16.7 ms (one frame at 60 Hz) at every step, well under 20 ms |
| Progress after finishing all eight | "8 of 8 lessons done" |

UI audit: `node bench/ui-audit.mjs --only learn,learn/running-models/one-model-many-users,learn/running-models/streaming-an-answer,learn/running-models/choosing-a-model-and-provider,learn/answers-you-can-trust/structured-output-and-validation,learn/answers-you-can-trust/retrieval-with-citations,learn/answers-you-can-trust/caching-answers,learn/agents/a-tool-loop-with-caps-and-confirmation,learn/agents/mcp-and-a-research-agent` at 1440 and 1024: 18 pages checked, 0 failing. The assets were rebuilt first (`npm run build`).

## Gates record (task 6)

- Full suite: 3612 passed, 3 skipped. Pint passed, PHPStan no errors, typecheck and build clean.
- Fingerprint: `app-DNob7CFE.css` and `app-PTzu1QaN.js`, unchanged.
- Parity: `PARITY_INSECURE=1 bench/parity.sh capture` changed only the `captured=` line; restored with `git checkout -- bench/parity`.
- `composer.lock` and `package-lock.json` unchanged.

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

- [x] All tasks checked
- [x] Every Traceability row has a passing test or a manual step that was run
- [x] Gates green
- Clean-tree bench: after the commit, `bench/run.sh ping --quick` exited 0 on frankenphp.
