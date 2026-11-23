# Decisions: local model fallback

## Task 7 and 12: the idle-host bench run (2026-11-23)

Run: `ddev artisan llm:bench --only=fallback --models=qwen3.5:9b`, result
`bench/results/llm-fallback/2026-11-23/run.json` (generated 2026-11-23T16:53:11Z) with the two small-model eval runs in
`evals/nl-search/run.json` and `evals/moderation/run.json`. Main `qwen3.5:9b`, fallback `qwen3:0.6b`, budget 2000 ms,
parallel 1, 4 and 16, 3 repeats, Ollama 0.40.16, `num_ctx` 8192. Every figure below is copied from that file.

Host load (1 / 5 / 15 min): 1.67 / 2.16 / 2.41 at the start, 4.24 / 3.3 / 2.79 at the end. The Boundaries rule (below 4
at the start) holds and `host_idle_at_start` is true. The end reading is above 4; step 9 of `manual-test.md` expected
"both low". The rise follows the bench's own 16 parallel streams and the two evals that run last, the 15-minute figure
stayed under 3, and the busy repeats agree with each other (16 parallel with the budget: p50 4949, 5580, 4982 ms), so the
run is accepted and the end load is stated in BENCHMARKS.md instead of rerunning.

### Bench verdict

- **Model missing:** all four entry points answered from `qwen3:0.6b` (341 to 1351 ms), reason `model_missing`; SL-28
  failed them in 8 to 32 ms.
- **Server unreachable:** no fallback; every row failed as in SL-28 (11 to 149 ms, same statuses and errors). The fallback
  shares the server, so it cannot help here.
- **1 s timeout:** describe (1904 ms) and assistant (2052 ms) answered from the fallback, reason `timeout`. nl-search
  (2037 ms) and moderation (2151 ms) still answered 503 with one failed row each: two timeouts in a row. A non-streamed
  call must return its whole JSON within the 1 s timeout, a stream only has to start.
- **Busy:** with the 2000 ms budget, 16 parallel first-token p50 11769 → 4982 ms, p95 26002 → 6609 ms, fallback share
  0.875 (42 `busy`); 4 parallel p50 1911 → 1878 ms, p95 5027 → 2912 ms, share 0.5. No request failed in any cell.
- **Quality:** nl-search exact match 0.95 (main) against 0.275 (fallback); moderation accuracy at best F1 0.96 against
  0.68; JSON-valid 1 for both models on both features.

Verdict: the fallback is worth keeping for a missing or slow main model on the streamed text features; it buys nothing
when Ollama itself is down, and on structured features it trades a clear error for a much worse answer.

### NFR-1 reading

Holds for every row the fallback answered: model missing 341 to 1351 ms and timeout 1904 / 2052 ms, all under 2 s plus the
small model's cold first-token time (595.9 ms). Against its warm first-token time (30.6 ms) the assistant on a timeout,
at 2052 ms, is 21 ms over; the timeout fault unloads only the main model before each entry, so whether the small model was
warm at that moment is not recorded. The rows the fallback did not answer (unreachable; nl-search and moderation on a
timeout) are outside NFR-1 and are explained above. Step 9's wording "every fault row answered by `qwen3:0.6b`" does not
hold for those five rows, for the reasons given.

### NFR-2 reading (C-15)

`no_fault` = `busy.1.without_budget`: first-token p50 144 ms, p95 144 ms (repeats 167, 139, 144 ms). p50 is at most
296 ms and p95 is within the SL-28 p95 of 296 ms, so NFR-2 passes; the p50 is even below the SL-28 p50 of 196 ms (a
different day and a warm model, not a speed-up from this slice). With the budget at 1 parallel nothing fell back (p50 119
ms, share 0).

### C-14 decision

Rule: the default fallback stays on only if the fallback's JSON-valid rate is at least 90 % and its accuracy is no more
than 15 points below the main model's committed figure.

| Feature | JSON-valid (fallback) | Accuracy main / fallback | Gap | Default |
|---|---|---|---|---|
| nl-search | 1 | 0.95 / 0.275 | 67.5 points | **off** (was `qwen3:0.6b`) |
| moderation | 1 | 0.96 / 0.68 | 28 points | **off** (already off) |
| describe, docs-answer, assistant | no eval in this run | — | — | on, rule cannot apply |

- `config/ai.php`: `nl-search.fallback_model` is now `env('AI_NL_SEARCH_FALLBACK_MODEL') ?: null`; the comment on it
  and on moderation names the rule and the result file. The env variables still turn either on.
- describe, docs-answer and assistant keep `qwen3:0.6b`: the run has no eval for them, so the rule has nothing to read.
  Their answers are prose a person reads, not filters or labels the app acts on; a future eval of the small model on
  the assistant or docs-answer sets would let the rule decide.
- Tests: `LocalModelFallbackTest` now asserts the three streamed defaults, both structured defaults off, and that the
  env variables still turn them on; its `beforeEach` sets the nl-search fallback explicitly for the trigger tests.
- Docs: BENCHMARKS.md "Local model fallback (2026-11-23)", the local-models and providers guides, and II-5 of the
  learning guide say why.
