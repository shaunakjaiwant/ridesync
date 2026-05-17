<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/services/CacheService.php';
require_once __DIR__ . '/../includes/services/RealtimeEventService.php';
require_once __DIR__ . '/../includes/services/QueueService.php';

mysqli_report(MYSQLI_REPORT_OFF);

$days = 14;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = (int) substr($arg, 7);
    }
}
$days = max(1, min(365, $days));

$summary = [
    'cache_files_removed' => RideSyncCacheService::pruneFiles(),
    'realtime_events_removed' => 0,
    'background_jobs_removed' => 0,
];

if ($conn instanceof mysqli) {
    $summary['realtime_events_removed'] = RideSyncRealtimeEventService::pruneExpired($conn, 5000);

    if (RideSyncQueueService::schemaReady($conn)) {
        $stmt = mysqli_prepare(
            $conn,
            "DELETE FROM background_jobs
             WHERE status IN ('succeeded', 'failed', 'cancelled')
               AND updated_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? DAY)
             ORDER BY updated_at ASC
             LIMIT 5000"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, "i", $days);
            mysqli_stmt_execute($stmt);
            $summary['background_jobs_removed'] = max(0, mysqli_stmt_affected_rows($stmt));
        }
    }
}

echo json_encode([
    'ok' => true,
    'retention_days' => $days,
    'summary' => $summary,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

?>
