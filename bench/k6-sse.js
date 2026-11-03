import http from 'k6/http';
import { Rate, Trend } from 'k6/metrics';

const duration = __ENV.DURATION || '60s';
const vus = parseInt(__ENV.VUS || '30', 10);
const idOffset = parseInt(__ENV.ID_OFFSET || '0', 10);
const listings = 50000;

export const options = {
  scenarios: {
    stream: { executor: 'constant-vus', vus, duration, gracefulStop: '30s' },
  },
  insecureSkipTLSVerify: true,
  summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)'],
};

// Time from sending the request to the end of a stream that carried data: [DONE]; incomplete streams are not timed.
const streamDone = new Trend('stream_done_ms', true);
// One value per stream (per iteration), true when the status is not 200 or [DONE] never arrived. The error share is
// taken from this metric, never from per-check counts, so a stream that fails twice still counts once.
const streamFailed = new Rate('stream_failed');

// Closed loop: each virtual user reads one whole stream to its end, then opens the next one.
// Every request takes the next listing id of 1..50000, starting at ID_OFFSET, so no two concurrent or consecutive
// streams share one.
export default function () {
  const id = (idOffset + (__VU - 1) + __ITER * vus) % listings + 1;
  const response = http.get(`${__ENV.BASE_URL}/listings/${id}/describe`, {
    headers: { Accept: 'text/event-stream' },
    timeout: '60s',
  });
  const done = typeof response.body === 'string' && response.body.indexOf('data: [DONE]') !== -1;
  const failed = response.status !== 200 || !done;
  streamFailed.add(failed);
  if (!failed) {
    streamDone.add(response.timings.duration);
  }
}
