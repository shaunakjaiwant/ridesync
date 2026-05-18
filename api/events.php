<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/services/RealtimeEventService.php';

ridesync_require_method('GET');

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
$lastEventId = max(0, (int) ($_GET['last_event_id'] ?? 0));

function ridesync_event_metrics($conn, $actorType, $actorId) {
    $actorId = (int) $actorId;
    if ($actorId <= 0) {
        return ['unread_notifications' => 0, 'pending_driver_requests' => 0];
    }

    try {
        if ($actorType === 'driver') {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT
                    (SELECT COUNT(*) FROM notifications WHERE driver_id = ? AND is_read = 0) AS unread_notifications,
                    (SELECT COUNT(*) FROM driver_ride_requests WHERE driver_id = ? AND request_status = 'pending') AS pending_driver_requests"
            );
            if (!$stmt) {
                return ['unread_notifications' => 0, 'pending_driver_requests' => 0];
            }
            mysqli_stmt_bind_param($stmt, "ii", $actorId, $actorId);
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT COUNT(*) AS unread_notifications
                 FROM notifications
                 WHERE user_id = ? AND is_read = 0"
            );
            if (!$stmt) {
                return ['unread_notifications' => 0, 'pending_driver_requests' => 0];
            }
            mysqli_stmt_bind_param($stmt, "i", $actorId);
        }

        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: [];
        return [
            'unread_notifications' => (int) ($row['unread_notifications'] ?? 0),
            'pending_driver_requests' => (int) ($row['pending_driver_requests'] ?? 0),
        ];
    } catch (Throwable $exception) {
        return ['unread_notifications' => 0, 'pending_driver_requests' => 0];
    }
}

for ($tick = 0; $tick < 12; $tick++) {
    if (connection_aborted()) {
        break;
    }

    $metrics = ridesync_event_metrics($conn, $actorType, $actorId);
    $payload = [
        'ok' => true,
        'actor_type' => $actorType,
        'unread_notifications' => $metrics['unread_notifications'],
        'server_time' => date('c'),
    ];

    $events = RideSyncRealtimeEventService::recentForAudience($conn, $actorType, $actorId, $lastEventId, 20);
    if (!empty($events)) {
        $payload['events'] = $events;
        $lastEventId = (int) end($events)['id'];
        reset($events);
    }

    if ($actorType === 'driver') {
        $payload['pending_driver_requests'] = $metrics['pending_driver_requests'];
    }

    ridesync_sse_event('ridesync', $payload);

    if ($tick < 11) {
        sleep(5);
    }
}
?>
