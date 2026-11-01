import http from 'k6/http';
import exec from 'k6/execution';

const duration = __ENV.DURATION || '60s';

export const options = {
  scenarios: {
    search: { executor: 'constant-vus', vus: 50, duration },
  },
  insecureSkipTLSVerify: true,
  summaryTrendStats: ['avg', 'med', 'p(95)', 'p(99)'],
};

export default function () {
  const page = (exec.scenario.iterationInInstance % 100) + 1;
  http.get(`${__ENV.BASE_URL}/listings?make=BMW&page=${page}`, { timeout: '30s' });
}
