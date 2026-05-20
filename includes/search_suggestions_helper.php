<?php
require_once __DIR__ . '/matching_helper.php';

function ridesync_search_query($query) {
    $query = preg_replace('/\s+/', ' ', trim((string) $query));
    return substr($query, 0, 120);
}

function ridesync_search_like($query) {
    return '%' . ridesync_search_query($query) . '%';
}

function ridesync_search_rows($conn, $sql, $types = '', $params = []) {
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
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }

    if (!mysqli_stmt_execute($stmt)) {
        return [];
    }

    $rows = [];
    $result = mysqli_stmt_get_result($stmt);
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }

    return $rows;
}

function ridesync_search_score($haystack, $query) {
    $haystack = strtolower(trim((string) $haystack));
    $query = strtolower(ridesync_search_query($query));
    if ($query === '' || $haystack === '') {
        return 0;
    }

    if ($haystack === $query) {
        return 1000;
    }
    if (strpos($haystack, $query) === 0) {
        return 850;
    }
    if (strpos($haystack, $query) !== false) {
        return 640;
    }

    $score = 0;
    foreach (preg_split('/\s+/', $query) as $token) {
        if (strlen($token) < 2) {
            continue;
        }
        if (strpos($haystack, $token) !== false) {
            $score += 120;
        }
    }

    return $score;
}

function ridesync_search_item($context, $category, $label, $value = null, $meta = '', $url = null, $score = 0) {
    $label = trim((string) $label);
    if ($label === '') {
        return null;
    }

    return [
        'context' => $context,
        'category' => $category,
        'label' => substr($label, 0, 160),
        'value' => substr(trim((string) ($value ?? $label)), 0, 160),
        'meta' => substr(trim((string) $meta), 0, 180),
        'url' => $url,
        'score' => (int) $score,
        'source' => 'database',
    ];
}

function ridesync_search_finalize($items, $query, $limit) {
    $query = ridesync_search_query($query);
    $limit = max(1, min(20, (int) $limit));
    $deduped = [];
    $seen = [];

    foreach ($items as $item) {
        if (!is_array($item) || trim((string) ($item['label'] ?? '')) === '') {
            continue;
        }

        $key = strtolower(($item['context'] ?? '') . '|' . ($item['category'] ?? '') . '|' . ($item['label'] ?? '') . '|' . ($item['url'] ?? ''));
        if (isset($seen[$key])) {
            continue;
        }

        $item['score'] = max((int) ($item['score'] ?? 0), ridesync_search_score(($item['label'] ?? '') . ' ' . ($item['meta'] ?? '') . ' ' . ($item['value'] ?? ''), $query));
        if ($query !== '' && $item['score'] <= 0) {
            continue;
        }

        $seen[$key] = true;
        $deduped[] = $item;
    }

    usort($deduped, static function ($left, $right) {
        if ((int) $left['score'] === (int) $right['score']) {
            return strcasecmp((string) $left['label'], (string) $right['label']);
        }
        return (int) $right['score'] <=> (int) $left['score'];
    });

    return array_slice($deduped, 0, $limit);
}

function ridesync_admin_suggestion_contexts() {
    return [
        'admin_global',
        'driversTable',
        'usersTable',
        'ridesTable',
        'directRequestsTable',
        'joinRequestsTable',
        'reportsPanel',
        'removeTable',
        'auditTable',
    ];
}

function ridesync_admin_driver_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'driver_accounts')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $rows = ridesync_search_rows($conn,
        "SELECT d.id, d.name, d.email, d.phone, d.status,
                p.license_number,
                v.vehicle_number
         FROM driver_accounts d
         LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
         LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
         WHERE d.name LIKE ? OR d.email LIKE ? OR d.phone LIKE ? OR d.status LIKE ?
            OR p.license_number LIKE ? OR v.vehicle_number LIKE ?
         ORDER BY d.created_at DESC
         LIMIT " . (int) $limit,
        'ssssss',
        [$like, $like, $like, $like, $like, $like]
    );

    $items = [];
    foreach ($rows as $row) {
        $meta = trim(($row['email'] ?? '') . ' ' . ($row['vehicle_number'] ? 'Vehicle ' . $row['vehicle_number'] : '') . ' ' . ($row['status'] ?? ''));
        $items[] = ridesync_search_item('driversTable', 'Driver', $row['name'], $row['name'], $meta, '/ridesync/pages/admin_driver_verification.php?driver_id=' . (int) $row['id']);
        if (!empty($row['vehicle_number'])) {
            $items[] = ridesync_search_item('driversTable', 'Vehicle', $row['vehicle_number'], $row['vehicle_number'], $row['name']);
        }
        if (!empty($row['license_number'])) {
            $items[] = ridesync_search_item('driversTable', 'License', $row['license_number'], $row['license_number'], $row['name']);
        }
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_user_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'users')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $rows = ridesync_search_rows($conn,
        "SELECT id, name, email, college
         FROM users
         WHERE name LIKE ? OR email LIKE ? OR college LIKE ?
         ORDER BY created_at DESC
         LIMIT " . (int) $limit,
        'sss',
        [$like, $like, $like]
    );

    $items = [];
    foreach ($rows as $row) {
        $items[] = ridesync_search_item('usersTable', 'User', $row['name'], $row['name'], ($row['email'] ?? '') . ' - ' . ($row['college'] ?? ''), '/ridesync/pages/admin_user_detail.php?user_id=' . (int) $row['id']);
        if (!empty($row['college'])) {
            $items[] = ridesync_search_item('usersTable', 'College', $row['college'], $row['college'], 'Student community');
        }
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_ride_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'rides') || !ridesync_table_exists($conn, 'users')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $id = ctype_digit($query) ? (int) $query : -1;
    $rows = ridesync_search_rows($conn,
        "SELECT r.id, r.origin, r.destination, r.status, r.travel_date, u.name AS owner_name
         FROM rides r
         JOIN users u ON u.id = r.user_id
         WHERE r.id = ? OR r.origin LIKE ? OR r.destination LIKE ? OR r.status LIKE ? OR u.name LIKE ?
         ORDER BY r.created_at DESC
         LIMIT " . (int) $limit,
        'issss',
        [$id, $like, $like, $like, $like]
    );

    $items = [];
    foreach ($rows as $row) {
        $route = '#' . (int) $row['id'] . ' ' . $row['origin'] . ' -> ' . $row['destination'];
        $items[] = ridesync_search_item('ridesTable', 'Ride', $route, $row['origin'] . ' ' . $row['destination'], ($row['owner_name'] ?? '') . ' - ' . ($row['status'] ?? ''), '/ridesync/pages/admin_ride_detail.php?id=' . (int) $row['id']);
        $items[] = ridesync_search_item('ridesTable', 'Route', $row['origin'] . ' -> ' . $row['destination'], $row['origin'] . ' ' . $row['destination'], $row['travel_date'] ?? '');
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_direct_request_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'driver_ride_requests') || !ridesync_table_exists($conn, 'driver_accounts')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $id = ctype_digit($query) ? (int) $query : -1;
    $rows = ridesync_search_rows($conn,
        "SELECT rr.id, rr.pickup, rr.drop_location, rr.request_status,
                d.name AS driver_name,
                u.name AS rider_name
         FROM driver_ride_requests rr
         JOIN driver_accounts d ON d.id = rr.driver_id
         LEFT JOIN users u ON u.id = rr.rider_user_id
         WHERE rr.id = ? OR rr.pickup LIKE ? OR rr.drop_location LIKE ? OR rr.request_status LIKE ?
            OR d.name LIKE ? OR u.name LIKE ?
         ORDER BY rr.requested_at DESC
         LIMIT " . (int) $limit,
        'isssss',
        [$id, $like, $like, $like, $like, $like]
    );

    $items = [];
    foreach ($rows as $row) {
        $route = '#' . (int) $row['id'] . ' ' . $row['pickup'] . ' -> ' . $row['drop_location'];
        $items[] = ridesync_search_item('directRequestsTable', 'Direct Request', $route, $row['pickup'] . ' ' . $row['drop_location'], ($row['rider_name'] ?? 'Guest') . ' / ' . ($row['driver_name'] ?? 'Driver') . ' - ' . ($row['request_status'] ?? ''));
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_join_request_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'matches') || !ridesync_table_exists($conn, 'rides') || !ridesync_table_exists($conn, 'users')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $id = ctype_digit($query) ? (int) $query : -1;
    $rows = ridesync_search_rows($conn,
        "SELECT m.id, m.ride_id, m.status, r.origin, r.destination,
                requester.name AS requester_name,
                owner.name AS owner_name
         FROM matches m
         JOIN rides r ON r.id = m.ride_id
         JOIN users requester ON requester.id = m.matched_user_id
         JOIN users owner ON owner.id = r.user_id
         WHERE m.id = ? OR m.ride_id = ? OR m.status LIKE ? OR r.origin LIKE ? OR r.destination LIKE ?
            OR requester.name LIKE ? OR owner.name LIKE ?
         ORDER BY m.created_at DESC
         LIMIT " . (int) $limit,
        'iisssss',
        [$id, $id, $like, $like, $like, $like, $like]
    );

    $items = [];
    foreach ($rows as $row) {
        $route = '#' . (int) $row['ride_id'] . ' ' . $row['origin'] . ' -> ' . $row['destination'];
        $items[] = ridesync_search_item('joinRequestsTable', 'Join Request', $route, $row['origin'] . ' ' . $row['destination'], ($row['requester_name'] ?? '') . ' to ' . ($row['owner_name'] ?? '') . ' - ' . ($row['status'] ?? ''), '/ridesync/pages/admin_ride_detail.php?id=' . (int) $row['ride_id']);
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_report_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'reports') || !ridesync_table_exists($conn, 'users')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $id = ctype_digit($query) ? (int) $query : -1;
    $rows = ridesync_search_rows($conn,
        "SELECT rep.id, rep.reason, rep.report_status, rep.message,
                reporter.name AS reporter_name,
                reported.name AS reported_name
         FROM reports rep
         JOIN users reporter ON reporter.id = rep.reporter_user_id
         LEFT JOIN users reported ON reported.id = rep.reported_user_id
         WHERE rep.id = ? OR rep.reason LIKE ? OR rep.report_status LIKE ? OR rep.message LIKE ?
            OR reporter.name LIKE ? OR reported.name LIKE ?
         ORDER BY rep.created_at DESC
         LIMIT " . (int) $limit,
        'isssss',
        [$id, $like, $like, $like, $like, $like]
    );

    $items = [];
    foreach ($rows as $row) {
        $items[] = ridesync_search_item('reportsPanel', 'Report', '#' . (int) $row['id'] . ' ' . $row['reason'], $row['reason'], ($row['reporter_name'] ?? '') . ' - ' . ($row['report_status'] ?? ''));
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_audit_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'audit_logs')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $hasSourceIp = ridesync_column_exists($conn, 'audit_logs', 'source_ip');
    $sourceIpSelect = $hasSourceIp ? 'al.source_ip' : "'' AS source_ip";
    $sourceIpWhere = $hasSourceIp ? ' OR al.source_ip LIKE ?' : '';
    $types = $hasSourceIp ? 'sssss' : 'ssss';
    $params = $hasSourceIp
        ? [$like, $like, $like, $like, $like]
        : [$like, $like, $like, $like];
    $rows = ridesync_search_rows($conn,
        "SELECT al.action, al.entity_type, al.entity_id, al.message, {$sourceIpSelect}, au.name AS admin_name
         FROM audit_logs al
         LEFT JOIN admin_users au ON au.id = al.admin_id
         WHERE al.action LIKE ? OR al.entity_type LIKE ? OR al.message LIKE ?{$sourceIpWhere} OR au.name LIKE ?
         ORDER BY al.created_at DESC
         LIMIT " . (int) $limit,
        $types,
        $params
    );

    $items = [];
    foreach ($rows as $row) {
        $label = trim(($row['action'] ?? '') . ' ' . ($row['entity_type'] ?? '') . ' #' . ($row['entity_id'] ?? ''));
        $items[] = ridesync_search_item('auditTable', 'Audit', $label, $row['action'], ($row['admin_name'] ?? 'System') . ' - ' . ($row['message'] ?? ''));
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_remove_suggestions($conn, $query, $limit) {
    if (!ridesync_table_exists($conn, 'users') || !ridesync_table_exists($conn, 'driver_accounts')) {
        return [];
    }

    $like = ridesync_search_like($query);
    $id = ctype_digit($query) ? (int) $query : -1;
    $rows = array_merge(
        ridesync_search_rows($conn,
            "SELECT 'rider' AS account_type, id, name, email, '' AS phone, created_at
             FROM users
             WHERE id = ? OR name LIKE ? OR email LIKE ? OR college LIKE ?
             ORDER BY created_at DESC
             LIMIT " . (int) $limit,
            'isss',
            [$id, $like, $like, $like]
        ),
        ridesync_search_rows($conn,
            "SELECT 'driver' AS account_type, id, name, email, phone, created_at
             FROM driver_accounts
             WHERE id = ? OR name LIKE ? OR email LIKE ? OR phone LIKE ?
             ORDER BY created_at DESC
             LIMIT " . (int) $limit,
            'isss',
            [$id, $like, $like, $like]
        )
    );

    $items = [];
    foreach ($rows as $row) {
        $type = (string) ($row['account_type'] ?? 'rider');
        $category = $type === 'driver' ? 'Driver Account' : 'Rider Account';
        $metaParts = array_filter([
            '#' . (int) $row['id'],
            $row['email'] ?? '',
            $row['phone'] ?? '',
        ]);
        $items[] = ridesync_search_item('removeTable', $category, $row['name'], $row['name'], implode(' - ', $metaParts), null, 0);
        $items[] = ridesync_search_item('removeTable', $category . ' ID', '#' . (int) $row['id'], (string) (int) $row['id'], $row['name'] ?? '', null, 0);
    }

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_shortcut_suggestions($query, $limit) {
    $shortcuts = [
        ['Operational Inbox', 'Open work requiring admin attention', '/ridesync/pages/admin_dashboard.php?section=overview#operational-inbox', 'Ops'],
        ['Risk Scoring', 'Platform risk score and risk component breakdown', '/ridesync/pages/admin_dashboard.php?section=overview#risk-score', 'Ops'],
        ['Incident Timeline', 'Recent operational events and moderation incidents', '/ridesync/pages/admin_dashboard.php?section=overview#incident-timeline', 'Ops'],
        ['Data Quality Monitor', 'Orphaned records, stale statuses, and broken links', '/ridesync/pages/admin_dashboard.php?section=overview#data-quality', 'Integrity'],
        ['SLA Timers', 'Aging verification and report queues', '/ridesync/pages/admin_dashboard.php?section=overview#sla-timers', 'Integrity'],
        ['Fraud Clusters', 'Shared phones, documents, vehicles, and route abuse', '/ridesync/pages/admin_dashboard.php?section=overview#fraud-clusters', 'Security'],
        ['Backup Status', 'Backup freshness and schema drift health', '/ridesync/pages/admin_dashboard.php?section=overview#backup-status', 'Reliability'],
        ['Feature Flags', 'Maintenance mode and module switches', '/ridesync/pages/admin_dashboard.php?section=overview#feature-flags', 'Control'],
        ['AI Services Monitor', 'API health, queue recovery, model latency, and logs', '/ridesync/pages/admin_dashboard.php?section=services', 'Services'],
        ['Audit Explorer', 'Admin actions, entity events, and security traceability', '/ridesync/pages/admin_dashboard.php?section=audit', 'Audit'],
        ['Bulk Operations', 'Controlled cleanup and recovery actions', '/ridesync/pages/admin_dashboard.php?section=bulk', 'Ops'],
        ['View As User Driver', 'Read-only panel inspection for user or driver accounts', '/ridesync/pages/admin_dashboard.php?section=users', 'Support'],
        ['Admin Profile', 'Admin identity, role, permissions, and session context', '/ridesync/pages/admin_dashboard.php?section=profiles', 'Account'],
        ['Record Health Score', 'Open a user, ride, or driver detail page to inspect per-record health', '/ridesync/pages/admin_dashboard.php?section=overview#operational-inbox', 'Insight'],
        ['Universal Record Timeline', 'Open a user, ride, or driver detail page to inspect the activity trail', '/ridesync/pages/admin_dashboard.php?section=overview#incident-timeline', 'Insight'],
    ];

    $items = [];
    foreach ($shortcuts as $shortcut) {
        [$label, $meta, $url, $category] = $shortcut;
        $items[] = ridesync_search_item('admin_global', $category, $label, $label, $meta, $url, 0);
    }

    foreach ($items as &$item) {
        $item['source'] = 'shortcut';
    }
    unset($item);

    return ridesync_search_finalize($items, $query, $limit);
}

function ridesync_admin_search_suggestions($conn, $context, $query, $limit = 10) {
    $context = in_array($context, ridesync_admin_suggestion_contexts(), true) ? $context : 'admin_global';
    $query = ridesync_search_query($query);
    $limit = max(1, min(20, (int) $limit));

    if (strlen($query) < 2) {
        return [];
    }

    $contextMap = [
        'driversTable' => 'ridesync_admin_driver_suggestions',
        'usersTable' => 'ridesync_admin_user_suggestions',
        'ridesTable' => 'ridesync_admin_ride_suggestions',
        'directRequestsTable' => 'ridesync_admin_direct_request_suggestions',
        'joinRequestsTable' => 'ridesync_admin_join_request_suggestions',
        'reportsPanel' => 'ridesync_admin_report_suggestions',
        'removeTable' => 'ridesync_admin_remove_suggestions',
        'auditTable' => 'ridesync_admin_audit_suggestions',
    ];

    if ($context !== 'admin_global') {
        $callback = $contextMap[$context] ?? null;
        return $callback ? $callback($conn, $query, $limit) : [];
    }

    $items = [];
    $items = array_merge($items, ridesync_admin_shortcut_suggestions($query, 8));
    $items = array_merge($items, ridesync_admin_user_suggestions($conn, $query, 4));
    $items = array_merge($items, ridesync_admin_driver_suggestions($conn, $query, 4));
    $items = array_merge($items, ridesync_admin_ride_suggestions($conn, $query, 4));
    $items = array_merge($items, ridesync_admin_direct_request_suggestions($conn, $query, 3));
    $items = array_merge($items, ridesync_admin_join_request_suggestions($conn, $query, 3));
    $items = array_merge($items, ridesync_admin_report_suggestions($conn, $query, 3));
    $items = array_merge($items, ridesync_admin_audit_suggestions($conn, $query, 3));

    foreach ($items as &$item) {
        $item['context'] = 'admin_global';
        if (empty($item['url'])) {
            $section = [
                'Driver' => 'drivers',
                'Vehicle' => 'drivers',
                'License' => 'drivers',
                'User' => 'users',
                'College' => 'users',
                'Ride' => 'rides',
                'Route' => 'rides',
                'Direct Request' => 'requests',
                'Join Request' => 'requests',
                'Report' => 'reports',
                'Audit' => 'audit',
            ][$item['category']] ?? 'overview';
            $item['url'] = '/ridesync/pages/admin_dashboard.php?section=' . $section . '&q=' . urlencode($item['value']);
        }
    }
    unset($item);

    return ridesync_search_finalize($items, $query, $limit);
}
?>
