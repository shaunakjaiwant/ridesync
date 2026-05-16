<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/intelligence_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/post_ride.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['ride_error'] = "Invalid request. Please try again.";
    header("Location: /ridesync/pages/post_ride.php");
    exit();
}

function ridesync_post_ride_remember_input(): void
{
    $_SESSION['ride_form_old'] = [
        'origin' => substr(trim((string) ($_POST['origin'] ?? '')), 0, 200),
        'destination' => substr(trim((string) ($_POST['destination'] ?? '')), 0, 200),
        'travel_date' => substr(trim((string) ($_POST['travel_date'] ?? '')), 0, 32),
        'travel_time' => substr(trim((string) ($_POST['travel_time'] ?? '')), 0, 32),
        'seats_available' => substr(trim((string) ($_POST['seats_available'] ?? '')), 0, 4),
        'origin_lat' => substr(trim((string) ($_POST['origin_lat'] ?? '')), 0, 32),
        'origin_lng' => substr(trim((string) ($_POST['origin_lng'] ?? '')), 0, 32),
        'destination_lat' => substr(trim((string) ($_POST['destination_lat'] ?? '')), 0, 32),
        'destination_lng' => substr(trim((string) ($_POST['destination_lng'] ?? '')), 0, 32),
        'route_distance_km' => substr(trim((string) ($_POST['route_distance_km'] ?? '')), 0, 32),
        'route_polyline' => substr(trim((string) ($_POST['route_polyline'] ?? '')), 0, 20000),
    ];
}

function ridesync_post_ride_fail(string $message): void
{
    ridesync_post_ride_remember_input();
    $_SESSION['ride_error'] = $message;
    header("Location: /ridesync/pages/post_ride.php");
    exit();
}

function ridesync_post_ride_acquire_lock(mysqli $conn, string $lockKey): bool
{
    $stmt = mysqli_prepare($conn, "SELECT GET_LOCK(?, 5) AS lock_acquired");
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "s", $lockKey);
    if (!mysqli_stmt_execute($stmt)) {
        mysqli_stmt_close($stmt);
        return false;
    }

    $result = mysqli_stmt_get_result($stmt);
    $row = $result ? mysqli_fetch_assoc($result) : null;
    mysqli_stmt_close($stmt);

    return isset($row['lock_acquired']) && (int) $row['lock_acquired'] === 1;
}

function ridesync_post_ride_release_lock(mysqli $conn, string $lockKey): void
{
    $stmt = mysqli_prepare($conn, "SELECT RELEASE_LOCK(?)");
    if (!$stmt) {
        return;
    }

    mysqli_stmt_bind_param($stmt, "s", $lockKey);
    mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);
}

$user_id         = (int) $_SESSION['user_id'];
$origin          = trim($_POST['origin'] ?? '');
$destination     = trim($_POST['destination'] ?? '');
$travel_date     = trim($_POST['travel_date'] ?? '');
$travel_time     = trim($_POST['travel_time'] ?? '');
$seats_available = intval($_POST['seats_available'] ?? 1);
$originLatRaw = trim($_POST['origin_lat'] ?? '');
$originLngRaw = trim($_POST['origin_lng'] ?? '');
$destinationLatRaw = trim($_POST['destination_lat'] ?? '');
$destinationLngRaw = trim($_POST['destination_lng'] ?? '');
$routeDistanceRaw = trim($_POST['route_distance_km'] ?? '');
$routePolylineRaw = trim($_POST['route_polyline'] ?? '');

if ($origin === '' || $destination === '' || $travel_date === '' || $travel_time === '') {
    ridesync_post_ride_fail("All fields are required.");
}

if (strlen($origin) > 150 || strlen($destination) > 150) {
    ridesync_post_ride_fail("Departure and destination must be 150 characters or fewer.");
}

if (strcasecmp($origin, $destination) === 0) {
    ridesync_post_ride_fail("Departure and destination cannot be the same.");
}

if (strtotime($travel_date) < strtotime(date('Y-m-d'))) {
    ridesync_post_ride_fail("Travel date cannot be in the past.");
}

$travelTimestamp = strtotime($travel_date . ' ' . $travel_time);
if (!$travelTimestamp || $travelTimestamp < (time() - 300)) {
    ridesync_post_ride_fail("Travel time cannot be in the past.");
}

if ($seats_available < 1 || $seats_available > 5) {
    ridesync_post_ride_fail("Seats must be between 1 and 5.");
}

$mapValues = [$originLatRaw, $originLngRaw, $destinationLatRaw, $destinationLngRaw];
$hasAnyMapValue = count(array_filter($mapValues, fn($value) => $value !== '')) > 0;
$hasCompleteMapValue = count(array_filter($mapValues, fn($value) => $value !== '')) === 4;

$originLat = null;
$originLng = null;
$destinationLat = null;
$destinationLng = null;
$routeDistanceKm = null;
$routePolyline = ridesync_normalize_route_polyline($routePolylineRaw);

if ($hasAnyMapValue && !$hasCompleteMapValue) {
    ridesync_post_ride_fail("Please set both departure and destination pins on the map.");
}

if ($hasCompleteMapValue) {
    $originLat = filter_var($originLatRaw, FILTER_VALIDATE_FLOAT);
    $originLng = filter_var($originLngRaw, FILTER_VALIDATE_FLOAT);
    $destinationLat = filter_var($destinationLatRaw, FILTER_VALIDATE_FLOAT);
    $destinationLng = filter_var($destinationLngRaw, FILTER_VALIDATE_FLOAT);
    $routeDistanceKm = filter_var($routeDistanceRaw, FILTER_VALIDATE_FLOAT);

    $validCoordinates = $originLat !== false && $originLng !== false && $destinationLat !== false && $destinationLng !== false
        && $originLat >= -90 && $originLat <= 90
        && $destinationLat >= -90 && $destinationLat <= 90
        && $originLng >= -180 && $originLng <= 180
        && $destinationLng >= -180 && $destinationLng <= 180;

    if (!$validCoordinates) {
        ridesync_post_ride_fail("Map coordinates are invalid. Please set the route again.");
    }

    $pinDistanceKm = ridesync_haversine_km($originLat, $originLng, $destinationLat, $destinationLng);
    if ($pinDistanceKm !== null && $pinDistanceKm < 0.05) {
        ridesync_post_ride_fail("Departure and destination pins are too close to be a valid ride.");
    }

    if ($routeDistanceKm === false || $routeDistanceKm <= 0 || $routeDistanceKm > 1000) {
        $routeDistanceKm = null;
    }

    if ($routePolylineRaw !== '' && $routePolyline === null) {
        ridesync_post_ride_fail("Route path could not be verified. Clear the map and set the route again.");
    }
}

$rideLockKey = 'ridesync_post_ride_' . hash('sha256', implode('|', [
    $user_id,
    strtolower($origin),
    strtolower($destination),
    $travel_date,
    $travel_time,
]));

if (!ridesync_post_ride_acquire_lock($conn, $rideLockKey)) {
    ridesync_post_ride_fail("Another ride post is already being processed. Please wait a moment and try again.");
}

$ridePosted = false;
$rideMessage = "Something went wrong. Try again.";

try {
    $stmt = mysqli_prepare($conn,
        "SELECT id
         FROM rides
         WHERE user_id = ?
           AND origin = ?
           AND destination = ?
           AND travel_date = ?
           AND travel_time = ?
           AND status IN ('open', 'closed')
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "issss", $user_id, $origin, $destination, $travel_date, $travel_time);
    mysqli_stmt_execute($stmt);
    if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
        $rideMessage = "You already posted this route at the same date and time.";
    } else {
        if ($hasCompleteMapValue) {
            $sql = "INSERT INTO rides
                    (user_id, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, route_distance_km, travel_date, travel_time, seats_available)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param(
                $stmt,
                "issdddddssi",
                $user_id,
                $origin,
                $destination,
                $originLat,
                $originLng,
                $destinationLat,
                $destinationLng,
                $routeDistanceKm,
                $travel_date,
                $travel_time,
                $seats_available
            );
        } else {
            $sql = "INSERT INTO rides (user_id, origin, destination, travel_date, travel_time, seats_available) VALUES (?, ?, ?, ?, ?, ?)";
            $stmt = mysqli_prepare($conn, $sql);
            mysqli_stmt_bind_param($stmt, "issssi", $user_id, $origin, $destination, $travel_date, $travel_time, $seats_available);
        }

        if (mysqli_stmt_execute($stmt)) {
            $rideId = mysqli_insert_id($conn);
            ridesync_ensure_live_status($conn, $rideId, 'searching');

            if ($hasCompleteMapValue && ridesync_table_exists($conn, 'ride_routes')) {
                $durationMinutes = $routeDistanceKm !== null ? (int) ceil(((float) $routeDistanceKm / 28) * 60) : null;
                $routeStmt = mysqli_prepare($conn,
                    "INSERT INTO ride_routes (ride_id, encoded_polyline, distance_km, duration_minutes)
                     VALUES (?, ?, ?, ?)
                     ON DUPLICATE KEY UPDATE encoded_polyline = VALUES(encoded_polyline), distance_km = VALUES(distance_km), duration_minutes = VALUES(duration_minutes)"
                );
                mysqli_stmt_bind_param($routeStmt, "isdi", $rideId, $routePolyline, $routeDistanceKm, $durationMinutes);
                mysqli_stmt_execute($routeStmt);
            }

            $notifiedDemand = ridesync_notify_matching_demand($conn, $rideId, [
                'id' => $rideId,
                'user_id' => $user_id,
                'origin' => $origin,
                'destination' => $destination,
                'origin_lat' => $originLat,
                'origin_lng' => $originLng,
                'destination_lat' => $destinationLat,
                'destination_lng' => $destinationLng,
                'route_distance_km' => $routeDistanceKm,
                'encoded_polyline' => $routePolyline,
                'travel_date' => $travel_date,
                'travel_time' => $travel_time,
            ]);

            $ridePosted = true;
            $rideMessage = $notifiedDemand > 0
                ? "Ride posted successfully! {$notifiedDemand} matching demand signal" . ($notifiedDemand === 1 ? '' : 's') . " notified."
                : "Ride posted successfully!";
        }
    }
} finally {
    ridesync_post_ride_release_lock($conn, $rideLockKey);
}

if ($ridePosted) {
    unset($_SESSION['ride_form_old']);
    $_SESSION['ride_success'] = $rideMessage;
} else {
    ridesync_post_ride_fail($rideMessage);
}

header("Location: /ridesync/pages/post_ride.php");
exit();
?>
