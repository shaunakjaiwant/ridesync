<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../includes/matching_helper.php';

ridesync_api_require_method('GET');
ridesync_api_enforce_rate_limit('api:v1:health', 60, 60, ridesync_client_ip());

$dbStarted = microtime(true);
$dbUp = $conn instanceof mysqli && mysqli_ping($conn);
$dbLatencyMs = $conn instanceof mysqli ? round((microtime(true) - $dbStarted) * 1000, 2) : null;

$schemaReady = false;
if ($conn instanceof mysqli) {
    $schemaReady = true;
    foreach (['users', 'rides', 'matches', 'driver_accounts', 'notifications', 'background_jobs', 'realtime_events', 'admin_users'] as $table) {
        if (!ridesync_table_exists($conn, $table)) {
            $schemaReady = false;
            break;
        }
    }
}

$healthy = $dbUp && $schemaReady && is_dir(ridesync_storage_path()) && is_writable(ridesync_storage_path());

ridesync_api_success([
    'service' => 'ridesync-web',
    'status' => $healthy ? 'healthy' : 'degraded',
    'environment' => ridesync_app_env(),
    'checks' => [
        'database' => $dbUp,
        'database_latency_ms' => $dbLatencyMs,
        'schema' => $schemaReady,
        'storage' => is_dir(ridesync_storage_path()) && is_writable(ridesync_storage_path()),
    ],
], $healthy ? 200 : 503);

