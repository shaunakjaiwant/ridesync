<?php
/**
 * Route Overlap Detection & Server-Side OSRM Snapping Helper
 * RideSync Partial-Path Carpooling Core Logic
 */

require_once __DIR__ . '/matching_helper.php';

if (!defined('RIDESYNC_ROUTE_PROXIMITY_THRESHOLD_KM')) {
    define('RIDESYNC_ROUTE_PROXIMITY_THRESHOLD_KM', 1.5);
}

/**
 * Fetch route polyline & metrics server-side from OSRM provider.
 */
function ridesync_fetch_osrm_route_polyline($originLat, $originLng, $destLat, $destLng) {
    static $cache = [];
    $key = sprintf('%.5f,%.5f;%.5f,%.5f', $originLng, $originLat, $destLng, $destLat);
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $baseUrl = ridesync_get_osrm_provider_url();
    // OSRM expects {lng},{lat};{lng},{lat}
    $url = sprintf('%s/%.6f,%.6f;%.6f,%.6f?overview=full&geometries=geojson', rtrim($baseUrl, '/'), $originLng, $originLat, $destLng, $destLat);

    $response = ridesync_http_get_json($url);
    if (!$response || empty($response['routes'][0]['geometry']['coordinates'])) {
        return null;
    }

    $route = $response['routes'][0];
    $coords = $route['geometry']['coordinates']; // [[lng, lat], ...]
    $points = [];
    foreach ($coords as $coord) {
        if (is_array($coord) && count($coord) >= 2) {
            $points[] = [(float) $coord[1], (float) $coord[0]]; // convert to [lat, lng]
        }
    }

    $result = [
        'points' => $points,
        'distance_meters' => (float) ($route['distance'] ?? 0),
        'duration_seconds' => (float) ($route['duration'] ?? 0),
    ];

    $cache[$key] = $result;
    return $result;
}

/**
 * Calculate detour distance and duration when driver A stops at B's snapped pickup and dropoff points.
 */
function ridesync_calculate_osrm_detour($originLat, $originLng, $pickupLat, $pickupLng, $dropoffLat, $dropoffLng, $destLat, $destLng) {
    static $cache = [];
    $key = sprintf('%.5f,%.5f;%.5f,%.5f;%.5f,%.5f;%.5f,%.5f',
        $originLng, $originLat, $pickupLng, $pickupLat, $dropoffLng, $dropoffLat, $destLng, $destLat
    );
    if (isset($cache[$key])) {
        return $cache[$key];
    }

    $direct = ridesync_fetch_osrm_route_polyline($originLat, $originLng, $destLat, $destLng);
    $directMeters = $direct ? $direct['distance_meters'] : 0;
    $directSeconds = $direct ? $direct['duration_seconds'] : 0;

    $baseUrl = ridesync_get_osrm_provider_url();
    $url = sprintf('%s/%.6f,%.6f;%.6f,%.6f;%.6f,%.6f;%.6f,%.6f?overview=false',
        rtrim($baseUrl, '/'),
        $originLng, $originLat,
        $pickupLng, $pickupLat,
        $dropoffLng, $dropoffLat,
        $destLng, $destLat
    );

    $response = ridesync_http_get_json($url);
    if (!$response || empty($response['routes'][0])) {
        // Fallback calculation using haversine if OSRM call is unavailable
        $directKm = ridesync_haversine_km($originLat, $originLng, $destLat, $destLng) ?? 1.0;
        $detourKm = (ridesync_haversine_km($originLat, $originLng, $pickupLat, $pickupLng) ?? 0)
            + (ridesync_haversine_km($pickupLat, $pickupLng, $dropoffLat, $dropoffLng) ?? 0)
            + (ridesync_haversine_km($dropoffLat, $dropoffLng, $destLat, $destLng) ?? 0);
        $addedKm = max(0, $detourKm - $directKm);
        return [
            'detour_distance_km' => round($addedKm, 2),
            'detour_time_minutes' => (int) round(($addedKm / 30) * 60),
        ];
    }

    $route = $response['routes'][0];
    $detourMeters = (float) ($route['distance'] ?? 0);
    $detourSeconds = (float) ($route['duration'] ?? 0);

    $addedDistanceKm = max(0, round(($detourMeters - $directMeters) / 1000, 2));
    $addedTimeMins = max(0, (int) round(($detourSeconds - $directSeconds) / 60));

    $result = [
        'detour_distance_km' => $addedDistanceKm,
        'detour_time_minutes' => $addedTimeMins,
    ];

    $cache[$key] = $result;
    return $result;
}

/**
 * Snap a point (lat, lng) to the closest point along a polyline.
 */
function ridesync_snap_point_to_polyline($lat, $lng, array $polylinePoints) {
    if (!is_numeric($lat) || !is_numeric($lng) || count($polylinePoints) === 0) {
        return null;
    }

    $lat = (float) $lat;
    $lng = (float) $lng;

    $bestIndex = 0;
    $minDist = null;
    $bestPoint = $polylinePoints[0];

    foreach ($polylinePoints as $idx => $pt) {
        $dist = ridesync_haversine_km($lat, $lng, $pt[0], $pt[1]);
        if ($dist !== null && ($minDist === null || $dist < $minDist)) {
            $minDist = $dist;
            $bestIndex = $idx;
            $bestPoint = $pt;
        }
    }

    return [
        'snapped_lat' => (float) $bestPoint[0],
        'snapped_lng' => (float) $bestPoint[1],
        'distance_km' => round((float) $minDist, 3),
        'point_index' => $bestIndex,
    ];
}

/**
 * Evaluate partial-path route overlap between Ride A and Search/Watch B.
 */
function ridesync_compute_route_overlap(array $ride, array $searchOrWatch) {
    $rideOriginLat = ridesync_float_or_null($ride['origin_lat'] ?? null);
    $rideOriginLng = ridesync_float_or_null($ride['origin_lng'] ?? null);
    $rideDestLat = ridesync_float_or_null($ride['destination_lat'] ?? null);
    $rideDestLng = ridesync_float_or_null($ride['destination_lng'] ?? null);

    $bOriginLat = ridesync_float_or_null($searchOrWatch['origin_lat'] ?? null);
    $bOriginLng = ridesync_float_or_null($searchOrWatch['origin_lng'] ?? null);
    $bDestLat = ridesync_float_or_null($searchOrWatch['destination_lat'] ?? null);
    $bDestLng = ridesync_float_or_null($searchOrWatch['destination_lng'] ?? null);

    if ($rideOriginLat === null || $rideOriginLng === null || $rideDestLat === null || $rideDestLng === null ||
        $bOriginLat === null || $bOriginLng === null || $bDestLat === null || $bDestLng === null) {
        return [
            'is_match' => false,
            'reason' => 'Missing valid geographical coordinates',
        ];
    }

    // Date & time validation
    $rideDate = trim((string) ($ride['travel_date'] ?? ''));
    $bDate = trim((string) ($searchOrWatch['travel_date'] ?? ''));
    if ($rideDate !== '' && $bDate !== '' && $rideDate !== $bDate) {
        return [
            'is_match' => false,
            'reason' => 'Travel date mismatch',
        ];
    }

    // Obtain polyline points for Ride A
    $ridePoints = ridesync_route_points_from_polyline($ride['encoded_polyline'] ?? '');
    if (count($ridePoints) < 2) {
        // Fetch server-side if not pre-stored
        $osrmRes = ridesync_fetch_osrm_route_polyline($rideOriginLat, $rideOriginLng, $rideDestLat, $rideDestLng);
        if ($osrmRes && !empty($osrmRes['points'])) {
            $ridePoints = $osrmRes['points'];
        } else {
            // Fallback straight line representation
            $ridePoints = [[$rideOriginLat, $rideOriginLng], [$rideDestLat, $rideDestLng]];
        }
    }

    // Snap B's origin & destination to A's route
    $pickupSnap = ridesync_snap_point_to_polyline($bOriginLat, $bOriginLng, $ridePoints);
    $dropoffSnap = ridesync_snap_point_to_polyline($bDestLat, $bDestLng, $ridePoints);

    if (!$pickupSnap || !$dropoffSnap) {
        return [
            'is_match' => false,
            'reason' => 'Failed snapping points to route polyline',
        ];
    }

    // 1. Proximity Threshold Check (~1.5 km)
    $threshold = RIDESYNC_ROUTE_PROXIMITY_THRESHOLD_KM;
    if ($pickupSnap['distance_km'] > $threshold || $dropoffSnap['distance_km'] > $threshold) {
        return [
            'is_match' => false,
            'reason' => sprintf('Points exceed maximum proximity threshold (%.2f km & %.2f km vs %.1f km limit)',
                $pickupSnap['distance_km'], $dropoffSnap['distance_km'], $threshold),
            'pickup_distance_km' => $pickupSnap['distance_km'],
            'dropoff_distance_km' => $dropoffSnap['distance_km'],
        ];
    }

    // 2. Sequence / Direction Check (Pickup MUST occur BEFORE Dropoff)
    if ($pickupSnap['point_index'] >= $dropoffSnap['point_index']) {
        return [
            'is_match' => false,
            'reason' => 'Reverse direction or invalid sequence along route',
            'pickup_index' => $pickupSnap['point_index'],
            'dropoff_index' => $dropoffSnap['point_index'],
        ];
    }

    // 3. Compute Route Overlap Percentage
    $totalPolylineCount = count($ridePoints);
    $segmentLength = abs($dropoffSnap['point_index'] - $pickupSnap['point_index']);
    $overlapPercent = (int) round(($segmentLength / max(1, $totalPolylineCount - 1)) * 100);
    $overlapPercent = max(5, min(100, $overlapPercent));

    // 4. Compute Detour Cost for Driver A
    $detour = ridesync_calculate_osrm_detour(
        $rideOriginLat, $rideOriginLng,
        $pickupSnap['snapped_lat'], $pickupSnap['snapped_lng'],
        $dropoffSnap['snapped_lat'], $dropoffSnap['snapped_lng'],
        $rideDestLat, $rideDestLng
    );

    return [
        'is_match' => true,
        'reason' => 'Partial-path route match verified',
        'overlap_percent' => $overlapPercent,
        'pickup_lat' => $pickupSnap['snapped_lat'],
        'pickup_lng' => $pickupSnap['snapped_lng'],
        'dropoff_lat' => $dropoffSnap['snapped_lat'],
        'dropoff_lng' => $dropoffSnap['snapped_lng'],
        'pickup_distance_km' => $pickupSnap['distance_km'],
        'dropoff_distance_km' => $dropoffSnap['distance_km'],
        'detour_distance_km' => $detour['detour_distance_km'],
        'detour_time_minutes' => $detour['detour_time_minutes'],
    ];
}

/**
 * Notify all active route_watches whose saved route overlaps with a newly posted ride.
 */
function ridesync_notify_matching_route_watches($conn, int $rideId, array $newRide): int {
    if (!$conn instanceof mysqli || $rideId <= 0 || !ridesync_table_exists($conn, 'route_watches')) {
        return 0;
    }

    require_once __DIR__ . '/notification_helper.php';

    // 1. Mark expired route_watches where travel_date has passed
    mysqli_query($conn, "UPDATE route_watches SET status = 'expired' WHERE status = 'active' AND travel_date < CURDATE()");

    // 2. Fetch active route_watches for the ride's travel date
    $travelDate = trim((string) ($newRide['travel_date'] ?? ''));
    $rideUserId = (int) ($newRide['user_id'] ?? 0);

    $stmt = mysqli_prepare(
        $conn,
        "SELECT id, user_id, origin, destination, origin_lat, origin_lng, destination_lat, destination_lng, travel_date, travel_time
         FROM route_watches
         WHERE status = 'active' AND travel_date = ? AND user_id <> ?"
    );
    if (!$stmt) {
        return 0;
    }

    mysqli_stmt_bind_param($stmt, "si", $travelDate, $rideUserId);
    mysqli_stmt_execute($stmt);
    $result = mysqli_stmt_get_result($stmt);

    $notified = 0;
    while ($watch = mysqli_fetch_assoc($result)) {
        $overlap = ridesync_compute_route_overlap($newRide, $watch);
        if ($overlap['is_match']) {
            $watchUser = (int) $watch['user_id'];
            $title = "Matching route found!";
            $message = sprintf(
                "A new ride from %s to %s overlaps with your saved route watch (%d%% overlap, +%.1f km detour). Tap to view and join!",
                $newRide['origin'],
                $newRide['destination'],
                $overlap['overlap_percent'],
                $overlap['detour_distance_km']
            );

            if (ridesync_notify_user($conn, $watchUser, $title, $message, $rideId, null, 'route_match_found')) {
                $notified++;
            }
        }
    }
    mysqli_stmt_close($stmt);

    return $notified;
}

/**
 * Mark a route watch as fulfilled when user joins a ride.
 */
function ridesync_fulfill_user_route_watches($conn, int $userId, string $travelDate): void {
    if (!$conn instanceof mysqli || $userId <= 0 || !ridesync_table_exists($conn, 'route_watches')) {
        return;
    }

    $stmt = mysqli_prepare($conn, "UPDATE route_watches SET status = 'fulfilled' WHERE user_id = ? AND travel_date = ? AND status = 'active'");
    if ($stmt) {
        mysqli_stmt_bind_param($stmt, "is", $userId, $travelDate);
        mysqli_stmt_execute($stmt);
        mysqli_stmt_close($stmt);
    }
}

/**
 * Internal helper for OSRM provider endpoint URL.
 */
function ridesync_get_osrm_provider_url(): string {
    if (function_exists('ridesync_env')) {
        return (string) ridesync_env('RIDESYNC_ROUTING_PROVIDER_URL', 'https://router.project-osrm.org/route/v1/driving');
    }
    return 'https://router.project-osrm.org/route/v1/driving';
}

/**
 * Helper to execute HTTP GET JSON requests.
 */
function ridesync_http_get_json(string $url): ?array {
    if (function_exists('ridesync_http_get')) {
        $body = ridesync_http_get($url, 4);
        return is_string($body) ? json_decode($body, true) : null;
    }

    $ch = curl_init();
    curl_setopt($ch, CURLOPT_URL, $url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 4);
    curl_setopt($ch, CURLOPT_USERAGENT, 'RideSync-Campus-Server/1.0');

    $response = curl_exec($ch);
    $error = curl_error($ch);
    curl_close($ch);

    if ($error || !is_string($response)) {
        return null;
    }

    return json_decode($response, true);
}

