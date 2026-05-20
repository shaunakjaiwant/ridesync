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

function schema_identifier($identifier) {
    if (preg_match('/^[A-Za-z0-9_]+$/', (string) $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe identifier: ' . (string) $identifier);
    }

    return '`' . $identifier . '`';
}

function schema_max_char_length($conn, $table, $column) {
    $tableSql = schema_identifier($table);
    $columnSql = schema_identifier($column);
    $result = mysqli_query($conn, "SELECT MAX(CHAR_LENGTH({$columnSql})) AS max_len FROM {$tableSql}");
    if (!$result) {
        return null;
    }

    $row = mysqli_fetch_assoc($result);
    return isset($row['max_len']) ? (int) $row['max_len'] : 0;
}

function schema_normalize_varchar_column($conn, $table, $column, $length, $suffix) {
    if (!schema_table_exists($conn, $table) || !schema_column_exists($conn, $table, $column)) {
        schema_note('SKIP', "Normalize {$table}.{$column}", 'required table or column missing');
        return;
    }

    $currentType = schema_column_type($conn, $table, $column);
    $desiredType = 'varchar(' . (int) $length . ')';
    if ($currentType === $desiredType) {
        schema_note('SKIP', "Normalize {$table}.{$column}");
        return;
    }

    $maxLength = schema_max_char_length($conn, $table, $column);
    if ($maxLength !== null && $maxLength > (int) $length) {
        schema_note('FAIL', "Normalize {$table}.{$column}", "max data length {$maxLength} exceeds {$length}");
        return;
    }

    $tableSql = schema_identifier($table);
    $columnSql = schema_identifier($column);
    schema_query(
        $conn,
        "ALTER TABLE {$tableSql} MODIFY COLUMN {$columnSql} VARCHAR(" . (int) $length . ") {$suffix}",
        "Normalize {$table}.{$column}"
    );
}

function schema_normalize_rides_seats($conn) {
    if (!schema_table_exists($conn, 'rides') || !schema_column_exists($conn, 'rides', 'seats_available')) {
        schema_note('SKIP', 'Normalize rides.seats_available', 'required table or column missing');
        return;
    }

    $currentType = schema_column_type($conn, 'rides', 'seats_available');
    if (preg_match('/^tinyint(?:\([0-9]+\))? unsigned$/', $currentType) === 1) {
        schema_note('SKIP', 'Normalize rides.seats_available');
        return;
    }

    $rangeResult = mysqli_query($conn, "SELECT MIN(seats_available) AS min_seats, MAX(seats_available) AS max_seats FROM rides");
    $range = $rangeResult ? mysqli_fetch_assoc($rangeResult) : null;
    $minSeats = isset($range['min_seats']) ? (int) $range['min_seats'] : 0;
    $maxSeats = isset($range['max_seats']) ? (int) $range['max_seats'] : 0;
    if ($minSeats < 0 || $maxSeats > 255) {
        schema_note('FAIL', 'Normalize rides.seats_available', "range {$minSeats}-{$maxSeats} cannot fit TINYINT UNSIGNED");
        return;
    }

    schema_query(
        $conn,
        "ALTER TABLE rides MODIFY COLUMN seats_available TINYINT UNSIGNED NOT NULL DEFAULT 1",
        'Normalize rides.seats_available'
    );
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

function schema_create_background_jobs($conn) {
    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS background_jobs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create background_jobs'
    );
}

function schema_create_realtime_events($conn) {
    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS realtime_events (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create realtime_events'
    );
}

function schema_create_admin_alert_rules($conn) {
    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS admin_alert_rules (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create admin_alert_rules'
    );

    if (!schema_table_exists($conn, 'admin_alert_rules')) {
        return;
    }

    $defaults = [
        ['ai_failed_24h', 'AI failures in last 24h', 'workflows.failed_24h', 'greater_than', 0, 'critical', 10],
        ['stale_processing_jobs', 'Stale processing jobs', 'queues.stale_processing', 'greater_than', 0, 'warning', 10],
        ['provider_failures', 'Provider validation failures', 'api_checks.failed_24h', 'greater_than', 2, 'warning', 15],
        ['runtime_errors', 'Runtime errors in logs', 'logs.error_events_24h', 'greater_than', 0, 'warning', 15],
        ['service_degraded', 'Degraded service count', 'summary.services_degraded', 'greater_than', 0, 'warning', 15],
        ['service_down', 'Down service count', 'summary.services_down', 'greater_than', 0, 'critical', 5],
    ];

    foreach ($defaults as $rule) {
        $ruleKey = $rule[0];
        $label = $rule[1];
        $metricKey = $rule[2];
        $operator = $rule[3];
        $threshold = (float) $rule[4];
        $severity = $rule[5];
        $cooldownMinutes = (int) $rule[6];
        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO admin_alert_rules
                (rule_key, label, metric_key, operator, threshold, severity, cooldown_minutes)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            schema_note('FAIL', 'Seed admin alert rule ' . $rule[0], mysqli_error($conn));
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'ssssdsi', $ruleKey, $label, $metricKey, $operator, $threshold, $severity, $cooldownMinutes);
        if (mysqli_stmt_execute($stmt)) {
            schema_note(mysqli_stmt_affected_rows($stmt) > 0 ? 'APPLIED' : 'SKIP', 'Seed admin alert rule ' . $ruleKey);
        } else {
            schema_note('FAIL', 'Seed admin alert rule ' . $ruleKey, mysqli_stmt_error($stmt));
        }
    }
}

function schema_create_admin_notes($conn) {
    if (!schema_table_exists($conn, 'admin_users')) {
        schema_note('SKIP', 'Create admin_notes', 'admin_users table missing');
        return;
    }

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS admin_notes (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create admin_notes'
    );
}

function schema_create_feature_flags($conn) {
    if (!schema_table_exists($conn, 'admin_users')) {
        schema_note('SKIP', 'Create feature_flags', 'admin_users table missing');
        return;
    }

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS feature_flags (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create feature_flags'
    );

    $featureTable = mysqli_query($conn, "SHOW TABLES LIKE 'feature_flags'");
    if (!$featureTable || mysqli_num_rows($featureTable) === 0) {
        schema_note('SKIP', 'Seed feature flags', 'feature_flags table missing');
        return;
    }

    $defaults = [
        ['rides_marketplace', 'Ride marketplace', 'Rider ride posting, search, and join-request flows.', 'rides'],
        ['driver_panel', 'Driver panel', 'Driver availability, requests, documents, and earnings workflows.', 'drivers'],
        ['ai_verification', 'AI verification', 'AI document analysis, provider checks, and compliance scoring.', 'ai'],
        ['reports_moderation', 'Reports moderation', 'User report intake, triage, decisions, and audit visibility.', 'trust'],
        ['payments_wallet', 'Payments and wallet', 'Fare due tracking, cash-paid records, and wallet ledgers.', 'payments'],
        ['realtime_gateway', 'Realtime gateway', 'Websocket events, polling fallbacks, and live ride status sync.', 'realtime'],
    ];

    foreach ($defaults as $flag) {
        $flagKey = $flag[0];
        $label = $flag[1];
        $description = $flag[2];
        $module = $flag[3];
        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO feature_flags (flag_key, label, description, module)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            schema_note('FAIL', 'Seed feature flag ' . $flagKey, mysqli_error($conn));
            continue;
        }
        mysqli_stmt_bind_param($stmt, 'ssss', $flagKey, $label, $description, $module);
        if (mysqli_stmt_execute($stmt)) {
            schema_note(mysqli_stmt_affected_rows($stmt) > 0 ? 'APPLIED' : 'SKIP', 'Seed feature flag ' . $flagKey);
        } else {
            schema_note('FAIL', 'Seed feature flag ' . $flagKey, mysqli_stmt_error($stmt));
        }
    }
}

function schema_create_repair_kit_runs($conn) {
    if (!schema_table_exists($conn, 'admin_users')) {
        schema_note('SKIP', 'Create repair_kit_runs', 'admin_users table missing');
        return;
    }

    schema_query($conn,
        "CREATE TABLE IF NOT EXISTS repair_kit_runs (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        'Create repair_kit_runs'
    );
}

function schema_reject_stale_pending_matches($conn) {
    if (!schema_table_exists($conn, 'matches') || !schema_table_exists($conn, 'rides')) {
        schema_note('SKIP', 'Reject stale pending matches', 'required table missing');
        return;
    }

    if (mysqli_query(
        $conn,
        "UPDATE matches m
         JOIN rides r ON r.id = m.ride_id
         SET m.status = 'rejected'
         WHERE m.status = 'pending'
           AND (r.status <> 'open' OR r.seats_available <= 0)"
    )) {
        $changed = mysqli_affected_rows($conn);
        schema_note($changed > 0 ? 'APPLIED' : 'SKIP', 'Reject stale pending matches', $changed . ' row(s)');
    } else {
        schema_note('FAIL', 'Reject stale pending matches', mysqli_error($conn));
    }
}

schema_add_column($conn, 'ride_routes', 'encoded_polyline', 'LONGTEXT NULL AFTER ride_id');
schema_add_column($conn, 'route_demand_signals', 'encoded_polyline', 'LONGTEXT NULL AFTER route_distance_km');
schema_add_column($conn, 'driver_ride_history', 'source_type', "ENUM('direct_request', 'community_ride') NULL AFTER distance_km");
schema_add_column($conn, 'driver_ride_history', 'source_id', 'INT NULL AFTER source_type');
schema_add_column($conn, 'driver_ride_requests', 'completed_at', 'TIMESTAMP NULL DEFAULT NULL AFTER responded_at');
schema_add_column($conn, 'driver_ride_requests', 'route_distance_km', 'DECIMAL(8,2) NULL AFTER estimated_fare');
schema_add_column($conn, 'driver_ride_requests', 'fare_rate_per_km', "DECIMAL(6,2) NOT NULL DEFAULT 25.60 AFTER route_distance_km");
schema_add_column($conn, 'driver_ride_requests', 'pricing_version', "VARCHAR(40) NOT NULL DEFAULT 'km_rate_v3_fair_split' AFTER fare_rate_per_km");
schema_add_column($conn, 'audit_logs', 'source_ip', 'VARCHAR(64) NULL AFTER message');
schema_add_column($conn, 'audit_logs', 'user_agent', 'VARCHAR(255) NULL AFTER source_ip');

schema_create_wallet_tables($conn);
schema_update_driver_document_types($conn);
schema_create_driver_verification_tables($conn);
schema_create_background_jobs($conn);
schema_create_realtime_events($conn);
schema_create_admin_alert_rules($conn);
schema_create_admin_notes($conn);
schema_create_feature_flags($conn);
schema_create_repair_kit_runs($conn);

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

schema_normalize_varchar_column($conn, 'users', 'email', 190, 'NOT NULL');
schema_normalize_varchar_column($conn, 'driver_accounts', 'email', 190, 'NOT NULL');
schema_normalize_varchar_column($conn, 'admin_users', 'email', 190, 'NOT NULL');
schema_normalize_varchar_column($conn, 'rides', 'origin', 150, 'NOT NULL');
schema_normalize_varchar_column($conn, 'rides', 'destination', 150, 'NOT NULL');
schema_normalize_rides_seats($conn);

schema_add_index($conn, 'driver_account_documents', 'uq_driver_account_documents_type', 'UNIQUE KEY uq_driver_account_documents_type (driver_id, document_type)');
schema_add_index($conn, 'driver_ride_history', 'uq_driver_ride_history_source', 'UNIQUE KEY uq_driver_ride_history_source (driver_id, source_type, source_id)');
schema_add_index($conn, 'rides', 'idx_rides_user_status_time', 'KEY idx_rides_user_status_time (user_id, status, travel_date, travel_time)');
schema_add_index($conn, 'rides', 'idx_rides_search', 'KEY idx_rides_search (status, travel_date, travel_time)');
schema_add_index($conn, 'matches', 'idx_matches_ride_status', 'KEY idx_matches_ride_status (ride_id, status, created_at)');
schema_add_index($conn, 'matches', 'idx_matches_user_status', 'KEY idx_matches_user_status (matched_user_id, status, created_at)');
schema_add_index($conn, 'driver_account_profiles', 'idx_driver_profiles_status', 'KEY idx_driver_profiles_status (verification_status, driver_id)');
schema_add_index($conn, 'driver_account_documents', 'idx_driver_documents_status', 'KEY idx_driver_documents_status (verification_status, document_type, driver_id)');
schema_add_index($conn, 'driver_account_availability', 'idx_driver_availability_status_changed', 'KEY idx_driver_availability_status_changed (status, last_changed_at)');
schema_add_index($conn, 'driver_ride_requests', 'idx_driver_requests_rider_status_time', 'KEY idx_driver_requests_rider_status_time (rider_user_id, request_status, requested_at)');
schema_add_index($conn, 'driver_ride_requests', 'idx_driver_requests_status_requested', 'KEY idx_driver_requests_status_requested (request_status, requested_at)');
schema_add_index($conn, 'notifications', 'idx_notifications_user_created', 'KEY idx_notifications_user_created (user_id, created_at)');
schema_add_index($conn, 'notifications', 'idx_notifications_driver_created', 'KEY idx_notifications_driver_created (driver_id, created_at)');
schema_add_index($conn, 'background_jobs', 'idx_background_jobs_ready', 'KEY idx_background_jobs_ready (queue_name, status, available_at, id)');
schema_add_index($conn, 'background_jobs', 'idx_background_jobs_type_status', 'KEY idx_background_jobs_type_status (job_type, status, created_at)');
schema_add_index($conn, 'background_jobs', 'idx_background_jobs_locked', 'KEY idx_background_jobs_locked (locked_at)');
schema_add_index($conn, 'realtime_events', 'uq_realtime_events_idempotency', 'UNIQUE KEY uq_realtime_events_idempotency (idempotency_key)');
schema_add_index($conn, 'realtime_events', 'idx_realtime_events_audience', 'KEY idx_realtime_events_audience (audience_type, audience_id, id)');
schema_add_index($conn, 'realtime_events', 'idx_realtime_events_aggregate', 'KEY idx_realtime_events_aggregate (aggregate_type, aggregate_id, id)');
schema_add_index($conn, 'realtime_events', 'idx_realtime_events_type_time', 'KEY idx_realtime_events_type_time (event_type, created_at)');
schema_add_index($conn, 'realtime_events', 'idx_realtime_events_expiry', 'KEY idx_realtime_events_expiry (expires_at)');
schema_add_index($conn, 'audit_logs', 'idx_audit_source_time', 'KEY idx_audit_source_time (source_ip, created_at)');
schema_add_index($conn, 'admin_alert_rules', 'uq_admin_alert_rules_key', 'UNIQUE KEY uq_admin_alert_rules_key (rule_key)');
schema_add_index($conn, 'admin_alert_rules', 'idx_admin_alert_rules_enabled', 'KEY idx_admin_alert_rules_enabled (enabled, severity)');
schema_add_index($conn, 'admin_notes', 'idx_admin_notes_entity', 'KEY idx_admin_notes_entity (entity_type, entity_id, created_at)');
schema_add_index($conn, 'admin_notes', 'idx_admin_notes_admin', 'KEY idx_admin_notes_admin (admin_id, created_at)');
schema_add_index($conn, 'feature_flags', 'uq_feature_flags_key', 'UNIQUE KEY uq_feature_flags_key (flag_key)');
schema_add_index($conn, 'feature_flags', 'idx_feature_flags_module', 'KEY idx_feature_flags_module (module, enabled, maintenance_mode)');
schema_add_index($conn, 'repair_kit_runs', 'uq_repair_kit_runs_uuid', 'UNIQUE KEY uq_repair_kit_runs_uuid (run_uuid)');
schema_add_index($conn, 'repair_kit_runs', 'idx_repair_kit_runs_admin_time', 'KEY idx_repair_kit_runs_admin_time (admin_id, created_at)');
schema_add_index($conn, 'repair_kit_runs', 'idx_repair_kit_runs_status_time', 'KEY idx_repair_kit_runs_status_time (status, created_at)');
schema_add_index($conn, 'repair_kit_runs', 'idx_repair_kit_runs_action_time', 'KEY idx_repair_kit_runs_action_time (action_key, created_at)');

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
schema_add_fk($conn, 'admin_notes', 'fk_admin_notes_admin', 'admin_id', 'admin_users', 'SET NULL');
schema_add_fk($conn, 'feature_flags', 'fk_feature_flags_admin', 'updated_by', 'admin_users', 'SET NULL');
schema_add_fk($conn, 'repair_kit_runs', 'fk_repair_kit_runs_admin', 'admin_id', 'admin_users', 'SET NULL');
schema_add_fk($conn, 'route_demand_signals', 'fk_route_demand_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'wallet_accounts', 'fk_wallet_accounts_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_wallet', 'wallet_id', 'wallet_accounts', 'CASCADE');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_user', 'user_id', 'users', 'CASCADE');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_ride', 'ride_id', 'rides', 'SET NULL');
schema_add_fk($conn, 'wallet_transactions', 'fk_wallet_transactions_driver', 'driver_id', 'driver_accounts', 'SET NULL');

schema_reject_stale_pending_matches($conn);

echo PHP_EOL;
echo "Schema upgrade finished: {$applied} applied, {$skipped} skipped, {$failed} failed." . PHP_EOL;
exit($failed > 0 ? 1 : 0);
