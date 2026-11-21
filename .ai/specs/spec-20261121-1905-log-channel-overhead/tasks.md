# Bring the frankenphp log channel under 5 % p95 overhead — Tasks

**Spec**: spec-20261121-1905-log-channel-overhead | **Size**: M | **Tasks**: 5

## Phase 1: Guard and profile

- [ ] 1. Characterization test of the full entry, then profile (laravel)
  Covers: AC-1, AC-3, MNC-1
  - [ ] 1.1 Add `tests/Feature/FrankenphpLogEntryCharacterizationTest.php`: a test that pins the full formatted entry and the slog attributes for records with nested context, an object, an exception (with previous) and sensitive keys (`password`, `apiKey`, `access-token`, `tokens_in`), on both the `frankenphp_log` writer and the stderr line. Green on today's code.
  - [ ] 1.2 Profile one entry part by part; write the split and the method in `decisions.md`.
  - [ ] 1.3 **Verify**: new test green; `StructuredLogTest` green.

## Phase 2: Optimise

- [ ] 2. Output-neutral optimisations chosen by the profile (laravel)
  Covers: AC-3, MNC-1, MNC-2
  - [ ] 2.1 Apply the changes; re-profile; record after-numbers in `decisions.md`.
  - [ ] 2.2 **Verify**: task 1 test, `StructuredLogTest`, `BenchLogOverheadTest` green; pint, phpstan green.

## Phase 3: Measure and document

- [ ] 3. Manual: run `bench/log-overhead.sh` on a clean tree
  Covers: AC-2, NFR-1, NFR-2, MNC-3
  - [ ] 3.1 **Verify**: `manual-test.md#step-2` passes; new result file committed.

- [ ] 4. Point BENCHMARKS, the guide, the lesson and the SL-16 note at the new file (docs)
  Covers: AC-4
  - [ ] 4.1 Before/after in BENCHMARKS; guide numbers and lesson `$overhead` read the new file; text matches the result (met or missed).
  - [ ] 4.2 **Verify**: `LessonContentTest`, `GuidePageTest` green.

- [ ] 5. Manual: walk manual-test.md
  Covers: MNC-4
  - [ ] 5.1 **Verify**: every step passes.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | per-part cost recorded | `manual-test.md#step-1` | manual | 1, 2 |
| AC-2 | new result < 5 % or miss recorded | `manual-test.md#step-2` | manual | 3 |
| AC-3 | identical entries | `tests/Feature/FrankenphpLogEntryCharacterizationTest.php` | test | 1, 2 |
| AC-4 | consumers read the new file | `tests/Feature/LessonContentTest.php` | test | 4 |
| MNC-1 | entry shape unchanged | `tests/Feature/FrankenphpLogEntryCharacterizationTest.php` | test | 1, 2 |
| MNC-2 | opt-in, request id unchanged | `tests/Feature/StructuredLogTest.php` | test | 2 |
| MNC-3 | bench method unchanged | `tests/Feature/BenchLogOverheadTest.php` | test | 3 |
| MNC-4 | no deps, fingerprint | `manual-test.md#step-3` | manual | 5 |
| NFR-1 | difference < 5 % | `manual-test.md#step-2` | manual | 3 |
| NFR-2 | channel-off p95 not slower | `manual-test.md#step-2` | manual | 3 |

## Verification gates

- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev artisan test --compact`
- `bench/log-overhead.sh` (task 3), `bench/asset-fingerprint.sh`

## Done when
- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
