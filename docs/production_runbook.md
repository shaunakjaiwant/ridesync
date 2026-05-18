# RideSync Production Runbook

## Release Gate

1. Run `npm test`.
2. Run `npm run test:syntax`.
3. Run `npm audit --omit=dev`.
4. Run `npm run test:kyc-provider -- --required` after setting sandbox KYC variables.
5. Run `php tools/apply_schema_upgrade.php` against the target database.
6. Run `php tools/queue_worker.php --once --queue=verification` against staging.
7. Verify `/ridesync/api/live.php` returns `200`.
8. Verify `/ridesync/api/readiness.php` returns `200`.
9. Verify `/ridesync/api/metrics.php` returns Prometheus metrics with `Authorization: Bearer $RIDESYNC_METRICS_TOKEN`.
10. Confirm GitHub Actions is unblocked and the `RideSync Quality Gate` passes, unless explicitly waived for a non-production merge.

## Container Deployment

```bash
cp .env.example .env
docker compose build
docker compose up -d
curl -fsS http://127.0.0.1:8080/ridesync/api/readiness.php
```

Before starting the stack, replace every `replace-with-*` value in `.env`. Production Compose now fails fast unless `RIDESYNC_DB_PASSWORD`, `RIDESYNC_DB_ROOT_PASSWORD`, `RIDESYNC_DOCUMENT_ENCRYPTION_KEY`, `RIDESYNC_METRICS_TOKEN`, `RIDESYNC_WEBSOCKET_URL`, and `RIDESYNC_WS_SHARED_TOKEN` are set. `RIDESYNC_DOCUMENT_ENCRYPTION_KEY` must be a base64-encoded 32-byte key, for example `openssl rand -base64 32`.

`RIDESYNC_COOKIE_SECURE` defaults to `true` in Compose. Set it to `false` only for local HTTP-only container testing. If a reverse proxy terminates TLS, set `RIDESYNC_TRUST_PROXY=true` only when that proxy controls `X-Forwarded-*` headers.

The compose stack includes:

- `app`: PHP/Apache web application.
- `queue-worker`: async driver verification worker.
- `notification-worker`: async notification worker.
- `ai-verification`: FastAPI KYC analysis service.
- `websocket-gateway`: signed realtime event fan-out.
- `mysql` and `redis`: database and cache/queue infrastructure.

## Health Checks

- Liveness: `/ridesync/api/live.php`
- Readiness: `/ridesync/api/readiness.php`
- Legacy health: `/ridesync/api/health.php`
- Metrics: `/ridesync/api/metrics.php`
- WebSocket gateway: `http://127.0.0.1:8081/health`

Use liveness for container restart checks and readiness for load balancer membership.

## Workers And Realtime

Run workers under Docker Compose, systemd, or a process supervisor:

```bash
npm run worker:verification
npm run worker:notifications
npm run worker:maintenance
```

For systemd-based hosts:

```bash
sudo cp ops/systemd/ridesync-queue-worker.service /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ridesync-queue-worker.service
```

WebSocket clients request a short-lived token from `/ridesync/api/realtime_token.php` and connect to `RIDESYNC_WEBSOCKET_URL`. Keep `RIDESYNC_WS_SHARED_TOKEN` at least 32 random characters, keep it secret, and rotate it during incident response.

## Monitoring And Alerting

Start the app with Prometheus and Alertmanager:

```bash
docker compose -f docker-compose.yml -f docker-compose.monitoring.yml up -d
```

Prometheus: `http://127.0.0.1:9090`
Alertmanager: `http://127.0.0.1:9093`

Alert rules are stored in `ops/monitoring/alerts.yml`. Replace the placeholder webhook in `ops/monitoring/alertmanager.yml` with Slack, PagerDuty, Teams, or your incident webhook before production.

Prometheus receives `RIDESYNC_METRICS_TOKEN` as a Docker secret and sends it as a bearer token. If metrics scrape targets show `403`, confirm the app and monitoring stack were started with the same token value.

## Backups

```bash
RIDESYNC_DB_PASSWORD=... ./ops/backup_mysql.sh
```

Backups are compressed and accompanied by a `.sha256` checksum. Store copies outside the application host.

## Restore Drill

```bash
RIDESYNC_DB_PASSWORD=... ./ops/restore_mysql.sh storage/backups/ridesync_db_YYYYMMDDTHHMMSSZ.sql.gz
php tools/apply_schema_upgrade.php
curl -fsS http://127.0.0.1/ridesync/api/readiness.php
```

Run a restore drill before every major production release and at least monthly.

## Backup Scheduling

Cron template:

```bash
crontab ops/cron/ridesync-backup.cron
crontab ops/cron/ridesync-maintenance.cron
```

Systemd timer template:

```bash
sudo cp ops/systemd/ridesync-backup.* /etc/systemd/system/
sudo systemctl daemon-reload
sudo systemctl enable --now ridesync-backup.timer
```

Create `/etc/ridesync/backup.env` with database credentials and restrict it to root or the deploy user.

## KYC Provider Cutover

The AI verification service defaults to `mock_compliance_provider`. To connect a real provider gateway:

```env
RIDESYNC_KYC_PROVIDER=idfy
RIDESYNC_KYC_PROVIDER_URL=https://provider-gateway.example.com/verify
RIDESYNC_KYC_PROVIDER_TOKEN=...
```

The provider endpoint must return either a JSON object or a `checks` array with `check_type`, `status`, `confidence`, and optional `response` fields. Raw Aadhaar, PAN, and license values must not be returned unmasked.

Validate the sandbox contract:

```bash
RIDESYNC_KYC_PROVIDER=idfy \
RIDESYNC_KYC_PROVIDER_URL=https://provider-gateway.example.com/verify \
RIDESYNC_KYC_PROVIDER_TOKEN=... \
npm run test:kyc-provider -- --required
```

## Large Dataset And Load Testing

Seed production-like synthetic data in a non-production database:

```bash
php tools/seed_large_dataset.php --reset --users=1000 --drivers=250 --rides=5000 --matches-per-ride=2 --demand=1000
```

Run local load smoke:

```bash
npm run test:load
```

Run production-style k6 load:

```bash
RIDESYNC_BASE_URL=https://staging.example.com/ridesync \
RIDESYNC_LOAD_VUS=250 \
RIDESYNC_STEADY=10m \
npm run test:load:production
```

For distributed execution, run the same `tests/load/k6-production.js` script through k6 Cloud, Grafana Cloud k6, or multiple regional runners and compare p95/p99 latency, error rate, and database CPU.

## API Contract And Negative Tests

The API contract is stored at `docs/openapi.yaml`. Treat it as the source of truth for `/ridesync/api/*.php` JSON, SSE, and metrics endpoints.

Run the unauthenticated negative API suite against a running local or staging app:

```bash
RIDESYNC_BASE_URL=http://127.0.0.1/ridesync npm run test:api:negative
```

The suite checks public probes, method rejection, auth boundaries, malformed limits, response content types, request ids, and that error responses do not leak PHP, SQL, or stack traces.

## DAST And Screen Readers

Use `docs/dast_security_test_plan.md` for OWASP ZAP baseline, authenticated, and active scan evidence. Use only disposable staging data for active scans.

Use `docs/screen_reader_test_plan.md` for NVDA, VoiceOver, and TalkBack manual accessibility evidence. Production release requires 0 critical/high findings in both DAST and manual screen-reader passes.

## Physical Device Lab

Use `docs/device_lab_test_plan.md` for Android, iPhone, iPad, and Safari validation. Production release requires real device evidence, not only browser emulation.
