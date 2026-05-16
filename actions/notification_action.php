<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

function ridesync_notification_redirect() {
    $actor = $_POST['actor_type'] ?? '';
    $suffix = in_array($actor, ['user', 'driver'], true) ? '?actor_type=' . urlencode($actor) : '';
    header("Location: /ridesync/pages/notifications.php" . $suffix);
    exit();
}

function ridesync_notification_actor() {
    $requestedActor = $_POST['actor_type'] ?? '';

    if ($requestedActor === 'driver' && isset($_SESSION['driver_id'])) {
        return ['driver_id', (int) $_SESSION['driver_id']];
    }

    if ($requestedActor === 'user' && isset($_SESSION['user_id'])) {
        return ['user_id', (int) $_SESSION['user_id']];
    }

    if (isset($_SESSION['driver_id'])) {
        return ['driver_id', (int) $_SESSION['driver_id']];
    }

    return ['user_id', (int) $_SESSION['user_id']];
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['driver_id'])) {
    header("Location: /ridesync/index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ridesync_notification_redirect();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['notification_error'] = "Invalid request. Please try again.";
    ridesync_notification_redirect();
}

$notificationsTable = mysqli_query($conn, "SHOW TABLES LIKE 'notifications'");
if (!$notificationsTable || mysqli_num_rows($notificationsTable) === 0) {
    $_SESSION['notification_error'] = "Notifications are not available yet.";
    ridesync_notification_redirect();
}

$action = $_POST['action_type'] ?? 'mark_all_read';
$notificationId = (int) ($_POST['notification_id'] ?? 0);
[$column, $actorId] = ridesync_notification_actor();
ridesync_enforce_rate_limit('notifications:mutate', 90, 60, $column . ':' . $actorId, [
    'redirect' => '/ridesync/pages/notifications.php?actor_type=' . urlencode($column === 'driver_id' ? 'driver' : 'user'),
    'flash_key' => 'notification_error',
    'message' => 'Too many notification actions. Please wait briefly and try again.',
]);

if ($action === 'mark_one_read') {
    if ($notificationId <= 0) {
        $_SESSION['notification_error'] = "Choose a valid notification.";
        ridesync_notification_redirect();
    }

    $sql = "UPDATE notifications SET is_read = 1 WHERE id = ? AND {$column} = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $notificationId, $actorId);
    mysqli_stmt_execute($stmt);

    $_SESSION['notification_success'] = mysqli_stmt_affected_rows($stmt) > 0
        ? "Notification marked as read."
        : "That notification was already read or is no longer available.";
    ridesync_notification_redirect();
}

if ($action === 'clear_one') {
    if ($notificationId <= 0) {
        $_SESSION['notification_error'] = "Choose a valid notification.";
        ridesync_notification_redirect();
    }

    $sql = "DELETE FROM notifications WHERE id = ? AND {$column} = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "ii", $notificationId, $actorId);
    mysqli_stmt_execute($stmt);

    $_SESSION['notification_success'] = mysqli_stmt_affected_rows($stmt) > 0
        ? "Notification cleared."
        : "That notification is no longer available.";
    ridesync_notification_redirect();
}

if ($action === 'clear_read') {
    $sql = "DELETE FROM notifications WHERE {$column} = ? AND is_read = 1";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $actorId);
    mysqli_stmt_execute($stmt);

    $_SESSION['notification_success'] = mysqli_stmt_affected_rows($stmt) > 0
        ? "Read notifications cleared."
        : "No read notifications to clear.";
    ridesync_notification_redirect();
}

if ($action === 'clear_all') {
    $sql = "DELETE FROM notifications WHERE {$column} = ?";
    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, "i", $actorId);
    mysqli_stmt_execute($stmt);

    $_SESSION['notification_success'] = mysqli_stmt_affected_rows($stmt) > 0
        ? "Inbox cleared."
        : "Your inbox is already empty.";
    ridesync_notification_redirect();
}

if ($action !== 'mark_all_read') {
    $_SESSION['notification_error'] = "Unsupported notification action.";
    ridesync_notification_redirect();
}

$sql = "UPDATE notifications SET is_read = 1 WHERE {$column} = ? AND is_read = 0";
$stmt = mysqli_prepare($conn, $sql);
mysqli_stmt_bind_param($stmt, "i", $actorId);
mysqli_stmt_execute($stmt);

$_SESSION['notification_success'] = mysqli_stmt_affected_rows($stmt) > 0
    ? "All notifications marked as read."
    : "Your inbox is already up to date.";
ridesync_notification_redirect();
?>
