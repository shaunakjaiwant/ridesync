<?php

namespace RideSync\Backend\Services;

use mysqli;

final class MatchService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function rejectPendingForRide(int $rideId, ?int $excludedMatchId = null): int
    {
        return ridesync_reject_pending_matches($this->conn, $rideId, $excludedMatchId);
    }
}
