<?php
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('CLI only');
}

function ridesync_queue_parse_args(array $argv): array
{
    $options = [
        'queue' => 'notifications',
        'limit' => 25,
        'sleep' => 3,
        'watch' => false,
        'help' => false,
        'types' => [],
        'stale_seconds' => 600,
    ];

    foreach (array_slice($argv, 1) as $arg) {
        if ($arg === '--help' || $arg === '-h') {
            $options['help'] = true;
            continue;
        }
        if ($arg === '--watch') {
            $options['watch'] = true;
            continue;
        }
        if ($arg === '--once') {
            $options['watch'] = false;
            continue;
        }
        if (str_starts_with($arg, '--queue=')) {
            $options['queue'] = substr($arg, 8);
            continue;
        }
        if (str_starts_with($arg, '--limit=')) {
            $options['limit'] = (int) substr($arg, 8);
            continue;
        }
        if (str_starts_with($arg, '--sleep=')) {
            $options['sleep'] = (int) substr($arg, 8);
            continue;
        }
        if (str_starts_with($arg, '--type=')) {
            $options['types'][] = substr($arg, 7);
            continue;
        }
        if (str_starts_with($arg, '--stale-seconds=')) {
            $options['stale_seconds'] = (int) substr($arg, 16);
        }
    }

    $options['limit'] = max(1, min(250, (int) $options['limit']));
    $options['sleep'] = max(1, min(60, (int) $options['sleep']));
    $options['stale_seconds'] = max(60, min(86400, (int) $options['stale_seconds']));

    return $options;
}

function ridesync_queue_print_help(): void
{
    echo "RideSync queue worker" . PHP_EOL . PHP_EOL;
    echo "Usage:" . PHP_EOL;
    echo "  php tools/queue_worker.php --once" . PHP_EOL;
    echo "  php tools/queue_worker.php --watch --queue=notifications --limit=50" . PHP_EOL . PHP_EOL;
    echo "Options:" . PHP_EOL;
    echo "  --queue=NAME           Queue name to process. Default: notifications" . PHP_EOL;
    echo "  --type=JOB.TYPE        Restrict to a job type. May be repeated." . PHP_EOL;
    echo "  --limit=N              Max jobs per pass. Default: 25" . PHP_EOL;
    echo "  --watch                Keep polling until stopped." . PHP_EOL;
    echo "  --once                 Process one pass and exit. Default behavior." . PHP_EOL;
    echo "  --sleep=N              Seconds between watch passes. Default: 3" . PHP_EOL;
    echo "  --stale-seconds=N      Requeue stale processing jobs. Default: 600" . PHP_EOL;
}

$options = ridesync_queue_parse_args($argv ?? []);
if ($options['help']) {
    ridesync_queue_print_help();
    exit(0);
}

require_once __DIR__ . '/../config/db.php';
require_once __DIR__ . '/../includes/services/QueueService.php';
require_once __DIR__ . '/../includes/services/NotificationService.php';

mysqli_report(MYSQLI_REPORT_OFF);

if (!$conn instanceof mysqli) {
    fwrite(STDERR, 'Database connection is not available.' . PHP_EOL);
    exit(1);
}

$workerId = 'queue-worker-' . getmypid() . '-' . bin2hex(random_bytes(4));

function ridesync_queue_handle_job($conn, array $job): array
{
    $type = (string) ($job['job_type'] ?? '');
    $payload = is_array($job['payload'] ?? null) ? $job['payload'] : [];

    if ($type === 'notification.create') {
        $created = RideSyncNotificationService::create(
            $conn,
            $payload['user_id'] ?? null,
            $payload['driver_id'] ?? null,
            $payload['title'] ?? '',
            $payload['message'] ?? ''
        );

        if (!$created) {
            throw new RuntimeException('Notification could not be created.');
        }

        return ['created' => true];
    }

    throw new RuntimeException('Unsupported job type: ' . $type);
}

do {
    $released = RideSyncQueueService::releaseTimedOut($conn, (int) $options['stale_seconds']);
    $summary = RideSyncQueueService::runDue(
        $conn,
        static function (array $job) use ($conn) {
            return ridesync_queue_handle_job($conn, $job);
        },
        [
            'queue_name' => $options['queue'],
            'job_types' => $options['types'],
            'limit' => $options['limit'],
            'worker_id' => $workerId,
        ]
    );

    echo sprintf(
        "[%s] queue=%s released=%d claimed=%d succeeded=%d failed=%d%s",
        date('Y-m-d H:i:s'),
        $options['queue'],
        $released,
        $summary['claimed'],
        $summary['succeeded'],
        $summary['failed'],
        PHP_EOL
    );

    if (!$options['watch']) {
        break;
    }

    sleep((int) $options['sleep']);
} while (true);

exit(0);

?>
