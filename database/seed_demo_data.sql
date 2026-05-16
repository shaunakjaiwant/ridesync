-- RideSync non-destructive demo seed.
-- Demo password for all seeded accounts: password123

USE ridesync_db;

INSERT INTO users (name, email, password, college, gender)
VALUES
  ('Demo Rider', 'demo.rider@ridesync.test', '$2y$10$NXachYJvyLdOzSTpm0TA3Ox0aB4P6YRhIeJJnnBGtDrwFGV1aKXF6', 'SDM Institute of Technology, Ujire', 'Other'),
  ('Demo Passenger', 'demo.passenger@ridesync.test', '$2y$10$NXachYJvyLdOzSTpm0TA3Ox0aB4P6YRhIeJJnnBGtDrwFGV1aKXF6', 'SDM College, Ujire', 'Other')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  college = VALUES(college),
  gender = VALUES(gender);

INSERT INTO user_verifications (user_id, verification_type, status, reference)
SELECT id, 'manual', 'verified', 'Demo seed verified student'
FROM users
WHERE email IN ('demo.rider@ridesync.test', 'demo.passenger@ridesync.test')
ON DUPLICATE KEY UPDATE status = 'verified', reference = VALUES(reference);

INSERT INTO driver_accounts (name, email, password, phone, status, onboarding_status)
VALUES ('Demo Driver', 'demo.driver@ridesync.test', '$2y$10$NXachYJvyLdOzSTpm0TA3Ox0aB4P6YRhIeJJnnBGtDrwFGV1aKXF6', '9000000001', 'active', 'complete')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  status = 'active',
  onboarding_status = 'complete';

INSERT INTO driver_account_profiles (driver_id, license_number, verification_details, verification_status)
SELECT id, 'DEMO-LIC-001', 'Seeded verified driver profile.', 'verified'
FROM driver_accounts
WHERE email = 'demo.driver@ridesync.test'
ON DUPLICATE KEY UPDATE
  license_number = VALUES(license_number),
  verification_details = VALUES(verification_details),
  verification_status = 'verified';

INSERT INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity)
SELECT id, 'Car', 'KA19DEMO1', 4
FROM driver_accounts
WHERE email = 'demo.driver@ridesync.test'
ON DUPLICATE KEY UPDATE
  vehicle_type = VALUES(vehicle_type),
  vehicle_number = VALUES(vehicle_number),
  seating_capacity = VALUES(seating_capacity);

INSERT INTO driver_account_documents (driver_id, document_type, document_reference, verification_status)
SELECT d.id, doc.document_type, CONCAT('Demo ', doc.document_type, ' reference'), 'verified'
FROM driver_accounts d
JOIN (
  SELECT 'license' AS document_type
  UNION ALL SELECT 'id_proof'
  UNION ALL SELECT 'vehicle_rc'
  UNION ALL SELECT 'insurance'
) doc
WHERE d.email = 'demo.driver@ridesync.test'
ON DUPLICATE KEY UPDATE verification_status = 'verified';

INSERT INTO driver_account_availability (driver_id, status, current_lat, current_lng)
SELECT id, 'online', 12.91870, 75.00560
FROM driver_accounts
WHERE email = 'demo.driver@ridesync.test'
ON DUPLICATE KEY UPDATE
  status = 'online',
  current_lat = VALUES(current_lat),
  current_lng = VALUES(current_lng),
  last_changed_at = CURRENT_TIMESTAMP;

INSERT INTO rides (user_id, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, route_distance_km, travel_date, travel_time, seats_available, status)
SELECT id, 'SDMIT Campus, Ujire', 'Mangaluru Central', 12.98220, 75.34120, 12.86980, 74.84300, 62.40, DATE_ADD(CURDATE(), INTERVAL 1 DAY), '09:00:00', 3, 'open'
FROM users
WHERE email = 'demo.rider@ridesync.test'
ON DUPLICATE KEY UPDATE seats_available = VALUES(seats_available);

INSERT INTO ride_live_status (ride_id, live_status, note)
SELECT r.id, 'searching', 'Demo ride is available for smart matching.'
FROM rides r
JOIN users u ON u.id = r.user_id
WHERE u.email = 'demo.rider@ridesync.test'
  AND r.origin = 'SDMIT Campus, Ujire'
  AND r.destination = 'Mangaluru Central'
ON DUPLICATE KEY UPDATE live_status = VALUES(live_status), note = VALUES(note);

INSERT INTO admin_users (name, email, password, role, status)
VALUES ('Demo Admin', 'demo.admin@ridesync.test', '$2y$10$NXachYJvyLdOzSTpm0TA3Ox0aB4P6YRhIeJJnnBGtDrwFGV1aKXF6', 'super_admin', 'active')
ON DUPLICATE KEY UPDATE
  name = VALUES(name),
  role = VALUES(role),
  status = VALUES(status);
