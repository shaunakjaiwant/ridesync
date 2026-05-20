-- RideSync database schema for XAMPP/phpMyAdmin.
-- Import this file in phpMyAdmin, or run it from the MySQL console.

CREATE DATABASE IF NOT EXISTS ridesync_db
  CHARACTER SET utf8mb4
  COLLATE utf8mb4_unicode_ci;

USE ridesync_db;

CREATE TABLE IF NOT EXISTS users (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  college VARCHAR(150) NOT NULL,
  gender ENUM('Male', 'Female', 'Other') NOT NULL,
  profile_photo VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS rides (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  origin VARCHAR(150) NOT NULL,
  destination VARCHAR(150) NOT NULL,
  origin_lat DECIMAL(10, 7) NULL,
  origin_lng DECIMAL(10, 7) NULL,
  destination_lat DECIMAL(10, 7) NULL,
  destination_lng DECIMAL(10, 7) NULL,
  route_distance_km DECIMAL(8, 2) NULL,
  travel_date DATE NOT NULL,
  travel_time TIME NOT NULL,
  seats_available TINYINT UNSIGNED NOT NULL DEFAULT 1,
  status ENUM('open', 'closed', 'cancelled') NOT NULL DEFAULT 'open',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_rides_user_id (user_id),
  KEY idx_rides_search (status, travel_date, travel_time),
  KEY idx_rides_user_status_time (user_id, status, travel_date, travel_time),
  CONSTRAINT fk_rides_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS matches (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ride_id INT UNSIGNED NOT NULL,
  matched_user_id INT UNSIGNED NOT NULL,
  status ENUM('pending', 'accepted', 'rejected') NOT NULL DEFAULT 'pending',
  match_score DECIMAL(5,2) NULL,
  pickup_distance_km DECIMAL(8,2) NULL,
  drop_distance_km DECIMAL(8,2) NULL,
  route_overlap_percent TINYINT UNSIGNED NULL,
  time_score TINYINT UNSIGNED NULL,
  match_source ENUM('manual', 'smart', 'driver_fallback') NOT NULL DEFAULT 'manual',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_matches_ride_user (ride_id, matched_user_id),
  KEY idx_matches_user_id (matched_user_id),
  KEY idx_matches_status (status),
  KEY idx_matches_score (match_score),
  KEY idx_matches_ride_status (ride_id, status, created_at),
  KEY idx_matches_user_status (matched_user_id, status, created_at),
  CONSTRAINT fk_matches_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_matches_user
    FOREIGN KEY (matched_user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_accounts (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  phone VARCHAR(20) NOT NULL,
  status ENUM('active', 'inactive', 'suspended') NOT NULL DEFAULT 'active',
  onboarding_status ENUM('incomplete', 'complete') NOT NULL DEFAULT 'complete',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_accounts_email (email),
  UNIQUE KEY uq_driver_accounts_phone (phone)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_account_profiles (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  license_number VARCHAR(80) NOT NULL,
  verification_details TEXT NULL,
  verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_account_profiles_driver_id (driver_id),
  KEY idx_driver_profiles_status (verification_status, driver_id),
  CONSTRAINT fk_driver_account_profiles_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_account_vehicles (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  vehicle_type ENUM('Bike', 'Car', 'Auto', 'Van', 'Other') NOT NULL,
  vehicle_number VARCHAR(40) NOT NULL,
  seating_capacity TINYINT UNSIGNED NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_account_vehicles_driver_id (driver_id),
  UNIQUE KEY uq_driver_account_vehicles_number (vehicle_number),
  CONSTRAINT fk_driver_account_vehicles_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_account_documents (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  document_type ENUM('license', 'aadhaar', 'pan', 'id_proof', 'vehicle_rc', 'insurance', 'profile_photo', 'selfie', 'vehicle_image', 'other') NOT NULL DEFAULT 'license',
  document_reference VARCHAR(255) NOT NULL,
  verification_status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_account_documents_type (driver_id, document_type),
  KEY idx_driver_account_documents_driver_id (driver_id),
  KEY idx_driver_documents_status (verification_status, document_type, driver_id),
  CONSTRAINT fk_driver_account_documents_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_account_availability (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  status ENUM('online', 'offline') NOT NULL DEFAULT 'offline',
  current_lat DECIMAL(10, 7) NULL,
  current_lng DECIMAL(10, 7) NULL,
  last_changed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_driver_account_availability_driver_id (driver_id),
  KEY idx_driver_account_availability_status (status),
  KEY idx_driver_availability_status_changed (status, last_changed_at),
  CONSTRAINT fk_driver_account_availability_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_ride_requests (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  rider_user_id INT UNSIGNED NULL,
  pickup VARCHAR(200) NOT NULL,
  drop_location VARCHAR(200) NOT NULL,
  estimated_fare DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  route_distance_km DECIMAL(8,2) NULL,
  fare_rate_per_km DECIMAL(6,2) NOT NULL DEFAULT 25.60,
  pricing_version VARCHAR(40) NOT NULL DEFAULT 'km_rate_v3_fair_split',
  request_status ENUM('pending', 'accepted', 'rejected', 'expired', 'cancelled', 'completed') NOT NULL DEFAULT 'pending',
  requested_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  responded_at TIMESTAMP NULL DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_driver_ride_requests_driver_status (driver_id, request_status),
  KEY idx_driver_ride_requests_rider (rider_user_id),
  KEY idx_driver_requests_rider_status_time (rider_user_id, request_status, requested_at),
  KEY idx_driver_requests_status_requested (request_status, requested_at),
  CONSTRAINT fk_driver_ride_requests_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_driver_ride_requests_rider
    FOREIGN KEY (rider_user_id) REFERENCES users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_ride_history (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  pickup VARCHAR(200) NOT NULL,
  drop_location VARCHAR(200) NOT NULL,
  fare DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  distance_km DECIMAL(8,2) NOT NULL DEFAULT 0.00,
  source_type ENUM('direct_request', 'community_ride') NULL,
  source_id INT NULL,
  completed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_driver_ride_history_driver_date (driver_id, completed_at),
  UNIQUE KEY uq_driver_ride_history_source (driver_id, source_type, source_id),
  CONSTRAINT fk_driver_ride_history_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ride_routes (
  id INT NOT NULL AUTO_INCREMENT,
  ride_id INT UNSIGNED NOT NULL,
  encoded_polyline LONGTEXT NULL,
  distance_km DECIMAL(8,2) NULL,
  duration_minutes INT UNSIGNED NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ride_routes_ride_id (ride_id),
  CONSTRAINT fk_ride_routes_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ride_live_status (
  id INT NOT NULL AUTO_INCREMENT,
  ride_id INT UNSIGNED NOT NULL,
  driver_id INT NULL,
  live_status ENUM('searching', 'matched', 'driver_assigned', 'arriving', 'active', 'completed', 'cancelled') NOT NULL DEFAULT 'searching',
  eta_minutes INT UNSIGNED NULL,
  note VARCHAR(255) NULL,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_ride_live_status_ride_id (ride_id),
  KEY idx_ride_live_status_driver_id (driver_id),
  KEY idx_ride_live_status_status (live_status),
  CONSTRAINT fk_ride_live_status_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ride_live_status_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS notifications (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NULL,
  driver_id INT NULL,
  title VARCHAR(120) NOT NULL,
  message VARCHAR(255) NOT NULL,
  is_read TINYINT(1) NOT NULL DEFAULT 0,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_notifications_user_read (user_id, is_read),
  KEY idx_notifications_driver_read (driver_id, is_read),
  KEY idx_notifications_user_created (user_id, created_at),
  KEY idx_notifications_driver_created (driver_id, created_at),
  CONSTRAINT fk_notifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_notifications_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS background_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  job_type VARCHAR(80) NOT NULL,
  queue_name VARCHAR(80) NOT NULL DEFAULT 'default',
  payload_json LONGTEXT NOT NULL,
  status ENUM('queued', 'processing', 'succeeded', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
  attempts INT UNSIGNED NOT NULL DEFAULT 0,
  max_attempts INT UNSIGNED NOT NULL DEFAULT 5,
  available_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  locked_at TIMESTAMP NULL DEFAULT NULL,
  locked_by VARCHAR(120) NULL,
  last_error VARCHAR(255) NULL,
  result_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_background_jobs_ready (queue_name, status, available_at, id),
  KEY idx_background_jobs_type_status (job_type, status, created_at),
  KEY idx_background_jobs_locked (locked_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  event_type VARCHAR(100) NOT NULL,
  audience_type VARCHAR(40) NOT NULL,
  audience_id INT NULL,
  aggregate_type VARCHAR(60) NULL,
  aggregate_id INT NULL,
  payload_json LONGTEXT NOT NULL,
  idempotency_key VARCHAR(120) NULL,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_realtime_events_idempotency (idempotency_key),
  KEY idx_realtime_events_audience (audience_type, audience_id, id),
  KEY idx_realtime_events_aggregate (aggregate_type, aggregate_id, id),
  KEY idx_realtime_events_type_time (event_type, created_at),
  KEY idx_realtime_events_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_ratings (
  id INT NOT NULL AUTO_INCREMENT,
  ride_id INT UNSIGNED NOT NULL,
  reviewer_user_id INT UNSIGNED NOT NULL,
  reviewed_user_id INT UNSIGNED NOT NULL,
  rating TINYINT UNSIGNED NOT NULL,
  comment VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_rating_once (ride_id, reviewer_user_id, reviewed_user_id),
  KEY idx_user_ratings_reviewed (reviewed_user_id),
  CONSTRAINT fk_user_ratings_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_user_ratings_reviewer
    FOREIGN KEY (reviewer_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_user_ratings_reviewed
    FOREIGN KEY (reviewed_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT chk_user_ratings_rating CHECK (rating BETWEEN 1 AND 5)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS ride_tracking (
  id INT NOT NULL AUTO_INCREMENT,
  ride_id INT UNSIGNED NOT NULL,
  driver_id INT NULL,
  lat DECIMAL(10,7) NOT NULL,
  lng DECIMAL(10,7) NOT NULL,
  eta_minutes INT UNSIGNED NULL,
  recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_ride_tracking_ride_time (ride_id, recorded_at),
  KEY idx_ride_tracking_driver_time (driver_id, recorded_at),
  CONSTRAINT fk_ride_tracking_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_ride_tracking_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_verifications (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  verification_type ENUM('college_email', 'student_id', 'manual') NOT NULL DEFAULT 'manual',
  status ENUM('pending', 'verified', 'rejected') NOT NULL DEFAULT 'pending',
  reference VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_user_verification_type (user_id, verification_type),
  KEY idx_user_verifications_status (status),
  CONSTRAINT fk_user_verifications_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_users (
  id INT NOT NULL AUTO_INCREMENT,
  name VARCHAR(100) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('super_admin', 'moderator') NOT NULL DEFAULT 'moderator',
  status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_email (email),
  KEY idx_admin_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS driver_verification_sessions (
  id INT NOT NULL AUTO_INCREMENT,
  driver_id INT NOT NULL,
  status ENUM('queued', 'processing', 'verified', 'suspicious', 'fake_tampered', 'needs_manual_review', 'failed', 'cancelled') NOT NULL DEFAULT 'queued',
  ai_decision ENUM('verified', 'suspicious', 'fake_tampered', 'needs_manual_review') NULL,
  risk_level ENUM('low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  confidence_score DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  ocr_score DECIMAL(5,2) NULL,
  api_score DECIMAL(5,2) NULL,
  face_score DECIMAL(5,2) NULL,
  fraud_score DECIMAL(5,2) NULL,
  progress_stage ENUM('queued', 'ocr', 'face_match', 'api_validation', 'fraud_analysis', 'decision', 'complete', 'failed') NOT NULL DEFAULT 'queued',
  provider VARCHAR(80) NOT NULL DEFAULT 'mock_compliance_provider',
  model_version VARCHAR(80) NOT NULL DEFAULT 'ridesync-verification-v1',
  reasons_json LONGTEXT NULL,
  input_snapshot_json LONGTEXT NULL,
  service_response_json LONGTEXT NULL,
  admin_decision ENUM('approved', 'rejected', 'escalated') NULL,
  admin_note TEXT NULL,
  decided_by INT NULL,
  queued_at TIMESTAMP NULL DEFAULT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  completed_at TIMESTAMP NULL DEFAULT NULL,
  decided_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_driver_verification_driver_time (driver_id, created_at),
  KEY idx_driver_verification_status (status, risk_level, created_at),
  KEY idx_driver_verification_decider (decided_by),
  CONSTRAINT fk_driver_verification_sessions_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_driver_verification_sessions_admin
    FOREIGN KEY (decided_by) REFERENCES admin_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS document_analysis_results (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  document_id INT NULL,
  driver_id INT NOT NULL,
  document_type VARCHAR(40) NOT NULL,
  analysis_status ENUM('passed', 'needs_review', 'failed', 'not_available') NOT NULL DEFAULT 'needs_review',
  extracted_json LONGTEXT NULL,
  normalized_json LONGTEXT NULL,
  mismatch_json LONGTEXT NULL,
  ocr_confidence DECIMAL(5,2) NULL,
  authenticity_score DECIMAL(5,2) NULL,
  document_score DECIMAL(5,2) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_document_analysis_session (session_id, document_type),
  KEY idx_document_analysis_document (document_id),
  KEY idx_document_analysis_driver (driver_id),
  CONSTRAINT fk_document_analysis_session
    FOREIGN KEY (session_id) REFERENCES driver_verification_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_document_analysis_document
    FOREIGN KEY (document_id) REFERENCES driver_account_documents(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_document_analysis_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS fraud_flags (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  document_id INT NULL,
  severity ENUM('info', 'low', 'medium', 'high', 'critical') NOT NULL DEFAULT 'medium',
  flag_code VARCHAR(80) NOT NULL,
  flag_label VARCHAR(140) NOT NULL,
  description VARCHAR(255) NOT NULL,
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  evidence_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_fraud_flags_session (session_id, severity),
  KEY idx_fraud_flags_document (document_id),
  CONSTRAINT fk_fraud_flags_session
    FOREIGN KEY (session_id) REFERENCES driver_verification_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_fraud_flags_document
    FOREIGN KEY (document_id) REFERENCES driver_account_documents(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS face_match_results (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  selfie_document_id INT NULL,
  id_document_id INT NULL,
  similarity_percent DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  threshold_percent DECIMAL(5,2) NOT NULL DEFAULT 82.00,
  status ENUM('passed', 'failed', 'not_available') NOT NULL DEFAULT 'not_available',
  details_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_face_match_session (session_id),
  CONSTRAINT fk_face_match_session
    FOREIGN KEY (session_id) REFERENCES driver_verification_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_face_match_selfie
    FOREIGN KEY (selfie_document_id) REFERENCES driver_account_documents(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_face_match_identity_doc
    FOREIGN KEY (id_document_id) REFERENCES driver_account_documents(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS government_api_checks (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  document_id INT NULL,
  provider VARCHAR(80) NOT NULL,
  check_type VARCHAR(100) NOT NULL,
  external_reference VARCHAR(120) NULL,
  status ENUM('passed', 'failed', 'needs_review', 'not_available') NOT NULL DEFAULT 'needs_review',
  confidence DECIMAL(5,2) NOT NULL DEFAULT 0.00,
  response_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_government_checks_session (session_id, status),
  KEY idx_government_checks_document (document_id),
  CONSTRAINT fk_government_checks_session
    FOREIGN KEY (session_id) REFERENCES driver_verification_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_government_checks_document
    FOREIGN KEY (document_id) REFERENCES driver_account_documents(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS verification_audit_logs (
  id INT NOT NULL AUTO_INCREMENT,
  session_id INT NOT NULL,
  admin_id INT NULL,
  actor_type ENUM('system', 'admin', 'service') NOT NULL DEFAULT 'system',
  event_type VARCHAR(80) NOT NULL,
  message VARCHAR(255) NOT NULL,
  metadata_json LONGTEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_verification_audit_session (session_id, created_at),
  KEY idx_verification_audit_admin (admin_id, created_at),
  CONSTRAINT fk_verification_audit_session
    FOREIGN KEY (session_id) REFERENCES driver_verification_sessions(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_verification_audit_admin
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS reports (
  id INT NOT NULL AUTO_INCREMENT,
  reporter_user_id INT UNSIGNED NOT NULL,
  reported_user_id INT UNSIGNED NULL,
  ride_id INT UNSIGNED NULL,
  reason ENUM('safety', 'misconduct', 'fake_profile', 'payment', 'spam', 'other') NOT NULL DEFAULT 'other',
  message TEXT NOT NULL,
  report_status ENUM('open', 'reviewing', 'resolved', 'dismissed') NOT NULL DEFAULT 'open',
  admin_note VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_reports_status (report_status, created_at),
  KEY idx_reports_reporter (reporter_user_id),
  KEY idx_reports_ride (ride_id),
  KEY idx_reports_reported_user (reported_user_id),
  CONSTRAINT fk_reports_reporter
    FOREIGN KEY (reporter_user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_reports_reported_user
    FOREIGN KEY (reported_user_id) REFERENCES users(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_reports_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_accounts (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
  status ENUM('active', 'frozen') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_accounts_user (user_id),
  CONSTRAINT fk_wallet_accounts_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS wallet_transactions (
  id INT NOT NULL AUTO_INCREMENT,
  wallet_id INT NOT NULL,
  user_id INT UNSIGNED NOT NULL,
  ride_id INT UNSIGNED NULL,
  driver_id INT NULL,
  transaction_type ENUM('credit', 'debit', 'hold', 'release', 'fare_due', 'cash_paid', 'adjustment') NOT NULL,
  amount DECIMAL(10,2) NOT NULL,
  description VARCHAR(255) NULL,
  reference_type VARCHAR(40) NOT NULL,
  reference_id INT NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_wallet_reference (wallet_id, transaction_type, reference_type, reference_id),
  KEY idx_wallet_transactions_user (user_id, created_at),
  KEY idx_wallet_transactions_ride (ride_id),
  CONSTRAINT fk_wallet_transactions_wallet
    FOREIGN KEY (wallet_id) REFERENCES wallet_accounts(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_wallet_transactions_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE,
  CONSTRAINT fk_wallet_transactions_ride
    FOREIGN KEY (ride_id) REFERENCES rides(id)
    ON DELETE SET NULL,
  CONSTRAINT fk_wallet_transactions_driver
    FOREIGN KEY (driver_id) REFERENCES driver_accounts(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS audit_logs (
  id INT NOT NULL AUTO_INCREMENT,
  admin_id INT NULL,
  action VARCHAR(80) NOT NULL,
  entity_type VARCHAR(80) NOT NULL,
  entity_id INT NULL,
  message VARCHAR(255) NULL,
  source_ip VARCHAR(64) NULL,
  user_agent VARCHAR(255) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_audit_admin_time (admin_id, created_at),
  KEY idx_audit_entity (entity_type, entity_id),
  KEY idx_audit_source_time (source_ip, created_at),
  CONSTRAINT fk_audit_logs_admin
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_alert_rules (
  id INT NOT NULL AUTO_INCREMENT,
  rule_key VARCHAR(80) NOT NULL,
  label VARCHAR(140) NOT NULL,
  metric_key VARCHAR(120) NOT NULL,
  operator ENUM('greater_than', 'greater_or_equal', 'equal_to') NOT NULL DEFAULT 'greater_than',
  threshold DECIMAL(12,2) NOT NULL DEFAULT 0.00,
  severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'warning',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  cooldown_minutes INT UNSIGNED NOT NULL DEFAULT 15,
  last_triggered_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_alert_rules_key (rule_key),
  KEY idx_admin_alert_rules_enabled (enabled, severity)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS admin_notes (
  id INT NOT NULL AUTO_INCREMENT,
  entity_type ENUM('user', 'driver', 'ride', 'report') NOT NULL,
  entity_id INT NOT NULL,
  admin_id INT NULL,
  note_type ENUM('general', 'risk', 'support', 'compliance') NOT NULL DEFAULT 'general',
  note_text TEXT NOT NULL,
  visibility ENUM('internal') NOT NULL DEFAULT 'internal',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_admin_notes_entity (entity_type, entity_id, created_at),
  KEY idx_admin_notes_admin (admin_id, created_at),
  CONSTRAINT fk_admin_notes_admin
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS feature_flags (
  id INT NOT NULL AUTO_INCREMENT,
  flag_key VARCHAR(80) NOT NULL,
  label VARCHAR(140) NOT NULL,
  description VARCHAR(255) NOT NULL,
  module VARCHAR(60) NOT NULL DEFAULT 'core',
  enabled TINYINT(1) NOT NULL DEFAULT 1,
  maintenance_mode TINYINT(1) NOT NULL DEFAULT 0,
  updated_by INT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_feature_flags_key (flag_key),
  KEY idx_feature_flags_module (module, enabled, maintenance_mode),
  CONSTRAINT fk_feature_flags_admin
    FOREIGN KEY (updated_by) REFERENCES admin_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

INSERT INTO feature_flags (flag_key, label, description, module)
VALUES
  ('rides_marketplace', 'Ride marketplace', 'Rider ride posting, search, and join-request flows.', 'rides'),
  ('driver_panel', 'Driver panel', 'Driver availability, requests, documents, and earnings workflows.', 'drivers'),
  ('ai_verification', 'AI verification', 'AI document analysis, provider checks, and compliance scoring.', 'ai'),
  ('reports_moderation', 'Reports moderation', 'User report intake, triage, decisions, and audit visibility.', 'trust'),
  ('payments_wallet', 'Payments and wallet', 'Fare due tracking, cash-paid records, and wallet ledgers.', 'payments'),
  ('realtime_gateway', 'Realtime gateway', 'Websocket events, polling fallbacks, and live ride status sync.', 'realtime')
ON DUPLICATE KEY UPDATE
  label = VALUES(label),
  description = VALUES(description),
  module = VALUES(module);

CREATE TABLE IF NOT EXISTS repair_kit_runs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  run_uuid CHAR(36) NOT NULL,
  admin_id INT NULL,
  action_key VARCHAR(80) NOT NULL,
  status ENUM('queued', 'running', 'succeeded', 'failed', 'blocked') NOT NULL DEFAULT 'queued',
  severity ENUM('info', 'warning', 'critical') NOT NULL DEFAULT 'info',
  checkpoint_json LONGTEXT NULL,
  result_json LONGTEXT NULL,
  log_ciphertext LONGTEXT NOT NULL,
  log_iv VARCHAR(64) NOT NULL,
  log_tag VARCHAR(64) NOT NULL,
  log_hash CHAR(64) NOT NULL,
  started_at TIMESTAMP NULL DEFAULT NULL,
  finished_at TIMESTAMP NULL DEFAULT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_repair_kit_runs_uuid (run_uuid),
  KEY idx_repair_kit_runs_admin_time (admin_id, created_at),
  KEY idx_repair_kit_runs_status_time (status, created_at),
  KEY idx_repair_kit_runs_action_time (action_key, created_at),
  CONSTRAINT fk_repair_kit_runs_admin
    FOREIGN KEY (admin_id) REFERENCES admin_users(id)
    ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS route_demand_signals (
  id INT NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  route_key VARCHAR(220) NOT NULL,
  origin VARCHAR(200) NOT NULL,
  destination VARCHAR(200) NOT NULL,
  origin_lat DECIMAL(10,7) NULL,
  origin_lng DECIMAL(10,7) NULL,
  destination_lat DECIMAL(10,7) NULL,
  destination_lng DECIMAL(10,7) NULL,
  route_distance_km DECIMAL(8,2) NULL,
  encoded_polyline LONGTEXT NULL,
  travel_date DATE NULL,
  travel_time TIME NULL,
  demand_status ENUM('active', 'matched', 'expired', 'cancelled') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_route_demand_user_status (user_id, demand_status),
  KEY idx_route_demand_route_date (route_key, travel_date),
  KEY idx_route_demand_status_date (demand_status, travel_date),
  CONSTRAINT fk_route_demand_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
