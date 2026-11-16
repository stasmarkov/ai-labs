#!/usr/bin/env bash
## The pinned Playwright browser container that renders the Grafana dashboard to a PNG (bench/metrics-screenshot.mjs).
## Source it; it defines values and functions and runs nothing. bench/metrics-matrix.sh starts one container per run with
## playwright_start (playwright-core is installed once) and stops it with playwright_stop; bench/metrics-snapshot.sh runs
## each screenshot in that container, or in a one-off container when none is given.
## A caller sets before use: SCRIPT_DIR (the bench directory, mounted read-only at /bench).

PLAYWRIGHT_VERSION="1.49.1"
PLAYWRIGHT_IMAGE_PINNED="${PLAYWRIGHT_IMAGE:-mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble@sha256:70e367e0cbf60340a5b5fd562f6247a34eb3196efab9f88a3dd56482d9fe09d2}"
PLAYWRIGHT_INSTALL="mkdir -p /tmp/pw && cd /tmp/pw && cp /bench/metrics-screenshot.mjs . && npm init -y >/dev/null 2>&1 && npm install --no-audit --no-fund playwright-core@${PLAYWRIGHT_VERSION} >/dev/null 2>&1"

# The dashboard for an absolute window, kiosk mode, with auto-refresh off (an empty refresh parameter).
playwright_dashboard_url() {
  local grafana="$1" from_ms="$2" to_ms="$3"
  printf 'http://%s:3000/d/frankenphp/frankenphp?orgId=1&from=%s&to=%s&refresh=&kiosk' "$grafana" "$from_ms" "$to_ms"
}

# The shell command, run inside the container, that saves /out/<cell>.png and hands it to the host user.
playwright_shot_command() {
  local url="$1" cell="$2"
  printf "cd /tmp/pw && node metrics-screenshot.mjs --url '%s' --out '/out/%s.png' && chown \"\$HOST_UID:\$HOST_GID\" '/out/%s.png'" "$url" "$cell" "$cell"
}

# Starts a detached container named $1 on ddev_default with $2 mounted at /out, and installs playwright-core in it once.
playwright_start() {
  local name="$1" out_dir="$2"
  mkdir -p "$out_dir"
  docker rm -f "$name" >/dev/null 2>&1 || true
  docker run -d --rm --name "$name" --network ddev_default \
    -v "${SCRIPT_DIR:?}:/bench:ro" -v "$out_dir:/out" \
    -e HOST_UID="$(id -u)" -e HOST_GID="$(id -g)" \
    "$PLAYWRIGHT_IMAGE_PINNED" sleep infinity >/dev/null
  docker exec "$name" bash -c "$PLAYWRIGHT_INSTALL"
}

playwright_stop() {
  docker rm -f "$1" >/dev/null 2>&1 || true
}
