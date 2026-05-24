<?php

namespace RideSync\Backend\Services;

use mysqli;

final class AdminService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function fetchActive(int $adminId): ?array
    {
        $admin = ridesync_fetch_admin($this->conn, $adminId);
        return $admin && ($admin['status'] ?? null) === 'active' ? $admin : null;
    }
}
