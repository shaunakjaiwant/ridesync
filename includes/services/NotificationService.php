<?php

class RideSyncNotificationService
{
    public static function schemaReady($conn): bool
    {
        return self::tableExists($conn, 'notifications');
    }

    public static function create($conn, $userId, $driverId, $title, $message): bool
    {
        if (!$conn instanceof mysqli || ($userId === null && $driverId === null) || !self::schemaReady($conn)) {
            return false;
        }

        $title = trim((string) $title);
        $message = trim((string) $message);
        if ($title === '' || $message === '') {
            return false;
        }

        $title = function_exists('mb_substr') ? mb_substr($title, 0, 120) : substr($title, 0, 120);
        $message = function_exists('mb_substr') ? mb_substr($message, 0, 255) : substr($message, 0, 255);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO notifications (user_id, driver_id, title, message)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        $userId = $userId !== null ? (int) $userId : null;
        $driverId = $driverId !== null ? (int) $driverId : null;
        mysqli_stmt_bind_param($stmt, "iiss", $userId, $driverId, $title, $message);
        return mysqli_stmt_execute($stmt);
    }

    public static function markOneRead($conn, string $actorColumn, int $actorId, int $notificationId): int
    {
        return self::mutateScoped($conn, "UPDATE notifications SET is_read = 1 WHERE id = ? AND {$actorColumn} = ?", $actorColumn, $actorId, $notificationId);
    }

    public static function deleteOne($conn, string $actorColumn, int $actorId, int $notificationId): int
    {
        return self::mutateScoped($conn, "DELETE FROM notifications WHERE id = ? AND {$actorColumn} = ?", $actorColumn, $actorId, $notificationId);
    }

    public static function clearRead($conn, string $actorColumn, int $actorId): int
    {
        return self::mutateActor($conn, "DELETE FROM notifications WHERE {$actorColumn} = ? AND is_read = 1", $actorColumn, $actorId);
    }

    public static function clearAll($conn, string $actorColumn, int $actorId): int
    {
        return self::mutateActor($conn, "DELETE FROM notifications WHERE {$actorColumn} = ?", $actorColumn, $actorId);
    }

    public static function markAllRead($conn, string $actorColumn, int $actorId): int
    {
        return self::mutateActor($conn, "UPDATE notifications SET is_read = 1 WHERE {$actorColumn} = ? AND is_read = 0", $actorColumn, $actorId);
    }

    private static function mutateScoped($conn, string $sql, string $actorColumn, int $actorId, int $notificationId): int
    {
        if ($notificationId <= 0 || !self::isActorColumn($actorColumn) || !self::schemaReady($conn)) {
            return 0;
        }

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, "ii", $notificationId, $actorId);
        mysqli_stmt_execute($stmt);
        return max(0, mysqli_stmt_affected_rows($stmt));
    }

    private static function mutateActor($conn, string $sql, string $actorColumn, int $actorId): int
    {
        if (!self::isActorColumn($actorColumn) || !self::schemaReady($conn)) {
            return 0;
        }

        $stmt = mysqli_prepare($conn, $sql);
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, "i", $actorId);
        mysqli_stmt_execute($stmt);
        return max(0, mysqli_stmt_affected_rows($stmt));
    }

    private static function isActorColumn(string $actorColumn): bool
    {
        return in_array($actorColumn, ['user_id', 'driver_id'], true);
    }

    private static function tableExists($conn, string $table): bool
    {
        if (!$conn instanceof mysqli) {
            return false;
        }

        if (function_exists('ridesync_table_exists')) {
            return ridesync_table_exists($conn, $table);
        }

        $safeTable = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
        return $result && mysqli_num_rows($result) > 0;
    }
}

?>
