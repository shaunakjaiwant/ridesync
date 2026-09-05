# RideSync Comprehensive Deployment Audit

**Audited Date**: August 11, 2026  
**Auditor**: Lead Architect, Full-Stack & DevOps Engineering Team  

---

## 1. Current Architecture

RideSync is a multi-tier academic campus mobility application configured as follows:

```
[ Browser Client (HTML5 / Vanilla JS / Leaflet) ]
                        │
      HTTP/S & SSE      │      WebSocket (WS/WSS)
   (Same-origin / API)  │  (Port 8081 / path: /ridesync/ws)
                        ▼
      ┌──────────────────────────────────┐
      │ Apache Web Server (PHP 8.2)      │
      │ Serves pages, actions & REST API │
      └────────────────┬─────────────────┘
                       │
         ┌─────────────┴─────────────┐
         ▼                           ▼
┌───────────────────┐    ┌───────────────────────────┐
│ MySQL 8.4 DB      │    │ Node.js WebSocket Gateway │
│ Shared state & DB │◄───┤ Real-time telemetry, SOS  │
└───────────────────┘    └───────────────────────────┘
         ▲                           ▲
         │                           │
         │ HTTP Bearer (Port 8011)   │ Redis 7 Pub/Sub
         └─────────────┬─────────────┘
                       ▼
         ┌───────────────────────────┐
         │ Python FastAPI AI/KYC     │
         │ OCR, facial comparison    │
         └───────────────────────────┘
```

---

## 2. Detected Services & Dependencies

### Monorepo / Multi-Service Components
1. **PHP Core Web Backend & Server-Rendered Views**:
   - **PHP Version**: 8.2 (Apache SAPI)
   - **DB Interface**: `mysqli` extension with MySQL transactions (`BEGIN TRANSACTION`, `COMMIT`, `ROLLBACK`) and pessimistic row locking (`SELECT ... FOR UPDATE`).
   - **Session Engine**: Native PHP sessions with 30-day lifetime configuration, CSRF token issuance, role-based access validation, and strict cookie security.
   - **Storage Engine**: Local encrypted filesystem storage under `storage/secure_driver_documents` using AES-256-CBC encryption via `RIDESYNC_DOCUMENT_ENCRYPTION_KEY`.

2. **Realtime WebSocket Gateway** (`realtime/websocket-gateway`):
   - **Runtime**: Node.js 20+
   - **Dependencies**: `ws`, `mysql2`, `redis`
   - **Path**: `/ridesync/ws` (default port 8081)
   - **Security**: HMAC SHA-256 signed query tokens (`sign(audienceType, audienceId, expiresAt)`).

3. **AI Driver KYC & Verification Microservice** (`apps/ai-verification`):
   - **Runtime**: Python 3.11/3.13 (FastAPI, Uvicorn, Pydantic v2)
   - **Dependencies**: `Pillow`, `OpenCV` (lightweight ORB feature matching), `pytesseract` (optional OCR)
   - **Endpoint**: `POST /v1/driver-verifications/analyze` (Port 8011)
   - **Security**: Bearer token authentication (`RIDESYNC_VERIFICATION_SERVICE_TOKEN`).

4. **Background & Queue Workers**:
   - `tools/queue_worker.php`: Asynchronous background processing for verification jobs and user notifications.

---

## 3. Current Database Configuration

- **Engine**: MySQL 8.4 / MariaDB
- **Database Name**: `ridesync_db`
- **Primary Schema**: Defined in `database/ridesync_db.sql`
- **Key Tables**: `users`, `driver_accounts`, `driver_account_profiles`, `driver_account_documents`, `driver_ride_requests`, `rides`, `matches`, `sos_alerts`, `user_emergency_contacts`, `realtime_events`, `background_jobs`, `schema_migrations`.
- **Concurrency & Transactions**:
  - `actions/driver_request_action.php`: Uses `mysqli_begin_transaction()` and `FOR UPDATE` queries to prevent race conditions during driver request acceptance.
  - `actions/match_action.php`: Uses transaction blocks to manage ride seat allocation.

---

## 4. Frontend / Backend Relationship

- **Frontend Engine**: Server-rendered PHP templates ([pages/*.php](file:///c:/xampp/htdocs/ridesync/pages/login.php)) combined with progressive enhancement via Vanilla JavaScript ([js/*.js](file:///c:/xampp/htdocs/ridesync/js/script.js)).
- **Navigation Prefetching & API Calls**: `js/script.js` performs `fetch()` calls to `/ridesync/api/*.php` endpoints and uses `EventSource` (SSE) or WebSockets.
- **Routing**: Relative and absolute path URLs (`/ridesync/pages/...`, `/ridesync/actions/...`, `/ridesync/api/...`).

---

## 5. Deployment Blockers & Technical Risks

1. **Path Prefix Expectations (`/ridesync/`)**:
   - Hardcoded references to `/ridesync/` exist across frontend scripts and backend headers.
   - **Resolution**: Apache `RedirectMatch 302 ^/$ /ridesync/` and Vercel routing rewrites ensure root visits route cleanly to `/ridesync/`.

2. **Session / CORS Cross-Origin Restrictions**:
   - If Vercel frontend domain (`ridesync.vercel.app`) communicates with Render backend (`ridesync-api.onrender.com`), browsers block third-party cookies unless `SameSite=None; Secure` headers are used with CORS `Access-Control-Allow-Credentials: true`.
   - **Resolution**: Vercel reverse proxying (`vercel.json` rewrites) keeps frontend and API calls on the **same origin**, preserving strict `SameSite=Lax` cookies and preventing CORS issues!

3. **Ephemeral Storage on Cloud Hosts**:
   - Render containers use ephemeral filesystems. Files saved locally to `uploads/` or `storage/` are reset on container restart.
   - **Resolution**: Keep local fallback storage intact while providing an S3/Object Storage abstraction layer (`StorageService`) for KYC document blobs.

---

## 6. Files That Must Change vs. Files That Should NOT Change

### Files That Must Change / Be Added
- [vercel.json](file:///c:/xampp/htdocs/ridesync/vercel.json): Vercel edge reverse proxy routing.
- [render.yaml](file:///c:/xampp/htdocs/ridesync/render.yaml): Render Blueprint service configuration.
- [.env.production.example](file:///c:/xampp/htdocs/ridesync/.env.production.example): Complete template of production secrets.
- [infrastructure/apache/ridesync.conf](file:///c:/xampp/htdocs/ridesync/infrastructure/apache/ridesync.conf): Apache root path redirect to `/ridesync/`.
- [health.php](file:///c:/xampp/htdocs/ridesync/health.php): Unified PHP health check endpoint for Render load balancing.
- [docs/DEPLOYMENT_GUIDE.md](file:///c:/xampp/htdocs/ridesync/docs/DEPLOYMENT_GUIDE.md): Operational guide for live deployment.
- [docs/API.md](file:///c:/xampp/htdocs/ridesync/docs/API.md): API inventory documentation.
- [docs/SECURITY.md](file:///c:/xampp/htdocs/ridesync/docs/SECURITY.md): Security architecture documentation.

### Files That Should NOT Change
- `database/ridesync_db.sql`: Core schema design and constraints.
- Business logic in `actions/match_action.php`, `actions/driver_request_action.php`, `actions/sos_action.php`.
- Algorithm for pessimistic locking (`SELECT ... FOR UPDATE`) in driver request claim flows.

---

## 7. Recommended Production Architecture

- **Frontend**: Vercel (Edge CDN reverse proxy) -> routes to Render.
- **Backend API & Web App**: Render Web Service (Docker - PHP 8.2 Apache).
- **Database**: Managed MySQL (Aiven for MySQL / TiDB Cloud / Render MySQL).
- **Realtime Gateway**: Render Web Service (Docker - Node.js WebSocket Gateway).
- **AI/KYC Service**: Render Web Service (Docker - Python 3.11 FastAPI).
