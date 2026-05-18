<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';
require_once __DIR__ . '/../includes/wallet_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

$checks = [];

function smoke_check($label, $ok, $detail = '') {
    global $checks;
    $checks[] = [$label, (bool) $ok, $detail];
}

$requiredTables = [
    'users',
    'rides',
    'matches',
    'driver_accounts',
    'driver_account_documents',
    'driver_ride_requests',
    'ride_routes',
    'ride_live_status',
    'notifications',
    'background_jobs',
    'realtime_events',
    'wallet_accounts',
    'wallet_transactions',
    'admin_users',
    'reports',
    'audit_logs',
];

foreach ($requiredTables as $table) {
    smoke_check("table {$table}", ridesync_table_exists($conn, $table));
}

smoke_check('fare rate', abs(ridesync_fare_rate_per_km() - 25.6) < 0.01, 'expected 25.60');

$route = ridesync_normalize_route_polyline('[[12.9822,75.3412],[12.93,75.12],[12.8698,74.843]]');
smoke_check('route polyline parser', $route !== null, $route ?: 'not parsed');

$walletReady = ridesync_wallet_schema_ready($conn);
smoke_check('wallet schema', $walletReady);

$userResult = mysqli_query($conn, "SELECT id FROM users ORDER BY id LIMIT 1");
$smokeUser = $userResult ? mysqli_fetch_assoc($userResult) : null;
if ($walletReady && $smokeUser) {
    mysqli_begin_transaction($conn);
    $walletDirectOk = ridesync_wallet_record_fare_due(
        $conn,
        (int) $smokeUser['id'],
        null,
        null,
        64.25,
        'Rollback smoke direct fare',
        'smoke_direct',
        900001
    );
    $walletRideOk = ridesync_wallet_record_fare_due(
        $conn,
        (int) $smokeUser['id'],
        null,
        null,
        128.50,
        'Rollback smoke ride fare',
        'smoke_ride',
        900002
    );
    $walletSummary = ridesync_wallet_summary($conn, (int) $smokeUser['id']);
    mysqli_rollback($conn);

    smoke_check(
        'wallet fare recording',
        $walletDirectOk && $walletRideOk && $walletSummary['pending_due'] >= 192.75,
        number_format((float) $walletSummary['pending_due'], 2) . ' seen before rollback'
    );
} else {
    smoke_check('wallet fare recording', true, 'skipped: no user row');
}

$fkResult = mysqli_query($conn,
    "SELECT COUNT(*) AS total
     FROM information_schema.TABLE_CONSTRAINTS
     WHERE CONSTRAINT_SCHEMA = DATABASE()
       AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
);
$fkCount = $fkResult ? (int) (mysqli_fetch_assoc($fkResult)['total'] ?? 0) : 0;
smoke_check('foreign keys present', $fkCount >= 15, $fkCount . ' found');

$rateLimitDir = ridesync_rate_limit_dir();
smoke_check('rate limiter storage', is_dir($rateLimitDir) && is_writable($rateLimitDir), $rateLimitDir);

foreach (['source_ip', 'user_agent'] as $column) {
    smoke_check("audit log {$column} column", ridesync_column_exists($conn, 'audit_logs', $column));
}

$expectedIndexes = [
    ['rides', 'idx_rides_user_status_time'],
    ['matches', 'idx_matches_ride_status'],
    ['driver_ride_requests', 'idx_driver_requests_rider_status_time'],
    ['notifications', 'idx_notifications_user_created'],
    ['background_jobs', 'idx_background_jobs_ready'],
    ['realtime_events', 'idx_realtime_events_audience'],
    ['audit_logs', 'idx_audit_source_time'],
];

foreach ($expectedIndexes as [$table, $index]) {
    $stmt = mysqli_prepare($conn,
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "ss", $table, $index);
    mysqli_stmt_execute($stmt);
    smoke_check("index {$index}", mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0, $table);
}

$stalePendingResult = mysqli_query($conn,
    "SELECT COUNT(*) AS total
     FROM matches m
     JOIN rides r ON r.id = m.ride_id
     WHERE m.status = 'pending'
       AND (r.status <> 'open' OR r.seats_available <= 0)"
);
$stalePending = $stalePendingResult ? (int) (mysqli_fetch_assoc($stalePendingResult)['total'] ?? 0) : 0;
smoke_check('no stale pending matches on unavailable rides', $stalePending === 0, $stalePending . ' found');

$failed = 0;
foreach ($checks as [$label, $ok, $detail]) {
    echo ($ok ? '[OK] ' : '[FAIL] ') . $label;
    if ($detail !== '') {
        echo ' - ' . $detail;
    }
    echo PHP_EOL;
    if (!$ok) {
        $failed++;
    }
}

if ($failed > 0) {
    echo PHP_EOL . $failed . ' smoke check(s) failed.' . PHP_EOL;
    exit(1);
}

echo PHP_EOL . 'RideSync smoke checks passed.' . PHP_EOL;
?>
