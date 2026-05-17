<?php
require_once __DIR__ . '/matching_helper.php';
require_once __DIR__ . '/driver_document_helper.php';

function ridesync_admin_remove_confirmation_phrase($accountType, $accountId) {
    $accountType = strtolower(trim((string) $accountType));
    $label = $accountType === 'driver' ? 'DRIVER' : 'RIDER';
    return 'REMOVE ' . $label . ' #' . (int) $accountId;
}

function ridesync_admin_fetch_removable_account($conn, $accountType, $accountId) {
    $accountType = strtolower(trim((string) $accountType));
    $accountId = (int) $accountId;
    if (!$conn instanceof mysqli || $accountId <= 0) {
        return null;
    }

    if ($accountType === 'rider') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, name, email, NULL AS phone, profile_photo, created_at
             FROM users
             WHERE id = ?
             LIMIT 1"
        );
    } elseif ($accountType === 'driver') {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, name, email, phone, NULL AS profile_photo, created_at
             FROM driver_accounts
             WHERE id = ?
             LIMIT 1"
        );
    } else {
        return null;
    }

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, 'i', $accountId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$row) {
        return null;
    }

    $row['account_type'] = $accountType;
    $row['account_id'] = $accountId;
    return $row;
}

function ridesync_admin_remove_delete_count($conn, $table, $whereSql, $types, array $params) {
    if (!ridesync_table_exists($conn, $table)) {
        return 0;
    }

    $stmt = mysqli_prepare($conn, "DELETE FROM `{$table}` WHERE {$whereSql}");
    if (!$stmt) {
        throw new RuntimeException("Could not prepare cleanup for {$table}.");
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException("Could not clean {$table}.");
    }

    return max(0, mysqli_stmt_affected_rows($stmt));
}

function ridesync_admin_remove_update_count($conn, $table, $sql, $types, array $params) {
    if (!ridesync_table_exists($conn, $table)) {
        return 0;
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        throw new RuntimeException("Could not prepare update for {$table}.");
    }

    if ($types !== '') {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }

    if (!mysqli_stmt_execute($stmt)) {
        throw new RuntimeException("Could not update {$table}.");
    }

    return max(0, mysqli_stmt_affected_rows($stmt));
}

function ridesync_admin_remove_json_cleanup($conn, $accountType, $accountId) {
    if (!ridesync_table_exists($conn, 'background_jobs')) {
        return 0;
    }

    $accountId = (int) $accountId;
    $fields = $accountType === 'driver'
        ? ['driver_id', 'assigned_driver_id']
        : ['user_id', 'rider_user_id', 'matched_user_id', 'reporter_user_id', 'reported_user_id'];
    $fieldPattern = implode('|', array_map(static fn($field) => preg_quote($field, '/'), $fields));
    $regexp = '"(' . $fieldPattern . ')"[[:space:]]*:[[:space:]]*' . $accountId . '([^0-9]|$)';

    return ridesync_admin_remove_delete_count($conn, 'background_jobs', 'payload_json REGEXP ?', 's', [$regexp]);
}

function ridesync_admin_remove_optional_references($conn, $accountType, $accountId) {
    $accountId = (int) $accountId;
    $definitions = $accountType === 'driver'
        ? [
            'bookings' => ['driver_id'],
            'payments' => ['driver_id'],
            'driver_payments' => ['driver_id'],
            'chat_messages' => ['driver_id', 'sender_driver_id', 'receiver_driver_id'],
            'messages' => ['driver_id', 'sender_driver_id', 'receiver_driver_id'],
            'driver_sessions' => ['driver_id'],
            'auth_sessions' => ['driver_id'],
            'personal_access_tokens' => ['driver_id'],
            'uploaded_documents' => ['driver_id'],
        ]
        : [
            'bookings' => ['user_id', 'rider_user_id'],
            'saved_locations' => ['user_id'],
            'user_saved_locations' => ['user_id'],
            'payments' => ['user_id', 'rider_user_id'],
            'payment_methods' => ['user_id'],
            'chat_messages' => ['user_id', 'sender_user_id', 'receiver_user_id', 'rider_user_id'],
            'messages' => ['user_id', 'sender_user_id', 'receiver_user_id', 'rider_user_id'],
            'user_sessions' => ['user_id'],
            'auth_sessions' => ['user_id'],
            'personal_access_tokens' => ['user_id'],
            'otp_verifications' => ['user_id'],
            'password_resets' => ['user_id'],
        ];

    $deleted = [];
    foreach ($definitions as $table => $columns) {
        if (!ridesync_table_exists($conn, $table)) {
            continue;
        }

        $existingColumns = array_values(array_filter($columns, static function ($column) use ($conn, $table) {
            return ridesync_column_exists($conn, $table, $column);
        }));
        if (count($existingColumns) === 0) {
            continue;
        }

        $where = implode(' OR ', array_map(static fn($column) => "`{$column}` = ?", $existingColumns));
        $deleted[$table] = ridesync_admin_remove_delete_count(
            $conn,
            $table,
            $where,
            str_repeat('i', count($existingColumns)),
            array_fill(0, count($existingColumns), $accountId)
        );
    }

    return $deleted;
}

function ridesync_admin_remove_realtime_events($conn, $accountType, $accountId, array $aggregateIds = []) {
    if (!ridesync_table_exists($conn, 'realtime_events')) {
        return 0;
    }

    $accountId = (int) $accountId;
    $deleted = ridesync_admin_remove_delete_count(
        $conn,
        'realtime_events',
        'audience_type = ? AND audience_id = ?',
        'si',
        [$accountType === 'driver' ? 'driver' : 'user', $accountId]
    );

    $aggregateIds = array_values(array_unique(array_filter(array_map('intval', $aggregateIds), static fn($id) => $id > 0)));
    if ($accountType === 'driver' && count($aggregateIds) > 0) {
        $placeholders = implode(',', array_fill(0, count($aggregateIds), '?'));
        $types = 's' . str_repeat('i', count($aggregateIds));
        $params = array_merge(['driver_verification'], $aggregateIds);
        $deleted += ridesync_admin_remove_delete_count(
            $conn,
            'realtime_events',
            "aggregate_type = ? AND aggregate_id IN ({$placeholders})",
            $types,
            $params
        );
    }

    return $deleted;
}

function ridesync_admin_remove_collect_driver_document_references($conn, $driverId) {
    if (!ridesync_table_exists($conn, 'driver_account_documents')) {
        return [];
    }

    $stmt = mysqli_prepare($conn, "SELECT document_reference FROM driver_account_documents WHERE driver_id = ?");
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $driverId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $references = [];
    while ($row = $result ? mysqli_fetch_assoc($result) : null) {
        $reference = trim((string) ($row['document_reference'] ?? ''));
        if ($reference !== '' && ridesync_driver_document_reference_is_file($reference)) {
            $references[] = $reference;
        }
    }

    return array_values(array_unique($references));
}

function ridesync_admin_remove_collect_driver_session_ids($conn, $driverId) {
    if (!ridesync_table_exists($conn, 'driver_verification_sessions')) {
        return [];
    }

    $stmt = mysqli_prepare($conn, "SELECT id FROM driver_verification_sessions WHERE driver_id = ?");
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, 'i', $driverId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $ids = [];
    while ($row = $result ? mysqli_fetch_assoc($result) : null) {
        $ids[] = (int) ($row['id'] ?? 0);
    }

    return array_values(array_filter($ids, static fn($id) => $id > 0));
}

function ridesync_admin_remove_collect_rider_files($account) {
    $profilePhoto = trim((string) ($account['profile_photo'] ?? ''));
    if ($profilePhoto === '' || !str_starts_with($profilePhoto, 'uploads/profile_photos/')) {
        return [];
    }

    $uploadRoot = realpath(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_photos');
    $path = realpath(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $profilePhoto));
    if (!$uploadRoot || !$path || !str_starts_with($path, $uploadRoot) || !is_file($path)) {
        return [];
    }

    return [$path];
}

function ridesync_admin_remove_delete_files(array $fileReferences) {
    $summary = ['deleted' => 0, 'failed' => 0];
    foreach (array_values(array_unique(array_filter($fileReferences))) as $reference) {
        if (str_starts_with((string) $reference, 'secure://driver_documents/')
            || str_starts_with((string) $reference, 'uploads/driver_documents/')) {
            if (ridesync_driver_document_delete_reference($reference)) {
                $summary['deleted']++;
            } else {
                $summary['failed']++;
            }
            continue;
        }

        $path = realpath((string) $reference);
        $profileRoot = realpath(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . 'uploads' . DIRECTORY_SEPARATOR . 'profile_photos');
        if ($path && $profileRoot && str_starts_with($path, $profileRoot) && is_file($path) && @unlink($path)) {
            $summary['deleted']++;
        } else {
            $summary['failed']++;
        }
    }

    return $summary;
}

function ridesync_admin_remove_purge_sessions($accountType, $accountId) {
    $savePath = session_save_path();
    if (strpos($savePath, ';') !== false) {
        $parts = explode(';', $savePath);
        $savePath = end($parts);
    }
    $savePath = trim((string) $savePath);
    if ($savePath === '') {
        $savePath = sys_get_temp_dir();
    }

    $sessionDir = realpath($savePath);
    if (!$sessionDir || !is_dir($sessionDir)) {
        return 0;
    }

    $key = $accountType === 'driver' ? 'driver_id' : 'user_id';
    $accountId = (int) $accountId;
    $markers = [
        $key . '|i:' . $accountId . ';',
        '"' . $key . '";i:' . $accountId . ';',
    ];

    $deleted = 0;
    foreach (glob($sessionDir . DIRECTORY_SEPARATOR . 'sess_*') ?: [] as $file) {
        if (!is_file($file)) {
            continue;
        }
        $contents = @file_get_contents($file);
        if (!is_string($contents)) {
            continue;
        }
        foreach ($markers as $marker) {
            if (strpos($contents, $marker) !== false && @unlink($file)) {
                $deleted++;
                break;
            }
        }
    }

    return $deleted;
}

function ridesync_admin_remove_account($conn, $accountType, $accountId) {
    $accountType = strtolower(trim((string) $accountType));
    $accountId = (int) $accountId;
    if (!in_array($accountType, ['rider', 'driver'], true) || $accountId <= 0) {
        throw new InvalidArgumentException('Invalid account removal request.');
    }

    $account = ridesync_admin_fetch_removable_account($conn, $accountType, $accountId);
    if (!$account) {
        throw new RuntimeException('Account not found.');
    }

    $fileReferences = [];
    $driverSessionIds = [];
    if ($accountType === 'driver') {
        $fileReferences = ridesync_admin_remove_collect_driver_document_references($conn, $accountId);
        $driverSessionIds = ridesync_admin_remove_collect_driver_session_ids($conn, $accountId);
    } else {
        $fileReferences = ridesync_admin_remove_collect_rider_files($account);
    }

    $summary = [
        'account' => $account,
        'deleted_rows' => [],
        'file_references' => $fileReferences,
        'files' => ['deleted' => 0, 'failed' => 0],
        'sessions_purged' => 0,
    ];

    if ($accountType === 'rider') {
        $summary['deleted_rows']['driver_ride_requests'] = ridesync_admin_remove_delete_count($conn, 'driver_ride_requests', 'rider_user_id = ?', 'i', [$accountId]);
        $summary['deleted_rows']['reports_about_user'] = ridesync_admin_remove_delete_count($conn, 'reports', 'reported_user_id = ?', 'i', [$accountId]);
        $summary['deleted_rows']['realtime_events'] = ridesync_admin_remove_realtime_events($conn, 'rider', $accountId);
        $summary['deleted_rows']['background_jobs'] = ridesync_admin_remove_json_cleanup($conn, 'rider', $accountId);
        $summary['deleted_rows'] += ridesync_admin_remove_optional_references($conn, 'rider', $accountId);
        $summary['deleted_rows']['users'] = ridesync_admin_remove_delete_count($conn, 'users', 'id = ?', 'i', [$accountId]);
    } else {
        $summary['deleted_rows']['ride_tracking'] = ridesync_admin_remove_delete_count($conn, 'ride_tracking', 'driver_id = ?', 'i', [$accountId]);
        $summary['deleted_rows']['wallet_transactions_unlinked'] = ridesync_admin_remove_update_count(
            $conn,
            'wallet_transactions',
            'UPDATE wallet_transactions SET driver_id = NULL WHERE driver_id = ?',
            'i',
            [$accountId]
        );
        $summary['deleted_rows']['ride_live_status'] = ridesync_admin_remove_update_count(
            $conn,
            'ride_live_status',
            "UPDATE ride_live_status
             SET driver_id = NULL,
                 live_status = CASE WHEN live_status IN ('driver_assigned', 'arriving', 'active') THEN 'searching' ELSE live_status END,
                 note = 'Assigned driver was removed by admin cleanup.'
             WHERE driver_id = ?",
            'i',
            [$accountId]
        );
        $summary['deleted_rows']['realtime_events'] = ridesync_admin_remove_realtime_events($conn, 'driver', $accountId, $driverSessionIds);
        $summary['deleted_rows']['background_jobs'] = ridesync_admin_remove_json_cleanup($conn, 'driver', $accountId);
        $summary['deleted_rows'] += ridesync_admin_remove_optional_references($conn, 'driver', $accountId);
        $summary['deleted_rows']['driver_accounts'] = ridesync_admin_remove_delete_count($conn, 'driver_accounts', 'id = ?', 'i', [$accountId]);
    }

    return $summary;
}

function ridesync_admin_remove_finalize_cleanup(array $summary, $accountType, $accountId) {
    $summary['files'] = ridesync_admin_remove_delete_files($summary['file_references'] ?? []);
    $summary['sessions_purged'] = ridesync_admin_remove_purge_sessions($accountType, $accountId);
    unset($summary['file_references']);

    return $summary;
}
?>
