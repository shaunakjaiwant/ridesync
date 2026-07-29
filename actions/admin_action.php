<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/redirect_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';
require_once __DIR__ . '/../includes/admin_remove_helper.php';
require_once __DIR__ . '/../includes/admin_operations_helper.php';
require_once __DIR__ . '/../includes/services/ServiceObservabilityService.php';
require_once __DIR__ . '/../includes/services/RepairKitService.php';

ridesync_require_admin_login();

function ridesync_admin_redirect() {
    ridesync_redirect_back('/ridesync/pages/admin_dashboard.php');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ridesync_admin_redirect();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['admin_error'] = "Invalid request. Please try again.";
    ridesync_admin_redirect();
}

if (!ridesync_admin_schema_ready($conn)) {
    $_SESSION['admin_error'] = "Admin database tables are missing.";
    ridesync_admin_redirect();
}

$adminId = (int) $_SESSION['admin_id'];
$action = $_POST['action_type'] ?? '';
$admin = ridesync_fetch_admin($conn, $adminId);
if (!$admin || ($admin['status'] ?? '') !== 'active') {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "This admin account cannot perform actions right now.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}
ridesync_admin_sync_session($admin);

$requiredCapability = ridesync_admin_action_capability($action);
if ($requiredCapability !== null && !ridesync_admin_can($admin, $requiredCapability)) {
    ridesync_admin_log($conn, $adminId, 'admin_action_denied', 'admin_user', $adminId, $action);
    $_SESSION['admin_error'] = "You do not have permission to perform that admin action.";
    ridesync_admin_redirect();
}

if ($action === 'admin_remove_account') {
    $accountType = strtolower(trim((string) ($_POST['account_type'] ?? '')));
    $accountId = (int) ($_POST['account_id'] ?? 0);
    $confirmationText = trim((string) ($_POST['confirmation_text'] ?? ''));
    $expectedConfirmation = ridesync_admin_remove_confirmation_phrase($accountType, $accountId);

    if (!in_array($accountType, ['rider', 'driver'], true) || $accountId <= 0) {
        $_SESSION['admin_error'] = "Invalid account removal request.";
        ridesync_admin_redirect();
    }

    if (!hash_equals($expectedConfirmation, $confirmationText)) {
        ridesync_admin_log($conn, $adminId, 'admin_remove_confirmation_failed', $accountType, $accountId, 'Confirmation phrase mismatch.');
        $_SESSION['admin_error'] = "Removal cancelled. The confirmation phrase did not match.";
        ridesync_admin_redirect();
    }

    $account = ridesync_admin_fetch_removable_account($conn, $accountType, $accountId);
    if (!$account) {
        $_SESSION['admin_error'] = ucfirst($accountType) . " account not found.";
        ridesync_admin_redirect();
    }

    mysqli_begin_transaction($conn);

    try {
        $summary = ridesync_admin_remove_account($conn, $accountType, $accountId);
        mysqli_commit($conn);
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        ridesync_log_exception($exception, [
            'admin_id' => $adminId,
            'account_type' => $accountType,
            'account_id' => $accountId,
        ]);
        $_SESSION['admin_error'] = "Could not remove this account safely. No deletion was completed.";
        ridesync_admin_redirect();
    }

    $summary = ridesync_admin_remove_finalize_cleanup($summary, $accountType, $accountId);
    $deletedRows = array_sum(array_map('intval', $summary['deleted_rows'] ?? []));
    $accountLabel = ucfirst($accountType) . ' #' . $accountId;
    $accountName = trim((string) ($account['name'] ?? ''));
    $accountEmail = trim((string) ($account['email'] ?? ''));
    $auditMessage = substr(trim($accountLabel . ' ' . $accountName . ' ' . $accountEmail . ' removed; rows=' . $deletedRows), 0, 255);
    ridesync_admin_log($conn, $adminId, 'admin_remove_' . $accountType, $accountType === 'driver' ? 'driver_account' : 'user', $accountId, $auditMessage);

    if ((int) (($summary['files']['failed'] ?? 0)) > 0) {
        $_SESSION['admin_error'] = $accountLabel . " was removed, but one uploaded file could not be deleted. Check server file permissions.";
    } else {
        $_SESSION['admin_success'] = $accountLabel . " and associated RideSync data were permanently removed.";
    }
    ridesync_admin_redirect();
}

if ($action === 'user_verification_decision') {
    $verificationId = (int) ($_POST['verification_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    if ($verificationId <= 0 || !in_array($decision, ['verified', 'rejected'], true)) {
        $_SESSION['admin_error'] = "Invalid student verification decision.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT uv.user_id, uv.verification_type, u.name
         FROM user_verifications uv
         JOIN users u ON u.id = uv.user_id
         WHERE uv.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $verificationId);
    mysqli_stmt_execute($stmt);
    $verification = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$verification) {
        $_SESSION['admin_error'] = "Verification request not found.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "UPDATE user_verifications SET status = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $decision, $verificationId);
    mysqli_stmt_execute($stmt);

    $approved = $decision === 'verified';
    ridesync_admin_notify(
        $conn,
        (int) $verification['user_id'],
        null,
        $approved ? 'Student verification approved' : 'Student verification needs update',
        $approved
            ? 'Your RideSync student verification has been approved.'
            : 'Your RideSync student verification was rejected. Please update your profile details and submit again.'
    );
    ridesync_admin_log($conn, $adminId, 'student_verification_' . $decision, 'user_verification', $verificationId, $verification['name']);

    $_SESSION['admin_success'] = "Student verification marked as " . ridesync_admin_status_label($decision) . ".";
    ridesync_admin_redirect();
}

if ($action === 'driver_profile_decision') {
    $profileId = (int) ($_POST['profile_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $rejectionReason = trim($_POST['rejection_reason'] ?? '');

    if ($profileId <= 0 || !in_array($decision, ['verified', 'rejected'], true)) {
        $_SESSION['admin_error'] = "Invalid driver profile decision.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT p.driver_id, p.verification_status, d.name
         FROM driver_account_profiles p
         JOIN driver_accounts d ON d.id = p.driver_id
         WHERE p.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $profileId);
    mysqli_stmt_execute($stmt);
    $profile = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$profile) {
        $_SESSION['admin_error'] = "Driver profile not found.";
        ridesync_admin_redirect();
    }

    if ($profile['verification_status'] === $decision) {
        $_SESSION['admin_error'] = "This driver profile was already " . ridesync_admin_status_label($decision) . ".";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "UPDATE driver_account_profiles SET verification_status = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $decision, $profileId);
    mysqli_stmt_execute($stmt);

    if ($decision === 'rejected') {
        ridesync_driver_set_availability($conn, (int) $profile['driver_id'], 'offline');
        if ($rejectionReason !== '') {
            $stmt = mysqli_prepare($conn, "UPDATE driver_accounts SET rejection_reason = ? WHERE id = ?");
            mysqli_stmt_bind_param($stmt, "si", $rejectionReason, $profile['driver_id']);
            mysqli_stmt_execute($stmt);
        }
    }

    $notifyMessage = $decision === 'verified'
        ? 'Your driver profile has been approved. You can go online from the driver dashboard.'
        : ($rejectionReason !== '' ? 'Your driver profile was rejected. Reason: ' . $rejectionReason : 'Your driver profile was rejected. Please update your license and vehicle details.');

    ridesync_admin_notify(
        $conn,
        null,
        (int) $profile['driver_id'],
        $decision === 'verified' ? 'Driver verification approved' : 'Driver verification needs update',
        $notifyMessage
    );
    ridesync_admin_log($conn, $adminId, 'driver_profile_' . $decision, 'driver_profile', $profileId, $profile['name'] . ($rejectionReason !== '' ? ' (' . $rejectionReason . ')' : ''));

    $_SESSION['admin_success'] = "Driver profile marked as " . ridesync_admin_status_label($decision) . ".";
    ridesync_admin_redirect();
}

if ($action === 'driver_full_approval') {
    $driverId = (int) ($_POST['driver_id'] ?? 0);

    if ($driverId <= 0) {
        $_SESSION['admin_error'] = "Invalid driver approval request.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT d.name, p.id AS profile_id
         FROM driver_accounts d
         LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
         WHERE d.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$driver || empty($driver['profile_id'])) {
        $_SESSION['admin_error'] = "Driver profile is not ready for approval.";
        ridesync_admin_redirect();
    }

    $state = ridesync_fetch_driver_state($conn, $driverId);
    $documentSummary = ridesync_driver_required_document_summary($state['documents'] ?? []);

    if (!$documentSummary['complete']) {
        $_SESSION['admin_error'] = "Driving license, Aadhaar card, PAN card, and vehicle RC must be submitted before full approval.";
        ridesync_admin_redirect();
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = mysqli_prepare($conn, "UPDATE driver_accounts SET status = 'active' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare($conn, "UPDATE driver_account_profiles SET verification_status = 'verified' WHERE driver_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE driver_account_documents
             SET verification_status = 'verified'
             WHERE driver_id = ?
               AND document_type IN ('license', 'aadhaar', 'pan', 'id_proof', 'vehicle_rc', 'insurance', 'selfie', 'vehicle_image')"
        );
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);

        if (!ridesync_driver_set_availability($conn, $driverId, 'offline')) {
            throw new RuntimeException("Could not sync driver availability.");
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['admin_error'] = "Could not approve this driver right now.";
        ridesync_admin_redirect();
    }

    ridesync_admin_notify(
        $conn,
        null,
        $driverId,
        'Driver verification complete',
        'Your profile and required documents were approved by RideSync admin. You can now go online from the driver dashboard.'
    );
    ridesync_admin_log($conn, $adminId, 'driver_full_approval', 'driver_account', $driverId, $driver['name']);

    $_SESSION['admin_success'] = "Driver is fully approved and synced with the driver panel.";
    ridesync_admin_redirect();
}

if ($action === 'driver_ai_verification_start') {
    $driverId = (int) ($_POST['driver_id'] ?? 0);

    if ($driverId <= 0) {
        $_SESSION['admin_error'] = "Invalid AI verification request.";
        ridesync_admin_redirect();
    }

    if (!ridesync_verification_schema_ready($conn)) {
        $_SESSION['admin_error'] = "AI verification tables are missing. Run the schema upgrade first.";
        ridesync_admin_redirect();
    }

    $sessionId = ridesync_verification_start_for_driver($conn, $driverId, 'admin_manual_run');
    if (!$sessionId) {
        $_SESSION['admin_error'] = "Could not start AI verification for this driver.";
        ridesync_admin_redirect();
    }

    ridesync_admin_log($conn, $adminId, 'driver_ai_verification_started', 'driver_account', $driverId, 'Session #' . $sessionId);
    $_SESSION['admin_success'] = "AI verification analysis started.";
    ridesync_admin_redirect();
}

if ($action === 'driver_ai_verification_decision') {
    $sessionId = (int) ($_POST['session_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($sessionId <= 0 || !in_array($decision, ['approved', 'rejected', 'escalated'], true)) {
        $_SESSION['admin_error'] = "Invalid AI verification decision.";
        ridesync_admin_redirect();
    }

    if (!ridesync_verification_schema_ready($conn)) {
        $_SESSION['admin_error'] = "AI verification tables are missing.";
        ridesync_admin_redirect();
    }

    $bundle = ridesync_verification_fetch_session_bundle($conn, $sessionId);
    if (!$bundle) {
        $_SESSION['admin_error'] = "AI verification session not found.";
        ridesync_admin_redirect();
    }

    $driverId = (int) $bundle['session']['driver_id'];
    mysqli_begin_transaction($conn);

    try {
        ridesync_verification_admin_decision($conn, $sessionId, $adminId, $decision, $adminNote);

        if ($decision === 'approved') {
            $stmt = mysqli_prepare($conn, "UPDATE driver_account_profiles SET verification_status = 'verified' WHERE driver_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $driverId);
            mysqli_stmt_execute($stmt);

            $stmt = mysqli_prepare(
                $conn,
                "UPDATE driver_account_documents
                 SET verification_status = 'verified'
                 WHERE driver_id = ?
                   AND document_type IN ('license', 'aadhaar', 'pan', 'id_proof', 'vehicle_rc', 'insurance', 'selfie', 'vehicle_image')"
            );
            mysqli_stmt_bind_param($stmt, "i", $driverId);
            mysqli_stmt_execute($stmt);
        } elseif ($decision === 'rejected') {
            $stmt = mysqli_prepare($conn, "UPDATE driver_account_profiles SET verification_status = 'rejected' WHERE driver_id = ?");
            mysqli_stmt_bind_param($stmt, "i", $driverId);
            mysqli_stmt_execute($stmt);

            if (!ridesync_driver_set_availability($conn, $driverId, 'offline')) {
                throw new RuntimeException("Could not sync driver availability.");
            }
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['admin_error'] = "Could not save AI verification decision.";
        ridesync_admin_redirect();
    }

    ridesync_admin_notify(
        $conn,
        null,
        $driverId,
        $decision === 'approved' ? 'Driver verification approved' : ($decision === 'rejected' ? 'Driver verification rejected' : 'Driver verification under review'),
        $decision === 'approved'
            ? 'Your driver documents were approved after RideSync AI verification.'
            : ($decision === 'rejected'
                ? 'Your driver documents were rejected after verification review. Please update your profile.'
                : 'Your driver verification was escalated for manual compliance review.')
    );
    ridesync_admin_log($conn, $adminId, 'driver_ai_verification_' . $decision, 'driver_verification_session', $sessionId, 'Driver #' . $driverId);

    $_SESSION['admin_success'] = "AI verification decision saved.";
    ridesync_admin_redirect();
}

if ($action === 'admin_services_release_timeouts') {
    $released = RideSyncServiceObservabilityService::releaseTimedOutJobs($conn, 600);
    ridesync_admin_log($conn, $adminId, 'admin_services_release_timeouts', 'background_jobs', null, $released . ' stale job(s) released');
    $_SESSION['admin_success'] = $released . " stale background job" . ($released === 1 ? "" : "s") . " released back to the queue.";
    ridesync_admin_redirect();
}

if ($action === 'admin_services_retry_failed_verifications') {
    $summary = RideSyncServiceObservabilityService::retryFailedVerificationJobs($conn, 25);
    $total = (int) $summary['jobs_requeued'] + (int) $summary['jobs_created'];
    ridesync_admin_log(
        $conn,
        $adminId,
        'admin_services_retry_failed_verifications',
        'background_jobs',
        null,
        'jobs=' . $total . ', sessions=' . (int) $summary['sessions_requeued']
    );
    $_SESSION['admin_success'] = $total . " failed verification job" . ($total === 1 ? "" : "s") . " prepared for retry.";
    ridesync_admin_redirect();
}

if ($action === 'admin_repair_kit_execute') {
    $operation = trim((string) ($_POST['repair_operation'] ?? ''));
    $confirmation = trim((string) ($_POST['confirmation_text'] ?? ''));
    $result = RideSyncRepairKitService::execute($conn, $adminId, $operation, $confirmation);

    ridesync_admin_log(
        $conn,
        $adminId,
        !empty($result['ok']) ? 'admin_repair_kit_' . $operation : 'admin_repair_kit_failed',
        'repair_kit',
        isset($result['details']['run_id']) ? (int) $result['details']['run_id'] : null,
        substr((string) ($result['message'] ?? ''), 0, 255)
    );

    if (!empty($result['ok'])) {
        $_SESSION['admin_success'] = (string) ($result['message'] ?? 'Repair Kit action completed.');
    } else {
        $_SESSION['admin_error'] = (string) ($result['message'] ?? 'Repair Kit action failed.');
    }
    ridesync_admin_redirect();
}

if ($action === 'admin_alert_rule_toggle') {
    $ruleId = (int) ($_POST['rule_id'] ?? 0);
    $enabled = (int) ($_POST['enabled'] ?? 0) === 1;

    if ($ruleId <= 0) {
        $_SESSION['admin_error'] = "Invalid alert rule.";
        ridesync_admin_redirect();
    }

    if (!RideSyncServiceObservabilityService::toggleAlertRule($conn, $ruleId, $enabled)) {
        $_SESSION['admin_error'] = "Could not update alert rule.";
        ridesync_admin_redirect();
    }

    ridesync_admin_log($conn, $adminId, $enabled ? 'admin_alert_rule_enabled' : 'admin_alert_rule_disabled', 'admin_alert_rule', $ruleId, null);
    $_SESSION['admin_success'] = "Alert rule " . ($enabled ? "enabled" : "disabled") . ".";
    ridesync_admin_redirect();
}

if ($action === 'admin_feature_flag_update') {
    $flagId = (int) ($_POST['flag_id'] ?? 0);
    $enabled = (int) ($_POST['enabled'] ?? 0) === 1;
    $maintenanceMode = (int) ($_POST['maintenance_mode'] ?? 0) === 1;

    if ($flagId <= 0) {
        $_SESSION['admin_error'] = "Invalid feature flag.";
        ridesync_admin_redirect();
    }

    if (!ridesync_admin_feature_flags_schema_ready($conn)) {
        $_SESSION['admin_error'] = "Feature flag schema is missing. Run the schema upgrade before changing runtime switches.";
        ridesync_admin_redirect();
    }

    if (!ridesync_admin_update_feature_flag($conn, $flagId, $enabled, $maintenanceMode, $adminId)) {
        $_SESSION['admin_error'] = "Could not update feature flag.";
        ridesync_admin_redirect();
    }

    ridesync_admin_log(
        $conn,
        $adminId,
        'admin_feature_flag_update',
        'feature_flag',
        $flagId,
        'enabled=' . (int) $enabled . ', maintenance=' . (int) $maintenanceMode
    );
    $_SESSION['admin_success'] = "Feature flag updated.";
    ridesync_admin_redirect();
}

if ($action === 'admin_note_create') {
    $entityType = ridesync_admin_normalize_note_entity($_POST['entity_type'] ?? '');
    $entityId = (int) ($_POST['entity_id'] ?? 0);
    $noteText = trim((string) ($_POST['note_text'] ?? ''));
    $noteType = trim((string) ($_POST['note_type'] ?? 'general'));

    if ($entityType === '' || $entityId <= 0 || $noteText === '') {
        $_SESSION['admin_error'] = "Admin note requires a valid target and note body.";
        ridesync_admin_redirect();
    }

    if (!ridesync_admin_notes_schema_ready($conn)) {
        $_SESSION['admin_error'] = "Admin notes schema is missing. Run the schema upgrade before saving notes.";
        ridesync_admin_redirect();
    }

    if (!ridesync_admin_create_note($conn, $entityType, $entityId, $adminId, $noteText, $noteType)) {
        $_SESSION['admin_error'] = "Could not save admin note.";
        ridesync_admin_redirect();
    }

    ridesync_admin_log($conn, $adminId, 'admin_note_create', $entityType, $entityId, $noteType);
    $_SESSION['admin_success'] = "Admin note saved.";
    ridesync_admin_redirect();
}

if ($action === 'admin_bulk_operation') {
    $operation = trim((string) ($_POST['bulk_operation'] ?? ''));
    $confirmation = trim((string) ($_POST['confirmation_text'] ?? ''));
    $allowedOperations = array_keys(ridesync_admin_bulk_operation_definitions($conn));

    if (!in_array($operation, $allowedOperations, true)) {
        $_SESSION['admin_error'] = "Invalid bulk operation.";
        ridesync_admin_redirect();
    }

    if (!hash_equals('RUN BULK', $confirmation)) {
        ridesync_admin_log($conn, $adminId, 'admin_bulk_confirmation_failed', 'bulk_operation', null, $operation);
        $_SESSION['admin_error'] = "Bulk operation cancelled. Type RUN BULK exactly to confirm.";
        ridesync_admin_redirect();
    }

    mysqli_begin_transaction($conn);
    try {
        $changed = 0;
        $detail = '';
        if ($operation === 'retry_failed_ai_jobs') {
            mysqli_commit($conn);
            $summary = RideSyncServiceObservabilityService::retryFailedVerificationJobs($conn, 25);
            $changed = (int) $summary['jobs_requeued'] + (int) $summary['jobs_created'];
            $detail = 'jobs=' . $changed . ', sessions=' . (int) $summary['sessions_requeued'];
        } elseif ($operation === 'close_stale_rides') {
            $changed = ridesync_admin_close_stale_rides($conn, 250);
            mysqli_commit($conn);
            $detail = 'closed_rides=' . $changed;
        } elseif ($operation === 'expire_stale_demand') {
            $changed = ridesync_admin_expire_stale_demand($conn, 500);
            mysqli_commit($conn);
            $detail = 'expired_demand=' . $changed;
        } elseif ($operation === 'approve_low_risk_ai') {
            $summary = ridesync_admin_approve_low_risk_ai_sessions($conn, $adminId, 25);
            $changed = (int) $summary['approved'];
            mysqli_commit($conn);
            $detail = 'approved=' . $changed . ', drivers=' . implode(',', array_slice($summary['drivers'], 0, 20));
        } else {
            throw new RuntimeException('Unsupported bulk operation.');
        }
    } catch (Throwable $exception) {
        mysqli_rollback($conn);
        ridesync_log_exception($exception, [
            'admin_id' => $adminId,
            'operation' => $operation,
        ]);
        $_SESSION['admin_error'] = "Bulk operation failed. No partial unsafe cleanup was kept.";
        ridesync_admin_redirect();
    }

    RideSyncServiceObservabilityService::clearSnapshotCache();
    ridesync_admin_log($conn, $adminId, 'admin_bulk_' . $operation, 'bulk_operation', null, $detail);
    $_SESSION['admin_success'] = "Bulk operation completed: " . $changed . " item" . ($changed === 1 ? "" : "s") . " changed.";
    ridesync_admin_redirect();
}

if ($action === 'driver_document_decision') {
    $documentId = (int) ($_POST['document_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';

    if ($documentId <= 0 || !in_array($decision, ['verified', 'rejected'], true)) {
        $_SESSION['admin_error'] = "Invalid driver document decision.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT doc.driver_id, doc.document_type, d.name
         FROM driver_account_documents doc
         JOIN driver_accounts d ON d.id = doc.driver_id
         WHERE doc.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $documentId);
    mysqli_stmt_execute($stmt);
    $document = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$document) {
        $_SESSION['admin_error'] = "Driver document not found.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "UPDATE driver_account_documents SET verification_status = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "si", $decision, $documentId);
    mysqli_stmt_execute($stmt);

    if ($decision === 'rejected') {
        ridesync_driver_set_availability($conn, (int) $document['driver_id'], 'offline');
    }

    ridesync_admin_notify(
        $conn,
        null,
        (int) $document['driver_id'],
        $decision === 'verified' ? 'Driver document approved' : 'Driver document needs update',
        $decision === 'verified'
            ? 'A driver document was approved by RideSync admin.'
            : 'A driver document was rejected. Please update your document reference.'
    );
    ridesync_admin_log($conn, $adminId, 'driver_document_' . $decision, 'driver_document', $documentId, $document['name']);

    if ($decision === 'verified') {
        $state = ridesync_fetch_driver_state($conn, (int) $document['driver_id']);

        if (ridesync_driver_is_verified($state)) {
            ridesync_admin_notify(
                $conn,
                null,
                (int) $document['driver_id'],
                'Driver verification complete',
                'Your required documents are approved. You can now go online from the driver dashboard.'
            );
            ridesync_admin_log($conn, $adminId, 'driver_ready_for_panel', 'driver_account', $document['driver_id'], $document['name']);
        }
    }

    $_SESSION['admin_success'] = "Driver document marked as " . ridesync_admin_status_label($decision) . ".";
    ridesync_admin_redirect();
}

if ($action === 'driver_account_status') {
    $driverId = (int) ($_POST['driver_id'] ?? 0);
    $status = $_POST['status'] ?? '';

    if ($driverId <= 0 || !in_array($status, ['active', 'inactive', 'suspended'], true)) {
        $_SESSION['admin_error'] = "Invalid driver account status.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "SELECT name FROM driver_accounts WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$driver) {
        $_SESSION['admin_error'] = "Driver account not found.";
        ridesync_admin_redirect();
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = mysqli_prepare($conn, "UPDATE driver_accounts SET status = ? WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "si", $status, $driverId);
        if (!mysqli_stmt_execute($stmt)) {
            throw new RuntimeException("Could not update driver account status.");
        }

        if ($status !== 'active' && !ridesync_driver_set_availability($conn, $driverId, 'offline')) {
            throw new RuntimeException("Could not take driver offline.");
        }

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['admin_error'] = "Could not update this driver account right now.";
        ridesync_admin_redirect();
    }

    ridesync_admin_notify(
        $conn,
        null,
        $driverId,
        'Driver account ' . ridesync_admin_status_label($status),
        $status === 'active'
            ? 'Your driver account has been restored by RideSync admin.'
            : 'Your driver account has been marked ' . ridesync_admin_status_label($status) . ' by RideSync admin.'
    );
    ridesync_admin_log($conn, $adminId, 'driver_account_' . $status, 'driver_account', $driverId, $driver['name']);

    $_SESSION['admin_success'] = "Driver account marked as " . ridesync_admin_status_label($status) . ".";
    ridesync_admin_redirect();
}

if ($action === 'report_decision') {
    $reportId = (int) ($_POST['report_id'] ?? 0);
    $decision = $_POST['decision'] ?? '';
    $adminNote = trim($_POST['admin_note'] ?? '');

    if ($reportId <= 0 || !in_array($decision, ['reviewing', 'resolved', 'dismissed'], true)) {
        $_SESSION['admin_error'] = "Invalid report action.";
        ridesync_admin_redirect();
    }

    if (strlen($adminNote) > 255) {
        $adminNote = substr($adminNote, 0, 255);
    }

    $stmt = mysqli_prepare($conn, "SELECT reporter_user_id, reason FROM reports WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $reportId);
    mysqli_stmt_execute($stmt);
    $report = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$report) {
        $_SESSION['admin_error'] = "Report not found.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE reports
         SET report_status = ?,
             admin_note = ?,
             resolved_at = CASE WHEN ? IN ('resolved', 'dismissed') THEN CURRENT_TIMESTAMP ELSE NULL END
         WHERE id = ?"
    );
    mysqli_stmt_bind_param($stmt, "sssi", $decision, $adminNote, $decision, $reportId);
    mysqli_stmt_execute($stmt);

    if (in_array($decision, ['resolved', 'dismissed'], true)) {
        ridesync_admin_notify(
            $conn,
            (int) $report['reporter_user_id'],
            null,
            'Report ' . ridesync_admin_status_label($decision),
            $decision === 'resolved'
                ? 'Your report was reviewed and resolved by RideSync admin.'
                : 'Your report was reviewed and dismissed by RideSync admin.'
        );
    }

    ridesync_admin_log($conn, $adminId, 'report_' . $decision, 'report', $reportId, $report['reason']);
    $_SESSION['admin_success'] = "Report marked as " . ridesync_admin_status_label($decision) . ".";
    ridesync_admin_redirect();
}

if ($action === 'user_account_status') {
    $userId = (int) ($_POST['user_id'] ?? 0);
    $status = strtolower(trim((string) ($_POST['status'] ?? '')));
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($userId <= 0 || !in_array($status, ['active', 'suspended'], true)) {
        $_SESSION['admin_error'] = "Invalid user account status request.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "SELECT name, email, status FROM users WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $user = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$user) {
        $_SESSION['admin_error'] = "User account not found.";
        ridesync_admin_redirect();
    }

    if ($user['status'] === $status) {
        $_SESSION['admin_error'] = "User account is already " . $status . ".";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "UPDATE users SET status = ?, status_reason = ? WHERE id = ?");
    mysqli_stmt_bind_param($stmt, "ssi", $status, $reason, $userId);
    mysqli_stmt_execute($stmt);

    ridesync_admin_notify(
        $conn,
        $userId,
        null,
        'Account ' . ridesync_admin_status_label($status),
        $status === 'suspended'
            ? 'Your rider account has been suspended by RideSync admin' . ($reason ? '. Reason: ' . $reason : '.')
            : 'Your rider account has been reinstated by RideSync admin.'
    );
    ridesync_admin_log($conn, $adminId, 'user_account_' . $status, 'user', $userId, $user['name'] . ($reason ? ' (' . $reason . ')' : ''));

    $_SESSION['admin_success'] = "User account marked as " . ridesync_admin_status_label($status) . ".";
    ridesync_admin_redirect();
}

if ($action === 'admin_force_cancel_ride') {
    $rideId = (int) ($_POST['ride_id'] ?? 0);
    $reason = trim((string) ($_POST['reason'] ?? ''));

    if ($rideId <= 0) {
        $_SESSION['admin_error'] = "Invalid ride cancellation request.";
        ridesync_admin_redirect();
    }

    $stmt = mysqli_prepare($conn, "SELECT user_id, origin, destination, status FROM rides WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $rideId);
    mysqli_stmt_execute($stmt);
    $ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$ride) {
        $_SESSION['admin_error'] = "Ride not found.";
        ridesync_admin_redirect();
    }

    if ($ride['status'] === 'cancelled') {
        $_SESSION['admin_error'] = "This ride is already cancelled.";
        ridesync_admin_redirect();
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = mysqli_prepare($conn, "UPDATE rides SET status = 'cancelled' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $rideId);
        mysqli_stmt_execute($stmt);

        // Reject pending or accepted matches
        $stmt = mysqli_prepare($conn, "UPDATE matches SET status = 'rejected' WHERE ride_id = ? AND status IN ('pending', 'accepted')");
        mysqli_stmt_bind_param($stmt, "i", $rideId);
        mysqli_stmt_execute($stmt);

        mysqli_commit($conn);
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['admin_error'] = "Could not cancel this ride safely.";
        ridesync_admin_redirect();
    }

    // Notify rider owner
    ridesync_admin_notify(
        $conn,
        (int) $ride['user_id'],
        null,
        'Ride Cancelled by Admin',
        'Your ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' was cancelled by RideSync admin' . ($reason ? '. Reason: ' . $reason : '.')
    );

    ridesync_admin_log($conn, $adminId, 'admin_force_cancel_ride', 'ride', $rideId, 'Cancelled ride #' . $rideId . ($reason ? ' (' . $reason . ')' : ''));
    $_SESSION['admin_success'] = "Ride #" . $rideId . " was force-cancelled by admin.";
    ridesync_admin_redirect();
}

$_SESSION['admin_error'] = "Unknown admin action.";
ridesync_admin_redirect();
?>
