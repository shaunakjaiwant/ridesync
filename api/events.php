<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['driver_id'])) {
    http_response_code(401);
    exit();
}

$actorType = isset($_SESSION['driver_id']) ? 'driver' : 'user';
$actorId = $actorType === 'driver' ? (int) $_SESSION['driver_id'] : (int) $_SESSION['user_id'];
ridesync_enforce_rate_limit('sse:events', 60, 60, $actorType . ':' . $actorId, [
    'message' => 'Too many live event connections. Please retry shortly.',
]);

session_write_close();

ridesync_sse_headers();

function ridesync_event_count($conn, $actorType, $actorId) {
    if (!ridesync_table_exists($conn, 'notifications')) {
        return 0;
    }

    $column = $actorType === 'driver' ? 'driver_id' : 'user_id';
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE {$column} = ? AND is_read = 0");
    mysqli_stmt_bind_param($stmt, "i", $actorId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return (int) ($row['total'] ?? 0);
}

function ridesync_driver_pending_count($conn, $driverId) {
    if (!ridesync_table_exists($conn, 'driver_ride_requests')) {
        return 0;
    }

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM driver_ride_requests WHERE driver_id = ? AND request_status = 'pending'");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    return (int) ($row['total'] ?? 0);
}

for ($tick = 0; $tick < 12; $tick++) {
    if (connection_aborted()) {
        break;
    }

    $payload = [
        'ok' => true,
        'actor_type' => $actorType,
        'unread_notifications' => ridesync_event_count($conn, $actorType, $actorId),
        'server_time' => date('c'),
    ];

    if ($actorType === 'driver') {
        $payload['pending_driver_requests'] = ridesync_driver_pending_count($conn, $actorId);
    }

    ridesync_sse_event('ridesync', $payload);

    if ($tick < 11) {
        sleep(5);
    }
}
?>
