<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/view_helper.php';
require_once __DIR__ . '/../includes/services/NotificationService.php';

function ridesync_notification_redirect() {
    ridesync_forget_notification_count_cache();
    $actor = $_POST['actor_type'] ?? '';
    $suffix = in_array($actor, ['user', 'driver'], true) ? '?actor_type=' . urlencode($actor) : '';
    header("Location: /ridesync/pages/notifications.php" . $suffix);
    exit();
}

function ridesync_notification_actor() {
    $requestedActor = $_REQUEST['actor_type'] ?? '';

    if ($requestedActor === 'driver' && isset($_SESSION['driver_id'])) {
        return ['driver_id', (int) $_SESSION['driver_id']];
    }

    if ($requestedActor === 'user' && isset($_SESSION['user_id'])) {
        return ['user_id', (int) $_SESSION['user_id']];
    }

    if (isset($_SESSION['driver_id'])) {
        return ['driver_id', (int) $_SESSION['driver_id']];
    }

    return ['user_id', (int) ($_SESSION['user_id'] ?? 0)];
}

if (!isset($_SESSION['user_id']) && !isset($_SESSION['driver_id'])) {
    if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll_unread'])) {
        header('Content-Type: application/json');
        echo json_encode(['unread_count' => 0]);
        exit();
    }
    header("Location: /ridesync/index.php");
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'GET' && isset($_GET['poll_unread'])) {
    header('Content-Type: application/json');
    [$column, $actorId] = ridesync_notification_actor();
    $count = ridesync_unread_notification_count($conn, $column, $actorId);
    echo json_encode(['unread_count' => (int) $count]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    ridesync_notification_redirect();
}

if (!ridesync_csrf_is_valid()) {
    $_SESSION['notification_error'] = "Invalid request. Please try again.";
    ridesync_notification_redirect();
}

if (!RideSyncNotificationService::schemaReady($conn)) {
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

    $_SESSION['notification_success'] = RideSyncNotificationService::markOneRead($conn, $column, $actorId, $notificationId) > 0
        ? "Notification marked as read."
        : "That notification was already read or is no longer available.";
    ridesync_notification_redirect();
}

if ($action === 'clear_one') {
    if ($notificationId <= 0) {
        $_SESSION['notification_error'] = "Choose a valid notification.";
        ridesync_notification_redirect();
    }

    $_SESSION['notification_success'] = RideSyncNotificationService::deleteOne($conn, $column, $actorId, $notificationId) > 0
        ? "Notification cleared."
        : "That notification is no longer available.";
    ridesync_notification_redirect();
}

if ($action === 'clear_read') {
    $_SESSION['notification_success'] = RideSyncNotificationService::clearRead($conn, $column, $actorId) > 0
        ? "Read notifications cleared."
        : "No read notifications to clear.";
    ridesync_notification_redirect();
}

if ($action === 'clear_all') {
    $_SESSION['notification_success'] = RideSyncNotificationService::clearAll($conn, $column, $actorId) > 0
        ? "Inbox cleared."
        : "Your inbox is already empty.";
    ridesync_notification_redirect();
}

if ($action !== 'mark_all_read') {
    $_SESSION['notification_error'] = "Unsupported notification action.";
    ridesync_notification_redirect();
}

$_SESSION['notification_success'] = RideSyncNotificationService::markAllRead($conn, $column, $actorId) > 0
    ? "All notifications marked as read."
    : "Your inbox is already up to date.";
ridesync_notification_redirect();
