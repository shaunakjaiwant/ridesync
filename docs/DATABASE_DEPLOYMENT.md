# RideSync Database Deployment Guide (Render MySQL / Aiven / TiDB)

This guide provides instructions for deploying the production database for **RideSync**.

---

## Step 1: Provision Managed Cloud MySQL

Create a managed MySQL 8.0+ instance using one of the supported free cloud database providers:

1. **Aiven for MySQL** (Free Tier - 10 GB storage, 1 GB RAM, SSL enabled).
2. **TiDB Cloud** (MySQL compatible Serverless DB).
3. **Render MySQL** (Addon).

---

## Step 2: Import Database Schema & Seed Data

Execute `database/schema.sql` followed by `database/seed.sql` using MySQL CLI or phpMyAdmin:

```bash
mysql -h <DB_HOST> -P <DB_PORT> -u <DB_USER> -p < database/schema.sql
mysql -h <DB_HOST> -P <DB_PORT> -u <DB_USER> -p < database/seed.sql
```

---

## Step 3: Configure Environment Variables

Set the following environment variables in your Render App Web Service environment settings:

```env
RIDESYNC_DB_HOST=<cloud_db_host>
RIDESYNC_DB_PORT=3306
RIDESYNC_DB_NAME=ridesync_db
RIDESYNC_DB_USER=<cloud_db_user>
RIDESYNC_DB_PASSWORD=<cloud_db_password>
RIDESYNC_DB_CONNECT_TIMEOUT=10
```

---

## Step 4: Verify Database Connectivity

Verify that the health check endpoint returns `database: connected`:

```bash
curl -fsS https://ridesync-api.onrender.com/health.php
```
Expected output:
```json
{
  "status": "ok",
  "service": "ridesync-php-api",
  "environment": "production",
  "database": "connected"
}
```
