# Manual test plan: listing embeddings and semantic search (SL-24)

Run everything from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://ai-lab.ddev.site. The database is Postgres in the `db` container; the `psql` shortcut used below is:

```sh
alias dbq='ddev exec -s db psql -U db -d db -tAc'
```

## Preconditions

- DDEV is running. Run `ddev server nginx-fpm` first (this plan starts on nginx-fpm on purpose); `ddev server --current` prints `nginx-fpm`.
- Ollama runs on the Mac and `ollama list` shows `bge-m3`.
- `.env` leaves `AI_EMBEDDINGS_PROVIDER` and `OLLAMA_EMBED_MODEL` unset (defaults `ollama` and `bge-m3`). Then run `ddev artisan config:clear`.
- The catalogue has the 50 000 seeded listings: `dbq "select count(*) from listings"` prints `50000`.
- Write down the listings hash before you start: `dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"`. The known value is `6a0f96d2c7139919cc215a520072d3f7`.
- Risky parts: step 2 overwrites every stored vector with `--fresh`, and step 5 switches web servers. To go back, run `ddev server default`; to rebuild vectors, repeat step 2. Never touch `listings`; if the hash ever changes, stop and run `ddev artisan migrate:fresh --seed`.

## Step 1: descriptions (AC-1, MNC-1)

```sh
ddev artisan migrate
ddev artisan listings:describe-all
dbq "select count(*) from listing_documents"
dbq "select description from listing_documents where listing_id in (1, 2)"
dbq "select min(array_length(string_to_array(description, ' '), 1)), max(array_length(string_to_array(description, ' '), 1)) from listing_documents"
dbq "select md5(string_agg(description, ',' order by listing_id)) from listing_documents"
ddev artisan listings:describe-all
dbq "select md5(string_agg(description, ',' order by listing_id)) from listing_documents"
dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"
```

Expected:
- First count is `50000`.
- The two sample descriptions are readable English and name the year, make, model, body type, fuel and mileage, plus up to three features.
- Word counts fall between 25 and 80 (about 28 to 39 on the seeded data).
- The two description hashes are identical, so the second run changed nothing, and the same id always gives the same text.
- The listings hash equals `6a0f96d2c7139919cc215a520072d3f7`.

## Step 2: embed everything (AC-2, AC-3, AC-4)

```sh
ddev artisan listings:embed --fresh --batch=256
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
ddev artisan listings:embed --summary-only
dbq "select count(*) from listing_documents where embedding is not null"
dbq "select vector_dims(embedding), count(*) from listing_documents group by 1"
dbq "select count(*), min(inputs), max(inputs) from ai_runs where agent = 'embeddings'"
ddev artisan listings:embed
ddev exec pgrep -f queue:work
```

Expected:
- The first command prints how many batches it queued (50 000 / 256 = 196).
- After the worker stops, the embedded count is `50000` and the only dimension group is `1024 | 50000`.
- The `ai_runs` query shows one row per batch with `inputs` at most 256.
- The second `listings:embed` queues nothing and reports 0 model calls (AC-4). With no `--batch`, the size comes from config (256 by default).
- `pgrep` prints nothing, so no worker is left running.

Re-embed only what changed (AC-4):

```sh
dbq "update listing_documents set description = description || ' Test edit.' where listing_id = 1"
ddev artisan listings:embed
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
```

Expected: exactly 1 listing is queued and embedded. Restore the text afterwards with `ddev artisan listings:describe-all`, then run the two embed commands again so the edited row matches.

Wrong dimension (AC-3) has no safe manual route against the real model; it is covered by the automated test `AC-3 a vector of the wrong length fails the batch`. To see it by hand, set `OLLAMA_EMBED_MODEL=nomic-embed-text` (768 numbers), run `ddev artisan config:clear`, `ddev artisan listings:embed --limit=10 --fresh`, and the worker. Expected: the jobs fail with a message about the wrong vector length and no vectors are stored for those rows. Remove the line from `.env` and run `ddev artisan config:clear` afterwards, then redo `listings:embed --fresh`.

## Step 3: semantic search, the big-boot query (AC-6, AC-7)

```sh
curl -s 'https://ai-lab.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot' | python3 -m json.tool | head -40
curl -s 'https://ai-lab.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot' | python3 -c "import sys,json; d=json.load(sys.stdin); print(len(d['data']) if 'data' in d else len(d)); print(sorted(x['similarity'] for x in (d['data'] if 'data' in d else d))[::-1] == [x['similarity'] for x in (d['data'] if 'data' in d else d)])"
curl -s 'https://ai-lab.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot' | grep -o '"body_type":"[^"]*"' | sort | uniq -c
```

Expected:
- HTTP 200 with 10 items. Each has `id`, `year`, `make`, `model`, `body_type`, `price_cents`, `currency`, `city`, `description` and `similarity` (4 decimals).
- The second command prints `10` then `True`, so results run from the highest similarity down.
- At least 7 of the 10 body types are estates, SUVs or MPVs (the last recorded check gave 10 of 10 estates).

## Step 4: measurements are recorded (AC-8)

```sh
ls bench/results/2026-11-02-embeddings/
python3 -m json.tool bench/results/2026-11-02-embeddings/search-bench.json | head -40
grep -n -i 'ollama\|batch\|recall\|p95\|index' BENCHMARKS.md | head -40
```

Expected: result files exist for the sweep (`embed-16-2000.json`, `embed-64-2000.json`, `embed-256-2000.json`), the full run, and `search-bench.json`. Check each of these is present:
- the model and Ollama version;
- embeddings/s for each batch size;
- the full run's embeddings/s and total time at the best batch size (`embed-256-all.json`: 48.98 embeddings/s, 1 020.8 s);
- the HNSW build time (`index_build_seconds` not null) and index size;
- the table size;
- p50 and p95 latency with embedding time and database time shown separately;
- recall@10 over 20 queries.

Also check `ef_sweep` in `search-bench.json` lists recall and database p50 for `ef_search` 40, 100, 200 and 400 (recorded: 0.405, 0.485, 0.605, 0.795).

To refresh the numbers, start one worker in a second terminal (`ddev exec php artisan queue:work --queue=embeddings --timeout=300`), run `ddev artisan listings:embed --fresh --batch=256 --wait`, then `ddev artisan listings:search-bench --index`, then stop the worker with Ctrl-C.

## Step 5: nothing else changed (MNC-1, MNC-2, MNC-3)

```sh
ddev artisan migrate:fresh --seed
dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"
for p in /up /api/ping /listings /listings/1; do curl -s -o /dev/null -w "$p %{http_code}\n" https://ai-lab.ddev.site$p; done
curl -s -N --max-time 30 -D - -o /dev/null https://ai-lab.ddev.site/listings/1/describe | head -5

mkdir -p /tmp/sl24
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl24 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl24/$s.txt
done
ddev server default
ddev artisan test --compact
bench/run.sh ping --quick; echo "exit=$?"
```

Expected:
- `migrate:fresh --seed` is slow and wipes vectors (redo step 2 afterwards). The listings hash is still `6a0f96d2c7139919cc215a520072d3f7`, and `listing_documents` is refilled by the seeder.
- `/up`, `/api/ping`, `/listings`, `/listings/1` all print `200`; the describe response header block shows `200` and `content-type: text/event-stream`.
- `identical` is printed six times, once per server.
- All tests pass (417 at the last run) with no network access needed.
- `exit=0` for the bench run. It refuses a dirty working tree, so commit first.

## Step 6: validation, rate limit and logging (AC-5, AC-6)

```sh
for q in '' 'x'; do curl -s -w ' -> %{http_code}\n' "https://ai-lab.ddev.site/api/listings/semantic?q=$q"; done
curl -s -w ' -> %{http_code}\n' 'https://ai-lab.ddev.site/api/listings/semantic' | head -c 300; echo
curl -s -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site/api/listings/semantic?q=$(printf 'a%.0s' {1..201})"
curl -s -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site/api/listings/semantic?q=$(printf 'a%.0s' {1..200})"
dbq "select agent, provider, model, status, inputs, tokens_in, tokens_out from ai_runs where agent like 'embeddings%' order by id desc limit 3"
for i in $(seq 1 25); do curl -s -o /dev/null -w '%{http_code} ' 'https://ai-lab.ddev.site/api/listings/semantic?q=small%20cheap%20city%20car'; done; echo
```

Expected:
- Empty `q=` and missing `q` give 422 with body like `{"success":false,"status":422,"message":"Validation failed","errors":{"q":[...]}}`.
- `q=x` gives 200 (it returns a result list, not an error).
- 201 characters gives 422; 200 characters gives 200.
- The latest `ai_runs` rows show `embeddings:query | ollama | bge-m3 | completed | 1`, `tokens_in` set (or empty if the provider reported none) and `tokens_out` empty. Batch rows from step 2 show agent `embeddings` and `inputs` equal to the batch size.
- The 25-request loop prints 200s and then 429s once past the per-minute limit (20 by default, the same as describe). Wait a minute for it to reset.

## Step 7: wrong-length query vector is a failed call (AC-6, AC-5)

This is the originally reported gap (task 13). It cannot be triggered with the real bge-m3, so use the automated route:

```sh
ddev artisan test --compact --filter='wrong length'
```

Expected: the test passes and asserts a 503 JSON answer plus one `embeddings:query` row with status `failed` and error class `InvalidEmbeddingException`. Until task 13 is done, the 503 is returned but the row is stored as `completed`, so this step fails on the `ai_runs` status.

By hand, you can see the 503 path with Ollama stopped (`osascript -e 'quit app "Ollama"'` or `pkill ollama`), then:

```sh
curl -s -w ' -> %{http_code}\n' 'https://ai-lab.ddev.site/api/listings/semantic?q=family%20car'
dbq "select status, error_class from ai_runs where agent = 'embeddings:query' order by id desc limit 1"
```

Expected: HTTP 503 with a JSON error body (not an HTML page), and the newest row has status `failed`. Start Ollama again afterwards.

## Step 8: learning guide section 11 (AC-9)

```sh
grep -n '^## 11' docs/learning-guide.md
sed -n "$(grep -n '^## 11' docs/learning-guide.md | cut -d: -f1),\$p" docs/learning-guide.md | grep -n -i 'embedding\|cosine\|HNSW\|ef_search\|fake\|template\|0001\|pgvector\|bge-m3' | head -40
```

Expected: section "11. Embeddings and vector search" explains what an embedding is, what bge-m3 does with a description, why cosine and normalised vectors, the HNSW trade-offs (speed against exact results, `ef_search`, build time, memory), and why the description generator must not touch the seed. It links ADR 0001, the code, the spec, the result files, and the pgvector and bge-m3 docs. It also says the AC-7 test uses arranged fake vectors and proves only the ordering, notes that template text limits the meaning a model can find, and presents the recall explanation as an untested idea. The last three points are missing until task 14 is done.
