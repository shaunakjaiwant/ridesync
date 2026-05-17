<?php
require_once __DIR__ . '/../config/bootstrap.php';
require_once __DIR__ . '/college_suggestions.php';

function ridesync_location_local_places() {
    return [
        ['display_name' => 'Mangalore Airport, Mangaluru International Airport, Karnataka, India', 'lat' => '12.9613', 'lon' => '74.8901', 'source' => 'local'],
        ['display_name' => 'Mangaluru International Airport, Karnataka, India', 'lat' => '12.9613', 'lon' => '74.8901', 'source' => 'local'],
        ['display_name' => 'Mangalore Central Railway Station, Mangaluru, Karnataka, India', 'lat' => '12.8654', 'lon' => '74.8424', 'source' => 'local'],
        ['display_name' => 'Mangalore Junction Railway Station, Kankanady, Karnataka, India', 'lat' => '12.8560', 'lon' => '74.8589', 'source' => 'local'],
        ['display_name' => 'KSRTC Bus Stand, Bejai, Mangaluru, Karnataka, India', 'lat' => '12.8843', 'lon' => '74.8431', 'source' => 'local'],
        ['display_name' => 'State Bank Bus Stand, Mangaluru, Karnataka, India', 'lat' => '12.8698', 'lon' => '74.8428', 'source' => 'local'],
        ['display_name' => 'Ujire Bus Stand, Ujire, Karnataka, India', 'lat' => '13.3339', 'lon' => '75.2367', 'source' => 'local'],
        ['display_name' => 'Dharmasthala Bus Stand, Karnataka, India', 'lat' => '12.9498', 'lon' => '75.3789', 'source' => 'local'],
        ['display_name' => 'NITK Surathkal, Mangaluru, Karnataka, India', 'lat' => '13.0108', 'lon' => '74.7943', 'source' => 'local'],
        ['display_name' => 'SDMIT Campus, Ujire, Karnataka, India', 'lat' => '13.3420', 'lon' => '75.2376', 'source' => 'local'],
        ['display_name' => 'SDM Institute of Technology, Ujire, Karnataka, India', 'lat' => '13.3420', 'lon' => '75.2376', 'source' => 'local'],
        ['display_name' => 'St Aloysius College, Mangaluru, Karnataka, India', 'lat' => '12.8729', 'lon' => '74.8440', 'source' => 'local'],
        ['display_name' => 'Sahyadri College of Engineering and Management, Adyar, Mangaluru, Karnataka, India', 'lat' => '12.8878', 'lon' => '74.9243', 'source' => 'local'],
        ['display_name' => 'Mangalore University, Konaje, Karnataka, India', 'lat' => '12.8157', 'lon' => '74.9256', 'source' => 'local'],
    ];
}

function ridesync_location_normalize_query($query) {
    $query = preg_replace('/\s+/', ' ', trim((string) $query));
    return substr($query, 0, 160);
}

function ridesync_location_score($label, $query) {
    $labelVariants = ridesync_location_text_variants($label);
    $queryVariants = ridesync_location_text_variants($query);
    $bestScore = 0;

    foreach ($labelVariants as $labelLower) {
        foreach ($queryVariants as $queryLower) {
            if ($labelLower === $queryLower) {
                $bestScore = max($bestScore, 1000);
                continue;
            }
            if (strpos($labelLower, $queryLower) === 0) {
                $bestScore = max($bestScore, 800);
                continue;
            }
            if (strpos($labelLower, $queryLower) !== false) {
                $bestScore = max($bestScore, 620);
                continue;
            }

            $score = 0;
            foreach (preg_split('/\s+/', $queryLower) as $token) {
                if (strlen($token) < 2) {
                    continue;
                }
                if (strpos($labelLower, $token) !== false) {
                    $score += 120;
                }
            }
            $bestScore = max($bestScore, $score);
        }
    }

    return $bestScore;
}

function ridesync_location_text_variants($value) {
    $base = strtolower((string) $value);
    return array_values(array_unique([
        $base,
        str_replace('mangalore', 'mangaluru', $base),
        str_replace('mangaluru', 'mangalore', $base),
        str_replace('sdmit', 'sdm institute of technology', $base),
    ]));
}

function ridesync_location_local_suggestions($query, $limit = 8) {
    $query = ridesync_location_normalize_query($query);
    if (strlen($query) < 2) {
        return [];
    }

    $places = ridesync_location_local_places();
    foreach (ridesync_college_suggestions() as $college) {
        $places[] = [
            'display_name' => $college . ', Karnataka, India',
            'lat' => null,
            'lon' => null,
            'source' => 'campus_directory',
        ];
    }

    $matches = [];
    foreach ($places as $place) {
        $score = ridesync_location_score($place['display_name'], $query);
        if ($score <= 0) {
            continue;
        }
        $place['_score'] = $score;
        $place['_has_coords'] = $place['lat'] !== null && $place['lon'] !== null ? 1 : 0;
        $matches[] = $place;
    }

    usort($matches, function ($left, $right) {
        if ($left['_score'] === $right['_score']) {
            if ($left['_has_coords'] !== $right['_has_coords']) {
                return $right['_has_coords'] <=> $left['_has_coords'];
            }
            return strcasecmp($left['display_name'], $right['display_name']);
        }
        return $right['_score'] <=> $left['_score'];
    });

    return array_map(function ($place) {
        unset($place['_score']);
        unset($place['_has_coords']);
        return $place;
    }, array_slice($matches, 0, max(1, (int) $limit)));
}

function ridesync_location_fetch_url($url) {
    $userAgent = ridesync_env('RIDESYNC_LOCATION_USER_AGENT', 'RideSync local development location search');
    $timeout = max(1, ridesync_env_int('RIDESYNC_LOCATION_TIMEOUT_SECONDS', 4));

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => $timeout,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_USERAGENT => $userAgent,
            CURLOPT_HTTPHEADER => ['Accept: application/json'],
        ]);
        $body = curl_exec($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);
        return $status >= 200 && $status < 300 ? $body : false;
    }

    $context = stream_context_create([
        'http' => [
            'method' => 'GET',
            'timeout' => $timeout,
            'header' => "Accept: application/json\r\nUser-Agent: " . $userAgent . "\r\n",
        ],
    ]);

    return @file_get_contents($url, false, $context);
}

function ridesync_location_provider_suggestions($query, $limit = 6) {
    $query = ridesync_location_normalize_query($query);
    if (strlen($query) < 3) {
        return [];
    }

    $normalized = preg_match('/\b(india|karnataka|mangaluru|mangalore|dakshina kannada)\b/i', $query)
        ? $query
        : $query . ' Karnataka India';
    $baseUrl = rtrim((string) ridesync_env('RIDESYNC_LOCATION_PROVIDER_URL', 'https://nominatim.openstreetmap.org/search'), '?');
    $url = $baseUrl . '?format=jsonv2&addressdetails=1&limit=' . (int) $limit . '&countrycodes=in&q=' . rawurlencode($normalized);
    $body = ridesync_location_fetch_url($url);
    if (!is_string($body) || $body === '') {
        return [];
    }

    $decoded = json_decode($body, true);
    if (!is_array($decoded)) {
        return [];
    }

    $results = [];
    foreach ($decoded as $item) {
        if (empty($item['display_name']) || !isset($item['lat'], $item['lon'])) {
            continue;
        }
        $results[] = [
            'display_name' => (string) $item['display_name'],
            'lat' => (string) $item['lat'],
            'lon' => (string) $item['lon'],
            'source' => 'provider',
        ];
    }

    return $results;
}

function ridesync_location_suggestions($query, $limit = 8) {
    $limit = max(1, min(12, (int) $limit));
    $provider = ridesync_location_provider_suggestions($query, $limit);
    $local = ridesync_location_local_suggestions($query, $limit);
    $merged = [];
    $seen = [];

    foreach (array_merge($local, $provider) as $suggestion) {
        $key = strtolower(trim((string) $suggestion['display_name']));
        if ($key === '' || isset($seen[$key])) {
            continue;
        }
        $seen[$key] = true;
        $merged[] = $suggestion;
        if (count($merged) >= $limit) {
            break;
        }
    }

    return $merged;
}
?>
