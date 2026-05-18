<?php
// Smart route matching, hybrid driver fallback, and live status helpers.
require_once __DIR__ . '/cost_helper.php';
require_once __DIR__ . '/services/NotificationService.php';
require_once __DIR__ . '/services/RideService.php';

function ridesync_table_exists($conn, $table) {
    if (!$conn instanceof mysqli) {
        return false;
    }

    static $cache = [];
    static $loadedAllTables = false;
    $key = strtolower($table);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    if (!$loadedAllTables) {
        $result = mysqli_query(
            $conn,
            "SELECT TABLE_NAME
             FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE()"
        );
        if ($result) {
            while ($row = mysqli_fetch_assoc($result)) {
                $cache[strtolower((string) $row['TABLE_NAME'])] = true;
            }
            $loadedAllTables = true;
            return $cache[$key] ?? false;
        }
    }

    $safeTable = mysqli_real_escape_string($conn, $table);
    $result = mysqli_query($conn, "SHOW TABLES LIKE '{$safeTable}'");
    $cache[$key] = $result && mysqli_num_rows($result) > 0;

    return $cache[$key];
}

function ridesync_column_exists($conn, $table, $column) {
    if (!$conn instanceof mysqli) {
        return false;
    }

    static $cache = [];
    $key = strtolower($table . '.' . $column);

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $safeTable = mysqli_real_escape_string($conn, $table);
    $safeColumn = mysqli_real_escape_string($conn, $column);
    $result = mysqli_query($conn, "SHOW COLUMNS FROM `{$safeTable}` LIKE '{$safeColumn}'");
    $cache[$key] = $result && mysqli_num_rows($result) > 0;

    return $cache[$key];
}

function ridesync_float_or_null($value) {
    if ($value === null || $value === '') {
        return null;
    }

    $float = filter_var($value, FILTER_VALIDATE_FLOAT);
    return $float === false ? null : (float) $float;
}

function ridesync_haversine_km($lat1, $lng1, $lat2, $lng2) {
    foreach ([$lat1, $lng1, $lat2, $lng2] as $value) {
        if (!is_numeric($value)) {
            return null;
        }
    }

    $earthRadiusKm = 6371;
    $latDelta = deg2rad((float) $lat2 - (float) $lat1);
    $lngDelta = deg2rad((float) $lng2 - (float) $lng1);
    $a = sin($latDelta / 2) ** 2
        + cos(deg2rad((float) $lat1)) * cos(deg2rad((float) $lat2)) * sin($lngDelta / 2) ** 2;

    return $earthRadiusKm * (2 * atan2(sqrt($a), sqrt(1 - $a)));
}

function ridesync_normalize_route_polyline($value, $maxPoints = 80) {
    $points = ridesync_route_points_from_polyline($value);
    if (count($points) < 2) {
        return null;
    }

    $maxPoints = max(2, (int) $maxPoints);
    if (count($points) > $maxPoints) {
        $sampled = [];
        $lastIndex = count($points) - 1;
        $step = max(1, (int) ceil(count($points) / $maxPoints));
        foreach ($points as $index => $point) {
            if ($index % $step === 0 || $index === $lastIndex) {
                $sampled[] = $point;
            }
        }
        $points = $sampled;
    }

    return json_encode($points);
}

function ridesync_route_points_from_polyline($value) {
    if (!is_string($value) || trim($value) === '') {
        return [];
    }

    $decoded = json_decode($value, true);
    if (!is_array($decoded)) {
        return [];
    }

    $points = [];
    foreach ($decoded as $point) {
        if (is_array($point) && array_key_exists(0, $point) && array_key_exists(1, $point)) {
            $lat = ridesync_float_or_null($point[0]);
            $lng = ridesync_float_or_null($point[1]);
        } elseif (is_array($point)) {
            $lat = ridesync_float_or_null($point['lat'] ?? null);
            $lng = ridesync_float_or_null($point['lng'] ?? ($point['lon'] ?? null));
        } else {
            continue;
        }

        if ($lat === null || $lng === null || $lat < -90 || $lat > 90 || $lng < -180 || $lng > 180) {
            continue;
        }

        $points[] = [round($lat, 5), round($lng, 5)];
    }

    return $points;
}

function ridesync_distance_to_route_km($lat, $lng, $routePoints) {
    if (!is_numeric($lat) || !is_numeric($lng) || count($routePoints) === 0) {
        return null;
    }

    $best = null;
    foreach ($routePoints as $point) {
        $distance = ridesync_haversine_km($lat, $lng, $point[0], $point[1]);
        if ($distance !== null && ($best === null || $distance < $best)) {
            $best = $distance;
        }
    }

    return $best;
}

function ridesync_route_overlap_from_points($searchPoints, $ridePoints, $nearKm = 1.2) {
    if (count($searchPoints) < 2 || count($ridePoints) < 2) {
        return null;
    }

    $near = 0;
    foreach ($searchPoints as $point) {
        $distance = ridesync_distance_to_route_km($point[0], $point[1], $ridePoints);
        if ($distance !== null && $distance <= $nearKm) {
            $near++;
        }
    }

    return (int) round(($near / max(1, count($searchPoints))) * 100);
}

function ridesync_text_match_score($needle, $haystack) {
    $needle = strtolower(trim((string) $needle));
    $haystack = strtolower(trim((string) $haystack));

    if ($needle === '' || $haystack === '') {
        return 45;
    }

    if ($needle === $haystack) {
        return 100;
    }

    if (str_contains($haystack, $needle) || str_contains($needle, $haystack)) {
        return 86;
    }

    similar_text($needle, $haystack, $percent);
    return (int) max(25, min(78, round($percent)));
}

function ridesync_proximity_score($distanceKm, $maxUsefulKm = 12) {
    if ($distanceKm === null || !is_numeric($distanceKm)) {
        return 50;
    }

    $distanceKm = max(0, (float) $distanceKm);

    if ($distanceKm <= 0.5) {
        return 100;
    }

    if ($distanceKm >= $maxUsefulKm) {
        return 0;
    }

    return (int) round(100 - (($distanceKm - 0.5) / ($maxUsefulKm - 0.5) * 100));
}

function ridesync_time_similarity_score($rideDate, $rideTime, $searchDate, $searchTime) {
    if ($searchDate === '' || $searchTime === '') {
        return 72;
    }

    $rideTs = strtotime($rideDate . ' ' . $rideTime);
    $searchTs = strtotime($searchDate . ' ' . $searchTime);

    if (!$rideTs || !$searchTs) {
        return 60;
    }

    $minutes = abs($rideTs - $searchTs) / 60;

    if ($minutes <= 15) {
        return 100;
    }

    if ($minutes >= 180) {
        return 0;
    }

    return (int) round(100 - (($minutes - 15) / 165 * 100));
}

function ridesync_direction_score($ride, $search) {
    $rideOriginLat = ridesync_float_or_null($ride['origin_lat'] ?? null);
    $rideOriginLng = ridesync_float_or_null($ride['origin_lng'] ?? null);
    $rideDestLat = ridesync_float_or_null($ride['destination_lat'] ?? null);
    $rideDestLng = ridesync_float_or_null($ride['destination_lng'] ?? null);
    $searchOriginLat = ridesync_float_or_null($search['origin_lat'] ?? null);
    $searchOriginLng = ridesync_float_or_null($search['origin_lng'] ?? null);
    $searchDestLat = ridesync_float_or_null($search['destination_lat'] ?? null);
    $searchDestLng = ridesync_float_or_null($search['destination_lng'] ?? null);

    if ($rideOriginLat === null || $rideOriginLng === null || $rideDestLat === null || $rideDestLng === null
        || $searchOriginLat === null || $searchOriginLng === null || $searchDestLat === null || $searchDestLng === null) {
        return 75;
    }

    $rideVectorLat = $rideDestLat - $rideOriginLat;
    $rideVectorLng = $rideDestLng - $rideOriginLng;
    $searchVectorLat = $searchDestLat - $searchOriginLat;
    $searchVectorLng = $searchDestLng - $searchOriginLng;

    $dot = ($rideVectorLat * $searchVectorLat) + ($rideVectorLng * $searchVectorLng);
    $rideMagnitude = sqrt(($rideVectorLat ** 2) + ($rideVectorLng ** 2));
    $searchMagnitude = sqrt(($searchVectorLat ** 2) + ($searchVectorLng ** 2));

    if ($rideMagnitude <= 0 || $searchMagnitude <= 0) {
        return 70;
    }

    $cosine = $dot / ($rideMagnitude * $searchMagnitude);
    return (int) round(max(0, min(100, (($cosine + 1) / 2) * 100)));
}

function ridesync_user_trust_score($conn, $userId, $sameCollege = false) {
    static $cache = [];
    $key = (int) $userId . ':' . ($sameCollege ? 'same' : 'any');

    if (array_key_exists($key, $cache)) {
        return $cache[$key];
    }

    $score = 76;

    if (ridesync_table_exists($conn, 'user_ratings')) {
        $stmt = mysqli_prepare($conn, "SELECT AVG(rating) AS avg_rating, COUNT(*) AS total FROM user_ratings WHERE reviewed_user_id = ?");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        $row = mysqli_fetch_assoc(mysqli_stmt_get_result($stmt));
        if ($row && (int) $row['total'] > 0) {
            $score = (int) round(((float) $row['avg_rating'] / 5) * 100);
        }
    }

    if ($sameCollege) {
        $score += 8;
    }

    if (ridesync_table_exists($conn, 'user_verifications')) {
        $stmt = mysqli_prepare($conn, "SELECT id FROM user_verifications WHERE user_id = ? AND status = 'verified' LIMIT 1");
        mysqli_stmt_bind_param($stmt, "i", $userId);
        mysqli_stmt_execute($stmt);
        if (mysqli_fetch_assoc(mysqli_stmt_get_result($stmt))) {
            $score += 10;
        }
    }

    $cache[$key] = (int) max(35, min(100, $score));
    return $cache[$key];
}

function ridesync_calculate_match_score($conn, $ride, $search, $viewerCollege = '') {
    $pickupDistance = null;
    $dropDistance = null;
    $hasSearchCoords = ridesync_float_or_null($search['origin_lat'] ?? null) !== null
        && ridesync_float_or_null($search['origin_lng'] ?? null) !== null
        && ridesync_float_or_null($search['destination_lat'] ?? null) !== null
        && ridesync_float_or_null($search['destination_lng'] ?? null) !== null;
    $hasRideCoords = ridesync_float_or_null($ride['origin_lat'] ?? null) !== null
        && ridesync_float_or_null($ride['origin_lng'] ?? null) !== null
        && ridesync_float_or_null($ride['destination_lat'] ?? null) !== null
        && ridesync_float_or_null($ride['destination_lng'] ?? null) !== null;

    if ($hasSearchCoords && $hasRideCoords) {
        $rideRoutePoints = ridesync_route_points_from_polyline($ride['encoded_polyline'] ?? '');
        $searchRoutePoints = ridesync_route_points_from_polyline($search['route_polyline'] ?? '');
        $usedRouteGeometry = count($rideRoutePoints) >= 2;

        $pickupDistance = $usedRouteGeometry
            ? ridesync_distance_to_route_km($search['origin_lat'], $search['origin_lng'], $rideRoutePoints)
            : ridesync_haversine_km($search['origin_lat'], $search['origin_lng'], $ride['origin_lat'], $ride['origin_lng']);
        $dropDistance = $usedRouteGeometry
            ? ridesync_distance_to_route_km($search['destination_lat'], $search['destination_lng'], $rideRoutePoints)
            : ridesync_haversine_km($search['destination_lat'], $search['destination_lng'], $ride['destination_lat'], $ride['destination_lng']);
        $pickupScore = ridesync_proximity_score($pickupDistance, 12);
        $dropScore = ridesync_proximity_score($dropDistance, 14);

        $rideDistance = is_numeric($ride['route_distance_km'] ?? null)
            ? max(1, (float) $ride['route_distance_km'])
            : max(1, ridesync_haversine_km($ride['origin_lat'], $ride['origin_lng'], $ride['destination_lat'], $ride['destination_lng']) ?? 1);
        $directionScore = ridesync_direction_score($ride, $search);
        $endpointPenalty = min(1, (($pickupDistance ?? 0) + ($dropDistance ?? 0)) / max(1, $rideDistance * 1.35));
        $routeOverlap = ridesync_route_overlap_from_points($searchRoutePoints, $rideRoutePoints);
        if ($routeOverlap === null) {
            $routeOverlap = (int) round(max(0, min(100, (100 - ($endpointPenalty * 100)) * ($directionScore / 100))));
        } else {
            $routeOverlap = (int) round(($routeOverlap * 0.82) + ($directionScore * 0.18));
        }
    } else {
        $pickupScore = ridesync_text_match_score($search['origin'] ?? '', $ride['origin'] ?? '');
        $dropScore = ridesync_text_match_score($search['destination'] ?? '', $ride['destination'] ?? '');
        $routeOverlap = (int) round(($pickupScore + $dropScore) / 2);
    }

    $timeScore = ridesync_time_similarity_score(
        $ride['travel_date'] ?? '',
        $ride['travel_time'] ?? '',
        trim((string) ($search['travel_date'] ?? '')),
        trim((string) ($search['travel_time'] ?? ''))
    );
    $sameCollege = $viewerCollege !== '' && strcasecmp($viewerCollege, (string) ($ride['poster_college'] ?? '')) === 0;
    $trustScore = ridesync_user_trust_score($conn, (int) ($ride['user_id'] ?? 0), $sameCollege);

    $score = ($routeOverlap * 0.35)
        + ($pickupScore * 0.25)
        + ($dropScore * 0.20)
        + ($timeScore * 0.10)
        + ($trustScore * 0.10);

    $score = (float) round(max(0, min(100, $score)), 1);

    return [
        'score' => $score,
        'label' => ridesync_match_score_label($score),
        'pickup_distance_km' => $pickupDistance !== null ? round($pickupDistance, 2) : null,
        'drop_distance_km' => $dropDistance !== null ? round($dropDistance, 2) : null,
        'route_overlap_percent' => $routeOverlap,
        'pickup_score' => $pickupScore,
        'drop_score' => $dropScore,
        'time_score' => $timeScore,
        'trust_score' => $trustScore,
        'source' => ($hasSearchCoords && $hasRideCoords) ? 'smart' : 'manual',
    ];
}

function ridesync_match_score_label($score) {
    if ($score >= 85) {
        return 'Excellent smart match';
    }
    if ($score >= 70) {
        return 'Strong match';
    }
    if ($score >= 55) {
        return 'Good fallback';
    }
    if ($score >= 40) {
        return 'Loose match';
    }

    return 'Low confidence';
}

function ridesync_find_online_driver($conn, $search) {
    if (!ridesync_table_exists($conn, 'driver_accounts')
        || !ridesync_table_exists($conn, 'driver_account_availability')
        || !ridesync_table_exists($conn, 'driver_account_profiles')
        || !ridesync_table_exists($conn, 'driver_account_documents')
        || !ridesync_table_exists($conn, 'driver_ride_requests')
        || !ridesync_table_exists($conn, 'ride_live_status')) {
        return null;
    }

    $sql = "SELECT d.id, d.name, d.phone, a.current_lat, a.current_lng, a.last_changed_at,
                   v.vehicle_type, v.vehicle_number, v.seating_capacity
            FROM driver_accounts d
            JOIN driver_account_availability a ON a.driver_id = d.id
            JOIN driver_account_profiles p ON p.driver_id = d.id
            JOIN (
                SELECT driver_id,
                       SUM(CASE WHEN document_type = 'license' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS license_ok,
                       SUM(CASE WHEN document_type = 'aadhaar' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS aadhaar_ok,
                       SUM(CASE WHEN document_type = 'pan' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS pan_ok,
                       SUM(CASE WHEN document_type = 'vehicle_rc' AND verification_status = 'verified' THEN 1 ELSE 0 END) AS rc_ok
                FROM driver_account_documents
                GROUP BY driver_id
            ) docs ON docs.driver_id = d.id
            LEFT JOIN driver_account_vehicles v ON v.driver_id = d.id
            WHERE d.status = 'active'
              AND a.status = 'online'
              AND p.verification_status = 'verified'
              AND docs.license_ok > 0
              AND docs.aadhaar_ok > 0
              AND docs.pan_ok > 0
              AND docs.rc_ok > 0
              AND NOT EXISTS (
                    SELECT 1
                    FROM driver_ride_requests rr
                    WHERE rr.driver_id = d.id
                      AND rr.request_status = 'accepted'
              )
              AND NOT EXISTS (
                    SELECT 1
                    FROM ride_live_status busy_ls
                    WHERE busy_ls.driver_id = d.id
                      AND busy_ls.live_status IN ('driver_assigned', 'arriving', 'active')
              )
            ORDER BY a.last_changed_at DESC
            LIMIT 20";
    $result = mysqli_query($conn, $sql);

    if (!$result || mysqli_num_rows($result) === 0) {
        return null;
    }

    $originLat = ridesync_float_or_null($search['origin_lat'] ?? null);
    $originLng = ridesync_float_or_null($search['origin_lng'] ?? null);
    $best = null;

    while ($driver = mysqli_fetch_assoc($result)) {
        $distance = null;
        if ($originLat !== null && $originLng !== null && $driver['current_lat'] !== null && $driver['current_lng'] !== null) {
            $distance = ridesync_haversine_km($originLat, $originLng, $driver['current_lat'], $driver['current_lng']);
        }

        $driver['distance_km'] = $distance !== null ? round($distance, 2) : null;
        $driver['eta_minutes'] = $distance !== null ? max(3, (int) ceil(($distance / 22) * 60)) : 8;

        if ($best === null) {
            $best = $driver;
            continue;
        }

        if ($driver['distance_km'] !== null && ($best['distance_km'] === null || $driver['distance_km'] < $best['distance_km'])) {
            $best = $driver;
        }
    }

    return $best;
}

function ridesync_driver_fare_estimate($distanceKm) {
    return ridesync_estimate_total_ride_fare($distanceKm);
}

function ridesync_record_driver_trip($conn, $driverId, $pickup, $drop, $fare, $distanceKm, $sourceType = null, $sourceId = null) {
    return RideSyncRideService::recordDriverTrip(
        $conn,
        (int) $driverId,
        (string) $pickup,
        (string) $drop,
        (float) $fare,
        $distanceKm,
        $sourceType,
        $sourceId !== null ? (int) $sourceId : null
    );
}

function ridesync_ensure_live_status($conn, $rideId, $status = 'searching') {
    return RideSyncRideService::ensureLiveStatus($conn, (int) $rideId, (string) $status);
}

function ridesync_update_live_status($conn, $rideId, $status, $note = null, $driverId = null, $etaMinutes = null) {
    return RideSyncRideService::updateLiveStatus(
        $conn,
        (int) $rideId,
        (string) $status,
        $note !== null ? (string) $note : null,
        $driverId !== null ? (int) $driverId : null,
        $etaMinutes !== null ? (int) $etaMinutes : null
    );
}

function ridesync_create_notification($conn, $userId, $driverId, $title, $message) {
    return RideSyncNotificationService::create($conn, $userId, $driverId, $title, $message);
}

function ridesync_reject_pending_matches($conn, int $rideId, ?int $excludedMatchId = null, string $title = 'Ride unavailable', string $message = 'That ride is no longer accepting pending requests.'): int
{
    if (!$conn instanceof mysqli || $rideId <= 0) {
        return 0;
    }

    if ($excludedMatchId !== null && $excludedMatchId > 0) {
        $pending = mysqli_prepare($conn,
            "SELECT id, matched_user_id
             FROM matches
             WHERE ride_id = ? AND status = 'pending' AND id <> ?
             FOR UPDATE"
        );
        if (!$pending) {
            return 0;
        }
        mysqli_stmt_bind_param($pending, "ii", $rideId, $excludedMatchId);
    } else {
        $pending = mysqli_prepare($conn,
            "SELECT id, matched_user_id
             FROM matches
             WHERE ride_id = ? AND status = 'pending'
             FOR UPDATE"
        );
        if (!$pending) {
            return 0;
        }
        mysqli_stmt_bind_param($pending, "i", $rideId);
    }

    mysqli_stmt_execute($pending);
    $result = mysqli_stmt_get_result($pending);
    $matchedUserIds = [];
    while ($row = mysqli_fetch_assoc($result)) {
        $matchedUserIds[] = (int) $row['matched_user_id'];
    }

    if ($matchedUserIds === []) {
        return 0;
    }

    if ($excludedMatchId !== null && $excludedMatchId > 0) {
        $upd = mysqli_prepare($conn,
            "UPDATE matches
             SET status = 'rejected'
             WHERE ride_id = ? AND status = 'pending' AND id <> ?"
        );
        if (!$upd) {
            return 0;
        }
        mysqli_stmt_bind_param($upd, "ii", $rideId, $excludedMatchId);
    } else {
        $upd = mysqli_prepare($conn,
            "UPDATE matches
             SET status = 'rejected'
             WHERE ride_id = ? AND status = 'pending'"
        );
        if (!$upd) {
            return 0;
        }
        mysqli_stmt_bind_param($upd, "i", $rideId);
    }

    mysqli_stmt_execute($upd);
    $affected = max(0, mysqli_stmt_affected_rows($upd));

    foreach (array_unique($matchedUserIds) as $matchedUserId) {
        ridesync_create_notification($conn, $matchedUserId, null, $title, $message);
    }

    return $affected;
}
?>
