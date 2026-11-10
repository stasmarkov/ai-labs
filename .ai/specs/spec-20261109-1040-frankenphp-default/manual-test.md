# Manual test plan: FrankenPHP as the default server (SL-47)

What changed: the project's resting web server is now frankenphp instead of nginx-fpm. `ddev server --default` names it, `ddev server default` switches to it, `ddev setup` brings a fresh clone to it, and every bench script ends on it. `.ddev/config.yaml` is untouched and still says `nginx-fpm` on purpose.

## Preconditions

- Run everything from `/Users/stasmarkov/work/interview` in a terminal on the host (not inside `ddev ssh`).
- DDEV is running (`ddev start`) and the site answers at https://ai-lab.ddev.site.
- The machine is currently on frankenphp (`ddev server --current` prints `frankenphp`).
- The Mercure keys already exist in `.env` and `.ddev/.env.web` (the plan checks that `ddev setup` keeps them).
- Steps 1 and 2 temporarily remove files under `.ddev/`. Step 1 makes a backup first, so do not skip its first command.

## Rollback (if anything goes wrong)

```sh
# restore the files saved in step 1 (use the folder name step 1 printed)
b=/tmp/sl47-<number>
cp $b/config.zz-server.yaml .ddev/ && cp $b/docker-compose.frankenphp.yaml .ddev/ && cp -R $b/web-build/. .ddev/web-build/
ddev restart
ddev server default
ddev server --current   # frankenphp
```

If the backup is gone, `ddev setup` rebuilds the same files.

## Step 1

The default and the fresh-clone setup (AC-1, AC-2). This is also the case that used to fail: a checkout with no overlay did not end up on frankenphp.

```sh
ddev server --default
ddev server
ddev server default
ddev server frankenphp
ddev server nonsense; echo "rc=$?"
```

Expected:
- `ddev server --default` prints exactly `frankenphp`.
- `ddev server` prints `Active: frankenphp (default: frankenphp)`, then a `Supported:` line listing all six servers, and no `Not set up` line.
- `ddev server default` and `ddev server frankenphp` behave the same: both say frankenphp is already active and nothing restarts.
- `ddev server nonsense` prints `Unknown server 'nonsense'. Supported: ...` and `rc=1`.

Now simulate a fresh clone. Keep the printed backup folder name.

```sh
b=/tmp/sl47-$$; echo "backup in $b"
mkdir -p $b/web-build
cp .ddev/config.zz-server.yaml .ddev/docker-compose.frankenphp.yaml $b/
cp .ddev/web-build/Caddyfile.frankenphp .ddev/web-build/Dockerfile.frankenphp .ddev/web-build/pre.Dockerfile.frankenphp $b/web-build/
md5 -q .env .ddev/.env.web | tee $b/keys.md5
rm -f .ddev/config.zz-server.yaml .ddev/docker-compose.frankenphp.yaml .ddev/web-build/Caddyfile.frankenphp .ddev/web-build/Dockerfile.frankenphp .ddev/web-build/pre.Dockerfile.frankenphp
ddev server
```

Expected: `Active: nginx-fpm (default: frankenphp)` (it falls back to `config.yaml`), the `Supported:` line, and `Not set up: run ddev setup`.

Setup also does what a real fresh clone needs: it copies `.env.example` when `.env` is missing, runs `ddev composer install` when `vendor/autoload.php` is missing, `ddev artisan key:generate` when `APP_KEY` is empty, and `ddev npm install && ddev npm run build` when `public/build/manifest.json` is missing. `ddev server --check default` exits 0 only when the overlay, the activated files and no bench overlay match, and setup uses it to decide whether the server is ready.

Run the setup:

```sh
ddev setup
curl -sN -m 3 -o /dev/null -w '%{http_code} %{content_type}\n' 'https://ai-lab.ddev.site/.well-known/mercure?topic=x'
ddev server
md5 -q .env .ddev/.env.web
```

Expected:
- `ddev setup` starts DDEV, switches to frankenphp, keeps the existing Mercure keys (it does not generate new ones), runs migrations (`Nothing to migrate` is fine), prints `Set up: frankenphp` and the site URL.
- curl prints `200 text/event-stream` (curl may exit with a timeout code after 3 seconds; that is normal for an open stream).
- `ddev server` is back to `Active: frankenphp (default: frankenphp)` with no hint.
- The two md5 hashes equal the ones saved in `$b/keys.md5`.
- `.ddev/config.zz-server.yaml` exists again.

Second run:

```sh
ddev setup
```

Expected: `Already set up: frankenphp`, no restart, no file changes (`ls -l .ddev/config.zz-server.yaml` keeps its modification time).

Optional cleanup: `rm -rf $b`.

## Step 2

Other servers still work (MNC-3).

```sh
ddev server nginx-fpm
curl -sI https://ai-lab.ddev.site/up | grep -iE '^(HTTP|server)'
ddev server default
curl -sI https://ai-lab.ddev.site/up | grep -iE '^(HTTP|server)'
curl -sN -m 3 -o /dev/null -w '%{http_code} %{content_type}\n' 'https://ai-lab.ddev.site/.well-known/mercure?topic=x'
ddev server --current
```

Expected:
- After `nginx-fpm`: `HTTP/2 200` and `server: nginx`.
- After `default`: `HTTP/2 200` and a FrankenPHP/Caddy server header.
- The Mercure curl prints `200 text/event-stream`.
- `ddev server --current` prints `frankenphp`.

Optionally repeat `ddev server <name>` and a `/up` check for `apache-fpm`, `openlitespeed` (URL `https://ai-lab.ddev.site:8091/up`), `frankenphp-octane` and `frankenphp-worker`; each should answer 200. Finish with `ddev server default`.

## Step 3

End-state checks (AC-5, MNC-1, MNC-2). Needs the changes committed so the tree is clean.

```sh
git diff --stat HEAD -- .ddev/config.yaml
bench/asset-fingerprint.sh
for s in nginx-fpm apache-fpm openlitespeed frankenphp frankenphp-octane frankenphp-worker; do ddev server $s >/dev/null; BENCH_PARITY_DIR=/tmp/sl47p bench/parity.sh capture >/dev/null; echo -n "$s: "; bench/parity.sh compare bench/parity/$s.txt /tmp/sl47p/$s.txt; done
ddev server default
git status --short
bench/run.sh ping --quick; echo "exit=$?"
ddev server --current
```

Expected:
- The `config.yaml` diff prints nothing.
- The fingerprint is unchanged: `app-DNob7CFE.css` and `app-PTzu1QaN.js`.
- `identical` for all six servers.
- `git status --short` prints nothing (`bench/run.sh` refuses to run on a dirty tree).
- `exit=0`, and the machine ends on `frankenphp`.

## Step 4

Bench scripts restore the default (AC-3). The dry runs print the commands and run none of them.

```sh
grep -rn 'ddev server nginx-fpm' bench/
bench/http3-matrix.sh --dry-run | tail -n 3
bench/slow-load-matrix.sh --dry-run | tail -n 3
bench/export-load-matrix.sh --dry-run | tail -n 3
```

Expected:
- The `grep` prints nothing.
- Each dry run's last line restores the default, `ddev server frankenphp` (read from `ddev server --default`), never `nginx-fpm`.
- Earlier lines still measure the same servers as before: the export matrix lists apache-fpm, frankenphp, openlitespeed, nginx-fpm; the slow-load matrix lists nginx-fpm and frankenphp; the http3 matrix lists frankenphp.

## Step 5

Docs name the default (AC-4).

```sh
head -n 3 BENCHMARKS.md
grep -n 'The default server is' README.md CLAUDE.md docs/learning-guide.md BENCHMARKS.md
grep -n 'ddev setup' README.md CLAUDE.md
grep -rniE 'default server, nginx-fp[m]|back to nginx-fp[m]|end on nginx-fp[m]' .ai/specs/*/manual-test.md
```

Expected:
- The `BENCHMARKS.md` top note reads `The default server is now frankenphp.`
- The README, guide and BENCHMARKS notes are found; README and `CLAUDE.md` both mention `ddev setup`, and `CLAUDE.md` explains that `config.yaml` says nginx-fpm on purpose.
- The last `grep` prints nothing.
- `BENCHMARKS.md`'s top note also names `ddev setup`, and no plan ends a fenced block or its Rollback section on a bare nginx-fpm switch (the doc test checks both).

## Step 6

History and the offline suite (MNC-4, MNC-5).

```sh
git diff HEAD --stat -- bench/results bench/parity
git diff HEAD -- BENCHMARKS.md | grep '^[+-]' | grep -v '^+++\|^---'
```

Then turn off Wi-Fi (or unplug the network) and run:

```sh
ddev artisan test --compact
```

Expected:
- The first command prints nothing: result files and parity baselines are untouched.
- The `BENCHMARKS.md` diff shows only the added top note.
- The full suite passes with no network (about 1270 tests; the tests use a stubbed `ddev`).
