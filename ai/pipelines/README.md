# Verification Pipelines

## Overview

The verification pipeline orchestrates OCR, fraud detection, face matching, and
external provider API calls for a single driver verification session.

## Current Flow (apps/ai-verification)

```
POST /v1/driver-verifications/analyze
  │
  ├─ Validate Pydantic payload (session_id, driver, documents)
  ├─ For each document:
  │   ├─ extract_document()   → OCR + regex field extraction
  │   ├─ mismatches()         → compare extracted vs. registered values
  │   ├─ fraud_flags()        → rule-based anomaly checks
  │   └─ provider.checks_for_document()  → KYC provider API call
  ├─ face_similarity_score()  → ORB feature match (selfie vs. ID photo)
  └─ score_payload()          → compute final confidence + decision
```

## Async Pipeline (Celery)

When `RIDESYNC_VERIFICATION_ASYNC=true`, the PHP backend enqueues a
`ridesync.verify_driver_documents` Celery task instead of calling the HTTP
endpoint inline. The worker in `apps/ai-verification/app/worker.py` processes
the task and stores the result in the Celery backend (Redis).

## Planned Improvements

- Step-level retry with exponential backoff (OCR timeout should not fail the full pipeline)
- Pipeline result streaming via WebSocket for real-time admin progress updates
- Configurable pipeline stages (skip face match when only one photo is submitted)
- A/B test different scoring formulas without deploying new code (feature flags)
- Async OCR queuing: submit image, poll for result, continue pipeline
