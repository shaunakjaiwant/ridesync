<?php
require_once __DIR__ . '/../config/db.php';

/**
 * Record a new GPS location ping for an active ride.
 */
function ridesync_insert_location_ping($conn, int $rideId, ?int $driverId, float $lat, float $lng): bool {
    if ($rideId <= 0) {
        return false;
    }

    if ($lat < -90.0 || $lat > 90.0 || $lng < -180.0 || $lng > 180.0) {
        return false;
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO ride_locations (ride_id, driver_id, latitude, longitude, recorded_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "iidd", $rideId, $driverId, $lat, $lng);
    $result = mysqli_stmt_execute($stmt);
    mysqli_stmt_close($stmt);

    return (bool) $result;
}

/**
 * Fetch the most recent GPS location for a ride.
 */
function ridesync_get_latest_ride_location($conn, int $rideId): ?array {
    if ($rideId <= 0) {
        return null;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT id, ride_id, driver_id, latitude, longitude, recorded_at
         FROM ride_locations
         WHERE ride_id = ?
         ORDER BY recorded_at DESC, id DESC
         LIMIT 1"
    );

    if (!$stmt) {
        return null;
    }

    mysqli_stmt_bind_param($stmt, "i", $rideId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $location = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$location) {
        return null;
    }

    return [
        'id' => (int) $location['id'],
        'ride_id' => (int) $location['ride_id'],
        'driver_id' => $location['driver_id'] !== null ? (int) $location['driver_id'] : null,
        'latitude' => (float) $location['latitude'],
        'longitude' => (float) $location['longitude'],
        'recorded_at' => (string) $location['recorded_at'],
    ];
}

/**
 * Enforce strict server-side authorization for live tracking viewing.
 * Only the ride owner, accepted matched riders, assigned driver, or admin can track a ride.
 */
function ridesync_can_view_ride_tracking($conn, int $rideId, ?int $userId = null, ?int $driverId = null): bool {
    if ($rideId <= 0) {
        return false;
    }

    // 1. Fetch ride owner & assigned driver from live status
    $stmt = mysqli_prepare($conn,
        "SELECT r.user_id AS owner_id, ls.driver_id AS assigned_driver_id, ls.live_status
         FROM rides r
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE r.id = ?
         LIMIT 1"
    );

    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, "i", $rideId);
    mysqli_stmt_execute($stmt);
    $res = mysqli_stmt_get_result($stmt);
    $ride = $res ? mysqli_fetch_assoc($res) : null;
    mysqli_stmt_close($stmt);

    if (!$ride) {
        return false;
    }

    $ownerId = (int) $ride['owner_id'];
    $assignedDriverId = !empty($ride['assigned_driver_id']) ? (int) $ride['assigned_driver_id'] : null;

    // Check driver match
    if ($driverId !== null && $driverId > 0) {
        if ($assignedDriverId !== null && $driverId === $assignedDriverId) {
            return true;
        }
    }

    // Check user match
    if ($userId !== null && $userId > 0) {
        if ($userId === $ownerId) {
            return true;
        }

        // Check if accepted match
        $stmt = mysqli_prepare($conn,
            "SELECT id FROM matches WHERE ride_id = ? AND matched_user_id = ? AND status = 'accepted' LIMIT 1"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "ii", $rideId, $userId);
            mysqli_stmt_execute($stmt);
            $matchRes = mysqli_stmt_get_result($stmt);
            $isAcceptedMatch = $matchRes && mysqli_num_rows($matchRes) > 0;
            mysqli_stmt_close($stmt);

            if ($isAcceptedMatch) {
                return true;
            }
        }
    }

    return false;
}

/**
 * Lightweight cleanup: delete location pings older than given hours.
 */
function ridesync_cleanup_old_location_pings($conn, int $hoursOld = 24): int {
    $hoursOld = max(1, $hoursOld);
    $stmt = mysqli_prepare($conn,
        "DELETE FROM ride_locations WHERE recorded_at < DATE_SUB(NOW(), INTERVAL ? HOUR)"
    );

    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "i", $hoursOld);
    mysqli_stmt_execute($stmt);
    $deletedRows = mysqli_stmt_affected_rows($conn);
    mysqli_stmt_close($stmt);

    return max(0, $deletedRows);
}
