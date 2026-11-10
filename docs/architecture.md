# architecture

Lightweight ADR for slice SL-01 (walking skeleton). Business rules covered: BR-01, BR-03, BR-04, BR-05, BR-10, BR-11. Cross-cutting rules not to violate: BR-02 (identical responses on every server), BR-20 (production env, cached config), BR-30/31 (no state leaks). The product brief is still a draft, so the filter semantics below are recorded assumptions.

## overview

One Laravel app serves both a JSON API and one server-rendered Blade page.

```
Route -> final invokable Controller -> Service -> ListingRepositoryInterface -> EloquentListingRepository -> PostgreSQL
```

| Endpoint | Result |
| --- | --- |
| GET /up | framework health page (HTML, 200) |
| GET /api/ping | constant JSON, no session, no DB, no cache |
| GET /listings | JSON search with filters and pagination |
| GET /listings/{id} | HTML detail page (Vite CSS/JS, hero image) |

## layers

- Routes: `routes/api.php` (registered with `withRouting(api:)`, holds `/api/ping`), `routes/catalog.php` (registered with `withRouting(then:)` inside the stateless `api` middleware group, no prefix, holds `/listings` and `/listings/{id}`), `routes/web.php` (`/` is the Inertia landing page, `ShowLabHomeController`; `welcome.blade.php` stays only so the main Tailwind build keeps its fingerprint). Every route is named; no closures, so `route:cache` works. `/listings/{id}` has `where('id', '[1-9][0-9]{0,17}')`.
- Controllers: `PingController`, `ListListingsController`, `ShowListingController`. Final, invokable, no models or DB.
- Validation: `ListListingsRequest` validates the filters and builds a `ListingFilter` through `toFilter()`.
- Responses: `ApiResponse` builds the success envelope; `JsonErrorRenderer` (called from `withExceptions` in `bootstrap/app.php`) builds the error envelope.
- Services (final readonly): `ListingSearchService`, `ListingDetailService`. They hold defaults and depend only on the repository interface.
- Repository: `App\Contracts\ListingRepositoryInterface`, implemented by `App\Repositories\EloquentListingRepository`, bound in `AppServiceProvider::register`. All queries, eager loading and model-to-DTO mapping live here.
- DTOs (`app/DTOs`, final readonly): `ListingFilter` (input); `ListingSummary`, `ListingDetail`, `DealerSummary`, `ListingPage` (output), each with `toArray()` in a stable key order.
- Enum: `App\Enums\Fuel` (petrol, diesel, hybrid, electric, lpg) is the single source for the model cast, `Rule::enum`, factory, seeder and the migration CHECK.
- Views: `resources/views/components/layouts/app.blade.php` (with `@vite`) and `resources/views/listings/show.blade.php`. They render the `ListingDetail` DTO only.
- Data: `DealerSeeder` and `ListingSeeder` called from `DatabaseSeeder`.

## response contracts

- Success: `{success, status, message, data, meta?}`. `/api/ping` is exactly `{"success":true,"status":200,"message":"pong","data":{"ping":"pong"}}`. `/listings` has `message: "Listings retrieved"` and `meta: {current_page, per_page, total, last_page}`.
- Error (422, 404, 500): `{success:false, status, message, errors}`; `errors` is `{field:[msg]}` for 422 and `{}` otherwise. With `APP_DEBUG=false`, 500 uses the generic message.
- `/listings/{id}` unknown: framework 404 page, or the JSON envelope when the client expects JSON.

## data model

Documented first in `docs/schemas/dealers.md` and `docs/schemas/listings.md`.

- `dealers`: id, name, city, timestamps. 500 rows.
- `listings`: id, dealer_id (FK, ON DELETE RESTRICT, NOT NULL), make, model, fuel (CHECK enum), year (CHECK >= 1980), price_cents (CHECK > 0), currency char(3) default EUR, mileage_km (CHECK >= 0), city (denormalized from the dealer), listed_at, timestamps. 50 000 rows.
- Indexes: `(listed_at DESC, id DESC)` for the unfiltered page; `(lower(make), lower(model), listed_at DESC, id DESC)`; `(lower(city), listed_at DESC, id DESC)`; `(dealer_id)`. Fuel, year and price are residual filters until EXPLAIN shows a need.
- pgvector is enabled by a migration; no vector columns yet (reserved for later NL search).
- The upper year bound (next year) cannot be a static CHECK, so it lives in validation and the factory.

## filter semantics (assumptions)

make, model, city: trimmed, exact case-insensitive match, max 80 chars. fuel: enum. min_year: integer 1980..next year. max_price: integer whole EUR (1..1 000 000 000), converted to cents. per_page: integer 1-50, default 20. page: integer >= 1. Filters combine with AND. Unknown query keys are ignored. Order: `listed_at DESC, id DESC`. A page past the end returns 200 with empty data.

## decisions and rationale

| Decision | Why | Alternatives rejected |
| --- | --- | --- |
| Single repo | One backend, one server-rendered page | Split API/SPA with contract sync |
| Controller -> Service -> Repository, binding in AppServiceProvider | Thin controllers, queries in one place, fewest moving parts | Dedicated provider (revisit at 3+ bindings); hexagonal/DDD; queries in services |
| Plain final readonly DTOs | Typed boundaries without a new dependency; readonly forbids static state | spatie/laravel-data (needs approval); Eloquent resources over models |
| One envelope built centrally | Consistent contract, no try/catch in controllers, byte-stable for parity | Laravel default `{data, links, meta}` (absolute hosts differ per server); controller trait |
| `shouldRenderJsonWhen` extended to `listings.index` | Invalid input returns 422 even without `Accept`, instead of a 302 redirect | Override `failedValidation`; force an Accept header |
| Catalog routes in the stateless `api` group, no prefix | No Redis session or cookies on search/detail; stable headers | Put them in web.php; `install:api` (adds Sanctum) |
| `/listings` is JSON only | BR-02 and benchmarks need one JSON representation | HTML results page |
| Sequential bigint ids + route regex | Deterministic ids across servers; overflow and junk ids 404 before any query | UUIDs (random or seed-derived); `whereNumber` |
| Lowercase exact matching with expression indexes | Simple and index-friendly | ILIKE/prefix (pg_trgm), citext |
| price in integer cents, currency char(3) | Money rule, no float | Omit currency |
| Denormalized listings.city | Single-table indexed search | Join on dealers.city |
| Offset pagination with total | Deterministic pages and meta; count is cheap at 50k | simplePaginate, cursor |
| Deterministic seeding | Fixed Faker seed, `mt_srand`, fixed reference time 2026-01-01T00:00:00Z, fixed catalog, 1 000-row bulk inserts | Factories with create(), SQL dump |
| `/up` stays framework HTML | BR-01 only needs 200 | Custom JSON route |
| PostgreSQL 16 + pgvector, Redis for cache/sessions | Production engine in dev and tests | SQLite tests |
| Pest instead of PHPUnit; arch tests | Mechanically enforce layering, final/readonly, env() only in config | Review only |
| Detail hero image: `/images/heroes/hero-{id % 6}.webp` in `public/` | Deterministic, relative path, no Vite glob config | Hashed Vite assets, random image |
| No result caching, no throttling | Would distort benchmarks (BR-21/23) | Redis result cache |

## deviations from the original plan

- Test stack is Pest 5.3 / PHPUnit 13 (plan said Pest 4 / PHPUnit 12); kept, approved by Stanislav Markov 2026-10-29.
- Larastan (level 5) is installed and `phpstan.neon` exists; kept, approved 2026-10-29.
- The pre-existing `Order` scaffold was deleted (approved 2026-10-29).
- Timestamps are nullable `timestamp(0) with time zone` (`timestampsTz()`); schema docs corrected to match.
- On a make-filtered page query, EXPLAIN uses `listings_listed_at_id_idx` with `lower(make)` as a filter; `listings_make_model_idx` is used by the count query.

## open questions (defaults applied)

1. Order scaffold: deleted.
2. New folders app/Services, Contracts, Repositories, DTOs, Enums: approved by use.
3. Larastan: installed and kept.
4. spatie/laravel-data: not used.
5. Test user in DatabaseSeeder: remove.
6. Draft brief: proceeding, assumptions recorded here.

## risks

- pgvector package/extension availability in the db image: built via `.ddev/db-build/Dockerfile`; verify with `SELECT extversion FROM pg_extension WHERE extname='vector'`.
- `@vite` asset URLs embed the host: use identical APP_URL/ASSET_URL on every server, or normalize in the parity script.
- Next-year validation is time dependent: tests freeze time.
- `count(*)` cost grows with filters: switch to simplePaginate if benchmarks show it dominating.
