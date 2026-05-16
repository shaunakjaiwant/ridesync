<?php
require_once __DIR__ . '/matching_helper.php';

function ridesync_fetch_driver_community_rides($conn, $driverId, $limit = 12) {
    if (!ridesync_table_exists($conn, 'rides') || !ridesync_table_exists($conn, 'ride_live_status')) {
        return [];
    }

    $limit = max(1, min(50, (int) $limit));
    $driverId = (int) $driverId;

    $stmt = mysqli_prepare($conn,
        "SELECT r.*, u.name AS poster_name, u.college AS poster_college,
                ls.driver_id AS assigned_driver_id, ls.live_status, ls.eta_minutes, ls.note
         FROM rides r
         JOIN users u ON u.id = r.user_id
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE (
                (
                    r.status IN ('open', 'closed')
                    AND TIMESTAMP(r.travel_date, r.travel_time) >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    AND (ls.driver_id IS NULL OR ls.driver_id = ?)
                )
                OR (
                    ls.driver_id = ?
                    AND ls.live_status IN ('driver_assigned', 'arriving', 'active')
                )
           )
           AND (ls.live_status IS NULL OR ls.live_status NOT IN ('completed', 'cancelled'))
         ORDER BY r.travel_date ASC, r.travel_time ASC, r.created_at DESC
         LIMIT ?"
    );
    mysqli_stmt_bind_param($stmt, "iii", $driverId, $driverId, $limit);
    mysqli_stmt_execute($stmt);

    $rides = [];
    $result = mysqli_stmt_get_result($stmt);
    while ($ride = mysqli_fetch_assoc($result)) {
        $rides[] = $ride;
    }

    return $rides;
}

function ridesync_count_driver_community_rides($conn, $driverId) {
    if (!ridesync_table_exists($conn, 'rides') || !ridesync_table_exists($conn, 'ride_live_status')) {
        return 0;
    }

    $driverId = (int) $driverId;
    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) AS total
         FROM rides r
         LEFT JOIN ride_live_status ls ON ls.ride_id = r.id
         WHERE (
                (
                    r.status IN ('open', 'closed')
                    AND TIMESTAMP(r.travel_date, r.travel_time) >= DATE_SUB(NOW(), INTERVAL 30 MINUTE)
                    AND (ls.driver_id IS NULL OR ls.driver_id = ?)
                )
                OR (
                    ls.driver_id = ?
                    AND ls.live_status IN ('driver_assigned', 'arriving', 'active')
                )
           )
           AND (ls.live_status IS NULL OR ls.live_status NOT IN ('completed', 'cancelled'))"
    );
    mysqli_stmt_bind_param($stmt, "ii", $driverId, $driverId);
    mysqli_stmt_execute($stmt);
    $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));

    return (int) ($row['total'] ?? 0);
}

function ridesync_driver_community_eta($ride, $state) {
    $driverLat = ridesync_float_or_null($state['current_lat'] ?? null);
    $driverLng = ridesync_float_or_null($state['current_lng'] ?? null);
    $originLat = ridesync_float_or_null($ride['origin_lat'] ?? null);
    $originLng = ridesync_float_or_null($ride['origin_lng'] ?? null);

    if ($driverLat === null || $driverLng === null || $originLat === null || $originLng === null) {
        return null;
    }

    $distanceKm = ridesync_haversine_km($driverLat, $driverLng, $originLat, $originLng);
    if ($distanceKm === null) {
        return null;
    }

    return max(3, (int) ceil(($distanceKm / 22) * 60));
}

function ridesync_driver_community_fare($ride) {
    $distanceKm = ridesync_float_or_null($ride['route_distance_km'] ?? null);
    if ($distanceKm === null || $distanceKm <= 0) {
        $distanceKm = ridesync_estimate_route_distance($ride['origin'] ?? '', $ride['destination'] ?? '');
    }

    return ridesync_driver_fare_estimate($distanceKm);
}

function ridesync_driver_community_time_label($ride) {
    $timestamp = strtotime(($ride['travel_date'] ?? '') . ' ' . ($ride['travel_time'] ?? ''));
    return $timestamp ? date('M j, g:i A', $timestamp) : 'Time not set';
}
?>
