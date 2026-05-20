<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';

function ridesync_smoke_admin_record_first_id($conn, $table, $where = '1=1') {
    $safeTable = preg_match('/^[A-Za-z0-9_]+$/', (string) $table) === 1 ? $table : '';
    if ($safeTable === '') {
        return 0;
    }

    $result = mysqli_query($conn, "SELECT id FROM {$safeTable} WHERE {$where} ORDER BY id ASC LIMIT 1");
    $row = $result ? mysqli_fetch_assoc($result) : null;
    return (int) ($row['id'] ?? 0);
}

function ridesync_smoke_admin_record_render($path, $get) {
    global $conn;

    $_SERVER['SCRIPT_NAME'] = '/ridesync/' . str_replace('\\', '/', ltrim($path, '/'));
    $_SERVER['REQUEST_METHOD'] = 'GET';
    $_GET = $get;
    ob_start();
    require __DIR__ . '/../' . $path;
    return ob_get_clean();
}

$adminResult = mysqli_query($conn, "SELECT id, name, role FROM admin_users WHERE status = 'active' ORDER BY FIELD(role, 'super_admin', 'moderator'), id LIMIT 1");
$admin = $adminResult ? mysqli_fetch_assoc($adminResult) : null;
if (!$admin) {
    fwrite(STDERR, "No active admin available for record intelligence smoke\n");
    exit(1);
}

$_SESSION['admin_id'] = (int) $admin['id'];
$_SESSION['admin_name'] = $admin['name'];
$_SESSION['admin_role'] = $admin['role'];
$_SESSION['csrf_token'] = $_SESSION['csrf_token'] ?? bin2hex(random_bytes(32));

$checks = [
    'user' => [
        'id' => ridesync_smoke_admin_record_first_id($conn, 'users'),
        'path' => 'pages/admin_user_detail.php',
        'get_key' => 'user_id',
        'markers' => ['User Risk Profile', 'User Activity Trail', 'View As User'],
    ],
    'ride' => [
        'id' => ridesync_smoke_admin_record_first_id($conn, 'rides'),
        'path' => 'pages/admin_ride_detail.php',
        'get_key' => 'id',
        'markers' => ['Ride Risk Profile', 'Ride Activity Trail', 'Open Owner'],
    ],
    'driver' => [
        'id' => ridesync_smoke_admin_record_first_id($conn, 'driver_accounts'),
        'path' => 'pages/admin_driver_verification.php',
        'get_key' => 'driver_id',
        'markers' => ['Driver Risk Profile', 'Driver Activity Trail', 'Verification Intelligence'],
    ],
];

$failures = [];
$skips = [];

foreach ($checks as $label => $check) {
    if ((int) $check['id'] <= 0) {
        $skips[] = $label;
        continue;
    }

    $html = ridesync_smoke_admin_record_render($check['path'], [$check['get_key'] => (int) $check['id']]);
    foreach ($check['markers'] as $marker) {
        if (!str_contains($html, $marker)) {
            $failures[] = "{$label} missing marker: {$marker}";
        }
    }

    foreach (['Fatal error', 'Parse error', 'Warning:', 'Notice:'] as $badOutput) {
        if (str_contains($html, $badOutput)) {
            $failures[] = "{$label} rendered PHP diagnostic: {$badOutput}";
        }
    }
}

if (count($failures) > 0) {
    foreach ($failures as $failure) {
        fwrite(STDERR, "[FAIL] {$failure}\n");
    }
    exit(1);
}

$suffix = count($skips) > 0 ? ' (skipped empty tables: ' . implode(', ', $skips) . ')' : '';
echo "[OK] admin record intelligence rendered{$suffix}\n";
exit(0);
?>
