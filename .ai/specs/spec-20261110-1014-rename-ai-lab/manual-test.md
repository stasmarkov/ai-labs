# Manual test plan: rename to ai-lab (SL-49)

## Step 1

Rename with data (AC-1, MNC-1, MNC-3).

```sh
bench/rename-project.sh
ddev describe | head -5
ddev server --check default; echo "check=$?"
curl -s https://ai-lab.ddev.site/api/listings?per_page=5 | jq '.data[].id'
```

Expected:
- The script reports equal counts for every table and prints, without running, `ddev delete interview --omit-snapshot`.
- `ddev describe` shows `ai-lab` and `https://ai-lab.ddev.site`.
- `check=0`.
- The same 5 listing ids as before the rename.
- `.env` and `.ddev/.env.web` keep their Mercure keys.

## Step 2

The landing page (AC-3).

Open https://ai-lab.ddev.site/.

Expected:
- The what-it-is text, the run steps, the capability map (each item has a lab link, a guide section and a code path), the server modes, and links to the guide and `BENCHMARKS.md`.
- No horizontal scroll at 390 px.

## Step 3

Checks (AC-5, MNC-2, MNC-4).

```sh
git diff HEAD~1 -- .ddev/config.yaml
git grep -n "interview.ddev.site" -- ':!bench/results' ':!.ai/reports' ':!.ai/session' | head
bench/asset-fingerprint.sh
```

Expected:
- The `config.yaml` diff changes only `name:`.
- The grep finds nothing outside the allowed history paths.
- The fingerprint is unchanged.
- Parity is identical on all current servers against the re-captured baseline.
- `bench/run.sh ping --quick` exits 0 after the commit.
