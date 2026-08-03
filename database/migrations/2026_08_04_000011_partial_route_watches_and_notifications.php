<?php
return [
    'version' => '2026_08_04_000011_partial_route_watches_and_notifications',
    'description' => 'Create route_watches table, notifications table extensions, and matches table snapped pickup/dropoff & detour columns',
    'up' => function ($conn) {
        $userIdType = function_exists('schema_id_type') ? schema_id_type($conn, 'users') : 'INT';
        $rideIdType = function_exists('schema_id_type') ? schema_id_type($conn, 'rides') : 'INT';

        // 1. Create route_watches table
        $sqlWatches = "CREATE TABLE IF NOT EXISTS route_watches (
            id INT NOT NULL AUTO_INCREMENT,
            user_id {$userIdType} NOT NULL,
            origin VARCHAR(255) NOT NULL,
            destination VARCHAR(255) NOT NULL,
            origin_lat DECIMAL(10, 8) NOT NULL,
            origin_lng DECIMAL(11, 8) NOT NULL,
            destination_lat DECIMAL(10, 8) NOT NULL,
            destination_lng DECIMAL(11, 8) NOT NULL,
            travel_date DATE NOT NULL,
            travel_time TIME NULL,
            status ENUM('active', 'expired', 'fulfilled') NOT NULL DEFAULT 'active',
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_route_watches_date_status (travel_date, status),
            KEY idx_route_watches_user_status (user_id, status),
            CONSTRAINT fk_route_watches_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!mysqli_query($conn, $sqlWatches)) {
            return false;
        }

        // 2. Create notifications table if not exists
        $sqlNotifications = "CREATE TABLE IF NOT EXISTS notifications (
            id INT NOT NULL AUTO_INCREMENT,
            user_id {$userIdType} NULL,
            driver_id INT NULL,
            user_type ENUM('user', 'driver') NOT NULL DEFAULT 'user',
            type VARCHAR(60) NOT NULL DEFAULT 'route_match_found',
            title VARCHAR(120) NOT NULL,
            message VARCHAR(500) NOT NULL,
            related_ride_id {$rideIdType} NULL,
            related_match_id INT NULL,
            is_read TINYINT(1) NOT NULL DEFAULT 0,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            KEY idx_notifications_user (user_id, is_read, created_at),
            KEY idx_notifications_driver (driver_id, is_read, created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        if (!mysqli_query($conn, $sqlNotifications)) {
            return false;
        }

        // Ensure columns exist if notifications table was already created in earlier schema
        $cols = [
            'user_type' => "ALTER TABLE notifications ADD COLUMN user_type ENUM('user', 'driver') NOT NULL DEFAULT 'user' AFTER driver_id",
            'type' => "ALTER TABLE notifications ADD COLUMN type VARCHAR(60) NOT NULL DEFAULT 'route_match_found' AFTER user_type",
            'related_ride_id' => "ALTER TABLE notifications ADD COLUMN related_ride_id {$rideIdType} NULL AFTER message",
            'related_match_id' => "ALTER TABLE notifications ADD COLUMN related_match_id INT NULL AFTER related_ride_id",
        ];

        foreach ($cols as $colName => $alterSql) {
            $chk = mysqli_query($conn, "SHOW COLUMNS FROM notifications LIKE '{$colName}'");
            if ($chk && mysqli_num_rows($chk) === 0) {
                mysqli_query($conn, $alterSql);
            }
        }

        // 3. Extend matches table
        $matchCols = [
            'pickup_lat' => "ALTER TABLE matches ADD COLUMN pickup_lat DECIMAL(10,8) NULL AFTER match_score",
            'pickup_lng' => "ALTER TABLE matches ADD COLUMN pickup_lng DECIMAL(11,8) NULL AFTER pickup_lat",
            'dropoff_lat' => "ALTER TABLE matches ADD COLUMN dropoff_lat DECIMAL(10,8) NULL AFTER pickup_lng",
            'dropoff_lng' => "ALTER TABLE matches ADD COLUMN dropoff_lng DECIMAL(11,8) NULL AFTER dropoff_lat",
            'detour_distance_km' => "ALTER TABLE matches ADD COLUMN detour_distance_km DECIMAL(8,2) NULL AFTER dropoff_lng",
            'detour_time_minutes' => "ALTER TABLE matches ADD COLUMN detour_time_minutes INT NULL AFTER detour_distance_km",
            'source' => "ALTER TABLE matches ADD COLUMN source ENUM('search', 'route_watch') NOT NULL DEFAULT 'search' AFTER detour_time_minutes",
        ];

        foreach ($matchCols as $colName => $alterSql) {
            $chk = mysqli_query($conn, "SHOW COLUMNS FROM matches LIKE '{$colName}'");
            if ($chk && mysqli_num_rows($chk) === 0) {
                mysqli_query($conn, $alterSql);
            }
        }

        return true;
    }
];
