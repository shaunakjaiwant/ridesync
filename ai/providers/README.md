# KYC Provider Integrations

## Overview

The provider system allows plugging in any government-API or third-party KYC
gateway without changing the verification engine.

## Current Providers

### `mock_compliance_provider` (default)

Implemented in `apps/ai-verification/app/providers.py` as `MockGovernmentProvider`.
Works offline. Used in all development and test environments.

### Generic External HTTP Provider

Implemented as `ExternalHttpGovernmentProvider`. Configure via env:

```env
RIDESYNC_KYC_PROVIDER=your_provider_name
RIDESYNC_KYC_PROVIDER_URL=https://api.yourprovider.com/v1/verify
RIDESYNC_KYC_PROVIDER_TOKEN=your-secret-token
RIDESYNC_KYC_PROVIDER_TIMEOUT_SECONDS=6
```

The provider endpoint must return JSON with either:
- A top-level `checks` array, or
- A single object representing one check

Each check object must have:
- `check_type` (string): e.g. `driving_license_check`, `aadhaar_verification`
- `status` (string): one of `passed`, `failed`, `needs_review`, `not_available`
- `confidence` (number): 0–100
- `response` (object, optional): provider-specific metadata (auto-redacted for PII)

## Security Constraints (Production)

- Provider URL must use HTTPS
- Cannot contain credentials (`user:pass@host`)
- Cannot target loopback, private, reserved, multicast, link-local, or metadata IPs
- DNS resolution is performed before every call; mixed public/private answers are rejected
- TLS connection is pinned to the resolved address
- HTTP redirects are never followed

## Validate a New Provider Contract

```bash
cd apps/ai-verification
RIDESYNC_KYC_PROVIDER=mygateway \
RIDESYNC_KYC_PROVIDER_URL=https://sandbox.mygateway.com/verify \
RIDESYNC_KYC_PROVIDER_TOKEN=sandbox-token \
python scripts/validate_provider_contract.py --required
```

## Planned Integrations

| Provider | Country | Documents |
|----------|---------|----------|
| IDfy | India | Aadhaar, PAN, Driving License, RC |
| Digilocker | India | Government-issued eKYC |
| SARATHI | India | Driving License validation |
| VAHAN | India | Vehicle Registration |
| Stripe Identity | Global | Passport, ID card |
