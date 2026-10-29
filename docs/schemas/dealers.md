# dealers

Car dealers that own listings. Seeded with exactly 500 rows (BR-05).

| Column | Type | Null | Default | Notes |
|--------|------|------|---------|-------|
| id | bigint identity | NO | generated | Primary key, sequential |
| name | varchar(120) | NO | | |
| city | varchar(80) | NO | | |
| created_at | timestamp(0) with time zone | YES | | `timestampsTz()`; seeded to the fixed reference timestamp |
| updated_at | timestamp(0) with time zone | YES | | `timestampsTz()`; seeded to the fixed reference timestamp |

## Relations

- hasMany `listings` (`listings.dealer_id`, ON DELETE RESTRICT).

## Indexes

- `dealers_pkey` (id).
