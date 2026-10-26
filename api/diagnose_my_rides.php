<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';

header('Content-Type: application/json');

$applied = [];
$errors = [];

$matchCols = [
    'pickup_lat' => "ALTER TABLE matches ADD COLUMN pickup_lat DECIMAL(10,8) NULL",
    'pickup_lng' => "ALTER TABLE matches ADD COLUMN pickup_lng DECIMAL(11,8) NULL",
    'dropoff_lat' => "ALTER TABLE matches ADD COLUMN dropoff_lat DECIMAL(10,8) NULL",
    'dropoff_lng' => "ALTER TABLE matches ADD COLUMN dropoff_lng DECIMAL(11,8) NULL",
    'detour_distance_km' => "ALTER TABLE matches ADD COLUMN detour_distance_km DECIMAL(8,2) NULL",
    'detour_time_minutes' => "ALTER TABLE matches ADD COLUMN detour_time_minutes INT NULL",
    'source' => "ALTER TABLE matches ADD COLUMN source ENUM('search', 'route_watch') NOT NULL DEFAULT 'search'",
];

foreach ($matchCols as $col => $sql) {
    try {
        $chk = mysqli_query($conn, "SHOW COLUMNS FROM matches LIKE '{$col}'");
        if ($chk && mysqli_num_rows($chk) === 0) {
            if (mysqli_query($conn, $sql)) {
                $applied[] = "Added column $col to matches";
            } else {
                $errors[] = "Error on $col: " . mysqli_error($conn);
            }
        } else {
            $applied[] = "Column $col already exists";
        }
    } catch (Throwable $e) {
        $errors[] = "Exception on $col: " . $e->getMessage();
    }
}

$columns = [];
$res = mysqli_query($conn, "DESCRIBE matches");
if ($res) {
    while ($r = mysqli_fetch_assoc($res)) {
        $columns[] = $r['Field'];
    }
}

echo json_encode([
    'ok' => count($errors) === 0,
    'applied' => $applied,
    'errors' => $errors,
    'columns' => $columns,
]);
