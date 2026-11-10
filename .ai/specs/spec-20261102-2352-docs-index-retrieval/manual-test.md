# Manual test plan: docs index and hybrid retrieval (SL-32)

Run everything from the project root, `/Users/stasmarkov/work/interview`, in a terminal on your Mac. The app is at https://ai-lab.ddev.site.

Handy alias for database checks (used throughout):

```sh
alias dbq='ddev exec -s db psql -U db -d db -tAc'
```

## Preconditions

- `ddev start` has run, `ddev server nginx-fpm` has run (so `ddev server --current` prints `nginx-fpm`), and `ddev artisan migrate` has run.
- Ollama is running on the Mac and `ollama list` shows `bge-m3`.
- Network access to github.com (steps 1 and 2).
- Write down the listings hash before you start: `dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"`. Expected `6a0f96d2c7139919cc215a520072d3f7`.
- No queue workers running: `ddev exec ps -eo args | grep -c "[q]ueue:work"` prints `0`.

The case this slice fixes: there was no way to search the project's own markdown and the Laravel docs by exact identifier and by meaning. Steps 6 and 9 are that case (an identifier like `flushpackets` and a question by meaning must both find the right file).

Rollback: the slice only adds the `docs_documents` and `docs_chunks` tables, `storage/app/docs/` and new commands. To undo: `ddev artisan migrate:rollback --step=2` and `rm -rf storage/app/docs`. Step 5 edits `docs/getting-started.md` temporarily and restores it with git.

## Step 1: fetch the Laravel docs (AC-1, happy path)

```sh
ddev artisan docs:fetch-laravel
ls storage/app/docs/laravel | head
cat storage/app/docs/laravel.json
git ls-remote https://github.com/laravel/docs.git refs/heads/13.x
git status --short storage
```

Expected:
- The command prints `Laravel docs 13.x at commit <40-character hash>`.
- `ls` lists files like `scheduling.md` and `queues.md`.
- The hash printed by the command equals the hash from `git ls-remote`.
- `git status` prints nothing (the folder is ignored).

Run `ddev artisan docs:fetch-laravel` a second time. Expected: it succeeds with the same branch and hash (or a newer hash if upstream moved).

## Step 2: fetch fails clearly and changes nothing (AC-1)

```sh
cp storage/app/docs/laravel.json /tmp/laravel.json.before
ls storage/app/docs/laravel | wc -l
ddev exec env AI_DOCS_LARAVEL_BRANCH=no-such-branch php artisan docs:fetch-laravel; echo "exit=$?"
ddev exec env PATH=/nonexistent /usr/local/bin/php artisan docs:fetch-laravel; echo "exit=$?"
diff /tmp/laravel.json.before storage/app/docs/laravel.json && echo "meta unchanged"
ls storage/app/docs/laravel | wc -l
```

Expected:
- First fetch: a message with `git clone of no-such-branch failed` and `Remote branch no-such-branch not found`, then `exit=1`.
- Second fetch: a message with `git clone of 13.x failed` and `git: not found`, then `exit=1`. If your PHP binary is elsewhere, use `ddev exec which php` to find it.
- `meta unchanged` prints and both file counts are the same (about 105).

## Step 3: index both corpora (AC-2, AC-3, AC-4)

```sh
time ddev artisan docs:index
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
dbq "select source, count(*) from docs_documents group by 1"
dbq "select path from docs_documents where path in ('CLAUDE.md','AGENTS.md')"
dbq "select count(*) from docs_chunks where embedding is null"
dbq "select embedding_model, count(*) from docs_chunks group by 1"
dbq "select agent, provider, model, status, inputs from ai_runs where agent = 'embeddings:docs' order by id desc limit 3"
```

Expected:
- `docs:index` prints `Documents: N indexed, 0 unchanged, 0 removed.` and `Queued J jobs for C chunks`.
- Two sources: `laravel` (about 100 files) and `repo` (about 20 files). The `CLAUDE.md` query prints nothing.
- 0 chunks without a vector; every chunk has model `ollama/bge-m3`.
- The `ai_runs` rows show `embeddings:docs | ollama | bge-m3 | completed` with `inputs` equal to the batch size (up to 32).

Run it again once the worker has finished:

```sh
ddev artisan docs:index
dbq "select count(*) from docs_chunks"
```

Expected: `0 indexed, <all> unchanged, 0 removed` and `Queued 0 jobs`; the chunk count is the same as before.

## Step 4: chunk shape (AC-2)

```sh
dbq "select left(content, 160) from docs_chunks order by id limit 2"
dbq "select count(*) from docs_chunks where content not like 'Document: %'"
dbq "select count(*) from docs_chunks where oversize = false and token_estimate > 500"
dbq "select count(*) from docs_chunks where ((length(body) - length(replace(body, '\`\`\`', ''))) / 3) % 2 = 1"
dbq "select d.source, count(*), min(c.token_estimate), percentile_cont(0.5) within group (order by c.token_estimate), max(c.token_estimate), sum(c.oversize::int) from docs_chunks c join docs_documents d on d.id = c.document_id group by 1"
dbq "select d.path, c.token_estimate, left(c.body, 60) from docs_chunks c join docs_documents d on d.id = c.document_id where c.oversize order by c.token_estimate desc limit 5"
dbq "select position, right(body, 100) as tail, left(body, 100) as head from docs_chunks where document_id = (select id from docs_documents where path = 'queues.md' and source = 'laravel') order by position limit 4"
```

Expected:
- The first query shows text starting `Document: <title> › Section: <heading path>`.
- The next three counts are `0`: every chunk has the prefix, no non-oversize chunk is over 500, no chunk holds half a code fence.
- The distribution shows a maximum of 500 for non-oversize chunks and a small oversize count (about 15).
- In the last query the start of each chunk repeats whole sentences from the end of the previous chunk of the same section.
- Known gap: the oversize chunks are mostly tables and link lists with no sentence end, plus one base64 image, not only code blocks (open task 11). The Clarifications allow this but the AC wording does not mention it, so note it rather than fail the step.

## Step 5: one changed file, one deleted file (AC-3)

```sh
printf '\n\nTemporary test paragraph about flux capacitors.\n' >> docs/getting-started.md
ddev artisan docs:index
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
git checkout -- docs/getting-started.md
ddev artisan docs:index
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
dbq "select count(*) from docs_chunks where embedding is null"
```

If `docs/getting-started.md` does not exist, use any other tracked markdown file under `docs/`. Note that other uncommitted markdown changes (such as `BENCHMARKS.md`) also count as changes the first time.

Expected for each of the two runs: your file is reported as `1 indexed` and only a few chunks are queued (not the whole file). The final null-vector count is `0`.

Deletion check:

```sh
printf '# Scratch\n\nA scratch page for deletion testing.\n' > docs/zz-scratch.md
ddev artisan docs:index
dbq "select count(*) from docs_documents where path = 'docs/zz-scratch.md'"
rm docs/zz-scratch.md
ddev artisan docs:index
dbq "select count(*) from docs_documents where path = 'docs/zz-scratch.md'"
ddev exec php artisan queue:work --queue=embeddings --stop-when-empty --timeout=300
```

Expected: `1`, then `... 1 removed.` on the next run, then `0`. Its chunks are gone too.

## Step 6: search endpoint, all three modes (AC-5, AC-6; the reported case)

```sh
for m in text vector hybrid; do echo "== $m"; curl -s "https://ai-lab.ddev.site/api/docs/search?q=flushpackets&mode=$m&k=3" | python3 -m json.tool | grep -E '"(path|score|vector_rank|text_rank)"'; done
curl -s 'https://ai-lab.ddev.site/api/docs/search?q=why%20does%20apache%20hold%20back%20a%20streamed%20response&mode=hybrid&k=3' | python3 -m json.tool | head -40
curl -s 'https://ai-lab.ddev.site/api/docs/search?q=withoutOverlapping' | python3 -c 'import sys,json; d=json.load(sys.stdin)["data"]; print(len(d), [r["path"] for r in d])'
```

Expected:
- All requests answer 200 with an envelope of `success`, `status`, `message` and `data`.
- For `flushpackets`, text and hybrid return `docs/learning-guide.md` first.
- Each row has `id, source, path, heading, snippet, score, vector_rank, vector_similarity, text_rank, text_score`. In `vector` mode the `text_*` fields are `null`; in `text` mode the `vector_*` fields are `null`.
- In hybrid mode scores are sums of 1/(60+rank), for example `0.032266` = 1/61 + 1/63. A chunk in only one list scores at most 1/61 = 0.016393.
- The snippet starts with the body text (not `Document:`), is at most 300 characters, and ends with `…` when it was cut.
- Without `k`, 6 rows come back (the last command prints `6` and `scheduling.md` is in the list).

## Step 7: validation, rate limit and query logging (AC-6)

```sh
long=$(printf 'a%.0s' {1..201})
for q in 'q=' 'q=%20%20%20' '' "q=$long" 'q=x&mode=fuzzy' 'q=x&k=0' 'q=x&k=21' 'q=x&k=abc'; do
  printf '%s -> ' "${q:0:30}"; curl -s -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site/api/docs/search?$q"
done
curl -s -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/docs/search?q=x&k=20'
curl -s 'https://ai-lab.ddev.site/api/docs/search?q=queues&k=0' | python3 -m json.tool
curl -si 'https://ai-lab.ddev.site/api/docs/search?q=queues' | grep -i 'x-ratelimit'
dbq "select count(*) from ai_runs where agent = 'embeddings:query'"
curl -s -o /dev/null 'https://ai-lab.ddev.site/api/docs/search?q=scheduling&mode=hybrid'
dbq "select count(*) from ai_runs where agent = 'embeddings:query'"
dbq "select provider, model, status, inputs from ai_runs where agent = 'embeddings:query' order by id desc limit 1"
```

Expected:
- The eight bad requests print `422`; `k=20` prints `200`.
- The 422 body is JSON with `success: false` and a message naming the field.
- A 200-character query padded with spaces beyond 200 still answers `200` (it is trimmed).
- Headers `x-ratelimit-limit: 60` and a falling `x-ratelimit-remaining`.
- The `embeddings:query` count rises by exactly 1 after the hybrid call; the latest row is `ollama | bge-m3 | completed | 1`.
- Optional limit check: set `AI_DOCS_SEARCH_PER_MINUTE=3` in `.env`, run `ddev restart`, send four quick requests; expect `200 200 200 429`. Remove the line and restart afterwards.

## Step 8: command line search (AC-5)

```sh
ddev artisan docs:search "withoutOverlapping"
ddev artisan docs:search "how do I run a command every five minutes" --mode=vector --k=3
ddev artisan docs:search "x" --k=0; echo "exit=$?"
```

Expected: a table with rank, score, source, path, heading and snippet; `scheduling.md` is in the top 3 of both searches. `--k=0` prints `--k must be a whole number from 1 to 20.` and `exit=1`.

## Step 9: identifier and meaning queries (AC-7)

```sh
cat bench/docs-queries.txt
ddev artisan docs:search-bench --output-dir=/tmp/sl32-bench --index-seconds=1
python3 -m json.tool /tmp/sl32-bench/search-bench.json | head -60
python3 -m json.tool bench/results/2026-11-02-docs-index/search-bench.json | grep -A12 '"thresholds"'
```

`--output-dir` keeps the committed results untouched.

Expected:
- 10 queries: 5 identifier, 5 meaning.
- For each query and each mode, the output says whether the expected file is in the top 3.
- Hybrid hits at least 4 of 5 identifier queries and at least 4 of 5 meaning queries, and `thresholds.met` is `true`. The committed record is hybrid 5/5 and 5/5, vector 5/5 and 5/5, text 5/5 and 2/5.
- Text mode missing most meaning queries is expected, since it needs the exact words.

## Step 10: measurements recorded (AC-8)

```sh
ls bench/results/2026-11-02-docs-index/
python3 -m json.tool bench/results/2026-11-02-docs-index/index.json | head -80
sed -n 293,380p BENCHMARKS.md
```

Expected: `index.json` and `search-bench.json` exist. With the BENCHMARKS.md section "Docs index and hybrid retrieval" they hold: repo commit and Laravel docs commit; documents and chunks per source; min, median and max tokens and the oversize count; the token estimate against bge-m3's real count for 20 sampled chunks; indexing and embedding time; table and index sizes; the query table; and an interview story with at least one number.

Known open items (tasks 11 and 12): the text calls oversize chunks code blocks or tables, while the data show tables, link lists and one base64 image and no code blocks; and `embedding` in `index.json` is null if the latest run embedded only a single one-chunk batch. Do not fail the step for these two alone.

## Step 11: learning guide section 12 (AC-9)

```sh
grep -n '^## 12' docs/learning-guide.md
sed -n 362,420p docs/learning-guide.md
grep -n 'postgresql.org.*textsearch\|pgvector' docs/learning-guide.md | tail -5
```

Open `docs/learning-guide.md` in your editor or the GitHub preview and click each link in section 12.

Expected: the heading `## 12. Chunking and hybrid retrieval` exists. It explains why chunks follow structure, the prefix and overlap, why code and tables stay whole, what the token estimate gets wrong, what full text finds that vectors miss and the reverse, how RRF combines two rankings without comparing scores, and what the measured numbers show. Links to the reference design, the code (`MarkdownChunker.php`, `DocsSearchService.php`), the spec folder, the results folder, the PostgreSQL full-text search docs and pgvector all open. Known issue: item 4 about the oversize breakdown repeats the wrong claim from step 10.

## Step 12: SL-24 untouched (MNC-1)

```sh
dbq "select md5(string_agg(l::text, ',' order by id)) from listings l"
dbq "select count(*), count(embedding), max(updated_at) from listing_documents"
curl -s -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot'
curl -s -o /dev/null -w '%{http_code}\n' 'https://ai-lab.ddev.site/api/listings/semantic?q='
curl -s 'https://ai-lab.ddev.site/api/listings/semantic?q=family%20car%20with%20a%20big%20boot' | grep -o '"body_type":"[^"]*"' | sort | uniq -c
```

Expected: hash `6a0f96d2c7139919cc215a520072d3f7`; `50000 | 50000` with a `max(updated_at)` from before this slice (2026-11-02 19:26); status `200` then `422`; mostly estate, SUV or MPV bodies.

## Step 13: other endpoints and parity on all six servers (MNC-2)

```sh
for p in /up /api/ping /listings/1 /; do printf '%s -> ' $p; curl -s -o /dev/null -w '%{http_code}\n' "https://ai-lab.ddev.site$p"; done
mkdir -p /tmp/sl32
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $s >/dev/null
  BENCH_PARITY_DIR=/tmp/sl32 bench/parity.sh capture >/dev/null
  bench/parity.sh compare bench/parity/$s.txt /tmp/sl32/$s.txt
done
ddev server default
```

Expected: four `200`; `identical` printed six times. If a server fails to start or its Server header check fails, fix that first; it is not a code failure. Only the `/listings/1` hash differed from the pre-slice baselines (Vite rebuild, Decision 5), and the baselines were re-captured.

## Step 14: suite and bench run conditions (MNC-3)

```sh
ddev artisan test --compact
ddev exec vendor/bin/pint --dirty --format agent
ddev exec vendor/bin/phpstan analyse
bench/run.sh ping --quick; echo "exit=$?"
ddev exec ps -eo args | grep -c "[q]ueue:work"
```

Expected:
- All tests pass (487 at the last run). To prove the suite is network-free, quit Ollama and disconnect from the network, then rerun the first command: same result.
- Pint and PHPStan report no errors.
- Before the slice is committed, `bench/run.sh ping --quick` refuses with `Run condition failed: git working tree has changes outside bench/results/ and .ai/session/`. That is the open task 7.1. After the slice commit it passes its run conditions with `exit=0`.
- The last command prints `0`.

Rollback: the benchmark writes only under `bench/results/`; remove any new folder it creates there if you do not want to keep it.

## Step 15: housekeeping

```sh
grep -c '^ *- \[ \]' .ai/specs/spec-20261102-2352-docs-index-retrieval/tasks.md
ls .ai/specs/spec-20261102-2352-docs-index-retrieval/
```

Expected once the slice is done: `0` open tasks and no stray `tasks.md-E` file. Today tasks 1, 7, 11, 12 and 13 are open and `tasks.md-E` exists (remove it with `rm`).
