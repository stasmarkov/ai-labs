// Runs inside the pinned node container of bench/mercure-load.sh. No dependencies: Node's built-in fetch and fs only.
// Opens COUNT public subscribers on TOPIC plus the check subscribers in CHECKS, holds them, and on the file OUT/stop
// writes OUT/result.json. OUT/ready.json is written once every connection has been answered or has failed.
// Env: HUB (hub URL), TOPIC, COUNT, CHECKS (JSON list of {name, topic, token}), OUT (directory),
//      RAMP_BATCH (connections opened per step, default 50), RAMP_PAUSE_MS (default 100), CONNECT_TIMEOUT_MS (default 15000).
// A subscriber counts as connected when the hub answers 200 with content-type text/event-stream.
// Latency of a message is Date.now() on receipt minus published_at_ms in its data, taken by PHP right before the POST;
// the PHP and Node containers read the same host clock, so the difference is meaningful.
import { existsSync, writeFileSync } from 'node:fs';

const env = process.env;
const hub = env.HUB;
const topic = env.TOPIC;
const count = Number(env.COUNT);
const checks = JSON.parse(env.CHECKS || '[]');
const out = env.OUT;
const batch = Number(env.RAMP_BATCH || 50);
const pause = Number(env.RAMP_PAUSE_MS || 100);
const connectTimeout = Number(env.CONNECT_TIMEOUT_MS || 15000);

const latencies = [];
const failures = {};

function subscribe(subscription) {
  const state = { ...subscription, status: 0, contentType: '', open: false, received: 0, error: null, controller: new AbortController() };
  state.headers = new Promise((resolve) => {
    state.run = (async () => {
      const timer = setTimeout(() => state.controller.abort(), connectTimeout);
      try {
        const headers = { Accept: 'text/event-stream' };
        if (state.token) {
          headers.Authorization = `Bearer ${state.token}`;
        }
        const response = await fetch(`${hub}?topic=${encodeURIComponent(state.topic)}`, { headers, signal: state.controller.signal });
        clearTimeout(timer);
        state.status = response.status;
        state.contentType = response.headers.get('content-type') || '';
        state.open = response.status === 200 && state.contentType.startsWith('text/event-stream');
        resolve();
        if (!state.open) {
          await response.body?.cancel();
          return;
        }
        await read(state, response.body.getReader());
      } catch (error) {
        clearTimeout(timer);
        if (!state.open) {
          state.error = error.cause?.code || error.name || 'error';
        }
        resolve();
      }
    })();
  });
  return state;
}

async function read(state, reader) {
  const decoder = new TextDecoder();
  let buffer = '';
  let data = [];
  for (;;) {
    const { done, value } = await reader.read();
    if (done) {
      return;
    }
    buffer += decoder.decode(value, { stream: true });
    let newline;
    while ((newline = buffer.indexOf('\n')) !== -1) {
      const line = buffer.slice(0, newline).replace(/\r$/, '');
      buffer = buffer.slice(newline + 1);
      if (line === '') {
        if (data.length > 0) {
          deliver(state, data.join('\n'));
        }
        data = [];
      } else if (line.startsWith('data:')) {
        data.push(line.slice(5).replace(/^ /, ''));
      }
    }
  }
}

function deliver(state, payload) {
  state.received += 1;
  if (!state.isCheck) {
    try {
      latencies.push(Date.now() - JSON.parse(payload).published_at_ms);
    } catch {
      latencies.push(null);
    }
  }
}

const subscribers = [];
for (let opened = 0; opened < count; opened += batch) {
  const group = [];
  for (let i = opened; i < Math.min(count, opened + batch); i += 1) {
    group.push(subscribe({ topic }));
  }
  subscribers.push(...group);
  await Promise.all(group.map((state) => state.headers));
  await new Promise((resolve) => setTimeout(resolve, pause));
}
const checkers = checks.map((check) => subscribe({ ...check, isCheck: true }));
await Promise.all(checkers.map((state) => state.headers));

for (const state of subscribers) {
  if (!state.open) {
    const reason = state.error || `http_${state.status}`;
    failures[reason] = (failures[reason] || 0) + 1;
  }
}
writeFileSync(`${out}/ready.json`, JSON.stringify({ connected: subscribers.filter((s) => s.open).length, failed: Object.values(failures).reduce((a, b) => a + b, 0) }));

while (!existsSync(`${out}/stop`)) {
  await new Promise((resolve) => setTimeout(resolve, 200));
}

const received = subscribers.filter((s) => s.open).map((s) => s.received);
writeFileSync(`${out}/result.json`, JSON.stringify({
  requested: count,
  connected: received.length,
  failed: count - received.length,
  failures,
  messages: received.reduce((a, b) => a + b, 0),
  received_min: received.length ? Math.min(...received) : null,
  received_max: received.length ? Math.max(...received) : null,
  latencies_ms: latencies,
  checks: checkers.map((s) => ({ name: s.name, status: s.status, content_type: s.contentType, open: s.open, received: s.received })),
}));
for (const state of [...subscribers, ...checkers]) {
  state.controller.abort();
}
process.exit(0);
