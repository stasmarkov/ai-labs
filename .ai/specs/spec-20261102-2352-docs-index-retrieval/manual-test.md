# Manual test plan: docs index and hybrid retrieval (SL-32)

Run from the project root, `/Users/stasmarkov/work/interview`, in a host terminal. The app is at https://interview.ddev.site.

```sh
alias dbq='ddev exec -s db psql -U db -d db -tAc'
```

## Preconditions

- Ollama is running and `ollama list` shows `bge-m3`; `ddev server --current` prints `nginx-fpm`; `ddev artisan migrate` has run.
- Note the listings hash: `dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"` (expected `6a0f96d2c7139919cc215a520072d3f7`).

## Step 1: fetch the Laravel docs (AC-1)

```sh
ddev artisan docs:fetch-laravel
ls storage/app/docs/laravel | head
git status --short storage
```

Expected: the command prints the branch (`13.x`) and a commit hash; the folder holds the Laravel markdown files; `git status` shows nothing under `storage` (not committed).

## Step 2: index both corpora (AC-2, AC-3, AC-4)

```sh
ddev artisan docs:index
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
dbq "select source, count(*) from docs_documents group by 1"
dbq "select d.source, count(*), min(c.token_estimate), percentile_cont(0.5) within group (order by c.token_estimate), max(c.token_estimate), sum(c.oversize::int) from docs_chunks c join docs_documents d on d.id = c.document_id group by 1"
dbq "select count(*) from docs_chunks where embedding is null"
dbq "select left(content, 200) from docs_chunks order by id limit 2"
ddev artisan docs:index   # second run, after the worker finished
```

Expected: documents for `repo` and `laravel`; chunk estimates mostly 300–500 with a few oversize code/table chunks; 0 chunks without a vector; chunk text starts `Document: … › Section: …`. The second `docs:index` reports 0 changed documents and queues nothing.

Change one file and re-index:

```sh
printf '\n\nTemporary test paragraph about flux capacitors.\n' >> docs/getting-started.md
ddev artisan docs:index
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
git checkout -- docs/getting-started.md
ddev artisan docs:index
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
```

Expected: each run reports exactly 1 changed document and only a few chunks to embed; the final state matches the file in git.

## Step 3: identifier and meaning queries (AC-5, AC-6, AC-7)

```sh
for m in vector text hybrid; do echo "== $m"; ddev artisan docs:search "flushpackets" --mode=$m --k=3; done
curl -s 'https://interview.ddev.site/api/docs/search?q=why%20does%20apache%20hold%20back%20a%20streamed%20response&mode=hybrid&k=3' | python3 -m json.tool | head -40
curl -s -o /dev/null -w '%{http_code}\n' 'https://interview.ddev.site/api/docs/search?q=&mode=hybrid'
ddev artisan docs:search-bench
```

Expected: text and hybrid put a chunk mentioning `flushpackets` first; vector mode may not. The endpoint returns `data` with the documented fields and scores; the empty query gives 422. `docs:search-bench` prints the per-query table: hybrid has the expected file in the top 3 for at least 4 of 5 identifier queries and at least 4 of 5 meaning queries.

## Step 4: measurements (AC-8)

```sh
ls bench/results/*-docs-index/
grep -n "Docs index" BENCHMARKS.md
```

Expected: the result files and a BENCHMARKS.md section with corpus commits, counts per source, chunk size distribution, the token-estimate check, indexing time, sizes, the per-query table and an interview story.

## Step 5: nothing else changed (MNC-1, MNC-2, MNC-3)

```sh
dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"
curl -s 'https://interview.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot' | grep -o '"body_type":"[^"]*"' | sort | uniq -c
mkdir -p /tmp/sl32
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl32 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl32/$s.txt
done
ddev server nginx-fpm
ddev artisan test --compact
bench/run.sh ping --quick; echo "exit=$?"   # commit first
ddev exec ps -eo args | grep -c "[q]ueue:work"
```

Expected: the listings hash is unchanged; semantic search still returns mostly estates, SUVs or MPVs; `identical` six times; tests pass; `exit=0`; `0` queue workers.
