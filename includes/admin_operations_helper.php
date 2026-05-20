<?php
require_once __DIR__ . '/matching_helper.php';

function ridesync_admin_ops_scalar($conn, $sql) {
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

function ridesync_admin_ops_rows($conn, $sql) {
    if (!$conn instanceof mysqli) {
        return [];
    }

    $result = mysqli_query($conn, $sql);
    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    return $rows;
}

function ridesync_admin_ops_prepared_rows($conn, $sql, $types = '', $params = []) {
    if (!$conn instanceof mysqli) {
        return [];
    }

    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '' && count($params) > 0) {
        $refs = [];
        foreach ($params as $index => $value) {
            $refs[$index] = &$params[$index];
        }
        if (!mysqli_stmt_bind_param($stmt, $types, ...$refs)) {
            return [];
        }
    }

    if (!mysqli_stmt_execute($stmt)) {
        return [];
    }

    $rows = [];
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }

    return $rows;
}

function ridesync_admin_ops_table_ready($conn, $table, $columns = []) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, $table)) {
        return false;
    }

    foreach ($columns as $column) {
        if (!ridesync_column_exists($conn, $table, $column)) {
            return false;
        }
    }

    return true;
}

function ridesync_admin_ops_escape_identifier($identifier) {
    if (preg_match('/^[A-Za-z0-9_]+$/', (string) $identifier) !== 1) {
        throw new InvalidArgumentException('Unsafe identifier.');
    }

    return '`' . $identifier . '`';
}

function ridesync_admin_ops_item($type, $severity, $title, $detail, $createdAt, $href, $meta = '') {
    $createdAt = trim((string) $createdAt);
    return [
        'type' => (string) $type,
        'severity' => in_array($severity, ['critical', 'warning', 'info', 'healthy'], true) ? $severity : 'info',
        'title' => substr(trim((string) $title), 0, 160),
        'detail' => substr(trim((string) $detail), 0, 240),
        'created_at' => $createdAt !== '' ? $createdAt : date('Y-m-d H:i:s'),
        'href' => (string) $href,
        'meta' => substr(trim((string) $meta), 0, 120),
    ];
}

function ridesync_admin_ops_severity_rank($severity) {
    return [
        'critical' => 4,
        'warning' => 3,
        'info' => 2,
        'healthy' => 1,
    ][(string) $severity] ?? 0;
}

function ridesync_admin_sort_operations(&$items, $oldestFirstWithinSeverity = false) {
    usort($items, static function ($a, $b) use ($oldestFirstWithinSeverity) {
        $severity = ridesync_admin_ops_severity_rank($b['severity'] ?? '') <=> ridesync_admin_ops_severity_rank($a['severity'] ?? '');
        if ($severity !== 0) {
            return $severity;
        }

        $aTime = strtotime((string) ($a['created_at'] ?? '')) ?: 0;
        $bTime = strtotime((string) ($b['created_at'] ?? '')) ?: 0;
        return $oldestFirstWithinSeverity ? ($aTime <=> $bTime) : ($bTime <=> $aTime);
    });
}

function ridesync_admin_operational_inbox($conn, $metrics = [], $limit = 18) {
    $items = [];
    $limit = max(6, min(40, (int) $limit));

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'reports')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT rep.id, rep.reason, rep.report_status, rep.created_at, reporter.name AS reporter_name,
                    reported.name AS reported_name
             FROM reports rep
             JOIN users reporter ON reporter.id = rep.reporter_user_id
             LEFT JOIN users reported ON reported.id = rep.reported_user_id
             WHERE rep.report_status IN ('open', 'reviewing')
             ORDER BY FIELD(rep.reason, 'safety', 'misconduct', 'fake_profile', 'payment', 'spam', 'other'),
                      rep.created_at ASC
             LIMIT 8"
        ) as $row) {
            $isCritical = in_array((string) $row['reason'], ['safety', 'misconduct', 'fake_profile'], true);
            $items[] = ridesync_admin_ops_item(
                'Report',
                $isCritical ? 'critical' : 'warning',
                'Report #' . (int) $row['id'] . ' - ' . ridesync_admin_status_label($row['reason']),
                'From ' . (string) $row['reporter_name'] . (!empty($row['reported_name']) ? ' against ' . (string) $row['reported_name'] : ''),
                $row['created_at'],
                '/ridesync/pages/admin_dashboard.php?section=reports&q=' . urlencode((string) $row['id']),
                ridesync_admin_status_label($row['report_status'])
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'driver_account_profiles')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT p.driver_id, p.verification_status, p.updated_at, d.name, d.email
             FROM driver_account_profiles p
             JOIN driver_accounts d ON d.id = p.driver_id
             WHERE p.verification_status IN ('pending', 'rejected')
             ORDER BY FIELD(p.verification_status, 'rejected', 'pending'), p.updated_at ASC
             LIMIT 6"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Driver',
                $row['verification_status'] === 'rejected' ? 'warning' : 'info',
                'Driver profile needs review: ' . (string) $row['name'],
                (string) $row['email'],
                $row['updated_at'],
                '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $row['driver_id'],
                ridesync_admin_status_label($row['verification_status'])
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'driver_account_documents')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT doc.driver_id, doc.document_type, doc.created_at, d.name
             FROM driver_account_documents doc
             JOIN driver_accounts d ON d.id = doc.driver_id
             WHERE doc.verification_status = 'pending'
             ORDER BY doc.created_at ASC
             LIMIT 6"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Document',
                'info',
                'Pending ' . ridesync_admin_status_label($row['document_type']) . ' for ' . (string) $row['name'],
                'Driver document requires admin decision.',
                $row['created_at'],
                '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $row['driver_id'],
                'Document review'
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'user_verifications')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT uv.id, uv.verification_type, uv.updated_at, u.name, u.email
             FROM user_verifications uv
             JOIN users u ON u.id = uv.user_id
             WHERE uv.status = 'pending'
             ORDER BY uv.updated_at ASC
             LIMIT 6"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Student',
                'info',
                'Student verification pending: ' . (string) $row['name'],
                (string) $row['email'] . ' - ' . ridesync_admin_status_label($row['verification_type']),
                $row['updated_at'],
                '/ridesync/pages/admin_dashboard.php?section=users&q=' . urlencode((string) $row['name']),
                'Student review'
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'rides')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT r.id, r.origin, r.destination, r.travel_date, r.travel_time, u.name AS owner_name
             FROM rides r
             JOIN users u ON u.id = r.user_id
             WHERE r.status = 'open'
               AND CONCAT(r.travel_date, ' ', r.travel_time) < NOW()
             ORDER BY r.travel_date ASC, r.travel_time ASC, r.id ASC
             LIMIT 6"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Ride',
                'warning',
                'Stale open ride #' . (int) $row['id'],
                (string) $row['origin'] . ' to ' . (string) $row['destination'] . ' by ' . (string) $row['owner_name'],
                $row['travel_date'] . ' ' . $row['travel_time'],
                '/ridesync/pages/admin_ride_detail.php?id=' . (int) $row['id'],
                'Past departure'
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'background_jobs')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT id, job_type, queue_name, last_error, updated_at
             FROM background_jobs
             WHERE status = 'failed'
             ORDER BY updated_at DESC, id DESC
             LIMIT 6"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Service',
                'critical',
                'Failed background job #' . (int) $row['id'],
                (string) $row['queue_name'] . ' / ' . (string) $row['job_type'] . ': ' . (string) ($row['last_error'] ?: 'No error captured'),
                $row['updated_at'],
                '/ridesync/pages/admin_dashboard.php?section=services',
                'Queue recovery'
            );
        }
    }

    if (function_exists('ridesync_verification_schema_ready') && ridesync_verification_schema_ready($conn)) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT s.id, s.driver_id, s.status, s.ai_decision, s.risk_level, s.confidence_score, s.updated_at, d.name
             FROM driver_verification_sessions s
             JOIN driver_accounts d ON d.id = s.driver_id
             WHERE s.status IN ('failed', 'needs_manual_review')
                OR s.risk_level IN ('high', 'critical')
                OR s.ai_decision IN ('suspicious', 'fake_tampered')
             ORDER BY FIELD(s.risk_level, 'critical', 'high', 'medium', 'low'),
                      FIELD(s.status, 'failed', 'needs_manual_review', 'processing', 'queued', 'verified'),
                      s.updated_at DESC
             LIMIT 8"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'AI Review',
                in_array((string) $row['risk_level'], ['critical', 'high'], true) || $row['status'] === 'failed' ? 'critical' : 'warning',
                'AI verification needs review for ' . (string) $row['name'],
                'Session #' . (int) $row['id'] . ' - ' . ridesync_admin_status_label($row['status']) . ', ' . ridesync_admin_status_label($row['risk_level']) . ' risk, score ' . number_format((float) $row['confidence_score'], 1),
                $row['updated_at'],
                '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $row['driver_id'],
                'AI compliance'
            );
        }
    }

    ridesync_admin_sort_operations($items, true);
    if (count($items) === 0) {
        $items[] = ridesync_admin_ops_item(
            'Healthy',
            'healthy',
            'Operational inbox is clear',
            'No urgent moderation, service, ride, or verification items are waiting right now.',
            date('Y-m-d H:i:s'),
            '/ridesync/pages/admin_dashboard.php?section=overview',
            'No action'
        );
    }

    return array_slice($items, 0, $limit);
}

function ridesync_admin_risk_score($conn, $metrics = []) {
    $components = [];
    $openReports = (int) ($metrics['open_reports'] ?? 0);
    $activeReports = (int) ($metrics['active_reports'] ?? 0);
    $pendingVerifications = (int) ($metrics['pending_driver_profiles'] ?? 0)
        + (int) ($metrics['pending_driver_documents'] ?? 0)
        + (int) ($metrics['pending_student_verifications'] ?? 0);
    $staleOpenRides = (int) ($metrics['stale_open_rides'] ?? 0);
    $openRides = (int) ($metrics['open_rides'] ?? 0);
    $onlineDrivers = (int) ($metrics['online_drivers'] ?? 0);

    $failedJobs = ridesync_table_exists($conn, 'background_jobs')
        ? ridesync_admin_ops_scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'failed'")
        : 0;
    $staleJobs = ridesync_table_exists($conn, 'background_jobs')
        ? ridesync_admin_ops_scalar($conn, "SELECT COUNT(*) FROM background_jobs WHERE status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE)")
        : 0;
    $failedAi = ridesync_table_exists($conn, 'driver_verification_sessions')
        ? ridesync_admin_ops_scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE status = 'failed' AND updated_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")
        : 0;
    $highRiskAi = ridesync_table_exists($conn, 'driver_verification_sessions')
        ? ridesync_admin_ops_scalar($conn, "SELECT COUNT(*) FROM driver_verification_sessions WHERE risk_level IN ('high', 'critical') AND updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)")
        : 0;
    $adminDenials = ridesync_table_exists($conn, 'audit_logs')
        ? ridesync_admin_ops_scalar($conn, "SELECT COUNT(*) FROM audit_logs WHERE action IN ('admin_action_denied', 'admin_remove_confirmation_failed', 'admin_bulk_confirmation_failed') AND created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)")
        : 0;

    $components[] = [
        'label' => 'Moderation pressure',
        'points' => min(25, ($openReports * 8) + ($activeReports * 3)),
        'detail' => $openReports . ' open, ' . $activeReports . ' active report(s)',
    ];
    $components[] = [
        'label' => 'Verification backlog',
        'points' => min(20, ($pendingVerifications * 2) + ($highRiskAi * 6)),
        'detail' => $pendingVerifications . ' pending check(s), ' . $highRiskAi . ' high-risk AI session(s)',
    ];
    $components[] = [
        'label' => 'Ride integrity',
        'points' => min(20, ($staleOpenRides * 5) + (($openRides > 0 && $onlineDrivers === 0) ? 12 : 0)),
        'detail' => $staleOpenRides . ' stale ride(s), ' . $onlineDrivers . ' online driver(s)',
    ];
    $components[] = [
        'label' => 'Service stability',
        'points' => min(20, ($failedJobs * 4) + ($staleJobs * 6) + ($failedAi * 6)),
        'detail' => $failedJobs . ' failed job(s), ' . $staleJobs . ' stale job(s), ' . $failedAi . ' failed AI run(s)',
    ];
    $components[] = [
        'label' => 'Admin/security controls',
        'points' => min(15, $adminDenials * 5),
        'detail' => $adminDenials . ' denied or failed sensitive action(s) in 24h',
    ];

    $risk = 0;
    foreach ($components as $component) {
        $risk += (int) $component['points'];
    }
    $risk = max(0, min(100, $risk));

    $level = 'Low';
    $severity = 'healthy';
    if ($risk >= 75) {
        $level = 'Critical';
        $severity = 'critical';
    } elseif ($risk >= 50) {
        $level = 'High';
        $severity = 'critical';
    } elseif ($risk >= 25) {
        $level = 'Elevated';
        $severity = 'warning';
    }

    return [
        'score' => $risk,
        'inverse_score' => 100 - $risk,
        'level' => $level,
        'severity' => $severity,
        'components' => $components,
        'generated_at' => date('Y-m-d H:i:s'),
    ];
}

function ridesync_admin_incident_timeline($conn, $limit = 24) {
    $items = [];
    $limit = max(8, min(60, (int) $limit));

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'reports')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT rep.id, rep.reason, rep.report_status, rep.created_at, rep.updated_at, reporter.name AS reporter_name
             FROM reports rep
             JOIN users reporter ON reporter.id = rep.reporter_user_id
             WHERE rep.report_status IN ('open', 'reviewing')
                OR rep.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
             ORDER BY rep.updated_at DESC
             LIMIT 10"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Report',
                in_array((string) $row['reason'], ['safety', 'misconduct', 'fake_profile'], true) ? 'critical' : 'warning',
                'Report #' . (int) $row['id'] . ' ' . ridesync_admin_status_label($row['report_status']),
                ridesync_admin_status_label($row['reason']) . ' report from ' . (string) $row['reporter_name'],
                $row['updated_at'] ?: $row['created_at'],
                '/ridesync/pages/admin_dashboard.php?section=reports&q=' . urlencode((string) $row['id']),
                'Moderation'
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'audit_logs')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT al.id, al.action, al.entity_type, al.entity_id, al.message, al.created_at, au.name AS admin_name
             FROM audit_logs al
             LEFT JOIN admin_users au ON au.id = al.admin_id
             WHERE al.created_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND (
                    al.action LIKE '%denied%'
                 OR al.action LIKE 'admin_bulk_%'
                 OR al.action LIKE 'admin_remove_%'
                 OR al.action LIKE 'driver_ai_verification_%'
                 OR al.action LIKE 'report_%'
               )
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 14"
        ) as $row) {
            $isCritical = str_contains((string) $row['action'], 'remove') || str_contains((string) $row['action'], 'denied');
            $items[] = ridesync_admin_ops_item(
                'Audit',
                $isCritical ? 'critical' : 'info',
                ridesync_admin_status_label($row['action']),
                trim((string) ($row['admin_name'] ?: 'System') . ' - ' . (string) ($row['message'] ?: $row['entity_type'])),
                $row['created_at'],
                '/ridesync/pages/admin_dashboard.php?section=audit&audit_action=' . urlencode((string) $row['action']),
                'Audit event'
            );
        }
    }

    if ($conn instanceof mysqli && ridesync_table_exists($conn, 'background_jobs')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT id, job_type, queue_name, status, last_error, updated_at
             FROM background_jobs
             WHERE status = 'failed'
                OR (status = 'processing' AND locked_at < DATE_SUB(NOW(), INTERVAL 10 MINUTE))
             ORDER BY updated_at DESC, id DESC
             LIMIT 10"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'Job',
                $row['status'] === 'failed' ? 'critical' : 'warning',
                'Background job #' . (int) $row['id'] . ' ' . ridesync_admin_status_label($row['status']),
                (string) $row['queue_name'] . ' / ' . (string) $row['job_type'] . ': ' . (string) ($row['last_error'] ?: 'No error captured'),
                $row['updated_at'],
                '/ridesync/pages/admin_dashboard.php?section=services',
                'Worker'
            );
        }
    }

    if (function_exists('ridesync_verification_schema_ready') && ridesync_verification_schema_ready($conn)) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT s.id, s.driver_id, s.status, s.ai_decision, s.risk_level, s.updated_at, d.name
             FROM driver_verification_sessions s
             JOIN driver_accounts d ON d.id = s.driver_id
             WHERE s.updated_at >= DATE_SUB(NOW(), INTERVAL 7 DAY)
               AND (s.status IN ('failed', 'needs_manual_review')
                    OR s.ai_decision IN ('suspicious', 'fake_tampered', 'needs_manual_review')
                    OR s.risk_level IN ('high', 'critical'))
             ORDER BY s.updated_at DESC, s.id DESC
             LIMIT 12"
        ) as $row) {
            $items[] = ridesync_admin_ops_item(
                'AI',
                in_array((string) $row['risk_level'], ['high', 'critical'], true) || $row['status'] === 'failed' ? 'critical' : 'warning',
                'AI verification session #' . (int) $row['id'],
                (string) $row['name'] . ' - ' . ridesync_admin_status_label($row['status']) . ', ' . ridesync_admin_status_label($row['risk_level']) . ' risk',
                $row['updated_at'],
                '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $row['driver_id'],
                'AI compliance'
            );
        }
    }

    ridesync_admin_sort_operations($items, false);
    if (count($items) === 0) {
        $items[] = ridesync_admin_ops_item(
            'Healthy',
            'healthy',
            'No incidents in timeline',
            'No qualifying incidents were found in the current monitoring window.',
            date('Y-m-d H:i:s'),
            '/ridesync/pages/admin_dashboard.php?section=overview',
            'Stable'
        );
    }

    return array_slice($items, 0, $limit);
}

function ridesync_admin_low_risk_ai_session_count($conn) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'driver_verification_sessions')) {
        return 0;
    }

    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM driver_verification_sessions s
         JOIN (
            SELECT driver_id, MAX(id) AS latest_id
            FROM driver_verification_sessions
            GROUP BY driver_id
         ) latest ON latest.latest_id = s.id
         JOIN driver_account_profiles p ON p.driver_id = s.driver_id
         WHERE s.status = 'verified'
           AND s.ai_decision = 'verified'
           AND s.risk_level = 'low'
           AND s.confidence_score >= 90
           AND s.admin_decision IS NULL
           AND p.verification_status <> 'verified'
           AND NOT EXISTS (
                SELECT 1
                FROM fraud_flags f
                WHERE f.session_id = s.id
                  AND f.severity IN ('high', 'critical')
           )"
    );

    if (!$result) {
        return 0;
    }

    return (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

function ridesync_admin_failed_ai_job_count($conn) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'background_jobs')) {
        return 0;
    }

    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM background_jobs
         WHERE queue_name = 'verification'
           AND job_type = 'verification.process'
           AND status = 'failed'"
    );

    if (!$result) {
        return 0;
    }

    return (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

function ridesync_admin_stale_open_ride_count($conn) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'rides')) {
        return 0;
    }

    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM rides
         WHERE status = 'open'
           AND CONCAT(travel_date, ' ', travel_time) < NOW()"
    );

    if (!$result) {
        return 0;
    }

    return (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

function ridesync_admin_stale_demand_count($conn) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'route_demand_signals')) {
        return 0;
    }

    $result = mysqli_query(
        $conn,
        "SELECT COUNT(*) AS total
         FROM route_demand_signals
         WHERE demand_status = 'active'
           AND travel_date IS NOT NULL
           AND travel_time IS NOT NULL
           AND CONCAT(travel_date, ' ', travel_time) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );

    if (!$result) {
        return 0;
    }

    return (int) (mysqli_fetch_assoc($result)['total'] ?? 0);
}

function ridesync_admin_bulk_operation_definitions($conn) {
    return [
        'retry_failed_ai_jobs' => [
            'title' => 'Retry Failed AI Jobs',
            'kicker' => 'AI Recovery',
            'description' => 'Requeue failed verification worker jobs and failed sessions that have no active retry job.',
            'count' => ridesync_admin_failed_ai_job_count($conn),
            'button' => 'Retry jobs',
            'severity' => 'warning',
        ],
        'close_stale_rides' => [
            'title' => 'Close Stale Open Rides',
            'kicker' => 'Ride Hygiene',
            'description' => 'Close open rides whose departure time has already passed and reject their pending join requests.',
            'count' => ridesync_admin_stale_open_ride_count($conn),
            'button' => 'Close stale rides',
            'severity' => 'warning',
        ],
        'expire_stale_demand' => [
            'title' => 'Expire Stale Route Demand',
            'kicker' => 'Demand Cleanup',
            'description' => 'Expire unmatched route demand signals that are past their requested travel time.',
            'count' => ridesync_admin_stale_demand_count($conn),
            'button' => 'Expire signals',
            'severity' => 'warning',
        ],
        'approve_low_risk_ai' => [
            'title' => 'Approve Low-Risk AI Verifications',
            'kicker' => 'Verification Workbench',
            'description' => 'Approve only the latest low-risk AI sessions with 90+ trust score and no high fraud flags.',
            'count' => ridesync_admin_low_risk_ai_session_count($conn),
            'button' => 'Approve low-risk',
            'severity' => 'critical',
        ],
    ];
}

function ridesync_admin_fetch_low_risk_ai_sessions($conn, $limit = 25) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'driver_verification_sessions')) {
        return [];
    }

    $limit = max(1, min(50, (int) $limit));
    $result = mysqli_query(
        $conn,
        "SELECT s.id, s.driver_id, s.confidence_score, d.name
         FROM driver_verification_sessions s
         JOIN (
            SELECT driver_id, MAX(id) AS latest_id
            FROM driver_verification_sessions
            GROUP BY driver_id
         ) latest ON latest.latest_id = s.id
         JOIN driver_accounts d ON d.id = s.driver_id
         JOIN driver_account_profiles p ON p.driver_id = s.driver_id
         WHERE s.status = 'verified'
           AND s.ai_decision = 'verified'
           AND s.risk_level = 'low'
           AND s.confidence_score >= 90
           AND s.admin_decision IS NULL
           AND p.verification_status <> 'verified'
           AND NOT EXISTS (
                SELECT 1
                FROM fraud_flags f
                WHERE f.session_id = s.id
                  AND f.severity IN ('high', 'critical')
           )
         ORDER BY s.confidence_score DESC, s.updated_at DESC, s.id DESC
         LIMIT " . (int) $limit
    );

    $rows = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }
    return $rows;
}

function ridesync_admin_close_stale_rides($conn, $limit = 250) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'rides')) {
        return 0;
    }

    $limit = max(1, min(1000, (int) $limit));
    $ids = [];
    $result = mysqli_query(
        $conn,
        "SELECT id
         FROM rides
         WHERE status = 'open'
           AND CONCAT(travel_date, ' ', travel_time) < NOW()
         ORDER BY travel_date ASC, travel_time ASC, id ASC
         LIMIT " . (int) $limit
    );
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $ids[] = (int) $row['id'];
    }

    if (count($ids) === 0) {
        return 0;
    }

    $idList = implode(',', $ids);
    mysqli_query($conn, "UPDATE rides SET status = 'closed' WHERE id IN ({$idList}) AND status = 'open'");
    $changed = max(0, mysqli_affected_rows($conn));

    if (ridesync_table_exists($conn, 'matches')) {
        mysqli_query($conn, "UPDATE matches SET status = 'rejected' WHERE ride_id IN ({$idList}) AND status = 'pending'");
    }

    return $changed;
}

function ridesync_admin_expire_stale_demand($conn, $limit = 500) {
    if (!$conn instanceof mysqli || !ridesync_table_exists($conn, 'route_demand_signals')) {
        return 0;
    }

    $limit = max(1, min(2000, (int) $limit));
    mysqli_query(
        $conn,
        "UPDATE route_demand_signals
         SET demand_status = 'expired'
         WHERE demand_status = 'active'
           AND travel_date IS NOT NULL
           AND travel_time IS NOT NULL
           AND CONCAT(travel_date, ' ', travel_time) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)
         ORDER BY travel_date ASC, travel_time ASC, id ASC
         LIMIT " . (int) $limit
    );

    return max(0, mysqli_affected_rows($conn));
}

function ridesync_admin_approve_low_risk_ai_sessions($conn, $adminId, $limit = 25) {
    $summary = [
        'approved' => 0,
        'drivers' => [],
    ];

    if (!function_exists('ridesync_verification_admin_decision')) {
        return $summary;
    }

    $sessions = ridesync_admin_fetch_low_risk_ai_sessions($conn, $limit);
    foreach ($sessions as $session) {
        $sessionId = (int) $session['id'];
        $driverId = (int) $session['driver_id'];
        $note = 'Bulk low-risk approval: latest AI verification scored ' . number_format((float) $session['confidence_score'], 1) . '/100 with no high fraud flags.';

        if (!ridesync_verification_admin_decision($conn, $sessionId, (int) $adminId, 'approved', $note)) {
            continue;
        }

        $stmt = mysqli_prepare($conn, "UPDATE driver_account_profiles SET verification_status = 'verified' WHERE driver_id = ?");
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $driverId);
            mysqli_stmt_execute($stmt);
        }

        $stmt = mysqli_prepare(
            $conn,
            "UPDATE driver_account_documents
             SET verification_status = 'verified'
             WHERE driver_id = ?
               AND document_type IN ('license', 'aadhaar', 'pan', 'vehicle_rc', 'selfie', 'vehicle_image')"
        );
        if ($stmt) {
            mysqli_stmt_bind_param($stmt, 'i', $driverId);
            mysqli_stmt_execute($stmt);
        }

        if (function_exists('ridesync_admin_notify')) {
            ridesync_admin_notify(
                $conn,
                null,
                $driverId,
                'Driver verification approved',
                'Your driver documents were approved after RideSync AI verification.'
            );
        }

        $summary['approved']++;
        $summary['drivers'][] = $driverId;
    }

    return $summary;
}

function ridesync_admin_note_entity_types() {
    return ['user', 'driver', 'ride', 'report'];
}

function ridesync_admin_note_types() {
    return ['general', 'risk', 'support', 'compliance'];
}

function ridesync_admin_notes_schema_ready($conn) {
    return ridesync_admin_ops_table_ready($conn, 'admin_notes', ['entity_type', 'entity_id', 'note_text', 'note_type']);
}

function ridesync_admin_normalize_note_entity($entityType) {
    $entityType = strtolower(trim((string) $entityType));
    return in_array($entityType, ridesync_admin_note_entity_types(), true) ? $entityType : '';
}

function ridesync_admin_fetch_notes($conn, $entityType, $entityId, $limit = 8) {
    $entityType = ridesync_admin_normalize_note_entity($entityType);
    $entityId = (int) $entityId;
    $limit = max(1, min(20, (int) $limit));

    if ($entityType === '' || $entityId <= 0 || !ridesync_admin_notes_schema_ready($conn)) {
        return [];
    }

    $stmt = mysqli_prepare(
        $conn,
        "SELECT n.*, au.name AS admin_name, au.email AS admin_email
         FROM admin_notes n
         LEFT JOIN admin_users au ON au.id = n.admin_id
         WHERE n.entity_type = ? AND n.entity_id = ?
         ORDER BY n.created_at DESC, n.id DESC
         LIMIT ?"
    );
    if (!$stmt) {
        return [];
    }
    mysqli_stmt_bind_param($stmt, 'sii', $entityType, $entityId, $limit);
    mysqli_stmt_execute($stmt);

    $rows = [];
    $result = mysqli_stmt_get_result($stmt);
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $rows[] = $row;
    }

    return $rows;
}

function ridesync_admin_fetch_notes_for_entities($conn, $entityType, $entityIds, $perEntity = 2) {
    $entityType = ridesync_admin_normalize_note_entity($entityType);
    $entityIds = array_values(array_unique(array_filter(array_map('intval', (array) $entityIds), static fn($id) => $id > 0)));
    $perEntity = max(1, min(5, (int) $perEntity));

    if ($entityType === '' || count($entityIds) === 0 || !ridesync_admin_notes_schema_ready($conn)) {
        return [];
    }

    $idList = implode(',', $entityIds);
    $safeEntity = mysqli_real_escape_string($conn, $entityType);
    $result = mysqli_query(
        $conn,
        "SELECT n.*, au.name AS admin_name
         FROM admin_notes n
         LEFT JOIN admin_users au ON au.id = n.admin_id
         WHERE n.entity_type = '{$safeEntity}'
           AND n.entity_id IN ({$idList})
         ORDER BY n.entity_id ASC, n.created_at DESC, n.id DESC"
    );

    $grouped = [];
    while ($result && ($row = mysqli_fetch_assoc($result))) {
        $entityId = (int) $row['entity_id'];
        if (!isset($grouped[$entityId])) {
            $grouped[$entityId] = [];
        }
        if (count($grouped[$entityId]) < $perEntity) {
            $grouped[$entityId][] = $row;
        }
    }

    return $grouped;
}

function ridesync_admin_create_note($conn, $entityType, $entityId, $adminId, $noteText, $noteType = 'general') {
    $entityType = ridesync_admin_normalize_note_entity($entityType);
    $entityId = (int) $entityId;
    $adminId = (int) $adminId;
    $noteType = strtolower(trim((string) $noteType));
    $noteText = trim((string) $noteText);

    if (!in_array($noteType, ridesync_admin_note_types(), true)) {
        $noteType = 'general';
    }

    if ($entityType === '' || $entityId <= 0 || $adminId <= 0 || $noteText === '' || !ridesync_admin_notes_schema_ready($conn)) {
        return false;
    }

    if (strlen($noteText) > 2000) {
        $noteText = substr($noteText, 0, 2000);
    }

    $stmt = mysqli_prepare(
        $conn,
        "INSERT INTO admin_notes (entity_type, entity_id, admin_id, note_type, note_text)
         VALUES (?, ?, ?, ?, ?)"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'siiss', $entityType, $entityId, $adminId, $noteType, $noteText);
    return mysqli_stmt_execute($stmt);
}

function ridesync_admin_default_feature_flags() {
    return [
        [
            'id' => 0,
            'flag_key' => 'rides_marketplace',
            'label' => 'Ride marketplace',
            'description' => 'Rider ride posting, search, and join-request flows.',
            'module' => 'rides',
            'enabled' => 1,
            'maintenance_mode' => 0,
            'source' => 'fallback',
        ],
        [
            'id' => 0,
            'flag_key' => 'driver_panel',
            'label' => 'Driver panel',
            'description' => 'Driver availability, requests, documents, and earnings workflows.',
            'module' => 'drivers',
            'enabled' => 1,
            'maintenance_mode' => 0,
            'source' => 'fallback',
        ],
        [
            'id' => 0,
            'flag_key' => 'ai_verification',
            'label' => 'AI verification',
            'description' => 'AI document analysis, provider checks, and compliance scoring.',
            'module' => 'ai',
            'enabled' => 1,
            'maintenance_mode' => 0,
            'source' => 'fallback',
        ],
        [
            'id' => 0,
            'flag_key' => 'reports_moderation',
            'label' => 'Reports moderation',
            'description' => 'User report intake, triage, decisions, and audit visibility.',
            'module' => 'trust',
            'enabled' => 1,
            'maintenance_mode' => 0,
            'source' => 'fallback',
        ],
        [
            'id' => 0,
            'flag_key' => 'payments_wallet',
            'label' => 'Payments and wallet',
            'description' => 'Fare due tracking, cash-paid records, and wallet ledgers.',
            'module' => 'payments',
            'enabled' => 1,
            'maintenance_mode' => 0,
            'source' => 'fallback',
        ],
        [
            'id' => 0,
            'flag_key' => 'realtime_gateway',
            'label' => 'Realtime gateway',
            'description' => 'Websocket events, polling fallbacks, and live ride status sync.',
            'module' => 'realtime',
            'enabled' => 1,
            'maintenance_mode' => 0,
            'source' => 'fallback',
        ],
    ];
}

function ridesync_admin_feature_flags_schema_ready($conn) {
    return ridesync_admin_ops_table_ready($conn, 'feature_flags', ['flag_key', 'enabled', 'maintenance_mode']);
}

function ridesync_admin_feature_flags($conn) {
    if (!ridesync_admin_feature_flags_schema_ready($conn)) {
        return [
            'schema_ready' => false,
            'flags' => ridesync_admin_default_feature_flags(),
        ];
    }

    $rows = ridesync_admin_ops_rows(
        $conn,
        "SELECT ff.*, au.name AS updated_by_name
         FROM feature_flags ff
         LEFT JOIN admin_users au ON au.id = ff.updated_by
         ORDER BY ff.module ASC, ff.label ASC"
    );

    return [
        'schema_ready' => true,
        'flags' => $rows,
    ];
}

function ridesync_admin_update_feature_flag($conn, $flagId, $enabled, $maintenanceMode, $adminId) {
    $flagId = (int) $flagId;
    $enabled = (int) ((bool) $enabled);
    $maintenanceMode = (int) ((bool) $maintenanceMode);
    $adminId = (int) $adminId;

    if ($flagId <= 0 || $adminId <= 0 || !ridesync_admin_feature_flags_schema_ready($conn)) {
        return false;
    }

    $stmt = mysqli_prepare(
        $conn,
        "UPDATE feature_flags
         SET enabled = ?, maintenance_mode = ?, updated_by = ?
         WHERE id = ?"
    );
    if (!$stmt) {
        return false;
    }

    mysqli_stmt_bind_param($stmt, 'iiii', $enabled, $maintenanceMode, $adminId, $flagId);
    return mysqli_stmt_execute($stmt);
}

function ridesync_admin_quality_issue($title, $count, $severity, $detail, $href) {
    return [
        'title' => (string) $title,
        'count' => (int) $count,
        'severity' => in_array($severity, ['critical', 'warning', 'healthy', 'info'], true) ? $severity : 'info',
        'detail' => (string) $detail,
        'href' => (string) $href,
    ];
}

function ridesync_admin_data_quality_monitor($conn) {
    $issues = [];

    $checks = [
        [
            'tables' => ['matches', 'rides'],
            'title' => 'Orphan join requests',
            'sql' => "SELECT COUNT(*) FROM matches m LEFT JOIN rides r ON r.id = m.ride_id WHERE r.id IS NULL",
            'detail' => 'Join requests whose ride record no longer exists.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=requests',
            'severity' => 'critical',
        ],
        [
            'tables' => ['matches', 'users'],
            'title' => 'Join requests missing requester',
            'sql' => "SELECT COUNT(*) FROM matches m LEFT JOIN users u ON u.id = m.matched_user_id WHERE u.id IS NULL",
            'detail' => 'Community ride requests with no linked rider account.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=requests',
            'severity' => 'critical',
        ],
        [
            'tables' => ['rides', 'users'],
            'title' => 'Rides missing owners',
            'sql' => "SELECT COUNT(*) FROM rides r LEFT JOIN users u ON u.id = r.user_id WHERE u.id IS NULL",
            'detail' => 'Ride records that cannot be traced to a rider.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=rides',
            'severity' => 'critical',
        ],
        [
            'tables' => ['ride_live_status', 'rides'],
            'title' => 'Broken live ride links',
            'sql' => "SELECT COUNT(*) FROM ride_live_status ls LEFT JOIN rides r ON r.id = ls.ride_id WHERE r.id IS NULL",
            'detail' => 'Live status rows attached to deleted or missing rides.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=rides',
            'severity' => 'critical',
        ],
        [
            'tables' => ['driver_ride_requests', 'driver_accounts'],
            'title' => 'Direct requests missing driver',
            'sql' => "SELECT COUNT(*) FROM driver_ride_requests rr LEFT JOIN driver_accounts d ON d.id = rr.driver_id WHERE d.id IS NULL",
            'detail' => 'Driver requests with no valid driver account.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=requests',
            'severity' => 'critical',
        ],
        [
            'tables' => ['reports', 'users'],
            'title' => 'Reports missing reporter',
            'sql' => "SELECT COUNT(*) FROM reports rep LEFT JOIN users u ON u.id = rep.reporter_user_id WHERE u.id IS NULL",
            'detail' => 'Moderation records without a reporter identity.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=reports',
            'severity' => 'critical',
        ],
        [
            'tables' => ['rides'],
            'title' => 'Stale open rides',
            'sql' => "SELECT COUNT(*) FROM rides WHERE status = 'open' AND CONCAT(travel_date, ' ', travel_time) < NOW()",
            'detail' => 'Open rides whose departure time has already passed.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=bulk',
            'severity' => 'warning',
        ],
        [
            'tables' => ['matches', 'rides'],
            'title' => 'Pending requests on closed rides',
            'sql' => "SELECT COUNT(*) FROM matches m JOIN rides r ON r.id = m.ride_id WHERE m.status = 'pending' AND r.status <> 'open'",
            'detail' => 'Join requests still pending after the ride became unavailable.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=bulk',
            'severity' => 'warning',
        ],
        [
            'tables' => ['driver_account_documents'],
            'title' => 'Duplicate driver document types',
            'sql' => "SELECT COALESCE(SUM(dupe.total - 1), 0) FROM (SELECT driver_id, document_type, COUNT(*) AS total FROM driver_account_documents GROUP BY driver_id, document_type HAVING total > 1) dupe",
            'detail' => 'Drivers with more than one active record for the same document type.',
            'href' => '/ridesync/pages/admin_dashboard.php?section=drivers',
            'severity' => 'warning',
        ],
    ];

    foreach ($checks as $check) {
        $ready = true;
        foreach ($check['tables'] as $table) {
            if (!ridesync_table_exists($conn, $table)) {
                $ready = false;
                break;
            }
        }
        if (!$ready) {
            continue;
        }

        $count = ridesync_admin_ops_scalar($conn, $check['sql']);
        $issues[] = ridesync_admin_quality_issue(
            $check['title'],
            $count,
            $count > 0 ? $check['severity'] : 'healthy',
            $check['detail'],
            $check['href']
        );
    }

    if (count($issues) === 0) {
        $issues[] = ridesync_admin_quality_issue(
            'Data quality monitor unavailable',
            0,
            'info',
            'Core operational tables were not available for quality checks.',
            '/ridesync/pages/admin_dashboard.php?section=overview'
        );
    }

    return $issues;
}

function ridesync_admin_sla_timer($title, $count, $oldestAt, $thresholdHours, $detail, $href) {
    $oldestAgeHours = $oldestAt ? max(0, (int) floor((time() - (strtotime((string) $oldestAt) ?: time())) / 3600)) : 0;
    $severity = 'healthy';
    if ((int) $count > 0 && $oldestAgeHours >= ($thresholdHours * 2)) {
        $severity = 'critical';
    } elseif ((int) $count > 0 && $oldestAgeHours >= $thresholdHours) {
        $severity = 'warning';
    } elseif ((int) $count > 0) {
        $severity = 'info';
    }

    return [
        'title' => (string) $title,
        'count' => (int) $count,
        'oldest_at' => $oldestAt,
        'oldest_age_hours' => $oldestAgeHours,
        'threshold_hours' => (int) $thresholdHours,
        'severity' => $severity,
        'detail' => (string) $detail,
        'href' => (string) $href,
    ];
}

function ridesync_admin_sla_timers($conn) {
    $timers = [];

    if (ridesync_table_exists($conn, 'driver_account_profiles')) {
        $row = ridesync_admin_ops_rows(
            $conn,
            "SELECT COUNT(*) AS total, MIN(updated_at) AS oldest_at
             FROM driver_account_profiles
             WHERE verification_status = 'pending'"
        )[0] ?? [];
        $timers[] = ridesync_admin_sla_timer('Pending driver profiles', (int) ($row['total'] ?? 0), $row['oldest_at'] ?? null, 24, 'Driver profile reviews should be cleared within 24 hours.', '/ridesync/pages/admin_dashboard.php?section=drivers');
    }

    if (ridesync_table_exists($conn, 'driver_account_documents')) {
        $row = ridesync_admin_ops_rows(
            $conn,
            "SELECT COUNT(*) AS total, MIN(created_at) AS oldest_at
             FROM driver_account_documents
             WHERE verification_status = 'pending'"
        )[0] ?? [];
        $timers[] = ridesync_admin_sla_timer('Pending driver documents', (int) ($row['total'] ?? 0), $row['oldest_at'] ?? null, 24, 'Required document reviews should not sit longer than one business day.', '/ridesync/pages/admin_dashboard.php?section=drivers');
    }

    if (ridesync_table_exists($conn, 'user_verifications')) {
        $row = ridesync_admin_ops_rows(
            $conn,
            "SELECT COUNT(*) AS total, MIN(updated_at) AS oldest_at
             FROM user_verifications
             WHERE status = 'pending'"
        )[0] ?? [];
        $timers[] = ridesync_admin_sla_timer('Pending student verifications', (int) ($row['total'] ?? 0), $row['oldest_at'] ?? null, 24, 'Student verification requests should be resolved within 24 hours.', '/ridesync/pages/admin_dashboard.php?section=users');
    }

    if (ridesync_table_exists($conn, 'reports')) {
        $row = ridesync_admin_ops_rows(
            $conn,
            "SELECT COUNT(*) AS total, MIN(created_at) AS oldest_at
             FROM reports
             WHERE report_status IN ('open', 'reviewing')"
        )[0] ?? [];
        $timers[] = ridesync_admin_sla_timer('Unresolved reports', (int) ($row['total'] ?? 0), $row['oldest_at'] ?? null, 12, 'Trust and safety reports should be triaged the same day.', '/ridesync/pages/admin_dashboard.php?section=reports');
    }

    return $timers;
}

function ridesync_admin_backup_status($conn) {
    $root = dirname(__DIR__);
    $paths = [
        $root . '/storage/backups',
        $root . '/backups',
        $root . '/database/backups',
    ];
    $files = [];

    foreach ($paths as $path) {
        if (!is_dir($path)) {
            continue;
        }
        foreach (glob($path . '/*.{sql,sql.gz,zip}', GLOB_BRACE) ?: [] as $file) {
            if (is_file($file)) {
                $files[] = $file;
            }
        }
    }

    usort($files, static fn($a, $b) => filemtime($b) <=> filemtime($a));
    $latest = $files[0] ?? null;
    $latestTime = $latest ? filemtime($latest) : null;
    $ageHours = $latestTime ? (int) floor((time() - $latestTime) / 3600) : null;

    $schemaChecks = [
        ['table' => 'admin_notes', 'label' => 'admin_notes table'],
        ['table' => 'feature_flags', 'label' => 'feature_flags table'],
        ['table' => 'admin_alert_rules', 'label' => 'admin_alert_rules table'],
        ['table' => 'repair_kit_runs', 'label' => 'repair_kit_runs table'],
        ['table' => 'background_jobs', 'label' => 'background_jobs table'],
        ['table' => 'audit_logs', 'column' => 'source_ip', 'label' => 'audit source_ip column'],
        ['table' => 'rides', 'index' => 'idx_rides_search', 'label' => 'rides search index'],
        ['table' => 'driver_account_documents', 'index' => 'uq_driver_account_documents_type', 'label' => 'document uniqueness index'],
    ];
    $missing = [];

    foreach ($schemaChecks as $check) {
        $table = $check['table'];
        if (!ridesync_table_exists($conn, $table)) {
            $missing[] = $check['label'];
            continue;
        }
        if (!empty($check['column']) && !ridesync_column_exists($conn, $table, $check['column'])) {
            $missing[] = $check['label'];
        }
        if (!empty($check['index'])) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT 1
                 FROM information_schema.STATISTICS
                 WHERE TABLE_SCHEMA = DATABASE()
                   AND TABLE_NAME = ?
                   AND INDEX_NAME = ?
                 LIMIT 1"
            );
            if ($stmt) {
                mysqli_stmt_bind_param($stmt, 'ss', $table, $check['index']);
                mysqli_stmt_execute($stmt);
                if (mysqli_num_rows(mysqli_stmt_get_result($stmt)) === 0) {
                    $missing[] = $check['label'];
                }
            }
        }
    }

    $dbHealthy = $conn instanceof mysqli && mysqli_ping($conn);
    $severity = 'healthy';
    if (!$dbHealthy || count($missing) > 0 || $latest === null || ($ageHours !== null && $ageHours > 48)) {
        $severity = (!$dbHealthy || count($missing) > 2 || $latest === null) ? 'critical' : 'warning';
    }

    return [
        'severity' => $severity,
        'db_healthy' => $dbHealthy,
        'latest_file' => $latest ? basename($latest) : '',
        'latest_path' => $latest ?: '',
        'latest_at' => $latestTime ? date('Y-m-d H:i:s', $latestTime) : null,
        'age_hours' => $ageHours,
        'backup_count' => count($files),
        'missing_schema' => $missing,
    ];
}

function ridesync_admin_fraud_clusters($conn) {
    $clusters = [];

    if (ridesync_table_exists($conn, 'driver_accounts')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT phone AS cluster_key, COUNT(*) AS total, GROUP_CONCAT(id ORDER BY id SEPARATOR ',') AS ids
             FROM driver_accounts
             WHERE phone IS NOT NULL AND phone <> ''
             GROUP BY phone
             HAVING total > 1
             ORDER BY total DESC
             LIMIT 8"
        ) as $row) {
            $clusters[] = ridesync_admin_quality_issue('Shared driver phone', (int) $row['total'], 'warning', 'Phone ' . $row['cluster_key'] . ' appears on driver IDs ' . $row['ids'] . '.', '/ridesync/pages/admin_dashboard.php?section=drivers');
        }
    }

    if (ridesync_table_exists($conn, 'driver_account_profiles')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT license_number AS cluster_key, COUNT(*) AS total, GROUP_CONCAT(driver_id ORDER BY driver_id SEPARATOR ',') AS ids
             FROM driver_account_profiles
             WHERE license_number IS NOT NULL AND license_number <> ''
             GROUP BY license_number
             HAVING total > 1
             ORDER BY total DESC
             LIMIT 8"
        ) as $row) {
            $clusters[] = ridesync_admin_quality_issue('Duplicate license number', (int) $row['total'], 'critical', 'License ' . $row['cluster_key'] . ' appears on driver IDs ' . $row['ids'] . '.', '/ridesync/pages/admin_dashboard.php?section=drivers');
        }
    }

    if (ridesync_table_exists($conn, 'driver_account_vehicles')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT vehicle_number AS cluster_key, COUNT(*) AS total, GROUP_CONCAT(driver_id ORDER BY driver_id SEPARATOR ',') AS ids
             FROM driver_account_vehicles
             WHERE vehicle_number IS NOT NULL AND vehicle_number <> ''
             GROUP BY vehicle_number
             HAVING total > 1
             ORDER BY total DESC
             LIMIT 8"
        ) as $row) {
            $clusters[] = ridesync_admin_quality_issue('Duplicate vehicle number', (int) $row['total'], 'critical', 'Vehicle ' . $row['cluster_key'] . ' appears on driver IDs ' . $row['ids'] . '.', '/ridesync/pages/admin_dashboard.php?section=drivers');
        }
    }

    if (ridesync_table_exists($conn, 'driver_account_documents')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT document_reference AS cluster_key, COUNT(*) AS total, GROUP_CONCAT(driver_id ORDER BY driver_id SEPARATOR ',') AS ids
             FROM driver_account_documents
             WHERE document_reference IS NOT NULL AND document_reference <> ''
             GROUP BY document_reference
             HAVING total > 1
             ORDER BY total DESC
             LIMIT 8"
        ) as $row) {
            $clusters[] = ridesync_admin_quality_issue('Repeated document reference', (int) $row['total'], 'critical', 'Document reference is reused by driver IDs ' . $row['ids'] . '.', '/ridesync/pages/admin_dashboard.php?section=drivers');
        }
    }

    if (ridesync_table_exists($conn, 'route_demand_signals')) {
        foreach (ridesync_admin_ops_rows($conn,
            "SELECT user_id AS cluster_key, COUNT(*) AS total, MIN(origin) AS origin, MIN(destination) AS destination
             FROM route_demand_signals
             WHERE created_at >= DATE_SUB(NOW(), INTERVAL 24 HOUR)
             GROUP BY user_id, route_key
             HAVING total >= 5
             ORDER BY total DESC
             LIMIT 8"
        ) as $row) {
            $clusters[] = ridesync_admin_quality_issue('Repeated route demand', (int) $row['total'], 'warning', 'User #' . (int) $row['cluster_key'] . ' repeatedly requested ' . $row['origin'] . ' to ' . $row['destination'] . ' in 24h.', '/ridesync/pages/admin_dashboard.php?section=users&q=' . urlencode((string) $row['cluster_key']));
        }
    }

    if (count($clusters) === 0) {
        $clusters[] = ridesync_admin_quality_issue('No fraud clusters detected', 0, 'healthy', 'Shared identity, document, vehicle, and route-pattern checks found no clusters.', '/ridesync/pages/admin_dashboard.php?section=overview');
    }

    return array_slice($clusters, 0, 16);
}

function ridesync_admin_record_label($value) {
    return function_exists('ridesync_admin_status_label')
        ? ridesync_admin_status_label($value)
        : ucwords(str_replace('_', ' ', (string) $value));
}

function ridesync_admin_record_factor($severity, $title, $detail, $points) {
    return [
        'severity' => in_array($severity, ['critical', 'warning', 'info', 'healthy'], true) ? $severity : 'info',
        'title' => substr(trim((string) $title), 0, 120),
        'detail' => substr(trim((string) $detail), 0, 220),
        'points' => max(0, min(60, (int) $points)),
    ];
}

function ridesync_admin_record_health_from_factors($factors) {
    $factors = array_values(array_filter((array) $factors, static fn($factor) => is_array($factor) && trim((string) ($factor['title'] ?? '')) !== ''));
    if (count($factors) === 0) {
        $factors[] = ridesync_admin_record_factor('healthy', 'No active risk signals', 'This record has no currently detected operational risk factors.', 0);
    }

    $penalty = 0;
    $hasCritical = false;
    $hasWarning = false;
    foreach ($factors as $factor) {
        $penalty += (int) ($factor['points'] ?? 0);
        $hasCritical = $hasCritical || (($factor['severity'] ?? '') === 'critical');
        $hasWarning = $hasWarning || (($factor['severity'] ?? '') === 'warning');
    }

    $score = max(0, min(100, 100 - $penalty));
    $severity = 'healthy';
    $label = 'Healthy';
    if ($score < 50 || $hasCritical) {
        $severity = 'critical';
        $label = 'Critical';
    } elseif ($score < 75 || $hasWarning) {
        $severity = 'warning';
        $label = 'Needs attention';
    } elseif ($score < 90) {
        $severity = 'info';
        $label = 'Watch';
    }

    return [
        'score' => $score,
        'label' => $label,
        'severity' => $severity,
        'factors' => array_slice($factors, 0, 8),
    ];
}

function ridesync_admin_record_health_score($conn, $entityType, $entityId, $context = []) {
    $entityType = ridesync_admin_normalize_note_entity($entityType);
    $entityId = (int) $entityId;
    $factors = [];

    if ($entityType === '' || $entityId <= 0 || !$conn instanceof mysqli) {
        return ridesync_admin_record_health_from_factors([
            ridesync_admin_record_factor('critical', 'Record unavailable', 'The requested record could not be evaluated.', 40),
        ]);
    }

    if ($entityType === 'user') {
        $user = $context['user'] ?? [];
        $stats = $context['stats'] ?? [];
        if (empty($user) && ridesync_admin_ops_table_ready($conn, 'users', ['id'])) {
            $user = ridesync_admin_ops_prepared_rows($conn, "SELECT * FROM users WHERE id = ? LIMIT 1", 'i', [$entityId])[0] ?? [];
        }

        $verificationStatus = (string) ($user['verification_status'] ?? '');
        if ($verificationStatus !== '' && !in_array($verificationStatus, ['verified', 'approved'], true)) {
            $factors[] = ridesync_admin_record_factor('warning', 'Student verification incomplete', 'Latest verification status is ' . ridesync_admin_record_label($verificationStatus) . '.', 12);
        }

        $reportsAgainst = (int) ($stats['reports_against'] ?? 0);
        if ($reportsAgainst === 0 && ridesync_table_exists($conn, 'reports')) {
            $reportsAgainst = ridesync_admin_ops_prepared_rows($conn, "SELECT COUNT(*) AS total FROM reports WHERE reported_user_id = ?", 'i', [$entityId])[0]['total'] ?? 0;
        }
        if ($reportsAgainst > 0) {
            $factors[] = ridesync_admin_record_factor($reportsAgainst >= 3 ? 'critical' : 'warning', 'Reports against user', $reportsAgainst . ' report(s) reference this user as the reported party.', min(36, $reportsAgainst * 12));
        }

        $pendingDue = (float) ($stats['pending_due'] ?? 0);
        if ($pendingDue > 0) {
            $factors[] = ridesync_admin_record_factor('warning', 'Outstanding fare due', 'Wallet ledger shows Rs ' . number_format($pendingDue, 0) . ' still pending.', min(18, 8 + (int) floor($pendingDue / 250)));
        }

        if (ridesync_table_exists($conn, 'rides')) {
            $staleRides = (int) (ridesync_admin_ops_prepared_rows(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM rides
                 WHERE user_id = ?
                   AND status = 'open'
                   AND CONCAT(travel_date, ' ', travel_time) < NOW()",
                'i',
                [$entityId]
            )[0]['total'] ?? 0);
            if ($staleRides > 0) {
                $factors[] = ridesync_admin_record_factor('warning', 'Stale open rides', $staleRides . ' open ride(s) already passed departure time.', min(24, $staleRides * 8));
            }
        }

        $pendingIncoming = (int) ($stats['pending_incoming_requests'] ?? 0);
        if ($pendingIncoming >= 5) {
            $factors[] = ridesync_admin_record_factor('info', 'Large incoming request queue', $pendingIncoming . ' riders are waiting on this user to act.', 6);
        }

        if (!empty($user['linked_driver_status']) && $user['linked_driver_status'] !== 'active') {
            $factors[] = ridesync_admin_record_factor('warning', 'Linked driver account is not active', 'Linked driver status is ' . ridesync_admin_record_label($user['linked_driver_status']) . '.', 12);
        }
    } elseif ($entityType === 'ride') {
        $ride = $context['ride'] ?? [];
        if (empty($ride) && ridesync_admin_ops_table_ready($conn, 'rides', ['id'])) {
            $ride = ridesync_admin_ops_prepared_rows($conn, "SELECT * FROM rides WHERE id = ? LIMIT 1", 'i', [$entityId])[0] ?? [];
        }

        if (!empty($ride)) {
            $departure = strtotime((string) ($ride['travel_date'] ?? '') . ' ' . (string) ($ride['travel_time'] ?? ''));
            if (($ride['status'] ?? '') === 'open' && $departure && $departure < time()) {
                $factors[] = ridesync_admin_record_factor('critical', 'Ride is stale but open', 'Departure time has passed while the ride still accepts activity.', 28);
            }
            if (($ride['status'] ?? '') !== 'open' && !empty($context['matches'])) {
                $pendingOnClosed = 0;
                foreach ((array) $context['matches'] as $match) {
                    if (($match['status'] ?? '') === 'pending') {
                        $pendingOnClosed++;
                    }
                }
                if ($pendingOnClosed > 0) {
                    $factors[] = ridesync_admin_record_factor('warning', 'Pending requests on unavailable ride', $pendingOnClosed . ' request(s) are still pending after the ride left open state.', 14);
                }
            }
        }

        $openReports = 0;
        $criticalReports = 0;
        foreach ((array) ($context['reports'] ?? []) as $report) {
            if (in_array((string) ($report['report_status'] ?? ''), ['open', 'reviewing'], true)) {
                $openReports++;
                if (in_array((string) ($report['reason'] ?? ''), ['safety', 'misconduct', 'fake_profile'], true)) {
                    $criticalReports++;
                }
            }
        }
        if ($openReports === 0 && ridesync_table_exists($conn, 'reports')) {
            $row = ridesync_admin_ops_prepared_rows(
                $conn,
                "SELECT COUNT(*) AS total,
                        SUM(CASE WHEN reason IN ('safety', 'misconduct', 'fake_profile') THEN 1 ELSE 0 END) AS critical_total
                 FROM reports
                 WHERE ride_id = ? AND report_status IN ('open', 'reviewing')",
                'i',
                [$entityId]
            )[0] ?? [];
            $openReports = (int) ($row['total'] ?? 0);
            $criticalReports = (int) ($row['critical_total'] ?? 0);
        }
        if ($openReports > 0) {
            $factors[] = ridesync_admin_record_factor($criticalReports > 0 ? 'critical' : 'warning', 'Unresolved ride reports', $openReports . ' open or reviewing report(s) are attached to this ride.', min(42, 16 + ($criticalReports * 14) + ($openReports * 6)));
        }

        if (ridesync_table_exists($conn, 'ride_live_status')) {
            $live = ridesync_admin_ops_prepared_rows($conn, "SELECT live_status, driver_id, updated_at FROM ride_live_status WHERE ride_id = ? LIMIT 1", 'i', [$entityId])[0] ?? null;
            if (!$live) {
                $factors[] = ridesync_admin_record_factor('info', 'No live status row', 'The ride has no canonical live-status record yet.', 8);
            } elseif (in_array((string) ($live['live_status'] ?? ''), ['driver_assigned', 'arriving', 'active'], true) && empty($live['driver_id'])) {
                $factors[] = ridesync_admin_record_factor('warning', 'Live status missing driver', 'Ride is in ' . ridesync_admin_record_label($live['live_status']) . ' state without an assigned driver.', 16);
            }
        }
    } elseif ($entityType === 'driver') {
        $driver = $context['driver'] ?? [];
        if (empty($driver) && ridesync_table_exists($conn, 'driver_accounts')) {
            $driver = ridesync_admin_ops_prepared_rows($conn, "SELECT id AS driver_id, status AS driver_status FROM driver_accounts WHERE id = ? LIMIT 1", 'i', [$entityId])[0] ?? [];
        }

        if (!empty($driver['driver_status']) && $driver['driver_status'] !== 'active') {
            $factors[] = ridesync_admin_record_factor('critical', 'Driver account is not active', 'Driver account status is ' . ridesync_admin_record_label($driver['driver_status']) . '.', 28);
        }
        if (!empty($driver['profile_verification_status']) && $driver['profile_verification_status'] !== 'verified') {
            $factors[] = ridesync_admin_record_factor('warning', 'Profile not verified', 'Driver profile status is ' . ridesync_admin_record_label($driver['profile_verification_status']) . '.', 18);
        }

        $requiredSummary = $context['required_document_summary'] ?? [];
        if (isset($requiredSummary['submitted'], $requiredSummary['verified'])) {
            $missing = max(0, 4 - (int) $requiredSummary['submitted']);
            $unverified = max(0, 4 - (int) $requiredSummary['verified']);
            if ($missing > 0) {
                $factors[] = ridesync_admin_record_factor('warning', 'Required documents missing', $missing . ' required document type(s) are not submitted.', min(24, $missing * 6));
            } elseif ($unverified > 0) {
                $factors[] = ridesync_admin_record_factor('warning', 'Required documents unverified', $unverified . ' required document type(s) still need verification.', min(20, $unverified * 5));
            }
        } elseif (ridesync_table_exists($conn, 'driver_account_documents')) {
            $row = ridesync_admin_ops_prepared_rows(
                $conn,
                "SELECT
                    SUM(CASE WHEN document_type IN ('license', 'aadhaar', 'pan', 'vehicle_rc') THEN 1 ELSE 0 END) AS submitted,
                    SUM(CASE WHEN document_type IN ('license', 'aadhaar', 'pan', 'vehicle_rc') AND verification_status = 'verified' THEN 1 ELSE 0 END) AS verified,
                    SUM(CASE WHEN verification_status = 'rejected' THEN 1 ELSE 0 END) AS rejected
                 FROM driver_account_documents
                 WHERE driver_id = ?",
                'i',
                [$entityId]
            )[0] ?? [];
            $missing = max(0, 4 - (int) ($row['submitted'] ?? 0));
            $rejected = (int) ($row['rejected'] ?? 0);
            if ($missing > 0) {
                $factors[] = ridesync_admin_record_factor('warning', 'Required documents missing', $missing . ' required document type(s) are not submitted.', min(24, $missing * 6));
            }
            if ($rejected > 0) {
                $factors[] = ridesync_admin_record_factor('critical', 'Rejected documents present', $rejected . ' document(s) are rejected.', min(30, $rejected * 10));
            }
        }

        $verificationSession = $context['verification_session'] ?? null;
        if (is_array($verificationSession) && count($verificationSession) > 0) {
            $risk = (string) ($verificationSession['risk_level'] ?? 'medium');
            $status = (string) ($verificationSession['status'] ?? '');
            if (in_array($risk, ['high', 'critical'], true) || in_array($status, ['failed', 'fake_tampered'], true)) {
                $factors[] = ridesync_admin_record_factor($risk === 'critical' || $status === 'fake_tampered' ? 'critical' : 'warning', 'AI verification risk', 'Latest AI session is ' . ridesync_admin_record_label($status) . ' with ' . ridesync_admin_record_label($risk) . ' risk.', $risk === 'critical' ? 32 : 20);
            }
        }

        $fraudFlags = (array) ($context['fraud_flags'] ?? []);
        $highFlags = 0;
        foreach ($fraudFlags as $flag) {
            if (in_array((string) ($flag['severity'] ?? ''), ['high', 'critical'], true)) {
                $highFlags++;
            }
        }
        if ($highFlags > 0) {
            $factors[] = ridesync_admin_record_factor('critical', 'Fraud flags present', $highFlags . ' high or critical fraud flag(s) need review.', min(36, $highFlags * 12));
        }
    } elseif ($entityType === 'report') {
        if (ridesync_table_exists($conn, 'reports')) {
            $report = ridesync_admin_ops_prepared_rows($conn, "SELECT * FROM reports WHERE id = ? LIMIT 1", 'i', [$entityId])[0] ?? [];
            if (!$report) {
                $factors[] = ridesync_admin_record_factor('critical', 'Report not found', 'No report row exists for this id.', 40);
            } else {
                if (in_array((string) ($report['report_status'] ?? ''), ['open', 'reviewing'], true)) {
                    $ageHours = max(0, (int) floor((time() - (strtotime((string) ($report['created_at'] ?? '')) ?: time())) / 3600));
                    $severity = $ageHours >= 24 || in_array((string) ($report['reason'] ?? ''), ['safety', 'misconduct', 'fake_profile'], true) ? 'critical' : 'warning';
                    $factors[] = ridesync_admin_record_factor($severity, 'Report unresolved', 'Report has been open for ' . $ageHours . ' hour(s).', min(40, 12 + (int) floor($ageHours / 4)));
                }
            }
        }
    }

    return ridesync_admin_record_health_from_factors($factors);
}

function ridesync_admin_record_timeline($conn, $entityType, $entityId, $limit = 12) {
    $entityType = ridesync_admin_normalize_note_entity($entityType);
    $entityId = (int) $entityId;
    $limit = max(4, min(30, (int) $limit));
    $items = [];

    if ($entityType === '' || $entityId <= 0 || !$conn instanceof mysqli) {
        return [];
    }

    $href = [
        'user' => '/ridesync/pages/admin_user_detail.php?user_id=' . $entityId,
        'driver' => '/ridesync/pages/admin_driver_verification.php?driver_id=' . $entityId,
        'ride' => '/ridesync/pages/admin_ride_detail.php?id=' . $entityId,
        'report' => '/ridesync/pages/admin_dashboard.php?section=reports&q=' . $entityId,
    ][$entityType] ?? '/ridesync/pages/admin_dashboard.php';

    if (ridesync_table_exists($conn, 'audit_logs')) {
        foreach (ridesync_admin_ops_prepared_rows(
            $conn,
            "SELECT al.action, al.message, al.created_at, au.name AS admin_name
             FROM audit_logs al
             LEFT JOIN admin_users au ON au.id = al.admin_id
             WHERE al.entity_type = ? AND al.entity_id = ?
             ORDER BY al.created_at DESC, al.id DESC
             LIMIT 8",
            'si',
            [$entityType, $entityId]
        ) as $row) {
            $items[] = ridesync_admin_ops_item('Audit', 'info', ridesync_admin_record_label($row['action'] ?? 'Audit event'), (string) ($row['message'] ?: 'Admin audit event recorded.'), $row['created_at'], $href, $row['admin_name'] ?: 'System');
        }
    }

    foreach (ridesync_admin_fetch_notes($conn, $entityType, $entityId, 6) as $note) {
        $noteType = (string) ($note['note_type'] ?? 'general');
        $items[] = ridesync_admin_ops_item(
            'Admin Note',
            $noteType === 'risk' ? 'warning' : 'info',
            ridesync_admin_record_label($noteType) . ' note',
            (string) ($note['note_text'] ?? ''),
            $note['created_at'] ?? '',
            $href,
            $note['admin_name'] ?: 'Admin'
        );
    }

    if ($entityType === 'user') {
        if (ridesync_table_exists($conn, 'rides')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT id, origin, destination, status, created_at FROM rides WHERE user_id = ? ORDER BY created_at DESC LIMIT 6", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Ride', $row['status'] === 'open' ? 'info' : 'healthy', 'Posted ride #' . (int) $row['id'], (string) $row['origin'] . ' to ' . (string) $row['destination'], $row['created_at'], '/ridesync/pages/admin_ride_detail.php?id=' . (int) $row['id'], ridesync_admin_record_label($row['status']));
            }
        }
        if (ridesync_table_exists($conn, 'matches') && ridesync_table_exists($conn, 'rides')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT m.ride_id, m.status, m.created_at, r.origin, r.destination FROM matches m JOIN rides r ON r.id = m.ride_id WHERE m.matched_user_id = ? ORDER BY m.created_at DESC LIMIT 6", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Join Request', $row['status'] === 'pending' ? 'warning' : 'info', 'Requested ride #' . (int) $row['ride_id'], (string) $row['origin'] . ' to ' . (string) $row['destination'], $row['created_at'], '/ridesync/pages/admin_ride_detail.php?id=' . (int) $row['ride_id'], ridesync_admin_record_label($row['status']));
            }
        }
        if (ridesync_table_exists($conn, 'reports')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT id, reason, report_status, created_at FROM reports WHERE reporter_user_id = ? OR reported_user_id = ? ORDER BY created_at DESC LIMIT 6", 'ii', [$entityId, $entityId]) as $row) {
                $isCritical = in_array((string) $row['reason'], ['safety', 'misconduct', 'fake_profile'], true) && in_array((string) $row['report_status'], ['open', 'reviewing'], true);
                $items[] = ridesync_admin_ops_item('Report', $isCritical ? 'critical' : 'warning', 'Report #' . (int) $row['id'], ridesync_admin_record_label($row['reason']) . ' - ' . ridesync_admin_record_label($row['report_status']), $row['created_at'], '/ridesync/pages/admin_dashboard.php?section=reports&q=' . (int) $row['id'], 'Trust');
            }
        }
        if (ridesync_table_exists($conn, 'notifications')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT title, message, is_read, created_at FROM notifications WHERE user_id = ? ORDER BY created_at DESC LIMIT 4", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Notification', (int) $row['is_read'] === 1 ? 'healthy' : 'info', (string) $row['title'], (string) $row['message'], $row['created_at'], $href, (int) $row['is_read'] === 1 ? 'Read' : 'Unread');
            }
        }
    } elseif ($entityType === 'ride') {
        if (ridesync_table_exists($conn, 'ride_live_status')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT live_status, eta_minutes, note, updated_at FROM ride_live_status WHERE ride_id = ? LIMIT 1", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Live Status', 'info', ridesync_admin_record_label($row['live_status']), (string) ($row['note'] ?: 'ETA ' . ((int) ($row['eta_minutes'] ?? 0)) . ' min'), $row['updated_at'], $href, 'Ride lifecycle');
            }
        }
        if (ridesync_table_exists($conn, 'matches') && ridesync_table_exists($conn, 'users')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT m.status, m.created_at, u.name FROM matches m JOIN users u ON u.id = m.matched_user_id WHERE m.ride_id = ? ORDER BY m.created_at DESC LIMIT 8", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Join Request', $row['status'] === 'pending' ? 'warning' : 'info', (string) $row['name'] . ' requested this ride', 'Request is ' . ridesync_admin_record_label($row['status']) . '.', $row['created_at'], $href, 'Community');
            }
        }
        if (ridesync_table_exists($conn, 'reports')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT id, reason, report_status, created_at FROM reports WHERE ride_id = ? ORDER BY created_at DESC LIMIT 6", 'i', [$entityId]) as $row) {
                $isCritical = in_array((string) $row['reason'], ['safety', 'misconduct', 'fake_profile'], true) && in_array((string) $row['report_status'], ['open', 'reviewing'], true);
                $items[] = ridesync_admin_ops_item('Report', $isCritical ? 'critical' : 'warning', 'Report #' . (int) $row['id'], ridesync_admin_record_label($row['reason']) . ' - ' . ridesync_admin_record_label($row['report_status']), $row['created_at'], '/ridesync/pages/admin_dashboard.php?section=reports&q=' . (int) $row['id'], 'Trust');
            }
        }
        if (ridesync_table_exists($conn, 'ride_tracking')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT eta_minutes, recorded_at FROM ride_tracking WHERE ride_id = ? ORDER BY recorded_at DESC LIMIT 4", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Tracking', 'healthy', 'Driver position recorded', $row['eta_minutes'] !== null ? 'ETA ' . (int) $row['eta_minutes'] . ' minutes.' : 'Location ping stored.', $row['recorded_at'], $href, 'Telemetry');
            }
        }
    } elseif ($entityType === 'driver') {
        if (ridesync_table_exists($conn, 'driver_account_documents')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT document_type, verification_status, created_at FROM driver_account_documents WHERE driver_id = ? ORDER BY created_at DESC LIMIT 8", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Document', $row['verification_status'] === 'rejected' ? 'critical' : ($row['verification_status'] === 'pending' ? 'warning' : 'healthy'), ridesync_admin_record_label($row['document_type']) . ' document', 'Document status is ' . ridesync_admin_record_label($row['verification_status']) . '.', $row['created_at'], $href, 'KYC');
            }
        }
        if (ridesync_table_exists($conn, 'driver_verification_sessions')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT id, status, risk_level, confidence_score, updated_at FROM driver_verification_sessions WHERE driver_id = ? ORDER BY updated_at DESC, id DESC LIMIT 6", 'i', [$entityId]) as $row) {
                $isRisky = in_array((string) $row['risk_level'], ['high', 'critical'], true) || in_array((string) $row['status'], ['failed', 'fake_tampered'], true);
                $items[] = ridesync_admin_ops_item('AI Session', $isRisky ? 'critical' : 'info', 'AI verification session #' . (int) $row['id'], ridesync_admin_record_label($row['status']) . ', ' . ridesync_admin_record_label($row['risk_level']) . ' risk, score ' . number_format((float) $row['confidence_score'], 1), $row['updated_at'], $href, 'AI');
            }
        }
        if (ridesync_table_exists($conn, 'driver_ride_requests')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT id, pickup, drop_location, request_status, requested_at FROM driver_ride_requests WHERE driver_id = ? ORDER BY requested_at DESC LIMIT 5", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Direct Request', $row['request_status'] === 'pending' ? 'warning' : 'info', 'Direct request #' . (int) $row['id'], (string) $row['pickup'] . ' to ' . (string) $row['drop_location'], $row['requested_at'], '/ridesync/pages/admin_dashboard.php?section=requests&q=' . (int) $row['id'], ridesync_admin_record_label($row['request_status']));
            }
        }
        if (ridesync_table_exists($conn, 'driver_ride_history')) {
            foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT pickup, drop_location, fare, completed_at FROM driver_ride_history WHERE driver_id = ? ORDER BY completed_at DESC LIMIT 4", 'i', [$entityId]) as $row) {
                $items[] = ridesync_admin_ops_item('Completed Ride', 'healthy', (string) $row['pickup'] . ' to ' . (string) $row['drop_location'], 'Fare Rs ' . number_format((float) $row['fare'], 0), $row['completed_at'], $href, 'Earnings');
            }
        }
    } elseif ($entityType === 'report' && ridesync_table_exists($conn, 'reports')) {
        foreach (ridesync_admin_ops_prepared_rows($conn, "SELECT reason, report_status, message, created_at, updated_at FROM reports WHERE id = ? LIMIT 1", 'i', [$entityId]) as $row) {
            $items[] = ridesync_admin_ops_item('Report', in_array((string) $row['report_status'], ['open', 'reviewing'], true) ? 'warning' : 'healthy', ridesync_admin_record_label($row['reason']), (string) $row['message'], $row['created_at'], $href, ridesync_admin_record_label($row['report_status']));
            if (!empty($row['updated_at']) && $row['updated_at'] !== $row['created_at']) {
                $items[] = ridesync_admin_ops_item('Report Update', 'info', 'Report status updated', 'Latest status is ' . ridesync_admin_record_label($row['report_status']) . '.', $row['updated_at'], $href, 'Moderation');
            }
        }
    }

    usort($items, static function ($left, $right) {
        $leftTime = strtotime((string) ($left['created_at'] ?? '')) ?: 0;
        $rightTime = strtotime((string) ($right['created_at'] ?? '')) ?: 0;
        if ($leftTime === $rightTime) {
            return ridesync_admin_ops_severity_rank($right['severity'] ?? '') <=> ridesync_admin_ops_severity_rank($left['severity'] ?? '');
        }
        return $rightTime <=> $leftTime;
    });

    if (count($items) === 0) {
        $items[] = ridesync_admin_ops_item('Timeline', 'healthy', 'No activity yet', 'No notes, audit events, or related operational records are available for this record.', date('Y-m-d H:i:s'), $href, 'Record');
    }

    return array_slice($items, 0, $limit);
}

?>
