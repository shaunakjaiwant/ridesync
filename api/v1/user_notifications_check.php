<?php
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/http_helper.php';
require_once __DIR__ . '/../../includes/notification_helper.php';

ridesync_require_method('GET');

header('Content-Type: application/json; charset=utf-8');

$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;
$driverId = isset($_SESSION['driver_id']) ? (int) $_SESSION['driver_id'] : null;

if (!$userId && !$driverId) {
    http_response_code(401);
    echo json_encode(['ok' => false, 'error' => 'Authentication required']);
    exit();
}

session_write_close();

$column = $userId ? 'user_id' : 'driver_id';
$targetId = $userId ?: $driverId;
$sinceId = isset($_GET['since_id']) ? (int) $_GET['since_id'] : 0;

$unreadCount = ridesync_unread_notification_count($conn, $column, $targetId);

$latest = null;
if ($sinceId > 0) {
    $stmt = mysqli_prepare($conn, "SELECT id, title, message, is_read, created_at FROM notifications WHERE {$column} = ? AND id > ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "ii", $targetId, $sinceId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $latest = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
} else {
    $stmt = mysqli_prepare($conn, "SELECT id, title, message, is_read, created_at FROM notifications WHERE {$column} = ? ORDER BY id DESC LIMIT 1");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "i", $targetId);
        mysqli_stmt_execute($stmt);
        $res = mysqli_stmt_get_result($stmt);
        $latest = mysqli_fetch_assoc($res);
        mysqli_stmt_close($stmt);
    }
}

echo json_encode([
    'ok' => true,
    'unread_count' => $unreadCount,
    'latest_notification' => $latest,
]);
