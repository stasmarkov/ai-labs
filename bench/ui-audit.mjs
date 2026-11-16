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
// Pages without a teach block by design (overview, guides, guide page) get the layout checks only. Gated pages that answer 404
// (admin assistant, changes, playground) are skipped with a note. Only the first render is checked: nothing is submitted.
// <details> elements are opened before checking. Viewport height 1000, HTTPS errors ignored (DDEV certificate).
// Screenshots go to bench/results/raw/ui-audit/ (gitignored). Exit 0 when no page has a finding, 1 otherwise, 2 on a setup error.
// Playwright: PW (path to a playwright package), else the newest ~/.npm/_npx/*/node_modules/playwright; otherwise exits 2 with
// "run: npx playwright@1.55 --version". Browser: the installed Chrome channel, Playwright Chromium as a fallback.

import { createRequire } from 'node:module';
import { existsSync, mkdirSync, readdirSync, statSync } from 'node:fs';
import { homedir } from 'node:os';
import { dirname, join, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const HERE = dirname(fileURLToPath(import.meta.url));
const OUT = resolve(HERE, 'results/raw/ui-audit');

const TEACH_PAGES = [
  'describe', 'semantic', 'nl-search', 'docs', 'chat', 'photos', 'photo-search', 'photos/duplicates',
  'assistant', 'moderation', 'research', 'playground', 'admin-assistant', 'changes', 'settings',
];
const LAYOUT_ONLY_PAGES = ['', 'guides', 'guides/describe'];
const LISTING_SELECTS = ['#moderation-pick', '#describe-listing'];

function option(name, fallback) {
  const index = process.argv.indexOf(`--${name}`);
  return index === -1 ? fallback : process.argv[index + 1];
}

function loadPlaywright() {
  const candidates = [];
  if (process.env.PW) {
    candidates.push(process.env.PW);
  }
  const npx = join(homedir(), '.npm', '_npx');
  if (existsSync(npx)) {
    const found = readdirSync(npx)
      .map((dir) => join(npx, dir, 'node_modules', 'playwright'))
      .filter((path) => existsSync(path))
      .sort((a, b) => statSync(b).mtimeMs - statSync(a).mtimeMs);
    candidates.push(...found);
  }
  for (const path of candidates) {
    try {
      return createRequire(import.meta.url)(path);
    } catch {
      // try the next candidate
    }
  }
  console.error('Playwright not found; run: npx playwright@1.55 --version');
  process.exit(2);
}

function inPage({ listingSelects, expectTeach }) {
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

const { chromium } = loadPlaywright();
const base = option('base', 'https://ai-lab.ddev.site').replace(/\/$/, '');
const widths = option('widths', '1440,1024').split(',').map(Number);
const only = option('only') ? option('only').split(',').map((p) => p.replace(/^\/?(ai\/?)?/, '')) : null;
const pages = [
  ...TEACH_PAGES.map((path) => ({ path, teach: true })),
  ...LAYOUT_ONLY_PAGES.map((path) => ({ path, teach: false })),
].filter((page) => only === null || only.includes(page.path));

mkdirSync(OUT, { recursive: true });

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

let failing = 0;
let checked = 0;
for (const width of widths) {
  const context = await browser.newContext({ viewport: { width, height: 1000 }, ignoreHTTPSErrors: true });
  const page = await context.newPage();
  for (const target of pages) {
    const url = `${base}/ai${target.path ? `/${target.path}` : ''}`;
    const label = `/ai${target.path ? `/${target.path}` : ''} @${width}`;
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
    const findings = await page.evaluate(inPage, { listingSelects: LISTING_SELECTS, expectTeach: target.teach }).catch((error) => {
      console.log(`       ${error.message.split('\n')[0]}`);
      return null;
    });
    const slug = `${target.path.replace(/\//g, '-') || 'overview'}-${width}`;
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
