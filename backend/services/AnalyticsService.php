<?php

namespace RideSync\Backend\Services;

use mysqli;

final class AnalyticsService
{
    public function __construct(private mysqli $conn)
    {
    }

    public function adminDashboardMetrics(): array
    {
        if (class_exists('\RideSyncAdminMetricsRepository') && method_exists('\RideSyncAdminMetricsRepository', 'snapshot')) {
            return \RideSyncAdminMetricsRepository::snapshot($this->conn);
        }

        return [];
    }
}
