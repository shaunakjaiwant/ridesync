<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

$options = getopt('', [
    'users::',
    'drivers::',
    'rides::',
    'matches-per-ride::',
    'demand::',
    'reset',
    'dry-run',
]);

$userCount = max(1, (int) ($options['users'] ?? 1000));
$driverCount = max(1, (int) ($options['drivers'] ?? 250));
$rideCount = max(1, (int) ($options['rides'] ?? 5000));
$matchesPerRide = max(0, min(5, (int) ($options['matches-per-ride'] ?? 2)));
$demandCount = max(0, (int) ($options['demand'] ?? 1000));
$dryRun = array_key_exists('dry-run', $options);
$reset = array_key_exists('reset', $options);

$origins = [
    ['SDMIT Ujire', 12.9899000, 75.3345000],
    ['Mangaluru Central', 12.8698000, 74.8430000],
    ['Manipal Campus', 13.3525000, 74.7928000],
    ['Puttur Bus Stand', 12.7598000, 75.2017000],
    ['Moodbidri', 13.0662000, 74.9959000],
    ['Bantwal', 12.8914000, 75.0349000],
];

function seed_note($message) {
    echo '[' . date('H:i:s') . '] ' . $message . PHP_EOL;
}

function seed_exec($conn, $sql, $label) {
    if (!mysqli_query($conn, $sql)) {
        throw new RuntimeException($label . ': ' . mysqli_error($conn));
    }
}

function seed_loadtest_users($conn) {
    $result = mysqli_query($conn, "SELECT id FROM users WHERE email LIKE 'loadtest.%@ridesync.test' ORDER BY id ASC");
    return $result ? array_map('intval', array_column(mysqli_fetch_all($result, MYSQLI_ASSOC), 'id')) : [];
}

function seed_loadtest_drivers($conn) {
    $result = mysqli_query($conn, "SELECT id FROM driver_accounts WHERE email LIKE 'loadtest.driver.%@ridesync.test' ORDER BY id ASC");
    return $result ? array_map('intval', array_column(mysqli_fetch_all($result, MYSQLI_ASSOC), 'id')) : [];
}

function seed_route_key($origin, $destination) {
    return strtolower(preg_replace('/[^a-z0-9]+/', '-', $origin . '-' . $destination));
}

if (!$dryRun) {
    require_once __DIR__ . '/../config/db.php';
    require_once __DIR__ . '/../includes/matching_helper.php';
}

seed_note("Requested users={$userCount}, drivers={$driverCount}, rides={$rideCount}, matches_per_ride={$matchesPerRide}, demand={$demandCount}");

if ($dryRun) {
    seed_note('Dry run only. No database writes performed.');
    exit(0);
}

mysqli_begin_transaction($conn);

try {
    if ($reset) {
        seed_note('Removing prior loadtest data.');
        seed_exec($conn, "DELETE FROM route_demand_signals WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'loadtest.%@ridesync.test')", 'reset demand');
        seed_exec($conn, "DELETE FROM notifications WHERE user_id IN (SELECT id FROM users WHERE email LIKE 'loadtest.%@ridesync.test') OR driver_id IN (SELECT id FROM driver_accounts WHERE email LIKE 'loadtest.driver.%@ridesync.test')", 'reset notifications');
        seed_exec($conn, "DELETE FROM driver_accounts WHERE email LIKE 'loadtest.driver.%@ridesync.test'", 'reset drivers');
        seed_exec($conn, "DELETE FROM users WHERE email LIKE 'loadtest.%@ridesync.test'", 'reset users');
    }

    $password = password_hash('LoadTest123!', PASSWORD_DEFAULT);
    $userStmt = mysqli_prepare($conn, "INSERT IGNORE INTO users (name, email, password, college, gender) VALUES (?, ?, ?, ?, ?)");
    $colleges = ['SDMIT Ujire', 'AIMIT Mangaluru', 'MITE Moodbidri', 'Srinivas Institute', 'Yenepoya Institute'];
    $genders = ['Male', 'Female', 'Other'];
    for ($i = 1; $i <= $userCount; $i++) {
        $name = 'Load Test Rider ' . $i;
        $email = 'loadtest.rider.' . $i . '@ridesync.test';
        $college = $colleges[$i % count($colleges)];
        $gender = $genders[$i % count($genders)];
        mysqli_stmt_bind_param($userStmt, 'sssss', $name, $email, $password, $college, $gender);
        mysqli_stmt_execute($userStmt);
    }
    $userIds = seed_loadtest_users($conn);

    $driverStmt = mysqli_prepare($conn, "INSERT IGNORE INTO driver_accounts (name, email, password, phone, status, onboarding_status) VALUES (?, ?, ?, ?, 'active', 'complete')");
    $profileStmt = mysqli_prepare($conn, "INSERT IGNORE INTO driver_account_profiles (driver_id, license_number, verification_status) VALUES (?, ?, 'verified')");
    $vehicleStmt = mysqli_prepare($conn, "INSERT IGNORE INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity) VALUES (?, ?, ?, ?)");
    $availabilityStmt = mysqli_prepare($conn, "INSERT IGNORE INTO driver_account_availability (driver_id, status, current_lat, current_lng) VALUES (?, ?, ?, ?)");
    for ($i = 1; $i <= $driverCount; $i++) {
        $name = 'Load Test Driver ' . $i;
        $email = 'loadtest.driver.' . $i . '@ridesync.test';
        $phone = '+919900' . str_pad((string) $i, 6, '0', STR_PAD_LEFT);
        mysqli_stmt_bind_param($driverStmt, 'ssss', $name, $email, $password, $phone);
        mysqli_stmt_execute($driverStmt);
    }
    $driverIds = seed_loadtest_drivers($conn);
    foreach ($driverIds as $index => $driverId) {
        $license = 'KA20LT' . str_pad((string) ($index + 1), 6, '0', STR_PAD_LEFT);
        $vehicleType = ($index % 4 === 0) ? 'Car' : 'Bike';
        $vehicleNumber = 'KA20LT' . str_pad((string) ($index + 1), 4, '0', STR_PAD_LEFT);
        $seats = $vehicleType === 'Car' ? 4 : 1;
        $status = ($index % 3 === 0) ? 'online' : 'offline';
        $point = $GLOBALS['origins'][$index % count($GLOBALS['origins'])];
        $lat = (float) $point[1] + (($index % 10) / 1000);
        $lng = (float) $point[2] + (($index % 10) / 1000);
        mysqli_stmt_bind_param($profileStmt, 'is', $driverId, $license);
        mysqli_stmt_execute($profileStmt);
        mysqli_stmt_bind_param($vehicleStmt, 'issi', $driverId, $vehicleType, $vehicleNumber, $seats);
        mysqli_stmt_execute($vehicleStmt);
        mysqli_stmt_bind_param($availabilityStmt, 'isdd', $driverId, $status, $lat, $lng);
        mysqli_stmt_execute($availabilityStmt);
    }

    $rideStmt = mysqli_prepare(
        $conn,
        "INSERT INTO rides (user_id, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, route_distance_km, travel_date, travel_time, seats_available, status)
         VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)"
    );
    $routeStmt = mysqli_prepare($conn, "INSERT IGNORE INTO ride_routes (ride_id, distance_km, duration_minutes) VALUES (?, ?, ?)");
    $liveStmt = mysqli_prepare($conn, "INSERT IGNORE INTO ride_live_status (ride_id, driver_id, live_status, eta_minutes, note) VALUES (?, ?, ?, ?, ?)");
    $matchStmt = mysqli_prepare($conn, "INSERT IGNORE INTO matches (ride_id, matched_user_id, status, match_score, pickup_distance_km, drop_distance_km, route_overlap_percent, time_score, match_source) VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'smart')");
    for ($i = 1; $i <= $rideCount; $i++) {
        $userId = $userIds[$i % count($userIds)];
        $from = $origins[$i % count($origins)];
        $to = $origins[($i + 2) % count($origins)];
        $distance = round(8 + ($i % 70) * 0.8, 2);
        $date = date('Y-m-d', strtotime('+' . ($i % 21) . ' days'));
        $time = sprintf('%02d:%02d:00', 7 + ($i % 12), ($i * 7) % 60);
        $seats = 1 + ($i % 4);
        $status = ($i % 17 === 0) ? 'closed' : (($i % 29 === 0) ? 'cancelled' : 'open');
        mysqli_stmt_bind_param($rideStmt, 'issdddddssis', $userId, $from[0], $to[0], $from[1], $from[2], $to[1], $to[2], $distance, $date, $time, $seats, $status);
        mysqli_stmt_execute($rideStmt);
        $rideId = mysqli_insert_id($conn);
        if ($rideId <= 0) {
            continue;
        }

        $duration = (int) max(10, round(($distance / 32) * 60));
        mysqli_stmt_bind_param($routeStmt, 'idi', $rideId, $distance, $duration);
        mysqli_stmt_execute($routeStmt);

        $driverId = $driverIds ? $driverIds[$i % count($driverIds)] : null;
        $liveStatus = $status === 'open' ? (($i % 5 === 0) ? 'driver_assigned' : 'searching') : $status;
        $eta = 6 + ($i % 25);
        $note = 'Synthetic load-test ride state';
        mysqli_stmt_bind_param($liveStmt, 'iisis', $rideId, $driverId, $liveStatus, $eta, $note);
        mysqli_stmt_execute($liveStmt);

        if ($status !== 'open' || $seats <= 0) {
            continue;
        }

        for ($m = 1; $m <= $matchesPerRide; $m++) {
            $matchUserId = $userIds[($i + $m + 3) % count($userIds)];
            if ($matchUserId === $userId) {
                continue;
            }
            $matchStatus = $m === 1 && $i % 4 === 0 ? 'accepted' : 'pending';
            $score = (float) min(99, 62 + (($i + $m) % 35));
            $pickup = (float) (($i + $m) % 9) / 10;
            $drop = (float) (($i + $m + 2) % 12) / 10;
            $overlap = (int) min(100, 55 + (($i + $m) % 45));
            $timeScore = (int) min(100, 60 + (($i + $m) % 40));
            mysqli_stmt_bind_param($matchStmt, 'iisdddii', $rideId, $matchUserId, $matchStatus, $score, $pickup, $drop, $overlap, $timeScore);
            mysqli_stmt_execute($matchStmt);
        }
    }

    if ($demandCount > 0) {
        $demandStmt = mysqli_prepare(
            $conn,
            "INSERT INTO route_demand_signals (user_id, route_key, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, route_distance_km, travel_date, travel_time, demand_status)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'active')"
        );
        for ($i = 1; $i <= $demandCount; $i++) {
            $userId = $userIds[($i + 7) % count($userIds)];
            $from = $origins[($i + 1) % count($origins)];
            $to = $origins[($i + 4) % count($origins)];
            $routeKey = seed_route_key($from[0], $to[0]);
            $distance = round(6 + ($i % 60) * 0.9, 2);
            $date = date('Y-m-d', strtotime('+' . ($i % 14) . ' days'));
            $time = sprintf('%02d:%02d:00', 8 + ($i % 10), ($i * 11) % 60);
            mysqli_stmt_bind_param($demandStmt, 'isssdddddss', $userId, $routeKey, $from[0], $to[0], $from[1], $from[2], $to[1], $to[2], $distance, $date, $time);
            mysqli_stmt_execute($demandStmt);
        }
    }

    mysqli_commit($conn);
    seed_note('Seed complete.');
    seed_note('Loadtest users: ' . count($userIds));
    seed_note('Loadtest drivers: ' . count($driverIds));
} catch (Throwable $exception) {
    mysqli_rollback($conn);
    fwrite(STDERR, 'Seed failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

?>
