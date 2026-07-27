from __future__ import annotations

import os
from typing import Any

from celery import Celery

from .main import AnalyzePayload, analyze


redis_url = os.getenv("RIDESYNC_REDIS_URL", "redis://127.0.0.1:6379/2")
celery_app = Celery("ridesync_verification", broker=redis_url, backend=redis_url)


@celery_app.task(name="ridesync.verify_driver_documents", bind=True, max_retries=3, default_retry_delay=30)
def verify_driver_documents(self, payload: dict[str, Any]) -> dict[str, Any]:
    """Celery entrypoint for async verification processing.

    PHP creates a `driver_verification_sessions` row and dispatches this task
    when Redis/Celery is enabled. The result is stored in the Celery backend
    and can be polled by a PHP worker or a result callback.

    The task validates the incoming payload using the same Pydantic model as
    the synchronous HTTP endpoint, ensuring identical input constraints.
    """
    try:
        validated = AnalyzePayload.model_validate(payload)
        return analyze(validated)
    except Exception as exc:
        raise self.retry(exc=exc)
