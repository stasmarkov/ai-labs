# SL-46 manual checks (task 11)

Run on 2026-11-03 in headless Chrome driven over CDP (1440x900), against https://interview.ddev.site, with Ollama running `qwen3.5:9b` and `bge-m3`. Screenshots are in `.ai/reports/sl-46-screenshots/`. `.env` was restored after every change (provider, rate limit, `OLLAMA_URL`, fake failure).

Not done: the VoiceOver check. A headless browser cannot drive a screen reader; see step 5. Task 11.1 stays unticked for that reason.

## Step 2: describe, Stop, new run (AC-2, AC-3)

| Step | Server | Result |
|------|--------|--------|
| 2.4 fake provider | nginx-fpm | Text grew word by word: 109 chars at 1 s, 679 at 6 s, 1175 at 12 s. Tokens 200, first token 46 ms, 19 tokens/s, 204 events in the rail. State went Streaming to Finished. Logged run: provider `fake-stream`, model `fake-slow-1`, 53 in, 200 out, latency 10483 ms. Screenshot `2.4-fake-finished.png`. |
| 2.5 Stop, fake | nginx-fpm | Run count 2348 before and 2348 after. Page said "Stopped after 19 tokens. The server may not have logged this run." (`2.5-stopped.png`). |
| 2.5 Stop, ollama | nginx-fpm | Run count 2355 before and 2355 after Stop at 30 tokens ("Stopped after 30 tokens. The server may not have logged this run."). A new run started straight away and finished with 105 tokens (the count then rose to 2356). `nginx-fpm-2.3-stopped.png`. |
| 2.6 Start | frankenphp-octane | Streamed and finished: 107 tokens, first token 162 ms, 59 tokens/s; logged run ollama, 111 in, 110 out, TTFT 109 ms. `frankenphp-octane-2.2-finished.png`. |
| 2.6 Stop | frankenphp-octane | Page said "Stopped after 24 tokens. The server may not have logged this run." Run count 2357 before and 2358 after. Run 2358 is `completed` with 93 tokens out: on octane the server does not notice the abort and finishes and logs the run. The manual step's "count usually does not change" holds on nginx-fpm only. |
| 2.6 new run | frankenphp-octane | Start worked at once after Stop: 82 tokens, 60 tokens/s, finished. |

Server returned to nginx-fpm afterwards.

## Step 3: errors (AC-4)

| Step | Result |
|------|--------|
| 3.1 429 | `AI_DESCRIBE_PER_MINUTE=2`: runs 1 and 2 finished, run 3 showed "Too many requests, try again in 51 s." in the page (`3.1-429.png`). |
| Masked `error` event | Fake provider with `AI_FAKE_FAIL_AFTER=30`: after 30 tokens the state was Failed and the page showed "The description could not be generated."; the last rail event was `error The description could not be generated.`; no stack trace, no console error (`3-masked-error.png`). |
| Connection lost | The php-fpm processes were killed during a stream. State Failed, page showed "Connection lost." (`3-connection-lost.png`). The only console line is Chrome's own network log, `ERR_HTTP2_PROTOCOL_ERROR` on the request; there is no uncaught exception, so the failure is visible in the page and not only in the console. Setting the browser offline did not cut a stream that was already open, so it was not usable. |
| 3.2 Ollama unreachable | Ollama was not quit (the quit prompt was cancelled); `OLLAMA_URL` pointed at a closed port instead, which gives the same unreachable state. Describe showed the masked "The description could not be generated." with no stack trace (`3.2-describe-masked.png`). After a reload the top bar read "Ollama unreachable" (`3.2-topbar-unreachable.png`). |

## Step 4: search pages (AC-5, AC-6)

Semantic, query "family car with a big boot": 10 listings, each with body type, city, price, a similarity bar and a description; the top three were a 2017 Volkswagen Passat estate (0.5624), a 2016 Passat estate (0.5617) and a 2014 Peugeot 308 estate (0.5617). Rail: embedding 1651.3 ms, database 673.9 ms, round trip 2386 ms (cold embed). `4.1-semantic-results.png`.

Docs, query `flushpackets` (columns Vector, Text, Hybrid; timings embed / db / round trip):

| Column | Embed | Db | Round trip | First results |
|--------|-------|----|-----------|---------------|
| Vector | 73.7 ms | 186.5 ms | 281 ms | learning guide "9. Streaming an AI answer" x2, Laravel strings.md |
| Text | none | 66.9 ms | 83 ms | BENCHMARKS.md "Docs index and hybrid retrieval", then learning guide chunks |
| Hybrid | 81 ms | 188.5 ms | 291 ms | learning guide "9. Streaming an AI answer" x3 |

Chunks found by more than one mode carry the "In several lists" mark: 4 in Vector, 4 in Text, 6 in Hybrid. Deviation from the manual step: the text column now starts with a BENCHMARKS.md chunk, not the learning guide, because BENCHMARKS.md also mentions `flushpackets`; the hybrid column does start with the learning guide. The step text was updated. `4.2-docs-flushpacke.png`.

Docs, "why does apache hold back a streamed response until the end": Vector 273.1 / 12.8 / 323 ms, Text none / 27 ms / 63 ms (2 results), Hybrid 260.2 / 33.1 / 327 ms. Hybrid had 6 results, all marked "In several lists", led by BENCHMARKS.md, then the learning guide, then Laravel responses.md "Streamed Responses". `4.2-docs-whydoesa.png`.

422: the Search button is disabled for an empty query, so manual step 4.1 could not run as written. With the field empty, `form.requestSubmit()` sends the request and both pages show "The q field is required." (semantic once, docs in each of the three columns). `4.1-semantic-422.png`, `4.2-docs-422.png`. The step text was updated.

503 (Ollama unreachable, as above): semantic showed "Semantic search is unavailable." (`4.1-semantic-503.png`); on docs the Vector and Hybrid columns showed "Docs search is unavailable." while the Text column still returned 6 results (`4.2-docs-503.png`). Console showed only the browser's network lines for the 503 responses.

## Step 5: screen reader (AC-9)

VoiceOver was not run. Evidence from the accessibility tree on `/ai/describe` during a stream:

- Exactly one live region on the page: the `<output>` (role status, `aria-live="polite"`, atomic, relevant "additions text"), holding the streamed text.
- The event log `<ol>` in the rail has no live attribute and none of its ancestors has one, so a screen reader will not announce its rows.
- The streaming cursor is `aria-hidden`.

A person still has to confirm with VoiceOver (Cmd+F5) that the text is read politely and the log is not.

## Contrast pairs (WCAG AA, 4.5:1 for normal text)

| Pair | Colours | Ratio |
|------|---------|-------|
| ink on paper (body, headings) | #1a1916 on #f5f2eb | 15.72 |
| muted on paper (13 px) | #6b665c on #f5f2eb | 5.10 |
| signal on paper (15 px) | #c2410c on #f5f2eb | 4.63 |
| danger on paper | #b42318 on #f5f2eb | 5.88 |
| success on paper | #2b7a4b on #f5f2eb | 4.71 |
| primary button text | #ffffff on #c2410c | 5.18 |
| top bar text | #f5f2eb on #1a1916 | 15.72 |

All pairs pass AA.

## Asset fingerprint and parity re-baseline (task 12, MNC-1)

The post-task-0 baseline of 2026-11-03 (commit 580fbac) is also recorded in BENCHMARKS.md, section "Response parity". After `ddev npm run build` with the lab in place, `bench/asset-fingerprint.sh` prints:

```
resources/css/app.css assets/app-CjRfEL-0.css 82042c94c3ab3d21075df7b9868cb39096d32a2085ceddb6c94b2b56d438ce71
resources/js/app.js assets/app-BvRk9kiK.js e3b0c44298fc1c149afbf4c8996fb92427ae41e4649b934ca495991b7852b855
```

Manual step 6 parity (capture per server, then `compare` against the committed snapshot) printed `identical` on nginx-fpm, apache-fpm, openlitespeed, frankenphp, frankenphp-octane and frankenphp-worker. The server was returned to nginx-fpm.
