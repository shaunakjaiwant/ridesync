<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/tracking_helper.php';

/**
 * Trigger an emergency SOS alert for a ride.
 */
function ridesync_create_sos_alert($conn, int $rideId, string $triggeredByType, int $triggeredById, ?float $lat = null, ?float $lng = null): int|false {
    if ($rideId <= 0 || $triggeredById <= 0) {
        return false;
    }

    if (!in_array($triggeredByType, ['user', 'driver'], true)) {
        return false;
    }

    // If coordinates not passed, attempt to grab latest location ping from live tracking
    if ($lat === null || $lng === null) {
        $latestLoc = ridesync_get_latest_ride_location($conn, $rideId);
        if ($latestLoc) {
            $lat = $latestLoc['latitude'];
            $lng = $latestLoc['longitude'];
        }
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO sos_alerts (ride_id, triggered_by_type, triggered_by_id, latitude, longitude, status, created_at)
         VALUES (?, ?, ?, ?, ?, 'active', CURRENT_TIMESTAMP)"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "isidd", $rideId, $triggeredByType, $triggeredById, $lat, $lng);
    $result = mysqli_stmt_execute($stmt);
    $alertId = $result ? mysqli_insert_id($conn) : false;
    mysqli_stmt_close($stmt);

    if ($alertId) {
        ridesync_publish_realtime_sos_event($conn, (int) $alertId, $rideId, $triggeredByType, $triggeredById, $lat, $lng);
    }

    return $alertId;
}

/**
 * Publish real-time event to realtime_events table targeted at admin listeners.
 */
function ridesync_publish_realtime_sos_event($conn, int $alertId, int $rideId, string $triggeredByType, int $triggeredById, ?float $lat, ?float $lng): bool {
    if (!$conn instanceof mysqli) {
        return false;
    }

    try {
        if (!function_exists('ridesync_table_exists') || !ridesync_table_exists($conn, 'realtime_events')) {
            return false;
        }

    $payload = json_encode([
        'alert_id' => $alertId,
        'ride_id' => $rideId,
        'triggered_by_type' => $triggeredByType,
        'triggered_by_id' => $triggeredById,
        'latitude' => $lat,
        'longitude' => $lng,
        'created_at' => date('Y-m-d H:i:s'),
    ]);

    $idempotencyKey = 'sos_alert_' . $alertId;
    $stmt = mysqli_prepare($conn,
        "INSERT INTO realtime_events (event_type, audience_type, aggregate_type, aggregate_id, payload_json, idempotency_key)
         VALUES ('sos_alert', 'admin', 'ride', ?, ?, ?)
         ON DUPLICATE KEY UPDATE payload_json = VALUES(payload_json)"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "iss", $rideId, $payload, $idempotencyKey);
    $ok = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool) $ok;
    } catch (Throwable $e) {
        return false;
    }
}

/**
 * Resolve an active SOS alert.
 */
function ridesync_resolve_sos_alert($conn, int $alertId): bool {
    if ($alertId <= 0) {
        return false;
    }

    $stmt = mysqli_prepare($conn,
        "UPDATE sos_alerts
         SET status = 'resolved', resolved_at = CURRENT_TIMESTAMP
         WHERE id = ? AND status = 'active'"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $alertId);
    $result = mysqli_stmt_execute($stmt);
    $affected = mysqli_stmt_affected_rows($conn);
    mysqli_stmt_close($stmt);

    return $affected > 0;
}

/**
 * Fetch active SOS alerts for admin dashboard visibility.
 */
function ridesync_get_active_sos_alerts($conn): array {
    $sql = "SELECT s.id, s.ride_id, s.triggered_by_type, s.triggered_by_id, s.latitude, s.longitude, s.status, s.created_at,
                   r.origin, r.destination, r.travel_date, r.travel_time,
                   CASE WHEN s.triggered_by_type = 'user' THEN u.name ELSE d.name END AS triggerer_name,
                   CASE WHEN s.triggered_by_type = 'user' THEN u.email ELSE d.email END AS triggerer_contact
            FROM sos_alerts s
            JOIN rides r ON r.id = s.ride_id
            LEFT JOIN users u ON (s.triggered_by_type = 'user' AND u.id = s.triggered_by_id)
            LEFT JOIN driver_accounts d ON (s.triggered_by_type = 'driver' AND d.id = s.triggered_by_id)
            WHERE s.status = 'active'
            ORDER BY s.created_at DESC";

    $res = mysqli_query($conn, $sql);
    $alerts = [];
    if ($res) {
        require_once __DIR__ . '/emergency_contact_helper.php';
        while ($row = mysqli_fetch_assoc($res)) {
            $contacts = ridesync_get_user_emergency_contacts($conn, $row['triggered_by_type'], (int) $row['triggered_by_id']);
            $row['emergency_contacts'] = $contacts;
            $alerts[] = $row;
        }
    }

    return $alerts;
}

/**
 * Fetch single SOS alert details.
 */
function ridesync_get_sos_alert_by_id($conn, int $alertId): ?array {
    if ($alertId <= 0) {
        return null;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT s.*, r.origin, r.destination
         FROM sos_alerts s
         JOIN rides r ON r.id = s.ride_id
         WHERE s.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $alertId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $row = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    return $row;
}
