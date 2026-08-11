-- RideSync Production Database Schema
-- Compatible with MySQL 8.0+ / MariaDB 10.5+

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

CREATE TABLE IF NOT EXISTS admin_users (
  id INT NOT NULL AUTO_INCREMENT,
  username VARCHAR(80) NOT NULL,
  email VARCHAR(190) NOT NULL,
  password VARCHAR(255) NOT NULL,
  role ENUM('admin', 'super_admin') NOT NULL DEFAULT 'admin',
  status ENUM('active', 'suspended') NOT NULL DEFAULT 'active',
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  UNIQUE KEY uq_admin_users_username (username),
  UNIQUE KEY uq_admin_users_email (email)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS sos_alerts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  ride_id INT UNSIGNED NULL,
  actor_role ENUM('user', 'driver', 'admin') NOT NULL,
  actor_id INT NOT NULL,
  status ENUM('active', 'resolved', 'dismissed') NOT NULL DEFAULT 'active',
  latitude DECIMAL(10, 7) NULL,
  longitude DECIMAL(10, 7) NULL,
  note TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  resolved_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  KEY idx_sos_alerts_status (status),
  KEY idx_sos_alerts_created (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS user_emergency_contacts (
  id INT UNSIGNED NOT NULL AUTO_INCREMENT,
  user_id INT UNSIGNED NOT NULL,
  contact_name VARCHAR(100) NOT NULL,
  contact_phone VARCHAR(20) NOT NULL,
  relation VARCHAR(50) NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_user_emergency_contacts_user (user_id),
  CONSTRAINT fk_user_emergency_contacts_user
    FOREIGN KEY (user_id) REFERENCES users(id)
    ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS realtime_events (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  idempotency_key VARCHAR(128) NULL,
  event_type VARCHAR(80) NOT NULL,
  audience_type ENUM('user', 'driver', 'admin') NOT NULL,
  audience_id INT UNSIGNED NULL,
  aggregate_type VARCHAR(80) NULL,
  aggregate_id INT UNSIGNED NULL,
  payload_json JSON NOT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at TIMESTAMP NULL DEFAULT NULL,
  PRIMARY KEY (id),
  UNIQUE KEY uq_realtime_events_idempotency (idempotency_key),
  KEY idx_realtime_events_audience (audience_type, audience_id, id),
  KEY idx_realtime_events_expires (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS background_jobs (
  id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
  queue VARCHAR(80) NOT NULL DEFAULT 'default',
  payload_json JSON NOT NULL,
  status ENUM('queued', 'processing', 'completed', 'failed') NOT NULL DEFAULT 'queued',
  attempts TINYINT UNSIGNED NOT NULL DEFAULT 0,
  error_message TEXT NULL,
  created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  PRIMARY KEY (id),
  KEY idx_background_jobs_queue_status (queue, status, id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE IF NOT EXISTS schema_migrations (
  version VARCHAR(255) NOT NULL,
  executed_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (version)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;
