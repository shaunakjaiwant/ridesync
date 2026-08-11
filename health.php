<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);
require_once __DIR__ . '/config/db.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');

global $conn;
$dbOk = $conn instanceof mysqli && @mysqli_ping($conn);

$statusCode = $dbOk ? 200 : 503;
http_response_code($statusCode);

echo json_encode([
    'status' => $dbOk ? 'ok' : 'degraded',
    'service' => 'ridesync-php-api',
    'environment' => function_exists('ridesync_app_env') ? ridesync_app_env() : 'production',
    'database' => $dbOk ? 'connected' : 'disconnected',
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
exit();
