<?php
require_once __DIR__ . '/CacheService.php';
require_once __DIR__ . '/QueueService.php';
require_once __DIR__ . '/ServiceObservabilityService.php';
require_once __DIR__ . '/../matching_helper.php';
require_once __DIR__ . '/../logger.php';

class RideSyncRepairKitService
{
    private const SNAPSHOT_CACHE_KEY = 'admin:repair-kit:snapshot:v1';
    private const SNAPSHOT_TTL_SECONDS = 20;

    public static function snapshot($conn, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        if (!$force) {
            return RideSyncCacheService::remember(self::SNAPSHOT_CACHE_KEY, self::SNAPSHOT_TTL_SECONDS, static function () use ($conn) {
                return self::buildSnapshot($conn);
            });
        }

        return self::buildSnapshot($conn);
    }

    public static function clearSnapshotCache(): void
    {
        RideSyncCacheService::delete(self::SNAPSHOT_CACHE_KEY);
    }

    public static function schemaReady($conn): bool
    {
        return $conn instanceof mysqli
            && ridesync_table_exists($conn, 'repair_kit_runs')
            && ridesync_column_exists($conn, 'repair_kit_runs', 'log_ciphertext')
            && ridesync_column_exists($conn, 'repair_kit_runs', 'checkpoint_json');
    }

    public static function operations(): array
    {
        return [
            'deep_scan' => [
                'label' => 'Run Deep Scan',
                'description' => 'Refresh every service, API, queue, schema, storage, security, and AI diagnostic without changing state.',
                'category' => 'Scanner',
                'severity' => 'info',
                'confirmation' => '',
                'button' => 'Scan now',
            ],
            'force_health_recheck' => [
                'label' => 'Force Health Recheck',
                'description' => 'Clear repair and service-monitor caches, then rebuild the live health snapshot.',
                'category' => 'Diagnostics',
                'severity' => 'info',
                'confirmation' => '',
                'button' => 'Recheck health',
            ],
            'flush_cache' => [
                'label' => 'Force Cache Purge',
                'description' => 'Remove expired file-cache entries and clear admin service snapshots.',
                'category' => 'Optimization',
                'severity' => 'warning',
                'confirmation' => 'PURGE CACHE',
                'button' => 'Purge cache',
            ],
            'repair_queues' => [
                'label' => 'Repair Queues',
                'description' => 'Release stale processing jobs and requeue failed AI verification work.',
                'category' => 'Recovery',
                'severity' => 'warning',
                'confirmation' => 'REPAIR QUEUES',
                'button' => 'Repair queues',
            ],
            'ai_recovery' => [
                'label' => 'AI Recovery',
                'description' => 'Reset AI service snapshots, retry failed verification jobs, and validate the AI service contract view.',
                'category' => 'AI',
                'severity' => 'warning',
                'confirmation' => 'RESET AI',
                'button' => 'Recover AI',
            ],
            'repair_indexes' => [
                'label' => 'Repair Indexes',
                'description' => 'Apply missing known operational indexes used by admin, queue, notification, audit, and service monitors.',
                'category' => 'Database',
                'severity' => 'warning',
                'confirmation' => 'REPAIR INDEXES',
                'button' => 'Repair indexes',
            ],
            'optimize_tables' => [
                'label' => 'Optimize Tables',
                'description' => 'Run bounded MySQL table optimization for high-churn operational tables.',
                'category' => 'Performance',
                'severity' => 'warning',
                'confirmation' => 'OPTIMIZE DB',
                'button' => 'Optimize DB',
            ],
            'rotate_logs' => [
                'label' => 'Rotate Logs',
                'description' => 'Rotate oversized app logs and prune old rotated log files without deleting current diagnostics.',
                'category' => 'Observability',
                'severity' => 'info',
                'confirmation' => 'ROTATE LOGS',
                'button' => 'Rotate logs',
            ],
            'storage_cleanup' => [
                'label' => 'Storage Cleanup',
                'description' => 'Ensure runtime folders exist, prune expired cache records, and remove stale temporary files.',
                'category' => 'Storage',
                'severity' => 'info',
                'confirmation' => 'CLEAN STORAGE',
                'button' => 'Clean storage',
            ],
            'maintenance_mode' => [
                'label' => 'Activate Maintenance',
                'description' => 'Set feature flags into maintenance mode to pause user-facing modules while admins investigate.',
                'category' => 'Emergency',
                'severity' => 'critical',
                'confirmation' => 'MAINTENANCE ON',
                'button' => 'Maintenance on',
            ],
            'ai_kill_switch' => [
                'label' => 'AI Kill Switch',
                'description' => 'Disable the AI verification feature flag and place it in maintenance mode.',
                'category' => 'Emergency',
                'severity' => 'critical',
                'confirmation' => 'KILL AI',
                'button' => 'Kill AI',
            ],
            'platform_recovery' => [
                'label' => 'One-click Platform Recovery',
                'description' => 'Run the safe recovery bundle: storage repair, cache purge, queue repair, AI recovery, and log rotation.',
                'category' => 'God Mode',
                'severity' => 'critical',
                'confirmation' => 'RECOVER PLATFORM',
                'button' => 'Recover platform',
            ],
            'queue_full_restart' => [
                'label' => 'Queue Full Restart Hook',
                'description' => 'Create an audited ops handoff job for infrastructure restart workers. The web app does not restart host processes directly.',
                'category' => 'God Mode',
                'severity' => 'critical',
                'confirmation' => 'QUEUE RESTART',
                'button' => 'Queue restart',
            ],
            'queue_rollback' => [
                'label' => 'Emergency Rollback Hook',
                'description' => 'Create an audited ops handoff job for rollback automation. Execution requires a configured ops worker.',
                'category' => 'God Mode',
                'severity' => 'critical',
                'confirmation' => 'QUEUE ROLLBACK',
                'button' => 'Queue rollback',
            ],
        ];
    }

    public static function execute($conn, int $adminId, string $operation, string $confirmation = ''): array
    {
        $operations = self::operations();
        if (!isset($operations[$operation])) {
            return self::result(false, 'Unsupported repair operation.', ['operation' => $operation]);
        }

        $definition = $operations[$operation];
        $expected = (string) ($definition['confirmation'] ?? '');
        if ($expected !== '' && !hash_equals($expected, trim($confirmation))) {
            self::recordRun($conn, $adminId, $operation, 'blocked', 'Confirmation phrase mismatch.', [], [
                'expected_confirmation' => $expected,
            ]);
            return self::result(false, 'Repair cancelled. Confirmation phrase did not match.', [
                'expected_confirmation' => $expected,
            ]);
        }

        $checkpoint = self::checkpoint($conn);
        $runId = self::recordRun($conn, $adminId, $operation, 'running', 'Repair operation started.', $checkpoint, [
            'operation_label' => $definition['label'],
        ]);

        try {
            $details = [];
            if ($operation === 'deep_scan') {
                self::clearSnapshotCache();
                RideSyncServiceObservabilityService::clearSnapshotCache();
                $details['snapshot'] = self::snapshot($conn, ['force' => true])['summary'] ?? [];
            } elseif ($operation === 'force_health_recheck') {
                self::clearSnapshotCache();
                RideSyncServiceObservabilityService::clearSnapshotCache();
                $details['service_status'] = RideSyncServiceObservabilityService::snapshot($conn, ['force' => true])['summary'] ?? [];
                $details['repair_status'] = self::snapshot($conn, ['force' => true])['summary'] ?? [];
            } elseif ($operation === 'flush_cache') {
                $details = self::repairCache();
            } elseif ($operation === 'repair_queues') {
                $details = self::repairQueues($conn);
            } elseif ($operation === 'ai_recovery') {
                $details = self::repairAi($conn);
            } elseif ($operation === 'repair_indexes') {
                $details = self::repairIndexes($conn, $adminId);
            } elseif ($operation === 'optimize_tables') {
                $details = self::optimizeTables($conn);
            } elseif ($operation === 'rotate_logs') {
                $details = self::rotateLogs();
            } elseif ($operation === 'storage_cleanup') {
                $details = self::storageCleanup();
            } elseif ($operation === 'maintenance_mode') {
                $details = self::setMaintenanceMode($conn, $adminId);
            } elseif ($operation === 'ai_kill_switch') {
                $details = self::aiKillSwitch($conn, $adminId);
            } elseif ($operation === 'platform_recovery') {
                $details = self::platformRecovery($conn, $adminId);
            } elseif ($operation === 'queue_full_restart') {
                $details = self::queueOpsHandoff($conn, 'repair.ops.full_restart', $adminId);
            } elseif ($operation === 'queue_rollback') {
                $details = self::queueOpsHandoff($conn, 'repair.ops.rollback', $adminId);
            }

            self::clearSnapshotCache();
            RideSyncServiceObservabilityService::clearSnapshotCache();
            self::finishRun($conn, $runId, 'succeeded', 'Repair operation completed.', $details);

            return self::result(true, (string) $definition['label'] . ' completed.', $details + ['run_id' => $runId]);
        } catch (Throwable $exception) {
            ridesync_log_exception($exception, [
                'repair_operation' => $operation,
                'admin_id' => $adminId,
            ]);
            self::finishRun($conn, $runId, 'failed', $exception->getMessage(), [
                'exception' => get_class($exception),
            ]);

            return self::result(false, 'Repair operation failed: ' . $exception->getMessage(), ['run_id' => $runId]);
        }
    }

    public static function recentRuns($conn, int $limit = 8): array
    {
        if (!self::schemaReady($conn)) {
            return [];
        }

        $limit = max(1, min(25, $limit));
        $result = mysqli_query($conn, "SELECT
                                            rk.id,
                                            rk.run_uuid,
                                            rk.admin_id,
                                            rk.action_key,
                                            rk.status,
                                            rk.severity,
                                            rk.result_json,
                                            rk.log_hash,
                                            rk.started_at,
                                            rk.finished_at,
                                            rk.created_at,
                                            rk.updated_at,
                                            au.name AS admin_name
                                       FROM repair_kit_runs rk
                                       LEFT JOIN admin_users au ON au.id = rk.admin_id
                                       ORDER BY rk.created_at DESC, rk.id DESC
                                       LIMIT " . (int) $limit);
        $rows = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $row['result'] = self::decodeJson((string) ($row['result_json'] ?? ''));
            $rows[] = $row;
        }

        return $rows;
    }

    private static function buildSnapshot($conn): array
    {
        $serviceSnapshot = RideSyncServiceObservabilityService::snapshot($conn, ['probe_external' => false]);
        $findings = array_merge(
            self::scanServices($serviceSnapshot),
            self::scanEnvironment(),
            self::scanDatabase($conn),
            self::scanQueues($conn),
            self::scanStorage(),
            self::scanSecurity(),
            self::scanDependencies(),
            self::scanApiContracts(),
            self::scanLogs()
        );

        self::sortFindings($findings);
        $summary = self::summary($findings, $serviceSnapshot);
        $runs = self::recentRuns($conn, 8);

        return [
            'ok' => $summary['status'] === 'healthy',
            'status' => $summary['status'],
            'generated_at' => date('c'),
            'schema_ready' => self::schemaReady($conn),
            'summary' => $summary,
            'findings' => array_slice($findings, 0, 30),
            'actions' => self::operationsWithState($findings),
            'recent_runs' => $runs,
            'checkpoint' => self::checkpoint($conn),
            'runtime' => self::runtimeMetrics(),
        ];
    }

    private static function scanServices(array $serviceSnapshot): array
    {
        $findings = [];
        foreach (($serviceSnapshot['services'] ?? []) as $service) {
            $status = (string) ($service['status'] ?? 'unknown');
            if (in_array($status, ['down', 'degraded', 'unknown'], true)) {
                $findings[] = self::finding(
                    $status === 'down' ? 'critical' : 'warning',
                    'Service ' . ($service['name'] ?? 'unknown') . ' requires attention',
                    (string) ($service['summary'] ?? 'No service summary was available.'),
                    'Services',
                    $status === 'down' ? 'force_health_recheck' : 'deep_scan'
                );
            }
        }

        foreach (($serviceSnapshot['alerts'] ?? []) as $alert) {
            $findings[] = self::finding(
                ($alert['severity'] ?? '') === 'critical' ? 'critical' : 'warning',
                (string) ($alert['title'] ?? 'Service alert'),
                (string) ($alert['detail'] ?? 'Review service monitor.'),
                'Alerts',
                ($alert['service_key'] ?? '') === 'ai_verification' ? 'ai_recovery' : 'force_health_recheck'
            );
        }

        return $findings;
    }

    private static function scanEnvironment(): array
    {
        $findings = [];
        $required = [
            'RIDESYNC_ENV' => ['min' => 1, 'secret' => false],
            'RIDESYNC_DB_NAME' => ['min' => 1, 'secret' => false],
            'RIDESYNC_DB_USER' => ['min' => 1, 'secret' => false],
            'RIDESYNC_DOCUMENT_ENCRYPTION_KEY' => ['min' => 32, 'secret' => true, 'base64' => true],
            'RIDESYNC_METRICS_TOKEN' => ['min' => 32, 'secret' => true],
            'RIDESYNC_WS_SHARED_TOKEN' => ['min' => 32, 'secret' => true],
        ];

        if (ridesync_app_env() === 'production') {
            $required['RIDESYNC_DB_PASSWORD'] = ['min' => 24, 'secret' => true];
            $required['RIDESYNC_WEBSOCKET_URL'] = ['min' => 8, 'secret' => false];
            $required['RIDESYNC_VERIFICATION_SERVICE_TOKEN'] = ['min' => 32, 'secret' => true];
        }

        foreach ($required as $key => $rule) {
            $value = (string) ridesync_env($key, '');
            $configured = !empty($rule['base64'])
                ? ridesync_base64_key_is_configured($value, 32)
                : (trim($value) !== '' && strlen(trim($value)) >= (int) $rule['min'] && stripos(trim($value), 'replace-with') !== 0);
            if (!$configured) {
                $findings[] = self::finding(
                    'critical',
                    'Missing or weak environment variable: ' . $key,
                    !empty($rule['secret'])
                        ? 'Secret must be configured out-of-band with production-grade entropy; Repair Kit will not invent secrets inside a web request.'
                        : 'Runtime configuration must be deterministic before production.',
                    'Environment',
                    'deep_scan'
                );
            }
        }

        if (ridesync_app_env() === 'production' && !ridesync_env_bool('RIDESYNC_COOKIE_SECURE', false)) {
            $findings[] = self::finding('critical', 'Secure cookies are disabled in production', 'Set RIDESYNC_COOKIE_SECURE=true when serving over HTTPS.', 'Auth', 'deep_scan');
        }

        return $findings;
    }

    private static function scanDatabase($conn): array
    {
        $findings = [];
        if (!$conn instanceof mysqli || !@mysqli_ping($conn)) {
            return [self::finding('critical', 'Database connection is unavailable', 'MySQL ping failed from the repair scanner.', 'Database', 'force_health_recheck')];
        }

        foreach (['users', 'rides', 'matches', 'driver_accounts', 'admin_users', 'audit_logs', 'background_jobs', 'realtime_events'] as $table) {
            if (!ridesync_table_exists($conn, $table)) {
                $findings[] = self::finding('critical', 'Missing required table: ' . $table, 'Schema drift can break production flows.', 'Database', 'repair_indexes');
            }
        }

        foreach (self::knownIndexes() as $check) {
            if (ridesync_table_exists($conn, $check['table']) && !self::indexExists($conn, $check['table'], $check['index'])) {
                $findings[] = self::finding('warning', 'Missing database index: ' . $check['index'], $check['table'] . ' needs ' . $check['index'] . ' for production query health.', 'Database', 'repair_indexes');
            }
        }

        foreach (self::orphanChecks() as $check) {
            if (!ridesync_table_exists($conn, $check['child']) || !ridesync_table_exists($conn, $check['parent']) || !ridesync_column_exists($conn, $check['child'], $check['column'])) {
                continue;
            }
            $count = self::scalar($conn, "SELECT COUNT(*)
                                         FROM `{$check['child']}` c
                                         LEFT JOIN `{$check['parent']}` p ON p.id = c.`{$check['column']}`
                                         WHERE c.`{$check['column']}` IS NOT NULL AND p.id IS NULL");
            if ($count > 0) {
                $findings[] = self::finding('critical', 'Orphaned records detected in ' . $check['child'], $count . ' row(s) reference missing ' . $check['parent'] . ' records.', 'Database', 'deep_scan');
            }
        }

        return $findings;
    }

    private static function scanQueues($conn): array
    {
        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'background_jobs')) {
            return [self::finding('warning', 'Background job schema is unavailable', 'Queue recovery cannot run until background_jobs exists.', 'Queues', 'deep_scan')];
        }

        $findings = [];
        $failed = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'failed'");
        $stale = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'processing' AND locked_at < DATE_SUB(CURRENT_TIMESTAMP, INTERVAL 10 MINUTE)");
        $invalidPayloads = self::invalidJobPayloadCount($conn);

        if ($failed > 0) {
            $findings[] = self::finding('critical', 'Failed background jobs detected', $failed . ' job(s) are failed and need recovery.', 'Queues', 'repair_queues');
        }
        if ($stale > 0) {
            $findings[] = self::finding('warning', 'Stale processing jobs detected', $stale . ' job(s) have exceeded their processing lease.', 'Queues', 'repair_queues');
        }
        if ($invalidPayloads > 0) {
            $findings[] = self::finding('critical', 'Malformed queue payloads detected', $invalidPayloads . ' job payload(s) are not valid JSON.', 'Queues', 'deep_scan');
        }

        return $findings;
    }

    private static function scanStorage(): array
    {
        $findings = [];
        foreach (['', 'logs', 'cache', 'rate_limits', 'backups'] as $relative) {
            $path = ridesync_storage_path($relative);
            if (!ridesync_ensure_directory($path) || !is_writable($path)) {
                $findings[] = self::finding('critical', 'Runtime storage is not writable: ' . ($relative ?: 'storage'), $path, 'Storage', 'storage_cleanup');
            }
        }

        $free = @disk_free_space(ridesync_storage_path());
        $total = @disk_total_space(ridesync_storage_path());
        if (is_numeric($free) && is_numeric($total) && $total > 0) {
            $freePercent = ($free / $total) * 100;
            if ($freePercent < 10) {
                $findings[] = self::finding('critical', 'Low disk space', round($freePercent, 2) . '% free on runtime storage volume.', 'Storage', 'storage_cleanup');
            } elseif ($freePercent < 20) {
                $findings[] = self::finding('warning', 'Disk space is getting tight', round($freePercent, 2) . '% free on runtime storage volume.', 'Storage', 'storage_cleanup');
            }
        }

        return $findings;
    }

    private static function scanSecurity(): array
    {
        $findings = [];
        if (ridesync_app_env() === 'production' && ridesync_is_debug()) {
            $findings[] = self::finding('critical', 'Debug mode is enabled in production', 'RIDESYNC_DEBUG must be false in production.', 'Security', 'deep_scan');
        }
        if (!ridesync_env_secret_is_configured('RIDESYNC_METRICS_TOKEN', 32)) {
            $findings[] = self::finding('critical', 'Metrics endpoint token is missing or weak', 'Prometheus metrics must stay bearer-token protected.', 'Security', 'deep_scan');
        }
        if (!function_exists('openssl_encrypt')) {
            $findings[] = self::finding('critical', 'OpenSSL is unavailable', 'Encrypted repair logs and driver document crypto require OpenSSL.', 'Security', 'deep_scan');
        }
        $hasDedicatedRepairKey = self::decodedEnvKey('RIDESYNC_REPAIR_LOG_KEY') !== null;
        $hasDocumentFallbackKey = self::decodedEnvKey('RIDESYNC_DOCUMENT_ENCRYPTION_KEY') !== null;
        if (!$hasDedicatedRepairKey && !$hasDocumentFallbackKey) {
            $findings[] = self::finding('critical', 'Repair log encryption key is unavailable', 'Set RIDESYNC_REPAIR_LOG_KEY to a base64 encoded 32-byte key before running destructive recovery workflows.', 'Security', 'deep_scan');
        } elseif (!$hasDedicatedRepairKey) {
            $findings[] = self::finding('warning', 'Dedicated repair log key is not configured', 'Repair logs are using the document encryption key fallback. Set RIDESYNC_REPAIR_LOG_KEY to isolate recovery-log encryption.', 'Security', 'deep_scan');
        }

        return $findings;
    }

    private static function scanDependencies(): array
    {
        $findings = [];
        $requiredFiles = [
            'package-lock.json',
            'realtime/websocket-gateway/package-lock.json',
            'apps/ai-verification/app/main.py',
            'apps/ai-verification/scripts/selftest_service.py',
            'docker-compose.yml',
            'Dockerfile',
            'infrastructure/scripts/cron/ridesync-maintenance.cron',
        ];
        foreach ($requiredFiles as $relative) {
            if (!is_file(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $findings[] = self::finding('warning', 'Missing operational dependency file', $relative . ' is expected by production readiness checks.', 'Dependencies', 'deep_scan');
            }
        }

        return $findings;
    }

    private static function scanApiContracts(): array
    {
        $findings = [];
        foreach (['api/admin_services.php', 'api/metrics.php', 'api/realtime_token.php', 'docs/openapi.yaml'] as $relative) {
            if (!is_file(RIDESYNC_ROOT . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $relative))) {
                $findings[] = self::finding('warning', 'API contract artifact missing', $relative . ' is required for service diagnostics and contract verification.', 'API', 'deep_scan');
            }
        }

        return $findings;
    }

    private static function scanLogs(): array
    {
        $findings = [];
        $logDir = ridesync_storage_path('logs');
        foreach (glob($logDir . DIRECTORY_SEPARATOR . 'app-*.log') ?: [] as $file) {
            $size = is_file($file) ? filesize($file) : 0;
            if ($size > 5 * 1024 * 1024) {
                $findings[] = self::finding('warning', 'Oversized application log', basename($file) . ' is ' . round($size / 1024 / 1024, 2) . ' MB.', 'Logs', 'rotate_logs');
            }
        }

        return $findings;
    }

    private static function summary(array $findings, array $serviceSnapshot): array
    {
        $critical = count(array_filter($findings, static fn($finding) => ($finding['severity'] ?? '') === 'critical'));
        $warning = count(array_filter($findings, static fn($finding) => ($finding['severity'] ?? '') === 'warning'));
        $score = max(0, 100 - ($critical * 18) - ($warning * 6));
        $status = $critical > 0 ? 'critical' : ($warning > 0 ? 'degraded' : 'healthy');

        return [
            'status' => $status,
            'status_label' => $status === 'healthy' ? 'Healthy' : ($status === 'critical' ? 'Critical' : 'Degraded'),
            'repair_score' => $score,
            'critical_findings' => $critical,
            'warning_findings' => $warning,
            'total_findings' => count($findings),
            'service_status' => $serviceSnapshot['summary']['status_label'] ?? 'Unknown',
            'service_uptime' => $serviceSnapshot['summary']['current_uptime_percent'] ?? 0,
            'recommended_actions' => count(array_filter(self::operationsWithState($findings), static fn($operation) => ($operation['recommended'] ?? false))),
        ];
    }

    private static function operationsWithState(array $findings): array
    {
        $recommended = [];
        foreach ($findings as $finding) {
            $key = (string) ($finding['action_key'] ?? '');
            if ($key !== '') {
                $recommended[$key] = true;
            }
        }

        $operations = [];
        foreach (self::operations() as $key => $definition) {
            $definition['key'] = $key;
            $definition['recommended'] = isset($recommended[$key]);
            $operations[] = $definition;
        }

        usort($operations, static function ($a, $b) {
            if (($a['recommended'] ?? false) !== ($b['recommended'] ?? false)) {
                return !empty($a['recommended']) ? -1 : 1;
            }
            $rank = ['critical' => 3, 'warning' => 2, 'info' => 1];
            return ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0);
        });

        return $operations;
    }

    private static function repairCache(): array
    {
        RideSyncServiceObservabilityService::clearSnapshotCache();
        self::clearSnapshotCache();
        $pruned = RideSyncCacheService::pruneFiles(5000);
        $removedSnapshots = 0;
        foreach (glob(ridesync_storage_path('cache') . DIRECTORY_SEPARATOR . '*.json') ?: [] as $path) {
            $contents = (string) @file_get_contents($path);
            if (str_contains($contents, 'admin:services') || str_contains($contents, 'admin:repair-kit')) {
                @unlink($path);
                $removedSnapshots++;
            }
        }

        return [
            'expired_cache_files_pruned' => $pruned,
            'snapshot_files_removed' => $removedSnapshots,
        ];
    }

    private static function repairQueues($conn): array
    {
        $released = RideSyncServiceObservabilityService::releaseTimedOutJobs($conn, 600);
        $retry = RideSyncServiceObservabilityService::retryFailedVerificationJobs($conn, 50);
        return [
            'stale_jobs_released' => $released,
            'ai_jobs_requeued' => (int) $retry['jobs_requeued'] + (int) $retry['jobs_created'],
            'ai_sessions_requeued' => (int) $retry['sessions_requeued'],
        ];
    }

    private static function repairAi($conn): array
    {
        RideSyncServiceObservabilityService::clearSnapshotCache();
        $retry = RideSyncServiceObservabilityService::retryFailedVerificationJobs($conn, 50);
        $snapshot = RideSyncServiceObservabilityService::snapshot($conn, ['force' => true, 'probe_external' => false]);
        $aiService = null;
        foreach (($snapshot['services'] ?? []) as $service) {
            if (($service['key'] ?? '') === 'ai_verification') {
                $aiService = $service;
                break;
            }
        }

        return [
            'jobs_requeued' => (int) $retry['jobs_requeued'] + (int) $retry['jobs_created'],
            'sessions_requeued' => (int) $retry['sessions_requeued'],
            'ai_service_status' => $aiService['status_label'] ?? 'Unknown',
            'fallback_required' => in_array($aiService['status'] ?? 'unknown', ['down', 'degraded', 'unknown'], true),
        ];
    }

    private static function repairIndexes($conn, int $adminId): array
    {
        $added = 0;
        $skipped = 0;
        $failed = [];
        $handoffIndexes = [];
        foreach (self::knownIndexes() as $index) {
            if (!ridesync_table_exists($conn, $index['table'])) {
                $skipped++;
                continue;
            }
            if (self::indexExists($conn, $index['table'], $index['index'])) {
                $skipped++;
                continue;
            }
            $sql = "ALTER TABLE `{$index['table']}` ADD {$index['definition']}";
            try {
                if (@mysqli_query($conn, $sql)) {
                    $added++;
                } else {
                    $failed[] = $index['index'] . ': ' . mysqli_error($conn);
                    $handoffIndexes[] = $index;
                }
            } catch (Throwable $exception) {
                $failed[] = $index['index'] . ': ' . $exception->getMessage();
                $handoffIndexes[] = $index;
            }
        }

        $handoff = null;
        if (!empty($handoffIndexes)) {
            $handoff = self::queueOpsHandoff($conn, 'repair.ops.schema_indexes', $adminId, [
                'indexes' => array_values(array_map(static function ($index) {
                    return [
                        'table' => $index['table'],
                        'index' => $index['index'],
                        'definition' => $index['definition'],
                    ];
                }, $handoffIndexes)),
            ]);
        }

        return [
            'indexes_added' => $added,
            'indexes_skipped' => $skipped,
            'failures' => array_slice($failed, 0, 8),
            'ops_handoff' => $handoff,
        ];
    }

    private static function optimizeTables($conn): array
    {
        $tables = array_filter(['background_jobs', 'audit_logs', 'realtime_events', 'notifications', 'driver_verification_sessions'], static function ($table) use ($conn) {
            return ridesync_table_exists($conn, $table);
        });
        $optimized = [];
        foreach ($tables as $table) {
            if (@mysqli_query($conn, "OPTIMIZE TABLE `{$table}`")) {
                $optimized[] = $table;
            }
        }

        return [
            'tables_requested' => count($tables),
            'tables_optimized' => $optimized,
        ];
    }

    private static function rotateLogs(): array
    {
        $logDir = ridesync_storage_path('logs');
        ridesync_ensure_directory($logDir);
        $rotated = 0;
        $pruned = 0;
        $now = date('YmdHis');
        foreach (glob($logDir . DIRECTORY_SEPARATOR . 'app-*.log') ?: [] as $file) {
            if (is_file($file) && filesize($file) > 5 * 1024 * 1024) {
                @rename($file, $file . '.' . $now . '.rotated');
                $rotated++;
            }
        }
        foreach (glob($logDir . DIRECTORY_SEPARATOR . '*.rotated') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - (21 * 86400)) {
                @unlink($file);
                $pruned++;
            }
        }

        return [
            'logs_rotated' => $rotated,
            'old_rotated_logs_pruned' => $pruned,
        ];
    }

    private static function storageCleanup(): array
    {
        $created = [];
        foreach (['', 'logs', 'cache', 'rate_limits', 'backups', 'tmp'] as $relative) {
            $path = ridesync_storage_path($relative);
            if (ridesync_ensure_directory($path)) {
                $created[] = $relative === '' ? 'storage' : $relative;
            }
        }

        $cachePruned = RideSyncCacheService::pruneFiles(5000);
        $tmpPruned = 0;
        foreach (glob(ridesync_storage_path('tmp') . DIRECTORY_SEPARATOR . '*') ?: [] as $file) {
            if (is_file($file) && filemtime($file) < time() - 86400) {
                @unlink($file);
                $tmpPruned++;
            }
        }

        return [
            'directories_verified' => $created,
            'expired_cache_files_pruned' => $cachePruned,
            'stale_tmp_files_pruned' => $tmpPruned,
        ];
    }

    private static function setMaintenanceMode($conn, int $adminId): array
    {
        if (!ridesync_table_exists($conn, 'feature_flags')) {
            return ['changed_flags' => 0, 'schema_ready' => false];
        }
        $stmt = mysqli_prepare($conn, "UPDATE feature_flags SET maintenance_mode = 1, enabled = 0, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE module IN ('rides', 'drivers', 'ai', 'trust', 'payments', 'realtime')");
        if (!$stmt) {
            return ['changed_flags' => 0, 'schema_ready' => true, 'error' => mysqli_error($conn)];
        }
        mysqli_stmt_bind_param($stmt, 'i', $adminId);
        mysqli_stmt_execute($stmt);
        return ['changed_flags' => max(0, mysqli_stmt_affected_rows($stmt)), 'schema_ready' => true];
    }

    private static function aiKillSwitch($conn, int $adminId): array
    {
        if (!ridesync_table_exists($conn, 'feature_flags')) {
            return ['changed_flags' => 0, 'schema_ready' => false];
        }
        $stmt = mysqli_prepare($conn, "UPDATE feature_flags SET maintenance_mode = 1, enabled = 0, updated_by = ?, updated_at = CURRENT_TIMESTAMP WHERE flag_key = 'ai_verification'");
        if (!$stmt) {
            return ['changed_flags' => 0, 'schema_ready' => true, 'error' => mysqli_error($conn)];
        }
        mysqli_stmt_bind_param($stmt, 'i', $adminId);
        mysqli_stmt_execute($stmt);
        RideSyncServiceObservabilityService::clearSnapshotCache();
        return ['changed_flags' => max(0, mysqli_stmt_affected_rows($stmt)), 'schema_ready' => true];
    }

    private static function platformRecovery($conn, int $adminId): array
    {
        return [
            'storage' => self::storageCleanup(),
            'cache' => self::repairCache(),
            'queues' => self::repairQueues($conn),
            'ai' => self::repairAi($conn),
            'logs' => self::rotateLogs(),
            'admin_id' => $adminId,
        ];
    }

    private static function queueOpsHandoff($conn, string $jobType, int $adminId, array $payload = []): array
    {
        $jobId = RideSyncQueueService::enqueue($conn, $jobType, array_merge([
            'admin_id' => $adminId,
            'requested_at' => date('c'),
            'source' => 'admin_repair_kit',
        ], $payload), [
            'queue_name' => 'ops',
            'max_attempts' => 1,
        ]);

        return [
            'job_id' => $jobId,
            'ops_worker_required' => true,
            'message' => $jobId ? 'Ops handoff job queued.' : 'Background job schema is unavailable; no host-level action was executed.',
        ];
    }

    private static function checkpoint($conn): array
    {
        return [
            'created_at' => date('c'),
            'tables' => [
                'users' => self::safeCount($conn, 'users'),
                'drivers' => self::safeCount($conn, 'driver_accounts'),
                'rides' => self::safeCount($conn, 'rides'),
                'jobs_failed' => self::safeWhereCount($conn, 'background_jobs', "status = 'failed'"),
                'jobs_processing' => self::safeWhereCount($conn, 'background_jobs', "status = 'processing'"),
                'feature_flags_maintenance' => self::safeWhereCount($conn, 'feature_flags', 'maintenance_mode = 1'),
            ],
            'env' => [
                'app_env' => ridesync_app_env(),
                'debug' => ridesync_is_debug(),
                'cookie_secure' => ridesync_env_bool('RIDESYNC_COOKIE_SECURE', false),
            ],
        ];
    }

    private static function recordRun($conn, int $adminId, string $operation, string $status, string $message, array $checkpoint, array $details): ?int
    {
        if (!self::schemaReady($conn)) {
            return null;
        }

        $runUuid = self::uuid();
        $checkpointJson = self::encodeJson($checkpoint);
        $resultJson = self::encodeJson(['message' => $message, 'details' => $details]);
        $encrypted = self::encryptLog([
            'operation' => $operation,
            'status' => $status,
            'message' => $message,
            'checkpoint' => $checkpoint,
            'details' => $details,
            'recorded_at' => date('c'),
        ]);
        $hash = hash('sha256', $encrypted['plaintext']);

        $stmt = mysqli_prepare(
            $conn,
            "INSERT INTO repair_kit_runs
                (run_uuid, admin_id, action_key, status, severity, checkpoint_json, result_json, log_ciphertext, log_iv, log_tag, log_hash, started_at, finished_at)
             VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, CURRENT_TIMESTAMP, ?)"
        );
        if (!$stmt) {
            return null;
        }

        $severity = self::operationSeverity($operation);
        $finishedAt = in_array($status, ['succeeded', 'failed', 'blocked'], true) ? date('Y-m-d H:i:s') : null;
        $adminIdParam = $adminId > 0 ? $adminId : null;
        mysqli_stmt_bind_param(
            $stmt,
            'sissssssssss',
            $runUuid,
            $adminIdParam,
            $operation,
            $status,
            $severity,
            $checkpointJson,
            $resultJson,
            $encrypted['ciphertext'],
            $encrypted['iv'],
            $encrypted['tag'],
            $hash,
            $finishedAt
        );
        if (!mysqli_stmt_execute($stmt)) {
            return null;
        }

        return (int) mysqli_insert_id($conn);
    }

    private static function finishRun($conn, ?int $runId, string $status, string $message, array $details): void
    {
        if (!$runId || !self::schemaReady($conn)) {
            return;
        }

        $resultJson = self::encodeJson(['message' => $message, 'details' => $details]);
        $encrypted = self::encryptLog([
            'status' => $status,
            'message' => $message,
            'details' => $details,
            'finished_at' => date('c'),
        ]);
        $hash = hash('sha256', $encrypted['plaintext']);
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE repair_kit_runs
             SET status = ?, result_json = ?, log_ciphertext = ?, log_iv = ?, log_tag = ?, log_hash = ?, finished_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        if (!$stmt) {
            return;
        }
        mysqli_stmt_bind_param($stmt, 'ssssssi', $status, $resultJson, $encrypted['ciphertext'], $encrypted['iv'], $encrypted['tag'], $hash, $runId);
        mysqli_stmt_execute($stmt);
    }

    private static function encryptLog(array $payload): array
    {
        $plaintext = self::encodeJson($payload);
        $key = self::repairLogKey();
        if (!$key || !function_exists('openssl_encrypt')) {
            $redacted = self::encodeJson([
                'redacted' => true,
                'message' => 'Repair log payload was not stored because encryption was unavailable.',
                'payload_hash' => hash('sha256', $plaintext),
                'recorded_at' => date('c'),
            ]);
            return [
                'ciphertext' => base64_encode($redacted),
                'iv' => 'encryption-unavailable',
                'tag' => 'redacted',
                'plaintext' => $plaintext,
            ];
        }

        $iv = random_bytes(12);
        $tag = '';
        $ciphertext = openssl_encrypt($plaintext, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($ciphertext === false) {
            $redacted = self::encodeJson([
                'redacted' => true,
                'message' => 'Repair log payload was not stored because encryption failed.',
                'payload_hash' => hash('sha256', $plaintext),
                'recorded_at' => date('c'),
            ]);
            return [
                'ciphertext' => base64_encode($redacted),
                'iv' => 'encryption-failed',
                'tag' => 'redacted',
                'plaintext' => $plaintext,
            ];
        }

        return [
            'ciphertext' => base64_encode($ciphertext),
            'iv' => base64_encode($iv),
            'tag' => base64_encode($tag),
            'plaintext' => $plaintext,
        ];
    }

    private static function repairLogKey()
    {
        foreach (['RIDESYNC_REPAIR_LOG_KEY', 'RIDESYNC_DOCUMENT_ENCRYPTION_KEY'] as $keyName) {
            $decoded = self::decodedEnvKey($keyName);
            if ($decoded !== null) {
                return $decoded;
            }
        }

        return null;
    }

    private static function decodedEnvKey(string $keyName): ?string
    {
        $value = (string) ridesync_env($keyName, '');
        $decoded = base64_decode($value, true);
        if ($decoded === false || strlen($decoded) < 32) {
            return null;
        }

        return substr($decoded, 0, 32);
    }

    private static function knownIndexes(): array
    {
        return [
            ['table' => 'background_jobs', 'index' => 'idx_background_jobs_ready', 'definition' => 'KEY idx_background_jobs_ready (queue_name, status, available_at, id)'],
            ['table' => 'background_jobs', 'index' => 'idx_background_jobs_type_status', 'definition' => 'KEY idx_background_jobs_type_status (job_type, status, created_at)'],
            ['table' => 'background_jobs', 'index' => 'idx_background_jobs_locked', 'definition' => 'KEY idx_background_jobs_locked (locked_at)'],
            ['table' => 'audit_logs', 'index' => 'idx_audit_source_time', 'definition' => 'KEY idx_audit_source_time (source_ip, created_at)'],
            ['table' => 'notifications', 'index' => 'idx_notifications_user_created', 'definition' => 'KEY idx_notifications_user_created (user_id, created_at)'],
            ['table' => 'notifications', 'index' => 'idx_notifications_driver_created', 'definition' => 'KEY idx_notifications_driver_created (driver_id, created_at)'],
            ['table' => 'driver_verification_sessions', 'index' => 'idx_verification_sessions_status', 'definition' => 'KEY idx_verification_sessions_status (status, updated_at)'],
            ['table' => 'repair_kit_runs', 'index' => 'idx_repair_kit_runs_status_time', 'definition' => 'KEY idx_repair_kit_runs_status_time (status, created_at)'],
        ];
    }

    private static function orphanChecks(): array
    {
        return [
            ['child' => 'rides', 'column' => 'user_id', 'parent' => 'users'],
            ['child' => 'matches', 'column' => 'ride_id', 'parent' => 'rides'],
            ['child' => 'driver_ride_requests', 'column' => 'driver_id', 'parent' => 'driver_accounts'],
            ['child' => 'driver_ride_requests', 'column' => 'rider_user_id', 'parent' => 'users'],
            ['child' => 'reports', 'column' => 'reporter_user_id', 'parent' => 'users'],
        ];
    }

    private static function finding(string $severity, string $title, string $detail, string $area, string $actionKey): array
    {
        return [
            'severity' => in_array($severity, ['critical', 'warning', 'info'], true) ? $severity : 'info',
            'title' => substr($title, 0, 160),
            'detail' => substr($detail, 0, 280),
            'area' => substr($area, 0, 80),
            'action_key' => $actionKey,
            'detected_at' => date('c'),
        ];
    }

    private static function sortFindings(array &$findings): void
    {
        $rank = ['critical' => 3, 'warning' => 2, 'info' => 1];
        usort($findings, static function ($a, $b) use ($rank) {
            $severity = ($rank[$b['severity']] ?? 0) <=> ($rank[$a['severity']] ?? 0);
            return $severity !== 0 ? $severity : strcmp((string) $a['title'], (string) $b['title']);
        });
    }

    private static function result(bool $ok, string $message, array $details): array
    {
        return [
            'ok' => $ok,
            'message' => $message,
            'details' => $details,
        ];
    }

    private static function runtimeMetrics(): array
    {
        $storage = ridesync_storage_path();
        $free = @disk_free_space($storage);
        $total = @disk_total_space($storage);
        return [
            'php_memory_mb' => round(memory_get_usage(true) / 1024 / 1024, 2),
            'php_peak_memory_mb' => round(memory_get_peak_usage(true) / 1024 / 1024, 2),
            'disk_free_percent' => is_numeric($free) && is_numeric($total) && $total > 0 ? round(($free / $total) * 100, 2) : null,
            'server_software' => $_SERVER['SERVER_SOFTWARE'] ?? PHP_SAPI,
        ];
    }

    private static function operationSeverity(string $operation): string
    {
        $operationDefinition = self::operations()[$operation] ?? [];
        return in_array(($operationDefinition['severity'] ?? ''), ['info', 'warning', 'critical'], true)
            ? $operationDefinition['severity']
            : 'info';
    }

    private static function safeCount($conn, string $table): int
    {
        return ridesync_table_exists($conn, $table) ? self::scalar($conn, "SELECT COUNT(*) FROM `{$table}`") : 0;
    }

    private static function safeWhereCount($conn, string $table, string $where): int
    {
        return ridesync_table_exists($conn, $table) ? self::scalar($conn, "SELECT COUNT(*) FROM `{$table}` WHERE {$where}") : 0;
    }

    private static function scalar($conn, string $sql): int
    {
        if (!$conn instanceof mysqli) {
            return 0;
        }
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            return 0;
        }
        $row = mysqli_fetch_row($result);
        return (int) ($row[0] ?? 0);
    }

    private static function indexExists($conn, string $table, string $index): bool
    {
        if (!$conn instanceof mysqli) {
            return false;
        }
        $stmt = mysqli_prepare($conn, "SELECT 1 FROM information_schema.STATISTICS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND INDEX_NAME = ? LIMIT 1");
        if (!$stmt) {
            return false;
        }
        mysqli_stmt_bind_param($stmt, 'ss', $table, $index);
        mysqli_stmt_execute($stmt);
        return mysqli_num_rows(mysqli_stmt_get_result($stmt)) > 0;
    }

    private static function invalidJobPayloadCount($conn): int
    {
        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'background_jobs')) {
            return 0;
        }
        $result = mysqli_query($conn, "SELECT payload_json FROM background_jobs ORDER BY id DESC LIMIT 500");
        $invalid = 0;
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            json_decode((string) ($row['payload_json'] ?? ''), true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $invalid++;
            }
        }
        return $invalid;
    }

    private static function encodeJson(array $payload): string
    {
        return json_encode($payload, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    }

    private static function decodeJson(string $payload): array
    {
        $decoded = json_decode($payload, true);
        return is_array($decoded) ? $decoded : [];
    }

    private static function uuid(): string
    {
        $bytes = random_bytes(16);
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        return vsprintf('%s%s-%s-%s-%s-%s%s%s', str_split(bin2hex($bytes), 4));
    }
}

?>
