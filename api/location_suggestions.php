<?php
require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/location_suggestions.php';

ridesync_require_method('GET');
ridesync_enforce_rate_limit('api:location_suggestions', 90, 60, ridesync_client_ip(), [
    'json' => true,
    'message' => 'Too many location searches. Please try again shortly.',
]);

$query = ridesync_location_normalize_query($_GET['q'] ?? '');
if (strlen($query) < 2) {
    ridesync_json_response([
        'ok' => true,
        'query' => $query,
        'suggestions' => [],
    ]);
}

ridesync_json_response([
    'ok' => true,
    'query' => $query,
    'suggestions' => ridesync_location_suggestions($query, 8),
]);
?>
