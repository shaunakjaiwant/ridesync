<?php
return [
    'version' => '2026_07_30_000009_create_sos_alerts_table',
    'description' => 'Create sos_alerts table for emergency admin notifications',
    'up' => function ($conn) {
        $rideIdType = function_exists('schema_id_type') ? schema_id_type($conn, 'rides') : 'INT';

        $sql = "CREATE TABLE IF NOT EXISTS sos_alerts (
            id INT UNSIGNED NOT NULL AUTO_INCREMENT,
            ride_id {$rideIdType} NOT NULL,
            triggered_by_type ENUM('user', 'driver') NOT NULL,
            triggered_by_id INT NOT NULL,
            latitude DECIMAL(10, 7) NULL,
            longitude DECIMAL(10, 7) NULL,
            status ENUM('active', 'resolved') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            resolved_at TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (id),
            KEY idx_sos_alerts_status_created (status, created_at),
            KEY idx_sos_alerts_ride (ride_id),
            CONSTRAINT fk_sos_alerts_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return mysqli_query($conn, $sql);
    }
];
