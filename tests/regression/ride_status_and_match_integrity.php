<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/db.php';

$root = realpath(__DIR__ . '/../..');
$failures = [];

function rt_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function rt_expect($condition, $message, array &$failures) {
    if (!$condition) {
        $failures[] = $message;
    }
}

function rt_insert_user($conn, $email, $name) {
    $password = password_hash('RideSyncRegression#2026', PASSWORD_DEFAULT);
    $college = 'RideSync Regression College';
    $gender = 'Other';
    $stmt = mysqli_prepare($conn, "INSERT INTO users (name, email, password, college, gender) VALUES (?, ?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "sssss", $name, $email, $password, $college, $gender);
    mysqli_stmt_execute($stmt);

    return (int) mysqli_insert_id($conn);
}

function rt_insert_ride($conn, $ownerId, $seats) {
    $origin = 'Regression Origin';
    $destination = 'Regression Destination';
    $travelTime = '09:30:00';
    $stmt = mysqli_prepare($conn,
        "INSERT INTO rides (user_id, origin, destination, travel_date, travel_time, seats_available, status)
         VALUES (?, ?, ?, DATE_ADD(CURDATE(), INTERVAL 1 DAY), ?, ?, 'open')"
    );
    mysqli_stmt_bind_param($stmt, "isssi", $ownerId, $origin, $destination, $travelTime, $seats);
    mysqli_stmt_execute($stmt);

    return (int) mysqli_insert_id($conn);
}

function rt_insert_match($conn, $rideId, $userId, $status) {
    $source = 'manual';
    $stmt = mysqli_prepare($conn, "INSERT INTO matches (ride_id, matched_user_id, status, match_source) VALUES (?, ?, ?, ?)");
    mysqli_stmt_bind_param($stmt, "iiss", $rideId, $userId, $status, $source);
    mysqli_stmt_execute($stmt);

    return (int) mysqli_insert_id($conn);
}

function rt_run_php(array $lines) {
    $tempDir = ridesync_storage_path('cache' . DIRECTORY_SEPARATOR . 'regression');
    ridesync_ensure_directory($tempDir);
    $file = tempnam($tempDir, 'rt_');
    file_put_contents($file, "<?php\n" . implode("\n", $lines) . "\n");

    $output = [];
    $exitCode = 1;
    exec('php ' . escapeshellarg($file) . ' 2>&1', $output, $exitCode);
    unlink($file);

    return [
        'exit_code' => $exitCode,
        'output' => implode("\n", $output),
    ];
}

function rt_call_ride_status($root, $rideId, $userId) {
    $apiFile = $root . DIRECTORY_SEPARATOR . 'api' . DIRECTORY_SEPARATOR . 'ride_status.php';
    return rt_run_php([
        '$_SERVER["REQUEST_METHOD"] = "GET";',
        '$_SERVER["SCRIPT_NAME"] = "/ridesync/api/ride_status.php";',
        '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";',
        '$_SERVER["HTTP_USER_AGENT"] = "RideSyncRegression/1.0";',
        '$_SERVER["HTTP_ACCEPT"] = "application/json";',
        'session_id(' . var_export('rtstatus' . bin2hex(random_bytes(8)), true) . ');',
        'session_start();',
        '$_SESSION["user_id"] = ' . (int) $userId . ';',
        '$_SESSION["user_name"] = "Regression Rider";',
        '$_SESSION["_auth_role"] = "rider";',
        '$_SESSION["_auth_started_at"] = time();',
        '$_SESSION["_last_seen_at"] = time();',
        '$_SESSION["_last_rotated_at"] = time();',
        '$_SESSION["_created_at"] = time();',
        '$_SESSION["csrf_token"] = bin2hex(random_bytes(32));',
        '$_GET["ride_id"] = ' . (int) $rideId . ';',
        'require ' . var_export($apiFile, true) . ';',
    ]);
}

function rt_call_match_accept($root, $ownerId, $matchId) {
    $actionFile = $root . DIRECTORY_SEPARATOR . 'actions' . DIRECTORY_SEPARATOR . 'match_action.php';
    return rt_run_php([
        '$_SERVER["REQUEST_METHOD"] = "POST";',
        '$_SERVER["SCRIPT_NAME"] = "/ridesync/actions/match_action.php";',
        '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";',
        '$_SERVER["HTTP_USER_AGENT"] = "RideSyncRegression/1.0";',
        '$_SERVER["HTTP_REFERER"] = "/ridesync/pages/my_rides.php";',
        'session_id(' . var_export('rtmatch' . bin2hex(random_bytes(8)), true) . ');',
        'session_start();',
        '$_SESSION["user_id"] = ' . (int) $ownerId . ';',
        '$_SESSION["user_name"] = "Regression Owner";',
        '$_SESSION["_auth_role"] = "rider";',
        '$_SESSION["_auth_started_at"] = time();',
        '$_SESSION["_last_seen_at"] = time();',
        '$_SESSION["_last_rotated_at"] = time();',
        '$_SESSION["_created_at"] = time();',
        '$_SESSION["csrf_token"] = "regression-csrf-token";',
        '$_POST["csrf_token"] = "regression-csrf-token";',
        '$_POST["action"] = "accept";',
        '$_POST["match_id"] = ' . (int) $matchId . ';',
        'require ' . var_export($actionFile, true) . ';',
    ]);
}

$prefix = 'regression_' . date('YmdHis') . '_' . bin2hex(random_bytes(4));

try {
    $ownerId = rt_insert_user($conn, $prefix . '_owner@example.test', 'Regression Owner');
    $pendingUserId = rt_insert_user($conn, $prefix . '_pending@example.test', 'Regression Pending');
    $rejectedUserId = rt_insert_user($conn, $prefix . '_rejected@example.test', 'Regression Rejected');
    $acceptedUserId = rt_insert_user($conn, $prefix . '_accepted@example.test', 'Regression Accepted');
    $extraUserId = rt_insert_user($conn, $prefix . '_extra@example.test', 'Regression Extra');

    $statusRideId = rt_insert_ride($conn, $ownerId, 3);
    rt_insert_match($conn, $statusRideId, $pendingUserId, 'pending');
    rt_insert_match($conn, $statusRideId, $rejectedUserId, 'rejected');
    rt_insert_match($conn, $statusRideId, $acceptedUserId, 'accepted');

    foreach ([
        'pending' => $pendingUserId,
        'rejected' => $rejectedUserId,
    ] as $label => $userId) {
        $response = rt_call_ride_status($root, $statusRideId, $userId);
        $decoded = json_decode($response['output'], true);
        rt_expect($response['exit_code'] === 0, "ride_status {$label} subprocess failed: " . $response['output'], $failures);
        rt_expect(is_array($decoded), "ride_status {$label} did not return JSON: " . $response['output'], $failures);
        rt_expect(($decoded['ok'] ?? null) === false && ($decoded['error'] ?? '') === 'Not allowed', "ride_status allowed {$label} match", $failures);
    }

    $acceptedResponse = rt_call_ride_status($root, $statusRideId, $acceptedUserId);
    $acceptedDecoded = json_decode($acceptedResponse['output'], true);
    rt_expect($acceptedResponse['exit_code'] === 0, 'ride_status accepted subprocess failed: ' . $acceptedResponse['output'], $failures);
    rt_expect(($acceptedDecoded['ok'] ?? null) === true, 'ride_status denied accepted match', $failures);

    $fullRideId = rt_insert_ride($conn, $ownerId, 1);
    $acceptedMatchId = rt_insert_match($conn, $fullRideId, $pendingUserId, 'pending');
    $remainingMatchId = rt_insert_match($conn, $fullRideId, $extraUserId, 'pending');

    $acceptResponse = rt_call_match_accept($root, $ownerId, $acceptedMatchId);
    rt_expect($acceptResponse['exit_code'] === 0, 'match accept subprocess failed: ' . $acceptResponse['output'], $failures);

    $rideResult = mysqli_query($conn, "SELECT seats_available, status FROM rides WHERE id = " . (int) $fullRideId);
    $ride = mysqli_fetch_assoc($rideResult);
    rt_expect((int) ($ride['seats_available'] ?? -1) === 0, 'accepted full ride did not reserve the final seat', $failures);
    rt_expect(($ride['status'] ?? '') === 'closed', 'accepted full ride did not close the ride', $failures);

    $statusResult = mysqli_query($conn,
        "SELECT id, status
         FROM matches
         WHERE id IN (" . (int) $acceptedMatchId . ", " . (int) $remainingMatchId . ")"
    );
    $statuses = [];
    while ($row = mysqli_fetch_assoc($statusResult)) {
        $statuses[(int) $row['id']] = $row['status'];
    }

    rt_expect(($statuses[$acceptedMatchId] ?? '') === 'accepted', 'selected match was not accepted', $failures);
    rt_expect(($statuses[$remainingMatchId] ?? '') === 'rejected', 'remaining pending match was not closed when ride became full', $failures);

    $notificationResult = mysqli_query($conn,
        "SELECT COUNT(*) AS total
         FROM notifications
         WHERE user_id = " . (int) $extraUserId . "
           AND title = 'Ride is full'"
    );
    $notification = mysqli_fetch_assoc($notificationResult);
    rt_expect((int) ($notification['total'] ?? 0) >= 1, 'remaining pending rider was not notified that the ride is full', $failures);
} finally {
    $escapedPrefix = mysqli_real_escape_string($conn, $prefix . '%');
    mysqli_query($conn, "DELETE FROM users WHERE email LIKE '{$escapedPrefix}'");
}

if (!empty($failures)) {
    echo PHP_EOL . 'Ride status and match integrity regression failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

rt_note('OK', 'ride status rejects pending/rejected matches');
rt_note('OK', 'accepted match remains authorized');
rt_note('OK', 'full ride closes remaining pending matches');
echo PHP_EOL . 'RideSync ride status and match integrity regressions passed.' . PHP_EOL;
?>
