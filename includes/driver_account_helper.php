<?php
require_once __DIR__ . '/matching_helper.php';

function ridesync_driver_schema_ready($conn) {
    static $ready = null;

    if ($ready !== null) {
        return $ready;
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

    $ready = true;
    foreach ($required as $table) {
        $safeTable = mysqli_real_escape_string($conn, $table);
        $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
        if (!$result || mysqli_num_rows($result) === 0) {
            $ready = false;
            break;
        }
    }

    return $ready;
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

function ridesync_fetch_driver_state($conn, $driverId) {
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

    $state['account'] = ridesync_fetch_driver_account($conn, $driverId);

    $stmt = mysqli_prepare($conn, "SELECT * FROM driver_account_profiles WHERE driver_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $state['profile'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    $stmt = mysqli_prepare($conn, "SELECT * FROM driver_account_vehicles WHERE driver_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $state['vehicle'] = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

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

    $stmt = mysqli_prepare($conn, "SELECT status, current_lat, current_lng FROM driver_account_availability WHERE driver_id = ? LIMIT 1");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $availability = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($availability) {
        $state['availability'] = $availability['status'];
        $state['current_lat'] = $availability['current_lat'];
        $state['current_lng'] = $availability['current_lng'];
    }

    $stmt = mysqli_prepare($conn, "SELECT COUNT(*) AS total FROM driver_ride_requests WHERE driver_id = ? AND request_status = 'pending'");
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $state['pending_requests'] = (int) mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'];

    $stmt = mysqli_prepare($conn,
        "SELECT
            COALESCE(SUM(CASE WHEN DATE(completed_at) = CURDATE() THEN fare END), 0) AS today_earnings,
            COALESCE(SUM(CASE WHEN YEARWEEK(completed_at, 1) = YEARWEEK(CURDATE(), 1) THEN fare END), 0) AS week_earnings,
            COALESCE(SUM(fare), 0) AS total_earnings,
            COUNT(*) AS completed_trips
         FROM driver_ride_history
         WHERE driver_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $driverId);
    mysqli_stmt_execute($stmt);
    $earnings = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
    if ($earnings) {
        $state['today_earnings'] = (float) $earnings['today_earnings'];
        $state['week_earnings'] = (float) $earnings['week_earnings'];
        $state['total_earnings'] = (float) $earnings['total_earnings'];
        $state['completed_trips'] = (int) $earnings['completed_trips'];
    }

    $state['active_workload'] = ridesync_driver_active_workload_count($conn, $driverId);

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
    return ridesync_driver_document_submitted($documents, 'id_proof')
        || (
            ridesync_driver_document_submitted($documents, 'aadhaar')
            && ridesync_driver_document_submitted($documents, 'pan')
        );
}

function ridesync_driver_identity_verified($documents) {
    return ridesync_driver_document_verified($documents, 'id_proof')
        || (
            ridesync_driver_document_verified($documents, 'aadhaar')
            && ridesync_driver_document_verified($documents, 'pan')
        );
}

function ridesync_driver_required_document_summary($documents) {
    $checks = [
        'license' => [
            'label' => 'Driving license',
            'submitted' => ridesync_driver_document_submitted($documents, 'license'),
            'verified' => ridesync_driver_document_verified($documents, 'license'),
        ],
        'identity' => [
            'label' => 'Identity proof',
            'submitted' => ridesync_driver_identity_submitted($documents),
            'verified' => ridesync_driver_identity_verified($documents),
        ],
        'vehicle_rc' => [
            'label' => 'Vehicle RC',
            'submitted' => ridesync_driver_document_submitted($documents, 'vehicle_rc'),
            'verified' => ridesync_driver_document_verified($documents, 'vehicle_rc'),
        ],
        'insurance' => [
            'label' => 'Insurance',
            'submitted' => ridesync_driver_document_submitted($documents, 'insurance'),
            'verified' => ridesync_driver_document_verified($documents, 'insurance'),
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
