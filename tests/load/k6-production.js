import http from 'k6/http';
import { check, group, sleep } from 'k6';

export const options = {
  thresholds: {
    http_req_failed: ['rate<0.02'],
    http_req_duration: ['p(95)<1500', 'p(99)<3000'],
    checks: ['rate>0.98'],
  },
  scenarios: {
    browse_and_health: {
      executor: 'ramping-vus',
      stages: [
        { duration: __ENV.RIDESYNC_RAMP_UP || '2m', target: Number(__ENV.RIDESYNC_LOAD_VUS || 100) },
        { duration: __ENV.RIDESYNC_STEADY || '5m', target: Number(__ENV.RIDESYNC_LOAD_VUS || 100) },
        { duration: __ENV.RIDESYNC_RAMP_DOWN || '1m', target: 0 },
      ],
      gracefulRampDown: '30s',
    },
  },
};

const BASE_URL = (__ENV.RIDESYNC_BASE_URL || 'http://127.0.0.1/ridesync').replace(/\/$/, '');

function assertPage(response, name) {
  check(response, {
    [`${name} status < 400`]: (res) => res.status > 0 && res.status < 400,
    [`${name} request id present`]: (res) => Boolean(res.headers['X-Request-Id']),
    [`${name} CSP present`]: (res) => Boolean(res.headers['Content-Security-Policy']),
  });
}

export default function () {
  group('health', () => {
    const live = http.get(`${BASE_URL}/api/live.php`);
    const ready = http.get(`${BASE_URL}/api/readiness.php`);
    assertPage(live, 'live');
    assertPage(ready, 'readiness');
    check(ready, {
      'readiness is ready': (res) => res.status === 200 && String(res.body).includes('"status":"ready"'),
    });
  });

  group('public navigation', () => {
    for (const path of ['/index.php', '/pages/login.php', '/pages/driver_login.php', '/pages/admin_login.php']) {
      assertPage(http.get(`${BASE_URL}${path}`), path);
    }
  });

  group('search surfaces', () => {
    const routes = [
      '/pages/search_rides.php?origin=SDMIT&destination=Mangaluru',
      '/pages/search_rides.php?origin=Mangaluru&destination=Ujire',
      '/pages/search_rides.php?origin=Manipal&destination=Mangaluru',
    ];
    const path = routes[(__VU + __ITER) % routes.length];
    const response = http.get(`${BASE_URL}${path}`);
    check(response, {
      'protected search redirects or loads': (res) => [200, 302].includes(res.status),
    });
  });

  sleep(Number(__ENV.RIDESYNC_USER_THINK_SECONDS || 1));
}
