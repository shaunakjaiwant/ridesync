<?php
require_once __DIR__ . '/matching_helper.php';

function ridesync_route_key($origin, $destination) {
    $clean = function ($value) {
        $value = strtolower(trim((string) $value));
        $value = preg_replace('/[^a-z0-9]+/', ' ', $value);
        $value = preg_replace('/\s+/', ' ', $value);
        return trim($value);
    };

    return substr($clean($origin) . '>' . $clean($destination), 0, 220);
}

function ridesync_expire_stale_demand_signals($conn) {
    static $done = false;

    if ($done || !ridesync_table_exists($conn, 'route_demand_signals')) {
        return;
    }

    $done = true;
    mysqli_query(
        $conn,
        "UPDATE route_demand_signals
         SET demand_status = 'expired', updated_at = CURRENT_TIMESTAMP
         WHERE demand_status = 'active'
           AND travel_date IS NOT NULL
           AND TIMESTAMP(travel_date, COALESCE(travel_time, '23:59:59')) < DATE_SUB(NOW(), INTERVAL 15 MINUTE)"
    );
}

function ridesync_fetch_popular_routes($conn, $limit = 6) {
    ridesync_expire_stale_demand_signals($conn);

    $routes = [];

    $rideResult = mysqli_query($conn,
        "SELECT origin, destination, COUNT(*) AS total,
                COALESCE(AVG(route_distance_km), 0) AS avg_distance,
                MAX(created_at) AS last_seen
         FROM rides
         GROUP BY LOWER(origin), LOWER(destination)
         ORDER BY total DESC, last_seen DESC
         LIMIT 30"
    );

    if ($rideResult) {
        while ($row = mysqli_fetch_assoc($rideResult)) {
            $key = ridesync_route_key($row['origin'], $row['destination']);
            if (!isset($routes[$key])) {
                $routes[$key] = [
                    'origin' => $row['origin'],
                    'destination' => $row['destination'],
                    'rides' => 0,
                    'demand' => 0,
                    'avg_distance' => (float) $row['avg_distance'],
                    'last_seen' => $row['last_seen'],
                ];
            }
            $routes[$key]['rides'] += (int) $row['total'];
        }
    }

    if (ridesync_table_exists($conn, 'route_demand_signals')) {
        $demandResult = mysqli_query($conn,
            "SELECT origin, destination, COUNT(*) AS total,
                    COALESCE(AVG(route_distance_km), 0) AS avg_distance,
                    MAX(updated_at) AS last_seen
             FROM route_demand_signals
             WHERE demand_status = 'active'
               AND (travel_date IS NULL OR TIMESTAMP(travel_date, COALESCE(travel_time, '23:59:59')) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE))
             GROUP BY route_key, origin, destination
             ORDER BY total DESC, last_seen DESC
             LIMIT 30"
        );

        if ($demandResult) {
            while ($row = mysqli_fetch_assoc($demandResult)) {
                $key = ridesync_route_key($row['origin'], $row['destination']);
                if (!isset($routes[$key])) {
                    $routes[$key] = [
                        'origin' => $row['origin'],
                        'destination' => $row['destination'],
                        'rides' => 0,
                        'demand' => 0,
                        'avg_distance' => (float) $row['avg_distance'],
                        'last_seen' => $row['last_seen'],
                    ];
                }
                $routes[$key]['demand'] += (int) $row['total'];
                if ((float) $routes[$key]['avg_distance'] <= 0) {
                    $routes[$key]['avg_distance'] = (float) $row['avg_distance'];
                }
                if (strtotime($row['last_seen']) > strtotime($routes[$key]['last_seen'] ?? '1970-01-01')) {
                    $routes[$key]['last_seen'] = $row['last_seen'];
                }
            }
        }
    }

    foreach ($routes as &$route) {
        $route['heat_score'] = ($route['rides'] * 2) + ($route['demand'] * 3);
        $route['estimated_savings'] = ridesync_estimated_pool_savings($route['avg_distance'] ?: 8, max(1, $route['demand']));
    }
    unset($route);

    usort($routes, function ($a, $b) {
        $score = $b['heat_score'] <=> $a['heat_score'];
        if ($score !== 0) {
            return $score;
        }
        return strcmp((string) $b['last_seen'], (string) $a['last_seen']);
    });

    return array_slice($routes, 0, $limit);
}

function ridesync_count_matching_demand($conn, $search, $excludeUserId = 0) {
    ridesync_expire_stale_demand_signals($conn);

    if (!ridesync_table_exists($conn, 'route_demand_signals')) {
        return 0;
    }

    $routeKey = ridesync_route_key($search['origin'] ?? '', $search['destination'] ?? '');
    if ($routeKey === '>') {
        return 0;
    }

    $travelDate = trim((string) ($search['travel_date'] ?? ''));
    $sql = "SELECT COUNT(*) AS total
            FROM route_demand_signals
            WHERE demand_status = 'active'
              AND (travel_date IS NULL OR TIMESTAMP(travel_date, COALESCE(travel_time, '23:59:59')) >= DATE_SUB(NOW(), INTERVAL 15 MINUTE))
              AND user_id != ?
              AND route_key = ?";
    $params = [(int) $excludeUserId, $routeKey];
    $types = "is";

    if ($travelDate !== '') {
        $sql .= " AND (travel_date IS NULL OR travel_date = ?)";
        $params[] = $travelDate;
        $types .= "s";
    }

    $stmt = mysqli_prepare($conn, $sql);
    mysqli_stmt_bind_param($stmt, $types, ...$params);
    mysqli_stmt_execute($stmt);
    return (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);
}

function ridesync_estimated_pool_savings($distanceKm, $demandCount = 1) {
    $distanceKm = max(1, (float) $distanceKm);
    $baseFare = ridesync_estimate_total_ride_fare($distanceKm);
    $poolFactor = min(0.42, 0.16 + (max(0, (int) $demandCount) * 0.05));
    return (int) round($baseFare * $poolFactor);
}

function ridesync_smart_wait_suggestion($distanceKm, $bestScore, $demandCount, $resultCount) {
    $distanceKm = max(1, (float) $distanceKm);
    $demandCount = max(0, (int) $demandCount);
    $resultCount = max(0, (int) $resultCount);

    if ($bestScore >= 82 && $resultCount > 0) {
        return [
            'minutes' => 0,
            'savings' => 0,
            'message' => 'A strong match is already available.',
            'should_wait' => false,
        ];
    }

    $minutes = min(14, max(5, 9 - min(4, $demandCount) + ($resultCount === 0 ? 2 : 0)));
    $savings = ridesync_estimated_pool_savings($distanceKm, $demandCount + 1);

    return [
        'minutes' => $minutes,
        'savings' => $savings,
        'message' => "Wait {$minutes} minutes for nearby demand and you may save " . formatCost($savings) . ".",
        'should_wait' => true,
    ];
}

function ridesync_fetch_user_demand_signals($conn, $userId, $limit = 5) {
    ridesync_expire_stale_demand_signals($conn);

    if (!ridesync_table_exists($conn, 'route_demand_signals')) {
        return [];
    }

    $stmt = mysqli_prepare($conn,
        "SELECT *
         FROM route_demand_signals
         WHERE user_id = ?
         ORDER BY FIELD(demand_status, 'active', 'matched', 'expired', 'cancelled'), updated_at DESC
         LIMIT ?"
    );
    mysqli_stmt_bind_param($stmt, "ii", $userId, $limit);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $signals = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $signals[] = $row;
    }

    return $signals;
}

function ridesync_green_score_for_user($conn, $userId) {
    $postedDistance = 0.0;
    $acceptedPassengerCount = 0;

    $stmt = mysqli_prepare($conn,
        "SELECT COALESCE(SUM(COALESCE(route_distance_km, 0)), 0) AS distance
         FROM rides
         WHERE user_id = ?"
    );
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $postedDistance = (float) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['distance'] ?? 0);

    $stmt = mysqli_prepare($conn,
        "SELECT COUNT(*) AS total
         FROM matches
         WHERE matched_user_id = ? AND status = 'accepted'"
    );
    mysqli_stmt_bind_param($stmt, "i", $userId);
    mysqli_stmt_execute($stmt);
    $acceptedPassengerCount = (int) (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))['total'] ?? 0);

    $sharedKm = $postedDistance + ($acceptedPassengerCount * 8);
    $co2Kg = $sharedKm * 0.12;

    return [
        'shared_km' => round($sharedKm, 1),
        'co2_kg' => round($co2Kg, 1),
    ];
}

function ridesync_notify_matching_demand($conn, $rideId, $ride) {
    ridesync_expire_stale_demand_signals($conn);

    if (!ridesync_table_exists($conn, 'route_demand_signals')) {
        return 0;
    }

    $routeKey = ridesync_route_key($ride['origin'] ?? '', $ride['destination'] ?? '');
    if ($routeKey === '>') {
        return 0;
    }

    $stmt = mysqli_prepare($conn,
        "SELECT *
         FROM route_demand_signals
         WHERE demand_status = 'active'
           AND user_id != ?
           AND route_key = ?
           AND (travel_date IS NULL OR travel_date = ?)
         ORDER BY updated_at DESC
         LIMIT 25"
    );
    mysqli_stmt_bind_param($stmt, "iss", $ride['user_id'], $routeKey, $ride['travel_date']);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $notified = 0;
    while ($signal = mysqli_fetch_assoc($result)) {
        $score = ridesync_calculate_match_score($conn, $ride, [
            'origin' => $signal['origin'],
            'destination' => $signal['destination'],
            'origin_lat' => $signal['origin_lat'],
            'origin_lng' => $signal['origin_lng'],
            'destination_lat' => $signal['destination_lat'],
            'destination_lng' => $signal['destination_lng'],
            'route_polyline' => $signal['encoded_polyline'] ?? '',
            'travel_date' => $signal['travel_date'],
            'travel_time' => $signal['travel_time'],
        ]);

        if ($score['score'] < 55) {
            continue;
        }

        ridesync_create_notification(
            $conn,
            (int) $signal['user_id'],
            null,
            'A route you need is now available',
            'New ride posted from ' . $ride['origin'] . ' to ' . $ride['destination'] . ' with ' . number_format($score['score'], 0) . '% match.'
        );

        $notified++;
    }

    return $notified;
}
?>
