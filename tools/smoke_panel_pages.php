<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';

$failures = [];

function panel_smoke_note($status, $message) {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function panel_smoke_excerpt($text) {
    $text = trim(preg_replace('/\s+/', ' ', (string) $text));
    return strlen($text) > 700 ? substr($text, 0, 700) . '...' : $text;
}

function panel_smoke_prepare_rider($conn) {
    $email = 'smoke.rider.panel@ridesync.test';
    $name = 'Panel Smoke Rider';
    $password = password_hash('SmokeRiderPanel!2026', PASSWORD_DEFAULT);
    $college = 'RideSync QA College';
    $gender = 'Other';

    $stmt = mysqli_prepare($conn,
        "INSERT INTO users (name, email, password, college, gender)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            college = VALUES(college),
            gender = VALUES(gender)"
    );
    mysqli_stmt_bind_param($stmt, 'sssss', $name, $email, $password, $college, $gender);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "SELECT id, name FROM users WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $rider = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if (!$rider) {
        throw new RuntimeException('Unable to prepare rider panel smoke account.');
    }

    return [
        'id' => (int) $rider['id'],
        'name' => (string) $rider['name'],
    ];
}

function panel_smoke_prepare_driver($conn) {
    $email = 'smoke.driver.panel@ridesync.test';
    $name = 'Panel Smoke Driver';
    $phone = '9999902600';
    $password = password_hash('SmokeDriverPanel!2026', PASSWORD_DEFAULT);

    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_accounts (name, email, password, phone, status, onboarding_status)
         VALUES (?, ?, ?, ?, 'active', 'complete')
         ON DUPLICATE KEY UPDATE
            name = VALUES(name),
            phone = VALUES(phone),
            status = 'active',
            onboarding_status = 'complete'"
    );
    mysqli_stmt_bind_param($stmt, 'ssss', $name, $email, $password, $phone);
    mysqli_stmt_execute($stmt);

    $stmt = mysqli_prepare($conn, "SELECT id, name FROM driver_accounts WHERE email = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, 's', $email);
    mysqli_stmt_execute($stmt);
    $driver = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if (!$driver) {
        throw new RuntimeException('Unable to prepare driver panel smoke account.');
    }

    $driverId = (int) $driver['id'];
    $license = 'SMOKE-LIC-2600';
    $details = 'Panel smoke verification fixture';
    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_profiles (driver_id, license_number, verification_details, verification_status)
         VALUES (?, ?, ?, 'verified')
         ON DUPLICATE KEY UPDATE
            license_number = VALUES(license_number),
            verification_details = VALUES(verification_details),
            verification_status = 'verified'"
    );
    mysqli_stmt_bind_param($stmt, 'iss', $driverId, $license, $details);
    mysqli_stmt_execute($stmt);

    $vehicleType = 'Car';
    $vehicleNumber = 'KA-00-SM-2600';
    $seats = 4;
    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_vehicles (driver_id, vehicle_type, vehicle_number, seating_capacity)
         VALUES (?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE
            vehicle_type = VALUES(vehicle_type),
            vehicle_number = VALUES(vehicle_number),
            seating_capacity = VALUES(seating_capacity)"
    );
    mysqli_stmt_bind_param($stmt, 'issi', $driverId, $vehicleType, $vehicleNumber, $seats);
    mysqli_stmt_execute($stmt);

    $availability = 'offline';
    $lat = 13.3519720;
    $lng = 75.0969760;
    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_availability (driver_id, status, current_lat, current_lng, last_changed_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE
            status = VALUES(status),
            current_lat = VALUES(current_lat),
            current_lng = VALUES(current_lng),
            last_changed_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, 'isdd', $driverId, $availability, $lat, $lng);
    mysqli_stmt_execute($stmt);

    $documentTypes = ['license', 'aadhaar', 'pan', 'vehicle_rc'];
    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_documents (driver_id, document_type, document_reference, verification_status)
         VALUES (?, ?, ?, 'verified')
         ON DUPLICATE KEY UPDATE
            document_reference = VALUES(document_reference),
            verification_status = 'verified'"
    );
    foreach ($documentTypes as $type) {
        $reference = 'smoke-' . $type . '-verified';
        mysqli_stmt_bind_param($stmt, 'iss', $driverId, $type, $reference);
        mysqli_stmt_execute($stmt);
    }

    return [
        'id' => $driverId,
        'name' => (string) $driver['name'],
    ];
}

function panel_smoke_run_php($code) {
    $command = '"' . PHP_BINARY . '"';
    $descriptors = [
        0 => ['pipe', 'r'],
        1 => ['pipe', 'w'],
        2 => ['pipe', 'w'],
    ];
    $process = proc_open($command, $descriptors, $pipes, dirname(__DIR__));
    if (!is_resource($process)) {
        throw new RuntimeException('Unable to start PHP subprocess.');
    }

    fwrite($pipes[0], $code);
    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    fclose($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exit_code' => $exitCode,
        'stdout' => (string) $stdout,
        'stderr' => (string) $stderr,
    ];
}

function panel_smoke_render_case(array $case, array $principal) {
    $root = dirname(__DIR__);
    $session = [];
    $role = $case['role'];
    if ($role === 'rider') {
        $session = [
            'user_id' => $principal['id'],
            'user_name' => $principal['name'],
        ];
    } elseif ($role === 'driver') {
        $session = [
            'driver_id' => $principal['id'],
            'driver_name' => $principal['name'],
        ];
    }

    $code = '<?php' . PHP_EOL
        . 'error_reporting(E_ALL);' . PHP_EOL
        . 'ini_set("display_errors", "1");' . PHP_EOL
        . '$root = ' . var_export($root, true) . ';' . PHP_EOL
        . 'require $root . "/config/bootstrap.php";' . PHP_EOL
        . '$_SESSION = ' . var_export($session, true) . ';' . PHP_EOL
        . 'ridesync_mark_authenticated_session(' . var_export($role, true) . ');' . PHP_EOL
        . '$_SERVER["SCRIPT_NAME"] = "/ridesync/' . $case['path'] . '";' . PHP_EOL
        . '$_SERVER["REQUEST_METHOD"] = "GET";' . PHP_EOL
        . '$_SERVER["HTTP_HOST"] = "127.0.0.1";' . PHP_EOL
        . '$_SERVER["REMOTE_ADDR"] = "127.0.0.1";' . PHP_EOL
        . '$_SERVER["HTTP_USER_AGENT"] = "RideSyncPanelSmoke/1.0";' . PHP_EOL
        . '$_GET = ' . var_export($case['get'] ?? [], true) . ';' . PHP_EOL
        . '$_POST = [];' . PHP_EOL
        . 'ob_start();' . PHP_EOL
        . 'require $root . "/" . ' . var_export($case['path'], true) . ';' . PHP_EOL
        . 'echo ob_get_clean();' . PHP_EOL;

    return panel_smoke_run_php($code);
}

try {
    $rider = panel_smoke_prepare_rider($conn);
    $driver = panel_smoke_prepare_driver($conn);
} catch (Throwable $exception) {
    fwrite(STDERR, '[FAIL] panel smoke fixture setup failed: ' . $exception->getMessage() . PHP_EOL);
    exit(1);
}

$cases = [
    [
        'name' => 'rider dashboard',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/dashboard.php',
        'markers' => ['rider-app', 'Welcome back', 'dashboard-stats'],
    ],
    [
        'name' => 'rider profile',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/profile.php',
        'markers' => ['My Profile', 'Edit Profile'],
    ],
    [
        'name' => 'rider post ride',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/post_ride.php',
        'markers' => ['Post a Ride', 'data-map-picker'],
    ],
    [
        'name' => 'rider search rides',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/search_rides.php',
        'markers' => ['Smart Match Search', 'search-form'],
    ],
    [
        'name' => 'rider my rides',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/my_rides.php',
        'markers' => ['My Rides', 'Post Your First Ride'],
    ],
    [
        'name' => 'rider my matches',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/my_matches.php',
        'markers' => ['My Matches', 'Search Rides'],
    ],
    [
        'name' => 'rider insights',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/insights.php',
        'markers' => ['Mobility Insights', 'Popular routes'],
    ],
    [
        'name' => 'rider notifications',
        'role' => 'rider',
        'principal' => $rider,
        'path' => 'pages/notifications.php',
        'get' => ['actor_type' => 'user'],
        'markers' => ['Notifications', 'notification-panel'],
    ],
    [
        'name' => 'driver dashboard',
        'role' => 'driver',
        'principal' => $driver,
        'path' => 'pages/driver_dashboard.php',
        'markers' => ['driver-app', 'Driver Home', 'driver-metrics-grid'],
    ],
    [
        'name' => 'driver requests',
        'role' => 'driver',
        'principal' => $driver,
        'path' => 'pages/driver_requests.php',
        'markers' => ['Requests & posted rides', 'Direct Requests'],
    ],
    [
        'name' => 'driver earnings',
        'role' => 'driver',
        'principal' => $driver,
        'path' => 'pages/driver_earnings.php',
        'markers' => ['Trips & earnings', 'Recent breakdown'],
    ],
    [
        'name' => 'driver history',
        'role' => 'driver',
        'principal' => $driver,
        'path' => 'pages/driver_history.php',
        'markers' => ['Past trips', 'driver-panel'],
    ],
    [
        'name' => 'driver profile',
        'role' => 'driver',
        'principal' => $driver,
        'path' => 'pages/driver_profile.php',
        'markers' => ['Driver profile', 'Vehicle & Documents'],
    ],
    [
        'name' => 'driver notifications',
        'role' => 'driver',
        'principal' => $driver,
        'path' => 'pages/notifications.php',
        'get' => ['actor_type' => 'driver'],
        'markers' => ['driver-app', 'Notifications', 'notification-panel'],
    ],
];

foreach ($cases as $case) {
    $result = panel_smoke_render_case($case, $case['principal']);
    $combined = $result['stdout'] . PHP_EOL . $result['stderr'];
    $caseFailures = [];

    if ((int) $result['exit_code'] !== 0) {
        $caseFailures[] = 'subprocess exited with ' . (int) $result['exit_code'];
    }
    if (preg_match('/\b(Fatal error|Parse error|Warning|Notice|Deprecated):/i', $combined, $matches)) {
        $caseFailures[] = 'PHP emitted ' . $matches[1];
    }
    foreach ($case['markers'] as $marker) {
        if (!str_contains($result['stdout'], $marker)) {
            $caseFailures[] = 'missing marker "' . $marker . '"';
        }
    }

    if ($caseFailures) {
        $failures[] = $case['name'] . ': ' . implode('; ', $caseFailures) . PHP_EOL . panel_smoke_excerpt($combined);
        panel_smoke_note('FAIL', $case['name']);
    } else {
        panel_smoke_note('OK', $case['name']);
    }
}

if ($failures) {
    echo PHP_EOL . 'Panel smoke failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . 'RideSync panel smoke checks passed.' . PHP_EOL;
?>
