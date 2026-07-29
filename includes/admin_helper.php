<?php
require_once __DIR__ . '/services/NotificationService.php';

function ridesync_admin_schema_ready($conn) {
    if (!$conn instanceof mysqli) {
        return false;
    }

    static $ready = null;

    if ($ready !== null) {
        return $ready;
    }

    $required = ['admin_users', 'reports', 'audit_logs'];
    $ready = true;

    foreach ($required as $table) {
        $safeTable = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
        if (!$result || mysqli_num_rows($result) === 0) {
            $ready = false;
            break;
        }
    }

    return $ready;
}

function ridesync_require_admin_login() {
    if (!isset($_SESSION['admin_id'])) {
        header("Location: /ridesync/pages/admin_login.php");
        exit();
    }
}

function ridesync_admin_count($conn) {
    if (!ridesync_admin_schema_ready($conn)) {
        return 0;
    }

    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM admin_users");
    if (!$result) {
        return 0;
    }

    return (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

function ridesync_fetch_admin($conn, $adminId) {
    if (!ridesync_admin_schema_ready($conn)) {
        return null;
    }

    $stmt = mysqli_prepare($conn, "SELECT id, name, email, role, status FROM admin_users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $adminId);
    mysqli_stmt_execute($stmt);

    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function ridesync_admin_sync_session($admin) {
    if (!is_array($admin)) {
        return;
    }

    $_SESSION['admin_id'] = (int) ($admin['id'] ?? 0);
    $_SESSION['admin_name'] = (string) ($admin['name'] ?? ($_SESSION['admin_name'] ?? 'Admin'));
    $_SESSION['admin_role'] = (string) ($admin['role'] ?? 'moderator');
}

function ridesync_admin_can($admin, $capability) {
    $role = is_array($admin) ? (string) ($admin['role'] ?? '') : (string) $admin;
    if ($role === 'super_admin') {
        return true;
    }

    $moderatorCapabilities = [
        'review_students',
        'review_drivers',
        'run_ai_verification',
        'review_reports',
        'manage_admin_notes',
    ];

    return $role === 'moderator' && in_array($capability, $moderatorCapabilities, true);
}

function ridesync_admin_audit_has_request_context($conn) {
    static $hasColumns = null;

    if ($hasColumns !== null) {
        return $hasColumns;
    }

    if (!$conn instanceof mysqli) {
        $hasColumns = false;
        return $hasColumns;
    }

    $ipColumn = mysqli_query($conn, "SHOW COLUMNS FROM audit_logs LIKE 'source_ip'");
    $agentColumn = mysqli_query($conn, "SHOW COLUMNS FROM audit_logs LIKE 'user_agent'");
    $hasColumns = $ipColumn && mysqli_num_rows($ipColumn) > 0 && $agentColumn && mysqli_num_rows($agentColumn) > 0;

    return $hasColumns;
}

function ridesync_admin_action_capability($action) {
    $map = [
        'user_verification_decision' => 'review_students',
        'driver_profile_decision' => 'review_drivers',
        'driver_full_approval' => 'review_drivers',
        'driver_ai_verification_start' => 'run_ai_verification',
        'driver_ai_verification_decision' => 'review_drivers',
        'driver_document_decision' => 'review_drivers',
        'user_account_status' => 'review_students',
        'driver_account_status' => 'manage_driver_accounts',
        'admin_force_cancel_ride' => 'review_reports',
        'admin_services_release_timeouts' => 'run_ai_verification',
        'admin_services_retry_failed_verifications' => 'run_ai_verification',
        'admin_repair_kit_execute' => 'repair_platform',
        'admin_alert_rule_toggle' => 'manage_alert_rules',
        'admin_feature_flag_update' => 'manage_feature_flags',
        'admin_note_create' => 'manage_admin_notes',
        'admin_bulk_operation' => 'run_bulk_operations',
        'admin_remove_account' => 'remove_accounts',
        'report_decision' => 'review_reports',
    ];

    return $map[$action] ?? null;
}

function ridesync_admin_log($conn, $adminId, $action, $entityType, $entityId = null, $message = null) {
    if (!ridesync_admin_schema_ready($conn)) {
        return;
    }

    $action = substr(trim((string) $action), 0, 80);
    $entityType = substr(trim((string) $entityType), 0, 80);
    $entityId = $entityId !== null ? (int) $entityId : null;
    $message = $message !== null ? substr(trim((string) $message), 0, 255) : null;

    if ($action === '' || $entityType === '') {
        return;
    }

    if (ridesync_admin_audit_has_request_context($conn)) {
        $sourceIp = function_exists('ridesync_client_ip') ? ridesync_client_ip() : substr((string) ($_SERVER['REMOTE_ADDR'] ?? ''), 0, 64);
        $userAgent = function_exists('ridesync_user_agent') ? ridesync_user_agent() : substr(trim((string) ($_SERVER['HTTP_USER_AGENT'] ?? '')), 0, 255);
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, message, source_ip, user_agent)
             VALUES (?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($stmt, "ississs", $adminId, $action, $entityType, $entityId, $message, $sourceIp, $userAgent);
        mysqli_stmt_execute($stmt);
        return;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO audit_logs (admin_id, action, entity_type, entity_id, message)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "issis", $adminId, $action, $entityType, $entityId, $message);
    mysqli_stmt_execute($stmt);
}

function ridesync_admin_notify($conn, $userId, $driverId, $title, $message) {
    return RideSyncNotificationService::create($conn, $userId, $driverId, $title, $message);
}

function ridesync_admin_status_label($status) {
    return ucwords(str_replace('_', ' ', (string) $status));
}

function ridesync_admin_badge_class($status) {
    if (in_array($status, ['verified', 'active', 'resolved'], true)) {
        return 'accepted';
    }

    if (in_array($status, ['rejected', 'suspended', 'dismissed'], true)) {
        return 'rejected';
    }

    return 'pending';
}
?>
