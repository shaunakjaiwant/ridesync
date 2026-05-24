<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/api_helper.php';
require_once __DIR__ . '/../../includes/matching_helper.php';
require_once __DIR__ . '/../../includes/driver_document_helper.php';

ridesync_api_require_method('GET');
ridesync_api_enforce_rate_limit('api:v1:readiness', 60, 60, ridesync_client_ip());

$checks = [
    'database' => false,
    'schema' => false,
    'storage' => false,
    'logs' => false,
    'rate_limits' => false,
    'crypto' => function_exists('openssl_encrypt') && function_exists('random_bytes'),
    'document_crypto' => ridesync_driver_document_crypto_ready(),
    'repair_log_crypto' => ridesync_app_env() !== 'production'
        || ridesync_base64_key_is_configured((string) ridesync_env('RIDESYNC_REPAIR_LOG_KEY', ''), 32),
    'secure_cookies' => ridesync_app_env() !== 'production'
        || ridesync_env_bool('RIDESYNC_COOKIE_SECURE', false),
    'metrics_token' => ridesync_app_env() !== 'production'
        || ridesync_env_secret_is_configured('RIDESYNC_METRICS_TOKEN', 16),
    'websocket_config' => ridesync_app_env() !== 'production'
        || (trim((string) ridesync_env('RIDESYNC_WEBSOCKET_URL', '')) !== ''
            && ridesync_env_secret_is_configured('RIDESYNC_WS_SHARED_TOKEN', 32)),
    'verification_service_config' => ridesync_app_env() !== 'production'
        || (trim((string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_URL', '')) !== ''
            && ridesync_env_secret_is_configured('RIDESYNC_VERIFICATION_SERVICE_TOKEN', 32)),
];

$details = [
    'database_latency_ms' => null,
    'missing_tables' => [],
];

if ($conn instanceof mysqli) {
    $started = microtime(true);
    $checks['database'] = mysqli_ping($conn);
    $details['database_latency_ms'] = round((microtime(true) - $started) * 1000, 2);

    $requiredTables = [
        'users',
        'rides',
        'matches',
        'driver_accounts',
        'driver_account_documents',
        'notifications',
        'background_jobs',
        'realtime_events',
        'admin_users',
        'audit_logs',
    ];
    $checks['schema'] = true;
    foreach ($requiredTables as $table) {
        if (!ridesync_table_exists($conn, $table)) {
            $checks['schema'] = false;
            $details['missing_tables'][] = $table;
        }
    }
}

$storageDir = ridesync_storage_path();
$logDir = ridesync_storage_path('logs');
$rateLimitDir = ridesync_storage_path('rate_limits');

$checks['storage'] = is_dir($storageDir) && is_writable($storageDir);
$checks['logs'] = ridesync_ensure_directory($logDir) && is_writable($logDir);
$checks['rate_limits'] = ridesync_ensure_directory($rateLimitDir) && is_writable($rateLimitDir);

$ready = !in_array(false, $checks, true);

ridesync_api_success([
    'service' => 'ridesync-web',
    'status' => $ready ? 'ready' : 'not_ready',
    'environment' => ridesync_app_env(),
    'checks' => $checks,
    'details' => $details,
], $ready ? 200 : 503);

