<?php

function ridesync_e($value) {
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function ridesync_flash($key, $class) {
    if (!isset($_SESSION[$key])) {
        return;
    }

    $message = $_SESSION[$key];
    unset($_SESSION[$key]);

    echo '<div class="alert ' . ridesync_e($class) . '">' . ridesync_e($message) . '</div>';
}

function ridesync_unread_notification_count($conn, string $actorColumn, int $actorId): int {
    if (!$conn instanceof mysqli || !in_array($actorColumn, ['user_id', 'driver_id'], true) || $actorId <= 0) {
        return 0;
    }

    $cacheKey = $actorColumn . ':' . $actorId;
    $cache = $_SESSION['_unread_notification_cache'][$cacheKey] ?? null;
    if (is_array($cache) && (int) ($cache['expires_at'] ?? 0) > time()) {
        return max(0, (int) ($cache['count'] ?? 0));
    }

    try {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM notifications
             WHERE {$actorColumn} = ? AND is_read = 0"
        );
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, 'i', $actorId);
        mysqli_stmt_execute($stmt);
        $count = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
    } catch (Throwable $exception) {
        $count = 0;
    }

    $_SESSION['_unread_notification_cache'][$cacheKey] = [
        'count' => $count,
        'expires_at' => time() + 10,
    ];

    return $count;
}

function ridesync_forget_notification_count_cache(string $actorColumn = '', int $actorId = 0): void {
    if (!isset($_SESSION['_unread_notification_cache'])) {
        return;
    }

    if ($actorColumn === '' || $actorId <= 0) {
        unset($_SESSION['_unread_notification_cache']);
        return;
    }

    unset($_SESSION['_unread_notification_cache'][$actorColumn . ':' . $actorId]);
}

?>
