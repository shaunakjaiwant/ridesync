<?php
/**
 * In-App Notification Helper for RideSync
 * Manages route match alerts, unread counts, and notification inbox
 */

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/services/NotificationService.php';

/**
 * Create a new user notification for route matches or trip alerts.
 */
function ridesync_notify_user($conn, $userId, $title, $message, $relatedRideId = null, $relatedMatchId = null, $type = 'route_match_found') {
    if (!$conn instanceof mysqli || $userId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO notifications (user_id, user_type, type, title, message, related_ride_id, related_match_id, is_read)
         VALUES (?, 'user', ?, ?, ?, ?, ?, 0)"
    );
    if (!$stmt) {
        return false;
    }

    $relatedRideId = $relatedRideId !== null ? (int) $relatedRideId : null;
    $relatedMatchId = $relatedMatchId !== null ? (int) $relatedMatchId : null;

    mysqli_stmt_bind_param($stmt, "isssii", $userId, $type, $title, $message, $relatedRideId, $relatedMatchId);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return $ok;
}

/**
 * Fetch unread notification count for a user or driver.
 */
function ridesync_get_unread_notification_count($conn, $actorColumn, $actorId): int {
    if (!$conn instanceof mysqli || $actorId <= 0) {
        return 0;
    }

    $column = $actorColumn === 'driver_id' ? 'driver_id' : 'user_id';
    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM notifications WHERE {$column} = ? AND is_read = 0");
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "i", $actorId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    mysqli_stmt_close($stmt);

    return (int) ($row['total'] ?? 0);
}

/**
 * Fetch recent notifications list for a user.
 */
function ridesync_get_user_notifications($conn, $userId, $limit = 15): array {
    if (!$conn instanceof mysqli || $userId <= 0) {
        return [];
    }

    $limit = max(1, min(50, (int) $limit));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, type, title, message, related_ride_id, related_match_id, is_read, created_at
         FROM notifications
         WHERE user_id = ?
         ORDER BY created_at DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }

    mysqli_stmt_bind_param($stmt, "ii", $userId, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);
    $items = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $items[] = $row;
    }
    mysqli_stmt_close($stmt);

    return $items;
}

/**
 * Mark a specific notification as read.
 */
function ridesync_mark_notification_as_read($conn, $userId, $notificationId): bool {
    if (!$conn instanceof mysqli || $userId <= 0 || $notificationId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare($conn, "UPDATE notifications SET is_read = 1 WHERE id = ? AND user_id = ?");
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "ii", $notificationId, $userId);
    mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($stmt);
    mysqli_stmt_close($stmt);

    return $affected > 0;
}
