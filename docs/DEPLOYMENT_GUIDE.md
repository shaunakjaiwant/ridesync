# RideSync Free Cloud Deployment Guide (Render & Vercel)

This guide provides step-by-step instructions for hosting the **RideSync** application online for **100% free** using **Render**, **Vercel**, and **Aiven for MySQL**.

---

## Step 1: Set Up Free Cloud MySQL Database (Aiven / TiDB)

RideSync requires a MySQL 8.0+ compatible database.

### Option A: Aiven for MySQL (Recommended - 10 GB Free Forever)
1. Sign up for a free account at [https://aiven.io](https://aiven.io).
2. Create a new service: select **Aiven for MySQL** on the **Free Plan**.
3. Note your service connection details:
   - **Host**: `mysql-xxxx.aivencloud.com`
   - **Port**: `3306` (or provided port)
   - **Database**: `ridesync_db`
   - **User**: `avnadmin`
   - **Password**: `<generated_password>`
4. Connect using phpMyAdmin, MySQL Workbench, DBeaver, or command line:
   ```bash
   mysql -h <HOST> -P <PORT> -u avnadmin -p < database/ridesync_db.sql
   ```

---

## Step 2: Deploy to Render (PHP Web App & WebSockets)

Render will build and run the RideSync Docker container containing PHP 8.2, Apache, and all security modules.

### Option 1: Render Blueprint (1-Click Automated Deployment)
1. Log in to [https://render.com](https://render.com) and connect your GitHub account.
2. Click **New +** -> **Blueprint**.
3. Select your repository: `shaunakjaiwant/ridesync`.
4. Render will automatically detect `render.yaml` and prompt for required environment variables:
   - `RIDESYNC_DB_HOST`
   - `RIDESYNC_DB_USER`
   - `RIDESYNC_DB_PASSWORD`
   - `RIDESYNC_DB_NAME` (set to `ridesync_db`)
5. Click **Apply**. Render will build and deploy:
   - `ridesync-app` (PHP Core Web App)
   - `ridesync-ws` (WebSocket Realtime Server)

### Option 2: Manual Web Service Setup on Render
1. Click **New +** -> **Web Service**.
2. Source: **Build and deploy from a Git repository** -> `shaunakjaiwant/ridesync`.
3. Select **Docker** as Environment.
4. Select **Free Tier**.
5. Add Environment Variables under **Advanced**:
   - `RIDESYNC_ENV`: `production`
   - `RIDESYNC_DEBUG`: `false`
   - `RIDESYNC_TRUST_PROXY`: `true`
   - `RIDESYNC_COOKIE_SECURE`: `true`
   - `RIDESYNC_DB_HOST`: `<Your Aiven DB Host>`
   - `RIDESYNC_DB_PORT`: `3306`
   - `RIDESYNC_DB_NAME`: `ridesync_db`
   - `RIDESYNC_DB_USER`: `avnadmin`
   - `RIDESYNC_DB_PASSWORD`: `<Your Aiven DB Password>`
   - `RIDESYNC_DOCUMENT_ENCRYPTION_KEY`: `<Generate random 32-byte Base64 key>`
   - `RIDESYNC_REPAIR_LOG_KEY`: `<Generate random 32-byte Base64 key>`
   - `RIDESYNC_METRICS_TOKEN`: `<Generate random 32-char string>`
   - `RIDESYNC_VERIFICATION_SERVICE_TOKEN`: `<Generate random 32-char string>`
   - `RIDESYNC_WS_SHARED_TOKEN`: `<Generate random 32-char string>`
6. Click **Create Web Service**.

Once deployed, Render will provide your public URL (e.g. `https://ridesync-app.onrender.com`).

---

## Step 3: Connect Vercel (Edge CDN & Custom Domain)

Vercel provides ultra-fast global CDN edge routing and custom domain support.

1. Log in to [https://vercel.com](https://vercel.com).
2. Click **Add New...** -> **Project**.
3. Import your GitHub repository: `shaunakjaiwant/ridesync`.
4. Open `vercel.json` in your repository and update `YOUR_RENDER_APP_URL.onrender.com` with your actual Render URL:
   ```json
   {
     "version": 2,
     "name": "ridesync",
     "routes": [
       {
         "src": "/(.*)",
         "dest": "https://ridesync-app.onrender.com/$1"
       }
     ]
   }
   ```
5. Click **Deploy**. Vercel will deploy your edge proxy in seconds!

---

## Step 4: Verify All 3 Panels & Initial Setup

1. Open your live Vercel or Render domain:
   - **Rider Workspace / Landing**: `https://ridesync.vercel.app/`
   - **Driver Workspace**: `https://ridesync.vercel.app/ridesync/pages/driver_login.php`
   - **First-Time Admin Setup**: `https://ridesync.vercel.app/ridesync/pages/admin_login.php`
2. Complete the initial Admin creation screen to set up your primary Admin credentials.
3. Test Rider sign-up, post ride, driver verification upload, and admin approvals.

---

## Summary of Free Tier Operational Capabilities
- **Rider Panel**: 100% Operational (Post rides, search, request matches, notifications).
- **Driver Panel**: 100% Operational (Registration, document submission, ride acceptance).
- **Admin Panel**: 100% Operational (Verification management, driver status control, system metrics).
- **Realtime / WebSockets**: Active on Render (`wss://ridesync-ws.onrender.com/ridesync/ws`).
- **AI Verification**: Runs with built-in OCR fallback on PHP server.
