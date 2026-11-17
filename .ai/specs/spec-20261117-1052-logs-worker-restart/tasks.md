# Structured logs and worker restart — Tasks

**Spec**: spec-20261117-1052-logs-worker-restart | **Size**: M | **Tasks**: 5

## Phase 1: Code

- [x] 1. Structured log channel (backend)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, NFR-1, MNC-1
  - [x] 1.1 Add `FrankenphpLogHandler`, `RequestContextProcessor` and `AssignRequestId` middleware, the `frankenphp` channel and the `.env.example` note.
  - [x] 1.2 **Verify**: `tests/Feature/StructuredLogTest.php`:
    - the function path, using a test double;
    - the stderr JSON fallback;
    - the fields;
    - `X-Request-Id` reused and replaced;
    - two requests with different ids;
    - redaction;
    - the default stack unchanged.

- [x] 2. Restart command (backend)
  Covers: AC-6, MNC-3, MNC-4
  - [x] 2.1 Add `frankenphp:workers:restart`, with mode detection, the admin POST, `octane:reload`, the classic no-op, errors and the container check.
  - [x] 2.2 **Verify**: `tests/Feature/WorkersRestartCommandTest.php` (`Http::fake` for the admin API; the Octane call faked).

## Phase 2: Measure and document

- [ ] 3. Restart under load (checks)
  Covers: AC-7, NFR-2
  - [ ] 3.1 Add `bench/restart-load.sh`, run it on worker and Octane with the full method on a clean tree, and do the channel overhead ping.
  - [ ] 3.2 **Verify**: the result files exist; `.env` and the server are restored.

- [x] 4. Docs and guide (docs)
  Covers: AC-8
  - [x] 4.1 Add the BENCHMARKS section, the learning guide I-12, the `resources/guides/logs-restart.php` guide (six tabs plus teach) and README.
  - [x] 4.2 **Verify**: `DocsLinksTest`, `GuideContentTest`, `GuidePageTest`, `NoReferencesInUiTest`, and `bench/ui-audit.mjs` on `/ai/guides`.

- [x] 5. Gates and ship (checks)
  Covers: MNC-1, MNC-2, MNC-3, MNC-4
  - [x] 5.1 Run the full suite, Pint and PHPStan; check the fingerprint and parity; commit, ping and push.

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | frankenphp_log entry with fields | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-2 | stderr JSON fallback | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-3 | X-Request-Id | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-4 | no leak between worker requests | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-5 | redaction | `tests/Feature/StructuredLogTest.php` | test | 1 |
| AC-6 | restart command per mode | `tests/Feature/WorkersRestartCommandTest.php` | test | 2 |
| AC-7 | restart under load measured | `manual-test.md#step-3` | manual | 3 |
| AC-8 | guide and docs | `tests/Feature/GuideContentTest.php` | test | 4 |
| MNC-1 | default logging unchanged | `tests/Feature/StructuredLogTest.php` | test | 1 |
| MNC-2 | responses, fingerprint, parity | `manual-test.md#step-4` | manual | 5 |
| MNC-3 | admin API stays local | `tests/Feature/MetricsStackConfigTest.php` | test | 2 |
| MNC-4 | suite needs no server | `tests/Feature/WorkersRestartCommandTest.php` | test | 2 |
| NFR-1 | privacy | `tests/Feature/StructuredLogTest.php` | test | 1 |
| NFR-2 | overhead | `manual-test.md#step-3` | manual | 3 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green
