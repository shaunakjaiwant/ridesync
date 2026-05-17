from __future__ import annotations

import json
import os
import re
import urllib.error
import urllib.request
from dataclasses import dataclass
from typing import Any


@dataclass
class ProviderCheck:
    check_type: str
    status: str
    confidence: float
    response: dict[str, Any]


class GovernmentProvider:
    name = "base"

    def checks_for_document(self, document: dict[str, Any], driver: dict[str, Any], extracted: dict[str, Any]) -> list[ProviderCheck]:
        raise NotImplementedError


class MockGovernmentProvider(GovernmentProvider):
    name = "mock_compliance_provider"

    def checks_for_document(self, document: dict[str, Any], driver: dict[str, Any], extracted: dict[str, Any]) -> list[ProviderCheck]:
        doc_type = str(document.get("document_type") or "")
        checks: list[ProviderCheck] = [
            ProviderCheck(
                check_type=f"{doc_type}_document_exists",
                status="passed" if document.get("is_file") else "needs_review",
                confidence=91.0 if document.get("is_file") else 55.0,
                response={"mode": "mock", "replaceable_provider": True},
            )
        ]

        if doc_type == "license":
            license_number = str(driver.get("license_number") or "")
            valid = bool(re.match(r"^[A-Z0-9 -]{4,80}$", license_number))
            checks.append(
                ProviderCheck(
                    check_type="driving_license_format",
                    status="passed" if valid else "failed",
                    confidence=88.0 if valid else 35.0,
                    response={"license_number_masked": mask_value(license_number)},
                )
            )

        if doc_type in {"vehicle_rc", "insurance", "vehicle_image"}:
            vehicle_number = normalize(str(driver.get("vehicle_number") or ""))
            valid = bool(re.match(r"^[A-Z]{2}[0-9]{1,2}[A-Z]{0,3}[0-9]{3,4}$", vehicle_number))
            checks.append(
                ProviderCheck(
                    check_type="vehicle_registration_format",
                    status="passed" if valid else "needs_review",
                    confidence=86.0 if valid else 58.0,
                    response={"vehicle_number": driver.get("vehicle_number")},
                )
            )

        if doc_type in {"aadhaar", "id_proof"}:
            has_masked = bool(extracted.get("aadhaar_number"))
            checks.append(
                ProviderCheck(
                    check_type="uidai_compatible_masked_identity",
                    status="passed" if has_masked else "needs_review",
                    confidence=82.0 if has_masked else 57.0,
                    response={"aadhaar_masked": extracted.get("aadhaar_number")},
                )
            )

        if doc_type == "pan":
            has_pan = bool(extracted.get("pan_number"))
            checks.append(
                ProviderCheck(
                    check_type="pan_format",
                    status="passed" if has_pan else "needs_review",
                    confidence=82.0 if has_pan else 57.0,
                    response={"pan_masked": extracted.get("pan_number")},
                )
            )

        return checks


class ExternalHttpGovernmentProvider(GovernmentProvider):
    name = "external_http_provider"

    def __init__(self, provider_name: str, endpoint: str, token: str = "", timeout_seconds: float = 6.0):
        self.name = provider_name
        self.endpoint = endpoint.rstrip("/")
        self.token = token
        self.timeout_seconds = max(1.0, min(20.0, timeout_seconds))

    def checks_for_document(self, document: dict[str, Any], driver: dict[str, Any], extracted: dict[str, Any]) -> list[ProviderCheck]:
        if not self.endpoint:
            return [
                ProviderCheck(
                    check_type="provider_configuration",
                    status="needs_review",
                    confidence=0.0,
                    response={"error": "Provider endpoint is not configured."},
                )
            ]

        payload = {
            "provider": self.name,
            "document": redact_document(document),
            "driver": redact_driver(driver),
            "extracted": redact_extracted(extracted),
        }
        request = urllib.request.Request(
            self.endpoint,
            data=json.dumps(payload).encode("utf-8"),
            headers={
                "Content-Type": "application/json",
                "Accept": "application/json",
                **({"Authorization": f"Bearer {self.token}"} if self.token else {}),
            },
            method="POST",
        )

        try:
            with urllib.request.urlopen(request, timeout=self.timeout_seconds) as response:
                body = response.read(1024 * 256).decode("utf-8")
                decoded = json.loads(body) if body else {}
        except (urllib.error.URLError, TimeoutError, ValueError) as exc:
            return [
                ProviderCheck(
                    check_type="provider_reachability",
                    status="needs_review",
                    confidence=0.0,
                    response={"provider": self.name, "error": exc.__class__.__name__},
                )
            ]

        checks = decoded.get("checks") if isinstance(decoded, dict) else None
        if not isinstance(checks, list):
            checks = [decoded] if isinstance(decoded, dict) else []

        mapped: list[ProviderCheck] = []
        for item in checks:
            if not isinstance(item, dict):
                continue
            mapped.append(
                ProviderCheck(
                    check_type=str(item.get("check_type") or item.get("type") or "external_provider_check")[:80],
                    status=normalize_status(str(item.get("status") or "needs_review")),
                    confidence=normalize_confidence(item.get("confidence", item.get("confidence_score", 0.0))),
                    response=redact_extracted(item.get("response") if isinstance(item.get("response"), dict) else item),
                )
            )

        return mapped or [
            ProviderCheck(
                check_type="provider_response",
                status="needs_review",
                confidence=0.0,
                response={"provider": self.name, "error": "Provider returned no usable checks."},
            )
        ]


def provider_from_env() -> GovernmentProvider:
    provider_name = os.getenv("RIDESYNC_KYC_PROVIDER", "mock_compliance_provider").strip() or "mock_compliance_provider"
    endpoint = os.getenv("RIDESYNC_KYC_PROVIDER_URL", "").strip()
    token = os.getenv("RIDESYNC_KYC_PROVIDER_TOKEN", "").strip()
    timeout = os.getenv("RIDESYNC_KYC_PROVIDER_TIMEOUT_SECONDS", "6").strip()
    try:
        timeout_seconds = float(timeout)
    except ValueError:
        timeout_seconds = 6.0

    if provider_name in {"mock", "mock_compliance_provider"} or endpoint == "":
        return MockGovernmentProvider()

    return ExternalHttpGovernmentProvider(provider_name, endpoint, token, timeout_seconds)


def normalize(value: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", value.upper())


def mask_value(value: str, visible: int = 4) -> str:
    cleaned = re.sub(r"\s+", "", value)
    if not cleaned:
        return ""
    return "*" * max(0, len(cleaned) - visible) + cleaned[-visible:]


def normalize_status(status: str) -> str:
    normalized = status.lower().strip().replace("-", "_").replace(" ", "_")
    return normalized if normalized in {"passed", "failed", "needs_review", "not_available"} else "needs_review"


def normalize_confidence(value: Any) -> float:
    try:
        return max(0.0, min(100.0, float(value)))
    except (TypeError, ValueError):
        return 0.0


def redact_document(document: dict[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in document.items() if key != "file_base64"}


def redact_driver(driver: dict[str, Any]) -> dict[str, Any]:
    redacted = dict(driver)
    if "license_number" in redacted:
        redacted["license_number"] = mask_value(str(redacted["license_number"]))
    return redacted


def redact_extracted(extracted: dict[str, Any]) -> dict[str, Any]:
    redacted: dict[str, Any] = {}
    for key, value in extracted.items():
        key_lower = key.lower()
        if any(marker in key_lower for marker in ("aadhaar", "aadhar", "pan", "license")):
            redacted[key] = mask_value(str(value)) if value else value
        else:
            redacted[key] = value
    return redacted
