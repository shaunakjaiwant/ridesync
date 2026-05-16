<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';
require_once __DIR__ . '/../includes/redirect_helper.php';

function ridesync_demand_redirect($default) {
    ridesync_redirect_back($default);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/search_rides.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['match_error'] = "Invalid request. Please try again.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

if (!ridesync_table_exists($conn, 'route_demand_signals')) {
    $_SESSION['match_error'] = "Demand signals are not ready yet.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

$userId = (int) $_SESSION['user_id'];
$action = $_POST['action_type'] ?? 'save_signal';

if ($action === 'cancel_signal') {
    $signalId = (int) ($_POST['signal_id'] ?? 0);

    if ($signalId <= 0) {
        $_SESSION['match_error'] = "Choose a valid route alert.";
        ridesync_demand_redirect("/ridesync/pages/insights.php");
    }

    $stmt = mysqli_prepare($conn,
        "UPDATE route_demand_signals
         SET demand_status = 'cancelled', updated_at = CURRENT_TIMESTAMP
         WHERE id = ? AND user_id = ? AND demand_status = 'active'"
    );
    mysqli_stmt_bind_param($stmt, "ii", $signalId, $userId);
    mysqli_stmt_execute($stmt);

    $_SESSION['match_success'] = mysqli_stmt_affected_rows($stmt) > 0
        ? "Route alert cancelled."
        : "That route alert is already closed or no longer available.";
    ridesync_demand_redirect("/ridesync/pages/insights.php");
}

if ($action !== 'save_signal') {
    $_SESSION['match_error'] = "Unsupported route alert action.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

$origin = trim($_POST['origin'] ?? '');
$destination = trim($_POST['destination'] ?? '');
$travelDate = trim($_POST['travel_date'] ?? '');
$travelTime = trim($_POST['travel_time'] ?? '');
$originLat = ridesync_float_or_null($_POST['origin_lat'] ?? null);
$originLng = ridesync_float_or_null($_POST['origin_lng'] ?? null);
$destinationLat = ridesync_float_or_null($_POST['destination_lat'] ?? null);
$destinationLng = ridesync_float_or_null($_POST['destination_lng'] ?? null);
$routeDistanceKm = ridesync_float_or_null($_POST['route_distance_km'] ?? null);
$routePolylineRaw = trim($_POST['route_polyline'] ?? '');
$routePolyline = ridesync_normalize_route_polyline($routePolylineRaw);

if ($origin === '' || $destination === '') {
    $_SESSION['match_error'] = "Set pickup and destination before creating a demand signal.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

if (strlen($origin) > 200 || strlen($destination) > 200) {
    $_SESSION['match_error'] = "Pickup and destination must be 200 characters or fewer.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

if (strcasecmp($origin, $destination) === 0) {
    $_SESSION['match_error'] = "Pickup and destination cannot be the same.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

if ($travelDate !== '' && strtotime($travelDate) < strtotime(date('Y-m-d'))) {
    $_SESSION['match_error'] = "Demand date cannot be in the past.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

if ($travelDate !== '' && $travelTime !== '') {
    $travelTimestamp = strtotime($travelDate . ' ' . $travelTime);
    if (!$travelTimestamp || $travelTimestamp < (time() - 300)) {
        $_SESSION['match_error'] = "Demand time cannot be in the past.";
        ridesync_demand_redirect("/ridesync/pages/search_rides.php");
    }
}

$mapValues = [$originLat, $originLng, $destinationLat, $destinationLng];
$hasAnyMapValue = count(array_filter($mapValues, fn($value) => $value !== null)) > 0;
$hasCompleteMapValue = count(array_filter($mapValues, fn($value) => $value !== null)) === 4;

if ($hasAnyMapValue && !$hasCompleteMapValue) {
    $_SESSION['match_error'] = "Set both pickup and destination pins before saving route demand.";
    ridesync_demand_redirect("/ridesync/pages/search_rides.php");
}

if ($hasCompleteMapValue) {
    $validCoordinates = $originLat >= -90 && $originLat <= 90
        && $destinationLat >= -90 && $destinationLat <= 90
        && $originLng >= -180 && $originLng <= 180
        && $destinationLng >= -180 && $destinationLng <= 180;

    if (!$validCoordinates) {
        $_SESSION['match_error'] = "Route coordinates are invalid. Set the route again.";
        ridesync_demand_redirect("/ridesync/pages/search_rides.php");
    }

    $pinDistanceKm = ridesync_haversine_km($originLat, $originLng, $destinationLat, $destinationLng);
    if ($pinDistanceKm !== null && $pinDistanceKm < 0.05) {
        $_SESSION['match_error'] = "Pickup and destination pins are too close to be valid.";
        ridesync_demand_redirect("/ridesync/pages/search_rides.php");
    }

    if ($routePolylineRaw !== '' && $routePolyline === null) {
        $_SESSION['match_error'] = "Route path could not be verified. Set the route again.";
        ridesync_demand_redirect("/ridesync/pages/search_rides.php");
    }
}

if ($routeDistanceKm !== null && ($routeDistanceKm <= 0 || $routeDistanceKm > 1000)) {
    $routeDistanceKm = null;
}

$routeKey = ridesync_route_key($origin, $destination);
$travelDateOrNull = $travelDate !== '' ? $travelDate : null;
$travelTimeOrNull = $travelTime !== '' ? $travelTime : null;
$hasPolylineColumn = ridesync_column_exists($conn, 'route_demand_signals', 'encoded_polyline');

$stmt = mysqli_prepare($conn,
    "SELECT id
     FROM route_demand_signals
     WHERE user_id = ?
       AND route_key = ?
       AND demand_status = 'active'
       AND ((travel_date IS NULL AND ? IS NULL) OR travel_date = ?)
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "isss", $userId, $routeKey, $travelDateOrNull, $travelDateOrNull);
mysqli_stmt_execute($stmt);
$existing = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if ($existing) {
    if ($hasPolylineColumn) {
        $stmt = mysqli_prepare($conn,
            "UPDATE route_demand_signals
             SET origin = ?, destination = ?, origin_lat = ?, origin_lng = ?, destination_lat = ?, destination_lng = ?,
                 route_distance_km = ?, encoded_polyline = ?, travel_time = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "ssdddddssii",
            $origin,
            $destination,
            $originLat,
            $originLng,
            $destinationLat,
            $destinationLng,
            $routeDistanceKm,
            $routePolyline,
            $travelTimeOrNull,
            $existing['id'],
            $userId
        );
    } else {
        $stmt = mysqli_prepare($conn,
            "UPDATE route_demand_signals
             SET origin = ?, destination = ?, origin_lat = ?, origin_lng = ?, destination_lat = ?, destination_lng = ?,
                 route_distance_km = ?, travel_time = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ? AND user_id = ?"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "ssdddddsii",
            $origin,
            $destination,
            $originLat,
            $originLng,
            $destinationLat,
            $destinationLng,
            $routeDistanceKm,
            $travelTimeOrNull,
            $existing['id'],
            $userId
        );
    }
    $ok = mysqli_stmt_execute($stmt);
} else {
    if ($hasPolylineColumn) {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO route_demand_signals
                (user_id, route_key, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, route_distance_km, encoded_polyline, travel_date, travel_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "isssdddddsss",
            $userId,
            $routeKey,
            $origin,
            $destination,
            $originLat,
            $originLng,
            $destinationLat,
            $destinationLng,
            $routeDistanceKm,
            $routePolyline,
            $travelDateOrNull,
            $travelTimeOrNull
        );
    } else {
        $stmt = mysqli_prepare($conn,
            "INSERT INTO route_demand_signals
                (user_id, route_key, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, route_distance_km, travel_date, travel_time)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param(
            $stmt,
            "isssddddsss",
            $userId,
            $routeKey,
            $origin,
            $destination,
            $originLat,
            $originLng,
            $destinationLat,
            $destinationLng,
            $routeDistanceKm,
            $travelDateOrNull,
            $travelTimeOrNull
        );
    }
    $ok = mysqli_stmt_execute($stmt);
}

if ($ok) {
    $_SESSION['match_success'] = "Demand signal saved. RideSync will alert you when this route appears.";
} else {
    $_SESSION['match_error'] = "Could not save demand signal. Try again.";
}

ridesync_demand_redirect("/ridesync/pages/search_rides.php");
?>
