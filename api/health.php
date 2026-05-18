<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';

ridesync_require_method('GET');

ridesync_enforce_rate_limit('api:health', 30, 60, ridesync_client_ip(), [
    'json' => true,
    'message' => 'Too many health checks. Please retry shortly.',
]);

$checks = [
    'database' => false,
    'schema' => false,
    'storage' => false,
];

if ($conn instanceof mysqli) {
    $checks['database'] = mysqli_ping($conn);
    $requiredTables = ['users', 'rides', 'matches', 'driver_accounts', 'notifications', 'background_jobs', 'realtime_events', 'admin_users'];
    $schemaReady = true;
    foreach ($requiredTables as $table) {
        if (!ridesync_table_exists($conn, $table)) {
            $schemaReady = false;
            break;
        }
    }
    $checks['schema'] = $schemaReady;
}

$storageDir = ridesync_storage_path();
$checks['storage'] = is_dir($storageDir) && is_writable($storageDir);

$healthy = !in_array(false, $checks, true);

ridesync_json_response([
    'ok' => $healthy,
    'status' => $healthy ? 'healthy' : 'degraded',
    'checks' => $checks,
    'timestamp' => date('c'),
], $healthy ? 200 : 503);

?>
