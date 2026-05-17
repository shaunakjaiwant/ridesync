<?php

class RideSyncQueueService
{
    private const STATUS_QUEUED = 'queued';
    private const STATUS_PROCESSING = 'processing';
    private const STATUS_SUCCEEDED = 'succeeded';
    private const STATUS_FAILED = 'failed';

    public static function schemaReady($conn): bool
    {
        return self::tableExists($conn, 'background_jobs');
    }

    public static function enqueue($conn, string $jobType, array $payload = [], array $options = []): ?int
    {
        if (!$conn instanceof mysqli || !self::schemaReady($conn)) {
            return null;
        }

        $jobType = self::normalizeName($jobType, 80);
        $queueName = self::normalizeName((string) ($options['queue_name'] ?? 'default'), 80);
        if ($jobType === '' || $queueName === '') {
            return null;
        }

        $payloadJson = self::encodeJson($payload);
        if ($payloadJson === null) {
            return null;
        }

        $maxAttempts = max(1, min(25, (int) ($options['max_attempts'] ?? 5)));
        $availableAt = self::normalizeAvailableAt($options['available_at'] ?? null);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO background_jobs (job_type, queue_name, payload_json, max_attempts, available_at)
             VALUES (?, ?, ?, ?, ?)"
        );
        if (!$stmt) {
            return null;
        }

        mysqli_stmt_bind_param($stmt, "sssis", $jobType, $queueName, $payloadJson, $maxAttempts, $availableAt);
        if (!mysqli_stmt_execute($stmt)) {
            return null;
        }

        return (int) mysqli_insert_id($conn);
    }

    public static function claimNext($conn, string $queueName = 'default', array $jobTypes = [], int $leaseSeconds = 120, ?string $workerId = null): ?array
    {
        if (!$conn instanceof mysqli || !self::schemaReady($conn)) {
            return null;
        }

        $queueName = self::normalizeName($queueName, 80);
        if ($queueName === '') {
            return null;
        }

        $requestedJobTypeCount = count($jobTypes);
        $jobTypes = array_values(array_filter(array_map(static function ($jobType) {
            return self::normalizeName((string) $jobType, 80);
        }, $jobTypes)));
        if ($requestedJobTypeCount > 0 && empty($jobTypes)) {
            return null;
        }

        $workerId = self::normalizeWorkerId($workerId);
        $leaseSeconds = max(30, min(3600, $leaseSeconds));

        mysqli_begin_transaction($conn);

        try {
            $params = [$queueName];
            $types = 's';
            $typeFilterSql = '';
            if (!empty($jobTypes)) {
                $placeholders = implode(', ', array_fill(0, count($jobTypes), '?'));
                $typeFilterSql = " AND job_type IN ({$placeholders})";
                foreach ($jobTypes as $jobType) {
                    $params[] = $jobType;
                    $types .= 's';
                }
            }

            $stmt = mysqli_prepare(
                $conn,
                "SELECT id, job_type, queue_name, payload_json, attempts, max_attempts
                 FROM background_jobs
                 WHERE queue_name = ?
                   AND status = 'queued'
                   AND available_at <= CURRENT_TIMESTAMP
                   {$typeFilterSql}
                 ORDER BY available_at ASC, id ASC
                 LIMIT 1
                 FOR UPDATE"
            );
            if (!$stmt || !self::bindParams($stmt, $types, $params) || !mysqli_stmt_execute($stmt)) {
                mysqli_rollback($conn);
                return null;
            }

            $result = mysqli_stmt_get_result($stmt);
            $job = $result ? mysqli_fetch_assoc($result) : null;
            if (!$job) {
                mysqli_commit($conn);
                return null;
            }

            $jobId = (int) $job['id'];
            $expiresAt = date('Y-m-d H:i:s', time() + $leaseSeconds);
            $statusProcessing = self::STATUS_PROCESSING;
            $update = mysqli_prepare(
                $conn,
                "UPDATE background_jobs
                 SET status = ?, attempts = attempts + 1, locked_at = CURRENT_TIMESTAMP, locked_by = ?,
                     available_at = ?, last_error = NULL
                 WHERE id = ? AND status = 'queued'"
            );
            if (!$update) {
                mysqli_rollback($conn);
                return null;
            }

            mysqli_stmt_bind_param($update, "sssi", $statusProcessing, $workerId, $expiresAt, $jobId);
            if (!mysqli_stmt_execute($update) || mysqli_stmt_affected_rows($update) !== 1) {
                mysqli_rollback($conn);
                return null;
            }

            mysqli_commit($conn);

            $job['id'] = $jobId;
            $job['attempts'] = ((int) $job['attempts']) + 1;
            $job['max_attempts'] = (int) $job['max_attempts'];
            $job['payload'] = self::decodeJson((string) $job['payload_json']);
            unset($job['payload_json']);

            return $job;
        } catch (Throwable $exception) {
            mysqli_rollback($conn);
            return null;
        }
    }

    public static function markSucceeded($conn, int $jobId, array $result = []): bool
    {
        if ($jobId <= 0 || !$conn instanceof mysqli || !self::schemaReady($conn)) {
            return false;
        }

        $resultJson = self::encodeJson($result) ?? '{}';
        $status = self::STATUS_SUCCEEDED;
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE background_jobs
             SET status = ?, locked_at = NULL, locked_by = NULL, result_json = ?, last_error = NULL,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "ssi", $status, $resultJson, $jobId);
        return mysqli_stmt_execute($stmt);
    }

    public static function markFailed($conn, int $jobId, string $error): bool
    {
        if ($jobId <= 0 || !$conn instanceof mysqli || !self::schemaReady($conn)) {
            return false;
        }

        $stmt = mysqli_prepare($conn, "SELECT attempts, max_attempts FROM background_jobs WHERE id = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, "i", $jobId);
        if (!mysqli_stmt_execute($stmt)) {
            return false;
        }

        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if (!$row) {
            return false;
        }

        $attempts = max(1, (int) $row['attempts']);
        $maxAttempts = max(1, (int) $row['max_attempts']);
        $willRetry = $attempts < $maxAttempts;
        $status = $willRetry ? self::STATUS_QUEUED : self::STATUS_FAILED;
        $delaySeconds = $willRetry ? min(3600, (int) (30 * (2 ** max(0, $attempts - 1)))) : 0;
        $availableAt = date('Y-m-d H:i:s', time() + $delaySeconds);
        $lastError = self::truncate($error, 255);

        $update = mysqli_prepare(
            $conn,
            "UPDATE background_jobs
             SET status = ?, available_at = ?, locked_at = NULL, locked_by = NULL, last_error = ?,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        if (!$update) {
            return false;
        }

        mysqli_stmt_bind_param($update, "sssi", $status, $availableAt, $lastError, $jobId);
        return mysqli_stmt_execute($update);
    }

    public static function releaseTimedOut($conn, int $staleSeconds = 600): int
    {
        if (!$conn instanceof mysqli || !self::schemaReady($conn)) {
            return 0;
        }

        $staleSeconds = max(60, min(86400, $staleSeconds));
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE background_jobs
             SET status = 'queued', locked_at = NULL, locked_by = NULL, updated_at = CURRENT_TIMESTAMP
             WHERE status = 'processing'
               AND locked_at IS NOT NULL
               AND locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL ? SECOND)"
        );
        if (!$stmt) {
            return 0;
        }

        mysqli_stmt_bind_param($stmt, "i", $staleSeconds);
        mysqli_stmt_execute($stmt);
        return max(0, mysqli_stmt_affected_rows($stmt));
    }

    public static function runDue($conn, callable $handler, array $options = []): array
    {
        $limit = max(1, min(250, (int) ($options['limit'] ?? 25)));
        $queueName = (string) ($options['queue_name'] ?? 'default');
        $jobTypes = $options['job_types'] ?? [];
        $leaseSeconds = (int) ($options['lease_seconds'] ?? 120);
        $workerId = $options['worker_id'] ?? null;
        $summary = [
            'claimed' => 0,
            'succeeded' => 0,
            'failed' => 0,
            'idle' => false,
        ];

        for ($i = 0; $i < $limit; $i++) {
            $job = self::claimNext($conn, $queueName, is_array($jobTypes) ? $jobTypes : [], $leaseSeconds, $workerId);
            if (!$job) {
                $summary['idle'] = $summary['claimed'] === 0;
                break;
            }

            $summary['claimed']++;
            try {
                $result = $handler($job);
                self::markSucceeded($conn, (int) $job['id'], is_array($result) ? $result : ['ok' => (bool) $result]);
                $summary['succeeded']++;
            } catch (Throwable $exception) {
                self::markFailed($conn, (int) $job['id'], $exception->getMessage());
                $summary['failed']++;
            }
        }

        return $summary;
    }

    private static function normalizeName(string $name, int $maxLength): string
    {
        $name = strtolower(trim($name));
        if (!preg_match('/^[a-z0-9_.:-]+$/', $name)) {
            return '';
        }

        return substr($name, 0, $maxLength);
    }

    private static function normalizeWorkerId(?string $workerId): string
    {
        $workerId = trim((string) $workerId);
        if ($workerId === '') {
            $workerId = 'php-worker-' . getmypid() . '-' . bin2hex(random_bytes(4));
        }

        return self::truncate(preg_replace('/[^A-Za-z0-9_.:-]/', '-', $workerId), 120);
    }

    private static function normalizeAvailableAt($value): string
    {
        if ($value instanceof DateTimeInterface) {
            return $value->format('Y-m-d H:i:s');
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        if (is_string($value) && trim($value) !== '') {
            $timestamp = strtotime($value);
            if ($timestamp !== false) {
                return date('Y-m-d H:i:s', $timestamp);
            }
        }

        return date('Y-m-d H:i:s');
    }

    private static function encodeJson(array $value): ?string
    {
        $json = json_encode($value, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        return $json === false ? null : $json;
    }

    private static function decodeJson(string $value): array
    {
        $decoded = json_decode($value, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function bindParams(mysqli_stmt $stmt, string $types, array &$params): bool
    {
        if ($types === '') {
            return true;
        }

        $refs = [$types];
        foreach ($params as $index => &$param) {
            $refs[] = &$param;
        }

        return call_user_func_array([$stmt, 'bind_param'], $refs);
    }

    private static function truncate(string $value, int $length): string
    {
        $value = trim($value);
        if (function_exists('mb_substr')) {
            return mb_substr($value, 0, $length);
        }

        return substr($value, 0, $length);
    }

    private static function tableExists($conn, string $table): bool
    {
        if (!$conn instanceof mysqli) {
            return false;
        }

        if (function_exists('ridesync_table_exists')) {
            return ridesync_table_exists($conn, $table);
        }

        $safeTable = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
        return $result && mysqli_num_rows($result) > 0;
    }
}

?>
