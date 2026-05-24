<?php

namespace RideSync\Backend\Repositories;

final class RideRepository extends BaseRepository
{
    public function findOwnedRideForUpdate(int $rideId, int $userId): ?array
    {
        $stmt = mysqli_prepare(
            $this->conn,
            "SELECT r.id, r.user_id, r.status AS ride_status, r.origin, r.destination,
                    r.route_distance_km,
                    COALESCE(ls.live_status, 'searching') AS live_status,
                    ls.driver_id
             FROM rides r
             LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
             WHERE r.id = ? AND r.user_id = ?
             LIMIT 1
             FOR UPDATE"
        );
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, 'ii', $rideId, $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

        return $row ?: null;
    }

    public function acceptedUserIds(int $rideId): array
    {
        $stmt = mysqli_prepare(
            $this->conn,
            "SELECT matched_user_id
             FROM matches
             WHERE ride_id = ? AND status = 'accepted'"
        );
        if (!$stmt) {
            return [];
        }

        mysqli_stmt_bind_param($stmt, 'i', $rideId);
        mysqli_stmt_execute($stmt);
        $result = mysqli_stmt_get_result($stmt);
        $ids = [];
        while ($row = $result ? mysqli_fetch_assoc($result) : null) {
            $ids[] = (int) $row['matched_user_id'];
        }

        return $ids;
    }

    public function closeRide(int $rideId): bool
    {
        $stmt = mysqli_prepare($this->conn, "UPDATE rides SET status = 'closed' WHERE id = ?");
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, 'i', $rideId);
        return mysqli_stmt_execute($stmt);
    }
}
