<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/rating_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

// Rater can be logged-in user or logged-in driver
$ratedByType = null;
$ratedById = 0;

if (isset($_SESSION['user_id'])) {
    $ratedByType = 'user';
    $ratedById = (int) $_SESSION['user_id'];
} elseif (isset($_SESSION['driver_id'])) {
    $ratedByType = 'driver';
    $ratedById = (int) $_SESSION['driver_id'];
}

if (!$ratedByType || $ratedById <= 0) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/my_rides.php");
    exit();
}

// Enforce CSRF protection
if (!ridesync_csrf_is_valid()) {
    $_SESSION['error'] = "Invalid CSRF token. Please try again.";
    header("Location: /ridesync/pages/my_rides.php");
    exit();
}

$rideId = (int) ($_POST['ride_id'] ?? 0);
$ratedUserType = trim($_POST['rated_user_type'] ?? 'user');
$ratedUserId = (int) ($_POST['rated_user_id'] ?? 0);
$score = (int) ($_POST['score'] ?? 0);
$comment = trim($_POST['comment'] ?? '');

$redirectUrl = "/ridesync/pages/ride_detail.php?id=" . $rideId;
if ($rideId <= 0) {
    $redirectUrl = "/ridesync/pages/my_rides.php";
}

// Rate Limiting
$rateIdentity = ridesync_client_ip() . '|rate_ride|' . $ratedByType . '_' . $ratedById;
ridesync_enforce_rate_limit('action:rate_ride', 10, 60, $rateIdentity, [
    'message' => 'Too many rating submissions. Please retry in a moment.',
]);

// Validation
if ($rideId <= 0 || $ratedUserId <= 0 || $score < 1 || $score > 5) {
    $_SESSION['error'] = "Please select a valid rating score (1-5).";
    header("Location: " . $redirectUrl);
    exit();
}

if (!in_array($ratedUserType, ['user', 'driver'], true)) {
    $_SESSION['error'] = "Invalid rated user type.";
    header("Location: " . $redirectUrl);
    exit();
}

if ($ratedByType === $ratedUserType && $ratedById === $ratedUserId) {
    $_SESSION['error'] = "You cannot rate yourself.";
    header("Location: " . $redirectUrl);
    exit();
}

if (strlen($comment) > 500) {
    $comment = substr($comment, 0, 500);
}

// Anti-Spam & Link Filtering for Review Comments
if ($comment !== '') {
    if (preg_match('/https?:\/\/|www\./i', $comment)) {
        $_SESSION['error'] = "Review comments cannot contain external links or URLs.";
        header("Location: " . $redirectUrl);
        exit();
    }

    if (preg_match('/(.)\1{7,}/i', $comment)) {
        $_SESSION['error'] = "Please enter a meaningful review comment.";
        header("Location: " . $redirectUrl);
        exit();
    }
}

// Validate that ride is closed/completed and rater & rated user both participated
if (!ridesync_can_user_rate_ride($conn, $rideId, $ratedByType, $ratedById, $ratedUserType, $ratedUserId)) {
    $_SESSION['error'] = "You cannot rate this ride (it may not be completed, or you/they were not participants, or you already rated it).";
    header("Location: " . $redirectUrl);
    exit();
}

// Submit rating
$success = ridesync_submit_rating($conn, $rideId, $ratedByType, $ratedById, $ratedUserType, $ratedUserId, $score, $comment);

if ($success) {
    // Send in-app notification
    $targetUserId = ($ratedUserType === 'user') ? $ratedUserId : null;
    $targetDriverId = ($ratedUserType === 'driver') ? $ratedUserId : null;
    $raterName = $_SESSION['user_name'] ?? ($_SESSION['driver_name'] ?? 'A ride partner');

    ridesync_create_notification(
        $conn,
        $targetUserId,
        $targetDriverId,
        'New Ride Rating',
        $raterName . ' left you a ' . $score . '★ rating for ride #' . $rideId . '.'
    );

    $_SESSION['success'] = "Thank you! Your rating has been saved.";
} else {
    $_SESSION['error'] = "Could not save your rating. Please try again.";
}

header("Location: " . $redirectUrl);
exit();
