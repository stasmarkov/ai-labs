# Security audit summary (the last areas not audited)

## Run
- Status: incomplete (`run_artifact_write_failed`, as in the three runs before: no REPORT.md or summary from the report agent; ledger and findings validated)
- Profile: standard, max units 20, scoped to 22 paths behind the 13 areas left by `security-audit-20261124-follow-up.md`
- Source ref: a45560b
- Run dir: `.ai/security-audit/run-5` (ignored by git)
- Summary written by the owner session from `coverage-ledger.json` and `findings.json`

## Coverage
- Units: covered 14, candidate 1, deferred 25 (max units cap; most are areas already covered by earlier runs that this ledger listed again), out of scope 3
- Findings: 0 confirmed, 1 needs validation, 0 rejected

## Lead and outcome
- **sse-streams-unbounded-concurrency-against-fixed-worker-pool** (accepted as a known limit, not fixed here): anonymous `POST /api/docs/ask` and `POST /api/assistant/ask` hold a PHP worker for the length of a model stream. The only controls are per-IP per-minute limiters (docs-ask 10/min, assistant-ask 20/min); there is no concurrency cap, and worker mode runs 4 PHP threads, so 4 open streams from one client fill the pool. The bound per stream is the provider timeout (Ollama 120 s) and `max_tokens`. This is the measured SL-28 result (search p95 on worker mode 64 to 2724 ms with 4 AI streams, `BENCHMARKS.md`) and is taught in the guides and the "One model, many users" lesson. A fix is a feature: a per-IP and global concurrency cap on streamed AI routes, answered with 429 while full. Proposed as its own slice.

## Covered without findings (14)
Exported Caddy ports versus the app boundary, the FrankenPHP build Dockerfile sources, CI workflow inputs and `pull_request` privileges, the docs answer output guard (indirect injection), the Xdebug trigger reachability, NL search CSRF and pre-auth work, the VIN stub slow route, listing reads (query amplification), docs answer retention and cache poisoning, research queue starvation, and the guide page (DOM XSS).

## Not audited this time
JSON-body CSRF on docs ask (`prepareForValidation`), clickjacking headers on lab pages, MCP admin tool schema versus dispatcher, the photo-extract queue backlog, research run retention and rendering, docs answer context extraction, cloud quota scope in `NamesProvider`, the worker debug flag, and per-IP limiter keys under the Octane topology (no `trusted_proxies` there). The other deferred units repeat areas covered in earlier runs.
