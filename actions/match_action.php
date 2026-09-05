<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/redirect_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/route_overlap_helper.php';

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

function ridesync_match_existing_request_message($status) {
    switch ($status) {
        case 'pending':
            return "You already have a pending request for this ride.";
        case 'accepted':
            return "You're already accepted on this ride.";
        case 'rejected':
            return "Your previous request for this ride was declined.";
        default:
            return "You already requested to join this ride.";
    }
}

function ridesync_match_existing_request_status($conn, $rideId, $userId) {
    $stmt = mysqli_prepare($conn, "SELECT status FROM matches WHERE ride_id = ? AND matched_user_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
    mysqli_stmt_execute($stmt);
    $match = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return $match['status'] ?? null;
}

require_once __DIR__ . '/../includes/db_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if (ridesync_is_user_suspended($conn, (int) $_SESSION['user_id'])) {
    ridesync_match_error("Your rider account is suspended. You cannot request or manage ride matches.", "/ridesync/pages/dashboard.php");
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

    $existingStatus = ridesync_match_existing_request_status($conn, $ride_id, $user_id);
    if ($existingStatus !== null) {
        ridesync_match_error(ridesync_match_existing_request_message($existingStatus), $default);
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

    $pickupLat = ridesync_float_or_null($_POST['pickup_lat'] ?? null);
    $pickupLng = ridesync_float_or_null($_POST['pickup_lng'] ?? null);
    $dropoffLat = ridesync_float_or_null($_POST['dropoff_lat'] ?? null);
    $dropoffLng = ridesync_float_or_null($_POST['dropoff_lng'] ?? null);
    $detourDist = ridesync_float_or_null($_POST['detour_distance_km'] ?? null);
    $detourMins = isset($_POST['detour_time_minutes']) && $_POST['detour_time_minutes'] !== '' ? (int) $_POST['detour_time_minutes'] : null;
    $requestSource = ($_POST['source'] ?? '') === 'route_watch' ? 'route_watch' : 'search';

    $hasSnappedCoords = ridesync_column_exists($conn, 'matches', 'pickup_lat');

    if ($hasSnappedCoords) {
        $ins = mysqli_prepare($conn,
            "INSERT INTO matches
                (ride_id, matched_user_id, status, match_score, pickup_distance_km, drop_distance_km, route_overlap_percent, time_score, match_source, pickup_lat, pickup_lng, dropoff_lat, dropoff_lng, detour_distance_km, detour_time_minutes, source)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($ins, "iidddiisdddddis",
            $ride_id, $user_id, $matchScore, $pickupDistance, $dropDistance, $routeOverlap, $timeScore, $matchSource,
            $pickupLat, $pickupLng, $dropoffLat, $dropoffLng, $detourDist, $detourMins, $requestSource
        );
    } else {
        $ins = mysqli_prepare($conn,
            "INSERT INTO matches
                (ride_id, matched_user_id, status, match_score, pickup_distance_km, drop_distance_km, route_overlap_percent, time_score, match_source)
             VALUES (?, ?, 'pending', ?, ?, ?, ?, ?, ?)"
        );
        mysqli_stmt_bind_param($ins, "iidddiis", $ride_id, $user_id, $matchScore, $pickupDistance, $dropDistance, $routeOverlap, $timeScore, $matchSource);
    }

    if (mysqli_stmt_execute($ins)) {
        ridesync_ensure_live_status($conn, $ride_id, 'searching');

        if (function_exists('ridesync_fulfill_user_route_watches') && !empty($ride['travel_date'])) {
            ridesync_fulfill_user_route_watches($conn, $user_id, (string) $ride['travel_date']);
        }

        $notificationMsg = ($_SESSION['user_name'] ?? 'A rider') . ' requested to join your ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . '.';
        if ($detourDist !== null && $detourDist > 0) {
            $notificationMsg .= sprintf(" (Partial-path overlap: +%.1f km detour)", $detourDist);
        }

        ridesync_create_notification(
            $conn,
            (int) $ride['user_id'],
            null,
            'New join request',
            $notificationMsg
        );
        ridesync_match_success("Smart request sent. You'll see the status in My Matches.", $default);
    }

    if ((int) mysqli_errno($conn) === 1062) {
        $existingStatus = ridesync_match_existing_request_status($conn, $ride_id, $user_id);
        ridesync_match_error(ridesync_match_existing_request_message($existingStatus), $default);
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
        ridesync_match_error("No available seats left on this ride.", $default);
    }

    if (in_array($match_data['live_status'], ['active', 'completed', 'cancelled'], true)) {
        mysqli_rollback($conn);
        ridesync_match_error("This ride is already in progress or finished.", $default);
    }

    $ride_id = (int) $match_data['ride_id'];
    $requesterId = (int) $match_data['matched_user_id'];
    $updMatch = mysqli_prepare($conn, "UPDATE matches SET status = 'accepted' WHERE id = ?");
    mysqli_stmt_bind_param($updMatch, "i", $match_id);
    mysqli_stmt_execute($updMatch);

    $newSeats = (int) $match_data['seats_available'] - 1;
    $newStatus = $newSeats === 0 ? 'closed' : 'open';
    $updRide = mysqli_prepare($conn, "UPDATE rides SET seats_available = ?, status = ? WHERE id = ?");
    mysqli_stmt_bind_param($updRide, "isi", $newSeats, $newStatus, $ride_id);
    mysqli_stmt_execute($updRide);

    $rejectedOthersCount = 0;
    if ($newSeats === 0) {
        $rejectedOthersCount = ridesync_reject_pending_matches(
            $conn,
            $ride_id,
            $match_id,
            'Ride is full',
            'All seats on this ride have been filled.'
        );
    }

    mysqli_commit($conn);

    ridesync_create_notification(
        $conn,
        $requesterId,
        null,
        'Join request accepted!',
        'Your request to join the ride has been accepted. Have a great trip!'
    );

    $msg = "Match request accepted. Seats remaining: {$newSeats}.";
    if ($rejectedOthersCount > 0) {
        $msg .= " Remaining pending requests were automatically declined because all seats are filled.";
    }

    ridesync_match_success($msg, $default);
}

// ---- REJECT A MATCH REQUEST ----
if ($action === 'reject' && $match_id > 0) {
    $default = "/ridesync/pages/my_rides.php";

    $verify = mysqli_prepare($conn,
        "SELECT m.id, m.matched_user_id, r.user_id AS poster_id, r.origin, r.destination
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

    $upd = mysqli_prepare($conn, "UPDATE matches SET status = 'rejected' WHERE id = ? AND status = 'pending'");
    mysqli_stmt_bind_param($upd, "i", $match_id);
    mysqli_stmt_execute($upd);

    if (mysqli_stmt_affected_rows($upd) === 1) {
        ridesync_create_notification(
            $conn,
            (int) $match_data['matched_user_id'],
            null,
            'Join request declined',
            'Your request to join the ride from ' . $match_data['origin'] . ' to ' . $match_data['destination'] . ' was declined.'
        );
        ridesync_match_success("Request declined.", $default);
    }

    ridesync_match_error("Request could not be declined or was already processed.", $default);
}

header("Location: /ridesync/pages/dashboard.php");
exit();
