# Manual test plan: FrankenPHP family only (SL-50)

The lab used to offer six web servers. It now offers three: `frankenphp` (classic, the default, with Mercure), `frankenphp-worker` and `frankenphp-octane`. This plan checks that the removed servers are refused, the three that remain still work and answer every route the same way, and nothing else moved.

## Preconditions

- DDEV is running the project: `ddev start`, then open https://ai-lab.ddev.site/ and see the home page.
- Work from the repo root, `/Users/stasmarkov/work/interview`.
- Run the commands in a terminal on the host (they are DDEV host commands and bench scripts, not container commands).
- The active server is the default: `ddev server --current` prints `frankenphp`.
- The working tree may have uncommitted changes. That is fine. Step 2 rewrites the three files in `bench/parity/`, so run `git status --short bench/parity` first and note what you see.
- Last commit that still had the old servers: `719c0a5`.

Rollback for any step that switches servers: `ddev server default`, then `ddev server --current` must print `frankenphp`. If step 2 leaves `bench/parity/*.txt` dirty with more than the `# server=... captured=` header line, run `git checkout -- bench/parity`.

## Step 1

The server menu and the removed names (AC-1).

```sh
ddev server; echo "exit=$?"
ddev help server
ddev server nginx-fpm; echo "exit=$?"
ddev server apache-fpm; echo "exit=$?"
ddev server openlitespeed; echo "exit=$?"
ddev server --current
```

Expected:
- `ddev server` with no arguments prints `Active: frankenphp (default: frankenphp)` and `Supported: frankenphp frankenphp-worker frankenphp-octane`, with `exit=0`.
- `ddev help server` shows the usage line listing `default|frankenphp|frankenphp-worker|frankenphp-octane` and no removed name.
- Each removed name prints `<name> was removed in SL-50; the lab runs on the FrankenPHP family: frankenphp, frankenphp-worker, frankenphp-octane` and `exit=1`.
- `ddev server --current` still prints `frankenphp`. Nothing was switched.
- Note: `ddev server --help` is intercepted by the script and prints `Unknown server '--help'. Supported: frankenphp frankenphp-worker frankenphp-octane` with exit 1. It still lists exactly the three names. Use `ddev help server` for the proper help text.

## Step 2

Parity on the three modes (AC-3, MNC-2).

```sh
ls bench/parity
for s in frankenphp frankenphp-worker frankenphp-octane; do
  ddev server $s && ddev server --current && PARITY_INSECURE=1 bench/parity.sh capture
done
ddev server default
bench/parity.sh compare frankenphp frankenphp-worker; echo "exit=$?"
bench/parity.sh compare frankenphp frankenphp-octane; echo "exit=$?"
bench/parity.sh compare frankenphp-worker frankenphp-octane; echo "exit=$?"
ls bench/parity
git diff -U0 -- bench/parity | grep '^[+-][^+-]'
```

Expected:
- The first `ls` and the last `ls` both show only `frankenphp-octane.txt`, `frankenphp-worker.txt`, `frankenphp.txt`. No `nginx-fpm.txt`, `apache-fpm.txt` or `openlitespeed.txt`.
- Each switch exits 0 and `--current` prints the mode just switched to. Each capture finishes without error.
- All three comparisons print `identical` and `exit=0`.
- The last command shows only changed `# server=... captured=` header lines. No route line (`/up`, `/api/ping`, `/listings...`, `/listings/1`, `/listings/999999`) differs from what is committed, so the per-route hashes still equal the committed `frankenphp.txt`.
- After `ddev server default`, `ddev server --current` prints `frankenphp`.

## Step 3

Parts that must not change (MNC-3, MNC-5, MNC-6).

```sh
git diff --stat 719c0a5 -- .ddev/config.yaml .ddev/servers/frankenphp .ddev/servers/frankenphp-worker .ddev/servers/frankenphp-octane
git diff --quiet HEAD -- .ddev/config.yaml .ddev/servers/frankenphp .ddev/servers/frankenphp-worker .ddev/servers/frankenphp-octane; echo "exit=$?"
bench/asset-fingerprint.sh
grep -o 'app-[A-Za-z0-9_-]*\.\(css\|js\)' public/build/manifest.json | sort -u
```

Expected:
- Both git commands show no changes (empty stat, `exit=0`).
- The fingerprint script and the manifest show `app-DNob7CFE.css` and `app-PTzu1QaN.js`.

## Step 4

Bench scripts refuse removed servers and default to the three (AC-2).

```sh
bench/suite.sh --servers frankenphp,nginx-fpm; echo "exit=$?"
bench/http3.sh --server openlitespeed; echo "exit=$?"
sed -n 19p bench/suite.sh
```

Expected:
- The first two commands exit 2 (non-zero) and print the same wording as step 1: `<name> was removed in SL-50; the lab runs on the FrankenPHP family: ...`. No server is switched; `ddev server --current` still prints `frankenphp`.
- Line 19 reads `SERVERS=("${SUPPORTED_SERVERS[@]}")`, so with no `--servers` the suite uses the three modes.
- Optional, slow: `bench/suite.sh --quick` runs only `frankenphp`, `frankenphp-worker`, `frankenphp-octane` and puts the default server back at the end.

## Step 5

Stale overlay naming a removed server, and a fresh clone (AC-1, MNC-1).

Do this in a throw-away copy so your real overlay is untouched:

```sh
cp -R . /tmp/lab-copy && cd /tmp/lab-copy
printf '# server: nginx-fpm\n' > .ddev/config.zz-server.yaml
.ddev/commands/host/server --current
.ddev/commands/host/server --check default; echo "check=$?"
rm .ddev/config.zz-server.yaml
.ddev/commands/host/server --current
cd - && rm -rf /tmp/lab-copy
```

Expected:
- With the stale overlay `--current` prints `nginx-fpm (removed; run ddev server default)` and `--check default` exits 1.
- With no overlay `--current` prints `not set up (run ddev setup)`.

## Step 6

Default behaviour unchanged (MNC-1).

```sh
ddev server default; echo "exit=$?"
ddev server --current
ddev server --check default; echo "check=$?"
ddev setup
```

Expected:
- `exit=0`, current is `frankenphp`, `check=0`.
- `ddev setup` prints `Already set up: frankenphp` and the site still opens at https://ai-lab.ddev.site/.

## Step 7

The web image has no Apache module (AC-6).

```sh
git ls-files .ddev/apache .ddev/web-build .ddev/servers | grep -i -e apache -e openlitespeed -e xsendfile; echo "grep=$?"
ddev exec 'dpkg -l | grep -i xsendfile'; echo "exit=$?"
```

Expected:
- The first command prints nothing and `grep=1`.
- The second prints nothing and `exit=1`. Run `ddev restart` first if the web image was built before this slice (the image rebuilds from `.ddev/web-build`).

## Step 8

Home page and docs show only the three modes (AC-5).

1. Open https://ai-lab.ddev.site/ and find the "Server modes" section.
2. Open https://ai-lab.ddev.site/ai/guides/streaming, open the Modify tab and find the recipe "Stream on each of the three FrankenPHP modes".
3. In a terminal: `sed -n 70,85p README.md` and `sed -n 44,55p docs/getting-started.md`.

Expected:
- The home page lists Classic (`ddev server frankenphp`), Worker (`ddev server frankenphp-worker`) and Octane (`ddev server frankenphp-octane`). No nginx, Apache or OpenLiteSpeed name appears.
- The streaming guide names classic, worker and Octane, gives the `ddev server frankenphp-worker`, `ddev server frankenphp-octane` and `ddev server default` commands, and mentions PHP-FPM only as "measured earlier on PHP-FPM".
- README and getting-started each name the three modes with their `ddev server` commands.
- The browser console shows no errors.

## Step 9

History kept (MNC-4) and no stray names left (AC-4).

```sh
git diff --stat HEAD -- bench/results
git diff HEAD -U0 -- BENCHMARKS.md | grep '^[+-][^+-]'
git ls-files | xargs grep -l -i -e nginx-fpm -e apache-fpm -e openlitespeed 2>/dev/null
```

Expected:
- No change under `bench/results`.
- `BENCHMARKS.md` shows one changed line: the top note gaining the sentence that the removed servers were dropped and their numbers are history.
- The last command lists only allowed files: `bench/results/**`, `.ai/**`, `BENCHMARKS.md`, `product-brief.md`, `backlog.md`, `docs/learning-guide.md`, `docs/adr/**`, `bench/lib/servers.sh`, `.ddev/commands/host/server`, `.ddev/config.yaml`, `CLAUDE.md`, `resources/**/summary.php` and the history and removal tests. Anything else is a failure.
- Known open item at the time of writing: the full test suite had one failure, `LearningGuideTest` line 683 (section 2 of `docs/learning-guide.md` lacks `frankenphp` in backticks). Run `ddev artisan test --compact tests/Feature/LearningGuideTest.php`; it must be green before sign-off.
