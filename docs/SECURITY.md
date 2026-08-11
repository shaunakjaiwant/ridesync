# RideSync Security Architecture & Production Hardening

This document outlines the security controls, authentication mechanisms, data protection standards, and threat mitigations implemented in RideSync.

---

## 1. Authentication & Session Management

- **Password Hashing**: Passwords are hashed using BCrypt via `password_hash($password, PASSWORD_DEFAULT)` with automatic rehashing on login.
- **Session Duration**: Persistent sessions are configured for 30 days (`session.cookie_lifetime = 2592000`).
- **Session Protection**:
  - `session.use_strict_mode = 1`
  - `session.cookie_httponly = 1`
  - `session.cookie_samesite = Lax`
  - Session IDs are regenerated upon authentication (`session_regenerate_id(true)`).
  - Suspended users or drivers are rejected immediately on every request via `ridesync_validate_session_principal()`.

---

## 2. Cross-Site Request Forgery (CSRF) Protection

- Unique 32-byte cryptographically secure random CSRF tokens (`ridesync_issue_csrf_token()`) are generated per session.
- All state-modifying requests (`POST`, `PUT`, `DELETE`) require a valid `csrf_token` form parameter or `X-CSRF-Token` header.
- Token validation uses constant-time string comparison (`hash_equals()`).

---

## 3. Data Protection & Document Storage

- **Driver KYC Documents**: Uploaded driver documents (Aadhaar, PAN, License, RC) are encrypted on disk using AES-256-CBC with random 16-byte IVs (`RIDESYNC_DOCUMENT_ENCRYPTION_KEY`).
- **Access Authorization**: Decryption and serving of documents (`pages/secure_document_view.php`) requires active Admin session authorization (`ridesync_authenticated_role() === 'admin'`).
- **Path Traversal Protection**: Document file path resolution validates realpaths to strictly prevent path traversal outside designated storage directories.

---

## 4. HTTP Security Headers

Every PHP response sends the following production security headers:

```http
X-Content-Type-Options: nosniff
X-Frame-Options: DENY
X-Download-Options: noopen
X-Permitted-Cross-Domain-Policies: none
Origin-Agent-Cluster: ?1
Cross-Origin-Opener-Policy: same-origin
Cross-Origin-Resource-Policy: same-site
Referrer-Policy: strict-origin-when-cross-origin
Permissions-Policy: camera=(), microphone=(), geolocation=(self)
Content-Security-Policy: default-src 'self'; script-src 'self' 'nonce-...'; style-src 'self'; img-src 'self' data: blob: https://*.tile.openstreetmap.org; connect-src 'self' https://nominatim.openstreetmap.org https://router.project-osrm.org;
Strict-Transport-Security: max-age=31536000; includeSubDomains (Over HTTPS)
```

---

## 5. WebSockets & Microservice Security

- **WebSocket Authentication**: WebSockets require HMAC SHA-256 signatures (`RIDESYNC_WS_SHARED_TOKEN`) matching `audience_type`, `audience_id`, and `expires_at`.
- **Python AI Service**: Requests to `POST /v1/driver-verifications/analyze` require HTTP `Authorization: Bearer <RIDESYNC_VERIFICATION_SERVICE_TOKEN>`.
