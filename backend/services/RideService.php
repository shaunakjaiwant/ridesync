<?php

namespace RideSync\Backend\Services;

use mysqli;

final class RideService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function updateLiveStatus(int $rideId, string $status, ?string $note = null, ?int $driverId = null, ?int $etaMinutes = null): bool
    {
        return ridesync_update_live_status($this->conn, $rideId, $status, $note, $driverId, $etaMinutes);
    }
}
