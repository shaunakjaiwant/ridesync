# Fraud Detection Module

## Current Implementation

Fraud detection is currently handled inside `apps/ai-verification/app/main.py` via
`fraud_flags()`, a rule-based system that checks:

| Flag Code | Trigger | Severity |
|-----------|---------|---------|
| `reference_only` | No file uploaded, only metadata | medium |
| `file_decode_failed` | File bytes cannot be decoded as image/PDF | critical (selfie), high (others) |
| `low_resolution` | Image dimensions below 640×420 | high (selfie), medium (others) |
| `synthetic_fingerprint` | Reference fingerprint starts with `0000`, `dead`, or `fake` | high |

## Scoring Formula

```
penalty = Σ { critical: 38, high: 24, medium: 14, low: 6, info: 2 }
fraud_score = max(0.0, 100.0 - penalty)
```

## Planned Improvements

- Document tamper detection using ELA (Error Level Analysis) on JPEG files
- Metadata consistency check (EXIF timestamp vs. document date)
- Hash-based duplicate document detection (same file submitted by multiple drivers)
- Pattern matching for known fake document templates
- ML-based document authenticity classifier (binary: genuine vs. manipulated)
- Velocity checks: same device/IP submitting many verification sessions
