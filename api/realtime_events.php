<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/services/RealtimeEventService.php';

$audienceType = null;
$audienceId = null;
$rateLimitKey = null;

if (isset($_SESSION['admin_id'])) {
    $admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
    if (!$admin || $admin['status'] !== 'active') {
        ridesync_error_response('Not allowed', 403);
    }
    $audienceType = 'admin';
    $audienceId = null;
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

ridesync_enforce_rate_limit('api:realtime_events', 120, 60, $rateLimitKey, [
    'json' => true,
    'message' => 'Too many live event checks. Please slow down briefly.',
]);

$afterId = max(0, (int) ($_GET['after_id'] ?? 0));
$limit = max(1, min(100, (int) ($_GET['limit'] ?? 25)));
$events = RideSyncRealtimeEventService::recentForAudience($conn, $audienceType, $audienceId, $afterId, $limit);
$lastEventId = $afterId;
if (!empty($events)) {
    $lastEventId = (int) end($events)['id'];
    reset($events);
}

ridesync_json_response([
    'ok' => true,
    'audience_type' => $audienceType,
    'last_event_id' => $lastEventId,
    'events' => $events,
    'server_time' => date('c'),
]);

?>
