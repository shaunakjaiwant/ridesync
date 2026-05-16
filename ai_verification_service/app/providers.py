from __future__ import annotations

import re
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


def normalize(value: str) -> str:
    return re.sub(r"[^A-Z0-9]", "", value.upper())


def mask_value(value: str, visible: int = 4) -> str:
    cleaned = re.sub(r"\s+", "", value)
    if not cleaned:
        return ""
    return "*" * max(0, len(cleaned) - visible) + cleaned[-visible:]
