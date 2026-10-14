# RideSync — Exhibition Access & Live Demo Guide

This guide contains the direct access links, seeded demonstration accounts, operational commands, and the recommended presentation sequence for live college exhibition demonstrations.

---

## 🚀 1. Active Services & URLs

| Role / Surface | URL | Description |
| :--- | :--- | :--- |
| **Public Landing** | [http://localhost/ridesync/index.php](http://localhost/ridesync/index.php) | Hero landing page, feature breakdown, role pathways |
| **Rider Portal** | [http://localhost/ridesync/pages/login.php](http://localhost/ridesync/pages/login.php) | Rider login, registration, campus ride search & posting |
| **Driver Portal** | [http://localhost/ridesync/pages/driver_login.php](http://localhost/ridesync/pages/driver_login.php) | Driver login, onboard verification, trip dispatch |
| **Admin Command Center** | [http://localhost/ridesync/pages/admin_login.php](http://localhost/ridesync/pages/admin_login.php) | Master admin authentication and control center |
| **Admin Dashboard** | [http://localhost/ridesync/pages/admin_dashboard.php](http://localhost/ridesync/pages/admin_dashboard.php) | System overview, live map, driver/rider intelligence |
| **Verification Queue** | [http://localhost/ridesync/pages/admin_dashboard.php?section=drivers](http://localhost/ridesync/pages/admin_dashboard.php?section=drivers) | Driver compliance queue & AI evaluation workbench |
| **Service Observability** | [http://localhost/ridesync/pages/admin_dashboard.php?section=services](http://localhost/ridesync/pages/admin_dashboard.php?section=services) | Realtime microservice health, latency, & repair kit |
| **Database (phpMyAdmin)** | [http://localhost/phpmyadmin](http://localhost/phpmyadmin) | Local database management (`ridesync_db`) |

---

## 🔑 2. Seeded Exhibition Demo Accounts

All demonstration accounts are non-destructive and can be re-seeded at any time by running `npm run seed:demo`.

### 1. Rider Account (Campus Commuter)
* **Email:** `rider@ridesync.com` (or `demo.rider@ridesync.test`)
* **Password:** `Password123!` (or `password123`)
* **Profile:** Verified Student, SDM Institute of Technology (SDMIT), Ujire.
* **Pre-loaded State:** Active posted ride (`SDMIT Campus, Ujire -> Mangaluru Central`).

### 2. Driver Account (Verified Campus Partner)
* **Email:** `driver@ridesync.com` (or `demo.driver@ridesync.test`)
* **Password:** `Password123!` (or `password123`)
* **Phone:** `9000000001`
* **Profile:** Active, Onboarding Complete, Car (`KA19DEMO1`, 4 seats).
* **Pre-loaded State:** Availability status **Online** in Ujire / Belthangady region.

### 3. Super Admin Account
* **Email:** `admin@ridesync.com` (or `ridesync@admin.com`)
* **Password:** `Password123!` (or `12345678`)
* **Role:** `super_admin`
* **Capabilities:** Full access to Audit Logs, AI Verification decisions, Repair Kit, and Service Observability.

---

## 🛠️ 3. Quick Terminal Commands

* **Re-seed Demo Accounts & Clean State:**
  ```bash
  npm run seed:demo
  ```
* **Run Full System Quality Gate:**
  ```bash
  npm test
  ```
* **Run Automated Browser Test in Chrome:**
  ```bash
  npm run test:chrome
  ```
* **Run Automated Browser Test in Brave:**
  ```bash
  npm run test:brave
  ```
* **Launch RideSync in Chrome:**
  ```bash
  npm run chrome:open
  ```
* **Prune Logs & Maintenance:**
  ```bash
  npm run worker:maintenance
  ```

---

## 🎬 4. Recommended Exhibition Demonstration Sequence

1. **Introduction & Landing Page** (`/index.php`):
   * Showcase the modern responsive design, dark/light theme switch, and clear student/driver mobility pathways.
2. **Rider Workspace** (`/pages/dashboard.php`):
   * Sign in as `demo.rider@ridesync.test` / `password123`.
   * Display the smart route shortcuts (frequent routes, college campus shortcuts).
   * Show route creation (`/pages/post_ride.php`) and live match suggestions (`/pages/my_matches.php`).
3. **Driver Workspace** (`/pages/driver_dashboard.php`):
   * Sign in as `demo.driver@ridesync.test` / `password123`.
   * Toggle Driver Online/Offline status with live location awareness.
   * View pending student ride requests and route matches.
4. **AI-Assisted Verification & Compliance** (`/pages/admin_driver_verification.php?driver_id=...`):
   * Sign in as `ridesync@admin.com` / `12345678`.
   * Open the Driver Verification intelligence card.
   * Show the **Weighted Decision Engine** (OCR consistency 25%, API validation 30%, facial verification 20%, fraud resistance heuristics 25%).
   * Point out the **Synthetic Demo Sandbox** badge explaining that compliance checks are running in demo mode for exhibition integrity.
5. **Admin Operations & Service Observability** (`/pages/admin_dashboard.php?section=services`):
   * Display the real-time status of the 4 platform engines: Apache HTTP, MariaDB Database, Node.js WebSocket Gateway (port 8081), and Python FastAPI AI microservice (port 8011).
   * Highlight the automated **Repair Kit** capable of deep scanning, queue recovery, index repairs, and diagnostic cache purging.
6. **Realtime WebSocket & Resiliency**:
   * Show live ride status updates powered by WebSocket with automatic polling fallback.