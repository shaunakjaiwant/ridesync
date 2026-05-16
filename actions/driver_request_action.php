<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/cost_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/redirect_helper.php';

function ridesync_driver_request_redirect($default) {
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
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

if (!ridesync_table_exists($conn, 'driver_ride_requests') || !ridesync_table_exists($conn, 'driver_account_documents')) {
    $_SESSION['match_error'] = "Driver request system is not ready yet.";
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

$riderUserId = (int) $_SESSION['user_id'];
$action = $_POST['action_type'] ?? 'create';

if ($action === 'cancel_pending') {
    $requestId = (int) ($_POST['request_id'] ?? 0);

    if ($requestId <= 0) {
        $_SESSION['match_error'] = "Choose a valid driver request.";
        ridesync_driver_request_redirect("/ridesync/pages/my_matches.php");
    }

    mysqli_begin_transaction($conn);

    try {
        $stmt = mysqli_prepare($conn,
            "SELECT id, driver_id, pickup, drop_location
             FROM driver_ride_requests
             WHERE id = ? AND rider_user_id = ? AND request_status = 'pending'
             LIMIT 1
             FOR UPDATE"
        );
        mysqli_stmt_bind_param($stmt, "ii", $requestId, $riderUserId);
        mysqli_stmt_execute($stmt);
        $request = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        if (!$request) {
            throw new RuntimeException("Pending driver request not found.");
        }

        $stmt = mysqli_prepare($conn,
            "UPDATE driver_ride_requests
             SET request_status = 'cancelled', responded_at = CURRENT_TIMESTAMP
             WHERE id = ? AND rider_user_id = ? AND request_status = 'pending'"
        );
        mysqli_stmt_bind_param($stmt, "ii", $requestId, $riderUserId);
        mysqli_stmt_execute($stmt);

        if (mysqli_stmt_affected_rows($stmt) !== 1) {
            throw new RuntimeException("Could not cancel this driver request.");
        }

        ridesync_create_notification(
            $conn,
            null,
            (int) $request['driver_id'],
            'Driver request cancelled',
            ($_SESSION['user_name'] ?? 'A rider') . ' cancelled the ride request from ' . $request['pickup'] . ' to ' . $request['drop_location'] . '.'
        );

        mysqli_commit($conn);
        $_SESSION['match_success'] = "Driver request cancelled.";
    } catch (Throwable $e) {
        mysqli_rollback($conn);
        $_SESSION['match_error'] = $e instanceof RuntimeException ? $e->getMessage() : "Could not cancel this driver request.";
    }

    ridesync_driver_request_redirect("/ridesync/pages/my_matches.php");
}

if ($action !== 'create') {
    $_SESSION['match_error'] = "Unsupported driver request action.";
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

$driverId = (int) ($_POST['driver_id'] ?? 0);
$pickup = trim($_POST['pickup'] ?? '');
$drop = trim($_POST['drop_location'] ?? '');
$distanceKm = ridesync_float_or_null($_POST['route_distance_km'] ?? null);

if ($driverId <= 0 || $pickup === '' || $drop === '') {
    $_SESSION['match_error'] = "Set pickup, destination, and driver before requesting.";
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

if (strlen($pickup) > 200 || strlen($drop) > 200 || strcasecmp($pickup, $drop) === 0) {
    $_SESSION['match_error'] = "Set a valid pickup and destination.";
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

$stmt = mysqli_prepare($conn,
    "SELECT d.id, d.name
     FROM driver_accounts d
     JOIN driver_account_availability a ON a.driver_id = d.id
     JOIN driver_account_profiles p ON p.driver_id = d.id
     JOIN (
        SELECT driver_id,
               SUM(CASE WHEN document_type = 'license' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS license_ok,
               SUM(CASE WHEN document_type = 'id_proof' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS id_ok,
               SUM(CASE WHEN document_type = 'aadhaar' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS aadhaar_ok,
               SUM(CASE WHEN document_type = 'pan' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS pan_ok,
               SUM(CASE WHEN document_type = 'vehicle_rc' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS rc_ok,
               SUM(CASE WHEN document_type = 'insurance' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS insurance_ok
        FROM driver_account_documents
        GROUP BY driver_id
     ) docs ON docs.driver_id = d.id
     WHERE d.id = ?
       AND d.status = 'active'
       AND a.status = 'online'
       AND p.verification_status = 'verified'
       AND docs.license_ok > 0
       AND (docs.id_ok > 0 OR (docs.aadhaar_ok > 0 AND docs.pan_ok > 0))
       AND docs.rc_ok > 0
       AND docs.insurance_ok > 0
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $driverId);
mysqli_stmt_execute($stmt);
$driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$driver) {
    $_SESSION['match_error'] = "That driver is no longer available or fully verified. Try searching again.";
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

if ($distanceKm === null || $distanceKm <= 0) {
    $distanceKm = ridesync_estimate_route_distance($pickup, $drop);
}

$stmt = mysqli_prepare($conn,
    "SELECT id
     FROM driver_ride_requests
     WHERE driver_id = ?
       AND rider_user_id = ?
       AND pickup = ?
       AND drop_location = ?
       AND request_status = 'pending'
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "iiss", $driverId, $riderUserId, $pickup, $drop);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    $_SESSION['match_error'] = "You already have a pending request for this driver and route.";
    ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
}

$estimatedFare = ridesync_driver_fare_estimate($distanceKm);
$fareRate = ridesync_fare_rate_per_km();
$pricingVersion = 'km_rate_v3_fair_split';

$hasPricingColumns = ridesync_column_exists($conn, 'driver_ride_requests', 'route_distance_km')
    && ridesync_column_exists($conn, 'driver_ride_requests', 'fare_rate_per_km')
    && ridesync_column_exists($conn, 'driver_ride_requests', 'pricing_version');

if ($hasPricingColumns) {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_ride_requests
            (driver_id, rider_user_id, pickup, drop_location, estimated_fare, route_distance_km, fare_rate_per_km, pricing_version)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "iissddds", $driverId, $riderUserId, $pickup, $drop, $estimatedFare, $distanceKm, $fareRate, $pricingVersion);
} else {
    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_ride_requests (driver_id, rider_user_id, pickup, drop_location, estimated_fare)
         VALUES (?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($stmt, "iissd", $driverId, $riderUserId, $pickup, $drop, $estimatedFare);
}

if (mysqli_stmt_execute($stmt)) {
    ridesync_create_notification(
        $conn,
        null,
        $driverId,
        'New smart driver request',
        ($_SESSION['user_name'] ?? 'A rider') . ' requested a ride from ' . $pickup . ' to ' . $drop . '.'
    );
    $_SESSION['match_success'] = "Driver request sent to " . $driver['name'] . ". Watch for acceptance in the driver request flow.";
} else {
    $_SESSION['match_error'] = "Could not send driver request. Please try again.";
}

ridesync_driver_request_redirect("/ridesync/pages/search_rides.php");
?>
