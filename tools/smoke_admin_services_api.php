<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/services/ServiceObservabilityService.php';
require_once __DIR__ . '/../includes/services/RepairKitService.php';

$result = mysqli_query(
    $conn,
    "SELECT id, name, role
     FROM admin_users
     WHERE status = 'active'
     ORDER BY FIELD(role, 'super_admin', 'moderator'), id
     LIMIT 1"
);
$admin = $result ? mysqli_fetch_assoc($result) : null;
if (!$admin) {
    fwrite(STDERR, "No active admin available for service API smoke\n");
    exit(1);
}

$payload = RideSyncServiceObservabilityService::snapshot($conn, ['force' => true]);
$payload['repair_kit'] = ridesync_admin_can($admin, 'repair_platform')
    ? RideSyncRepairKitService::snapshot($conn, ['force' => true])
    : ['locked' => true];
$payload['admin'] = [
    'id' => (int) $admin['id'],
    'role' => (string) $admin['role'],
];

$ok = is_array($payload)
    && isset($payload['summary'], $payload['services'], $payload['repair_kit'])
    && is_array($payload['repair_kit'])
    && (isset($payload['repair_kit']['locked']) || isset($payload['repair_kit']['summary']))
    && !str_contains(json_encode($payload['repair_kit']['recent_runs'] ?? [], JSON_UNESCAPED_SLASHES), 'log_ciphertext');

echo $ok ? "[OK] admin services API returned service and repair payloads\n" : "[FAIL] admin services API payload invalid\n";
exit($ok ? 0 : 1);
?>
