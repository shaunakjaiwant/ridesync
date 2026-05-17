import http from 'k6/http';
import { check, sleep } from 'k6';

export const options = {
  thresholds: {
    http_req_failed: ['rate<0.01'],
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
  },
  scenarios: {
    public_smoke: {
      executor: 'constant-vus',
      vus: Number(__ENV.RIDESYNC_LOAD_VUS || 10),
      duration: __ENV.RIDESYNC_LOAD_DURATION || '1m',
    },
  },
};

const BASE_URL = __ENV.RIDESYNC_BASE_URL || 'http://127.0.0.1/ridesync';

export default function () {
  const responses = [
    http.get(`${BASE_URL}/api/live.php`),
    http.get(`${BASE_URL}/api/readiness.php`),
    http.get(`${BASE_URL}/index.php`),
    http.get(`${BASE_URL}/pages/login.php`),
    http.get(`${BASE_URL}/pages/driver_login.php`),
    http.get(`${BASE_URL}/pages/admin_login.php`),
  ];

  for (const response of responses) {
    check(response, {
      'status is 2xx or expected auth page': (res) => res.status >= 200 && res.status < 400,
      'has request id': (res) => Boolean(res.headers['X-Request-Id']),
    });
  }

  sleep(1);
}
