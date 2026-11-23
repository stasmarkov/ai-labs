# Security audit summary (whole repo)

## Run
- Status: incomplete (`run_artifact_write_failed`: the report agent did not write REPORT.md, FINDINGS-DETAIL.md or this summary; the ledger and findings validated)
- Profile: standard, whole repo, max units 40
- Source ref: e017a65 plus the playground cleanup (no code change)
- Run dir: `.ai/security-audit/run-3` (ignored by git)
- Summary written by the owner session from `coverage-ledger.json` and `findings.json`

## Coverage
- Units: covered 27, candidate 1, deferred 36 (max units cap), out of scope 0
- Findings: 0 confirmed, 1 needs validation, 0 rejected; partial pass

## Lead and outcome
- **playground-get-reads-cross-origin-readable** (needs validation by the workflow, validated by hand): the playground read routes `GET /api/playground/prompts`, `/prompts/{id}`, `/runs`, `/runs/{id}` (local environment only) sat outside `RefuseCrossSiteRequests`, and `api/*` answers CORS with `Access-Control-Allow-Origin: *`, so a script on any site the developer opened could read the stored system prompts, inputs and outputs, subject to the browser's private-network rules.
  - Checked against the dev site: `curl -H 'Origin: https://evil.example' https://ai-lab.ddev.site/api/playground/runs` answered 200 with `access-control-allow-origin: *`.
  - Fix: `RefuseCrossSiteRequests` now wraps the whole `api/playground` group (reads and writes); cross-site reads answer 403, same-site reads 200. Test: `tests/Feature/PlaygroundEndpointTest.php` "refuses a cross-site read of the stored prompts and runs with 403, and still answers same-site reads" (red before, green after).

## Covered without findings (27)
Admin assistant (agency, write confirmation binding, conversation store keys, write applier), MCP server identity and `/ai/changes` pages, assistant tools and prompt roles, docs answer indirect injection, NL search tool schema, moderation logic, describe output rendering, listing and search injection, exports file handling, playground run quota, debug routes (gate, sleep starvation), guide file loading, research page rendering, run page as a log reader, request id header injection, Inertia shared props (secrets), provider input access control, exception rendering, Grafana reachability, duplicate-photo keep logic.

## Not audited (36, max units cap)
Among them: docs answer cache poisoning, JSON-body CSRF on docs and assistant asks, cloud quota accounting, trusted proxies, search query amplification, research run reads and job loop, guide renderer XSS, clickjacking headers, Xdebug trigger reachability, Mercure tokens and keys, MCP admin tool wrapper arguments, settings writes, worker context bleed, session cookie flags, outbound URLs (VIN stub, docs fetch), photo import files, bench facts in guides, Docker image pinning, CI permissions, dependency sources, local MCP config, setup script temp files, browser storage, log handler as a reader, vendor packages.
