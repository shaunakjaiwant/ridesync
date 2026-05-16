<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';

// Must be logged in
if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit;
}

// Must receive a ride ID via POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !isset($_POST['ride_id'])) {
    header("Location: /ridesync/pages/my_rides.php");
    exit;
}

// CSRF check
if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'] ?? '', $_POST['csrf_token'])) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: /ridesync/pages/my_rides.php");
    exit;
}

$rideId = (int) $_POST['ride_id'];
$userId = (int) $_SESSION['user_id'];

// Verify this ride belongs to the current user
$checkQuery = "SELECT r.id, r.status, r.origin, r.destination,
                      COALESCE(ls.live_status, 'searching') AS live_status,
                      ls.driver_id
               FROM rides r
               LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
               WHERE r.id = ? AND r.user_id = ?
               LIMIT 1";
$stmt = mysqli_prepare($conn, $checkQuery);
mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
mysqli_stmt_execute($stmt);
$result = mysqli_stmt_get_result($stmt);
$ride = mysqli_fetch_assoc($result);

if (!$ride) {
    $_SESSION['error'] = "Ride not found or you don't have permission to cancel it.";
    header("Location: /ridesync/pages/my_rides.php");
    exit;
}

if ($ride['status'] === 'cancelled') {
    $_SESSION['error'] = "This ride is already cancelled.";
    header("Location: /ridesync/pages/my_rides.php");
    exit;
}

if (in_array($ride['live_status'], ['active', 'completed'], true)) {
    $_SESSION['error'] = "Active or completed rides cannot be cancelled here.";
    header("Location: /ridesync/pages/my_rides.php");
    exit;
}

// Start a transaction: update the ride, reject open matches, and notify affected people.
mysqli_begin_transaction($conn);

try {
    $affectedUsers = [];
    $stmt = mysqli_prepare($conn, "SELECT matched_user_id FROM matches WHERE ride_id = ? AND status IN ('pending', 'accepted')");
    mysqli_stmt_bind_param($stmt, "i", $rideId);
    mysqli_stmt_execute($stmt);
    $affectedResult = mysqli_stmt_get_result($stmt);
    while ($row = mysqli_fetch_assoc($affectedResult)) {
        $affectedUsers[] = (int) $row['matched_user_id'];
    }

    // 1. Set ride status to 'cancelled'
    $cancelRide = "UPDATE rides SET status = 'cancelled' WHERE id = ?";
    $stmt1 = mysqli_prepare($conn, $cancelRide);
    mysqli_stmt_bind_param($stmt1, "i", $rideId);
    mysqli_stmt_execute($stmt1);

    // 2. Reject all pending/accepted matches for this ride
    $cancelMatches = "UPDATE matches SET status = 'rejected' WHERE ride_id = ? AND status IN ('pending', 'accepted')";
    $stmt2 = mysqli_prepare($conn, $cancelMatches);
    mysqli_stmt_bind_param($stmt2, "i", $rideId);
    mysqli_stmt_execute($stmt2);

    ridesync_update_live_status($conn, $rideId, 'cancelled', 'Ride was cancelled by the owner.', null, 0);

    $message = 'The ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' was cancelled by the owner.';
    foreach ($affectedUsers as $affectedUserId) {
        ridesync_create_notification($conn, $affectedUserId, null, 'Ride cancelled', $message);
    }

    if (!empty($ride['driver_id'])) {
        ridesync_create_notification($conn, null, (int) $ride['driver_id'], 'Ride cancelled', $message);
    }

    // Commit both changes
    mysqli_commit($conn);

    $_SESSION['success'] = "Ride cancelled successfully. All match requests have been notified.";
} catch (Throwable $e) {
    // If anything fails, undo everything
    mysqli_rollback($conn);
    $_SESSION['error'] = "Something went wrong. Please try again.";
}

header("Location: /ridesync/pages/my_rides.php");
exit;
