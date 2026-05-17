# RideSync Production Runbook

## Release Gate

1. Run `npm test`.
2. Run `npm run test:syntax`.
3. Run `npm audit --omit=dev`.
4. Run `php tools/apply_schema_upgrade.php` against the target database.
5. Verify `/ridesync/api/live.php` returns `200`.
6. Verify `/ridesync/api/readiness.php` returns `200`.
7. Confirm GitHub Actions is unblocked and the `RideSync Quality Gate` passes.

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

Use liveness for container restart checks and readiness for load balancer membership.

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

## KYC Provider Cutover

The AI verification service defaults to `mock_compliance_provider`. To connect a real provider gateway:

```env
RIDESYNC_KYC_PROVIDER=idfy
RIDESYNC_KYC_PROVIDER_URL=https://provider-gateway.example.com/verify
RIDESYNC_KYC_PROVIDER_TOKEN=...
```

The provider endpoint must return either a JSON object or a `checks` array with `check_type`, `status`, `confidence`, and optional `response` fields. Raw Aadhaar, PAN, and license values must not be returned unmasked.
