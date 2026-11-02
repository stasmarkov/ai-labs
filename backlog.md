---
product: AutoPulse
brief: product-brief.md
---

# AutoPulse backlog

Build order: SL-01 → {SL-02, SL-03, SL-05} → SL-04 → SL-06 → SL-07 → SL-08 → SL-20 → SL-22 → SL-24 → SL-32 → {SL-33, SL-40, SL-41} → SL-09 → {SL-10, SL-11, SL-12} → SL-13 → SL-14 → SL-15 → {SL-16, SL-17} → {SL-23, SL-26, SL-27} → SL-25 → SL-28 → SL-42 → SL-34 → SL-35 → SL-36 → {SL-37, SL-38, SL-39} → SL-43 → SL-44

## Slices

### SL-01 · Walking skeleton: seeded marketplace on nginx-fpm

- **Outcome:** A visitor calls the ping and health endpoints, searches 50 000 seeded listings with filters and pagination, and opens a listing detail page with CSS, JS and a hero image, on nginx-fpm over PostgreSQL 16 and Redis, covered by Pest tests.
- **Entry point:** `GET /up`, `GET /api/ping`, `GET /listings`, `GET /listings/{id}`
- **Size:** M
- **BRs:** BR-01, BR-03, BR-04, BR-05, BR-10, BR-11
- **Depends on:** none
- **Blocked by:** none
- **Status:** shipped
- **Spec:** greenfield-build
- **Source:** brief
- **Run:** `/sdd "New app — AutoPulse, first slice SL-01: on nginx-fpm, GET /up and GET /api/ping return fixed JSON, GET /listings searches 50 000 seeded listings (500 dealers, fixed seed) with make/model/fuel/city/min year/max price filters and pagination, GET /listings/{id} renders a detail page with Vite CSS/JS and a hero image. Switch DDEV DB to PostgreSQL 16 + pgvector, Redis for cache/sessions, Pest instead of PHPUnit. Laravel 13. Conventions from CLAUDE.md and product-brief.md (BR-01, BR-03, BR-04, BR-05, BR-10, BR-11)."`

### SL-02 · Apache-fpm overlay, switching guide and parity check

- **Outcome:** The developer switches to apache-fpm with one documented command, and a parity script confirms ping, search and detail return identical status and body on both servers; versions are recorded in BENCHMARKS.md.
- **Entry point:** `.ddev/` overlay + README switching section + parity script
- **Size:** S
- **BRs:** BR-02, BR-25
- **Depends on:** SL-01
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261029-1013-apache-fpm-parity
- **Source:** brief (Phase 0)
- **Run:** `/sdd "SL-02: add an apache-fpm DDEV overlay, a README section on switching servers, a parity script comparing ping/search/detail responses across servers, and a versions table in BENCHMARKS.md; OPcache confirmed on (BR-02, BR-25). Rules in product-brief.md."`

### SL-03 · FrankenPHP classic overlay

- **Outcome:** The app runs unchanged on FrankenPHP classic mode via the DDEV add-on with thread-safe extensions, passing the parity check, with OPcache on and versions recorded.
- **Entry point:** `.ddev/` FrankenPHP overlay, Caddyfile
- **Size:** S
- **BRs:** BR-02, BR-25
- **Depends on:** SL-02
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261029-1221-frankenphp-classic
- **Source:** brief (Phase 0)
- **Run:** `/sdd "SL-03: add a FrankenPHP classic-mode DDEV overlay (ddev-frankenphp add-on, ZTS pgsql/redis extensions) that passes the parity check, with OPcache confirmed and versions recorded (BR-02, BR-25). Rules in product-brief.md."`

### SL-05 · OpenLiteSpeed overlay

- **Outcome:** The app runs unchanged on OpenLiteSpeed with LSAPI as an extra DDEV service, passing the parity check, with OPcache on and versions recorded.
- **Entry point:** `ddev server openlitespeed`, `.ddev/servers/openlitespeed/` (compose file, Dockerfile, OLS server and vhost config)
- **Size:** M
- **BRs:** BR-02, BR-25
- **Depends on:** SL-02
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261029-2027-openlitespeed
- **Source:** brief (Phase 0)
- **Run:** `/sdd "SL-05: run the app on OpenLiteSpeed + LSAPI (lsphp84 with pgsql/redis) as an extra DDEV service with a vhost on public/, passing the parity check, OPcache confirmed, versions recorded (BR-02, BR-25). Rules in product-brief.md."`

### SL-04 · FrankenPHP worker mode via Octane overlay

- **Outcome:** The app runs in worker mode both as an Octane daemon and via the add-on's worker directive, passing the parity check, with a README comparison of who owns config, restarts and logs, and which Octane features are server-specific.
- **Entry point:** `.ddev/config.octane.yaml`, add-on worker config
- **Size:** M
- **BRs:** BR-02, BR-25
- **Depends on:** SL-03
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261030-1437-octane-worker-mode
- **Source:** brief (Phase 0, Phase 2)
- **Run:** `/sdd "SL-04: run the app in FrankenPHP worker mode via Laravel Octane two ways (Octane daemon overlay; add-on worker directive), both passing the parity check, plus README notes comparing config/restart/log ownership and listing server-specific Octane features (BR-02, BR-25). Rules in product-brief.md."`

### SL-06 · Benchmark harness and process-vs-thread comparison

- **Outcome:** The developer runs one command per scenario that warms up, measures and repeats per the rules, and BENCHMARKS.md gets ping and search tables (RPS, latency percentiles, memory idle and at 50 users) for nginx-fpm, apache-fpm, OpenLiteSpeed and FrankenPHP classic, plus a recorded request-timeout comparison.
- **Entry point:** `bench/` scripts, BENCHMARKS.md
- **Size:** M
- **BRs:** BR-20, BR-21, BR-22, BR-23, BR-24, BR-26
- **Depends on:** SL-03, SL-05
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261030-1806-bench-harness
- **Source:** brief (Benchmarks, Phase 1)
- **Run:** `/sdd "SL-06: bench/ harness (k6 + oha) running outside the web container with 30 s warm-up, 60 s measure, 3 repeats, median; fill BENCHMARKS.md ping and search tables (fair + tuned) for nginx-fpm, apache-fpm, OLS, FrankenPHP classic with idle and 50-user memory, and a long-request timeout comparison (BR-20…BR-24, BR-26). Rules in product-brief.md."`

### SL-07 · Octane state-leak lab

- **Outcome:** The developer toggles four leak routes (request-bound singleton, static price cache, config locale mutation, request captured at boot) between leak and fixed modes, sees one visitor's data bleed into another's under Octane, and a worker-inspection endpoint shows the worker persisting; each leak has a test that fails in leak mode and passes when fixed.
- **Entry point:** leak-lab routes, `/debug/worker`
- **Size:** M
- **BRs:** BR-30, BR-31, BR-32
- **Depends on:** SL-04
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261101-1822-octane-leak-lab
- **Source:** brief (Phase 2)
- **Run:** `/sdd "SL-07: Octane state-leak lab — four routes with leak/fixed toggles (singleton vs scoped current dealer, static price-formatter cache, config locale mutation, service built with the request at boot) and /debug/worker (pid, app instance id, memory, request counter); Pest tests per leak; no leak on classic servers (BR-30, BR-31, BR-32). Rules in product-brief.md."`

### SL-08 · Classic vs worker benchmark

- **Outcome:** BENCHMARKS.md shows ping and search for FrankenPHP classic vs Octane worker with an explanation of why the bootstrap-bound endpoint gains more than the DB-bound one, plus memory growth across 10 000 requests with and without the static cache and the effect of max-requests.
- **Entry point:** `bench/` scenarios, BENCHMARKS.md
- **Size:** S
- **BRs:** BR-21, BR-24, BR-26
- **Depends on:** SL-06, SL-07
- **Blocked by:** none
- **Status:** shipped
- **Spec:** spec-20261101-2126-classic-vs-worker
- **Source:** brief (Phase 2)
- **Run:** `/sdd "SL-08: benchmark FrankenPHP classic vs Octane worker on ping and search, record memory growth over 10 000 requests with/without the static cache and with max-requests, and explain the gap in BENCHMARKS.md (BR-21, BR-24, BR-26). Rules in product-brief.md."`

### SL-09 · Slow I/O, thread autoscaling and healthz

- **Outcome:** Under 100 concurrent users on the slow listing endpoint, the developer records health-endpoint p95 and error rate for FPM (two max_children settings) and FrankenPHP (fixed vs autoscaled threads with a max wait time) as a chart in BENCHMARKS.md.
- **Entry point:** `GET /listings/{id}/slow`, stub VIN API, `GET /healthz`
- **Size:** M
- **BRs:** BR-40, BR-41, BR-26
- **Depends on:** SL-06
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 3)
- **Run:** `/sdd "SL-09: GET /listings/{id}/slow calling a 300 ms stub VIN API, a fast GET /healthz, and a bench scenario measuring healthz p95 and 502/504 rate under 100-user slow load for FPM (2 max_children settings) and FrankenPHP (fixed vs max_threads + max_wait_time) (BR-40, BR-41, BR-26). Rules in product-brief.md."`

### SL-10 · Early Hints and compression

- **Outcome:** The detail page sends preload links and, on FrankenPHP, a 103 response before the main work; FrankenPHP negotiates zstd/brotli/gzip; BENCHMARKS.md records LCP with and without hints and bytes per encoding.
- **Entry point:** `GET /listings/{id}`, Caddyfile
- **Size:** M
- **BRs:** BR-50, BR-52, BR-26
- **Depends on:** SL-06
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 4)
- **Run:** `/sdd "SL-10: detail page sends Vite preload Link headers and a guarded 103 Early Hint on FrankenPHP (identical final response elsewhere), Caddyfile encode zstd br gzip, and BENCHMARKS.md LCP with/without hints and bytes per encoding (BR-50, BR-52, BR-26). Rules in product-brief.md."`

### SL-11 · Large CSV export offloaded to the web server

- **Outcome:** A visitor downloads a ~50 MB CSV export served by the web server's file offload on FrankenPHP, nginx and Apache, and BENCHMARKS.md compares PHP memory and CPU against a PHP-streaming variant.
- **Entry point:** `GET /exports/{id}`
- **Size:** M
- **BRs:** BR-51, BR-26
- **Depends on:** SL-06
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 4)
- **Run:** `/sdd "SL-11: GET /exports/{id} generates a ~50 MB CSV to disk and serves it via X-Sendfile (FrankenPHP), X-Accel-Redirect (nginx) and mod_xsendfile (Apache), with a PHP-streaming variant for comparison of PHP memory/CPU (BR-51, BR-26). Rules in product-brief.md."`

### SL-12 · One HTTP/3 request

- **Outcome:** The developer runs FrankenPHP directly with UDP 443 exposed and serves one verified HTTP/3 request, recording h2 vs h3 latency on a throttled profile.
- **Entry point:** direct container run, `curl --http3`
- **Size:** S
- **BRs:** BR-26
- **Depends on:** SL-03
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 4)
- **Run:** `/sdd "SL-12: run FrankenPHP outside the DDEV router with UDP 443 exposed, verify one HTTP/3 request and Alt-Svc, and record h2 vs h3 latency on a throttled profile (BR-26). Rules in product-brief.md."`

### SL-13 · Live price updates via built-in Mercure

- **Outcome:** When a listing's price changes, every open detail page for it on FrankenPHP shows the new price without reload through the built-in Mercure hub, and private updates reach only authorized subscribers.
- **Entry point:** `/.well-known/mercure`, detail page
- **Size:** M
- **BRs:** BR-60, BR-61
- **Depends on:** SL-03
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 5)
- **Run:** `/sdd "SL-13: enable FrankenPHP's built-in Mercure hub, publish a per-listing update when a price changes, and update the detail page price live via EventSource, including a private-update variant with subscriber JWT topic claims (BR-60, BR-61). Rules in product-brief.md."`

### SL-14 · Same live updates via Reverb, and subscriber load test

- **Outcome:** The same live price update works on nginx-fpm via Reverb and Echo, and BENCHMARKS.md compares publish-to-receive latency, memory per 1 000 subscribers and moving parts for Mercure vs Reverb under 500 subscribers.
- **Entry point:** detail page on nginx-fpm, Reverb service, `bench/` subscriber script
- **Size:** M
- **BRs:** BR-60, BR-26
- **Depends on:** SL-13
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 5)
- **Run:** `/sdd "SL-14: live price updates on nginx-fpm via Laravel Reverb + Echo, and a 500-subscriber load test comparing publish-to-receive latency, memory per 1 000 subscribers and moving parts for Mercure vs Reverb (BR-60, BR-26). Rules in product-brief.md."`

### SL-15 · Metrics dashboard

- **Outcome:** Grafana shows FrankenPHP thread/worker metrics (busy vs total threads, busy workers, queue depth, durations) next to FPM pool metrics, and the slow-I/O scenarios are re-run with screenshots in BENCHMARKS.md.
- **Entry point:** Prometheus + Grafana DDEV services, dashboard
- **Size:** M
- **BRs:** BR-26
- **Depends on:** SL-09
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 6)
- **Run:** `/sdd "SL-15: Prometheus + Grafana as DDEV services scraping FrankenPHP/Caddy metrics and php-fpm_exporter, a dashboard (RPS, p95, busy vs total threads, busy workers, queue depth, memory), and SL-09 scenarios re-run with screenshots (BR-26). Rules in product-brief.md."`

### SL-16 · Structured logs and worker restart

- **Outcome:** Logs written from PHP appear as structured JSON with request fields in the server log stream, and one Artisan command restarts workers through the admin API; a restart under load drops zero requests, compared with Octane's reload.
- **Entry point:** log channel, `frankenphp:workers:restart` command
- **Size:** S
- **BRs:** BR-70, BR-71, BR-26
- **Depends on:** SL-15
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 6)
- **Run:** `/sdd "SL-16: a Monolog handler using frankenphp_log() with stderr-JSON fallback, and an Artisan command restarting FrankenPHP workers via the Caddy admin API, verified to drop zero requests under load and compared with octane:reload (BR-70, BR-71, BR-26). Rules in product-brief.md."`

### SL-17 · Worker-mode developer experience

- **Outcome:** In worker mode, a code edit takes effect in under 2 s via watch or hot reload, and an Xdebug breakpoint hits inside an Octane request; edit-to-reload times for classic, Octane without/with watch, and hot reload are recorded.
- **Entry point:** worker watch / hot reload config, Xdebug
- **Size:** S
- **BRs:** BR-26
- **Depends on:** SL-04
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 7)
- **Run:** `/sdd "SL-17: worker watch patterns and FrankenPHP hot reload, Xdebug on the Octane overlay, and recorded edit-to-reload times for classic, Octane without watch, Octane with watch, and hot reload (BR-26). Rules in product-brief.md."`

### SL-18 · VIN validation in Go (optional)

- **Outcome:** A custom FrankenPHP build provides a Go VIN check-digit function used by a validation rule with a PHP fallback that gives identical results, and BENCHMARKS.md records a 1M-validation micro-benchmark (Go, PHP, PHP+JIT) and request-level impact.
- **Entry point:** custom FrankenPHP binary, VIN validation rule
- **Size:** M
- **BRs:** BR-80, BR-26
- **Depends on:** SL-06
- **Blocked by:** none
- **Status:** dropped
- **Spec:** —
- **Source:** brief (Phase 8); dropped 2026-11-01 (Stanislav Markov)
- **Run:** `/sdd "SL-18: a Go extension autopulse_vin_valid built into a custom FrankenPHP via xcaddy, used by a Laravel validation rule with a pure-PHP fallback giving identical results, with micro (1M, Go vs PHP vs JIT) and request-level benchmarks (BR-80, BR-26). Rules in product-brief.md."`

### SL-19 · App as a single binary (optional)

- **Outcome:** The app runs from one embedded FrankenPHP binary on a machine with no PHP, and BENCHMARKS.md compares its size and cold start with a Docker image.
- **Entry point:** `./autopulse php-server`
- **Size:** M
- **BRs:** BR-26
- **Depends on:** SL-03
- **Blocked by:** none
- **Status:** dropped
- **Spec:** —
- **Source:** brief (Phase 9); dropped 2026-11-01 (Stanislav Markov)
- **Run:** `/sdd "SL-19: build AutoPulse as an embedded FrankenPHP binary, run it on a clean container without PHP, and compare size and cold start with a Docker image (BR-26). Rules in product-brief.md."`

### SL-20 · Streamed car description with a fake provider

- **Outcome:** A visitor opens a listing's "describe" endpoint and receives a description streamed as server-sent events from an AI agent; a fake streaming provider (a token every 50 ms for 10 s) serves tests and load runs, and every run is logged.
- **Entry point:** `GET /listings/{id}/describe`
- **Size:** M
- **BRs:** BR-90, BR-91, BR-95
- **Depends on:** SL-01
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 10)
- **Run:** `/sdd "SL-20: Laravel AI SDK agent that describes a car from its specs, streamed as SSE from GET /listings/{id}/describe, a fake streaming provider (token every 50 ms for 10 s) for tests and load, and an AI-run log (provider, model, prompt version, tokens, latency) (BR-90, BR-91, BR-95). Rules in product-brief.md."`

### SL-21 · Queued AI streaming via Mercure, and AI load test (optional)

- **Outcome:** The describe request can instead return immediately while a queued job pushes chunks to a Mercure topic the page subscribes to, and BENCHMARKS.md records search p95, completed streams and errors under 200 concurrent describes for nginx-fpm, FrankenPHP classic (autoscaled), Octane, and FrankenPHP + Mercure.
- **Entry point:** describe endpoint (queued variant), `bench/` AI scenario
- **Size:** M
- **BRs:** BR-95, BR-26
- **Depends on:** SL-20, SL-13, SL-09
- **Blocked by:** none
- **Status:** dropped
- **Spec:** —
- **Source:** brief (Phase 10); dropped 2026-11-01 (Stanislav Markov)
- **Run:** `/sdd "SL-21: queued describe variant that returns immediately and streams chunks to a per-request Mercure topic, plus a 200-concurrent-describe load test measuring search p95, completed streams and errors on nginx-fpm, FrankenPHP classic, Octane and FrankenPHP + Mercure (BR-95, BR-26). Rules in product-brief.md."`

### SL-22 · Local model wiring

- **Outcome:** The describe agent runs against the chosen local text model on the Mac host, reachable from DDEV, with provider and model switched only via `.env`, and both streaming variants work on it.
- **Entry point:** AI provider config, `GET /listings/{id}/describe`
- **Size:** S
- **BRs:** BR-91
- **Depends on:** SL-20
- **Blocked by:** Q-01, decision "Which local models (text, vision, embeddings) and local server fit an M3 Max 36 GB?"
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11), user 2026-10-28 (Hugging Face models, M3 Max 36 GB)
- **Run:** `/sdd "SL-22: wire the chosen local model server (per the Q-01 ADR) as an openai-compatible provider reachable from DDEV via host.docker.internal; the describe agent and both streaming variants run on it, switched only via .env (BR-91). Rules in product-brief.md."`

### SL-23 · Natural-language search to filters

- **Outcome:** A visitor types a free-text query ("diesel estate under 20k in Lviv") and gets listing results from filters the local model extracts, validated by the same rules as normal search.
- **Entry point:** NL search endpoint → `/listings` filters
- **Size:** S
- **BRs:** BR-92, BR-90
- **Depends on:** SL-22
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11)
- **Run:** `/sdd "SL-23: NL search — a structured-output agent turns free text into the /listings filters, validated by the same rules (422 on invalid), never producing queries (BR-92, BR-90). Rules in product-brief.md."`

### SL-24 · Semantic search over listing embeddings

- **Outcome:** A visitor searches by meaning ("family car with a big boot") and gets the 10 most similar listings from embeddings of all listings; embedding throughput and index size are recorded.
- **Entry point:** semantic search endpoint, embedding job
- **Size:** M
- **BRs:** BR-90
- **Depends on:** SL-22
- **Blocked by:** Q-03
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11)
- **Run:** `/sdd "SL-24: pgvector semantic search — queued batch embedding of all 50 000 listing descriptions with the local embedding model, HNSW index, top-10 similarity search, recording embeddings/s, total time and index size (BR-90). Rules in product-brief.md."`

### SL-25 · Tool-calling buyer assistant

- **Outcome:** A visitor asks the buyer assistant a question and it answers using a listing-search tool within at most 4 tool steps; the tool-call success rate of the local model is recorded.
- **Entry point:** assistant endpoint
- **Size:** S
- **BRs:** BR-94, BR-90
- **Depends on:** SL-23
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11)
- **Run:** `/sdd "SL-25: buyer-assistant agent with a SearchListings tool (schema = NL-search filters), max 4 steps and tool-call repair, recording tool-call success rate on the local model (BR-94, BR-90). Rules in product-brief.md."`

### SL-26 · Moderation classifier and eval set

- **Outcome:** The developer runs a moderation classifier over 100 hand-labelled listings (including scam-like ones) and gets risk scores with reasons plus an accuracy score for the local model.
- **Entry point:** moderation classifier, eval command
- **Size:** M
- **BRs:** BR-93, BR-90
- **Depends on:** SL-22
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11)
- **Run:** `/sdd "SL-26: moderation classifier with structured output {risk 0..1, reasons[]}, a 100-listing hand-labelled eval set including scam-like listings, and an eval command reporting accuracy and JSON-valid rate (BR-93, BR-90). Rules in product-brief.md."`

### SL-27 · Car-photo vision extraction

- **Outcome:** The developer runs a queued batch that sends every stored car photo to the local vision model and stores, per photo, a caption, tags, dominant colours, body type, visible damage and people count as schema-validated structured data, retrying when the model returns invalid JSON.
- **Entry point:** vision extraction command or endpoint
- **Size:** M
- **BRs:** BR-90, BR-27
- **Depends on:** SL-22
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11), user 2026-10-28 (images), widened by intake 2026-10-30 (auto-tagging pipeline)
- **Run:** `/sdd "SL-27: queued batch sends each stored car photo to the local vision model and stores a schema-validated {caption, tags, dominant colours, body type, visible damage, people count}, retrying on invalid JSON (BR-90, BR-27). Rules in product-brief.md."`

### SL-28 · Local LLM comparison and limits

- **Outcome:** BENCHMARKS.md has a per-model table (tokens/s, TTFT, JSON-valid %, eval accuracy, RAM), latency at 1/4/16 parallel describes, a context-truncation check, failover behaviour, and search p95 per server mode while local AI requests run; an optional tiny-model smoke test group exists.
- **Entry point:** `bench/` AI scenarios, smoke test group
- **Size:** M
- **BRs:** BR-91, BR-26
- **Depends on:** SL-23, SL-24, SL-25, SL-26, SL-27
- **Blocked by:** Q-04
- **Status:** approved
- **Spec:** —
- **Source:** brief (Phase 11)
- **Run:** `/sdd "SL-28: compare local models (tokens/s, TTFT, JSON-valid %, eval accuracy, RAM), concurrency at 1/4/16, context truncation, failover, and search p95 per server mode under local AI load; add an optional local-llm smoke test group on a tiny model (BR-91, BR-26). Rules in product-brief.md."`

### SL-29 · Uniform JSON errors and cache-safe production run

- **Outcome:** A client calling search or detail with bad input and no Accept header always gets the same 422/404/500 JSON envelope, and the app serves all SL-01 endpoints unchanged with config, route and view caches built and APP_DEBUG=false.
- **Entry point:** `GET /listings` (422), `GET /listings/{id}` (404), `ddev artisan optimize`
- **Size:** S
- **BRs:** BR-03, BR-04, BR-20
- **Depends on:** SL-01
- **Blocked by:** none
- **Status:** dropped
- **Spec:** —
- **Source:** greenfield-build; dropped 2026-11-01 (Stanislav Markov)
- **Run:** `/sdd "SL-29: JsonErrorRenderer envelope for 422/404/500 with and without Accept, no stack traces with APP_DEBUG=false, and Pest checks that config:cache, route:cache and view:cache succeed with identical responses (BR-03, BR-04, BR-20). Rules in product-brief.md."`

### SL-30 · Search index proof on the 50k seed

- **Outcome:** The developer runs one command that prints EXPLAIN for the default, make, make+model and city searches on the 50 000-row seed and confirms each uses its index and meets the p95 targets (search < 100 ms, ping < 20 ms), recorded in docs/schemas/listings.md.
- **Entry point:** `GET /listings` plus an EXPLAIN check command
- **Size:** S
- **BRs:** BR-03, BR-05
- **Depends on:** SL-01
- **Blocked by:** none
- **Status:** dropped
- **Spec:** —
- **Source:** greenfield-build; dropped 2026-11-01 (Stanislav Markov)
- **Run:** `/sdd "SL-30: EXPLAIN-backed index verification for the newest-first, make, make+model and city searches on the seeded data, p95 targets recorded in docs/schemas/listings.md, and a Pest guard on query count per request (BR-03, BR-05). Rules in product-brief.md."`

### SL-31 · Dealer inventory listing

- **Outcome:** A visitor opens a dealer's inventory and sees that dealer's listings newest first with pagination, using the same envelope, DTOs and per_page rules as search.
- **Entry point:** `GET /dealers/{id}/listings`
- **Size:** S
- **BRs:** BR-03, BR-04, BR-10
- **Depends on:** SL-01
- **Blocked by:** none
- **Status:** dropped
- **Spec:** —
- **Source:** greenfield-build; dropped 2026-11-01 (Stanislav Markov)
- **Run:** `/sdd "SL-31: GET /dealers/{id}/listings through DealerInventoryController, service and repository using listings_dealer_id_idx, 404 for unknown dealer ids, per_page 1-50 default 20 (BR-03, BR-04, BR-10). Rules in product-brief.md."`

### SL-32 · Docs chatbot with RAG and citations

- **Outcome:** The developer asks questions about this repo's docs (README, BENCHMARKS.md, specs) and the Laravel 13 docs, and gets answers from the local model that cite the chunks they came from, or "I don't know" when nothing relevant is retrieved; prompt-injection attempts in the question or in a document do not change the bot's rules.
- **Entry point:** docs chat endpoint, `docs:index` command
- **Size:** L (split into indexing and answering if the spec passes 12 tasks)
- **BRs:** BR-98, BR-100, BR-90, BR-27
- **Notes:** structure-aware chunks (300–500 tokens, 50–80 overlap, headings → paragraphs → sentences, tables and code never split, "document › section" prefix, per-document hash for idempotent re-indexing); vector-only retrieval first, then hybrid vector + full text fused with RRF, compared by SL-33; chunks passed as marked data with their source; embedding model and its query/document prefixes stored with each document; ingest runs as queued jobs on a dedicated low-concurrency queue; the trace row adds retrieved chunk ids and cache hit. Reference design: `.ai/research/ai-chatbot-reference.md` (owner input 2026-11-02).
- **Depends on:** SL-22, SL-24
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-32: docs chatbot — queued ingest of this repo's markdown docs and the Laravel 13 docs into structure-aware chunks embedded with the local model into pgvector, vector then hybrid (vector + full text, RRF) top-k retrieval, answers with citations, "I don't know" below the relevance threshold, retrieved text marked as data plus an input/output guard (BR-98, BR-100, BR-90, BR-27). Rules in product-brief.md; design in .ai/research/ai-chatbot-reference.md."`

### SL-33 · RAG evaluation harness

- **Outcome:** The developer runs one command over 30 hand-written question/answer pairs (plus, optionally, the rag-mini-wikipedia set) and gets retrieval recall@k first, then correctness and groundedness scores graded by a second model call, so chunker settings, top-k, vector vs hybrid retrieval and model can be compared.
- **Notes:** recall@k needs no model (does the expected answer appear in the top-k chunks?) and is re-run after every chunker or embedding change. Reference design: `.ai/research/ai-chatbot-reference.md` (owner input 2026-11-02).
- **Entry point:** `rag:eval` command
- **Size:** S
- **BRs:** BR-26, BR-27
- **Depends on:** SL-32
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-33: RAG eval harness — 30 labelled Q/A pairs, an Artisan command that reports retrieval recall@k, then runs them through the docs chatbot and grades correctness and groundedness with a second model call, results table per chunk size / k / vector-vs-hybrid / model (BR-26, BR-27). Rules in product-brief.md."`

### SL-34 · Admin assistant with a hand-written agent loop

- **Outcome:** The developer chats with an assistant that calls listing tools (search, get, update title, add tag) through an agent loop written by hand, confirms every write before it runs, and sees each step logged.
- **Notes:** each tool checks what it may do in PHP before it runs (never in the prompt) and returns a capped result (row limit, long fields truncated). Reference design: `.ai/research/ai-chatbot-reference.md` (owner input 2026-11-02).
- **Entry point:** admin assistant endpoint
- **Size:** M
- **BRs:** BR-97, BR-94, BR-90, BR-27
- **Depends on:** SL-25
- **Blocked by:** none
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-34: admin assistant with a hand-written tool-calling loop over the local model's OpenAI-compatible API — tools searchListings, getListing, updateTitle, addTag, countByTag; confirmation before writes, BR-94 step limit, every step logged (BR-97, BR-94, BR-90, BR-27). Rules in product-brief.md."`

### SL-35 · MCP server for the listing tools

- **Outcome:** The developer connects Claude Code or Claude Desktop to a Laravel MCP server and uses the same listing tools as the admin assistant from that client.
- **Notes:** built on the already-installed `laravel/mcp`; one server per data domain, the server only groups and describes tools. Reference design: `.ai/research/ai-chatbot-reference.md` (owner input 2026-11-02).
- **Entry point:** MCP server (local transport)
- **Size:** S
- **BRs:** BR-97, BR-27
- **Depends on:** SL-34
- **Blocked by:** none
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-35: expose the admin assistant's listing tools as an MCP server in Laravel and use them from Claude Code, with writes still confirmed (BR-97, BR-27). Rules in product-brief.md."`

### SL-36 · Similar car photos block

- **Outcome:** A visitor on a listing page sees up to 8 visually similar photos of other listings next to the hero image, found by nearest-neighbour search over image embeddings from a local image encoder.
- **Entry point:** `GET /listings/{id}` (block), photo embedding job
- **Size:** M
- **BRs:** BR-96, BR-90, BR-27
- **Depends on:** SL-22, SL-27
- **Blocked by:** Q-06
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-36: similar car photos — embed every photo with a local CLIP/SigLIP image encoder (sidecar service), store vectors in pgvector, show up to 8 nearest photos of other listings next to the hero image, threshold and near-duplicate cutoff, cached in Redis (BR-96, BR-90, BR-27). Rules in product-brief.md."`

### SL-37 · Search car photos by text

- **Outcome:** The developer types a description ("red estate with a roof box") and gets the best-matching car photos, using the same image encoder's text side.
- **Entry point:** photo search endpoint
- **Size:** S
- **BRs:** BR-90, BR-27
- **Depends on:** SL-36
- **Blocked by:** Q-06
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-37: text-to-photo search using the CLIP/SigLIP text encoder against the SL-36 photo vectors (BR-90, BR-27). Rules in product-brief.md."`

### SL-38 · Hybrid image and text similarity

- **Outcome:** The similar-photos block can combine image similarity with similarity of the listing titles or SL-27 captions, with configurable weights, and the developer compares the two result sets on a few listings.
- **Entry point:** `GET /listings/{id}` (block)
- **Size:** S
- **BRs:** BR-96, BR-27
- **Depends on:** SL-36, SL-27
- **Blocked by:** Q-06
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-38: hybrid similarity — add a text vector (title or SL-27 caption), combine with the image vector by weights (default 0.7/0.3), compare against image-only results (BR-96, BR-27). Rules in product-brief.md."`

### SL-39 · Duplicate and quality detector

- **Outcome:** The developer opens a review page that groups near-duplicate photos by image embedding and shows which photo of each group the vision model judges best, with the others marked for removal.
- **Entry point:** photo review page, clustering command
- **Size:** M
- **BRs:** BR-90, BR-27
- **Depends on:** SL-36, SL-27
- **Blocked by:** Q-06
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-39: cluster near-duplicate photos by image vector and ask the local vision model which photo per cluster is best; review page "keep this one, remove N" (BR-90, BR-27). Rules in product-brief.md."`

### SL-40 · Semantic cache for the chatbot

- **Outcome:** When the developer asks the docs chatbot the same or a nearly identical question, the cached answer comes back at once, marked as cached, and hit rate and saved latency are recorded.
- **Notes:** exact cache first (hash of normalised question + prompt version), semantic cache second; the threshold is calibrated on paraphrase and near-miss pairs for the chosen embedding model, not copied. Reference design: `.ai/research/ai-chatbot-reference.md` (owner input 2026-11-02).
- **Entry point:** docs chat endpoint
- **Size:** S
- **BRs:** BR-99, BR-90, BR-27
- **Depends on:** SL-32
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-40: semantic cache in front of the docs chatbot — embed the question, return a stored answer above a similarity threshold, mark it cached, record hit rate and latency saved (BR-99, BR-90, BR-27). Rules in product-brief.md."`

### SL-41 · Streaming chat UI with stop

- **Outcome:** The developer chats with the docs chatbot in a page that shows the answer token by token and has a stop button that cancels generation on the server.
- **Notes:** direct SSE (BR-95); the reference design's queued job + Reverb stream is the dropped queued variant and stays out unless the owner reopens that non-goal.
- **Entry point:** docs chat page
- **Size:** S
- **BRs:** BR-95, BR-27
- **Depends on:** SL-32, SL-20
- **Blocked by:** none
- **Status:** approved
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-41: chat page streaming the docs chatbot's tokens over SSE into a Livewire/Alpine component, with a stop button that cancels generation server-side (BR-95, BR-27). Rules in product-brief.md."`

### SL-42 · Multi-step research agent

- **Outcome:** The developer gives a topic and the agent plans sub-questions, searches the docs index, keeps notes, and writes a report; every step is stored and can be replayed.
- **Entry point:** `agent:research` command
- **Size:** M
- **BRs:** BR-90, BR-27
- **Depends on:** SL-32
- **Blocked by:** none
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-42: research agent — plan sub-questions, search the SL-32 docs index, keep notes in Redis, write a report; store and replay every step (BR-90, BR-27). Rules in product-brief.md."`

### SL-43 · Prompt playground with versions

- **Outcome:** The developer saves versioned prompts, runs one input against two or three local models side by side, and sees output, tokens and latency for each run.
- **Entry point:** playground page
- **Size:** M
- **BRs:** BR-90, BR-91, BR-27
- **Depends on:** SL-22
- **Blocked by:** none
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (AI integration ideas, Stanislav Markov)
- **Run:** `/sdd "SL-43: prompt playground — versioned prompts, the same input run across 2–3 local models side by side, output, tokens and latency logged per run (BR-90, BR-91, BR-27). Rules in product-brief.md."`

### SL-44 · Living learning guide

- **Outcome:** `docs/learning-guide.md` exists from SL-06 on and grows with every slice (BR-27); this final slice checks every link, fills gaps, orders the sections in learning order and adds a closing summary. The guide covers the server part (PHP execution models, worker mode and state leaks, threads and slow I/O, HTTP features, real-time, observability, DX) and the AI part (every AI slice SL-20, SL-22…SL-28, SL-32…SL-43 and its concepts), each with a short explanation, a walkthrough of one request or job, links to the code and specs, BENCHMARKS.md numbers and official docs.
- **Entry point:** `docs/learning-guide.md`
- **Size:** S
- **BRs:** BR-27
- **Depends on:** SL-43
- **Blocked by:** none
- **Status:** proposed
- **Spec:** —
- **Source:** intake 2026-10-30 (Stanislav Markov: "a guide with links that teaches me how everything we implemented works", after all planned slices)
- **Run:** `/sdd "SL-44: finish docs/learning-guide.md — check every link, fill missing sections, put sections in learning order, add a closing summary (BR-27). Rules in product-brief.md."`

## Intake log

| Date | Source | Item | Class | Went to |
|------|--------|------|-------|---------|
| 2026-10-28 | roadmap doc (Stanislav Markov) | Phases 0–11 | New slices | SL-01…SL-28 |
| 2026-10-28 | elicitation | "Local LLM from Hugging Face on M3 Max 36 GB, text + images" | Question | Q-01, SL-22, SL-27 |
| 2026-10-28 | elicitation | No cloud LLM comparison | Non-goal | brief Non-goals |
| 2026-10-30 | AI ideas chat (Stanislav Markov) | Similar car photos block (CLIP/SigLIP, vector kNN) | New slice | SL-36, BR-96 |
| 2026-10-30 | AI ideas chat | Search photos by text (CLIP text encoder) | New slice | SL-37 |
| 2026-10-30 | AI ideas chat | Hybrid image + text similarity | New slice | SL-38 |
| 2026-10-30 | AI ideas chat | Auto-tagging and captioning pipeline | Duplicate | SL-27 widened (batch, caption, tags, retry on invalid JSON) |
| 2026-10-30 | AI ideas chat | Duplicate and quality detector | New slice | SL-39 |
| 2026-10-30 | AI ideas chat | Docs chatbot with RAG and citations | New slice | SL-32, BR-98 |
| 2026-10-30 | AI ideas chat | RAG evaluation harness | New slice | SL-33 |
| 2026-10-30 | AI ideas chat | Admin assistant with tool calling | New slice | SL-34, BR-97 (SL-25 stays the read-only buyer assistant) |
| 2026-10-30 | AI ideas chat | MCP server in Laravel | New slice | SL-35 |
| 2026-10-30 | AI ideas chat | Multi-step research agent | New slice | SL-42 |
| 2026-10-30 | AI ideas chat | Semantic cache | New slice | SL-40, BR-99 |
| 2026-10-30 | AI ideas chat | Streaming chat UI with stop | New slice | SL-41 |
| 2026-10-30 | AI ideas chat | Prompt playground with versioning | New slice | SL-43 |
| 2026-10-30 | AI ideas chat | Single LLM client abstraction | Duplicate | BR-91 (Laravel AI SDK); hand-writing a thin client to learn the HTTP calls stays an option inside SL-34 |
| 2026-10-30 | AI ideas chat | Every slice explains how its mechanism works | BR change | BR-27 (cross-cutting) |
| 2026-10-30 | AI ideas chat | Which car photos; pgvector vs Elasticsearch; image encoder choice | Question | Q-06, Q-07, Q-01 widened |
| 2026-10-30 | Stanislav Markov | Final guide with links teaching how everything implemented works | New slice | SL-44 (last; built from the BR-27 notes) |
| 2026-10-30 | Stanislav Markov | Guide must explain all AI features built | New slice (scope) | SL-44 outcome lists every AI slice and its concepts |
| 2026-11-01 | Stanislav Markov | Drop optional/non-learning slices | Scope change | SL-18, SL-19, SL-21, SL-29, SL-30, SL-31 dropped; BR-80 retired |
| 2026-11-01 | Stanislav Markov | Start the learning guide now and grow it per slice | BR change | BR-27 widened; SL-44 becomes the final polish (S) |
| 2026-11-02 | Stanislav Markov, "Laravel LLM chatbot" design doc | Chunking, hybrid search, MCP tools, guards, exact + semantic cache, tracing, eval data | Detail for existing slices | SL-32, SL-33, SL-34, SL-35, SL-40, SL-41 notes; `.ai/research/ai-chatbot-reference.md` |
| 2026-11-02 | same | Input/output guard against prompt injection | BR change | BR-100 (draft); cited by SL-32 |
| 2026-11-02 | same | Prism client, Ollama + qwen2.5:7b + nomic-embed-text | Question | Q-01 widened (client per slice, candidates) |
| 2026-11-02 | same | Tenants, per-tool policies, queued job + Reverb streaming | Non-goal | logged only: no accounts; queued streaming dropped 2026-11-01 |
| 2026-11-02 | Stanislav Markov | Build the docs chatbot sooner | Reorder | SL-20 → SL-22 → SL-24 → SL-32 → {SL-33, SL-40, SL-41} moved right after SL-08, before the server track; SL-32, SL-33, SL-40, SL-41 approved |
