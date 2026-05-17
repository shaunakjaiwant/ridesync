# RideSync Production Runbook

## Release Gate

1. Run `npm test`.
2. Run `npm run test:syntax`.
3. Run `npm audit --omit=dev`.
4. Run `npm run test:kyc-provider -- --required` after setting sandbox KYC variables.
5. Run `php tools/apply_schema_upgrade.php` against the target database.
6. Verify `/ridesync/api/live.php` returns `200`.
7. Verify `/ridesync/api/readiness.php` returns `200`.
8. Verify `/ridesync/api/metrics.php` returns Prometheus metrics.
9. Confirm GitHub Actions is unblocked and the `RideSync Quality Gate` passes, unless explicitly waived for a non-production merge.

## Container Deployment

```bash
cp .env.example .env
docker compose build
docker compose up -d
curl -fsS http://127.0.0.1:8080/ridesync/api/readiness.php
```

Set `RIDESYNC_COOKIE_SECURE=true` when the app is served only over HTTPS. If a reverse proxy terminates TLS, set `RIDESYNC_TRUST_PROXY=true` only when that proxy controls `X-Forwarded-*` headers.

## Health Checks

- Liveness: `/ridesync/api/live.php`
- Readiness: `/ridesync/api/readiness.php`
- Legacy health: `/ridesync/api/health.php`
- Metrics: `/ridesync/api/metrics.php`

Use liveness for container restart checks and readiness for load balancer membership.

## Monitoring And Alerting

Start the app with Prometheus and Alertmanager:

```bash
docker compose -f docker-compose.yml -f docker-compose.monitoring.yml up -d
```

Prometheus: `http://127.0.0.1:9090`
Alertmanager: `http://127.0.0.1:9093`

Alert rules are stored in `ops/monitoring/alerts.yml`. Replace the placeholder webhook in `ops/monitoring/alertmanager.yml` with Slack, PagerDuty, Teams, or your incident webhook before production.

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

## Physical Device Lab

Use `docs/device_lab_test_plan.md` for Android, iPhone, iPad, and Safari validation. Production release requires real device evidence, not only browser emulation.
