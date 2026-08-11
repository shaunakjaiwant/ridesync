# RideSync Final Deployment Checklist

Use this checklist to verify production deployment completeness across all services.

---

- [x] **Database Preparation**
  - [x] Schema SQL generated (`database/schema.sql`).
  - [x] Baseline seed SQL created (`database/seed.sql`).
  - [x] Environment variable configuration supported (`DB_HOST`, `DB_PORT`, `DB_NAME`, `DB_USER`, `DB_PASSWORD`).
  - [x] Foreign keys, indexes, and UTF-8 charset verified.

- [x] **PHP Application Backend**
  - [x] Dockerfile configured for PHP 8.2 SAPI + Apache (`Dockerfile`).
  - [x] Unified health check created (`/health.php`).
  - [x] Base root path redirect configured (`infrastructure/apache/ridesync.conf`).
  - [x] Security headers and CSP enabled (`ridesync_send_security_headers()`).
  - [x] CSRF protection enforced across all state-modifying actions.
  - [x] Driver request pessimistic locking (`SELECT ... FOR UPDATE`) preserved.

- [x] **Frontend (Vercel CDN Proxy)**
  - [x] Reverse proxy configuration created (`vercel.json`).
  - [x] Absolute and relative URL routing compatibility verified.
  - [x] Leaflet OpenStreetMap tiles over HTTPS verified.

- [x] **WebSocket Gateway Service**
  - [x] WebSocket Dockerfile created (`realtime/websocket-gateway/Dockerfile`).
  - [x] HMAC SHA-256 token authentication enabled.
  - [x] Listens on `0.0.0.0` port 8081.

- [x] **Python AI Driver KYC Service**
  - [x] FastAPI Dockerfile created (`apps/ai-verification/Dockerfile`).
  - [x] Bearer token authorization enforced (`RIDESYNC_VERIFICATION_SERVICE_TOKEN`).
  - [x] OCR & OpenCV ORB facial feature comparison enabled.

- [x] **Security & Secret Hardening**
  - [x] Secrets removed from source code and `.gitignore` updated.
  - [x] `.env.production.example` template provided.
  - [x] AES-256 encryption for driver documents enabled.
