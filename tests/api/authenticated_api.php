<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../../config/bootstrap.php';
require_once __DIR__ . '/../../config/db.php';
require_once __DIR__ . '/../../includes/matching_helper.php';

$baseUrl = getenv('RIDESYNC_BASE_URL') ?: 'http://127.0.0.1/ridesync';
$failures = [];
$passes = 0;

function aapi_note(string $status, string $message): void {
    echo '[' . $status . '] ' . $message . PHP_EOL;
}

function aapi_expect(bool $condition, string $message, array &$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
}

function aapi_run_endpoint(string $targetFile, string $sessionKey, int $principalId, array $queryData = [], ?string &$rawOutput = null): ?array {
    $targetPath = str_replace('\\', '/', realpath($targetFile));
    $bootstrapPath = str_replace('\\', '/', realpath(__DIR__ . '/../../config/bootstrap.php'));
    $dbPath = str_replace('\\', '/', realpath(__DIR__ . '/../../config/db.php'));
    $queryExport = json_encode($queryData);

    $phpCode = sprintf(
        'require_once \'%s\'; require_once \'%s\'; $_SESSION = []; $_SESSION[\'%s\'] = %d; if (\'%s\' === \'admin_id\') { $_SESSION[\'admin_name\'] = \'Test Admin\'; $_SESSION[\'admin_email\'] = \'testadmin@ridesync.test\'; } ridesync_validate_session_principal($conn); $_GET = json_decode(\'%s\', true); $_SERVER[\'REQUEST_METHOD\'] = \'GET\'; require \'%s\';',
        $bootstrapPath,
        $dbPath,
        $sessionKey,
        $principalId,
        $sessionKey,
        $queryExport,
        $targetPath
    );

    $cmd = 'php -r ' . escapeshellarg($phpCode);
    $rawOutput = shell_exec($cmd);
    $decoded = json_decode((string) $rawOutput, true);
    return is_array($decoded) ? $decoded : null;
}








aapi_note('INFO', 'Authenticated API target: ' . $baseUrl);

global $conn;
$isDbAvailable = ($conn !== null && ridesync_table_exists($conn, 'users'));

if (!$isDbAvailable) {
    aapi_note('SKIP', 'Database unavailable for authenticated API tests.');
    exit(0);
}

// Ensure test rider fixture exists
$riderRes = mysqli_query($conn, "SELECT id, name FROM users LIMIT 1");
if (!$riderRes || mysqli_num_rows($riderRes) === 0) {
    mysqli_query($conn, "INSERT INTO users (name, email, password, college, gender) VALUES ('Test Rider', 'testrider@ridesync.test', '" . password_hash('password123', PASSWORD_DEFAULT) . "', 'SDMIT', 'Male')");
    $riderId = (int) mysqli_insert_id($conn);
} else {
    $row = mysqli_fetch_assoc($riderRes);
    $riderId = (int) $row['id'];
}

$endpointsToTest = [
    [
        'name' => 'rider realtime token endpoint',
        'file' => __DIR__ . '/../../api/realtime_token.php',
        'query' => [],
    ],
    [
        'name' => 'rider location suggestions endpoint',
        'file' => __DIR__ . '/../../api/location_suggestions.php',
        'query' => ['q' => 'SDMIT'],
    ],
    [
        'name' => 'rider realtime events polling endpoint',
        'file' => __DIR__ . '/../../api/realtime_events.php',
        'query' => ['after_id' => '0'],
    ],
];

foreach ($endpointsToTest as $ep) {
    $rawOutput = '';
    $json = aapi_run_endpoint($ep['file'], 'user_id', $riderId, $ep['query'], $rawOutput);
    $caseFailures = [];
    aapi_expect(is_array($json), $ep['name'] . ' returned invalid JSON: ' . $rawOutput, $caseFailures);
    aapi_expect(($json['ok'] ?? false) === true, $ep['name'] . ' returned ok=false: ' . $rawOutput, $caseFailures);

    if (empty($caseFailures)) {
        $passes++;
        aapi_note('OK', $ep['name']);
    } else {
        foreach ($caseFailures as $f) {
            $failures[] = $f;
        }
        aapi_note('FAIL', $ep['name']);
    }
}

// Ensure test driver fixture exists
$driverRes = mysqli_query($conn, "SELECT id, name FROM driver_accounts WHERE status = 'active' LIMIT 1");
if (!$driverRes || mysqli_num_rows($driverRes) === 0) {
    mysqli_query($conn, "INSERT INTO driver_accounts (name, email, password, phone, status) VALUES ('Test Driver', 'testdriver@ridesync.test', '" . password_hash('password123', PASSWORD_DEFAULT) . "', '9876543211', 'active')");
    $driverId = (int) mysqli_insert_id($conn);
} else {
    $row = mysqli_fetch_assoc($driverRes);
    $driverId = (int) $row['id'];
}

$rawOutput = '';
$json = aapi_run_endpoint(__DIR__ . '/../../api/driver_state.php', 'driver_id', $driverId, [], $rawOutput);
$caseFailures = [];
aapi_expect(is_array($json), 'driver state returned invalid JSON: ' . $rawOutput, $caseFailures);
aapi_expect(($json['ok'] ?? false) === true, 'driver state returned ok=false: ' . $rawOutput, $caseFailures);
aapi_expect(isset($json['availability']), 'driver state missing availability: ' . $rawOutput, $caseFailures);

if (empty($caseFailures)) {
    $passes++;
    aapi_note('OK', 'driver state authenticated flow');
} else {
    foreach ($caseFailures as $f) {
        $failures[] = $f;
    }
    aapi_note('FAIL', 'driver state authenticated flow');
}

// Ensure test admin fixture exists
$adminRes = mysqli_query($conn, "SELECT id, name, email FROM admin_users WHERE status = 'active' AND role IN ('super_admin', 'admin') LIMIT 1");
if (!$adminRes || mysqli_num_rows($adminRes) === 0) {
    mysqli_query($conn, "INSERT INTO admin_users (name, email, password, role, status) VALUES ('Test Admin', 'testadmin@ridesync.test', '" . password_hash('password123', PASSWORD_DEFAULT) . "', 'super_admin', 'active')");
    $adminId = (int) mysqli_insert_id($conn);
} else {
    $row = mysqli_fetch_assoc($adminRes);
    $adminId = (int) $row['id'];
}

$adminEndpoints = [
    [
        'name' => 'admin services endpoint',
        'file' => __DIR__ . '/../../api/admin_services.php',
        'query' => [],
        'check_key' => 'services',
    ],
    [
        'name' => 'admin search suggestions endpoint',
        'file' => __DIR__ . '/../../api/search_suggestions.php',
        'query' => ['q' => 'test', 'context' => 'admin_global'],
        'check_key' => 'suggestions',
    ],
];

foreach ($adminEndpoints as $ep) {
    $rawOutput = '';
    $json = aapi_run_endpoint($ep['file'], 'admin_id', $adminId, $ep['query'], $rawOutput);
    $caseFailures = [];
    aapi_expect(is_array($json), $ep['name'] . ' returned invalid JSON: ' . $rawOutput, $caseFailures);
    aapi_expect(isset($json[$ep['check_key']]) || ($json['ok'] ?? false) === true, $ep['name'] . ' missing expected key ' . $ep['check_key'] . ': ' . $rawOutput, $caseFailures);

    if (empty($caseFailures)) {
        $passes++;
        aapi_note('OK', $ep['name']);
    } else {
        foreach ($caseFailures as $f) {
            $failures[] = $f;
        }
        aapi_note('FAIL', $ep['name']);
    }
}






if (!empty($failures)) {
    echo PHP_EOL . 'Authenticated API failures:' . PHP_EOL;
    foreach ($failures as $failure) {
        echo '- ' . $failure . PHP_EOL;
    }
    exit(1);
}

echo PHP_EOL . "RideSync authenticated API suite passed ({$passes} checks)." . PHP_EOL;
