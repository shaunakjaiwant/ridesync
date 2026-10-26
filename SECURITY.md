# RideSync Security Policy

## Supported Versions

| Version | Supported          |
| ------- | ------------------ |
| 1.0.x   | :white_check_mark: |

## Reporting a Vulnerability

If you discover a security vulnerability within **RideSync**, please send an email to the project maintainers or open a confidential Security Advisory on GitHub:

- **Contact**: `shaunak.jaiwant832005@gmail.com`
- **Response SLA**: Within 24 hours
- Please do not report security issues through public GitHub issues.

### Security Architecture Highlights
- **Authentication**: BCrypt hashed passwords, strict session regeneration, and role-based access validation (`ridesync_validate_session_principal()`).
- **CSRF Protection**: Constant-time cryptographic HMAC tokens on all mutation endpoints.
- **Data Encryption**: AES-256-CBC encryption at rest for sensitive KYC driver documents.
- **Defensive HTTP Headers**: Strict CSP with nonces, HSTS, frame denial (`DENY`), and MIME nosniffing.
