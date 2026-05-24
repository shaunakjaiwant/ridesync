from __future__ import annotations

import base64
import json
import os
import socket
import sys
import threading
import time
from contextlib import contextmanager
from http.server import BaseHTTPRequestHandler, ThreadingHTTPServer
from io import BytesIO
from pathlib import Path
from typing import Any

sys.path.insert(0, str(Path(__file__).resolve().parents[1]))

os.environ["RIDESYNC_ENV"] = "production"
os.environ["RIDESYNC_VERIFICATION_SERVICE_TOKEN"] = "s" * 40
os.environ["RIDESYNC_VERIFICATION_MAX_REQUEST_BYTES"] = "100000"
os.environ["RIDESYNC_VERIFICATION_MAX_FILE_BASE64_BYTES"] = "32768"
os.environ["RIDESYNC_VERIFICATION_MAX_DOCUMENTS"] = "2"
os.environ["RIDESYNC_VERIFICATION_OCR_TIMEOUT_SECONDS"] = "1"
os.environ["RIDESYNC_LOG_LEVEL"] = "WARNING"

from fastapi.testclient import TestClient  # noqa: E402
from PIL import Image  # noqa: E402

from app.main import app  # noqa: E402
from app.providers import ExternalHttpGovernmentProvider, provider_endpoint_allowed  # noqa: E402


TOKEN = "s" * 40


def sample_payload() -> dict[str, Any]:
    return {
        "session_id": 9001,
        "driver": {
            "name": "QA Driver",
            "license_number": "KA20QA1234",
            "vehicle_number": "KA20AB1234",
            "vehicle_type": "Car",
        },
        "documents": [
            {
                "id": 1,
                "document_type": "license",
                "is_file": False,
                "reference_fingerprint": "sandbox-fingerprint",
                "mime": "image/png",
            }
        ],
    }


def assert_status(client: TestClient, label: str, status: int, method: str, path: str, **kwargs: Any) -> None:
    response = getattr(client, method)(path, **kwargs)
    if response.status_code != status:
        raise AssertionError(f"{label}: expected {status}, got {response.status_code}: {response.text[:500]}")


def assert_no_sensitive_values(label: str, response_json: dict[str, Any]) -> None:
    serialized = json.dumps(response_json, ensure_ascii=False)
    for value in ["KA20QA1234", "KA20AB1234"]:
        if value in serialized:
            raise AssertionError(f"{label}: response leaked raw sensitive value {value}")


def tiny_png_base64() -> str:
    image = Image.new("RGB", (80, 80), color=(255, 255, 255))
    buffer = BytesIO()
    image.save(buffer, format="PNG")
    return base64.b64encode(buffer.getvalue()).decode("ascii")


def assert_not_verified(label: str, response_json: dict[str, Any]) -> None:
    if response_json.get("status") == "verified":
        raise AssertionError(f"{label}: expected not verified, got verified: {json.dumps(response_json)[:500]}")


def resolver_for(*addresses: str):
    def _resolver(host: str, port: int, *args: Any, **kwargs: Any) -> list[tuple[Any, ...]]:
        results: list[tuple[Any, ...]] = []
        for address in addresses:
            family = socket.AF_INET6 if ":" in address else socket.AF_INET
            sockaddr = (address, port, 0, 0) if family == socket.AF_INET6 else (address, port)
            results.append((family, socket.SOCK_STREAM, 6, "", sockaddr))
        return results

    return _resolver


class RedirectingProviderHandler(BaseHTTPRequestHandler):
    def do_POST(self) -> None:
        self.send_response(302)
        self.send_header("Location", "http://127.0.0.1/latest/meta-data")
        self.end_headers()

    def log_message(self, format: str, *args: Any) -> None:
        return


@contextmanager
def redirecting_provider_url():
    server = ThreadingHTTPServer(("127.0.0.1", 0), RedirectingProviderHandler)
    thread = threading.Thread(target=server.serve_forever, daemon=True)
    thread.start()
    deadline = time.time() + 2
    while time.time() < deadline:
        try:
            with socket.create_connection(("127.0.0.1", server.server_port), timeout=0.1):
                break
        except OSError:
            time.sleep(0.02)
    try:
        yield f"http://127.0.0.1:{server.server_port}/verify"
    finally:
        server.shutdown()
        server.server_close()
        thread.join(timeout=2)


def main() -> int:
    client = TestClient(app)

    assert_status(client, "healthz", 200, "get", "/healthz")
    assert_status(client, "readyz", 200, "get", "/readyz")
    assert_status(client, "analyze requires auth", 401, "post", "/v1/driver-verifications/analyze", json=sample_payload())
    assert_status(
        client,
        "analyze rejects bad token",
        401,
        "post",
        "/v1/driver-verifications/analyze",
        json=sample_payload(),
        headers={"Authorization": "Bearer wrong"},
    )
    assert_status(
        client,
        "analyze rejects non-json",
        415,
        "post",
        "/v1/driver-verifications/analyze",
        data="not-json",
        headers={"Authorization": f"Bearer {TOKEN}", "Content-Type": "text/plain"},
    )

    ok_response = client.post(
        "/v1/driver-verifications/analyze",
        json=sample_payload(),
        headers={"Authorization": f"Bearer {TOKEN}", "X-Request-Id": "selftest-request-9001"},
    )
    if ok_response.status_code != 200:
        raise AssertionError(f"valid analyze failed: {ok_response.status_code}: {ok_response.text[:500]}")
    assert_no_sensitive_values("valid analyze", ok_response.json())
    if ok_response.headers.get("x-request-id") != "selftest-request-9001":
        raise AssertionError("valid analyze did not echo request id")

    extra = sample_payload()
    extra["unexpected"] = True
    assert_status(
        client,
        "extra field rejected",
        422,
        "post",
        "/v1/driver-verifications/analyze",
        json=extra,
        headers={"Authorization": f"Bearer {TOKEN}"},
    )

    invalid_doc = sample_payload()
    invalid_doc["documents"][0]["document_type"] = "passport"
    assert_status(
        client,
        "invalid document type rejected",
        422,
        "post",
        "/v1/driver-verifications/analyze",
        json=invalid_doc,
        headers={"Authorization": f"Bearer {TOKEN}"},
    )

    duplicate_docs = sample_payload()
    duplicate_docs["documents"].append(dict(duplicate_docs["documents"][0]))
    assert_status(
        client,
        "duplicate document ids rejected",
        422,
        "post",
        "/v1/driver-verifications/analyze",
        json=duplicate_docs,
        headers={"Authorization": f"Bearer {TOKEN}"},
    )

    oversized = sample_payload()
    oversized["documents"][0]["file_base64"] = base64.b64encode(b"x" * 40000).decode("ascii")
    assert_status(
        client,
        "oversized document rejected",
        413,
        "post",
        "/v1/driver-verifications/analyze",
        json=oversized,
        headers={"Authorization": f"Bearer {TOKEN}"},
    )

    empty_docs = sample_payload()
    empty_docs["documents"] = []
    assert_status(
        client,
        "empty documents rejected",
        422,
        "post",
        "/v1/driver-verifications/analyze",
        json=empty_docs,
        headers={"Authorization": f"Bearer {TOKEN}"},
    )

    corrupt_selfie = sample_payload()
    corrupt_selfie["documents"] = [
        {
            "id": 2,
            "document_type": "selfie",
            "is_file": True,
            "reference_fingerprint": "sandbox-fingerprint",
            "mime": "image/png",
            "file_base64": base64.b64encode(b"not an image").decode("ascii"),
        }
    ]
    response = client.post("/v1/driver-verifications/analyze", json=corrupt_selfie, headers={"Authorization": f"Bearer {TOKEN}"})
    if response.status_code != 200:
        raise AssertionError(f"corrupt selfie should be scored, got {response.status_code}: {response.text[:500]}")
    assert_not_verified("corrupt selfie", response.json())
    if response.json().get("face_match", {}).get("status") != "failed":
        raise AssertionError("corrupt selfie face match should fail")

    tiny_selfie = sample_payload()
    tiny_selfie["documents"] = [
        {
            "id": 3,
            "document_type": "selfie",
            "is_file": True,
            "reference_fingerprint": "sandbox-fingerprint",
            "mime": "image/png",
            "file_base64": tiny_png_base64(),
        }
    ]
    response = client.post("/v1/driver-verifications/analyze", json=tiny_selfie, headers={"Authorization": f"Bearer {TOKEN}"})
    if response.status_code != 200:
        raise AssertionError(f"tiny selfie should be scored, got {response.status_code}: {response.text[:500]}")
    assert_not_verified("tiny selfie", response.json())

    if provider_endpoint_allowed("https://169.254.169.254/latest/meta-data"):
        raise AssertionError("metadata IP provider URL must be blocked in production")
    if provider_endpoint_allowed("https://127.0.0.1/provider"):
        raise AssertionError("loopback provider URL must be blocked in production")
    public_resolver = resolver_for("8.8.8.8")
    if provider_endpoint_allowed("http://provider.example.com/verify", resolver=public_resolver):
        raise AssertionError("public HTTP provider URL must be blocked in production")
    if provider_endpoint_allowed("https://user:pass@provider.example.com/verify", resolver=public_resolver):
        raise AssertionError("provider URL credentials must be blocked")
    if provider_endpoint_allowed("https://provider.example.local/verify", resolver=public_resolver):
        raise AssertionError("local provider DNS names must be blocked in production")
    if provider_endpoint_allowed("https://provider.example.com/verify#fragment", resolver=public_resolver):
        raise AssertionError("provider URL fragments must be blocked")
    if provider_endpoint_allowed("https://kyc.provider.test/verify", resolver=resolver_for("10.0.0.25")):
        raise AssertionError("provider DNS resolving to private IP must be blocked")
    if provider_endpoint_allowed("https://kyc.provider.test/verify", resolver=resolver_for("8.8.8.8", "127.0.0.1")):
        raise AssertionError("provider DNS with mixed public/private answers must be blocked")
    if not provider_endpoint_allowed("https://provider.example.com/verify", resolver=public_resolver):
        raise AssertionError("public HTTPS provider URL should be allowed")

    previous_env = os.environ["RIDESYNC_ENV"]
    os.environ["RIDESYNC_ENV"] = "local"
    try:
        with redirecting_provider_url() as url:
            redirecting_provider = ExternalHttpGovernmentProvider("redirect_probe", url)
            checks = redirecting_provider.checks_for_document(sample_payload()["documents"][0], sample_payload()["driver"], {})
            if not checks or checks[0].check_type != "provider_reachability":
                raise AssertionError("redirecting provider should produce a reachability failure")
            if checks[0].response.get("error") != "ProviderRedirectBlocked":
                raise AssertionError("provider redirects must be blocked instead of followed")
    finally:
        os.environ["RIDESYNC_ENV"] = previous_env

    print(json.dumps({"ok": True, "checks": 25}, indent=2))
    return 0


if __name__ == "__main__":
    sys.exit(main())
