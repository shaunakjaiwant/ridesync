# RideSync Driver Verification Intelligence Service

FastAPI microservice for RideSync admin KYC verification. The PHP app posts driver and document metadata to this service through `RIDESYNC_VERIFICATION_SERVICE_URL`.

## Local run

```bash
cd ai_verification_service
python -m venv .venv
.venv\Scripts\activate
pip install -r requirements.txt
uvicorn app.main:app --host 127.0.0.1 --port 8011
```

Set this for PHP:

```env
RIDESYNC_VERIFICATION_SERVICE_URL=http://127.0.0.1:8011
RIDESYNC_VERIFICATION_SERVICE_TOKEN=replace-with-random-32-plus-character-token
RIDESYNC_KYC_PROVIDER=mock_compliance_provider
```

PHP sends `Authorization: Bearer <RIDESYNC_VERIFICATION_SERVICE_TOKEN>` when the token is configured. In `RIDESYNC_ENV=production`, the service fails closed until that token is present.

## Runtime contract

- `GET /healthz` is public liveness.
- `GET /readyz` validates production readiness, including service-token configuration and active limits.
- `POST /v1/driver-verifications/analyze` requires `Content-Type: application/json` and bearer auth when the token is configured.
- Request bodies, base64 document payloads, document counts, OCR runtime, and decoded image pixels are bounded by `RIDESYNC_VERIFICATION_*` environment variables.
- Request IDs are accepted through `X-Request-Id`, echoed in responses, and included in structured JSON logs.
- Validation errors omit raw request input, and analysis responses redact license, PAN, Aadhaar, vehicle, document, token, and base64-like fields.
- Corrupt identity images and low-resolution selfies cannot produce a verified decision.
- Production external provider URLs must use HTTPS, cannot include credentials or fragments, and cannot target loopback, private, reserved, multicast, link-local, or metadata IP ranges.
- Production provider hostnames are resolved before every outbound call; any private or mixed public/private DNS answer is rejected, the TLS connection is pinned to the validated address, and HTTP redirects are never followed.

Run the local service self-test:

```bash
python scripts/selftest_service.py
```

## Provider adapter

The service includes a generic HTTP provider adapter so production KYC gateways can be connected without changing the verification engine:

```env
RIDESYNC_KYC_PROVIDER=idfy
RIDESYNC_KYC_PROVIDER_URL=https://provider-gateway.example.com/verify
RIDESYNC_KYC_PROVIDER_TOKEN=replace-with-secret
RIDESYNC_KYC_PROVIDER_TIMEOUT_SECONDS=6
```

The endpoint should return either a JSON object or a `checks` array with `check_type`, `status`, `confidence`, and optional `response` fields. Supported statuses are `passed`, `failed`, `needs_review`, and `not_available`.

Validate a sandbox provider contract before cutover:

```bash
RIDESYNC_KYC_PROVIDER=idfy \
RIDESYNC_KYC_PROVIDER_URL=https://provider-gateway.example.com/verify \
RIDESYNC_KYC_PROVIDER_TOKEN=... \
python scripts/validate_provider_contract.py --required
```

The validator fails if the provider response has an invalid status, missing check type, non-numeric confidence, excessive latency, or raw Aadhaar/PAN-like values. It uses the same guarded provider transport as the service, including redirect refusal and production URL restrictions.

## Production notes

- Add Redis/Celery workers for background document processing when uploads become high volume.
- Pass signed download URLs or encrypted object-storage references instead of raw files.
- Keep raw Aadhaar/PAN values out of logs. Only return masked values to PHP.
