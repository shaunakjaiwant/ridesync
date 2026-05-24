from __future__ import annotations

import os
from typing import Any

from celery import Celery

from .main import AnalyzePayload, analyze


redis_url = os.getenv("RIDESYNC_REDIS_URL", "redis://127.0.0.1:6379/2")
celery_app = Celery("ridesync_verification", broker=redis_url, backend=redis_url)


@celery_app.task(name="ridesync.verify_driver_documents")
def verify_driver_documents(payload: dict[str, Any]) -> dict[str, Any]:
    """Celery entrypoint for production async processing.

    PHP can continue to create `driver_verification_sessions` rows and dispatch
    this task when Redis is enabled. The returned payload is intentionally
    masked and can be persisted by a callback or polling worker.
    """

    return analyze(AnalyzePayload.model_validate(payload))
