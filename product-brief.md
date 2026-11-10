---
product: ai-lab
owner: Stanislav Markov
status: draft
validated_by:
validated_at:
stacks: [laravel]
---

# ai-lab product brief

*Renamed from AutoPulse on 2026-11-10 (Stanislav Markov): the project is now a study lab for implementing AI features in Laravel. The car marketplace stays as the demo domain. The history of the server-comparison phase stays in `BENCHMARKS.md` and the guide.*

## Problem

A Laravel developer who wants to add AI features finds scattered tutorials. Each one shows one provider, one happy path and no numbers. Questions like these rarely get a worked, runnable answer in one place: how do you stream an answer, cancel it, cite sources, guard against prompt injection, cache, evaluate, call tools, use structured output, embed images, or switch between a local model and Claude, OpenAI or Gemini without rewriting code? And what does each choice cost in latency, accuracy and server resources?

ai-lab is a Laravel 13 app that anyone can clone, start with `ddev setup` and study. Every AI capability is implemented the Laravel way, on a realistic demo domain (a 50 000-listing car marketplace). It is visible in the `/ai` lab with "how it works" next to the live run, measured in `BENCHMARKS.md`, and explained step by step in `docs/learning-guide.md` with links to the code. It runs on FrankenPHP: classic mode, worker mode and Octane, with the built-in Mercure hub. The lab also shows how FrankenPHP and Octane change the way AI features are served (streaming, held workers, live updates).

**Constraints:**
- **Stack:** Laravel 13 on PHP 8.4, in DDEV (project `ai-lab`, https://ai-lab.ddev.site). PostgreSQL 16 with pgvector. Redis for cache and sessions. Pest. The Laravel AI SDK (`laravel/ai`) is the client library.
- **Models:** local open-weight models through Ollama are the default, so the lab runs for free and offline. Claude (Anthropic), OpenAI and Gemini are optional providers, switched on by putting their API key in `.env` (never committed). Every feature works with the local default, and the test suite runs offline with fakes.
- **Servers:** the FrankenPHP family only: classic, its native worker mode, and Octane. nginx-fpm, apache-fpm and OpenLiteSpeed are removed (owner decision 2026-11-10); their past measurements remain as history.

## Users and roles

| Role | Who | Can |
|------|-----|-----|
| Learner | any developer who clones the repo | run `ddev setup`, use every AI feature in the lab, read the guide and the code, run the benches and evals, switch providers and server modes |
| Visitor | anyone using the demo site | search listings, open a listing page, receive live price updates, use the AI features |
| Maintainer | Stanislav Markov | everything above, plus the backlog and specs |

There is no authentication; every endpoint is public on the local DDEV network. Debug and lab-only endpoints stay behind `DEBUG_ROUTES`.

## MVP scope

The history items (1–13) are kept for traceability. Struck items were removed by the 2026-11-10 pivot.

1. **Marketplace core.** Seeded dealers and listings; JSON ping and health endpoints; paginated, filtered listing search; listing detail page. *(kept as the demo domain)*
2. ~~**Five server configs.**~~ *(narrowed 2026-11-10 to the FrankenPHP family: classic, worker, Octane)*
3. **Benchmark harness.** Load-test scripts and a repeatable procedure that fills `BENCHMARKS.md` tables per endpoint and scenario.
4. ~~**Process vs thread comparison.**~~ *(history; FPM servers removed 2026-11-10)*
5. **Worker mode and state-leak lab.** Four toggleable leaks and a worker-inspection endpoint, on FrankenPHP worker mode and Octane.
6. **Slow I/O and autoscaling.** A slow endpoint and a health check under load, with FrankenPHP thread settings (FPM cells kept as history).
7. **HTTP features.** 103 Early Hints, compression, file offload, HTTP/3, all on FrankenPHP.
8. **Real-time updates.** Live price changes through FrankenPHP's built-in Mercure hub. ~~Reverb on nginx-fpm~~ *(dropped 2026-11-10)*.
9. **Observability on FrankenPHP.** Caddy/FrankenPHP metrics, structured logs, worker restart.
10. **Worker-mode developer experience.** Octane and FrankenPHP worker watch, hot reload, Xdebug in a worker.
11. ~~**Go extension**~~ *(dropped 2026-11-01)*
12. ~~**Standalone binary**~~ *(dropped 2026-11-01)*
13. **AI streaming.** Answers streamed over SSE, with a Stop that really cancels.
14. **AI features.**
    - Provider wiring: local Ollama by default; Claude, OpenAI and Gemini optional; switching by configuration only.
    - Search and understanding: NL search to filters, semantic search, moderation with an eval set.
    - Agents and tools: a tool-calling assistant, an admin assistant with a hand-written agent loop, an MCP server, a research agent.
    - Images: car-photo vision, image embeddings (similar photos, text-to-photo search, duplicates).
    - Comparing and tuning: a model and provider comparison, a prompt playground.
15. **AI integration lab.** Docs chatbot with RAG and citations, RAG evaluation, semantic cache, streaming chat UI, and a study-focused UI that explains each capability next to its live run.

## Non-goals

- Production deployment, CDN and TLS tuning. The lab is deployable for study, not hardened for production traffic.
- Servers other than FrankenPHP (classic, worker, Octane). Comparing them was the earlier phase; its numbers stay as history.
- Real marketplace features: user accounts, payments, image upload, dealer admin.
- Making a cloud provider required. Each cloud provider is optional and needs only its key; no feature may depend on one.
- Declaring a single "winning" model or provider.
- Go extensions, a single-binary build, the queued AI-streaming variant (dropped 2026-11-01).

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
- **BR-27** Every shipped slice includes a short "How it works" note that names the concepts involved and walks through what happens, step by step, for one request or job (e.g. which process handles it, what is cached, which model call is made), and adds or updates its section in `docs/learning-guide.md` with links to the code, specs, measured numbers and official docs. *(cross-cutting)* Source: intake 2026-10-30; guide duty added 2026-11-01 (Stanislav Markov).

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

- ~~**BR-80**~~ *(retired: the VIN Go extension slice SL-18 was dropped, 2026-11-01, Stanislav Markov)*

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
- **BR-100** Text retrieved for a model (document chunks, tool results) is passed as marked data, never as instructions; a question matching a known prompt-injection pattern is refused with a stated reason, and an answer that contains the system prompt is replaced by a refusal. Both refusals are logged with the BR-90 fields. Source: reference design, 2026-11-02, Stanislav Markov.

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

- [x] **Q-01** Which local open-weight models (text + tool calling, vision for car photos, text embeddings, and an image encoder such as CLIP or SigLIP) and which local server (Ollama, LM Studio, MLX, vLLM-metal, or a small sidecar for the image encoder, since LM Studio's embeddings endpoint serves text models) fit a MacBook Pro M3 Max with 36 GB? The ADR also records which client each slice uses: the installed Laravel AI SDK (`laravel/ai`), a hand-written HTTP client for the agent-loop lesson, or another library (Prism in the 2026-11-02 reference design). Candidates from that design: Ollama with qwen2.5:7b and nomic-embed-text, sized for a 16 GB machine. Decides: tech-research ADR. Blocks: SL-22 (and through it every AI slice). Source: widened by intake 2026-10-30 and 2026-11-02. — **Answered** (Stanislav Markov, 2026-11-02): [ADR 0001](docs/adr/0001-local-model-server-models-php-client.md) — Ollama on the host, laravel/ai `ollama` driver (Ollama `/v1` only for SL-34), qwen3.5:9b chat/tools/vision, gemma4:12b grader, bge-m3 embeddings, SigLIP2 sidecar for photos.
- [x] **Q-02** Is a result valid only when RPS varies less than 5 % across the 3 repeats? — **Answered** (Stanislav Markov, 2026-10-30): yes; spread = (max − min) / median of the 3 runs' RPS. Above 5 %, rerun the scenario once; still above, record the median marked `noisy` with its spread. Blocks: nothing now.
- [x] **Q-03** Embedding vector size follows the embedding model chosen in Q-01. Decides: Q-01 ADR. Blocks: SL-24. — **Answered** (ADR 0001, 2026-11-02): bge-m3, `vector(1024)`, cosine, normalised; photo vectors separate, `vector(768)` (SigLIP2).
- [x] **Q-04** Without cloud providers, what is the failover target: a second local server (e.g. LM Studio behind Ollama) or none? Decides: Stanislav Markov. Blocks: SL-28. **Answered 2026-11-05: a second, smaller model in the same Ollama** (fall back when the first model times out or errors); no second server.
- [ ] **Q-05** Hostnames: keep DDEV project name `interview` (the old interview URL) or rename to `autopulse`? Decides: Stanislav Markov. Blocks: none (default: keep `interview`).
- [x] **Q-06** Which car photo set do the photo slices use? The seed has only a few static hero images; similarity needs many real car photos whose licence allows local use. Decides: Stanislav Markov (external). Blocks: SL-36, SL-37, SL-38, SL-39. **Answered 2026-11-05: the Stanford Cars dataset**, downloaded locally and gitignored (research use; the licence terms are checked and recorded when SL-36 is specced).
- [ ] **Q-07** Vector search in pgvector only, or also compare Elasticsearch `dense_vector` kNN on the same data? Decides: Stanislav Markov. Blocks: none (default: pgvector only).
