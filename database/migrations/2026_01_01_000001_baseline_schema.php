<?php
return [
    'version' => '2026_01_01_000001_baseline_schema',
    'description' => 'Ensure core RideSync baseline tables exist',
    'up' => function ($conn) {
        $tables = ['users', 'rides', 'matches', 'driver_accounts', 'driver_account_documents', 'driver_ride_requests', 'ride_live_status', 'notifications'];
        foreach ($tables as $t) {
            if (!ridesync_table_exists($conn, $t)) {
                return false;
            }
        }
        return true;
    }
];
