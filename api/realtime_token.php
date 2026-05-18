<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

ridesync_require_method('GET');

$audienceType = null;
$audienceId = 0;
$rateLimitKey = null;

if (isset($_SESSION['admin_id'])) {
    $admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
    if (!$admin || $admin['status'] !== 'active') {
        ridesync_error_response('Not allowed', 403);
    }
    $audienceType = 'admin';
    $audienceId = 0;
    $rateLimitKey = 'admin:' . (int) $_SESSION['admin_id'];
} elseif (isset($_SESSION['driver_id'])) {
    $audienceType = 'driver';
    $audienceId = (int) $_SESSION['driver_id'];
    $rateLimitKey = 'driver:' . $audienceId;
} elseif (isset($_SESSION['user_id'])) {
    $audienceType = 'user';
    $audienceId = (int) $_SESSION['user_id'];
    $rateLimitKey = 'user:' . $audienceId;
} else {
    ridesync_error_response('Not authenticated', 401);
}

ridesync_enforce_rate_limit('api:realtime_token', 60, 60, $rateLimitKey, [
    'json' => true,
    'message' => 'Too many realtime token requests. Please retry shortly.',
]);

$secret = trim((string) ridesync_env('RIDESYNC_WS_SHARED_TOKEN', ''));
$wsUrl = trim((string) ridesync_env('RIDESYNC_WEBSOCKET_URL', ''));
if (!ridesync_secret_is_configured($secret, 32) || $wsUrl === '') {
    ridesync_json_response([
        'ok' => true,
        'enabled' => false,
        'reason' => 'websocket_gateway_not_configured',
    ]);
}

$expiresAt = time() + 300;
$token = hash_hmac('sha256', $audienceType . ':' . $audienceId . ':' . $expiresAt, $secret);

ridesync_json_response([
    'ok' => true,
    'enabled' => true,
    'ws_url' => $wsUrl,
    'audience_type' => $audienceType,
    'audience_id' => $audienceId,
    'expires_at' => $expiresAt,
    'token' => $token,
]);

?>
