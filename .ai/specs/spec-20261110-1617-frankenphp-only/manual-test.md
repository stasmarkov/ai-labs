# Manual test plan: FrankenPHP family only (SL-50)

## Step 1

The server menu (AC-1, MNC-1).

```sh
ddev server --help
ddev server nginx-fpm; echo "exit=$?"
ddev server --current
ddev server --check default; echo "check=$?"
```

Expected:
- The help lists `frankenphp`, `frankenphp-worker` and `frankenphp-octane` only.
- `nginx-fpm` prints the removal message and `exit=1`.
- `--current` prints `frankenphp`.
- `check=0`.

## Step 2

Parity on the three modes (AC-3, MNC-2).

```sh
for s in frankenphp frankenphp-worker frankenphp-octane; do ddev server $s && PARITY_INSECURE=1 bench/parity.sh capture; done
ddev server default
bench/parity.sh compare frankenphp frankenphp-worker
bench/parity.sh compare frankenphp frankenphp-octane
ls bench/parity
```

Expected:
- Both comparisons print `identical`.
- `ls` shows only the three files.
- The route lines of `frankenphp.txt` equal the lines committed before this slice.

## Step 3

Untouched parts (MNC-3, MNC-5, MNC-6).

```sh
git diff <commit before SL-50> -- .ddev/config.yaml .ddev/servers/frankenphp .ddev/servers/frankenphp-worker .ddev/servers/frankenphp-octane
bench/asset-fingerprint.sh
```

Expected:
- The diff is empty.
- The fingerprint prints `app-DNob7CFE.css` and `app-PTzu1QaN.js`.

## Step 4

Docs and site (AC-5).

1. Open https://ai-lab.ddev.site/ and look at the server modes.
2. Open https://ai-lab.ddev.site/ai/guides/streaming and read How it works.

Expected: both show classic, worker and Octane only. A mention of PHP-FPM appears only as the reason, for example "measured earlier on PHP-FPM".
