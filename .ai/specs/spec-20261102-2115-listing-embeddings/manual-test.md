# Manual test plan: listing embeddings and semantic search (SL-24)

Run from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://interview.ddev.site.

## Preconditions

- Ollama is running and `ollama list` shows `bge-m3`. `ddev server --current` prints `nginx-fpm`.
- `.env` has `AI_EMBEDDINGS_PROVIDER` and `OLLAMA_EMBED_MODEL` unset or at their defaults (`ollama`, `bge-m3`); `ddev artisan config:clear`.
- Note the listings hash: `ddev exec -s db psql -U db -d db -tAc "select md5(string_agg(l::text, ',' order by id)) from listings l"`.

## Step 1: descriptions (AC-1, MNC-1)

```sh
ddev artisan migrate
ddev artisan listings:describe-all
ddev exec -s db psql -U db -d db -tAc "select count(*), min(length(description)), max(length(description)) from listing_documents"
ddev exec -s db psql -U db -d db -tAc "select description from listing_documents where listing_id in (1, 2)"
ddev exec -s db psql -U db -d db -tAc "select md5(string_agg(l::text, ',' order by id)) from listings l"
```

Expected: 50 000 rows; readable English descriptions naming year, make, model, body type, fuel and mileage; the listings hash equals the one noted.

## Step 2: embed everything (AC-2, AC-4)

```sh
ddev artisan listings:embed --fresh                     # dispatches the jobs, prints how many
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
ddev artisan listings:embed --summary-only              # writes the summary of the run just finished
ddev artisan listings:embed                             # second run: dispatches nothing
```

Expected: the first run fills 50 000 vectors and prints a summary (embeddings/s, total seconds); the second run reports 0 model calls. `ddev exec -s db psql -U db -d db -tAc "select count(*) from listing_documents where embedding is not null"` prints 50000. No queue worker remains afterwards (`ddev exec pgrep -f queue:work` prints nothing).

## Step 3: semantic search by hand (AC-6, AC-7)

```sh
curl -s 'https://interview.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot' | python3 -m json.tool | head -60
curl -s -o /dev/null -w '%{http_code}\n' 'https://interview.ddev.site/api/listings/semantic?q='
```

Expected: 10 results, highest similarity first, at least 7 estates, SUVs or MPVs; the empty query gives 422.

## Step 4: measurements (AC-8)

Run the sweep, full run, index timing, latency and recall as described in tasks.md 5.1. Expected: JSON summaries in `bench/results/<date>-embeddings/`, and a BENCHMARKS.md section with every number named in AC-8 plus the versions.

## Step 5: nothing else changed (MNC-2, MNC-3)

```sh
mkdir -p /tmp/sl24
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl24 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl24/$s.txt
done
ddev server nginx-fpm
ddev artisan test --compact
bench/run.sh ping --quick; echo "exit=$?"   # commit first: it refuses a dirty tree
```

Expected: `identical` six times; all tests pass; `exit=0`.
