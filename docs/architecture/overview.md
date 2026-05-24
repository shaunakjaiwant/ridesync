# RideSync Architecture

RideSync now uses explicit app, backend, realtime, AI, infrastructure, database, docs, and tools boundaries.

## Request Flow

All new PHP work should follow this flow:

`Route -> Controller -> Service -> Repository -> Database`

Routes remain thin public entrypoints under `api/`, `actions/`, or `pages/`. Controllers live in `backend/controllers`, business workflows in `backend/services`, and SQL access in `backend/repositories`.

## Runtime Boundaries

- `apps/web`: rider-facing web application boundary.
- `apps/admin`: admin panel boundary.
- `apps/api`: canonical API app boundary for `/api/v1`.
- `apps/ai-verification`: FastAPI AI verification service.
- `backend`: PHP controllers, services, repositories, policies, validators, DTOs, events, jobs, and contracts.
- `realtime/websocket-gateway`: Node websocket fan-out service.
- `realtime/pubsub`: Redis pub/sub bridge for realtime events.
- `infrastructure`: Apache, monitoring, backup, cron, systemd, and future deployment assets.
- `database`: schema, seeds, migrations, procedures, backups, and optimization artifacts.
- `tools`: diagnostics, repair, migration, and automation scripts.

## Realtime Flow

PHP services write durable events to `realtime_events` and publish the same event to Redis channel `ridesync:realtime_events` when Redis is configured. The websocket gateway subscribes to Redis for low-latency fan-out and keeps DB polling as a recovery path.

## API Flow

Canonical APIs live under `/api/v1` and use:

- `includes/api_helper.php` for response envelopes, validation helpers, bearer token handling, and rate limiting.
- backend controllers for request orchestration.
- backend policies for authorization.
- backend validators for request shape.
- backend services and repositories for business logic and SQL.

## Legacy Compatibility Rule

Legacy public URLs can remain stable, but new logic must move inward. Thin wrappers are acceptable at the route/action layer; raw business workflows should not be added to page or action files.
