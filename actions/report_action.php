<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/redirect_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

if (!isset($_SESSION['user_id'])) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

$returnTo = ridesync_safe_redirect_target($_POST['return_to'] ?? null, '/ridesync/pages/dashboard.php');

if (!ridesync_csrf_is_valid()) {
    $_SESSION['error'] = "Invalid request. Please try again.";
    header("Location: {$returnTo}");
    exit();
}

$reportsTable = mysqli_query($conn, "SHOW TABLES LIKE 'reports'");
if (!$reportsTable || mysqli_num_rows($reportsTable) === 0) {
    $_SESSION['error'] = "Reporting is not available yet. Please import the latest RideSync SQL file.";
    header("Location: {$returnTo}");
    exit();
}

$reporterId = (int) $_SESSION['user_id'];
$rideId = (int) ($_POST['ride_id'] ?? 0);
$reportedUserId = (int) ($_POST['reported_user_id'] ?? 0);
$reason = $_POST['reason'] ?? 'other';
$message = trim($_POST['message'] ?? '');
$allowedReasons = ['safety', 'misconduct', 'fake_profile', 'payment', 'spam', 'other'];

if ($rideId <= 0 || !in_array($reason, $allowedReasons, true) || strlen($message) < 10) {
    $_SESSION['error'] = "Choose a reason and explain the report in at least 10 characters.";
    header("Location: {$returnTo}");
    exit();
}

if (strlen($message) > 1200) {
    $message = substr($message, 0, 1200);
}

$stmt = mysqli_prepare($conn, "SELECT user_id FROM rides WHERE id = ? LIMIT 1");
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$ride = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

if (!$ride) {
    $_SESSION['error'] = "Ride not found.";
    header("Location: {$returnTo}");
    exit();
}

$rideOwnerId = (int) $ride['user_id'];
$reportedUserValue = $reportedUserId > 0 ? $reportedUserId : null;
$participantIds = [$rideOwnerId];

$stmt = mysqli_prepare($conn, "SELECT matched_user_id FROM matches WHERE ride_id = ? AND status = 'accepted'");
mysqli_stmt_bind_param($stmt, "i", $rideId);
mysqli_stmt_execute($stmt);
$participants = mysqli_stmt_get_result($stmt);
while ($participant = mysqli_fetch_assoc($participants)) {
    $participantIds[] = (int) $participant['matched_user_id'];
}

if ($reportedUserId === $reporterId) {
    $_SESSION['error'] = "You cannot report yourself.";
    header("Location: {$returnTo}");
    exit();
}

if (!in_array($reporterId, $participantIds, true)) {
    $_SESSION['error'] = "Only ride participants can submit a report for this ride.";
    header("Location: {$returnTo}");
    exit();
}

if ($reportedUserId > 0 && !in_array($reportedUserId, $participantIds, true)) {
    $_SESSION['error'] = "That report target is not connected to this ride.";
    header("Location: {$returnTo}");
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "SELECT id
     FROM reports
     WHERE reporter_user_id = ?
       AND ride_id = ?
       AND ((reported_user_id IS NULL AND ? IS NULL) OR reported_user_id = ?)
       AND report_status IN ('open', 'reviewing')
     LIMIT 1"
);
mysqli_stmt_bind_param($stmt, "iiii", $reporterId, $rideId, $reportedUserValue, $reportedUserValue);
mysqli_stmt_execute($stmt);
if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
    $_SESSION['error'] = "You already have an active report for this ride.";
    header("Location: {$returnTo}");
    exit();
}

$stmt = mysqli_prepare(
    $conn,
    "INSERT INTO reports (reporter_user_id, reported_user_id, ride_id, reason, message)
     VALUES (?, ?, ?, ?, ?)"
);
mysqli_stmt_bind_param($stmt, "iiiss", $reporterId, $reportedUserValue, $rideId, $reason, $message);

if (!mysqli_stmt_execute($stmt)) {
    $_SESSION['error'] = "Could not submit the report. Please try again.";
    header("Location: {$returnTo}");
    exit();
}

$_SESSION['success'] = "Report submitted. Admin will review it.";
header("Location: {$returnTo}");
exit();
?>
