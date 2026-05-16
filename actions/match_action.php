<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/redirect_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

function ridesync_redirect($default) {
    ridesync_redirect_back($default);
}

function ridesync_match_error($message, $default) {
    $_SESSION['match_error'] = $message;
    ridesync_redirect($default);
}

function ridesync_match_success($message, $default) {
    $_SESSION['match_success'] = $message;
    ridesync_redirect($default);
}

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (!ridesync_csrf_is_valid()) {
    ridesync_match_error("Invalid request. Please try again.", "/ridesync/pages/dashboard.php");
}

$user_id  = (int) $_SESSION['user_id'];
$action   = $_POST['action'] ?? '';
$ride_id  = (int) ($_POST['ride_id'] ?? 0);
$match_id = (int) ($_POST['match_id'] ?? 0);

// ---- REQUEST TO JOIN A RIDE ----
if ($action === 'request' && $ride_id > 0) {
    $default = "/ridesync/pages/search_rides.php";

    $check = mysqli_prepare($conn,
        "SELECT r.user_id, r.seats_available, r.status, r.origin, r.destination, r.travel_date, r.travel_time,
                COALESCE(ls.live_status, 'searching') AS live_status
         FROM rides r
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE r.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($check, "i", $ride_id);
    mysqli_stmt_execute($check);
    $ride = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

    if (!$ride || $ride['status'] !== 'open' || (int) $ride['seats_available'] <= 0) {
        ridesync_match_error("Ride not found or no longer available.", $default);
    }

    if (in_array($ride['live_status'], ['active', 'completed', 'cancelled'], true)) {
        ridesync_match_error("This ride is already in progress or finished.", $default);
    }

    $travelTimestamp = strtotime(($ride['travel_date'] ?? '') . ' ' . ($ride['travel_time'] ?? ''));
    if ($travelTimestamp && $travelTimestamp < (time() - 900)) {
        ridesync_match_error("This ride time has already passed.", $default);
    }

    if ((int) $ride['user_id'] === $user_id) {
        ridesync_match_error("You can't request to join your own ride.", $default);
    }

    $dup = mysqli_prepare($conn, "SELECT id FROM matches WHERE ride_id = ? AND matched_user_id = ?");
    mysqli_stmt_bind_param($dup, "ii", $ride_id, $user_id);
    mysqli_stmt_execute($dup);

    if (mysqli_num_rows(mysqli_stmt_get_result($dup)) > 0) {
        ridesync_match_error("You already requested to join this ride.", $default);
    }

    $matchScore = ridesync_float_or_null($_POST['match_score'] ?? null);
    $pickupDistance = ridesync_float_or_null($_POST['pickup_distance_km'] ?? null);
    $dropDistance = ridesync_float_or_null($_POST['drop_distance_km'] ?? null);
    $routeOverlap = isset($_POST['route_overlap_percent']) && $_POST['route_overlap_percent'] !== ''
        ? max(0, min(100, (int) $_POST['route_overlap_percent']))
        : null;
    $timeScore = isset($_POST['time_score']) && $_POST['time_score'] !== ''
        ? max(0, min(100, (int) $_POST['time_score']))
        : null;
    $matchSource = ($_POST['match_source'] ?? '') === 'smart' ? 'smart' : 'manual';

    $ins = mysqli_prepare($conn,
        "INSERT INTO matches
            (ride_id, matched_user_id, status, match_score, pickup_distance_km, drop_distance_km, route_overlap_percent, time_score, match_source)
         VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?)"
    );
    mysqli_stmt_bind_param($ins, "iidddiis", $ride_id, $user_id, $matchScore, $pickupDistance, $dropDistance, $routeOverlap, $timeScore, $matchSource);

    if (mysqli_stmt_execute($ins)) {
        ridesync_ensure_live_status($conn, $ride_id, 'searching');
        ridesync_create_notification(
            $conn,
            (int) $ride['user_id'],
            null,
            'New join request',
            ($_SESSION['user_name'] ?? 'A rider') . ' requested to join your ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . '.'
        );
        ridesync_match_success("Smart request sent. You'll see the status in My Matches.", $default);
    }

    ridesync_match_error("Something went wrong. Try again.", $default);
}

// ---- CANCEL YOUR OWN PENDING REQUEST ----
if ($action === 'cancel' && $match_id > 0) {
    $default = "/ridesync/pages/my_matches.php";

    $check = mysqli_prepare($conn, "SELECT id, status FROM matches WHERE id = ? AND matched_user_id = ?");
    mysqli_stmt_bind_param($check, "ii", $match_id, $user_id);
    mysqli_stmt_execute($check);
    $match = mysqli_fetch_assoc(mysqli_stmt_get_result($check));

    if (!$match) {
        ridesync_match_error("Match request not found.", $default);
    }

    if ($match['status'] !== 'pending') {
        ridesync_match_error("Only pending requests can be cancelled.", $default);
    }

    $del = mysqli_prepare($conn, "DELETE FROM matches WHERE id = ? AND matched_user_id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($del, "ii", $match_id, $user_id);
    mysqli_stmt_execute($del);

    if (mysqli_stmt_affected_rows($del) === 1) {
        ridesync_match_success("Request cancelled.", $default);
    }

    ridesync_match_error("Something went wrong. Try again.", $default);
}

// ---- ACCEPT A MATCH REQUEST ----
if ($action === 'accept' && $match_id > 0) {
    $default = "/ridesync/pages/my_rides.php";

    mysqli_begin_transaction($conn);

    $verify = mysqli_prepare($conn,
        "SELECT m.id, m.ride_id, m.matched_user_id, m.status AS match_status,
                r.seats_available, r.status AS ride_status, r.travel_date, r.travel_time,
                COALESCE(ls.live_status, 'searching') AS live_status
         FROM matches m
         JOIN rides r ON m.ride_id = r.id
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE m.id = ? AND r.user_id = ?
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($verify, "ii", $match_id, $user_id);
    mysqli_stmt_execute($verify);
    $match_data = mysqli_fetch_assoc(mysqli_stmt_get_result($verify));

    if (!$match_data) {
        mysqli_rollback($conn);
        ridesync_match_error("Match request not found.", $default);
    }

    if ($match_data['match_status'] !== 'pending') {
        mysqli_rollback($conn);
        ridesync_match_error("Only pending requests can be accepted.", $default);
    }

    if ($match_data['ride_status'] !== 'open' || (int) $match_data['seats_available'] <= 0) {
        mysqli_rollback($conn);
        ridesync_match_error("This ride has no available seats.", $default);
    }

    if (in_array($match_data['live_status'], ['active', 'completed', 'cancelled'], true)) {
        mysqli_rollback($conn);
        ridesync_match_error("This ride has already started or ended.", $default);
    }

    $travelTimestamp = strtotime(($match_data['travel_date'] ?? '') . ' ' . ($match_data['travel_time'] ?? ''));
    if ($travelTimestamp && $travelTimestamp < (time() - 1800)) {
        mysqli_rollback($conn);
        ridesync_match_error("This ride time has already passed.", $default);
    }

    $upd = mysqli_prepare($conn, "UPDATE matches SET status = 'accepted' WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($upd, "i", $match_id);
    mysqli_stmt_execute($upd);

    if (mysqli_stmt_affected_rows($upd) !== 1) {
        mysqli_rollback($conn);
        ridesync_match_error("Something went wrong. Try again.", $default);
    }

    $dec = mysqli_prepare($conn,
        "UPDATE rides
         SET seats_available = seats_available - 1,
             status = CASE WHEN seats_available - 1 <= 0 THEN 'closed' ELSE status END
         WHERE id = ? AND seats_available > 0"
    );
    mysqli_stmt_bind_param($dec, "i", $match_data['ride_id']);
    mysqli_stmt_execute($dec);

    if (mysqli_stmt_affected_rows($dec) !== 1) {
        mysqli_rollback($conn);
        ridesync_match_error("This ride has no available seats.", $default);
    }

    $nextLiveStatus = $match_data['live_status'] === 'driver_assigned' ? 'driver_assigned' : 'matched';
    $nextLiveNote = $nextLiveStatus === 'driver_assigned'
        ? 'Passenger matched and driver remains assigned.'
        : 'Passenger matched and seat reserved.';
    ridesync_update_live_status($conn, (int) $match_data['ride_id'], $nextLiveStatus, $nextLiveNote);
    ridesync_create_notification($conn, (int) $match_data['matched_user_id'], null, 'Ride request accepted', 'Your ride request was accepted.');

    mysqli_commit($conn);
    ridesync_match_success("Request accepted!", $default);
}

// ---- REJECT A MATCH REQUEST ----
if ($action === 'reject' && $match_id > 0) {
    $default = "/ridesync/pages/my_rides.php";

    $verify = mysqli_prepare($conn,
        "SELECT m.id, m.status, m.matched_user_id
         FROM matches m
         JOIN rides r ON m.ride_id = r.id
         WHERE m.id = ? AND r.user_id = ?"
    );
    mysqli_stmt_bind_param($verify, "ii", $match_id, $user_id);
    mysqli_stmt_execute($verify);
    $match_data = mysqli_fetch_assoc(mysqli_stmt_get_result($verify));

    if (!$match_data) {
        ridesync_match_error("Match request not found.", $default);
    }

    if ($match_data['status'] !== 'pending') {
        ridesync_match_error("Only pending requests can be rejected.", $default);
    }

    $upd = mysqli_prepare($conn, "UPDATE matches SET status = 'rejected' WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($upd, "i", $match_id);
    mysqli_stmt_execute($upd);

    if (mysqli_stmt_affected_rows($upd) === 1) {
        ridesync_create_notification($conn, (int) $match_data['matched_user_id'], null, 'Ride request rejected', 'Your ride request was rejected.');
        ridesync_match_success("Request rejected.", $default);
    }

    ridesync_match_error("Something went wrong. Try again.", $default);
}

ridesync_match_error("Invalid match action.", "/ridesync/pages/dashboard.php");
?>
