#!/usr/bin/env node
// Usage: node bench/ui-audit.mjs [--base https://ai-lab.ddev.site] [--only <path>[,<path>]] [--widths 1440,1024]
// Layout and teaching check for every lab page, against the site that is up now (seeded dev database, APP_ENV=local).
// For each page at each width it reports:
//   overlap     two visible label, button, a, input, select, textarea, h1, h2 or h3 elements, neither containing the other,
//               whose rectangles intersect by more than 4 px on both axes (elements with no text that are not form controls,
//               and anything inside [role=dialog], [popover], or a fixed or sticky ancestor, are ignored)
//   narrow      a text, search, number, url or email input, select or textarea under 120 px wide; the Moderation and Describe
//               listing selects (#moderation-pick, #describe-listing) must be at least 160 px
//   scroll      the document is wider than the window (scrollWidth > innerWidth + 2); inner containers may scroll
//   no-teach    no [data-lab-teach] block, or one of its parts data-teach-part="works|build|watch|elsewhere" is missing
//   no-guide    no [data-guide-link]
//   no-hint     a text, search, number, url, email or textarea field with no placeholder and no aria-describedby pointing at
//               visible text, or a select with no visible label or with neither an empty option nor aria-describedby
//   chips       a page with a main input shows fewer than 2 or more than 4 [data-example] chips (pages that had more than 4
//               before the teach slice keep them: CHIPS_GRANDFATHERED)
// Detail pages (changes, research, playground) and the run page are opened with a real id: first from a list-page link, then from
// the dev database (latest row, through `ddev artisan tinker`, only for a *.ddev.site base), or skipped with a note when there is none.
// The run page answers JSON, so for it only the status and the content type are checked.
// Pages without a teach block by design (overview, guides, guide page, details, run) get the layout checks only. Gated pages that answer 404
// (admin assistant, changes, playground) are skipped with a note. Only the first render is checked: nothing is submitted.
// <details> elements are opened before checking. Viewport height 1000. HTTPS errors are ignored only for a *.ddev.site base (DDEV's
// local certificate); any other base must present a valid certificate.
// Screenshots go to bench/results/raw/ui-audit/ (gitignored). Exit 0 when no page has a finding, 1 otherwise, 2 on a setup error.
// Playwright: PW (path to a playwright package you chose), else the ~/.npm/_npx/*/node_modules/playwright entry whose version is
// the pinned PLAYWRIGHT_PIN (1.55.x); otherwise exits 2 with "run: npx playwright@1.55 --version". No other cache entry is loaded.
// Browser: the installed Chrome channel, Playwright Chromium as a fallback.

import { execFileSync } from 'node:child_process';
import { createRequire } from 'node:module';
import { existsSync, mkdirSync, readFileSync, readdirSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'results/raw/ui-audit');

const TEACH_PAGES = [
  'describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'photo-search', 'photos/duplicates',
  'assistant', 'moderation', 'research', 'playground', 'admin-assistant', 'changes', 'settings',
];
const LAYOUT_ONLY_PAGES = ['', 'guides', 'guides/describe', 'guides/logs-restart', 'guides/worker-dx'];
const UUID = '[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}';
const DETAIL_PAGES = [
  { list: 'changes', prefix: 'changes', gated: true },
  { list: 'research', prefix: 'research', gated: false },
  { list: 'playground', prefix: 'playground', gated: true },
];
const RUN_SOURCES = ['research', 'moderation', 'describe', 'semantic', 'nl-search', 'chat', 'assistant', 'playground'];
const LISTING_SELECTS = ['#moderation-pick', '#describe-listing'];
const PLAYWRIGHT_PIN = /^1\.55\./;
/** Pages whose main input gets 2 to 4 example chips. Photos, photo duplicates, changes and settings have no main input. */
const CHIP_PAGES = ['describe', 'semantic', 'nl-search', 'docs', 'chat', 'photo-search', 'assistant', 'moderation', 'research', 'playground', 'admin-assistant'];
/** Pages that already had more than 4 chips before the teach slice; they keep them (C-4). */
const CHIPS_GRANDFATHERED = [];

function option(name, fallback) {
  const index = process.argv.indexOf(`--${name}`);
  if (index === -1) {
    return fallback;
  }
  const value = process.argv[index + 1];
  if (value === undefined || value.startsWith('--')) {
    console.error(`Usage: --${name} needs a value`);
    process.exit(2);
  }
  return value;
}

function loadPlaywright() {
  const require = createRequire(import.meta.url);
  if (process.env.PW) {
    try {
      return require(process.env.PW);
    } catch (error) {
      console.error(`PW=${process.env.PW} is not a playwright package: ${error.message.split('\n')[0]}`);
      process.exit(2);
    }
  }
  const npx = join(homedir(), '.npm', '_npx');
  const pinned = existsSync(npx)
    ? readdirSync(npx)
      .map((dir) => join(npx, dir, 'node_modules', 'playwright'))
      .filter((path) => {
        try {
          return PLAYWRIGHT_PIN.test(JSON.parse(readFileSync(join(path, 'package.json'), 'utf8')).version);
        } catch {
          return false;
        }
      })
    : [];
  for (const path of pinned) {
    try {
      return require(path);
    } catch {
      // try the next pinned entry
    }
  }
  console.error('Playwright 1.55.x not found; run: npx playwright@1.55 --version');
  process.exit(2);
}

/** The latest ids in the dev database, for detail pages no list page links to. Empty when DDEV or a row is missing. */
function databaseIds() {
  const code = 'echo json_encode(['
    + '"runs" => App\\Models\\AiRun::query()->latest("id")->value("invocation_id"), '
    + '"research" => App\\Models\\ResearchRun::query()->latest("created_at")->value("id"), '
    + '"playground" => App\\Models\\PlaygroundRun::query()->latest("created_at")->value("id"), '
    + '"changes" => App\\Models\\PendingChange::query()->latest("created_at")->value("id")]);';
  try {
    const output = execFileSync('ddev', ['artisan', 'tinker', '--execute', code], { cwd: resolve(HERE, '..'), encoding: 'utf8', timeout: 60000, stdio: ['ignore', 'pipe', 'ignore'] });
    const line = output.trim().split('\n').pop() ?? '{}';
    const ids = JSON.parse(line);
    return Object.fromEntries(Object.entries(ids).filter(([, id]) => typeof id === 'string' && new RegExp(`^${UUID}$`, 'i').test(id)));
  } catch {
    return {};
  }
}

function inPage({ listingSelects, expectTeach, chips }) {
  const FIELD = 'input[type=text],input[type=search],input[type=number],input[type=url],input[type=email],input:not([type]),textarea';
  const CONTROL = `${FIELD},select`;
  const OVERLAP = 'label,button,a,input,select,textarea,h1,h2,h3';
  const findings = [];
  const add = (kind, detail) => findings.push({ kind, detail });

  const visible = (el) => {
    const r = el.getBoundingClientRect();
    const s = getComputedStyle(el);
    return r.width > 0 && r.height > 0 && s.visibility !== 'hidden' && s.display !== 'none';
  };
  const name = (el) => {
    const id = el.id ? `#${el.id}` : '';
    const text = (el.textContent || el.getAttribute('placeholder') || '').trim().replace(/\s+/g, ' ').slice(0, 30);
    return `${el.tagName.toLowerCase()}${id}${text ? ` "${text}"` : ''}`;
  };
  const skipped = (el) => {
    for (let node = el; node && node.nodeType === 1; node = node.parentElement) {
      if (node.matches('[role=dialog],[popover]')) {
        return true;
      }
      const position = getComputedStyle(node).position;
      if (position === 'fixed' || position === 'sticky') {
        return true;
      }
    }
    return false;
  };

  document.querySelectorAll('details').forEach((d) => {
    d.open = true;
  });

  const candidates = [...document.querySelectorAll(OVERLAP)].filter((el) => {
    if (!visible(el) || skipped(el)) {
      return false;
    }
    const isControl = el.matches('input,select,textarea');
    return isControl || (el.textContent || '').trim() !== '';
  });
  for (let i = 0; i < candidates.length; i++) {
    for (let j = i + 1; j < candidates.length; j++) {
      const a = candidates[i];
      const b = candidates[j];
      if (a.contains(b) || b.contains(a)) {
        continue;
      }
      const ra = a.getBoundingClientRect();
      const rb = b.getBoundingClientRect();
      const dx = Math.min(ra.right, rb.right) - Math.max(ra.left, rb.left);
      const dy = Math.min(ra.bottom, rb.bottom) - Math.max(ra.top, rb.top);
      if (dx > 4 && dy > 4) {
        add('overlap', `${name(a)} x ${name(b)} (${Math.round(dx)}x${Math.round(dy)} px)`);
      }
    }
  }

  document.querySelectorAll(CONTROL).forEach((el) => {
    if (!visible(el)) {
      return;
    }
    const width = el.getBoundingClientRect().width;
    const min = listingSelects.some((selector) => el.matches(selector)) ? 160 : 120;
    if (width < min) {
      add('narrow', `${name(el)} is ${Math.round(width)} px, needs ${min}`);
    }
  });

  if (document.documentElement.scrollWidth > window.innerWidth + 2) {
    add('scroll', `scrollWidth ${document.documentElement.scrollWidth} > innerWidth ${window.innerWidth}`);
  }

  if (chips !== null) {
    const count = [...document.querySelectorAll('[data-example]')].filter(visible).length;
    if (count < chips.min || count > chips.max) {
      add('chips', `${count} example chip(s), needs ${chips.min} to ${chips.max}`);
    }
  }

  if (expectTeach) {
    const teach = document.querySelector('[data-lab-teach]');
    if (!teach) {
      add('no-teach', 'no [data-lab-teach] block');
    } else {
      for (const part of ['works', 'build', 'watch', 'elsewhere']) {
        if (!teach.querySelector(`[data-teach-part="${part}"]`)) {
          add('no-teach', `missing part ${part}`);
        }
      }
    }
    if (!document.querySelector('[data-guide-link]')) {
      add('no-guide', 'no [data-guide-link]');
    }

    const describedByText = (el) => {
      const ids = (el.getAttribute('aria-describedby') || '').split(/\s+/).filter(Boolean);
      return ids.some((id) => {
        const target = document.getElementById(id);
        return target && visible(target) && (target.textContent || '').trim() !== '';
      });
    };
    document.querySelectorAll(FIELD).forEach((el) => {
      if (visible(el) && !(el.getAttribute('placeholder') || '').trim() && !describedByText(el)) {
        add('no-hint', `${name(el)} has no placeholder or hint`);
      }
    });
    document.querySelectorAll('select').forEach((el) => {
      if (!visible(el)) {
        return;
      }
      const labelled = (el.labels && el.labels.length > 0 && [...el.labels].some((l) => (l.textContent || '').trim() !== ''))
        || (el.getAttribute('aria-label') || '').trim() !== '';
      if (!labelled) {
        add('no-hint', `${name(el)} has no visible label`);
      }
      const hasEmptyOption = [...el.options].some((o) => o.value === '');
      if (!hasEmptyOption && !describedByText(el)) {
        add('no-hint', `${name(el)} has no empty option and no hint line`);
      }
    });
  }

  return findings;
}

const base = option('base', 'https://ai-lab.ddev.site').replace(/\/$/, '');
const widths = option('widths', '1440,1024').split(',').map(Number);
const onlyOption = option('only', null);
const only = onlyOption === null ? null : onlyOption.split(',').map((p) => p.replace(/^\/?(ai\/?)?/, ''));
let baseUrl;
try {
  baseUrl = new URL(base);
} catch {
  console.error(`Usage: --base needs an absolute URL, got ${base}`);
  process.exit(2);
}
/** DDEV signs its sites with a local certificate; only those may skip TLS checks. */
const isDdev = baseUrl.protocol === 'https:' && baseUrl.hostname.endsWith('.ddev.site');
const contextOptions = (width) => ({ viewport: { width, height: 1000 }, ignoreHTTPSErrors: isDdev });
const { chromium } = loadPlaywright();
const pages = [
  ...TEACH_PAGES.map((path) => ({ path, teach: true, chips: CHIP_PAGES.includes(path) ? (CHIPS_GRANDFATHERED.includes(path) ? { min: 2, max: Infinity } : { min: 2, max: 4 }) : null })),
  ...LAYOUT_ONLY_PAGES.map((path) => ({ path, teach: false, chips: null })),
].filter((page) => only === null || only.includes(page.path));

mkdirSync(OUT, { recursive: true });

async function firstLinkId(page, base, listPath, prefix) {
  const response = await page.goto(`${base}/ai/${listPath}`, { waitUntil: 'networkidle', timeout: 30000 }).catch(() => null);
  if (!response || response.status() >= 400) {
    return null;
  }
  return page.evaluate(({ prefix: segment, uuid }) => {
    const pattern = new RegExp(`/ai/${segment}/(${uuid})(?:[/?#]|$)`, 'i');
    for (const a of document.querySelectorAll('a[href]')) {
      const match = pattern.exec(a.getAttribute('href') || '');
      if (match) {
        return match[1];
      }
    }
    return null;
  }, { prefix, uuid: UUID });
}

async function resolveExtraPages(browser) {
  const context = await browser.newContext(contextOptions(1440));
  const page = await context.newPage();
  const fromLinks = {};
  for (const detail of DETAIL_PAGES) {
    fromLinks[detail.prefix] = await firstLinkId(page, base, detail.list, detail.prefix);
  }
  for (const source of RUN_SOURCES) {
    fromLinks.runs = fromLinks.runs ?? await firstLinkId(page, base, source, 'runs');
  }
  await context.close();

  const missing = Object.keys(fromLinks).filter((prefix) => !fromLinks[prefix]);
  const fromDatabase = missing.length > 0 && isDdev ? databaseIds() : {};
  const extra = [];
  const skipped = [];
  for (const prefix of [...DETAIL_PAGES.map((detail) => detail.prefix), 'runs']) {
    const id = fromLinks[prefix] ?? fromDatabase[prefix];
    const label = `/ai/${prefix}/{id}`;
    if (id) {
      extra.push({ path: `${prefix}/${id}`, teach: false, chips: null, json: prefix === 'runs', label, source: fromLinks[prefix] ? 'link' : 'database' });
    } else {
      skipped.push(`${label}: no row in the lab pages or the dev database`);
    }
  }
  return { extra, skipped };
}

let browser;
try {
  browser = await chromium.launch({ channel: 'chrome' });
} catch {
  try {
    browser = await chromium.launch();
  } catch (error) {
    console.error(`No browser could start: ${error.message.split('\n')[0]}`);
    process.exit(2);
  }
}

if (only === null) {
  const { extra, skipped } = await resolveExtraPages(browser);
  pages.push(...extra);
  for (const target of extra) {
    console.log(`id   ${target.label}: ${target.path.split('/').pop()} (from ${target.source})`);
  }
  for (const note of skipped) {
    console.log(`SKIP ${note}`);
  }
}

let failing = 0;
let checked = 0;
for (const width of widths) {
  const context = await browser.newContext(contextOptions(width));
  const page = await context.newPage();
  for (const target of pages) {
    const url = `${base}/ai${target.path ? `/${target.path}` : ''}`;
    const label = `${target.label ?? `/ai${target.path ? `/${target.path}` : ''}`} @${width}`;
    let response;
    try {
      response = await page.goto(url, { waitUntil: 'networkidle', timeout: 30000 });
    } catch (error) {
      console.log(`FAIL ${label}: ${error.message.split('\n')[0]}`);
      failing++;
      continue;
    }
    if (response && response.status() === 404 && ['admin-assistant', 'changes', 'playground'].includes(target.path)) {
      console.log(`SKIP ${label}: gated (404)`);
      continue;
    }
    if (!response || response.status() >= 400) {
      console.log(`FAIL ${label}: HTTP ${response ? response.status() : 'none'}`);
      failing++;
      continue;
    }
    if (target.json) {
      const type = response.headers()['content-type'] ?? '';
      checked++;
      if (type.includes('application/json')) {
        console.log(`ok   ${label} (JSON endpoint: status and content type only)`);
      } else {
        console.log(`FAIL ${label}: expected JSON, got ${type || 'no content type'}`);
        failing++;
      }
      continue;
    }
    const findings = await page.evaluate(inPage, { listingSelects: LISTING_SELECTS, expectTeach: target.teach, chips: target.chips }).catch((error) => {
      console.log(`       ${error.message.split('\n')[0]}`);
      return null;
    });
    const slug = `${target.path.replace(/\//g, '-').slice(0, 40) || 'overview'}-${width}`;
    await page.screenshot({ path: join(OUT, `${slug}.png`), fullPage: true });
    checked++;
    if (findings === null) {
      console.log(`FAIL ${label}: audit could not run`);
      failing++;
    } else if (findings.length === 0) {
      console.log(`ok   ${label}`);
    } else {
      failing++;
      console.log(`FAIL ${label}: ${findings.length} finding(s)`);
      for (const finding of findings.slice(0, 12)) {
        console.log(`       ${finding.kind}: ${finding.detail}`);
      }
      if (findings.length > 12) {
        console.log(`       ... ${findings.length - 12} more`);
      }
    }
  }
  await context.close();
}
await browser.close();

console.log(`${checked} page(s) checked, ${failing} failing`);
process.exit(failing === 0 ? 0 : 1);
