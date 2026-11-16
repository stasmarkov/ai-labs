// Usage: node metrics-screenshot.mjs --url <grafana dashboard url> --out <png path> [--width 1600] [--height 1320]
// Opens the dashboard in Chromium and saves a PNG of the whole dashboard once every panel has drawn. The default viewport
// (1600x1320) holds all seven panels; a full-page shot is not used because the resize it does makes Grafana redraw the
// panels and the shot catches them blank. Runs inside the Playwright container
// that bench/metrics-snapshot.sh starts on ddev_default, with playwright-core installed next to this file.
// The page gets an explicit locale and time zone: the container has none, and Grafana stops at boot ("Error bootstrapping
// Grafana RangeError: Incorrect locale information provided") and renders its "failed to load its application files" page.
// Auto-refresh is turned off for the shot: the dashboard's own 5 s refresh cannot be switched off by a URL parameter (Grafana
// falls back to the nearest allowed interval), so the dashboard JSON is rewritten with an empty refresh on its way to the page.
// The run fails when the "failed to load" page or no panel shows, so a broken page is never saved as a result.
// A file over 400 KB prints a warning; it is not a failure.
import { statSync } from 'node:fs';

const WARN_BYTES = 400 * 1024;
const SETTLE_MS = 3000;

function option(name, fallback) {
  const index = process.argv.indexOf(`--${name}`);
  return index === -1 ? fallback : process.argv[index + 1];
}

const url = option('url');
const out = option('out');
if (!url || !out) {
  console.error('Usage: node metrics-screenshot.mjs --url <url> --out <png> [--width 1600] [--height 1320]');
  process.exit(2);
}
const width = Number(option('width', '1600'));
const height = Number(option('height', '1320'));

const { chromium } = await import('playwright-core');
const browser = await chromium.launch({ args: ['--no-sandbox'] });
try {
  const page = await browser.newPage({ viewport: { width, height }, locale: 'en-US', timezoneId: 'UTC' });
  await page.route(/\/apis?\/.*dashboards.*frankenphp/, async (route) => {
    const response = await route.fetch();
    const json = await response.json();
    for (const dashboard of [json.dashboard, json.spec]) {
      if (dashboard) {
        dashboard.refresh = '';
      }
    }
    await route.fulfill({ response, json });
  });
  await page.goto(url, { waitUntil: 'networkidle', timeout: 60000 });
  await page.waitForTimeout(SETTLE_MS);
  if ((await page.getByText('failed to load its application files').count()) > 0) {
    throw new Error('Grafana did not start in the browser (its "failed to load" page is shown).');
  }
  await page.getByText('Requests per second', { exact: true }).first().waitFor({ timeout: 30000 });
  await page.waitForLoadState('networkidle');
  await page.waitForTimeout(SETTLE_MS);
  await page.screenshot({ path: out });
} finally {
  await browser.close();
}

const bytes = statSync(out).size;
if (bytes > WARN_BYTES) {
  console.error(`warning: ${out} is ${Math.round(bytes / 1024)} KB, over the 400 KB guide`);
}
console.log(`wrote ${out} (${bytes} bytes)`);
