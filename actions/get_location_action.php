<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/tracking_helper.php';

header('Content-Type: application/json; charset=utf-8');

$driverId = isset($_SESSION['driver_id']) ? (int) $_SESSION['driver_id'] : null;
$userId = isset($_SESSION['user_id']) ? (int) $_SESSION['user_id'] : null;

if (!$driverId && !$userId) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Authentication required.']);
    exit();
}

$rideId = (int) ($_GET['ride_id'] ?? ($_POST['ride_id'] ?? 0));

if ($rideId <= 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'error' => 'Valid ride_id is required.']);
    exit();
}

// Strict Server-Side Access Control
if (!ridesync_can_view_ride_tracking($conn, $rideId, $userId, $driverId)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'error' => 'Access denied. You must be an accepted rider or driver on this ride to view live location.']);
    exit();
}

$location = ridesync_get_latest_ride_location($conn, $rideId);

echo json_encode([
    'success' => true,
    'ride_id' => $rideId,
    'location' => $location,
]);
