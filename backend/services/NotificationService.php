<?php

namespace RideSync\Backend\Services;

use mysqli;

final class NotificationService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function create($userId, $driverId, string $title, string $message): bool
    {
        return \RideSyncNotificationService::create($this->conn, $userId, $driverId, $title, $message);
    }

    public function createAsync($userId, $driverId, string $title, string $message, array $options = []): ?int
    {
        return \RideSyncNotificationService::createAsync($this->conn, $userId, $driverId, $title, $message, $options);
    }
}
