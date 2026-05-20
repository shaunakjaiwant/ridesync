<?php
require_once __DIR__ . '/CacheService.php';
require_once __DIR__ . '/QueueService.php';
require_once __DIR__ . '/../matching_helper.php';
require_once __DIR__ . '/../location_suggestions.php';
require_once __DIR__ . '/../logger.php';

class RideSyncServiceObservabilityService
{
    private const SNAPSHOT_CACHE_KEY = 'admin:services:snapshot:v2';
    private const SNAPSHOT_FAST_CACHE_KEY = 'admin:services:snapshot-fast:v1';
    private const SNAPSHOT_TTL_SECONDS = 30;

    public static function snapshot($conn, array $options = []): array
    {
        $force = (bool) ($options['force'] ?? false);
        $probeExternal = (bool) ($options['probe_external'] ?? true);
        $cacheKey = $probeExternal ? self::SNAPSHOT_CACHE_KEY : self::SNAPSHOT_FAST_CACHE_KEY;

        if (!$force && !$probeExternal) {
            $fullSnapshot = RideSyncCacheService::get(self::SNAPSHOT_CACHE_KEY);
            if ($fullSnapshot['hit'] && is_array($fullSnapshot['value'])) {
                return $fullSnapshot['value'];
            }
        }

        if (!$force) {
            return RideSyncCacheService::remember($cacheKey, self::SNAPSHOT_TTL_SECONDS, static function () use ($conn, $probeExternal) {
                return self::buildSnapshot($conn, $probeExternal);
            });
        }

        return self::buildSnapshot($conn, $probeExternal);
    }

    public static function clearSnapshotCache(): void
    {
        RideSyncCacheService::delete(self::SNAPSHOT_CACHE_KEY);
        RideSyncCacheService::delete(self::SNAPSHOT_FAST_CACHE_KEY);
    }

    public static function releaseTimedOutJobs($conn, int $staleSeconds = 600): int
    {
        $released = RideSyncQueueService::releaseTimedOut($conn, $staleSeconds);
        self::clearSnapshotCache();
        return $released;
    }

    public static function retryFailedVerificationJobs($conn, int $limit = 25): array
    {
        $summary = [
            'jobs_requeued' => 0,
            'sessions_requeued' => 0,
            'jobs_created' => 0,
        ];

        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'background_jobs')) {
            return $summary;
        }

        $limit = max(1, min(100, $limit));
        $rows = [];
        $stmt = mysqli_prepare(
            $conn,
            "SELECT id, payload_json
             FROM background_jobs
             WHERE queue_name = 'verification'
               AND job_type = 'verification.process'
               AND status = 'failed'
             ORDER BY updated_at DESC, id DESC
             LIMIT ?"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $limit);
            mysqli_stmt_execute($stmt);
            $result = mysqli_stmt_get_result($stmt);
            while ($result && ($row = mysqli_fetch_assoc($result))) {
                $rows[] = $row;
            }
        }

        foreach ($rows as $row) {
            $jobId = (int) $row['id'];
            $payload = json_decode((string) $row['payload_json'], true);
            $sessionId = is_array($payload) ? (int) ($payload['session_id'] ?? 0) : 0;

            $update = mysqli_prepare(
                $conn,
                "UPDATE background_jobs
                 SET status = 'queued',
                     attempts = 0,
                     available_at = CURRENT_TIMESTAMP,
                     locked_at = NULL,
                     locked_by = NULL,
                     last_error = NULL,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = ? AND status = 'failed'"
            );
            if ($update) {
                mysqli_stmt_bind_param($update, 'i', $jobId);
                mysqli_stmt_execute($update);
                $summary['jobs_requeued'] += max(0, mysqli_stmt_affected_rows($update));
            }

            if ($sessionId > 0 && ridesync_table_exists($conn, 'driver_verification_sessions')) {
                $sessionUpdate = mysqli_prepare(
                    $conn,
                    "UPDATE driver_verification_sessions
                     SET status = 'queued',
                         progress_stage = 'queued',
                         completed_at = NULL,
                         updated_at = CURRENT_TIMESTAMP
                     WHERE id = ? AND status = 'failed'"
                );
                if ($sessionUpdate) {
                    mysqli_stmt_bind_param($sessionUpdate, 'i', $sessionId);
                    mysqli_stmt_execute($sessionUpdate);
                    $summary['sessions_requeued'] += max(0, mysqli_stmt_affected_rows($sessionUpdate));
                }
            }
        }

        $remainingCapacity = $limit - (int) $summary['jobs_requeued'];
        if ($remainingCapacity > 0
            && ridesync_table_exists($conn, 'driver_verification_sessions')
            && class_exists('RideSyncQueueService')
        ) {
            $failedSessions = self::failedVerificationSessionsWithoutActiveJob($conn, $remainingCapacity);
            foreach ($failedSessions as $session) {
                $jobId = RideSyncQueueService::enqueue($conn, 'verification.process', [
                    'session_id' => (int) $session['id'],
                    'driver_id' => (int) $session['driver_id'],
                    'source' => 'admin_services_retry',
                ], [
                    'queue_name' => 'verification',
                    'max_attempts' => 3,
                ]);

                if ($jobId !== null) {
                    $summary['jobs_created']++;
                    $sessionUpdate = mysqli_prepare(
                        $conn,
                        "UPDATE driver_verification_sessions
                         SET status = 'queued',
                             progress_stage = 'queued',
                             completed_at = NULL,
                             updated_at = CURRENT_TIMESTAMP
                         WHERE id = ?"
                    );
                    if ($sessionUpdate) {
                        $sessionId = (int) $session['id'];
                        mysqli_stmt_bind_param($sessionUpdate, 'i', $sessionId);
                        mysqli_stmt_execute($sessionUpdate);
                        $summary['sessions_requeued'] += max(0, mysqli_stmt_affected_rows($sessionUpdate));
                    }
                }
            }
        }

        self::clearSnapshotCache();
        return $summary;
    }

    public static function toggleAlertRule($conn, int $ruleId, bool $enabled): bool
    {
        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'admin_alert_rules')) {
            return false;
        }

        $enabledValue = $enabled ? 1 : 0;
        $stmt = mysqli_prepare(
            $conn,
            "UPDATE admin_alert_rules
             SET enabled = ?, updated_at = CURRENT_TIMESTAMP
             WHERE id = ?"
        );
        if (!$stmt) {
            return false;
        }

        mysqli_stmt_bind_param($stmt, 'ii', $enabledValue, $ruleId);
        $ok = mysqli_stmt_execute($stmt);
        self::clearSnapshotCache();
        return $ok && mysqli_stmt_affected_rows($stmt) >= 0;
    }

    private static function buildSnapshot($conn, bool $probeExternal = true): array
    {
        $services = [
            self::databaseService($conn),
            self::storageService(),
            self::aiVerificationService($probeExternal),
            self::kycProviderService(),
            self::websocketService($probeExternal),
            self::locationService($probeExternal),
            self::routePlannerService($probeExternal),
            self::queueService($conn),
            self::metricsService(),
        ];

        $workflows = self::aiWorkflowMetrics($conn);
        $apiChecks = self::providerApiMetrics($conn);
        $queue = self::queueMetrics($conn);
        $logs = self::recentLogFindings();
        $alerts = self::alerts($services, $workflows, $queue, $logs);
        $summary = self::summary($services, $workflows, $apiChecks, $queue, $logs, $alerts);
        $alertRules = self::alertRules($conn, $services, $workflows, $apiChecks, $queue, $logs, $summary);
        $alerts = array_slice(array_merge($alerts, self::alertsFromRules($alertRules)), 0, 18);
        $summary = self::summary($services, $workflows, $apiChecks, $queue, $logs, $alerts);
        $incidents = self::incidents($conn, $alerts, $workflows, $apiChecks, $queue, $logs);

        return [
            'ok' => $summary['status'] === 'operational',
            'status' => $summary['status'],
            'generated_at' => date('c'),
            'summary' => $summary,
            'services' => $services,
            'workflows' => $workflows,
            'api_checks' => $apiChecks,
            'queues' => $queue,
            'logs' => $logs,
            'alerts' => $alerts,
            'incidents' => $incidents,
            'alert_rules' => $alertRules,
        ];
    }

    private static function databaseService($conn): array
    {
        $started = microtime(true);
        $up = $conn instanceof mysqli && @mysqli_ping($conn);
        $latency = self::elapsedMs($started);

        return self::service(
            'database',
            'MySQL Database',
            'Core',
            $up ? self::latencyStatus($latency, 250, 900) : 'down',
            $up ? 'Database connection is responsive.' : 'Database connection is unavailable.',
            $latency,
            [
                'ping_ms' => $latency,
                'schema_ready' => $up && self::requiredTablesReady($conn),
            ],
            [
                'host' => self::redactedHost((string) ridesync_env('RIDESYNC_DB_HOST', 'localhost')),
                'database' => (string) ridesync_env('RIDESYNC_DB_NAME', 'ridesync'),
            ]
        );
    }

    private static function storageService(): array
    {
        $storage = ridesync_storage_path();
        $logs = ridesync_storage_path('logs');
        $cache = ridesync_storage_path('cache');
        $rateLimits = ridesync_storage_path('rate_limits');
        $checks = [
            'storage_writable' => is_dir($storage) && is_writable($storage),
            'logs_writable' => ridesync_ensure_directory($logs) && is_writable($logs),
            'cache_writable' => ridesync_ensure_directory($cache) && is_writable($cache),
            'rate_limits_writable' => ridesync_ensure_directory($rateLimits) && is_writable($rateLimits),
        ];

        $ok = !in_array(false, $checks, true);

        return self::service(
            'storage',
            'Runtime Storage',
            'Core',
            $ok ? 'operational' : 'degraded',
            $ok ? 'Storage, logs, cache, and rate-limit directories are writable.' : 'One or more runtime directories are not writable.',
            null,
            $checks,
            [
                'storage_dir' => $storage,
            ]
        );
    }

    private static function aiVerificationService(bool $probeExternal = true): array
    {
        $serviceUrl = rtrim((string) ridesync_env('RIDESYNC_VERIFICATION_SERVICE_URL', ''), '/');
        $tokenConfigured = ridesync_env_secret_is_configured('RIDESYNC_VERIFICATION_SERVICE_TOKEN', 32);
        if ($serviceUrl === '') {
            $status = ridesync_app_env() === 'production' ? 'degraded' : 'disabled';
            return self::service(
                'ai_verification',
                'AI Verification Service',
                'AI',
                $status,
                'RIDESYNC_VERIFICATION_SERVICE_URL is not configured.',
                null,
                [
                    'service_token_configured' => $tokenConfigured,
                    'requests_24h' => 0,
                ],
                [
                    'endpoint' => 'not configured',
                ]
            );
        }

        if (!$probeExternal) {
            return self::service(
                'ai_verification',
                'AI Verification Service',
                'AI',
                'unknown',
                'External ready probe is deferred until the background services refresh runs.',
                null,
                [
                    'service_token_configured' => $tokenConfigured,
                    'requests_24h' => 0,
                ],
                [
                    'endpoint' => self::safeUrl($serviceUrl),
                ]
            );
        }

        $probe = self::httpProbe($serviceUrl . '/readyz', [
            'Accept: application/json',
        ], 1.2);
        $status = self::statusFromProbe($probe, [200]);
        if ($status === 'operational' && !$tokenConfigured && ridesync_app_env() === 'production') {
            $status = 'degraded';
        }

        $ready = is_array($probe['json'] ?? null) ? $probe['json'] : [];
        $limits = $ready['checks']['limits'] ?? [];

        return self::service(
            'ai_verification',
            'AI Verification Service',
            'AI',
            $status,
            $probe['ok'] ? 'Ready endpoint responded.' : ($probe['error'] ?: 'Ready endpoint did not return success.'),
            $probe['latency_ms'],
            [
                'http_status' => $probe['status_code'],
                'service_token_configured' => $tokenConfigured,
                'provider' => $ready['checks']['provider'] ?? 'unknown',
                'mock_provider' => (bool) ($ready['checks']['mock_provider'] ?? false),
                'max_request_bytes' => $limits['max_request_bytes'] ?? null,
                'max_documents' => $limits['max_documents'] ?? null,
            ],
            [
                'endpoint' => self::safeUrl($serviceUrl),
                'request_id' => $probe['headers']['x-request-id'] ?? null,
            ]
        );
    }

    private static function kycProviderService(): array
    {
        $provider = trim((string) ridesync_env('RIDESYNC_KYC_PROVIDER', 'mock_compliance_provider'));
        $url = trim((string) ridesync_env('RIDESYNC_KYC_PROVIDER_URL', ''));
        $tokenReady = ridesync_env_secret_is_configured('RIDESYNC_KYC_PROVIDER_TOKEN', 12);
        $isMock = $provider === '' || in_array($provider, ['mock', 'mock_compliance_provider'], true);

        if ($isMock) {
            $status = ridesync_app_env() === 'production' ? 'degraded' : 'operational';
            return self::service(
                'kyc_provider',
                'External KYC Provider',
                'AI',
                $status,
                $status === 'operational' ? 'Mock compliance provider is active.' : 'Production is using the mock compliance provider.',
                null,
                [
                    'provider' => $provider ?: 'mock_compliance_provider',
                    'endpoint_configured' => false,
                    'token_configured' => false,
                    'contract_validation_required' => ridesync_app_env() === 'production',
                ],
                [
                    'endpoint' => 'not configured',
                ]
            );
        }

        $validScheme = preg_match('/^https:\/\//i', $url) === 1;
        $status = $url !== '' && $validScheme ? 'unknown' : 'degraded';

        return self::service(
            'kyc_provider',
            'External KYC Provider',
            'AI',
            $status,
            $status === 'unknown'
                ? 'Provider is configured. Run the sandbox contract validator before production cutover.'
                : 'Provider URL is missing or not HTTPS.',
            null,
            [
                'provider' => $provider,
                'endpoint_configured' => $url !== '',
                'https_endpoint' => $validScheme,
                'token_configured' => $tokenReady,
                'contract_validation_required' => true,
            ],
            [
                'endpoint' => self::safeUrl($url),
            ]
        );
    }

    private static function websocketService(bool $probeExternal = true): array
    {
        $wsUrl = trim((string) ridesync_env('RIDESYNC_WEBSOCKET_URL', ''));
        $tokenReady = ridesync_env_secret_is_configured('RIDESYNC_WS_SHARED_TOKEN', 32);
        $healthUrl = self::websocketHealthUrl($wsUrl);

        if ($healthUrl === '') {
            $status = ridesync_app_env() === 'production' ? 'degraded' : 'disabled';
            return self::service(
                'websocket_gateway',
                'Realtime WebSocket Gateway',
                'Realtime',
                $status,
                'RIDESYNC_WEBSOCKET_URL is not configured.',
                null,
                [
                    'shared_token_configured' => $tokenReady,
                ],
                [
                    'endpoint' => 'not configured',
                ]
            );
        }

        if (!$probeExternal) {
            return self::service(
                'websocket_gateway',
                'Realtime WebSocket Gateway',
                'Realtime',
                'unknown',
                'Gateway health probe is deferred until the background services refresh runs.',
                null,
                [
                    'shared_token_configured' => $tokenReady,
                ],
                [
                    'endpoint' => self::safeUrl($healthUrl),
                ]
            );
        }

        $probe = self::httpProbe($healthUrl, ['Accept: application/json'], 1.0);
        $status = self::statusFromProbe($probe, [200]);
        if ($status === 'operational' && !$tokenReady && ridesync_app_env() === 'production') {
            $status = 'degraded';
        }

        return self::service(
            'websocket_gateway',
            'Realtime WebSocket Gateway',
            'Realtime',
            $status,
            $probe['ok'] ? 'Gateway health endpoint responded.' : ($probe['error'] ?: 'Gateway health endpoint did not return success.'),
            $probe['latency_ms'],
            [
                'http_status' => $probe['status_code'],
                'shared_token_configured' => $tokenReady,
            ],
            [
                'endpoint' => self::safeUrl($healthUrl),
            ]
        );
    }

    private static function locationService(bool $probeExternal = true): array
    {
        $baseUrl = rtrim((string) ridesync_env('RIDESYNC_LOCATION_PROVIDER_URL', 'https://nominatim.openstreetmap.org/search'), '?');
        if (!$probeExternal) {
            $localSuggestions = ridesync_location_local_suggestions('SDMIT', 3);
            return self::service(
                'location_suggestions',
                'Location Suggestions API',
                'External API',
                count($localSuggestions) > 0 ? 'unknown' : 'degraded',
                'Provider health probe is deferred; local fallback directory is available for navigation-safe rendering.',
                null,
                [
                    'provider_http_status' => null,
                    'provider_suggestions_seen' => null,
                    'local_fallback_suggestions_seen' => count($localSuggestions),
                    'provider_url' => self::safeUrl($baseUrl),
                    'timeout_seconds' => 1,
                ],
                [
                    'sample_query' => 'SDMIT',
                ]
            );
        }

        $url = $baseUrl . '?format=jsonv2&addressdetails=1&limit=3&countrycodes=in&q=' . rawurlencode('SDMIT Karnataka India');
        $probe = self::httpProbe($url, ['Accept: application/json'], 1.0);
        $localSuggestions = ridesync_location_local_suggestions('SDMIT', 3);
        $providerSuggestions = is_array($probe['json'] ?? null) ? $probe['json'] : [];
        $providerOk = $probe['ok'] && count($providerSuggestions) > 0;
        $localOk = count($localSuggestions) > 0;
        $ok = $localOk && $providerOk;

        return self::service(
            'location_suggestions',
            'Location Suggestions API',
            'External API',
            $ok ? self::latencyStatus((float) ($probe['latency_ms'] ?? 0), 750, 2500) : self::statusFromProbe($probe, [200]),
            $ok ? 'Location provider and local fallback returned usable places.' : ($probe['error'] ?: 'Location provider response was not usable.'),
            $probe['latency_ms'],
            [
                'provider_http_status' => $probe['status_code'],
                'provider_suggestions_seen' => count($providerSuggestions),
                'local_fallback_suggestions_seen' => count($localSuggestions),
                'provider_url' => self::safeUrl($baseUrl),
                'timeout_seconds' => 1,
            ],
            [
                'sample_query' => 'SDMIT',
            ]
        );
    }

    private static function routePlannerService(bool $probeExternal = true): array
    {
        $baseUrl = rtrim((string) ridesync_env('RIDESYNC_ROUTING_PROVIDER_URL', 'https://router.project-osrm.org/route/v1/driving'), '/');
        if (!$probeExternal) {
            return self::service(
                'route_planner',
                'Route Planner API',
                'External API',
                'unknown',
                'Route provider health probe is deferred until the background services refresh runs.',
                null,
                [
                    'http_status' => null,
                    'provider_code' => null,
                ],
                [
                    'endpoint' => self::safeUrl($baseUrl),
                    'sample_route' => 'Mangaluru to Ujire',
                ]
            );
        }

        $url = $baseUrl . '/74.8428,12.8698;75.2376,13.3420?overview=false';
        $probe = self::httpProbe($url, ['Accept: application/json'], 1.5);
        $json = is_array($probe['json'] ?? null) ? $probe['json'] : [];
        $ok = $probe['ok'] && (($json['code'] ?? '') === 'Ok' || empty($json));
        $status = $ok ? self::latencyStatus((float) ($probe['latency_ms'] ?? 0), 1200, 4000) : self::statusFromProbe($probe, [200]);
        if ($probe['ok'] && !$ok) {
            $status = 'degraded';
        }

        return self::service(
            'route_planner',
            'Route Planner API',
            'External API',
            $status,
            $ok ? 'Route planner returned a usable route response.' : ($probe['error'] ?: 'Route planner response was not usable.'),
            $probe['latency_ms'],
            [
                'http_status' => $probe['status_code'],
                'provider_code' => $json['code'] ?? null,
            ],
            [
                'endpoint' => self::safeUrl($baseUrl),
                'sample_route' => 'Mangaluru to Ujire',
            ]
        );
    }

    private static function queueService($conn): array
    {
        $metrics = self::queueMetrics($conn);
        if (!$metrics['schema_ready']) {
            return self::service(
                'background_jobs',
                'Background Job Queues',
                'Workers',
                'down',
                'background_jobs table is missing.',
                null,
                $metrics,
                []
            );
        }

        $status = 'operational';
        if ((int) $metrics['stale_processing'] > 0 || (int) $metrics['failed'] > 0) {
            $status = 'degraded';
        }
        if ((int) $metrics['queued_verification'] > 25) {
            $status = 'degraded';
        }

        return self::service(
            'background_jobs',
            'Background Job Queues',
            'Workers',
            $status,
            $status === 'operational' ? 'Queues are within expected bounds.' : 'Failed, stale, or backed-up jobs need attention.',
            null,
            $metrics,
            []
        );
    }

    private static function metricsService(): array
    {
        $tokenReady = ridesync_env_secret_is_configured('RIDESYNC_METRICS_TOKEN', 32);
        $status = $tokenReady ? 'operational' : 'degraded';

        return self::service(
            'metrics',
            'Prometheus Metrics Endpoint',
            'Operations',
            $status,
            $tokenReady ? 'Metrics endpoint is protected by a configured bearer token.' : 'Metrics token is missing or not strong enough.',
            null,
            [
                'token_configured' => $tokenReady,
                'endpoint' => '/ridesync/api/metrics.php',
            ],
            []
        );
    }

    private static function aiWorkflowMetrics($conn): array
    {
        $empty = [
            'schema_ready' => false,
            'requests_24h' => 0,
            'responses_24h' => 0,
            'queued' => 0,
            'processing' => 0,
            'failed_24h' => 0,
            'completed_24h' => 0,
            'slow_processing' => 0,
            'avg_processing_ms' => 0,
            'max_processing_ms' => 0,
            'token_usage_24h' => 0,
            'token_meter' => 'not_applicable',
            'statuses' => [],
        ];

        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'driver_verification_sessions')) {
            return $empty;
        }

        $metrics = $empty;
        $metrics['schema_ready'] = true;
        $metrics['requests_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['responses_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE service_response_json IS NOT NULL AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['queued'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE status = 'queued'");
        $metrics['processing'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE status = 'processing'");
        $metrics['failed_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['completed_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['slow_processing'] = self::scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE status = 'processing' AND started_at < DATE_SUB(NOW(), INTERVAL 5 MINUTE)");

        $duration = self::row($conn, "SELECT AVG(TIMESTAMPDIFF(MICROSECOND, started_at, completed_at) / 1000) AS avg_ms,
                                            MAX(TIMESTAMPDIFF(MICROSECOND, started_at, completed_at) / 1000) AS max_ms
                                     FROM driver_verification_sessions
                                     WHERE started_at IS NOT NULL
                                       AND completed_at IS NOT NULL
                                       AND completed_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['avg_processing_ms'] = round((float) ($duration['avg_ms'] ?? 0), 2);
        $metrics['max_processing_ms'] = round((float) ($duration['max_ms'] ?? 0), 2);

        foreach (self::rows($conn, "SELECT status, COUNT(*) AS total FROM driver_verification_sessions GROUP BY status") as $row) {
            $metrics['statuses'][(string) $row['status']] = (int) $row['total'];
        }

        return $metrics;
    }

    private static function providerApiMetrics($conn): array
    {
        $metrics = [
            'schema_ready' => false,
            'checks_24h' => 0,
            'failed_24h' => 0,
            'needs_review_24h' => 0,
            'passed_24h' => 0,
            'providers' => [],
            'recent_failures' => [],
        ];

        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'government_api_checks')) {
            return $metrics;
        }

        $metrics['schema_ready'] = true;
        $metrics['checks_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM government_api_checks WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['failed_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM government_api_checks WHERE status = 'failed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['needs_review_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM government_api_checks WHERE status = 'needs_review' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['passed_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM government_api_checks WHERE status = 'passed' AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");

        $metrics['providers'] = self::rows($conn, "SELECT provider,
                                                        COUNT(*) AS total,
                                                        SUM(CASE WHEN status = 'passed' THEN 1 ELSE 0 END) AS passed,
                                                        SUM(CASE WHEN status = 'failed' THEN 1 ELSE 0 END) AS failed,
                                                        AVG(confidence) AS avg_confidence,
                                                        MAX(created_at) AS last_seen
                                                 FROM government_api_checks
                                                 WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
                                                 GROUP BY provider
                                                 ORDER BY total DESC
                                                 LIMIT 12");
        $metrics['recent_failures'] = self::rows($conn, "SELECT provider, check_type, status, confidence, created_at
                                                        FROM government_api_checks
                                                        WHERE status IN ('failed', 'needs_review')
                                                        ORDER BY created_at DESC, id DESC
                                                        LIMIT 10");

        return $metrics;
    }

    private static function queueMetrics($conn): array
    {
        $metrics = [
            'schema_ready' => false,
            'queued' => 0,
            'processing' => 0,
            'succeeded_24h' => 0,
            'failed' => 0,
            'failed_24h' => 0,
            'stale_processing' => 0,
            'queued_verification' => 0,
            'failed_verification' => 0,
            'by_queue' => [],
            'recent_failures' => [],
        ];

        if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'background_jobs')) {
            return $metrics;
        }

        $metrics['schema_ready'] = true;
        $metrics['queued'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'queued'");
        $metrics['processing'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'processing'");
        $metrics['succeeded_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'succeeded' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['failed'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'failed'");
        $metrics['failed_24h'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)");
        $metrics['stale_processing'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)");
        $metrics['queued_verification'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE queue_name = 'verification' AND status = 'queued'");
        $metrics['failed_verification'] = self::scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE queue_name = 'verification' AND status = 'failed'");
        $metrics['by_queue'] = self::rows($conn, "SELECT queue_name,
                                                   status,
                                                   COUNT(*) AS total,
                                                   MIN(available_at) AS oldest_available_at,
                                                   MAX(updated_at) AS latest_update_at
                                            FROM background_jobs
                                            GROUP BY queue_name, status
                                            ORDER BY queue_name ASC, status ASC");
        $metrics['recent_failures'] = self::rows($conn, "SELECT id, job_type, queue_name, attempts, max_attempts, last_error, updated_at
                                                        FROM background_jobs
                                                        WHERE status = 'failed'
                                                        ORDER BY updated_at DESC, id DESC
                                                        LIMIT 10");

        return $metrics;
    }

    private static function recentLogFindings(): array
    {
        $result = [
            'warnings_24h' => 0,
            'errors_24h' => 0,
            'critical_24h' => 0,
            'recent' => [],
        ];

        $files = glob(ridesync_storage_path('logs') . DIRECTORY_SEPARATOR . 'app-*.log') ?: [];
        rsort($files);
        $files = array_slice($files, 0, 3);
        $cutoff = time() - 86400;

        foreach ($files as $file) {
            foreach (self::tailLines($file, 240) as $line) {
                $entry = json_decode($line, true);
                if (!is_array($entry)) {
                    continue;
                }
                $timestamp = strtotime((string) ($entry['timestamp'] ?? ''));
                if ($timestamp !== false && $timestamp < $cutoff) {
                    continue;
                }
                $level = strtolower((string) ($entry['level'] ?? 'info'));
                if ($level === 'warning') {
                    $result['warnings_24h']++;
                } elseif ($level === 'error') {
                    $result['errors_24h']++;
                } elseif ($level === 'critical') {
                    $result['critical_24h']++;
                }

                if (in_array($level, ['warning', 'error', 'critical'], true) && count($result['recent']) < 10) {
                    $result['recent'][] = [
                        'timestamp' => (string) ($entry['timestamp'] ?? ''),
                        'level' => $level,
                        'message' => substr((string) ($entry['message'] ?? 'Log event'), 0, 180),
                        'request_id' => (string) ($entry['request_id'] ?? ''),
                    ];
                }
            }
        }

        return $result;
    }

    private static function alertRules($conn, array $services, array $workflows, array $apiChecks, array $queue, array $logs, array $summary): array
    {
        $rules = [];
        if ($conn instanceof mysqli && ridesync_table_exists($conn, 'admin_alert_rules')) {
            $rules = self::rows(
                $conn,
                "SELECT id, rule_key, label, metric_key, operator, threshold, severity, enabled, cooldown_minutes, last_triggered_at, updated_at
                 FROM admin_alert_rules
                 ORDER BY FIELD(severity, 'critical', 'warning', 'info'), label ASC"
            );
        }

        if (count($rules) === 0) {
            $rules = self::defaultAlertRules();
        }

        $evaluated = [];
        foreach ($rules as $rule) {
            $value = self::alertMetricValue((string) ($rule['metric_key'] ?? ''), $services, $workflows, $apiChecks, $queue, $logs, $summary);
            $threshold = (float) ($rule['threshold'] ?? 0);
            $operator = (string) ($rule['operator'] ?? 'greater_than');
            $enabled = (int) ($rule['enabled'] ?? 1) === 1;
            $triggered = $enabled && self::evaluateAlertRule($value, $operator, $threshold);
            $evaluated[] = [
                'id' => isset($rule['id']) ? (int) $rule['id'] : null,
                'rule_key' => (string) ($rule['rule_key'] ?? ''),
                'label' => (string) ($rule['label'] ?? 'Alert rule'),
                'metric_key' => (string) ($rule['metric_key'] ?? ''),
                'operator' => $operator,
                'threshold' => $threshold,
                'severity' => (string) ($rule['severity'] ?? 'warning'),
                'enabled' => $enabled,
                'cooldown_minutes' => (int) ($rule['cooldown_minutes'] ?? 15),
                'last_triggered_at' => $rule['last_triggered_at'] ?? null,
                'updated_at' => $rule['updated_at'] ?? null,
                'current_value' => $value,
                'triggered' => $triggered,
            ];
        }

        return $evaluated;
    }

    private static function alertsFromRules(array $alertRules): array
    {
        $alerts = [];
        foreach ($alertRules as $rule) {
            if (empty($rule['triggered'])) {
                continue;
            }

            $alerts[] = [
                'severity' => in_array($rule['severity'], ['critical', 'warning'], true) ? $rule['severity'] : 'warning',
                'title' => 'Alert rule triggered: ' . $rule['label'],
                'detail' => 'Current value ' . self::formatNumber((float) $rule['current_value']) . ' meets threshold ' . self::formatNumber((float) $rule['threshold']) . ' for ' . $rule['metric_key'] . '.',
                'service_key' => 'alert_rules',
                'rule_key' => $rule['rule_key'],
            ];
        }

        return $alerts;
    }

    private static function incidents($conn, array $alerts, array $workflows, array $apiChecks, array $queue, array $logs): array
    {
        $incidents = [];
        foreach ($alerts as $alert) {
            $incidents[] = [
                'severity' => (string) ($alert['severity'] ?? 'warning'),
                'status' => 'active',
                'source' => (string) ($alert['service_key'] ?? 'services'),
                'title' => (string) ($alert['title'] ?? 'Service alert'),
                'detail' => (string) ($alert['detail'] ?? 'Review service state.'),
                'created_at' => date('c'),
            ];
        }

        foreach (($queue['recent_failures'] ?? []) as $job) {
            $incidents[] = [
                'severity' => 'critical',
                'status' => 'open',
                'source' => 'background_jobs',
                'title' => 'Failed job #' . (int) ($job['id'] ?? 0) . ' - ' . (string) ($job['job_type'] ?? 'job'),
                'detail' => (string) ($job['last_error'] ?? 'No error message captured.'),
                'created_at' => (string) ($job['updated_at'] ?? date('c')),
            ];
        }

        foreach (($apiChecks['recent_failures'] ?? []) as $check) {
            $incidents[] = [
                'severity' => ($check['status'] ?? '') === 'failed' ? 'critical' : 'warning',
                'status' => 'open',
                'source' => 'provider_api',
                'title' => (string) ($check['provider'] ?? 'Provider') . ' ' . (string) ($check['check_type'] ?? 'check'),
                'detail' => 'Status ' . (string) ($check['status'] ?? 'unknown') . ', confidence ' . self::formatNumber((float) ($check['confidence'] ?? 0)) . '.',
                'created_at' => (string) ($check['created_at'] ?? date('c')),
            ];
        }

        foreach (($logs['recent'] ?? []) as $log) {
            $level = (string) ($log['level'] ?? 'warning');
            $incidents[] = [
                'severity' => in_array($level, ['error', 'critical'], true) ? 'critical' : 'warning',
                'status' => 'open',
                'source' => 'runtime_logs',
                'title' => strtoupper($level) . ' runtime log',
                'detail' => (string) ($log['message'] ?? 'Runtime warning.'),
                'created_at' => (string) ($log['timestamp'] ?? date('c')),
            ];
        }

        if ($conn instanceof mysqli && ridesync_table_exists($conn, 'driver_verification_sessions')) {
            foreach (self::rows($conn, "SELECT s.id, s.driver_id, s.status, s.progress_stage, s.updated_at, d.name
                                      FROM driver_verification_sessions s
                                      JOIN driver_accounts d ON d.id = s.driver_id
                                      WHERE s.status IN ('failed', 'needs_manual_review')
                                      ORDER BY s.updated_at DESC, s.id DESC
                                      LIMIT 8") as $session) {
                $incidents[] = [
                    'severity' => $session['status'] === 'failed' ? 'critical' : 'warning',
                    'status' => 'open',
                    'source' => 'ai_verification',
                    'title' => 'Verification session #' . (int) $session['id'] . ' for ' . (string) $session['name'],
                    'detail' => 'Stage ' . (string) $session['progress_stage'] . ', status ' . (string) $session['status'] . '.',
                    'created_at' => (string) $session['updated_at'],
                    'driver_id' => (int) $session['driver_id'],
                ];
            }
        }

        usort($incidents, static function ($a, $b) {
            $severityRank = ['critical' => 3, 'warning' => 2, 'info' => 1];
            $severityCompare = ($severityRank[$b['severity']] ?? 0) <=> ($severityRank[$a['severity']] ?? 0);
            if ($severityCompare !== 0) {
                return $severityCompare;
            }
            return strtotime((string) $b['created_at']) <=> strtotime((string) $a['created_at']);
        });

        return array_slice($incidents, 0, 20);
    }

    private static function alerts(array $services, array $workflows, array $queue, array $logs): array
    {
        $alerts = [];
        foreach ($services as $service) {
            if (in_array($service['status'], ['down', 'degraded'], true)) {
                $alerts[] = [
                    'severity' => $service['status'] === 'down' ? 'critical' : 'warning',
                    'title' => $service['name'] . ' is ' . $service['status_label'],
                    'detail' => $service['summary'],
                    'service_key' => $service['key'],
                ];
            }
        }

        if ((int) ($workflows['failed_24h'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'critical',
                'title' => 'AI verification failures detected',
                'detail' => (int) $workflows['failed_24h'] . ' verification workflow(s) failed in the last 24 hours.',
                'service_key' => 'ai_verification',
            ];
        }
        if ((int) ($workflows['slow_processing'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Slow AI processing detected',
                'detail' => (int) $workflows['slow_processing'] . ' verification workflow(s) have been processing for more than 5 minutes.',
                'service_key' => 'ai_verification',
            ];
        }
        if ((int) ($queue['stale_processing'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Stale background jobs detected',
                'detail' => (int) $queue['stale_processing'] . ' processing job(s) exceeded the stale threshold.',
                'service_key' => 'background_jobs',
            ];
        }
        if ((int) ($logs['errors_24h'] ?? 0) + (int) ($logs['critical_24h'] ?? 0) > 0) {
            $alerts[] = [
                'severity' => 'warning',
                'title' => 'Runtime errors logged',
                'detail' => ((int) $logs['errors_24h'] + (int) $logs['critical_24h']) . ' error or critical log event(s) were seen in the last 24 hours.',
                'service_key' => 'logs',
            ];
        }

        return array_slice($alerts, 0, 12);
    }

    private static function summary(array $services, array $workflows, array $apiChecks, array $queue, array $logs, array $alerts): array
    {
        $counts = [
            'operational' => 0,
            'degraded' => 0,
            'down' => 0,
            'unknown' => 0,
            'disabled' => 0,
        ];

        $latencies = [];
        foreach ($services as $service) {
            $status = (string) ($service['status'] ?? 'unknown');
            $counts[$status] = ($counts[$status] ?? 0) + 1;
            if (isset($service['latency_ms']) && is_numeric($service['latency_ms'])) {
                $latencies[] = (float) $service['latency_ms'];
            }
        }

        $status = 'operational';
        if ($counts['down'] > 0 || count(array_filter($alerts, static fn($alert) => ($alert['severity'] ?? '') === 'critical')) > 0) {
            $status = 'critical';
        } elseif ($counts['degraded'] > 0 || $counts['unknown'] > 0 || count($alerts) > 0) {
            $status = 'degraded';
        }

        $totalServices = max(1, count($services) - $counts['disabled']);
        $currentUptime = round(($counts['operational'] / $totalServices) * 100, 2);

        return [
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'current_uptime_percent' => $currentUptime,
            'services_total' => count($services),
            'services_operational' => $counts['operational'],
            'services_degraded' => $counts['degraded'],
            'services_down' => $counts['down'],
            'services_unknown' => $counts['unknown'],
            'services_disabled' => $counts['disabled'],
            'avg_latency_ms' => empty($latencies) ? 0 : round(array_sum($latencies) / count($latencies), 2),
            'ai_requests_24h' => (int) ($workflows['requests_24h'] ?? 0),
            'ai_responses_24h' => (int) ($workflows['responses_24h'] ?? 0),
            'api_checks_24h' => (int) ($apiChecks['checks_24h'] ?? 0),
            'failed_jobs' => (int) ($queue['failed'] ?? 0),
            'runtime_errors_24h' => (int) ($logs['errors_24h'] ?? 0) + (int) ($logs['critical_24h'] ?? 0),
            'alerts_total' => count($alerts),
        ];
    }

    private static function service(string $key, string $name, string $group, string $status, string $summary, ?float $latencyMs, array $metrics, array $details): array
    {
        $status = self::normalizeStatus($status);
        return [
            'key' => $key,
            'name' => $name,
            'group' => $group,
            'status' => $status,
            'status_label' => self::statusLabel($status),
            'summary' => $summary,
            'latency_ms' => $latencyMs,
            'uptime_percent' => $status === 'operational' ? 100.0 : ($status === 'disabled' ? null : 0.0),
            'metrics' => self::scrub($metrics),
            'details' => self::scrub($details),
            'checked_at' => date('c'),
        ];
    }

    private static function httpProbe(string $url, array $headers = [], float $timeout = 2.0): array
    {
        $started = microtime(true);
        $result = [
            'ok' => false,
            'status_code' => null,
            'latency_ms' => null,
            'error' => '',
            'body' => '',
            'json' => null,
            'headers' => [],
        ];

        if ($url === '' || !preg_match('/^https?:\/\//i', $url)) {
            $result['error'] = 'invalid_url';
            return $result;
        }

        try {
            if (function_exists('curl_init')) {
                $responseHeaders = [];
                $timeoutSeconds = max(1, (int) ceil($timeout));
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_CONNECTTIMEOUT => $timeoutSeconds,
                    CURLOPT_TIMEOUT => $timeoutSeconds,
                    CURLOPT_FOLLOWLOCATION => false,
                    CURLOPT_MAXREDIRS => 0,
                    CURLOPT_HTTPHEADER => $headers,
                    CURLOPT_HEADERFUNCTION => static function ($curl, string $line) use (&$responseHeaders): int {
                        $parts = explode(':', $line, 2);
                        if (count($parts) === 2) {
                            $responseHeaders[strtolower(trim($parts[0]))] = trim($parts[1]);
                        }
                        return strlen($line);
                    },
                ]);
                $body = curl_exec($ch);
                $result['status_code'] = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $error = curl_error($ch);
                curl_close($ch);
                $result['body'] = is_string($body) ? substr($body, 0, 4096) : '';
                $result['error'] = $error ?: '';
                $result['headers'] = $responseHeaders;
            } else {
                $context = stream_context_create([
                    'http' => [
                        'method' => 'GET',
                        'timeout' => $timeout,
                        'follow_location' => 0,
                        'max_redirects' => 0,
                        'header' => implode("\r\n", $headers) . "\r\n",
                    ],
                ]);
                $body = @file_get_contents($url, false, $context);
                $statusCode = 0;
                foreach (($http_response_header ?? []) as $headerLine) {
                    if (preg_match('/^HTTP\/\S+\s+([0-9]{3})/', $headerLine, $matches)) {
                        $statusCode = (int) $matches[1];
                    }
                }
                $result['status_code'] = $statusCode ?: null;
                $result['body'] = is_string($body) ? substr($body, 0, 4096) : '';
                $result['error'] = is_string($body) ? '' : 'request_failed';
            }
        } catch (Throwable $exception) {
            $result['error'] = $exception->getMessage();
        }

        $result['latency_ms'] = self::elapsedMs($started);
        $result['ok'] = is_int($result['status_code']) && $result['status_code'] >= 200 && $result['status_code'] < 300;
        $decoded = json_decode((string) $result['body'], true);
        $result['json'] = is_array($decoded) ? $decoded : null;

        return $result;
    }

    private static function statusFromProbe(array $probe, array $expectedStatuses): string
    {
        $statusCode = (int) ($probe['status_code'] ?? 0);
        if (in_array($statusCode, $expectedStatuses, true)) {
            return self::latencyStatus((float) ($probe['latency_ms'] ?? 0), 750, 2500);
        }
        if ($statusCode >= 500 || $statusCode === 0) {
            return 'down';
        }
        return 'degraded';
    }

    private static function latencyStatus(float $latencyMs, float $warningMs, float $criticalMs): string
    {
        if ($latencyMs >= $criticalMs) {
            return 'down';
        }
        if ($latencyMs >= $warningMs) {
            return 'degraded';
        }
        return 'operational';
    }

    private static function failedVerificationSessionsWithoutActiveJob($conn, int $limit): array
    {
        if (!ridesync_table_exists($conn, 'driver_verification_sessions')) {
            return [];
        }

        return self::rows($conn, "SELECT s.id, s.driver_id
                                  FROM driver_verification_sessions s
                                  WHERE s.status = 'failed'
                                    AND NOT EXISTS (
                                        SELECT 1
                                        FROM background_jobs bj
                                        WHERE bj.queue_name = 'verification'
                                          AND bj.job_type = 'verification.process'
                                          AND bj.status IN ('queued', 'processing')
                                          AND bj.payload_json LIKE CONCAT('%\"session_id\":', s.id, '%')
                                    )
                                  ORDER BY s.updated_at DESC, s.id DESC
                                  LIMIT " . (int) $limit);
    }

    private static function requiredTablesReady($conn): bool
    {
        foreach (['users', 'rides', 'matches', 'driver_accounts', 'background_jobs', 'realtime_events', 'admin_users'] as $table) {
            if (!ridesync_table_exists($conn, $table)) {
                return false;
            }
        }
        return true;
    }

    private static function websocketHealthUrl(string $wsUrl): string
    {
        if ($wsUrl === '') {
            return '';
        }
        $parts = parse_url($wsUrl);
        if (!is_array($parts) || empty($parts['host'])) {
            return '';
        }
        $scheme = strtolower((string) ($parts['scheme'] ?? 'ws')) === 'wss' ? 'https' : 'http';
        $host = $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        return $scheme . '://' . $host . $port . '/health';
    }

    private static function safeUrl(string $url): string
    {
        $url = trim($url);
        if ($url === '') {
            return '';
        }
        $parts = parse_url($url);
        if (!is_array($parts) || empty($parts['host'])) {
            return preg_replace('/[?].*/', '?...', $url) ?: '';
        }

        $scheme = (string) ($parts['scheme'] ?? 'https');
        $host = (string) $parts['host'];
        $port = isset($parts['port']) ? ':' . (int) $parts['port'] : '';
        $path = (string) ($parts['path'] ?? '');
        return $scheme . '://' . $host . $port . $path;
    }

    private static function redactedHost(string $host): string
    {
        $host = trim($host);
        if ($host === '') {
            return 'not configured';
        }
        return preg_replace('/:[^:@]+@/', ':***@', $host) ?: $host;
    }

    private static function scalar($conn, string $sql): int
    {
        $result = mysqli_query($conn, $sql);
        if (!$result) {
            return 0;
        }
        $row = mysqli_fetch_row($result);
        return (int) ($row[0] ?? 0);
    }

    private static function row($conn, string $sql): array
    {
        $result = mysqli_query($conn, $sql);
        return $result ? (mysqli_fetch_assoc($result) ?: []) : [];
    }

    private static function rows($conn, string $sql): array
    {
        $result = mysqli_query($conn, $sql);
        $rows = [];
        while ($result && ($row = mysqli_fetch_assoc($result))) {
            $rows[] = $row;
        }
        return $rows;
    }

    private static function tailLines(string $file, int $limit): array
    {
        if (!is_readable($file)) {
            return [];
        }
        $lines = file($file, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if (!is_array($lines)) {
            return [];
        }
        return array_slice($lines, -max(1, $limit));
    }

    private static function elapsedMs(float $started): float
    {
        return round((microtime(true) - $started) * 1000, 2);
    }

    private static function normalizeStatus(string $status): string
    {
        return in_array($status, ['operational', 'degraded', 'down', 'unknown', 'disabled', 'critical'], true)
            ? $status
            : 'unknown';
    }

    private static function statusLabel(string $status): string
    {
        $labels = [
            'operational' => 'Operational',
            'degraded' => 'Degraded',
            'down' => 'Down',
            'unknown' => 'Needs Validation',
            'disabled' => 'Disabled',
            'critical' => 'Critical',
        ];

        return $labels[$status] ?? 'Needs Validation';
    }

    private static function defaultAlertRules(): array
    {
        return [
            [
                'id' => null,
                'rule_key' => 'ai_failed_24h',
                'label' => 'AI failures in last 24h',
                'metric_key' => 'workflows.failed_24h',
                'operator' => 'greater_than',
                'threshold' => 0,
                'severity' => 'critical',
                'enabled' => 1,
                'cooldown_minutes' => 10,
                'last_triggered_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => null,
                'rule_key' => 'stale_processing_jobs',
                'label' => 'Stale processing jobs',
                'metric_key' => 'queues.stale_processing',
                'operator' => 'greater_than',
                'threshold' => 0,
                'severity' => 'warning',
                'enabled' => 1,
                'cooldown_minutes' => 10,
                'last_triggered_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => null,
                'rule_key' => 'provider_failures',
                'label' => 'Provider validation failures',
                'metric_key' => 'api_checks.failed_24h',
                'operator' => 'greater_than',
                'threshold' => 2,
                'severity' => 'warning',
                'enabled' => 1,
                'cooldown_minutes' => 15,
                'last_triggered_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => null,
                'rule_key' => 'runtime_errors',
                'label' => 'Runtime errors in logs',
                'metric_key' => 'logs.error_events_24h',
                'operator' => 'greater_than',
                'threshold' => 0,
                'severity' => 'warning',
                'enabled' => 1,
                'cooldown_minutes' => 15,
                'last_triggered_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => null,
                'rule_key' => 'service_degraded',
                'label' => 'Degraded service count',
                'metric_key' => 'summary.services_degraded',
                'operator' => 'greater_than',
                'threshold' => 0,
                'severity' => 'warning',
                'enabled' => 1,
                'cooldown_minutes' => 15,
                'last_triggered_at' => null,
                'updated_at' => null,
            ],
            [
                'id' => null,
                'rule_key' => 'service_down',
                'label' => 'Down service count',
                'metric_key' => 'summary.services_down',
                'operator' => 'greater_than',
                'threshold' => 0,
                'severity' => 'critical',
                'enabled' => 1,
                'cooldown_minutes' => 5,
                'last_triggered_at' => null,
                'updated_at' => null,
            ],
        ];
    }

    private static function evaluateAlertRule(float $value, string $operator, float $threshold): bool
    {
        if ($operator === 'greater_or_equal') {
            return $value >= $threshold;
        }
        if ($operator === 'equal_to') {
            return abs($value - $threshold) < 0.0001;
        }
        return $value > $threshold;
    }

    private static function alertMetricValue(string $metricKey, array $services, array $workflows, array $apiChecks, array $queue, array $logs, array $summary): float
    {
        if ($metricKey === 'logs.error_events_24h') {
            return (float) ((int) ($logs['errors_24h'] ?? 0) + (int) ($logs['critical_24h'] ?? 0));
        }

        $roots = [
            'summary' => $summary,
            'workflows' => $workflows,
            'api_checks' => $apiChecks,
            'queues' => $queue,
            'logs' => $logs,
        ];

        $parts = explode('.', $metricKey);
        $root = array_shift($parts);
        $value = $roots[$root] ?? null;
        foreach ($parts as $part) {
            if (!is_array($value) || !array_key_exists($part, $value)) {
                return 0.0;
            }
            $value = $value[$part];
        }

        if (is_numeric($value)) {
            return (float) $value;
        }

        if ($metricKey === 'services.down_count') {
            return (float) count(array_filter($services, static fn($service) => ($service['status'] ?? '') === 'down'));
        }

        return 0.0;
    }

    private static function formatNumber(float $value): string
    {
        return rtrim(rtrim(number_format($value, 2, '.', ''), '0'), '.');
    }

    private static function scrub(array $value): array
    {
        $scrubbed = [];
        foreach ($value as $key => $item) {
            $keyString = strtolower((string) $key);
            if (preg_match('/password|token|secret|cookie|authorization|csrf|aadhaar|aadhar|pan|license|document|base64|otp/', $keyString)) {
                $scrubbed[$key] = is_bool($item) ? $item : '[redacted]';
                continue;
            }
            if (is_array($item)) {
                $scrubbed[$key] = self::scrub($item);
                continue;
            }
            $scrubbed[$key] = $item;
        }

        return $scrubbed;
    }
}

?>
