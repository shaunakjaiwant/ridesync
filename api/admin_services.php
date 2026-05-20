<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/services/ServiceObservabilityService.php';
require_once __DIR__ . '/../includes/services/RepairKitService.php';

ridesync_require_method('GET');

if (!isset($_SESSION['admin_id'])) {
    ridesync_error_response('Admin session is required', 401);
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || ($admin['status'] ?? '') !== 'active') {
    ridesync_error_response('Active admin account is required', 403);
}
if (!ridesync_admin_can($admin, 'run_ai_verification')) {
    ridesync_error_response('Service monitor access is not permitted for this admin role', 403);
}

ridesync_enforce_rate_limit('api:admin_services', 120, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'message' => 'Too many service monitor refreshes. Please retry shortly.',
]);

$force = trim((string) ($_GET['force'] ?? '')) === '1';
$snapshot = RideSyncServiceObservabilityService::snapshot($conn, ['force' => $force]);
$snapshot['repair_kit'] = ridesync_admin_can($admin, 'repair_platform')
    ? RideSyncRepairKitService::snapshot($conn, ['force' => $force])
    : ['locked' => true];
$snapshot['admin'] = [
    'id' => (int) $admin['id'],
    'role' => (string) $admin['role'],
];

ridesync_json_response($snapshot, 200);

?>
