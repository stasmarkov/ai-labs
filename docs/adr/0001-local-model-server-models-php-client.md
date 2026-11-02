# 0001. Local model server, open-weight models and PHP LLM client for AutoPulse

## Status

Accepted (Stanislav Markov, 2026-11-02). Models, sizes and licences re-checked on ollama.com and Hugging Face before sign-off (see Sources: Sign-off checks). Ollama binds to the default 127.0.0.1 first; `OLLAMA_HOST=0.0.0.0` only if the container cannot connect, and only after telling the owner.

## Date

2026-11-02

## Context

Question Q-01 (which also answers Q-03): which local model server, which open-weight models and which PHP LLM client should each AutoPulse slice use on a MacBook Pro M3 Max with 36 GB of unified memory?

Constraints:

- The app is Laravel 13 on PHP 8.4, running only inside DDEV (Docker via OrbStack). The model server therefore runs on the macOS host and is reached from the container through `host.docker.internal`.
- `laravel/ai` 1.3.0 and `laravel/mcp` 1.1.2 are already installed. PostgreSQL 16 with pgvector 0.8.7 is the database. Cloud LLM providers are a non-goal.
- This is a learning project. The goal is to understand each mechanism (model loading, quantisation, context length, tokens/s, tool loops, embeddings), not to run at production scale.
- `.ai/research/ai-chatbot-reference.md` proposes Ollama, qwen2.5:7b, nomic-embed-text and Prism, sized for 16 GB. Those are treated as candidates, not decisions.
- The app's published `config/ai.php` (SL-20 work in progress) defaults to `fake-stream` (`config/ai.php:15`) and defines only `fake-stream`, `fake-instant`, `anthropic` and `openai` providers. `laravel/ai` merges its package config shallowly (`vendor/laravel/ai/src/AiServiceProvider.php:39`, `mergeConfigFrom`), so the app's `providers` array replaces the vendor one, including its `ollama` entry (`vendor/laravel/ai/config/ai.php:124-128`, which defaults to `http://localhost:11434`). SL-22 must therefore add an `ollama` entry (and `ollama-v1` for SL-34) to the app config. Setting `OLLAMA_URL` alone does nothing.
- Phase note: the CLAUDE.md in the repo is stale on the database and test runner (it mentions MariaDB and PHPUnit). The repo actually runs Postgres 16 and Pest. See `.ddev/config.yaml` and `tests/`.

Slices that consume this decision:

| Slice | Need |
| --- | --- |
| SL-20 | streaming describe (fake provider first, real path later) |
| SL-22 | local wiring |
| SL-24 | pgvector embeddings of 50 000 listings |
| SL-32 | docs RAG chatbot (English plus some Ukrainian) |
| SL-33 | RAG eval with a grader model |
| SL-25, SL-34 | tool calling, including a hand-written agent loop |
| SL-35 | MCP |
| SL-27 | vision extraction from car photos |
| SL-36..39 | image similarity |
| SL-40 | semantic cache |

## Decision Drivers

Must:

1. Memory fit. The resident set must fit about 26 GB (36 GB minus about 10 GB for macOS, OrbStack and DDEV with Postgres). Metal wires only about 70-75% of unified memory to the GPU by default (`iogpu.wired_limit_mb`). SL-33 needs chat plus grader loaded, and SL-32 needs chat plus embedder.
2. Reachable from the DDEV container via `host.docker.internal`, with chat SSE streaming, tool calls and embeddings. SL-34 needs a plain OpenAI `/v1/chat/completions` surface.
3. Open-weight licence that permits local use, recorded per model.
4. Embedding dimension compatible with pgvector 0.8 indexing (HNSW and IVFFlat support at most 2000 dimensions for `vector`, 4000 for `halfvec`) and fixed in a schema-first `vector(N)` migration. Record N, the distance metric, normalisation and prefixes.

Should:

5. Reliable tool calling and JSON-schema structured output (SL-25, SL-27, SL-33, SL-34), ideally with server-side constrained decoding.
6. Supported by `laravel/ai` 1.3 without an extra dependency.
7. English embedding quality with acceptable Ukrainian.
8. A vision model that reads car photos into schema-valid JSON.
9. A local image encoder for photo and text-to-photo similarity.
10. Fewer moving parts (one server process, one config surface).
11. Network-free PHPUnit/Pest testing (fakes; `Http::fake` for the hand-written client).

Nice:

12. Teaching value: visible loading, quantisation, context and tokens/s; models swap by config only (BR-91).
13. Sourced tokens/s and time-to-first-token on M3 Max class hardware.

## Options Considered

### Option 1: Ollama on the host, laravel/ai `ollama` driver, Ollama `/v1` for the SL-34 loop, SigLIP2/open_clip sidecar

Score: 82.

Fit: best codebase fit. The native driver (`OllamaProvider`) implements both `TextProvider` and `EmbeddingProvider`, posts streams to `api/chat` and embeddings to `api/embed` with `dimensions`, sends the schema as the top-level `format`, hoists `keep_alive` and `think`, and maps base64 image attachments. No composer change; migration cost S.

Pros:

- No new dependency. Each slice is a provider and model line in config (BR-91).
- Structured output is constrained on the server (grammar-constrained decoding), which SL-23, SL-25, SL-27 and SL-33 rely on. Vision models accept the same `format`.
- Token usage (`prompt_eval_count`, `eval_count`) is always in the stream, so the `ai_runs` token columns (BR-90) work without extra config.
- Residency is explicit: `OLLAMA_MAX_LOADED_MODELS` (default 3 per GPU), `keep_alive` per request, `OLLAMA_KEEP_ALIVE` globally, and `ollama ps` showing context and GPU/CPU split. Chat, grader and embedder can stay resident together without llama-swap.
- One host process serves chat, vision and embeddings. `/v1` on the same process serves the SL-34 loop, so that slice still teaches the raw OpenAI wire format.
- Every response carries `total_duration`, `load_duration`, `prompt_eval_count`, `prompt_eval_duration`, `eval_count`, `eval_duration` in nanoseconds, so tokens/s is `eval_count / eval_duration * 1e9`.
- Ollama has an MLX backend on Apple Silicon (preview since 0.19). The same model can be compared as GGUF and `-mlx` tag by config only, which is a direct quantisation and runtime lesson.

Cons:

- Contradicts the SL-22 wording in `backlog.md:296` ("as an openai-compatible provider"), which must be amended.
- The Ollama driver rejects `RemoteImage`, so SL-27 and SL-39 must pass stored or local images. Photo storage does not exist yet.
- Less low-level control than llama.cpp (no raw GBNF, hidden chat templates).
- `/v1` does not support `tool_choice`, `n` or logprobs, rejects image URLs, and cannot set `num_ctx`. An agent loop that forces a tool call will not work there.
- Open issue ollama/ollama#18717 (0.35.x) reorders schema properties alphabetically on the native llama-server path. Grader schemas that put reasoning before verdict are affected; name keys so alphabetical order matches intent, or wait for the fix.
- No image embeddings (issues #5304, #7677 unresolved or closed as not planned), so a sidecar is still needed.

### Option 2: LM Studio headless (`lms server` / llmster) with the `openai-compatible` driver and a hand-written `/v1` client, plus sidecar

Score: 72.

Fit: fits well, migration cost S. One OpenAI wire format serves both the SDK and SL-34. The gateway sends `response_format` json_schema (strict), `image_url` data URIs and `tool_choice`.

Pros:

- Single `/v1` surface for chat, tools, embeddings, images.
- GGUF engine uses llama.cpp grammar sampling and the MLX engine uses Outlines, so decoding is constrained on the server.
- `lms load --estimate-only` prints a memory estimate, `lms ps`, `lms log stream --stats` show load state, formatted prompts and stats; the REST API returns `tokens_per_second` and `time_to_first_token`.
- Free for personal and work use since July 2025.

Cons:

- Without `stream_options.include_usage=true` in provider config, streamed usage is zero and BR-90 breaks.
- `defaultTextModel()` throws when `models.text.default` is missing, so every entry needs it.
- JIT loading with Auto-Evict keeps at most one JIT-loaded model, so chat plus grader would swap on every switch. The resident set must be preloaded with `lms load`.
- MLX models in 0.4.21-0.4.23 ignore the requested context length (issue #2318). MLX embedding models fail in some builds (issue #808), and a misclassified embedder can return wrong-dimension vectors (issue #2177).
- Tool support is "native" only for models whose template LM Studio parses; others get a generic prompt.
- Closed-source, GUI-first, installed outside the repo.

### Option 3: llama.cpp `llama-server` (per model or behind llama-swap) with the `openai-compatible` driver, plus sidecar

Score: 60.

Fit: same driver path as option 2, migration cost M.

Pros:

- Highest teaching value: GBNF and json_schema constrained decoding, `--jinja` chat templates, `--mmproj` vision, `/metrics`, `/props`, verbose timings.
- Full control over quantisation and context length.

Cons:

- One process per model, or an extra llama-swap layer, plus a separate `--embeddings` instance. The repo has no host process supervisor.
- `llama-server` ignores the `dimensions` parameter that `laravel/ai` sends.
- Same `stream_options` requirement as option 2.
- Most moving parts for one developer.

### Option 4: MLX-native servers (mlx-lm, mlx-vlm, a separate embeddings server) with the `openai-compatible` driver

Score: 40.

Fit: weakest, migration cost M-L.

Pros:

- Likely fastest runtime on Apple Silicon.
- Raw Python makes quantisation and loading visible.

Cons:

- mlx-lm has no constrained decoding while the gateway always sends strict `json_schema`, so schema-valid slices fall back to retries.
- Three or more processes and provider entries, with no supervisor.
- CLIP still needs a Python sidecar, so nothing is saved.
- The throughput gain is not needed here.

### Option 5 (client choice, orthogonal): Prism PHP instead of `laravel/ai`

Score: 25.

Fit: poor.

Pros:

- Mature fakes and a broad provider surface.
- Would show a second SDK design for comparison.

Cons:

- New composer dependency, which needs approval under CLAUDE.md and the SL-20 Boundaries. The reference research already says Prism is not needed.
- Bypasses SL-20's SDK-event `ai_runs` logging and SSE design.
- No gain for MCP: `laravel/mcp` 1.1.2 and `laravel/ai`'s `McpTool` are installed.
- Two client abstractions in one learning codebase.

## Decision

Adopt Option 1.

Server and wiring:

- Run Ollama on the macOS host as the only model server.
- Add an `ollama` provider entry to the app `config/ai.php`. The app's `providers` array replaces the vendor one, so the vendor entry is not available. Read its URL from `OLLAMA_URL` and its text and embedding models from env (BR-91). Then set `OLLAMA_URL=http://host.docker.internal:11434` and `AI_PROVIDER=ollama` in `.env`.
- Binding: OrbStack documents `host.docker.internal` as the way to reach a Mac server, and a reproduction in orbstack/orbstack#2728 shows containers reaching a server bound only to `127.0.0.1`, because OrbStack relays it. First try the default bind, which keeps Ollama off the LAN. Fall back to `OLLAMA_HOST=0.0.0.0:11434` (set via `launchctl setenv` for the macOS app, then restart) only if the container cannot connect. If set, host `ollama` CLI calls need `OLLAMA_HOST` too. OrbStack needs the macOS Local Network permission. Do not add `extra_hosts: host-gateway` on macOS.
- Pin `OLLAMA_CONTEXT_LENGTH` (for example 8192) and verify it in `ollama ps`. Ollama's default depends on memory tier (24-48 GiB gets 32k), and a measured Ollama 0.32 run of qwen3.5:9b at its 262 144-token default used 15 GB resident against 6.6 GB of weights. `OLLAMA_KV_CACHE_TYPE=q8_0` roughly halves KV cache but needs flash attention and applies to every model.

Client per slice:

- Use the installed `laravel/ai` 1.3 `ollama` driver for SL-20 (real path), SL-22, SL-24, SL-25, SL-27, SL-32, SL-33 and SL-40: native `/api/chat` streaming, top-level `format` JSON schema, `/api/embed` with `dimensions`, `keep_alive` and `think` pass-through, token counts read from the stream.
- For the hand-written SL-34 agent loop, add a second config entry (`ollama-v1`, openai-compatible) pointing at the same process's `/v1/chat/completions`, with `stream_options.include_usage=true`. Its client sits behind an `app/Contracts` interface, is implemented in `app/Ai/`, and is faked with `Http::fake`. The loop must not rely on `tool_choice`.
- Image similarity (SL-36..39) uses a small open_clip/SigLIP FastAPI sidecar behind its own contract.
- Prism is not added. MCP (SL-35) uses `laravel/mcp` and `McpTool`.
- Tests use the existing `FakeTextGateway`, `FakeEmbeddingGateway` and the fake-stream driver, so the suite stays network-free.

Model set (all candidates, to be confirmed before this ADR is accepted):

| Role | Candidate | Parameters / quant / size | Licence |
| --- | --- | --- | --- |
| Chat, tools, structured output | Qwen3.5-9B (`qwen3.5:9b`), with `think` disabled when not wanted. Fallbacks: Qwen2.5-7B, Qwen3-8B | 9B, Q4_K_M, 6.6 GB on disk; hybrid attention, only 8 of 32 layers use full attention, about 32 KiB f16 KV per token, roughly 256 MiB at 8k and 1 GiB at 32k (derived from `config.json`) | Apache-2.0 |
| Vision | Same Qwen3.5-9B (natively multimodal, tagged vision + tools + thinking), which lowers memory pressure. Alternatives: Qwen2.5-VL-7B, Gemma 4 12B | as above | Apache-2.0 (Gemma 4 is also Apache-2.0; Gemma 3 used the Gemma Terms) |
| Grader (SL-33) | Gemma 4 12B (`gemma4:12b`), a different family from the chat model | about 12B, Q4_K_M, 7.7-8.0 GB; image input and function calling | Apache-2.0 |
| Text embedder | bge-m3 (MIT, 1024-d, 8192 tokens, no prefix, about 1.2 GB) or `qwen3-embedding:0.6b` (Apache-2.0, 1024-d MRL, Q8_0, 639 MB, queries need `Instruct: {task}\nQuery:{q}`) | | as noted |
| Image encoder | `timm/ViT-B-16-SigLIP2-256` via open_clip (768-d, multilingual WebLI, 64-token text limit, Apache-2.0) | | Apache-2.0 |

Memory budget (weights only, derived from the sourced sizes): chat 6.6 GB plus grader about 8 GB plus embedder 0.6-1.2 GB is roughly 16 GB, plus a few GB of KV cache at 8k context, which stays under the 26 GB budget. A 26B MoE grader (16-19 GB) plus the 9B chat model would be too tight. Exact resident figures must be read from `ollama ps` on the machine.

Embeddings (Q-03):

- Fix `vector(1024)` for text, cosine distance (`vector_cosine_ops`, HNSW), and store normalised vectors. Ollama's `/api/embed` returns L2-normalised vectors, so cosine and inner product rank identically.
- bge-m3 needs no prefix. If `qwen3-embedding` is chosen, only queries get the instruction prefix and documents get none. This departs from the reference design's nomic-embed-text (768-d, English-first, `search_query:`/`search_document:` prefixes) because SL-32 contains Ukrainian.
- Assert the returned dimension in the embedding job so a wrong model fails loudly.
- Image vectors live in a separate column: `vector(768)` for SigLIP2 ViT-B (`vector(512)` if ViT-B-32 is used instead). SigLIP2 text input is lowercased, padded to 64 tokens, and scored with a sigmoid. Its multilingual training helps the Ukrainian text-to-photo query.

Sidecar location: a DDEV compose service is simple but CPU-only, since the Linux VM has no Metal or MPS. A host process gets MPS but adds a second host process. Decide at SL-36 (see open questions).

Rationale: Option 1 needs no new dependency, keeps SL-20's SDK events and SSE design, captures token usage by default, gives explicit residency controls, constrains structured output on the server and uses one host process. The must criteria do not separate the options (every server can be reached from the container, licence depends on the model, 1024-d fits under the HNSW limit); the should and nice criteria do.

Throughput expectation: llama.cpp's Apple Silicon thread lists the M3 Max 30-core at 56.58 t/s generation and 567.59 t/s prompt processing for LLaMA-7B Q4_0, and an M3 Ultra ran qwen3.5:9b Q4_K_M at 77.7 t/s on Ollama 0.32.13. Scaling by memory bandwidth (300 GB/s versus about 800 GB/s) suggests roughly 30-40 t/s here. That is an inference, not a measurement, but adequate for interactive streaming. Prompt processing of several hundred t/s keeps RAG time-to-first-token around 1-3 s for 1-2K-token contexts.

Required follow-up before acceptance: amend the SL-22 run text in `backlog.md:296` to say "native ollama driver; openai-compatible /v1 only for SL-34".

Confidence: medium. Architecture and client fit are well evidenced; model-level numbers (sizes, KV cost, tokens/s, BFCL and MTEB scores, Ukrainian quality) come from vendor and third-party sources and still need measuring on the target machine.

## Consequences

Positive:

- Zero new dependencies and one config surface; swapping any model is an env/config edit (BR-91).
- `ai_runs` token columns work without extra configuration.
- Chat, grader and embedder can be resident together, and `ollama ps` plus the response timing fields make loading, context and tokens/s visible.
- SL-34 still exercises the raw OpenAI wire format.
- A fixed `vector(1024)` text column and `vector(768)` image column are decided once, avoiding a re-embedding migration later.

Negative:

- Two Ollama entries in `config/ai.php` (native and `/v1`) and a backlog amendment.
- Photo storage must exist before SL-27 and SL-39 because the Ollama driver rejects `RemoteImage`.
- Ollama hides chat templates and raw GBNF, so some low-level lessons are lost compared with llama.cpp.
- The structured-output property-ordering bug (#18717) constrains how grader schemas are written.
- The image sidecar is an extra process regardless of the server choice.
- Changing the text embedder later means a new migration and re-embedding all 50 000 listings.

Runner-up and why not: Option 2, LM Studio headless with the `openai-compatible` driver. It is close, with one `/v1` wire format for both the SDK and SL-34 and an MLX engine. It loses on two required config traps (`stream_options.include_usage` and explicit `models.text.default` on every entry), JIT/Auto-Evict swapping unless models are preloaded, open MLX context and embedding bugs, and a GUI-first install outside the repo. If Ollama's structured output or vision proves unreliable for the chosen models, switching to LM Studio is a config-only change (BR-91), with the embedder on a GGUF build such as `ggml-org/bge-m3-Q8_0-GGUF`.

## Open Questions

1. Final model set and measured resident peak: Qwen3.5-9B versus Qwen2.5-7B/Qwen3-8B for chat, Gemma 4 12B versus another family for the grader, and whether one multimodal model should cover chat and vision. Measure at Q4_K_M with the chosen `num_ctx` and confirm it fits about 26 GB, possibly raising `iogpu.wired_limit_mb`.
2. Q-03 embedder: bge-m3 or `qwen3-embedding:0.6b`? Confirm Ukrainian retrieval quality with a small local test, and confirm normalised output from the installed Ollama build.
3. Image encoder: SigLIP2 ViT-B (768-d) or ViT-B-32 CLIP (512-d, English-only text tower)? Is query translation needed for Ukrainian text-to-photo queries if a multilingual model is not used?
4. Sidecar location: DDEV compose service (CPU only) or host process (MPS, outside DDEV supervision)?
5. Is the default `127.0.0.1` bind reachable through OrbStack on this machine? If not, is `OLLAMA_HOST=0.0.0.0` acceptable on a laptop that joins untrusted networks, or should a firewall rule be added?
6. Approve amending `backlog.md:296` (SL-22 wording)?
7. Q-04: keep an LM Studio `openai-compatible` entry configured as a standby, or defer it?
8. Which slice owns the photo storage migration that SL-27 and SL-39 depend on?
9. Does Ollama enforce the `format` schema for vision requests and together with tool calls in the same request? Assumed, not verified.
10. The claims that mlx-lm lacks constrained decoding and that llama-server ignores `dimensions` come from codebase notes, not web sources. Verify if either becomes the fallback.

## Verification Addendum (2026-11-02)

The workflow's structured verification output came back empty, so the load-bearing claims above were re-checked against primary sources.

Confirmed:

- `qwen3.5:9b`: 6.6-7.6 GB, 256K context, vision + tools + thinking (Ollama library). Apache-2.0, thinking on by default, disabled with `enable_thinking: false` (HF card). On Ollama, `think: false` on `/api/chat` disables it (Ollama thinking docs).
- `gemma4:12b`: 7.7-8.0 GB, 256K context, text + image (Ollama library). Apache-2.0, native tool use, text/image/audio (HF `google/gemma-4-12B-it`). Released 2026-06-03. The original Gemma 4 announcement lists only E2B/E4B/26B/31B, so cite the 12B card, not the announcement.
- bge-m3: 1024-d, MIT, 8192 tokens, no query instruction (HF card); 1.2 GB on Ollama.
- `qwen3-embedding:0.6b`: 639 MB, Apache-2.0, 32k context, MRL 32-1024 (HF card). Queries need an instruction; omitting it costs roughly 1-5% retrieval quality. Multilingual MTEB mean is 64.33.
- `/api/embed` returns L2-normalised vectors and accepts `dimensions` (Ollama embeddings and API docs).
- `format` accepts a JSON schema and works with vision models (Ollama structured-outputs docs). Tools and schema in the same vision request are still unverified (open question 9).
- Ollama `/v1/chat/completions` supports `stream_options.include_usage`, `tools` and `response_format`. Vision works with base64 images only, not image URLs (Ollama OpenAI-compatibility docs). The docs do not confirm the `json_schema` subtype of `response_format`, so SL-34 should use plain JSON mode or tool calls.
- Defaults (Ollama FAQ): 127.0.0.1:11434 bind, `OLLAMA_MAX_LOADED_MODELS` = 3, `OLLAMA_NUM_PARALLEL` = 1, `keep_alive` = 5 min. Requests queue when memory is short.
- orbstack/orbstack#2728 (open) does show a container reaching a 127.0.0.1-bound Mac server through `host.docker.internal`. The issue is about intermittent blackouts on unattached networks, so test the default bind before relying on it.
- SigLIP2 `ViT-B-16-SigLIP2-256`: open_clip `embed_dim` 768, text `context_length` 64, Apache-2.0, needs open-clip-torch >= 2.31.0. ViT-B-32 `embed_dim` is 512.

Corrections to the fallback models:

- The Ollama library pages for `gemma3` and `qwen2.5vl` show no `tools` capability, so neither can be the single chat + tools + vision model on Ollama. `qwen2.5` (7B, 4.7 GB, Apache-2.0) and `qwen3` (8B, 5.2 GB, Apache-2.0) are tool-tagged. This strengthens the choice of Qwen3.5-9B as the single multimodal model.
- Derived f16 KV cost per token (from `config.json`): Qwen2.5-7B is 56 KiB (28 layers × 4 KV heads × 128), about 448 MiB at 8k. Qwen3-8B is 144 KiB (36 × 8 × 128), about 1.1 GiB at 8k.

Codebase claims re-read in vendor (laravel/ai v1.3.0 and laravel/mcp v1.1.2 per `composer.lock:1152-1153, 7344-7345`):

- Chat and streaming post to `api/chat` (`vendor/laravel/ai/src/Gateway/Ollama/OllamaGateway.php:54, 86`). Embeddings post to `api/embed` with `'dimensions' => $dimensions ?: null` (`OllamaGateway.php:106, 111`).
- `tools` and a schema-built `format` can both go on the same body (`Gateway/Ollama/Concerns/BuildsTextRequests.php:73-83`). `format`, `keep_alive`, `think`, `logprobs` and `top_logprobs` are moved from provider options to the top level, and a schema-built `format` takes precedence (`BuildsTextRequests.php:94-101`).
- Token usage is read from `prompt_eval_count`/`eval_count` when streaming (`Gateway/Ollama/Concerns/HandlesTextStreaming.php:166`) and when not (`Gateway/Ollama/Concerns/ParsesTextResponses.php:88`).
- `RemoteImage` throws `InvalidArgumentException` (`Gateway/Ollama/Concerns/MapsAttachments.php:36`).
- The openai-compatible gateway sends `stream_options` only from provider config (`Gateway/OpenAiCompatible/OpenAiCompatibleGateway.php:150-153`, used at `Gateway/OpenAiCompatible/Concerns/PerformsChatCompletionSteps.php:61-62`).
- Correction found by this pass: the app `config/ai.php` has no `ollama` entry (see Context). `backlog.md:296` still says "as an openai-compatible provider".

Still unverified, to be measured on the machine: resident peak in `ollama ps`, tokens/s on the M3 Max, Ukrainian retrieval quality of the embedder and SigLIP2, and the default GPU wired-memory limit.

## Sources

- Ollama FAQ (bind address, `OLLAMA_MAX_LOADED_MODELS`, `OLLAMA_NUM_PARALLEL`, keep-alive, KV cache type): https://docs.ollama.com/faq
- OrbStack networking: https://docs.orbstack.dev/docker/network
- OrbStack issue 2728: https://github.com/orbstack/orbstack/issues/2728
- OrbStack discussion 102: https://github.com/orgs/orbstack/discussions/102
- Ollama context length: https://docs.ollama.com/context-length
- Ollama versus llama.cpp versus MLX on Mac (measured numbers): https://terminalbytes.com/ollama-vs-llama-cpp-vs-mlx-mac-2026/
- Qwen3.5-9B model card and config: https://huggingface.co/Qwen/Qwen3.5-9B and https://huggingface.co/Qwen/Qwen3.5-9B/raw/main/config.json
- Ollama qwen3.5 library: https://ollama.com/library/qwen3.5
- Gemma 4 announcement: https://blog.google/innovation-and-ai/technology/developers-tools/gemma-4/
- Ollama gemma4 library: https://ollama.com/library/gemma4
- Ollama structured outputs: https://docs.ollama.com/capabilities/structured-outputs
- Ollama issue 18717 (schema property order): https://github.com/ollama/ollama/issues/18717
- Pydantic AI Ollama model notes: https://pydantic.dev/docs/ai/api/models/ollama/
- Ollama OpenAI compatibility: https://docs.ollama.com/api/openai-compatibility
- Ollama streaming tool calls: https://ollama.com/blog/streaming-tool
- Ollama embeddings and `/api/embed`: https://docs.ollama.com/capabilities/embeddings and https://docs.ollama.com/api/embed
- Qwen3-Embedding-0.6B: https://huggingface.co/Qwen/Qwen3-Embedding-0.6B and https://ollama.com/library/qwen3-embedding/tags
- bge-m3: https://huggingface.co/BAAI/bge-m3 and https://huggingface.co/ggml-org/bge-m3-Q8_0-GGUF
- embeddinggemma-300m: https://huggingface.co/google/embeddinggemma-300m
- pgvector: https://github.com/pgvector/pgvector
- Ollama image-embedding issues: https://github.com/ollama/ollama/issues/5304, https://github.com/ollama/ollama/issues/7677, https://github.com/ollama/ollama/issues/16076
- SigLIP2 ViT-B-16-256 checkpoint and open_clip config: https://huggingface.co/timm/ViT-B-16-SigLIP2-256, https://huggingface.co/timm/ViT-B-16-SigLIP2, https://raw.githubusercontent.com/mlfoundations/open_clip/main/src/open_clip/model_configs/ViT-B-16-SigLIP2-256.json
- SigLIP 2 paper, blog and transformers docs: https://arxiv.org/abs/2502.14786, https://huggingface.co/blog/siglip2, https://huggingface.co/docs/transformers/model_doc/siglip2
- Docker on Apple Silicon has no GPU access: https://chariotsolutions.com/blog/post/apple-silicon-gpus-docker-and-ollama-pick-two/ and https://www.docker.com/blog/docker-model-runner-vllm-metal-macos/
- Apple M3 Max specifications: https://support.apple.com/en-us/117736
- llama.cpp Apple Silicon performance thread: https://github.com/ggml-org/llama.cpp/discussions/4167 (see also https://github.com/ggml-org/llama.cpp/discussions/2182)
- Ollama MLX backend: https://ollama.com/blog/mlx
- Ollama usage fields: https://docs.ollama.com/api/usage
- LM Studio OpenAI compatibility, structured output, tools: https://lmstudio.ai/docs/developer/openai-compat, https://lmstudio.ai/docs/developer/openai-compat/structured-output, https://lmstudio.ai/docs/developer/openai-compat/tools
- LM Studio headless, server start, load, log stream, TTL and Auto-Evict, REST endpoints: https://lmstudio.ai/docs/developer/core/headless, https://lmstudio.ai/docs/cli/server-start, https://lmstudio.ai/docs/cli/load, https://lmstudio.ai/docs/cli/log-stream, https://lmstudio.ai/docs/developer/core/ttl-and-auto-evict, https://lmstudio.ai/docs/developer/rest/endpoints
- LM Studio issues 2318, 2177, 808: https://github.com/lmstudio-ai/lmstudio-bug-tracker/issues/2318, https://github.com/lmstudio-ai/lmstudio-bug-tracker/issues/2177, https://github.com/lmstudio-ai/lmstudio-bug-tracker/issues/808
- LM Studio unified MLX engine: https://lmstudio.ai/blog/unified-mlx-engine
- LM Studio licence change: https://simonwillison.net/2025/Jul/8/lm-studio-is-free-for-use-at-work/
- Verification addendum: https://ollama.com/library/qwen3.5, https://ollama.com/library/gemma4, https://huggingface.co/google/gemma-4-12B-it, https://ai.google.dev/gemma/docs/releases, https://ollama.com/library/gemma3, https://ollama.com/library/qwen2.5vl, https://ollama.com/library/qwen2.5, https://ollama.com/library/qwen3, https://ollama.com/library/bge-m3, https://ollama.com/library/qwen3-embedding, https://huggingface.co/Qwen/Qwen3-8B, https://huggingface.co/Qwen/Qwen2.5-7B-Instruct/raw/main/config.json, https://huggingface.co/Qwen/Qwen3-8B/raw/main/config.json, https://docs.ollama.com/capabilities/thinking, https://raw.githubusercontent.com/mlfoundations/open_clip/main/src/open_clip/model_configs/ViT-B-32.json

### Sign-off checks (2026-11-02)

- https://ollama.com/library/qwen3.5 : `qwen3.5:9b` 6.6–7.6 GB, 256K context, text + image input; family badges vision, tools, thinking.
- https://huggingface.co/Qwen/Qwen3.5-9B/blob/main/LICENSE : Apache License 2.0.
- https://ollama.com/library/gemma4 : `gemma4:12b` 7.7–8.0 GB, 256K context, text + image input.
- https://opensource.googleblog.com/2026/03/gemma-4-expanding-the-gemmaverse-with-apache-20.html : Gemma 4 under Apache 2.0 (12B added 2026-06-03, same licence).
- https://ollama.com/library/bge-m3 : 1.2 GB, 567M parameters, 8K context, 100+ languages.
- https://huggingface.co/BAAI/bge-m3 : MIT, dense dimension 1024, no query or document prefix.
