<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/http_helper.php';
require_once __DIR__ . '/../../includes/admin_helper.php';
require_once __DIR__ . '/../../includes/sos_helper.php';

ridesync_require_method('GET');

header('Content-Type: application/json; charset=utf-8');

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Admin authentication required']);
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    http_response_code(403);
    echo json_encode(['ok' => false, 'error' => 'Inactive or unauthorized admin account']);
    exit();
}

$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;
$activeAlerts = ridesync_get_active_sos_alerts($conn);

$activeCount = count($activeAlerts);
$latestAlertId = $activeCount > 0 ? (int) $activeAlerts[0]['id'] : 0;
$hasNew = $latestAlertId > 0 && $latestAlertId > $sinceId;

echo json_encode([
    'ok' => true,
    'active_count' => $activeCount,
    'latest_alert_id' => $latestAlertId,
    'has_new' => $hasNew,
    'alerts' => $activeAlerts,
]);
