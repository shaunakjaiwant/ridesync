<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/emergency_contact_helper.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header("Location: /ridesync/pages/profile.php");
    exit();
}

$accountType = isset($_SESSION['driver_id']) ? 'driver' : 'rider';
$userId = ($accountType === 'driver') ? (int) $_SESSION['driver_id'] : (int) ($_SESSION['user_id'] ?? 0);
$redirect = ($accountType === 'driver') ? '/ridesync/pages/driver_dashboard.php' : '/ridesync/pages/profile.php';

if ($userId <= 0) {
    header("Location: /ridesync/pages/login.php");
    exit();
}

ridesync_require_csrf($redirect, 'profile_error');

$action = trim($_POST['action_type'] ?? 'add');

if ($action === 'add') {
    $name = trim($_POST['name'] ?? '');
    $relationship = trim($_POST['relationship'] ?? 'Family');
    $phone = trim($_POST['phone_number'] ?? '');
    $isPrimary = !empty($_POST['is_primary']);

    $res = ridesync_add_emergency_contact($conn, $accountType, $userId, $name, $relationship, $phone, $isPrimary);
    if ($res['ok']) {
        $_SESSION['profile_success'] = "Emergency contact added successfully.";
    } else {
        $_SESSION['profile_error'] = $res['error'];
    }
} elseif ($action === 'delete') {
    $contactId = (int) ($_POST['contact_id'] ?? 0);
    $res = ridesync_delete_emergency_contact($conn, $accountType, $userId, $contactId);
    if ($res['ok']) {
        $_SESSION['profile_success'] = "Emergency contact removed.";
    } else {
        $_SESSION['profile_error'] = $res['error'];
    }
}

header("Location: " . $redirect);
exit();
