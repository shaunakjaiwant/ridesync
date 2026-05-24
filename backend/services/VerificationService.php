<?php

namespace RideSync\Backend\Services;

use mysqli;

final class VerificationService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function startForDriver(int $driverId, string $source = 'manual'): ?int
    {
        return ridesync_verification_start_for_driver($this->conn, $driverId, $source);
    }

    public function processSession(int $sessionId): bool
    {
        return ridesync_verification_process_session($this->conn, $sessionId);
    }
}
