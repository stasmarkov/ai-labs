---
product: AutoPulse
owner: Stanislav Markov
status: draft
validated_by:
validated_at:
stacks: [laravel]
---

# AutoPulse product brief

## Problem

PHP developers choosing a server setup hear claims like "FrankenPHP is faster" or "Octane gives 10×",
but rarely have their own numbers. DDEV's own benchmark found nginx-fpm, apache-fpm and FrankenPHP
classic roughly comparable at default settings and did not test worker mode. Without first-hand
measurements and a reproduced state leak, it is hard to defend a production choice or tell a credible
story in an interview.

AutoPulse is a small but realistic car marketplace that runs, unchanged, behind five servers on DDEV:
nginx + PHP-FPM, Apache + PHP-FPM, OpenLiteSpeed + LSAPI, FrankenPHP classic, and FrankenPHP worker
mode via Octane. Then it adds features that only make sense on FrankenPHP (worker mode, thread
autoscaling, Early Hints, built-in Mercure, metrics, hot reload, Go extensions, a standalone binary) and
local-LLM features, and measures what each one actually changes. The output is the app, one DDEV config
per server, load-test scripts, and BENCHMARKS.md with self-produced numbers, ratios and trade-offs.

**Constraints:** Laravel 13 on PHP 8.4, in the existing DDEV project in this repo. PostgreSQL 16 with
pgvector (replacing the current MariaDB 11.8). Redis for cache and sessions. Pest as the test framework
(replacing PHPUnit). All LLM work uses local open-weight models from Hugging Face on a MacBook Pro M3 Max
with 36 GB RAM, or a fake provider.

## Users and roles

| Role | Who | Can |
|------|-----|-----|
| Visitor | anyone, not signed in (load generator included) | search listings, open a listing detail page, receive live price updates, download an export, use the AI features |
| Developer | Stanislav Markov, on the local machine | switch the server under test, toggle leak-lab modes, run benchmarks, read metrics and logs, restart workers |
| Interviewer | reader of BENCHMARKS.md and the stories | read results, ratios, trade-offs and conclusions |

There is no authentication; every endpoint is public on the local DDEV network.

## MVP scope

1. **Marketplace core.** Seeded dealers and listings; JSON ping and health endpoints; paginated, filtered listing search; listing detail page with CSS, JS and a hero image.
2. **Five server configs.** One DDEV overlay per server (nginx-fpm, apache-fpm, FrankenPHP classic, FrankenPHP worker via Octane, OpenLiteSpeed), each serving identical responses, with a README note on switching.
3. **Benchmark harness.** Load-test scripts and a repeatable procedure that fills BENCHMARKS.md tables per endpoint and scenario.
4. **Process vs thread comparison.** Memory, RPS and latency of four non-worker servers, plus request-timeout behaviour.
5. **Worker mode and state-leak lab.** Four toggleable leaks, a worker-inspection endpoint, and classic vs worker numbers.
6. **Slow I/O and autoscaling.** A slow endpoint behind a stub external API, a health endpoint that must stay fast, and FPM vs FrankenPHP thread settings compared.
7. **HTTP features.** 103 Early Hints, zstd/brotli/gzip compression, large CSV export offloaded to the web server, one HTTP/3 request.
8. **Real-time price updates.** Live price changes via FrankenPHP's built-in Mercure hub, and the same via Reverb on nginx-fpm.
9. **Observability.** Prometheus + Grafana with FrankenPHP and FPM metrics, structured PHP logs, worker restart through the admin API.
10. **Developer experience.** Worker watch, hot reload, Xdebug inside a worker.
11. **Go extension** *(optional deep dive)*. VIN check-digit validation written in Go, with a PHP fallback.
12. **Standalone binary** *(optional deep dive)*. The whole app as one executable, compared to a Docker image.
13. **AI streaming under load** *(optional deep dive)*. Streamed car descriptions held open vs queued and pushed via Mercure.
14. **Local LLM features.** Local model wiring, NL search to filters, semantic search, tool-calling assistant, moderation classifier with an eval set, car-photo vision, and a model/concurrency comparison.
15. **AI integration lab.** Docs chatbot with RAG and citations, a RAG eval harness, an admin assistant with a hand-written agent loop and an MCP server, similar-car-photos by image embeddings (plus text-to-photo search, hybrid similarity, duplicate detection), a semantic cache, a streaming chat UI, a multi-step research agent and a prompt playground. Each one exists to show how its mechanism works.

## Non-goals

- Production or cloud deployment, CDN, and TLS tuning (beyond one direct HTTP/3 container run).
- Benchmarking other frameworks or languages; Swoole and RoadRunner appear only in the cheat sheet.
- Real marketplace features: user accounts, payments, image upload, dealer admin.
- Paid external APIs: the VIN API is a stub; no cloud LLM providers, LLM work is local models or a fake provider only.
- Declaring a single "winning" server.

## Draft business rules

IDs are stable. These rules are assumptions until Stanislav Markov validates them; the validated set
moves to `docs/context/business-rules.md` with the same ids.

### Catalog and endpoints

- **BR-01** `GET /api/ping` returns 200 with a fixed JSON body and does no database or cache work. `GET /up` returns 200.
- **BR-02** For the same request and seed, every endpoint returns the same status and the same JSON body on all five server configs. *(cross-cutting)*
- **BR-03** `GET /listings` filters by make, model, fuel, city, minimum year and maximum price; any combination applies with AND. `per_page` is 1–50, default 20; results are ordered newest first. An invalid filter or `per_page` value returns 422.
- **BR-04** `GET /listings/{id}` for an unknown id returns 404.
- **BR-05** The seeded dataset is 500 dealers and 50 000 listings, generated from a fixed seed, so every server queries identical data.

### Listings

- **BR-10** Every listing belongs to exactly one dealer.
- **BR-11** A listing's price is a positive whole number of cents; year is between 1980 and next year; fuel is one of petrol, diesel, hybrid, electric, lpg.

### Benchmarks

- **BR-20** Every benchmark run uses the same commit and `.env`, `APP_DEBUG=false`, `APP_ENV=production`, optimized caches, OPcache on and Xdebug off; a run that violates any of these is discarded. *(cross-cutting)*
- **BR-21** Each scenario is warmed up 30 s, measured 60 s, repeated 3 times; the median run is reported with RPS, p50, p95, p99, error %, peak memory and CPU.
- **BR-22** The load generator runs outside the web container under test.
- **BR-23** Each comparison has a fair run (comparable concurrency limits across servers) and a best-tuned run per server, both recorded with their settings.
- **BR-24** Results are reported as ratios against the nginx-fpm baseline plus trade-offs (memory, leak risk, moving parts); the expected outcome is written down before each run and any surprise explained after.
- **BR-25** Server, PHP, DDEV and add-on versions are pinned in config and recorded in BENCHMARKS.md for every result table.
- **BR-26** A slice that produces measurements is done only with passing tests, a committed result table following BR-21, and a short interview story containing at least one measured number. *(cross-cutting)*
- **BR-27** Every shipped slice includes a short "How it works" note that names the concepts involved and walks through what happens, step by step, for one request or job (e.g. which process handles it, what is cached, which model call is made). *(cross-cutting)* Source: intake 2026-10-30 (Stanislav Markov).

### Worker mode lab

- **BR-30** Each of the four leak routes has a leak mode and a fixed mode. Under Octane in leak mode, a request for dealer/locale B after one for A shows A's data; in fixed mode it never does.
- **BR-31** On non-worker servers no leak route shows another request's data in either mode.
- **BR-32** The worker-inspection endpoint returns process id, application instance id, memory usage and a per-worker request counter.

### Slow I/O

- **BR-40** The slow listing endpoint waits on a stub VIN API that responds after 300 ms.
- **BR-41** The health endpoint does no database or external work and returns 200.

### HTTP features

- **BR-50** The listing detail page announces its CSS and JS with preload links. On FrankenPHP it sends a 103 response before the main work; on other servers the final response is identical and no error occurs.
- **BR-51** The export endpoint delivers a ~50 MB CSV with the file transfer offloaded to the web server (FrankenPHP, nginx, Apache); a PHP-streaming variant exists for comparison and returns the same bytes.
- **BR-52** On FrankenPHP, responses are compressed with zstd, brotli or gzip according to the request's Accept-Encoding.

### Real-time

- **BR-60** When a listing's price changes, every open detail page for that listing shows the new price without reload.
- **BR-61** A private price update reaches only subscribers authorized for that listing's topic.

### Observability

- **BR-70** Log entries written from PHP appear as structured JSON carrying request fields; when the FrankenPHP log function is unavailable they go to stderr as JSON.
- **BR-71** Worker restart is available as one Artisan command.

### VIN

- **BR-80** A VIN is valid when it has 17 characters, contains no I, O or Q, and its 9th character matches the check digit; the Go and PHP implementations return the same result for every input.

### AI

- **BR-90** Every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
- **BR-91** Provider and model are chosen by configuration only; switching models needs no code change.
- **BR-92** Natural-language search output is turned into the BR-03 filters and validated by the same rules; the model never produces queries. Output that fails validation returns 422.
- **BR-93** The moderation classifier returns a risk between 0 and 1 and a list of reasons; output not matching that shape is rejected and logged.
- **BR-94** The buyer assistant takes at most 4 tool steps per question.
- **BR-95** The direct streaming variant sends the answer as server-sent events while it generates; the queued variant returns at once and the answer arrives as chunks on a per-request Mercure topic.
- **BR-96** The similar-photos block on a listing page shows at most 8 photos of other listings, never a photo of the current listing, omits near-duplicates above a similarity cutoff (default 0.98) and anything below a minimum score; when nothing qualifies the block is not shown. Source: intake 2026-10-30.
- **BR-97** An AI assistant never performs a write action (changing a title, adding a tag) without the developer's explicit confirmation in the same conversation; every tool step is logged, and a question takes at most the BR-94 step limit. Source: intake 2026-10-30.
- **BR-98** A RAG answer cites the source chunks it used; when retrieval returns nothing above the relevance threshold, the chatbot says it does not know instead of answering. Source: intake 2026-10-30.
- **BR-99** The semantic cache returns a stored answer only when the new question's similarity to a cached question is above its threshold, and the response is marked as cached. Source: intake 2026-10-30.

## Success metrics

| Metric | Target | Measured by |
|--------|--------|-------------|
| Servers serving identical responses | 5 of 5 on ping, search, detail | parity check script across overlays (BR-02) |
| Benchmark tables complete | every scenario (ping, search, slow I/O, early hints, AI streaming) has fair + tuned rows for each applicable server | BENCHMARKS.md review |
| State leaks reproduced and fixed | 4 of 4, each with a failing-then-passing test | Pest suite, Octane `--max-requests=1` vs `1000` |
| Requests dropped during worker restart under load | 0 | load-test error count |
| Edit-to-reload in worker mode | under 2 s | stopwatch over 5 edits |
| Interview stories | one per slice with measurements, each with a number | BENCHMARKS.md / notes |
| Local LLM comparison | tokens/s, TTFT, JSON-valid %, eval accuracy recorded per chosen model | comparison table |
| How-it-works notes | one per shipped slice (BR-27), collected in `docs/learning-guide.md` at the end (SL-44) | slice docs review |
| RAG quality | correctness and groundedness scores recorded for at least two chunking settings | RAG eval harness (SL-33) |

## Open questions

- [ ] **Q-01** Which local open-weight models (text + tool calling, vision for car photos, text embeddings, and an image encoder such as CLIP or SigLIP) and which local server (Ollama, LM Studio, MLX, vLLM-metal, or a small sidecar for the image encoder, since LM Studio's embeddings endpoint serves text models) fit a MacBook Pro M3 Max with 36 GB? Decides: tech-research ADR. Blocks: SL-22 (and through it every AI slice). Source: widened by intake 2026-10-30.
- [x] **Q-02** Is a result valid only when RPS varies less than 5 % across the 3 repeats? — **Answered** (Stanislav Markov, 2026-10-30): yes; spread = (max − min) / median of the 3 runs' RPS. Above 5 %, rerun the scenario once; still above, record the median marked `noisy` with its spread. Blocks: nothing now.
- [ ] **Q-03** Embedding vector size follows the embedding model chosen in Q-01. Decides: Q-01 ADR. Blocks: SL-24.
- [ ] **Q-04** Without cloud providers, what is the failover target: a second local server (e.g. LM Studio behind Ollama) or none? Decides: Stanislav Markov. Blocks: SL-28.
- [ ] **Q-05** Hostnames: keep DDEV project name `interview` (interview.ddev.site) or rename to `autopulse`? Decides: Stanislav Markov. Blocks: none (default: keep `interview`).
- [ ] **Q-06** Which car photo set do the photo slices use? The seed has only a few static hero images; similarity needs many real car photos whose licence allows local use. Decides: Stanislav Markov (external). Blocks: SL-36, SL-37, SL-38, SL-39.
- [ ] **Q-07** Vector search in pgvector only, or also compare Elasticsearch `dense_vector` kNN on the same data? Decides: Stanislav Markov. Blocks: none (default: pgvector only).
