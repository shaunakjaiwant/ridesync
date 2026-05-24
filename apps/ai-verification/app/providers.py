from __future__ import annotations

import http.client
import json
import ipaddress
import os
import re
import socket
import ssl
from collections.abc import Callable
from dataclasses import dataclass
from typing import Any
from urllib.parse import ParseResult, urlparse, urlunparse


PROVIDER_MAX_RESPONSE_BYTES = 1024 * 256
Resolver = Callable[..., Any]


@dataclass
class ProviderCheck:
    check_type: str
    status: str
    confidence: float
    response: dict[str, Any]


@dataclass(frozen=True)
class ProviderEndpointValidation:
    allowed: bool
    reason: str
    parsed: ParseResult | None = None
    host: str = ""
    port: int = 0
    target: str = "/"
    authority: str = ""
    address: str | None = None


class ProviderRequestError(Exception):
    pass


class ProviderConfigurationError(ProviderRequestError):
    pass


class ProviderRedirectBlocked(ProviderRequestError):
    pass


class ProviderHttpStatusError(ProviderRequestError):
    pass


class ProviderResponseTooLarge(ProviderRequestError):
    pass


class PinnedHTTPSConnection(http.client.HTTPSConnection):
    def __init__(self, hostname: str, address: str, port: int, timeout: float, context: ssl.SSLContext):
        super().__init__(hostname, port=port, timeout=timeout, context=context)
        self._pinned_address = address

    def connect(self) -> None:
        sock = socket.create_connection((self._pinned_address, self.port), self.timeout, self.source_address)
        self.sock = self._context.wrap_socket(sock, server_hostname=self.host)


class GovernmentProvider:
    name = "base"

    def checks_for_document(self, document: dict[str, Any], driver: dict[str, Any], extracted: dict[str, Any]) -> list[ProviderCheck]:
        raise NotImplementedError


class MockGovernmentProvider(GovernmentProvider):
    name = "mock_compliance_provider"

    def checks_for_document(self, document: dict[str, Any], driver: dict[str, Any], extracted: dict[str, Any]) -> list[ProviderCheck]:
        doc_type = str(document.get("document_type") or "")
        image_meta = extracted.get("image_meta") if isinstance(extracted.get("image_meta"), dict) else {}
        decode_error = bool(image_meta.get("decode_error")) if isinstance(image_meta, dict) else False
        document_status = "failed" if decode_error else ("passed" if document.get("is_file") else "needs_review")
        document_confidence = 18.0 if decode_error else (91.0 if document.get("is_file") else 55.0)
        checks: list[ProviderCheck] = [
            ProviderCheck(
                check_type=f"{doc_type}_document_exists",
                status=document_status,
                confidence=document_confidence,
                response={"mode": "mock", "replaceable_provider": True, **({"decode_error": True} if decode_error else {})},
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
                    response={"vehicle_number_masked": mask_value(str(driver.get("vehicle_number") or ""))},
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

        try:
            _, decoded = post_provider_json(self.endpoint, payload, self.token, self.timeout_seconds)
        except ProviderConfigurationError as exc:
            return [
                ProviderCheck(
                    check_type="provider_configuration",
                    status="needs_review",
                    confidence=0.0,
                    response={"provider": self.name, "error": str(exc)},
                )
            ]
        except (ProviderRequestError, TimeoutError, OSError, ValueError) as exc:
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


def provider_endpoint_allowed(endpoint: str, resolver: Resolver | None = None) -> bool:
    return validate_provider_endpoint(endpoint, resolver=resolver).allowed


def validate_provider_endpoint(endpoint: str, resolver: Resolver | None = None) -> ProviderEndpointValidation:
    if not endpoint:
        return ProviderEndpointValidation(False, "endpoint_empty")

    parsed = urlparse(endpoint)
    if parsed.scheme not in {"http", "https"} or not parsed.netloc:
        return ProviderEndpointValidation(False, "endpoint_must_be_http_or_https")

    if parsed.username or parsed.password:
        return ProviderEndpointValidation(False, "endpoint_credentials_not_allowed")

    if parsed.fragment:
        return ProviderEndpointValidation(False, "endpoint_fragment_not_allowed")

    try:
        port = parsed.port or (443 if parsed.scheme == "https" else 80)
    except ValueError:
        return ProviderEndpointValidation(False, "endpoint_port_invalid")

    host = normalize_provider_host(parsed.hostname or "")
    if not host:
        return ProviderEndpointValidation(False, "endpoint_host_invalid")

    target = urlunparse(("", "", parsed.path or "/", parsed.params, parsed.query, ""))
    authority = provider_authority(host, port, parsed.scheme)
    production = os.getenv("RIDESYNC_ENV", "local").strip().lower() == "production"
    if production:
        if parsed.scheme != "https":
            return ProviderEndpointValidation(False, "production_provider_requires_https")
        if provider_host_is_local_name(host):
            return ProviderEndpointValidation(False, "provider_host_not_public")

        literal_address = parse_ip_address(host)
        if literal_address is not None:
            if not provider_address_is_public(literal_address):
                return ProviderEndpointValidation(False, "provider_host_not_public")
            return ProviderEndpointValidation(True, "", parsed, host, port, target, authority, str(literal_address))

        addresses = resolve_provider_addresses(host, port, resolver)
        if not addresses:
            return ProviderEndpointValidation(False, "provider_host_resolution_failed")
        if any(not provider_address_is_public(address) for address in addresses):
            return ProviderEndpointValidation(False, "provider_host_not_public")
        return ProviderEndpointValidation(True, "", parsed, host, port, target, authority, str(addresses[0]))

    return ProviderEndpointValidation(True, "", parsed, host, port, target, authority, None)


def provider_host_is_private(host: str) -> bool:
    if provider_host_is_local_name(host):
        return True

    address = parse_ip_address(host)
    if address is None:
        return False

    return not provider_address_is_public(address)


def normalize_provider_host(host: str) -> str:
    normalized = host.strip().strip("[]").rstrip(".").lower()
    if not normalized or "%" in normalized:
        return ""
    if ":" in normalized:
        return normalized
    try:
        return normalized.encode("idna").decode("ascii")
    except UnicodeError:
        return ""


def provider_host_is_local_name(host: str) -> bool:
    return host in {"localhost", "ip6-localhost"} or host.endswith(".localhost") or host.endswith(".local")


def parse_ip_address(host: str) -> ipaddress.IPv4Address | ipaddress.IPv6Address | None:
    try:
        address = ipaddress.ip_address(host)
    except ValueError:
        return None
    if isinstance(address, ipaddress.IPv6Address) and address.ipv4_mapped is not None:
        return address.ipv4_mapped
    return address


def provider_address_is_public(address: ipaddress.IPv4Address | ipaddress.IPv6Address) -> bool:
    return all(
        [
            address.is_global,
            not address.is_private,
            not address.is_loopback,
            not address.is_link_local,
            not address.is_multicast,
            not address.is_reserved,
            not address.is_unspecified,
        ]
    )


def resolve_provider_addresses(host: str, port: int, resolver: Resolver | None = None) -> list[ipaddress.IPv4Address | ipaddress.IPv6Address]:
    lookup = resolver or socket.getaddrinfo
    try:
        results = lookup(host, port, type=socket.SOCK_STREAM)
    except (OSError, TypeError):
        return []

    addresses: list[ipaddress.IPv4Address | ipaddress.IPv6Address] = []
    seen: set[str] = set()
    for result in results:
        try:
            raw_address = result[4][0]
        except (IndexError, TypeError):
            continue
        address = parse_ip_address(str(raw_address))
        if address is None or str(address) in seen:
            continue
        addresses.append(address)
        seen.add(str(address))

    return addresses


def provider_authority(host: str, port: int, scheme: str) -> str:
    default_port = 443 if scheme == "https" else 80
    host_header = f"[{host}]" if ":" in host and not host.startswith("[") else host
    return host_header if port == default_port else f"{host_header}:{port}"


def post_provider_json(endpoint: str, payload: dict[str, Any], token: str, timeout_seconds: float) -> tuple[int, dict[str, Any]]:
    validation = validate_provider_endpoint(endpoint)
    if not validation.allowed or validation.parsed is None:
        raise ProviderConfigurationError(validation.reason or "provider_endpoint_not_allowed")

    body = json.dumps(payload, separators=(",", ":")).encode("utf-8")
    headers = {
        "Accept": "application/json",
        "Content-Type": "application/json",
        "Content-Length": str(len(body)),
        "Host": validation.authority,
        **({"Authorization": f"Bearer {token}"} if token else {}),
    }

    connection: http.client.HTTPConnection | http.client.HTTPSConnection
    if validation.parsed.scheme == "https":
        context = ssl.create_default_context()
        if validation.address:
            connection = PinnedHTTPSConnection(validation.host, validation.address, validation.port, timeout_seconds, context)
        else:
            connection = http.client.HTTPSConnection(validation.host, validation.port, timeout=timeout_seconds, context=context)
    else:
        connection = http.client.HTTPConnection(validation.host, validation.port, timeout=timeout_seconds)

    try:
        connection.request("POST", validation.target, body=body, headers=headers)
        response = connection.getresponse()
        raw_body = response.read(PROVIDER_MAX_RESPONSE_BYTES + 1)
    finally:
        connection.close()

    if 300 <= response.status < 400:
        raise ProviderRedirectBlocked("provider_redirect_blocked")
    if response.status >= 400:
        raise ProviderHttpStatusError(f"provider_http_status_{response.status}")
    if len(raw_body) > PROVIDER_MAX_RESPONSE_BYTES:
        raise ProviderResponseTooLarge("provider_response_too_large")

    decoded = json.loads(raw_body.decode("utf-8")) if raw_body else {}
    return response.status, decoded if isinstance(decoded, dict) else {"checks": decoded}


def redact_document(document: dict[str, Any]) -> dict[str, Any]:
    return {key: value for key, value in document.items() if key != "file_base64"}


def redact_driver(driver: dict[str, Any]) -> dict[str, Any]:
    redacted = dict(driver)
    if "license_number" in redacted:
        redacted["license_number"] = mask_value(str(redacted["license_number"]))
    if "vehicle_number" in redacted:
        redacted["vehicle_number"] = mask_value(str(redacted["vehicle_number"]))
    return redacted


def redact_extracted(extracted: dict[str, Any]) -> dict[str, Any]:
    redacted: dict[str, Any] = {}
    for key, value in extracted.items():
        key_lower = key.lower()
        if any(marker in key_lower for marker in ("aadhaar", "aadhar", "pan", "license", "vehicle")):
            redacted[key] = mask_value(str(value)) if value else value
        else:
            redacted[key] = value
    return redacted
