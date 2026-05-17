<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';

$checks = [
    'database' => false,
    'schema' => false,
    'storage' => false,
    'logs' => false,
    'rate_limits' => false,
    'crypto' => function_exists('openssl_encrypt') && function_exists('random_bytes'),
];

if ($conn instanceof mysqli) {
    $checks['database'] = mysqli_ping($conn);
    $requiredTables = [
        'users',
        'rides',
        'matches',
        'driver_accounts',
        'driver_account_documents',
        'notifications',
        'admin_users',
        'audit_logs',
    ];
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
$logDir = ridesync_storage_path('logs');
$rateLimitDir = ridesync_storage_path('rate_limits');

$checks['storage'] = is_dir($storageDir) && is_writable($storageDir);
$checks['logs'] = ridesync_ensure_directory($logDir) && is_writable($logDir);
$checks['rate_limits'] = ridesync_ensure_directory($rateLimitDir) && is_writable($rateLimitDir);

$ready = !in_array(false, $checks, true);

ridesync_json_response([
    'ok' => $ready,
    'status' => $ready ? 'ready' : 'not_ready',
    'service' => 'ridesync-web',
    'environment' => ridesync_app_env(),
    'checks' => $checks,
    'timestamp' => date('c'),
], $ready ? 200 : 503);

?>
