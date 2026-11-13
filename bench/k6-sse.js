import http from 'k6/http';
import { sleep } from 'k6';
import { Counter, Rate, Trend } from 'k6/metrics';

const duration = __ENV.DURATION || '60s';
// ITERATIONS switches to real mode (bench/llm-load.sh): exactly that many streams, shared by the VUS, and the extra
// per-stream metrics below. Without it the script is the closed-loop duration run it always was.
const iterations = parseInt(__ENV.ITERATIONS || '0', 10);
const real = iterations > 0;
const requestTimeout = __ENV.REQUEST_TIMEOUT || '60s';
const vus = parseInt(__ENV.VUS || '30', 10);
const idOffset = parseInt(__ENV.ID_OFFSET || '0', 10);
const listings = 50000;
// HOST_HEADER (bench/llm-load.sh search-under-load): BASE_URL is the web container itself, reached by its service name over
// ddev_default, and the Host header names the site. The name is looked up again every second, so a `ddev restart` that gives
// the container a new address cannot leave the loop talking to nothing.
const hostHeader = __ENV.HOST_HEADER || '';

export const options = {
  scenarios: {
    stream: real
      ? { executor: 'shared-iterations', vus, iterations, maxDuration: '2h', gracefulStop: '30s' }
      : { executor: 'constant-vus', vus, duration, gracefulStop: '30s' },
  },
  insecureSkipTLSVerify: true,
  // Duration mode keeps the summary its callers have always read; min and max are for real mode only.
  ...(hostHeader ? { dns: { ttl: '1s' } } : {}),
  summaryTrendStats: real ? ['avg', 'min', 'med', 'p(95)', 'p(99)', 'max'] : ['avg', 'med', 'p(95)', 'p(99)'],
};

// Time from sending the request to the end of a stream that carried data: [DONE]; incomplete streams are not timed.
const streamDone = new Trend('stream_done_ms', true);
// One value per stream (per iteration), true when the status is not 200 or [DONE] never arrived. The error share is
// taken from this metric, never from per-check counts, so a stream that fails twice still counts once.
const streamFailed = new Rate('stream_failed');
// Real mode only. The time to first byte of a complete stream: the stream opens when Ollama sends its first chunk, so
// this is the wait for the prompt to be evaluated and the first token to arrive, not the time of the whole response.
const streamTtfb = new Trend('stream_ttfb_ms', true);
const streamTextEvents = new Counter('stream_text_events_total');
// Text events per second of the generation window only: from the first byte (the first chunk) to [DONE]. The wait before
// the first byte (queue wait plus prompt evaluation) is stream_ttfb_ms and is not part of the rate.
const streamEventsPerSecond = new Trend('stream_events_per_s');
const streamGeneration = new Trend('stream_generation_ms', true);
const streamRateLimited = new Counter('stream_rate_limited');
// Real mode: attempts that got no answer at all (status 0, the server was restarting). They are also in stream_failed;
// a caller that wants the load share leaves them out.
const streamUnreachable = new Counter('stream_unreachable');
// Wall-clock epoch milliseconds of the first request and the last end, to turn the event total into events per second.
const streamStartedAt = new Trend('stream_started_at_ms');
const streamEndedAt = new Trend('stream_ended_at_ms');

function countOccurrences(body, needle) {
  let count = 0;
  let at = body.indexOf(needle);
  while (at !== -1) {
    count += 1;
    at = body.indexOf(needle, at + needle.length);
  }
  return count;
}

// Closed loop: each virtual user reads one whole stream to its end, then opens the next one.
// Every request takes the next listing id of 1..50000, starting at ID_OFFSET, so no two concurrent or consecutive
// streams share one.
export default function () {
  const id = (idOffset + (__VU - 1) + __ITER * vus) % listings + 1;
  const response = http.get(`${__ENV.BASE_URL}/listings/${id}/describe`, {
    headers: hostHeader ? { Accept: 'text/event-stream', Host: hostHeader } : { Accept: 'text/event-stream' },
    timeout: requestTimeout,
  });
  const body = typeof response.body === 'string' ? response.body : '';
  const done = body.indexOf('data: [DONE]') !== -1;
  // A stream that carried an error or refused event is incomplete in real mode, even when [DONE] follows.
  const errored = real && (body.indexOf('"type":"error"') !== -1 || body.indexOf('"type":"refused"') !== -1);
  const failed = response.status !== 200 || !done || errored;
  streamFailed.add(failed);
  if (real && response.status === 0) {
    // The server is not answering (a restart): wait instead of counting thousands of instant failures.
    streamUnreachable.add(1);
    sleep(1);
  }
  if (!failed) {
    streamDone.add(response.timings.duration);
  }
  if (real) {
    const endedAt = Date.now();
    streamStartedAt.add(endedAt - response.timings.duration);
    streamEndedAt.add(endedAt);
    if (response.status === 429) {
      streamRateLimited.add(1);
    }
    if (!failed) {
      const events = countOccurrences(body, '"type":"text_delta"');
      streamTtfb.add(response.timings.waiting);
      streamTextEvents.add(events);
      const generationMs = response.timings.duration - response.timings.waiting;
      if (generationMs > 0) {
        streamGeneration.add(generationMs);
        streamEventsPerSecond.add(events / (generationMs / 1000));
      }
    }
  }
}
