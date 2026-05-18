<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if (!isset($_SESSION['user_id']) && !isset($_SESSION['driver_id'])) {
    ridesync_error_response('Not authenticated', 401);
}

$actorIdentity = isset($_SESSION['driver_id'])
    ? 'driver:' . (int) $_SESSION['driver_id']
    : 'user:' . (int) $_SESSION['user_id'];
ridesync_enforce_rate_limit('api:ride_status', 120, 60, $actorIdentity, [
    'json' => true,
    'message' => 'Too many ride status checks. Please slow down briefly.',
]);

$rideId = (int) ($_GET['ride_id'] ?? 0);
if ($rideId <= 0) {
    ridesync_error_response('Invalid ride', 400);
}

$stmt = mysqli_prepare($conn,
    "SELECT r.*, ls.live_status, ls.driver_id, ls.eta_minutes, ls.note, ls.updated_at AS live_updated_at,
            d.name AS driver_name
     FROM rides r
     LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
     LEFT JOIN driver_accounts d ON d.id = ls.driver_id
     WHERE r.id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$ride) {
    ridesync_error_response('Ride not found', 404);
}

$allowed = false;
if (isset($_SESSION['user_id'])) {
    $userId = (int) $_SESSION['user_id'];
    if ((int) $ride['user_id'] === $userId) {
        $allowed = true;
    } else {
        $stmt = mysqli_prepare($conn, "SELECT id FROM matches WHERE ride_id = ? AND matched_user_id = ? AND status = 'accepted' LIMIT 1");
        mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
        mysqli_stmt_execute($stmt);
        $allowed = (bool) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    }
}

if (!$allowed && isset($_SESSION['driver_id']) && (int) ($ride['driver_id'] ?? 0) === (int) $_SESSION['driver_id']) {
    $allowed = true;
}

if (!$allowed) {
    ridesync_error_response('Not allowed', 403);
}

$stmt = mysqli_prepare($conn,
    "SELECT
        SUM(CASE WHEN status = 'accepted' THEN 1 ELSE 0 END) AS accepted_count,
        SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) AS pending_count
     FROM matches
     WHERE ride_id = ?"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$counts = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt)) ?: ['accepted_count' => 0, 'pending_count' => 0];

$acceptedCount = (int) ($counts['accepted_count'] ?? 0);
$pendingCount = (int) ($counts['pending_count'] ?? 0);
$liveStatus = $ride['live_status'] ?: 'searching';

if ($ride['status'] === 'cancelled') {
    $liveStatus = 'cancelled';
} elseif ($ride['status'] === 'closed' && $acceptedCount > 0 && $liveStatus === 'searching') {
    $liveStatus = 'matched';
} elseif ($acceptedCount > 0 && $liveStatus === 'searching') {
    $liveStatus = 'matched';
}

$steps = [
    ['key' => 'searching', 'label' => 'Ride Created'],
    ['key' => 'matched', 'label' => 'Passengers Matched'],
    ['key' => 'driver_assigned', 'label' => 'Driver Assigned'],
    ['key' => 'active', 'label' => 'Trip Started'],
    ['key' => 'completed', 'label' => 'Completed'],
];
$order = array_column($steps, 'key');
$currentIndex = array_search($liveStatus, $order, true);
if ($currentIndex === false) {
    $currentIndex = $liveStatus === 'cancelled' ? 0 : 0;
}

foreach ($steps as $index => &$step) {
    $step['state'] = $index < $currentIndex ? 'done' : ($index === $currentIndex ? 'current' : 'upcoming');
}
unset($step);

ridesync_json_response([
    'ok' => true,
    'ride_id' => $rideId,
    'ride_status' => $ride['status'],
    'live_status' => $liveStatus,
    'live_status_label' => ucwords(str_replace('_', ' ', $liveStatus)),
    'seats_available' => (int) $ride['seats_available'],
    'accepted_count' => $acceptedCount,
    'pending_count' => $pendingCount,
    'driver_name' => $ride['driver_name'],
    'eta_minutes' => $ride['eta_minutes'] !== null ? (int) $ride['eta_minutes'] : null,
    'note' => $ride['note'],
    'updated_at' => $ride['live_updated_at'],
    'steps' => $steps,
]);
?>
