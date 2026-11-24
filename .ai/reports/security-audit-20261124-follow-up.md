# Security audit summary (follow-up on the areas not audited)

## Run
- Status: incomplete (`run_artifact_write_failed`, as in the two runs before: no REPORT.md or summary from the report agent; ledger and findings validated)
- Profile: standard, max units 40, scoped to 28 paths behind the 36 areas left by `security-audit-20261123-whole-repo.md`
- Source ref: 824b05d
- Run dir: `.ai/security-audit/run-4` (ignored by git)
- Summary written by the owner session from `coverage-ledger.json` and `findings.json`

## Coverage
- Units: covered 30, candidate 3, blocked 1, deferred 13 (max units cap), out of scope 9
- Findings: 0 confirmed, 2 needs validation (both validated by hand and fixed), 1 rejected

## Leads and outcome
- **limitcloudai-nonatomic-check-then-increment** (fixed): `LimitCloudAi` read the cloud budget count, then incremented it in a second step, so concurrent requests could all pass a check made before any of them counted and overshoot the per-IP and global cloud caps. Fix: each budget is taken with one atomic increment and the result checked; a refused request gives back what it took. Tests in `tests/Feature/CloudRateLimitTest.php`: "counts a request before checking it, so concurrent requests cannot all pass a stale check" (simulates the stale read; red before, green after) and "a refused cloud request gives back what it reserved".
- **queue-timeout-exceeds-retry-after-research-job** (fixed): the default queue is `database` with `retry_after` 480 s, while `ExecuteResearchRunJob` times out after `ai.research.max_seconds` + 60 = 660 s. A run longer than 480 s would be reclaimed by a second worker while the first still runs; with `tries=1` the second pickup marks the running run failed. Fix: `DB_QUEUE_RETRY_AFTER` default 720 s. Test `tests/Unit/QueueRetryAfterTest.php` keeps `retry_after` above every job timeout, the research job's config-derived one included (red before, green after).
- **research-runs-recent-exposes-uuid-capability** (rejected by the verifiers): the recent research runs list exposes run ids, but the research spec (C-14) makes every run visible to anyone who can open the local lab by design; the app has no identity layer.

## Covered without findings (30)
Setup script temp files, Caddy admin and metrics port reachability, Mercure topics and subscriber JWTs, Mercure key command, local MCP config, docs fetch command (injection, unpinned inputs), photo import files, guide snippet resolver, log handler as a reader, MCP get-listing (indirect injection) and propose-change (agency), rate limiter keys and forwarded headers, AI service provider wiring, settings save and its route access, docs answer cache keys, Support classes (injection), composer sources, provider config, session cookie flags, worker recycle and request loop (starvation, stale state), event-stream composable (browser storage), research loop caps, cross-site guard groups (CSRF), health route, local-only route group precedence, `/ai/changes/{id}/confirm` binding. One unit (Services business logic) was blocked.

## Not audited (13, max units cap)
JSON-body CSRF on the docs ask route, pre-auth work imbalance on NL search limiters, docs answer cache poisoning on store, Xdebug trigger reachability, clickjacking headers on lab pages, MCP admin tool schema vs dispatcher, guide renderer output, VIN stub fetch, CI workflow permissions, long-held model streams against the 4 worker threads, anonymous enqueue backlog on the ai-vision queue, retention of anonymous research runs and cache entries, and query amplification on unthrottled listing reads.
