# listings

Used-car listings. Seeded with exactly 50 000 rows (BR-05). Ids are sequential and identical after every `migrate:fresh --seed`.

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| id | bigint identity | NO | generated | Primary key |
| dealer_id | bigint | NO | | FK -> dealers.id ON DELETE RESTRICT (BR-10) |
| make | varchar(50) | NO | | |
| model | varchar(80) | NO | | |
| fuel | varchar(16) | NO | | CHECK in `App\Enums\Fuel` values |
| year | smallint | NO | | CHECK `year >= 1980`; upper bound (next year) is enforced in validation and the factory |
| price_cents | bigint | NO | | CHECK `price_cents > 0`; integer minor units |
| currency | char(3) | NO | 'EUR' | CHECK `currency ~ '^[A-Z]{3}$'` |
| mileage_km | integer | NO | | CHECK `mileage_km >= 0` |
| city | varchar(80) | NO | | Vehicle location; seeded equal to the dealer's city |
| listed_at | timestamptz | NO | | Newest-first ordering key |
| created_at | timestamp(0) with time zone | YES | | `timestampsTz()` |
| updated_at | timestamp(0) with time zone | YES | | `timestampsTz()` |

## Enum: fuel

`petrol`, `diesel`, `hybrid`, `electric`, `lpg` (`App\Enums\Fuel`, the migration CHECK is built from `Fuel::cases()`).

## Constraints (BR-10, BR-11)

- `dealer_id` NOT NULL, FK to `dealers.id`, ON DELETE RESTRICT.
- `listings_fuel_check`, `listings_year_check`, `listings_price_cents_check`, `listings_currency_check`, `listings_mileage_km_check`.

## Indexes

| Index | Columns | Query served |
|-------|---------|--------------|
| listings_pkey | id | detail lookup |
| listings_listed_at_id_idx | (listed_at DESC, id DESC) | unfiltered newest-first page |
| listings_make_model_idx | (lower(make), lower(model), listed_at DESC, id DESC) | make or make+model filter, newest first |
| listings_city_idx | (lower(city), listed_at DESC, id DESC) | city filter, newest first |
| listings_dealer_id_idx | (dealer_id) | FK check and dealer eager load |

Fuel (low cardinality), year and price are residual predicates with no index until EXPLAIN on the 50k seed shows a need.

## Search query

```sql
SELECT ... FROM listings
WHERE [lower(make) = ?] AND [lower(model) = ?] AND [fuel = ?] AND [lower(city) = ?]
  AND [year >= ?] AND [price_cents <= ?]
ORDER BY listed_at DESC, id DESC
LIMIT :per_page OFFSET (:page - 1) * :per_page
```

`max_price` is in whole EUR in the API and converted to cents (`* 100`) before the query. Plus `count(*)` with the same WHERE for `meta.total`.
