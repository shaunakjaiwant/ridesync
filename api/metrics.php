<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/matching_helper.php';

ridesync_require_method('GET');

function ridesync_metrics_authorization_header() {
    foreach (['HTTP_AUTHORIZATION', 'REDIRECT_HTTP_AUTHORIZATION'] as $key) {
        $value = trim((string) ($_SERVER[$key] ?? ''));
        if ($value !== '') {
            return $value;
        }
    }

    if (function_exists('apache_request_headers')) {
        foreach (apache_request_headers() as $key => $value) {
            if (strtolower((string) $key) === 'authorization') {
                return trim((string) $value);
            }
        }
    }

    return '';
}

$expectedToken = trim((string) ridesync_env('RIDESYNC_METRICS_TOKEN', ''));
$providedToken = ridesync_metrics_authorization_header();
if (str_starts_with(strtolower($providedToken), 'bearer ')) {
    $providedToken = trim(substr($providedToken, 7));
}

if (!ridesync_secret_is_configured($expectedToken, 32)) {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit("forbidden\n");
}

if (!hash_equals($expectedToken, $providedToken)) {
    if (!headers_sent()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
    }
    exit("forbidden\n");
}

if (!headers_sent()) {
    header('Content-Type: text/plain; version=0.0.4; charset=utf-8');
    header('Cache-Control: no-store, private');
}

function metric_line($name, $value, array $labels = []) {
    $parts = [];
    foreach ($labels as $key => $labelValue) {
        $safeKey = preg_replace('/[^A-Za-z0-9_]/', '_', (string) $key);
        $safeValue = str_replace(['\\', "\n", '"'], ['\\\\', '', '\\"'], (string) $labelValue);
        $parts[] = $safeKey . '="' . $safeValue . '"';
    }

    return $name . (empty($parts) ? '' : '{' . implode(',', $parts) . '}') . ' ' . (is_bool($value) ? ($value ? '1' : '0') : (string) $value) . "\n";
}

function metric_count($conn, $table, $where = '1=1') {
    static $allowedTables = [
        'users' => true,
        'driver_accounts' => true,
        'rides' => true,
        'reports' => true,
        'driver_verification_sessions' => true,
        'background_jobs' => true,
    ];
    static $allowedPredicates = [
        '1=1' => true,
        "status = 'active'" => true,
        "status = 'inactive'" => true,
        "status = 'suspended'" => true,
        "status = 'open'" => true,
        "status = 'closed'" => true,
        "status = 'cancelled'" => true,
        "report_status IN ('open', 'reviewing')" => true,
        "status = 'queued'" => true,
        "status = 'processing'" => true,
        "status = 'succeeded'" => true,
        "status = 'verified'" => true,
        "status = 'suspicious'" => true,
        "status = 'fake_tampered'" => true,
        "status = 'needs_manual_review'" => true,
        "status = 'failed'" => true,
    ];

    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, $table)) {
        return 0;
    }

    if (!isset($allowedTables[$table]) || !isset($allowedPredicates[$where])) {
        ridesync_log('warning', 'Rejected unsafe metrics query shape', [
            'table' => $table,
            'where' => $where,
        ]);
        return 0;
    }

    $result = mysqli_query($conn, "SELECT COUNT(*) AS total FROM `{$table}` WHERE {$where}");
    return $result ? (int) (mysqli_fetch_assoc($result)['total'] ?? 0) : 0;
}

$dbUp = $conn instanceof mysqli && mysqli_ping($conn);
$storageWritable = is_dir(ridesync_storage_path()) && is_writable(ridesync_storage_path());

echo "# HELP ridesync_up RideSync application process health.\n";
echo "# TYPE ridesync_up gauge\n";
echo metric_line('ridesync_up', 1);

echo "# HELP ridesync_db_up RideSync MySQL connectivity.\n";
echo "# TYPE ridesync_db_up gauge\n";
echo metric_line('ridesync_db_up', $dbUp);

echo "# HELP ridesync_storage_writable RideSync storage writability.\n";
echo "# TYPE ridesync_storage_writable gauge\n";
echo metric_line('ridesync_storage_writable', $storageWritable);

echo "# HELP ridesync_users_total Total rider accounts.\n";
echo "# TYPE ridesync_users_total gauge\n";
echo metric_line('ridesync_users_total', metric_count($conn, 'users'));

echo "# HELP ridesync_drivers_total Total driver accounts by status.\n";
echo "# TYPE ridesync_drivers_total gauge\n";
foreach (['active', 'inactive', 'suspended'] as $status) {
    echo metric_line('ridesync_drivers_total', metric_count($conn, 'driver_accounts', "status = '{$status}'"), ['status' => $status]);
}

echo "# HELP ridesync_rides_total Total rides by status.\n";
echo "# TYPE ridesync_rides_total gauge\n";
foreach (['open', 'closed', 'cancelled'] as $status) {
    echo metric_line('ridesync_rides_total', metric_count($conn, 'rides', "status = '{$status}'"), ['status' => $status]);
}

echo "# HELP ridesync_reports_open Open reports requiring triage.\n";
echo "# TYPE ridesync_reports_open gauge\n";
echo metric_line('ridesync_reports_open', metric_count($conn, 'reports', "report_status IN ('open', 'reviewing')"));

echo "# HELP ridesync_verification_sessions_total Driver verification sessions by status.\n";
echo "# TYPE ridesync_verification_sessions_total gauge\n";
foreach (['queued', 'processing', 'verified', 'suspicious', 'fake_tampered', 'needs_manual_review', 'failed', 'cancelled'] as $status) {
    echo metric_line('ridesync_verification_sessions_total', metric_count($conn, 'driver_verification_sessions', "status = '{$status}'"), ['status' => $status]);
}

echo "# HELP ridesync_background_jobs_total Background job queue depth by status.\n";
echo "# TYPE ridesync_background_jobs_total gauge\n";
foreach (['queued', 'processing', 'succeeded', 'failed', 'cancelled'] as $status) {
    echo metric_line('ridesync_background_jobs_total', metric_count($conn, 'background_jobs', "status = '{$status}'"), ['status' => $status]);
}

?>
