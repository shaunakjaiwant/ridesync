<?php

class RideSyncRealtimeEventService
{
    public static function schemaReady($conn): bool
    {
        return self::tableExists($conn, 'realtime_events');
    }

    public static function publish($conn, string $eventType, string $audienceType, ?int $audienceId = null, array $payload = [], array $options = []): ?int
    {
        if (!$conn instanceof mysqli || !self::schemaReady($conn)) {
            return null;
        }

        $eventType = self::normalizeToken($eventType, 100);
        $audienceType = self::normalizeToken($audienceType, 40);
        if ($eventType === '' || $audienceType === '') {
            return null;
        }

        $audienceId = $audienceId !== null && $audienceId > 0 ? (int) $audienceId : null;
        $aggregateType = self::nullableToken($options['aggregate_type'] ?? null, 60);
        $aggregateId = isset($options['aggregate_id']) && (int) $options['aggregate_id'] > 0 ? (int) $options['aggregate_id'] : null;
        $idempotencyKey = self::nullableToken($options['idempotency_key'] ?? null, 120);
        $expiresAt = self::normalizeExpiresAt($options['expires_at'] ?? '+7 days');
        $payloadJson = self::encodePayload(self::sanitizePayload($payload));
        if ($payloadJson === null) {
            return null;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT IGNORE INTO realtime_events
                (event_type, audience_type, audience_id, aggregate_type, aggregate_id, payload_json, idempotency_key, expires_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param(
            $stmt,
            "ssisisss",
            $eventType,
            $audienceType,
            $audienceId,
            $aggregateType,
            $aggregateId,
            $payloadJson,
            $idempotencyKey,
            $expiresAt
        );

        if (!mysqli_stmt_execute($stmt) || mysqli_stmt_affected_rows($stmt) !== 1) {
            return null;
        }

        return (int) mysqli_insert_id($conn);
    }

    public static function publishForUser($conn, int $userId, string $eventType, array $payload = [], array $options = []): ?int
    {
        return self::publish($conn, $eventType, 'user', $userId, $payload, $options);
    }

    public static function publishForDriver($conn, int $driverId, string $eventType, array $payload = [], array $options = []): ?int
    {
        return self::publish($conn, $eventType, 'driver', $driverId, $payload, $options);
    }

    public static function publishForAdmins($conn, string $eventType, array $payload = [], array $options = []): ?int
    {
        return self::publish($conn, $eventType, 'admin', null, $payload, $options);
    }

    public static function publishForRide($conn, int $rideId, string $eventType, array $payload = [], array $options = []): ?int
    {
        $options['aggregate_type'] = $options['aggregate_type'] ?? 'ride';
        $options['aggregate_id'] = $options['aggregate_id'] ?? $rideId;

        return self::publish($conn, $eventType, 'ride', $rideId, $payload, $options);
    }

    public static function recentForAudience($conn, string $audienceType, ?int $audienceId = null, int $afterId = 0, int $limit = 25): array
    {
        if (!$conn instanceof mysqli || !self::schemaReady($conn)) {
            return [];
        }

        $audienceType = self::normalizeToken($audienceType, 40);
        if ($audienceType === '') {
            return [];
        }

        $afterId = max(0, (int) $afterId);
        $limit = max(1, min(100, (int) $limit));

        if ($audienceId !== null && $audienceId > 0) {
            $audienceId = (int) $audienceId;
            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, event_type, audience_type, audience_id, aggregate_type, aggregate_id, payload_json, created_at
                 FROM realtime_events
                 WHERE audience_type = ?
                   AND (audience_id = ? OR audience_id IS NULL)
                   AND id > ?
                   AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 ORDER BY id ASC
                 LIMIT ?"
            );
            if (!$stmt) {
                return [];
            }
            mysqli_stmt_bind_param($stmt, "siii", $audienceType, $audienceId, $afterId, $limit);
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, event_type, audience_type, audience_id, aggregate_type, aggregate_id, payload_json, created_at
                 FROM realtime_events
                 WHERE audience_type = ?
                   AND audience_id IS NULL
                   AND id > ?
                   AND (expires_at IS NULL OR expires_at > CURRENT_TIMESTAMP)
                 ORDER BY id ASC
                 LIMIT ?"
            );
            if (!$stmt) {
                return [];
            }
            mysqli_stmt_bind_param($stmt, "sii", $audienceType, $afterId, $limit);
        }

        if (!mysqli_stmt_execute($stmt)) {
            return [];
        }

        $events = [];
        $result = mysqli_stmt_get_result($stmt);
        while ($row = $result ? mysqli_fetch_assoc($result) : null) {
            $events[] = self::formatEvent($row);
        }

        return $events;
    }

    public static function pruneExpired($conn, int $limit = 500): int
    {
        if (!$conn instanceof mysqli || !self::schemaReady($conn)) {
            return 0;
        }

        $limit = max(1, min(5000, (int) $limit));
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM realtime_events
             WHERE expires_at IS NOT NULL AND expires_at <= CURRENT_TIMESTAMP
             ORDER BY expires_at ASC
             LIMIT ?"
        );
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, "i", $limit);
        mysqli_stmt_execute($stmt);
        return max(0, mysqli_stmt_affected_rows($stmt));
    }

    private static function formatEvent(array $row): array
    {
        return [
            'id' => (int) $row['id'],
            'event_type' => (string) $row['event_type'],
            'audience_type' => (string) $row['audience_type'],
            'audience_id' => $row['audience_id'] !== null ? (int) $row['audience_id'] : null,
            'aggregate_type' => $row['aggregate_type'] !== null ? (string) $row['aggregate_type'] : null,
            'aggregate_id' => $row['aggregate_id'] !== null ? (int) $row['aggregate_id'] : null,
            'payload' => self::decodePayload((string) $row['payload_json']),
            'created_at' => (string) $row['created_at'],
        ];
    }

    private static function sanitizePayload(array $payload): array
    {
        $safe = [];
        foreach ($payload as $key => $value) {
            $key = substr(preg_replace('/[^A-Za-z0-9_.:-]/', '_', (string) $key), 0, 80);
            if ($key === '') {
                continue;
            }

            if (preg_match('/password|token|secret|aadhaar|pan|document_reference|file_path|private_key/i', $key)) {
                $safe[$key] = '[redacted]';
                continue;
            }

            if (is_array($value)) {
                $safe[$key] = self::sanitizePayload($value);
            } elseif (is_bool($value) || is_int($value) || is_float($value) || $value === null) {
                $safe[$key] = $value;
            } else {
                $safe[$key] = self::truncate((string) $value, 255);
            }
        }

        return $safe;
    }

    private static function normalizeToken(string $value, int $length): string
    {
        $value = strtolower(trim($value));
        if (!preg_match('/^[a-z0-9_.:-]+$/', $value)) {
            return '';
        }

        return substr($value, 0, $length);
    }

    private static function nullableToken($value, int $length): ?string
    {
        if ($value === null || trim((string) $value) === '') {
            return null;
        }

        $token = self::normalizeToken((string) $value, $length);
        return $token === '' ? null : $token;
    }

    private static function normalizeExpiresAt($value): ?string
    {
        if ($value === null || $value === false) {
            return null;
        }

        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        $timestamp = strtotime((string) $value);
        return $timestamp === false ? null : date('Y-m-d H:i:s', $timestamp);
    }

    private static function encodePayload(array $payload): ?string
    {
        $json = json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private static function decodePayload(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
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
