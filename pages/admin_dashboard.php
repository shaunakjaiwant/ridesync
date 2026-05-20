<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/admin_dashboard_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/asset_helper.php';
require_once __DIR__ . '/../includes/admin_remove_helper.php';
require_once __DIR__ . '/../includes/admin_operations_helper.php';
require_once __DIR__ . '/../includes/services/CacheService.php';
require_once __DIR__ . '/../includes/services/ServiceObservabilityService.php';
require_once __DIR__ . '/../includes/services/RepairKitService.php';

ridesync_require_admin_login();

if (!ridesync_admin_schema_ready($conn)) {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "Admin database tables are missing.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    unset($_SESSION['admin_id'], $_SESSION['admin_name'], $_SESSION['admin_role']);
    $_SESSION['admin_error'] = "This admin account cannot access the dashboard right now.";
    header("Location: /ridesync/pages/admin_login.php");
    exit();
}
ridesync_admin_sync_session($admin);
$canManageDriverAccounts = ridesync_admin_can($admin, 'manage_driver_accounts');
$canRemoveAccounts = ridesync_admin_can($admin, 'remove_accounts');
$canViewAuditLogs = ridesync_admin_can($admin, 'view_audit_logs');
$canRunBulkOperations = ridesync_admin_can($admin, 'run_bulk_operations');
$canManageAlertRules = ridesync_admin_can($admin, 'manage_alert_rules');
$canManageFeatureFlags = ridesync_admin_can($admin, 'manage_feature_flags');
$canRepairPlatform = ridesync_admin_can($admin, 'repair_platform');

$section = (string) ($_GET['section'] ?? 'overview');
$sectionAliases = [
    'verifications' => 'users',
    'analytics' => 'overview',
    'system' => 'services',
];
if (isset($sectionAliases[$section])) {
    $section = $sectionAliases[$section];
    $_GET['section'] = $section;
}
$allowedSections = ['overview', 'profiles', 'drivers', 'users', 'rides', 'requests', 'reports', 'services', 'audit', 'bulk', 'remove'];
if (!in_array($section, $allowedSections, true)) {
    $section = 'overview';
}
if ($section === 'remove' && !$canRemoveAccounts) {
    $section = 'overview';
}
if ($section === 'services' && !ridesync_admin_can($admin, 'run_ai_verification')) {
    $section = 'overview';
}
if ($section === 'audit' && !$canViewAuditLogs) {
    $section = 'overview';
}
if ($section === 'bulk' && !$canRunBulkOperations) {
    $section = 'overview';
}

$globalSearch = trim($_GET['q'] ?? '');
$isOverviewSection = $section === 'overview';
$_GET['section'] = $section;
if ($globalSearch === '') {
    unset($_GET['q']);
}

$metrics = RideSyncCacheService::remember('admin:dashboard:metrics:v3', 10, static function () use ($conn) {
    $metricsResult = mysqli_query(
        $conn,
        "SELECT
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(DISTINCT user_id) FROM user_verifications WHERE status = 'verified') AS verified_users,
            (SELECT COUNT(*) FROM driver_accounts) AS total_drivers,
            (SELECT COUNT(*)
             FROM driver_accounts d
             JOIN driver_account_profiles p ON p.driver_id = d.id AND p.verification_status = 'verified'
             WHERE d.status = 'active'
               AND EXISTS (
                    SELECT 1 FROM driver_account_documents doc
                    WHERE doc.driver_id = d.id
                      AND doc.document_type = 'license'
                      AND doc.verification_status = 'verified'
               )
               AND EXISTS (
                    SELECT 1 FROM driver_account_documents doc
                    WHERE doc.driver_id = d.id
                      AND doc.document_type = 'aadhaar'
                      AND doc.verification_status = 'verified'
               )
               AND EXISTS (
                    SELECT 1 FROM driver_account_documents doc
                    WHERE doc.driver_id = d.id
                      AND doc.document_type = 'pan'
                      AND doc.verification_status = 'verified'
               )
               AND EXISTS (
                    SELECT 1 FROM driver_account_documents doc
                    WHERE doc.driver_id = d.id
                      AND doc.document_type = 'vehicle_rc'
                      AND doc.verification_status = 'verified'
               )) AS ready_drivers,
            (SELECT COUNT(*) FROM driver_account_availability WHERE status = 'online') AS online_drivers,
            (SELECT COUNT(*) FROM rides WHERE status = 'open') AS open_rides,
            (SELECT COUNT(*) FROM ride_live_status WHERE live_status IN ('matched', 'driver_assigned', 'arriving', 'active')) AS live_rides,
            (SELECT COUNT(*) FROM matches WHERE status = 'pending') AS pending_join_requests,
            (SELECT COUNT(*) FROM driver_ride_requests WHERE request_status = 'pending') AS pending_driver_requests,
            (SELECT COUNT(*) FROM driver_account_profiles WHERE verification_status = 'pending') AS pending_driver_profiles,
            (SELECT COUNT(*) FROM driver_account_documents WHERE verification_status = 'pending') AS pending_driver_documents,
            (SELECT COUNT(*) FROM user_verifications WHERE status = 'pending') AS pending_student_verifications,
            (SELECT COUNT(*) FROM reports WHERE report_status IN ('open', 'reviewing')) AS active_reports,
            (SELECT COUNT(*) FROM reports WHERE report_status = 'open') AS open_reports,
            (SELECT COUNT(*) FROM rides WHERE status = 'open' AND CONCAT(travel_date, ' ', travel_time) < NOW()) AS stale_open_rides,
            (SELECT COALESCE(SUM(amount), 0) FROM wallet_transactions WHERE transaction_type = 'fare_due') AS fare_due_total,
            (SELECT COUNT(*) FROM audit_logs WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)) AS audit_24h"
    );

    return mysqli_fetch_assoc($metricsResult) ?: [];
});
$pendingVerifications = ridesync_admin_int($metrics, 'pending_driver_profiles')
    + ridesync_admin_int($metrics, 'pending_driver_documents')
    + ridesync_admin_int($metrics, 'pending_student_verifications');
$pendingRequests = ridesync_admin_int($metrics, 'pending_join_requests')
    + ridesync_admin_int($metrics, 'pending_driver_requests');

$adminProfiles = [
    [
        'name' => 'Vinay H Gowda',
        'initials' => 'VG',
        'image' => '/ridesync/vinay.jpeg',
        'role' => 'Project Director & Operations Coordinator',
        'accent' => 'gold',
        'traits' => ['Leadership', 'Support', 'Operations', 'Workflow'],
        'description' => 'Vinay oversees platform management, daily coordination, operational discipline, and workflow execution across RideSync.',
    ],
    [
        'name' => 'Shaunak N Jaiwant',
        'initials' => 'SJ',
        'image' => '/ridesync/shaunak.png',
        'image_zoom' => '99%',
        'image_position' => 'center 34%',
        'image_shift_y' => '-1px',
        'role' => 'Creative Director & Technical Administrator',
        'accent' => 'violet',
        'traits' => ['Leadership', 'Design', 'Systems', 'Precision'],
        'description' => 'Shaunak leads technical administration, interface quality, system organization, and product polish for the admin experience.',
    ],
    [
        'name' => 'Vishal D Naik',
        'initials' => 'VN',
        'image' => '/ridesync/vishal.png',
        'image_zoom' => '100%',
        'image_position' => 'center 20%',
        'image_shift_y' => '-5px',
        'avatar_background' => '#ffffff',
        'role' => 'System Director & Support Coordinator',
        'accent' => 'cyan',
        'traits' => ['Leadership', 'Reliability', 'Support', 'Stability'],
        'description' => 'Vishal supports system reliability, administrative coordination, support workflows, and operational stability.',
    ],
];

$adminId = (int) $admin['id'];
$adminProfileRecord = ridesync_admin_prepared_rows(
    $conn,
    "SELECT id, name, email, role, status, created_at, updated_at FROM admin_users WHERE id = ? LIMIT 1",
    'i',
    [$adminId]
);
$adminProfileRecord = $adminProfileRecord[0] ?? $admin;

$adminCapabilityCatalog = [
    'review_students' => 'Student verification review',
    'review_drivers' => 'Driver and document review',
    'run_ai_verification' => 'AI verification operations',
    'review_reports' => 'Report resolution',
    'manage_admin_notes' => 'Admin notes',
    'manage_driver_accounts' => 'Driver account controls',
    'view_audit_logs' => 'Audit explorer',
    'run_bulk_operations' => 'Bulk cleanup operations',
    'manage_alert_rules' => 'Alert rules',
    'manage_feature_flags' => 'Feature flags',
    'repair_platform' => 'Repair Kit god-mode recovery',
    'remove_accounts' => 'Account removal',
];
$adminGrantedCapabilities = [];
foreach ($adminCapabilityCatalog as $capability => $label) {
    if (ridesync_admin_can($admin, $capability)) {
        $adminGrantedCapabilities[$capability] = $label;
    }
}

$adminProfileAudit = ridesync_admin_prepared_rows(
    $conn,
    "SELECT
        COUNT(*) AS total_actions,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR) THEN 1 ELSE 0 END) AS actions_24h,
        SUM(CASE WHEN created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY) THEN 1 ELSE 0 END) AS actions_7d,
        MAX(created_at) AS last_action_at
     FROM audit_logs
     WHERE admin_id = ?",
    'i',
    [$adminId]
);
$adminProfileAudit = $adminProfileAudit[0] ?? [];

$adminRecentActions = ridesync_admin_prepared_rows(
    $conn,
    "SELECT action, entity_type, entity_id, message, created_at
     FROM audit_logs
     WHERE admin_id = ?
     ORDER BY created_at DESC
     LIMIT 6",
    'i',
    [$adminId]
);

$adminRosterRows = ridesync_admin_query_rows(
    $conn,
    "SELECT au.id, au.name, au.email, au.role, au.status, au.created_at, MAX(al.created_at) AS last_action_at, COUNT(al.id) AS action_count
     FROM admin_users au
     LEFT JOIN audit_logs al ON al.admin_id = au.id
     GROUP BY au.id, au.name, au.email, au.role, au.status, au.created_at
     ORDER BY FIELD(au.status, 'active', 'inactive'), FIELD(au.role, 'super_admin', 'moderator'), au.name"
);

$sessionStartedAt = (int) ($_SESSION['_auth_started_at'] ?? time());
$sessionLastRotatedAt = (int) ($_SESSION['_last_rotated_at'] ?? $sessionStartedAt);
$sessionAgeMinutes = max(0, (int) floor((time() - $sessionStartedAt) / 60));
$sessionRotationMinutes = max(0, (int) floor((time() - $sessionLastRotatedAt) / 60));
$lastActionLabel = !empty($adminProfileAudit['last_action_at'])
    ? date('M j, g:i A', strtotime((string) $adminProfileAudit['last_action_at']))
    : 'No recorded actions';
$adminProfileName = (string) ($adminProfileRecord['name'] ?? 'Admin');
$adminInitials = '';
foreach (preg_split('/\s+/', trim($adminProfileName)) ?: [] as $namePart) {
    if ($namePart !== '') {
        $adminInitials .= strtoupper(substr($namePart, 0, 1));
    }
    if (strlen($adminInitials) >= 2) {
        break;
    }
}
$adminInitials = $adminInitials !== '' ? $adminInitials : 'AD';

$needsOverview = $section === 'overview';
$needsProfiles = $section === 'profiles';
$needsDrivers = $section === 'drivers';
$needsUsers = $section === 'users';
$needsRides = $section === 'rides';
$needsRequests = $section === 'requests';
$needsReports = $section === 'reports';
$needsServices = $section === 'services';
$needsAudit = $section === 'audit';
$needsBulk = $section === 'bulk';
$needsRemove = $section === 'remove';
$verificationReady = ridesync_verification_schema_ready($conn);
$profileServiceCount = 0;
if ($needsProfiles) {
    $profileServicesSnapshot = RideSyncServiceObservabilityService::snapshot($conn, ['probe_external' => false]);
    $profileServiceCount = count($profileServicesSnapshot['services'] ?? []);
}

$driverRows = [];
$userRows = [];
$rideRows = [];
$directRequestRows = [];
$communityRequestRows = [];
$studentVerificationRows = [];
$removeRows = [];
$reportRows = [];
$reportNotesById = [];
$routeDemandRows = [];
$activityFeedRows = [];
$riskFlags = [];
$mapDrivers = [];
$mapRides = [];
$mapDemand = [];
$aiQueueMetrics = [
    'pending' => 0,
    'verified' => 0,
    'suspicious' => 0,
    'rejected' => 0,
    'needs_review' => 0,
];
$servicesSnapshot = null;
$repairKitSnapshot = null;
$operationalInboxRows = [];
$riskScore = [
    'score' => 0,
    'inverse_score' => 100,
    'level' => 'Low',
    'severity' => 'healthy',
    'components' => [],
    'generated_at' => date('Y-m-d H:i:s'),
];
$incidentTimelineRows = [];
$auditRows = [];
$auditPagination = null;
$auditFilters = [];
$auditWhereSql = '';
$auditTypes = '';
$auditParams = [];
$auditSummary = [
    'total_24h' => 0,
    'denied_24h' => 0,
    'bulk_24h' => 0,
    'unique_admins_24h' => 0,
];
$bulkOperations = [];
$dataQualityIssues = [];
$slaTimers = [];
$backupStatus = [
    'severity' => 'info',
    'db_healthy' => false,
    'latest_file' => '',
    'latest_path' => '',
    'latest_at' => null,
    'age_hours' => null,
    'backup_count' => 0,
    'missing_schema' => [],
];
$fraudClusters = [];
$featureFlagsPanel = [
    'schema_ready' => false,
    'flags' => [],
];

$driverPagination = null;
$userPagination = null;
$ridePagination = null;
$directRequestPagination = null;
$communityRequestPagination = null;
$reportPagination = null;
$removePagination = null;

if ($needsDrivers) {
    if ($verificationReady) {
        $queueRow = ridesync_admin_query_rows($conn,
            "SELECT
                SUM(CASE WHEN latest.status IN ('queued', 'processing') OR latest.id IS NULL THEN 1 ELSE 0 END) AS pending,
                SUM(CASE WHEN latest.ai_decision = 'verified' THEN 1 ELSE 0 END) AS verified,
                SUM(CASE WHEN latest.ai_decision = 'suspicious' THEN 1 ELSE 0 END) AS suspicious,
                SUM(CASE WHEN latest.ai_decision = 'fake_tampered' THEN 1 ELSE 0 END) AS rejected,
                SUM(CASE WHEN latest.ai_decision = 'needs_manual_review' THEN 1 ELSE 0 END) AS needs_review
             FROM driver_accounts d
             LEFT JOIN (
                SELECT s.*
                FROM driver_verification_sessions s
                JOIN (
                    SELECT driver_id, MAX(id) AS latest_id
                    FROM driver_verification_sessions
                    GROUP BY driver_id
                ) latest_ids ON latest_ids.latest_id = s.id
             ) latest ON latest.driver_id = d.id"
        )[0] ?? [];

        foreach ($aiQueueMetrics as $key => $value) {
            $aiQueueMetrics[$key] = (int) ($queueRow[$key] ?? 0);
        }
    }

    $driverPagination = ridesync_admin_pagination_meta(ridesync_admin_int($metrics, 'total_drivers'), 'drivers_page', 25);
    $verificationSelect = $verificationReady
        ? "ai.id AS ai_session_id,
            ai.status AS ai_status,
            ai.ai_decision,
            ai.risk_level AS ai_risk_level,
            ai.confidence_score AS ai_confidence_score,"
        : "NULL AS ai_session_id,
            NULL AS ai_status,
            NULL AS ai_decision,
            NULL AS ai_risk_level,
            NULL AS ai_confidence_score,";
    $verificationJoin = $verificationReady
        ? "LEFT JOIN (
            SELECT s.*
            FROM driver_verification_sessions s
            JOIN (
                SELECT driver_id, MAX(id) AS latest_id
                FROM driver_verification_sessions
                GROUP BY driver_id
            ) latest_ai ON latest_ai.latest_id = s.id
         ) ai ON ai.driver_id = d.id"
        : "";
    $driverRows = ridesync_admin_paginated_rows($conn,
        "SELECT
            d.id AS driver_id,
            d.name,
            d.email,
            d.phone,
            d.status AS account_status,
            d.onboarding_status,
            d.created_at,
            p.id AS profile_id,
            p.license_number,
            p.verification_status AS profile_status,
            p.updated_at AS profile_updated_at,
            v.vehicle_type,
            v.vehicle_number,
            v.seating_capacity,
            COALESCE(a.status, 'offline') AS availability_status,
            a.current_lat,
            a.current_lng,
            COALESCE(doc_summary.total_documents, 0) AS total_documents,
            COALESCE(doc_summary.submitted_required_documents, 0) AS submitted_required_documents,
            COALESCE(doc_summary.verified_required_documents, 0) AS verified_required_documents,
            COALESCE(doc_summary.pending_documents, 0) AS pending_documents,
            COALESCE(doc_summary.verified_documents, 0) AS verified_documents,
            COALESCE(doc_summary.rejected_documents, 0) AS rejected_documents,
            COALESCE(request_summary.pending_requests, 0) AS pending_requests,
            COALESCE(request_summary.accepted_requests, 0) AS accepted_requests,
            COALESCE(live_summary.assigned_rides, 0) AS assigned_rides,
            COALESCE(history_summary.completed_trips, 0) AS completed_trips,
            COALESCE(history_summary.total_earnings, 0) AS total_earnings,
            {$verificationSelect}
            linked_user.id AS linked_user_id,
            linked_user.name AS linked_user_name,
            linked_user.email AS linked_user_email
         FROM driver_accounts d
         LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
         LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
         LEFT JOIN driver_account_availability a ON a.driver_id = d.id
         LEFT JOIN users linked_user
            ON CONVERT(linked_user.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
             = CONVERT(d.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
         LEFT JOIN (
            SELECT
                driver_id,
                total_documents,
                license_submitted
                    + aadhaar_submitted
                    + pan_submitted
                    + rc_submitted
                    AS submitted_required_documents,
                license_verified
                    + aadhaar_verified
                    + pan_verified
                    + rc_verified
                    AS verified_required_documents,
                pending_documents,
                verified_documents,
                rejected_documents
            FROM (
                SELECT
                    driver_id,
                    COUNT(*) AS total_documents,
                    MAX(CASE WHEN document_type = 'license' THEN 1 ELSE 0 END) AS license_submitted,
                    MAX(CASE WHEN document_type = 'license' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS license_verified,
                    MAX(CASE WHEN document_type = 'aadhaar' THEN 1 ELSE 0 END) AS aadhaar_submitted,
                    MAX(CASE WHEN document_type = 'aadhaar' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS aadhaar_verified,
                    MAX(CASE WHEN document_type = 'pan' THEN 1 ELSE 0 END) AS pan_submitted,
                    MAX(CASE WHEN document_type = 'pan' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS pan_verified,
                    MAX(CASE WHEN document_type = 'vehicle_rc' THEN 1 ELSE 0 END) AS rc_submitted,
                    MAX(CASE WHEN document_type = 'vehicle_rc' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS rc_verified,
                    SUM(CASE WHEN verification_status = 'pending' THEN 1 ELSE 0 END) AS pending_documents,
                    SUM(CASE WHEN verification_status = 'verified' THEN 1 ELSE 0 END) AS verified_documents,
                    SUM(CASE WHEN verification_status = 'rejected' THEN 1 ELSE 0 END) AS rejected_documents
                FROM driver_account_documents
                GROUP BY driver_id
            ) summarized_docs
         ) doc_summary ON doc_summary.driver_id = d.id
         LEFT JOIN (
            SELECT
                driver_id,
                SUM(CASE WHEN request_status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
                SUM(CASE WHEN request_status = 'accepted' THEN 1 ELSE 0 END) AS accepted_requests
            FROM driver_ride_requests
            GROUP BY driver_id
         ) request_summary ON request_summary.driver_id = d.id
         LEFT JOIN (
            SELECT driver_id, COUNT(*) AS assigned_rides
            FROM ride_live_status
            WHERE driver_id IS NOT NULL
              AND live_status IN ('driver_assigned', 'arriving', 'active')
            GROUP BY driver_id
         ) live_summary ON live_summary.driver_id = d.id
         LEFT JOIN (
            SELECT driver_id, COUNT(*) AS completed_trips, SUM(fare) AS total_earnings
            FROM driver_ride_history
            GROUP BY driver_id
         ) history_summary ON history_summary.driver_id = d.id
         {$verificationJoin}
         ORDER BY
            FIELD(COALESCE(p.verification_status, 'pending'), 'pending', 'rejected', 'verified'),
            FIELD(d.status, 'active', 'inactive', 'suspended'),
            d.created_at DESC",
        $driverPagination
    );
}

if ($needsUsers) {
    $userPagination = ridesync_admin_pagination_meta(ridesync_admin_int($metrics, 'total_users'), 'users_page', 25);
    $userRows = ridesync_admin_paginated_rows($conn,
        "SELECT
            u.id,
            u.name,
            u.email,
            u.college,
            u.gender,
            u.created_at,
            COALESCE(uv.verification_status, 'unverified') AS verification_status,
            COALESCE(ride_summary.rides_posted, 0) AS rides_posted,
            COALESCE(ride_summary.open_rides, 0) AS open_rides,
            COALESCE(incoming_summary.pending_incoming_requests, 0) AS pending_incoming_requests,
            COALESCE(match_summary.join_requests, 0) AS join_requests,
            COALESCE(match_summary.accepted_join_requests, 0) AS accepted_join_requests,
            COALESCE(report_summary.reports_against, 0) AS reports_against,
            COALESCE(filed_report_summary.reports_filed, 0) AS reports_filed,
            COALESCE(wallet_summary.pending_due, 0) AS pending_due,
            linked_driver.id AS linked_driver_id,
            linked_driver.status AS linked_driver_status,
            linked_driver_profile.verification_status AS linked_driver_verification,
            COALESCE(linked_driver_availability.status, 'offline') AS linked_driver_availability
         FROM users u
         LEFT JOIN driver_accounts linked_driver
            ON CONVERT(linked_driver.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
             = CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci
         LEFT JOIN driver_account_profiles linked_driver_profile ON linked_driver_profile.driver_id = linked_driver.id
         LEFT JOIN driver_account_availability linked_driver_availability ON linked_driver_availability.driver_id = linked_driver.id
         LEFT JOIN (
            SELECT user_id,
                   SUBSTRING_INDEX(GROUP_CONCAT(status ORDER BY updated_at DESC), ',', 1) AS verification_status
            FROM user_verifications
            GROUP BY user_id
         ) uv ON uv.user_id = u.id
         LEFT JOIN (
            SELECT
                user_id,
                COUNT(*) AS rides_posted,
                SUM(CASE WHEN status = 'open' THEN 1 ELSE 0 END) AS open_rides
            FROM rides
            GROUP BY user_id
         ) ride_summary ON ride_summary.user_id = u.id
         LEFT JOIN (
            SELECT r.user_id, COUNT(*) AS pending_incoming_requests
            FROM rides r
            JOIN matches m ON m.ride_id = r.id
            WHERE m.status = 'pending'
            GROUP BY r.user_id
         ) incoming_summary ON incoming_summary.user_id = u.id
         LEFT JOIN (
            SELECT
                matched_user_id,
                COUNT(*) AS join_requests,
                SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted_join_requests
            FROM matches
            GROUP BY matched_user_id
         ) match_summary ON match_summary.matched_user_id = u.id
         LEFT JOIN (
            SELECT reported_user_id, COUNT(*) AS reports_against
            FROM reports
            WHERE reported_user_id IS NOT NULL
            GROUP BY reported_user_id
         ) report_summary ON report_summary.reported_user_id = u.id
         LEFT JOIN (
            SELECT reporter_user_id, COUNT(*) AS reports_filed
            FROM reports
            GROUP BY reporter_user_id
         ) filed_report_summary ON filed_report_summary.reporter_user_id = u.id
         LEFT JOIN (
            SELECT user_id,
                   SUM(CASE WHEN transaction_type = 'fare_due' THEN amount ELSE 0 END)
                   - SUM(CASE WHEN transaction_type = 'cash_paid' THEN amount ELSE 0 END) AS pending_due
            FROM wallet_transactions
            GROUP BY user_id
         ) wallet_summary ON wallet_summary.user_id = u.id
         ORDER BY u.created_at DESC",
        $userPagination
    );

    $studentVerificationRows = ridesync_admin_query_rows($conn,
        "SELECT uv.*, u.name, u.email, u.college
         FROM user_verifications uv
         JOIN users u ON u.id = uv.user_id
         ORDER BY FIELD(uv.status, 'pending', 'rejected', 'verified'), uv.updated_at DESC
         LIMIT 80"
    );
}

if ($needsRemove) {
    $removeTotal = ridesync_admin_int($metrics, 'total_users') + ridesync_admin_int($metrics, 'total_drivers');
    $removePagination = ridesync_admin_pagination_meta($removeTotal, 'remove_page', 25);
    $removeRows = ridesync_admin_paginated_rows($conn,
        "SELECT *
         FROM (
            SELECT
                CAST('rider' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS account_type,
                u.id AS account_id,
                CONVERT(u.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name,
                CONVERT(u.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS email,
                CAST('' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS phone,
                CAST('Rider' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS account_label,
                u.created_at,
                COALESCE(ride_summary.rides_posted, 0) AS ride_count,
                COALESCE(match_summary.join_requests, 0) + COALESCE(direct_summary.direct_requests, 0) AS request_count,
                0 AS document_count,
                COALESCE(wallet_summary.payment_count, 0) AS payment_count,
                COALESCE(notification_summary.notification_count, 0) AS notification_count,
                COALESCE(report_summary.report_count, 0) + COALESCE(reported_summary.report_count, 0) AS report_count
             FROM users u
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS rides_posted
                FROM rides
                GROUP BY user_id
             ) ride_summary ON ride_summary.user_id = u.id
             LEFT JOIN (
                SELECT matched_user_id, COUNT(*) AS join_requests
                FROM matches
                GROUP BY matched_user_id
             ) match_summary ON match_summary.matched_user_id = u.id
             LEFT JOIN (
                SELECT rider_user_id, COUNT(*) AS direct_requests
                FROM driver_ride_requests
                WHERE rider_user_id IS NOT NULL
                GROUP BY rider_user_id
             ) direct_summary ON direct_summary.rider_user_id = u.id
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS payment_count
                FROM wallet_transactions
                GROUP BY user_id
             ) wallet_summary ON wallet_summary.user_id = u.id
             LEFT JOIN (
                SELECT user_id, COUNT(*) AS notification_count
                FROM notifications
                WHERE user_id IS NOT NULL
                GROUP BY user_id
             ) notification_summary ON notification_summary.user_id = u.id
             LEFT JOIN (
                SELECT reporter_user_id, COUNT(*) AS report_count
                FROM reports
                GROUP BY reporter_user_id
             ) report_summary ON report_summary.reporter_user_id = u.id
             LEFT JOIN (
                SELECT reported_user_id, COUNT(*) AS report_count
                FROM reports
                WHERE reported_user_id IS NOT NULL
                GROUP BY reported_user_id
             ) reported_summary ON reported_summary.reported_user_id = u.id
            UNION ALL
            SELECT
                CAST('driver' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS account_type,
                d.id AS account_id,
                CONVERT(d.name USING utf8mb4) COLLATE utf8mb4_unicode_ci AS name,
                CONVERT(d.email USING utf8mb4) COLLATE utf8mb4_unicode_ci AS email,
                CONVERT(d.phone USING utf8mb4) COLLATE utf8mb4_unicode_ci AS phone,
                CAST('Driver' AS CHAR CHARACTER SET utf8mb4) COLLATE utf8mb4_unicode_ci AS account_label,
                d.created_at,
                COALESCE(history_summary.completed_trips, 0) AS ride_count,
                COALESCE(request_summary.request_count, 0) AS request_count,
                COALESCE(document_summary.document_count, 0) AS document_count,
                COALESCE(wallet_summary.payment_count, 0) AS payment_count,
                COALESCE(notification_summary.notification_count, 0) AS notification_count,
                0 AS report_count
             FROM driver_accounts d
             LEFT JOIN (
                SELECT driver_id, COUNT(*) AS completed_trips
                FROM driver_ride_history
                GROUP BY driver_id
             ) history_summary ON history_summary.driver_id = d.id
             LEFT JOIN (
                SELECT driver_id, COUNT(*) AS request_count
                FROM driver_ride_requests
                GROUP BY driver_id
             ) request_summary ON request_summary.driver_id = d.id
             LEFT JOIN (
                SELECT driver_id, COUNT(*) AS document_count
                FROM driver_account_documents
                GROUP BY driver_id
             ) document_summary ON document_summary.driver_id = d.id
             LEFT JOIN (
                SELECT driver_id, COUNT(*) AS payment_count
                FROM wallet_transactions
                WHERE driver_id IS NOT NULL
                GROUP BY driver_id
             ) wallet_summary ON wallet_summary.driver_id = d.id
             LEFT JOIN (
                SELECT driver_id, COUNT(*) AS notification_count
                FROM notifications
                WHERE driver_id IS NOT NULL
                GROUP BY driver_id
             ) notification_summary ON notification_summary.driver_id = d.id
         ) removable_accounts
         ORDER BY created_at DESC",
        $removePagination
    );
}

if ($needsRides) {
    $ridePagination = ridesync_admin_pagination_meta(ridesync_admin_count_query($conn, "SELECT COUNT(*) AS total FROM rides"), 'rides_page', 25);
    $rideRows = ridesync_admin_paginated_rows($conn,
        "SELECT
            r.*,
            u.name AS owner_name,
            u.email AS owner_email,
            COALESCE(ls.live_status, 'searching') AS live_status,
            ls.eta_minutes,
            d.id AS assigned_driver_id,
            d.name AS driver_name,
            d.email AS driver_email,
            COALESCE(match_summary.total_requests, 0) AS total_requests,
            COALESCE(match_summary.pending_requests, 0) AS pending_requests,
            COALESCE(match_summary.accepted_requests, 0) AS accepted_requests
         FROM rides r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         LEFT JOIN driver_accounts d ON d.id = ls.driver_id
         LEFT JOIN (
            SELECT ride_id,
                   COUNT(*) AS total_requests,
                   SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_requests,
                   SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted_requests
            FROM matches
            GROUP BY ride_id
         ) match_summary ON match_summary.ride_id = r.id
         ORDER BY r.created_at DESC",
        $ridePagination
    );
}

if ($needsRequests) {
    $directRequestPagination = ridesync_admin_pagination_meta(ridesync_admin_count_query($conn, "SELECT COUNT(*) AS total FROM driver_ride_requests"), 'direct_requests_page', 25);
    $directRequestRows = ridesync_admin_paginated_rows($conn,
        "SELECT
            rr.*,
            d.id AS driver_account_id,
            d.name AS driver_name,
            d.email AS driver_email,
            u.id AS rider_account_id,
            u.name AS rider_name,
            u.email AS rider_email
         FROM driver_ride_requests rr
         JOIN driver_accounts d ON d.id = rr.driver_id
         LEFT JOIN users u ON u.id = rr.rider_user_id
         ORDER BY FIELD(rr.request_status, 'pending', 'accepted', 'completed', 'rejected', 'cancelled', 'expired'), rr.requested_at DESC",
        $directRequestPagination
    );

    $communityRequestPagination = ridesync_admin_pagination_meta(ridesync_admin_count_query($conn, "SELECT COUNT(*) AS total FROM matches"), 'join_requests_page', 25);
    $communityRequestRows = ridesync_admin_paginated_rows($conn,
        "SELECT
            m.*,
            r.origin,
            r.destination,
            r.travel_date,
            r.travel_time,
            owner.id AS owner_id,
            owner.name AS owner_name,
            requester.id AS requester_id,
            requester.name AS requester_name,
            requester.email AS requester_email
         FROM matches m
         JOIN rides r ON r.id = m.ride_id
         JOIN users owner ON owner.id = r.user_id
         JOIN users requester ON requester.id = m.matched_user_id
         ORDER BY FIELD(m.status, 'pending', 'accepted', 'rejected'), m.created_at DESC",
        $communityRequestPagination
    );
}

if ($needsReports) {
    $reportPagination = ridesync_admin_pagination_meta(ridesync_admin_count_query($conn, "SELECT COUNT(*) AS total FROM reports"), 'reports_page', 20);
    $reportRows = ridesync_admin_paginated_rows($conn,
        "SELECT rep.*, reporter.name AS reporter_name, reported.name AS reported_name,
                r.origin, r.destination
         FROM reports rep
         JOIN users reporter ON reporter.id = rep.reporter_user_id
         LEFT JOIN users reported ON reported.id = rep.reported_user_id
         LEFT JOIN rides r ON r.id = rep.ride_id
         ORDER BY FIELD(rep.report_status, 'open', 'reviewing', 'resolved', 'dismissed'), rep.created_at DESC",
        $reportPagination
    );
    $reportNotesById = ridesync_admin_fetch_notes_for_entities($conn, 'report', array_column($reportRows, 'id'), 2);
}

if ($needsServices) {
    $servicesSnapshot = RideSyncServiceObservabilityService::snapshot($conn, ['probe_external' => false]);
    if ($canRepairPlatform) {
        $repairKitSnapshot = RideSyncRepairKitService::snapshot($conn);
    }
}

if ($needsBulk) {
    $bulkOperations = ridesync_admin_bulk_operation_definitions($conn);
}

if ($needsAudit) {
    $auditFilters = [
        'action' => substr(trim((string) ($_GET['audit_action'] ?? '')), 0, 80),
        'entity' => substr(trim((string) ($_GET['audit_entity'] ?? '')), 0, 80),
        'ip' => substr(trim((string) ($_GET['audit_ip'] ?? '')), 0, 64),
        'admin_id' => max(0, (int) ($_GET['audit_admin'] ?? 0)),
        'from' => trim((string) ($_GET['audit_from'] ?? '')),
        'to' => trim((string) ($_GET['audit_to'] ?? '')),
    ];

    $where = ['1=1'];
    if ($auditFilters['action'] !== '') {
        $where[] = 'al.action LIKE ?';
        $auditTypes .= 's';
        $auditParams[] = '%' . $auditFilters['action'] . '%';
    }
    if ($auditFilters['entity'] !== '') {
        $where[] = 'al.entity_type LIKE ?';
        $auditTypes .= 's';
        $auditParams[] = '%' . $auditFilters['entity'] . '%';
    }
    if ($auditFilters['ip'] !== '') {
        $where[] = 'al.source_ip LIKE ?';
        $auditTypes .= 's';
        $auditParams[] = '%' . $auditFilters['ip'] . '%';
    }
    if ($auditFilters['admin_id'] > 0) {
        $where[] = 'al.admin_id = ?';
        $auditTypes .= 'i';
        $auditParams[] = $auditFilters['admin_id'];
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditFilters['from']) === 1) {
        $where[] = 'al.created_at >= ?';
        $auditTypes .= 's';
        $auditParams[] = $auditFilters['from'] . ' 00:00:00';
    }
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $auditFilters['to']) === 1) {
        $where[] = 'al.created_at <= ?';
        $auditTypes .= 's';
        $auditParams[] = $auditFilters['to'] . ' 23:59:59';
    }
    $auditWhereSql = implode(' AND ', $where);
    $auditTotal = ridesync_admin_prepared_count(
        $conn,
        "SELECT COUNT(*) AS total
         FROM audit_logs al
         LEFT JOIN admin_users au ON au.id = al.admin_id
         WHERE {$auditWhereSql}",
        $auditTypes,
        $auditParams
    );
    $auditPagination = ridesync_admin_pagination_meta($auditTotal, 'audit_page', 30);
    $auditRows = ridesync_admin_prepared_rows(
        $conn,
        "SELECT al.*, au.name AS admin_name, au.email AS admin_email
         FROM audit_logs al
         LEFT JOIN admin_users au ON au.id = al.admin_id
         WHERE {$auditWhereSql}
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT ? OFFSET ?",
        $auditTypes . 'ii',
        array_merge($auditParams, [(int) $auditPagination['per_page'], (int) $auditPagination['offset']])
    );

    $auditSummary = ridesync_admin_query_rows($conn,
        "SELECT
            COUNT(*) AS total_24h,
            SUM(CASE WHEN action LIKE '%denied%' OR action LIKE '%confirmation_failed%' THEN 1 ELSE 0 END) AS denied_24h,
            SUM(CASE WHEN action LIKE 'admin_bulk_%' THEN 1 ELSE 0 END) AS bulk_24h,
            COUNT(DISTINCT admin_id) AS unique_admins_24h
         FROM audit_logs
         WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)"
    )[0] ?? $auditSummary;
}

if ($needsOverview) {
    $overviewSnapshot = RideSyncCacheService::remember('admin:overview:snapshot:v1', 15, static function () use ($conn, $metrics) {
        $operationalInboxRows = ridesync_admin_operational_inbox($conn, $metrics, 14);
        $riskScore = ridesync_admin_risk_score($conn, $metrics);
        $incidentTimelineRows = ridesync_admin_incident_timeline($conn, 14);
        $dataQualityIssues = ridesync_admin_data_quality_monitor($conn);
        $slaTimers = ridesync_admin_sla_timers($conn);
        $backupStatus = ridesync_admin_backup_status($conn);
        $fraudClusters = ridesync_admin_fraud_clusters($conn);
        $featureFlagsPanel = ridesync_admin_feature_flags($conn);

        $routeDemandRows = ridesync_admin_query_rows($conn,
            "SELECT
                route_key,
                MIN(origin) AS origin,
                MIN(destination) AS destination,
                COUNT(*) AS demand_count,
                SUM(CASE WHEN demand_status = 'active' THEN 1 ELSE 0 END) AS active_count,
                MIN(origin_lat) AS origin_lat,
                MIN(origin_lng) AS origin_lng,
                MIN(destination_lat) AS destination_lat,
                MIN(destination_lng) AS destination_lng,
                MAX(updated_at) AS last_seen
             FROM route_demand_signals
             GROUP BY route_key
             ORDER BY active_count DESC, demand_count DESC, last_seen DESC
             LIMIT 12"
        );

        $activityFeedRows = [];
        foreach (ridesync_admin_query_rows($conn, "SELECT id, reason, report_status, created_at FROM reports ORDER BY created_at DESC LIMIT 8") as $row) {
            $activityFeedRows[] = [
                'event_type' => 'report',
                'event_title' => 'Report #' . $row['id'] . ' opened',
                'event_detail' => ridesync_admin_status_label($row['reason']) . ' - ' . ridesync_admin_status_label($row['report_status']),
                'event_time' => $row['created_at'],
                'event_status' => $row['report_status'],
            ];
        }
        foreach (ridesync_admin_query_rows($conn, "SELECT id, origin, destination, status, created_at FROM rides ORDER BY created_at DESC LIMIT 8") as $row) {
            $activityFeedRows[] = [
                'event_type' => 'ride',
                'event_title' => 'Ride #' . $row['id'] . ' posted',
                'event_detail' => $row['origin'] . ' -> ' . $row['destination'],
                'event_time' => $row['created_at'],
                'event_status' => $row['status'],
            ];
        }
        foreach (ridesync_admin_query_rows($conn, "SELECT name, email, status, created_at FROM driver_accounts ORDER BY created_at DESC LIMIT 8") as $row) {
            $activityFeedRows[] = [
                'event_type' => 'driver',
                'event_title' => $row['name'] . ' registered',
                'event_detail' => $row['email'],
                'event_time' => $row['created_at'],
                'event_status' => $row['status'],
            ];
        }
        foreach (ridesync_admin_query_rows($conn, "SELECT id, pickup, drop_location, request_status, requested_at FROM driver_ride_requests ORDER BY requested_at DESC LIMIT 8") as $row) {
            $activityFeedRows[] = [
                'event_type' => 'request',
                'event_title' => 'Driver request #' . $row['id'],
                'event_detail' => $row['pickup'] . ' -> ' . $row['drop_location'],
                'event_time' => $row['requested_at'],
                'event_status' => $row['request_status'],
            ];
        }
        foreach (ridesync_admin_query_rows($conn, "SELECT action, entity_type, entity_id, message, created_at FROM audit_logs ORDER BY created_at DESC LIMIT 8") as $row) {
            $activityFeedRows[] = [
                'event_type' => 'audit',
                'event_title' => ridesync_admin_status_label($row['action']),
                'event_detail' => $row['entity_type'] . ' #' . ($row['entity_id'] ?? '-') . ' ' . ($row['message'] ?? ''),
                'event_time' => $row['created_at'],
                'event_status' => $row['action'],
            ];
        }
        usort($activityFeedRows, static function ($a, $b) {
            return strtotime($b['event_time']) <=> strtotime($a['event_time']);
        });
        $activityFeedRows = array_slice($activityFeedRows, 0, 18);

        $riskFlags = [];
        if (ridesync_admin_int($metrics, 'open_reports') > 0) {
            $riskFlags[] = ['level' => 'critical', 'title' => 'Open reports need triage', 'detail' => ridesync_admin_int($metrics, 'open_reports') . ' report(s) are still open.'];
        }
        if (ridesync_admin_int($metrics, 'open_rides') > 0 && ridesync_admin_int($metrics, 'online_drivers') === 0) {
            $riskFlags[] = ['level' => 'critical', 'title' => 'No online drivers', 'detail' => 'Open rides exist, but no verified driver is currently online.'];
        }
        if (ridesync_admin_int($metrics, 'stale_open_rides') > 0) {
            $riskFlags[] = ['level' => 'warning', 'title' => 'Stale open rides detected', 'detail' => ridesync_admin_int($metrics, 'stale_open_rides') . ' open ride(s) have already passed departure time.'];
        }

        foreach (ridesync_admin_query_rows($conn,
            "SELECT license_number, COUNT(*) AS total
             FROM driver_account_profiles
             WHERE license_number <> ''
             GROUP BY license_number
             HAVING total > 1
             LIMIT 5"
        ) as $duplicate) {
            $riskFlags[] = ['level' => 'warning', 'title' => 'Duplicate license suspected', 'detail' => $duplicate['license_number'] . ' appears on ' . (int) $duplicate['total'] . ' driver profiles.'];
        }

        if (count($riskFlags) === 0) {
            $riskFlags[] = ['level' => 'healthy', 'title' => 'System trust health is stable', 'detail' => 'No high-priority moderation risks are currently detected.'];
        }

        $mapDrivers = ridesync_admin_query_rows($conn,
            "SELECT d.name, a.status, a.current_lat, a.current_lng
             FROM driver_account_availability a
             JOIN driver_accounts d ON d.id = a.driver_id
             WHERE a.status = 'online'
               AND a.current_lat IS NOT NULL
               AND a.current_lng IS NOT NULL
             ORDER BY a.last_changed_at DESC
             LIMIT 100"
        );

        $mapRides = ridesync_admin_query_rows($conn,
            "SELECT r.id, r.origin, r.destination, r.origin_lat, r.origin_lng, COALESCE(ls.live_status, 'searching') AS live_status
             FROM rides r
             LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
             WHERE r.origin_lat IS NOT NULL
               AND r.origin_lng IS NOT NULL
               AND (r.status = 'open' OR ls.live_status IN ('matched', 'driver_assigned', 'arriving', 'active'))
             ORDER BY r.created_at DESC
             LIMIT 100"
        );

        return compact(
            'operationalInboxRows',
            'riskScore',
            'incidentTimelineRows',
            'dataQualityIssues',
            'slaTimers',
            'backupStatus',
            'fraudClusters',
            'featureFlagsPanel',
            'routeDemandRows',
            'activityFeedRows',
            'riskFlags',
            'mapDrivers',
            'mapRides'
        );
    });

    foreach ($overviewSnapshot as $key => $value) {
        if (in_array($key, [
            'operationalInboxRows',
            'riskScore',
            'incidentTimelineRows',
            'dataQualityIssues',
            'slaTimers',
            'backupStatus',
            'fraudClusters',
            'featureFlagsPanel',
            'routeDemandRows',
            'activityFeedRows',
            'riskFlags',
            'mapDrivers',
            'mapRides',
        ], true)) {
            $$key = $value;
        }
    }
}

$searchResults = [
    'users' => [],
    'drivers' => [],
    'rides' => [],
    'reports' => [],
];
if ($globalSearch !== '') {
    $like = '%' . $globalSearch . '%';
    $idSearch = ctype_digit($globalSearch) ? (int) $globalSearch : -1;

    $searchResults['users'] = ridesync_admin_prepared_rows($conn,
        "SELECT id, name, email, college, created_at
         FROM users
         WHERE id = ? OR name LIKE ? OR email LIKE ? OR college LIKE ?
         ORDER BY created_at DESC
         LIMIT 8",
        'isss',
        [$idSearch, $like, $like, $like]
    );
    $searchResults['drivers'] = ridesync_admin_prepared_rows($conn,
        "SELECT d.id, d.name, d.email, d.phone, d.status, v.vehicle_number
         FROM driver_accounts d
         LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
         WHERE d.id = ? OR d.name LIKE ? OR d.email LIKE ? OR d.phone LIKE ? OR v.vehicle_number LIKE ?
         ORDER BY d.created_at DESC
         LIMIT 8",
        'issss',
        [$idSearch, $like, $like, $like, $like]
    );
    $searchResults['rides'] = ridesync_admin_prepared_rows($conn,
        "SELECT r.id, r.origin, r.destination, r.status, r.travel_date, r.travel_time, u.name AS owner_name
         FROM rides r
         JOIN users u ON u.id = r.user_id
         WHERE r.id = ? OR r.origin LIKE ? OR r.destination LIKE ? OR u.name LIKE ?
         ORDER BY r.created_at DESC
         LIMIT 8",
        'isss',
        [$idSearch, $like, $like, $like]
    );
    $searchResults['reports'] = ridesync_admin_prepared_rows($conn,
        "SELECT rep.id, rep.reason, rep.report_status, rep.message, reporter.name AS reporter_name
         FROM reports rep
         JOIN users reporter ON reporter.id = rep.reporter_user_id
         WHERE rep.id = ? OR rep.reason LIKE ? OR rep.message LIKE ? OR reporter.name LIKE ?
         ORDER BY rep.created_at DESC
         LIMIT 8",
        'isss',
        [$idSearch, $like, $like, $like]
    );
}

$mapDriverPayload = [];
foreach ($mapDrivers as $driver) {
    $mapDriverPayload[] = [
        'type' => 'driver',
        'name' => $driver['name'],
        'lat' => (float) $driver['current_lat'],
        'lng' => (float) $driver['current_lng'],
        'status' => $driver['status'],
    ];
}

$mapRidePayload = [];
foreach ($mapRides as $ride) {
    $mapRidePayload[] = [
        'type' => 'ride',
        'name' => '#' . $ride['id'] . ' ' . $ride['origin'] . ' -> ' . $ride['destination'],
        'lat' => (float) $ride['origin_lat'],
        'lng' => (float) $ride['origin_lng'],
        'status' => $ride['live_status'],
    ];
}

foreach ($routeDemandRows as $demand) {
    if ($needsOverview && $demand['origin_lat'] !== null && $demand['origin_lng'] !== null) {
        $mapDemand[] = [
            'type' => 'demand',
            'name' => $demand['origin'] . ' -> ' . $demand['destination'],
            'lat' => (float) $demand['origin_lat'],
            'lng' => (float) $demand['origin_lng'],
            'status' => (int) $demand['active_count'] . ' active',
        ];
    }
}

$mapPayload = [
    'drivers' => $mapDriverPayload,
    'rides' => $mapRidePayload,
    'demand' => $mapDemand,
];

if ($section === 'overview') {
    ridesync_enable_map_assets(false);
}

if ($needsAudit && ($_GET['export'] ?? '') === 'audit_csv') {
    $exportRows = ridesync_admin_prepared_rows(
        $conn,
        "SELECT al.created_at, COALESCE(au.name, 'System') AS admin_name, au.email AS admin_email,
                al.action, al.entity_type, al.entity_id, al.message, al.source_ip, al.user_agent
         FROM audit_logs al
         LEFT JOIN admin_users au ON au.id = al.admin_id
         WHERE {$auditWhereSql}
         ORDER BY al.created_at DESC, al.id DESC
         LIMIT 5000",
        $auditTypes,
        $auditParams
    );
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="ridesync-audit-log-' . date('Ymd-His') . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['created_at', 'admin_name', 'admin_email', 'action', 'entity_type', 'entity_id', 'message', 'source_ip', 'user_agent']);
    foreach ($exportRows as $row) {
        fputcsv($out, [
            $row['created_at'] ?? '',
            $row['admin_name'] ?? '',
            $row['admin_email'] ?? '',
            $row['action'] ?? '',
            $row['entity_type'] ?? '',
            $row['entity_id'] ?? '',
            $row['message'] ?? '',
            $row['source_ip'] ?? '',
            $row['user_agent'] ?? '',
        ]);
    }
    fclose($out);
    exit();
}

require_once __DIR__ . '/../includes/admin_header.php';
?>

<script nonce="<?php echo htmlspecialchars(ridesync_csp_nonce(), ENT_QUOTES, 'UTF-8'); ?>">
window.RideSyncAdminMap = <?php echo json_encode($mapPayload, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
</script>

<div class="admin-command-center" data-admin-command-center>
    <?php if ($isOverviewSection): ?>
        <section class="admin-hero-panel">
            <div>
                <span class="driver-kicker">Operational Control</span>
                <h1>RideSync Command Center</h1>
                <p>Monitor mobility activity, route demand, live rides, reports, and operational risks from one workspace.</p>
            </div>
            <div class="admin-hero-actions">
                <a class="btn btn-secondary" href="/ridesync/pages/admin_dashboard.php?section=rides">Ride Operations</a>
                <a class="btn btn-primary" href="/ridesync/pages/admin_dashboard.php?section=reports">Triage Reports</a>
            </div>
        </section>

        <nav class="panel-action-rail admin-command-rail" aria-label="Critical admin shortcuts">
            <a class="panel-action-card is-primary" href="/ridesync/pages/admin_dashboard.php?section=services">
                <span>Observability</span>
                <strong>Services</strong>
                <small>AI health, queues, latency, and alerts.</small>
            </a>
            <a class="panel-action-card" href="/ridesync/pages/admin_dashboard.php?section=drivers">
                <span>Verification</span>
                <strong>Drivers</strong>
                <small>KYC, AI checks, and approvals.</small>
            </a>
            <a class="panel-action-card" href="/ridesync/pages/admin_dashboard.php?section=audit">
                <span>Traceability</span>
                <strong>Audit</strong>
                <small>Admin actions and entity history.</small>
            </a>
            <a class="panel-action-card" href="/ridesync/pages/admin_dashboard.php?section=bulk">
                <span>Recovery</span>
                <strong>Bulk Ops</strong>
                <small>Safe cleanup and queue repair.</small>
            </a>
        </nav>
    <?php endif; ?>

    <?php ridesync_flash('admin_success', 'alert-success'); ?>
    <?php ridesync_flash('admin_error', 'alert-error'); ?>

    <?php if ($globalSearch !== ''): ?>
        <section class="admin-command-card admin-search-results">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Global Search</span>
                    <h2>Results for &quot;<?php echo htmlspecialchars($globalSearch); ?>&quot;</h2>
                </div>
                <a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_dashboard.php?section=<?php echo urlencode($section); ?>">Clear</a>
            </div>
            <div class="admin-search-grid">
                <?php foreach ($searchResults as $type => $rows): ?>
                    <article class="admin-search-column">
                        <h3><?php echo htmlspecialchars(ucfirst($type)); ?></h3>
                        <?php if (count($rows) === 0): ?>
                            <p>No <?php echo htmlspecialchars($type); ?> found.</p>
                        <?php else: ?>
                            <?php foreach ($rows as $row): ?>
                                <button type="button" class="admin-search-hit admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                    'kicker' => ucfirst($type),
                                    'title' => '#' . ($row['id'] ?? '') . ' ' . ($row['name'] ?? $row['origin'] ?? $row['reason'] ?? 'Record'),
                                    'fields' => $row,
                                ]); ?>">
                                    <strong>#<?php echo (int) ($row['id'] ?? 0); ?> <?php echo htmlspecialchars($row['name'] ?? $row['origin'] ?? ridesync_admin_status_label($row['reason'] ?? 'Record')); ?></strong>
                                    <span><?php echo htmlspecialchars($row['email'] ?? $row['destination'] ?? $row['message'] ?? 'Open details'); ?></span>
                                </button>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isOverviewSection): ?>
        <section class="admin-priority-grid">
            <article class="admin-op-card is-primary">
                <span>Total Users</span>
                <strong data-admin-metric="total_users"><?php echo ridesync_admin_int($metrics, 'total_users'); ?></strong>
                <small>Registered riders and community members</small>
            </article>
            <article class="admin-op-card">
                <span>Drivers</span>
                <strong data-admin-metric="total_drivers"><?php echo ridesync_admin_int($metrics, 'total_drivers'); ?></strong>
                <small><b data-admin-metric="online_drivers"><?php echo ridesync_admin_int($metrics, 'online_drivers'); ?></b> online now</small>
            </article>
            <article class="admin-op-card">
                <span>Live Rides</span>
                <strong data-admin-metric="live_rides"><?php echo ridesync_admin_int($metrics, 'live_rides'); ?></strong>
                <small><b data-admin-metric="open_rides"><?php echo ridesync_admin_int($metrics, 'open_rides'); ?></b> open rides</small>
            </article>
            <article class="admin-op-card is-warning">
                <span>Ride Requests</span>
                <strong><?php echo $pendingRequests; ?></strong>
                <small>Join and direct ride requests waiting</small>
            </article>
            <article class="admin-op-card is-danger">
                <span>Reports</span>
                <strong data-admin-metric="active_reports"><?php echo ridesync_admin_int($metrics, 'active_reports'); ?></strong>
                <small>Open or under review</small>
            </article>
        </section>
    <?php endif; ?>

    <?php if ($isOverviewSection): ?>
        <section class="admin-command-grid admin-ops-priority">
            <article id="operational-inbox" class="admin-command-card admin-operational-inbox">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Operational Inbox</span>
                        <h2>Work Requiring Attention</h2>
                    </div>
                    <span><?php echo count(array_filter($operationalInboxRows, static fn($item) => ($item['severity'] ?? '') !== 'healthy')); ?> open</span>
                </div>
                <div class="admin-inbox-list">
                    <?php foreach ($operationalInboxRows as $item): ?>
                        <a class="admin-inbox-row is-<?php echo htmlspecialchars($item['severity']); ?>" href="<?php echo htmlspecialchars($item['href']); ?>">
                            <span class="admin-feed-dot badge-<?php echo htmlspecialchars($item['severity'] === 'critical' ? 'rejected' : ($item['severity'] === 'healthy' ? 'accepted' : 'pending')); ?>"></span>
                            <div>
                                <strong><?php echo htmlspecialchars($item['title']); ?></strong>
                                <p><?php echo htmlspecialchars($item['detail']); ?></p>
                                <small><?php echo htmlspecialchars($item['meta']); ?> - <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $item['created_at']))); ?></small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>

            <article id="risk-score" class="admin-command-card admin-risk-score-card is-<?php echo htmlspecialchars($riskScore['severity']); ?>">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Risk Scoring</span>
                        <h2>Operational Risk</h2>
                    </div>
                    <span><?php echo htmlspecialchars($riskScore['level']); ?></span>
                </div>
                <div class="admin-risk-score-hero">
                    <strong><?php echo (int) $riskScore['score']; ?></strong>
                    <div>
                        <span>Risk score /100</span>
                        <div class="admin-risk-meter" aria-hidden="true">
                            <span style="width: <?php echo max(0, min(100, (int) $riskScore['score'])); ?>%;"></span>
                        </div>
                        <small>Readiness score: <?php echo (int) $riskScore['inverse_score']; ?>/100</small>
                    </div>
                </div>
                <div class="admin-risk-component-list">
                    <?php foreach ($riskScore['components'] as $component): ?>
                        <div>
                            <span><?php echo htmlspecialchars($component['label']); ?></span>
                            <strong><?php echo (int) $component['points']; ?> pts</strong>
                            <p><?php echo htmlspecialchars($component['detail']); ?></p>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section id="incident-timeline" class="admin-command-card admin-incident-timeline-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Incident Timeline</span>
                    <h2>Recent Operational Events</h2>
                </div>
                <a href="/ridesync/pages/admin_dashboard.php?section=audit">Open Audit</a>
            </div>
            <div class="admin-incident-timeline">
                <?php foreach ($incidentTimelineRows as $event): ?>
                    <a class="admin-timeline-row is-<?php echo htmlspecialchars($event['severity']); ?>" href="<?php echo htmlspecialchars($event['href']); ?>">
                        <span class="admin-timeline-pin"></span>
                        <div>
                            <span><?php echo htmlspecialchars($event['type']); ?> - <?php echo htmlspecialchars($event['meta']); ?></span>
                            <strong><?php echo htmlspecialchars($event['title']); ?></strong>
                            <p><?php echo htmlspecialchars($event['detail']); ?></p>
                        </div>
                        <time><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $event['created_at']))); ?></time>
                    </a>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="admin-command-grid admin-ops-layers">
            <article id="data-quality" class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Data Quality</span>
                        <h2>Integrity Monitor</h2>
                    </div>
                    <span><?php echo array_sum(array_map(static fn($issue) => (int) ($issue['count'] ?? 0), $dataQualityIssues)); ?> issues</span>
                </div>
                <div class="admin-ops-check-list">
                    <?php foreach ($dataQualityIssues as $issue): ?>
                        <a class="admin-ops-check is-<?php echo htmlspecialchars($issue['severity']); ?>" href="<?php echo htmlspecialchars($issue['href']); ?>">
                            <b><?php echo (int) $issue['count']; ?></b>
                            <div>
                                <strong><?php echo htmlspecialchars($issue['title']); ?></strong>
                                <p><?php echo htmlspecialchars($issue['detail']); ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>

            <article id="sla-timers" class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">SLA Timers</span>
                        <h2>Pending Work Age</h2>
                    </div>
                    <span><?php echo count(array_filter($slaTimers, static fn($timer) => ($timer['severity'] ?? '') !== 'healthy')); ?> active</span>
                </div>
                <div class="admin-ops-check-list">
                    <?php foreach ($slaTimers as $timer): ?>
                        <a class="admin-ops-check is-<?php echo htmlspecialchars($timer['severity']); ?>" href="<?php echo htmlspecialchars($timer['href']); ?>">
                            <b><?php echo (int) $timer['count']; ?></b>
                            <div>
                                <strong><?php echo htmlspecialchars($timer['title']); ?></strong>
                                <p><?php echo htmlspecialchars($timer['detail']); ?></p>
                                <small>Oldest: <?php echo !empty($timer['oldest_at']) ? htmlspecialchars((int) $timer['oldest_age_hours'] . 'h old') : 'none waiting'; ?>, target <?php echo (int) $timer['threshold_hours']; ?>h</small>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section class="admin-command-grid admin-ops-layers">
            <article id="backup-status" class="admin-command-card admin-backup-status is-<?php echo htmlspecialchars($backupStatus['severity']); ?>">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Backup & Restore</span>
                        <h2>Recovery Status</h2>
                    </div>
                    <span><?php echo !empty($backupStatus['db_healthy']) ? 'DB reachable' : 'DB unhealthy'; ?></span>
                </div>
                <div class="admin-health-stack">
                    <div><span>Latest Backup</span><strong><?php echo htmlspecialchars($backupStatus['latest_file'] ?: 'Not found'); ?></strong></div>
                    <div><span>Backup Age</span><strong><?php echo $backupStatus['age_hours'] !== null ? (int) $backupStatus['age_hours'] . 'h' : 'Unknown'; ?></strong></div>
                    <div><span>Backup Files</span><strong><?php echo (int) $backupStatus['backup_count']; ?></strong></div>
                    <div><span>Schema Drift</span><strong><?php echo count($backupStatus['missing_schema']); ?> missing</strong></div>
                </div>
                <?php if (count($backupStatus['missing_schema']) > 0): ?>
                    <ul class="admin-finding-list">
                        <?php foreach (array_slice($backupStatus['missing_schema'], 0, 8) as $missing): ?>
                            <li><?php echo htmlspecialchars($missing); ?></li>
                        <?php endforeach; ?>
                    </ul>
                <?php else: ?>
                    <p class="admin-message">Required operational tables, indexes, and columns are present.</p>
                <?php endif; ?>
            </article>

            <article id="fraud-clusters" class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Fraud Clusters</span>
                        <h2>Shared Identity Signals</h2>
                    </div>
                    <span><?php echo count(array_filter($fraudClusters, static fn($cluster) => ($cluster['severity'] ?? '') !== 'healthy')); ?> clusters</span>
                </div>
                <div class="admin-ops-check-list">
                    <?php foreach ($fraudClusters as $cluster): ?>
                        <a class="admin-ops-check is-<?php echo htmlspecialchars($cluster['severity']); ?>" href="<?php echo htmlspecialchars($cluster['href']); ?>">
                            <b><?php echo (int) $cluster['count']; ?></b>
                            <div>
                                <strong><?php echo htmlspecialchars($cluster['title']); ?></strong>
                                <p><?php echo htmlspecialchars($cluster['detail']); ?></p>
                            </div>
                        </a>
                    <?php endforeach; ?>
                </div>
            </article>
        </section>

        <section id="feature-flags" class="admin-command-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Feature Flags</span>
                    <h2>Runtime Module Control</h2>
                </div>
                <span><?php echo !empty($featureFlagsPanel['schema_ready']) ? 'Live switches' : 'Migration required'; ?></span>
            </div>
            <?php if (empty($featureFlagsPanel['schema_ready'])): ?>
                <p class="admin-message">Feature flags are shown in read-only fallback mode until the feature_flags table exists.</p>
            <?php endif; ?>
            <div class="admin-feature-flag-grid">
                <?php foreach ($featureFlagsPanel['flags'] as $flag): ?>
                    <article class="admin-feature-flag-card <?php echo !empty($flag['maintenance_mode']) ? 'is-maintenance' : ''; ?>">
                        <div>
                            <span><?php echo htmlspecialchars(ridesync_admin_status_label($flag['module'] ?? 'core')); ?></span>
                            <strong><?php echo htmlspecialchars($flag['label'] ?? $flag['flag_key']); ?></strong>
                            <p><?php echo htmlspecialchars($flag['description'] ?? 'No description available.'); ?></p>
                        </div>
                        <?php if (!empty($featureFlagsPanel['schema_ready']) && $canManageFeatureFlags && (int) ($flag['id'] ?? 0) > 0): ?>
                            <form action="/ridesync/actions/admin_action.php" method="POST" class="admin-feature-flag-form">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                <input type="hidden" name="action_type" value="admin_feature_flag_update">
                                <input type="hidden" name="flag_id" value="<?php echo (int) $flag['id']; ?>">
                                <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=overview">
                                <label><input type="checkbox" name="enabled" value="1" <?php echo !empty($flag['enabled']) ? 'checked' : ''; ?>> Enabled</label>
                                <label><input type="checkbox" name="maintenance_mode" value="1" <?php echo !empty($flag['maintenance_mode']) ? 'checked' : ''; ?>> Maintenance</label>
                                <button type="submit" class="btn btn-secondary btn-sm">Save</button>
                            </form>
                        <?php else: ?>
                            <div class="admin-chip-list">
                                <span class="badge badge-<?php echo !empty($flag['enabled']) ? 'accepted' : 'closed'; ?>"><?php echo !empty($flag['enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                                <span class="badge badge-<?php echo !empty($flag['maintenance_mode']) ? 'pending' : 'accepted'; ?>"><?php echo !empty($flag['maintenance_mode']) ? 'Maintenance' : 'Normal'; ?></span>
                            </div>
                        <?php endif; ?>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>

    <?php if ($isOverviewSection): ?>
        <section class="admin-connection-grid">
            <article class="admin-connection-card">
                <span>Rider Panel Sync</span>
                <strong><?php echo ridesync_admin_int($metrics, 'total_users'); ?> users</strong>
                <p><?php echo $pendingRequests; ?> ride request<?php echo $pendingRequests === 1 ? '' : 's'; ?> are visible across rider, driver, and admin workflows.</p>
                <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('users')); ?>">Open users</a>
            </article>
            <article class="admin-connection-card">
                <span>Driver Panel Sync</span>
                <strong><?php echo ridesync_admin_int($metrics, 'online_drivers'); ?> online</strong>
                <p>Driver availability, active assignments, and direct requests stay connected to the driver panel.</p>
                <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('drivers')); ?>">Open drivers</a>
            </article>
            <article class="admin-connection-card">
                <span>Ride Marketplace Sync</span>
                <strong><?php echo ridesync_admin_int($metrics, 'open_rides'); ?> open</strong>
                <p>Posted rides, assigned drivers, join requests, and reports are linked in admin detail views.</p>
                <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('rides')); ?>">Open rides</a>
            </article>
        </section>

        <section class="admin-command-grid">
            <article class="admin-command-card admin-feed-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Live Feed</span>
                        <h2>Operational Activity</h2>
                    </div>
                    <span class="admin-live-pill"><span></span> Live</span>
                </div>
                <div class="admin-feed-list" data-admin-feed>
                    <?php foreach ($activityFeedRows as $event): ?>
                        <div class="admin-feed-item">
                            <span class="admin-feed-dot badge-<?php echo htmlspecialchars(ridesync_admin_status_class($event['event_status'])); ?>"></span>
                            <div>
                                <strong><?php echo htmlspecialchars(ridesync_admin_status_label($event['event_title'])); ?></strong>
                                <p><?php echo htmlspecialchars($event['event_detail']); ?></p>
                                <small><?php echo htmlspecialchars(date('M j, g:i A', strtotime($event['event_time']))); ?></small>
                            </div>
                        </div>
                    <?php endforeach; ?>
                </div>
            </article>

            <article class="admin-command-card admin-map-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Mobility Map</span>
                        <h2>Live Network View</h2>
                    </div>
                    <span><?php echo count($mapDrivers) + count($mapRides) + count($mapDemand); ?> signals</span>
                </div>
                <div id="adminLiveMap" class="admin-live-map" aria-label="RideSync operational map"></div>
            </article>
        </section>

        <section class="admin-risk-grid">
            <?php foreach ($riskFlags as $flag): ?>
                <article class="admin-risk-card is-<?php echo htmlspecialchars($flag['level']); ?>">
                    <span><?php echo htmlspecialchars($flag['level'] === 'healthy' ? 'Healthy' : ($flag['level'] === 'critical' ? 'Critical' : 'Watch')); ?></span>
                    <strong><?php echo htmlspecialchars($flag['title']); ?></strong>
                    <p><?php echo htmlspecialchars($flag['detail']); ?></p>
                </article>
            <?php endforeach; ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'overview'): ?>
        <section class="admin-command-grid is-lower">
            <article class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Demand</span>
                        <h2>Popular Route Signals</h2>
                    </div>
                    <span><?php echo count($routeDemandRows); ?> routes</span>
                </div>
                <?php if (count($routeDemandRows) === 0): ?>
                    <div class="driver-empty-card">No active demand signals yet.</div>
                <?php else: ?>
                    <div class="admin-route-list">
                        <?php foreach ($routeDemandRows as $route): ?>
                            <?php $routePercent = ridesync_admin_percent($route['active_count'], max(1, $route['demand_count'])); ?>
                            <div class="admin-route-row">
                                <div>
                                    <strong><?php echo htmlspecialchars($route['origin']); ?> &rarr; <?php echo htmlspecialchars($route['destination']); ?></strong>
                                    <span><?php echo (int) $route['active_count']; ?> active - <?php echo (int) $route['demand_count']; ?> total signals</span>
                                </div>
                                <div class="admin-progress"><span style="width: <?php echo $routePercent; ?>%;"></span></div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </article>
            <article class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Marketplace</span>
                        <h2>Request Flow</h2>
                    </div>
                    <a href="/ridesync/pages/admin_dashboard.php?section=requests">Open Requests</a>
                </div>
                <div class="admin-health-stack">
                    <div><span>Join Requests</span><strong><?php echo ridesync_admin_int($metrics, 'pending_join_requests'); ?></strong></div>
                    <div><span>Direct Driver Requests</span><strong><?php echo ridesync_admin_int($metrics, 'pending_driver_requests'); ?></strong></div>
                    <div><span>Fare Due</span><strong>Rs <?php echo number_format(ridesync_admin_metric($metrics, 'fare_due_total'), 0); ?></strong></div>
                    <div><span>Audit Activity</span><strong><?php echo ridesync_admin_int($metrics, 'audit_24h'); ?></strong></div>
                </div>
            </article>
        </section>
    <?php endif; ?>

    <?php if ($needsProfiles): ?>
        <section class="admin-profile-showcase admin-profile-workspace" aria-labelledby="adminProfilesTitle">
            <div class="admin-profile-hero">
                <article class="admin-profile-identity-card">
                    <div class="admin-profile-avatar admin-current-avatar">
                        <span aria-hidden="true"><?php echo htmlspecialchars(substr($adminInitials, 0, 2)); ?></span>
                    </div>
                    <div>
                        <span class="driver-kicker">Admin Profile</span>
                        <h2 id="adminProfilesTitle"><?php echo htmlspecialchars($adminProfileName); ?></h2>
                        <p><?php echo htmlspecialchars($adminProfileRecord['email'] ?? 'No email'); ?></p>
                    </div>
                    <div class="admin-profile-status-row">
                        <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($adminProfileRecord['status'] ?? 'inactive')); ?>">
                            <?php echo htmlspecialchars(ridesync_admin_status_label($adminProfileRecord['status'] ?? 'inactive')); ?>
                        </span>
                        <span class="admin-profile-role-pill"><?php echo htmlspecialchars(ridesync_admin_status_label($adminProfileRecord['role'] ?? 'moderator')); ?></span>
                    </div>
                </article>

                <div class="admin-profile-signal-grid">
                    <article>
                        <span>Permissions</span>
                        <strong><?php echo count($adminGrantedCapabilities); ?></strong>
                        <small><?php echo count($adminGrantedCapabilities) === count($adminCapabilityCatalog) ? 'full operational access' : 'limited role access'; ?></small>
                    </article>
                    <article>
                        <span>Actions Today</span>
                        <strong><?php echo (int) ($adminProfileAudit['actions_24h'] ?? 0); ?></strong>
                        <small><?php echo (int) ($adminProfileAudit['actions_7d'] ?? 0); ?> this week</small>
                    </article>
                    <article>
                        <span>Session Age</span>
                        <strong><?php echo $sessionAgeMinutes; ?>m</strong>
                        <small>rotated <?php echo $sessionRotationMinutes; ?>m ago</small>
                    </article>
                    <article>
                        <span>Last Audit</span>
                        <strong><?php echo htmlspecialchars($lastActionLabel); ?></strong>
                        <small><?php echo (int) ($adminProfileAudit['total_actions'] ?? 0); ?> total actions</small>
                    </article>
                </div>
            </div>

            <div class="admin-profile-insight-grid">
                <article class="admin-profile-panel">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Access</span>
                            <h2>Granted Controls</h2>
                        </div>
                        <span><?php echo htmlspecialchars(ridesync_admin_status_label($adminProfileRecord['role'] ?? 'moderator')); ?></span>
                    </div>
                    <div class="admin-profile-permission-cloud">
                        <?php foreach ($adminGrantedCapabilities as $label): ?>
                            <span><?php echo htmlspecialchars($label); ?></span>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="admin-profile-panel">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Security</span>
                            <h2>Account Posture</h2>
                        </div>
                        <span class="admin-live-pill"><span></span> Valid</span>
                    </div>
                    <div class="admin-profile-detail-list">
                        <div><span>Account created</span><strong><?php echo htmlspecialchars(date('M j, Y', strtotime((string) ($adminProfileRecord['created_at'] ?? 'now')))); ?></strong></div>
                        <div><span>Last updated</span><strong><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) ($adminProfileRecord['updated_at'] ?? 'now')))); ?></strong></div>
                        <div><span>Session policy</span><strong>HttpOnly + SameSite</strong></div>
                        <div><span>CSRF guard</span><strong><?php echo !empty($_SESSION['csrf_token']) ? 'Active' : 'Missing'; ?></strong></div>
                    </div>
                </article>

                <article class="admin-profile-panel">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Shortcuts</span>
                            <h2>Operational Focus</h2>
                        </div>
                    </div>
                    <div class="admin-profile-action-stack">
                        <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('drivers')); ?>"><strong><?php echo $pendingVerifications; ?></strong><span>pending verification checks</span></a>
                        <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('reports')); ?>"><strong><?php echo ridesync_admin_int($metrics, 'active_reports'); ?></strong><span>active reports</span></a>
                        <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('services')); ?>"><strong><?php echo $profileServiceCount; ?></strong><span>monitored services</span></a>
                    </div>
                </article>
            </div>

            <div class="admin-profile-lower-grid">
                <article class="admin-profile-panel">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Audit</span>
                            <h2>Recent Activity</h2>
                        </div>
                        <a href="<?php echo htmlspecialchars(ridesync_admin_section_url('audit')); ?>">Open audit</a>
                    </div>
                    <?php if (count($adminRecentActions) === 0): ?>
                        <div class="driver-empty-card">No audit activity recorded for this admin yet.</div>
                    <?php else: ?>
                        <div class="admin-profile-timeline">
                            <?php foreach ($adminRecentActions as $action): ?>
                                <div>
                                    <span></span>
                                    <p><strong><?php echo htmlspecialchars(ridesync_admin_status_label($action['action'])); ?></strong> <?php echo htmlspecialchars($action['message'] ?: $action['entity_type']); ?></p>
                                    <small><?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $action['created_at']))); ?><?php echo !empty($action['entity_id']) ? ' - #' . (int) $action['entity_id'] : ''; ?></small>
                                </div>
                            <?php endforeach; ?>
                        </div>
                    <?php endif; ?>
                </article>

                <article class="admin-profile-panel">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Team</span>
                            <h2>Admin Roster</h2>
                        </div>
                        <span><?php echo count($adminRosterRows); ?> accounts</span>
                    </div>
                    <div class="admin-profile-roster">
                        <?php foreach ($adminRosterRows as $rosterAdmin): ?>
                            <div>
                                <strong><?php echo htmlspecialchars($rosterAdmin['name']); ?></strong>
                                <span><?php echo htmlspecialchars($rosterAdmin['email']); ?></span>
                                <small><?php echo htmlspecialchars(ridesync_admin_status_label($rosterAdmin['role'])); ?> - <?php echo htmlspecialchars(ridesync_admin_status_label($rosterAdmin['status'])); ?> - <?php echo (int) $rosterAdmin['action_count']; ?> actions</small>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </article>
            </div>

            <div class="admin-profile-grid admin-profile-team-grid" aria-label="RideSync admin leadership">
                <?php foreach ($adminProfiles as $profile): ?>
                    <article class="admin-profile-card is-<?php echo htmlspecialchars($profile['accent']); ?>">
                        <div class="admin-profile-avatar"<?php echo !empty($profile['image_zoom']) || !empty($profile['image_position']) || !empty($profile['image_shift_y']) || !empty($profile['avatar_background']) ? ' style="' . htmlspecialchars(trim((!empty($profile['image_zoom']) ? '--profile-image-scale: ' . $profile['image_zoom'] . '; ' : '') . (!empty($profile['image_position']) ? '--profile-image-position: ' . $profile['image_position'] . '; ' : '') . (!empty($profile['image_shift_y']) ? '--profile-image-shift-y: ' . $profile['image_shift_y'] . '; ' : '') . (!empty($profile['avatar_background']) ? '--profile-avatar-bg: ' . $profile['avatar_background'] . ';' : ''))) . '"' : ''; ?>>
                            <?php if (!empty($profile['image'])): ?>
                                <img src="<?php echo htmlspecialchars($profile['image']); ?>" alt="<?php echo htmlspecialchars($profile['name']); ?>">
                            <?php else: ?>
                                <span aria-hidden="true"><?php echo htmlspecialchars($profile['initials']); ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="admin-profile-card-body">
                            <h3><?php echo htmlspecialchars($profile['name']); ?></h3>
                            <span class="admin-profile-role"><?php echo htmlspecialchars($profile['role']); ?></span>
                            <p><?php echo htmlspecialchars($profile['description']); ?></p>
                        </div>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>


    <?php if ($section === 'drivers'): ?>
        <section class="admin-command-card admin-table-card" id="drivers">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Fleet</span>
                    <h2>Drivers</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter drivers" aria-label="Filter drivers table" data-admin-table-search="driversTable" data-search-context="driversTable">
                    <select aria-label="Filter drivers by status" data-admin-table-status="driversTable">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="verified">Verified</option>
                        <option value="suspicious">Suspicious</option>
                        <option value="manual_review">Needs Review</option>
                        <option value="rejected">Rejected</option>
                        <option value="suspended">Suspended</option>
                    </select>
                </div>
            </div>

            <div class="admin-kyc-queue" aria-label="Driver verification queue">
                <div><span>Pending</span><strong><?php echo (int) $aiQueueMetrics['pending']; ?></strong></div>
                <div><span>AI Verified</span><strong><?php echo (int) $aiQueueMetrics['verified']; ?></strong></div>
                <div><span>Suspicious</span><strong><?php echo (int) $aiQueueMetrics['suspicious']; ?></strong></div>
                <div><span>Rejected</span><strong><?php echo (int) $aiQueueMetrics['rejected']; ?></strong></div>
                <div><span>Needs Review</span><strong><?php echo (int) $aiQueueMetrics['needs_review']; ?></strong></div>
            </div>

            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="driversTable">
                    <thead>
                        <tr>
                            <th>Driver</th>
                            <th>Trust</th>
                            <th>AI Decision</th>
                            <th>Docs</th>
                            <th>Availability</th>
                            <th>Vehicle</th>
                            <th>Activity</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($driverRows as $driver): ?>
                            <?php
                            $profileStatus = $driver['profile_status'] ?: 'pending';
                            $docSummary = ridesync_admin_required_doc_summary($driver);
                            $driverPanelReady = $profileStatus === 'verified'
                                && $docSummary['ready']
                                && $driver['account_status'] === 'active';
                            $canApproveReadyDriver = !empty($driver['profile_id'])
                                && $docSummary['complete']
                                && !$driverPanelReady;
                            $aiStatus = $driver['ai_decision'] ?: ($driver['ai_status'] ?: 'not_run');
                            $driverSearch = ridesync_admin_search_blob([$driver['name'], $driver['email'], $driver['phone'], $driver['vehicle_number'], $profileStatus, $driver['account_status'], $aiStatus, $driver['ai_risk_level']]);
                            $driverDetailUrl = '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $driver['driver_id'];
                            $driverLinks = [
                                ['label' => 'Open verification page', 'href' => $driverDetailUrl],
                                ['label' => 'Filter driver requests', 'href' => ridesync_admin_section_url('requests', $driver['email'])],
                            ];
                            if (!empty($driver['linked_user_id'])) {
                                $driverLinks[] = ['label' => 'Open linked rider', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $driver['linked_user_id']];
                            }
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($driverSearch); ?>" data-status="<?php echo htmlspecialchars($profileStatus . ' ' . $driver['account_status'] . ' ' . $aiStatus); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($driver['name']); ?></strong>
                                    <span><?php echo htmlspecialchars($driver['email']); ?></span>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($profileStatus)); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($profileStatus)); ?></span>
                                    <small><?php echo htmlspecialchars(ridesync_admin_status_label($driver['account_status'])); ?></small>
                                </td>
                                <td>
                                    <?php if (!empty($driver['ai_session_id'])): ?>
                                        <span class="badge badge-<?php echo htmlspecialchars(ridesync_verification_badge_class($aiStatus)); ?>">
                                            <?php echo htmlspecialchars(ridesync_verification_status_label($aiStatus)); ?>
                                        </span>
                                        <small><?php echo (int) round((float) $driver['ai_confidence_score']); ?> score, <?php echo htmlspecialchars(ucfirst((string) $driver['ai_risk_level'])); ?> risk</small>
                                    <?php else: ?>
                                        <span class="badge badge-closed">Not Run</span>
                                        <small>Awaiting analysis</small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($docSummary['label']); ?></strong>
                                    <span><?php echo (int) $driver['pending_documents']; ?> pending, <?php echo (int) $driver['total_documents']; ?> total</span>
                                </td>
                                <td>
                                    <span class="admin-status-dot is-<?php echo htmlspecialchars($driver['availability_status']); ?>"></span>
                                    <?php echo htmlspecialchars(ridesync_admin_status_label($driver['availability_status'])); ?>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($driver['vehicle_number'] ?: 'Not submitted'); ?></strong>
                                    <span><?php echo htmlspecialchars(($driver['vehicle_type'] ?: 'Vehicle') . ' - ' . ((int) $driver['seating_capacity']) . ' seats'); ?></span>
                                </td>
                                <td>
                                    <strong><?php echo (int) $driver['completed_trips']; ?> trips</strong>
                                    <span><?php echo (int) $driver['pending_requests']; ?> pending, <?php echo (int) $driver['assigned_rides']; ?> assigned</span>
                                </td>
                                <td>
                                    <div class="admin-row-actions">
                                        <button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                            'kicker' => 'Driver',
                                            'title' => $driver['name'],
                                            'fields' => [
                                                'Email' => $driver['email'],
                                                'Phone' => $driver['phone'],
                                                'Account' => ridesync_admin_status_label($driver['account_status']),
                                                'Profile' => ridesync_admin_status_label($profileStatus),
                                                'AI Decision' => !empty($driver['ai_session_id']) ? ridesync_verification_status_label($aiStatus) : 'Not run',
                                                'AI Trust Score' => !empty($driver['ai_session_id']) ? round((float) $driver['ai_confidence_score']) . '/100' : 'Not available',
                                                'AI Risk' => !empty($driver['ai_session_id']) ? ucfirst((string) $driver['ai_risk_level']) : 'Not available',
                                                'Driver Panel Ready' => $driverPanelReady ? 'Yes' : 'No',
                                                'Availability' => ridesync_admin_status_label($driver['availability_status']),
                                                'Vehicle' => trim(($driver['vehicle_type'] ?: 'Vehicle') . ' ' . ($driver['vehicle_number'] ?: '')),
                                                'License' => $driver['license_number'] ?: 'Not submitted',
                                                'Required Documents' => $docSummary['label'],
                                                'All Documents' => $driver['verified_documents'] . '/' . $driver['total_documents'] . ' verified',
                                                'Linked Rider' => $driver['linked_user_name'] ?: 'No matching rider account',
                                                'Direct Requests' => $driver['pending_requests'] . ' pending, ' . $driver['accepted_requests'] . ' accepted',
                                                'Assigned Community Rides' => $driver['assigned_rides'],
                                                'Trips' => $driver['completed_trips'],
                                            ],
                                            'links' => $driverLinks,
                                        ]); ?>">Inspect</button>
                                        <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($driverDetailUrl); ?>">Docs</a>
                                        <?php if ($canApproveReadyDriver): ?>
                                            <form action="/ridesync/actions/admin_action.php" method="POST" data-confirm-message="Approve this driver profile and all submitted required documents?">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action_type" value="driver_full_approval">
                                                <input type="hidden" name="driver_id" value="<?php echo (int) $driver['driver_id']; ?>">
                                                <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=drivers">
                                                <button type="submit" class="btn btn-primary btn-sm">Approve Ready</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if (!empty($driver['profile_id']) && $profileStatus === 'pending' && !$canApproveReadyDriver): ?>
                                            <form action="/ridesync/actions/admin_action.php" method="POST">
                                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                <input type="hidden" name="action_type" value="driver_profile_decision">
                                                <input type="hidden" name="profile_id" value="<?php echo (int) $driver['profile_id']; ?>">
                                                <input type="hidden" name="decision" value="verified">
                                                <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=drivers">
                                                <button type="submit" class="btn btn-secondary btn-sm">Approve Profile</button>
                                            </form>
                                        <?php endif; ?>
                                        <?php if ($canManageDriverAccounts): ?>
                                            <?php if ($driver['account_status'] !== 'suspended'): ?>
                                                <form action="/ridesync/actions/admin_action.php" method="POST" data-confirm-message="Suspend this driver account?">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action_type" value="driver_account_status">
                                                    <input type="hidden" name="driver_id" value="<?php echo (int) $driver['driver_id']; ?>">
                                                    <input type="hidden" name="status" value="suspended">
                                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=drivers">
                                                    <button type="submit" class="btn btn-danger btn-sm">Suspend</button>
                                                </form>
                                            <?php else: ?>
                                                <form action="/ridesync/actions/admin_action.php" method="POST">
                                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                                    <input type="hidden" name="action_type" value="driver_account_status">
                                                    <input type="hidden" name="driver_id" value="<?php echo (int) $driver['driver_id']; ?>">
                                                    <input type="hidden" name="status" value="active">
                                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=drivers">
                                                    <button type="submit" class="btn btn-primary btn-sm">Restore</button>
                                                </form>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($driverRows) === 0): ?>
                            <tr><td colspan="8" class="admin-table-empty">No drivers found on this page.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($driverPagination); ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'users'): ?>
        <section class="admin-command-card admin-table-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Community</span>
                    <h2>Users</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter users" aria-label="Filter users table" data-admin-table-search="usersTable" data-search-context="usersTable">
                    <select aria-label="Filter users by status" data-admin-table-status="usersTable">
                        <option value="">All statuses</option>
                        <option value="verified">Verified</option>
                        <option value="pending">Pending</option>
                        <option value="unverified">Unverified</option>
                    </select>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="usersTable">
                    <thead><tr><th>User</th><th>College</th><th>Verification</th><th>Rides</th><th>Driver Link</th><th>Reports</th><th>Fare Due</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($userRows as $user): ?>
                            <?php
                            $linkedDriverLabel = !empty($user['linked_driver_id'])
                                ? ridesync_admin_status_label($user['linked_driver_status']) . ' / ' . ridesync_admin_status_label($user['linked_driver_verification'] ?: 'pending')
                                : 'No driver profile';
                            $userLinks = [
                                ['label' => 'Open admin user profile', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $user['id']],
                                ['label' => 'Filter user rides', 'href' => ridesync_admin_section_url('rides', $user['email'])],
                                ['label' => 'Filter requests', 'href' => ridesync_admin_section_url('requests', $user['email'])],
                            ];
                            if (!empty($user['linked_driver_id'])) {
                                $userLinks[] = ['label' => 'Open linked driver', 'href' => '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $user['linked_driver_id']];
                            }
                            $userSearch = ridesync_admin_search_blob([$user['name'], $user['email'], $user['college'], $user['verification_status'], $linkedDriverLabel]);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($userSearch); ?>" data-status="<?php echo htmlspecialchars($user['verification_status']); ?>">
                                <td><strong><?php echo htmlspecialchars($user['name']); ?></strong><span><?php echo htmlspecialchars($user['email']); ?></span></td>
                                <td><?php echo htmlspecialchars($user['college']); ?></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($user['verification_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($user['verification_status'])); ?></span></td>
                                <td><strong><?php echo (int) $user['rides_posted']; ?></strong><span><?php echo (int) $user['open_rides']; ?> open, <?php echo (int) $user['pending_incoming_requests']; ?> waiting</span></td>
                                <td>
                                    <strong><?php echo htmlspecialchars($linkedDriverLabel); ?></strong>
                                    <span><?php echo htmlspecialchars(!empty($user['linked_driver_id']) ? ridesync_admin_status_label($user['linked_driver_availability']) : 'Rider only'); ?></span>
                                </td>
                                <td><?php echo (int) $user['reports_against']; ?><span><?php echo (int) $user['reports_filed']; ?> filed</span></td>
                                <td>Rs <?php echo number_format((float) $user['pending_due'], 0); ?></td>
                                <td>
                                    <button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                        'kicker' => 'User',
                                        'title' => $user['name'],
                                        'fields' => [
                                            'Email' => $user['email'],
                                            'College' => $user['college'],
                                            'Gender' => $user['gender'],
                                            'Verification' => ridesync_admin_status_label($user['verification_status']),
                                            'Rides Posted' => $user['rides_posted'],
                                            'Open Rides' => $user['open_rides'],
                                            'Incoming Requests Waiting' => $user['pending_incoming_requests'],
                                            'Join Requests' => $user['join_requests'],
                                            'Accepted Join Requests' => $user['accepted_join_requests'],
                                            'Driver Link' => $linkedDriverLabel,
                                            'Reports Against' => $user['reports_against'],
                                            'Reports Filed' => $user['reports_filed'],
                                            'Pending Fare Due' => 'Rs ' . number_format((float) $user['pending_due'], 2),
                                            'Joined' => date('M j, Y', strtotime($user['created_at'])),
                                        ],
                                        'links' => $userLinks,
                                    ]); ?>">Inspect</button>
                                    <a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_user_detail.php?user_id=<?php echo (int) $user['id']; ?>">Open</a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($userRows) === 0): ?>
                            <tr><td colspan="8" class="admin-table-empty">No users found on this page.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($userPagination); ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'users'): ?>
        <section class="admin-command-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">User Verification</span>
                    <h2>User Verification Approvals</h2>
                </div>
            </div>
            <?php if (count($studentVerificationRows) === 0): ?>
                <div class="driver-empty-card">No user verification requests are waiting.</div>
            <?php else: ?>
                <div class="admin-review-grid">
                    <?php foreach ($studentVerificationRows as $verification): ?>
                        <article class="admin-review-card">
                            <div class="admin-review-top">
                                <div>
                                    <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($verification['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($verification['status'])); ?></span>
                                    <h3><?php echo htmlspecialchars($verification['name']); ?></h3>
                                    <p><?php echo htmlspecialchars($verification['email']); ?></p>
                                </div>
                                <strong><?php echo htmlspecialchars($verification['college']); ?></strong>
                            </div>
                            <dl class="admin-detail-list">
                                <div><dt>Method</dt><dd><?php echo htmlspecialchars(ridesync_admin_status_label($verification['verification_type'])); ?></dd></div>
                                <div><dt>Reference</dt><dd><?php echo htmlspecialchars($verification['reference'] ?: 'Not provided'); ?></dd></div>
                            </dl>
                            <?php if ($verification['status'] === 'pending'): ?>
                                <div class="admin-actions">
                                    <form action="/ridesync/actions/admin_action.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action_type" value="user_verification_decision">
                                        <input type="hidden" name="verification_id" value="<?php echo (int) $verification['id']; ?>">
                                        <input type="hidden" name="decision" value="verified">
                                        <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=users">
                                        <button type="submit" class="btn btn-primary btn-sm">Approve</button>
                                    </form>
                                    <form action="/ridesync/actions/admin_action.php" method="POST">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action_type" value="user_verification_decision">
                                        <input type="hidden" name="verification_id" value="<?php echo (int) $verification['id']; ?>">
                                        <input type="hidden" name="decision" value="rejected">
                                        <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=users">
                                        <button type="submit" class="btn btn-danger btn-sm">Reject</button>
                                    </form>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'remove'): ?>
        <section class="admin-command-card admin-table-card" id="remove">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Account Cleanup</span>
                    <h2>Remove Accounts</h2>
                    <p class="admin-danger-note">Permanent cleanup removes the selected rider or driver account, related operational data, uploaded files, and active sessions.</p>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Search removable users" aria-label="Search removable riders and drivers" data-admin-table-search="removeTable" data-search-context="removeTable">
                    <select aria-label="Filter removable accounts by type" data-admin-table-status="removeTable">
                        <option value="">All accounts</option>
                        <option value="rider">Riders</option>
                        <option value="driver">Drivers</option>
                    </select>
                </div>
            </div>

            <?php if (!$canRemoveAccounts): ?>
                <div class="alert alert-error">Only super admins can permanently remove rider or driver accounts.</div>
            <?php endif; ?>

            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="removeTable">
                    <thead>
                        <tr>
                            <th>User</th>
                            <th>Type</th>
                            <th>Ride Data</th>
                            <th>Requests</th>
                            <th>Files</th>
                            <th>Signals</th>
                            <th>Remove</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($removeRows as $accountRow): ?>
                            <?php
                            $accountType = (string) $accountRow['account_type'];
                            $accountId = (int) $accountRow['account_id'];
                            $confirmationPhrase = ridesync_admin_remove_confirmation_phrase($accountType, $accountId);
                            $removeSearch = ridesync_admin_search_blob([
                                $accountType,
                                $accountId,
                                $accountRow['name'],
                                $accountRow['email'],
                                $accountRow['phone'],
                            ]);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($removeSearch); ?>" data-status="<?php echo htmlspecialchars($accountType); ?>">
                                <td>
                                    <strong><?php echo htmlspecialchars($accountRow['name']); ?></strong>
                                    <span>#<?php echo $accountId; ?> - <?php echo htmlspecialchars($accountRow['email']); ?></span>
                                    <?php if (trim((string) $accountRow['phone']) !== ''): ?>
                                        <small><?php echo htmlspecialchars($accountRow['phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="badge badge-<?php echo $accountType === 'driver' ? 'open' : 'pending'; ?>"><?php echo htmlspecialchars($accountRow['account_label']); ?></span>
                                    <small>Joined <?php echo htmlspecialchars(date('M j, Y', strtotime($accountRow['created_at']))); ?></small>
                                </td>
                                <td>
                                    <strong><?php echo (int) $accountRow['ride_count']; ?></strong>
                                    <span><?php echo $accountType === 'driver' ? 'completed trips' : 'posted rides'; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo (int) $accountRow['request_count']; ?></strong>
                                    <span>booking/request records</span>
                                </td>
                                <td>
                                    <strong><?php echo (int) $accountRow['document_count']; ?></strong>
                                    <span><?php echo $accountType === 'driver' ? 'driver documents' : 'profile assets'; ?></span>
                                </td>
                                <td>
                                    <strong><?php echo (int) $accountRow['payment_count']; ?> payments</strong>
                                    <span><?php echo (int) $accountRow['notification_count']; ?> notifications - <?php echo (int) $accountRow['report_count']; ?> reports</span>
                                </td>
                                <td>
                                    <?php if ($canRemoveAccounts): ?>
                                        <form action="/ridesync/actions/admin_action.php" method="POST"
                                              data-confirm-message="This permanently removes <?php echo htmlspecialchars($accountRow['account_label'] . ' #' . $accountId . ' and all associated RideSync data.'); ?>"
                                              data-confirm-phrase="<?php echo htmlspecialchars($confirmationPhrase); ?>">
                                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                            <input type="hidden" name="action_type" value="admin_remove_account">
                                            <input type="hidden" name="account_type" value="<?php echo htmlspecialchars($accountType); ?>">
                                            <input type="hidden" name="account_id" value="<?php echo $accountId; ?>">
                                            <input type="hidden" name="confirmation_text" value="">
                                            <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=remove">
                                            <button type="submit" class="btn btn-danger btn-sm">Remove</button>
                                        </form>
                                    <?php else: ?>
                                        <span class="badge badge-closed">Restricted</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($removeRows) === 0): ?>
                            <tr><td colspan="7" class="admin-table-empty">No rider or driver accounts are available for removal.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($removePagination); ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'rides'): ?>
        <section class="admin-command-card admin-table-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Ride Operations</span>
                    <h2>Rides</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter rides" aria-label="Filter rides table" data-admin-table-search="ridesTable" data-search-context="ridesTable">
                    <select aria-label="Filter rides by status" data-admin-table-status="ridesTable">
                        <option value="">All statuses</option>
                        <option value="open">Open</option>
                        <option value="active">Active</option>
                        <option value="completed">Completed</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="ridesTable">
                    <thead><tr><th>Ride</th><th>Owner</th><th>Status</th><th>Seats</th><th>Requests</th><th>Distance</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($rideRows as $ride): ?>
                            <?php $fareEstimate = ridesync_estimate_total_ride_fare((float) ($ride['route_distance_km'] ?? 0)); ?>
                            <?php $rideSearch = ridesync_admin_search_blob([$ride['id'], $ride['origin'], $ride['destination'], $ride['owner_name'], $ride['status'], $ride['live_status']]); ?>
                            <?php
                                $rideAdminUrl = '/ridesync/pages/admin_ride_detail.php?id=' . (int) $ride['id'];
                                $rideLinks = [
                                    ['label' => 'Open admin ride detail', 'href' => $rideAdminUrl],
                                    ['label' => 'Open owner profile', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $ride['user_id']],
                                    ['label' => 'Filter join requests', 'href' => ridesync_admin_section_url('requests', (string) $ride['id'])],
                                ];
                                if (!empty($ride['assigned_driver_id'])) {
                                    $rideLinks[] = ['label' => 'Open assigned driver', 'href' => '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $ride['assigned_driver_id']];
                                }
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($rideSearch); ?>" data-status="<?php echo htmlspecialchars($ride['status'] . ' ' . $ride['live_status']); ?>">
                                <td><strong>#<?php echo (int) $ride['id']; ?> <?php echo htmlspecialchars($ride['origin']); ?> &rarr; <?php echo htmlspecialchars($ride['destination']); ?></strong><span><?php echo htmlspecialchars(date('M j', strtotime($ride['travel_date'])) . ' - ' . date('g:i A', strtotime($ride['travel_time']))); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($ride['owner_name']); ?></strong><span><?php echo htmlspecialchars($ride['owner_email']); ?></span></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($ride['live_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($ride['live_status'])); ?></span></td>
                                <td><?php echo (int) $ride['seats_available']; ?></td>
                                <td><?php echo (int) $ride['accepted_requests']; ?>/<?php echo (int) $ride['total_requests']; ?><span><?php echo (int) $ride['pending_requests']; ?> pending</span></td>
                                <td><?php echo $ride['route_distance_km'] !== null ? number_format((float) $ride['route_distance_km'], 1) . ' km' : 'Not mapped'; ?></td>
                                <td><div class="admin-row-actions"><button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                    'kicker' => 'Ride',
                                    'title' => '#' . $ride['id'] . ' ' . $ride['origin'] . ' -> ' . $ride['destination'],
                                    'fields' => [
                                        'Owner' => $ride['owner_name'] . ' (' . $ride['owner_email'] . ')',
                                        'Ride Status' => ridesync_admin_status_label($ride['status']),
                                        'Live Status' => ridesync_admin_status_label($ride['live_status']),
                                        'Assigned Driver' => $ride['driver_name'] ? $ride['driver_name'] . ' (' . $ride['driver_email'] . ')' : 'Not assigned',
                                        'Seats Available' => $ride['seats_available'],
                                        'Requests' => $ride['accepted_requests'] . ' accepted, ' . $ride['pending_requests'] . ' pending of ' . $ride['total_requests'],
                                        'Distance' => $ride['route_distance_km'] ? number_format((float) $ride['route_distance_km'], 2) . ' km' : 'Not mapped',
                                        'Estimated Total Fare' => 'Rs ' . number_format((float) $fareEstimate, 2),
                                    ],
                                    'links' => $rideLinks,
                                ]); ?>">Inspect</button><a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars($rideAdminUrl); ?>">Open</a></div></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($rideRows) === 0): ?>
                            <tr><td colspan="7" class="admin-table-empty">No rides found on this page.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($ridePagination); ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'requests'): ?>
        <section class="admin-command-card admin-table-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Requests</span>
                    <h2>Direct Driver Requests</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter direct requests" aria-label="Filter direct driver requests table" data-admin-table-search="directRequestsTable" data-search-context="directRequestsTable">
                    <select aria-label="Filter direct driver requests by status" data-admin-table-status="directRequestsTable">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="accepted">Accepted</option>
                        <option value="completed">Completed</option>
                        <option value="rejected">Rejected</option>
                        <option value="cancelled">Cancelled</option>
                    </select>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="directRequestsTable">
                    <thead><tr><th>Request</th><th>Rider</th><th>Driver</th><th>Status</th><th>Fare</th><th>Distance</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($directRequestRows as $request): ?>
                            <?php
                                $requestSearch = ridesync_admin_search_blob([$request['id'], $request['pickup'], $request['drop_location'], $request['rider_name'], $request['rider_email'], $request['driver_name'], $request['driver_email'], $request['request_status']]);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($requestSearch); ?>" data-status="<?php echo htmlspecialchars($request['request_status']); ?>">
                                <td><strong>#<?php echo (int) $request['id']; ?> <?php echo htmlspecialchars($request['pickup']); ?> &rarr; <?php echo htmlspecialchars($request['drop_location']); ?></strong><span><?php echo htmlspecialchars(date('M j, g:i A', strtotime($request['requested_at']))); ?></span></td>
                                <td><?php echo htmlspecialchars($request['rider_name'] ?: 'Guest rider'); ?><span><?php echo htmlspecialchars($request['rider_email'] ?: 'No email'); ?></span></td>
                                <td><?php echo htmlspecialchars($request['driver_name']); ?><span><?php echo htmlspecialchars($request['driver_email']); ?></span></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($request['request_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['request_status'])); ?></span></td>
                                <td>Rs <?php echo number_format((float) $request['estimated_fare'], 0); ?></td>
                                <td><?php echo $request['route_distance_km'] !== null ? number_format((float) $request['route_distance_km'], 1) . ' km' : 'Not mapped'; ?></td>
                                <td><button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                    'kicker' => 'Direct Request',
                                    'title' => '#' . $request['id'] . ' ' . $request['pickup'] . ' -> ' . $request['drop_location'],
                                    'fields' => [
                                        'Rider' => ($request['rider_name'] ?: 'Guest rider') . ' (' . ($request['rider_email'] ?: 'No email') . ')',
                                        'Driver' => $request['driver_name'] . ' (' . $request['driver_email'] . ')',
                                        'Status' => ridesync_admin_status_label($request['request_status']),
                                        'Estimated Fare' => 'Rs ' . number_format((float) $request['estimated_fare'], 2),
                                        'Distance' => $request['route_distance_km'] !== null ? number_format((float) $request['route_distance_km'], 2) . ' km' : 'Not mapped',
                                        'Requested' => date('M j, Y g:i A', strtotime($request['requested_at'])),
                                    ],
                                    'links' => array_values(array_filter([
                                        !empty($request['rider_account_id']) ? ['label' => 'Open rider profile', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $request['rider_account_id']] : null,
                                        ['label' => 'Open driver profile', 'href' => '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $request['driver_account_id']],
                                    ])),
                                ]); ?>">Inspect</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($directRequestRows) === 0): ?>
                            <tr><td colspan="7" class="admin-table-empty">No direct driver requests found on this page.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($directRequestPagination); ?>
        </section>

        <section class="admin-command-card admin-table-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Community Pool</span>
                    <h2>Join Requests</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter join requests" aria-label="Filter join requests table" data-admin-table-search="joinRequestsTable" data-search-context="joinRequestsTable">
                    <select aria-label="Filter join requests by status" data-admin-table-status="joinRequestsTable">
                        <option value="">All statuses</option>
                        <option value="pending">Pending</option>
                        <option value="accepted">Accepted</option>
                        <option value="rejected">Rejected</option>
                    </select>
                </div>
            </div>
            <div class="admin-table-wrap">
                <table class="admin-smart-table" id="joinRequestsTable">
                    <thead><tr><th>Ride</th><th>Requester</th><th>Owner</th><th>Status</th><th>Score</th><th>Overlap</th><th>Actions</th></tr></thead>
                    <tbody>
                        <?php foreach ($communityRequestRows as $request): ?>
                            <?php
                                $communitySearch = ridesync_admin_search_blob([$request['ride_id'], $request['origin'], $request['destination'], $request['requester_name'], $request['requester_email'], $request['owner_name'], $request['status']]);
                            ?>
                            <tr data-search="<?php echo htmlspecialchars($communitySearch); ?>" data-status="<?php echo htmlspecialchars($request['status']); ?>">
                                <td><strong>#<?php echo (int) $request['ride_id']; ?> <?php echo htmlspecialchars($request['origin']); ?> &rarr; <?php echo htmlspecialchars($request['destination']); ?></strong><span><?php echo htmlspecialchars(date('M j', strtotime($request['travel_date'])) . ' - ' . date('g:i A', strtotime($request['travel_time']))); ?></span></td>
                                <td><?php echo htmlspecialchars($request['requester_name']); ?><span><?php echo htmlspecialchars($request['requester_email']); ?></span></td>
                                <td><?php echo htmlspecialchars($request['owner_name']); ?></td>
                                <td><span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($request['status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($request['status'])); ?></span></td>
                                <td><?php echo $request['match_score'] !== null ? number_format((float) $request['match_score'], 0) . '%' : 'Manual'; ?></td>
                                <td><?php echo $request['route_overlap_percent'] !== null ? (int) $request['route_overlap_percent'] . '%' : 'Unknown'; ?></td>
                                <td><button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                    'kicker' => 'Join Request',
                                    'title' => '#' . $request['ride_id'] . ' ' . $request['origin'] . ' -> ' . $request['destination'],
                                    'fields' => [
                                        'Requester' => $request['requester_name'] . ' (' . $request['requester_email'] . ')',
                                        'Ride Owner' => $request['owner_name'],
                                        'Status' => ridesync_admin_status_label($request['status']),
                                        'Match Score' => $request['match_score'] !== null ? number_format((float) $request['match_score'], 0) . '%' : 'Manual',
                                        'Route Overlap' => $request['route_overlap_percent'] !== null ? (int) $request['route_overlap_percent'] . '%' : 'Unknown',
                                        'Departure' => date('M j, Y', strtotime($request['travel_date'])) . ' at ' . date('g:i A', strtotime($request['travel_time'])),
                                    ],
                                    'links' => [
                                        ['label' => 'Open ride detail', 'href' => '/ridesync/pages/admin_ride_detail.php?id=' . (int) $request['ride_id']],
                                        ['label' => 'Open requester', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $request['requester_id']],
                                        ['label' => 'Open owner', 'href' => '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $request['owner_id']],
                                    ],
                                ]); ?>">Inspect</button></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($communityRequestRows) === 0): ?>
                            <tr><td colspan="7" class="admin-table-empty">No join requests found on this page.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($communityRequestPagination); ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'reports'): ?>
        <section class="admin-command-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Moderation</span>
                    <h2>User Reports</h2>
                </div>
                <div class="admin-table-tools">
                    <input type="search" placeholder="Filter reports" aria-label="Filter reports panel" data-admin-panel-search="reportsPanel" data-search-context="reportsPanel">
                </div>
            </div>
            <?php if (count($reportRows) === 0): ?>
                <div class="driver-empty-card">No active reports. System trust health is stable.</div>
            <?php else: ?>
                <div class="admin-report-list" id="reportsPanel">
                    <?php foreach ($reportRows as $report): ?>
                        <?php $reportSearch = ridesync_admin_search_blob([$report['id'], $report['reason'], $report['report_status'], $report['reporter_name'], $report['reported_name'], $report['origin'], $report['destination'], $report['message'], $report['admin_note'] ?? '']); ?>
                        <article class="admin-report-card" data-search="<?php echo htmlspecialchars($reportSearch); ?>">
                            <div class="admin-review-top">
                                <div>
                                    <span class="badge badge-<?php echo htmlspecialchars(ridesync_admin_status_class($report['report_status'])); ?>"><?php echo htmlspecialchars(ridesync_admin_status_label($report['report_status'])); ?></span>
                                    <h3><?php echo htmlspecialchars(ridesync_admin_status_label($report['reason'])); ?></h3>
                                    <p>From <?php echo htmlspecialchars($report['reporter_name']); ?><?php echo !empty($report['reported_name']) ? ' against ' . htmlspecialchars($report['reported_name']) : ''; ?></p>
                                </div>
                                <?php if (!empty($report['origin'])): ?>
                                    <strong><?php echo htmlspecialchars($report['origin']); ?> &rarr; <?php echo htmlspecialchars($report['destination']); ?></strong>
                                <?php endif; ?>
                            </div>
                            <p class="admin-message"><?php echo nl2br(htmlspecialchars($report['message'])); ?></p>
                            <div class="admin-note-panel compact">
                                <div>
                                    <strong>Internal notes</strong>
                                    <span><?php echo count($reportNotesById[(int) $report['id']] ?? []); ?> recent</span>
                                </div>
                                <?php foreach (($reportNotesById[(int) $report['id']] ?? []) as $note): ?>
                                    <p><b><?php echo htmlspecialchars(ridesync_admin_status_label($note['note_type'] ?? 'general')); ?></b> <?php echo htmlspecialchars($note['note_text']); ?> <small><?php echo htmlspecialchars($note['admin_name'] ?: 'Admin'); ?>, <?php echo htmlspecialchars(date('M j, g:i A', strtotime((string) $note['created_at']))); ?></small></p>
                                <?php endforeach; ?>
                                <?php if (ridesync_admin_notes_schema_ready($conn)): ?>
                                    <form action="/ridesync/actions/admin_action.php" method="POST" class="admin-note-form">
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action_type" value="admin_note_create">
                                        <input type="hidden" name="entity_type" value="report">
                                        <input type="hidden" name="entity_id" value="<?php echo (int) $report['id']; ?>">
                                        <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=reports">
                                        <select name="note_type" aria-label="Note type">
                                            <option value="general">General</option>
                                            <option value="risk">Risk</option>
                                            <option value="support">Support</option>
                                            <option value="compliance">Compliance</option>
                                        </select>
                                        <input type="text" name="note_text" maxlength="2000" placeholder="Add internal note" required>
                                        <button type="submit" class="btn btn-secondary btn-sm">Add</button>
                                    </form>
                                <?php else: ?>
                                    <small>Run schema upgrade to enable persistent internal notes.</small>
                                <?php endif; ?>
                            </div>
                            <?php if (in_array($report['report_status'], ['open', 'reviewing'], true)): ?>
                                <form action="/ridesync/actions/admin_action.php" method="POST" class="admin-report-form">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action_type" value="report_decision">
                                    <input type="hidden" name="report_id" value="<?php echo (int) $report['id']; ?>">
                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=reports">
                                    <select name="decision" aria-label="Report decision" required>
                                        <option value="reviewing" <?php echo $report['report_status'] === 'reviewing' ? 'selected' : ''; ?>>Reviewing</option>
                                        <option value="resolved">Resolved</option>
                                        <option value="dismissed">Dismissed</option>
                                    </select>
                                    <input type="text" name="admin_note" maxlength="255" placeholder="Internal note" aria-label="Internal admin note" value="<?php echo htmlspecialchars($report['admin_note'] ?? ''); ?>">
                                    <button type="submit" class="btn btn-primary btn-sm">Update</button>
                                </form>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
            <?php ridesync_admin_render_pagination($reportPagination); ?>
        </section>
    <?php endif; ?>


    <?php if ($section === 'audit'): ?>
        <section class="admin-command-card admin-table-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Audit Explorer</span>
                    <h2>Administrative Activity</h2>
                </div>
                <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(ridesync_admin_current_url(['export' => 'audit_csv'])); ?>">Export CSV</a>
            </div>
            <div class="admin-audit-summary-grid" aria-label="Audit summary">
                <div><span>Events 24h</span><strong><?php echo (int) ($auditSummary['total_24h'] ?? 0); ?></strong></div>
                <div><span>Denied/Sensitive</span><strong><?php echo (int) ($auditSummary['denied_24h'] ?? 0); ?></strong></div>
                <div><span>Bulk Actions</span><strong><?php echo (int) ($auditSummary['bulk_24h'] ?? 0); ?></strong></div>
                <div><span>Active Admins</span><strong><?php echo (int) ($auditSummary['unique_admins_24h'] ?? 0); ?></strong></div>
            </div>
            <form class="admin-filter-panel" action="/ridesync/pages/admin_dashboard.php" method="GET">
                <input type="hidden" name="section" value="audit">
                <label>
                    <span>Action</span>
                    <input type="search" name="audit_action" maxlength="80" value="<?php echo htmlspecialchars($auditFilters['action'] ?? ''); ?>" placeholder="admin_bulk, report">
                </label>
                <label>
                    <span>Entity</span>
                    <input type="search" name="audit_entity" maxlength="80" value="<?php echo htmlspecialchars($auditFilters['entity'] ?? ''); ?>" placeholder="driver_account">
                </label>
                <label>
                    <span>IP</span>
                    <input type="search" name="audit_ip" maxlength="64" value="<?php echo htmlspecialchars($auditFilters['ip'] ?? ''); ?>" placeholder="127.0.0.1">
                </label>
                <label>
                    <span>Admin ID</span>
                    <input type="number" min="1" name="audit_admin" value="<?php echo (int) ($auditFilters['admin_id'] ?? 0) > 0 ? (int) $auditFilters['admin_id'] : ''; ?>">
                </label>
                <label>
                    <span>From</span>
                    <input type="date" name="audit_from" value="<?php echo htmlspecialchars($auditFilters['from'] ?? ''); ?>">
                </label>
                <label>
                    <span>To</span>
                    <input type="date" name="audit_to" value="<?php echo htmlspecialchars($auditFilters['to'] ?? ''); ?>">
                </label>
                <div class="admin-filter-actions">
                    <button type="submit" class="btn btn-primary btn-sm">Apply</button>
                    <a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_dashboard.php?section=audit">Reset</a>
                </div>
            </form>
            <div class="admin-table-wrap">
                <table class="admin-smart-table admin-audit-table" id="auditTable">
                    <thead><tr><th>Time</th><th>Admin</th><th>Action</th><th>Entity</th><th>Message</th><th>Source</th></tr></thead>
                    <tbody>
                        <?php foreach ($auditRows as $row): ?>
                            <?php $auditSearch = ridesync_admin_search_blob([$row['admin_name'], $row['admin_email'], $row['action'], $row['entity_type'], $row['message'], $row['source_ip']]); ?>
                            <tr data-search="<?php echo htmlspecialchars($auditSearch); ?>">
                                <td><strong><?php echo htmlspecialchars(date('M j', strtotime((string) $row['created_at']))); ?></strong><span><?php echo htmlspecialchars(date('g:i:s A', strtotime((string) $row['created_at']))); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($row['admin_name'] ?: 'System'); ?></strong><span><?php echo htmlspecialchars($row['admin_email'] ?: 'No admin account'); ?></span></td>
                                <td><span class="badge badge-pending"><?php echo htmlspecialchars(ridesync_admin_status_label($row['action'])); ?></span></td>
                                <td><strong><?php echo htmlspecialchars($row['entity_type']); ?></strong><span><?php echo $row['entity_id'] !== null ? '#' . (int) $row['entity_id'] : 'No entity id'; ?></span></td>
                                <td><?php echo htmlspecialchars($row['message'] ?: 'No message'); ?></td>
                                <td><strong><?php echo htmlspecialchars($row['source_ip'] ?: 'Unknown IP'); ?></strong><span><?php echo htmlspecialchars($row['user_agent'] ? substr((string) $row['user_agent'], 0, 80) : 'No user agent'); ?></span></td>
                            </tr>
                        <?php endforeach; ?>
                        <?php if (count($auditRows) === 0): ?>
                            <tr><td colspan="6" class="admin-table-empty">No audit records match the current filters.</td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
            <?php ridesync_admin_render_pagination($auditPagination); ?>
        </section>
    <?php endif; ?>

    <?php if ($section === 'bulk'): ?>
        <section class="admin-command-card">
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Bulk Operations</span>
                    <h2>Safeguarded System Cleanup</h2>
                </div>
                <span>Super admin only</span>
            </div>
            <p class="admin-message">Bulk actions are capped, auditable, and require the exact confirmation phrase. They do not delete accounts or documents.</p>
            <div class="admin-bulk-grid">
                <?php foreach ($bulkOperations as $operationKey => $operation): ?>
                    <article class="admin-bulk-card is-<?php echo htmlspecialchars($operation['severity'] ?? 'warning'); ?>">
                        <div class="admin-bulk-card-head">
                            <div>
                                <span><?php echo htmlspecialchars($operation['kicker']); ?></span>
                                <strong><?php echo htmlspecialchars($operation['title']); ?></strong>
                            </div>
                            <b><?php echo (int) $operation['count']; ?></b>
                        </div>
                        <p><?php echo htmlspecialchars($operation['description']); ?></p>
                        <form action="/ridesync/actions/admin_action.php" method="POST" data-confirm-message="Run this guarded bulk operation?">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action_type" value="admin_bulk_operation">
                            <input type="hidden" name="bulk_operation" value="<?php echo htmlspecialchars($operationKey); ?>">
                            <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=bulk">
                            <label>
                                <span>Confirmation</span>
                                <input type="text" name="confirmation_text" maxlength="8" placeholder="RUN BULK" autocomplete="off" required>
                            </label>
                            <button type="submit" class="btn <?php echo ($operation['severity'] ?? '') === 'critical' ? 'btn-danger' : 'btn-primary'; ?> btn-sm" <?php echo (int) $operation['count'] === 0 ? 'disabled' : ''; ?>>
                                <?php echo htmlspecialchars($operation['button']); ?>
                            </button>
                        </form>
                    </article>
                <?php endforeach; ?>
            </div>
        </section>
    <?php endif; ?>


    <?php if ($section === 'services'): ?>
        <?php
            $servicesSummary = $servicesSnapshot['summary'] ?? [];
            $services = $servicesSnapshot['services'] ?? [];
            $serviceAlerts = $servicesSnapshot['alerts'] ?? [];
            $serviceWorkflows = $servicesSnapshot['workflows'] ?? [];
            $serviceApiChecks = $servicesSnapshot['api_checks'] ?? [];
            $serviceQueues = $servicesSnapshot['queues'] ?? [];
            $serviceLogs = $servicesSnapshot['logs'] ?? [];
            $serviceIncidents = $servicesSnapshot['incidents'] ?? [];
            $serviceAlertRules = $servicesSnapshot['alert_rules'] ?? [];
            $repairSummary = $repairKitSnapshot['summary'] ?? [];
            $repairFindings = $repairKitSnapshot['findings'] ?? [];
            $repairActions = $repairKitSnapshot['actions'] ?? [];
            $repairRuns = $repairKitSnapshot['recent_runs'] ?? [];
            $repairRuntime = $repairKitSnapshot['runtime'] ?? [];
            $repairCheckpoint = $repairKitSnapshot['checkpoint'] ?? [];
        ?>
        <section class="admin-services-shell" data-admin-services>
            <div class="admin-card-head">
                <div>
                    <span class="driver-kicker">Services</span>
                    <h2>AI Operations Monitor</h2>
                </div>
                <span class="admin-live-pill"><span></span> <b data-service-last-refresh><?php echo htmlspecialchars(date('g:i:s A')); ?></b></span>
            </div>

            <section class="admin-priority-grid admin-services-summary" aria-label="AI service summary">
                <article class="admin-op-card <?php echo ($servicesSummary['status'] ?? '') === 'critical' ? 'is-danger' : (($servicesSummary['status'] ?? '') === 'degraded' ? 'is-warning' : 'is-primary'); ?>">
                    <span>Service State</span>
                    <strong data-service-summary="status_label"><?php echo htmlspecialchars($servicesSummary['status_label'] ?? 'Unknown'); ?></strong>
                    <small><b data-service-summary="current_uptime_percent"><?php echo htmlspecialchars((string) ($servicesSummary['current_uptime_percent'] ?? 0)); ?></b>% current uptime</small>
                </article>
                <article class="admin-op-card">
                    <span>AI Requests</span>
                    <strong data-service-summary="ai_requests_24h"><?php echo (int) ($servicesSummary['ai_requests_24h'] ?? 0); ?></strong>
                    <small><b data-service-summary="ai_responses_24h"><?php echo (int) ($servicesSummary['ai_responses_24h'] ?? 0); ?></b> responses in 24h</small>
                </article>
                <article class="admin-op-card">
                    <span>API Checks</span>
                    <strong data-service-summary="api_checks_24h"><?php echo (int) ($servicesSummary['api_checks_24h'] ?? 0); ?></strong>
                    <small>Provider validations in 24h</small>
                </article>
                <article class="admin-op-card <?php echo (int) ($servicesSummary['failed_jobs'] ?? 0) > 0 ? 'is-warning' : ''; ?>">
                    <span>Failed Jobs</span>
                    <strong data-service-summary="failed_jobs"><?php echo (int) ($servicesSummary['failed_jobs'] ?? 0); ?></strong>
                    <small>Background recovery queue</small>
                </article>
                <article class="admin-op-card <?php echo (int) ($servicesSummary['alerts_total'] ?? 0) > 0 ? 'is-danger' : ''; ?>">
                    <span>Alerts</span>
                    <strong data-service-summary="alerts_total"><?php echo (int) ($servicesSummary['alerts_total'] ?? 0); ?></strong>
                    <small><b data-service-summary="runtime_errors_24h"><?php echo (int) ($servicesSummary['runtime_errors_24h'] ?? 0); ?></b> runtime errors</small>
                </article>
            </section>

            <section id="repair-kit" class="admin-repair-kit-shell" data-repair-kit aria-labelledby="repairKitTitle">
                <div class="admin-card-head admin-repair-kit-head">
                    <div>
                        <span class="driver-kicker">Repair Kit</span>
                        <h2 id="repairKitTitle">Autonomous Recovery Console</h2>
                    </div>
                    <span class="admin-live-pill"><span></span> Super Admin</span>
                </div>

                <?php if (!$canRepairPlatform): ?>
                    <article class="admin-risk-card is-critical admin-repair-locked">
                        <span>Restricted</span>
                        <strong>Repair Kit requires Super Admin access</strong>
                        <p>Diagnostics are visible only to operators who can run guarded recovery, maintenance, rollback, and infrastructure handoff workflows.</p>
                    </article>
                <?php else: ?>
                    <section class="admin-repair-summary-grid" aria-label="Repair Kit health summary">
                        <article class="admin-op-card <?php echo ($repairSummary['status'] ?? '') === 'critical' ? 'is-danger' : (($repairSummary['status'] ?? '') === 'degraded' ? 'is-warning' : 'is-primary'); ?>">
                            <span>Repair Score</span>
                            <strong data-repair-summary="repair_score"><?php echo (int) ($repairSummary['repair_score'] ?? 0); ?></strong>
                            <small><?php echo htmlspecialchars($repairSummary['status_label'] ?? 'Unknown'); ?></small>
                        </article>
                        <article class="admin-op-card">
                            <span>Critical Findings</span>
                            <strong data-repair-summary="critical_findings"><?php echo (int) ($repairSummary['critical_findings'] ?? 0); ?></strong>
                            <small><?php echo (int) ($repairSummary['warning_findings'] ?? 0); ?> warnings</small>
                        </article>
                        <article class="admin-op-card">
                            <span>Recommended Fixes</span>
                            <strong data-repair-summary="recommended_actions"><?php echo (int) ($repairSummary['recommended_actions'] ?? 0); ?></strong>
                            <small>guarded actions available</small>
                        </article>
                        <article class="admin-op-card">
                            <span>Runtime Memory</span>
                            <strong><?php echo htmlspecialchars((string) ($repairRuntime['php_memory_mb'] ?? 0)); ?> MB</strong>
                            <small><?php echo $repairRuntime['disk_free_percent'] !== null ? htmlspecialchars((string) $repairRuntime['disk_free_percent']) . '% disk free' : 'disk unknown'; ?></small>
                        </article>
                        <article class="admin-op-card <?php echo empty($repairKitSnapshot['schema_ready']) ? 'is-warning' : ''; ?>">
                            <span>Encrypted Logs</span>
                            <strong><?php echo !empty($repairKitSnapshot['schema_ready']) ? 'Ready' : 'Migration'; ?></strong>
                            <small><?php echo count($repairRuns); ?> recent recovery run(s)</small>
                        </article>
                    </section>

                    <section class="admin-command-grid admin-repair-grid">
                        <article class="admin-command-card admin-repair-scanner">
                            <div class="admin-card-head">
                                <div>
                                    <span class="driver-kicker">Global Scanner</span>
                                    <h2>Infrastructure Findings</h2>
                                </div>
                                <span><?php echo count($repairFindings); ?> signals</span>
                            </div>
                            <div class="admin-repair-finding-list" data-repair-findings>
                                <?php if (empty($repairFindings)): ?>
                                    <div class="admin-feed-item">
                                        <div>
                                            <strong>No critical repair findings</strong>
                                            <p>Core services, schema, queues, storage, security settings, and AI diagnostics are within current scanner thresholds.</p>
                                            <small><?php echo htmlspecialchars(date('M j, g:i A')); ?></small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach (array_slice($repairFindings, 0, 10) as $finding): ?>
                                        <div class="admin-repair-finding is-<?php echo htmlspecialchars($finding['severity'] ?? 'info'); ?>">
                                            <span><?php echo htmlspecialchars(ridesync_admin_status_label($finding['area'] ?? 'System')); ?></span>
                                            <strong><?php echo htmlspecialchars($finding['title'] ?? 'Repair finding'); ?></strong>
                                            <p><?php echo htmlspecialchars($finding['detail'] ?? 'Review this repair signal.'); ?></p>
                                            <small><?php echo htmlspecialchars(ridesync_admin_status_label($finding['action_key'] ?? 'deep_scan')); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>

                        <article class="admin-command-card admin-repair-actions-card">
                            <div class="admin-card-head">
                                <div>
                                    <span class="driver-kicker">Auto Repair</span>
                                    <h2>Guarded Recovery Controls</h2>
                                </div>
                                <span>RBAC + audit</span>
                            </div>
                            <div class="admin-repair-actions">
                                <?php foreach ($repairActions as $repairAction): ?>
                                    <?php
                                        $confirmationPhrase = (string) ($repairAction['confirmation'] ?? '');
                                        $isCriticalRepair = ($repairAction['severity'] ?? '') === 'critical';
                                    ?>
                                    <form action="/ridesync/actions/admin_action.php" method="POST"
                                          <?php if ($confirmationPhrase !== ''): ?>
                                              data-confirm-message="<?php echo htmlspecialchars('Run Repair Kit action: ' . ($repairAction['label'] ?? 'repair') . '?'); ?>"
                                              data-confirm-phrase="<?php echo htmlspecialchars($confirmationPhrase); ?>"
                                          <?php endif; ?>>
                                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                        <input type="hidden" name="action_type" value="admin_repair_kit_execute">
                                        <input type="hidden" name="repair_operation" value="<?php echo htmlspecialchars($repairAction['key'] ?? 'deep_scan'); ?>">
                                        <input type="hidden" name="confirmation_text" value="">
                                        <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=services#repair-kit">
                                        <button type="submit" class="admin-repair-action <?php echo !empty($repairAction['recommended']) ? 'is-recommended' : ''; ?> <?php echo $isCriticalRepair ? 'is-critical' : ''; ?>">
                                            <span><?php echo htmlspecialchars($repairAction['category'] ?? 'Repair'); ?></span>
                                            <strong><?php echo htmlspecialchars($repairAction['label'] ?? 'Repair action'); ?></strong>
                                            <small><?php echo htmlspecialchars($repairAction['description'] ?? 'Run guarded repair workflow.'); ?></small>
                                        </button>
                                    </form>
                                <?php endforeach; ?>
                            </div>
                        </article>
                    </section>

                    <section class="admin-command-grid is-lower admin-repair-lower-grid">
                        <article class="admin-command-card">
                            <div class="admin-card-head">
                                <div>
                                    <span class="driver-kicker">Recovery Logs</span>
                                    <h2>Encrypted Repair Audit</h2>
                                </div>
                                <span><?php echo count($repairRuns); ?> latest</span>
                            </div>
                            <div class="admin-repair-log-list" data-repair-runs>
                                <?php if (empty($repairRuns)): ?>
                                    <div class="admin-feed-item">
                                        <div>
                                            <strong>No Repair Kit runs yet</strong>
                                            <p>Recovery actions will appear here with encrypted payloads, hashes, checkpoints, and admin attribution.</p>
                                            <small>Waiting for first run</small>
                                        </div>
                                    </div>
                                <?php else: ?>
                                    <?php foreach ($repairRuns as $run): ?>
                                        <?php $runResult = is_array($run['result'] ?? null) ? $run['result'] : []; ?>
                                        <div class="admin-repair-log-row is-<?php echo htmlspecialchars($run['status'] ?? 'queued'); ?>">
                                            <div>
                                                <span><?php echo htmlspecialchars(strtoupper((string) ($run['status'] ?? 'queued'))); ?></span>
                                                <strong><?php echo htmlspecialchars(ridesync_admin_status_label($run['action_key'] ?? 'repair')); ?></strong>
                                                <p><?php echo htmlspecialchars($runResult['message'] ?? 'Encrypted recovery record captured.'); ?></p>
                                            </div>
                                            <small><?php echo htmlspecialchars(!empty($run['created_at']) ? date('M j, g:i A', strtotime((string) $run['created_at'])) : ''); ?> / <?php echo htmlspecialchars(substr((string) ($run['log_hash'] ?? ''), 0, 12)); ?></small>
                                        </div>
                                    <?php endforeach; ?>
                                <?php endif; ?>
                            </div>
                        </article>

                        <article class="admin-command-card admin-repair-checkpoint-card">
                            <div class="admin-card-head">
                                <div>
                                    <span class="driver-kicker">Rollback Safety</span>
                                    <h2>Latest Checkpoint</h2>
                                </div>
                                <span><?php echo htmlspecialchars(!empty($repairCheckpoint['created_at']) ? date('g:i A', strtotime((string) $repairCheckpoint['created_at'])) : 'now'); ?></span>
                            </div>
                            <div class="admin-health-stack">
                                <?php foreach (($repairCheckpoint['tables'] ?? []) as $checkpointKey => $checkpointValue): ?>
                                    <div><span><?php echo htmlspecialchars(ridesync_admin_status_label($checkpointKey)); ?></span><strong><?php echo htmlspecialchars((string) $checkpointValue); ?></strong></div>
                                <?php endforeach; ?>
                            </div>
                            <p class="admin-message">Before every repair operation, the module records operational counts, environment safety state, encrypted log payload, and immutable hash for audit correlation.</p>
                        </article>

                        <article class="admin-command-card admin-repair-godmode">
                            <div class="admin-card-head">
                                <div>
                                    <span class="driver-kicker">God Mode</span>
                                    <h2>Emergency Architecture</h2>
                                </div>
                                <span>safe handoff</span>
                            </div>
                            <div class="admin-repair-godmode-list">
                                <div><strong>Host restarts</strong><span>Queued to ops workers, never executed directly by PHP web requests.</span></div>
                                <div><strong>Rollback</strong><span>Audited rollback hooks are created as background jobs for deployment automation.</span></div>
                                <div><strong>Kill switches</strong><span>Feature flags can pause unstable modules without deleting data.</span></div>
                                <div><strong>AI recovery</strong><span>Failed sessions are retried and service health snapshots are rebuilt.</span></div>
                            </div>
                        </article>
                    </section>
                <?php endif; ?>
            </section>

            <section class="admin-service-alerts" data-service-alerts aria-live="polite">
                <?php if (count($serviceAlerts) === 0): ?>
                    <article class="admin-risk-card is-healthy">
                        <span>Healthy</span>
                        <strong>No active service alerts</strong>
                        <p>All monitored AI and operations services are within current thresholds.</p>
                    </article>
                <?php else: ?>
                    <?php foreach ($serviceAlerts as $alert): ?>
                        <article class="admin-risk-card is-<?php echo htmlspecialchars(($alert['severity'] ?? '') === 'critical' ? 'critical' : 'warning'); ?>">
                            <span><?php echo htmlspecialchars(ucfirst((string) ($alert['severity'] ?? 'warning'))); ?></span>
                            <strong><?php echo htmlspecialchars($alert['title'] ?? 'Service alert'); ?></strong>
                            <p><?php echo htmlspecialchars($alert['detail'] ?? 'Review service details.'); ?></p>
                        </article>
                    <?php endforeach; ?>
                <?php endif; ?>
            </section>

            <section class="admin-command-grid">
                <article class="admin-command-card">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Service Matrix</span>
                            <h2>Integrated APIs and Workers</h2>
                        </div>
                        <span><?php echo count($services); ?> checks</span>
                    </div>
                    <div class="admin-service-grid" data-service-list>
                        <?php foreach ($services as $service): ?>
                            <?php
                                $status = (string) ($service['status'] ?? 'unknown');
                                $serviceMetrics = $service['metrics'] ?? [];
                                $serviceDetails = $service['details'] ?? [];
                                $serviceSearch = ridesync_admin_search_blob([$service['name'] ?? '', $service['group'] ?? '', $status, $service['summary'] ?? '']);
                            ?>
                            <article class="admin-service-tile is-<?php echo htmlspecialchars($status); ?>" data-search="<?php echo htmlspecialchars($serviceSearch); ?>" data-service-key="<?php echo htmlspecialchars($service['key'] ?? 'service'); ?>">
                                <div>
                                    <span><?php echo htmlspecialchars($service['group'] ?? 'Service'); ?></span>
                                    <strong><?php echo htmlspecialchars($service['name'] ?? 'Service'); ?></strong>
                                </div>
                                <span class="badge badge-<?php echo htmlspecialchars($status === 'operational' ? 'accepted' : ($status === 'down' || $status === 'critical' ? 'rejected' : 'pending')); ?>"><?php echo htmlspecialchars($service['status_label'] ?? 'Unknown'); ?></span>
                                <p><?php echo htmlspecialchars($service['summary'] ?? 'No summary available.'); ?></p>
                                <dl>
                                    <div><dt>Latency</dt><dd><?php echo isset($service['latency_ms']) && $service['latency_ms'] !== null ? htmlspecialchars((string) $service['latency_ms']) . ' ms' : 'Not measured'; ?></dd></div>
                                    <div><dt>Uptime</dt><dd><?php echo isset($service['uptime_percent']) && $service['uptime_percent'] !== null ? htmlspecialchars((string) $service['uptime_percent']) . '%' : 'Not tracked'; ?></dd></div>
                                    <?php foreach (array_slice($serviceMetrics, 0, 4) as $metricKey => $metricValue): ?>
                                        <div><dt><?php echo htmlspecialchars(ridesync_admin_status_label($metricKey)); ?></dt><dd><?php echo htmlspecialchars(is_bool($metricValue) ? ($metricValue ? 'yes' : 'no') : (is_scalar($metricValue) ? (string) $metricValue : 'details')); ?></dd></div>
                                    <?php endforeach; ?>
                                </dl>
                                <button type="button" class="btn btn-secondary btn-sm admin-inspect-btn" data-admin-drawer="<?php echo ridesync_admin_drawer_attr([
                                    'kicker' => 'Service',
                                    'title' => $service['name'] ?? 'Service',
                                    'fields' => array_merge([
                                        'Status' => $service['status_label'] ?? 'Unknown',
                                        'Group' => $service['group'] ?? 'Service',
                                        'Summary' => $service['summary'] ?? '',
                                        'Latency' => isset($service['latency_ms']) && $service['latency_ms'] !== null ? $service['latency_ms'] . ' ms' : 'Not measured',
                                        'Checked At' => $service['checked_at'] ?? '',
                                    ], $serviceMetrics, $serviceDetails),
                                ]); ?>">Inspect</button>
                            </article>
                        <?php endforeach; ?>
                    </div>
                </article>

                <article class="admin-command-card">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Recovery</span>
                            <h2>Queue Controls</h2>
                        </div>
                        <span><?php echo (int) ($serviceQueues['queued'] ?? 0); ?> queued</span>
                    </div>
                    <div class="admin-health-stack" data-service-queues>
                        <div><span>Processing</span><strong><?php echo (int) ($serviceQueues['processing'] ?? 0); ?></strong></div>
                        <div><span>Stale Processing</span><strong><?php echo (int) ($serviceQueues['stale_processing'] ?? 0); ?></strong></div>
                        <div><span>Failed Verification</span><strong><?php echo (int) ($serviceQueues['failed_verification'] ?? 0); ?></strong></div>
                        <div><span>Succeeded 24h</span><strong><?php echo (int) ($serviceQueues['succeeded_24h'] ?? 0); ?></strong></div>
                    </div>
                    <div class="admin-service-actions">
                        <form action="/ridesync/actions/admin_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action_type" value="admin_services_release_timeouts">
                            <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=services">
                            <button type="submit" class="btn btn-secondary btn-sm">Release stale jobs</button>
                        </form>
                        <form action="/ridesync/actions/admin_action.php" method="POST">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                            <input type="hidden" name="action_type" value="admin_services_retry_failed_verifications">
                            <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=services">
                            <button type="submit" class="btn btn-primary btn-sm">Retry failed AI jobs</button>
                        </form>
                    </div>
                </article>
            </section>

            <section class="admin-command-grid is-lower">
                <article class="admin-command-card">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Incident Center</span>
                            <h2>Active Failures and Instability</h2>
                        </div>
                        <span><?php echo count($serviceIncidents); ?> incidents</span>
                    </div>
                    <div class="admin-incident-list">
                        <?php if (count($serviceIncidents) === 0): ?>
                            <div class="driver-empty-card">No active incidents detected by service monitors.</div>
                        <?php else: ?>
                            <?php foreach (array_slice($serviceIncidents, 0, 8) as $incident): ?>
                                <article class="admin-incident-card is-<?php echo htmlspecialchars($incident['severity'] ?? 'warning'); ?>">
                                    <div>
                                        <span><?php echo htmlspecialchars(ridesync_admin_status_label($incident['source'] ?? 'service')); ?></span>
                                        <strong><?php echo htmlspecialchars($incident['title'] ?? 'Incident'); ?></strong>
                                    </div>
                                    <p><?php echo htmlspecialchars($incident['detail'] ?? 'Review operational telemetry.'); ?></p>
                                    <small><?php echo htmlspecialchars(!empty($incident['created_at']) ? date('M j, g:i A', strtotime((string) $incident['created_at'])) : date('M j, g:i A')); ?></small>
                                    <?php if (!empty($incident['driver_id'])): ?>
                                        <a class="btn btn-secondary btn-sm" href="/ridesync/pages/admin_driver_verification.php?driver_id=<?php echo (int) $incident['driver_id']; ?>">Open driver</a>
                                    <?php endif; ?>
                                </article>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </div>
                </article>

                <article class="admin-command-card">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">AI Flow</span>
                            <h2>Verification Processing</h2>
                        </div>
                    </div>
                    <div class="admin-health-stack" data-service-workflows>
                        <div><span>Queued</span><strong><?php echo (int) ($serviceWorkflows['queued'] ?? 0); ?></strong></div>
                        <div><span>Processing</span><strong><?php echo (int) ($serviceWorkflows['processing'] ?? 0); ?></strong></div>
                        <div><span>Slow</span><strong><?php echo (int) ($serviceWorkflows['slow_processing'] ?? 0); ?></strong></div>
                        <div><span>Avg Time</span><strong><?php echo htmlspecialchars((string) ($serviceWorkflows['avg_processing_ms'] ?? 0)); ?> ms</strong></div>
                        <div><span>Token Usage</span><strong><?php echo (int) ($serviceWorkflows['token_usage_24h'] ?? 0); ?></strong></div>
                    </div>
                </article>

                <article class="admin-command-card">
                    <div class="admin-card-head">
                        <div>
                            <span class="driver-kicker">Provider APIs</span>
                            <h2>Validation Outcomes</h2>
                        </div>
                    </div>
                    <div class="admin-health-stack" data-service-api-checks>
                        <div><span>Passed 24h</span><strong><?php echo (int) ($serviceApiChecks['passed_24h'] ?? 0); ?></strong></div>
                        <div><span>Needs Review</span><strong><?php echo (int) ($serviceApiChecks['needs_review_24h'] ?? 0); ?></strong></div>
                        <div><span>Failed 24h</span><strong><?php echo (int) ($serviceApiChecks['failed_24h'] ?? 0); ?></strong></div>
                        <div><span>Total Checks</span><strong><?php echo (int) ($serviceApiChecks['checks_24h'] ?? 0); ?></strong></div>
                    </div>
                </article>
            </section>

            <section class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Alert Rules</span>
                        <h2>Service Thresholds</h2>
                    </div>
                    <span><?php echo count(array_filter($serviceAlertRules, static fn($rule) => !empty($rule['triggered']))); ?> firing</span>
                </div>
                <div class="admin-rule-list">
                    <?php foreach ($serviceAlertRules as $rule): ?>
                        <article class="admin-rule-row <?php echo !empty($rule['triggered']) ? 'is-triggered' : ''; ?>">
                            <div>
                                <span class="badge badge-<?php echo htmlspecialchars(($rule['severity'] ?? '') === 'critical' ? 'rejected' : 'pending'); ?>">
                                    <?php echo htmlspecialchars(ridesync_admin_status_label($rule['severity'] ?? 'warning')); ?>
                                </span>
                                <strong><?php echo htmlspecialchars($rule['label'] ?? 'Alert rule'); ?></strong>
                                <p><?php echo htmlspecialchars(($rule['metric_key'] ?? 'metric') . ' ' . ridesync_admin_status_label($rule['operator'] ?? 'greater_than') . ' ' . (float) ($rule['threshold'] ?? 0)); ?></p>
                            </div>
                            <dl>
                                <div><dt>Current</dt><dd><?php echo htmlspecialchars((string) ($rule['current_value'] ?? 0)); ?></dd></div>
                                <div><dt>State</dt><dd><?php echo !empty($rule['triggered']) ? 'Firing' : 'Normal'; ?></dd></div>
                            </dl>
                            <?php if ($canManageAlertRules && !empty($rule['id'])): ?>
                                <form action="/ridesync/actions/admin_action.php" method="POST">
                                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($_SESSION['csrf_token']); ?>">
                                    <input type="hidden" name="action_type" value="admin_alert_rule_toggle">
                                    <input type="hidden" name="rule_id" value="<?php echo (int) $rule['id']; ?>">
                                    <input type="hidden" name="enabled" value="<?php echo !empty($rule['enabled']) ? 0 : 1; ?>">
                                    <input type="hidden" name="return_to" value="/ridesync/pages/admin_dashboard.php?section=services">
                                    <button type="submit" class="btn btn-secondary btn-sm"><?php echo !empty($rule['enabled']) ? 'Disable' : 'Enable'; ?></button>
                                </form>
                            <?php else: ?>
                                <span class="badge badge-<?php echo !empty($rule['enabled']) ? 'accepted' : 'closed'; ?>"><?php echo !empty($rule['enabled']) ? 'Enabled' : 'Disabled'; ?></span>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            </section>

            <section class="admin-command-card">
                <div class="admin-card-head">
                    <div>
                        <span class="driver-kicker">Debug Log</span>
                        <h2>Recent Service Errors</h2>
                    </div>
                    <span><?php echo (int) ($serviceLogs['warnings_24h'] ?? 0); ?> warnings</span>
                </div>
                <div class="admin-feed-list admin-service-log-list" data-service-logs>
                    <?php if (empty($serviceLogs['recent'])): ?>
                        <div class="admin-feed-item">
                            <div>
                                <strong>No recent warning or error logs</strong>
                                <p>Runtime logs have no service-level warnings in the current window.</p>
                                <small><?php echo htmlspecialchars(date('M j, g:i A')); ?></small>
                            </div>
                        </div>
                    <?php else: ?>
                        <?php foreach ($serviceLogs['recent'] as $log): ?>
                            <div class="admin-feed-item">
                                <span class="admin-feed-dot badge-<?php echo htmlspecialchars(($log['level'] ?? '') === 'error' || ($log['level'] ?? '') === 'critical' ? 'rejected' : 'pending'); ?>"></span>
                                <div>
                                    <strong><?php echo htmlspecialchars(strtoupper((string) ($log['level'] ?? 'warning')) . ' - ' . ($log['message'] ?? 'Log event')); ?></strong>
                                    <p><?php echo htmlspecialchars($log['request_id'] ?? 'No request id'); ?></p>
                                    <small><?php echo htmlspecialchars($log['timestamp'] ?? ''); ?></small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </div>
            </section>
        </section>
    <?php endif; ?>

</div>

<aside class="admin-drawer" id="adminDrawer" aria-hidden="true">
    <div class="admin-drawer-panel">
        <button type="button" class="admin-drawer-close" data-admin-drawer-close aria-label="Close details">x</button>
        <span class="driver-kicker" data-admin-drawer-kicker>Inspect</span>
        <h2 data-admin-drawer-title>Details</h2>
        <div class="admin-drawer-body" data-admin-drawer-body></div>
    </div>
</aside>

<?php require_once __DIR__ . '/../includes/admin_footer.php'; ?>
