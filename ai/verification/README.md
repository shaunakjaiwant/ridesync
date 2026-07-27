# Verification Domain

## Overview

Driver verification ensures that only drivers with valid documents, a
matching selfie, and passing fraud checks can be approved on the platform.

## Verification Lifecycle

```
Driver submits documents
         │
         ▼
admin triggers AI verification (actions/admin_action.php → driver_ai_verification_start)
         │
         ▼
PHP creates driver_verification_sessions row (status=queued)
         │
    ASYNC? ──yes──► PHP enqueues Celery task (background_jobs table)
         │               │
         │               ▼
         │         apps/ai-verification Celery worker picks up task
         │
    no (inline) ──► PHP calls POST /v1/driver-verifications/analyze directly
         │
         ▼
AI service returns { status, confidence_score, scores, reasons, face_match, … }
         │
         ▼
PHP worker/handler writes result to driver_verification_sessions (status=verified|suspicious|…)
         │
         ▼
Admin reviews in dashboard (api/driver_verification_status.php SSE stream)
         │
         ▼
Admin decision: approved | rejected | escalated  (actions/admin_action.php)
```

## Decision States

| State | Meaning |
|-------|---------|
| `queued` | Session created, not yet processed |
| `processing` | AI service has received the task |
| `verified` | confidence ≥ 85, no high-severity flags, selfie present |
| `suspicious` | confidence 65–84, selfie present but concerns found |
| `needs_manual_review` | confidence 50–64, no critical flags — admin must decide |
| `fake_tampered` | confidence < 50 or critical fraud flag detected |
| `failed` | Service error or job timeout |
| `cancelled` | Admin cancelled before processing |

## Configuration

```env
RIDESYNC_VERIFICATION_ASYNC=true           # use Celery queue (recommended in production)
RIDESYNC_VERIFICATION_INLINE_FALLBACK=true # fall back to inline when queue is down
RIDESYNC_VERIFICATION_SERVICE_URL=http://ai-verification:8011
RIDESYNC_VERIFICATION_SERVICE_TOKEN=replace-with-random-token
```
