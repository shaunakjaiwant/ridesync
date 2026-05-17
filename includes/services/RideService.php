<?php
require_once __DIR__ . '/RideStateMachine.php';

class RideSyncRideService
{
    public static function ensureLiveStatus($conn, int $rideId, string $status = RideSyncRideStateMachine::SEARCHING): bool
    {
        if ($rideId <= 0 || !RideSyncRideStateMachine::isValidLiveStatus($status) || !self::tableExists($conn, 'ride_live_status')) {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO ride_live_status (ride_id, live_status)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE live_status = live_status"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "is", $rideId, $status);
        return mysqli_stmt_execute($stmt);
    }

    public static function updateLiveStatus($conn, int $rideId, string $status, ?string $note = null, ?int $driverId = null, ?int $etaMinutes = null): bool
    {
        if ($rideId <= 0 || !RideSyncRideStateMachine::isValidLiveStatus($status) || !self::tableExists($conn, 'ride_live_status')) {
            return false;
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO ride_live_status (ride_id, driver_id, live_status, eta_minutes, note)
             VALUES (?, ?, ?, ?, ?)
             ON DUPLICATE KEY UPDATE
                 driver_id = COALESCE(VALUES(driver_id), driver_id),
                 live_status = VALUES(live_status),
                 eta_minutes = COALESCE(VALUES(eta_minutes), eta_minutes),
                 note = VALUES(note),
                 updated_at = CURRENT_TIMESTAMP"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "iisis", $rideId, $driverId, $status, $etaMinutes, $note);
        return mysqli_stmt_execute($stmt);
    }

    public static function recordDriverTrip($conn, int $driverId, string $pickup, string $drop, float $fare, $distanceKm, ?string $sourceType = null, ?int $sourceId = null): bool
    {
        if (!$conn instanceof mysqli || !self::tableExists($conn, 'driver_ride_history')) {
            return false;
        }

        $pickup = trim($pickup);
        $drop = trim($drop);
        $fare = max(0, $fare);
        $distanceKm = is_numeric($distanceKm) ? round(max(0, min(1000, (float) $distanceKm)), 2) : 0;
        $sourceId = $sourceId !== null ? (int) $sourceId : null;
        $sourceType = in_array($sourceType, ['direct_request', 'community_ride'], true) ? $sourceType : null;

        if ($driverId <= 0 || $pickup === '' || $drop === '') {
            return false;
        }

        $hasSourceColumns = self::columnExists($conn, 'driver_ride_history', 'source_type')
            && self::columnExists($conn, 'driver_ride_history', 'source_id');

        if ($hasSourceColumns && $sourceType !== null && $sourceId !== null && $sourceId > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT id
                 FROM driver_ride_history
                 WHERE driver_id = ? AND source_type = ? AND source_id = ?
                 LIMIT 1"
            );
            mysqli_stmt_bind_param($stmt, "isi", $driverId, $sourceType, $sourceId);
            mysqli_stmt_execute($stmt);
            if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
                return true;
            }

            $stmt = mysqli_prepare(
                $conn,
                "INSERT INTO driver_ride_history
                    (driver_id, pickup, drop_location, fare, distance_km, source_type, source_id)
                 VALUES (?, ?, ?, ?, ?, ?, ?)"
            );
            if (!$stmt) {
                return false;
            }
            mysqli_stmt_bind_param($stmt, "issddsi", $driverId, $pickup, $drop, $fare, $distanceKm, $sourceType, $sourceId);
            return mysqli_stmt_execute($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO driver_ride_history (driver_id, pickup, drop_location, fare, distance_km)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "issdd", $driverId, $pickup, $drop, $fare, $distanceKm);
        return mysqli_stmt_execute($stmt);
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

    private static function columnExists($conn, string $table, string $column): bool
    {
        if (!$conn instanceof mysqli) {
            return false;
        }
        if (function_exists('ridesync_column_exists')) {
            return ridesync_column_exists($conn, $table, $column);
        }

        $safeTable = mysqli_real_escape_string($conn, $table);
        $safeColumn = mysqli_real_escape_string($conn, $column);
        $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
        return $result && mysqli_num_rows($result) > 0;
    }
}

?>
