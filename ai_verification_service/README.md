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
RIDESYNC_KYC_PROVIDER=mock_compliance_provider
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

## Production notes

- Add Redis/Celery workers for background document processing when uploads become high volume.
- Pass signed download URLs or encrypted object-storage references instead of raw files.
- Keep raw Aadhaar/PAN values out of logs. Only return masked values to PHP.
