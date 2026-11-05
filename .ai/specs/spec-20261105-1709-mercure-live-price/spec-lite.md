---
id: spec-20261105-1709-mercure-live-price
title: Live price updates through FrankenPHP's built-in Mercure hub
type: feature
size: M
priority: P2
status: in-progress
ticket:
stakeholders: [Stanislav Markov]
approved_by: Stas Markov (standing approval)
approved_at: 2026-11-05T15:59:08Z
created: 2026-11-05
stacks: [laravel]
slice: SL-13
brs: [BR-60, BR-61, BR-26, BR-27]
---

# Live price updates through FrankenPHP's built-in Mercure hub

## Summary

The Mercure hub built into FrankenPHP's Caddy is turned on. When a listing's price changes, the app publishes a public update to that listing's topic. Every open detail page for the listing is subscribed through `EventSource` and shows the new price without a reload (BR-60). A private variant publishes to a private topic, and only a subscriber holding a JWT whose `mercure.subscribe` claim names that listing's topic receives it (BR-61).

A bench then opens 100 and 1000 subscribers on the hub while the SL-09 health probe runs. It records:
- the `/healthz` p95;
- how many FrankenPHP PHP threads are busy;
- the publish-to-receive latency through the DDEV router.

This shows in numbers that hub subscribers are held by Caddy's Go code, not by PHP workers, unlike a direct SSE answer (SL-09: 30 open answers starved 8 FPM children).

## Context

- **Checked on 2026-11-05:**
  - **The hub module exists.** FrankenPHP's build has the Mercure Caddy modules (`http.handlers.mercure.local`, `.bolt`), and the frankenphp stash Caddyfile has no `mercure` directive yet.
  - **No PHP publish function.** There is no `mercure_publish()`, so publishing is an HTTP POST to the hub with a publisher JWT.
  - **The client side.** The listing detail page uses `resources/js/app.js`, which is empty (0 bytes; the asset fingerprint `app-BvRk9kiK.js` is its empty build).
  - **Where keys can live.** Caddy reads process environment variables, not Laravel's `.env`. DDEV passes variables from an untracked `.ddev/.env.web` file into the web container.
- **Parity.** The detail page body must stay byte-identical on all 6 servers. The subscription markup and script are therefore the same everywhere. On servers without a hub, the script gives up after the first failed connection instead of retrying.

## Scope

### In scope

- **The hub.** The `mercure` directive goes in the frankenphp stash Caddyfile (`.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp`), in the `:80` site block that the router reaches. Its settings:
  - `publisher_jwt {env.MERCURE_PUBLISHER_JWT_KEY}` and `subscriber_jwt {env.MERCURE_SUBSCRIBER_JWT_KEY}` (HS256);
  - `anonymous` (public topics need no token);
  - `transport local` (or `bolt` with a path under the container's tmp; the hub's history is not needed);
  - `cors_origins` limited to the site.
  The frankenphp-worker Caddyfile is left unchanged (out of scope; noted).
- **Keys.**
  - `mercure:keys` (Artisan) generates two random 256-bit keys.
  - It writes them to `.env` (`MERCURE_PUBLISHER_JWT_KEY`, `MERCURE_SUBSCRIBER_JWT_KEY`) and to `.ddev/.env.web` (the same names, for Caddy).
  - Both files are gitignored. `/.ddev/.env.web` is added to the root `.gitignore`, and a test checks that.
  - `.env.example` documents the names, not the values.
- **Publishing.**
  - `MercurePublisher` (Laravel HTTP client) POSTs `topic`, `data` and optionally `private=on` to `config('mercure.publish_url')`, default `http://localhost/.well-known/mercure`, inside the web container.
  - It signs a short-lived publisher JWT with HS256, hand-rolled (no new dependency); the claim is `mercure.publish: [topic]`.
  - Timeout 2 s. A failure is reported (`report()`), never thrown to the caller.
- **Price changes.**
  - A `ListingPriceChanged` event fires after commit when `price_cents` changes (model `updated` with `wasChanged`).
  - A listener publishes to `https://interview.ddev.site/listings/{id}/price` with `{"id", "price_cents", "currency", "formatted", "published_at_ms"}` (`published_at_ms` for the latency bench).
  - The private variant publishes the same data to `…/listings/{id}/price/private` with `private=on`.
  - `listings:reprice {id} {price} [--private]` (Artisan) changes a price, for the demo and the bench.
- **The detail page.**
  - The price element gets `data-price-topic="…/listings/{id}/price"`, and the layout gets `<meta name="mercure-hub" content="/.well-known/mercure">`. The markup is identical on all servers.
  - `resources/js/app.js` opens an `EventSource` on the hub for the topic and updates the price text and an `aria-live="polite"` region on each message.
  - If the first connection fails before any message, it closes and does not retry, so servers without a hub stay quiet.
- **The private variant.**
  - `GET /listings/{id}?watch=private` behind the debug-routes gate sets a `mercureAuthorization` cookie (path `/.well-known/mercure`, HttpOnly, Secure, SameSite=Strict). The cookie is a subscriber JWT whose `mercure.subscribe` claim lists only that listing's private topic, valid for 1 h.
  - The page then subscribes to the private topic with `withCredentials`.
  - Without the debug gate, `?watch=private` is ignored.
- **The load bench.** `bench/mercure-load.sh` runs on frankenphp, for N in {100, 1000}. A pinned Node container on `ddev_default`, going through the router like other benches, opens N public subscriptions to listing 1's topic with `fetch` streams. No npm dependencies are used. While they are open:
  - the SL-09 probe runs for 60 s;
  - the FrankenPHP busy-thread count is sampled every second from Caddy's metrics (`frankenphp_busy_threads` on the admin endpoint inside the container);
  - 20 price changes are published 2 s apart, and each subscriber records receive time minus `published_at_ms`.

  It records health p95, timeouts, busy threads (max and median), and publish-to-receive latency p50/p95/max over all subscribers × updates, plus delivered vs expected counts. It also records the private check: a subscriber with listing 1's token receives the private update for 1 and not for 2, and an anonymous subscriber receives neither. Output: `bench/results/mercure/<date>/`.
- **Docs.**
  - `BENCHMARKS.md` section `## Live updates with Mercure (<date>)`, compared with SL-09's direct-SSE numbers;
  - guide section 23;
  - the `.env.example` entries.

### Out of scope

- Reverb on nginx-fpm and the Mercure vs Reverb comparison (SL-14).
- Making FrankenPHP the default server (a separate slice, requested 2026-11-05).
- Mercure in frankenphp-worker and Octane, and a hub on the other servers.
- User accounts. The private variant's "authorized subscriber" is whoever holds the debug-gated token.

## Must NOT change

- **MNC-1**: The detail page body is byte-identical across the 6 servers. The parity baseline is re-captured once because the markup gains the topic attribute and meta tag, and the reason is recorded in `BENCHMARKS.md` ("Response parity").
- **MNC-2**: The asset fingerprint changes once, because `app.js` is no longer empty. The new values are recorded in `BENCHMARKS.md` with the reason, and later slices compare against them.
- **MNC-3**: `.ddev/config.yaml` is not edited, and no key is committed (`.env`, `.ddev/.env.web` gitignored).
- **MNC-4**: Price changes on servers without a hub behave as before. The publish failure is reported, and the request or command still succeeds.
- **MNC-5**: The full suite runs offline (`Http::fake` for the hub).

## Acceptance criteria

- **AC-1 (BR-60)**: WHEN `listings:reprice 1 <price>` runs on frankenphp, THE SYSTEM SHALL publish one update to `…/listings/1/price`, and an open detail page for listing 1 SHALL show the new formatted price without a reload, within 1 s through the router. A page for listing 2 SHALL be unchanged.
- **AC-2 (BR-61)**: WHEN `listings:reprice 1 <price> --private` runs, THE SYSTEM SHALL publish to `…/listings/1/price/private` with `private=on`. A subscriber whose JWT claims that topic receives it. A subscriber with listing 2's token, or no token, does not. The bench records all three checks.
- **AC-3**: The publisher JWT SHALL be HS256 with claim `mercure.publish` = [the topic] and an expiry of 60 s or less. The subscriber cookie JWT SHALL carry `mercure.subscribe` = [one private topic] and an expiry of 1 h or less. Keys SHALL come only from configuration, which reads them from `.env`. Tests SHALL decode and verify both token shapes.
- **AC-4**: A publish failure (hub down, timeout, 4xx/5xx) SHALL be reported once and SHALL NOT fail the price change (MNC-4).
- **AC-5**: On nginx-fpm the detail page SHALL work as before. Its script SHALL open one `EventSource`, get an error, and stop. The console SHALL show no retry storm (at most one failed request in 10 s).
- **AC-6 (BR-26)**: `bench/mercure-load.sh` SHALL produce, for N = 100 and 1000:
  - health p95 and timeouts;
  - FrankenPHP busy threads (max and median);
  - publish-to-receive latency p50/p95/max;
  - delivered/expected counts;
  - the private-topic checks.
  `BENCHMARKS.md` SHALL have the section with a table, a Mermaid chart (health p95 vs open connections, with SL-09's direct-SSE cell for contrast), "What the numbers say", "Surprises" and "Interview story".
- **AC-7 (BR-27)**: The guide SHALL have `## 23. Live updates with Mercure`. It SHALL explain:
  - the hub as a separate Go component inside Caddy;
  - publish vs subscribe;
  - topics and private updates with JWT claims;
  - why subscribers don't hold PHP workers, with the measured numbers;
  - how keys reach Caddy (process env) vs Laravel (`.env`);
  - `EventSource` reconnect behaviour and the give-up rule.
  It SHALL link the code, the Caddyfile, scripts, results and official docs. The section 16 code map SHALL point to it for "notify the browser when something changes".

## Quality attributes

| ID | Attribute | Requirement | Verified by |
|----|-----------|-------------|-------------|
| **NFR-1** | Security | No Mercure key is committed (gitignore test + grep over the tree). Private topics need a token naming that topic. The subscribe cookie is HttpOnly, Secure, SameSite=Strict and scoped to the hub path. | `tests/Feature/MercureTokensTest.php`, `manual-test.md#step-3` |
| **NFR-2** | Accessibility | A live price update is announced politely (`aria-live="polite"`), not as an alert. | `manual-test.md#step-2` |

## Boundaries

- **Always**:
  - `Http::fake` in tests;
  - pin images by digest;
  - redact IPs in results;
  - restore `.env` and end the bench on nginx-fpm until the default-server slice changes that.
- **Ask first**: editing `.ddev/config.yaml`; new Composer or npm dependencies.
- **Never**:
  - commit keys;
  - add a Co-Authored-By or "Generated with" trailer.

## Technical context

- **Related code**:
  - `.ddev/servers/frankenphp/web-build/Caddyfile.frankenphp` (`:80` and `:443` site blocks, `common_config`);
  - `app/Models/Listing.php` (`price_cents`);
  - `resources/views/listings/show.blade.php` and `resources/views/components/layouts/app.blade.php`;
  - `resources/js/app.js`;
  - `bench/lib/health-probe.sh` and `bench/lib/env-switch.sh`;
  - `bench/slow-load.sh` (probe use);
  - `bench/parity.sh` and `bench/asset-fingerprint.sh`;
  - SL-09 results for contrast.
- **Approach**:
  - a `config/mercure.php` file;
  - `MercurePublisher`, `MercureTokens` (HS256 sign/verify, hand-rolled with `hash_hmac`);
  - an event + listener;
  - the two Artisan commands;
  - a small vanilla-JS subscriber in `app.js`;
  - a bench Node script with no dependencies.

## Decisions (self-decided, standing approval)

1. **Hand-rolled HS256 instead of a JWT library.** Signing is three base64url parts and an HMAC, about 20 lines, and it shows the mechanism. No new dependency is needed.
2. **Keys in `.env` and in an untracked `.ddev/.env.web`.** Caddy only sees process environment variables, and DDEV loads `.ddev/.env.web` into the web container. Both are ignored, so no key reaches git, as the owner asked.
3. **Identical markup everywhere, and a script that gives up.** Parity across 6 servers is a project rule. Rendering the subscription only on FrankenPHP would break it. A single failed `EventSource` attempt on other servers is cheap.
4. **Publish after commit, synchronously, 2 s timeout, never throws.** A price change must not fail because the hub is down. A queue would add a moving part the bench does not need. SL-14 can revisit this.
5. **Busy threads come from Caddy's metrics.** It is the direct answer to "does a subscriber hold a PHP worker".

## Clarifications (binding; resolve the clarity gate)

**Restoring prices (Q1).** `listings:reprice` prints the old price and has `--restore <cents>`. The bench reads each touched listing's price first and a `trap` restores it at the end, also on failure. The manual test ends with a restore step. Parity is captured only with seeded prices, so the baseline is unaffected.

**Public and private publishing (Q2).**
- **Public:** the model's after-commit listener publishes to the public topic on every `price_cents` change.
- **Private:** `--private` saves the price and then also publishes to the private topic, calling `ListingPricePublisher::publishPrivate($listing)` explicitly. The command owns the private publish; the model event knows nothing about it. So `--private` sends both a public and a private update.

**The private page (Q3).**
- **Markup:** only `GET /listings/{id}?watch=private` behind the debug gate renders an extra `data-price-topic-private="…/price/private"` attribute and sets the cookie. That URL is never in the parity capture, and the plain page is unchanged.
- **Script:** it opens one `EventSource` with both topics as `topic` parameters, and sets `withCredentials: true` only when the private attribute is present.

**Bench tokens (Q4).** `mercure:token {listing} --private` (Artisan) prints a subscriber JWT for that listing's private topic, valid for 1 h. The bench calls it through `ddev exec` before starting and passes the tokens to the Node script, which sends `Authorization: Bearer <jwt>`; the hub accepts the header. The Node script never sees a key.

**The debug gate (Q5).** The existing `EnsureDebugRoutes` middleware (`DEBUG_ROUTES`). Without it, `?watch=private` renders the normal page with status 200, no cookie and no private attribute.

**Keys and `.env` restore (Q6).**
- **Generating keys:** `mercure:keys` writes both files once and refuses to overwrite existing keys unless given `--force`. It prints that `ddev restart` is needed for Caddy to read `.ddev/.env.web`; `ddev server frankenphp` also restarts.
- **Benches:** they back up `.env` after the keys exist and restore that backup, so the keys survive. The env-switch helper changes only the variables it sets.

**Hub placement (Q7).**
- **Where:** a `(mercure_hub)` snippet imported into both the `:80` block, which the router reaches, and the `:443` block, the direct path. Publishing uses `http://localhost/.well-known/mercure` inside the container (the `:80` block).
- **CORS:** `cors_origins https://interview.ddev.site`.
- **Topic base:** `config('mercure.topic_base')`, default `config('app.url')`.

**Give-up and reconnect (Q8).**
- **Before any message:** an error closes the connection, with no retry. On nginx-fpm the 404 also makes `EventSource` stop by itself.
- **After at least one message:** a dropped connection uses `EventSource`'s default reconnect, for example when the hub restarts.
- **Measurement:** AC-1's "within 1 s" is measured by the bench's publish-to-receive latency through the router. AC-5 is checked in task 4.2 with a Playwright run that counts requests to `/.well-known/mercure` over 10 s.

**Failure semantics (Q9).** A 2 s total timeout (1 s to connect), no retry. A response that is not 2xx is a failure, checked with `->successful()`, because the client does not throw on 4xx/5xx. Each failed publish is reported once with `report()`.

**Test isolation (Q10).**
- **Config flag:** `config('mercure.enabled')` (`MERCURE_ENABLED`, default true) is false in `phpunit.xml`, so factories and seeders never POST.
- **Publisher tests:** they enable it and use `Http::fake()` with `Http::preventStrayRequests()`.
- **After commit:** the listener implements `ShouldHandleEventsAfterCommit`; Laravel runs after-commit handlers inside `RefreshDatabase` test transactions.

**The payload (Q11).**
- **`formatted`:** produced by the same helper that renders the price in the Blade view, so the live text equals a reload.
- **Fields:** `currency` is the listing's attribute.
- **Timing:** `published_at_ms` is taken right before the POST. PHP and Node containers share the host kernel's clock, so the latency math holds; the guide notes this.

**Bench assumptions (Q12).**
- **Metrics:** enabled with Caddy's global `metrics` option in the stash Caddyfile. The bench reads `frankenphp_busy_threads` from `http://localhost:2019/metrics` inside the web container; if the metric is missing outside worker mode, it records that and samples `frankenphp_total_threads` minus idle instead.
- **Connections:** 1000 subscribers use HTTP/1.1 through the router (Node's built-in fetch). Failed connections are counted and reported, not hidden.
- **`--quick`:** N = 20, a 20 s window, 5 publishes; written to `raw/` only.
- **The stub test** covers the plan, the aggregation and the private-check logic. Real authorization is proven by the live private checks recorded in the results.
- **Key check:** read both values from `.env` and run `git grep -F` over tracked files; this must find nothing.

## Open questions

Clarity gate 2026-11-05: a fresh-context reviewer raised 12 items. All were answered under standing approval in "Clarifications" above.

- [x] Q1: Restoring prices.
- [x] Q2: The command publishes privately; both updates are sent.
- [x] Q3: The private page markup and script.
- [x] Q4: Bench tokens from `mercure:token`.
- [x] Q5: The debug gate.
- [x] Q6: Keys survive the `.env` restore; `ddev restart`.
- [x] Q7: The hub on both blocks; CORS; topic base.
- [x] Q8: Give-up and reconnect; measuring.
- [x] Q9: Failure semantics.
- [x] Q10: Test isolation.
- [x] Q11: The payload.
- [x] Q12: Bench assumptions.

## Dependencies

- **Requires**: SL-09 (probe, `/healthz`), SL-10/SL-12 bench conventions, SL-06.
- **Blocks**: SL-14 (Reverb comparison), and the default-server slice.

---
Next: `/sdd spec-20261105-1709-mercure-live-price` (approval → build)
