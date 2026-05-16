<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';

mysqli_report(MYSQLI_REPORT_OFF);
header('Content-Type: text/plain; charset=utf-8');

$applied = 0;
$skipped = 0;
$failed = 0;

function schema_note($status, $message, $detail = '') {
    global $applied, $skipped, $failed;

    if ($status === 'APPLIED') {
        $applied++;
    } elseif ($status === 'SKIP') {
        $skipped++;
    } elseif ($status === 'FAIL') {
        $failed++;
    }

    echo '[' . $status . '] ' . $message;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;
}

function schema_query($conn, $sql, $label) {
    if (mysqli_query($conn, $sql)) {
        schema_note('APPLIED', $label);
        return true;
    }

    schema_note('FAIL', $label, mysqli_error($conn));
    return false;
}

function schema_table_exists($conn, $table) {
    return ridesync_table_exists($conn, $table);
}

function schema_column_exists($conn, $table, $column) {
    return ridesync_column_exists($conn, $table, $column);
}

function schema_index_exists($conn, $table, $index) {
    $stmt = mysqli_prepare($conn,
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ss", $table, $index);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

function schema_fk_exists($conn, $fkName) {
    $stmt = mysqli_prepare($conn,
        "SELECT 1
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_NAME = ?
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "s", $fkName);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

function schema_column_type($conn, $table, $column) {
    $stmt = mysqli_prepare($conn,
        "SELECT COLUMN_TYPE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND COLUMN_NAME = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ss", $table, $column);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return strtolower($row['COLUMN_TYPE'] ?? '');
}

function schema_id_type($conn, $table) {
    $type = schema_column_type($conn, $table, 'id');
    return strpos($type, 'unsigned') !== false ? 'INT UNSIGNED' : 'INT';
}

function schema_type_is_unsigned($type) {
    return strpos(strtolower((string) $type), 'unsigned') !== false;
}

function schema_normalize_fk_column($conn, $table, $column, $referencedTable, $nullable = true) {
    if (!schema_table_exists($conn, $table) || !schema_column_exists($conn, $table, $column) || !schema_table_exists($conn, $referencedTable)) {
        schema_note('SKIP', "{$table}.{$column} type check", 'required table or column missing');
        return;
    }

    $currentType = schema_column_type($conn, $table, $column);
    $desiredType = schema_id_type($conn, $referencedTable);
    if (schema_type_is_unsigned($currentType) === schema_type_is_unsigned($desiredType)) {
        schema_note('SKIP', "{$table}.{$column} type check");
        return;
    }

    $nullSql = $nullable ? 'NULL' : 'NOT NULL';
    schema_query(
        $conn,
        "ALTER TABLE `{$table}` MODIFY COLUMN `{$column}` {$desiredType} {$nullSql}",
        "Normalize {$table}.{$column} type"
    );
}

function schema_clear_orphans($conn, $childTable, $childColumn, $parentTable, $nullable = true) {
    if (!schema_table_exists($conn, $childTable) || !schema_table_exists($conn, $parentTable) || !schema_column_exists($conn, $childTable, $childColumn)) {
        return;
    }

    $action = $nullable ? 'set NULL' : 'delete';
    $sql = $nullable
        ? "UPDATE `{$childTable}` c
           LEFT JOIN `{$parentTable}` p ON p.id = c.`{$childColumn}`
           SET c.`{$childColumn}` = NULL
           WHERE c.`{$childColumn}` IS NOT NULL AND p.id IS NULL"
        : "DELETE c FROM `{$childTable}` c
           LEFT JOIN `{$parentTable}` p ON p.id = c.`{$childColumn}`
           WHERE c.`{$childColumn}` IS NOT NULL AND p.id IS NULL";

    if (mysqli_query($conn, $sql)) {
        $changed = mysqli_affected_rows($conn);
        schema_note($changed > 0 ? 'APPLIED' : 'SKIP', "Orphan cleanup {$childTable}.{$childColumn}", "{$action}, {$changed} row(s)");
    } else {
        schema_note('FAIL', "Orphan cleanup {$childTable}.{$childColumn}", mysqli_error($conn));
    }
}

function schema_add_column($conn, $table, $column, $definition) {
    if (!schema_table_exists($conn, $table)) {
        schema_note('SKIP', "Add {$table}.{$column}", 'table missing');
        return;
    }

    if (schema_column_exists($conn, $table, $column)) {
        schema_note('SKIP', "Add {$table}.{$column}");
        return;
    }

    schema_query($conn, "ALTER TABLE `{$table}` ADD COLUMN `{$column}` {$definition}", "Add {$table}.{$column}");
}

function schema_add_index($conn, $table, $index, $definition) {
    if (!schema_table_exists($conn, $table)) {
        schema_note('SKIP', "Add {$index}", 'table missing');
        return;
    }

    if (schema_index_exists($conn, $table, $index)) {
        schema_note('SKIP', "Add {$index}");
        return;
    }

    schema_query($conn, "ALTER TABLE `{$table}` ADD {$definition}", "Add {$index}");
}

function schema_add_fk($conn, $table, $fkName, $column, $parentTable, $onDelete) {
    if (schema_fk_exists($conn, $fkName)) {
        schema_note('SKIP', "Add {$fkName}");
        return;
    }

    if (!schema_table_exists($conn, $table) || !schema_table_exists($conn, $parentTable)) {
        schema_note('SKIP', "Add {$fkName}", 'required table missing');
        return;
    }

    schema_clear_orphans($conn, $table, $column, $parentTable, stripos($onDelete, 'SET NULL') !== false);

    schema_query(
        $conn,
        "ALTER TABLE `{$table}`
         ADD CONSTRAINT `{$fkName}`
         FOREIGN KEY (`{$column}`) REFERENCES `{$parentTable}` (`id`)
         ON DELETE {$onDelete}",
        "Add {$fkName}"
    );
}

function schema_create_wallet_tables($conn) {
    if (!schema_table_exists($conn, 'users')) {
        schema_note('SKIP', 'Create wallet tables', 'users table missing');
        return;
    }

    $userIdType = schema_id_type($conn, 'users');
    $rideIdType = schema_table_exists($conn, 'rides') ? schema_id_type($conn, 'rides') : 'INT';
    $driverIdType = schema_table_exists($conn, 'driver_accounts') ? schema_id_type($conn, 'driver_accounts') : 'INT';

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS wallet_accounts (
            id INT NOT NULL AUTO_INCREMENT,
            user_id {$userIdType} NOT NULL,
            balance DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            status ENUM('active', 'frozen') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_wallet_accounts_user (user_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create wallet_accounts'
    );

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS wallet_transactions (
            id INT NOT NULL AUTO_INCREMENT,
            wallet_id INT NOT NULL,
            user_id {$userIdType} NOT NULL,
            ride_id {$rideIdType} NULL,
            driver_id {$driverIdType} NULL,
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
            KEY idx_wallet_transactions_driver (driver_id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create wallet_transactions'
    );
}

function schema_update_driver_document_types($conn) {
    if (!schema_table_exists($conn, 'driver_account_documents') || !schema_column_exists($conn, 'driver_account_documents', 'document_type')) {
        schema_note('SKIP', 'Update driver document types', 'driver_account_documents missing');
        return;
    }

    $currentType = schema_column_type($conn, 'driver_account_documents', 'document_type');
    if (strpos($currentType, 'aadhaar') !== false && strpos($currentType, 'vehicle_image') !== false) {
        schema_note('SKIP', 'Update driver document types');
        return;
    }

    schema_query(
        $conn,
        "ALTER TABLE driver_account_documents
         MODIFY COLUMN document_type ENUM('license', 'aadhaar', 'pan', 'id_proof', 'vehicle_rc', 'insurance', 'profile_photo', 'selfie', 'vehicle_image', 'other') NOT NULL DEFAULT 'license'",
        'Update driver document types'
    );
}

function schema_create_driver_verification_tables($conn) {
    if (!schema_table_exists($conn, 'driver_accounts') || !schema_table_exists($conn, 'admin_users')) {
        schema_note('SKIP', 'Create driver verification intelligence tables', 'driver_accounts or admin_users missing');
        return;
    }

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS driver_verification_sessions (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create driver_verification_sessions'
    );

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS document_analysis_results (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create document_analysis_results'
    );

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS fraud_flags (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create fraud_flags'
    );

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS face_match_results (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create face_match_results'
    );

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS government_api_checks (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create government_api_checks'
    );

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS verification_audit_logs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create verification_audit_logs'
    );
}

schema_add_column($conn, 'ride_routes', 'encoded_polyline', 'LONGTEXT NULL AFTER ride_id');
schema_add_column($conn, 'route_demand_signals', 'encoded_polyline', 'LONGTEXT NULL AFTER route_distance_km');
schema_add_column($conn, 'driver_ride_history', 'source_type', "ENUM('direct_request', 'community_ride') NULL AFTER distance_km");
schema_add_column($conn, 'driver_ride_history', 'source_id', 'INT NULL AFTER source_type');
schema_add_column($conn, 'driver_ride_requests', 'completed_at', 'TIMESTAMP NULL DEFAULT NULL AFTER responded_at');
schema_add_column($conn, 'driver_ride_requests', 'route_distance_km', 'DECIMAL(8,2) NULL AFTER estimated_fare');
schema_add_column($conn, 'driver_ride_requests', 'fare_rate_per_km', "DECIMAL(6,2) NOT NULL DEFAULT 25.60 AFTER route_distance_km");
schema_add_column($conn, 'driver_ride_requests', 'pricing_version', "VARCHAR(40) NOT NULL DEFAULT 'km_rate_v3_fair_split' AFTER fare_rate_per_km");

schema_create_wallet_tables($conn);
schema_update_driver_document_types($conn);
schema_create_driver_verification_tables($conn);

schema_normalize_fk_column($conn, 'driver_ride_requests', 'rider_user_id', 'users', true);
schema_normalize_fk_column($conn, 'notifications', 'user_id', 'users', true);
schema_normalize_fk_column($conn, 'reports', 'reporter_user_id', 'users', false);
schema_normalize_fk_column($conn, 'reports', 'reported_user_id', 'users', true);
schema_normalize_fk_column($conn, 'reports', 'ride_id', 'rides', true);
schema_normalize_fk_column($conn, 'route_demand_signals', 'user_id', 'users', false);
schema_normalize_fk_column($conn, 'ride_tracking', 'ride_id', 'rides', false);
schema_normalize_fk_column($conn, 'user_ratings', 'reviewer_user_id', 'users', false);
schema_normalize_fk_column($conn, 'user_ratings', 'reviewed_user_id', 'users', false);
schema_normalize_fk_column($conn, 'wallet_accounts', 'user_id', 'users', false);
schema_normalize_fk_column($conn, 'wallet_transactions', 'user_id', 'users', false);
schema_normalize_fk_column($conn, 'wallet_transactions', 'ride_id', 'rides', true);

schema_add_index($conn, 'driver_account_documents', 'uq_driver_account_documents_type', 'UNIQUE KEY uq_driver_account_documents_type (driver_id, document_type)');
schema_add_index($conn, 'driver_ride_history', 'uq_driver_ride_history_source', 'UNIQUE KEY uq_driver_ride_history_source (driver_id, source_type, source_id)');
schema_add_index($conn, 'rides', 'idx_rides_user_status_time', 'KEY idx_rides_user_status_time (user_id, status, travel_date, travel_time)');
schema_add_index($conn, 'matches', 'idx_matches_ride_status', 'KEY idx_matches_ride_status (ride_id, status, created_at)');
schema_add_index($conn, 'matches', 'idx_matches_user_status', 'KEY idx_matches_user_status (matched_user_id, status, created_at)');
schema_add_index($conn, 'driver_account_profiles', 'idx_driver_profiles_status', 'KEY idx_driver_profiles_status (verification_status, driver_id)');
schema_add_index($conn, 'driver_account_documents', 'idx_driver_documents_status', 'KEY idx_driver_documents_status (verification_status, document_type, driver_id)');
schema_add_index($conn, 'driver_account_availability', 'idx_driver_availability_status_changed', 'KEY idx_driver_availability_status_changed (status, last_changed_at)');
schema_add_index($conn, 'driver_ride_requests', 'idx_driver_requests_rider_status_time', 'KEY idx_driver_requests_rider_status_time (rider_user_id, request_status, requested_at)');
schema_add_index($conn, 'driver_ride_requests', 'idx_driver_requests_status_requested', 'KEY idx_driver_requests_status_requested (request_status, requested_at)');
schema_add_index($conn, 'notifications', 'idx_notifications_user_created', 'KEY idx_notifications_user_created (user_id, created_at)');
schema_add_index($conn, 'notifications', 'idx_notifications_driver_created', 'KEY idx_notifications_driver_created (driver_id, created_at)');

schema_add_fk($conn, 'driver_account_profiles', 'fk_driver_account_profiles_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'driver_account_vehicles', 'fk_driver_account_vehicles_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'driver_account_documents', 'fk_driver_account_documents_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'driver_account_availability', 'fk_driver_account_availability_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'driver_ride_requests', 'fk_driver_ride_requests_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'driver_ride_requests', 'fk_driver_ride_requests_rider', 'rider_user_id', 'users', 'SET NULL');
schema_add_fk($conn, 'driver_ride_history', 'fk_driver_ride_history_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'ride_live_status', 'fk_ride_live_status_driver', 'driver_id', 'driver_accounts', 'SET NULL');
schema_add_fk($conn, 'notifications', 'fk_notifications_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'notifications', 'fk_notifications_driver', 'driver_id', 'driver_accounts', 'CASCADE');
schema_add_fk($conn, 'user_ratings', 'fk_user_ratings_reviewer', 'reviewer_user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'user_ratings', 'fk_user_ratings_reviewed', 'reviewed_user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'ride_tracking', 'fk_ride_tracking_ride', 'ride_id', 'rides', 'CASCADE');
schema_add_fk($conn, 'ride_tracking', 'fk_ride_tracking_driver', 'driver_id', 'driver_accounts', 'SET NULL');
schema_add_fk($conn, 'reports', 'fk_reports_reporter', 'reporter_user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'reports', 'fk_reports_reported_user', 'reported_user_id', 'users', 'SET NULL');
schema_add_fk($conn, 'reports', 'fk_reports_ride', 'ride_id', 'rides', 'SET NULL');
schema_add_fk($conn, 'audit_logs', 'fk_audit_logs_admin', 'admin_id', 'admin_users', 'SET NULL');
schema_add_fk($conn, 'route_demand_signals', 'fk_route_demand_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'wallet_accounts', 'fk_wallet_accounts_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_wallet', 'wallet_id', 'wallet_accounts', 'CASCADE');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_ride', 'ride_id', 'rides', 'SET NULL');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_driver', 'driver_id', 'driver_accounts', 'SET NULL');

echo PHP_EOL;
echo "Schema upgrade finished: {$applied} applied, {$skipped} skipped, {$failed} failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
