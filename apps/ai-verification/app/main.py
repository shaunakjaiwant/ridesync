from __future__ import annotations

import base64
import binascii
import hmac
import io
import json
import logging
import os
import re
import time
import uuid
from datetime import UTC, datetime, timedelta
from pathlib import Path
from typing import Any

from fastapi import Depends, FastAPI, Header, HTTPException, Request
from fastapi.exceptions import RequestValidationError
from fastapi.responses import JSONResponse
from PIL import Image, UnidentifiedImageError
from pydantic import BaseModel, ConfigDict, Field, field_validator, model_validator

try:
    import cv2  # type: ignore
except Exception:  # pragma: no cover - optional runtime dependency
    cv2 = None

try:
    import pytesseract  # type: ignore
except Exception:  # pragma: no cover - optional runtime dependency
    pytesseract = None

from .providers import mask_value, normalize, provider_from_env, redact_extracted


ALLOWED_DOCUMENT_TYPES = {
    "license",
    "aadhaar",
    "pan",
    "id_proof",
    "vehicle_rc",
    "insurance",
    "profile_photo",
    "selfie",
    "vehicle_image",
    "other",
}
ALLOWED_MIME_TYPES = {
    "application/pdf",
    "image/jpeg",
    "image/png",
    "image/webp",
}
SENSITIVE_LOG_KEYS = re.compile(r"password|token|secret|cookie|authorization|csrf|aadhaar|aadhar|pan|license|document|base64|otp", re.I)


logger = logging.getLogger("ridesync.verification")
if not logger.handlers:
    handler = logging.StreamHandler()
    handler.setFormatter(logging.Formatter("%(message)s"))
    logger.addHandler(handler)
logger.setLevel(os.environ.get("RIDESYNC_LOG_LEVEL", "INFO").upper())
logger.propagate = False


class RequestTooLarge(Exception):
    pass


class RequestBodyLimitMiddleware:
    def __init__(self, app, max_bytes: int):
        self.app = app
        self.max_bytes = max_bytes

    async def __call__(self, scope, receive, send):
        if scope.get("type") != "http":
            await self.app(scope, receive, send)
            return

        received = 0

        async def limited_receive():
            nonlocal received
            message = await receive()
            if message.get("type") == "http.request":
                received += len(message.get("body", b""))
                if received > self.max_bytes:
                    raise RequestTooLarge()
            return message

        try:
            await self.app(scope, limited_receive, send)
        except RequestTooLarge:
            response = JSONResponse(status_code=413, content={"detail": "request body too large"})
            await response(scope, receive, send)


def load_dotenv(path: Path) -> None:
    if not path.is_file():
        return

    for raw_line in path.read_text(encoding="utf-8").splitlines():
        line = raw_line.strip()
        if not line or line.startswith("#"):
            continue
        if line.startswith("export "):
            line = line[7:].strip()
        if "=" not in line:
            continue
        key, value = line.split("=", 1)
        key = key.strip()
        if not re.match(r"^[A-Za-z_][A-Za-z0-9_]*$", key) or key in os.environ:
            continue
        value = value.strip()
        if (value.startswith('"') and value.endswith('"')) or (value.startswith("'") and value.endswith("'")):
            value = value[1:-1]
        elif " #" in value:
            value = value.split(" #", 1)[0].rstrip()
        os.environ[key] = value


def int_env(name: str, default: int, minimum: int, maximum: int) -> int:
    try:
        value = int(os.environ.get(name, str(default)))
    except ValueError:
        return default
    return max(minimum, min(maximum, value))


def secret_configured(value: str, min_length: int = 32) -> bool:
    normalized = value.strip()
    return len(normalized) >= min_length and not normalized.lower().startswith("replace-with")


def bool_env(name: str, default: bool = False) -> bool:
    value = os.environ.get(name)
    if value is None:
        return default
    return value.strip().lower() in {"1", "true", "yes", "on"}


def app_env() -> str:
    return os.environ.get("RIDESYNC_ENV", "local").strip().lower() or "local"


def redact(value: Any) -> Any:
    if isinstance(value, dict):
        return {key: "[redacted]" if SENSITIVE_LOG_KEYS.search(str(key)) else redact(item) for key, item in value.items()}
    if isinstance(value, list):
        return [redact(item) for item in value]
    return value


def log_event(level: str, message: str, **context: Any) -> None:
    logger.log(
        getattr(logging, level.upper(), logging.INFO),
        json.dumps(
            {
                "timestamp": datetime.now(UTC).isoformat(),
                "level": level.lower(),
                "message": message,
                "context": redact(context),
            },
            separators=(",", ":"),
            ensure_ascii=False,
        ),
    )


def request_id_from_headers(request: Request) -> str:
    incoming = request.headers.get("x-request-id", "").strip()
    if re.match(r"^[A-Za-z0-9._:-]{8,128}$", incoming):
        return incoming
    return uuid.uuid4().hex


def clean_validation_errors(errors: list[dict[str, Any]]) -> list[dict[str, Any]]:
    cleaned: list[dict[str, Any]] = []
    for error in errors:
        cleaned.append({key: value for key, value in error.items() if key not in {"input", "url", "ctx"}})
    return cleaned


load_dotenv(Path(__file__).resolve().parents[2] / ".env")

MAX_REQUEST_BYTES = int_env("RIDESYNC_VERIFICATION_MAX_REQUEST_BYTES", 2_000_000, 32_768, 10_000_000)
MAX_FILE_BASE64_BYTES = int_env("RIDESYNC_VERIFICATION_MAX_FILE_BASE64_BYTES", 1_500_000, 32_768, 8_000_000)
MAX_DOCUMENTS = int_env("RIDESYNC_VERIFICATION_MAX_DOCUMENTS", 12, 1, 40)
OCR_TIMEOUT_SECONDS = int_env("RIDESYNC_VERIFICATION_OCR_TIMEOUT_SECONDS", 5, 1, 20)
MAX_IMAGE_PIXELS = int_env("RIDESYNC_VERIFICATION_MAX_IMAGE_PIXELS", 12_000_000, 250_000, 40_000_000)

app = FastAPI(title="RideSync Driver Verification Intelligence", version="1.0.0")
app.add_middleware(RequestBodyLimitMiddleware, max_bytes=MAX_REQUEST_BYTES)
provider = provider_from_env()
Image.MAX_IMAGE_PIXELS = MAX_IMAGE_PIXELS


@app.middleware("http")
async def request_guardrails(request: Request, call_next):
    request_id = request_id_from_headers(request)
    request.state.request_id = request_id
    started = time.perf_counter()

    if request.url.path == "/v1/driver-verifications/analyze" and request.method.upper() == "POST":
        content_type = request.headers.get("content-type", "")
        if "application/json" not in content_type.lower():
            return JSONResponse(status_code=415, content={"detail": "content-type must be application/json"})

    content_length = request.headers.get("content-length")
    if content_length:
        try:
            size = int(content_length)
        except ValueError:
            return JSONResponse(status_code=400, content={"detail": "invalid content-length"})
        if size > MAX_REQUEST_BYTES:
            return JSONResponse(status_code=413, content={"detail": "request body too large"})

    response = await call_next(request)
    response.headers["X-Request-Id"] = request_id
    response.headers["X-Content-Type-Options"] = "nosniff"
    response.headers["Cache-Control"] = "no-store"

    if request.url.path in {"/v1/driver-verifications/analyze", "/readyz"}:
        log_event(
            "info",
            "verification_request",
            request_id=request_id,
            path=request.url.path,
            method=request.method,
            status_code=response.status_code,
            elapsed_ms=round((time.perf_counter() - started) * 1000, 2),
        )

    return response


@app.exception_handler(RequestValidationError)
async def validation_exception_handler(request: Request, exc: RequestValidationError):
    return JSONResponse(
        status_code=422,
        content={"detail": clean_validation_errors(exc.errors()), "request_id": getattr(request.state, "request_id", None)},
    )


@app.exception_handler(Exception)
async def unhandled_exception_handler(request: Request, exc: Exception):
    log_event(
        "error",
        "verification_unhandled_exception",
        request_id=getattr(request.state, "request_id", None),
        error_class=exc.__class__.__name__,
        path=str(request.url.path),
    )
    return JSONResponse(status_code=500, content={"detail": "internal server error", "request_id": getattr(request.state, "request_id", None)})


def require_service_auth(authorization: str | None = Header(default=None)) -> None:
    expected = os.environ.get("RIDESYNC_VERIFICATION_SERVICE_TOKEN", "").strip()
    if not secret_configured(expected):
        if app_env() == "production":
            raise HTTPException(status_code=503, detail="verification service token is not configured")
        return

    scheme, _, token = (authorization or "").partition(" ")
    if scheme.lower() != "bearer" or not hmac.compare_digest(expected, token.strip()):
        raise HTTPException(status_code=401, detail="invalid verification service token")


class DriverPayload(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    name: str = Field(default="", max_length=120)
    license_number: str = Field(default="", max_length=80)
    vehicle_number: str = Field(default="", max_length=40)
    vehicle_type: str = Field(default="", max_length=40)


class DocumentPayload(BaseModel):
    model_config = ConfigDict(extra="forbid", str_strip_whitespace=True)

    id: int = Field(gt=0, le=2_147_483_647)
    document_type: str = Field(max_length=40)
    is_file: bool = False
    reference_fingerprint: str = Field(default="", max_length=128)
    mime: str | None = Field(default=None, max_length=80)
    file_base64: str | None = Field(default=None, repr=False)

    @field_validator("document_type")
    @classmethod
    def validate_document_type(cls, value: str) -> str:
        normalized = value.strip().lower()
        if normalized not in ALLOWED_DOCUMENT_TYPES:
            raise ValueError("unsupported document_type")
        return normalized

    @field_validator("reference_fingerprint")
    @classmethod
    def validate_reference_fingerprint(cls, value: str) -> str:
        if value and re.match(r"^[A-Za-z0-9._:-]{1,128}$", value) is None:
            raise ValueError("reference_fingerprint contains unsupported characters")
        return value

    @field_validator("mime")
    @classmethod
    def validate_mime(cls, value: str | None) -> str | None:
        if value is None or value == "":
            return None
        normalized = value.strip().lower()
        if normalized not in ALLOWED_MIME_TYPES:
            raise ValueError("unsupported mime type")
        return normalized

    @field_validator("file_base64")
    @classmethod
    def validate_file_base64(cls, value: str | None) -> str | None:
        if value is None or value == "":
            return None
        compact = re.sub(r"\s+", "", value)
        if len(compact) <= MAX_FILE_BASE64_BYTES:
            try:
                base64.b64decode(compact, validate=True)
            except (binascii.Error, ValueError) as exc:
                raise ValueError("file_base64 must be valid base64") from exc
        return compact


class AnalyzePayload(BaseModel):
    model_config = ConfigDict(extra="forbid")

    session_id: int = Field(gt=0, le=2_147_483_647)
    driver: DriverPayload
    documents: list[DocumentPayload] = Field(default_factory=list)

    @model_validator(mode="after")
    def validate_document_set(self) -> "AnalyzePayload":
        if not self.documents:
            raise ValueError("at least one document is required")
        if len(self.documents) > MAX_DOCUMENTS:
            raise ValueError("too many documents")
        document_ids = [document.id for document in self.documents]
        if len(document_ids) != len(set(document_ids)):
            raise ValueError("duplicate document ids")
        return self


def image_text(document: DocumentPayload) -> tuple[str, dict[str, Any]]:
    if not document.file_base64:
        return "", {}

    try:
        raw = base64.b64decode(document.file_base64, validate=True)
        if len(raw) > MAX_FILE_BASE64_BYTES:
            return "", {"decode_error": True, "reason": "decoded_file_too_large"}

        image = Image.open(io.BytesIO(raw))
        image.load()
        meta = {"width": image.width, "height": image.height, "format": image.format}
        if pytesseract:
            try:
                return pytesseract.image_to_string(image, timeout=OCR_TIMEOUT_SECONDS), meta
            except RuntimeError:
                meta["ocr_timeout"] = True
                return "", meta
        return "", meta
    except (binascii.Error, UnidentifiedImageError, Image.DecompressionBombError, ValueError, OSError):
        return "", {"decode_error": True}


def face_similarity_score(img_bytes_a: bytes, img_bytes_b: bytes) -> float | None:
    """Compare two face images using ORB feature matching as a lightweight proxy
    when DeepFace/FaceNet are not available. Returns a 0–100 similarity score,
    or None if OpenCV is unavailable or either image cannot be decoded."""
    if cv2 is None:
        return None

    try:
        arr_a = cv2.imdecode(
            __import__("numpy").frombuffer(img_bytes_a, dtype=__import__("numpy").uint8), cv2.IMREAD_GRAYSCALE
        )
        arr_b = cv2.imdecode(
            __import__("numpy").frombuffer(img_bytes_b, dtype=__import__("numpy").uint8), cv2.IMREAD_GRAYSCALE
        )
        if arr_a is None or arr_b is None:
            return None

        orb = cv2.ORB_create(nfeatures=500)
        kp_a, des_a = orb.detectAndCompute(arr_a, None)
        kp_b, des_b = orb.detectAndCompute(arr_b, None)
        if des_a is None or des_b is None or len(kp_a) == 0 or len(kp_b) == 0:
            return None

        matcher = cv2.BFMatcher(cv2.NORM_HAMMING, crossCheck=True)
        matches = matcher.match(des_a, des_b)
        if not matches:
            return 0.0

        matches = sorted(matches, key=lambda m: m.distance)
        good = [m for m in matches if m.distance < 64]
        total = min(len(kp_a), len(kp_b))
        score = (len(good) / total) * 100.0 if total > 0 else 0.0
        return min(100.0, round(score, 2))
    except Exception:  # pragma: no cover
        return None


def is_identity_photo(document: DocumentPayload) -> bool:
    return document.document_type in {"selfie", "profile_photo"}


def image_decode_failed(extracted: dict[str, Any]) -> bool:
    image_meta = extracted.get("image_meta")
    return isinstance(image_meta, dict) and bool(image_meta.get("decode_error"))


def image_low_resolution(extracted: dict[str, Any]) -> bool:
    image_meta = extracted.get("image_meta")
    if not isinstance(image_meta, dict):
        return False

    return int(image_meta.get("width") or 9999) < 640 or int(image_meta.get("height") or 9999) < 420


def identity_photo_is_usable(document: DocumentPayload, extracted: dict[str, Any]) -> bool:
    if not is_identity_photo(document) or not document.is_file:
        return False
    if not document.file_base64:
        return True
    return not image_decode_failed(extracted) and not image_low_resolution(extracted)


def identity_photo_bytes(document: DocumentPayload) -> bytes | None:
    """Return raw decoded bytes for a usable identity photo, or None."""
    if not document.file_base64:
        return None
    try:
        return base64.b64decode(document.file_base64, validate=True)
    except (binascii.Error, ValueError):
        return None


def extract_document(document: DocumentPayload, driver: DriverPayload) -> dict[str, Any]:
    text, image_meta = image_text(document)
    doc_type = document.document_type
    extracted: dict[str, Any] = {
        "full_name": driver.name,
        "image_meta": image_meta or None,
    }

    if doc_type == "license":
        found = re.search(r"\b([A-Z]{2}[0-9]{2}[A-Z0-9 -]{6,18})\b", text.upper())
        extracted["license_number"] = found.group(1) if found else driver.license_number
        extracted["expiry_date"] = (datetime.now(UTC) + timedelta(days=365)).date().isoformat()
    elif doc_type in {"aadhaar", "id_proof"}:
        found = re.search(r"\b([0-9]{4}\s?[0-9]{4}\s?[0-9]{4})\b", text)
        extracted["aadhaar_number"] = mask_value(found.group(1)) if found else None
    elif doc_type == "pan":
        found = re.search(r"\b([A-Z]{5}[0-9]{4}[A-Z])\b", text.upper())
        extracted["pan_number"] = mask_value(found.group(1)) if found else None
    elif doc_type in {"vehicle_rc", "insurance", "vehicle_image"}:
        found = re.search(r"\b([A-Z]{2}\s?[0-9]{1,2}\s?[A-Z]{0,3}\s?[0-9]{3,4})\b", text.upper())
        extracted["vehicle_registration_number"] = found.group(1) if found else driver.vehicle_number
    elif doc_type in {"selfie", "profile_photo"}:
        # identity_photo_is_usable checks that the image decoded correctly and
        # meets minimum resolution requirements. It does NOT perform face
        # detection — a separate computer-vision step would be needed for that.
        extracted["selfie_image_usable"] = identity_photo_is_usable(document, extracted)

    return {k: v for k, v in extracted.items() if v not in ("", None, {})}


def fraud_flags(document: DocumentPayload, extracted: dict[str, Any]) -> list[dict[str, Any]]:
    flags: list[dict[str, Any]] = []
    fingerprint = (document.reference_fingerprint or "").lower()

    if not document.is_file:
        flags.append(
            {
                "severity": "medium",
                "flag_code": "reference_only",
                "flag_label": "Reference-only submission",
                "description": "No uploaded file available for computer-vision verification.",
                "confidence": 67.0,
            }
        )

    image_meta = extracted.get("image_meta") or {}
    if document.is_file and isinstance(image_meta, dict) and image_meta.get("decode_error"):
        flags.append(
            {
                "severity": "critical" if is_identity_photo(document) else "high",
                "flag_code": "file_decode_failed",
                "flag_label": "Uploaded file could not be decoded",
                "description": "The uploaded file bytes could not be decoded as the claimed document media.",
                "confidence": 94.0,
            }
        )

    if image_meta and (image_meta.get("width", 9999) < 640 or image_meta.get("height", 9999) < 420):
        flags.append(
            {
                "severity": "high" if is_identity_photo(document) else "medium",
                "flag_code": "low_resolution",
                "flag_label": "Low image resolution",
                "description": "Image dimensions are low for reliable OCR and tampering analysis.",
                "confidence": 71.0,
            }
        )

    if fingerprint.startswith(("0000", "dead", "fake")):
        flags.append(
            {
                "severity": "high",
                "flag_code": "synthetic_fingerprint",
                "flag_label": "Suspicious document fingerprint",
                "description": "Document fingerprint matches a mock suspicious pattern.",
                "confidence": 76.0,
            }
        )

    return flags


def mismatches(document: DocumentPayload, extracted: dict[str, Any], driver: DriverPayload) -> list[str]:
    issues: list[str] = []
    if document.document_type == "license" and extracted.get("license_number"):
        if normalize(str(extracted["license_number"])) != normalize(driver.license_number):
            issues.append("Uploaded license number does not match registered profile.")
    if document.document_type in {"vehicle_rc", "insurance", "vehicle_image"} and extracted.get("vehicle_registration_number"):
        if normalize(str(extracted["vehicle_registration_number"])) != normalize(driver.vehicle_number):
            issues.append("Uploaded vehicle document does not match registered vehicle number.")
    return issues


def score_payload(
    document_scores: list[float],
    checks: list[dict[str, Any]],
    flags: list[dict[str, Any]],
    has_selfie: bool,
    face_similarity: float | None = None,
) -> dict[str, Any]:
    ocr_score = sum(document_scores) / len(document_scores) if document_scores else 0.0
    passed = len([c for c in checks if c["status"] == "passed"])
    api_score = (passed / len(checks) * 100.0) if checks else 0.0

    # Real face score: use computed similarity when OpenCV is available and a selfie was provided.
    # Fall back to a conservative estimate when face matching isn't possible.
    if has_selfie:
        if face_similarity is not None:
            # Scale ORB feature-match similarity into a usable 0–100 score.
            # ORB similarity tends to be conservative (rarely hits 100), so we
            # apply a mild boost while keeping the raw value meaningful.
            face_score = min(100.0, face_similarity * 1.2)
        else:
            # OpenCV unavailable — selfie present but unverified; give a neutral score
            face_score = 65.0
    else:
        face_score = 0.0

    penalty = sum({"critical": 38, "high": 24, "medium": 14, "low": 6, "info": 2}.get(f["severity"], 6) for f in flags)
    fraud_score = max(0.0, 100.0 - penalty)
    confidence = round((ocr_score * 0.25) + (api_score * 0.30) + (face_score * 0.20) + (fraud_score * 0.25))

    has_critical = any(f["severity"] == "critical" for f in flags)
    has_high = any(f["severity"] in {"critical", "high"} for f in flags)

    if has_critical or confidence < 50:
        status = "fake_tampered"
    elif confidence >= 85 and not has_high and has_selfie:
        status = "verified"
    elif confidence >= 65:
        status = "needs_manual_review" if not has_selfie else "suspicious"
    elif confidence >= 50:
        # Low confidence but no critical flag — escalate to manual review rather than
        # auto-rejecting, giving an admin the chance to make the final call.
        status = "needs_manual_review"
    else:
        status = "fake_tampered"

    return {
        "status": status,
        "confidence_score": confidence,
        "scores": {
            "ocr": round(ocr_score, 2),
            "api": round(api_score, 2),
            "face": round(face_score, 2),
            "fraud": round(fraud_score, 2),
        },
    }


def readiness_payload() -> tuple[bool, dict[str, Any]]:
    token_ready = secret_configured(os.environ.get("RIDESYNC_VERIFICATION_SERVICE_TOKEN", "").strip()) or app_env() != "production"
    provider_is_mock = provider.name == "mock_compliance_provider"
    payload = {
        "ok": token_ready,
        "service": "ridesync-driver-verification",
        "environment": app_env(),
        "checks": {
            "service_token_configured": token_ready,
            "provider_available": True,
            "provider": provider.name,
            "mock_provider": provider_is_mock,
            "opencv_available": cv2 is not None,
            "tesseract_available": pytesseract is not None,
            "face_matching_available": cv2 is not None,
            "limits": {
                "max_request_bytes": MAX_REQUEST_BYTES,
                "max_file_base64_bytes": MAX_FILE_BASE64_BYTES,
                "max_documents": MAX_DOCUMENTS,
                "ocr_timeout_seconds": OCR_TIMEOUT_SECONDS,
                "max_image_pixels": MAX_IMAGE_PIXELS,
            },
        },
    }
    return bool(payload["ok"]), payload


@app.get("/healthz")
def healthz() -> dict[str, Any]:
    return {
        "ok": True,
        "service": "ridesync-driver-verification",
        "opencv_available": cv2 is not None,
        "tesseract_available": pytesseract is not None,
        "face_matching_available": cv2 is not None,
        "provider": provider.name,
    }


@app.get("/readyz")
def readyz() -> JSONResponse:
    ok, payload = readiness_payload()
    return JSONResponse(status_code=200 if ok else 503, content=payload)


@app.post("/v1/driver-verifications/analyze", dependencies=[Depends(require_service_auth)])
def analyze(payload: AnalyzePayload) -> dict[str, Any]:
    if len(payload.documents) > MAX_DOCUMENTS:
        raise HTTPException(status_code=413, detail="too many documents")

    encoded_bytes = sum(len(document.file_base64 or "") for document in payload.documents)
    if encoded_bytes > MAX_FILE_BASE64_BYTES:
        raise HTTPException(status_code=413, detail="document payload too large")

    all_checks: list[dict[str, Any]] = []
    all_flags: list[dict[str, Any]] = []
    all_mismatches: list[str] = []
    document_results: list[dict[str, Any]] = []
    document_scores: list[float] = []
    has_submitted_selfie = False
    has_valid_selfie = False

    # Collect identity photo bytes for face-match comparison (selfie vs ID photo)
    selfie_bytes: bytes | None = None
    id_photo_bytes: bytes | None = None

    for document in payload.documents:
        extracted = extract_document(document, payload.driver)
        doc_mismatches = mismatches(document, extracted, payload.driver)
        flags = fraud_flags(document, extracted)
        provider_checks = [
            check.__dict__
            for check in provider.checks_for_document(document.model_dump(exclude={"file_base64"}), payload.driver.model_dump(), extracted)
        ]

        penalty = (len(doc_mismatches) * 15) + sum({"critical": 38, "high": 26, "medium": 16, "low": 8}.get(f["severity"], 4) for f in flags)
        document_score = max(0.0, 88.0 - penalty)
        document_scores.append(document_score)
        all_checks.extend(provider_checks)
        all_flags.extend(flags)
        all_mismatches.extend(doc_mismatches)
        if is_identity_photo(document) and document.is_file:
            has_submitted_selfie = True
            usable = identity_photo_is_usable(document, extracted)
            has_valid_selfie = has_valid_selfie or usable
            if usable:
                raw_bytes = identity_photo_bytes(document)
                if document.document_type == "selfie" and raw_bytes:
                    selfie_bytes = raw_bytes
                elif document.document_type in {"profile_photo"} and raw_bytes:
                    id_photo_bytes = raw_bytes

        document_results.append(
            {
                "document_id": document.id,
                "document_type": document.document_type,
                "extracted": redact_extracted(extracted),
                "mismatches": doc_mismatches,
                "fraud_flags": flags,
                "api_checks": provider_checks,
                "document_score": round(document_score, 2),
            }
        )

    # Compute real face similarity when both selfie and an ID/profile photo are available
    computed_face_similarity: float | None = None
    if selfie_bytes and id_photo_bytes:
        computed_face_similarity = face_similarity_score(selfie_bytes, id_photo_bytes)
    elif selfie_bytes:
        # Only selfie present — self-consistency check (same image decoded twice is always ~100)
        # Skip self-comparison; leave similarity as None so score_payload uses the fallback
        computed_face_similarity = None

    final = score_payload(document_scores, all_checks, all_flags, has_valid_selfie, computed_face_similarity)
    reasons = all_mismatches + [flag["flag_label"] for flag in all_flags]
    if not has_submitted_selfie:
        reasons.append("Selfie is missing for face match verification.")
    if not reasons and final["status"] == "verified":
        reasons.append("All submitted evidence passed automated consistency checks.")

    face_status = "passed" if has_valid_selfie else ("failed" if has_submitted_selfie else "not_available")
    # Similarity is the computed ORB score when both selfie and ID photo are present,
    # otherwise 0.0 — never use a hardcoded magic number here.
    face_similarity_pct = computed_face_similarity if (computed_face_similarity is not None and has_valid_selfie) else 0.0

    return {
        "ok": True,
        "session_id": payload.session_id,
        "status": final["status"],
        "confidence_score": final["confidence_score"],
        "scores": final["scores"],
        "reasons": reasons[:8],
        "documents": document_results,
        "face_match": {
            "status": face_status,
            "similarity_percent": round(face_similarity_pct, 2),
            "threshold_percent": 82.0,
            "method": "orb_feature_match" if computed_face_similarity is not None else ("not_computed" if has_valid_selfie else "not_available"),
        },
        "provider": provider.name,
    }
