<?php
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/location_suggestions.php';

ridesync_require_method('GET');
ridesync_enforce_rate_limit('api:reverse_geocode', 60, 60, ridesync_client_ip(), [
    'json' => true,
    'message' => 'Too many reverse geocode requests. Please try again shortly.',
]);

$rawLat = $_GET['lat'] ?? filter_input(INPUT_GET, 'lat', FILTER_DEFAULT);
$rawLng = $_GET['lng'] ?? filter_input(INPUT_GET, 'lng', FILTER_DEFAULT);

$lat = ($rawLat !== null && is_numeric($rawLat)) ? (float) $rawLat : null;
$lng = ($rawLng !== null && is_numeric($rawLng)) ? (float) $rawLng : null;

if ($lat === null || $lng === null) {
    ridesync_json_response([
        'ok' => false,
        'message' => 'Invalid or missing latitude and longitude coordinates.',
    ], 400);
    exit;
}

// 1. Check if close to known campus/local places (within ~1.2 km)
$localPlaces = ridesync_location_local_places();
$closestPlace = null;
$minDistance = 999999.0;

foreach ($localPlaces as $place) {
    if (empty($place['lat']) || empty($place['lon'])) {
        continue;
    }
    $pLat = (float) $place['lat'];
    $pLon = (float) $place['lon'];
    
    // Haversine approximate distance in KM
    $dLat = deg2rad($pLat - $lat);
    $dLon = deg2rad($pLon - $lng);
    $a = sin($dLat / 2) * sin($dLat / 2) +
         cos(deg2rad($lat)) * cos(deg2rad($pLat)) *
         sin($dLon / 2) * sin($dLon / 2);
    $dist = 6371 * 2 * atan2(sqrt($a), sqrt(1 - $a));
    
    if ($dist < $minDistance) {
        $minDistance = $dist;
        $closestPlace = $place;
    }
}

if ($closestPlace !== null && $minDistance <= 1.2) {
    ridesync_json_response([
        'ok' => true,
        'address' => $closestPlace['display_name'],
        'short_name' => explode(',', $closestPlace['display_name'])[0],
        'lat' => $lat,
        'lng' => $lng,
        'source' => 'campus_landmark',
    ]);
    exit;
}

// 2. Query Nominatim server-side with cache and proper User-Agent
$cacheKey = 'geo_' . round($lat, 4) . '_' . round($lng, 4);
$cacheFile = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ridesync_' . md5($cacheKey) . '.json';

if (file_exists($cacheFile) && (time() - filemtime($cacheFile) < 86400)) {
    $cached = @json_decode(file_get_contents($cacheFile), true);
    if (is_array($cached) && !empty($cached['display_name'])) {
        ridesync_json_response([
            'ok' => true,
            'address' => $cached['display_name'],
            'short_name' => $cached['short_name'] ?? $cached['display_name'],
            'lat' => $lat,
            'lng' => $lng,
            'source' => 'cache',
        ]);
        exit;
    }
}

$url = 'https://nominatim.openstreetmap.org/reverse?format=json&lat=' . urlencode((string) $lat) . '&lon=' . urlencode((string) $lng) . '&addressdetails=1';
$context = stream_context_create([
    'http' => [
        'method' => 'GET',
        'header' => "User-Agent: RideSync-Campus-Transit/1.0 (academic; +https://ridesync-app-81i7.onrender.com)\r\n" .
                    "Accept: application/json\r\n",
        'timeout' => 3.5,
    ],
]);

$response = @file_get_contents($url, false, $context);
if ($response !== false) {
    $data = @json_decode($response, true);
    if (is_array($data) && !empty($data['display_name'])) {
        $displayName = $data['display_name'];
        $addr = $data['address'] ?? [];
        $shortParts = [];
        if (!empty($addr['suburb'])) $shortParts[] = $addr['suburb'];
        elseif (!empty($addr['neighbourhood'])) $shortParts[] = $addr['neighbourhood'];
        elseif (!empty($addr['village'])) $shortParts[] = $addr['village'];
        elseif (!empty($addr['road'])) $shortParts[] = $addr['road'];
        
        if (!empty($addr['city'])) $shortParts[] = $addr['city'];
        elseif (!empty($addr['town'])) $shortParts[] = $addr['town'];
        elseif (!empty($addr['county'])) $shortParts[] = $addr['county'];
        
        if (!empty($addr['state'])) $shortParts[] = $addr['state'];
        
        $shortName = !empty($shortParts) ? implode(', ', array_unique($shortParts)) : $displayName;
        
        @file_put_contents($cacheFile, json_encode([
            'display_name' => $displayName,
            'short_name' => $shortName,
        ]));
        
        ridesync_json_response([
            'ok' => true,
            'address' => $shortName,
            'full_address' => $displayName,
            'short_name' => $shortName,
            'lat' => $lat,
            'lng' => $lng,
            'source' => 'nominatim',
        ]);
        exit;
    }
}

// 3. Fallback to coordinate representation
$coordLabel = number_format($lat, 5) . ', ' . number_format($lng, 5);
ridesync_json_response([
    'ok' => true,
    'address' => $coordLabel,
    'short_name' => $coordLabel,
    'lat' => $lat,
    'lng' => $lng,
    'source' => 'coordinates',
]);
