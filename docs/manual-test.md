# manual test: SL-01

Prerequisite: finish `docs/getting-started.md` (project started, `migrate:fresh --seed` done, `ddev npm run build` done). Base URL: `https://ai-lab.ddev.site`. Run commands from the repo root. Use `-k` with curl if your machine does not trust the DDEV CA.

## 1. infrastructure

| Action | Expected |
| --- | --- |
| `ddev psql -c "select version()"` | PostgreSQL 16.x |
| `ddev psql -c "select extname, extversion from pg_extension where extname='vector'"` | one row, `vector` |
| `ddev artisan tinker --execute 'echo config("cache.default"), " ", config("session.driver");'` | `redis redis` |
| `ddev psql -d postgres -tc "select 1 from pg_database where datname='testing'"` | `1` |

## 2. seed data

| Action | Expected |
| --- | --- |
| `ddev artisan migrate:fresh --seed` | completes without error |
| `ddev psql -tc "select (select count(*) from dealers), (select count(*) from listings)"` | `500 \| 50000` |
| Run the checksum below, repeat `migrate:fresh --seed`, run it again | identical hash both times |

```sh
ddev psql -tc "select md5(string_agg(concat_ws(',',id,dealer_id,make,model,fuel,year,price_cents,city,listed_at), ';' order by id)) from listings"
```

## 3. health and ping

| Action | Expected |
| --- | --- |
| `curl -si https://ai-lab.ddev.site/up` | `200`, HTML |
| `curl -si https://ai-lab.ddev.site/api/ping` | `200`, body exactly `{"success":true,"status":200,"message":"pong","data":{"ping":"pong"}}`, no `Set-Cookie` header |
| Optional: `docker stop ddev-ai-lab-db ddev-ai-lab-redis`, repeat the ping | still `200` (no DB or cache calls); run `ddev restart` afterwards |

## 4. search happy path

```sh
curl -s "https://ai-lab.ddev.site/listings?make=BMW&fuel=diesel&min_year=2018&max_price=30000&per_page=20"
```

Expected: `200`, `Content-Type: application/json`, `success: true`, `message: "Listings retrieved"`, at most 20 items, each with make BMW, fuel diesel, year >= 2018, price_cents <= 3000000; `meta` has `current_page`, `per_page`, `total`, `last_page`. On the fixed seed, total was 141 and last_page 8. Items are ordered by `listed_at` descending.

More checks:

| URL | Expected |
| --- | --- |
| `/listings` | 200, 20 items, meta.total 50000, meta.last_page 2500 |
| `/listings?make=bmw` | same results as `make=BMW` (case-insensitive) |
| `/listings?model=x1&city=VIENNA` | 200, items all model X1 in Vienna (32 on the fixed seed) |
| `/listings?per_page=1` and `/listings?per_page=50` | 200 with 1 and 50 items |
| `/listings?page=2` | 200, different ids from page 1, no overlap |
| `/listings?max_price=16375` | includes the listing priced exactly 1 637 500 cents |
| `/listings?make=NoSuchMake` | 200, `data: []`, `meta.total: 0` |

## 5. search validation (no Accept header needed)

For each URL, expected: HTTP `422`, `application/json`, body `{"success":false,"status":422,"message":"Validation failed","errors":{...}}`.

```sh
for q in 'fuel=steam' 'min_year=1979' 'min_year=2028' 'min_year=2018.5' 'max_price=0' 'max_price=abc' 'per_page=0' 'per_page=51' 'per_page=abc' 'page=0' 'page=-1' 'make[]=BMW'; do
  printf '%s -> ' "$q"; curl -s -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site/listings?$q"
done
```

Every line must print `422`. Note: the upper bound for `min_year` is next year, so `2028` is only invalid while the current year is 2026. Use current year + 2 on other dates.

## 6. detail page

1. Open https://ai-lab.ddev.site/listings/7801 in a browser.
2. Expected: page title `BMW X1`; hero image; price `16,375.00 EUR`; specs list; a Dealer section with the dealer name and city (Vienna). In dev tools Network: `build/assets/app-*.css`, `app-*.js` and `/images/heroes/hero-1.webp` (7801 % 6 = 1) all return 200. Console shows no errors.
3. Check the status of unusual ids:

```sh
for id in 50001 0 abc -1 7801.5 7801abc 999999999999999999 9223372036854775807 9223372036854775808 99999999999999999999; do
  printf '%s -> ' "$id"; curl -s -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site/listings/$id"
done
curl -s -H 'Accept: application/json' https://ai-lab.ddev.site/listings/50001
```

Expected: every line prints `404` (never 500). The last command returns the JSON error envelope with `success:false`, `status:404`, `errors:{}`.

## 7. caches

```sh
ddev artisan config:cache && ddev artisan route:cache && ddev artisan view:cache
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/api/ping
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/listings
curl -s -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/listings/7801
ddev artisan optimize:clear
```

Expected: all three commands succeed and the three requests print `200`.

## 8. query plans

```sh
ddev psql -c "explain select id from listings order by listed_at desc, id desc limit 20"
ddev psql -c "explain select id from listings where lower(make)='bmw' order by listed_at desc, id desc limit 20"
ddev psql -c "explain select count(*) from listings where lower(make)='bmw'"
```

Expected: the first uses an index only scan on `listings_listed_at_id_idx`; the second uses an index scan (the planner may pick `listings_listed_at_id_idx` and filter on `lower(make)`); the count uses a bitmap index scan on `listings_make_model_idx`. No sequential scan on the first query.

## 9. automated checks

```sh
ddev artisan test --compact                       # expected: all green (56 tests at time of writing)
ddev exec vendor/bin/pint --test --format agent   # expected: pass
ddev exec vendor/bin/phpstan analyse              # expected: no errors
```

Named tests exist for BR-01, BR-03, BR-04, BR-05, BR-10 and BR-11 (`ddev artisan test --compact --filter=BR-10`), plus arch tests in `tests/Unit/ArchTest.php`.
