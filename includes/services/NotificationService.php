<?php
require_once __DIR__ . '/QueueService.php';
require_once __DIR__ . '/RealtimeEventService.php';

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

        $notification = self::normalizeNotification($userId, $driverId, $title, $message);
        if ($notification === null) {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO notifications (user_id, driver_id, title, message)
             VALUES (?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        $userId = $notification['user_id'];
        $driverId = $notification['driver_id'];
        $title = $notification['title'];
        $message = $notification['message'];
        mysqli_stmt_bind_param($stmt, "iiss", $userId, $driverId, $title, $message);
        $ok = mysqli_stmt_execute($stmt);
        if ($ok) {
            self::publishNotificationEvent($conn, (int) mysqli_insert_id($conn), $notification);
        }

        return $ok;
    }

    public static function createAsync($conn, $userId, $driverId, $title, $message, array $options = []): ?int
    {
        if (!$conn instanceof mysqli) {
            return null;
        }

        $notification = self::normalizeNotification($userId, $driverId, $title, $message);
        if ($notification === null) {
            return null;
        }

        $jobId = RideSyncQueueService::enqueue($conn, 'notification.create', $notification, [
            'queue_name' => $options['queue_name'] ?? 'notifications',
            'max_attempts' => $options['max_attempts'] ?? 5,
            'available_at' => $options['available_at'] ?? null,
        ]);

        if ($jobId !== null) {
            return $jobId;
        }

        return self::create($conn, $userId, $driverId, $title, $message) ? 0 : null;
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

    private static function normalizeNotification($userId, $driverId, $title, $message): ?array
    {
        $userId = $userId !== null ? (int) $userId : null;
        $driverId = $driverId !== null ? (int) $driverId : null;
        if ($userId === null && $driverId === null) {
            return null;
        }

        $title = self::truncate((string) $title, 120);
        $message = self::truncate((string) $message, 255);
        if ($title === '' || $message === '') {
            return null;
        }

        return [
            'user_id' => $userId,
            'driver_id' => $driverId,
            'title' => $title,
            'message' => $message,
        ];
    }

    private static function publishNotificationEvent($conn, int $notificationId, array $notification): void
    {
        $payload = [
            'notification_id' => $notificationId,
            'title' => $notification['title'],
            'message' => $notification['message'],
            'unread_delta' => 1,
            'created_at' => date('c'),
        ];
        $options = [
            'aggregate_type' => 'notification',
            'aggregate_id' => $notificationId,
            'idempotency_key' => 'notification-created-' . $notificationId,
        ];

        if (!empty($notification['user_id'])) {
            RideSyncRealtimeEventService::publishForUser($conn, (int) $notification['user_id'], 'notification.created', $payload, $options);
        }
        if (!empty($notification['driver_id'])) {
            RideSyncRealtimeEventService::publishForDriver($conn, (int) $notification['driver_id'], 'notification.created', $payload, $options);
        }
    }

    private static function truncate(string $value, int $length): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
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
