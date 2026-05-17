<?php
define('RIDESYNC_ALLOW_DB_FAILURE', true);

require_once __DIR__ . '/../includes/http_helper.php';
require_once __DIR__ . '/../includes/rate_limit_helper.php';
require_once __DIR__ . '/../includes/admin_helper.php';
require_once __DIR__ . '/../includes/search_suggestions_helper.php';

ridesync_require_method('GET');

if (!isset($_SESSION['admin_id'])) {
    ridesync_error_response('Authentication required', 401);
}

require_once __DIR__ . '/../config/db.php';

if (!$conn instanceof mysqli) {
    ridesync_error_response('Search suggestions are temporarily unavailable', 503);
}

$admin = ridesync_fetch_admin($conn, (int) $_SESSION['admin_id']);
if (!$admin || $admin['status'] !== 'active') {
    ridesync_error_response('Not allowed', 403);
}

ridesync_enforce_rate_limit('api:search_suggestions', 180, 60, 'admin:' . (int) $_SESSION['admin_id'], [
    'json' => true,
    'message' => 'Too many suggestion searches. Please try again shortly.',
]);

$context = trim((string) ($_GET['context'] ?? 'admin_global'));
$query = ridesync_search_query($_GET['q'] ?? '');
$limit = filter_input(INPUT_GET, 'limit', FILTER_VALIDATE_INT) ?: 10;

ridesync_json_response([
    'ok' => true,
    'context' => $context,
    'query' => $query,
    'suggestions' => ridesync_admin_search_suggestions($conn, $context, $query, $limit),
]);
?>
