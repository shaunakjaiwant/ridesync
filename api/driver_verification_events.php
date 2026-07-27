<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';

ridesync_require_method('GET');

if (!isset($_SESSION['admin_id'])) {
    ridesync_error_response('Admin session is required', 401);
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    ridesync_error_response('Active admin account is required', 403);
}

ridesync_enforce_rate_limit('sse:driver_verification_events', 60, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'message' => 'Too many verification event streams. Please retry shortly.',
]);

if (!ridesync_verification_schema_ready($conn)) {
    ridesync_error_response('Verification schema is not available', 503);
}

$driverId = (int) ($_GET['driver_id'] ?? 0);
if ($driverId <= 0) {
    ridesync_error_response('Invalid driver_id parameter', 400);
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
                'ok'        => true,
                'driver_id' => $driverId,
                'session'   => [
                    'id'                    => (int) $session['id'],
                    'status'                => $session['status'],
                    'status_label'          => ridesync_verification_status_label($session['ai_decision'] ?: $session['status']),
                    'badge_class'           => ridesync_verification_badge_class($session['ai_decision'] ?: $session['status']),
                    'risk_level'            => $session['risk_level'],
                    'risk_label'            => ucfirst((string) $session['risk_level']) . ' Risk',
                    'risk_badge_class'      => ridesync_verification_badge_class($session['risk_level']),
                    'confidence_score'      => (int) round((float) $session['confidence_score']),
                    'progress_stage'        => $session['progress_stage'],
                    'progress_stage_label'  => ridesync_admin_status_label($session['progress_stage']),
                    'reasons'               => ridesync_verification_decode($session['reasons_json'] ?? '[]', []),
                ],
                'server_time' => date('c'),
            ]);
        }
    } else {
        // Emit a heartbeat so client knows stream is alive even with no session yet
        ridesync_sse_event('driver_verification_ping', [
            'ok'         => true,
            'driver_id'  => $driverId,
            'has_session' => false,
            'server_time' => date('c'),
        ]);
    }

    if ($tick < 17) {
        sleep(5);
    }
}
?>
