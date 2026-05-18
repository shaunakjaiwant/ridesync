<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/driver_account_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

ridesync_require_method('GET');

if (!isset($_SESSION['driver_id'])) {
    ridesync_error_response('Not authenticated', 401);
}

$driverId = (int) $_SESSION['driver_id'];
ridesync_enforce_rate_limit('api:driver_state', 120, 60, 'driver:' . $driverId, [
    'json' => true,
    'message' => 'Too many driver state checks. Please slow down briefly.',
]);

$state = ridesync_fetch_driver_state($conn, $driverId, ['documents' => false]);

ridesync_json_response([
    'ok' => true,
    'availability' => $state['availability'],
    'pending_requests' => (int) $state['pending_requests'],
    'active_workload' => (int) $state['active_workload'],
    'is_busy' => (int) $state['active_workload'] > 0,
    'today_earnings' => number_format((float) $state['today_earnings'], 2),
    'week_earnings' => number_format((float) $state['week_earnings'], 2),
    'total_earnings' => number_format((float) $state['total_earnings'], 2),
    'completed_trips' => (int) $state['completed_trips'],
]);
?>
