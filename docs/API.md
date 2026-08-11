# RideSync Production API Reference

This document serves as the complete inventory of all REST endpoints, SSE streams, and action handlers in RideSync.

---

## Public & Authentication Endpoints

### 1. User Login Action
- **Method**: `POST`
- **Path**: `/actions/login_action.php`
- **Auth Required**: No (Public)
- **Inputs**: `email` (string), `password` (string), `csrf_token` (string)
- **Role Validated**: `rider`
- **Success Response**: `302 Redirect` to `/ridesync/pages/dashboard.php`
- **Error Response**: `302 Redirect` to `/ridesync/pages/login.php` with session flash error
- **Tables Affected**: `users`

### 2. Driver Login Action
- **Method**: `POST`
- **Path**: `/actions/driver_login_action.php`
- **Auth Required**: No (Public)
- **Inputs**: `email` (string), `password` (string), `csrf_token` (string)
- **Role Validated**: `driver`
- **Success Response**: `302 Redirect` to `/ridesync/pages/driver_dashboard.php`
- **Error Response**: `302 Redirect` to `/ridesync/pages/driver_login.php` with session flash error
- **Tables Affected**: `driver_accounts`

### 3. Admin Login Action
- **Method**: `POST`
- **Path**: `/actions/admin_login_action.php`
- **Auth Required**: No (Public)
- **Inputs**: `email_or_username` (string), `password` (string), `csrf_token` (string)
- **Role Validated**: `admin` / `super_admin`
- **Success Response**: `302 Redirect` to `/ridesync/pages/admin_dashboard.php`
- **Tables Affected**: `admin_users`

### 4. User Registration Action
- **Method**: `POST`
- **Path**: `/actions/register_action.php`
- **Auth Required**: No (Public)
- **Inputs**: `name`, `email`, `password`, `college`, `gender`, `csrf_token`
- **Tables Affected**: `users`

---

## Core Mobility & Ride Operations

### 5. Post Ride Action
- **Method**: `POST`
- **Path**: `/actions/post_ride_action.php`
- **Auth Required**: Yes (`rider`)
- **Inputs**: `origin`, `destination`, `travel_date`, `travel_time`, `seats_available`, `origin_lat`, `origin_lng`, `destination_lat`, `destination_lng`, `csrf_token`
- **Tables Affected**: `rides`

### 6. Match Request Action
- **Method**: `POST`
- **Path**: `/actions/match_action.php`
- **Auth Required**: Yes (`rider`)
- **Inputs**: `ride_id`, `action_type` (`request` | `accept` | `reject` | `cancel`), `csrf_token`
- **Tables Affected**: `matches`, `rides`

### 7. Driver Request Action
- **Method**: `POST`
- **Path**: `/actions/driver_request_action.php`
- **Auth Required**: Yes (`rider`)
- **Inputs**: `driver_id`, `pickup`, `drop_location`, `route_distance_km`, `action_type` (`create` | `cancel_pending`), `csrf_token`
- **Concurrency Locking**: Executes `SELECT ... FOR UPDATE` inside a MySQL transaction block to ensure atomic allocation.
- **Tables Affected**: `driver_ride_requests`, `driver_accounts`, `driver_account_availability`

### 8. Driver Response Action
- **Method**: `POST`
- **Path**: `/actions/driver_response_action.php`
- **Auth Required**: Yes (`driver`)
- **Inputs**: `request_id`, `response` (`accept` | `reject`), `csrf_token`
- **Tables Affected**: `driver_ride_requests`, `driver_ride_history`

---

## Safety & Realtime Telemetry

### 9. Emergency SOS Alert Action
- **Method**: `POST`
- **Path**: `/actions/sos_action.php`
- **Auth Required**: Yes (`rider` | `driver` | `admin`)
- **Inputs**: `ride_id`, `latitude`, `longitude`, `note`, `csrf_token`
- **Side Effects**: Inserts into `sos_alerts`, pushes real-time event to `realtime_events`, triggers emergency contact notifications.

### 10. Realtime Notifications SSE Endpoint
- **Method**: `GET`
- **Path**: `/api/events.php`
- **Auth Required**: Yes
- **Response**: `text/event-stream` Server-Sent Events

### 11. WebSocket Gateway Endpoint
- **Method**: `WSS`
- **Path**: `/ridesync/ws?audience_type=user&audience_id=X&expires_at=Y&token=Z`
- **Auth Required**: Yes (HMAC SHA-256 Token)
- **Protocol**: WebSocket

---

## Health & System Operations

### 12. PHP Backend Health Check
- **Method**: `GET`
- **Path**: `/health.php`
- **Auth Required**: No
- **Response**: `200 OK` JSON (`{"status": "ok", "database": "connected"}`)
