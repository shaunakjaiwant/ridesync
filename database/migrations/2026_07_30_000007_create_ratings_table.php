<?php
return [
    'version' => '2026_07_30_000007_create_ratings_table',
    'description' => 'Create ratings table for rider and driver reviews',
    'up' => function ($conn) {
        $rideIdType = function_exists('schema_id_type') ? schema_id_type($conn, 'rides') : 'INT';

        $sql = "CREATE TABLE IF NOT EXISTS ratings (
            id INT NOT NULL AUTO_INCREMENT,
            ride_id {$rideIdType} NOT NULL,
            rated_by_type ENUM('user', 'driver') NOT NULL,
            rated_by_id INT NOT NULL,
            rated_user_type ENUM('user', 'driver') NOT NULL,
            rated_user_id INT NOT NULL,
            score TINYINT UNSIGNED NOT NULL,
            comment VARCHAR(500) NULL,
            created_at TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (id),
            UNIQUE KEY uq_ratings_ride_direction (ride_id, rated_by_type, rated_by_id),
            KEY idx_ratings_rated_target (rated_user_type, rated_user_id, created_at),
            KEY idx_ratings_rated_by (rated_by_type, rated_by_id),
            CONSTRAINT fk_ratings_ride FOREIGN KEY (ride_id) REFERENCES rides(id) ON DELETE CASCADE,
            CONSTRAINT chk_ratings_score CHECK (score BETWEEN 1 AND 5)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci";

        return mysqli_query($conn, $sql);
    }
];
