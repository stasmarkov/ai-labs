# Manual test plan: rename to ai-lab (SL-49)

The reported problem: the project is still called `interview`. The site answers on https://interview.ddev.site, while the README, the landing page and the scripts say https://ai-lab.ddev.site, which returns 404. After the rename the new URL must answer and nothing may be lost.

Run everything from the repo root (`~/work/interview`).

## Preconditions

- DDEV is running the project and the web server is frankenphp (`ddev server --current` prints `frankenphp`).
- Before the rename, `https://interview.ddev.site/up` answers 200.
- The git tree is clean except for `.ai/session` (the rename script refuses to run otherwise).
- Docker has room for a second copy of the project volumes. The old project stays until you delete it yourself.
- `jq` and `curl` are installed on the host.

Rollback for the whole plan: set `name: interview` back in `.ddev/config.yaml`, run `ddev start`, then `ddev import-db --file=.ai/tmp/pre-rename.sql.gz`. The old project's volumes are never touched by the script, so the old site keeps working until its volumes are removed (the script prints the `docker volume rm` line); DDEV needs `ddev stop --unlist interview` before the folder can start under the new name, and the script does it.

## Step 1

Rename with data (AC-1, MNC-1, MNC-3). Run this before any other step.

Note: the rename script samples `/api/listings...`, which is not a route (404 JSON on the old and the new URL), so its sample check proves little. Capture a real sample by hand first, from a route that exists.

```sh
curl -sk "https://interview.ddev.site/listings?per_page=5&page=1" | jq -S . > /tmp/listings-before.json
jq '.data | length' /tmp/listings-before.json
shasum .ddev/.env.web > /tmp/env-web-before.sha
grep -E '^MERCURE' .env | shasum > /tmp/mercure-before.sha
bench/rename-project.sh
```

Expected:
- `/tmp/listings-before.json` holds 5 listings (the `jq` line prints `5`; if the response has no `data` key, use the top-level array length).
- The script prints `exported the database to .ai/tmp/pre-rename.sql.gz`, `counts equal: listings listing_documents docs_documents docs_chunks ai_runs docs_answer_cache exports`, `samples equal`, `keys unchanged`, then runs `ddev server default` and `ddev setup`.
- It ends with the `docker volume rm interview-postgres ...` line printed, not run. Do not run it yet.
- If anything fails, the script prints a `Rollback:` line; follow it.

Then check the result:

```sh
ddev describe | head -8
ddev server --check default; echo "check=$?"
curl -sk "https://ai-lab.ddev.site/listings?per_page=5&page=1" | jq -S . > /tmp/listings-after.json
diff <(sed 's/interview\.ddev\.site/ai-lab.ddev.site/g' /tmp/listings-before.json) /tmp/listings-after.json && echo SAME_LISTINGS
shasum .ddev/.env.web | diff - /tmp/env-web-before.sha && echo SAME_ENV_WEB
grep -E '^MERCURE' .env | shasum | diff - /tmp/mercure-before.sha && echo SAME_MERCURE
ddev artisan config:show app.url
```

Expected:
- `ddev describe` shows the project `ai-lab` and URL `https://ai-lab.ddev.site`.
- `check=0`.
- `SAME_LISTINGS`, `SAME_ENV_WEB` and `SAME_MERCURE` are printed (no diff output).
- `config:show app.url` prints `https://ai-lab.ddev.site`.
- The table counts the script printed match what you see now: `ddev artisan tinker --execute 'echo DB::table("listings")->count();'` equals the number in `.ai/tmp/pre-rename/counts.json`.

## Step 2

The landing page (AC-3).

Open https://ai-lab.ddev.site/ in a browser.

Expected:
- 200, page title `ai-lab`.
- The what-it-is text, the run steps (`ddev setup`, `ollama pull qwen3.5:9b`, `ollama pull bge-m3`, optional cloud keys), the capability map (each item has a lab link, a guide section and a code path), the server modes, and links to the guide and `BENCHMARKS.md`.
- Click each lab link: `/ai`, `/ai/describe`, `/ai/semantic`, `/ai/nl-search`, `/ai/docs`, `/ai/chat` all open without an error page.
- Narrow the window to 390 px: no horizontal scrollbar. Press Tab: every link shows a visible focus ring.

## Step 3

Checks (AC-5, MNC-2, MNC-4, MNC-3). Needs the rename from step 1 and a commit of the work.

```sh
git diff <commit before the rename> -- .ddev/config.yaml
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm frankenphp frankenphp-worker frankenphp-octane openlitespeed; do
  ddev server "$s" && bench/parity.sh compare "$s" "$s" 2>&1 | tail -3
done
ddev server default
ddev artisan test --compact
ddev exec vendor/bin/pint --dirty --format agent
ddev exec vendor/bin/phpstan analyse
ddev npm run typecheck && ddev npm run build
git status --short
bench/run.sh ping --quick; echo "exit=$?"
```

Expected:
- The `config.yaml` diff has exactly one changed line: `-name: interview` and `+name: ai-lab` (MNC-4).
- The fingerprint prints `resources/css/app.css app-DNob7CFE.css ...` and `resources/js/app.js app-PTzu1QaN.js ...`, the same file names as before the rename (MNC-2).
- Parity says identical for every server against its snapshot in `bench/parity/<server>.txt`, with base `https://ai-lab.ddev.site` (`:8091` for openlitespeed). Check each snapshot header shows a `captured=` date from today, not `2026-11-05`; if not, the snapshots were edited and not re-captured, so run `bench/parity.sh capture` on each server and record it in `BENCHMARKS.md` "Response parity".
- Full suite green offline (turn off Wi-Fi, or run with no Ollama running; the tests use fakes), Pint, PHPStan, typecheck and build exit 0 (MNC-3).
- `git status --short` is empty before the bench run, then `bench/run.sh ping --quick` prints `exit=0`.

## Step 4

No stale names (AC-2).

```sh
git grep -n "interview.ddev.site" -- ':!bench/results' ':!.ai/reports' ':!.ai/session'
git grep -in autopulse
git grep -n -e 'DDEV_SITENAME:-interview' -e 'ddev-interview-' -- ':!bench/results' ':!.ai/reports' ':!.ai/session' ':!.ai/specs'
git status --short docs/
```

Expected:
- The first grep lists only the old-URL rewrite rule in `.ddev/commands/host/setup`, the fixtures in `tests/Feature/ServerDefaultCommandTest.php`, and quoted history lines that `tests/Feature/RenameTest.php` allows.
- The second lists only the rename note in `product-brief.md`, `backlog.md`, `BENCHMARKS.md` history and old specs. It must not list `docs/adr/0001-local-model-server-models-php-client.md` or `.ai/research/ai-chatbot-reference.md` unless `RenameTest` allows them as history.
- The third prints nothing.
- `git status` does not show ` D docs/getting-started.md` (that file must exist and say `App URL: https://ai-lab.ddev.site`).

## Step 5

README on a fresh clone (AC-4).

1. Stop the main project (`ddev stop`) so the name does not clash, or change `name:` in the clone to `ai-lab-test`.
2. `git clone . /tmp/ai-lab-clone && cd /tmp/ai-lab-clone`.
3. Open `README.md` and follow it literally: `ddev setup`, then the two model pulls (`ollama pull qwen3.5:9b`, `ollama pull bge-m3`).
4. Open the URL the README gives.

Expected:
- The README names the disk estimate (about 20 GB), the setup steps, the model pulls, how to run benches and evals, and the history note.
- The site comes up on frankenphp at the README URL and shows the landing page from step 2.
- The README sentence about the old DDEV URL being gone is true: https://interview.ddev.site no longer answers once the old project is deleted.
- Cleanup: `ddev delete -O` the clone project, then `ddev start` in the main repo.

## Step 6

The reported case, final check.

```sh
curl -sk -o /dev/null -w "%{http_code}\n" https://ai-lab.ddev.site/
curl -sk -o /dev/null -w "%{http_code}\n" https://ai-lab.ddev.site/up
for p in /ai /ai/describe /ai/semantic /ai/nl-search /ai/docs /ai/chat /listings /listings/1; do curl -sk -o /dev/null -w "$p %{http_code}\n" "https://ai-lab.ddev.site$p"; done
```

Expected: every line prints 200. Only after this, and only if you are sure, remove the old volumes with the printed `docker volume rm` line.
