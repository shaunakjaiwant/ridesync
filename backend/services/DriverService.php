<?php

namespace RideSync\Backend\Services;

use mysqli;

final class DriverService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function recordTrip(int $driverId, string $pickup, string $drop, float $fare, $distanceKm, ?string $sourceType = null, ?int $sourceId = null): bool
    {
        return ridesync_record_driver_trip($this->conn, $driverId, $pickup, $drop, $fare, $distanceKm, $sourceType, $sourceId);
    }
}
