<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';

function ridesync_status_redirect($rideId) {
    header("Location: /ridesync/pages/ride_detail.php?id=" . (int) $rideId);
    exit();
}

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

$rideId = (int) ($_POST['ride_id'] ?? 0);
if ($rideId <= 0) {
    $_SESSION['error'] = "Invalid ride.";
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    ridesync_status_redirect($rideId);
}

$targetStatus = $_POST['live_status'] ?? '';
$allowedTargets = ['active', 'completed'];

if (!in_array($targetStatus, $allowedTargets, true)) {
    $_SESSION['error'] = "Invalid ride status action.";
    ridesync_status_redirect($rideId);
}

$userId = (int) $_SESSION['user_id'];

mysqli_begin_transaction($conn);

try {
    $stmt = mysqli_prepare(
        $conn,
        "SELECT r.id, r.user_id, r.status AS ride_status, r.origin, r.destination,
                r.route_distance_km,
                COALESCE(ls.live_status, 'searching') AS live_status,
                ls.driver_id
         FROM rides r
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE r.id = ? AND r.user_id = ?
         LIMIT 1
         FOR UPDATE"
    );
    mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
    mysqli_stmt_execute($stmt);
    $ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$ride) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Ride not found or you do not own it.";
        ridesync_status_redirect($rideId);
    }

    if ($ride['ride_status'] === 'cancelled') {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Cancelled rides cannot be updated.";
        ridesync_status_redirect($rideId);
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT matched_user_id
         FROM matches
         WHERE ride_id = ? AND status = 'accepted'"
    );
    mysqli_stmt_bind_param($stmt, "i", $rideId);
    mysqli_stmt_execute($stmt);
    $acceptedResult = mysqli_stmt_get_result($stmt);
    $acceptedUsers = [];
    while ($row = mysqli_fetch_assoc($acceptedResult)) {
        $acceptedUsers[] = (int) $row['matched_user_id'];
    }

    $assignedDriverId = (int) ($ride['driver_id'] ?? 0);

    if (count($acceptedUsers) === 0 && $assignedDriverId <= 0) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Accept at least one rider or assign a driver before starting the trip.";
        ridesync_status_redirect($rideId);
    }

    $currentStatus = $ride['live_status'];
    if ($currentStatus === 'searching' && $assignedDriverId > 0) {
        $currentStatus = 'driver_assigned';
    } elseif ($currentStatus === 'searching') {
        $currentStatus = 'matched';
    }

    if ($targetStatus === 'active' && !in_array($currentStatus, ['matched', 'driver_assigned'], true)) {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Only matched rides can be started.";
        ridesync_status_redirect($rideId);
    }

    if ($targetStatus === 'completed' && $currentStatus !== 'active') {
        mysqli_rollback($conn);
        $_SESSION['error'] = "Start the trip before marking it completed.";
        ridesync_status_redirect($rideId);
    }

    if ($targetStatus === 'active') {
        ridesync_update_live_status($conn, $rideId, 'active', 'Trip started. Follow the shared route and keep passengers updated.');
        $title = 'Ride started';
        $message = 'Your RideSync trip from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' has started.';
        $_SESSION['success'] = "Trip marked as started.";
    } else {
        $stmt = mysqli_prepare($conn, "UPDATE rides SET status = 'closed' WHERE id = ?");
        mysqli_stmt_bind_param($stmt, "i", $rideId);
        mysqli_stmt_execute($stmt);

        ridesync_update_live_status($conn, $rideId, 'completed', 'Trip completed. Thanks for sharing the ride.', null, 0);
        $participants = array_values(array_unique(array_merge([(int) $ride['user_id']], $acceptedUsers)));
        $fareBreakdown = calculateDynamicFareBreakdown($ride['origin'], $ride['destination'], max(1, count($participants)), $ride['route_distance_km']);
        foreach ($participants as $participantId) {
            ridesync_wallet_record_fare_due(
                $conn,
                $participantId,
                $rideId,
                $assignedDriverId > 0 ? $assignedDriverId : null,
                $fareBreakdown['final_fare'],
                'Shared ride fare from ' . $ride['origin'] . ' to ' . $ride['destination'],
                'community_ride',
                $rideId
            );
        }

        if ($assignedDriverId > 0) {
            $distanceKm = ridesync_float_or_null($ride['route_distance_km'] ?? null);
            if ($distanceKm === null || $distanceKm <= 0) {
                $distanceKm = ridesync_estimate_route_distance($ride['origin'], $ride['destination']);
            }
            ridesync_record_driver_trip(
                $conn,
                $assignedDriverId,
                $ride['origin'],
                $ride['destination'],
                ridesync_driver_fare_estimate($distanceKm),
                $distanceKm,
                'community_ride',
                $rideId
            );
        }
        $title = 'Ride completed';
        $message = 'Your RideSync trip from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' has been completed.';
        $_SESSION['success'] = "Trip marked as completed.";
    }

    foreach ($acceptedUsers as $acceptedUserId) {
        ridesync_create_notification($conn, $acceptedUserId, null, $title, $message);
    }

    if ($assignedDriverId > 0) {
        ridesync_create_notification($conn, null, $assignedDriverId, $title, $message);
    }

    mysqli_commit($conn);
} catch (Throwable $e) {
    mysqli_rollback($conn);
    $_SESSION['error'] = "Could not update ride status. Please try again.";
}

ridesync_status_redirect($rideId);
?>
