# Manual test plan: log channel overhead (SL-60)

Base URL: https://ai-lab.ddev.site

## Preconditions

- `ddev start`; the working tree is clean (`git status --short` shows nothing outside `.ai/session`).
- The bench switches the server to `frankenphp-worker` and edits `.env` (`LOG_STACK`) with a backup; it restores both on exit, even on Ctrl-C.
- It takes about 10 minutes (two variants × warm-up 30 s + 3 × 60 s).
- Rollback: revert the feature commit; delete the new result folder if the run is discarded.

## Step 1

Profile record (AC-1).

1. Open `.ai/specs/spec-20261121-1905-log-channel-overhead/decisions.md`.

Expected: a table with the per-entry cost of each part (processor, normalize, redact, attribute flattening, `frankenphp_log`) before and after, and the method used.

## Step 2

Overhead (AC-2, NFR-1, NFR-2). This is the originally reported miss: +8 % on 2026-11-17.

```sh
bench/log-overhead.sh
jq '{off: .channel_off.p95_ms, on: .channel_on.p95_ms, difference_percent, within_threshold}' <the path printed after "Wrote">
jq '.channel_off.p95_ms' bench/results/restart/2026-11-17/log-overhead.json
```

Expected:
- The script prints `p95 off … ms, on … ms, difference … %` and `Wrote …/log-overhead.json`.
- `within_threshold` is `true` (difference < 5). If it is `false`, `decisions.md` and BENCHMARKS.md state the miss and the share taken by `frankenphp_log()`.
- The new channel-off p95 lies within 8.55–9.28 ms (the 2026-11-17 repeats), or the difference is explained.
- `git status` shows only the new result file (the script restored `.env`, the server and `laravel.log`).

## Step 3

Docs and nothing else changed (AC-4, MNC-4).

1. Open https://ai-lab.ddev.site/ai/guides/logs-restart#numbers and https://ai-lab.ddev.site/ai/learn/running-the-server/logs-and-a-safe-worker-restart (the overhead step).
2. Read the channel overhead part of `BENCHMARKS.md`.
3. Run `bench/asset-fingerprint.sh` and `git diff --stat master -- composer.json composer.lock package.json package-lock.json database routes`.

Expected: guide and lesson show the new difference; BENCHMARKS lists 2026-11-17 and the new run side by side; fingerprint `app-DNob7CFE.css` / `app-PTzu1QaN.js`; the git diff prints nothing.
