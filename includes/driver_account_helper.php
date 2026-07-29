<?php
require_once __DIR__ . '/matching_helper.php';
require_once __DIR__ . '/driver_document_helper.php';

function ridesync_driver_schema_ready($conn, $forceRefresh = false) {
    if (!$conn instanceof mysqli) {
        return false;
    }

    static $cache = [];
    $connId = spl_object_hash($conn);

    if ($forceRefresh) {
        unset($cache[$connId]);
    }

    if (isset($cache[$connId])) {
        return $cache[$connId];
    }

    $required = [
        'driver_accounts',
        'driver_account_profiles',
        'driver_account_vehicles',
        'driver_account_documents',
        'driver_account_availability',
        'driver_ride_requests',
        'driver_ride_history',
    ];

    $placeholders = implode(',', array_fill(0, count($required), '?'));
    $stmt = mysqli_prepare(
        $conn,
        "SELECT TABLE_NAME
         FROM information_schema.TABLES
         WHERE TABLE_SCHEMA = DATABASE()
           AND TABLE_NAME IN ({$placeholders})"
    );
    if (!$stmt) {
        $cache[$connId] = false;
        return false;
    }

    $types = str_repeat('s', count($required));
    $bindValues = $required;
    $bindParams = [$types];
    foreach ($bindValues as $index => $value) {
        $bindParams[] = &$bindValues[$index];
    }
    call_user_func_array([$stmt, 'bind_param'], $bindParams);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $found = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $found[strtolower((string) $row['TABLE_NAME'])] = true;
    }
    mysqli_stmt_close($stmt);

    $cache[$connId] = count($found) === count($required);
    return $cache[$connId];
}

function ridesync_require_driver_login() {
    if (!isset($_SESSION['driver_id'])) {
        header("Location: /ridesync/pages/driver_login.php");
        exit();
    }
}

function ridesync_fetch_driver_account($conn, $driverId) {
    if (!ridesync_driver_schema_ready($conn)) {
        return null;
    }

    $stmt = mysqli_prepare($conn, "SELECT * FROM driver_accounts WHERE id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    return mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
}

function ridesync_fetch_driver_state($conn, $driverId, array $options = []) {
    $state = [
        'schema_ready' => ridesync_driver_schema_ready($conn),
        'account' => null,
        'profile' => null,
        'vehicle' => null,
        'documents' => [],
        'availability' => 'offline',
        'current_lat' => null,
        'current_lng' => null,
        'pending_requests' => 0,
        'active_workload' => 0,
        'today_earnings' => 0,
        'week_earnings' => 0,
        'total_earnings' => 0,
        'completed_trips' => 0,
    ];

    if (!$state['schema_ready']) {
        return $state;
    }

    $includeDocuments = (bool) ($options['documents'] ?? true);

    $stmt = mysqli_prepare($conn,
        "SELECT
            d.id AS account_id,
            d.name AS account_name,
            d.email AS account_email,
            d.phone AS account_phone,
            d.status AS account_status,
            d.onboarding_status AS account_onboarding_status,
            d.created_at AS account_created_at,
            d.updated_at AS account_updated_at,
            p.id AS profile_id,
            p.license_number AS profile_license_number,
            p.verification_details AS profile_verification_details,
            p.verification_status AS profile_verification_status,
            p.created_at AS profile_created_at,
            p.updated_at AS profile_updated_at,
            v.id AS vehicle_id,
            v.vehicle_type,
            v.vehicle_number,
            v.seating_capacity,
            v.created_at AS vehicle_created_at,
            v.updated_at AS vehicle_updated_at,
            a.status AS availability_status,
            a.current_lat,
            a.current_lng
         FROM driver_accounts d
         LEFT JOIN driver_account_profiles p ON p.driver_id = d.id
         LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
         LEFT JOIN driver_account_availability a ON a.driver_id = d.id
         WHERE d.id = ?
         LIMIT 1"
    );
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    if ($row) {
        $state['account'] = [
            'id' => $row['account_id'],
            'name' => $row['account_name'],
            'email' => $row['account_email'],
            'phone' => $row['account_phone'],
            'status' => $row['account_status'],
            'onboarding_status' => $row['account_onboarding_status'],
            'created_at' => $row['account_created_at'],
            'updated_at' => $row['account_updated_at'],
        ];

        if ($row['profile_id'] !== null) {
            $state['profile'] = [
                'id' => $row['profile_id'],
                'driver_id' => $driverId,
                'license_number' => $row['profile_license_number'],
                'verification_details' => $row['profile_verification_details'],
                'verification_status' => $row['profile_verification_status'],
                'created_at' => $row['profile_created_at'],
                'updated_at' => $row['profile_updated_at'],
            ];
        }

        if ($row['vehicle_id'] !== null) {
            $state['vehicle'] = [
                'id' => $row['vehicle_id'],
                'driver_id' => $driverId,
                'vehicle_type' => $row['vehicle_type'],
                'vehicle_number' => $row['vehicle_number'],
                'seating_capacity' => $row['seating_capacity'],
                'created_at' => $row['vehicle_created_at'],
                'updated_at' => $row['vehicle_updated_at'],
            ];
        }

        if ($row['availability_status'] !== null) {
            $state['availability'] = $row['availability_status'];
            $state['current_lat'] = $row['current_lat'];
            $state['current_lng'] = $row['current_lng'];
        }
    }

    $stmt = mysqli_prepare($conn,
        "SELECT
            (SELECT COUNT(*)
             FROM driver_ride_requests
             WHERE driver_id = ? AND request_status = 'pending') AS pending_requests,
            (SELECT COUNT(*)
             FROM driver_ride_requests
             WHERE driver_id = ? AND request_status = 'accepted')
            +
            (SELECT COUNT(*)
             FROM ride_live_status
             WHERE driver_id = ? AND live_status IN ('driver_assigned', 'arriving', 'active')) AS active_workload,
            COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN fare END), 0) AS today_earnings,
            COALESCE(SUM(CASE WHEN YEARWEEK(completed_at, 1) = YEARWEEK(CURDATE(), 1) THEN fare END), 0) AS week_earnings,
            COALESCE(SUM(fare), 0) AS total_earnings,
            COUNT(driver_ride_history.id) AS completed_trips
         FROM driver_ride_history
         WHERE driver_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "iiii", $driverId, $driverId, $driverId, $driverId);
    mysqli_stmt_execute($stmt);
    $metrics = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($metrics) {
        $state['pending_requests'] = (int) ($metrics['pending_requests'] ?? 0);
        $state['active_workload'] = (int) ($metrics['active_workload'] ?? 0);
        $state['today_earnings'] = (float) ($metrics['today_earnings'] ?? 0);
        $state['week_earnings'] = (float) ($metrics['week_earnings'] ?? 0);
        $state['total_earnings'] = (float) ($metrics['total_earnings'] ?? 0);
        $state['completed_trips'] = (int) ($metrics['completed_trips'] ?? 0);
    }

    if ($includeDocuments) {
        $stmt = mysqli_prepare($conn,
            "SELECT *
             FROM driver_account_documents
             WHERE driver_id = ?
             ORDER BY id DESC"
        );
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);
        $documents = mysqli_stmt_get_result($stmt);
        while ($document = mysqli_fetch_assoc($documents)) {
            $type = $document['document_type'];
            if (!isset($state['documents'][$type])) {
                $state['documents'][$type] = $document;
            }
        }
    }

    return $state;
}

function ridesync_driver_active_workload_count($conn, $driverId, $excludeCommunityRideId = null) {
    if (!$conn instanceof mysqli) {
        return 0;
    }

    $driverId = (int) $driverId;
    if ($driverId <= 0) {
        return 0;
    }

    $total = 0;

    if (ridesync_table_exists($conn, 'driver_ride_requests')) {
        $stmt = mysqli_prepare(
            $conn,
            "SELECT COUNT(*) AS total
             FROM driver_ride_requests
             WHERE driver_id = ? AND request_status = 'accepted'"
        );
        mysqli_stmt_bind_param($stmt, "i", $driverId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $total += (int) ($row['total'] ?? 0);
    }

    if (ridesync_table_exists($conn, 'ride_live_status')) {
        $excludeCommunityRideId = $excludeCommunityRideId !== null ? (int) $excludeCommunityRideId : null;
        if ($excludeCommunityRideId !== null && $excludeCommunityRideId > 0) {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM ride_live_status
                 WHERE driver_id = ?
                   AND ride_id != ?
                   AND live_status IN ('driver_assigned', 'arriving', 'active')"
            );
            mysqli_stmt_bind_param($stmt, "ii", $driverId, $excludeCommunityRideId);
        } else {
            $stmt = mysqli_prepare(
                $conn,
                "SELECT COUNT(*) AS total
                 FROM ride_live_status
                 WHERE driver_id = ?
                   AND live_status IN ('driver_assigned', 'arriving', 'active')"
            );
            mysqli_stmt_bind_param($stmt, "i", $driverId);
        }
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        $total += (int) ($row['total'] ?? 0);
    }

    return $total;
}

function ridesync_driver_has_active_workload($conn, $driverId, $excludeCommunityRideId = null) {
    return ridesync_driver_active_workload_count($conn, $driverId, $excludeCommunityRideId) > 0;
}

function ridesync_driver_set_availability($conn, $driverId, $status, $currentLat = null, $currentLng = null) {
    if (!$conn instanceof mysqli) {
        return false;
    }

    $driverId = (int) $driverId;
    if ($driverId <= 0 || !in_array($status, ['online', 'offline'], true)) {
        return false;
    }

    if ($status === 'offline') {
        $currentLat = null;
        $currentLng = null;
    }

    $stmt = mysqli_prepare($conn,
        "INSERT INTO driver_account_availability (driver_id, status, current_lat, current_lng, last_changed_at)
         VALUES (?, ?, ?, ?, CURRENT_TIMESTAMP)
         ON DUPLICATE KEY UPDATE status = VALUES(status), current_lat = VALUES(current_lat), current_lng = VALUES(current_lng), last_changed_at = CURRENT_TIMESTAMP"
    );
    mysqli_stmt_bind_param($stmt, "isdd", $driverId, $status, $currentLat, $currentLng);
    return mysqli_stmt_execute($stmt);
}

function ridesync_driver_onboarding_complete($state) {
    return !empty($state['account']) && !empty($state['profile']) && !empty($state['vehicle']);
}

function ridesync_driver_required_document_total() {
    return 4;
}

function ridesync_driver_document_submitted($documents, $type) {
    return !empty($documents[$type]);
}

function ridesync_driver_document_verified($documents, $type) {
    return ($documents[$type]['verification_status'] ?? '') === 'verified';
}

function ridesync_driver_identity_submitted($documents) {
    return ridesync_driver_document_submitted($documents, 'aadhaar')
        && ridesync_driver_document_submitted($documents, 'pan');
}

function ridesync_driver_identity_verified($documents) {
    return ridesync_driver_document_verified($documents, 'aadhaar')
        && ridesync_driver_document_verified($documents, 'pan');
}

function ridesync_driver_required_document_summary($documents) {
    $checks = [
        'license' => [
            'label' => 'Driving license',
            'submitted' => ridesync_driver_document_submitted($documents, 'license'),
            'verified' => ridesync_driver_document_verified($documents, 'license'),
        ],
        'aadhaar' => [
            'label' => 'Aadhaar Card',
            'submitted' => ridesync_driver_document_submitted($documents, 'aadhaar'),
            'verified' => ridesync_driver_document_verified($documents, 'aadhaar'),
        ],
        'pan' => [
            'label' => 'PAN Card',
            'submitted' => ridesync_driver_document_submitted($documents, 'pan'),
            'verified' => ridesync_driver_document_verified($documents, 'pan'),
        ],
        'vehicle_rc' => [
            'label' => 'Vehicle RC',
            'submitted' => ridesync_driver_document_submitted($documents, 'vehicle_rc'),
            'verified' => ridesync_driver_document_verified($documents, 'vehicle_rc'),
        ],
    ];

    $submitted = 0;
    $verified = 0;
    foreach ($checks as $check) {
        if ($check['submitted']) {
            $submitted++;
        }
        if ($check['verified']) {
            $verified++;
        }
    }

    $total = ridesync_driver_required_document_total();

    return [
        'checks' => $checks,
        'submitted' => $submitted,
        'verified' => $verified,
        'total' => $total,
        'complete' => $submitted >= $total,
        'ready' => $verified >= $total,
    ];
}

function ridesync_driver_is_verified($state) {
    if (($state['profile']['verification_status'] ?? '') !== 'verified') {
        return false;
    }

    return ridesync_driver_required_document_summary($state['documents'] ?? [])['ready'];
}

function ridesync_format_money($amount) {
    return '&#8377;' . number_format((float) $amount, 2);
}
?>
