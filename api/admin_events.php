<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/services/RealtimeEventService.php';
require_once __DIR__ . '/../includes/repositories/AdminMetricsRepository.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    http_response_code(403);
    exit();
}

ridesync_enforce_rate_limit('sse:admin_events', 60, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'message' => 'Too many admin live event connections. Please retry shortly.',
]);

session_write_close();

ridesync_sse_headers();
$lastEventId = max(0, (int) ($_GET['last_event_id'] ?? 0));

function ridesync_admin_event_latest($conn) {
    return RideSyncAdminMetricsRepository::latestOperationalEvent($conn);
}

for ($tick = 0; $tick < 12; $tick++) {
    if (connection_aborted()) {
        break;
    }

    $events = RideSyncRealtimeEventService::recentForAudience($conn, 'admin', null, $lastEventId, 25);
    if (!empty($events)) {
        $lastEventId = (int) end($events)['id'];
        reset($events);
    }

    ridesync_sse_event('admin', [
        'ok' => true,
        'metrics' => RideSyncAdminMetricsRepository::dashboardMetrics($conn),
        'latest' => ridesync_admin_event_latest($conn),
        'events' => $events,
        'server_time' => date('c'),
    ]);

    if ($tick < 11) {
        sleep(5);
    }
}
?>
