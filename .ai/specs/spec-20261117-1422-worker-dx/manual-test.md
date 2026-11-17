# Manual test plan: worker-mode developer experience (SL-17)

## Step 1

Watch off is unchanged in all three modes (MNC-1).

```sh
for mode in frankenphp frankenphp-octane frankenphp-worker; do
  ddev server $mode && ddev dev-watch off && ddev dev-watch status
  PARITY_INSECURE=1 bench/parity.sh capture && git diff -U0 bench/parity
done
bench/asset-fingerprint.sh
ddev server frankenphp
```

Expected:
- `status` prints `off` in every mode.
- Only the `captured=` line differs in each capture.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.

## Step 2

Edit to reload (AC-1, AC-2, AC-3). The script edits `app/Support/ReloadProbe.php`, not a route.

```sh
bench/reload-latency.sh
git checkout -- app/Support/ReloadProbe.php
```

Expected:
- With watch on, worker mode and Octane show the new marker within about 2 s (median).
- With watch off, the marker stays old (`never`) until `ddev artisan frankenphp:workers:restart`, and the restart time is recorded.
- The probe file matches git afterwards.

## Step 3

Measurement and watcher cost (AC-6, NFR-2).

```sh
bench/reload-latency.sh
ddev server frankenphp-worker
for state in off on; do
  ddev dev-watch $state && sleep 20
  for i in $(seq 30); do docker stats --no-stream --format '{{.CPUPerc}}' ddev-ai-lab-web | tr -d '%'; sleep 1; done |
    awk -v s=$state '{t+=$1} END {printf "%s %.2f %%\n", s, t/NR}'
done
ddev dev-watch off && ddev server frankenphp
```

Expected:
- One JSON file per way of working.
- The BENCHMARKS table matches the files.
- Two means (30 one-second samples each, watch off and on); the on mean is less than 5 percentage points above the off mean.
- The server and dev-watch state are restored.

## Step 4

Xdebug in Octane (AC-4, MNC-3).

Run it on both worker modes, with one worker so the next request lands on the same thread (`bench/concurrency.sh 1`; `ddev server` clears it). For frankenphp-worker, replace the first line with `ddev server frankenphp-worker && bash bench/concurrency.sh 1 && ddev xdebug on`. `ddev xdebug on` and `off` restart the PHP server a few seconds later: before each listener, wait until `curl -sk https://ai-lab.ddev.site/up` shows a render time that grows between calls, or the request may still reach the old process.

```sh
ddev server frankenphp-octane && bash bench/concurrency.sh 1 && ddev xdebug on
php bench/xdebug-check.php --follow &
curl -sk "https://ai-lab.ddev.site/api/ping?XDEBUG_TRIGGER=1" >/dev/null
wait
php bench/xdebug-check.php --wait=3 &
curl -sk https://ai-lab.ddev.site/api/ping >/dev/null
wait
ddev xdebug off && ddev restart
php bench/xdebug-check.php &
curl -sk "https://ai-lab.ddev.site/api/ping?XDEBUG_TRIGGER=1" >/dev/null
wait
```

Expected:
- The first listener prints `connections=1 init=1 ended=1 after=<a few ms>`: the request with the trigger connected, and its worker was replaced after the response, which ended the session. `ended=0` would mean the session stays on the worker.
- The second listener (`--wait=3`) prints `connections=0`, for the request without the trigger.
- After `ddev xdebug off` and the restart, the third listener prints `connections=0`.

## Step 5

Switching watch on and off in both worker modes (AC-5). This is the case that used to fail: on Octane, `ddev dev-watch on` made `ddev restart` stop with `duplicate 'name: frankenphp-octane' in web_extra_exposed_ports`. It must now work.

Before you start, make sure no benchmark overlay is left over: `ls .ddev/config.z*.yaml` must list only `config.zz-server.yaml` (or nothing on the default server).

```sh
ddev server frankenphp-worker
ddev dev-watch on && ddev dev-watch status
ddev dev-watch off && ddev dev-watch status

ddev server frankenphp-octane
ddev dev-watch on && ddev dev-watch status
ddev exec 'ps -eo args | grep "octane:frankenphp" | grep -v grep'
ls .ddev/config.zz*.yaml
ddev dev-watch off && ddev dev-watch status
ddev server frankenphp
```

Expected:
- Every `ddev dev-watch on` and `off` finishes without an error and the web container restarts.
- `status` prints `on` after `on` and `off` after `off`, and shows the server name (`frankenphp-worker` or `frankenphp-octane`).
- On Octane, `ddev restart` does not report a duplicate `web_extra_exposed_ports` name, and the `ps` line contains `--watch`.
- While Octane watch is on, `ls` shows `config.zzz-dev-watch.yaml` and `config.zz-server.yaml`; after `off`, only `config.zz-server.yaml` remains.
- If a benchmark overlay (`config.zzz-bench-*.yaml`) is present, `dev-watch on` on Octane exits 2 and tells you to run `ddev server frankenphp-octane` first; with dev-watch on, `bash bench/concurrency.sh 2` exits 2 and tells you to run `ddev dev-watch off` first.

## Step 6

Project config and `.env` stay untouched, overlays are ignored by git (MNC-2).

```sh
shasum .ddev/config.yaml .env > /tmp/before.sum
git status --short .ddev/config.yaml
ddev server frankenphp-worker && ddev dev-watch on && ddev dev-watch off
ddev server frankenphp-octane && ddev dev-watch on && ddev dev-watch off
ddev server frankenphp
shasum .ddev/config.yaml .env | diff - /tmp/before.sum && echo same
git check-ignore -v .ddev/config.zzz-dev-watch.yaml
git status --short .ddev | grep config.zz || echo "no overlay shown"
```

Expected:
- `git status --short .ddev/config.yaml` prints nothing (or only a change you made before this test).
- `diff` prints nothing and the line `same` appears.
- `git check-ignore` names a rule in `.gitignore` for `.ddev/config.zzz-dev-watch.yaml`.
- The last command prints `no overlay shown`.

## Step 7

Guide page and learning guide (AC-7). Open https://ai-lab.ddev.site/ai/guides/worker-dx in a browser.

```sh
curl -sk -o /dev/null -w '%{http_code}\n' https://ai-lab.ddev.site/ai/guides/worker-dx
grep -n '^## I-13. Worker-mode developer experience' docs/learning-guide.md
node bench/ui-audit.mjs --only guides,guides/worker-dx
```

Expected:
- `curl` prints `200`.
- In the browser the page shows the tabs How it works, Implementation, Modify, Support, Numbers, Experiments, Build it yourself, Watch out for and Elsewhere (the six engine tabs plus build, watch and elsewhere).
- Nowhere in the page text do you see a slice id such as `SL-17`, a `.md` file name, or a path starting with `app/` or `bench/`.
- The browser console shows no errors and no request fails.
- `grep` finds the `I-13` heading.
- The audit ends with `4 page(s) checked, 0 failing`.

## Step 8

The default test suite needs no server or debugger (MNC-4). Stop Xdebug first and make sure no listener is running (`ddev xdebug status` prints disabled).

```sh
ddev xdebug off
ddev artisan test --compact
```

Expected:
- The run ends green (all tests passed, a few skipped) with no test waiting for a port or a debugger.

## Step 9

Cleanup after an interrupted benchmark (NFR-1, AC-6). Start the script, stop it with Ctrl-C after about 20 seconds, then look at the state.

```sh
BENCH_TRIALS=3 bench/reload-latency.sh
# press Ctrl-C after ~20 s
git diff --stat app/Support/ReloadProbe.php
shasum .env
ddev dev-watch status
ls .ddev/config.z*.yaml
```

Expected:
- The probe file shows no diff and `MARKER` is `'initial'`.
- `.env` has the same checksum as before you started, and `DEBUG_ROUTES` is back to its old value.
- `dev-watch` prints `off`, the server is the one you started on (`ddev server` without arguments shows it), and only `config.zz-server.yaml` is left.
- `GET https://ai-lab.ddev.site/debug/reload-probe` answers 404 when `DEBUG_ROUTES` is off.

Then run the full script once without interrupting it, `bench/reload-latency.sh`, and check that `bench/results/reload/<today>/` holds five files (`classic`, `octane-nowatch`, `octane-watch`, `worker-nowatch`, `worker-watch`), each with `median_ms`, `p90_ms`, `max_ms` and `never`; the two no-watch files also hold `restart_ms`, and the octane-watch median is a number (not all `never`). `BENCHMARKS.md` has the same figures with a story. Rollback: `git checkout -- app/Support/ReloadProbe.php && ddev dev-watch off && ddev server frankenphp`.
