<?php
return [
    'version' => '2026_07_30_000008_create_ride_locations_table',
    'description' => 'Create ride_locations table for live GPS tracking pings',
    'up' => function ($conn) {
        $rideIdType = function_exists('schema_id_type') ? schema_id_type($conn, 'rides') : 'INT';
        $driverIdType = function_exists('schema_table_exists') && schema_table_exists($conn, 'driver_accounts') ? schema_id_type($conn, 'driver_accounts') : 'INT';

        $sql = "CREATE TABLE IF NOT EXISTS ride_locations (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ride_id {$rideIdType} NOT NULL,
            driver_id {$driverIdType} NULL,
            latitude DECIMAL(10, 7) NOT NULL,
            longitude DECIMAL(10, 7) NOT NULL,
            recorded_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_ride_locations_ride_time (ride_id, recorded_at),
            KEY idx_ride_locations_driver_time (driver_id, recorded_at),
            CONSTRAINT fk_ride_locations_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return mysqli_query($conn, $sql);
    }
];
