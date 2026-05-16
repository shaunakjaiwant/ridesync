<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    http_response_code(403);
    exit();
}

ridesync_enforce_rate_limit('sse:admin_events', 60, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'message' => 'Too many admin live event connections. Please retry shortly.',
]);

session_write_close();

ridesync_sse_headers();

function ridesync_admin_event_metrics($conn) {
    $result = mysqli_query(
        $conn,
        "SELECT
            (SELECT COUNT(*) FROM users) AS total_users,
            (SELECT COUNT(*) FROM driver_accounts) AS total_drivers,
            (SELECT COUNT(*) FROM driver_account_availability WHERE status = 'online') AS online_drivers,
            (SELECT COUNT(*) FROM rides WHERE status = 'open') AS open_rides,
            (SELECT COUNT(*) FROM ride_live_status WHERE live_status IN ('matched', 'driver_assigned', 'arriving', 'active')) AS live_rides,
            (SELECT COUNT(*) FROM driver_account_profiles WHERE verification_status = 'pending')
                + (SELECT COUNT(*) FROM driver_account_documents WHERE verification_status = 'pending')
                + (SELECT COUNT(*) FROM user_verifications WHERE status = 'pending') AS pending_verifications,
            (SELECT COUNT(*) FROM reports WHERE report_status IN ('open', 'reviewing')) AS active_reports"
    );

    return $result ? (mysqli_fetch_assoc($result) ?: []) : [];
}

function ridesync_admin_event_latest($conn) {
    $events = [];

    $result = mysqli_query($conn, "SELECT reason, report_status, created_at FROM reports ORDER BY created_at DESC LIMIT 1");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $events[] = [
            'title' => 'Report opened',
            'detail' => $row['reason'] . ' - ' . $row['report_status'],
            'created_at' => $row['created_at'],
        ];
    }

    $result = mysqli_query($conn, "SELECT origin, destination, created_at FROM rides ORDER BY created_at DESC LIMIT 1");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $events[] = [
            'title' => 'Ride posted',
            'detail' => $row['origin'] . ' -> ' . $row['destination'],
            'created_at' => $row['created_at'],
        ];
    }

    $result = mysqli_query($conn, "SELECT email, created_at FROM driver_accounts ORDER BY created_at DESC LIMIT 1");
    if ($result && ($row = mysqli_fetch_assoc($result))) {
        $events[] = [
            'title' => 'Driver registered',
            'detail' => $row['email'],
            'created_at' => $row['created_at'],
        ];
    }

    usort($events, static function ($a, $b) {
        return strtotime($b['created_at']) <=> strtotime($a['created_at']);
    });

    return $events[0] ?? null;
}

for ($tick = 0; $tick < 12; $tick++) {
    if (connection_aborted()) {
        break;
    }

    ridesync_sse_event('admin', [
        'ok' => true,
        'metrics' => ridesync_admin_event_metrics($conn),
        'latest' => ridesync_admin_event_latest($conn),
        'server_time' => date('c'),
    ]);

    if ($tick < 11) {
        sleep(5);
    }
}
?>
