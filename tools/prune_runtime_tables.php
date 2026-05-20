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
$logDays = null;
foreach (array_slice($argv ?? [], 1) as $arg) {
    if (str_starts_with($arg, '--days=')) {
        $days = (int) substr($arg, 7);
    } elseif (str_starts_with($arg, '--log-days=')) {
        $logDays = (int) substr($arg, 11);
    }
}
$days = max(1, min(365, $days));
$logDays = $logDays === null ? $days : max(1, min(365, $logDays));

function ridesync_prune_log_files($days, $maxFiles = 45) {
    $logDir = ridesync_storage_path('logs');
    if (!is_dir($logDir)) {
        return 0;
    }

    $removed = 0;
    $cutoff = time() - ((int) $days * 86400);
    $files = glob($logDir . DIRECTORY_SEPARATOR . 'app-*.log') ?: [];

    foreach ($files as $file) {
        if (!is_file($file)) {
            continue;
        }
        $mtime = filemtime($file);
        if ($mtime !== false && $mtime < $cutoff && @unlink($file)) {
            $removed++;
        }
    }

    $remaining = array_values(array_filter(glob($logDir . DIRECTORY_SEPARATOR . 'app-*.log') ?: [], 'is_file'));
    usort($remaining, static fn($a, $b) => (filemtime($b) ?: 0) <=> (filemtime($a) ?: 0));
    foreach (array_slice($remaining, max(0, (int) $maxFiles)) as $file) {
        if (@unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

$summary = [
    'cache_files_removed' => RideSyncCacheService::pruneFiles(),
    'log_files_removed' => ridesync_prune_log_files($logDays),
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
    'log_retention_days' => $logDays,
    'summary' => $summary,
    'timestamp' => date('c'),
], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;

?>
