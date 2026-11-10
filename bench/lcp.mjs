#!/usr/bin/env node
// Usage: node bench/lcp.mjs --variant <name> --path <router|direct> --path-reason <text> [--runs 5] [--listing 1] [--dry-run]
// Measures LCP, FCP and TTFB of GET /listings/<id> on the server that is up now (`ddev server --current`), in a pinned
// Playwright Chromium container on ddev_default. One variant per invocation; the matrix (server, EARLY_HINTS on or off) is
// set up by the caller and named by --variant (for example frankenphp-hints, frankenphp-no-hints, nginx-fpm).
//   --path router  the browser resolves the site host to the DDEV router (Traefik)
//   --path direct  the browser resolves it to the web container (TLS on 443), skipping the router
//   --path-reason  why that path was chosen (from bench/early-hints.sh); written into the result file
// Throttle (Lighthouse "Slow 4G" values) is applied at the network layer so every fetch is delayed alike, the 103 and the hinted
// fetches included (CDP network emulation does not delay them): a pinned toxiproxy container on ddev_default proxies TCP 443 to the
// upstream (the router, or the web container for --path direct) with a latency toxic of 75 ms in each direction (150 ms RTT) and a
// bandwidth toxic of 200 KB/s down (1.6 Mbit/s) and 94 KB/s up (about 750 kbit/s). Chromium resolves the site host to the proxy
// (--host-resolver-rules), so TLS and SNI are unchanged. Only the 4x CPU slowdown is applied over CDP.
// Cold: every run uses a new browser context (empty HTTP cache). The cache is not disabled, so hinted preloads can be reused.
// TTFB is receiveHeadersEnd of the document request from CDP Network.responseReceived (ms from the request start, so it includes
// connection setup). Chrome's navigation responseStart is kept as ttfb_nav_ms for reference.
// A run without a first-contentful-paint or largest-contentful-paint entry (or with 0) is invalid: it is excluded from the summary,
// counted as invalid_runs, and the script fails when fewer than 3 valid runs remain.
// An early hint counts as used when the CSS or JS resource timing has initiatorType "early-hints".
// Output: bench/results/lcp/<UTC date>/<variant>.json (median, min and max over the valid runs, every sample, throttle, image digests).
// --hints on|off only records the EARLY_HINTS setting in the file; bench/lcp.sh is the wrapper that sets it in .env.
// --net netem [--protocol h2|h3] [--loss <pct>] (opt-in) replaces toxiproxy with kernel netem (bench/lib/netem.sh) on the browser container's own
// network, so TCP and QUIC see the same link (75 ms each way, 750/1600 kbit, loss per direction). The browser always goes straight to the web
// container (path 'direct'; the router has no HTTP/3), so --path router is rejected and --path / --path-reason are optional there.
// Chromium is forced to h2 (--disable-quic) or h3 (--origin-to-force-quic-on, so a cold h3 run models a visitor who already learned Alt-Svc,
// not a first visit). A run is valid only when the document's nextHopProtocol equals --protocol and the load event fired; a navigation that
// throws is an invalid attempt, not an abort. The result (profile, measured RTT, load time, offload state, qdisc drop counters) goes to
// bench/results/http3/<UTC date>/lcp-<variant>.json; a run that cannot be shaped and verified fails and records nothing.
// netem drops one skb at a time, so the segmentation offloads (tso, gso, gro, UDP GSO) are turned off on the browser container (by netem.sh)
// and on the web container's eth0 for the run (netem.sh web-offloads off); the web values are restored on every exit path, including
// SIGINT and SIGTERM, and recorded as net.offloads.web.{before,after,restored}.
// In netem mode --runs valid runs are required: runs that fail validity are repeated until --runs are valid or --runs + 3 attempts
// are used (attempts is recorded); fewer valid runs than --runs after that exits 1 and writes nothing.
// --variant is limited to [A-Za-z0-9._-]{1,40} (it names a result file).
// --dry-run prints the plan and calls nothing. Env: BENCH_APPROOT, BENCH_DATE, PLAYWRIGHT_IMAGE, TOXIPROXY_IMAGE, NETEM_SH.

import { execSync, spawn, spawnSync } from 'node:child_process';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, resolve } from 'node:path';
import { fileURLToPath } from 'node:url';

const PLAYWRIGHT_VERSION = '1.49.1';
const DEFAULT_IMAGE = `mcr.microsoft.com/playwright:v${PLAYWRIGHT_VERSION}-noble`;
const DEFAULT_TOXIPROXY_IMAGE = 'shopify/toxiproxy:2.1.4';
const MIN_VALID_RUNS = 3;
const NETEM_EXTRA_ATTEMPTS = 3;
const THROTTLE = {
  method: 'toxiproxy (TCP proxy on ddev_default, network layer)',
  rtt_ms: 150,
  down_kbps: 1600,
  up_kbps: 750,
  cpu_slowdown: 4,
  cpu_method: 'cdp Emulation.setCPUThrottlingRate',
  toxics: [
    { name: 'latency_up', type: 'latency', direction: 'upstream', latency_ms: 75 },
    { name: 'latency_down', type: 'latency', direction: 'downstream', latency_ms: 75 },
    { name: 'bandwidth_up', type: 'bandwidth', direction: 'upstream', rate_kb_per_s: 94 },
    { name: 'bandwidth_down', type: 'bandwidth', direction: 'downstream', rate_kb_per_s: 200 },
  ],
};
const TTFB_SOURCE = 'cdp Network.responseReceived timing.receiveHeadersEnd of the document request';
const scriptPath = fileURLToPath(import.meta.url);
const scriptDir = dirname(scriptPath);

function usage() {
  return 'Usage: node bench/lcp.mjs --variant <name> [--path <router|direct> --path-reason <text>; required unless --net netem] [--runs 5] [--listing 1] [--hints on|off] [--net toxiproxy|netem] [--protocol h2|h3] [--loss <pct>] [--netem-fallback egress-only] [--dry-run]';
}

function parseArgs(argv) {
  const options = { runs: 5, listing: 1, hints: 'on', net: 'toxiproxy', dryRun: false, inside: false, help: false };
  const names = new Set(['variant', 'path', 'path-reason', 'runs', 'listing', 'url', 'host', 'ip', 'hints', 'net', 'protocol', 'loss', 'netem-fallback']);
  for (let i = 0; i < argv.length; i++) {
    const arg = argv[i];
    if (arg === '--help' || arg === '-h') { options.help = true; continue; }
    if (arg === '--dry-run') { options.dryRun = true; continue; }
    if (arg === '--inside') { options.inside = true; continue; }
    const match = /^--([a-z-]+)(?:=(.*))?$/.exec(arg);
    if (!match || !names.has(match[1])) { fail(`unknown argument: ${arg}`); }
    const value = match[2] ?? argv[++i];
    if (value === undefined) { fail(`missing value for ${arg}`); }
    options[match[1].replace(/-(\w)/g, (_, c) => c.toUpperCase())] = value;
  }
  options.runs = Number(options.runs);
  options.listing = Number(options.listing);
  if (options.loss !== undefined) { options.loss = Number(options.loss); }
  return options;
}

function fail(message, code = 2) {
  process.stderr.write(`${message}\n${usage()}\n`);
  process.exit(code);
}

function sh(command, args, cwd) {
  const result = spawnSync(command, args, { cwd, encoding: 'utf8', maxBuffer: 64 * 1024 * 1024 });
  if (result.error || result.status !== 0) {
    process.stderr.write(result.stderr ?? '');
    fail(`${command} ${args.join(' ')} failed${result.error ? `: ${result.error.message}` : ` (exit ${result.status})`}`, 1);
  }
  return result.stdout.trim();
}

function summary(values) {
  const sorted = [...values].sort((a, b) => a - b);
  const mid = Math.floor(sorted.length / 2);
  const median = sorted.length % 2 ? sorted[mid] : (sorted[mid - 1] + sorted[mid]) / 2;
  return { median, min: sorted[0], max: sorted[sorted.length - 1] };
}

async function measureOnce(context, options) {
  const page = await context.newPage();
  const cdp = await context.newCDPSession(page);
  await cdp.send('Network.enable');
  let documentTiming = null;
  cdp.on('Network.responseReceived', (event) => {
    if (event.type === 'Document' && event.response.timing) { documentTiming = event.response.timing; }
  });
  await cdp.send('Emulation.setCPUThrottlingRate', { rate: THROTTLE.cpu_slowdown });
  await page.addInitScript(() => {
    window.__lcp = 0;
    new PerformanceObserver((list) => {
      for (const entry of list.getEntries()) { window.__lcp = entry.startTime; }
    }).observe({ type: 'largest-contentful-paint', buffered: true });
  });
  await page.goto(options.url, { waitUntil: 'load', timeout: 120000 });
  await page.waitForTimeout(1500);
  const sample = await page.evaluate((protocolRequested) => {
    const nav = performance.getEntriesByType('navigation')[0];
    const fcp = performance.getEntriesByName('first-contentful-paint')[0];
    const types = {};
    for (const entry of performance.getEntriesByType('resource')) {
      const path = new URL(entry.name).pathname;
      if (path.endsWith('.css')) { types.css = entry.initiatorType; }
      if (path.endsWith('.js')) { types.js = entry.initiatorType; }
    }
    const extra = protocolRequested
      ? { next_hop_protocol: nav.nextHopProtocol, load_ms: nav.loadEventEnd > 0 ? Math.round(nav.loadEventEnd) : null }
      : {};
    return {
      ...extra,
      lcp_ms: Math.round(window.__lcp),
      fcp_ms: fcp ? Math.round(fcp.startTime) : null,
      ttfb_nav_ms: Math.round(nav.responseStart),
      early_hint_used: Object.values(types).includes('early-hints'),
      initiator_types: types,
    };
  }, Boolean(options.protocol));
  if (!documentTiming) { throw new Error('no document response timing from CDP'); }
  sample.ttfb_ms = Math.round(documentTiming.receiveHeadersEnd);
  return sample;
}

async function measureInside(options) {
  const { chromium } = await import('playwright-core');
  // Chrome ignores Early Hints on a connection with a certificate error, so the leaf key is trusted by its SPKI hash
  // (--ignore-certificate-errors would make the hint look unused).
  const spki = execSync(
    `echo | openssl s_client -connect ${options.ip}:443 -servername ${options.host} 2>/dev/null | openssl x509 -pubkey -noout | openssl pkey -pubin -outform der | openssl dgst -sha256 -binary | base64`,
    { encoding: 'utf8' },
  ).trim();
  const args = [`--host-resolver-rules=MAP ${options.host} ${options.ip}`, `--ignore-certificate-errors-spki-list=${spki}`];
  if (options.protocol === 'h3') { args.push('--enable-quic', `--origin-to-force-quic-on=${options.host}:443`); }
  if (options.protocol === 'h2') { args.push('--disable-quic'); }
  const browser = await chromium.launch({ args });
  const runs = [];
  for (let i = 0; i < options.runs; i++) {
    const context = await browser.newContext();
    try {
      runs.push(await measureOnce(context, options));
    } catch (error) {
      if (!options.protocol) { throw error; }
      // netem mode: a failed navigation is one invalid attempt that the retry budget absorbs.
      runs.push({ error: String(error?.message ?? error).split('\n')[0].replace(/\d{1,3}(\.\d{1,3}){3}/g, '<ip>').slice(0, 160) });
    } finally {
      await context.close().catch(() => {});
    }
  }
  await browser.close();
  process.stdout.write(JSON.stringify({ runs }));
}

const options = parseArgs(process.argv.slice(2));

if (options.help) {
  process.stdout.write(`${usage()}\n  --variant      name of the measured variant (file name of the result)\n  --path         router or direct\n  --path-reason  why that path was chosen\n  --runs         cold runs (default 5)\n  --listing      listing id (default 1)\n  --dry-run      print the plan only\n`);
  process.exit(0);
}

if (options.inside) {
  await measureInside(options);
  process.exit(0);
}

if (!options.variant) { fail('--variant is required'); }
if (!/^[A-Za-z0-9._-]{1,40}$/.test(options.variant)) { fail('--variant must match [A-Za-z0-9._-]{1,40}: it names a result file'); }
if (!['toxiproxy', 'netem'].includes(options.net)) { fail('--net must be toxiproxy or netem'); }
if (options.net === 'netem') {
  if (options.path !== undefined && options.path !== 'direct') { fail('--net netem always goes straight to the web container: use --path direct or leave --path out (the router has no HTTP/3)'); }
  options.path = 'direct';
  options.pathReason ??= 'netem needs the direct path: the router has no HTTP/3';
}
if (!['router', 'direct'].includes(options.path)) { fail('--path must be router or direct'); }
if (!options.dryRun && !options.pathReason) { fail('--path-reason is required: record why this path was chosen'); }
if (!Number.isInteger(options.runs) || options.runs < 1) { fail('--runs must be a positive integer'); }
if (options.protocol !== undefined && !['h2', 'h3'].includes(options.protocol)) { fail('--protocol must be h2 or h3'); }
if (options.netemFallback !== undefined && options.netemFallback !== 'egress-only') { fail('--netem-fallback must be egress-only'); }
if (options.net !== 'netem' && (options.protocol !== undefined || options.loss !== undefined || options.netemFallback !== undefined)) { fail('--protocol, --loss and --netem-fallback need --net netem'); }
if (options.loss !== undefined && !(options.loss >= 0 && options.loss < 100)) { fail('--loss must be a percentage from 0 to below 100'); }
const netem = options.net === 'netem';
if (netem) { options.protocol ??= 'h2'; options.loss ??= 0; }

const appRoot = resolve(process.env.BENCH_APPROOT ?? resolve(scriptDir, '..'));
const image = process.env.PLAYWRIGHT_IMAGE ?? DEFAULT_IMAGE;
const toxiproxyImage = process.env.TOXIPROXY_IMAGE ?? DEFAULT_TOXIPROXY_IMAGE;
const day = process.env.BENCH_DATE ?? new Date().toISOString().slice(0, 10);
const baseUrl = sh('ddev', ['server', '--url'], appRoot);
const url = `${baseUrl}/listings/${options.listing}`;
const host = new URL(baseUrl).hostname;

const egressOnly = netem && options.netemFallback === 'egress-only';
const netemSh = process.env.NETEM_SH ?? resolve(scriptDir, 'lib/netem.sh');
const netemFallbackArgs = egressOnly ? ['--netem-fallback', 'egress-only'] : [];

function rateKbit(rate) {
  const match = /^(\d+(?:\.\d+)?)(kbit|mbit)$/i.exec(rate ?? '');
  return match ? Math.round(Number(match[1]) * (match[2].toLowerCase() === 'mbit' ? 1000 : 1)) : null;
}

// The throttle block comes from the profile netem.sh reports (the applied values in a real run, its defaults in a dry run), never from
// constants copied here.
function netemThrottle(profile) {
  const egressOnlyMode = profile.mode === 'egress-only';
  return {
    method: egressOnlyMode
      ? 'netem (kernel qdisc on the browser container network, egress only, no rate limit, network layer)'
      : 'netem (kernel qdisc on the browser container network, ingress via ifb, network layer)',
    rtt_ms: egressOnlyMode ? profile.delay_ms : profile.delay_ms * 2,
    down_kbps: rateKbit(profile.rate_down),
    up_kbps: rateKbit(profile.rate_up),
    cpu_slowdown: THROTTLE.cpu_slowdown,
    cpu_method: THROTTLE.cpu_method,
  };
}

if (options.dryRun) {
  let plan;
  if (netem) {
    const defaults = spawnSync('bash', [netemSh, ...netemFallbackArgs, 'defaults'], { cwd: appRoot, encoding: 'utf8', env: { ...process.env, NETEM_LOSS: String(options.loss) } });
    if (defaults.status !== 0) { fail(`netem.sh defaults failed: ${defaults.stderr}`, 1); }
    plan = { variant: options.variant, path: 'direct', runs: options.runs, hints: options.hints, url, image, protocol: options.protocol, net: 'netem', loss_pct: options.loss, throttle: netemThrottle(JSON.parse(defaults.stdout)) };
  } else {
    plan = { variant: options.variant, path: options.path, runs: options.runs, hints: options.hints, url, image, throttle_image: toxiproxyImage, throttle: THROTTLE };
  }
  process.stdout.write(`${JSON.stringify(plan, null, 2)}\n`);
  process.exit(0);
}

const server = sh('ddev', ['server', '--current'], appRoot);

function digestOf(imageName) {
  sh('docker', ['pull', imageName], appRoot);
  const found = sh('docker', ['image', 'inspect', '--format', '{{index .RepoDigests 0}}', imageName], appRoot);
  if (!found) { fail(`cannot read the digest of ${imageName}; refusing to run`, 1); }
  return found;
}

function containerIp(name) {
  const found = sh('docker', ['inspect', '-f', '{{(index .NetworkSettings.Networks "ddev_default").IPAddress}}', name], appRoot);
  if (!found) { fail(`cannot find the IP of ${name}`, 1); }
  return found;
}

// Same as sh() but asynchronous, so SIGINT and SIGTERM handlers run while a long container command is going.
function shAsync(command, args, cwd) {
  return new Promise((resolvePromise) => {
    const child = spawn(command, args, { cwd });
    let stdout = '';
    let stderr = '';
    child.stdout.on('data', (chunk) => { stdout += chunk; });
    child.stderr.on('data', (chunk) => { stderr += chunk; });
    child.on('error', (error) => fail(`${command} ${args.join(' ')} failed: ${error.message}`, 1));
    child.on('close', (code) => {
      if (code !== 0) {
        process.stderr.write(stderr);
        fail(`${command} ${args.join(' ')} failed (exit ${code})`, 1);
      }
      resolvePromise(stdout.trim());
    });
  });
}

const isValid = (r) => Number.isFinite(r.lcp_ms) && r.lcp_ms > 0 && Number.isFinite(r.fcp_ms) && r.fcp_ms > 0
  && (!netem || (r.next_hop_protocol === options.protocol && Number.isFinite(r.load_ms) && r.load_ms > 0));

const digest = digestOf(image);
const webName = `ddev-${process.env.DDEV_SITENAME ?? 'ai-lab'}-web`;
const toxiproxyDigest = netem ? null : digestOf(toxiproxyImage);
const upstream = options.path === 'direct' || netem ? webName : 'ddev-router';
const upstreamIp = containerIp(upstream);

const proxyName = `lcp-toxiproxy-${process.pid}`;
const cli = (...args) => sh('docker', ['exec', proxyName, '/go/bin/toxiproxy-cli', ...args], appRoot);
const clientName = `lcp-netem-${process.pid}`;
let output;
let netRuns = null;
let netProfile = null;
let webOffloadsTouched = false;

function restoreWebOffloads() {
  const restored = spawnSync('bash', [netemSh, 'web-offloads', 'restore', webName], { cwd: appRoot, encoding: 'utf8' });
  if (restored.status !== 0) {
    process.stderr.write(`WARNING: could not restore the offloads of ${webName}; run: bash bench/lib/netem.sh web-offloads restore ${webName}\n${restored.stderr}`);
  }
  return restored;
}

// fail() and the signal handlers call process.exit, which skips finally blocks, so everything is cleaned up from the exit handler:
// the proxy or the netem client container with its sidecar state, and the web container's offloads.
process.on('exit', () => {
  if (netem) {
    spawnSync('bash', [netemSh, ...netemFallbackArgs, 'clear', clientName], { cwd: appRoot, encoding: 'utf8' });
    spawnSync('docker', ['rm', '-f', clientName], { cwd: appRoot, encoding: 'utf8' });
    if (webOffloadsTouched) { restoreWebOffloads(); }
  } else {
    spawnSync('docker', ['rm', '-f', proxyName], { cwd: appRoot, encoding: 'utf8' });
  }
});
process.on('SIGINT', () => process.exit(130));
process.on('SIGTERM', () => process.exit(143));

const jsonFrom = (text, what) => {
  try {
    return JSON.parse(text.slice(text.indexOf('{')));
  } catch {
    return fail(`${what}`, 1);
  }
};

if (netem) {
  await shAsync('docker', ['run', '-d', '--name', clientName, '--network', 'ddev_default', '--ipc=host', '-v', `${scriptDir}:/bench:ro`, digest, 'sleep', 'infinity'], appRoot);
  await shAsync('docker', ['exec', clientName, 'bash', '-c', `mkdir -p /tmp/pw && cd /tmp/pw && cp /bench/lcp.mjs . && npm init -y >/dev/null 2>&1 && npm install --no-audit --no-fund playwright-core@${PLAYWRIGHT_VERSION} >/dev/null 2>&1`], appRoot);
  webOffloadsTouched = true;
  const webOff = spawnSync('bash', [netemSh, 'web-offloads', 'off', webName], { cwd: appRoot, encoding: 'utf8' });
  if (webOff.status !== 0) { fail(`cannot turn the segmentation offloads off on ${webName} (${webOff.stderr.trim().split('\n').pop()}); netem would drop whole aggregates, nothing written`, 1); }
  const webOffloads = jsonFrom(webOff.stdout, 'netem.sh web-offloads off did not return JSON');
  const applied = spawnSync('bash', [netemSh, ...netemFallbackArgs, 'apply', clientName], {
    cwd: appRoot,
    encoding: 'utf8',
    env: { ...process.env, NETEM_LOSS: String(options.loss), NETEM_PROBE_URL: baseUrl, NETEM_PROBE_RESOLVE: `${host}:443:${upstreamIp}` },
  });
  netProfile = jsonFrom(applied.stdout, 'netem.sh did not return a profile');
  if (!netProfile.applied) { fail(`netem was not applied (${netProfile.reason ?? 'unknown reason'}); nothing written`, 1); }
  netProfile.offloads = { ...(netProfile.offloads ?? {}), web: { ...webOffloads } };
  netRuns = [];
  const maxAttempts = options.runs + NETEM_EXTRA_ATTEMPTS;
  while (netRuns.filter(isValid).length < options.runs && netRuns.length < maxAttempts) {
    const batch = Math.min(options.runs - netRuns.filter(isValid).length, maxAttempts - netRuns.length);
    const batchOutput = await shAsync('docker', ['exec', clientName, 'bash', '-c', `cd /tmp/pw && node lcp.mjs --inside --url "${url}" --host "${host}" --ip "${upstreamIp}" --runs ${batch} --protocol ${options.protocol}`], appRoot);
    const parsed = jsonFrom(batchOutput, 'the browser container did not return JSON').runs;
    if (!Array.isArray(parsed) || parsed.length !== batch) { fail(`expected ${batch} runs, got ${parsed?.length ?? 0}`, 1); }
    netRuns.push(...parsed);
  }
} else {
  sh('docker', ['run', '-d', '--name', proxyName, '--network', 'ddev_default', '--platform', 'linux/amd64', toxiproxyDigest], appRoot);
  for (let attempt = 0; ; attempt++) {
    const ready = spawnSync('docker', ['exec', proxyName, '/go/bin/toxiproxy-cli', 'list'], { cwd: appRoot, encoding: 'utf8' });
    if (ready.status === 0) { break; }
    if (attempt >= 30) { fail('toxiproxy did not become ready', 1); }
    spawnSync('sleep', ['1']);
  }
  cli('create', 'site', '--listen', '0.0.0.0:443', '--upstream', `${upstreamIp}:443`);
  for (const toxic of THROTTLE.toxics) {
    const attribute = toxic.type === 'latency' ? `latency=${toxic.latency_ms}` : `rate=${toxic.rate_kb_per_s}`;
    cli('toxic', 'add', 'site', '--toxicName', toxic.name, '--type', toxic.type, '--attribute', attribute,
      toxic.direction === 'upstream' ? '--upstream' : '--downstream');
  }
  const proxyIp = containerIp(proxyName);
  const inner = `mkdir -p /tmp/pw && cd /tmp/pw && cp /bench/lcp.mjs . && npm init -y >/dev/null 2>&1 && npm install --no-audit --no-fund playwright-core@${PLAYWRIGHT_VERSION} >/dev/null 2>&1 && node lcp.mjs --inside --url "${url}" --host "${host}" --ip "${proxyIp}" --runs ${options.runs}`;
  output = sh('docker', ['run', '--rm', '--network', 'ddev_default', '--ipc=host', '-v', `${scriptDir}:/bench:ro`, digest, 'bash', '-c', inner], appRoot);
}

if (netem) {
  const drops = spawnSync('bash', [netemSh, ...netemFallbackArgs, 'drops', clientName], {
    cwd: appRoot,
    encoding: 'utf8',
    env: { ...process.env, NETEM_LOSS: String(options.loss) },
  });
  const dropProfile = jsonFrom(drops.stdout, 'netem.sh did not return the qdisc counters');
  if (drops.status !== 0) { fail(`netem loss check failed (${dropProfile.reason ?? 'unknown reason'}); nothing written`, 1); }
  netProfile = { ...netProfile, mode: dropProfile.mode, limit: dropProfile.limit, qdisc: dropProfile.qdisc };
  const restored = restoreWebOffloads();
  const restoredJson = jsonFrom(restored.stdout, 'netem.sh web-offloads restore did not return JSON');
  if (restored.status !== 0 || restoredJson.matches_before !== true) { fail(`the offloads of ${webName} were not restored to their previous values; nothing written`, 1); }
  netProfile.offloads.web.restored = restoredJson.restored;
}

let runs = netRuns;
if (!netem) {
  try {
    runs = JSON.parse(output.slice(output.indexOf('{'))).runs;
  } catch {
    fail('the browser container did not return JSON', 1);
  }
  if (!Array.isArray(runs) || runs.length !== options.runs) { fail(`expected ${options.runs} runs, got ${runs?.length ?? 0}`, 1); }
}

const samples = runs.map((r) => ({ ...r, valid: isValid(r) }));
const valid = samples.filter((r) => r.valid);
const invalidRuns = samples.length - valid.length;
const requiredRuns = netem ? options.runs : MIN_VALID_RUNS;
if (valid.length < requiredRuns) {
  fail(`only ${valid.length} valid runs of ${samples.length} attempts (${invalidRuns} invalid); need ${netem ? '' : 'at least '}${requiredRuns}, nothing written`, 1);
}

const commit = (() => {
  const result = spawnSync('git', ['rev-parse', '--short', 'HEAD'], { cwd: appRoot, encoding: 'utf8' });
  return result.status === 0 ? result.stdout.trim() : 'unknown';
})();

const result = {
  variant: options.variant,
  server,
  path: options.path,
  path_reason: options.pathReason,
  date: day,
  commit,
  listing: options.listing,
  url,
  runs: options.runs,
  ...(netem ? { attempts: samples.length } : {}),
  valid_runs: valid.length,
  invalid_runs: invalidRuns,
  early_hints_setting: options.hints,
  cold: true,
  browser_image: image,
  browser_image_digest: digest,
  playwright_version: PLAYWRIGHT_VERSION,
  ...(netem
    ? { protocol: options.protocol, net: netProfile, throttle: netemThrottle(netProfile) }
    : { throttle_image: toxiproxyImage, throttle_image_digest: toxiproxyDigest, throttle_upstream: upstream, throttle: THROTTLE }),
  lcp_ms: summary(valid.map((r) => r.lcp_ms)),
  fcp_ms: summary(valid.map((r) => r.fcp_ms)),
  ttfb_ms: summary(valid.map((r) => r.ttfb_ms)),
  ttfb_source: TTFB_SOURCE,
  ttfb_nav_ms: summary(valid.map((r) => r.ttfb_nav_ms)),
  early_hint_used: valid.some((r) => r.early_hint_used),
  early_hint_runs: valid.filter((r) => r.early_hint_used).length,
  ...(netem ? { load_ms: summary(valid.map((r) => r.load_ms)) } : {}),
  samples,
};

const outDir = resolve(appRoot, netem ? 'bench/results/http3' : 'bench/results/lcp', day);
mkdirSync(outDir, { recursive: true });
writeFileSync(resolve(outDir, `${netem ? 'lcp-' : ''}${options.variant}.json`), `${JSON.stringify(result, null, 2)}\n`);
process.stdout.write(`variant=${options.variant} server=${server} path=${options.path} lcp_median_ms=${result.lcp_ms.median} fcp_median_ms=${result.fcp_ms.median} ttfb_median_ms=${result.ttfb_ms.median} early_hint_used=${result.early_hint_used} out=${outDir}\n`);
