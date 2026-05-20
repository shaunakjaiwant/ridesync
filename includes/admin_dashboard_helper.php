<?php

function ridesync_admin_fetch_rows($result) {
    $rows = [];
    if ($result) {
        while ($row = mysqli_fetch_assoc($result)) {
            $rows[] = $row;
        }
    }
    return $rows;
}

function ridesync_admin_query_rows($conn, $sql) {
    return ridesync_admin_fetch_rows(mysqli_query($conn, $sql));
}

function ridesync_admin_prepared_rows($conn, $sql, $types = '', $params = []) {
    $stmt = mysqli_prepare($conn, $sql);
    if (!$stmt) {
        return [];
    }

    if ($types !== '' && count($params) > 0) {
        $refs = [];
        foreach ($params as $key => $value) {
            $refs[$key] = &$params[$key];
        }
        mysqli_stmt_bind_param($stmt, $types, ...$refs);
    }

    mysqli_stmt_execute($stmt);
    return ridesync_admin_fetch_rows(mysqli_stmt_get_result($stmt));
}

function ridesync_admin_metric($metrics, $key) {
    return (float) ($metrics[$key] ?? 0);
}

function ridesync_admin_int($metrics, $key) {
    return (int) ridesync_admin_metric($metrics, $key);
}

function ridesync_admin_status_class($status) {
    $status = (string) $status;
    if (in_array($status, ['active', 'online', 'open', 'verified', 'accepted', 'completed', 'resolved'], true)) {
        return 'accepted';
    }
    if (in_array($status, ['suspended', 'rejected', 'cancelled', 'dismissed', 'expired'], true)) {
        return 'rejected';
    }
    if (in_array($status, ['arriving', 'driver_assigned', 'matched', 'reviewing'], true)) {
        return 'open';
    }
    return 'pending';
}

function ridesync_admin_drawer_attr($payload) {
    return htmlspecialchars(json_encode($payload, JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_TAG | JSON_HEX_AMP), ENT_QUOTES, 'UTF-8');
}

function ridesync_admin_search_blob($values) {
    return strtolower(implode(' ', array_map(static function ($value) {
        return trim((string) $value);
    }, $values)));
}

function ridesync_admin_percent($value, $total) {
    $total = max(1, (float) $total);
    return min(100, max(0, round(((float) $value / $total) * 100)));
}

function ridesync_admin_get_page($param) {
    $page = filter_input(INPUT_GET, $param, FILTER_VALIDATE_INT);
    return max(1, (int) ($page ?: 1));
}

function ridesync_admin_pagination_meta($totalRows, $param, $perPage = 25) {
    $totalRows = max(0, (int) $totalRows);
    $perPage = max(1, min(100, (int) $perPage));
    $totalPages = max(1, (int) ceil($totalRows / $perPage));
    $page = min(ridesync_admin_get_page($param), $totalPages);
    $offset = ($page - 1) * $perPage;

    return [
        'param' => $param,
        'page' => $page,
        'per_page' => $perPage,
        'offset' => $offset,
        'total' => $totalRows,
        'total_pages' => $totalPages,
        'from' => $totalRows === 0 ? 0 : $offset + 1,
        'to' => min($totalRows, $offset + $perPage),
    ];
}

function ridesync_admin_count_query($conn, $sql) {
    $result = mysqli_query($conn, $sql);
    if (!$result) {
        return 0;
    }

    $row = mysqli_fetch_assoc($result);
    return (int) ($row['total'] ?? 0);
}

function ridesync_admin_prepared_count($conn, $sql, $types = '', $params = []) {
    $rows = ridesync_admin_prepared_rows($conn, $sql, $types, $params);
    return (int) ($rows[0]['total'] ?? 0);
}

function ridesync_admin_paginated_rows($conn, $sql, $pagination) {
    return ridesync_admin_query_rows(
        $conn,
        $sql . ' LIMIT ' . (int) $pagination['per_page'] . ' OFFSET ' . (int) $pagination['offset']
    );
}

function ridesync_admin_section_url($section, $query = '') {
    $url = '/ridesync/pages/admin_dashboard.php?section=' . urlencode($section);
    $query = trim((string) $query);
    if ($query !== '') {
        $url .= '&q=' . urlencode($query);
    }
    return $url;
}

function ridesync_admin_current_url($overrides = []) {
    $params = $_GET;

    foreach ($overrides as $key => $value) {
        if ($value === null || $value === '' || ((substr($key, -5) === '_page') && (int) $value <= 1)) {
            unset($params[$key]);
            continue;
        }
        $params[$key] = $value;
    }

    if (trim((string) ($params['q'] ?? '')) === '') {
        unset($params['q']);
    }

    foreach (array_keys($params) as $key) {
        if (substr($key, -5) === '_page' && (int) $params[$key] <= 1) {
            unset($params[$key]);
        }
    }

    return '/ridesync/pages/admin_dashboard.php?' . http_build_query($params);
}

function ridesync_admin_render_pagination($pagination) {
    if (!$pagination || (int) $pagination['total'] <= (int) $pagination['per_page']) {
        return;
    }

    $previousPage = max(1, (int) $pagination['page'] - 1);
    $nextPage = min((int) $pagination['total_pages'], (int) $pagination['page'] + 1);
    ?>
    <nav class="admin-pagination" aria-label="Table pagination">
        <span>
            Showing <?php echo (int) $pagination['from']; ?>-<?php echo (int) $pagination['to']; ?>
            of <?php echo (int) $pagination['total']; ?>
        </span>
        <div>
            <?php if ((int) $pagination['page'] > 1): ?>
                <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(ridesync_admin_current_url([$pagination['param'] => $previousPage])); ?>">Previous</a>
            <?php else: ?>
                <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Previous</span>
            <?php endif; ?>
            <strong><?php echo (int) $pagination['page']; ?> / <?php echo (int) $pagination['total_pages']; ?></strong>
            <?php if ((int) $pagination['page'] < (int) $pagination['total_pages']): ?>
                <a class="btn btn-secondary btn-sm" href="<?php echo htmlspecialchars(ridesync_admin_current_url([$pagination['param'] => $nextPage])); ?>">Next</a>
            <?php else: ?>
                <span class="btn btn-secondary btn-sm is-disabled" aria-disabled="true">Next</span>
            <?php endif; ?>
        </div>
    </nav>
    <?php
}

function ridesync_admin_required_doc_summary($driver) {
    $submitted = (int) ($driver['submitted_required_documents'] ?? 0);
    $verified = (int) ($driver['verified_required_documents'] ?? 0);

    return [
        'submitted' => $submitted,
        'verified' => $verified,
        'label' => $verified . '/4 required checks',
        'ready' => $verified >= 4,
        'complete' => $submitted >= 4,
    ];
}

?>
