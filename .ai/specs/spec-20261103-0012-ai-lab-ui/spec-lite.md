---
id: spec-20261103-0012-ai-lab-ui
title: AI lab UI with Inertia and Vue
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-02T22:18:22Z
created: 2026-11-03
stacks: [laravel]
slice: SL-46
brs: [BR-27, BR-90, BR-95]
---

# AI lab UI with Inertia and Vue

## Summary

A small reactive web UI at `/ai` (the "AI lab") where the developer uses every AI capability built so far, and sees what happens under the hood while it runs. Pages: an overview with recent `ai_runs`; the streamed car description with a stop button, a live event log, time to first token and tokens per second; semantic listing search with similarity bars; docs search with vector, full-text and hybrid results side by side; and placeholder pages for chat (SL-45, SL-41) and similar photos (SL-36). It is built with Inertia 3 and Vue 3 in TypeScript, on its own Vite entry, so the existing Blade pages and their built assets do not change.

## Context

- Requested by the owner on 2026-11-03: "For the future chat streaming and similar photos by AI I need some UI … you can use Inertia or similar … taste any design by your own." This approves the new frontend dependencies listed in Scope.
- Frontend today: Vite 8, Tailwind 4 (`@tailwindcss/vite`), `laravel-vite-plugin` 3 with Bunny fonts (Instrument Sans), entries `resources/css/app.css` and `resources/js/app.js`, used by `resources/views/welcome.blade.php` and `resources/views/components/layouts/app.blade.php` (the listing detail page). No Inertia, Livewire, Vue or React.
- `bench/parity.sh` hashes the bodies of `/up`, `/api/ping`, `/listings…`, `/listings/1` and `/listings/999999` on all six servers. `/listings/1` contains the hashed asset URLs of `app.css` and `app.js`, so any change to their built output changes parity. Tailwind 4 scans project files for class names automatically, so new Vue files with utility classes would change the built `app.css` unless excluded.
- Back-end endpoints the UI uses (shipped): `GET /listings/{id}/describe` (SSE: one `data: <event json>` per SDK event, then `data: [DONE]`; masked `error` event; rate limit `describe`), `GET /api/listings/semantic?q=` (SL-24), `GET /api/docs/search?q=&mode=&k=` (SL-32, being built), `ai_runs` (SL-20, SL-22, SL-24). Current provider and model come from config (BR-91).
- Rules from `product-brief.md`:
  - **BR-27** Every shipped slice adds its section to `docs/learning-guide.md`.
  - **BR-90** Every AI call is logged with provider, model, prompt version, tokens in and out, and latency.
  - **BR-95** The direct streaming variant sends the answer as server-sent events while it generates.

## Scope

- Dependencies (approved by the owner's request): `inertiajs/inertia-laravel` ^3, and npm `@inertiajs/vue3` ^3, `vue` ^3.5, `@vitejs/plugin-vue` ^6, `typescript`, `vue-tsc`.
- Inertia set up for the `/ai` route group only (its middleware is not added to the global `web` group); root view `resources/views/ai/app.blade.php`; Vite entries `resources/js/ai/app.ts` and `resources/css/ai.css`.
- Pages: `/ai` (overview), `/ai/describe`, `/ai/semantic`, `/ai/docs`, `/ai/chat` (placeholder), `/ai/photos` (placeholder).
- `Server-Timing` response header on the semantic and docs search endpoints (embedding and database time), shown in the UI.
- A design system for the lab (tokens in `ai.css`), described under Design.
- Learning guide section 13, "The AI lab UI: Inertia, Vue and streams in the browser".

## Out of scope

- The chat itself (SL-45 answering, SL-41 chat page) and similar photos (SL-36): placeholders only.
- Authentication, user accounts (non-goal), dark mode, internationalisation.
- Changing existing Blade pages, their CSS or JS, or the JSON shape of existing endpoints.
- Server-side rendering (Inertia SSR).

## Must NOT change

- MNC-1: after task 0 (below), the built `app.css` and `app.js` have the same file names and content before and after the lab is added, and they no longer change when markdown or other non-view files change; `bench/parity.sh compare` prints `identical` on all six servers against the snapshots re-baselined in task 0.
- MNC-2: existing endpoint bodies are unchanged; the only response change to existing endpoints is the added `Server-Timing` header on the two search endpoints.
- MNC-3: the PHP suite stays network-free and green; `bench/run.sh ping --quick` passes its run conditions.

## Acceptance criteria

- AC-1: WHEN the developer opens `/ai` THE SYSTEM SHALL render the Inertia page `Lab/Overview` with: the configured text provider and model, the embedding provider and model, whether Ollama answers (`/api/version` within 1 s; "unreachable" otherwise), one card per capability linking to its page (chat and photos marked "coming", with the slice that brings them), and the 20 newest `ai_runs` rows (agent, provider, model, tokens in and out, ttft_ms, latency_ms, status, time).
- AC-2 (BR-95): WHEN the developer picks a listing on `/ai/describe` and starts THE SYSTEM SHALL stream the description from `/listings/{id}/describe` into the page as it arrives, show a live event log (event type and arrival time in ms), the measured time to first visible token, tokens received, and tokens per second, and after `[DONE]` show the run's `ai_runs` row.
- AC-3: WHEN the developer presses Stop during a stream THE SYSTEM SHALL abort the request at once (the text stops growing and the page shows "stopped after N tokens"), and a new run can start immediately.
- AC-4: WHEN the stream ends with an `error` event, the server answers 429, or the network fails THE SYSTEM SHALL show a readable message in the page (the masked error text, "too many requests, try again in N s" from `Retry-After`, or "connection lost") without a console-only failure.
- AC-5: WHEN the developer searches on `/ai/semantic` THE SYSTEM SHALL show up to 10 listings with year, make, model, body type, price, city, a similarity bar and value, and the request's embedding time and database time read from the `Server-Timing` header; 422 and 503 answers show their message.
- AC-6: WHEN the developer searches on `/ai/docs` THE SYSTEM SHALL run the query in `vector`, `text` and `hybrid` modes in parallel and show the three ranked lists side by side (source, path, heading, snippet, score and ranks), highlighting chunks that appear in more than one list, with each list's timings from `Server-Timing`.
- AC-7: WHEN the developer opens `/ai/chat` or `/ai/photos` THE SYSTEM SHALL show a placeholder that names what will come (SL-45/SL-41: streamed answers with citations; SL-36: similar photos, blocked by Q-06) and links to the backlog entry and the learning guide.
- AC-8 (BR-27): THE SYSTEM SHALL have guide section "13. The AI lab UI: Inertia, Vue and streams in the browser" explaining what Inertia does for one page visit (first load vs. later visits, props as JSON), why the lab has its own Vite entry (parity), how the browser reads an SSE stream with `fetch` and a `ReadableStream`, how Stop aborts it (`AbortController`) and what the server sees then, and what `Server-Timing` is, with links to the code, the spec, and the Inertia, Vue and MDN docs.
- AC-9: WHEN the lab pages are checked in a browser at 1440×900 and 390×844 THE SYSTEM SHALL have no horizontal scroll, no console errors, keyboard focus visible on every control, text contrast of at least 4.5:1, and streamed text announced politely to screen readers (`aria-live="polite"` on the output, not on the event log).

## Design

Direction: **a lab notebook with an instrument rail**. The point of the UI is to see the mechanism, so every capability page has two columns: the **work area** on the left (input and result) and the **under-the-hood rail** on the right (events, timings, scores, the logged run). On narrow screens the rail moves below the work area.

- **Palette:** paper `#F5F2EB` background, ink `#1A1916` text, hairline rules `#D8D2C4`, muted text `#6B665C`, one signal colour, orange `#C2410C` (about 5:1 on paper, white text on it about 5.2:1), used only for live states (streaming, the active stream's cursor, the current token count) and the primary button; success `#2B7A4B`, error `#B42318`. No gradients, no drop shadows, no cards inside cards; sections are separated by hairline rules and whitespace.
- **Type:** Instrument Sans (already loaded) for text; a monospace face (JetBrains Mono from Bunny fonts) for numbers, event logs, model names and code. Numbers use tabular figures. Sizes: 13 / 15 / 18 / 28 px; headings in the sans at 600.
- **Layout:** a slim top bar (lab name, current text model and embedding model as mono "tags", Ollama status dot) and a left navigation of the six pages; content max width 1200 px; the rail is 360 px wide on desktop.
- **Motion:** only where it explains something: the streamed text grows token by token, each new event row fades in once; everything respects `prefers-reduced-motion`.
- **Components:** button (primary orange, secondary outline), text input, segmented control (docs modes), metric (label + mono value + unit), event log row, similarity bar (a 4 px bar from 0 to 1 with the value beside it), status dot, tag. Defined once in `resources/js/ai/components/`, styled with Tailwind utilities on the tokens from `ai.css` (`@theme`).

## Boundaries

- Always: keep the old entries untouched; keep Inertia and its middleware inside the `/ai` group; type-check the Vue code.
- Ask first: adding UI dependencies beyond the list in Scope (component libraries, icon packs, state managers); changing an existing endpoint's body.
- Never: put model or provider names in the frontend code (they come from props); call Ollama from the browser; commit `public/build`.

## Technical context

- `resources/css/app.css` gets `@source not` lines for `../js/ai` and `../views/ai` (and the new `ai.css` uses `@import 'tailwindcss' source(none);` with `@source` for `../js/ai` and `../views/ai`). The test for MNC-1 builds before and after and compares the two manifest entries and file bytes.
- Streams: a composable `useEventStream(url)` reads `fetch(url, { signal, headers: { Accept: 'text/event-stream' } })` with `response.body.getReader()` and a `TextDecoder`, splits on blank lines, parses `data:` lines as JSON, stops at `[DONE]`; returns reactive state (text, events, status, ttftMs, tokens, tokensPerSecond, error) and `stop()`.
- The describe page finds the run's `ai_runs` row by `invocation_id` (`GET /ai/runs/{invocationId}`, see Clarifications).
- `Server-Timing` values come from the timings the search services already measure (`embed;dur=…`, `db;dur=…`).
- Tests: Pest feature tests with Inertia assertions (`assertInertia`) for each page's component and props, the `Server-Timing` header, the latest-run endpoint; `npm run build` and `vue-tsc --noEmit` as gates; browser checks for AC-2 to AC-6 and AC-9 in the manual test (Playwright MCP is available to the build for screenshots).

## Clarifications (clarity gate, 2026-11-03)

Resolved under standing approval (self-decided). Facts checked in the repo and `vendor/laravel/ai`.

- **Found while answering the gate: the old CSS was never reproducible.** `app.css` uses Tailwind's automatic source detection, which scans every text file in the project, including markdown docs and specs. A clean `npm run build` on 2026-11-03 before any SL-46 change produced `app-DKQ1Qe75.css` instead of the deployed `app-guEwgz2K.css` (built 2026-10-28), so `/listings/1` parity already differed after any rebuild. **Task 0** fixes this before the lab: `app.css` imports Tailwind with `source(none)` and lists its sources explicitly (`../views/**/*.blade.php` except `../views/ai`, `../js/app.js`, and the two existing `@source` lines); the build is then re-run, and `bench/parity/*.txt` is re-captured on all six servers in one commit that explains the change. MNC-1 is checked against that new baseline.
- **Tests are Pest** (Pest 5.3 is installed, `tests/Pest.php`); **PHPStan with Larastan** is installed. The project CLAUDE.md (PHPUnit, MariaDB, SQLite) is out of date.
- **Token** = one SSE `text_delta` event with non-empty `delta`. Time to first token = from calling `fetch` to the first such event. Tokens/s = tokens ÷ seconds since the first token, updated live. All event-log times are ms since calling `fetch`.
- **Event shapes** (SDK events as sent by `SseProtocol`, SL-20): every line is `data: <json>` with a `type`; text arrives as `{"type":"text_delta","delta":"…",…}`; also `stream_start` (with `invocation_id`, `provider`, `model`), `text_start`, `text_end`, `reasoning_*` when thinking is on, `stream_end` (with `usage`); a failure is `{"type":"error","message":"The description could not be generated."}`; the stream ends with `data: [DONE]`. The TypeScript types cover these and keep unknown types as a generic event.
- **Stop on the server side.** The browser closes the connection; PHP notices on its next write and stops (`ignore_user_abort` is off), so the run usually leaves no `ai_runs` row (accepted in SL-20). After Stop the page says "stopped after N tokens; the server may not have logged this run" and shows no run row. The guide explains it; the manual test counts rows before and after a Stop.
- **Run row lookup.** Not by time: the page takes `invocation_id` from the `stream_start` event and calls `GET /ai/runs/{invocationId}` (JSON: invocation_id, agent, provider, model, prompt_version, tokens_in, tokens_out, ttft_ms, latency_ms, status, error_message, created_at in ISO 8601 UTC). It retries up to 5 times 300 ms apart on 404 (the row is written as the stream ends), then shows "run not logged yet". No agent names in the frontend.
- **Access.** Like the rest of the app, the lab is a local learning tool with no accounts (non-goal); no auth. The describe endpoint keeps its rate limit.
- **Shared props.** `HandleInertiaRequests` (in the `/ai` group, `rootView` `ai.app`) shares `lab.textProvider`, `lab.textModel`, `lab.embeddingProvider`, `lab.embeddingModel` (from `ai.default` / `ai.providers.<default>.models.text.default` and `ai.default_for_embeddings` / `ai.providers.<that>.models.embeddings.default`) and `lab.ollama`: `reachable` from `GET {ai.providers.ollama.url}/api/version` with a 1 s timeout, cached 10 s, or `not-used` when neither provider uses the `ollama` driver.
- **Capabilities:** five cards (describe, semantic, docs, chat, photos); the overview itself is not a card.
- **recentRuns:** `created_at` ISO 8601 UTC, shown as relative time with the exact time on hover; null values shown as `—`; status `completed` or `failed`.
- **Listing picker:** the 10 lowest listing ids, titled `{year} {make} {model}, {city}`, plus a free id field; a 404 at stream start shows "no such listing"; any other non-200 shows "server error (status)".
- **Semantic results** (SL-24 contract): up to 10, `similarity` = 1 − cosine distance (4 decimals); the bar clamps to 0–1; price shown from `price_cents` and `currency` with `Intl.NumberFormat('en', { style: 'currency' })`.
- **Docs page** (SL-32 contract, built first): fields `score`, `vector_rank`, `vector_similarity`, `text_rank`, `text_score`; chunks matched across columns by `id`; `k` = 6; 422 and 503 shown in the affected column. SL-46 is built only after SL-32 ships.
- **Server-Timing.** The search services return their measured durations with the results (a small result object); controllers write `Server-Timing: embed;dur=<ms>, db;dur=<ms>` with one decimal, ms. Docs `text` mode sends `db` only; `hybrid` and `vector` send both (`db` covers both legs in hybrid). Bodies are compared with the existing feature tests' expectations (fake embeddings) before and after.
- **Fingerprint.** `bench/asset-fingerprint.sh` (in scope) prints the manifest file names and sha256 of the `app.css` and `app.js` builds; its output after task 0 is recorded in the build report and in BENCHMARKS.md's parity section. The PHP test checks the root view's `@vite` call names `resources/css/ai.css` and `resources/js/ai/app.ts`, and that `/listings/1` contains no `/build/assets/ai` link. If the shared Vite config still changes the old outputs, a separate `vite.ai.config.ts` with its own build directory is allowed.
- **Links in placeholders** show repo paths (`backlog.md#sl-45`, `docs/learning-guide.md`) as monospace text, since the repo has no web host; the guide uses relative links.
- **Guide.** Sections 1–12 exist once SL-32 ships; the status line (`**Status (…):**` at the top) and `## Planned sections` are updated as before. Doc test URLs: https://inertiajs.com, https://vuejs.org, https://developer.mozilla.org/en-US/docs/Web/API/ReadableStream, https://developer.mozilla.org/en-US/docs/Web/API/AbortController, https://developer.mozilla.org/en-US/docs/Web/HTTP/Reference/Headers/Server-Timing.
- **Versions** (checked 2026-11-03): `inertiajs/inertia-laravel` 3.7, `@inertiajs/vue3` 3.11, `vue` 3.5, `@vitejs/plugin-vue` 6.0 (peer Vite ^8), `vue-tsc` 3.3, `typescript` ~6.0 (TypeScript 7 is the native rewrite whose API `vue-tsc` does not use; fall back to 5.9 if needed). `tsconfig.json` with `strict: true`.
- **Wiring:** pages in `resources/js/ai/Pages/Lab/*.vue` resolved with `import.meta.glob`; asset version from the Vite manifest (Inertia default); URLs passed as props from `route()` on the server (no Ziggy).
- **Describe page state:** Start disabled while streaming (Stop enabled); event log keeps the last 500 events and shows the total count.
- **429:** Laravel's throttle sends `Retry-After`; without it the page says "try again in a minute". Default limit 20 per minute (`AI_DESCRIBE_PER_MINUTE`).
- **Manual test** adds a run with `AI_PROVIDER=fake-stream` (200 words over 10 s) and a check of the describe page on `frankenphp-octane`; with Ollama stopped the stream is a 200 with the masked `error` event (verified in SL-22), and any non-200 is shown by status.
- **AC-9 method:** the build checks every page with the Playwright browser (screenshots at both sizes, console messages captured, keyboard tab order); contrast is checked from the palette values (all text pairs are listed with their ratios in the build report); the screen-reader check is manual.
- **Other servers:** the describe stream on all six servers is already covered by `bench/sse.sh` (SL-20, SL-22); the lab adds a browser check on one worker server.

## Decisions

1. Inertia 3 + Vue 3 + TypeScript (the owner suggested Inertia; Vue for its small single-file components and clear reactivity; TypeScript so the event types are explicit) (self-decided, standing approval).
2. A separate Vite entry and `@source not` exclusions so the existing assets stay byte-identical (parity) (self-decided, standing approval).
3. Read SSE with `fetch` and a stream reader rather than `EventSource`, so Stop can abort through `AbortController` and the page can read status codes such as 429 (self-decided, standing approval).
4. Design: lab notebook with an instrument rail (owner: "taste any design by your own") (self-decided).
- JSON errors keep their HTTP exception's headers (fix pass, self-decided under standing approval): 429s on describe, semantic search and docs search now carry `Retry-After` and `X-RateLimit-*`, 405s carry `Allow`. This widens MNC-2 beyond `Server-Timing`, on error responses only; bodies are unchanged. Kept because the describe page needs `Retry-After` to say how long to wait, and these headers are what HTTP expects. Pinned by `tests/Feature/JsonErrorHeadersTest.php`.

## Open questions

- [x] Assumption: BR-27, BR-90 and BR-95 are unvalidated draft rules (owner Stanislav Markov). — **Accepted assumption** (Stanislav Markov, standing approval 2026-11-01).
- [x] Clarity gate (28 items) — all answered in Clarifications (self-decided, standing approval), including the newly found non-reproducible CSS build (task 0).

## Dependencies

- SL-20, SL-22, SL-24 (shipped); SL-32 (in build; the docs page needs its endpoint).
