# RideSync End-to-End & Concurrency Production Test Plan

This document details the test scenarios for validating RideSync functionality in production.

---

## 1. End-to-End User Flow Scenarios

### Scenario A: Rider Registration & Ride Posting
1. Open `https://ridesync.vercel.app/ridesync/pages/register.php`.
2. Register a new Rider account (`rider_test@university.edu`).
3. Log in and navigate to **Post Ride** (`/ridesync/pages/post_ride.php`).
4. Submit origin, destination, travel date, travel time, and seats.
5. Verify ride appears in **My Rides** (`/ridesync/pages/my_rides.php`).

### Scenario B: Ride Search & Join Request
1. Log in as a second user (`rider2_test@university.edu`).
2. Search for routes matching origin/destination.
3. Submit a join request.
4. Verify request status shows `pending` in **My Matches**.

### Scenario C: Driver Onboarding & KYC Approval
1. Register a Driver account (`driver_test@university.edu`).
2. Submit vehicle registration and upload driver documents.
3. Log in as Admin (`/ridesync/pages/admin_login.php`).
4. Open **Driver Verifications** (`/ridesync/pages/admin_driver_verification.php`).
5. Run automated AI analysis & approve driver verification.
6. Verify driver status updates to `verified` and `active`.

---

## 2. Pessimistic Concurrency Test (Driver Claim Race Condition)

### Test Objective
Verify that when two drivers attempt to accept the same pending ride request simultaneously, MySQL pessimistic row locking (`SELECT ... FOR UPDATE`) guarantees atomic assignment without double-claims.

### Execution Procedure
1. Create a pending ride request (`request_id = 101`).
2. Open two concurrent database connections (`Driver A` and `Driver B`).
3. `Driver A` executes:
   ```sql
   BEGIN;
   SELECT id, request_status FROM driver_ride_requests WHERE id = 101 AND request_status = 'pending' FOR UPDATE;
   ```
4. `Driver B` executes simultaneously:
   ```sql
   BEGIN;
   SELECT id, request_status FROM driver_ride_requests WHERE id = 101 AND request_status = 'pending' FOR UPDATE;
   ```
5. **Expected Result**: `Driver B` blocks until `Driver A` completes `UPDATE driver_ride_requests SET request_status = 'accepted'` and `COMMIT`.
6. When `Driver B` unblocks, `request_status` is `'accepted'`, so `Driver B` receives a controlled conflict error (`"Driver request is no longer pending"`).
