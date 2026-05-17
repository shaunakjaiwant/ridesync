<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/matching_helper.php';

$expectedToken = trim((string) ridesync_env('RIDESYNC_METRICS_TOKEN', ''));
$providedToken = trim((string) ($_SERVER['HTTP_AUTHORIZATION'] ?? ''));
if (str_starts_with(strtolower($providedToken), 'bearer ')) {
    $providedToken = trim(substr($providedToken, 7));
}

if ($expectedToken !== '' && !hash_equals($expectedToken, $providedToken)) {
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
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, $table)) {
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

?>
