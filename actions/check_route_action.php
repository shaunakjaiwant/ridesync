<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/route_overlap_helper.php';
require_once __DIR__ . '/../includes/notification_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/search_rides.php");
    exit();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['route_check_error'] = "Invalid request security token. Please try again.";
    header("Location: /ridesync/pages/search_rides.php");
    exit();
}

$userId = (int) $_SESSION['user_id'];
$origin = trim((string) ($_POST['origin'] ?? ''));
$destination = trim((string) ($_POST['destination'] ?? ''));
$originLat = ridesync_float_or_null($_POST['origin_lat'] ?? null);
$originLng = ridesync_float_or_null($_POST['origin_lng'] ?? null);
$destLat = ridesync_float_or_null($_POST['destination_lat'] ?? null);
$destLng = ridesync_float_or_null($_POST['destination_lng'] ?? null);
$travelDate = trim((string) ($_POST['travel_date'] ?? ''));
$travelTime = trim((string) ($_POST['travel_time'] ?? ''));

if ($origin === '' || $destination === '' || $originLat === null || $originLng === null || $destLat === null || $destLng === null || $travelDate === '') {
    $_SESSION['route_check_error'] = "Please provide valid origin, destination, map coordinates, and travel date.";
    header("Location: /ridesync/pages/search_rides.php");
    exit();
}

// 1. Save active route watch
$watchStmt = mysqli_prepare(
    $conn,
    "INSERT INTO route_watches (user_id, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, travel_date, travel_time, status)
     VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
);
if ($watchStmt) {
    $timeVal = $travelTime !== '' ? $travelTime : null;
    mysqli_stmt_bind_param($watchStmt, "issddddss", $userId, $origin, $destination, $originLat, $originLng, $destLat, $destLng, $travelDate, $timeVal);
    mysqli_stmt_execute($watchStmt);
    $watchId = mysqli_insert_id($conn);
    mysqli_stmt_close($watchStmt);
}

// 2. Search all currently open rides for that travel_date
$ridesStmt = mysqli_prepare(
    $conn,
    "SELECT r.id, r.user_id, r.origin, r.destination, r.origin_lat, r.origin_lng, r.destination_lat, r.destination_lng,
            r.travel_date, r.travel_time, r.seats_available, u.name AS poster_name, rr.encoded_polyline
     FROM rides r
     JOIN users u ON r.user_id = u.id
     LEFT JOIN ride_routes rr ON rr.ride_id = r.id
     WHERE r.status = 'open' AND r.travel_date = ? AND r.user_id <> ?
     ORDER BY r.created_at DESC"
);

$matchingResults = [];
if ($ridesStmt) {
    mysqli_stmt_bind_param($ridesStmt, "si", $travelDate, $userId);
    mysqli_stmt_execute($ridesStmt);
    $result = mysqli_stmt_get_result($ridesStmt);

    $searchQuery = [
        'origin' => $origin,
        'destination' => $destination,
        'origin_lat' => $originLat,
        'origin_lng' => $originLng,
        'destination_lat' => $destLat,
        'destination_lng' => $destLng,
        'travel_date' => $travelDate,
        'travel_time' => $travelTime,
    ];

    while ($ride = mysqli_fetch_assoc($result)) {
        $overlap = ridesync_compute_route_overlap($ride, $searchQuery);
        if ($overlap['is_match']) {
            $ride['overlap_data'] = $overlap;
            $matchingResults[] = $ride;
        }
    }
    mysqli_stmt_close($ridesStmt);
}

// Check for JSON/AJAX request
$isJson = isset($_SERVER['HTTP_ACCEPT']) && str_contains($_SERVER['HTTP_ACCEPT'], 'application/json');
if ($isJson) {
    header('Content-Type: application/json');
    echo json_encode([
        'success' => true,
        'watch_id' => $watchId ?? null,
        'matches_count' => count($matchingResults),
        'results' => $matchingResults,
    ]);
    exit();
}

$_SESSION['route_check_results'] = [
    'origin' => $origin,
    'destination' => $destination,
    'travel_date' => $travelDate,
    'matches' => $matchingResults,
    'watch_saved' => true,
];

header("Location: /ridesync/pages/search_rides.php#route-check-results");
exit();
