<?php
require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/verification_helper.php';

ridesync_require_method('GET');

if (!isset($_SESSION['admin_id'])) {
    ridesync_error_response('Authentication required', 401);
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    ridesync_error_response('Not allowed', 403);
}

ridesync_enforce_rate_limit('api:driver_verification_status', 120, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'json' => true,
    'message' => 'Too many verification status requests. Please retry shortly.',
]);

if (!ridesync_verification_schema_ready($conn)) {
    ridesync_error_response('Verification schema is not ready', 503);
}

$driverId = (int) ($_GET['driver_id'] ?? 0);
if ($driverId <= 0) {
    ridesync_error_response('Invalid driver id', 400);
}

$session = ridesync_verification_latest_session($conn, $driverId);
if (!$session) {
    ridesync_json_response([
        'ok' => true,
        'has_session' => false,
        'driver_id' => $driverId,
    ]);
}

$bundle = ridesync_verification_fetch_session_bundle($conn, (int) $session['id']);
$session = $bundle['session'];

ridesync_json_response([
    'ok' => true,
    'has_session' => true,
    'driver_id' => $driverId,
    'session' => [
        'id' => (int) $session['id'],
        'status' => $session['status'],
        'status_label' => ridesync_verification_status_label($session['ai_decision'] ?: $session['status']),
        'badge_class' => ridesync_verification_badge_class($session['ai_decision'] ?: $session['status']),
        'risk_level' => $session['risk_level'],
        'risk_label' => ucfirst((string) $session['risk_level']) . ' Risk',
        'risk_badge_class' => ridesync_verification_badge_class($session['risk_level']),
        'confidence_score' => (int) round((float) $session['confidence_score']),
        'progress_stage' => $session['progress_stage'],
        'progress_stage_label' => ridesync_admin_status_label($session['progress_stage']),
        'reasons' => ridesync_verification_decode($session['reasons_json'] ?? '[]', []),
        'scores' => [
            'ocr' => (int) round((float) ($session['ocr_score'] ?? 0)),
            'api' => (int) round((float) ($session['api_score'] ?? 0)),
            'face' => (int) round((float) ($session['face_score'] ?? 0)),
            'fraud' => (int) round((float) ($session['fraud_score'] ?? 0)),
        ],
    ],
    'audit' => array_map(static function ($row) {
        return [
            'event_type' => $row['event_type'],
            'message' => $row['message'],
            'created_at' => $row['created_at'],
        ];
    }, $bundle['audit_logs']),
]);
?>
