<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (!ridesync_table_exists($conn, 'user_ratings')) {
    $_SESSION['error'] = "Ratings are not available yet.";
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

$reviewerId = (int) $_SESSION['user_id'];
$rideId = (int) ($_POST['ride_id'] ?? 0);
$reviewedId = (int) ($_POST['reviewed_user_id'] ?? 0);
$rating = (int) ($_POST['rating'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

require_once __DIR__ . '/../includes/rate_limit_helper.php';

$rateIdentity = ridesync_client_ip() . '|rating|user_' . $reviewerId;
ridesync_enforce_rate_limit('action:rating_submit', 10, 60, $rateIdentity, [
    'message' => 'Too many rating submissions. Please retry in a moment.',
]);

if ($rideId <= 0 || $reviewedId <= 0 || $reviewedId === $reviewerId || $rating < 1 || $rating > 5) {
    $_SESSION['error'] = "Choose a valid rider and rating.";
    header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
    exit();
}

if (strlen($comment) > 255) {
    $comment = substr($comment, 0, 255);
}

// Anti-Spam & Link Filtering for Review Comments
if ($comment !== '') {
    if (preg_match('/https?:\/\/|www\./i', $comment)) {
        $_SESSION['error'] = "Review comments cannot contain external links or URLs.";
        header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
        exit();
    }

    if (preg_match('/(.)\1{7,}/i', $comment)) {
        $_SESSION['error'] = "Please enter a meaningful review comment.";
        header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
        exit();
    }
}

$stmt = mysqli_prepare($conn,
    "SELECT r.user_id, r.origin, r.destination, r.travel_date, r.travel_time,
            COALESCE(ls.live_status, 'searching') AS live_status
     FROM rides r
     LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
     WHERE r.id = ?
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$ride) {
    $_SESSION['error'] = "Ride not found.";
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

$travelTimestamp = strtotime(($ride['travel_date'] ?? '') . ' ' . ($ride['travel_time'] ?? ''));
$isPastRide = $travelTimestamp && $travelTimestamp < time();
$isCompletedRide = ($ride['live_status'] ?? '') === 'completed';

if (!$isCompletedRide && !$isPastRide) {
    $_SESSION['error'] = "Ratings are available after the trip is completed.";
    header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
    exit();
}

$ownerId = (int) $ride['user_id'];
$participantIds = [$ownerId];
$stmt = mysqli_prepare($conn, "SELECT matched_user_id FROM matches WHERE ride_id = ? AND status = 'accepted'");
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$participants = mysqli_stmt_get_result($stmt);
while ($participant = mysqli_fetch_assoc($participants)) {
    $participantIds[] = (int) $participant['matched_user_id'];
}

if (!in_array($reviewerId, $participantIds, true) || !in_array($reviewedId, $participantIds, true)) {
    $_SESSION['error'] = "Only accepted ride participants can rate each other.";
    header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
    exit();
}

$stmt = mysqli_prepare($conn,
    "INSERT INTO user_ratings (ride_id, reviewer_user_id, reviewed_user_id, rating, comment)
     VALUES (?, ?, ?, ?, ?)
     ON DUPLICATE KEY UPDATE rating = VALUES(rating), comment = VALUES(comment), created_at = CURRENT_TIMESTAMP"
);
mysqli_stmt_bind_param($stmt, "iiiis", $rideId, $reviewerId, $reviewedId, $rating, $comment);

if (mysqli_stmt_execute($stmt)) {
    ridesync_create_notification(
        $conn,
        $reviewedId,
        null,
        'New ride rating',
        ($_SESSION['user_name'] ?? 'A rider') . ' rated your shared ride from ' . $ride['origin'] . ' to ' . $ride['destination'] . '.'
    );
    $_SESSION['success'] = "Rating saved.";
} else {
    $_SESSION['error'] = "Could not save rating. Try again.";
}

header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
exit();
?>
