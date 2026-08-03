<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/sos_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

$triggeredByType = null;
$triggeredById = 0;

if (isset($_SESSION['user_id'])) {
    $triggeredByType = 'user';
    $triggeredById = (int) $_SESSION['user_id'];
} elseif (isset($_SESSION['driver_id'])) {
    $triggeredByType = 'driver';
    $triggeredById = (int) $_SESSION['driver_id'];
} elseif (isset($_SESSION['admin_id'])) {
    $triggeredByType = 'admin';
    $triggeredById = (int) $_SESSION['admin_id'];
}

if (!$triggeredByType || $triggeredById <= 0) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['error'] = "Invalid CSRF token. Please try again.";
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

$action = trim($_POST['action'] ?? 'trigger');

if ($action === 'resolve') {
    $alertId = (int) ($_POST['alert_id'] ?? 0);
    $returnTo = trim($_POST['return_to'] ?? '/ridesync/pages/admin_dashboard.php');

    if ($alertId > 0 && ridesync_resolve_sos_alert($conn, $alertId)) {
        $_SESSION['success'] = "SOS alert #" . $alertId . " resolved.";
    } else {
        $_SESSION['error'] = "Could not resolve SOS alert.";
    }

    header("Location: " . $returnTo);
    exit();
}

$rideId = (int) ($_POST['ride_id'] ?? 0);
$lat = isset($_POST['latitude']) && $_POST['latitude'] !== '' ? (float) $_POST['latitude'] : null;
$lng = isset($_POST['longitude']) && $_POST['longitude'] !== '' ? (float) $_POST['longitude'] : null;

if ($rideId <= 0) {
    $_SESSION['error'] = "Invalid ride specified for SOS alert.";
    header("Location: /ridesync/pages/dashboard.php");
    exit();
}

// Rate Limiting
$rateKey = ridesync_client_ip() . '|sos_trigger|' . $triggeredByType . '_' . $triggeredById;
ridesync_enforce_rate_limit('action:sos_trigger', 5, 60, $rateKey, [
    'message' => 'Too many SOS requests. Please wait a moment.',
]);

$alertId = ridesync_create_sos_alert($conn, $rideId, $triggeredByType, $triggeredById, $lat, $lng);

if ($alertId) {
    $_SESSION['success'] = "🚨 EMERGENCY SOS ALERT TRANSMITTED TO PLATFORM ADMINS. Stay safe!";
} else {
    $_SESSION['error'] = "Failed to record SOS alert. If this is an emergency, please call 112 / 911 immediately.";
}

header("Location: /ridesync/pages/ride_detail.php?id=" . $rideId);
exit();
