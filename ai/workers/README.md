# AI Workers

## Overview

The AI worker layer processes verification jobs asynchronously so that
document analysis does not block the PHP HTTP request cycle.

## Current Worker (Celery)

Located in `apps/ai-verification/app/worker.py`.

The worker wraps the same `analyze()` function used by the HTTP endpoint,
ensuring identical validation and scoring logic for both sync and async paths.

### Task

| Task Name | Queue | Retry |
|-----------|-------|-------|
| `ridesync.verify_driver_documents` | `verification` | 3× with 30s delay |

### Starting the Worker

```bash
cd apps/ai-verification
celery -A app.worker worker --queues=verification --concurrency=2 --loglevel=info
```

Or via Docker Compose (handled by the `queue-worker` service in `docker-compose.yml`).

## PHP Queue Worker

PHP also has a queue worker (`tools/queue_worker.php`) that:
1. Reads `background_jobs` rows from MySQL
2. Dispatches verification jobs to the AI service via HTTP
3. Writes results back to `driver_verification_sessions`

Run it for development:
```bash
php tools/queue_worker.php --queue=verification --watch --limit=10 --sleep=2
```

## Planned Improvements

- Dead letter queue for jobs that exhaust retries
- Worker health metrics exposed to Prometheus
- Distributed locking so multiple workers do not process the same session
- Priority queue: manual admin retries get processed before background jobs
