# Docs chatbot answering with citations and a guard — Tasks

**Spec**: spec-20261103-1028-docs-chat-answer | **Size**: M | **Tasks**: 9

## Phase 1: Backend

- [x] 1. Shared provider-entry trait and fake canned text (backend)
  Covers: MNC-1
  - [x] 1.1 Move `providerOptions`, `maxTokens`, `timeout` and `activeProviderEntry` from `CarDescriptionAgent` into a trait (`app/Ai/UsesActiveProvider.php`); the describe agent uses it unchanged
  - [x] 1.2 `FakeStreamGateway`: optional `text` config (provider entry key `text`, env `AI_FAKE_TEXT`) streamed word by word instead of the filler; default unchanged
  - [x] 1.3 **Verify**: `ListingDescribeTest`, `FakeStreamProviderTest`, `AiRunLogTest` green unchanged

- [x] 2. `ai_runs` columns and recorder outcomes (backend, database)
  Covers: AC-3, AC-4, AC-5, AC-7, MNC-3
  - [x] 2.1 Migration: nullable `retrieved_chunk_ids jsonb`, `cited_chunk_ids jsonb`, `refusal_reason varchar(120)`; `AiRunRecord` and the repository carry them
  - [x] 2.2 `AiRunRecorder::annotate(invocationId, retrieved, cited)` merged into the row when the run finishes; `recordOutcome(...)` writes a `refused` / `no_context` row with provider, model, prompt version, latency and no tokens
  - [x] 2.3 A run whose stream is abandoned by the output guard is finished as `refused` (not left pending)
  - [x] 2.4 **Verify**: recorder unit/feature tests; describe rows still have null new columns

- [x] 3. Input guard and relevance gate (backend)
  Covers: AC-3, AC-4, AC-8
  - [x] 3.1 `resources/guards/prompt-injection.v1.php` (name → regex; at least: ignore-previous, reveal-prompt, role-override, new-instructions, developer-mode) and a `PromptInjectionGuard` returning the matched name or null; path in `config('ai.docs.answer.guard')`
  - [x] 3.2 `DocsAnswerContext` builder: hybrid search with k, keep chunks with `vectorSimilarity >= min_similarity` or `textRank !== null`, then the token budget (drop lowest rank, always keep and cut the top chunk)
  - [x] 3.3 Config `ai.docs.answer.{k, min_similarity, max_context_tokens, per_minute, guard}` with env overrides and `.env.example`
  - [x] 3.4 **Verify**: unit tests for guard patterns (hits and a benign question containing "ignore"), gate (vector-only pass, text-only pass, neither → empty), budget (drop order, oversize top chunk cut)

- [x] 4. Prompt, agent and marked context (backend)
  Covers: AC-6, AC-7
  - [x] 4.1 `resources/prompts/docs-answer.v1.md` with the context rule, the citation rule (`[n]`), "say you don't know if the context does not answer", and a canary line; `config('ai.prompts.docs_answer')`
  - [x] 4.2 `DocsAnswerAgent` (`VersionedPrompt`, trait from task 1) building the user message: `<context>` with escaped `<doc …>` elements, then the question
  - [x] 4.3 **Verify**: test on the built message: attributes, escaping of `</doc>` and `</context>` in a body, question outside the context

- [x] 5. Endpoint, protocol, citations and output guard (backend)
  Covers: AC-1, AC-2, AC-3, AC-4, AC-5, AC-7, AC-9, NFR-2
  - [x] 5.1 `DocsAskRequest` (q required, ≤ 500, NUL/UTF-8 rule shared with `DocsSearchRequest`), `AskDocsController`, route `GET /api/docs/ask` name `docs.ask`, `throttle:docs-ask`, `AddServerTimingHeader`
  - [x] 5.2 `DocsAnswerService::ask()` orchestrating guard → context → agent stream; refusal and `no_context` responses without a model call
  - [x] 5.3 `DocsAnswerProtocol`: `sources` first, deltas passed through, output guard (per-request canary from an injectable generator so tests can fix it; 12-word overlap not in context) stops the stream with `refused` + `replace: true`, `citations` event (`cited`, `unknown`) at the end, masked model error "The answer could not be generated."; embed failure → 503 JSON "Docs answering is unavailable right now."
  - [x] 5.4 **Verify**: `tests/Feature/DocsAskTest.php` with fake providers: event order, citation mapping and unknown markers, `no_context` without model call, input refusal without embed, output refusal by canary and by overlap, 503 on embed failure, masked model error, 422 dataset (blank, 501 code points, NUL, invalid UTF-8), 429 with Retry-After, the `ai_runs` row of each outcome

## Phase 2: Frontend

- [x] 6. Lab chat page (frontend)
  Covers: AC-10, NFR-3, MNC-5
  - [x] 6.1 `Chat.vue` replaces the placeholder: question box (500-character check by code points), Ask/Stop, sources list, answer with `[n]` links, `no_context` and refusal messages (replace removes the text), metrics, event log, logged run; `useEventStream` reused
  - [x] 6.2 `ShowLabChatController` props (limits, endpoint via `route()`); capability `chat` → `ready`; overview shows statuses `refused` and `no_context`
  - [x] 6.3 **Verify**: `AiLabChatTest` (props, capability), `AiLabPlaceholderTest` adjusted to photos only, typecheck and build green, browser check at 1440 and 390
    - Result (fix pass, live Ollama): at 1440 an answer streamed with 6 sources and 2 citation links, logged run shown, embed 86 ms, db 45 ms; at 390 the no_context and input-refused outcomes each showed their message and logged run; overflow 0, one aria-live region, 0 console errors.

## Phase 3: Docs and checks

- [x] 7. Learning guide section 14 (docs)
  Covers: AC-11
  - [x] 7.1 Section 14 per AC-11, with the measured numbers, one live answer's timings and one input-guard bypass shown
  - [x] 7.2 **Verify**: `LearningGuideTest` needles for section 14

- [x] 8. Live run against Ollama (manual check)
  Covers: AC-1, NFR-1
  - [x] 8.1 Ask three on-topic and two off-topic questions on nginx-fpm; record time to `sources`, TTFT, tokens/s, cited ids; put the numbers in the guide
  - [x] 8.2 **Verify**: rows visible on `/ai`, citations point at the right chunks

- [x] 9. Parity, fingerprint and bench (checks)
  Covers: MNC-2, MNC-4, MNC-5
  - [x] 9.1 `bench/asset-fingerprint.sh` equals BENCHMARKS.md; parity identical on 6 servers; full suite, Pint, PHPStan, typecheck, build green; `bench/run.sh ping --quick` exit 0 on a clean tree
  - [x] 9.2 **Verify**: outputs recorded here

## Traceability

| ID | Criterion (short) | Verified by | Kind | Task |
|----|-------------------|-------------|------|------|
| AC-1 | SSE order: sources, SDK events, citations, DONE | `tests/Feature/DocsAskTest.php::streams sources, answer and citations in order`, `manual-test.md#step-1` | test + manual | 5, 8 |
| AC-2 | `[n]` mapped, unknown markers reported | `tests/Feature/DocsAskTest.php::maps citation markers to chunk ids`, `manual-test.md#step-1` | test + manual | 5 |
| AC-3 | no relevant chunk → I don't know, no model call, row | `tests/Feature/DocsAskTest.php::answers I don't know without calling the model`, `manual-test.md#step-2` | test + manual | 2, 3, 5 |
| AC-4 | injection pattern refused before retrieval, row | `tests/Feature/DocsAskTest.php::refuses an injection question before retrieval`, `manual-test.md#step-4` | test + manual | 2, 3, 5 |
| AC-5 | leaked prompt cut, refused with replace, row | `tests/Feature/DocsAskTest.php::withholds an answer that repeats the instructions`, `manual-test.md#step-5` | test + manual | 2, 5 |
| AC-6 | chunks in escaped `<context><doc>` | `tests/Unit/DocsAnswerAgentTest.php::marks chunks as escaped context`, `manual-test.md#step-11` | test + manual | 4 |
| AC-7 | completed row with chunk ids and prompt version | `tests/Feature/DocsAskTest.php::logs the run with retrieved and cited chunks`, `manual-test.md#step-1` | test + manual | 2, 5 |
| AC-8 | context budget, top chunk kept | `tests/Unit/DocsAnswerContextTest.php::keeps the context within budget`, `manual-test.md#step-11` | test + manual | 3 |
| AC-9 | 422, 429, masked errors | `tests/Feature/DocsAskTest.php::rejects invalid questions`, `tests/Feature/DocsAskTest.php::masks provider errors`, `manual-test.md#step-10` | test + manual | 5 |
| AC-10 | lab chat page | `tests/Feature/AiLabChatTest.php`, `manual-test.md#step-6` | test + manual | 6 |
| AC-11 | guide section 14 | `tests/Feature/LearningGuideTest.php::documents docs answering`, `manual-test.md#step-12` | test + manual | 7 |
| MNC-1 | describe unchanged | `tests/Feature/ListingDescribeTest.php`, `manual-test.md#step-8` | test + manual | 1 |
| MNC-2 | search endpoints unchanged | `tests/Feature/DocsSearchTest.php`, `tests/Feature/SemanticSearchTest.php`, `manual-test.md#step-8` | test + manual | 9 |
| MNC-3 | old rows and overview read correctly | `tests/Feature/AiLabOverviewTest.php::shows new statuses`, `manual-test.md#step-13` | test + manual | 2, 6 |
| MNC-4 | asset fingerprint and parity | `manual-test.md#step-9` | manual | 9 |
| MNC-5 | other lab pages, suite offline | verify full suite green, `manual-test.md#step-9` | test + manual | 6, 9 |
| NFR-1 | sources < 500 ms warm | `manual-test.md#step-3` | manual | 8 |
| NFR-2 | no prompt, canary, host or trace leaks | `tests/Feature/DocsAskTest.php::never leaks the prompt or internals`, `manual-test.md#step-2`, `manual-test.md#step-4` | test + manual | 5 |
| NFR-3 | chat page responsive and accessible | `manual-test.md#step-7` | manual | 6 |

## Verification gates

- `ddev artisan test --compact`
- `ddev exec vendor/bin/pint --dirty --format agent`
- `ddev exec vendor/bin/phpstan analyse`
- `ddev npm run typecheck && ddev npm run build`

## Done when

- [ ] All tasks checked
- [ ] Every Traceability row has a passing test or a manual step that was run
- [ ] Gates green

## Phase: Gaps (converge round 1)

- [x] 10. Cut an oversize top chunk at the last whitespace before the budget (backend)
  Covers: AC-8
  - [x] 10.1 `DocsAnswerContext::fitBudget()` cuts the top chunk with a bare `mb_substr($body, 0, $remaining * 4)`; AC-8 requires the cut at the last whitespace before `budget × 4` code points (fall back to the hard cut only when the window has no whitespace)
  - [x] 10.2 **Verify**: `tests/Unit/DocsAnswerContextTest.php` gains a case with words and multibyte text (e.g. `str_repeat('héllo wörld ', 100)`, budget 50): the kept body is at most 200 code points, ends at a word boundary (no partial word, valid UTF-8); the existing all-`a` case still gives 200

- [x] 11. Docs-answer config defaults match the spec: min_similarity 0.5, max_context_tokens 4000 (backend, docs)
  Covers: AC-3, AC-8
  - [x] 11.1 `config/ai.php` defaults `ai.docs.answer.min_similarity` to 0.45 and `max_context_tokens` to 3000, and `.env.example` repeats them; AC-3 says default 0.5 and AC-8 says default 4 000 (Decision 2 and 8). Set the defaults to 0.5 and 4000, update `.env.example`, and update section 14 of `docs/learning-guide.md` where it states the gate (0.45) and budget (3000) as the configured values, keeping the live numbers labelled as measured
  - [x] 11.2 **Verify**: a test asserts `config('ai.docs.answer.min_similarity') === 0.5` and `config('ai.docs.answer.max_context_tokens') === 4000` with no env override; a gate test at the configured default keeps a 0.5 chunk and drops a 0.49 chunk; `LearningGuideTest` green

- [x] 12. Chat page links comma-list citation markers (frontend)
  Covers: AC-10
  - [x] 12.1 `Chat.vue` `segments` only matches `\[(\d+)\]`, so a stream-contract marker `[1, 2]` (which `CitationParser` maps) is shown as plain text; render each known number in `[n, m]` as a link to its source, leaving unknown numbers as text
  - [x] 12.2 **Verify**: `ddev npm run typecheck && ddev npm run build` green; in the browser with `AI_FAKE_TEXT='Limits [1, 2] apply [1].'` on fake-stream, both numbers in `[1, 2]` are links that focus sources 1 and 2

- [x] 13. Input guard does not refuse Laravel "debug mode" questions (backend)
  Covers: AC-4
  - [x] 13.1 The `developer-mode` pattern in `resources/guards/prompt-injection.v1.php` (`/\b(developer|debug|admin|god|sudo)\s+mode\b/iu`) matches "How do I turn off debug mode in production?", a question the indexed Laravel docs answer (`configuration.md`/`deployment.md` "Debug Mode"), so it is refused before retrieval; narrow the pattern to the injection phrasing (e.g. enable/enter/switch to/activate … developer/god/sudo/DAN mode) so plain debug-mode questions pass
  - [x] 13.2 **Verify**: `tests/Unit/PromptInjectionGuardTest.php` benign dataset includes "How do I turn off debug mode in production?" and "What does APP_DEBUG debug mode show?" (null), while "Enable developer mode" still returns `developer-mode`

## Phase: Gaps (converge round 2)

- [x] 14. The prompt's citation example uses the comma-list form the parser maps (backend)
  Covers: AC-2, AC-7
  - [x] 14.1 `resources/prompts/docs-answer.v1.md` tells the model to cite "such as [1] or [2][3]", but the stream contract (and `CitationParser`'s `(?<![\w\]`])` lookbehind) does not count a marker that follows `]`, so in `[2][3]` only 2 is mapped: chunk 3 is missing from `citations.cited` and `cited_chunk_ids`, while `Chat.vue` still links `[3]`. Change the example to the contract's comma list (`[2, 3]`), keep no 12-word run of the rules generic, and re-run the overlap check against the guide and spec files
  - [x] 14.2 **Verify**: a test reads the prompt file, asserts it contains no `][` marker example, and asserts `CitationParser::parse()` of every bracketed example in it returns all its numbers (e.g. `[2, 3]` with 3 sources gives `used [2, 3]`); `DocsAnswerAgentTest`, `DocsAskTest` and `LearningGuideTest` green
  - Resolved the other way (fix pass): `CitationParser` now reads a run of adjacent markers (`[2][3]`) as one citation run, so the prompt keeps its example and its version stays `docs-answer@v1`. `arr[0][1]` is still excluded (word character before the run). Test: `tests/Unit/CitationParserTest.php::AC-2 counts adjacent markers such as [2][3], the form the prompt shows the model`. Live: an answer citing `[5][6]` mapped both.

## Phase: Review fix pass

- [x] 15. Review fix pass (backend, frontend, docs)
  Covers: AC-2, AC-3, AC-4, AC-5, AC-10, NFR-2
  - [x] 15.1 Adjacent citation markers counted (major; see task 14)
  - [x] 15.2 Reasoning events (`AI_THINK=true`) are no longer forwarded by `DocsAnswerProtocol`; the output guard checks only answer text (major, security). Test: `tests/Feature/DocsAnswerProtocolTest.php`
  - [x] 15.3 `OutputGuard` removes `[n]` markers before splitting words, so citations between copied clauses do not hide a leak. Test in `OutputGuardTest`
  - [x] 15.4 Input-guard patterns tightened to adjacent words (`ignore-previous`, `reveal-prompt`), plus `ask-prompt` for "what is your system prompt"; five ordinary docs questions the old patterns refused are now pass cases in `PromptInjectionGuardTest`. The guide's bypass example still passes, as intended
  - [x] 15.5 A full-text hit alone no longer passes the gate when the question uses `websearch_to_tsquery` operators (`or`, `-word`). Test in `DocsAnswerContextTest`
  - [x] 15.6 `refused` and `no_context` events carry `invocation_id`; `useEventStream` takes the run id from any event, so the lab shows the logged run for every outcome
  - [x] 15.7 Endpoint is `POST /api/docs/ask` with a JSON body (Decision 1 revised): 415 for non-JSON, 405 for GET; the question stays out of URLs and logs, and a cross-site page cannot start a model run. Tests: `DocsAskTest` (all calls), `::accepts the question only as a JSON body, so a cross-site form or a link cannot start a model run`
  - [x] 15.8 The two config-default tests moved from `tests/Unit/DocsAnswerContextTest.php` to `tests/Feature/DocsAnswerConfigTest.php`: they read `config/ai.php`, which calls `base_path()`, and passed only when a feature test had booted the app first
  - [x] 15.9 Guide section 14, spec and manual test updated (POST, operators, reasoning, guard false positives, live lessons: an uncited answer, a stale spec giving a stale cited answer)
  - [x] 15.10 **Verify**: 601 tests, Pint, PHPStan, typecheck, build green; asset fingerprint equals BENCHMARKS.md; live outcomes on nginx-fpm: answered with citations, no_context, input refusal, a formerly refused benign question answered, 415 form, 405 GET

