from __future__ import annotations

import argparse
import json
import os
import re
import sys
import time
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parents[2]))

from ai_verification_service.app.providers import ProviderRequestError, post_provider_json  # noqa: E402


ALLOWED_STATUSES = {"passed", "failed", "needs_review", "not_available"}
SENSITIVE_PATTERNS = {
    "aadhaar": re.compile(r"\b[2-9][0-9]{3}\s?[0-9]{4}\s?[0-9]{4}\b"),
    "pan": re.compile(r"\b[A-Z]{5}[0-9]{4}[A-Z]\b"),
}


def sample_payload(provider: str) -> dict[str, Any]:
    return {
        "provider": provider,
        "document": {
            "id": 9001,
            "document_type": "license",
            "is_file": True,
            "reference_fingerprint": "sandbox-fingerprint",
            "mime": "image/png",
        },
        "driver": {
            "name": "Sandbox Driver",
            "license_number": "******1234",
            "vehicle_number": "******1234",
            "vehicle_type": "Car",
        },
        "extracted": {
            "full_name": "Sandbox Driver",
            "license_number": "******1234",
            "vehicle_registration_number": "******1234",
        },
    }


def post_json(url: str, token: str, payload: dict[str, Any], timeout: float) -> tuple[int, dict[str, Any], float]:
    started = time.perf_counter()
    status, decoded = post_provider_json(url, payload, token, timeout)
    elapsed_ms = round((time.perf_counter() - started) * 1000, 2)
    return status, decoded, elapsed_ms


def checks_from_response(response: dict[str, Any]) -> list[dict[str, Any]]:
    checks = response.get("checks")
    if isinstance(checks, list):
        return [check for check in checks if isinstance(check, dict)]
    return [response]


def validate_response(response: dict[str, Any], elapsed_ms: float, max_latency_ms: float) -> list[str]:
    failures: list[str] = []
    checks = checks_from_response(response)

    if not checks:
        failures.append("provider returned no checks")

    for index, check in enumerate(checks):
        prefix = f"check[{index}]"
        check_type = str(check.get("check_type") or check.get("type") or "")
        status = str(check.get("status") or "").lower().replace("-", "_").replace(" ", "_")

        if not check_type:
            failures.append(f"{prefix} missing check_type")
        if status not in ALLOWED_STATUSES:
            failures.append(f"{prefix} has invalid status {status!r}")

        confidence = check.get("confidence", check.get("confidence_score", None))
        try:
            confidence_value = float(confidence)
        except (TypeError, ValueError):
            failures.append(f"{prefix} confidence is not numeric")
        else:
            if not 0 <= confidence_value <= 100:
                failures.append(f"{prefix} confidence outside 0..100")

    serialized = json.dumps(response, ensure_ascii=False)
    for label, pattern in SENSITIVE_PATTERNS.items():
        if pattern.search(serialized.upper()):
            failures.append(f"response appears to expose raw {label} value")

    if elapsed_ms > max_latency_ms:
        failures.append(f"provider latency {elapsed_ms} ms exceeds {max_latency_ms} ms")

    return failures


def main() -> int:
    parser = argparse.ArgumentParser(description="Validate RideSync KYC provider sandbox response contract.")
    parser.add_argument("--url", default=os.getenv("RIDESYNC_KYC_PROVIDER_URL", ""))
    parser.add_argument("--token", default=os.getenv("RIDESYNC_KYC_PROVIDER_TOKEN", ""))
    parser.add_argument("--provider", default=os.getenv("RIDESYNC_KYC_PROVIDER", "sandbox_provider"))
    parser.add_argument("--timeout", type=float, default=float(os.getenv("RIDESYNC_KYC_PROVIDER_TIMEOUT_SECONDS", "6")))
    parser.add_argument("--max-latency-ms", type=float, default=float(os.getenv("RIDESYNC_KYC_PROVIDER_MAX_LATENCY_MS", "2500")))
    parser.add_argument("--required", action="store_true", help="Fail when no provider URL is configured.")
    args = parser.parse_args()

    if not args.url:
        result = {
            "ok": not args.required,
            "skipped": True,
            "reason": "RIDESYNC_KYC_PROVIDER_URL is not configured",
        }
        print(json.dumps(result, indent=2))
        return 1 if args.required else 0

    try:
        status, response, elapsed_ms = post_json(args.url, args.token, sample_payload(args.provider), args.timeout)
    except (ProviderRequestError, OSError, TimeoutError, ValueError, json.JSONDecodeError) as exc:
        print(json.dumps({
            "ok": False,
            "provider": args.provider,
            "error": exc.__class__.__name__,
            "message": str(exc),
        }, indent=2))
        return 1

    failures = validate_response(response, elapsed_ms, args.max_latency_ms)
    print(json.dumps({
        "ok": status < 400 and not failures,
        "provider": args.provider,
        "status_code": status,
        "elapsed_ms": elapsed_ms,
        "failures": failures,
        "checks_seen": len(checks_from_response(response)),
    }, indent=2))

    return 0 if status < 400 and not failures else 1


if __name__ == "__main__":
    sys.exit(main())
