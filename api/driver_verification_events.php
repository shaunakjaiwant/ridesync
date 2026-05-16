<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';

if (!isset($_SESSION['admin_id'])) {
    http_response_code(401);
    exit();
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    http_response_code(403);
    exit();
}

ridesync_enforce_rate_limit('sse:driver_verification_events', 20, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'message' => 'Too many verification event streams. Please retry shortly.',
]);

if (!ridesync_verification_schema_ready($conn)) {
    http_response_code(503);
    exit();
}

$driverId = (int) ($_GET['driver_id'] ?? 0);
if ($driverId <= 0) {
    http_response_code(400);
    exit();
}

session_write_close();
ridesync_sse_headers();

$lastSignature = '';
for ($tick = 0; $tick < 18; $tick++) {
    if (connection_aborted()) {
        break;
    }

    $session = ridesync_verification_latest_session($conn, $driverId);
    if ($session) {
        $signature = implode('|', [
            $session['id'],
            $session['status'],
            $session['ai_decision'],
            $session['risk_level'],
            $session['confidence_score'],
            $session['progress_stage'],
            $session['updated_at'],
        ]);

        if ($signature !== $lastSignature || $tick === 0) {
            $lastSignature = $signature;
            ridesync_sse_event('driver_verification', [
                'ok' => true,
                'driver_id' => $driverId,
                'session' => [
                    'id' => (int) $session['id'],
                    'status_label' => ridesync_verification_status_label($session['ai_decision'] ?: $session['status']),
                    'badge_class' => ridesync_verification_badge_class($session['ai_decision'] ?: $session['status']),
                    'risk_label' => ucfirst((string) $session['risk_level']) . ' Risk',
                    'risk_badge_class' => ridesync_verification_badge_class($session['risk_level']),
                    'confidence_score' => (int) round((float) $session['confidence_score']),
                    'progress_stage_label' => ridesync_admin_status_label($session['progress_stage']),
                    'reasons' => ridesync_verification_decode($session['reasons_json'] ?? '[]', []),
                ],
            ]);
        }
    }

    if ($tick < 17) {
        sleep(5);
    }
}
?>
