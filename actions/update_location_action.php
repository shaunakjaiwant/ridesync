<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/tracking_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

header('Content-Type: application/json; charset=utf-8');

$driverId = isset($_SESSION['driver_id']) ? (int) $_SESSION['driver_id'] : null;
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if (!$driverId && !$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['success' => false, 'error' => 'Method not allowed.']);
    exit();
}

if (!ridesync_csrf_is_valid()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Invalid CSRF token.']);
    exit();
}

$rideId = (int) ($_POST['ride_id'] ?? 0);
$lat = isset($_POST['latitude']) ? (float) $_POST['latitude'] : null;
$lng = isset($_POST['longitude']) ? (float) $_POST['longitude'] : null;

if ($rideId <= 0 || $lat === null || $lng === null) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid ride_id, latitude, and longitude are required.']);
    exit();
}

if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Latitude/longitude out of range.']);
    exit();
}

// Check that caller has permission to post location updates for this ride
if (!ridesync_can_view_ride_tracking($conn, $rideId, $userId, $driverId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Not authorized to update location for this ride.']);
    exit();
}

// Rate limiting: max 1 ping per 5 seconds per ride
$rateKey = ridesync_client_ip() . '|loc_update|ride_' . $rideId;
ridesync_enforce_rate_limit('action:update_location', 20, 60, $rateKey, [
    'message' => 'Location updates throttled.',
]);

$success = ridesync_insert_location_ping($conn, $rideId, $driverId, $lat, $lng);

if ($success) {
    echo json_encode([
        'success' => true,
        'ride_id' => $rideId,
        'latitude' => $lat,
        'longitude' => $lng,
        'recorded_at' => date('Y-m-d H:i:s'),
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Failed to save location ping.']);
}
