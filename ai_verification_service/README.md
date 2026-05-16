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

## Production notes

- Replace `MockGovernmentProvider` with Signzy, Karza, IDfy, HyperVerge, Decentro, SurePass, or DigiLocker adapters.
- Add Redis/Celery workers for background document processing when uploads become high volume.
- Pass signed download URLs or encrypted object-storage references instead of raw files.
- Keep raw Aadhaar/PAN values out of logs. Only return masked values to PHP.
