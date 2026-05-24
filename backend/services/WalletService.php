<?php

namespace RideSync\Backend\Services;

use mysqli;

final class WalletService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function recordFareDue(int $userId, int $rideId, ?int $driverId, float $amount, string $description, string $referenceType, int $referenceId): bool
    {
        return ridesync_wallet_record_fare_due($this->conn, $userId, $rideId, $driverId, $amount, $description, $referenceType, $referenceId);
    }
}
