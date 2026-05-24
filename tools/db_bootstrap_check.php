<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

require_once __DIR__ . '/../config/bootstrap.php';

$options = [
    'json' => in_array('--json', $argv ?? [], true),
    'required' => in_array('--required', $argv ?? [], true),
    'max_latency_ms' => 250,
];

foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--max-latency-ms=')) {
        $options['max_latency_ms'] = max(25, (int) substr($arg, 17));
    }
}

$checks = [];
$startedAt = microtime(true);

function db_bootstrap_note(array &$checks, string $name, bool $ok, string $detail = '', array $meta = []): void
{
    $checks[] = [
        'name' => $name,
        'ok' => $ok,
        'detail' => $detail,
        'meta' => $meta,
    ];
}

function db_bootstrap_secret_state(string $key, int $minLength = 1): bool
{
    $value = (string) ridesync_env($key, '');
    return trim($value) !== ''
        && strlen($value) >= $minLength
        && stripos(trim($value), 'replace-with') !== 0;
}

function db_bootstrap_table_exists(mysqli $conn, string $table): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 's', $table);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

function db_bootstrap_index_exists(mysqli $conn, string $table, string $index): bool
{
    $stmt = mysqli_prepare(
        $conn,
        "SELECT 1
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME = ?
           AND INDEX_NAME = ?
         LIMIT 1"
    );
    if (!$stmt) {
        return false;
    }
    mysqli_stmt_bind_param($stmt, 'ss', $table, $index);
    mysqli_stmt_execute($stmt);
    return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
}

function db_bootstrap_scalar(mysqli $conn, string $sql): ?string
{
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return null;
    }
    $row = mysqli_fetch_row($result);
    return $row ? (string) $row[0] : null;
}

function db_bootstrap_variable(mysqli $conn, string $name): ?string
{
    $safe = mysqli_real_escape_string($conn, $name);
    $result = mysqli_query($conn, "SHOW VARIABLES LIKE '{$safe}'");
    if (!$result) {
        return null;
    }
    $row = mysqli_fetch_assoc($result);
    return isset($row['Value']) ? (string) $row['Value'] : null;
}

$environment = ridesync_app_env();
$requiredEnv = [
    'RIDESYNC_DB_NAME',
    'RIDESYNC_DB_USER',
];

if ($environment === 'production' || $options['required']) {
    $requiredEnv[] = 'RIDESYNC_DB_HOST';
    $requiredEnv[] = 'RIDESYNC_DB_PASSWORD';
}

foreach ($requiredEnv as $key) {
    $ok = $key === 'RIDESYNC_DB_PASSWORD'
        ? db_bootstrap_secret_state($key, 8)
        : trim((string) ridesync_env($key, '')) !== '';
    db_bootstrap_note($checks, "env {$key}", $ok, $ok ? 'configured' : 'missing or placeholder');
}

$host = trim((string) ridesync_env('RIDESYNC_DB_HOST', 'localhost'));
$host = $host === '' ? 'localhost' : $host;
$port = (int) ridesync_env('RIDESYNC_DB_PORT', 3306);
$database = (string) ridesync_env('RIDESYNC_DB_NAME', 'ridesync_db');
$user = (string) ridesync_env('RIDESYNC_DB_USER', 'root');
$password = (string) ridesync_env('RIDESYNC_DB_PASSWORD', '');

$conn = mysqli_init();
mysqli_options($conn, MYSQLI_OPT_CONNECT_TIMEOUT, (int) ridesync_env('RIDESYNC_DB_CONNECT_TIMEOUT', 5));

$connectStarted = microtime(true);
$connected = false;
$connectError = '';
try {
    $connected = @mysqli_real_connect($conn, $host, $user, $password, $database, $port);
} catch (Throwable $exception) {
    $connectError = $exception->getMessage();
}
$connectMs = round((microtime(true) - $connectStarted) * 1000, 2);
if (!$connected) {
    $connectError = $connectError ?: (mysqli_connect_error() ?: mysqli_error($conn));
}

db_bootstrap_note(
    $checks,
    'database connection',
    $connected,
    $connected ? "connected in {$connectMs} ms" : $connectError,
    [
        'host' => $host,
        'port' => $port,
        'database' => $database,
        'latency_ms' => $connectMs,
    ]
);

if ($connected) {
    mysqli_set_charset($conn, 'utf8mb4');
    db_bootstrap_note($checks, 'connection charset', mysqli_character_set_name($conn) === 'utf8mb4', mysqli_character_set_name($conn));
    db_bootstrap_note($checks, 'connection latency budget', $connectMs <= (int) $options['max_latency_ms'], "{$connectMs} ms");

    $version = db_bootstrap_scalar($conn, 'SELECT VERSION()');
    db_bootstrap_note($checks, 'server version', $version !== null, $version ?? 'unavailable');

    $requiredTables = [
        'users',
        'rides',
        'matches',
        'driver_accounts',
        'driver_account_documents',
        'driver_ride_requests',
        'ride_live_status',
        'notifications',
        'background_jobs',
        'realtime_events',
        'admin_users',
        'audit_logs',
        'wallet_accounts',
        'wallet_transactions',
    ];
    $missingTables = [];
    foreach ($requiredTables as $table) {
        if (!db_bootstrap_table_exists($conn, $table)) {
            $missingTables[] = $table;
        }
    }
    db_bootstrap_note($checks, 'required tables', empty($missingTables), empty($missingTables) ? count($requiredTables) . ' present' : implode(', ', $missingTables));

    $requiredIndexes = [
        ['rides', 'idx_rides_search'],
        ['rides', 'idx_rides_user_status_time'],
        ['matches', 'idx_matches_ride_status'],
        ['driver_ride_requests', 'idx_driver_requests_status_requested'],
        ['notifications', 'idx_notifications_user_created'],
        ['background_jobs', 'idx_background_jobs_ready'],
        ['realtime_events', 'idx_realtime_events_audience'],
        ['audit_logs', 'idx_audit_source_time'],
    ];
    $missingIndexes = [];
    foreach ($requiredIndexes as [$table, $index]) {
        if (!db_bootstrap_index_exists($conn, $table, $index)) {
            $missingIndexes[] = "{$table}.{$index}";
        }
    }
    db_bootstrap_note($checks, 'critical indexes', empty($missingIndexes), empty($missingIndexes) ? count($requiredIndexes) . ' present' : implode(', ', $missingIndexes));

    $fkCount = (int) (db_bootstrap_scalar(
        $conn,
        "SELECT COUNT(*)
         FROM information_schema.TABLE_CONSTRAINTS
         WHERE CONSTRAINT_SCHEMA = DATABASE()
           AND CONSTRAINT_TYPE = 'FOREIGN KEY'"
    ) ?? 0);
    db_bootstrap_note($checks, 'foreign key coverage', $fkCount >= 40, "{$fkCount} foreign keys");

    $writeOk = false;
    $writeDetail = '';
    if (db_bootstrap_table_exists($conn, 'background_jobs')) {
        mysqli_begin_transaction($conn);
        $payload = json_encode(['source' => 'db_bootstrap_check', 'request_id' => ridesync_request_id()]);
        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO background_jobs (job_type, queue_name, payload_json, max_attempts)
             VALUES ('diagnostic.rollback', 'diagnostics', ?, 1)"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 's', $payload);
            $writeOk = mysqli_stmt_execute($stmt);
            $writeDetail = $writeOk ? 'rollback write succeeded' : mysqli_stmt_error($stmt);
        } else {
            $writeDetail = mysqli_error($conn);
        }
        mysqli_rollback($conn);
    } else {
        $writeDetail = 'background_jobs table missing';
    }
    db_bootstrap_note($checks, 'transactional rollback write', $writeOk, $writeDetail);

    $slowLog = db_bootstrap_variable($conn, 'slow_query_log');
    $longQuery = db_bootstrap_variable($conn, 'long_query_time');
    db_bootstrap_note($checks, 'slow query observability', true, 'available through MySQL variables', [
        'slow_query_log' => $slowLog,
        'long_query_time' => $longQuery,
    ]);
}

$ok = !in_array(false, array_column($checks, 'ok'), true);
$payload = [
    'ok' => $ok,
    'environment' => $environment,
    'duration_ms' => round((microtime(true) - $startedAt) * 1000, 2),
    'checks' => $checks,
];

if ($options['json']) {
    echo json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
} else {
    foreach ($checks as $check) {
        echo '[' . ($check['ok'] ? 'OK' : 'FAIL') . '] ' . $check['name'];
        if ($check['detail'] !== '') {
            echo ' - ' . $check['detail'];
        }
        echo PHP_EOL;
    }
    echo PHP_EOL . ($ok ? 'RideSync DB bootstrap check passed.' : 'RideSync DB bootstrap check failed.') . PHP_EOL;
}

exit($ok ? 0 : 1);
